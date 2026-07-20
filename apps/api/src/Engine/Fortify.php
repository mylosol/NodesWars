<?php

declare(strict_types=1);

namespace NodesWars\Api\Engine;

/**
 * Fortify shields. Port of packages/engine/src/fortify.ts.
 *
 * Shields decay on a one hour half-life and absorb damage before base HP.
 * Decay is 2^(-elapsed/halfLife) computed without pow(): whole half-lives are
 * a right shift, and the fractional remainder comes from DecayTable with
 * integer interpolation.
 */
final class Fortify
{
    public const HALF_LIFE_MS = 3600000;

    /**
     * Past this many half-lives the shield has decayed below the smallest
     * representable Fixed value, so shifting further is pointless.
     */
    private const MAX_HALF_LIVES = 63;

    private const DECAY_STEPS = 64;

    /** Fraction of a shield remaining after $elapsedMs, a Fixed in (0, 1]. */
    public static function decayFactor(int $elapsedMs): int
    {
        if ($elapsedMs < 0) {
            throw new \InvalidArgumentException('fortify: elapsedMs must be non-negative');
        }

        $halfLives = intdiv($elapsedMs, self::HALF_LIFE_MS);
        if ($halfLives > self::MAX_HALF_LIVES) {
            return 0;
        }

        $remainder = $elapsedMs - $halfLives * self::HALF_LIFE_MS;

        $scaled = $remainder * self::DECAY_STEPS;
        $index = intdiv($scaled, self::HALF_LIFE_MS);
        $frac = $scaled % self::HALF_LIFE_MS;

        $lo = DecayTable::VALUES[$index];
        $hi = DecayTable::VALUES[$index + 1];
        // The table descends, so interpolate downward from lo.
        $interpolated = $lo - intdiv(($lo - $hi) * $frac, self::HALF_LIFE_MS);

        // $interpolated is always positive, so the shift matches the
        // TypeScript engine's arithmetic shift exactly.
        return $interpolated >> $halfLives;
    }

    /** Shield HP remaining after $elapsedMs of decay. */
    public static function remainingShield(int $shieldHp, int $elapsedMs): int
    {
        if ($shieldHp < 0) {
            throw new \InvalidArgumentException('fortify: shieldHp must be non-negative');
        }

        return FixedPoint::mul($shieldHp, self::decayFactor($elapsedMs));
    }

    /**
     * Applies damage to the shield first, then to base HP. Both outputs are
     * floored at zero, so overkill is discarded rather than going negative.
     *
     * @return array{shieldHp: int, baseHp: int, spillover: int}
     */
    public static function applyDamage(int $shieldHp, int $baseHp, int $damage): array
    {
        if ($shieldHp < 0 || $baseHp < 0) {
            throw new \InvalidArgumentException('fortify: HP must be non-negative');
        }
        if ($damage < 0) {
            throw new \InvalidArgumentException('fortify: damage must be non-negative');
        }

        $absorbed = min($damage, $shieldHp);
        $spillover = $damage - $absorbed;
        $remainingBase = $baseHp - $spillover;

        return [
            'shieldHp' => $shieldHp - $absorbed,
            'baseHp' => max(0, $remainingBase),
            'spillover' => $spillover,
        ];
    }

    /** Stacks a new shield layer onto whatever is left of the old one. */
    public static function stack(int $currentShieldHp, int $addedHp): int
    {
        if ($currentShieldHp < 0 || $addedHp < 0) {
            throw new \InvalidArgumentException('fortify: shield HP must be non-negative');
        }

        return $currentShieldHp + $addedHp;
    }
}
