<?php

/**
 * A discarding {@see DisclosureLogger} for the eval gate's vendor-fixture
 * replay path (TRO-35; W2_ARCHITECTURE.md §2 step 3; AUDIT C1/C5).
 *
 * The gate's VLM crossings are replayed fixtures, not live disclosures to a
 * real vendor — {@see \OpenEMR\Modules\Copilot\Ingestion\VlmDocumentExtractor}
 * still calls this port unconditionally before every extraction (mirroring
 * production discipline exactly, so the gate exercises the real
 * disclosure-before-call ordering), but there is no production audit sink
 * to write into during a gate run. This implementation accepts every
 * disclosure and discards it — accepting, never throwing, keeps extraction
 * cases free of an unrelated failure mode this ticket does not own.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Eval;

use OpenEMR\Modules\Copilot\Audit\Disclosure;
use OpenEMR\Modules\Copilot\Audit\DisclosureLogger;

final class DiscardingDisclosureLogger implements DisclosureLogger
{
    public function record(Disclosure $disclosure): void
    {
    }
}
