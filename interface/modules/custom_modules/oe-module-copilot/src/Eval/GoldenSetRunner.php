<?php

/**
 * Composition root and executor for the 50-case Week 2 golden-set gate
 * (TRO-35, arming TRO-29/31/34/23/40; consuming TRO-36's comparator;
 * eval/goldenset/README.md; W2_ARCHITECTURE.md §7).
 *
 * `forCommittedGoldenSet()` wires the shipped seams over the committed
 * golden-set directory, the committed vendor-fixtures replay, and the real
 * database — PS-1's stub-seam tier: vendor boundary fixture-stubbed at the
 * injectable transport, everything else (ingestion, schema parse, persist,
 * supervisor routing, deterministic mapped-chunk fetch, hybrid retrieval
 * union, rerank consumption, one-mint reference index, claim verification)
 * runs production code. `run()` executes every case plus the Week 1
 * critical-subset fold-in and returns the six-category report.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Eval;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\Copilot\Chart\PhysicianContext;
use OpenEMR\Modules\Copilot\Corpus\CorpusManifest;
use OpenEMR\Modules\Copilot\DataTrust\CurrencyStatus;
use OpenEMR\Modules\Copilot\Detectors\CriticalFinding;
use OpenEMR\Modules\Copilot\Detectors\CriticalFindingType;
use OpenEMR\Modules\Copilot\Detectors\CriticalSubsetDetectors;
use OpenEMR\Modules\Copilot\Evidence\CriticalFindingChunkMap;
use OpenEMR\Modules\Copilot\GoldenChart\CaseResult;
use OpenEMR\Modules\Copilot\GoldenChart\GoldenChartCaseLoader;
use OpenEMR\Modules\Copilot\GoldenChart\Scorer;
use OpenEMR\Modules\Copilot\Ingestion\DocumentIngestionService;
use OpenEMR\Modules\Copilot\Ingestion\PatientDocumentAttacher;
use OpenEMR\Modules\Copilot\Ingestion\VlmDocumentExtractor;
use OpenEMR\Modules\Copilot\Observability\TraceContext;
use OpenEMR\Modules\Copilot\Orchestration\DispatchResult;
use OpenEMR\Modules\Copilot\Orchestration\EvidenceRetrieverWorker;
use OpenEMR\Modules\Copilot\Orchestration\SupervisedTurnDispatcher;
use OpenEMR\Modules\Copilot\Orchestration\Supervisor;
use OpenEMR\Modules\Copilot\Orchestration\SupervisorStep;
use OpenEMR\Modules\Copilot\Orchestration\SupervisorStepKind;
use OpenEMR\Modules\Copilot\Orchestration\SupervisorTurnState;
use OpenEMR\Modules\Copilot\Persistence\ExtractionLineageSchema;
use OpenEMR\Modules\Copilot\Persistence\IntakeCandidatesSchema;
use OpenEMR\Modules\Copilot\Rag\CohereEmbedClient;
use OpenEMR\Modules\Copilot\Rag\CohereRerankClient;
use OpenEMR\Modules\Copilot\Rag\CorpusIndexer;
use OpenEMR\Modules\Copilot\Rag\CorpusIndexSchema;
use OpenEMR\Modules\Copilot\Rag\EvidenceRetrievalService;
use OpenEMR\Modules\Copilot\Rag\EvidenceRetrieverWorkerImpl;
use OpenEMR\Modules\Copilot\Rag\HybridRetriever;
use OpenEMR\Modules\Copilot\Rag\RetrievalOutcome;
use OpenEMR\Modules\Copilot\Rag\RetrievedChunk;
use OpenEMR\Modules\Copilot\Synthesis\AllergyEntry;
use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshot;
use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshotSynthesizer;
use OpenEMR\Modules\Copilot\Synthesis\FollowUpEntry;
use OpenEMR\Modules\Copilot\Synthesis\LabResultEntry;
use OpenEMR\Modules\Copilot\Synthesis\MedicationEntry;
use OpenEMR\Modules\Copilot\Synthesis\SourceRef;
use OpenEMR\Modules\Copilot\Verification\ClaimVerifier;
use OpenEMR\Modules\Copilot\Verification\DraftClaim;
use OpenEMR\Modules\Copilot\Verification\ReferenceIndex;

final class GoldenSetRunner
{
    private const PATIENT_PID_BASE_OFFSET = 6000;

    /** @var array<string, bool>|null */
    private ?array $manifestChunkIds = null;

    private bool $corpusIndexed = false;

    private int $fixturePatientCounter = 0;

    /**
     * @param array<string, array{int, array<string, mixed>}> $embedFixtures
     * @param array<string, array{int, array<string, mixed>}> $rerankFixtures
     * @param array<string, array{int, array<string, mixed>}> $vlmFixtures
     */
    private function __construct(
        private readonly string $casesDir,
        private readonly string $corpusDir,
        private readonly string $adjudicatedDir,
        array $embedFixtures,
        array $rerankFixtures,
        array $vlmFixtures,
    ) {
        $this->embedReplayTransport = InputKeyedReplayTransport::fromFixtures($embedFixtures);
        $this->rerankReplayTransport = InputKeyedReplayTransport::fromFixtures($rerankFixtures);
        $this->vlmReplayTransport = InputKeyedReplayTransport::fromFixtures($vlmFixtures);
    }

    private readonly InputKeyedReplayTransport $embedReplayTransport;
    private readonly InputKeyedReplayTransport $rerankReplayTransport;
    private readonly InputKeyedReplayTransport $vlmReplayTransport;

    public static function forCommittedGoldenSet(): self
    {
        $moduleDir = dirname(__DIR__, 2);
        $fixturesPath = $moduleDir . '/eval/goldenset/vendor-fixtures.json';
        $fixtures = self::loadVendorFixtures($fixturesPath);

        return new self(
            $moduleDir . '/eval/goldenset/cases',
            $moduleDir . '/corpus',
            dirname(__DIR__, 6) . '/tests/Tests/Isolated/Copilot/GoldenChart/adjudicated',
            $fixtures['embed'],
            $fixtures['rerank'],
            $fixtures['vlm'],
        );
    }

    /**
     * @return array{embed: array<string, array{int, array<string, mixed>}>, rerank: array<string, array{int, array<string, mixed>}>, vlm: array<string, array{int, array<string, mixed>}>}
     */
    private static function loadVendorFixtures(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \DomainException(sprintf('Eval vendor-fixtures file "%s" is absent — run bin/regenerate-eval-goldenset.php', $path));
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \DomainException(sprintf('Eval vendor-fixtures file "%s" could not be read', $path));
        }

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \DomainException(sprintf('Eval vendor-fixtures file "%s" must decode to a JSON object', $path));
        }

        $sections = [];
        foreach (['embed', 'rerank', 'vlm'] as $section) {
            $value = $decoded[$section] ?? null;
            if (!is_array($value)) {
                throw new \DomainException(sprintf('Eval vendor-fixtures file "%s" is missing a "%s" object', $path, $section));
            }
            /** @var array<string, array{int, array<string, mixed>}> $narrowed */
            $narrowed = $value;
            $sections[$section] = $narrowed;
        }

        /** @var array{embed: array<string, array{int, array<string, mixed>}>, rerank: array<string, array{int, array<string, mixed>}>, vlm: array<string, array{int, array<string, mixed>}>} $sections */
        return $sections;
    }

    public function run(): GoldenSetGateReport
    {
        ExtractionLineageSchema::ensureInstalled();
        IntakeCandidatesSchema::ensureInstalled();
        $this->ensureCorpusIndexed();

        $cases = (new GoldenSetCaseLoader())->loadFromDirectory($this->casesDir);

        /** @var array<string, int> $totals */
        $totals = [];
        /** @var array<string, int> $passedCounts */
        $passedCounts = [];
        /** @var array<string, list<string>> $caseFailuresById */
        $caseFailuresById = [];
        /** @var array<string, GoldenCaseArtifacts> $artifactsById */
        $artifactsById = [];

        foreach ($cases as $case) {
            [$rubricFailures, $artifacts] = match ($case->kind) {
                GoldenCaseKind::Extraction => $this->runExtractionCase($case),
                GoldenCaseKind::Retrieval => $this->runRetrievalCase($case),
                GoldenCaseKind::Turn => $this->runTurnCase($case),
            };

            $artifactsById[$case->id] = $artifacts;

            $flat = [];
            foreach ($case->rubrics as $rubric) {
                $violations = $rubricFailures[$rubric] ?? [];
                $totals[$rubric] = ($totals[$rubric] ?? 0) + 1;
                if ($violations === []) {
                    $passedCounts[$rubric] = ($passedCounts[$rubric] ?? 0) + 1;
                } else {
                    foreach ($violations as $violation) {
                        $flat[] = sprintf('%s: %s', $rubric, $violation);
                    }
                }
            }
            // Only failing cases appear in the map — an all-green run reports
            // caseFailures() === [] exactly (the frozen gate test's contract),
            // never a 50-key map of empty lists.
            if ($flat !== []) {
                $caseFailuresById[$case->id] = array_values(array_unique($flat));
            }
        }

        $this->foldCriticalSubset($totals, $passedCounts);

        $scores = [];
        foreach ($totals as $category => $total) {
            $scores[] = new CategoryScore($category, $passedCounts[$category] ?? 0, $total);
        }

        return new GoldenSetGateReport(new EvalRunResult($scores), $caseFailuresById, $artifactsById);
    }

    // ------------------------------------------------------------------
    // Setup
    // ------------------------------------------------------------------

    private function ensureCorpusIndexed(): void
    {
        if ($this->corpusIndexed) {
            return;
        }

        // Drop the module-owned index tables before rebuilding (the same
        // discipline the frozen CorpusIndexerTest setUp() applies, verified
        // empirically): InnoDB FULLTEXT retains deleted-row ghost entries in
        // its relevance statistics after a DELETE-based rebuild, so MATCH()
        // scores — and therefore the deterministic relevance ORDER the
        // rerank request's document list depends on — drift between rebuilds
        // of the identical corpus unless the tables start fresh. A fresh
        // table makes the index state a pure function of the committed
        // corpus, which is what makes strict order-sensitive fixture hashing
        // (PS-2) reproducible across record and replay runs.
        QueryUtils::sqlStatementThrowException('DROP TABLE IF EXISTS ' . CorpusIndexSchema::EMBEDDING_TABLE, []);
        QueryUtils::sqlStatementThrowException('DROP TABLE IF EXISTS ' . CorpusIndexSchema::CHUNK_TABLE, []);

        $replay = $this->embedReplayTransport;
        $embedder = new CohereEmbedClient(
            static fn (array $requestBody): array => $replay($requestBody),
            EvalVendorConfig::EMBED_MODEL_ID,
        );
        (new CorpusIndexer($embedder))->rebuild($this->corpusDir);
        $this->corpusIndexed = true;
    }

    /**
     * @return array<string, bool>
     */
    private function manifestChunkIds(): array
    {
        if ($this->manifestChunkIds === null) {
            $ids = [];
            foreach (CorpusManifest::fromDirectory($this->corpusDir)->chunkInventory() as $entry) {
                $ids[$entry->chunkId] = true;
            }
            $this->manifestChunkIds = $ids;
        }

        return $this->manifestChunkIds;
    }

    // ------------------------------------------------------------------
    // kind: extraction
    // ------------------------------------------------------------------

    /**
     * @return array{array<string, list<string>>, GoldenCaseArtifacts}
     */
    private function runExtractionCase(GoldenSetCase $case): array
    {
        $rubricFailures = array_fill_keys($case->rubrics, []);

        $docType = self::requireString($case->inputs, 'doc_type');
        $filename = self::requireString($case->inputs, 'filename');
        $bytes = self::requireString($case->inputs, 'document_bytes');
        $wire = $case->inputs['vlm_wire'] ?? null;
        if (!is_array($wire)) {
            throw new \DomainException(sprintf('case "%s" inputs.vlm_wire must be an object', $case->id));
        }
        $uploadTwice = (bool) ($case->inputs['upload_twice'] ?? false);

        $patient = $this->createFixturePatient();
        $pid = $patient['pid'];
        $tempFile = $this->writeTempFile($bytes, $filename);

        $counts = ['embed' => 0, 'rerank' => 0, 'vlm' => 0];

        try {
            $vlmTransport = $this->countingTransport($this->vlmReplayTransport, function () use (&$counts): void {
                $counts['vlm']++;
            });

            $service = new DocumentIngestionService(
                new PatientDocumentAttacher(),
                new VlmDocumentExtractor($vlmTransport, new DiscardingDisclosureLogger(), EvalVendorConfig::VLM_MODEL_ID),
            );

            $physician = new PhysicianContext('copilot-eval', 1);
            $result = $service->attachAndExtract($physician, $patient['uuid'], $tempFile, $docType);

            $documentIdRaw = $result['document_id'];
            $status = $result['extraction_status'];

            if (!ctype_digit($documentIdRaw)) {
                throw new \RuntimeException('GoldenSetRunner received a non-numeric document id from attachAndExtract');
            }
            $documentId = (int) $documentIdRaw;

            $documentAttached = $this->documentAttached($pid, $documentId);
            if (!$documentAttached) {
                $rubricFailures = $this->tag($rubricFailures, $case, ['schema_valid', 'safe_refusal'], 'document was not attached');
            }

            $expectedStatus = self::expectedStringOrNull($case->expected, 'extraction_status');
            if ($expectedStatus !== null && $expectedStatus !== $status) {
                $rubricFailures = $this->tag($rubricFailures, $case, ['schema_valid', 'safe_refusal'], sprintf('extraction_status mismatch: expected "%s", got "%s"', $expectedStatus, $status));
            }

            $actualLabRows = [];
            $actualCandidates = [];

            if ($docType === 'lab_pdf') {
                $actualLabRows = $this->readLabRows($pid);
                $expectedLabRows = self::expectedLabRows($case->expected);
                if ($expectedLabRows !== null) {
                    foreach ($this->compareLabRows($actualLabRows, $expectedLabRows) as $problem) {
                        $rubricFailures = $this->tag($rubricFailures, $case, ['factually_consistent', 'safe_refusal'], $problem);
                    }
                }

                if (self::expectedBoolOrNull($case->expected, 'stamped_document_id_is_real') === true) {
                    foreach ($actualLabRows as $row) {
                        if ($row['document_id'] !== $documentId || $documentId === 999999) {
                            $rubricFailures = $this->tag($rubricFailures, $case, ['factually_consistent'], 'stamped document_id is not the real attached id');
                            break;
                        }
                    }
                }
            } else {
                $actualCandidates = $this->readIntakeCandidates($documentId);
                $expectedCandidates = self::expectedIntakeCandidates($case->expected);
                if ($expectedCandidates !== null) {
                    foreach ($this->compareIntakeCandidates($actualCandidates, $expectedCandidates) as $problem) {
                        $rubricFailures = $this->tag($rubricFailures, $case, ['factually_consistent', 'safe_refusal'], $problem);
                    }
                }

                $absentFieldPaths = self::expectedStringListOrNull($case->expected, 'absent_field_paths') ?? [];
                $actualFieldPaths = array_map(static fn (array $row): string => $row['field_path'], $actualCandidates);
                foreach ($absentFieldPaths as $absentFieldPath) {
                    if (in_array($absentFieldPath, $actualFieldPaths, true)) {
                        $rubricFailures = $this->tag($rubricFailures, $case, ['factually_consistent'], sprintf('field path "%s" was expected absent but was persisted', $absentFieldPath));
                    }
                }
            }

            if ($status === 'extraction_failed' && ($actualLabRows !== [] || $actualCandidates !== [])) {
                $rubricFailures = $this->tag($rubricFailures, $case, ['schema_valid', 'safe_refusal'], 'extraction_failed left persisted rows behind — a whole-fail must persist nothing');
            }

            if ($uploadTwice) {
                $mediaType = self::mediaTypeFor($filename);
                $reattach = (new PatientDocumentAttacher())->attach($physician, $pid, $filename, $mediaType, $bytes);
                $secondAttachDeduplicates = $reattach->deduplicated && $reattach->documentId === $documentId;
                if (self::expectedBoolOrNull($case->expected, 'second_attach_deduplicates') === true && !$secondAttachDeduplicates) {
                    $rubricFailures = $this->tag($rubricFailures, $case, ['factually_consistent'], 'second attach did not deduplicate to the same document');
                }
            }

            $surface = sprintf(
                "extraction_status=%s\nlab_row_count=%d\nintake_candidate_count=%d",
                $status,
                count($actualLabRows),
                count($actualCandidates),
            );
            foreach (PhiPatternDetector::scan($surface) as $violation) {
                $rubricFailures['no_phi_in_logs'][] = sprintf('PHI-shaped content on line %d (rule %s)', $violation->lineNumber, $violation->rule);
            }

            $artifacts = new GoldenCaseArtifacts([], 0, $counts, [], [], [], [], [], $status);

            return [$rubricFailures, $artifacts];
        } finally {
            @unlink($tempFile);
            $this->cleanupFixturePatient($pid);
        }
    }

    // ------------------------------------------------------------------
    // kind: retrieval
    // ------------------------------------------------------------------

    /**
     * @return array{array<string, list<string>>, GoldenCaseArtifacts}
     */
    private function runRetrievalCase(GoldenSetCase $case): array
    {
        $rubricFailures = array_fill_keys($case->rubrics, []);

        $question = self::requireString($case->inputs, 'question');
        $topK = self::requireInt($case->inputs, 'top_k');
        $degrade = self::stringList($case->inputs, 'degrade');

        $counts = ['embed' => 0, 'rerank' => 0, 'vlm' => 0];
        $service = $this->buildEvidenceRetrievalService($degrade, $counts);
        $outcome = $service->search($question, $topK);

        $chunkIds = array_map(
            static fn (RetrievedChunk $chunk): string => $chunk->chunkId,
            self::retrievedChunks($outcome),
        );

        $expectedInTopK = self::expectedStringListOrNull($case->expected, 'chunk_ids_in_top_k') ?? [];
        foreach ($expectedInTopK as $expectedChunkId) {
            if (!in_array($expectedChunkId, $chunkIds, true)) {
                $rubricFailures['factually_consistent'][] = sprintf('expected chunk "%s" did not appear in the result', $expectedChunkId);
            }
        }

        $expectedTopChunkId = self::expectedStringOrNull($case->expected, 'top_chunk_id');
        if ($expectedTopChunkId !== null) {
            $actualTop = $chunkIds[0] ?? null;
            if ($actualTop !== $expectedTopChunkId) {
                $rubricFailures['factually_consistent'][] = sprintf('top chunk mismatch: expected "%s", got "%s"', $expectedTopChunkId, (string) $actualTop);
            }
        }

        $maxResults = self::expectedIntOrNull($case->expected, 'max_results') ?? $topK;
        if (count($chunkIds) > $maxResults) {
            $rubricFailures['factually_consistent'][] = sprintf('result count %d exceeds top-k discipline bound %d', count($chunkIds), $maxResults);
        }

        $expectedDenseDegraded = self::expectedBoolOrNull($case->expected, 'dense_degraded');
        if ($expectedDenseDegraded !== null && $expectedDenseDegraded !== $outcome->denseDegraded) {
            $rubricFailures['factually_consistent'][] = sprintf('dense_degraded mismatch: expected %s, got %s', $expectedDenseDegraded ? 'true' : 'false', $outcome->denseDegraded ? 'true' : 'false');
        }

        $expectedRerankDegraded = self::expectedBoolOrNull($case->expected, 'rerank_degraded');
        if ($expectedRerankDegraded !== null && $expectedRerankDegraded !== $outcome->rerankDegraded) {
            $rubricFailures['factually_consistent'][] = sprintf('rerank_degraded mismatch: expected %s, got %s', $expectedRerankDegraded ? 'true' : 'false', $outcome->rerankDegraded ? 'true' : 'false');
        }

        if (self::expectedBoolOrNull($case->expected, 'all_chunks_in_manifest') === true) {
            $manifestIds = $this->manifestChunkIds();
            foreach ($chunkIds as $chunkId) {
                if (!isset($manifestIds[$chunkId])) {
                    $rubricFailures['factually_consistent'][] = sprintf('chunk "%s" does not exist in the corpus manifest', $chunkId);
                }
            }
        }

        $surface = sprintf(
            "chunks=%s\ndense_degraded=%s\nrerank_degraded=%s",
            implode(',', $chunkIds),
            $outcome->denseDegraded ? 'true' : 'false',
            $outcome->rerankDegraded ? 'true' : 'false',
        );
        foreach (PhiPatternDetector::scan($surface) as $violation) {
            $rubricFailures['no_phi_in_logs'][] = sprintf('PHI-shaped content on line %d (rule %s)', $violation->lineNumber, $violation->rule);
        }

        $artifacts = new GoldenCaseArtifacts([], 0, $counts, $chunkIds, [], [], [], [], null);

        return [$rubricFailures, $artifacts];
    }

    /**
     * @param list<string> $degrade
     * @param array<string, int> $counts
     */
    private function buildEvidenceRetrievalService(array $degrade, array &$counts): EvidenceRetrievalService
    {
        $embedTransport = in_array('embed', $degrade, true)
            ? self::throwingTransport()
            : $this->countingTransport($this->embedReplayTransport, function () use (&$counts): void {
                $counts['embed']++;
            });

        $rerankTransport = in_array('rerank', $degrade, true)
            ? self::throwingTransport()
            : $this->countingTransport($this->rerankReplayTransport, function () use (&$counts): void {
                $counts['rerank']++;
            });

        return new EvidenceRetrievalService(
            new CohereEmbedClient($embedTransport, EvalVendorConfig::EMBED_MODEL_ID),
            new HybridRetriever(new CohereRerankClient($rerankTransport, EvalVendorConfig::RERANK_MODEL_ID)),
        );
    }

    // ------------------------------------------------------------------
    // kind: turn
    // ------------------------------------------------------------------

    /**
     * @return array{array<string, list<string>>, GoldenCaseArtifacts}
     */
    private function runTurnCase(GoldenSetCase $case): array
    {
        $rubricFailures = array_fill_keys($case->rubrics, []);

        $stateInput = self::stringKeyed($case->inputs['state'] ?? null);
        $isSnapshotTurn = self::requireBool($stateInput, 'is_snapshot_turn');
        $hasPendingDocument = self::requireBool($stateInput, 'has_pending_unextracted_document');
        $questionAsksForEvidence = self::requireBool($stateInput, 'question_asks_for_evidence');
        $criticalFindingPresentDeclared = self::requireBool($stateInput, 'critical_finding_present');
        $engaged = self::requireBool($stateInput, 'physician_engaged_critical_finding');

        $question = self::requireString($case->inputs, 'question');
        $topK = array_key_exists('top_k', $case->inputs) ? self::requireInt($case->inputs, 'top_k') : 5;
        $chartInput = self::stringKeyed($case->inputs['chart'] ?? []);

        [$chart, $labRefToAnalyte] = $this->buildChart($chartInput);

        $findings = [];
        foreach (CriticalSubsetDetectors::withDraftTables()->detectAll($chart, CriticalSubsetLabels::today()) as $report) {
            $findings = [...$findings, ...$report->findings];
        }
        $computedPresent = $findings !== [];
        if ($computedPresent !== $criticalFindingPresentDeclared) {
            throw new \DomainException(sprintf(
                'case "%s" declares critical_finding_present=%s but the real detectors computed %s over its chart — case bug',
                $case->id,
                $criticalFindingPresentDeclared ? 'true' : 'false',
                $computedPresent ? 'true' : 'false',
            ));
        }

        $derivedSetupInput = $case->inputs['derived_setup'] ?? null;
        $derivedResultIds = [];
        $derivedPatientPid = null;
        if (is_array($derivedSetupInput)) {
            [$derivedResultIds, $derivedPatientPid] = $this->runDerivedSetup($case, self::stringKeyed($derivedSetupInput));
        }

        $patientPid = $derivedPatientPid ?? 1;

        try {
            $counts = ['embed' => 0, 'rerank' => 0, 'vlm' => 0];
            $degrade = self::stringList($case->inputs, 'degrade');

            $mappedChunkId = null;
            if (!$questionAsksForEvidence && $criticalFindingPresentDeclared && $engaged) {
                $mappedChunkId = $this->resolveMappedChunkId($findings, $labRefToAnalyte);
                $evidenceWorker = new MappedChunkEvidenceWorker($mappedChunkId);
            } else {
                $evidenceWorker = $this->buildRagEvidenceWorkerForTurn($degrade, $counts);
            }

            $recorder = new CollectingTraceRecorder();
            $dispatcher = new SupervisedTurnDispatcher(
                new Supervisor(),
                new NoPendingDocumentsIntakeWorker(),
                $evidenceWorker,
                $recorder,
            );

            $state = new SupervisorTurnState($isSnapshotTurn, $hasPendingDocument, $questionAsksForEvidence, $criticalFindingPresentDeclared, $engaged);
            $physician = new PhysicianContext('copilot-eval', 1);
            $turnSpan = TraceContext::start('turn', new \DateTimeImmutable());

            $writeCountsBefore = $this->globalWriteCounts();

            $dispatchResult = $dispatcher->dispatch($physician, $state, $patientPid, $question, $topK, $turnSpan);

            $planSteps = self::planSteps($dispatchResult);
            $planStepKinds = array_map(static fn (SupervisorStep $step): string => $step->kind->name, $planSteps);
            $retrievalStepCount = 0;
            foreach ($planSteps as $step) {
                if ($step->kind === SupervisorStepKind::EvidenceRetriever) {
                    $retrievalStepCount++;
                }
            }

            $evidence = $dispatchResult->evidence;
            $evidenceChunks = $evidence !== null ? self::retrievedChunks($evidence) : [];
            $evidenceChunkIds = array_map(static fn (RetrievedChunk $chunk): string => $chunk->chunkId, $evidenceChunks);
            $denseDegraded = $evidence !== null && $evidence->denseDegraded;
            $rerankDegraded = $evidence !== null && $evidence->rerankDegraded;

            $extraRefsInput = self::listOf($case->inputs, 'extra_refs');
            $referenceIndex = $this->buildReferenceIndex($chart, $findings, $evidenceChunks, $extraRefsInput, $derivedResultIds);

            $draftClaimsInput = self::listOf($case->inputs, 'draft_claims');
            $draftClaims = $this->buildDraftClaims($draftClaimsInput, $derivedResultIds);

            $derivedGroundingInput = self::stringKeyed($case->inputs['derived_grounding'] ?? []);
            $derivedGroundingMode = self::optionalString($derivedGroundingInput, 'mode') ?? 'real_port';
            $claimVerifier = $derivedGroundingMode === 'no_port'
                ? new ClaimVerifier()
                : new ClaimVerifier(new DocumentBackedDerivedObservationGrounding());

            $grounded = [];
            $rejected = [];
            $groundedSourcesByIndex = [];
            foreach ($draftClaims as $i => $claim) {
                $verified = $claimVerifier->verify([$claim], $referenceIndex);
                if ($verified->grounded !== []) {
                    $grounded[] = $i;
                    $groundedSourcesByIndex[$i] = $verified->grounded[0]->sources;
                } else {
                    $rejected[] = $i;
                }
            }

            $writeCountsAfter = $this->globalWriteCounts();
            $noWriteSideEffects = $writeCountsBefore === $writeCountsAfter;

            $groundedCitationSourceTypes = [];
            foreach ($groundedSourcesByIndex as $sources) {
                foreach ($sources as $source) {
                    $groundedCitationSourceTypes[] = $source->sourceType;
                }
            }
            $groundedCitationSourceTypes = array_values(array_unique($groundedCitationSourceTypes));

            // -------- expected-value checks --------

            $expectedPlanStepKinds = self::expectedStringListOrNull($case->expected, 'plan_step_kinds');
            if ($expectedPlanStepKinds !== null && $expectedPlanStepKinds !== $planStepKinds) {
                $rubricFailures = $this->tag($rubricFailures, $case, ['factually_consistent'], 'plan_step_kinds mismatch');
            }

            $expectedRetrievalStepCount = self::expectedIntOrNull($case->expected, 'retrieval_step_count');
            if ($expectedRetrievalStepCount !== null && $expectedRetrievalStepCount !== $retrievalStepCount) {
                $rubricFailures = $this->tag($rubricFailures, $case, ['factually_consistent'], 'retrieval_step_count mismatch');
            }

            $expectedVendorCalls = self::expectedMapOrNull($case->expected, 'vendor_calls');
            if ($expectedVendorCalls !== null) {
                foreach (['embed', 'rerank'] as $seam) {
                    $expectedCount = $expectedVendorCalls[$seam] ?? null;
                    if (is_int($expectedCount) && $expectedCount !== $counts[$seam]) {
                        $rubricFailures = $this->tag($rubricFailures, $case, ['factually_consistent'], sprintf('vendor_calls.%s mismatch: expected %d, got %d', $seam, $expectedCount, $counts[$seam]));
                    }
                }
            }

            $expectedMappedChunkId = self::expectedStringOrNull($case->expected, 'mapped_chunk_id');
            if ($expectedMappedChunkId !== null && $expectedMappedChunkId !== $mappedChunkId) {
                $rubricFailures = $this->tag($rubricFailures, $case, ['factually_consistent'], sprintf('mapped_chunk_id mismatch: expected "%s", got "%s"', $expectedMappedChunkId, (string) $mappedChunkId));
            }

            $expectedEvidenceChunkIds = self::expectedStringListOrNull($case->expected, 'evidence_contains_chunk_ids');
            if ($expectedEvidenceChunkIds !== null) {
                foreach ($expectedEvidenceChunkIds as $expectedChunkId) {
                    if (!in_array($expectedChunkId, $evidenceChunkIds, true)) {
                        $rubricFailures = $this->tag($rubricFailures, $case, ['factually_consistent', 'citation_present'], sprintf('evidence did not contain expected chunk "%s"', $expectedChunkId));
                    }
                }
            }

            $expectedDenseDegraded = self::expectedBoolOrNull($case->expected, 'dense_degraded');
            if ($expectedDenseDegraded !== null && $expectedDenseDegraded !== $denseDegraded) {
                $rubricFailures = $this->tag($rubricFailures, $case, ['factually_consistent'], 'dense_degraded mismatch');
            }

            $expectedRerankDegraded = self::expectedBoolOrNull($case->expected, 'rerank_degraded');
            if ($expectedRerankDegraded !== null && $expectedRerankDegraded !== $rerankDegraded) {
                $rubricFailures = $this->tag($rubricFailures, $case, ['factually_consistent'], 'rerank_degraded mismatch');
            }

            $expectedTraceStepNames = self::expectedStringListOrNull($case->expected, 'trace_step_names');
            if ($expectedTraceStepNames !== null && $expectedTraceStepNames !== $recorder->stepNames()) {
                $rubricFailures = $this->tag($rubricFailures, $case, ['factually_consistent'], 'trace_step_names mismatch');
            }

            $expectedFindingIds = self::expectedStringListOrNull($case->expected, 'finding_ids_include');
            if ($expectedFindingIds !== null) {
                $actualFindingIds = CriticalSubsetLabels::labelsFor($findings);
                foreach ($expectedFindingIds as $expectedFindingId) {
                    if (!in_array($expectedFindingId, $actualFindingIds, true)) {
                        $rubricFailures = $this->tag($rubricFailures, $case, ['factually_consistent'], sprintf('expected finding id "%s" was not surfaced', $expectedFindingId));
                    }
                }
            }

            $expectedGrounded = self::expectedIntListOrNull($case->expected, 'grounded_claim_indexes');
            if ($expectedGrounded !== null && $expectedGrounded !== $grounded) {
                $rubricFailures = $this->tag($rubricFailures, $case, ['citation_present', 'factually_consistent'], 'grounded_claim_indexes mismatch');
            }

            $expectedRejected = self::expectedIntListOrNull($case->expected, 'rejected_claim_indexes');
            if ($expectedRejected !== null && $expectedRejected !== $rejected) {
                $rubricFailures = $this->tag($rubricFailures, $case, ['safe_refusal', 'factually_consistent'], 'rejected_claim_indexes mismatch');
            }

            $expectedSourceTypesInclude = self::expectedStringListOrNull($case->expected, 'grounded_citation_source_types_include');
            if ($expectedSourceTypesInclude !== null) {
                foreach ($expectedSourceTypesInclude as $expectedType) {
                    if (!in_array($expectedType, $groundedCitationSourceTypes, true)) {
                        $rubricFailures = $this->tag($rubricFailures, $case, ['citation_present'], sprintf('grounded citations did not include source type "%s"', $expectedType));
                    }
                }
            }

            $expectedQuotes = self::expectedMapOrNull($case->expected, 'grounded_quotes');
            if ($expectedQuotes !== null) {
                foreach ($expectedQuotes as $indexKey => $expectedQuote) {
                    if (!is_string($expectedQuote)) {
                        continue;
                    }
                    $index = (int) $indexKey;
                    $sources = $groundedSourcesByIndex[$index] ?? null;
                    $actualQuote = $sources !== null && $sources !== [] ? $sources[0]->quoteOrValue : null;
                    if ($actualQuote !== $expectedQuote) {
                        $rubricFailures = $this->tag($rubricFailures, $case, ['citation_present'], sprintf('grounded_quotes[%d] mismatch: expected "%s", got "%s"', $index, $expectedQuote, (string) $actualQuote));
                    }
                }
            }

            $expectedSourceCounts = self::expectedMapOrNull($case->expected, 'grounded_source_counts');
            if ($expectedSourceCounts !== null) {
                foreach ($expectedSourceCounts as $indexKey => $expectedCount) {
                    if (!is_int($expectedCount)) {
                        continue;
                    }
                    $index = (int) $indexKey;
                    $sources = $groundedSourcesByIndex[$index] ?? [];
                    if (count($sources) !== $expectedCount) {
                        $rubricFailures = $this->tag($rubricFailures, $case, ['citation_present'], sprintf('grounded_source_counts[%d] mismatch: expected %d, got %d', $index, $expectedCount, count($sources)));
                    }
                }
            }

            if (self::expectedBoolOrNull($case->expected, 'no_write_side_effects') === true && !$noWriteSideEffects) {
                $rubricFailures = $this->tag($rubricFailures, $case, ['safe_refusal'], 'a write side effect occurred during the turn');
            }

            $surface = $recorder->renderSurface() . "\n"
                . implode("\n", array_map(static fn (SupervisorStep $step): string => $step->reason, $planSteps));
            foreach (PhiPatternDetector::scan($surface) as $violation) {
                $rubricFailures['no_phi_in_logs'][] = sprintf('PHI-shaped content on line %d (rule %s)', $violation->lineNumber, $violation->rule);
            }

            $artifacts = new GoldenCaseArtifacts(
                $planStepKinds,
                $retrievalStepCount,
                $counts,
                $evidenceChunkIds,
                $grounded,
                $rejected,
                $groundedCitationSourceTypes,
                $recorder->stepNames(),
                null,
            );

            return [$rubricFailures, $artifacts];
        } finally {
            if ($derivedPatientPid !== null) {
                $this->cleanupFixturePatient($derivedPatientPid);
            }
        }
    }

    /**
     * @param list<string> $degrade
     * @param array<string, int> $counts
     */
    private function buildRagEvidenceWorkerForTurn(array $degrade, array &$counts): EvidenceRetrieverWorker
    {
        return new EvidenceRetrieverWorkerImpl($this->buildEvidenceRetrievalService($degrade, $counts));
    }

    /**
     * @param list<CriticalFinding> $findings
     * @param array<string, string> $labRefToAnalyte
     */
    private function resolveMappedChunkId(array $findings, array $labRefToAnalyte): string
    {
        if ($findings === []) {
            throw new \DomainException('resolveMappedChunkId requires at least one finding');
        }

        $finding = $findings[0];
        $analyteHint = null;
        if ($finding->type === CriticalFindingType::PanicLab) {
            $refId = $finding->sources[0]->sourceId;
            $analyteHint = $labRefToAnalyte[$refId] ?? null;
        }

        return CriticalFindingChunkMap::chunkIdFor($finding->type, $analyteHint);
    }

    /**
     * @param array<string, mixed> $derivedSetupInput
     *
     * @return array{0: list<int>, 1: int}
     */
    private function runDerivedSetup(GoldenSetCase $case, array $derivedSetupInput): array
    {
        $docType = self::requireString($derivedSetupInput, 'doc_type');
        $filename = self::requireString($derivedSetupInput, 'filename');
        $suffix = self::requireString($derivedSetupInput, 'document_bytes_suffix');
        $wire = $derivedSetupInput['vlm_wire'] ?? null;
        if (!is_array($wire)) {
            throw new \DomainException(sprintf('case "%s" derived_setup.vlm_wire must be an object', $case->id));
        }
        $thenDelete = (bool) ($derivedSetupInput['then_delete_source_document'] ?? false);

        $bytes = sprintf('%%PDF-1.7 goldenset %s %s%s', $case->id, $suffix, "\n");

        $patient = $this->createFixturePatient();
        $pid = $patient['pid'];
        $tempFile = $this->writeTempFile($bytes, $filename);

        try {
            $vlmTransport = $this->countingTransport($this->vlmReplayTransport, static function (): void {
            });

            $service = new DocumentIngestionService(
                new PatientDocumentAttacher(),
                new VlmDocumentExtractor($vlmTransport, new DiscardingDisclosureLogger(), EvalVendorConfig::VLM_MODEL_ID),
            );
            $physician = new PhysicianContext('copilot-eval', 1);
            $result = $service->attachAndExtract($physician, $patient['uuid'], $tempFile, $docType);

            $documentIdRaw = $result['document_id'];
            if (!ctype_digit($documentIdRaw)) {
                throw new \RuntimeException('GoldenSetRunner derived_setup received a non-numeric document id');
            }
            $documentId = (int) $documentIdRaw;

            if ($result['extraction_status'] !== 'extracted') {
                throw new \DomainException(sprintf('case "%s" derived_setup failed to extract — case bug', $case->id));
            }

            $resultIds = QueryUtils::fetchTableColumn(
                'SELECT prr.procedure_result_id AS rid FROM procedure_result prr'
                    . ' JOIN procedure_report pr ON prr.procedure_report_id=pr.procedure_report_id'
                    . ' JOIN procedure_order po ON pr.procedure_order_id=po.procedure_order_id'
                    . ' WHERE po.patient_id = ? ORDER BY prr.procedure_result_id ASC',
                'rid',
                [$pid],
            );

            $derivedResultIds = [];
            foreach ($resultIds as $rid) {
                if (is_numeric($rid)) {
                    $derivedResultIds[] = (int) $rid;
                }
            }

            if ($derivedResultIds === []) {
                throw new \DomainException(sprintf('case "%s" derived_setup persisted no derived observations — case bug', $case->id));
            }

            if ($thenDelete) {
                QueryUtils::sqlStatementThrowException('DELETE FROM categories_to_documents WHERE document_id = ?', [$documentId]);
                QueryUtils::sqlStatementThrowException('DELETE FROM documents WHERE id = ?', [$documentId]);
            }

            return [$derivedResultIds, $pid];
        } finally {
            @unlink($tempFile);
        }
    }

    // ------------------------------------------------------------------
    // Chart / reference-index / claim assembly
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $chartInput
     *
     * @return array{0: ChartSnapshot, 1: array<string, string>}
     */
    private function buildChart(array $chartInput): array
    {
        $labRefToAnalyte = [];
        $labs = [];
        foreach (self::listOf($chartInput, 'labs') as $labInputRaw) {
            $labInput = self::stringKeyed($labInputRaw);
            $analyte = self::requireString($labInput, 'analyte');
            $valueRaw = $labInput['value'] ?? null;
            if (!is_int($valueRaw) && !is_float($valueRaw)) {
                throw new \DomainException('turn chart lab value must be numeric');
            }
            $unit = self::requireString($labInput, 'unit');
            $refId = self::requireString($labInput, 'ref_id');
            $quote = self::optionalString($labInput, 'quote');
            $labRefToAnalyte[$refId] = $analyte;
            $labs[] = new LabResultEntry(
                $analyte,
                (float) $valueRaw,
                $unit,
                new \DateTimeImmutable('2026-07-01 00:00:00'),
                [new SourceRef('procedure_result', $refId, null, null, $quote)],
            );
        }

        $medications = [];
        foreach (self::listOf($chartInput, 'medications') as $medInputRaw) {
            $medInput = self::stringKeyed($medInputRaw);
            $name = self::requireString($medInput, 'name');
            $refId = self::requireString($medInput, 'ref_id');
            $quote = self::optionalString($medInput, 'quote');
            $medications[] = new MedicationEntry($name, CurrencyStatus::Current, [new SourceRef('lists', $refId, null, null, $quote)]);
        }

        $allergies = [];
        foreach (self::listOf($chartInput, 'allergies') as $allergyInputRaw) {
            $allergyInput = self::stringKeyed($allergyInputRaw);
            $substance = self::requireString($allergyInput, 'substance');
            $refId = self::requireString($allergyInput, 'ref_id');
            $quote = self::optionalString($allergyInput, 'quote');
            $allergies[] = new AllergyEntry($substance, CurrencyStatus::Current, [new SourceRef('lists', $refId, null, null, $quote)]);
        }

        $followUps = [];
        foreach (self::listOf($chartInput, 'follow_ups') as $followUpInputRaw) {
            $followUpInput = self::stringKeyed($followUpInputRaw);
            $description = self::requireString($followUpInput, 'description');
            $refId = self::requireString($followUpInput, 'ref_id');
            $dueRaw = self::optionalString($followUpInput, 'due');
            $due = $dueRaw !== null ? new \DateTimeImmutable($dueRaw) : null;
            $followUps[] = new FollowUpEntry($description, $due, true, [new SourceRef('lists', $refId)]);
        }

        $chart = (new ChartSnapshotSynthesizer())->synthesize($medications, $labs, $allergies, $followUps);

        return [$chart, $labRefToAnalyte];
    }

    /**
     * @param list<CriticalFinding> $findings
     * @param list<RetrievedChunk> $evidenceChunks
     * @param list<mixed> $extraRefsInput
     * @param list<int> $derivedResultIds
     */
    private function buildReferenceIndex(
        ChartSnapshot $chart,
        array $findings,
        array $evidenceChunks,
        array $extraRefsInput,
        array $derivedResultIds,
    ): ReferenceIndex {
        $refs = [];

        foreach ($chart->medications as $medication) {
            foreach ($medication->sources as $source) {
                $refs[] = $source;
            }
        }
        foreach ($chart->labs as $lab) {
            foreach ($lab->sources as $source) {
                $refs[] = $source;
            }
        }
        foreach ($chart->allergies as $allergy) {
            foreach ($allergy->sources as $source) {
                $refs[] = $source;
            }
        }
        foreach ($chart->followUps as $followUp) {
            foreach ($followUp->sources as $source) {
                $refs[] = $source;
            }
        }

        foreach ($findings as $finding) {
            $labelId = CriticalSubsetLabels::labelFor($finding);
            $quote = $finding->sources[0]->quoteOrValue ?? null;
            $refs[] = new SourceRef('detector', $labelId, null, null, $quote);
        }

        foreach ($evidenceChunks as $chunk) {
            $refs[] = $chunk->toSourceRef();
        }

        foreach ($extraRefsInput as $extraRefRaw) {
            $extraRef = self::stringKeyed($extraRefRaw);
            $refs[] = new SourceRef(
                self::requireString($extraRef, 'source_type'),
                self::requireString($extraRef, 'source_id'),
                self::optionalString($extraRef, 'page_or_section'),
                self::optionalString($extraRef, 'field_or_chunk_id'),
                self::optionalString($extraRef, 'quote_or_value'),
            );
        }

        foreach ($derivedResultIds as $resultId) {
            $refs[] = new SourceRef('derived_observation', (string) $resultId);
        }

        return ReferenceIndex::fromRefs($refs);
    }

    /**
     * @param list<mixed> $draftClaimsInput
     * @param list<int> $derivedResultIds
     *
     * @return list<DraftClaim>
     */
    private function buildDraftClaims(array $draftClaimsInput, array $derivedResultIds): array
    {
        $claims = [];
        foreach ($draftClaimsInput as $claimInputRaw) {
            $claimInput = self::stringKeyed($claimInputRaw);
            $text = self::requireString($claimInput, 'text');
            $citesInput = self::listOf($claimInput, 'cites');

            $sourceIds = [];
            foreach ($citesInput as $citeRaw) {
                $cite = self::stringKeyed($citeRaw);
                $sourceType = self::requireString($cite, 'source_type');
                $sourceId = self::requireString($cite, 'source_id');
                if ($sourceType === 'derived_observation' && $sourceId === '@derived:0') {
                    if ($derivedResultIds === []) {
                        throw new \DomainException('draft claim cites @derived:0 but no derived_setup persisted anything');
                    }
                    $sourceId = (string) $derivedResultIds[0];
                }
                $fieldOrChunkId = self::optionalString($cite, 'field_or_chunk_id');
                $ref = new SourceRef($sourceType, $sourceId, null, $fieldOrChunkId, null);
                $sourceIds[] = ReferenceIndex::tokenFor($ref);
            }

            $claims[] = new DraftClaim($text, $sourceIds);
        }

        return $claims;
    }

    // ------------------------------------------------------------------
    // Persisted-state readback
    // ------------------------------------------------------------------

    private function documentAttached(int $pid, int $documentId): bool
    {
        $count = QueryUtils::fetchSingleValue(
            'SELECT COUNT(*) AS c FROM documents WHERE id = ? AND foreign_id = ? AND deleted = 0',
            'c',
            [$documentId, $pid],
        );

        return is_numeric($count) && (int) $count > 0;
    }

    /**
     * @return list<array{test_name: string, value: string, unit: string, collection_date: ?string, document_id: int}>
     */
    private function readLabRows(int $pid): array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT prr.result_text AS test_name, prr.result AS value, prr.units AS unit, prr.date AS collection_date, prr.document_id AS document_id'
                . ' FROM procedure_result prr'
                . ' JOIN procedure_report pr ON prr.procedure_report_id = pr.procedure_report_id'
                . ' JOIN procedure_order po ON pr.procedure_order_id = po.procedure_order_id'
                . ' WHERE po.patient_id = ? ORDER BY prr.procedure_result_id ASC',
            [$pid],
        );

        $result = [];
        foreach ($rows as $row) {
            $testName = $row['test_name'] ?? null;
            $value = $row['value'] ?? null;
            $unit = $row['unit'] ?? null;
            // A literal SQL NULL date is a legal persisted state the README's
            // lab_rows dialect can pin (`"collection_date": null`), so the
            // date column narrows to string-or-null, not string.
            $collectionDate = $row['collection_date'] ?? null;
            $documentId = $row['document_id'] ?? null;
            if (
                !is_string($testName) || !is_string($value) || !is_string($unit)
                || ($collectionDate !== null && !is_string($collectionDate))
                || !is_numeric($documentId)
            ) {
                throw new \RuntimeException('GoldenSetRunner read a malformed procedure_result row');
            }
            $result[] = [
                'test_name' => $testName,
                'value' => $value,
                'unit' => $unit,
                'collection_date' => $collectionDate,
                'document_id' => (int) $documentId,
            ];
        }

        return $result;
    }

    /**
     * @return list<array{field_path: string, value: string, confidence: float}>
     */
    private function readIntakeCandidates(int $documentId): array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT field_path, value_text AS value, confidence FROM ' . IntakeCandidatesSchema::CANDIDATES_TABLE
                . ' WHERE document_id = ? AND superseded_at IS NULL ORDER BY id ASC',
            [$documentId],
        );

        $result = [];
        foreach ($rows as $row) {
            $fieldPath = $row['field_path'] ?? null;
            $value = $row['value'] ?? null;
            $confidence = $row['confidence'] ?? null;
            if (!is_string($fieldPath) || !is_string($value) || !is_numeric($confidence)) {
                throw new \RuntimeException('GoldenSetRunner read a malformed intake-candidate row');
            }
            $result[] = [
                'field_path' => $fieldPath,
                'value' => $value,
                'confidence' => (float) $confidence,
            ];
        }

        return $result;
    }

    /**
     * README lab_rows dialect: for each expected row, exactly the keys
     * PRESENT in the case JSON are checked — `null` pins a literal SQL NULL;
     * an OMITTED key is unconstrained. A present date string checks as a
     * prefix (the case pins the date, the column carries a datetime) — the
     * same convention the frozen DerivedObservationWriterTest uses.
     *
     * @param list<array{test_name: string, value: string, unit: string, collection_date: ?string, document_id: int}> $actualRows
     * @param list<array<string, ?string>> $expectedRows present-keys-only rows
     *
     * @return list<string>
     */
    private function compareLabRows(array $actualRows, array $expectedRows): array
    {
        $problems = [];
        if (count($actualRows) !== count($expectedRows)) {
            return [sprintf('lab_rows count mismatch: expected %d, got %d', count($expectedRows), count($actualRows))];
        }

        foreach ($expectedRows as $i => $expectedRow) {
            $actualRow = $actualRows[$i];
            foreach ($expectedRow as $field => $expectedValue) {
                $actualValue = match ($field) {
                    'test_name' => $actualRow['test_name'],
                    'value' => $actualRow['value'],
                    'unit' => $actualRow['unit'],
                    'collection_date' => $actualRow['collection_date'],
                    default => throw new \DomainException(sprintf('expected lab_rows[%d] pins unknown column "%s"', $i, $field)),
                };

                if ($expectedValue === null) {
                    if ($actualValue !== null) {
                        $problems[] = sprintf('lab_rows[%d].%s mismatch: expected literal SQL NULL, got "%s"', $i, $field, $actualValue);
                    }
                    continue;
                }

                if ($actualValue === null) {
                    $problems[] = sprintf('lab_rows[%d].%s mismatch: expected "%s", got literal SQL NULL', $i, $field, $expectedValue);
                    continue;
                }

                $matches = $field === 'collection_date'
                    ? str_starts_with($actualValue, $expectedValue)
                    : $actualValue === $expectedValue;
                if (!$matches) {
                    $problems[] = sprintf('lab_rows[%d].%s mismatch: expected "%s", got "%s"', $i, $field, $expectedValue, $actualValue);
                }
            }
        }

        return $problems;
    }

    /**
     * @param list<array{field_path: string, value: string, confidence: float}> $actualRows
     * @param list<array{field_path: string, value: string, confidence: float}> $expectedRows
     *
     * @return list<string>
     */
    private function compareIntakeCandidates(array $actualRows, array $expectedRows): array
    {
        $problems = [];
        if (count($actualRows) !== count($expectedRows)) {
            return [sprintf('intake_candidates count mismatch: expected %d, got %d', count($expectedRows), count($actualRows))];
        }

        foreach ($expectedRows as $i => $expectedRow) {
            $actualRow = $actualRows[$i];
            if ($actualRow['field_path'] !== $expectedRow['field_path']) {
                $problems[] = sprintf('intake_candidates[%d].field_path mismatch: expected "%s", got "%s"', $i, $expectedRow['field_path'], $actualRow['field_path']);
            }
            if ($actualRow['value'] !== $expectedRow['value']) {
                $problems[] = sprintf('intake_candidates[%d].value mismatch: expected "%s", got "%s"', $i, $expectedRow['value'], $actualRow['value']);
            }
            if (abs($actualRow['confidence'] - $expectedRow['confidence']) > 0.0001) {
                $problems[] = sprintf('intake_candidates[%d].confidence mismatch: expected %f, got %f', $i, $expectedRow['confidence'], $actualRow['confidence']);
            }
        }

        return $problems;
    }

    /**
     * @return array{procedure_order: int, prescriptions: int, lists: int}
     */
    private function globalWriteCounts(): array
    {
        $counts = [];
        foreach (['procedure_order', 'prescriptions', 'lists'] as $table) {
            $count = QueryUtils::fetchSingleValue('SELECT COUNT(*) AS c FROM ' . $table, 'c', []);
            $counts[$table] = is_numeric($count) ? (int) $count : -1;
        }

        /** @var array{procedure_order: int, prescriptions: int, lists: int} $counts */
        return $counts;
    }

    // ------------------------------------------------------------------
    // critical_subset fold-in
    // ------------------------------------------------------------------

    /**
     * @param array<string, int> $totals
     * @param array<string, int> $passedCounts
     */
    private function foldCriticalSubset(array &$totals, array &$passedCounts): void
    {
        $cases = (new GoldenChartCaseLoader())->loadFromDirectory($this->adjudicatedDir);
        $scenarios = CriticalSubsetLabels::chartScenarios();
        $scorer = new Scorer();
        $today = CriticalSubsetLabels::today();

        $casePassed = 0;
        foreach ($cases as $case) {
            $chart = $scenarios[$case->id] ?? throw new \DomainException(sprintf('critical-subset fold-in has no chart scenario for adjudicated case "%s"', $case->id));

            $findings = [];
            foreach (CriticalSubsetDetectors::withDraftTables()->detectAll($chart, $today) as $report) {
                $findings = [...$findings, ...$report->findings];
            }
            $flagged = CriticalSubsetLabels::labelsFor($findings);

            $score = $scorer->score($case, new CaseResult($flagged, $flagged, 0, 0));
            if ($score->missedCritical === [] && $score->falsePositiveFlags === 0) {
                $casePassed++;
            }
        }

        $totals['critical_subset'] = count($cases);
        $passedCounts['critical_subset'] = $casePassed;
    }

    // ------------------------------------------------------------------
    // Fixture-patient / temp-file lifecycle
    // ------------------------------------------------------------------

    /**
     * @return array{pid: int, uuid: string}
     */
    private function createFixturePatient(): array
    {
        $maxPid = QueryUtils::fetchSingleValue('SELECT MAX(pid) AS m FROM patient_data', 'm', []);
        $pid = (is_numeric($maxPid) ? (int) $maxPid : 0) + self::PATIENT_PID_BASE_OFFSET + $this->fixturePatientCounter;
        $this->fixturePatientCounter++;

        QueryUtils::sqlStatementThrowException(
            "INSERT INTO patient_data (pid, pubpid, fname, lname, date, uuid) VALUES (?, ?, ?, ?, NOW(), UNHEX(REPLACE(UUID(),'-','')))",
            [$pid, 'copilot-eval-' . $pid, 'Eval', 'Fixture'],
        );

        $uuidHex = QueryUtils::fetchSingleValue('SELECT LOWER(HEX(uuid)) AS u FROM patient_data WHERE pid = ?', 'u', [$pid]);
        if (!is_string($uuidHex)) {
            throw new \RuntimeException('GoldenSetRunner could not read back the fixture patient uuid');
        }

        $uuid = sprintf(
            '%s-%s-%s-%s-%s',
            substr($uuidHex, 0, 8),
            substr($uuidHex, 8, 4),
            substr($uuidHex, 12, 4),
            substr($uuidHex, 16, 4),
            substr($uuidHex, 20, 12),
        );

        return ['pid' => $pid, 'uuid' => $uuid];
    }

    private function cleanupFixturePatient(int $pid): void
    {
        $resultIds = QueryUtils::fetchTableColumn(
            'SELECT prr.procedure_result_id AS rid FROM procedure_result prr'
                . ' JOIN procedure_report pr ON prr.procedure_report_id=pr.procedure_report_id'
                . ' JOIN procedure_order po ON pr.procedure_order_id=po.procedure_order_id'
                . ' WHERE po.patient_id = ?',
            'rid',
            [$pid],
        );
        foreach ($resultIds as $rid) {
            if (is_numeric($rid)) {
                QueryUtils::sqlStatementThrowException('DELETE FROM ' . ExtractionLineageSchema::LINEAGE_TABLE . ' WHERE procedure_result_id = ?', [(int) $rid]);
            }
        }
        QueryUtils::sqlStatementThrowException('DELETE prr FROM procedure_result prr JOIN procedure_report pr ON prr.procedure_report_id=pr.procedure_report_id JOIN procedure_order po ON pr.procedure_order_id=po.procedure_order_id WHERE po.patient_id = ?', [$pid]);
        QueryUtils::sqlStatementThrowException('DELETE pr FROM procedure_report pr JOIN procedure_order po ON pr.procedure_order_id=po.procedure_order_id WHERE po.patient_id = ?', [$pid]);
        QueryUtils::sqlStatementThrowException('DELETE poc FROM procedure_order_code poc JOIN procedure_order po ON poc.procedure_order_id=po.procedure_order_id WHERE po.patient_id = ?', [$pid]);
        QueryUtils::sqlStatementThrowException('DELETE FROM procedure_order WHERE patient_id = ?', [$pid]);
        QueryUtils::sqlStatementThrowException('DELETE FROM ' . IntakeCandidatesSchema::CANDIDATES_TABLE . ' WHERE patient_pid = ?', [$pid]);

        $docIds = QueryUtils::fetchTableColumn('SELECT id FROM documents WHERE foreign_id = ?', 'id', [$pid]);
        foreach ($docIds as $docId) {
            if (is_numeric($docId)) {
                $url = QueryUtils::fetchSingleValue('SELECT url FROM documents WHERE id = ?', 'url', [(int) $docId]);
                if (is_string($url) && str_starts_with($url, 'file://')) {
                    $path = substr($url, 7);
                    if (is_file($path)) {
                        @unlink($path);
                    }
                }
                QueryUtils::sqlStatementThrowException('DELETE FROM categories_to_documents WHERE document_id = ?', [(int) $docId]);
                QueryUtils::sqlStatementThrowException('DELETE FROM documents WHERE id = ?', [(int) $docId]);
            }
        }
        QueryUtils::sqlStatementThrowException('DELETE FROM patient_data WHERE pid = ?', [$pid]);
    }

    private function writeTempFile(string $bytes, string $filename): string
    {
        $path = sys_get_temp_dir() . '/copilot-goldenset-' . uniqid('', true) . '-' . $filename;
        file_put_contents($path, $bytes);

        return $path;
    }

    private static function mediaTypeFor(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/pdf',
        };
    }

    // ------------------------------------------------------------------
    // Transport wiring
    // ------------------------------------------------------------------

    private function countingTransport(InputKeyedReplayTransport $inner, callable $onCall): \Closure
    {
        return static function (array $requestBody) use ($inner, $onCall): array {
            $onCall();

            // Runtime identity: real vendor request bodies are JSON objects,
            // so every key is already a string — this re-key narrows the
            // untyped closure boundary for the replay transport's contract.
            $keyedRequest = [];
            foreach ($requestBody as $requestKey => $requestValue) {
                $keyedRequest[(string) $requestKey] = $requestValue;
            }

            return $inner($keyedRequest);
        };
    }

    private static function throwingTransport(): \Closure
    {
        return static function (array $requestBody): array {
            throw new \RuntimeException('vendor unavailable (degraded seam, eval gate)');
        };
    }

    // ------------------------------------------------------------------
    // DTO-list narrowing (boundary discipline: the shipped DTOs carry
    // list<mixed> properties validated at their own construction; these
    // helpers re-narrow with instanceof at the read side, never a cast)
    // ------------------------------------------------------------------

    /**
     * @return list<RetrievedChunk>
     */
    private static function retrievedChunks(RetrievalOutcome $outcome): array
    {
        $chunks = [];
        foreach ($outcome->chunks as $chunk) {
            if (!$chunk instanceof RetrievedChunk) {
                throw new \RuntimeException('RetrievalOutcome carried a non-RetrievedChunk element');
            }
            $chunks[] = $chunk;
        }

        return $chunks;
    }

    /**
     * @return list<SupervisorStep>
     */
    private static function planSteps(DispatchResult $result): array
    {
        $steps = [];
        foreach ($result->plan as $step) {
            if (!$step instanceof SupervisorStep) {
                throw new \RuntimeException('DispatchResult carried a non-SupervisorStep plan element');
            }
            $steps[] = $step;
        }

        return $steps;
    }

    // ------------------------------------------------------------------
    // Rubric tagging
    // ------------------------------------------------------------------

    /**
     * @param array<string, list<string>> $rubricFailures
     * @param list<string> $preferred
     *
     * @return array<string, list<string>>
     */
    private function tag(array $rubricFailures, GoldenSetCase $case, array $preferred, string $message): array
    {
        $matched = array_values(array_intersect($preferred, $case->rubrics));
        $targets = $matched !== [] ? $matched : $case->rubrics;
        foreach ($targets as $rubric) {
            $rubricFailures[$rubric][] = $message;
        }

        return $rubricFailures;
    }

    // ------------------------------------------------------------------
    // Boundary parsing helpers
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     */
    private static function requireString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new \DomainException(sprintf('golden-set case input "%s" must be a string', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \DomainException(sprintf('golden-set case input "%s" must be a string or null', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function requireInt(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value)) {
            throw new \DomainException(sprintf('golden-set case input "%s" must be an integer', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function requireBool(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;
        if (!is_bool($value)) {
            throw new \DomainException(sprintf('golden-set case input "%s" must be a boolean', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<mixed>
     */
    private static function listOf(array $data, string $key): array
    {
        $value = $data[$key] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw new \DomainException(sprintf('golden-set case input "%s" must be a list', $key));
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private static function stringKeyed(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \DomainException('golden-set case input entry must be an object');
        }
        $result = [];
        foreach ($value as $k => $v) {
            $result[(string) $k] = $v;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private static function stringList(array $data, string $key): array
    {
        $value = $data[$key] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw new \DomainException(sprintf('golden-set case input "%s" must be a list of strings', $key));
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new \DomainException(sprintf('golden-set case input "%s" must be a list of strings', $key));
            }
            $result[] = $item;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $expected
     *
     * @return list<mixed>|null
     */
    private static function expectedListOrNull(array $expected, string $key): ?array
    {
        if (!array_key_exists($key, $expected)) {
            return null;
        }
        $value = $expected[$key];
        if (!is_array($value) || !array_is_list($value)) {
            throw new \DomainException(sprintf('expected "%s" must be a list', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $expected
     *
     * @return list<string>|null
     */
    private static function expectedStringListOrNull(array $expected, string $key): ?array
    {
        $list = self::expectedListOrNull($expected, $key);
        if ($list === null) {
            return null;
        }
        $result = [];
        foreach ($list as $item) {
            if (!is_string($item)) {
                throw new \DomainException(sprintf('expected "%s" must be a list of strings', $key));
            }
            $result[] = $item;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $expected
     *
     * @return list<int>|null
     */
    private static function expectedIntListOrNull(array $expected, string $key): ?array
    {
        $list = self::expectedListOrNull($expected, $key);
        if ($list === null) {
            return null;
        }
        $result = [];
        foreach ($list as $item) {
            if (!is_int($item)) {
                throw new \DomainException(sprintf('expected "%s" must be a list of integers', $key));
            }
            $result[] = $item;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $expected
     */
    private static function expectedBoolOrNull(array $expected, string $key): ?bool
    {
        if (!array_key_exists($key, $expected)) {
            return null;
        }
        $value = $expected[$key];
        if (!is_bool($value)) {
            throw new \DomainException(sprintf('expected "%s" must be a boolean', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $expected
     */
    private static function expectedIntOrNull(array $expected, string $key): ?int
    {
        if (!array_key_exists($key, $expected)) {
            return null;
        }
        $value = $expected[$key];
        if (!is_int($value)) {
            throw new \DomainException(sprintf('expected "%s" must be an integer', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $expected
     */
    private static function expectedStringOrNull(array $expected, string $key): ?string
    {
        if (!array_key_exists($key, $expected)) {
            return null;
        }
        $value = $expected[$key];
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \DomainException(sprintf('expected "%s" must be a string or null', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $expected
     *
     * @return array<string, mixed>|null
     */
    private static function expectedMapOrNull(array $expected, string $key): ?array
    {
        if (!array_key_exists($key, $expected)) {
            return null;
        }
        $value = $expected[$key];
        if (!is_array($value)) {
            throw new \DomainException(sprintf('expected "%s" must be an object', $key));
        }
        $result = [];
        foreach ($value as $k => $v) {
            $result[(string) $k] = $v;
        }

        return $result;
    }

    /**
     * README lab_rows dialect: only the keys actually PRESENT in a case row
     * are carried through (an omitted key is unconstrained); a present key's
     * value is a string or null (null pins a literal SQL NULL).
     *
     * @param array<string, mixed> $expected
     *
     * @return list<array<string, ?string>>|null present-keys-only rows
     */
    private static function expectedLabRows(array $expected): ?array
    {
        $list = self::expectedListOrNull($expected, 'lab_rows');
        if ($list === null) {
            return null;
        }
        $rows = [];
        foreach ($list as $rowRaw) {
            $row = self::stringKeyed($rowRaw);
            $presentKeys = [];
            foreach ($row as $field => $value) {
                if ($value !== null && !is_string($value)) {
                    throw new \DomainException(sprintf('expected lab_rows column "%s" must be a string or null', $field));
                }
                $presentKeys[$field] = $value;
            }
            $rows[] = $presentKeys;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $expected
     *
     * @return list<array{field_path: string, value: string, confidence: float}>|null
     */
    private static function expectedIntakeCandidates(array $expected): ?array
    {
        $list = self::expectedListOrNull($expected, 'intake_candidates');
        if ($list === null) {
            return null;
        }
        $rows = [];
        foreach ($list as $rowRaw) {
            $row = self::stringKeyed($rowRaw);
            $confidenceRaw = $row['confidence'] ?? null;
            if (!is_int($confidenceRaw) && !is_float($confidenceRaw)) {
                throw new \DomainException('expected intake_candidates[].confidence must be numeric');
            }
            $rows[] = [
                'field_path' => self::requireString($row, 'field_path'),
                'value' => self::requireString($row, 'value'),
                'confidence' => (float) $confidenceRaw,
            ];
        }

        return $rows;
    }
}
