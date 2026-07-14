<?php

/**
 * The Cohere embed vendor boundary's single failure type (W2_ARCHITECTURE.md
 * §5 "Hybrid RAG + rerank"; PS-12 degradation pair).
 *
 * Every failure mode at the embed transport — network fault, non-200 status,
 * a malformed or short response body — collapses to this one exception, the
 * same failure-mapping idiom `AnthropicLlmClient` uses for the LLM boundary
 * ({@see \OpenEMR\Modules\Copilot\Llm\LlmUnavailableException}). Vendor
 * response bodies and transport exception messages are never echoed into the
 * thrown message; the original throwable, if any, rides on getPrevious()
 * only.
 *
 * PS-12's asymmetry lives entirely in how *callers* react to this one type,
 * not in the exception itself:
 *
 * - **Build time** ({@see CorpusIndexer::rebuild()}): catching this exception
 *   means the keyword leg still indexes and the embedding leg is skipped —
 *   an operator-facing stale-index alarm carried in `IndexReport`, never a
 *   user-facing failure.
 * - **Query time:** handling this exception during retrieval (keyword-only
 *   fallback, degraded `/ready`) is out of this class's scope — that is
 *   TRO-28's responsibility, not this indexer's.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Rag;

final class EmbeddingUnavailableException extends \RuntimeException
{
}
