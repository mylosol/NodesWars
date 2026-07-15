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

// bigint `/` truncates toward zero, matching PHP intdiv. This is the parity contract.
export function mul(a: Fixed, b: Fixed): Fixed {
  return asI64((a * b) / SCALE);
}

export function div(a: Fixed, b: Fixed): Fixed {
  if (b === 0n) throw new Error('fixedPoint.div by zero');
  return asI64((a * SCALE) / b);
}

/** Exact value with magnitude |int| + num/den; a negative int makes the whole value negative. */
export function fromParts(int: number, num: number, den: number): Fixed {
  if (!Number.isInteger(int) || !Number.isInteger(num) || !Number.isInteger(den)) {
    throw new Error('fromParts expects integers');
  }
  if (den <= 0) throw new Error('fromParts den must be positive');
  if (num < 0) throw new Error('fromParts num must be non-negative');
  const whole = BigInt(int) * SCALE;
  const frac = (BigInt(num) * SCALE) / BigInt(den); // trunc toward zero
  const signed = int < 0 ? whole - frac : whole + frac;
  return asI64(signed);
}

/** Exact decimal parse, e.g. "9.81", "-0.5", "3". No floats. */
export function fromString(s: string): Fixed {
  const m = /^(-?)(\d+)(?:\.(\d+))?$/.exec(s.trim());
  if (!m) throw new Error(`fromString cannot parse ${s}`);
  const neg = m[1] === '-';
  const intPart = BigInt(m[2] as string);
  const fracDigits = m[3] ?? '';
  const den = 10n ** BigInt(fracDigits.length);
  const fracNum = fracDigits === '' ? 0n : BigInt(fracDigits);
  const mag = intPart * SCALE + (fracNum * SCALE) / den; // trunc toward zero
  return asI64(neg ? -mag : mag);
}

/** Floor integer square root of a non-negative bigint (Newton's method, integer-only). */
export function isqrt(n: bigint): bigint {
  if (n < 0n) throw new Error('isqrt of negative');
  if (n < 2n) return n;
  let x0 = n >> 1n;
  let x1 = (x0 + n / x0) >> 1n;
  while (x1 < x0) {
    x0 = x1;
    x1 = (x0 + n / x0) >> 1n;
  }
  return x0;
}

/** sqrt(x) with x >= 0: isqrt(x_stored * SCALE) gives floor(sqrt(real) * SCALE). */
export function sqrt(x: Fixed): Fixed {
  if (x < 0n) throw new Error('fixedPoint.sqrt of negative');
  return asI64(isqrt(x * SCALE));
}
