<?php

/**
 * CLI entry for the committed corpus indexer (TRO-26; W2_ARCHITECTURE.md §5
 * "Hybrid RAG + rerank", §11 "reproducible from the repo alone"; PS-12
 * degradation pair) — thin shell around the tested `CorpusIndexer`; all
 * logic lives (and is tested) there.
 *
 * Usage (in the openemr container):
 *   COHERE_API_KEY=... php interface/modules/custom_modules/oe-module-copilot/bin/index-corpus.php
 *
 * Refuses to run without COHERE_API_KEY — there is no service-account or
 * default-key fallback for this vendor boundary. This is also the *only*
 * place the corpus index is ever built or replaced: the index is rebuilt by
 * this committed command alone, never hand-edited (§5).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\Copilot\Rag\CohereEmbedClient;
use OpenEMR\Modules\Copilot\Rag\CorpusIndexer;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../../../../../vendor/autoload.php';

// The module's PSR-4 mapping lives in the root composer.json's autoload-dev,
// which a production build (composer install --no-dev) drops — so on the
// deployed stack Composer's autoloader alone cannot find the module classes.
// The web entry points register the namespace at runtime via
// ModulesClassLoader (see openemr.bootstrap.php); this CLI does the same,
// self-contained, so the index build runs identically in dev and on prod.
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

$apiKey = getenv('COHERE_API_KEY');
if ($apiKey === false || trim($apiKey) === '') {
    fwrite(STDERR, "COHERE_API_KEY is not set — refusing to run without a Cohere API key.\n");
    fwrite(STDERR, "This vendor boundary has no service-account or default-key fallback.\n");
    exit(1);
}

// Minimal, explicit DB-connection config resolution — NOT interface/globals.php
// (CLAUDE.md danger zone): no session, no auth, no $ignoreAuth. QueryUtils'
// ADODB layer resolves its connection from OE_SITE_DIR alone; without this
// block the indexer's first write dies before reaching the vendor at all
// (gap found 2026-07-14 — the same bootstrap the eval regeneration command
// carries; mirrored here rather than shared because bin scripts are
// deliberately self-contained).
$siteDir = getenv('OE_SITE_DIR');
if ($siteDir === false || trim($siteDir) === '') {
    $siteDir = dirname(__DIR__, 5) . '/sites/default';
}
OEGlobalsBag::getInstance()->set('OE_SITE_DIR', $siteDir);
if (is_file($siteDir . '/config.php')) {
    require_once $siteDir . '/config.php';
}

// Warm up the ADODB connection BEFORE any stdout output: legacy sql.inc.php
// starts a PHP session on its first require, and PHP refuses session_start()
// once output has been sent — even under the CLI SAPI.
\OpenEMR\Common\Database\QueryUtils::fetchSingleValue('SELECT 1 AS one', 'one', []);

$modelId = getenv('COHERE_EMBED_MODEL');
if ($modelId === false || trim($modelId) === '') {
    $modelId = 'embed-english-v3.0';
}

$corpusDir = getenv('COPILOT_CORPUS_DIR');
if ($corpusDir === false || trim($corpusDir) === '') {
    $corpusDir = __DIR__ . '/../corpus';
}

$httpClient = new \GuzzleHttp\Client([
    'base_uri' => 'https://api.cohere.com',
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
    $response = $httpClient->post('/v2/embed', [
        'headers' => [
            'Authorization' => 'bearer ' . $apiKey,
            'content-type' => 'application/json',
        ],
        'json' => $requestBody,
    ]);

    $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new \RuntimeException('The Cohere embed API response body did not decode to a JSON object');
    }

    // Normalise to string keys so the transport contract's shape holds.
    $body = [];
    foreach ($decoded as $key => $value) {
        $body[(string) $key] = $value;
    }

    return [$response->getStatusCode(), $body];
};

$embedder = new CohereEmbedClient($transport, $modelId);
$indexer = new CorpusIndexer($embedder);

$report = $indexer->rebuild($corpusDir);

echo "Clinical Co-Pilot — corpus index rebuild\n";
echo sprintf("  corpus dir:          %s\n", $corpusDir);
echo sprintf("  embed model:         %s\n", $modelId);
echo sprintf("  chunks indexed:      %d\n", $report->chunksIndexed);
echo sprintf("  embeddings stored:   %d\n", $report->embeddingsStored);

if ($report->embeddingsSkipped) {
    fwrite(STDERR, "STALE INDEX (operator alarm, PS-12): the Cohere embed endpoint was unreachable during this build.\n");
    fwrite(STDERR, "The keyword (FULLTEXT) leg indexed in full; the dense (VECTOR) leg is EMPTY for this run.\n");
    fwrite(STDERR, "Retrieval degrades to keyword-only until this command is re-run successfully.\n");
    exit(1);
}

echo "  status:              OK\n";
