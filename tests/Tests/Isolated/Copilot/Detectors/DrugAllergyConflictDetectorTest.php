<?php

/**
 * FROZEN acceptance tests — T10: drug–allergy conflict detector (R13, UC4;
 * ARCHITECTURE.md §6).
 *
 * Authored by the orchestrator from the ticket's acceptance criteria and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: a conflict fires when a CURRENT medication's name
 * contains (word-boundary, case-insensitive) either the allergy substance
 * itself or a member ingredient of the allergy's class (typed class map).
 * Unknown currency on either side of a would-be conflict is surfaced as
 * unevaluable. Class-map CONTENTS are founder-adjudicated DRAFT; only the
 * unambiguous classic is asserted against it.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Detectors;

use OpenEMR\Modules\Copilot\DataTrust\CurrencyStatus;
use OpenEMR\Modules\Copilot\Detectors\AllergyClassMap;
use OpenEMR\Modules\Copilot\Detectors\CriticalFindingType;
use OpenEMR\Modules\Copilot\Detectors\DrugAllergyConflictDetector;
use OpenEMR\Modules\Copilot\Synthesis\AllergyEntry;
use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshot;
use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshotSynthesizer;
use OpenEMR\Modules\Copilot\Synthesis\MedicationEntry;
use OpenEMR\Modules\Copilot\Synthesis\SourceRef;
use PHPUnit\Framework\TestCase;

class DrugAllergyConflictDetectorTest extends TestCase
{
    private static function classes(): AllergyClassMap
    {
        return new AllergyClassMap([
            'penicillins' => ['penicillin', 'amoxicillin', 'ampicillin'],
        ]);
    }

    private static function med(
        string $name,
        CurrencyStatus $status = CurrencyStatus::Current,
        string $refId = 'med-1',
    ): MedicationEntry {
        return new MedicationEntry($name, $status, [new SourceRef('lists', $refId)]);
    }

    private static function allergy(
        string $substance,
        CurrencyStatus $status = CurrencyStatus::Current,
        string $refId = 'all-1',
    ): AllergyEntry {
        return new AllergyEntry($substance, $status, [new SourceRef('lists', $refId)]);
    }

    /**
     * @param list<MedicationEntry> $meds
     * @param list<AllergyEntry> $allergies
     */
    private static function snapshot(array $meds, array $allergies): ChartSnapshot
    {
        return (new ChartSnapshotSynthesizer())->synthesize($meds, [], $allergies);
    }

    public function testClassMemberConflictFiresWithProvenanceFromBothSides(): void
    {
        $report = (new DrugAllergyConflictDetector(self::classes()))->detect(self::snapshot(
            [self::med('Amoxicillin 500mg Capsule', CurrencyStatus::Current, 'med-amox')],
            [self::allergy('Penicillin', CurrencyStatus::Current, 'all-pcn')],
        ));

        $this->assertCount(1, $report->findings);
        $finding = $report->findings[0];
        $this->assertSame(CriticalFindingType::DrugAllergyConflict, $finding->type);

        $sourceIds = array_map(static fn (SourceRef $ref): string => $ref->sourceId, $finding->sources);
        sort($sourceIds);
        $this->assertSame(['all-pcn', 'med-amox'], $sourceIds);
    }

    public function testDirectSubstanceMatchFiresWithoutAClassEntry(): void
    {
        $report = (new DrugAllergyConflictDetector(new AllergyClassMap([])))->detect(self::snapshot(
            [self::med('Ibuprofen 400mg', CurrencyStatus::Current, 'med-ibu')],
            [self::allergy('ibuprofen', CurrencyStatus::Current, 'all-ibu')],
        ));

        $this->assertCount(1, $report->findings, 'The substance itself in a current med name is always a conflict.');
    }

    public function testNoConflictMeansSilence(): void
    {
        $report = (new DrugAllergyConflictDetector(self::classes()))->detect(self::snapshot(
            [self::med('Lisinopril 10mg')],
            [self::allergy('Penicillin')],
        ));

        $this->assertSame([], $report->findings, 'Silence when nothing conflicts (R7 — alert fatigue).');
        $this->assertSame([], $report->unevaluable);
    }

    public function testInactiveAllergyDoesNotFire(): void
    {
        $report = (new DrugAllergyConflictDetector(self::classes()))->detect(self::snapshot(
            [self::med('Amoxicillin 500mg')],
            [self::allergy('Penicillin', CurrencyStatus::NotCurrent)],
        ));

        $this->assertSame([], $report->findings);
        $this->assertSame([], $report->unevaluable);
    }

    public function testUnknownCurrencyOnEitherSideIsSurfacedAsUnevaluable(): void
    {
        $detector = new DrugAllergyConflictDetector(self::classes());

        $unknownAllergy = $detector->detect(self::snapshot(
            [self::med('Amoxicillin 500mg', CurrencyStatus::Current)],
            [self::allergy('Penicillin', CurrencyStatus::Unknown)],
        ));
        $this->assertSame([], $unknownAllergy->findings);
        $this->assertCount(1, $unknownAllergy->unevaluable, 'Unknown allergy currency must surface (R13).');

        $unknownMed = $detector->detect(self::snapshot(
            [self::med('Amoxicillin 500mg', CurrencyStatus::Unknown)],
            [self::allergy('Penicillin', CurrencyStatus::Current)],
        ));
        $this->assertSame([], $unknownMed->findings);
        $this->assertCount(1, $unknownMed->unevaluable, 'Unknown med currency must surface (R13).');
    }

    public function testDraftClassMapFlagsTheClassicCrossReactivity(): void
    {
        $report = (new DrugAllergyConflictDetector(AllergyClassMap::draftV1()))->detect(self::snapshot(
            [self::med('Amoxicillin 500mg Capsule', CurrencyStatus::Current, 'med-amox')],
            [self::allergy('Penicillin', CurrencyStatus::Current, 'all-pcn')],
        ));

        $this->assertCount(1, $report->findings, 'Penicillin allergy x amoxicillin fires under any sane draft map.');
    }

    public function testClassMapRejectsMalformedEntries(): void
    {
        $this->expectException(\DomainException::class);
        new AllergyClassMap(['penicillins' => []]);
    }
}
