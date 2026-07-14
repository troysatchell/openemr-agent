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
use OpenEMR\Modules\Copilot\Ingestion\DocumentIngestion;
use OpenEMR\Modules\Copilot\Ingestion\DocumentIngestionService;
use OpenEMR\Modules\Copilot\Ingestion\PatientDocumentAttacher;
use OpenEMR\Modules\Copilot\Ingestion\VlmDocumentExtractor;
use OpenEMR\Modules\Copilot\Llm\AnthropicLlmClient;
use OpenEMR\Modules\Copilot\Llm\ChartDataFlattener;
use OpenEMR\Modules\Copilot\Llm\CopilotTask;
use OpenEMR\Modules\Copilot\Llm\DraftPolicies;
use OpenEMR\Modules\Copilot\Llm\LlmClient;
use OpenEMR\Modules\Copilot\Llm\LlmTurnRequest;
use OpenEMR\Modules\Copilot\Llm\LlmTurnResponse;
use OpenEMR\Modules\Copilot\Llm\LlmUnavailableException;
use OpenEMR\Modules\Copilot\Llm\MinimumNecessaryPayloadBuilder;
use OpenEMR\Modules\Copilot\Observability\JsonlTraceRecorder;
use OpenEMR\Modules\Copilot\Observability\ReadinessCheck;
use OpenEMR\Modules\Copilot\Orchestration\ReadThroughChartSnapshotProvider;
use OpenEMR\Modules\Copilot\Orchestration\TurnOrchestrator;
use OpenEMR\Modules\Copilot\Routes\AclRequirement;
use OpenEMR\Modules\Copilot\Routes\DocumentUploadEndpoint;
use OpenEMR\Modules\Copilot\Routes\GuardedRouteRegistrar;
use OpenEMR\Modules\Copilot\Routes\TurnEndpoint;
use OpenEMR\Modules\Copilot\Synthesis\ChartSnapshotSynthesizer;
use OpenEMR\Modules\Copilot\Verification\ClaimVerifier;
use OpenEMR\RestControllers\Config\RestConfig;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Bootstrap
{
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
                ]);

                $report = $check->run();
                if (!$report->ready) {
                    http_response_code(503);
                }

                return ['ready' => $report->ready, 'checks' => $report->checks];
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
                    $endpoint = new TurnEndpoint(self::buildTurnOrchestrator());

                    // Normalise decoded JSON to string keys at this boundary so
                    // the endpoint receives array<string, mixed> (a JSON object's
                    // keys are strings; PHP coerces numeric ones to int).
                    $input = [];
                    foreach ($decoded as $key => $value) {
                        $input[(string) $key] = $value;
                    }

                    return $endpoint->handle(new PhysicianContext($username, $userId), $input);
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
        $pidResolver = static function (string $patientUuid): int {
            $records = QueryUtils::fetchRecords(
                'SELECT `pid` FROM `patient_data` WHERE `uuid` = ?',
                [UuidRegistry::uuidToBytes($patientUuid)],
            );
            $pid = $records[0]['pid'] ?? null;
            if (!is_int($pid) && !(is_string($pid) && ctype_digit($pid))) {
                throw new \DomainException('Unknown patient uuid — no pid mapping in the uuid registry.');
            }

            return (int) $pid;
        };

        return new ReadThroughChartSnapshotProvider(
            new ChartReader(new OpenEmrFhirGateway(new OpenEmrFhirServiceFactory())),
            new FhirChartMapper(),
            new ChartSnapshotSynthesizer(),
            $pidResolver,
        );
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
        $apiKey = getenv('ANTHROPIC_API_KEY') ?: '';
        $llm = trim($apiKey) !== ''
            ? AnthropicLlmClient::forAnthropicApi($apiKey)
            : new class implements LlmClient {
                public function complete(LlmTurnRequest $request): LlmTurnResponse
                {
                    // No key configured: degrade honestly — findings intact,
                    // answer absent — rather than failing the turn (R11).
                    throw new LlmUnavailableException('No language-model API key is configured');
                }
            };

        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            }
        };

        return new TurnOrchestrator(
            self::buildChartSnapshotProvider(),
            CriticalSubsetDetectors::withDraftTables(),
            new ChartDataFlattener(),
            new MinimumNecessaryPayloadBuilder(DraftPolicies::v1()),
            CopilotTask::FollowUpQa,
            EventAuditDisclosureLogger::forEventAuditLogger(),
            $llm,
            new ClaimVerifier(),
            $clock,
            new JsonlTraceRecorder(self::defaultTracePath()),
        );
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
