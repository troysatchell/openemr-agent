<?php

/**
 * Shared vendor identity constants for the eval gate (TRO-35;
 * eval/goldenset/README.md "Vendor fixture policy").
 *
 * Model ids are arbitrary, fixed strings here — the gate never reaches a
 * real vendor (every crossing replays through {@see InputKeyedReplayTransport}),
 * so these do not need to name a real Anthropic/Cohere model. What matters
 * is that {@see GoldenSetRunner} (replay) and
 * `bin/regenerate-eval-goldenset.php` (record) use the SAME constants, or a
 * request built at replay time hashes differently than the one recorded and
 * throws {@see UnexpectedVendorCallException} on every case.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Eval;

final class EvalVendorConfig
{
    public const VLM_MODEL_ID = 'copilot-eval-vlm-v1';
    public const EMBED_MODEL_ID = 'copilot-eval-embed-v1';
    public const RERANK_MODEL_ID = 'copilot-eval-rerank-v1';

    private function __construct()
    {
    }
}
