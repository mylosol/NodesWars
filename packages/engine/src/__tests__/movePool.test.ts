import { describe, expect, it } from 'vitest';
import { REGEN_INTERVAL_MS, regen } from '../movePool.js';

describe('movePool.regen', () => {
  it('no elapsed time returns current', () => {
    expect(regen(2, 10, 0)).toBe(2);
  });
  it('regenerates one move per interval', () => {
    expect(regen(0, 10, REGEN_INTERVAL_MS)).toBe(1);
    expect(regen(0, 10, REGEN_INTERVAL_MS * 3)).toBe(3);
    expect(regen(2, 10, REGEN_INTERVAL_MS * 2 + 1000)).toBe(4); // partial interval ignored
  });
  it('never exceeds max', () => {
    expect(regen(8, 10, REGEN_INTERVAL_MS * 100)).toBe(10);
  });
  it('throws on negative elapsed', () => {
    expect(() => regen(0, 10, -1)).toThrow();
  });
});
