import { describe, expect, it } from 'vitest';
import * as fp from '../fixedPoint.js';
import { LEVEL_CAP, levelForXp, xpForLevel, xpToNext } from '../levelCurve.js';

describe('levelCurve', () => {
  it('starts level 1 at zero XP', () => {
    expect(xpForLevel(1)).toBe(fp.fromInt(0));
    expect(levelForXp(fp.fromInt(0))).toBe(1);
  });

  it('follows the committed exponential thresholds', () => {
    expect(fp.toNumber(xpForLevel(2))).toBe(100);
    expect(fp.toNumber(xpForLevel(5))).toBe(619);
    expect(fp.toNumber(xpForLevel(LEVEL_CAP))).toBe(4262);
  });

  it('xpToNext is the gap between thresholds', () => {
    for (let level = 1; level < LEVEL_CAP; level++) {
      const gap = fp.sub(xpForLevel(level + 1), xpForLevel(level));
      expect(xpToNext(level)).toBe(gap);
    }
  });

  it('costs nothing to advance past the cap', () => {
    expect(xpToNext(LEVEL_CAP)).toBe(fp.fromInt(0));
  });

  it('grows monotonically', () => {
    for (let level = 1; level < LEVEL_CAP - 1; level++) {
      expect(fp.cmp(xpToNext(level + 1), xpToNext(level))).toBe(1);
    }
  });

  it('levelForXp is the inverse of xpForLevel at every threshold', () => {
    for (let level = 1; level <= LEVEL_CAP; level++) {
      expect(levelForXp(xpForLevel(level))).toBe(level);
    }
  });

  it('one XP short of a threshold stays on the lower level', () => {
    for (let level = 2; level <= LEVEL_CAP; level++) {
      const justUnder = fp.sub(xpForLevel(level), fp.fromInt(1));
      expect(levelForXp(justUnder)).toBe(level - 1);
    }
  });

  it('clamps above the cap', () => {
    expect(levelForXp(fp.fromInt(999_999))).toBe(LEVEL_CAP);
  });

  it('rejects out-of-range levels and negative XP', () => {
    expect(() => xpForLevel(0)).toThrow();
    expect(() => xpForLevel(LEVEL_CAP + 1)).toThrow();
    expect(() => xpForLevel(1.5)).toThrow();
    expect(() => levelForXp(fp.fromInt(-1))).toThrow();
  });
});
