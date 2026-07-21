<?php

declare(strict_types=1);

namespace NodesWars\Api\Engine;

/**
 * 3-tier hit scoring split. Port of packages/engine/src/scoring.ts.
 *
 * Frozen: 20% base strike, 40% suspected hit, 40% confirmed hit. The confirmed
 * tier is the remainder rather than another multiply, so the three always sum
 * to exactly maxXp despite truncation.
 */
final class Scoring
{
    /**
     * Splits a move's max XP into the three tiers.
     *
     * @return array{base: int, suspected: int, confirmed: int}
     */
    public static function split(int $maxXp): array
    {
        $pct20 = FixedPoint::fromParts(0, 20, 100);
        $pct40 = FixedPoint::fromParts(0, 40, 100);

        $base = FixedPoint::mul($maxXp, $pct20);
        $suspected = FixedPoint::mul($maxXp, $pct40);
        $confirmed = FixedPoint::sub(FixedPoint::sub($maxXp, $base), $suspected);

        return ['base' => $base, 'suspected' => $suspected, 'confirmed' => $confirmed];
    }
}
