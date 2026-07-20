<?php

declare(strict_types=1);

namespace NodesWars\Api\Engine;

/**
 * Blast geometry and damage. Port of packages/engine/src/blast.ts.
 *
 * The weapon roster lives in data/weapons.json and is compiled into
 * Weapons.php. Adding, removing or retuning a weapon is a data edit.
 */
final class Blast
{
    public static function isWeaponId(string $id): bool
    {
        return \array_key_exists($id, Weapons::ALL);
    }

    /**
     * @return array{id: string, name: string, blastRadiusM: int, damage: int, falloff: string}
     */
    public static function specFor(string $weaponId): array
    {
        if (!self::isWeaponId($weaponId)) {
            throw new \InvalidArgumentException("blast: unknown weapon {$weaponId}");
        }

        return Weapons::ALL[$weaponId];
    }

    public static function radiusFor(string $weaponId): int
    {
        return FixedPoint::fromInt(self::specFor($weaponId)['blastRadiusM']);
    }

    public static function damageFor(string $weaponId): int
    {
        return FixedPoint::fromInt(self::specFor($weaponId)['damage']);
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
     * Damage a weapon deals at $distanceM from the impact point. Zero outside
     * the radius.
     */
    public static function damageAt(string $weaponId, int $distanceM): int
    {
        if ($distanceM < 0) {
            throw new \InvalidArgumentException('blast: distance must be non-negative');
        }

        $spec = self::specFor($weaponId);
        $radius = FixedPoint::fromInt($spec['blastRadiusM']);
        if (FixedPoint::cmp($distanceM, $radius) > 0) {
            return FixedPoint::fromInt(0);
        }

        $full = FixedPoint::fromInt($spec['damage']);
        if ('flat' === $spec['falloff']) {
            return $full;
        }

        // Linear: full damage at the centre, zero at the edge.
        $remaining = FixedPoint::sub(FixedPoint::fromInt(1), FixedPoint::div($distanceM, $radius));

        return FixedPoint::mul($full, $remaining);
    }

    /**
     * Distance, hit flag and damage for every target, in input order.
     *
     * @param array{x: int, y: int}       $center
     * @param list<array{x: int, y: int}> $targets
     *
     * @return list<array{targetIndex: int, distanceM: int, withinRadius: bool, damage: int}>
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
                'damage' => self::damageAt($weaponId, $distanceM),
            ];
        }

        return $hits;
    }
}
