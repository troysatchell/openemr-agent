<?php

/**
 * The explicit, reviewed regeneration command for the golden-set eval gate's
 * committed derived artifacts (TRO-35/TRO-36 residual; eval/goldenset/README.md
 * "Baseline + regeneration"; W2_ARCHITECTURE.md §7; PS-11).
 *
 * Re-records `eval/goldenset/vendor-fixtures.json` from the frozen, committed
 * cases (VLM responses through the real request-building path — never
 * hand-computed hashes — plus deterministic embed/rerank vectors and
 * relevance scores over the real, freshly-rebuilt corpus index), then runs
 * the gate against those fresh fixtures and writes
 * `eval/goldenset/baseline.json`. Both outputs are GENERATED, REVIEWED
 * artifacts: this is the ONLY path that ever produces them — never CI, never
 * a side effect of a green test run. Review the diff before committing; the
 * committed baseline must be all-pass by construction (the gate refuses to
 * write one otherwise, printing every red case's rubric failures instead).
 *
 * Usage (in the openemr container):
 *   php interface/modules/custom_modules/oe-module-copilot/bin/regenerate-eval-goldenset.php
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Core\Kernel;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\Copilot\Chart\PhysicianContext;
use OpenEMR\Modules\Copilot\Corpus\CorpusChunker;
use OpenEMR\Modules\Copilot\Corpus\CorpusManifest;
use OpenEMR\Modules\Copilot\Eval\DeterministicVectors;
use OpenEMR\Modules\Copilot\Eval\DiscardingDisclosureLogger;
use OpenEMR\Modules\Copilot\Eval\EvalVendorConfig;
use OpenEMR\Modules\Copilot\Eval\GoldenCaseKind;
use OpenEMR\Modules\Copilot\Eval\GoldenSetCaseLoader;
use OpenEMR\Modules\Copilot\Eval\GoldenSetRunner;
use OpenEMR\Modules\Copilot\Eval\InputKeyedReplayTransport;
use OpenEMR\Modules\Copilot\Ingestion\VlmDocumentExtractor;
use OpenEMR\Modules\Copilot\Observability\TraceContext;
use OpenEMR\Modules\Copilot\Rag\CohereEmbedClient;
use OpenEMR\Modules\Copilot\Rag\CohereRerankClient;
use OpenEMR\Modules\Copilot\Rag\CorpusIndexer;
use OpenEMR\Modules\Copilot\Rag\CorpusIndexSchema;
use OpenEMR\Modules\Copilot\Rag\EvidenceRetrievalService;
use OpenEMR\Modules\Copilot\Rag\HybridRetriever;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$ci = getenv('CI');
if ($ci !== false && trim($ci) !== '') {
    fwrite(STDERR, "Refusing to run under CI — the eval-gate baseline is regenerated only by an explicit, reviewed, human-invoked run.\n");
    exit(1);
}

require_once __DIR__ . '/../../../../../vendor/autoload.php';

// The module's PSR-4 mapping lives in the root composer.json's autoload-dev,
// which a production build (composer install --no-dev) drops — mirrors
// bin/index-corpus.php's self-contained bootstrap so this command runs
// identically in dev and on prod.
spl_autoload_register(static function (string $class): void {
    $prefix = 'OpenEMR\\Modules\\Copilot\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Minimal, explicit DB-connection config resolution — NOT interface/globals.php
// (CLAUDE.md danger zone): no session, no auth, no $ignoreAuth. QueryUtils'
// ADODB layer resolves its connection from OE_SITE_DIR alone.
$siteDir = getenv('OE_SITE_DIR');
if ($siteDir === false || trim($siteDir) === '') {
    $siteDir = dirname(__DIR__, 5) . '/sites/default';
}
OEGlobalsBag::getInstance()->set('OE_SITE_DIR', $siteDir);

// Per-site configuration (document repository path, among other plain
// settings) — a config file, not interface/globals.php: no session, no
// auth, no $ignoreAuth. Without this, PatientDocumentAttacher's writes
// (via core's Document::createDocument()) resolve a null repository path
// and land under the process's cwd instead of the real per-site documents
// directory, and the module's own cleanup can no longer find them.
if (is_file($siteDir . '/config.php')) {
    require_once $siteDir . '/config.php';
}

// Warm up the ADODB connection BEFORE any output: OpenEMR's legacy
// sql.inc.php unconditionally starts a PHP session on its first require
// (via SessionWrapperFactory), and PHP refuses session_start() once any
// output has already been sent — even under the CLI SAPI. Touching the DB
// once, here, first, keeps every later echo/DB-call in this script from
// tripping that "headers already sent" failure.
\OpenEMR\Common\Database\QueryUtils::fetchSingleValue('SELECT 1 AS one', 'one', []);

// PatientDocumentAttacher's write path goes through core's own
// Document::createDocument(), which reaches OEGlobalsBag::getKernel() for
// its event dispatcher — a lightweight, self-contained Kernel (no session,
// no auth, no route/controller wiring), safe to construct directly here.
OEGlobalsBag::getInstance()->set('kernel', new Kernel(dirname(__DIR__, 5), ''));

$moduleDir = __DIR__ . '/..';
$casesDir = $moduleDir . '/eval/goldenset/cases';
$corpusDir = $moduleDir . '/corpus';

echo "Clinical Co-Pilot — golden-set eval fixture + baseline regeneration\n";

$cases = (new GoldenSetCaseLoader())->loadFromDirectory($casesDir);
echo sprintf("  cases loaded: %d\n", count($cases));

// ---------------------------------------------------------------------
// 1. Chunk the corpus (mirrors CorpusIndexer::chunkManifest()) so this
//    script can compute chunk vectors / a body->chunkId reverse map without
//    duplicating chunking logic incorrectly.
// ---------------------------------------------------------------------

$manifest = CorpusManifest::fromDirectory($corpusDir);
$chunks = [];
foreach ($manifest->documents() as $document) {
    foreach (CorpusChunker::chunkFile($corpusDir . '/' . $document->file) as $chunk) {
        $chunks[] = $chunk;
    }
}

$dimensions = CorpusIndexSchema::EMBEDDING_DIMENSIONS;

/** @var array<string, list<float>> $chunkVectorsById */
$chunkVectorsById = [];
/** @var array<string, string> $bodyToChunkId */
$bodyToChunkId = [];
foreach ($chunks as $chunk) {
    $text = $chunk->heading . "\n" . $chunk->body;
    $chunkVectorsById[$chunk->chunkId] = DeterministicVectors::vectorForText($text, $dimensions);
    $bodyToChunkId[$chunk->body] = $chunk->chunkId;
}

/** @var array<string, array{int, array<string, mixed>}> $embedFixtures */
$embedFixtures = [];
/** @var array<string, array{int, array<string, mixed>}> $rerankFixtures */
$rerankFixtures = [];
/** @var array<string, array{int, array<string, mixed>}> $vlmFixtures */
$vlmFixtures = [];

// ---------------------------------------------------------------------
// 2. Rebuild the corpus index for real, recording the one indexing embed
//    call through the real CorpusIndexer request-building path.
//
//    Tables are DROPPED first (the frozen CorpusIndexerTest's own setUp
//    discipline, verified empirically): InnoDB FULLTEXT retains deleted-row
//    ghost entries in its relevance statistics after a DELETE-based rebuild,
//    so MATCH() scores — and the deterministic relevance ORDER the rerank
//    request's document list depends on — drift between rebuilds of the
//    identical corpus unless the tables start fresh. GoldenSetRunner drops
//    them the same way, so record-time and replay-time candidate order (and
//    therefore the strict order-sensitive fixture hashes, PS-2) agree.
// ---------------------------------------------------------------------

\OpenEMR\Common\Database\QueryUtils::sqlStatementThrowException('DROP TABLE IF EXISTS ' . CorpusIndexSchema::EMBEDDING_TABLE, []);
\OpenEMR\Common\Database\QueryUtils::sqlStatementThrowException('DROP TABLE IF EXISTS ' . CorpusIndexSchema::CHUNK_TABLE, []);
CorpusIndexSchema::ensureInstalled();

$indexEmbedTransport = static function (array $requestBody) use (&$embedFixtures, $dimensions): array {
    $texts = $requestBody['texts'] ?? null;
    if (!is_array($texts)) {
        throw new \RuntimeException('regen: corpus embed request is missing "texts"');
    }
    $vectors = [];
    foreach ($texts as $text) {
        if (!is_string($text)) {
            throw new \RuntimeException('regen: corpus embed request text is not a string');
        }
        $vectors[] = DeterministicVectors::vectorForText($text, $dimensions);
    }
    $body = ['embeddings' => ['float' => $vectors]];
    $keyedRequest = [];
    foreach ($requestBody as $requestKey => $requestValue) {
        $keyedRequest[(string) $requestKey] = $requestValue;
    }
    $embedFixtures[InputKeyedReplayTransport::keyFor($keyedRequest)] = [200, $body];

    return [200, $body];
};

$indexReport = (new CorpusIndexer(new CohereEmbedClient($indexEmbedTransport, EvalVendorConfig::EMBED_MODEL_ID)))->rebuild($corpusDir);
echo sprintf("  corpus indexed: %d chunks, %d embeddings\n", $indexReport->chunksIndexed, $indexReport->embeddingsStored);
if ($indexReport->embeddingsSkipped) {
    fwrite(STDERR, "regen: corpus embedding was skipped against a deterministic transport — this must never happen\n");
    exit(1);
}

// ---------------------------------------------------------------------
// 3. Record VLM fixtures: every extraction-kind case, plus every turn-kind
//    case's derived_setup, through the real VlmDocumentExtractor request
//    path.
// ---------------------------------------------------------------------

// Script helpers live as static closures assigned to variables, never free
// global functions (the project's PHPStan namespace rule for conditionally-
// defined globals applies to CLI scripts too).

$regenMediaTypeFor = static function (string $filename): string {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    return match ($extension) {
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        default => 'application/pdf',
    };
};

/**
 * Narrows an untrusted case-input subtree to a string-keyed map (boundary
 * discipline: narrow at the extraction point, never cast).
 *
 * @return array<string, mixed>
 */
$regenStringKeyed = static function (mixed $value): array {
    if (!is_array($value)) {
        throw new \RuntimeException('regen: expected a JSON object in the case inputs');
    }
    $result = [];
    foreach ($value as $key => $item) {
        $result[(string) $key] = $item;
    }

    return $result;
};

/**
 * @param array<string, mixed> $wire
 */
$regenRecordVlm = static function (string $docType, string $filename, string $bytes, array $wire) use (&$vlmFixtures, $regenMediaTypeFor, $regenStringKeyed): void {
    $wireJson = (string) json_encode($wire, JSON_THROW_ON_ERROR);

    $transport = static function (array $requestBody) use (&$vlmFixtures, $wireJson, $regenStringKeyed): array {
        $body = ['content' => [['type' => 'text', 'text' => $wireJson]]];
        $vlmFixtures[InputKeyedReplayTransport::keyFor($regenStringKeyed($requestBody))] = [200, $body];

        return [200, $body];
    };

    $extractor = new VlmDocumentExtractor($transport, new DiscardingDisclosureLogger(), EvalVendorConfig::VLM_MODEL_ID);
    $extractor->extract(
        new PhysicianContext('copilot-eval-regen', 1),
        1,
        '999999',
        $docType,
        $regenMediaTypeFor($filename),
        base64_encode($bytes),
        TraceContext::start('regen', new \DateTimeImmutable()),
        new \DateTimeImmutable(),
    );
};

$vlmRecorded = 0;
foreach ($cases as $case) {
    if ($case->kind === GoldenCaseKind::Extraction) {
        $docType = $case->inputs['doc_type'] ?? null;
        $filename = $case->inputs['filename'] ?? null;
        $bytes = $case->inputs['document_bytes'] ?? null;
        if (!is_string($docType) || !is_string($filename) || !is_string($bytes)) {
            throw new \RuntimeException(sprintf('regen: case "%s" has a malformed extraction input shape', $case->id));
        }
        $regenRecordVlm($docType, $filename, $bytes, $regenStringKeyed($case->inputs['vlm_wire'] ?? null));
        $vlmRecorded++;
    }

    if ($case->kind === GoldenCaseKind::Turn) {
        $derivedSetup = $case->inputs['derived_setup'] ?? null;
        if (is_array($derivedSetup)) {
            $docType = $derivedSetup['doc_type'] ?? null;
            $filename = $derivedSetup['filename'] ?? null;
            $suffix = $derivedSetup['document_bytes_suffix'] ?? null;
            if (!is_string($docType) || !is_string($filename) || !is_string($suffix)) {
                throw new \RuntimeException(sprintf('regen: case "%s" has a malformed derived_setup input shape', $case->id));
            }
            $bytes = sprintf('%%PDF-1.7 goldenset %s %s%s', $case->id, $suffix, "\n");
            $regenRecordVlm($docType, $filename, $bytes, $regenStringKeyed($derivedSetup['vlm_wire'] ?? null));
            $vlmRecorded++;
        }
    }
}
echo sprintf("  vlm fixtures recorded: %d\n", $vlmRecorded);

// ---------------------------------------------------------------------
// 4. Record embed/rerank fixtures: every retrieval-kind case, plus every
//    turn-kind case whose question asks for evidence and is not a snapshot
//    turn (zero-RAG-on-snapshot means those never call the worker at all).
// ---------------------------------------------------------------------

$regenRecordRetrieval = static function (
    string $question,
    int $topK,
    array $aimChunkIds,
    array $degrade,
) use (
    &$embedFixtures,
    &$rerankFixtures,
    $chunkVectorsById,
    $bodyToChunkId,
    $dimensions,
    $regenStringKeyed,
): void {
    // Narrow the untrusted aim list once, at the boundary (both inner
    // closures capture the narrowed list, never the raw parameter).
    $aimIds = [];
    foreach ($aimChunkIds as $aimChunkId) {
        if (!is_string($aimChunkId)) {
            throw new \RuntimeException('regen: fixture_aim_chunk_ids must be strings');
        }
        $aimIds[] = $aimChunkId;
    }

    if (in_array('embed', $degrade, true)) {
        $embedTransport = static function (array $requestBody): array {
            throw new \RuntimeException('regen: a degraded embed seam must never actually be called');
        };
    } else {
        $embedTransport = static function (array $requestBody) use (&$embedFixtures, $chunkVectorsById, $dimensions, $aimIds, $regenStringKeyed): array {
            $texts = $requestBody['texts'] ?? null;
            if (!is_array($texts) || count($texts) !== 1 || !is_string($texts[0])) {
                throw new \RuntimeException('regen: query embed request must carry exactly one text');
            }
            $query = $texts[0];

            if ($aimIds === []) {
                // No aim: the query text stands in for its own "chunk text" —
                // still deterministic, and (by construction, high-dimensional
                // independent hash components) not meaningfully close to any
                // specific corpus vector.
                $vector = DeterministicVectors::vectorForText($query, $dimensions);
            } else {
                $vectors = [];
                foreach ($aimIds as $chunkId) {
                    if (!isset($chunkVectorsById[$chunkId])) {
                        throw new \RuntimeException(sprintf('regen: fixture_aim_chunk_id "%s" is not a real corpus chunk', $chunkId));
                    }
                    $vectors[] = $chunkVectorsById[$chunkId];
                }
                $vector = DeterministicVectors::centroid($vectors);
            }

            $body = ['embeddings' => ['float' => [$vector]]];
            $embedFixtures[InputKeyedReplayTransport::keyFor($regenStringKeyed($requestBody))] = [200, $body];

            return [200, $body];
        };
    }

    // Strict order-sensitive recording (PS-2): the candidate union order is
    // deterministic across rebuilds now that HybridRetriever's keyword leg
    // carries an explicit ORDER BY (relevance DESC, chunk_id ASC), so the
    // exact "documents" list — order included — is part of the recorded
    // request identity. A reordered candidate list at replay time is a
    // different request and must throw, never resolve to this fixture.
    if (in_array('rerank', $degrade, true)) {
        $rerankTransport = static function (array $requestBody): array {
            throw new \RuntimeException('regen: a degraded rerank seam must never actually be called');
        };
    } else {
        $rerankTransport = static function (array $requestBody) use (&$rerankFixtures, $bodyToChunkId, $aimIds, $regenStringKeyed): array {
            $documents = $requestBody['documents'] ?? null;
            if (!is_array($documents)) {
                throw new \RuntimeException('regen: rerank request is missing "documents"');
            }

            $results = [];
            foreach (array_values($documents) as $index => $document) {
                if (!is_string($document)) {
                    throw new \RuntimeException('regen: rerank request document is not a string');
                }
                $chunkId = $bodyToChunkId[$document] ?? null;
                $aimPosition = $chunkId !== null ? array_search($chunkId, $aimIds, true) : false;
                // Aimed chunks rank first, in authored order; the rest keep
                // union (request) order, both as strictly decreasing scores.
                $score = $aimPosition !== false ? (10000.0 - (float) $aimPosition) : (5000.0 - (float) $index);
                $results[] = ['index' => $index, 'relevance_score' => $score];
            }

            $body = ['results' => $results];
            $rerankFixtures[InputKeyedReplayTransport::keyFor($regenStringKeyed($requestBody))] = [200, $body];

            return [200, $body];
        };
    }

    $service = new EvidenceRetrievalService(
        new CohereEmbedClient($embedTransport, EvalVendorConfig::EMBED_MODEL_ID),
        new HybridRetriever(new CohereRerankClient($rerankTransport, EvalVendorConfig::RERANK_MODEL_ID)),
    );
    $service->search($question, $topK);
};

/**
 * @return list<string>
 */
$regenStringList = static function (mixed $value): array {
    if (!is_array($value)) {
        return [];
    }
    $result = [];
    foreach ($value as $item) {
        if (is_string($item)) {
            $result[] = $item;
        }
    }

    return $result;
};

$retrievalRecorded = 0;
foreach ($cases as $case) {
    $shouldRecord = false;
    $question = null;
    $topK = 5;
    $aim = [];
    $degrade = [];

    if ($case->kind === GoldenCaseKind::Retrieval) {
        $shouldRecord = true;
        $question = $case->inputs['question'] ?? null;
        $topKRaw = $case->inputs['top_k'] ?? 5;
        $topK = is_int($topKRaw) ? $topKRaw : 5;
        $aim = $regenStringList($case->inputs['fixture_aim_chunk_ids'] ?? []);
        $degrade = $regenStringList($case->inputs['degrade'] ?? []);
    } elseif ($case->kind === GoldenCaseKind::Turn) {
        $state = $case->inputs['state'] ?? [];
        $questionAsksForEvidence = is_array($state) && ($state['question_asks_for_evidence'] ?? false) === true;
        $isSnapshot = is_array($state) && ($state['is_snapshot_turn'] ?? false) === true;
        if ($questionAsksForEvidence && !$isSnapshot) {
            $shouldRecord = true;
            $question = $case->inputs['question'] ?? null;
            $topKRaw = $case->inputs['top_k'] ?? 5;
            $topK = is_int($topKRaw) ? $topKRaw : 5;
            $aim = $regenStringList($case->inputs['fixture_aim_chunk_ids'] ?? []);
            $degrade = $regenStringList($case->inputs['degrade'] ?? []);
        }
    }

    if ($shouldRecord) {
        if (!is_string($question)) {
            throw new \RuntimeException(sprintf('regen: case "%s" needs retrieval recording but has no question', $case->id));
        }
        $regenRecordRetrieval($question, $topK, $aim, $degrade);
        $retrievalRecorded++;
    }
}
echo sprintf("  retrieval cases recorded: %d\n", $retrievalRecorded);
echo sprintf("  embed fixtures:  %d\n", count($embedFixtures));
echo sprintf("  rerank fixtures: %d\n", count($rerankFixtures));

// ---------------------------------------------------------------------
// 5. Write vendor-fixtures.json
// ---------------------------------------------------------------------

$vendorFixturesPath = $moduleDir . '/eval/goldenset/vendor-fixtures.json';
$fixturesPayload = [
    '_meta' => ['generated_by' => 'bin/regenerate-eval-goldenset.php'],
    'embed' => $embedFixtures,
    'rerank' => $rerankFixtures,
    'vlm' => $vlmFixtures,
];
file_put_contents(
    $vendorFixturesPath,
    (string) json_encode($fixturesPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n",
);
echo sprintf("  vendor-fixtures.json written: %s\n", $vendorFixturesPath);

// ---------------------------------------------------------------------
// 6. Run the gate fresh against the just-recorded fixtures and write
//    baseline.json — refusing if the fresh run is not all-green.
// ---------------------------------------------------------------------

$gateReport = GoldenSetRunner::forCommittedGoldenSet()->run();
$caseFailures = $gateReport->caseFailures();

$hasFailures = false;
foreach ($caseFailures as $caseId => $messages) {
    if ($messages !== []) {
        $hasFailures = true;
        fwrite(STDERR, sprintf("RED case %s:\n", $caseId));
        foreach ($messages as $message) {
            fwrite(STDERR, sprintf("  - %s\n", $message));
        }
    }
}

if ($hasFailures) {
    fwrite(STDERR, "\nregen: the freshly-recorded fixtures did not run all-green.\n");
    fwrite(STDERR, "vendor-fixtures.json WAS written (for debugging); baseline.json was NOT — refusing to bake a failure into the baseline.\n");
    exit(1);
}

$result = $gateReport->result();
$categories = [];
foreach ($result->categories() as $category) {
    $score = $result->scoreFor($category);
    $categories[$category] = ['passed' => $score->passed, 'total' => $score->total];
}
ksort($categories);

$baseline = [
    '_meta' => ['generated_by' => 'bin/regenerate-eval-goldenset.php'],
    'categories' => $categories,
    'floors' => [
        'critical_subset' => 1.0,
        'factually_consistent' => 1.0,
        'no_phi_in_logs' => 1.0,
    ],
];

$baselinePath = $moduleDir . '/eval/goldenset/baseline.json';
file_put_contents(
    $baselinePath,
    (string) json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n",
);

echo sprintf("  baseline.json written: %s\n", $baselinePath);
foreach ($categories as $category => $score) {
    echo sprintf("    %-24s %d/%d\n", $category, $score['passed'], $score['total']);
}
echo "  status: OK — all-green\n";
