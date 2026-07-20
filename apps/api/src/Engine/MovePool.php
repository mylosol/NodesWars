<?php

declare(strict_types=1);

namespace NodesWars\Api\Engine;

/**
 * Move-pool regeneration. Port of packages/engine/src/movePool.ts.
 *
 * Frozen: one move regenerates every 5 minutes. The cap is a caller-supplied
 * balance value, not hardcoded here.
 */
final class MovePool
{
    public const REGEN_INTERVAL_MS = 300000; // 5 minutes

    /** Moves available after elapsedMs, capped at max. */
    public static function regen(int $current, int $max, int $elapsedMs): int
    {
        if ($elapsedMs < 0) {
            throw new \InvalidArgumentException('elapsedMs must be non-negative');
        }

        $gained = intdiv($elapsedMs, self::REGEN_INTERVAL_MS);

        return min($max, $current + $gained);
    }
}
