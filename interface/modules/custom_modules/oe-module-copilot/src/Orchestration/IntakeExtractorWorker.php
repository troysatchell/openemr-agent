<?php

/**
 * The intake-extractor worker port (TRO-32; W2_ARCHITECTURE §6).
 *
 * Wraps the §2 ingestion/extraction flow for documents already attached to
 * the patient: find co-pilot documents not yet extracted, run VLM extraction,
 * and persist derived facts by fact shape. The supervisor depends on this
 * port abstractly — worker-level stubs exist ONLY for orchestration unit
 * tests; the eval gate always exercises the real implementation (§7).
 *
 * The TraceContext argument is the worker's CHILD span, derived by the
 * dispatcher from the turn span — the correlation ID arrives explicitly,
 * never from ambient state (S4).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Orchestration;

use OpenEMR\Modules\Copilot\Chart\PhysicianContext;
use OpenEMR\Modules\Copilot\Observability\TraceContext;

interface IntakeExtractorWorker
{
    public function run(PhysicianContext $physician, int $patientPid, TraceContext $workerSpan): IntakeExtractionOutcome;
}
