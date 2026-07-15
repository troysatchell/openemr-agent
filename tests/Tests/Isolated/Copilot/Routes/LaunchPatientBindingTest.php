<?php

/**
 * FROZEN acceptance tests — TRO-52 (Wave N): LaunchPatientBinding, the pure
 * guard that binds every clinical route to the access token's launch-context
 * patient (TRO-51 / docs/TRO51_SMART_LAUNCH_DESIGN.md §4.1).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and
 * frozen: implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test:
 *  - Constructed from the token-bound patient uuid
 *    (HttpRestRequest::getPatientUUIDString()); '' / whitespace normalize to
 *    unbound (D1: empty string is unknown).
 *  - An UNBOUND token refuses enforce() unconditionally — clinical routes
 *    require a patient-bound token (design decision D1), 403 before any
 *    chart read.
 *  - A bound token: body patient_uuid absent / '' / whitespace → the token's
 *    patient is injected; present and matching (trim + case-insensitive) →
 *    canonicalized to the token's own uuid; present and mismatched → refused;
 *    non-string → refused. Cross-patient access is structurally impossible.
 *  - Refusals carry NO patient uuid in the exception message (R11: generic
 *    errors, no PHI/identifier leak).
 *  - All other input keys pass through untouched.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Routes;

use OpenEMR\Modules\Copilot\Routes\LaunchPatientAccessDeniedException;
use OpenEMR\Modules\Copilot\Routes\LaunchPatientBinding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LaunchPatientBindingTest extends TestCase
{
    private const TOKEN_PATIENT = '9a4f2b6e-1c3d-4e5f-8a7b-0c1d2e3f4a5b';
    private const OTHER_PATIENT = '11111111-2222-3333-4444-555555555555';

    public function testRefusalIsARuntimeException(): void
    {
        $this->assertTrue(
            is_a(LaunchPatientAccessDeniedException::class, \RuntimeException::class, true),
            'the refusal exception must be catchable as \RuntimeException without matching the 400 DomainException path'
        );
    }

    /**
     * @return array<string, array{?string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function unboundTokenProvider(): array
    {
        return [
            'null token patient' => [null],
            'empty-string token patient (D1: empty is unknown)' => [''],
            'whitespace token patient' => ["  \t"],
        ];
    }

    #[DataProvider('unboundTokenProvider')]
    public function testUnboundTokenRefusesEvenAMatchingBodyUuid(?string $tokenPatient): void
    {
        $binding = new LaunchPatientBinding($tokenPatient);

        $this->expectException(LaunchPatientAccessDeniedException::class);
        $binding->enforce(['patient_uuid' => self::TOKEN_PATIENT, 'question' => 'q']);
    }

    #[DataProvider('unboundTokenProvider')]
    public function testUnboundTokenRefusesWhenBodyOmitsThePatient(?string $tokenPatient): void
    {
        $binding = new LaunchPatientBinding($tokenPatient);

        $this->expectException(LaunchPatientAccessDeniedException::class);
        $binding->enforce(['question' => 'q']);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function absentBodyUuidProvider(): array
    {
        return [
            'patient_uuid key absent' => [['question' => 'q']],
            'patient_uuid empty string (D1: empty is unknown)' => [['patient_uuid' => '', 'question' => 'q']],
            'patient_uuid whitespace-only' => [['patient_uuid' => '   ', 'question' => 'q']],
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('absentBodyUuidProvider')]
    public function testBoundTokenInjectsItsPatientWhenBodyOmitsIt(array $input): void
    {
        $binding = new LaunchPatientBinding(self::TOKEN_PATIENT);

        $result = $binding->enforce($input);

        $this->assertSame(self::TOKEN_PATIENT, $result['patient_uuid']);
        $this->assertSame('q', $result['question'], 'other input keys pass through untouched');
    }

    /**
     * @return array<string, array{string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function matchingBodyUuidProvider(): array
    {
        return [
            'exact match' => [self::TOKEN_PATIENT],
            'case-insensitive match' => [strtoupper(self::TOKEN_PATIENT)],
            'surrounding whitespace trimmed' => ['  ' . self::TOKEN_PATIENT . ' '],
        ];
    }

    #[DataProvider('matchingBodyUuidProvider')]
    public function testBoundTokenAcceptsAMatchingBodyUuidAndCanonicalizesIt(string $bodyUuid): void
    {
        $binding = new LaunchPatientBinding(self::TOKEN_PATIENT);

        $result = $binding->enforce(['patient_uuid' => $bodyUuid, 'question' => 'q', 'ask_evidence' => true]);

        $this->assertSame(
            self::TOKEN_PATIENT,
            $result['patient_uuid'],
            'the effective patient is always the token\'s own canonical uuid'
        );
        $this->assertSame('q', $result['question']);
        $this->assertTrue($result['ask_evidence']);
    }

    public function testBoundTokenCanonicalIsItsTrimmedValue(): void
    {
        $binding = new LaunchPatientBinding(' ' . self::TOKEN_PATIENT . ' ');

        $result = $binding->enforce([]);

        $this->assertSame(self::TOKEN_PATIENT, $result['patient_uuid']);
    }

    public function testBoundTokenRefusesAMismatchedBodyUuid(): void
    {
        $binding = new LaunchPatientBinding(self::TOKEN_PATIENT);

        $this->expectException(LaunchPatientAccessDeniedException::class);
        $binding->enforce(['patient_uuid' => self::OTHER_PATIENT]);
    }

    public function testRefusalMessageLeaksNeitherUuid(): void
    {
        $binding = new LaunchPatientBinding(self::TOKEN_PATIENT);

        try {
            $binding->enforce(['patient_uuid' => self::OTHER_PATIENT]);
            $this->fail('expected LaunchPatientAccessDeniedException');
        } catch (LaunchPatientAccessDeniedException $e) {
            $this->assertStringNotContainsString(self::TOKEN_PATIENT, $e->getMessage());
            $this->assertStringNotContainsString(self::OTHER_PATIENT, $e->getMessage());
            $this->assertStringNotContainsString(strtoupper(self::OTHER_PATIENT), strtoupper($e->getMessage()));
        }
    }

    /**
     * @return array<string, array{mixed}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function nonStringBodyUuidProvider(): array
    {
        return [
            'integer' => [12345],
            'array' => [[self::TOKEN_PATIENT]],
            'boolean' => [true],
            'float' => [1.5],
        ];
    }

    #[DataProvider('nonStringBodyUuidProvider')]
    public function testBoundTokenRefusesANonStringBodyUuid(mixed $bodyUuid): void
    {
        $binding = new LaunchPatientBinding(self::TOKEN_PATIENT);

        $this->expectException(LaunchPatientAccessDeniedException::class);
        $binding->enforce(['patient_uuid' => $bodyUuid]);
    }
}
