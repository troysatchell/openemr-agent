<?php

/**
 * FROZEN acceptance tests — T10: drug–drug interaction detector (R13, UC4;
 * ARCHITECTURE.md §6).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: contraindicated pairs are a code guarantee. A pair
 * fires only when both ingredients appear among CURRENT medications
 * (word-boundary, case-insensitive ingredient match inside the med name).
 * A pair that would match except that one member's currency is Unknown is
 * surfaced as unevaluable — never silently passed. Pair-table CONTENTS are
 * founder-adjudicated DRAFT; only unambiguous classics are asserted against it.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Detectors;

use OpenEMR\Modules\Copilot\DataTrust\CurrencyStatus;
use OpenEMR\Modules\Copilot\Detectors\CriticalFindingType;
use OpenEMR\Modules\Copilot\Detectors\DrugDrugInteractionDetector;
use OpenEMR\Modules\Copilot\Detectors\InteractionPairs;
use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshot;
use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshotSynthesizer;
use OpenEMR\Modules\Copilot\Synthesis\MedicationEntry;
use OpenEMR\Modules\Copilot\Synthesis\SourceRef;
use PHPUnit\Framework\TestCase;

class DrugDrugInteractionDetectorTest extends TestCase
{
    private static function pairs(): InteractionPairs
    {
        return new InteractionPairs([
            ['warfarin', 'aspirin'],
            ['methotrexate', 'trimethoprim'],
        ]);
    }

    private static function med(
        string $name,
        CurrencyStatus $status = CurrencyStatus::Current,
        string $refId = 'med-1',
    ): MedicationEntry {
        return new MedicationEntry($name, $status, [new SourceRef('lists', $refId)]);
    }

    /**
     * @param list<MedicationEntry> $meds
     */
    private static function snapshot(array $meds): ChartSnapshot
    {
        return (new ChartSnapshotSynthesizer())->synthesize($meds, [], []);
    }

    public function testBothCurrentIngredientsFireOneFindingWithBothSources(): void
    {
        $report = (new DrugDrugInteractionDetector(self::pairs()))->detect(self::snapshot([
            self::med('Warfarin 5mg Tablet', CurrencyStatus::Current, 'med-w'),
            self::med('ASPIRIN 81 mg chewable', CurrencyStatus::Current, 'med-a'),
            self::med('Lisinopril 10mg', CurrencyStatus::Current, 'med-l'),
        ]));

        $this->assertCount(1, $report->findings);
        $finding = $report->findings[0];
        $this->assertSame(CriticalFindingType::DrugDrugInteraction, $finding->type);

        $sourceIds = array_map(static fn (SourceRef $ref): string => $ref->sourceId, $finding->sources);
        sort($sourceIds);
        $this->assertSame(['med-a', 'med-w'], $sourceIds, 'Provenance must point at both interacting meds.');
    }

    public function testOnlyOneIngredientPresentMeansNoFinding(): void
    {
        $report = (new DrugDrugInteractionDetector(self::pairs()))->detect(self::snapshot([
            self::med('Warfarin 5mg Tablet'),
            self::med('Lisinopril 10mg', CurrencyStatus::Current, 'med-2'),
        ]));

        $this->assertSame([], $report->findings);
        $this->assertSame([], $report->unevaluable);
    }

    public function testDiscontinuedMedNeverJoinsAPair(): void
    {
        $report = (new DrugDrugInteractionDetector(self::pairs()))->detect(self::snapshot([
            self::med('Warfarin 5mg Tablet', CurrencyStatus::Current, 'med-w'),
            self::med('Aspirin 81mg', CurrencyStatus::NotCurrent, 'med-a'),
        ]));

        $this->assertSame([], $report->findings, 'A discontinued med is not an active interaction.');
        $this->assertSame([], $report->unevaluable);
    }

    public function testUnknownCurrencyPartnerIsSurfacedAsUnevaluable(): void
    {
        $report = (new DrugDrugInteractionDetector(self::pairs()))->detect(self::snapshot([
            self::med('Warfarin 5mg Tablet', CurrencyStatus::Current, 'med-w'),
            self::med('Aspirin 81mg', CurrencyStatus::Unknown, 'med-a'),
        ]));

        $this->assertSame([], $report->findings, 'Unknown currency cannot assert an active interaction.');
        $this->assertCount(
            1,
            $report->unevaluable,
            'A potentially dangerous pair blocked only by Unknown currency must be surfaced (R13).'
        );
    }

    public function testIngredientMatchRespectsWordBoundaries(): void
    {
        $report = (new DrugDrugInteractionDetector(new InteractionPairs([['aspirin', 'warfarin']])))
            ->detect(self::snapshot([
                // "warfarin" must not match inside another token
                self::med('Nowarfarinol 10mg (fictional)', CurrencyStatus::Current, 'med-x'),
                self::med('Aspirin 81mg', CurrencyStatus::Current, 'med-a'),
            ]));

        $this->assertSame([], $report->findings);
    }

    public function testDraftTableFlagsTheClassicPair(): void
    {
        $report = (new DrugDrugInteractionDetector(InteractionPairs::draftV1()))->detect(self::snapshot([
            self::med('Warfarin 5mg Tablet', CurrencyStatus::Current, 'med-w'),
            self::med('Aspirin 81mg Tablet', CurrencyStatus::Current, 'med-a'),
        ]));

        $this->assertCount(1, $report->findings, 'Warfarin plus aspirin is flagged under any sane draft table.');
    }

    public function testPairTableRejectsMalformedEntries(): void
    {
        $this->expectException(\DomainException::class);
        new InteractionPairs([['warfarin', '']]);
    }
}
