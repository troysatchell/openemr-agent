<?php

/**
 * FROZEN acceptance tests — T23: citation tokens carry a readable label
 * (R6/R10; DESIGN.md — provenance is part of the content).
 *
 * Authored by the orchestrator and frozen: implementation makes these pass
 * and MUST NOT modify this file.
 *
 * Contract under test: CitationIndex maps each chart citation token back to a
 * humanized record kind and the record's own display label — read from the
 * same chart the token was minted against, so a chip can only ever name a
 * record that exists. The token is preserved verbatim (it is the verifier's
 * grounding key). A token not present in the chart falls back to a humanized
 * kind with a null label — never a guessed name.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Verification;

use OpenEMR\Modules\Copilot\DataTrust\CurrencyStatus;
use OpenEMR\Modules\Copilot\Synthesis\AllergyEntry;
use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshot;
use OpenEMR\Modules\Copilot\Synthesis\FollowUpEntry;
use OpenEMR\Modules\Copilot\Synthesis\LabResultEntry;
use OpenEMR\Modules\Copilot\Synthesis\MedicationEntry;
use OpenEMR\Modules\Copilot\Synthesis\SourceRef;
use OpenEMR\Modules\Copilot\Verification\CitationIndex;
use PHPUnit\Framework\TestCase;

class CitationIndexTest extends TestCase
{
    private function chart(): ChartSnapshot
    {
        return new ChartSnapshot(
            [new MedicationEntry('Warfarin 5mg Tablet', CurrencyStatus::Current, [new SourceRef('MedicationRequest', 'mr-1')])],
            [new LabResultEntry('Potassium', 6.8, 'mmol/L', new \DateTimeImmutable('2026-07-07T07:00:00+00:00'), [new SourceRef('Observation', 'ob-1')])],
            [new AllergyEntry('Penicillin', CurrencyStatus::Current, [new SourceRef('AllergyIntolerance', 'al-1')])],
            [new FollowUpEntry('Recheck INR in 1 week', null, true, [new SourceRef('Condition', 'co-1')])],
        );
    }

    public function testDescribesEachRecordKindWithItsOwnLabelAndVerbatimToken(): void
    {
        $index = CitationIndex::fromChart($this->chart());

        $this->assertSame(
            ['token' => 'MedicationRequest:mr-1', 'kind' => 'Medication', 'label' => 'Warfarin 5mg Tablet'],
            $index->describe(new SourceRef('MedicationRequest', 'mr-1')),
        );
        $this->assertSame(
            ['token' => 'Observation:ob-1', 'kind' => 'Lab', 'label' => 'Potassium'],
            $index->describe(new SourceRef('Observation', 'ob-1')),
        );
        $this->assertSame(
            ['token' => 'AllergyIntolerance:al-1', 'kind' => 'Allergy', 'label' => 'Penicillin'],
            $index->describe(new SourceRef('AllergyIntolerance', 'al-1')),
        );
        $this->assertSame(
            ['token' => 'Condition:co-1', 'kind' => 'Follow-up', 'label' => 'Recheck INR in 1 week'],
            $index->describe(new SourceRef('Condition', 'co-1')),
        );
    }

    public function testUnknownTokenHumanizesTheTypeAndNeverInventsALabel(): void
    {
        $index = CitationIndex::fromChart($this->chart());

        // A token not in the chart: the type is humanized (best-effort) but the
        // label is null — the index never fabricates a record name (R6/R10).
        $this->assertSame(
            ['token' => 'MedicationRequest:not-in-chart', 'kind' => 'Medication', 'label' => null],
            $index->describe(new SourceRef('MedicationRequest', 'not-in-chart')),
        );
        $this->assertSame(
            ['token' => 'Observation:ghost', 'kind' => 'Lab', 'label' => null],
            $index->describe(new SourceRef('Observation', 'ghost')),
        );
    }

    public function testUnrecognizedTypeIsPassedThroughRatherThanGuessed(): void
    {
        $index = CitationIndex::fromChart(new ChartSnapshot([], [], [], []));

        $described = $index->describe(new SourceRef('SomethingExotic', 'x-1'));
        $this->assertSame('SomethingExotic', $described['kind'], 'An unknown type is passed through, not guessed.');
        $this->assertNull($described['label']);
        $this->assertSame('SomethingExotic:x-1', $described['token']);
    }

    public function testTokenMatchesTheOneCanonicalMint(): void
    {
        $index = CitationIndex::fromChart($this->chart());
        $ref = new SourceRef('MedicationRequest', 'mr-1');

        $this->assertSame(
            \OpenEMR\Modules\Copilot\Verification\ReferenceIndex::tokenFor($ref),
            $index->describe($ref)['token'],
            'The chip token IS the verifier grounding key — one canonical mint.',
        );
    }
}
