<?php

declare(strict_types=1);

namespace NodesWars\Api\Engine;

/**
 * Diminishing-returns reward multiplier. Port of packages/engine/src/loot.ts.
 *
 * Frozen: max(0, 1 - (playerLevel - 1) / 10). Level 1 = 100%, 5 = 60%, 11+ = 0%.
 *
 * The ERD introduces this specifically "to protect Non-Played Nodes (NPNs,
 * unengaged nodes mapped by the server) from high-level farming". It is an
 * anti-farming rule, not a progression brake, so it applies only to rewards
 * taken from NPN targets. Hits on real players always pay in full.
 */
final class Loot
{
    public const TARGET_NPN = 'npn';
    public const TARGET_PLAYER = 'player';

    /** Reward multiplier in [0, 1] for a given player level. */
    public static function multiplier(int $playerLevel): int
    {
        $raw = FixedPoint::sub(
            FixedPoint::fromInt(1),
            FixedPoint::div(FixedPoint::fromInt($playerLevel - 1), FixedPoint::fromInt(10)),
        );

        return $raw < 0 ? 0 : $raw;
    }

    /** The multiplier actually applied, given who was hit. */
    public static function effectiveMultiplier(int $playerLevel, string $target): int
    {
        if (self::TARGET_PLAYER === $target) {
            return FixedPoint::fromInt(1);
        }
        if (self::TARGET_NPN !== $target) {
            throw new \InvalidArgumentException("loot: unknown target kind {$target}");
        }

        return self::multiplier($playerLevel);
    }

    /**
     * Scale a base reward. NPN rewards decay with level; player rewards do not.
     * Defaults to NPN, the conservative case, so a caller that forgets to say
     * cannot accidentally hand out unscaled farming rewards.
     */
    public static function applyReward(
        int $baseReward,
        int $playerLevel,
        string $target = self::TARGET_NPN,
    ): int {
        return FixedPoint::mul($baseReward, self::effectiveMultiplier($playerLevel, $target));
    }
}
