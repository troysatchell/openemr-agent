<?php

/**
 * Acceptance tests — overlay citation path (W2_ARCHITECTURE.md §4): a
 * lineage-backed lab's chart ref is rewritten so its citation resolves
 * through to the source document, never terminating at the derived pointer.
 *
 * Contract under test: DerivedLabSourceRewriter swaps a LabResultEntry's
 * `Observation:<uuid>` SourceRef for `derived_observation:<procedure_result_id>`
 * exactly when the injected lookup reports an extraction-lineage row for that
 * uuid — and leaves everything else untouched. Wired into
 * ReadThroughChartSnapshotProvider as an OPTIONAL collaborator: absent, the
 * Week 1 read-through path is byte-for-byte unchanged (the same pattern as
 * ClaimVerifier's optional grounding port). The chip label a physician reads
 * ("Lab · <analyte>") must survive the swap — CitationIndex labels whatever
 * ref the entry carries.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Chart;

use OpenEMR\Modules\Copilot\Chart\ChartReader;
use OpenEMR\Modules\Copilot\Chart\DerivedLabSourceRewriter;
use OpenEMR\Modules\Copilot\Chart\FhirChartMapper;
use OpenEMR\Modules\Copilot\Chart\FhirReadGateway;
use OpenEMR\Modules\Copilot\Chart\PhysicianContext;
use OpenEMR\Modules\Copilot\Orchestration\ReadThroughChartSnapshotProvider;
use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshotSynthesizer;
use OpenEMR\Modules\Copilot\Synthesis\LabResultEntry;
use OpenEMR\Modules\Copilot\Synthesis\SourceRef;
use OpenEMR\Modules\Copilot\Verification\CitationIndex;
use OpenEMR\Modules\Copilot\Verification\ReferenceIndex;
use PHPUnit\Framework\TestCase;

class DerivedLabSourceRewriterTest extends TestCase
{
    public const DERIVED_UUID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    private const PLAIN_UUID = '11111111-2222-3333-4444-555555555555';

    /**
     * @param list<SourceRef> $sources
     */
    private static function lab(string $analyte, array $sources): LabResultEntry
    {
        return new LabResultEntry($analyte, 6.8, 'mmol/L', new \DateTimeImmutable('2026-07-12T00:00:00+00:00'), $sources);
    }

    /**
     * @param array<string, int> $map
     * @param \ArrayObject<int, list<string>> $invocations Appended the uuid
     *        batch of every lookup invocation.
     */
    private static function rewriter(array $map, \ArrayObject $invocations): DerivedLabSourceRewriter
    {
        return new DerivedLabSourceRewriter(static function (array $uuids) use ($map, $invocations): array {
            $invocations->append($uuids);
            $found = [];
            foreach ($uuids as $uuid) {
                if (isset($map[$uuid])) {
                    $found[$uuid] = $map[$uuid];
                }
            }

            return $found;
        });
    }

    public function testLineageBackedObservationRefIsRewrittenToItsDerivedObservationResultId(): void
    {
        $invocations = new \ArrayObject();
        $rewriter = self::rewriter([self::DERIVED_UUID => 21], $invocations);

        $labs = $rewriter->rewrite([
            self::lab('Potassium', [new SourceRef('Observation', self::DERIVED_UUID)]),
        ]);

        $this->assertCount(1, $labs);
        $this->assertCount(1, $labs[0]->sources);
        $ref = $labs[0]->sources[0];
        $this->assertSame('derived_observation', $ref->sourceType, 'the citation must resolve through lineage to the source document — the derived pointer alone opens no overlay');
        $this->assertSame('21', $ref->sourceId);
        $this->assertSame('Potassium', $labs[0]->analyte, 'the clinical content of the entry survives the swap untouched');
        $this->assertSame(6.8, $labs[0]->value);
        $this->assertSame('mmol/L', $labs[0]->unit);
    }

    public function testLabsWithoutLineageKeepTheirChartRefsAndTheirInstances(): void
    {
        $invocations = new \ArrayObject();
        $rewriter = self::rewriter([self::DERIVED_UUID => 21], $invocations);
        $plain = self::lab('Sodium', [new SourceRef('Observation', self::PLAIN_UUID)]);

        $labs = $rewriter->rewrite([$plain]);

        $this->assertSame($plain, $labs[0], 'a lab with no lineage row is not rebuilt — the Week 1 ref stands');
        $this->assertSame('Observation', $labs[0]->sources[0]->sourceType);
    }

    public function testLookupIsBatchedOncePerRewriteAndSkippedWhenNoObservationRefsExist(): void
    {
        $invocations = new \ArrayObject();
        $rewriter = self::rewriter([self::DERIVED_UUID => 21], $invocations);

        $rewriter->rewrite([
            self::lab('Potassium', [new SourceRef('Observation', self::DERIVED_UUID)]),
            self::lab('Sodium', [new SourceRef('Observation', self::PLAIN_UUID)]),
        ]);
        $this->assertCount(1, $invocations, 'one chart read = one lineage lookup, never a query per lab');
        $this->assertEqualsCanonicalizing([self::DERIVED_UUID, self::PLAIN_UUID], $invocations[0], 'the one batch carries every Observation uuid on the chart');

        $rewriter->rewrite([
            self::lab('Hemoglobin', [new SourceRef('detector', 'panic_lab')]),
        ]);
        $this->assertCount(1, $invocations, 'no Observation refs on any lab means the lookup is never consulted');

        $rewriter->rewrite([]);
        $this->assertCount(1, $invocations, 'an empty chart never touches the lookup');
    }

    public function testNonObservationRefsAreNeverRewrittenEvenWhenTheirIdCollides(): void
    {
        $invocations = new \ArrayObject();
        $rewriter = self::rewriter([self::DERIVED_UUID => 21], $invocations);

        $labs = $rewriter->rewrite([
            self::lab('Potassium', [new SourceRef('detector', self::DERIVED_UUID)]),
        ]);

        $this->assertSame('detector', $labs[0]->sources[0]->sourceType, 'only Observation refs are candidates — a colliding id on another type is untouched');
    }

    public function testProviderRewritesLiveLabsAndTheChipLabelSurvivesTheSwap(): void
    {
        $invocations = new \ArrayObject();
        $gateway = new class implements FhirReadGateway {
            public function read(PhysicianContext $physician, string $resourceType, array $searchParams): array
            {
                return match ($resourceType) {
                    'Patient' => [[
                        'resourceType' => 'Patient',
                        'id' => 'uuid-1',
                        'name' => [['given' => ['Alma'], 'family' => 'Reyes']],
                        'birthDate' => '1961-03-14',
                        'gender' => 'female',
                    ]],
                    'Observation' => [[
                        'resourceType' => 'Observation',
                        'id' => DerivedLabSourceRewriterTest::DERIVED_UUID,
                        'category' => [['coding' => [['code' => 'laboratory']]]],
                        'code' => ['text' => 'Potassium'],
                        'valueQuantity' => ['value' => 4.4, 'unit' => 'mmol/L'],
                        'effectiveDateTime' => '2026-07-12T00:00:00+00:00',
                    ]],
                    default => [],
                };
            }
        };

        $provider = new ReadThroughChartSnapshotProvider(
            new ChartReader($gateway),
            new FhirChartMapper(),
            new ChartSnapshotSynthesizer(),
            static fn (string $uuid): int => 42,
            self::rewriter([self::DERIVED_UUID => 21], $invocations),
        );

        $provided = $provider->provide(new PhysicianContext('ellis.tran', 7), 'uuid-1');

        $this->assertCount(1, $provided->chart->labs);
        $ref = $provided->chart->labs[0]->sources[0];
        $this->assertSame('derived_observation', $ref->sourceType);
        $this->assertSame('21', $ref->sourceId);

        $index = ReferenceIndex::fromChart($provided->chart);
        $this->assertNotNull($index->resolve('derived_observation:21'), 'one mint: the rewritten ref must resolve in the same index the verifier grounds against');

        $chip = CitationIndex::fromChart($provided->chart)->describe($ref);
        $this->assertSame('Lab', $chip['kind'], 'the physician still reads "Lab · Potassium" — the token changed, the chip did not');
        $this->assertSame('Potassium', $chip['label']);
        $this->assertSame('derived_observation:21', $chip['token']);
    }
}
