// Vacuum-parabola artillery solver. See
// docs/superpowers/specs/2026-07-15-engine-trajectory-design.md.
//
// Frozen formula (position at time t):
//   x = v*cos(angle)*cos(direction)*t
//   y = v*cos(angle)*sin(direction)*t
//   z = v*sin(angle)*t - 0.5*g*t^2
// The shell lands at launch altitude (z = 0), so flightTime = 2*v*sin(angle)/g.
// All outputs are local meters / seconds from the launch point.

import { type Fixed, cosDeg, div, fromInt, fromString, mul, sinDeg } from './fixedPoint.js';

/** A 2D point or offset in local meters. */
export interface Vec2 {
  readonly x: Fixed;
  readonly y: Fixed;
}

export interface TrajectoryInput {
  readonly velocity: Fixed; // muzzle speed, m/s
  readonly angleDeg: Fixed; // elevation, degrees (domain [0, 90])
  readonly directionDeg: Fixed; // bearing, degrees
}

export interface TrajectoryResult {
  readonly impact: Vec2; // offset from launch point, meters
  readonly flightTimeS: Fixed; // seconds
  readonly apogeeM: Fixed; // meters
}

/** Standard gravity, 9.81 m/s^2. Override via compute's second argument. */
export const GRAVITY: Fixed = fromString('9.81');

const TWO = fromInt(2);

export function compute(input: TrajectoryInput, gravity: Fixed = GRAVITY): TrajectoryResult {
  const { velocity, angleDeg, directionDeg } = input;
  const vSin = mul(velocity, sinDeg(angleDeg));
  const vCos = mul(velocity, cosDeg(angleDeg));

  const flightTimeS = div(mul(TWO, vSin), gravity);
  const horiz = mul(vCos, flightTimeS); // horizontal distance travelled
  const impact: Vec2 = {
    x: mul(horiz, cosDeg(directionDeg)),
    y: mul(horiz, sinDeg(directionDeg)),
  };
  const apogeeM = div(mul(vSin, vSin), mul(TWO, gravity));

  return { impact, flightTimeS, apogeeM };
}
