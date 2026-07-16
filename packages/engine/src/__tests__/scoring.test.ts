import { describe, expect, it } from 'vitest';
import * as fp from '../fixedPoint.js';
import { split } from '../scoring.js';

describe('scoring.split', () => {
  it('splits 100 XP into ~20 / ~40 / ~40', () => {
    const s = split(fp.fromInt(100));
    expect(Math.abs(fp.toNumber(s.base) - 20)).toBeLessThan(0.01);
    expect(Math.abs(fp.toNumber(s.suspected) - 40)).toBeLessThan(0.01);
    expect(Math.abs(fp.toNumber(s.confirmed) - 40)).toBeLessThan(0.01);
  });
  it('tiers always sum to exactly the input', () => {
    for (const v of [0, 1, 7, 100, 12345]) {
      const s = split(fp.fromInt(v));
      expect(fp.add(fp.add(s.base, s.suspected), s.confirmed)).toBe(fp.fromInt(v));
    }
  });
});
