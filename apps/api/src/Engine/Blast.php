<?php

declare(strict_types=1);

namespace NodesWars\Api\Engine;

/**
 * Blast geometry. Port of packages/engine/src/blast.ts.
 *
 * Distance from impact and whether it fell inside the weapon radius. Damage
 * magnitude is not modelled on either side; per-weapon damage values are not
 * specified yet.
 */
final class Blast
{
    /** @var array<string, int> */
    private const RADIUS_M_INT = [
        'scout' => 15,
        'light' => 25,
        'medium' => 50,
        'heavy' => 100,
        'siege' => 200,
    ];

    public static function isWeaponId(string $id): bool
    {
        return \array_key_exists($id, self::RADIUS_M_INT);
    }

    public static function radiusFor(string $weaponId): int
    {
        if (!self::isWeaponId($weaponId)) {
            throw new \InvalidArgumentException("blast: unknown weapon {$weaponId}");
        }

        return FixedPoint::fromInt(self::RADIUS_M_INT[$weaponId]);
    }

    /**
     * Straight-line distance between two local-metre points.
     *
     * @param array{x: int, y: int} $a
     * @param array{x: int, y: int} $b
     */
    public static function distance(array $a, array $b): int
    {
        $dx = FixedPoint::sub($a['x'], $b['x']);
        $dy = FixedPoint::sub($a['y'], $b['y']);

        return FixedPoint::sqrt(
            FixedPoint::add(FixedPoint::mul($dx, $dx), FixedPoint::mul($dy, $dy)),
        );
    }

    /**
     * Distance and hit flag for every target, in input order.
     *
     * @param array{x: int, y: int}       $center
     * @param list<array{x: int, y: int}> $targets
     *
     * @return list<array{targetIndex: int, distanceM: int, withinRadius: bool}>
     */
    public static function resolve(array $center, string $weaponId, array $targets): array
    {
        $radius = self::radiusFor($weaponId);
        $hits = [];

        foreach ($targets as $targetIndex => $target) {
            $distanceM = self::distance($center, $target);
            $hits[] = [
                'targetIndex' => $targetIndex,
                'distanceM' => $distanceM,
                // Exactly on the radius counts as a hit.
                'withinRadius' => FixedPoint::cmp($distanceM, $radius) <= 0,
            ];
        }

        return $hits;
    }
}
