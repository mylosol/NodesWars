import { describe, expect, it } from 'vitest';
import {
  WEAPONS,
  WEAPON_IDS,
  damageAt,
  damageFor,
  distance,
  isWeaponId,
  radiusFor,
  resolve,
  specFor,
} from '../blast.js';
import * as fp from '../fixedPoint.js';

const at = (x: number, y: number) => ({ x: fp.fromInt(x), y: fp.fromInt(y) });

describe('blast registry', () => {
  it('exposes every weapon from data/weapons.json', () => {
    expect(WEAPON_IDS).toEqual(['scout', 'light', 'medium', 'heavy', 'siege']);
    expect(fp.toNumber(radiusFor('scout'))).toBe(15);
    expect(fp.toNumber(radiusFor('siege'))).toBe(200);
    expect(fp.toNumber(damageFor('scout'))).toBe(20);
  });

  it('radii increase with tier', () => {
    for (let i = 1; i < WEAPON_IDS.length; i++) {
      const previous = WEAPONS[WEAPON_IDS[i - 1]!]!.blastRadiusM;
      expect(WEAPONS[WEAPON_IDS[i]!]!.blastRadiusM).toBeGreaterThan(previous);
    }
  });

  it('validates weapon ids', () => {
    expect(isWeaponId('siege')).toBe(true);
    expect(isWeaponId('nuke')).toBe(false);
    // @ts-expect-error unknown weapon is rejected at runtime too
    expect(() => specFor('nuke')).toThrow();
  });
});

describe('blast geometry', () => {
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
    for (let i = 1; i < WEAPON_IDS.length; i++) {
      const small = resolve({ center: at(0, 0), weaponId: WEAPON_IDS[i - 1]!, targets });
      const large = resolve({ center: at(0, 0), weaponId: WEAPON_IDS[i]!, targets });
      for (let t = 0; t < targets.length; t++) {
        if (small[t]!.withinRadius) expect(large[t]!.withinRadius).toBe(true);
      }
    }
  });

  it('handles an empty target list', () => {
    expect(resolve({ center: at(0, 0), weaponId: 'heavy', targets: [] })).toEqual([]);
  });
});

describe('blast damage', () => {
  it('deals full damage at the centre', () => {
    expect(damageAt('heavy', fp.fromInt(0))).toBe(fp.fromInt(80));
  });

  it('deals nothing beyond the radius', () => {
    expect(damageAt('heavy', fp.fromInt(101))).toBe(fp.fromInt(0));
    expect(damageAt('scout', fp.fromInt(16))).toBe(fp.fromInt(0));
  });

  it('falls off linearly to zero at the edge', () => {
    // heavy: 80 damage, 100 m radius.
    expect(fp.toNumber(damageAt('heavy', fp.fromInt(50)))).toBeCloseTo(40, 2);
    expect(fp.toNumber(damageAt('heavy', fp.fromInt(75)))).toBeCloseTo(20, 2);
    expect(damageAt('heavy', fp.fromInt(100))).toBe(fp.fromInt(0));
  });

  it('flat falloff deals full damage anywhere inside', () => {
    // scout is the only flat weapon: 20 damage, 15 m radius.
    expect(damageAt('scout', fp.fromInt(0))).toBe(fp.fromInt(20));
    expect(damageAt('scout', fp.fromInt(14))).toBe(fp.fromInt(20));
    expect(damageAt('scout', fp.fromInt(15))).toBe(fp.fromInt(20));
  });

  it('damage decreases monotonically with distance', () => {
    let previous = damageAt('siege', fp.fromInt(0));
    for (let d = 5; d <= 200; d += 5) {
      const current = damageAt('siege', fp.fromInt(d));
      expect(fp.cmp(current, previous)).toBeLessThanOrEqual(0);
      previous = current;
    }
  });

  it('resolve reports damage alongside geometry', () => {
    const hits = resolve({
      center: at(0, 0),
      weaponId: 'heavy',
      targets: [at(0, 0), at(50, 0), at(200, 0)],
    });
    expect(fp.toNumber(hits[0]!.damage)).toBeCloseTo(80, 2);
    expect(fp.toNumber(hits[1]!.damage)).toBeCloseTo(40, 2);
    expect(hits[2]!.damage).toBe(fp.fromInt(0));
  });

  it('rejects a negative distance', () => {
    expect(() => damageAt('heavy', fp.fromInt(-1))).toThrow();
  });
});
