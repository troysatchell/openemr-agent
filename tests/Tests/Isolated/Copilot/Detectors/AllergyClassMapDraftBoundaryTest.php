<?php

/**
 * Boundary regression tests for the draftV1 beta-lactam cross-link
 * (R13, UC4; ARCHITECTURE.md §6; PHASE0.md §3a.3 DA-2/DA-3).
 *
 * Not frozen. Failure mode guarded: before the beta-lactams umbrella was
 * added, a documented penicillin allergy plus a cephalexin/ceftriaxone/
 * cefdinir order was NOT flagged — a genuine omission in exactly the
 * drug-allergy surface the detector exists to guard (PHASE0.md §3a.5).
 * Citations: Cephalexin label (Lupin, rev. 09/2024) — cross-hypersensitivity
 * "up to 10%" with penicillin allergy; Amoxicillin label (NorthStar Rx,
 * rev. 02/2024) §4 — contraindicated on serious hypersensitivity to other
 * beta-lactams incl. cephalosporins.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
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

class AllergyClassMapDraftBoundaryTest extends TestCase
{
    private static function snapshot(string $medName, string $allergySubstance): ChartSnapshot
    {
        return (new ChartSnapshotSynthesizer())->synthesize(
            [new MedicationEntry($medName, CurrencyStatus::Current, [new SourceRef('lists', 'med-x')])],
            [],
            [new AllergyEntry($allergySubstance, CurrencyStatus::Current, [new SourceRef('lists', 'all-x')])],
        );
    }

    /**
     * Guards the DA-2 miss: penicillin allergy + cephalosporin order must
     * fire via the beta-lactams umbrella — the old map had no cephalosporin
     * class and no cross-link, so this exact chart was silent.
     */
    public function testPenicillinAllergyReachesACephalexinOrder(): void
    {
        $report = (new DrugAllergyConflictDetector(AllergyClassMap::draftV1()))
            ->detect(self::snapshot('Cephalexin 500mg', 'Penicillin'));

        $this->assertCount(1, $report->findings, 'Penicillin↔cephalosporin cross-reactivity (Lupin label, up to 10%).');
        $this->assertSame(CriticalFindingType::DrugAllergyConflict, $report->findings[0]->type);
    }

    /**
     * Guards the reverse DA-2 direction: a cephalosporin allergy must reach
     * a penicillin-class order through the same umbrella.
     */
    public function testCephalexinAllergyReachesAnAmoxicillinOrder(): void
    {
        $report = (new DrugAllergyConflictDetector(AllergyClassMap::draftV1()))
            ->detect(self::snapshot('Amoxicillin 500mg Capsule', 'Cephalexin'));

        $this->assertCount(1, $report->findings, 'The cross-link must traverse in both directions.');
    }

    /**
     * Guards DA-3: cephalosporin allergy + another cephalosporin (intra-class,
     * per the Lupin label's class-wide contraindication).
     */
    public function testCephalosporinAllergyReachesAnotherCephalosporin(): void
    {
        $report = (new DrugAllergyConflictDetector(AllergyClassMap::draftV1()))
            ->detect(self::snapshot('Cefdinir 300mg Capsule', 'Ceftriaxone'));

        $this->assertCount(1, $report->findings, 'Intra-cephalosporin conflicts must fire (DA-3).');
    }

    /**
     * Guards against the umbrella over-widening: a penicillin allergy must
     * not reach an unrelated antibiotic — over-flagging is the R7 churn path.
     */
    public function testPenicillinAllergyDoesNotReachAnUnrelatedAntibiotic(): void
    {
        $report = (new DrugAllergyConflictDetector(AllergyClassMap::draftV1()))
            ->detect(self::snapshot('Azithromycin 250mg', 'Penicillin'));

        $this->assertSame([], $report->findings, 'The beta-lactams umbrella must not swallow unrelated drugs (R7).');
        $this->assertSame([], $report->unevaluable);
    }
}
