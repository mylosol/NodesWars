<?php

declare(strict_types=1);

namespace NodesWars\Api\Engine;

/**
 * XP to level curve. Port of packages/engine/src/levelCurve.ts.
 *
 * Levels run 1..11. Advancing from level L costs round(100 * 1.3^(L-1)) XP.
 * The thresholds are a committed table on both sides rather than a runtime
 * pow, which is what makes the two engines agree exactly.
 */
final class LevelCurve
{
    public const LEVEL_MIN = 1;
    public const LEVEL_CAP = 11;

    /**
     * Cumulative XP required to *be* a given level, indexed by level. Index 0
     * is unused padding. Index 1 is 0: everyone starts at level 1.
     *
     * @var list<int>
     */
    private const THRESHOLDS_INT = [0, 0, 100, 230, 399, 619, 905, 1276, 1759, 2386, 3202, 4262];

    private static function assertLevel(int $level): void
    {
        if ($level < self::LEVEL_MIN || $level > self::LEVEL_CAP) {
            throw new \InvalidArgumentException(
                "levelCurve: level {$level} out of range ".self::LEVEL_MIN.'..'.self::LEVEL_CAP,
            );
        }
    }

    private static function threshold(int $level): int
    {
        return FixedPoint::fromInt(self::THRESHOLDS_INT[$level]);
    }

    /** Total XP required to reach $level from zero. */
    public static function xpForLevel(int $level): int
    {
        self::assertLevel($level);

        return self::threshold($level);
    }

    /** XP required to advance from $level to the next one. Zero at the cap. */
    public static function xpToNext(int $level): int
    {
        self::assertLevel($level);
        if (self::LEVEL_CAP === $level) {
            return FixedPoint::fromInt(0);
        }

        return FixedPoint::sub(self::threshold($level + 1), self::threshold($level));
    }

    /** Highest level fully paid for by $totalXp, clamped to the cap. */
    public static function levelForXp(int $totalXp): int
    {
        if ($totalXp < 0) {
            throw new \InvalidArgumentException('levelCurve: totalXp must be non-negative');
        }

        $level = self::LEVEL_MIN;
        for ($candidate = self::LEVEL_MIN + 1; $candidate <= self::LEVEL_CAP; ++$candidate) {
            if (FixedPoint::cmp($totalXp, self::threshold($candidate)) < 0) {
                break;
            }
            $level = $candidate;
        }

        return $level;
    }
}
