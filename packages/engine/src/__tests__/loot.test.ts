import { describe, expect, it } from 'vitest';
import * as fp from '../fixedPoint.js';
import { applyReward, multiplier } from '../loot.js';

describe('loot.multiplier', () => {
  it('level 1 is full (1.0)', () => {
    expect(multiplier(1)).toBe(fp.fromInt(1));
  });
  it('level 5 is ~0.6', () => {
    expect(Math.abs(fp.toNumber(multiplier(5)) - 0.6)).toBeLessThan(0.001);
  });
  it('level 11 is 0', () => {
    expect(multiplier(11)).toBe(0n);
  });
  it('level 12+ clamps to 0', () => {
    expect(multiplier(12)).toBe(0n);
    expect(multiplier(50)).toBe(0n);
  });
  it('applyReward scales the base reward', () => {
    expect(applyReward(fp.fromInt(100), 1)).toBe(fp.fromInt(100));
    expect(applyReward(fp.fromInt(100), 11)).toBe(0n);
  });
});
