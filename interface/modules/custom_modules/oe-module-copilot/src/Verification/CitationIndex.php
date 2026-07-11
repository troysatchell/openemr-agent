<?php

/**
 * Human-readable labelling for citation tokens (T23; R6/R10; DESIGN.md —
 * provenance is part of the content).
 *
 * The citation token minted by ReferenceIndex::tokenFor() ("sourceType:id")
 * is the verifier's exact grounding key, but it is not something a physician
 * reads — a raw FHIR UUID is honest and illegible at once. This index maps
 * each token back to what the physician recognizes: a humanized record KIND
 * (Medication / Lab / Allergy / Problem) and, where the chart carries one,
 * the record's own display LABEL (the med name, the analyte, the substance).
 *
 * The label is never invented: it is read from the same reconciled
 * ChartSnapshot the token was minted from, so a chip can only ever name a
 * record that actually exists in this patient's chart. A token not present
 * in the chart (defensive — a cited record should always be in it) falls
 * back to a humanized kind and no label, never a guess.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Verification;

use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshot;
use OpenEMR\Modules\Copilot\Synthesis\SourceRef;

final readonly class CitationIndex
{
    /**
     * @param array<string, array{kind: string, label: string}> $byToken
     */
    private function __construct(private array $byToken)
    {
    }

    /**
     * Walks every citable section of the chart (medications, labs, allergies,
     * follow-ups) and indexes each SourceRef's token to that record's kind
     * and display label. Duplicate tokens collapse to one — the same record
     * cited twice is one record.
     */
    public static function fromChart(ChartSnapshot $chart): self
    {
        $byToken = [];
        foreach ($chart->medications as $medication) {
            foreach ($medication->sources as $source) {
                $byToken[ReferenceIndex::tokenFor($source)] = ['kind' => 'Medication', 'label' => $medication->name];
            }
        }
        foreach ($chart->labs as $lab) {
            foreach ($lab->sources as $source) {
                $byToken[ReferenceIndex::tokenFor($source)] = ['kind' => 'Lab', 'label' => $lab->analyte];
            }
        }
        foreach ($chart->allergies as $allergy) {
            foreach ($allergy->sources as $source) {
                $byToken[ReferenceIndex::tokenFor($source)] = ['kind' => 'Allergy', 'label' => $allergy->substance];
            }
        }
        foreach ($chart->followUps as $followUp) {
            foreach ($followUp->sources as $source) {
                $byToken[ReferenceIndex::tokenFor($source)] = ['kind' => 'Follow-up', 'label' => $followUp->description];
            }
        }

        return new self($byToken);
    }

    /**
     * The wire shape for one citation chip: the exact grounding token (kept
     * for provenance fidelity), the humanized kind, and the record's label
     * (null when the token is not in the chart — never invented).
     *
     * @return array{token: string, kind: string, label: string|null}
     */
    public function describe(SourceRef $ref): array
    {
        $token = ReferenceIndex::tokenFor($ref);
        if (isset($this->byToken[$token])) {
            return [
                'token' => $token,
                'kind' => $this->byToken[$token]['kind'],
                'label' => $this->byToken[$token]['label'],
            ];
        }

        return [
            'token' => $token,
            'kind' => self::humanizeType($ref->sourceType),
            'label' => null,
        ];
    }

    /**
     * Best-effort humanization of a raw source/resource type for the
     * defensive no-label path. FHIR resource types (live read path) map to a
     * clinician-facing word; anything unrecognized is passed through
     * unchanged rather than guessed.
     */
    private static function humanizeType(string $sourceType): string
    {
        return match ($sourceType) {
            'MedicationRequest' => 'Medication',
            'Observation' => 'Lab',
            'AllergyIntolerance' => 'Allergy',
            'Condition' => 'Problem',
            default => $sourceType,
        };
    }
}
