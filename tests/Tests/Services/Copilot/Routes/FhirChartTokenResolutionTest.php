<?php

/**
 * Acceptance tests — overlay citation path (W2_ARCHITECTURE.md §4): the live
 * chart mint's FHIR-typed tokens must resolve to a chart preview, not fail.
 *
 * The live read-through path mints `Observation:<uuid>`,
 * `MedicationRequest:<uuid>`, and `AllergyIntolerance:<uuid>` refs
 * (FhirChartMapper), and the panel ships exactly those tokens back to
 * `POST /api/copilot/source` on chip click. Before this fix the resolver's
 * match had no arm for them — every chart-fact click errored as
 * "Unsupported source token type". Contract: they resolve to the same
 * PHI-minimal `{type: 'chart', source_type, source_id}` shape the
 * `procedure_result`/`lists` arm returns; genuinely unknown types still
 * fail loud.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Services\Copilot\Routes;

use OpenEMR\Modules\Copilot\Chart\PhysicianContext;
use OpenEMR\Modules\Copilot\Routes\SourceResolverEndpoint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FhirChartTokenResolutionTest extends TestCase
{
    private PhysicianContext $physician;

    protected function setUp(): void
    {
        $this->physician = new PhysicianContext('dr-tran', 1);
    }

    /**
     * @return array<string, array{string, string, string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function fhirChartTokenProvider(): array
    {
        return [
            'lab observation' => ['Observation:ob-uuid-1', 'Observation', 'ob-uuid-1'],
            'medication request' => ['MedicationRequest:mr-uuid-1', 'MedicationRequest', 'mr-uuid-1'],
            'allergy intolerance' => ['AllergyIntolerance:al-uuid-1', 'AllergyIntolerance', 'al-uuid-1'],
        ];
    }

    #[DataProvider('fhirChartTokenProvider')]
    public function testLiveMintedFhirChartTokensResolveToTheChartPreview(string $token, string $sourceType, string $sourceId): void
    {
        $endpoint = SourceResolverEndpoint::forLiveResolution();

        $preview = $endpoint->handle($this->physician, [
            'token' => $token,
            'patient_uuid' => 'any-patient-uuid',
        ]);

        $this->assertSame('chart', $preview['type']);
        $this->assertSame($sourceType, $preview['source_type']);
        $this->assertSame($sourceId, $preview['source_id']);
    }

    public function testAGenuinelyUnknownSourceTypeStillFailsLoud(): void
    {
        $endpoint = SourceResolverEndpoint::forLiveResolution();

        $this->expectException(\DomainException::class);
        $endpoint->handle($this->physician, [
            'token' => 'NotAThing:whatever',
            'patient_uuid' => 'any-patient-uuid',
        ]);
    }
}
