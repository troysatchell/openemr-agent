<?php

/**
 * FROZEN acceptance tests — T13: glanceable snapshot composer (UC1;
 * ARCHITECTURE.md §1/§6; R5/R7/R11; AUDIT D0/D1/D6/D10).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: the snapshot is deterministic PRESENTATION SHAPING of
 * already-guaranteed content — it never invents, reorders, dedupes, or drops
 * a detector finding (the detectors own must-not-miss; the composer owns
 * layout). Honest uncertainty is structural: unknown-currency entries and
 * unevaluable items surface in their own sections; an unknown change-delta
 * (no last-visit reference date) is UNKNOWN, never "no changes" (D1); an
 * undated lab cannot be placed in a timeline and is said so (D0/D6).
 * "Silence when nothing changed" (R5/R7) is an explicit, computed state —
 * quiet only when there is affirmatively nothing to say. Pure: no I/O, no
 * clock reads, no globals — the last-visit reference date is injected.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Snapshot;

use OpenEMR\Modules\Copilot\DataTrust\CurrencyStatus;
use OpenEMR\Modules\Copilot\DataTrust\PatientDemographics;
use OpenEMR\Modules\Copilot\Detectors\CriticalFinding;
use OpenEMR\Modules\Copilot\Detectors\CriticalFindingType;
use OpenEMR\Modules\Copilot\Detectors\DetectorReport;
use OpenEMR\Modules\Copilot\Detectors\UnevaluableItem;
use OpenEMR\Modules\Copilot\Snapshot\ChangesBasis;
use OpenEMR\Modules\Copilot\Snapshot\GlanceableSnapshot;
use OpenEMR\Modules\Copilot\Snapshot\SnapshotComposer;
use OpenEMR\Modules\Copilot\Synthesis\AllergyEntry;
use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshot;
use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshotSynthesizer;
use OpenEMR\Modules\Copilot\Synthesis\LabResultEntry;
use OpenEMR\Modules\Copilot\Synthesis\MedicationEntry;
use OpenEMR\Modules\Copilot\Synthesis\SourceRef;
use PHPUnit\Framework\TestCase;

class SnapshotComposerTest extends TestCase
{
    private static function patient(): PatientDemographics
    {
        return new PatientDemographics(42, null, 'Alma', 'Reyes', '1961-03-14', 'F');
    }

    private static function med(string $name, CurrencyStatus $status, string $refId): MedicationEntry
    {
        return new MedicationEntry($name, $status, [new SourceRef('lists', $refId)]);
    }

    private static function allergy(string $substance, CurrencyStatus $status, string $refId): AllergyEntry
    {
        return new AllergyEntry($substance, $status, [new SourceRef('lists', $refId)]);
    }

    private static function lab(string $analyte, ?string $resultedAt, string $refId): LabResultEntry
    {
        return new LabResultEntry(
            $analyte,
            4.5,
            'mmol/L',
            $resultedAt === null ? null : new \DateTimeImmutable($resultedAt),
            [new SourceRef('procedure_result', $refId)],
        );
    }

    private static function finding(string $summary, string $refId): CriticalFinding
    {
        return new CriticalFinding(
            CriticalFindingType::PanicLab,
            $summary,
            [new SourceRef('procedure_result', $refId)],
        );
    }

    private static function unevaluable(string $summary, string $refId): UnevaluableItem
    {
        return new UnevaluableItem($summary, [new SourceRef('lists', $refId)]);
    }

    /**
     * @param list<MedicationEntry> $meds
     * @param list<LabResultEntry> $labs
     * @param list<AllergyEntry> $allergies
     */
    private static function chart(array $meds = [], array $labs = [], array $allergies = []): ChartSnapshot
    {
        return (new ChartSnapshotSynthesizer())->synthesize($meds, $labs, $allergies);
    }

    public function testDetectorFindingsAreCarriedVerbatimInReportOrder(): void
    {
        $first = self::finding('Panic lab: potassium 6.8', 'lab-k');
        $second = self::finding('Panic lab: glucose 480', 'lab-glu');
        $third = self::finding('Panic lab: sodium 112', 'lab-na');

        $snapshot = (new SnapshotComposer())->compose(
            self::patient(),
            self::chart(),
            [new DetectorReport([$first, $second], []), new DetectorReport([$third], [])],
            new \DateTimeImmutable('2026-07-01'),
        );

        $this->assertSame(
            [$first, $second, $third],
            $snapshot->mustNotMiss,
            'The composer shapes presentation; the detectors own must-not-miss content. '
            . 'Same objects, same order — never reordered, deduped, invented, or dropped (R13).',
        );
        foreach ($snapshot->mustNotMiss as $finding) {
            $this->assertNotSame([], $finding->sources, 'Provenance is mandatory on every surfaced item.');
        }
    }

    public function testUnevaluableItemsAreCarriedNotDropped(): void
    {
        $item = self::unevaluable('Possible conflict cannot be confirmed: unknown currency (D10)', 'all-1');

        $snapshot = (new SnapshotComposer())->compose(
            self::patient(),
            self::chart(),
            [new DetectorReport([], [$item])],
            new \DateTimeImmutable('2026-07-01'),
        );

        $this->assertContains($item, $snapshot->unevaluable, 'Unevaluable is a first-class section — silence is the failure mode (R13/R11).');
    }

    public function testUnknownCurrencyEntriesSurfaceInTheirOwnSection(): void
    {
        $unknownMed = self::med('Metformin 500mg', CurrencyStatus::Unknown, 'med-met');
        $unknownAllergy = self::allergy('Latex', CurrencyStatus::Unknown, 'all-latex');

        $snapshot = (new SnapshotComposer())->compose(
            self::patient(),
            self::chart([$unknownMed], [], [$unknownAllergy]),
            [],
            new \DateTimeImmutable('2026-07-01'),
        );

        $this->assertCount(2, $snapshot->unknownCurrency, 'Unknown currency is surfaced, never silently treated as current or dropped (D10).');
        $this->assertSame([], $snapshot->currentMedications, 'An unknown-currency med must not masquerade as current.');
        $this->assertSame([], $snapshot->currentAllergies);
    }

    public function testNewLabsAreStrictlyAfterTheLastVisit(): void
    {
        $before = self::lab('Potassium', '2026-06-15 08:00:00', 'lab-old');
        $atVisit = self::lab('Sodium', '2026-07-01 00:00:00', 'lab-at');
        $after = self::lab('Glucose', '2026-07-03 09:30:00', 'lab-new');

        $snapshot = (new SnapshotComposer())->compose(
            self::patient(),
            self::chart([], [$before, $atVisit, $after]),
            [],
            new \DateTimeImmutable('2026-07-01 00:00:00'),
        );

        $this->assertSame(ChangesBasis::SinceLastVisit, $snapshot->changesBasis);
        $this->assertSame([$after], $snapshot->newLabs, 'Only labs resulted strictly after the reference date are "new" — the boundary itself is not.');
    }

    public function testUndatedLabIsUnplaceableWhenADeltaIsClaimed(): void
    {
        $undated = self::lab('Potassium', null, 'lab-undated');

        $snapshot = (new SnapshotComposer())->compose(
            self::patient(),
            self::chart([], [$undated]),
            [],
            new \DateTimeImmutable('2026-07-01'),
        );

        $this->assertSame([], $snapshot->newLabs, 'An undated lab must never be claimed as new (D0/D6).');
        $this->assertCount(
            1,
            $snapshot->unevaluable,
            'An undated lab cannot be placed relative to the last visit — say so, never silently omit it.',
        );
        $this->assertNotSame([], $snapshot->unevaluable[0]->sources);
    }

    public function testNoLastVisitDateMeansTheDeltaIsUnknownNotEmpty(): void
    {
        $snapshot = (new SnapshotComposer())->compose(
            self::patient(),
            self::chart([], [self::lab('Potassium', '2026-07-03', 'lab-1')]),
            [],
            null,
        );

        $this->assertSame(
            ChangesBasis::UnknownNoLastVisit,
            $snapshot->changesBasis,
            'No reference date => the delta is UNKNOWN — absence of data is never "known empty" (D1).',
        );
        $this->assertSame([], $snapshot->newLabs, 'No delta may be claimed without a reference date.');
        $this->assertFalse(
            $snapshot->isQuiet(),
            '"Nothing changed" may not be said when we do not know what changed (R5/R11).',
        );
    }

    public function testQuietChartIsExplicitlyQuiet(): void
    {
        $snapshot = (new SnapshotComposer())->compose(
            self::patient(),
            self::chart(
                [self::med('Lisinopril 10mg', CurrencyStatus::Current, 'med-lis')],
                [self::lab('Potassium', '2026-06-01', 'lab-1')],
                [self::allergy('Penicillin', CurrencyStatus::Current, 'all-pcn')],
            ),
            [new DetectorReport([], [])],
            new \DateTimeImmutable('2026-07-01'),
        );

        $this->assertTrue(
            $snapshot->isQuiet(),
            'No findings, nothing unevaluable, nothing unknown, no new labs => affirmatively quiet (R5/R7). '
            . 'Standing meds/allergies are state, not change — they do not break the silence.',
        );
        $this->assertCount(1, $snapshot->currentMedications, 'Quiet does not mean empty: orientation content is still there.');
        $this->assertCount(1, $snapshot->currentAllergies);
    }

    public function testAnyMustNotMissFindingBreaksQuiet(): void
    {
        $snapshot = (new SnapshotComposer())->compose(
            self::patient(),
            self::chart(),
            [new DetectorReport([self::finding('Panic lab: potassium 6.8', 'lab-k')], [])],
            new \DateTimeImmutable('2026-07-01'),
        );

        $this->assertFalse($snapshot->isQuiet());
    }

    public function testAnyUnevaluableItemBreaksQuiet(): void
    {
        $snapshot = (new SnapshotComposer())->compose(
            self::patient(),
            self::chart(),
            [new DetectorReport([], [self::unevaluable('Unknown currency (D10)', 'all-1')])],
            new \DateTimeImmutable('2026-07-01'),
        );

        $this->assertFalse($snapshot->isQuiet(), 'Honest uncertainty is content, not silence (R11).');
    }

    public function testOnlyCurrentEntriesAreListedAsCurrent(): void
    {
        $snapshot = (new SnapshotComposer())->compose(
            self::patient(),
            self::chart(
                [
                    self::med('Lisinopril 10mg', CurrencyStatus::Current, 'med-1'),
                    self::med('Old Statin 20mg', CurrencyStatus::NotCurrent, 'med-2'),
                    self::med('Metformin 500mg', CurrencyStatus::Unknown, 'med-3'),
                ],
                [],
                [
                    self::allergy('Penicillin', CurrencyStatus::Current, 'all-1'),
                    self::allergy('Sulfa', CurrencyStatus::NotCurrent, 'all-2'),
                ],
            ),
            [],
            new \DateTimeImmutable('2026-07-01'),
        );

        $this->assertCount(1, $snapshot->currentMedications, 'NotCurrent is excluded; Unknown lives in its own section — never laundered into current (D10).');
        $this->assertSame('Lisinopril 10mg', $snapshot->currentMedications[0]->name);
        $this->assertCount(1, $snapshot->currentAllergies);
        $this->assertCount(1, $snapshot->unknownCurrency);
    }

    public function testPatientIdentityRidesTheSnapshot(): void
    {
        $snapshot = (new SnapshotComposer())->compose(
            self::patient(),
            self::chart(),
            [],
            null,
        );

        $this->assertSame(42, $snapshot->patient->pid, 'Who the patient is — the snapshot opens with identity (UC1).');
    }

    public function testSnapshotRefusesAClaimedDeltaWithoutAReferenceBasis(): void
    {
        $this->expectException(\DomainException::class);
        new GlanceableSnapshot(
            patient: self::patient(),
            mustNotMiss: [],
            unevaluable: [],
            unknownCurrency: [],
            changesBasis: ChangesBasis::UnknownNoLastVisit,
            newLabs: [self::lab('Potassium', '2026-07-03', 'lab-1')],
            currentMedications: [],
            currentAllergies: [],
        );
    }
}
