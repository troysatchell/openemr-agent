<?php

/**
 * Clinical Co-Pilot module bootstrap (ARCHITECTURE.md §2 — module + events,
 * never core edits).
 *
 * Subscribes to RestApiCreateEvent and contributes the module's API routes
 * exclusively through GuardedRouteRegistrar, so every copilot route enforces
 * an explicit ACL before its handler runs (AUDIT S5: OpenEMR has no
 * default-deny gate — this module supplies its own).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Troy Satchell <troysatchell@gmail.com>
 * @copyright Copyright (c) 2026 Troy Satchell <troysatchell@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Events\RestApiExtend\RestApiCreateEvent;
use OpenEMR\Events\RestApiExtend\RestApiScopeEvent;
use OpenEMR\Modules\Copilot\Audit\EventAuditDisclosureLogger;
use OpenEMR\Modules\Copilot\Chart\ChartReader;
use OpenEMR\Modules\Copilot\Chart\FhirChartMapper;
use OpenEMR\Modules\Copilot\Chart\OpenEmrFhirGateway;
use OpenEMR\Modules\Copilot\Chart\OpenEmrFhirServiceFactory;
use OpenEMR\Modules\Copilot\Chart\PhysicianContext;
use OpenEMR\Modules\Copilot\Detectors\CriticalSubsetDetectors;
use OpenEMR\Modules\Copilot\Eval\NoPendingDocumentsIntakeWorker;
use OpenEMR\Modules\Copilot\Ingestion\DocumentIngestion;
use OpenEMR\Modules\Copilot\Ingestion\DocumentIngestionService;
use OpenEMR\Modules\Copilot\Ingestion\PatientDocumentAttacher;
use OpenEMR\Modules\Copilot\Ingestion\VlmDocumentExtractor;
use OpenEMR\Modules\Copilot\Llm\AnthropicLlmClient;
use OpenEMR\Modules\Copilot\Llm\ChartDataFlattener;
use OpenEMR\Modules\Copilot\Llm\CopilotTask;
use OpenEMR\Modules\Copilot\Llm\DraftPolicies;
use OpenEMR\Modules\Copilot\Llm\FieldAllowlist;
use OpenEMR\Modules\Copilot\Llm\LlmClient;
use OpenEMR\Modules\Copilot\Llm\LlmTurnRequest;
use OpenEMR\Modules\Copilot\Llm\LlmTurnResponse;
use OpenEMR\Modules\Copilot\Llm\LlmUnavailableException;
use OpenEMR\Modules\Copilot\Llm\MinimumNecessaryPayloadBuilder;
use OpenEMR\Modules\Copilot\Observability\JsonlTraceRecorder;
use OpenEMR\Modules\Copilot\Observability\ReadinessCheck;
use OpenEMR\Modules\Copilot\Observability\TraceContext;
use OpenEMR\Modules\Copilot\Orchestration\ReadThroughChartSnapshotProvider;
use OpenEMR\Modules\Copilot\Orchestration\SupervisedTurnDispatcher;
use OpenEMR\Modules\Copilot\Orchestration\Supervisor;
use OpenEMR\Modules\Copilot\Orchestration\SupervisorTurnState;
use OpenEMR\Modules\Copilot\Orchestration\TurnOrchestrator;
use OpenEMR\Modules\Copilot\Rag\CohereEmbedClient;
use OpenEMR\Modules\Copilot\Rag\CohereHttpTransport;
use OpenEMR\Modules\Copilot\Rag\CohereRerankClient;
use OpenEMR\Modules\Copilot\Rag\CorpusIndexSchema;
use OpenEMR\Modules\Copilot\Rag\DisclosedEvidenceRetrieverWorker;
use OpenEMR\Modules\Copilot\Rag\EvidenceRetrievalService;
use OpenEMR\Modules\Copilot\Rag\EvidenceRetrieverWorkerImpl;
use OpenEMR\Modules\Copilot\Rag\HybridRetriever;
use OpenEMR\Modules\Copilot\Rag\RetrievalOutcome;
use OpenEMR\Modules\Copilot\Resilience\CircuitBreaker;
use OpenEMR\Modules\Copilot\Routes\AclRequirement;
use OpenEMR\Modules\Copilot\Routes\DocumentUploadEndpoint;
use OpenEMR\Modules\Copilot\Routes\GuardedRouteRegistrar;
use OpenEMR\Modules\Copilot\Routes\SourceResolverEndpoint;
use OpenEMR\Modules\Copilot\Routes\TurnEndpoint;
use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshotSynthesizer;
use OpenEMR\Modules\Copilot\Verification\ClaimVerifier;
use OpenEMR\RestControllers\Config\RestConfig;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Bootstrap
{
    /**
     * Cohere embed model id for the live evidence-retriever composition
     * (Wave K.2, TRO-44) — same default `bin/index-corpus.php` uses for
     * production corpus indexing (`COHERE_EMBED_MODEL` env override), so a
     * query embeds against the same model family the corpus was built with.
     */
    private const DEFAULT_COHERE_EMBED_MODEL = 'embed-english-v3.0';

    /**
     * Cohere rerank model id for the live evidence-retriever composition
     * (Wave K.2, TRO-44). UNVERIFIED against Cohere's current model catalog
     * in this environment (no live network access here) — overridable via
     * `COHERE_RERANK_MODEL`; confirm the current model id before relying on
     * this default in a real deployment.
     */
    private const DEFAULT_COHERE_RERANK_MODEL = 'rerank-english-v3.0';

    /** The evidence-retriever's requested chunk count for the live turn route. */
    private const EVIDENCE_TOP_K = 5;

    /**
     * Circuit-breaker defaults for the three live vendor-client constructions
     * below (TRO-47; W2_ARCHITECTURE.md §8): consecutive failures before a
     * breaker opens, and the cooldown before it admits one half-open probe.
     * A committed default, not yet tuned against production failure/latency
     * data — see `docs/SLOS.md` for the honest MEASURED/PENDING MEASUREMENT
     * accounting of these numbers.
     */
    private const BREAKER_FAILURE_THRESHOLD = 3;
    private const BREAKER_COOLDOWN_SECONDS = 60;

    public function __construct(private readonly EventDispatcherInterface $eventDispatcher)
    {
    }

    /**
     * Default trace-sink path (T17). Not a class constant because
     * sys_get_temp_dir() is not a compile-time constant expression. A
     * composition-root concern: Wave 2 wiring makes this configurable; for
     * now it names a fixed location so the trace_sink readiness probe has
     * something concrete to check.
     */
    public static function defaultTracePath(): string
    {
        return sys_get_temp_dir() . '/copilot-trace.jsonl';
    }

    public function subscribeToEvents(): void
    {
        $this->eventDispatcher->addListener(RestApiCreateEvent::EVENT_HANDLE, $this->registerApiRoutes(...));
        $this->eventDispatcher->addListener(RestApiScopeEvent::EVENT_TYPE_GET_SUPPORTED_SCOPES, $this->registerApiScopes(...));
    }

    /**
     * Registers the OAuth2 scopes the module's routes require (T20 live
     * smoke finding): the core dispatcher derives each route's required
     * scope from its FINAL path segment (HttpRestParsedRoute) as
     * `user/<segment>.<read|write>`, and rejects any token whose scope was
     * never registered — so without this listener every module route 401s
     * before the guard wrapper even runs. Scope names are therefore
     * segment-generic ('health', 'ready', 'turn'); acceptable for v1,
     * revisit if core ever claims those resource names.
     */
    public function registerApiScopes(RestApiScopeEvent $event): void
    {
        if ($event->getApiType() !== RestApiScopeEvent::API_TYPE_STANDARD) {
            return;
        }

        $event->addScope('user', 'ping', 'read');
        $event->addScope('user', 'health', 'read');
        $event->addScope('user', 'ready', 'read');
        $event->addScope('user', 'turn', 'write');
        $event->addScope('user', 'document', 'write');
        // 'source' is a POST route (the citation token rides the request
        // body), and the core dispatcher derives a POST's required action as
        // create (checked as `user/source.c`). Declaring it 'write' — not
        // 'read' — is what makes that check satisfiable; a client granted
        // `user/source.read` fails the create-action check (live smoke,
        // 2026-07-14). Semantically it is still a read-only resolve;
        // tightening the route to a read-scoped verb is tracked separately.
        $event->addScope('user', 'source', 'write');
    }

    public function registerApiRoutes(RestApiCreateEvent $event): void
    {
        $registrar = new GuardedRouteRegistrar(
            static function (HttpRestRequest $request, string $section, string $value): void {
                RestConfig::request_authorization_check($request, $section, $value);
            }
        );

        $registrar->register(
            'GET /api/copilot/ping',
            new AclRequirement('patients', 'demo'),
            static function (HttpRestRequest $request): array {
                return ['status' => 'ok'];
            }
        );

        $registrar->register(
            'GET /api/copilot/health',
            new AclRequirement('patients', 'demo'),
            static function (HttpRestRequest $request): array {
                // Process liveness only — no dependency checks belong here.
                return ['status' => 'alive'];
            }
        );

        $registrar->register(
            'GET /api/copilot/ready',
            new AclRequirement('patients', 'demo'),
            static function (HttpRestRequest $request): array {
                $tracePath = self::defaultTracePath();
                $check = new ReadinessCheck([
                    'db' => static function (): bool {
                        QueryUtils::fetchRecords('SELECT 1', []);

                        return true;
                    },
                    'trace_sink' => static fn (): bool => is_writable(dirname($tracePath)),
                    // Config-presence only until the T18 LLM adapter
                    // supplies a real endpoint probe.
                    'llm' => static fn (): bool => (getenv('ANTHROPIC_API_KEY') ?: '') !== '',
                    // Cheap metadata-only reachability check (no row scan,
                    // no heavy IO) against core's native document table —
                    // write (a) of the two-write amendment lands there.
                    'document-storage' => static function (): bool {
                        // information_schema, not SHOW ... LIKE ?: the live
                        // MariaDB driver refuses placeholders in SHOW
                        // statements (found on the deployed /ready).
                        return QueryUtils::fetchRecords(
                            'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
                            ['documents'],
                        ) !== [];
                    },
                    // Same cheap metadata-only check against the module-owned
                    // corpus chunk table (CorpusIndexSchema) — existence only,
                    // never a content scan.
                    'vector-index' => static function (): bool {
                        // Dense retrieval needs the embeddings, not just the
                        // chunk text — probe both so a partial install can
                        // never report ready and fail on retrieval.
                        $tableExists = static fn (string $table): bool => QueryUtils::fetchRecords(
                            'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
                            [$table],
                        ) !== [];

                        return $tableExists(CorpusIndexSchema::CHUNK_TABLE)
                            && $tableExists(CorpusIndexSchema::EMBEDDING_TABLE);
                    },
                    // Missing key degrades evidence honestly (PS-12) rather
                    // than failing the whole turn — 'degraded', never
                    // 'failed', so the reranker's absence never trips /ready
                    // into 503 on its own (TRO-47; W2_ARCHITECTURE.md §8).
                    'reranker' => static function (): bool|string {
                        return (getenv('COHERE_API_KEY') ?: '') !== '' ? true : 'degraded';
                    },
                ]);

                $report = $check->run();
                if (!$report->ready) {
                    http_response_code(503);
                }

                return ['ready' => $report->ready, 'checks' => $report->checks, 'statuses' => $report->statuses];
            }
        );

        $registrar->register(
            'POST /api/copilot/turn',
            new AclRequirement('patients', 'med'),
            static function (HttpRestRequest $request): array {
                // Delegation, never a service account (S4/S6): the principal
                // is the authenticated API user; a request without one is
                // refused before any read.
                $user = $request->getRequestUser();
                $username = is_string($user['username'] ?? null) ? $user['username'] : '';
                $userId = $request->getRequestUserId();
                if (trim($username) === '' || $userId === null) {
                    http_response_code(403);

                    return ['error' => 'No authenticated user principal for this request.'];
                }

                try {
                    $decoded = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    http_response_code(400);

                    return ['error' => 'Request body must be valid JSON.'];
                }
                if (!is_array($decoded)) {
                    http_response_code(400);

                    return ['error' => 'Request body must be a JSON object.'];
                }

                try {
                    $physician = new PhysicianContext($username, $userId);

                    // Normalise decoded JSON to string keys at this boundary so
                    // the endpoint receives array<string, mixed> (a JSON object's
                    // keys are strings; PHP coerces numeric ones to int).
                    $input = [];
                    foreach ($decoded as $key => $value) {
                        $input[(string) $key] = $value;
                    }

                    // Wave K.2 (TRO-44; UC7): an explicit clinician toggle —
                    // never an invented NLP classification of the question —
                    // read here in the route so TurnEndpoint's own contract
                    // stays additive-only (the flag never enters its wire
                    // shape; only the resolved RetrievalOutcome does).
                    $askEvidenceRaw = $input['ask_evidence'] ?? false;
                    $askEvidence = is_bool($askEvidenceRaw) && $askEvidenceRaw;
                    $evidence = $askEvidence ? self::resolveLiveEvidence($physician, $input) : null;

                    $endpoint = new TurnEndpoint(self::buildTurnOrchestrator());

                    return $endpoint->handle($physician, $input, $evidence);
                } catch (\DomainException) {
                    // Generic by design — never echo internals (R11).
                    http_response_code(400);

                    return ['error' => 'Invalid request: patient_uuid and question are required.'];
                }
            }
        );

        $registrar->register(
            'POST /api/copilot/document',
            // Same ACL as the turn route: the two-write amendment (attach +
            // persist derived facts, W2_ARCHITECTURE.md §2/§1) executes as
            // the delegated physician against the same patient-scoped
            // clinical data the turn path reads — never a service account
            // (S4/S6) — so it rides the identical section/value pairing.
            new AclRequirement('patients', 'med'),
            static function (HttpRestRequest $request): array {
                // Delegation, never a service account (S4/S6): same
                // principal extraction as the turn route.
                $user = $request->getRequestUser();
                $username = is_string($user['username'] ?? null) ? $user['username'] : '';
                $userId = $request->getRequestUserId();
                if (trim($username) === '' || $userId === null) {
                    http_response_code(403);

                    return ['error' => 'No authenticated user principal for this request.'];
                }

                try {
                    $decoded = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    http_response_code(400);

                    return ['error' => 'Request body must be valid JSON.'];
                }
                if (!is_array($decoded)) {
                    http_response_code(400);

                    return ['error' => 'Request body must be a JSON object.'];
                }

                try {
                    // TRO-17/18/32 compose the real DocumentIngestion
                    // (attach via DocumentService + dedupe-by-hash, then VLM
                    // extraction + persistence, §2 steps 2-5). See
                    // buildDocumentIngestion() for the no-key degrade path.
                    $ingestion = self::buildDocumentIngestion();
                    $endpoint = new DocumentUploadEndpoint($ingestion);

                    // Normalise decoded JSON to string keys — see the turn
                    // route above for why.
                    $input = [];
                    foreach ($decoded as $key => $value) {
                        $input[(string) $key] = $value;
                    }

                    return $endpoint->handle(new PhysicianContext($username, $userId), $input);
                } catch (\DomainException) {
                    // Generic by design — never echo internals (R11).
                    http_response_code(400);

                    return [
                        'error' => 'Invalid request: patient_uuid, doc_type, file_path, '
                            . 'and file_size_bytes are required.',
                    ];
                } catch (LlmUnavailableException) {
                    // No API key configured — buildDocumentIngestion()'s
                    // no-key degrade path throws here (R11: degrade
                    // honestly rather than silently faking ingestion).
                    http_response_code(501);

                    return ['error' => 'Document ingestion is not yet available.'];
                } catch (\RuntimeException) {
                    // Genuine storage/transaction failure (§2 failure table:
                    // storage failure -> generic error, nothing persisted).
                    // Generic by design — never echo internals (R11).
                    http_response_code(500);

                    return ['error' => 'Document ingestion failed.'];
                }
            }
        );

        $registrar->register(
            'POST /api/copilot/source',
            // Same ACL as the turn/document routes: resolving a citation can
            // surface patient-scoped document content (the document/
            // derived_observation branches), so it rides the identical
            // section/value pairing rather than a narrower one that would
            // have to vary by token type.
            new AclRequirement('patients', 'med'),
            static function (HttpRestRequest $request): array {
                // Delegation, never a service account (S4/S6): same
                // principal extraction as the turn/document routes.
                $user = $request->getRequestUser();
                $username = is_string($user['username'] ?? null) ? $user['username'] : '';
                $userId = $request->getRequestUserId();
                if (trim($username) === '' || $userId === null) {
                    http_response_code(403);

                    return ['error' => 'No authenticated user principal for this request.'];
                }

                try {
                    $decoded = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    http_response_code(400);

                    return ['error' => 'Request body must be valid JSON.'];
                }
                if (!is_array($decoded)) {
                    http_response_code(400);

                    return ['error' => 'Request body must be a JSON object.'];
                }

                try {
                    $endpoint = SourceResolverEndpoint::forLiveResolution();

                    // Normalise decoded JSON to string keys — see the turn
                    // route above for why.
                    $input = [];
                    foreach ($decoded as $key => $value) {
                        $input[(string) $key] = $value;
                    }

                    return $endpoint->handle(new PhysicianContext($username, $userId), $input);
                } catch (\DomainException) {
                    // Generic by design — never echo internals (R11); this
                    // also covers the malformed/unresolvable/cross-patient
                    // token cases SourceResolverEndpoint itself refuses.
                    http_response_code(400);

                    return ['error' => 'Invalid request: token and patient_uuid are required and must resolve.'];
                }
            }
        );

        $registrar->applyTo($event);
    }

    /**
     * Composition root for the read-through chart snapshot provider shared
     * by the live turn path (T20) and the session panel's snapshot endpoint
     * (T21). DB-backed and NOT covered by the isolated suite — verified at
     * live-stack smoke (Wave 3).
     *
     * The uuid→pid resolver is the DB uuid registry's job (D7: pid is the
     * trusted surrogate key; FHIR content is never the pid source).
     */
    public static function buildChartSnapshotProvider(): ReadThroughChartSnapshotProvider
    {
        return new ReadThroughChartSnapshotProvider(
            new ChartReader(new OpenEmrFhirGateway(new OpenEmrFhirServiceFactory())),
            new FhirChartMapper(),
            new ChartSnapshotSynthesizer(),
            self::resolvePatientPid(...),
        );
    }

    /**
     * Resolves a caller-supplied patient uuid to the trusted `pid` (D7: pid
     * is the trusted surrogate key; FHIR/uuid content is never the pid
     * source). Shared by the chart-snapshot provider above and the live
     * evidence composition below (Wave K.2, TRO-44) — one uuid→pid resolver,
     * not two copies drifting independently.
     */
    private static function resolvePatientPid(string $patientUuid): int
    {
        $records = QueryUtils::fetchRecords(
            'SELECT `pid` FROM `patient_data` WHERE `uuid` = ?',
            [UuidRegistry::uuidToBytes($patientUuid)],
        );
        $pid = $records[0]['pid'] ?? null;
        if (!is_int($pid) && !(is_string($pid) && ctype_digit($pid))) {
            throw new \DomainException('Unknown patient uuid — no pid mapping in the uuid registry.');
        }

        return (int) $pid;
    }

    /**
     * Composition root for the live turn path (T20). DB-backed and NOT
     * covered by the isolated suite — verified at live-stack smoke (Wave 3).
     *
     * The LLM client reads ANTHROPIC_API_KEY from the environment — the one
     * sanctioned key source (never the DB, never committed); when the key is
     * absent every turn degrades honestly instead of failing (R11).
     */
    public static function buildTurnOrchestrator(): TurnOrchestrator
    {
        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            }
        };

        // TRO-47: an open breaker fails the turn's LLM call immediately,
        // without invoking the transport, instead of hanging on a vendor
        // already known to be down (R11). Constructed fresh per request —
        // see docs/SLOS.md for the honest accounting of what that does and
        // does not protect against without a persistent, cross-request store.
        $llmBreaker = new CircuitBreaker(
            'anthropic-llm',
            self::BREAKER_FAILURE_THRESHOLD,
            self::BREAKER_COOLDOWN_SECONDS,
            $clock,
        );

        $apiKey = getenv('ANTHROPIC_API_KEY') ?: '';
        $llm = trim($apiKey) !== ''
            ? AnthropicLlmClient::forAnthropicApi($apiKey, breaker: $llmBreaker)
            : new class implements LlmClient {
                public function complete(LlmTurnRequest $request): LlmTurnResponse
                {
                    // No key configured: degrade honestly — findings intact,
                    // answer absent — rather than failing the turn (R11).
                    throw new LlmUnavailableException('No language-model API key is configured');
                }
            };

        return new TurnOrchestrator(
            self::buildChartSnapshotProvider(),
            CriticalSubsetDetectors::withDraftTables(),
            new ChartDataFlattener(),
            new MinimumNecessaryPayloadBuilder(self::buildTurnOrchestratorPolicies()),
            CopilotTask::FollowUpQa,
            EventAuditDisclosureLogger::forEventAuditLogger(),
            $llm,
            new ClaimVerifier(),
            $clock,
            new JsonlTraceRecorder(self::defaultTracePath()),
        );
    }

    /**
     * The live turn route's per-task field allowlists (Wave K.2, TRO-44;
     * W2_ARCHITECTURE.md §4/§6; PS-14).
     *
     * `DraftPolicies::v1()` itself is left untouched — it is the shared,
     * founder-owned DRAFT governance artifact (see its own class docblock:
     * "changing a field list here is a clinical-governance decision, not an
     * engineering one — escalate, don't edit"), and `DraftPoliciesRefFieldTest`
     * pins its exact shape for every task. This composition root instead
     * widens ONLY the copy used for the live FollowUpQa turn — the task the
     * `/api/copilot/turn` route actually runs — adding the `guideline_evidence`
     * data class (`chunk`, `source`, `heading`, `snippet`, `ref`) that
     * `TurnOrchestrator::withGuidelineEvidence()` folds a supplied
     * `RetrievalOutcome`'s chunks into. Without this, evidence entering the
     * flattened chart data would never survive `MinimumNecessaryPayloadBuilder`
     * (it only reads data classes the task's own policy names), so the model
     * would never see it — this is what makes the live evidence seam actually
     * reach the LLM, not merely compose a `RetrievalOutcome` nobody reads.
     *
     * Snapshot and PreChart are the zero-RAG-on-snapshot paths (§5) and are
     * never supplied evidence, so their allowlists are left exactly as
     * `DraftPolicies::v1()` returns them.
     *
     * @return array<string, FieldAllowlist> CopilotTask backing value => allowlist
     */
    private static function buildTurnOrchestratorPolicies(): array
    {
        $policies = DraftPolicies::v1();

        $followUpFields = $policies[CopilotTask::FollowUpQa->value]->fieldsByDataClass();
        $followUpFields['guideline_evidence'] = ['chunk', 'source', 'heading', 'snippet', 'ref'];
        $policies[CopilotTask::FollowUpQa->value] = new FieldAllowlist($followUpFields);

        return $policies;
    }

    /**
     * Resolves this turn's guideline evidence for the live route (Wave K.2,
     * TRO-44) through the REAL supervised dispatch — `Supervisor` +
     * `SupervisedTurnDispatcher` + the real `EvidenceRetrieverWorkerImpl`
     * (worker stubs never leave orchestration unit tests, §6), embed/rerank
     * on `CohereHttpTransport`, keyed from `COHERE_API_KEY`. A missing key
     * composes the degraded pair per PS-12 inside `EvidenceRetrievalService`
     * itself rather than failing here.
     *
     * The supervised state is fixed to "evidence requested, nothing else in
     * play" (`isSnapshotTurn`/`hasPendingUnextractedDocument`/
     * `criticalFindingPresent`/`physicianEngagedCriticalFinding` all false;
     * `questionAsksForEvidence` true) — this composition answers only "the
     * physician explicitly asked for guideline evidence this turn" (UC7); it
     * does not attempt pending-document intake on the turn path (a TRO-32
     * residual, out of this ticket's scope), so the intake worker passed to
     * the dispatcher is never actually invoked given this fixed state.
     *
     * Malformed/missing patient_uuid or question returns null immediately —
     * this method never decides the request's fate; `TurnEndpoint` refuses
     * those independently regardless of what evidence (if any) is supplied.
     *
     * A dispatch failure degrades the TURN, not just retrieval: anything
     * other than `\Error`/`\ErrorException` (which propagate, per this
     * repo's forbidden-catch-type convention) returns null — evidence-less is
     * an honest fallback, never a route error (the physician still gets
     * findings and a chart-grounded answer, only without this turn's
     * guideline citations).
     *
     * @param array<string, mixed> $input
     */
    private static function resolveLiveEvidence(PhysicianContext $physician, array $input): ?RetrievalOutcome
    {
        $patientUuid = $input['patient_uuid'] ?? null;
        $question = $input['question'] ?? null;
        if (!is_string($patientUuid) || trim($patientUuid) === '' || !is_string($question) || trim($question) === '') {
            return null;
        }

        try {
            // Cheap and idempotent (CREATE TABLE IF NOT EXISTS); population
            // rides the committed `bin/index-corpus.php` deployment step, not
            // this request path.
            CorpusIndexSchema::ensureInstalled();

            $patientPid = self::resolvePatientPid($patientUuid);

            $embedModel = getenv('COHERE_EMBED_MODEL') ?: self::DEFAULT_COHERE_EMBED_MODEL;
            $rerankModel = getenv('COHERE_RERANK_MODEL') ?: self::DEFAULT_COHERE_RERANK_MODEL;

            $evidenceClock = new class implements ClockInterface {
                public function now(): \DateTimeImmutable
                {
                    return new \DateTimeImmutable();
                }
            };

            // TRO-47: an open breaker fails the embed/rerank call
            // immediately, without invoking the transport, instead of
            // hanging on a vendor already known to be down (R11).
            // Constructed fresh per request — see docs/SLOS.md for the
            // honest accounting of what that does and does not protect
            // against without a persistent, cross-request store.
            $embedBreaker = new CircuitBreaker(
                'cohere-embed',
                self::BREAKER_FAILURE_THRESHOLD,
                self::BREAKER_COOLDOWN_SECONDS,
                $evidenceClock,
            );
            $rerankBreaker = new CircuitBreaker(
                'cohere-rerank',
                self::BREAKER_FAILURE_THRESHOLD,
                self::BREAKER_COOLDOWN_SECONDS,
                $evidenceClock,
            );

            // The recorder makes embed/rerank vendor units land in the same
            // JSONL trace the turn writes, so per-vendor cost stays derivable
            // from traces alone (TRO-46).
            $vendorTraceRecorder = new JsonlTraceRecorder(self::defaultTracePath());
            $embedder = new CohereEmbedClient(CohereHttpTransport::forEmbed(), $embedModel, $vendorTraceRecorder, $embedBreaker);
            $reranker = new CohereRerankClient(CohereHttpTransport::forRerank(), $rerankModel, $vendorTraceRecorder, $rerankBreaker);
            // The question text crossing to the embed/rerank vendor is a
            // disclosed crossing like every other vendor crossing (C1/C5) —
            // logged before the call, per DisclosedEvidenceRetrieverWorker.
            $evidenceWorker = new DisclosedEvidenceRetrieverWorker(
                new EvidenceRetrieverWorkerImpl(
                    new EvidenceRetrievalService($embedder, new HybridRetriever($reranker)),
                ),
                EventAuditDisclosureLogger::forEventAuditLogger(),
                $physician,
                $patientPid,
                $evidenceClock,
            );

            $dispatcher = new SupervisedTurnDispatcher(
                new Supervisor(),
                new NoPendingDocumentsIntakeWorker(),
                $evidenceWorker,
                new JsonlTraceRecorder(self::defaultTracePath()),
            );

            $state = new SupervisorTurnState(false, false, true, false, false);
            $turnSpan = TraceContext::start('turn', new \DateTimeImmutable());

            $result = $dispatcher->dispatch($physician, $state, $patientPid, $question, self::EVIDENCE_TOP_K, $turnSpan);

            return $result->evidence;
        } catch (\Throwable $e) {
            if (!($e instanceof \Error) && !($e instanceof \ErrorException)) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Composition root for the document-ingestion route (TRO-32; §2). DB-
     * backed (via the composed writers) and NOT covered by the isolated
     * suite — verified by the DB-backed
     * `tests/Tests/Services/Copilot/DocumentIngestionServiceTest.php` and at
     * live-stack smoke (Wave 3).
     *
     * The VLM transport reads ANTHROPIC_API_KEY from the environment — same
     * sanctioned key source as buildTurnOrchestrator() (never the DB, never
     * committed) — and mirrors AnthropicLlmClient::forAnthropicApi()'s own
     * Guzzle client construction, because VlmDocumentExtractor takes the raw
     * transport closure directly rather than an AnthropicLlmClient instance.
     * When the key is absent, ingestion degrades to the same throwing stub
     * the route used before this port was composed, so an unconfigured
     * deployment still gets a wired, guarded route that reports 501 instead
     * of silently pretending to ingest (R11).
     */
    public static function buildDocumentIngestion(): DocumentIngestion
    {
        $apiKey = getenv('ANTHROPIC_API_KEY') ?: '';
        if (trim($apiKey) === '') {
            return new class implements DocumentIngestion {
                public function attachAndExtract(
                    PhysicianContext $physician,
                    string $patientUuid,
                    string $filePath,
                    string $docType,
                ): array {
                    throw new LlmUnavailableException('document ingestion not yet available (no LLM API key configured)');
                }
            };
        }

        $httpClient = new \GuzzleHttp\Client([
            'base_uri' => 'https://api.anthropic.com',
            'timeout' => 60,
            'connect_timeout' => 5,
            'http_errors' => false,
        ]);

        /**
         * @param array<string, mixed> $requestBody
         *
         * @return array{int, array<string, mixed>}
         */
        $transport = static function (array $requestBody) use ($httpClient, $apiKey): array {
            $response = $httpClient->post('/v1/messages', [
                'headers' => [
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ],
                'json' => $requestBody,
            ]);

            $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new \RuntimeException('The VLM API response body did not decode to a JSON object');
            }

            // Normalise to string keys so the transport contract's shape holds.
            $body = [];
            foreach ($decoded as $key => $value) {
                $body[(string) $key] = $value;
            }

            return [$response->getStatusCode(), $body];
        };

        $extractor = new VlmDocumentExtractor(
            $transport,
            EventAuditDisclosureLogger::forEventAuditLogger(),
            AnthropicLlmClient::DEFAULT_MODEL,
        );

        return new DocumentIngestionService(new PatientDocumentAttacher(), $extractor);
    }
}
