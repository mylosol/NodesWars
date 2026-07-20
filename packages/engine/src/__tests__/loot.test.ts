import { describe, expect, it } from 'vitest';
import * as fp from '../fixedPoint.js';
import { applyReward, effectiveMultiplier, multiplier } from '../loot.js';

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

describe('loot target scoping', () => {
  it('leaves player-versus-player rewards unscaled at every level', () => {
    for (const level of [1, 5, 10, 11, 20]) {
      expect(effectiveMultiplier(level, 'player')).toBe(fp.fromInt(1));
      expect(applyReward(fp.fromInt(100), level, 'player')).toBe(fp.fromInt(100));
    }
  });

  it('scales NPN rewards down with level', () => {
    expect(applyReward(fp.fromInt(100), 1, 'npn')).toBe(fp.fromInt(100));
    expect(Math.abs(fp.toNumber(applyReward(fp.fromInt(100), 5, 'npn')) - 60)).toBeLessThan(0.01);
    expect(applyReward(fp.fromInt(100), 11, 'npn')).toBe(0n);
  });

  it('defaults to NPN so a forgetful caller cannot leak unscaled rewards', () => {
    expect(applyReward(fp.fromInt(100), 11)).toBe(applyReward(fp.fromInt(100), 11, 'npn'));
    expect(applyReward(fp.fromInt(100), 11)).toBe(0n);
  });
});
