<?php

/**
 * Result of one `/ready` dependency probe (TRO-28; PS-12; W2_ARCHITECTURE.md
 * §8).
 *
 * `detail` is required and must be non-blank whenever `status` is
 * `Degraded` — a nameless degradation helps nobody: `/ready` must be able to
 * name the failing dependency and the reason, not just flip a flag. `detail`
 * is optional (and typically null) when `status` is `Ok`.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Rag;

final readonly class ProbeResult
{
    public function __construct(
        public string $dependency,
        public ProbeStatus $status,
        public ?string $detail = null,
    ) {
        if (trim($this->dependency) === '') {
            throw new \DomainException('ProbeResult dependency must be non-blank');
        }

        if ($this->status === ProbeStatus::Degraded && ($this->detail === null || trim($this->detail) === '')) {
            throw new \DomainException('ProbeResult detail must be non-blank when status is Degraded (a nameless degradation helps nobody)');
        }

        if ($this->detail !== null && trim($this->detail) === '') {
            throw new \DomainException('ProbeResult detail, when present, must be non-blank');
        }
    }
}
