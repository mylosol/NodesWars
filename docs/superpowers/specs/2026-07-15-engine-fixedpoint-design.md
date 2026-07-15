# Deterministic engine: fixed-point foundation

Date: 2026-07-15
Status: approved design, pre-implementation

## Goal

Implement `packages/engine/src/fixedPoint.ts` as the deterministic numeric
foundation of the Nodes Wars engine. Every game-affecting calculation runs
through this module. The TypeScript implementation and the later PHP port
must produce byte-identical results for the same inputs, verified by shared
golden fixtures.

This spec covers the `fixedPoint` module only. The gameplay modules
(trajectory, blast, loot, movePool, levelCurve) build on it and are specced
separately.

## Frozen context this reconciles

The ERD and handoff froze "Q32.32 fixed point, byte-identical TS and PHP."
Q32.32 forces 128-bit multiply intermediates, which PHP's native 64-bit int
cannot hold without ext-gmp. With the user, we changed the scale (an
explicit revision of the frozen choice, not a silent one) to a binary scale
that keeps every multiply inside signed 64-bit. Precision at the new scale
is far more than a location game needs.

## Representation

- A `Fixed` value is a signed 64-bit integer equal to `round(real * 2^16)`.
- `SCALE = 65536` (`1 << 16`), 16 fractional bits.
- TypeScript: `Fixed` is a branded `bigint`. Every operation masks the
  result back into the signed 64-bit range so results match a 64-bit
  language exactly. Helper `asI64(x)` wraps to `[-2^63, 2^63)`.
- PHP (later port): a plain `int` (64-bit on target platforms). No ext-gmp,
  no ext-bcmath.

### Safe-multiply range

`mul(a, b)` computes `a_stored * b_stored` as an intermediate before scaling
down. That intermediate must stay below `2^63`. Therefore operands must
satisfy `|a_real| * |b_real| * 2^32 < 2^63`, i.e. roughly `|real| < 46340`
for a product of two same-magnitude values.

Consequence for the engine: all physics runs in **local meters** offset from
a per-match origin, so magnitudes stay well under 46,340 (a match spans a
few km). This is a documented invariant, asserted in debug builds. Callers
never multiply raw squared distances; magnitudes are taken with `sqrt`.

## Rounding

All scaling divisions **truncate toward zero**, because that is the one
rounding mode both `bigint /` (TS) and `intdiv` (PHP) share exactly.

- `mul(a, b) = truncTowardZero(a * b, SCALE)`
- `div(a, b) = truncTowardZero(a * SCALE, b)`

No banker's rounding, no floor. Truncation toward zero is the parity
contract; the PHP port must use `intdiv`, not `>>`.

## Public API

```
type Fixed = bigint & { __brand: 'Fixed' }
const SCALE: bigint            // 65536n

fromInt(n: number): Fixed      // exact; n is a whole number
fromParts(int: number, num: number, den: number): Fixed
                               // exact rational, e.g. fromParts(0, 1, 2) = 0.5
fromString(s: string): Fixed   // exact decimal parse, e.g. "9.81"
toNumber(x: Fixed): number     // DISPLAY ONLY; never feeds back into engine math

neg(x): Fixed
abs(x): Fixed
add(a, b): Fixed
sub(a, b): Fixed
mul(a, b): Fixed               // trunc toward zero
div(a, b): Fixed               // trunc toward zero; div by zero throws
cmp(a, b): -1 | 0 | 1

sqrt(x): Fixed                 // x >= 0; deterministic integer sqrt
sinDeg(angle: Fixed): Fixed    // angle in degrees, any range, normalized
cosDeg(angle: Fixed): Fixed    // = sinDeg(90 - angle)
```

`fromString` and `fromParts` exist so fixtures and constants (like `9.81`)
are expressed exactly without ever touching a float. `toNumber` is the only
float-producing function and is banned from the game-state path (only for UI
and logging).

### sqrt

Deterministic integer square root of the underlying value, computed so that
`sqrt(x)_stored = isqrt(x_stored * SCALE)`. `isqrt` is the bit-by-bit integer
square root (no floats), identical in both languages. Domain: `x >= 0`;
negative input throws.

### Trig: lookup table + linear interpolation

`Math.sin`/`cos` are banned and accuracy is irrelevant to parity, so trig is
a fixed integer table plus integer interpolation. Both languages ship the
same table and the same integer steps, so they are identical by construction.

- Table: sine of `0..90` degrees, one entry per **1/16 degree** (1441
  entries), each `round(sin(theta) * SCALE)`, generated once by a build-time
  script and committed as a constant array. The generator is float-based but
  runs offline; only its integer output ships.
- `sinDeg(angle)`: normalize angle to `[0, 360)` in fixed point, reduce to
  the first quadrant with sign bookkeeping, index the table, and linearly
  interpolate between the two nearest entries using `mul`/`sub`/`add`.
- `cosDeg(angle) = sinDeg(add(fromInt(90), neg(angle)))` ... i.e.
  `sinDeg(90 - angle)`.

The table and interpolation are the parity contract for trig; the PHP port
copies the same constants.

## Golden fixtures

`test-fixtures/engine-cases.json` gains fixed-point cases. Schema:

```
{
  "cases": [
    {
      "id": "mul-neg-half",
      "op": "mul",
      "args": ["-98304", "32768"],   // Fixed operands as int64 strings
      "expected": "-49152"           // Fixed result as int64 string
    }
  ]
}
```

- Operands and results are int64 **strings** (JSON numbers cannot hold 64-bit
  safely). TS parses with `BigInt`, PHP with `intval`/`(int)`.
- `op` is one of the API function names. `args` are Fixed strings, except
  `fromInt`/`fromString`/`fromParts` whose args are the raw inputs.
- Cases cover: identities, signs (both operands negative, mixed),
  truncation boundaries (values that round differently under floor vs
  trunc), div rounding, `sqrt` of perfect and non-perfect squares, and trig
  at 0/30/45/60/90/180/270/360 plus a few off-grid angles that exercise
  interpolation.

The same JSON drives both the TS test and the future PHP test, which is how
cross-language parity is enforced in CI.

## Testing

- `packages/engine/src/__tests__/fixedPoint.test.ts` (Vitest):
  1. **Fixture runner:** load `engine-cases.json`, execute each case against
     the module, assert the Fixed result equals `expected`.
  2. **Property/edge tests:** `add`/`sub` inverse, `mul` by SCALE identity,
     `div` then `mul` round-trip within one ulp, `sqrt(mul(x,x)) == abs(x)`
     for in-range x, angle normalization wrap-around, i64 overflow wrap.
  3. **Guards:** `div` by zero throws, `sqrt` of negative throws, safe-range
     assertion fires in debug when an operand exceeds ±46340.
- No test may call `Math.*` on a value that flows into an assertion of a
  Fixed result.

## Out of scope

- Gameplay modules (trajectory, blast, loot, movePool, levelCurve).
- The PHP port itself (Phase 2). This spec defines the contract it must meet.
- Lat/lon <-> local-meter conversion (lives in a later `coords` module; the
  safe-range invariant it must uphold is stated here).
