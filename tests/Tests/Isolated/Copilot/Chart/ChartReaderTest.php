<?php

/**
 * FROZEN acceptance tests — T5: FHIR read path as the physician's delegated
 * session (S4/S6 bright lines; ARCHITECTURE.md §4, Decision 3).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: every chart read is delegation — it requires a typed
 * PhysicianContext (never a service account, never ambient state), passes that
 * context to the gateway on every call, scopes every query to one patient,
 * covers all five v1 sources in one bundle, and propagates read failures
 * instead of laundering them into silently-empty results.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Chart;

use OpenEMR\Modules\Copilot\Chart\ChartReader;
use OpenEMR\Modules\Copilot\Chart\FhirReadFailedException;
use OpenEMR\Modules\Copilot\Chart\FhirReadGateway;
use OpenEMR\Modules\Copilot\Chart\PhysicianContext;
use OpenEMR\Modules\Copilot\Chart\RawChartBundle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ChartReaderTest extends TestCase
{
    private const PATIENT_UUID = '9a4f2f9e-1c2b-4a77-9c61-000000000001';

    /**
     * @return array<string, array{string, int}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function invalidPhysicianProvider(): array
    {
        return [
            'empty username' => ['', 5],
            'whitespace username' => ['   ', 5],
            'zero user id' => ['ellis.tran', 0],
            'negative user id' => ['ellis.tran', -1],
        ];
    }

    #[DataProvider('invalidPhysicianProvider')]
    public function testPhysicianContextCannotBeAnonymous(string $username, int $userId): void
    {
        $this->expectException(\DomainException::class);
        new PhysicianContext($username, $userId);
    }

    public function testReadsAllFiveSourcesWithTheSamePhysicianContext(): void
    {
        $gateway = new class implements FhirReadGateway {
            /** @var list<array{PhysicianContext, string, array<string, mixed>}> */
            public array $calls = [];

            public function read(PhysicianContext $physician, string $resourceType, array $searchParams): array
            {
                $this->calls[] = [$physician, $resourceType, $searchParams];
                return [];
            }
        };

        $physician = new PhysicianContext('ellis.tran', 5);
        $bundle = (new ChartReader($gateway))->readChart($physician, self::PATIENT_UUID);
        $calls = $gateway->calls;

        $this->assertInstanceOf(RawChartBundle::class, $bundle);

        $requestedTypes = array_map(static fn (array $call): string => $call[1], $calls);
        sort($requestedTypes);
        $this->assertSame(
            ['AllergyIntolerance', 'Condition', 'MedicationRequest', 'Observation', 'Patient'],
            $requestedTypes,
            'The v1 chart bundle reads exactly the five sanctioned FHIR sources.'
        );

        foreach ($calls as [$context, $resourceType, $searchParams]) {
            $this->assertSame($physician, $context, 'Every read carries the same delegated physician context.');
            $this->assertContains(
                self::PATIENT_UUID,
                self::flattenValues($searchParams),
                sprintf('%s search must be scoped to the requested patient.', $resourceType)
            );
        }
    }

    public function testGatewayFailurePropagatesInsteadOfReturningAnEmptyChart(): void
    {
        $gateway = new class implements FhirReadGateway {
            public function read(PhysicianContext $physician, string $resourceType, array $searchParams): array
            {
                throw new FhirReadFailedException(sprintf('read failed for %s', $resourceType));
            }
        };

        $this->expectException(FhirReadFailedException::class);
        (new ChartReader($gateway))->readChart(new PhysicianContext('ellis.tran', 5), self::PATIENT_UUID);
    }

    public function testBundleSeparatesTheFiveSourcesWithoutMixingThem(): void
    {
        $gateway = new class implements FhirReadGateway {
            public function read(PhysicianContext $physician, string $resourceType, array $searchParams): array
            {
                return [['resourceType' => $resourceType, 'marker' => $resourceType . '-payload']];
            }
        };

        $bundle = (new ChartReader($gateway))->readChart(new PhysicianContext('ellis.tran', 5), self::PATIENT_UUID);

        $this->assertSame('Patient', $bundle->patient[0]['resourceType']);
        $this->assertSame('MedicationRequest', $bundle->medications[0]['resourceType']);
        $this->assertSame('Observation', $bundle->observations[0]['resourceType']);
        $this->assertSame('AllergyIntolerance', $bundle->allergies[0]['resourceType']);
        $this->assertSame('Condition', $bundle->problems[0]['resourceType']);
    }

    /**
     * @param array<string, mixed> $params
     * @return list<string>
     */
    private static function flattenValues(array $params): array
    {
        $values = [];
        array_walk_recursive($params, static function (mixed $value) use (&$values): void {
            if (is_string($value)) {
                $values[] = $value;
            }
        });
        return $values;
    }
}
