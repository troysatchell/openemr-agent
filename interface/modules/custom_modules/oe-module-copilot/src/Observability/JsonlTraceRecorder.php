<?php

/**
 * JSONL-file TraceRecorder (T17; ARCHITECTURE.md §6 observability; AUDIT
 * C4/C5; founder decision 5, 2026-07-09).
 *
 * Appends exactly one JSON line per step (FILE_APPEND | LOCK_EX), so a
 * crash mid-turn leaves the prior steps intact on disk. The emitted schema
 * is fixed and exhaustive — it is the trace's PHI defense: there is no slot
 * for free-form content (no patient name, no question text, no chart data,
 * no claims), so a PHI leak into the trace would require adding a new key,
 * not merely a careless value.
 *
 * `vendor_units` (TRO-46) serializes {@see VendorUnits} when a step carries
 * one — vendor, unit kind, unit count, price version, and the already
 * computed cost — so non-token vendor cost (Cohere embed / rerank, ...) is
 * derivable FROM TRACES ALONE, exactly like the existing token-usage
 * `cost_usd`. `null` when the step carries no vendor units.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Observability;

final class JsonlTraceRecorder implements TraceRecorder
{
    public function __construct(private readonly string $filePath)
    {
        if (trim($filePath) === '') {
            throw new \DomainException('JsonlTraceRecorder requires a non-blank filePath');
        }
    }

    public function record(TraceContext $context, StepRecord $step): void
    {
        $tokenUsage = $step->tokenUsage;
        $vendorUnits = $step->vendorUnits;

        $line = json_encode(
            [
                'correlation_id' => $context->correlationId,
                'turn_kind' => $context->turnKind,
                'step' => $step->step,
                'started_at' => $step->startedAt->format(\DateTimeInterface::ATOM),
                'duration_ms' => $step->durationMs,
                'outcome' => $step->outcome->value,
                'error_class' => $step->errorClass,
                'model' => $tokenUsage?->modelId,
                'input_tokens' => $tokenUsage?->inputTokens,
                'output_tokens' => $tokenUsage?->outputTokens,
                'cost_usd' => $tokenUsage?->costUsd,
                'grounded_count' => $step->groundedCount,
                'rejected_count' => $step->rejectedCount,
                'vendor_units' => $vendorUnits === null ? null : [
                    'vendor' => $vendorUnits->vendor,
                    'unit_kind' => $vendorUnits->unitKind,
                    'units' => $vendorUnits->units,
                    'price_version' => $vendorUnits->priceVersion,
                    'cost_usd' => $vendorUnits->costUsd,
                ],
            ],
            JSON_THROW_ON_ERROR,
        );

        file_put_contents($this->filePath, $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
