<?php

/**
 * Per-dependency readiness status for a RAG-side health probe (TRO-28;
 * PS-12; W2_ARCHITECTURE.md §8 "`/ready` grows document-storage, vector-index,
 * and reranker probes and returns per-dependency degraded status rather than
 * binary up/down").
 *
 * Deliberately no `Down` case: a dependency that is partially usable (e.g. a
 * corpus index with some chunks embedded and some not) is `Degraded`, never
 * collapsed into a binary up/down signal that would hide the "worse results
 * beat no results" middle ground the degradation pair (PS-12) depends on.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Rag;

enum ProbeStatus
{
    case Ok;
    case Degraded;
}
