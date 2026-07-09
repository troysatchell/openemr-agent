<?php

/**
 * One turn's trace correlation context (T17; ARCHITECTURE.md §6
 * observability; AUDIT S4/C4/C5; founder decision 5, 2026-07-09).
 *
 * The correlation ID is minted at the orchestrator boundary — the single
 * choke point — and carried explicitly through value objects and ports from
 * there on. It is never an ambient global or static: that pattern is exactly
 * what made auth hinge on a mutable global (S4), and observability must not
 * reproduce it. This is the ONLY join key between the PHI-free trace log and
 * the PHI-carrying disclosure log.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Engineering <copilot@example.com>
 * @copyright Copyright (c) 2026 Clinical Co-Pilot Engineering
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\Copilot\Observability;

final readonly class TraceContext
{
    public function __construct(
        public string $correlationId,
        public string $turnKind,
        public \DateTimeImmutable $startedAt,
    ) {
        if (trim($correlationId) === '') {
            throw new \DomainException('TraceContext requires a non-blank correlationId');
        }

        if (trim($turnKind) === '') {
            throw new \DomainException('TraceContext requires a non-blank turnKind');
        }
    }

    /**
     * Mints a fresh v4 UUID correlation ID for one turn. Called exactly once
     * per runTurn() — the orchestrator boundary is the single choke point
     * for minting (see TurnOrchestrator).
     */
    public static function start(string $turnKind, \DateTimeImmutable $now): self
    {
        return new self(self::uuidV4(), $turnKind, $now);
    }

    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);

        // Version 4: the high nibble of byte 6 is 0100.
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        // Variant: the high two bits of byte 8 are 10.
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
