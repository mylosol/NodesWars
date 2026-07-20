import { describe, expect, it } from 'vitest';
import * as fp from '../fixedPoint.js';
import { HALF_LIFE_MS, applyDamage, decayFactor, remainingShield, stack } from '../fortify.js';

const near = (actual: fp.Fixed, expected: number, tolerance = 0.001) =>
  expect(Math.abs(fp.toNumber(actual) - expected)).toBeLessThan(tolerance);

describe('fortify decay', () => {
  it('is a no-op at zero elapsed', () => {
    expect(decayFactor(0)).toBe(fp.fromInt(1));
  });

  it('halves exactly on each half-life boundary', () => {
    near(decayFactor(HALF_LIFE_MS), 0.5);
    near(decayFactor(HALF_LIFE_MS * 2), 0.25);
    near(decayFactor(HALF_LIFE_MS * 3), 0.125);
  });

  it('interpolates within a half-life', () => {
    // 2^-0.5 = 0.70710678...
    near(decayFactor(HALF_LIFE_MS / 2), 0.70711, 0.0005);
  });

  it('decreases monotonically', () => {
    let previous = decayFactor(0);
    for (let ms = 60_000; ms <= HALF_LIFE_MS * 4; ms += 60_000) {
      const current = decayFactor(ms);
      expect(fp.cmp(current, previous)).toBeLessThanOrEqual(0);
      previous = current;
    }
  });

  it('bottoms out at zero rather than going negative', () => {
    expect(decayFactor(HALF_LIFE_MS * 200)).toBe(fp.fromInt(0));
  });

  it('scales shield HP', () => {
    near(remainingShield(fp.fromInt(100), HALF_LIFE_MS), 50);
    near(remainingShield(fp.fromInt(100), HALF_LIFE_MS * 2), 25);
    expect(remainingShield(fp.fromInt(0), 1234)).toBe(fp.fromInt(0));
  });

  it('rejects bad input', () => {
    expect(() => decayFactor(-1)).toThrow();
    expect(() => decayFactor(1.5)).toThrow();
    expect(() => remainingShield(fp.fromInt(-1), 0)).toThrow();
  });
});

describe('fortify damage', () => {
  it('absorbs damage on the shield first', () => {
    const r = applyDamage(fp.fromInt(50), fp.fromInt(100), fp.fromInt(30));
    expect(r.shieldHp).toBe(fp.fromInt(20));
    expect(r.baseHp).toBe(fp.fromInt(100));
    expect(r.spillover).toBe(fp.fromInt(0));
  });

  it('spills the remainder onto base HP', () => {
    const r = applyDamage(fp.fromInt(50), fp.fromInt(100), fp.fromInt(70));
    expect(r.shieldHp).toBe(fp.fromInt(0));
    expect(r.baseHp).toBe(fp.fromInt(80));
    expect(r.spillover).toBe(fp.fromInt(20));
  });

  it('floors base HP at zero on overkill', () => {
    const r = applyDamage(fp.fromInt(10), fp.fromInt(20), fp.fromInt(500));
    expect(r.shieldHp).toBe(fp.fromInt(0));
    expect(r.baseHp).toBe(fp.fromInt(0));
    expect(r.spillover).toBe(fp.fromInt(490));
  });

  it('conserves HP when the hit is not overkill', () => {
    const damage = 70;
    const r = applyDamage(fp.fromInt(50), fp.fromInt(100), fp.fromInt(damage));
    const before = 50 + 100;
    const after = fp.toNumber(r.shieldHp) + fp.toNumber(r.baseHp);
    expect(before - after).toBe(damage);
  });

  it('rejects negative input', () => {
    expect(() => applyDamage(fp.fromInt(-1), fp.fromInt(1), fp.fromInt(1))).toThrow();
    expect(() => applyDamage(fp.fromInt(1), fp.fromInt(1), fp.fromInt(-1))).toThrow();
  });
});

describe('fortify stacking', () => {
  it('adds a layer onto the remainder of the old one', () => {
    const decayed = remainingShield(fp.fromInt(100), HALF_LIFE_MS);
    near(stack(decayed, fp.fromInt(100)), 150);
  });

  it('rejects negative input', () => {
    expect(() => stack(fp.fromInt(-1), fp.fromInt(0))).toThrow();
  });
});
