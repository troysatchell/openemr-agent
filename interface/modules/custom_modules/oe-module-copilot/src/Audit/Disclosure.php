<?php

/**
 * External-AI disclosure record (T2).
 *
 * Every PHI crossing to the LLM is a logged, minimum-necessary disclosure
 * (AUDIT C1/C5; ARCHITECTURE §3.4). This value object answers all five
 * questions — who disclosed, which patient, what data classes, for what
 * purpose, when — and refuses to exist if any answer is missing. No silent
 * coercion: blank or missing fields are a \DomainException, never defaulted.
 *
 * The optional trailing correlationId (T17) is the ONLY join key between
 * this PHI-carrying disclosure record and the separate PHI-free trace log —
 * it lets a full turn be reconstructed from logs alone without ever mixing
 * the two records into one schema (ARCHITECTURE.md §6 observability).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Audit;

final readonly class Disclosure
{
    /**
     * @param list<string> $dataClasses
     */
    public function __construct(
        public string $userId,
        public int $patientPid,
        public array $dataClasses,
        public string $purpose,
        public \DateTimeImmutable $occurredAt,
        public ?string $correlationId = null,
    ) {
        if (trim($userId) === '') {
            throw new \DomainException('Disclosure requires the disclosing user — userId must be non-blank');
        }

        if ($patientPid <= 0) {
            throw new \DomainException('Disclosure requires a valid patient — pid must be a positive integer (pid is the trusted surrogate key — D7)');
        }

        if ($dataClasses === []) {
            throw new \DomainException('Disclosure requires at least one data class — an empty disclosure cannot be minimum-necessary (C1)');
        }

        foreach ($dataClasses as $dataClass) {
            if (trim($dataClass) === '') {
                throw new \DomainException('Disclosure data classes must all be non-blank — a blank class hides what was disclosed (C1)');
            }
        }

        if (trim($purpose) === '') {
            throw new \DomainException('Disclosure requires a purpose — purpose must be non-blank (C5)');
        }

        if ($correlationId !== null && trim($correlationId) === '') {
            throw new \DomainException('Disclosure correlationId, when provided, must be non-blank');
        }
    }
}
