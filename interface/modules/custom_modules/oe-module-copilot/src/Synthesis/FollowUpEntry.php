<?php

/**
 * Follow-up thread entry in a one-pass chart synthesis (T9).
 *
 * Open follow-ups are a must-not-miss item guaranteed by deterministic
 * rules, never model salience. dueDate is nullable by design (AUDIT D0/D6:
 * dates are NULL, zero, or free text) — an undated open loop is still an
 * open loop.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Synthesis;

final readonly class FollowUpEntry
{
    /**
     * @param list<SourceRef> $sources
     */
    public function __construct(
        public string $description,
        public ?\DateTimeImmutable $dueDate,
        public bool $open,
        public array $sources,
    ) {
        if ($sources === []) {
            throw new \DomainException('FollowUpEntry requires at least one SourceRef (provenance is mandatory)');
        }
    }
}
