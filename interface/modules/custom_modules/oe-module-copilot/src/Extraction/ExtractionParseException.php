<?php

/**
 * Boundary failure for VLM extraction output (W2_ARCHITECTURE.md §2 step 4).
 *
 * Per Decision W2, VLM JSON is untrusted draft data: any schema violation —
 * malformed JSON, an unrecognized or missing key, a wrong-shaped field, an
 * out-of-range confidence, a DTO invariant the parsed wire data would break —
 * is an ingestion FAILURE. The source document stays attached and the failure
 * is traced; the extraction never partially succeeds (one bad field fails the
 * whole document, never a partially-trusted result).
 *
 * Because parse failures are recorded on the PHI-free trace (field names and
 * outcomes only, never values — W2_ARCHITECTURE §2 step 6), the message here
 * must never embed raw model output: it names which field failed and why,
 * never the value that failed.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Extraction;

final class ExtractionParseException extends \RuntimeException
{
}
