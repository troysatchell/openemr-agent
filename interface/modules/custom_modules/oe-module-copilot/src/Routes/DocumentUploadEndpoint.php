<?php

/**
 * The document-upload endpoint's wire contract (W2_ARCHITECTURE.md §2 step 1;
 * AUDIT S5).
 *
 * Thin shaping layer over the `DocumentIngestion` port — the same idiom as
 * `TurnEndpoint`: it parses raw route input into typed arguments and refuses
 * bad input BEFORE the port runs (cheap validation never touches the
 * document store or the VLM), then forwards the port's result verbatim under
 * the documented wire keys. This array IS the wire contract:
 *
 *   ['document_id' => string, 'extraction_status' => string]
 *
 * Validation enforces the §10 type/size allowlist at upload:
 *   - 'patient_uuid' must be a non-blank string.
 *   - 'doc_type' must be exactly 'lab_pdf' or 'intake_form' — a closed set;
 *     nothing outside it is accepted, however plausible.
 *   - 'file_path' must be a non-blank string whose extension (the substring
 *     after the last '.', lowercased) is one of pdf/png/jpg/jpeg. A path with
 *     no extension is refused, not defaulted.
 *   - 'file_size_bytes' must be a genuine int (no string coercion — `"123"`
 *     is refused even though it looks numeric), strictly positive, and no
 *     larger than 10 MiB (10485760 bytes; the cap itself is accepted).
 *
 * Content mode (Wave K.2, TRO-43 completion; browser-reachable): a second,
 * mutually exclusive input shape a browser can actually send — a static page
 * cannot supply a server-side path (browsers deliberately withhold it), so
 * this shape accepts the raw bytes instead.
 *   - 'filename' must be a non-blank string, extension-allowlisted exactly
 *     like 'file_path' (same pdf/png/jpg/jpeg rule).
 *   - 'file_content_b64' must be a non-blank string that decodes under
 *     strict base64 (`base64_decode($value, true)`; a non-base64 character
 *     refuses rather than silently dropping it). The DECODED byte length is
 *     capped at the same 10 MiB this class enforces on 'file_size_bytes'.
 *
 * Exactly one mode may be supplied: 'file_path' and 'file_content_b64'
 * together is refused as ambiguous; neither is refused (the existing
 * missing-'file_path' error covers that case, since it is this class's
 * fallback mode). Content mode materializes the decoded bytes to a
 * server-owned temporary file (extension taken from 'filename', never
 * trusted from request metadata otherwise) and hands it to the SAME
 * `DocumentIngestion::attachAndExtract()` call the path mode uses — one
 * ingestion path, two ways to reach it — then unlinks the temporary file
 * whether ingestion succeeded or threw.
 *
 * Any violation throws \DomainException before `DocumentIngestion::
 * attachAndExtract()` is ever called — so a refused upload never reaches the
 * document store, never logs a disclosure, and never invokes the VLM.
 *
 * This route registers no ACL/authorization on its own: that is the module's
 * GuardedRouteRegistrar's job at the RestApiCreateEvent wiring layer (S5).
 * This class is a pure shaping layer, deliberately without route or ACL
 * knowledge — identical posture to TurnEndpoint.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Routes;

use OpenEMR\Modules\Copilot\Chart\PhysicianContext;
use OpenEMR\Modules\Copilot\Ingestion\DocumentIngestion;

final readonly class DocumentUploadEndpoint
{
    /**
     * Closed set of accepted doc_type values (W2_ARCHITECTURE.md §3 —
     * `lab_pdf` and `intake_form` are the only extraction schemas defined).
     *
     * @var list<string>
     */
    private const ALLOWED_DOC_TYPES = ['lab_pdf', 'intake_form'];

    /**
     * Type/size allowlist at upload (§10): only these extensions (matched
     * case-insensitively) may be attached.
     *
     * @var list<string>
     */
    private const ALLOWED_EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg'];

    /** 10 MiB — the cap itself is accepted (boundary inclusive). */
    private const MAX_FILE_SIZE_BYTES = 10 * 1024 * 1024;

    public function __construct(private DocumentIngestion $ingestion)
    {
    }

    /**
     * @param array<string, mixed> $input Raw route input: 'patient_uuid'
     *        (string), 'doc_type' (string, one of self::ALLOWED_DOC_TYPES),
     *        EITHER 'file_path' (string, extension-allowlisted) +
     *        'file_size_bytes' (int, 1..10485760 inclusive) OR 'filename'
     *        (string, extension-allowlisted) + 'file_content_b64' (string,
     *        strict base64, decoded length 1..10485760 inclusive) — exactly
     *        one of the two modes.
     *
     * @return array{document_id: string, extraction_status: string}
     *
     * @throws \DomainException when any input field is missing or fails
     *         validation, or when both/neither upload mode is supplied —
     *         refused before the ingestion port (and therefore the document
     *         store and the VLM) ever runs.
     */
    public function handle(PhysicianContext $physician, array $input): array
    {
        $patientUuid = $this->requireNonBlankString($input, 'patient_uuid');
        $docType = $this->requireDocType($input);

        $hasFilePathMode = array_key_exists('file_path', $input);
        $hasContentMode = array_key_exists('file_content_b64', $input);

        if ($hasFilePathMode && $hasContentMode) {
            throw new \DomainException(
                'Provide exactly one upload mode: "file_path" (server-side path) OR '
                    . '"filename" + "file_content_b64" (browser upload) — not both'
            );
        }

        if ($hasContentMode) {
            return $this->handleContentMode($physician, $input, $patientUuid, $docType);
        }

        $filePath = $this->requireAllowedFilePath($input);
        $this->requireValidFileSize($input);

        return $this->ingestion->attachAndExtract($physician, $patientUuid, $filePath, $docType);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{document_id: string, extraction_status: string}
     */
    private function handleContentMode(
        PhysicianContext $physician,
        array $input,
        string $patientUuid,
        string $docType,
    ): array {
        $filename = $this->requireNonBlankString($input, 'filename');
        $extension = $this->requireAllowedExtension($filename, 'filename');
        $decoded = $this->requireDecodedContent($input);

        $tempPath = sys_get_temp_dir() . '/copilot-upload-' . bin2hex(random_bytes(16)) . '.' . $extension;
        if (file_put_contents($tempPath, $decoded) === false) {
            throw new \RuntimeException('Failed to materialize uploaded content to a temporary file');
        }

        try {
            return $this->ingestion->attachAndExtract($physician, $patientUuid, $tempPath, $docType);
        } finally {
            @unlink($tempPath);
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private function requireDecodedContent(array $input): string
    {
        $encoded = $input['file_content_b64'] ?? null;
        if (!is_string($encoded) || trim($encoded) === '') {
            throw new \DomainException('"file_content_b64" must be a non-blank string');
        }

        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            throw new \DomainException('"file_content_b64" must be valid base64');
        }

        $size = strlen($decoded);
        if ($size <= 0 || $size > self::MAX_FILE_SIZE_BYTES) {
            throw new \DomainException(
                sprintf('decoded "file_content_b64" must be > 0 and <= %d bytes (10 MiB)', self::MAX_FILE_SIZE_BYTES)
            );
        }

        return $decoded;
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
     * @param array<string, mixed> $input
     */
    private function requireDocType(array $input): string
    {
        $docType = $this->requireNonBlankString($input, 'doc_type');
        if (!in_array($docType, self::ALLOWED_DOC_TYPES, true)) {
            throw new \DomainException(
                sprintf(
                    '"doc_type" must be one of: %s',
                    implode(', ', self::ALLOWED_DOC_TYPES),
                )
            );
        }

        return $docType;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function requireAllowedFilePath(array $input): string
    {
        $filePath = $this->requireNonBlankString($input, 'file_path');
        $this->requireAllowedExtension($filePath, 'file_path');

        return $filePath;
    }

    /**
     * Shared extension allowlist check for both upload modes: 'file_path'
     * (the full server path) and 'filename' (content mode) both name their
     * value's trailing extension the same way, so the check — and its error
     * text, parameterized only by which field name is being checked — is
     * shared rather than duplicated.
     *
     * @return string the lowercased extension, for the caller that needs it
     *         (content mode's temp-file suffix)
     */
    private function requireAllowedExtension(string $value, string $fieldName): string
    {
        $lastDot = strrpos($value, '.');
        if ($lastDot === false || $lastDot === strlen($value) - 1) {
            throw new \DomainException(sprintf('"%s" must have a file extension', $fieldName));
        }

        $extension = strtolower(substr($value, $lastDot + 1));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new \DomainException(
                sprintf(
                    '"%s" extension must be one of: %s',
                    $fieldName,
                    implode(', ', self::ALLOWED_EXTENSIONS),
                )
            );
        }

        return $extension;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function requireValidFileSize(array $input): void
    {
        $size = $input['file_size_bytes'] ?? null;
        if (!is_int($size)) {
            throw new \DomainException('"file_size_bytes" must be an integer (no string coercion)');
        }

        if ($size <= 0 || $size > self::MAX_FILE_SIZE_BYTES) {
            throw new \DomainException(
                sprintf('"file_size_bytes" must be > 0 and <= %d bytes (10 MiB)', self::MAX_FILE_SIZE_BYTES)
            );
        }
    }
}
