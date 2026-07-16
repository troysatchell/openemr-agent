<?php

/**
 * The source-resolver endpoint's wire contract (TRO-44 MVP click-to-source
 * slice; W2_ARCHITECTURE.md §4; UC6; MVP row 5 "source-grounded UI").
 *
 * Thin shaping + resolution layer behind `POST /api/copilot/source`: the same
 * idiom as `TurnEndpoint`/`DocumentUploadEndpoint` — parse raw route input
 * into typed arguments and refuse bad input before any lookup runs — plus the
 * resolution itself, because a citation token has no separate "port" the way
 * ingestion does. Every lookup re-grounds against LIVE data on every call
 * (never a cached turn), matching the citation contract's own promise: a
 * token is a pointer, not a cached snapshot.
 *
 * The token grammar is the one `ReferenceIndex::tokenFor()` mints:
 * `sourceType ':' sourceId ['#' fieldOrChunkId]`. Resolution branches on
 * `sourceType` into five shapes, one per source class the citation contract
 * (§4) already recognizes:
 *
 *   - `guideline`   -> the REAL corpus index (`CorpusIndexSchema::CHUNK_TABLE`):
 *                      `{type: 'guideline', source_id, chunk_id, heading, snippet}`.
 *   - `lab_pdf` /
 *     `intake_form` -> the REAL persisted extraction lineage for an attached
 *                      document, REFUSED unless the document belongs to the
 *                      named patient (cross-patient leak is the S-class
 *                      failure this guards):
 *                      `{type: 'document', document_id, filename, page, quote, field,
 *                      bbox, document_base64, document_mime}`. `bbox` is the
 *                      stored normalized [x,y,w,h] (TRO-44) — null when no
 *                      box was captured, never invented; `document_base64` /
 *                      `document_mime` are the actual source bytes so the
 *                      panel viewer can render the cited page and draw the
 *                      overlay.
 *   - `derived_observation` -> the procedure_result id is resolved through
 *                      the same lineage to its source document, then behaves
 *                      exactly like the document case (same patient check;
 *                      PS-6: a derived observation is a pointer, never
 *                      evidence, so it is never rendered as its own kind of
 *                      preview — it resolves to the document that grounds it).
 *   - `detector`    -> a typed, PHI-minimal label derived from the SAME
 *                      static detector/finding vocabulary the golden set uses
 *                      (`Eval\CriticalSubsetLabels` + the real deterministic
 *                      detectors) — no DB read at all for this branch:
 *                      `{type: 'detector', finding_id, label}`.
 *   - `procedure_result` / `lists` / `Observation` / `MedicationRequest` /
 *                      `AllergyIntolerance` (the FHIR types the live chart
 *                      mint labels its refs with) -> a PHI-minimal
 *                      chart-reference pointer, no value fetched:
 *                      `{type: 'chart', source_type, source_id}`. A
 *                      lineage-backed lab never reaches this arm — the mint
 *                      rewrites it to `derived_observation` so it grounds in
 *                      its source document.
 *
 * Any other sourceType, a malformed token (no ':'), a required-but-missing
 * '#' fragment, or an unresolvable id all throw `\DomainException` with a
 * generic message — never a guessed preview, never route/internal detail
 * (R11). Where a wrong answer could leak whether a record exists for another
 * patient (the document/derived-observation branches), the "not found" and
 * "found but not yours" cases throw the IDENTICAL message so there is no
 * enumeration side channel.
 *
 * This route registers no ACL/authorization on its own: that is the module's
 * GuardedRouteRegistrar's job at the RestApiCreateEvent wiring layer (S5).
 * This class is a pure shaping + resolution layer, deliberately without
 * route or ACL knowledge — identical posture to TurnEndpoint/
 * DocumentUploadEndpoint. `$physician` is accepted (and unused in the body)
 * purely for calling-convention parity with every other endpoint in this
 * module (`handle(PhysicianContext, array): array`) — every read this class
 * performs is either non-PHI (guideline, detector) or explicitly re-checked
 * against the caller-supplied patient uuid (document, derived_observation),
 * so no further use of the principal is needed once the route's ACL guard
 * has already run.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Routes;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Modules\Copilot\Chart\PhysicianContext;
use OpenEMR\Modules\Copilot\Detectors\CriticalSubsetDetectors;
use OpenEMR\Modules\Copilot\Eval\CriticalSubsetLabels;
use OpenEMR\Modules\Copilot\Persistence\ExtractionLineageSchema;
use OpenEMR\Modules\Copilot\Persistence\IntakeCandidatesSchema;
use OpenEMR\Modules\Copilot\Rag\CorpusIndexSchema;

final class SourceResolverEndpoint
{
    /**
     * The `quote_or_value`-equivalent bound for a guideline snippet, matching
     * `RetrievedChunk::SNIPPET_LENGTH` — a preview is never the whole chunk
     * body (minimum-necessary evidence applies to previews too).
     */
    private const GUIDELINE_SNIPPET_LENGTH = 300;

    /**
     * Default MIME for a document-preview render when the stored
     * `documents.mimetype` is blank — every source document this module
     * ingests is a PDF (`DocumentIngestionService`'s accepted content type).
     */
    private const DEFAULT_DOCUMENT_MIME = 'application/pdf';

    private function __construct()
    {
        // Static-only composition root; see forLiveResolution().
    }

    /**
     * The one live composition: every lookup below runs against the real
     * corpus index, the real extraction lineage / intake candidates tables,
     * and the real `documents`/`procedure_result` rows via QueryUtils —
     * never raw legacy bootstrap, never a cached snapshot.
     */
    public static function forLiveResolution(): self
    {
        return new self();
    }

    /**
     * @param array<string, mixed> $input Raw route input: 'token' (string,
     *        `sourceType:sourceId[#fieldOrChunkId]`), 'patient_uuid' (string).
     *
     * @return array{type: 'guideline', source_id: string, chunk_id: string, heading: string, snippet: string}
     *     |array{type: 'document', document_id: int, filename: string, page: ?string, quote: string, field: string, bbox: ?list<float>, document_base64: string, document_mime: string}
     *     |array{type: 'detector', finding_id: string, label: string}
     *     |array{type: 'chart', source_type: string, source_id: string}
     *
     * @throws \DomainException when the input is malformed, the token is
     *         malformed or unresolvable, or a resolved document does not
     *         belong to the named patient — always before any value is
     *         returned; never a guessed preview.
     */
    public function handle(PhysicianContext $physician, array $input): array
    {
        $token = $this->requireNonBlankString($input, 'token');
        $patientUuid = $this->requireNonBlankString($input, 'patient_uuid');

        $parsed = $this->parseToken($token);

        return match ($parsed['sourceType']) {
            'guideline' => $this->resolveGuideline($parsed['sourceId'], $parsed['fieldOrChunkId']),
            'lab_pdf' => $this->resolveDocumentExtraction('lab_pdf', $parsed['sourceId'], $parsed['fieldOrChunkId'], $patientUuid),
            'intake_form' => $this->resolveDocumentExtraction('intake_form', $parsed['sourceId'], $parsed['fieldOrChunkId'], $patientUuid),
            'derived_observation' => $this->resolveDerivedObservation($parsed['sourceId'], $patientUuid),
            'detector' => $this->resolveDetector($parsed['sourceId']),
            // The FHIR resource types the live chart mint labels its refs
            // with (FhirChartMapper) resolve like any other chart pointer —
            // a typed, PHI-minimal echo, never a data read. Lineage-backed
            // labs never reach this arm: DerivedLabSourceRewriter rewrites
            // them to `derived_observation` at the mint.
            'procedure_result', 'lists', 'Observation', 'MedicationRequest', 'AllergyIntolerance'
                => $this->resolveChart($parsed['sourceType'], $parsed['sourceId']),
            default => throw new \DomainException('Unsupported source token type'),
        };
    }

    /**
     * @param array<string, mixed> $input
     */
    private function requireNonBlankString(array $input, string $key): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new \DomainException(sprintf('"%s" must be a non-blank string', $key));
        }

        return $value;
    }

    /**
     * Parses the token grammar `sourceType:sourceId[#fieldOrChunkId]` —
     * the exact inverse of `ReferenceIndex::tokenFor()`. The FIRST ':' and
     * the FIRST '#' are the split points (symmetric with tokenFor(), which
     * only ever inserts one of each); a blank sourceType, sourceId, or
     * present-but-blank fragment all fail loud.
     *
     * @return array{sourceType: string, sourceId: string, fieldOrChunkId: ?string}
     */
    private function parseToken(string $token): array
    {
        $hashPos = strpos($token, '#');
        $base = $hashPos === false ? $token : substr($token, 0, $hashPos);
        $fragment = $hashPos === false ? null : substr($token, $hashPos + 1);

        $colonPos = strpos($base, ':');
        if ($colonPos === false) {
            throw new \DomainException('Malformed source token: expected "sourceType:sourceId"');
        }

        $sourceType = substr($base, 0, $colonPos);
        $sourceId = substr($base, $colonPos + 1);

        if (trim($sourceType) === '' || trim($sourceId) === '') {
            throw new \DomainException('Malformed source token: sourceType and sourceId must be non-blank');
        }
        if ($fragment !== null && trim($fragment) === '') {
            throw new \DomainException('Malformed source token: the "#" fragment, when present, must be non-blank');
        }

        return ['sourceType' => $sourceType, 'sourceId' => $sourceId, 'fieldOrChunkId' => $fragment];
    }

    /**
     * @return array{type: 'guideline', source_id: string, chunk_id: string, heading: string, snippet: string}
     */
    private function resolveGuideline(string $sourceId, ?string $chunkId): array
    {
        if ($chunkId === null) {
            throw new \DomainException('A guideline source token requires a "#chunk_id" fragment');
        }

        $rows = QueryUtils::fetchRecords(
            'SELECT source_id, heading, body FROM ' . CorpusIndexSchema::CHUNK_TABLE . ' WHERE chunk_id = ?',
            [$chunkId],
        );
        $row = $rows[0] ?? null;
        if ($row === null) {
            throw new \DomainException('Unknown guideline chunk');
        }

        $rowSourceId = $row['source_id'] ?? null;
        $heading = $row['heading'] ?? null;
        $body = $row['body'] ?? null;
        if (!is_string($rowSourceId) || !is_string($heading) || !is_string($body)) {
            throw new \RuntimeException('Corpus chunk query returned a non-string column value');
        }

        if ($rowSourceId !== $sourceId) {
            // Same "no guessed preview" posture as an outright miss.
            throw new \DomainException('Unknown guideline chunk');
        }

        return [
            'type' => 'guideline',
            'source_id' => $sourceId,
            'chunk_id' => $chunkId,
            'heading' => $heading,
            'snippet' => mb_substr($body, 0, self::GUIDELINE_SNIPPET_LENGTH),
        ];
    }

    /**
     * @return array{type: 'document', document_id: int, filename: string, page: ?string, quote: string, field: string, bbox: ?list<float>, document_base64: string, document_mime: string}
     */
    private function resolveDocumentExtraction(string $docType, string $sourceId, ?string $fieldPath, string $patientUuid): array
    {
        if ($fieldPath === null) {
            throw new \DomainException(sprintf('A "%s" source token requires a "#field" fragment', $docType));
        }
        if (!ctype_digit($sourceId)) {
            // Same generic message a genuinely unknown/foreign document gets
            // below — a malformed id must not be distinguishable from one
            // that simply isn't this patient's (no enumeration side channel).
            throw new \DomainException('Unknown source document');
        }
        $documentId = (int) $sourceId;
        $patientPid = $this->resolvePid($patientUuid);

        $document = $this->requireOwnedDocument($documentId, $patientPid);

        $citation = $docType === 'lab_pdf'
            ? $this->fetchLabFieldCitation($documentId, $fieldPath)
            : $this->fetchIntakeFieldCitation($documentId, $fieldPath);
        if ($citation === null) {
            throw new \DomainException('Unknown extracted field for this document');
        }

        return [
            'type' => 'document',
            'document_id' => $documentId,
            'filename' => $document['filename'],
            'page' => $citation['page'],
            'quote' => $citation['quote'],
            'field' => $fieldPath,
            'bbox' => $citation['bbox'],
            'document_base64' => $this->readDocumentBase64($documentId),
            'document_mime' => $document['mimetype'] ?? self::DEFAULT_DOCUMENT_MIME,
        ];
    }

    /**
     * @return array{type: 'document', document_id: int, filename: string, page: ?string, quote: string, field: string, bbox: ?list<float>, document_base64: string, document_mime: string}
     */
    private function resolveDerivedObservation(string $sourceId, string $patientUuid): array
    {
        if (!ctype_digit($sourceId)) {
            throw new \DomainException('Unknown derived observation');
        }
        $resultId = (int) $sourceId;

        $rows = QueryUtils::fetchRecords(
            'SELECT mcel.document_id AS document_id, mcel.field_path AS field_path, mcel.page AS page,'
                . ' prr.result AS quote, mcel.bbox AS bbox'
                . ' FROM ' . ExtractionLineageSchema::LINEAGE_TABLE . ' mcel'
                . ' JOIN procedure_result prr ON mcel.procedure_result_id = prr.procedure_result_id'
                . ' WHERE mcel.procedure_result_id = ?',
            [$resultId],
        );
        $row = $rows[0] ?? null;
        if ($row === null) {
            throw new \DomainException('Unknown derived observation');
        }

        $documentId = $row['document_id'] ?? null;
        $fieldPath = $row['field_path'] ?? null;
        $page = $row['page'] ?? null;
        $quote = $row['quote'] ?? null;
        $bboxCsv = $row['bbox'] ?? null;
        if (!is_int($documentId) && !(is_string($documentId) && ctype_digit($documentId))) {
            throw new \RuntimeException('Extraction lineage query returned a non-numeric document id');
        }
        if (!is_string($fieldPath)) {
            throw new \RuntimeException('Extraction lineage query returned a non-string field path');
        }
        if ($page !== null && !is_string($page)) {
            throw new \RuntimeException('Extraction lineage query returned a non-string page value');
        }
        if (!is_string($quote)) {
            throw new \RuntimeException('Extraction lineage query returned a non-string quote value');
        }
        if ($bboxCsv !== null && !is_string($bboxCsv)) {
            throw new \RuntimeException('Extraction lineage query returned a non-string bbox value');
        }

        $patientPid = $this->resolvePid($patientUuid);
        $resolvedDocumentId = (int) $documentId;
        $document = $this->requireOwnedDocument($resolvedDocumentId, $patientPid);

        return [
            'type' => 'document',
            'document_id' => $resolvedDocumentId,
            'filename' => $document['filename'],
            'page' => $page,
            'quote' => $quote,
            'field' => $fieldPath,
            'bbox' => $this->parseBboxCsv($bboxCsv),
            'document_base64' => $this->readDocumentBase64($resolvedDocumentId),
            'document_mime' => $document['mimetype'] ?? self::DEFAULT_DOCUMENT_MIME,
        ];
    }

    /**
     * Static resolution only — the detector/finding vocabulary is pure
     * runtime logic (`CriticalSubsetLabels` chart scenarios + the real
     * deterministic detectors), never a DB read. The label is the exact
     * `CriticalFinding::summary` text the same finding id produces
     * everywhere else in this module (golden set, snapshot, turn), so a
     * clinician reading a detector citation preview sees the identical
     * wording the finding itself was raised with — never a paraphrase.
     *
     * @return array{type: 'detector', finding_id: string, label: string}
     */
    private function resolveDetector(string $findingId): array
    {
        $scenarios = CriticalSubsetLabels::chartScenarios();
        $matchers = CriticalSubsetLabels::labelMatchers();
        $chart = $scenarios[$findingId] ?? null;
        $matcher = $matchers[$findingId] ?? null;
        if ($chart === null || $matcher === null) {
            throw new \DomainException('Unknown detector finding id');
        }

        $reports = CriticalSubsetDetectors::withDraftTables()->detectAll($chart, CriticalSubsetLabels::today());
        foreach ($reports as $report) {
            foreach ($report->findings as $finding) {
                if ($matcher($finding)) {
                    return [
                        'type' => 'detector',
                        'finding_id' => $findingId,
                        'label' => $finding->summary,
                    ];
                }
            }
        }

        throw new \DomainException('Unknown detector finding id');
    }

    /**
     * @return array{type: 'chart', source_type: string, source_id: string}
     */
    private function resolveChart(string $sourceType, string $sourceId): array
    {
        return [
            'type' => 'chart',
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ];
    }

    /**
     * Resolves the caller-supplied patient uuid to the trusted `pid` (D7),
     * mirroring the identical lookup already established in
     * `Bootstrap::buildChartSnapshotProvider()` and
     * `DocumentIngestionService::resolvePid()`.
     */
    private function resolvePid(string $patientUuid): int
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
     * Verifies the document belongs to `$patientPid` (honoring the `deleted`
     * filter, D10) and returns its display filename plus the stored
     * `mimetype` the document-preview render needs (TRO-44; blank/absent
     * normalized to null — D1 — so callers apply one honest default). The
     * document's bytes are resolved separately, by id, through
     * {@see readDocumentBase64()}. "Does not exist" and "exists but belongs
     * to a different patient" throw the IDENTICAL message — the S-class
     * failure this guards against is a cross-patient leak, so the two cases
     * must not be distinguishable from the outside.
     *
     * @return array{filename: string, mimetype: ?string}
     */
    private function requireOwnedDocument(int $documentId, int $patientPid): array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT foreign_id, name, url, mimetype FROM documents WHERE id = ? AND deleted = 0',
            [$documentId],
        );
        $row = $rows[0] ?? null;

        $foreignId = null;
        $name = null;
        $url = null;
        $mimetype = null;
        if ($row !== null) {
            $foreignId = $row['foreign_id'] ?? null;
            $name = $row['name'] ?? null;
            $url = $row['url'] ?? null;
            $mimetype = $row['mimetype'] ?? null;
        }

        $ownedByPatient = (is_int($foreignId) && $foreignId === $patientPid)
            || (is_string($foreignId) && ctype_digit($foreignId) && (int) $foreignId === $patientPid);
        if (!$ownedByPatient) {
            throw new \DomainException('Unknown source document');
        }

        $filename = (is_string($name) && trim($name) !== '')
            ? $name
            : ((is_string($url) && trim($url) !== '') ? basename($url) : null);
        if ($filename === null || trim($filename) === '') {
            throw new \DomainException('Unknown source document');
        }
        if ($mimetype !== null && !is_string($mimetype)) {
            throw new \RuntimeException('Documents query returned a non-string mimetype value');
        }

        return [
            'filename' => $filename,
            'mimetype' => (is_string($mimetype) && trim($mimetype) !== '') ? $mimetype : null,
        ];
    }

    /**
     * Resolves the document's stored bytes, base64-encoded for the wire.
     * Reads through core's own `\Document::get_data()` (the exact counterpart
     * of the `\Document::createDocument()` write `PatientDocumentAttacher`
     * already uses) rather than reading the `file://` path directly — that
     * is deliberate: whether the on-disk bytes are plaintext or
     * `drive_encryption`-encrypted is a deployment setting core's own crypto
     * layer already knows how to reverse, and reimplementing that decision
     * here would silently diverge the moment the setting differs from this
     * dev environment's default. Fails loud (\DomainException) on any
     * read/decrypt failure — an unreadable source is never silently swapped
     * for an empty preview.
     */
    private function readDocumentBase64(int $documentId): string
    {
        try {
            $bytes = (new \Document($documentId))->get_data();
        } catch (\Throwable $e) {
            throw new \DomainException('Source document bytes could not be read', 0, $e);
        }

        if (!is_string($bytes) || $bytes === '') {
            throw new \DomainException('Source document bytes could not be read');
        }

        return base64_encode($bytes);
    }

    /**
     * Parses a stored lineage `bbox` CSV (the canonical form
     * `BoundingBox::toCsv()` writes) back into its four components. Lenient
     * by construction (R-W3): a value that does not parse renders as "no
     * box" rather than surfacing an internal error, even though in practice
     * this column is only ever written by this module's own writer.
     *
     * @return list<float>|null
     */
    private function parseBboxCsv(?string $csv): ?array
    {
        if ($csv === null || trim($csv) === '') {
            return null;
        }

        $parts = explode(',', $csv);
        if (count($parts) !== 4) {
            return null;
        }

        $components = [];
        foreach ($parts as $part) {
            if (!is_numeric($part)) {
                return null;
            }
            $components[] = (float) $part;
        }

        return $components;
    }

    /**
     * lab_pdf lineage carries a stored bbox (TRO-44, this module's own
     * writer); the query pulls it alongside page/quote so a single row read
     * resolves the whole citation.
     *
     * @return array{page: ?string, quote: string, bbox: ?list<float>}|null
     */
    private function fetchLabFieldCitation(int $documentId, string $fieldPath): ?array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT mcel.page AS page, prr.result AS quote, mcel.bbox AS bbox'
                . ' FROM ' . ExtractionLineageSchema::LINEAGE_TABLE . ' mcel'
                . ' JOIN procedure_result prr ON mcel.procedure_result_id = prr.procedure_result_id'
                . ' WHERE mcel.document_id = ? AND mcel.field_path = ?'
                . ' ORDER BY mcel.id DESC LIMIT 1',
            [$documentId, $fieldPath],
        );

        return $this->narrowCitationRow($rows[0] ?? null);
    }

    /**
     * intake_form candidates have no bbox column (out of this ticket's
     * scope — TRO-44 covers the lab_pdf round trip only): `bbox` is always
     * null here, never invented.
     *
     * @return array{page: ?string, quote: string, bbox: ?list<float>}|null
     */
    private function fetchIntakeFieldCitation(int $documentId, string $fieldPath): ?array
    {
        $rows = QueryUtils::fetchRecords(
            'SELECT page, value_text AS quote FROM ' . IntakeCandidatesSchema::CANDIDATES_TABLE
                . ' WHERE document_id = ? AND field_path = ? AND superseded_at IS NULL'
                . ' ORDER BY id DESC LIMIT 1',
            [$documentId, $fieldPath],
        );

        return $this->narrowCitationRow($rows[0] ?? null);
    }

    /**
     * The QueryUtils boundary yields untyped rows; this helper IS the
     * narrowing point, so it accepts the wide shape and validates every
     * field itself. `bbox` is optional in the row shape (the intake-candidate
     * query never selects it) and always narrows through
     * {@see parseBboxCsv()} when present.
     *
     * @param array<array-key, mixed>|null $row
     *
     * @return array{page: ?string, quote: string, bbox: ?list<float>}|null
     */
    private function narrowCitationRow(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        $page = $row['page'] ?? null;
        $quote = $row['quote'] ?? null;
        $bboxCsv = $row['bbox'] ?? null;
        if ($page !== null && !is_string($page)) {
            throw new \RuntimeException('Extraction citation query returned a non-string page value');
        }
        if (!is_string($quote)) {
            throw new \RuntimeException('Extraction citation query returned a non-string quote value');
        }
        if ($bboxCsv !== null && !is_string($bboxCsv)) {
            throw new \RuntimeException('Extraction citation query returned a non-string bbox value');
        }

        return ['page' => $page, 'quote' => $quote, 'bbox' => $this->parseBboxCsv($bboxCsv)];
    }
}
