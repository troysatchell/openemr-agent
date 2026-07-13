<?php

/**
 * One entry in the corpus manifest's "Documents" table (corpus README §3).
 *
 * One document per file; `sourceId` is the filename stem and the physical
 * referent for click-to-source document preview. `file` is the filename the
 * indexer and chunker read manifest-driven (a whitelist, never a directory
 * scan) so a stray file can never become a phantom source. `sourceType`
 * distinguishes authored practice protocols from verbatim public-domain
 * guideline text; `license` records the tier that governs it (corpus README
 * §4). All four fields are mandatory — a blank value is not a real document.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Corpus;

final readonly class CorpusDocument
{
    public function __construct(
        public string $sourceId,
        public string $file,
        public string $sourceType,
        public string $license,
    ) {
        if (trim($sourceId) === '') {
            throw new \DomainException('CorpusDocument sourceId must be non-blank.');
        }
        if (trim($file) === '') {
            throw new \DomainException('CorpusDocument file must be non-blank.');
        }
        if (trim($sourceType) === '') {
            throw new \DomainException('CorpusDocument sourceType must be non-blank.');
        }
        if (trim($license) === '') {
            throw new \DomainException('CorpusDocument license must be non-blank.');
        }
    }
}
