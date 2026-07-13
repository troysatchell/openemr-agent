<?php

/**
 * The lab_pdf extraction root — a source document's analytes (W2_ARCHITECTURE §3).
 *
 * Ties a list of LabAnalyteExtraction rows to a non-blank source document id.
 * An empty analyte list is allowed (a document that yielded nothing grounded
 * is not an error); every element must be a LabAnalyteExtraction — the schema
 * boundary contains whatever the extraction step produced.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Extraction;

final readonly class LabPdfExtraction
{
    /** @var list<LabAnalyteExtraction> */
    public array $analytes;

    /**
     * $analytes arrives untyped at this boundary (extraction output is
     * untrusted draft data): elements are validated with instanceof, never
     * assumed from the caller's declared type.
     *
     * @param list<mixed> $analytes
     */
    public function __construct(
        public string $documentId,
        array $analytes,
    ) {
        if (trim($documentId) === '') {
            throw new \DomainException('LabPdfExtraction requires a non-blank source document id — provenance is mandatory');
        }

        $validatedAnalytes = [];
        foreach ($analytes as $analyte) {
            if (!$analyte instanceof LabAnalyteExtraction) {
                throw new \DomainException('LabPdfExtraction analytes must all be LabAnalyteExtraction instances — the schema boundary contains malformed extraction output');
            }
            $validatedAnalytes[] = $analyte;
        }

        $this->analytes = $validatedAnalytes;
    }
}
