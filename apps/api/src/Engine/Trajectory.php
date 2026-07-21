<?php

declare(strict_types=1);

namespace NodesWars\Api\Engine;

/**
 * Vacuum-parabola artillery solver. Port of packages/engine/src/trajectory.ts.
 *
 * Frozen formula (position at time t):
 *   x = v*cos(angle)*cos(direction)*t
 *   y = v*cos(angle)*sin(direction)*t
 *   z = v*sin(angle)*t - 0.5*g*t^2
 * The shell lands at launch altitude (z = 0), so flightTime = 2*v*sin(angle)/g.
 * All outputs are local metres / seconds from the launch point.
 */
final class Trajectory
{
    /** Standard gravity, 9.81 m/s^2. */
    public static function gravity(): int
    {
        return FixedPoint::fromString('9.81');
    }

    /**
     * @param int      $velocity     muzzle speed, m/s
     * @param int      $angleDeg     elevation, degrees (domain [0, 90])
     * @param int      $directionDeg bearing, degrees
     * @param int|null $gravity      defaults to standard gravity
     *
     * @return array{impact: array{x: int, y: int}, flightTimeS: int, apogeeM: int}
     */
    public static function compute(
        int $velocity,
        int $angleDeg,
        int $directionDeg,
        ?int $gravity = null,
    ): array {
        $g = $gravity ?? self::gravity();
        $two = FixedPoint::fromInt(2);

        $vSin = FixedPoint::mul($velocity, FixedPoint::sinDeg($angleDeg));
        $vCos = FixedPoint::mul($velocity, FixedPoint::cosDeg($angleDeg));

        $flightTimeS = FixedPoint::div(FixedPoint::mul($two, $vSin), $g);
        $horiz = FixedPoint::mul($vCos, $flightTimeS);

        return [
            'impact' => [
                'x' => FixedPoint::mul($horiz, FixedPoint::cosDeg($directionDeg)),
                'y' => FixedPoint::mul($horiz, FixedPoint::sinDeg($directionDeg)),
            ],
            'flightTimeS' => $flightTimeS,
            'apogeeM' => FixedPoint::div(
                FixedPoint::mul($vSin, $vSin),
                FixedPoint::mul($two, $g),
            ),
        ];
    }
}
