<?php

/**
 * Disclosure decorator for the evidence-retriever port (Wave K.3; C1/C5;
 * CLAUDE.md bright line "never send PHI without the disclosure being
 * logged").
 *
 * The physician's QUESTION TEXT crosses to the embed/rerank vendor on every
 * evidence retrieval. It is physician-authored free text, not chart data —
 * but free text can carry patient identifiers, so the crossing is treated
 * exactly like the Anthropic payload and the VLM document crossing: it is
 * DISCLOSED, before the vendor is called. Gap found 2026-07-14 — the Week 1
 * rule had been applied to both other vendor crossings but not this one.
 *
 * Semantics mirror VlmDocumentExtractor's crossing:
 *  - LOG THEN SEND: the disclosure is recorded before the inner worker
 *    runs, so a crash mid-retrieval leaves a logged crossing, never an
 *    unlogged one (C1).
 *  - The record carries the physician, the resolved patient pid (D7), the
 *    `evidence-query` data class, and the turn's correlation id — the only
 *    join key between the PHI-free trace and this PHI-adjacent log (S4).
 *  - The purpose string never embeds the question text itself: the log
 *    records THAT a query crossed, not the query. The question already has
 *    a PHI-bearing home (the disclosure log's join partner is the audit
 *    trail); duplicating it here would widen the PHI surface for nothing.
 *  - Pass-through otherwise: the question, topK, span, outcome, and any
 *    failure propagate untouched — this class discloses, it never decides.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Rag;

use OpenEMR\Modules\Copilot\Audit\Disclosure;
use OpenEMR\Modules\Copilot\Audit\DisclosureLogger;
use OpenEMR\Modules\Copilot\Chart\PhysicianContext;
use OpenEMR\Modules\Copilot\Observability\TraceContext;
use OpenEMR\Modules\Copilot\Orchestration\EvidenceRetrieverWorker;
use Psr\Clock\ClockInterface;

final readonly class DisclosedEvidenceRetrieverWorker implements EvidenceRetrieverWorker
{
    public function __construct(
        private EvidenceRetrieverWorker $inner,
        private DisclosureLogger $disclosureLogger,
        private PhysicianContext $physician,
        private int $patientPid,
        private ClockInterface $clock,
    ) {
    }

    public function run(string $question, int $topK, TraceContext $workerSpan): RetrievalOutcome
    {
        $this->disclosureLogger->record(new Disclosure(
            $this->physician->username,
            $this->patientPid,
            ['evidence-query'],
            'Guideline evidence retrieval: the clinician question text crossed to the embed/rerank vendor',
            $this->clock->now(),
            $workerSpan->correlationId,
        ));

        return $this->inner->run($question, $topK, $workerSpan);
    }
}
