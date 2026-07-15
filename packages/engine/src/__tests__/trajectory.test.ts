import { describe, expect, it } from 'vitest';
import * as fp from '../fixedPoint.js';
import { GRAVITY, compute } from '../trajectory.js';

const near = (actual: bigint, expected: number, tol: number) =>
  expect(Math.abs(fp.toNumber(actual as fp.Fixed) - expected)).toBeLessThan(tol);

describe('trajectory.compute', () => {
  it('45 deg / direction 0: ~1019 m range, ~254.8 m apogee, ~14.42 s', () => {
    const r = compute({
      velocity: fp.fromInt(100),
      angleDeg: fp.fromInt(45),
      directionDeg: fp.fromInt(0),
    });
    near(r.impact.x, 1019.4, 1);
    expect(r.impact.y).toBe(0n); // sin(0) = 0 exactly
    near(r.apogeeM, 254.8, 1);
    near(r.flightTimeS, 14.42, 0.1);
  });

  it('30 deg / direction 90: range lands on the y axis', () => {
    const r = compute({
      velocity: fp.fromInt(100),
      angleDeg: fp.fromInt(30),
      directionDeg: fp.fromInt(90),
    });
    expect(r.impact.x).toBe(0n); // cos(90) = 0 exactly
    near(r.impact.y, 882.8, 1);
  });

  it('angle 0: no flight, no impact', () => {
    const r = compute({
      velocity: fp.fromInt(100),
      angleDeg: fp.fromInt(0),
      directionDeg: fp.fromInt(0),
    });
    expect(r.flightTimeS).toBe(0n);
    expect(r.impact.x).toBe(0n);
    expect(r.impact.y).toBe(0n);
  });

  it('direction wraps (360 == 0)', () => {
    const a = compute({
      velocity: fp.fromInt(80),
      angleDeg: fp.fromInt(50),
      directionDeg: fp.fromInt(360),
    });
    const b = compute({
      velocity: fp.fromInt(80),
      angleDeg: fp.fromInt(50),
      directionDeg: fp.fromInt(0),
    });
    expect(a.impact.x).toBe(b.impact.x);
    expect(a.impact.y).toBe(b.impact.y);
  });

  it('exposes GRAVITY = 9.81', () => {
    expect(GRAVITY).toBe(642908n); // fromString('9.81')
  });
});
