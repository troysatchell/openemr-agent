<?php

/**
 * Contract tests — OpenEmrFhirGateway pipeline logic (closes the T5 isolated
 * coverage gap; HANDOFF 2026-07-11 open item #1).
 *
 * The gateway is exercised against a stubbed FhirServiceBase injected through
 * FhirServiceFactory, so every pipeline branch runs without a database: the
 * unscoped-read refusal, unsupported-type rejection, failure wrapping, the
 * no-laundering rule (a failed read must never masquerade as an empty chart —
 * FhirReadGateway contract), minimum-necessary exception messages (field names
 * only, never query or validation values; C1), and FHIR-resource JSON
 * decoding. The only lines still requiring a live stack are the five service
 * constructions inside OpenEmrFhirServiceFactory.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Chart;

use OpenEMR\FHIR\R4\FHIRResource\FHIRDomainResource;
use OpenEMR\Modules\Copilot\Chart\FhirReadFailedException;
use OpenEMR\Modules\Copilot\Chart\FhirServiceFactory;
use OpenEMR\Modules\Copilot\Chart\OpenEmrFhirGateway;
use OpenEMR\Modules\Copilot\Chart\OpenEmrFhirServiceFactory;
use OpenEMR\Modules\Copilot\Chart\PhysicianContext;
use OpenEMR\Services\FHIR\FhirServiceBase;
use OpenEMR\Services\Search\FhirSearchParameterDefinition;
use OpenEMR\Validators\ProcessingResult;
use PHPUnit\Framework\TestCase;

class OpenEmrFhirGatewayTest extends TestCase
{
    private const PATIENT_UUID = '9a4f2f9e-1c2b-4a77-9c61-000000000001';

    public function testRefusesUnscopedReadBeforeTouchingTheServiceLayer(): void
    {
        $factory = self::recordingFactory(self::serviceReturning(new ProcessingResult()));
        $gateway = new OpenEmrFhirGateway($factory);

        $refused = null;
        try {
            $gateway->read(self::physician(), 'Patient', []);
        } catch (\InvalidArgumentException $refused) {
        }

        $this->assertInstanceOf(\InvalidArgumentException::class, $refused);
        $this->assertSame([], $factory->requestedTypes, 'An unscoped read must be refused before any service is built');
    }

    public function testRejectsUnsupportedResourceTypeWithoutWrapping(): void
    {
        // Real factory: its default match arm is pure and needs no database.
        $gateway = new OpenEmrFhirGateway(new OpenEmrFhirServiceFactory());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Encounter');
        $gateway->read(self::physician(), 'Encounter', ['patient' => self::PATIENT_UUID]);
    }

    public function testServiceFailureIsWrappedWithCauseAndWithoutQueryValues(): void
    {
        $failure = new \RuntimeException('connection refused');
        $gateway = new OpenEmrFhirGateway(self::recordingFactory(self::serviceThrowing($failure)));

        $caught = null;
        try {
            $gateway->read(self::physician(), 'Observation', ['patient' => self::PATIENT_UUID]);
        } catch (FhirReadFailedException $caught) {
        }

        $this->assertInstanceOf(FhirReadFailedException::class, $caught);
        $this->assertSame($failure, $caught->getPrevious(), 'The original failure must stay on the chain');
        $this->assertStringContainsString('Observation', $caught->getMessage());
        $this->assertStringNotContainsString(
            self::PATIENT_UUID,
            $caught->getMessage(),
            'Exception messages must not carry query values (minimum necessary, C1)'
        );
    }

    public function testValidationErrorsSurfaceFieldNamesOnly(): void
    {
        $result = new ProcessingResult();
        $result->setValidationMessages(['patient' => 'uuid must match pattern; got ' . self::PATIENT_UUID]);
        $gateway = new OpenEmrFhirGateway(self::recordingFactory(self::serviceReturning($result)));

        $caught = null;
        try {
            $gateway->read(self::physician(), 'Condition', ['patient' => self::PATIENT_UUID]);
        } catch (FhirReadFailedException $caught) {
        }

        $this->assertInstanceOf(FhirReadFailedException::class, $caught);
        $this->assertStringContainsString('patient', $caught->getMessage());
        $this->assertStringNotContainsString(
            self::PATIENT_UUID,
            $caught->getMessage(),
            'Validation message bodies must never leak into the exception (minimum necessary, C1)'
        );
    }

    public function testInternalErrorMustNotMasqueradeAsAnEmptyChart(): void
    {
        $result = new ProcessingResult();
        $result->addInternalError('database unavailable');
        $gateway = new OpenEmrFhirGateway(self::recordingFactory(self::serviceReturning($result)));

        $this->expectException(FhirReadFailedException::class);
        $gateway->read(self::physician(), 'MedicationRequest', ['patient' => self::PATIENT_UUID]);
    }

    public function testDecodesFhirResourcesToJsonShapedArrays(): void
    {
        $result = new ProcessingResult();
        $result->addData(self::fhirResource(['resourceType' => 'Patient', 'id' => 'a-1']));
        $result->addData(self::fhirResource(['resourceType' => 'Patient', 'id' => 'a-2']));
        $factory = self::recordingFactory(self::serviceReturning($result));
        $gateway = new OpenEmrFhirGateway($factory);

        $resources = $gateway->read(self::physician(), 'Patient', ['patient' => self::PATIENT_UUID]);

        $this->assertSame([
            ['resourceType' => 'Patient', 'id' => 'a-1'],
            ['resourceType' => 'Patient', 'id' => 'a-2'],
        ], $resources);
        $this->assertSame(['Patient'], $factory->requestedTypes);
    }

    public function testValidEmptyResultIsAnEmptyList(): void
    {
        // An empty chart from a SUCCESSFUL read is legitimate — only failures must throw.
        $gateway = new OpenEmrFhirGateway(self::recordingFactory(self::serviceReturning(new ProcessingResult())));

        $this->assertSame(
            [],
            $gateway->read(self::physician(), 'AllergyIntolerance', ['patient' => self::PATIENT_UUID])
        );
    }

    public function testNonSerializableRecordIsARefusedRead(): void
    {
        $result = new ProcessingResult();
        $result->addData('not-a-fhir-resource');
        $gateway = new OpenEmrFhirGateway(self::recordingFactory(self::serviceReturning($result)));

        $this->expectException(FhirReadFailedException::class);
        $this->expectExceptionMessage('not a serializable FHIR resource');
        $gateway->read(self::physician(), 'Patient', ['patient' => self::PATIENT_UUID]);
    }

    public function testResourceDecodingToANonObjectIsRefused(): void
    {
        $result = new ProcessingResult();
        $result->addData(self::fhirResource('just-a-string'));
        $gateway = new OpenEmrFhirGateway(self::recordingFactory(self::serviceReturning($result)));

        $this->expectException(FhirReadFailedException::class);
        $this->expectExceptionMessage('did not decode to a JSON object');
        $gateway->read(self::physician(), 'Patient', ['patient' => self::PATIENT_UUID]);
    }

    private static function physician(): PhysicianContext
    {
        return new PhysicianContext('ellis.tran', 5);
    }

    /**
     * @return FhirServiceFactory&object{requestedTypes: list<string>}
     */
    private static function recordingFactory(FhirServiceBase $service): FhirServiceFactory
    {
        return new class ($service) implements FhirServiceFactory {
            /** @var list<string> */
            public array $requestedTypes = [];

            public function __construct(private readonly FhirServiceBase $service)
            {
            }

            public function create(string $resourceType): FhirServiceBase
            {
                $this->requestedTypes[] = $resourceType;
                return $this->service;
            }
        };
    }

    private static function serviceReturning(ProcessingResult $result): FhirServiceBase
    {
        return self::stubService($result, null);
    }

    private static function serviceThrowing(\Throwable $failure): FhirServiceBase
    {
        return self::stubService(null, $failure);
    }

    /**
     * Stub at the gateway's exact seam: getAll() answers with a canned
     * ProcessingResult or a throw. The parent constructor is skipped so no
     * search machinery is built, and the remaining abstract methods are
     * satisfied with inert never-called bodies.
     */
    private static function stubService(?ProcessingResult $result, ?\Throwable $failure): FhirServiceBase
    {
        return new class ($result, $failure) extends FhirServiceBase {
            public function __construct(
                private readonly ?ProcessingResult $result,
                private readonly ?\Throwable $failure,
            ) {
                // Deliberately no parent constructor: the stub never touches the search machinery.
            }

            /**
             * @param mixed $fhirSearchParameters
             * @param mixed $puuidBind
             */
            public function getAll($fhirSearchParameters, $puuidBind = null): ProcessingResult
            {
                if ($this->failure !== null) {
                    throw $this->failure;
                }
                if ($this->result === null) {
                    throw new \LogicException('Stub built without a result or a failure');
                }
                return $this->result;
            }

            /**
             * @return array<string, FhirSearchParameterDefinition>
             */
            protected function loadSearchParameters(): array
            {
                return [];
            }

            /**
             * @param array<array-key, mixed> $dataRecord
             * @param bool $encode
             */
            public function parseOpenEMRRecord($dataRecord = [], $encode = false): never
            {
                throw new \LogicException('Not part of the stubbed seam');
            }

            public function parseFhirResource(FHIRDomainResource $fhirResource): never
            {
                throw new \LogicException('Not part of the stubbed seam');
            }

            /**
             * @param mixed $openEmrRecord
             */
            protected function insertOpenEMRRecord($openEmrRecord): never
            {
                throw new \LogicException('Not part of the stubbed seam');
            }

            /**
             * @param string $fhirResourceId
             * @param array<array-key, mixed> $updatedOpenEMRRecord
             */
            protected function updateOpenEMRRecord($fhirResourceId, $updatedOpenEMRRecord): never
            {
                throw new \LogicException('Not part of the stubbed seam');
            }

            /**
             * @param array<array-key, mixed> $openEMRSearchParameters
             */
            protected function searchForOpenEMRRecords($openEMRSearchParameters): ProcessingResult
            {
                throw new \LogicException('Not part of the stubbed seam');
            }

            /**
             * @param mixed $dataRecord
             * @param bool $encode
             */
            public function createProvenanceResource($dataRecord, $encode = false): never
            {
                throw new \LogicException('Not part of the stubbed seam');
            }
        };
    }

    private static function fhirResource(mixed $jsonForm): \JsonSerializable
    {
        return new class ($jsonForm) implements \JsonSerializable {
            public function __construct(private readonly mixed $jsonForm)
            {
            }

            public function jsonSerialize(): mixed
            {
                return $this->jsonForm;
            }
        };
    }
}
