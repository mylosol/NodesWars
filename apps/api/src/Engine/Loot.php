<?php

declare(strict_types=1);

namespace NodesWars\Api\Engine;

/**
 * Diminishing-returns reward multiplier. Port of packages/engine/src/loot.ts.
 *
 * Frozen: max(0, 1 - (playerLevel - 1) / 10). Level 1 = 100%, 5 = 60%, 11+ = 0%.
 */
final class Loot
{
    /** Reward multiplier in [0, 1] for a given player level. */
    public static function multiplier(int $playerLevel): int
    {
        $raw = FixedPoint::sub(
            FixedPoint::fromInt(1),
            FixedPoint::div(FixedPoint::fromInt($playerLevel - 1), FixedPoint::fromInt(10)),
        );

        return $raw < 0 ? 0 : $raw;
    }

    /** Scale a base reward by the level multiplier. */
    public static function applyReward(int $baseReward, int $playerLevel): int
    {
        return FixedPoint::mul($baseReward, self::multiplier($playerLevel));
    }
}
