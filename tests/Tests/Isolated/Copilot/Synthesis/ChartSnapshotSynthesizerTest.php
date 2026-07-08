<?php

/**
 * FROZEN acceptance tests — T9: one-pass synthesis (D9, AUDIT.md; ARCHITECTURE.md §3.3).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: meds, labs, allergies (and follow-up threads) are
 * reconciled together in a single pass — never per-source summaries, because
 * the dangerous interactions live *between* sources. Near-duplicate list
 * entries collapse with ALL provenance retained (SourceRef on every item —
 * groundwork for R6/R10), cross-source pairs are reachable from one
 * structure, and Unknown-currency items are surfaced, never dropped.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Synthesis;

use OpenEMR\Modules\Copilot\DataTrust\CurrencyStatus;
use OpenEMR\Modules\Copilot\Synthesis\AllergyEntry;
use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshot;
use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshotSynthesizer;
use OpenEMR\Modules\Copilot\Synthesis\FollowUpEntry;
use OpenEMR\Modules\Copilot\Synthesis\LabResultEntry;
use OpenEMR\Modules\Copilot\Synthesis\MedicationEntry;
use OpenEMR\Modules\Copilot\Synthesis\SourceRef;
use PHPUnit\Framework\TestCase;

class ChartSnapshotSynthesizerTest extends TestCase
{
    private static function ref(string $id): SourceRef
    {
        return new SourceRef('lists', $id);
    }

    private static function med(
        string $name,
        CurrencyStatus $status = CurrencyStatus::Current,
        string $refId = 'med-1',
    ): MedicationEntry {
        return new MedicationEntry($name, $status, [self::ref($refId)]);
    }

    private static function allergy(
        string $substance,
        CurrencyStatus $status = CurrencyStatus::Current,
        string $refId = 'all-1',
    ): AllergyEntry {
        return new AllergyEntry($substance, $status, [self::ref($refId)]);
    }

    private static function lab(string $analyte, ?float $value, ?string $unit, string $refId = 'lab-1'): LabResultEntry
    {
        return new LabResultEntry(
            $analyte,
            $value,
            $unit,
            new \DateTimeImmutable('2026-07-01 08:00:00'),
            [self::ref($refId)],
        );
    }

    public function testSourceRefRejectsEmptyComponents(): void
    {
        $this->expectException(\DomainException::class);
        new SourceRef('', 'med-1');
    }

    public function testEntriesWithoutProvenanceCannotExist(): void
    {
        $this->expectException(\DomainException::class);
        new MedicationEntry('Lisinopril 10mg', CurrencyStatus::Current, []);
    }

    public function testOnePassSnapshotHoldsAllFourSources(): void
    {
        $snapshot = (new ChartSnapshotSynthesizer())->synthesize(
            [self::med('Lisinopril 10mg')],
            [self::lab('Potassium', 4.1, 'mmol/L')],
            [self::allergy('Penicillin')],
            [new FollowUpEntry('Recheck TSH', new \DateTimeImmutable('2026-08-01'), true, [self::ref('fu-1')])],
        );

        $this->assertInstanceOf(ChartSnapshot::class, $snapshot);
        $this->assertCount(1, $snapshot->medications);
        $this->assertCount(1, $snapshot->labs);
        $this->assertCount(1, $snapshot->allergies);
        $this->assertCount(1, $snapshot->followUps);
    }

    public function testNearDuplicateMedicationsCollapseWithAllProvenanceRetained(): void
    {
        $snapshot = (new ChartSnapshotSynthesizer())->synthesize(
            [
                self::med('Lisinopril 10mg', CurrencyStatus::Current, 'med-1'),
                self::med('  lisinopril 10MG ', CurrencyStatus::Current, 'med-2'),
            ],
            [],
            [],
        );

        $this->assertCount(1, $snapshot->medications, 'Near-duplicates (case/whitespace) must collapse.');
        $sourceIds = array_map(
            static fn (SourceRef $ref): string => $ref->sourceId,
            $snapshot->medications[0]->sources,
        );
        sort($sourceIds);
        $this->assertSame(['med-1', 'med-2'], $sourceIds, 'Both source records must remain for provenance.');
    }

    public function testDifferentCurrencyStatusesNeverMerge(): void
    {
        $snapshot = (new ChartSnapshotSynthesizer())->synthesize(
            [
                self::med('Warfarin 5mg', CurrencyStatus::Current, 'med-1'),
                self::med('Warfarin 5mg', CurrencyStatus::NotCurrent, 'med-2'),
            ],
            [],
            [],
        );

        $this->assertCount(
            2,
            $snapshot->medications,
            'A discontinued row and a current row are different facts — merging would launder D10 state.'
        );
    }

    public function testCurrentMedicationsExcludeNotCurrentAndUnknown(): void
    {
        $snapshot = (new ChartSnapshotSynthesizer())->synthesize(
            [
                self::med('Lisinopril 10mg', CurrencyStatus::Current, 'med-1'),
                self::med('Atorvastatin 20mg', CurrencyStatus::NotCurrent, 'med-2'),
                self::med('Metformin 500mg', CurrencyStatus::Unknown, 'med-3'),
            ],
            [],
            [],
        );

        $names = array_map(
            static fn (MedicationEntry $entry): string => $entry->name,
            $snapshot->currentMedications(),
        );
        $this->assertSame(['Lisinopril 10mg'], $names);
    }

    public function testUnknownCurrencyItemsAreSurfacedNotDropped(): void
    {
        $snapshot = (new ChartSnapshotSynthesizer())->synthesize(
            [self::med('Metformin 500mg', CurrencyStatus::Unknown, 'med-3')],
            [],
            [self::allergy('Sulfa', CurrencyStatus::Unknown, 'all-2')],
        );

        $unknown = $snapshot->unknownCurrencyEntries();
        $this->assertCount(2, $unknown, 'Unevaluable rows must surface for honest-uncertainty UX (R11).');
    }

    public function testMedicationAllergyPairsCrossOnlyCurrentSources(): void
    {
        $snapshot = (new ChartSnapshotSynthesizer())->synthesize(
            [
                self::med('Amoxicillin 500mg', CurrencyStatus::Current, 'med-1'),
                self::med('Lisinopril 10mg', CurrencyStatus::Current, 'med-2'),
                self::med('Ibuprofen 400mg', CurrencyStatus::NotCurrent, 'med-3'),
            ],
            [],
            [
                self::allergy('Penicillin', CurrencyStatus::Current, 'all-1'),
                self::allergy('Latex', CurrencyStatus::Current, 'all-2'),
            ],
        );

        $pairs = $snapshot->medicationAllergyPairs();
        $this->assertCount(4, $pairs, '2 current meds x 2 current allergies; the discontinued med joins no pair.');

        foreach ($pairs as $pair) {
            $this->assertInstanceOf(MedicationEntry::class, $pair[0]);
            $this->assertInstanceOf(AllergyEntry::class, $pair[1]);
            $this->assertNotSame('Ibuprofen 400mg', $pair[0]->name);
        }
    }

    public function testOpenFollowUpsAreExposedFromTheSnapshot(): void
    {
        $snapshot = (new ChartSnapshotSynthesizer())->synthesize(
            [],
            [],
            [],
            [
                new FollowUpEntry('Recheck TSH in 3 months', new \DateTimeImmutable('2026-04-01'), true, [self::ref('fu-1')]),
                new FollowUpEntry('Colonoscopy discussed', null, false, [self::ref('fu-2')]),
            ],
        );

        $open = $snapshot->openFollowUps();
        $this->assertCount(1, $open);
        $this->assertSame('Recheck TSH in 3 months', $open[0]->description);
    }
}
