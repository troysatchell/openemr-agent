<?php

/**
 * FROZEN acceptance tests — absence markers at the LLM payload boundary
 * (D1/R13; ARCHITECTURE.md §3; PHASE0.md §3a).
 *
 * Authored by the orchestrator from the closeout decision and frozen:
 * implementation agents make these pass and MUST NOT modify this file.
 *
 * Contract under test: a chart with ZERO recorded entries for a data class
 * the chart WAS assessed for (known-absent — e.g. NKDA) must be
 * distinguishable, at the payload boundary, from a chart NEVER assessed for
 * that class (unknown) — for allergies, medications, and follow_ups alike.
 * D1 is the audit's core lesson: '' (absence of data) must never read as
 * "known empty". The marker is metadata about the chart, carries no PHI
 * content, and is disclosed like everything else that crosses (C1/C5).
 * Minimum-necessary is a COMPRESSION rule; honest-uncertainty is a
 * PRESERVATION rule — trimming must never destroy this distinction
 * (ARCHITECTURE.md §3).
 *
 * CurrencyStatus::Unknown gets ONE canonical wire token via ONE mapper used
 * for ALL data classes — never a per-class encoding, and never a value
 * inferable as false/empty. Input convention at the builder boundary: a
 * data class key PRESENT with an empty entry list = assessed-and-empty; a
 * data class key ABSENT = never assessed.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot\Llm;

use OpenEMR\Modules\Copilot\DataTrust\CurrencyStatus;
use OpenEMR\Modules\Copilot\Llm\CopilotTask;
use OpenEMR\Modules\Copilot\Llm\CurrencyWire;
use OpenEMR\Modules\Copilot\Llm\DisclosedPayload;
use OpenEMR\Modules\Copilot\Llm\FieldAllowlist;
use OpenEMR\Modules\Copilot\Llm\MinimumNecessaryPayloadBuilder;
use PHPUnit\Framework\TestCase;

class AbsenceMarkerPayloadTest extends TestCase
{
    private const MARKER_CLASS = 'chart_assessment';

    private static function when(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-07-08 21:30:00', new \DateTimeZone('UTC'));
    }

    private static function builder(): MinimumNecessaryPayloadBuilder
    {
        return new MinimumNecessaryPayloadBuilder([
            CopilotTask::Snapshot->value => new FieldAllowlist([
                'medications' => ['name', 'status'],
                'lab_results' => ['analyte', 'value', 'unit'],
                'allergies' => ['substance', 'status'],
                'follow_ups' => ['description', 'due'],
            ]),
        ]);
    }

    /**
     * @param array<string, mixed> $chartData
     */
    private static function build(array $chartData): DisclosedPayload
    {
        return self::builder()->build(CopilotTask::Snapshot, $chartData, 'ellis.tran', 42, self::when());
    }

    public function testUnknownHasOneCanonicalNonFalsyWireToken(): void
    {
        $unknown = CurrencyWire::status(CurrencyStatus::Unknown);

        $this->assertSame(CurrencyWire::UNKNOWN, $unknown, 'One canonical token, not a per-call invention.');
        $this->assertNotSame('', trim($unknown), 'Unknown must never be blank — blank is D1, the bug this exists to kill.');
        $this->assertNotContains(
            strtolower($unknown),
            ['false', '0', 'no', 'none', 'null'],
            'Unknown must not be spellable as false or as known-absence.',
        );

        $tokens = [
            CurrencyWire::status(CurrencyStatus::Current),
            CurrencyWire::status(CurrencyStatus::NotCurrent),
            CurrencyWire::status(CurrencyStatus::Unknown),
        ];
        $this->assertCount(3, array_unique($tokens), 'The three currency states must stay distinguishable on the wire.');
    }

    public function testKnownAbsentAndUnknownAreDistinctTokens(): void
    {
        $this->assertNotSame(
            CurrencyWire::KNOWN_ABSENT,
            CurrencyWire::UNKNOWN,
            'Assessed-empty collapsing into never-assessed is the exact D1 failure at the boundary that matters most.',
        );
        $this->assertNotSame('', trim(CurrencyWire::KNOWN_ABSENT));
    }

    public function testAssessedEmptyIsDistinguishableFromNeverAssessedForEveryDataClass(): void
    {
        foreach (['allergies', 'medications', 'follow_ups'] as $dataClass) {
            // Same chart otherwise: one disclosable lab keeps the send non-empty.
            $anchor = ['lab_results' => [['analyte' => 'Potassium', 'value' => 4.1, 'unit' => 'mmol/L']]];

            $assessedEmpty = self::build($anchor + [$dataClass => []]);
            $neverAssessed = self::build($anchor);

            $this->assertSame(
                CurrencyWire::KNOWN_ABSENT,
                $assessedEmpty->payload[self::MARKER_CLASS][$dataClass] ?? null,
                sprintf('Assessed-and-empty %s must cross as known-absent.', $dataClass),
            );
            $this->assertSame(
                CurrencyWire::UNKNOWN,
                $neverAssessed->payload[self::MARKER_CLASS][$dataClass] ?? null,
                sprintf('Never-assessed %s must cross as the canonical Unknown token — absence of a marker is not a marker.', $dataClass),
            );
        }
    }

    public function testClassesWithDisclosedEntriesCarryNoAbsenceMarker(): void
    {
        $disclosed = self::build([
            'medications' => [['name' => 'Lisinopril 10mg', 'status' => 'current']],
            'allergies' => [],
        ]);

        $marker = $disclosed->payload[self::MARKER_CLASS];
        $this->assertIsArray($marker);
        $this->assertArrayNotHasKey(
            'medications',
            $marker,
            'Entries already say the class was assessed — a redundant marker is noise the compression rule may not add.',
        );
        $this->assertSame(
            [['name' => 'Lisinopril 10mg', 'status' => 'current']],
            $disclosed->payload['medications'],
            'The marker channel is additive: entry-bearing classes keep their exact shape.',
        );
    }

    public function testTheMarkerChannelIsDisclosedLikeAnyOtherCrossing(): void
    {
        $disclosed = self::build([
            'medications' => [['name' => 'Lisinopril 10mg', 'status' => 'current']],
        ]);

        $this->assertContains(
            self::MARKER_CLASS,
            $disclosed->disclosure->dataClasses,
            'The marker crosses the boundary, so the disclosure must enumerate it (C1) — DisclosedPayload guarantees the reverse.',
        );
    }

    public function testAKnownAbsentOnlyChartIsAValidSendNotARefusal(): void
    {
        $disclosed = self::build(['allergies' => []]);

        $this->assertSame(
            CurrencyWire::KNOWN_ABSENT,
            $disclosed->payload[self::MARKER_CLASS]['allergies'] ?? null,
            'NKDA is knowledge — an assessed-empty chart must be sendable, not silently refused.',
        );
    }

    public function testAWhollyUnknownChartStaysARefusal(): void
    {
        $this->expectException(\DomainException::class);
        self::build([]);
    }

    public function testNonAllowlistedClassesNeverAppearInTheMarkerChannel(): void
    {
        $disclosed = self::build([
            'medications' => [['name' => 'Lisinopril 10mg', 'status' => 'current']],
            'billing_notes' => [],
        ]);

        $marker = $disclosed->payload[self::MARKER_CLASS];
        $this->assertIsArray($marker);
        $this->assertArrayNotHasKey(
            'billing_notes',
            $marker,
            'The marker channel is policy-scoped: minimum-necessary compresses; it never adds classes the task may not see.',
        );
    }
}
