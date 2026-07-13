<?php

/**
 * Thrown when the eval gate's replay transport (InputKeyedReplayTransport)
 * receives a request whose canonicalized content hash has no recorded
 * fixture (W2_ARCHITECTURE.md §7; PS-2).
 *
 * There is no default fallback and no silent fixture regeneration: an unseen
 * input key is exactly the signal the gate exists to catch — a data-trust bug
 * upstream that garbles what the module sends a vendor must surface as a red
 * gate, never a canned answer replayed for the wrong request.
 *
 * The message carries the request HASH only, never the request body — vendor
 * requests carry PHI, and this exception's message is the kind of thing that
 * ends up in CI logs and stack traces (AUDIT: never leak PHI into
 * incidental channels).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Eval;

final class UnexpectedVendorCallException extends \RuntimeException
{
}
