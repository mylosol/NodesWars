import { describe, expect, it } from 'vitest';
import { BLAST_RADIUS_M, distance, isWeaponId, radiusFor, resolve } from '../blast.js';
import * as fp from '../fixedPoint.js';

const at = (x: number, y: number) => ({ x: fp.fromInt(x), y: fp.fromInt(y) });

describe('blast', () => {
  it('exposes the five frozen radii', () => {
    expect(Object.keys(BLAST_RADIUS_M)).toEqual(['scout', 'light', 'medium', 'heavy', 'siege']);
    expect(fp.toNumber(radiusFor('scout'))).toBe(15);
    expect(fp.toNumber(radiusFor('siege'))).toBe(200);
  });

  it('measures a 3-4-5 triangle exactly', () => {
    expect(distance(at(0, 0), at(3, 4))).toBe(fp.fromInt(5));
  });

  it('distance is symmetric and zero at the same point', () => {
    expect(distance(at(10, -7), at(-3, 2))).toBe(distance(at(-3, 2), at(10, -7)));
    expect(distance(at(9, 9), at(9, 9))).toBe(fp.fromInt(0));
  });

  it('flags targets inside the radius and not outside it', () => {
    const hits = resolve({
      center: at(0, 0),
      weaponId: 'light', // 25 m
      targets: [at(0, 0), at(20, 0), at(30, 0)],
    });
    expect(hits.map((h) => h.withinRadius)).toEqual([true, true, false]);
    expect(hits.map((h) => h.targetIndex)).toEqual([0, 1, 2]);
  });

  it('counts a target exactly on the radius as a hit', () => {
    const [hit] = resolve({ center: at(0, 0), weaponId: 'medium', targets: [at(50, 0)] });
    expect(hit!.withinRadius).toBe(true);
    expect(hit!.distanceM).toBe(fp.fromInt(50));
  });

  it('a bigger weapon never misses what a smaller one hits', () => {
    const targets = [at(10, 10), at(40, 0), at(120, 90), at(300, 0)];
    const order = ['scout', 'light', 'medium', 'heavy', 'siege'] as const;
    for (let i = 1; i < order.length; i++) {
      const small = resolve({ center: at(0, 0), weaponId: order[i - 1]!, targets });
      const large = resolve({ center: at(0, 0), weaponId: order[i]!, targets });
      for (let t = 0; t < targets.length; t++) {
        if (small[t]!.withinRadius) expect(large[t]!.withinRadius).toBe(true);
      }
    }
  });

  it('handles an empty target list', () => {
    expect(resolve({ center: at(0, 0), weaponId: 'heavy', targets: [] })).toEqual([]);
  });

  it('validates weapon ids', () => {
    expect(isWeaponId('siege')).toBe(true);
    expect(isWeaponId('nuke')).toBe(false);
    // @ts-expect-error unknown weapon is rejected at runtime too
    expect(() => radiusFor('nuke')).toThrow();
  });
});
