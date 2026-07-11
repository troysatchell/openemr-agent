<?php

/**
 * The session panel's glanceable snapshot endpoint (T21; UC1 "90-second
 * re-orientation"; R5/R6/R10/R11/R13; AUDIT D1/D7/D9/D10;
 * ARCHITECTURE.md §4 session-bound delegation).
 *
 * Wire-shapes a patient uuid into the GlanceableSnapshot the panel renders,
 * mirroring TurnEndpoint's conventions (src/Routes/TurnEndpoint.php):
 * snake_case keys, finding types by enum name, every 'refs' entry a citation
 * object {token, kind, label} — the token minted via ReferenceIndex::tokenFor()
 * (the one canonical mint), the kind + label added by CitationIndex from the
 * same chart so the physician reads (and clicks) real provenance. A blank
 * patient_uuid is refused before any chart read or last-visit lookup
 * (mirrors TurnEndpoint::requireNonBlankString). Composes the REAL
 * deterministic detectors (CriticalSubsetDetectors — the critical subset is
 * guaranteed by code, never model salience, R13) and the REAL
 * SnapshotComposer over an injected ChartSnapshotProvider and last-visit
 * resolver. A failed chart read (FhirReadFailedException only — everything
 * else, including a failing resolver, propagates) degrades into an
 * explicit, generic error shape whose snapshot is null: a degraded response
 * can never be mistaken for a quiet chart (R11). No LLM crossing happens on
 * this path, so no disclosure is logged here (the turn path keeps its own).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Troy Satchell <troysatchell@gmail.com>
 * @copyright Copyright (c) 2026 Troy Satchell <troysatchell@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Panel;

use OpenEMR\Modules\Copilot\Chart\FhirReadFailedException;
use OpenEMR\Modules\Copilot\Chart\PhysicianContext;
use OpenEMR\Modules\Copilot\DataTrust\PatientDemographics;
use OpenEMR\Modules\Copilot\Detectors\CriticalFinding;
use OpenEMR\Modules\Copilot\Detectors\CriticalSubsetDetectors;
use OpenEMR\Modules\Copilot\Detectors\UnevaluableItem;
use OpenEMR\Modules\Copilot\Orchestration\ChartSnapshotProvider;
use OpenEMR\Modules\Copilot\Snapshot\ChangesBasis;
use OpenEMR\Modules\Copilot\Snapshot\GlanceableSnapshot;
use OpenEMR\Modules\Copilot\Snapshot\SnapshotComposer;
use OpenEMR\Modules\Copilot\Synthesis\AllergyEntry;
use OpenEMR\Modules\Copilot\Synthesis\LabResultEntry;
use OpenEMR\Modules\Copilot\Synthesis\MedicationEntry;
use OpenEMR\Modules\Copilot\Synthesis\SourceRef;
use OpenEMR\Modules\Copilot\Verification\CitationIndex;
use Psr\Clock\ClockInterface;

final readonly class SnapshotEndpoint
{
    /**
     * @param \Closure(int): ?\DateTimeImmutable $lastVisitResolver Contract:
     *        (int $pid): ?\DateTimeImmutable.
     */
    public function __construct(
        private ChartSnapshotProvider $provider,
        private CriticalSubsetDetectors $detectors,
        private SnapshotComposer $composer,
        private \Closure $lastVisitResolver,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $input Raw route input: 'patient_uuid' (string).
     *
     * @return array{
     *     degraded: bool,
     *     degraded_reason: ?string,
     *     snapshot: null|array{
     *         patient: array{pid: int, uuid: ?string, first_name: ?string, last_name: ?string, dob: ?string, sex: ?string},
     *         quiet: bool,
     *         changes_basis: string,
     *         must_not_miss: list<array{type: string, summary: string, refs: list<array{token: string, kind: string, label: string|null}>}>,
     *         unevaluable: list<array{reason: string, refs: list<array{token: string, kind: string, label: string|null}>}>,
     *         unknown_currency: list<array{kind: string, name: string, refs: list<array{token: string, kind: string, label: string|null}>}>,
     *         new_labs: list<array{analyte: string, value: ?float, unit: ?string, resulted_at: ?string, refs: list<array{token: string, kind: string, label: string|null}>}>,
     *         current_medications: list<array{name: string, refs: list<array{token: string, kind: string, label: string|null}>}>,
     *         current_allergies: list<array{substance: string, refs: list<array{token: string, kind: string, label: string|null}>}>,
     *     },
     * }
     *
     * @throws \DomainException when patient_uuid is blank/missing/non-string
     *         — refused before the chart read or the last-visit lookup ever
     *         runs.
     */
    public function handle(PhysicianContext $physician, array $input): array
    {
        $patientUuid = $this->requireNonBlankString($input, 'patient_uuid');

        try {
            $provided = $this->provider->provide($physician, $patientUuid);
        } catch (FhirReadFailedException) {
            // Degrade honestly (R11): the reason is generic, never the
            // originating exception's internals, and the snapshot is null —
            // never a fabricated "quiet" chart.
            return [
                'degraded' => true,
                'degraded_reason' => 'Unable to read the patient chart right now; please try again.',
                'snapshot' => null,
            ];
        }

        $reports = $this->detectors->detectAll($provided->chart, $this->clock->now());

        $lastVisitResolver = $this->lastVisitResolver;
        $lastVisit = $lastVisitResolver($provided->patient->pid);

        $snapshot = $this->composer->compose($provided->patient, $provided->chart, $reports, $lastVisit);

        return [
            'degraded' => false,
            'degraded_reason' => null,
            'snapshot' => $this->shapeSnapshot($snapshot, CitationIndex::fromChart($provided->chart)),
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function requireNonBlankString(array $input, string $key): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new \DomainException(sprintf('"%s" must be a non-blank string', $key));
        }

        return $value;
    }

    /**
     * @return array{
     *     patient: array{pid: int, uuid: ?string, first_name: ?string, last_name: ?string, dob: ?string, sex: ?string},
     *     quiet: bool,
     *     changes_basis: string,
     *     must_not_miss: list<array{type: string, summary: string, refs: list<array{token: string, kind: string, label: string|null}>}>,
     *     unevaluable: list<array{reason: string, refs: list<array{token: string, kind: string, label: string|null}>}>,
     *     unknown_currency: list<array{kind: string, name: string, refs: list<array{token: string, kind: string, label: string|null}>}>,
     *     new_labs: list<array{analyte: string, value: ?float, unit: ?string, resulted_at: ?string, refs: list<array{token: string, kind: string, label: string|null}>}>,
     *     current_medications: list<array{name: string, refs: list<array{token: string, kind: string, label: string|null}>}>,
     *     current_allergies: list<array{substance: string, refs: list<array{token: string, kind: string, label: string|null}>}>,
     * }
     */
    private function shapeSnapshot(GlanceableSnapshot $snapshot, CitationIndex $citations): array
    {
        return [
            'patient' => $this->shapePatient($snapshot->patient),
            'quiet' => $snapshot->isQuiet(),
            'changes_basis' => $this->shapeChangesBasis($snapshot->changesBasis),
            'must_not_miss' => $this->shapeFindings($snapshot->mustNotMiss, $citations),
            'unevaluable' => $this->shapeUnevaluable($snapshot->unevaluable, $citations),
            'unknown_currency' => $this->shapeUnknownCurrency($snapshot->unknownCurrency, $citations),
            'new_labs' => $this->shapeLabs($snapshot->newLabs, $citations),
            'current_medications' => $this->shapeMedications($snapshot->currentMedications, $citations),
            'current_allergies' => $this->shapeAllergies($snapshot->currentAllergies, $citations),
        ];
    }

    /**
     * @return array{pid: int, uuid: ?string, first_name: ?string, last_name: ?string, dob: ?string, sex: ?string}
     */
    private function shapePatient(PatientDemographics $patient): array
    {
        return [
            'pid' => $patient->pid,
            'uuid' => $patient->uuid,
            'first_name' => $patient->firstName,
            'last_name' => $patient->lastName,
            'dob' => $patient->dob,
            'sex' => $patient->sex,
        ];
    }

    /**
     * Exhaustive match, deliberately without a default branch: adding a new
     * ChangesBasis case must force this shaper to be updated, not silently
     * fall through (D1: the basis is never assumed).
     */
    private function shapeChangesBasis(ChangesBasis $basis): string
    {
        return match ($basis) {
            ChangesBasis::SinceLastVisit => 'since_last_visit',
            ChangesBasis::UnknownNoLastVisit => 'unknown_no_last_visit',
        };
    }

    /**
     * @param list<CriticalFinding> $findings
     *
     * @return list<array{type: string, summary: string, refs: list<array{token: string, kind: string, label: string|null}>}>
     */
    private function shapeFindings(array $findings, CitationIndex $citations): array
    {
        $shaped = [];
        foreach ($findings as $finding) {
            $shaped[] = [
                'type' => $finding->type->name,
                'summary' => $finding->summary,
                'refs' => $this->citationsFor($finding->sources, $citations),
            ];
        }

        return $shaped;
    }

    /**
     * @param list<UnevaluableItem> $items
     *
     * @return list<array{reason: string, refs: list<array{token: string, kind: string, label: string|null}>}>
     */
    private function shapeUnevaluable(array $items, CitationIndex $citations): array
    {
        $shaped = [];
        foreach ($items as $item) {
            $shaped[] = [
                'reason' => $item->reason,
                'refs' => $this->citationsFor($item->sources, $citations),
            ];
        }

        return $shaped;
    }

    /**
     * Unknown-currency meds/allergies get their own honest section, never
     * folded into "current" (D10).
     *
     * @param list<MedicationEntry|AllergyEntry> $entries
     *
     * @return list<array{kind: string, name: string, refs: list<array{token: string, kind: string, label: string|null}>}>
     */
    private function shapeUnknownCurrency(array $entries, CitationIndex $citations): array
    {
        $shaped = [];
        foreach ($entries as $entry) {
            if ($entry instanceof MedicationEntry) {
                $shaped[] = [
                    'kind' => 'medication',
                    'name' => $entry->name,
                    'refs' => $this->citationsFor($entry->sources, $citations),
                ];
                continue;
            }

            $shaped[] = [
                'kind' => 'allergy',
                'name' => $entry->substance,
                'refs' => $this->citationsFor($entry->sources, $citations),
            ];
        }

        return $shaped;
    }

    /**
     * @param list<LabResultEntry> $labs
     *
     * @return list<array{analyte: string, value: ?float, unit: ?string, resulted_at: ?string, refs: list<array{token: string, kind: string, label: string|null}>}>
     */
    private function shapeLabs(array $labs, CitationIndex $citations): array
    {
        $shaped = [];
        foreach ($labs as $lab) {
            $shaped[] = [
                'analyte' => $lab->analyte,
                'value' => $lab->value,
                'unit' => $lab->unit,
                'resulted_at' => $lab->resultedAt?->format(\DateTimeInterface::ATOM),
                'refs' => $this->citationsFor($lab->sources, $citations),
            ];
        }

        return $shaped;
    }

    /**
     * @param list<MedicationEntry> $medications
     *
     * @return list<array{name: string, refs: list<array{token: string, kind: string, label: string|null}>}>
     */
    private function shapeMedications(array $medications, CitationIndex $citations): array
    {
        $shaped = [];
        foreach ($medications as $medication) {
            $shaped[] = [
                'name' => $medication->name,
                'refs' => $this->citationsFor($medication->sources, $citations),
            ];
        }

        return $shaped;
    }

    /**
     * @param list<AllergyEntry> $allergies
     *
     * @return list<array{substance: string, refs: list<array{token: string, kind: string, label: string|null}>}>
     */
    private function shapeAllergies(array $allergies, CitationIndex $citations): array
    {
        $shaped = [];
        foreach ($allergies as $allergy) {
            $shaped[] = [
                'substance' => $allergy->substance,
                'refs' => $this->citationsFor($allergy->sources, $citations),
            ];
        }

        return $shaped;
    }

    /**
     * Each citation carries the exact grounding token (the one canonical mint,
     * ReferenceIndex::tokenFor(), via CitationIndex) plus the humanized kind
     * and record label the panel renders — provenance the physician can read
     * and click, never invented (R6/R10; DESIGN.md).
     *
     * @param list<SourceRef> $sources
     *
     * @return list<array{token: string, kind: string, label: string|null}>
     */
    private function citationsFor(array $sources, CitationIndex $citations): array
    {
        return array_map(
            static fn (SourceRef $source): array => $citations->describe($source),
            $sources,
        );
    }
}
