import { describe, expect, it } from 'vitest';
import * as fp from '../fixedPoint.js';

describe('fixedPoint core', () => {
  it('round-trips whole numbers', () => {
    expect(fp.fromInt(3)).toBe(3n * fp.SCALE);
    expect(fp.toNumber(fp.fromInt(3))).toBe(3);
  });
  it('adds and subtracts', () => {
    expect(fp.add(fp.fromInt(2), fp.fromInt(5))).toBe(fp.fromInt(7));
    expect(fp.sub(fp.fromInt(2), fp.fromInt(5))).toBe(fp.fromInt(-3));
  });
  it('negates and abs', () => {
    expect(fp.neg(fp.fromInt(4))).toBe(fp.fromInt(-4));
    expect(fp.abs(fp.fromInt(-4))).toBe(fp.fromInt(4));
  });
  it('compares', () => {
    expect(fp.cmp(fp.fromInt(1), fp.fromInt(2))).toBe(-1);
    expect(fp.cmp(fp.fromInt(2), fp.fromInt(2))).toBe(0);
    expect(fp.cmp(fp.fromInt(3), fp.fromInt(2))).toBe(1);
  });
});

describe('fixedPoint mul/div', () => {
  it('multiplies', () => {
    expect(fp.mul(fp.fromInt(3), fp.fromInt(4))).toBe(fp.fromInt(12));
    // 1.5 * 0.5 = 0.75 -> stored 49152
    expect(fp.mul(98304n as fp.Fixed, 32768n as fp.Fixed)).toBe(49152n);
  });
  it('multiply truncates toward zero for negatives', () => {
    // (-1.5) * 0.5 = -0.75 -> stored -49152 exactly
    expect(fp.mul(-98304n as fp.Fixed, 32768n as fp.Fixed)).toBe(-49152n);
  });
  it('divides', () => {
    expect(fp.div(fp.fromInt(1), fp.fromInt(2))).toBe(32768n); // 0.5
    expect(fp.div(fp.fromInt(-1), fp.fromInt(2))).toBe(-32768n);
  });
  it('throws on divide by zero', () => {
    expect(() => fp.div(fp.fromInt(1), fp.fromInt(0))).toThrow();
  });
});

describe('fixedPoint exact constructors', () => {
  it('fromParts builds exact rationals', () => {
    expect(fp.fromParts(0, 1, 2)).toBe(32768n); // 0.5
    expect(fp.fromParts(2, 1, 4)).toBe(147456n); // 2.25
    expect(fp.fromParts(-1, 1, 2)).toBe(-98304n); // -(1 + 1/2) = -1.5
  });
  it('fromString parses exact decimals', () => {
    expect(fp.fromString('9.81')).toBe(fp.fromParts(9, 81, 100));
    expect(fp.fromString('-0.5')).toBe(-32768n);
    expect(fp.fromString('3')).toBe(fp.fromInt(3));
  });
});
