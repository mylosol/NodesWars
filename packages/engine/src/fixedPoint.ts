// Fixed-point arithmetic for the deterministic engine.
// A Fixed is a signed 64-bit integer equal to round(real * 2^16).
// See docs/superpowers/specs/2026-07-15-engine-fixedpoint-design.md.

export type Fixed = bigint & { readonly __brand: 'Fixed' };

export const SCALE = 65536n; // 1 << 16

/** Wrap a bigint into the signed 64-bit range so results match a 64-bit language. */
export function asI64(x: bigint): Fixed {
  return BigInt.asIntN(64, x) as Fixed;
}

export function fromInt(n: number): Fixed {
  if (!Number.isInteger(n)) throw new Error(`fromInt expects an integer, got ${n}`);
  return asI64(BigInt(n) * SCALE);
}

/** DISPLAY ONLY. Never feed the result back into engine math. */
export function toNumber(x: Fixed): number {
  return Number(x) / Number(SCALE);
}

export function neg(x: Fixed): Fixed {
  return asI64(-x);
}

export function abs(x: Fixed): Fixed {
  return asI64(x < 0n ? -x : x);
}

export function add(a: Fixed, b: Fixed): Fixed {
  return asI64(a + b);
}

export function sub(a: Fixed, b: Fixed): Fixed {
  return asI64(a - b);
}

export function cmp(a: Fixed, b: Fixed): -1 | 0 | 1 {
  return a < b ? -1 : a > b ? 1 : 0;
}
