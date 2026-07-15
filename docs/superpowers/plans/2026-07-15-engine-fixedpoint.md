# Fixed-Point Engine Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement `packages/engine/src/fixedPoint.ts` — the deterministic Q-scale-2^16 numeric foundation — with golden fixtures the future PHP port must match byte-for-byte.

**Architecture:** A `Fixed` value is a signed 64-bit integer equal to `round(real * 65536)`, carried as a branded `bigint` in TS and masked to 64-bit with `BigInt.asIntN(64, …)`. All scaling divisions truncate toward zero (`bigint /`, the mode PHP `intdiv` shares). Trig uses a committed integer sine table plus integer interpolation. A fixture runner executes shared `test-fixtures/engine-cases.json` cases so the same JSON validates TS now and PHP later.

**Tech Stack:** TypeScript (strict, ESM), Vitest, Node scripts for table generation. Reference spec: `docs/superpowers/specs/2026-07-15-engine-fixedpoint-design.md`.

---

## File Structure

- `packages/engine/src/fixedPoint.ts` — the module (replaces the current stub).
- `packages/engine/src/sineTable.ts` — GENERATED constant sine table.
- `packages/engine/scripts/gen-sine-table.mjs` — offline generator for the table.
- `packages/engine/src/__tests__/fixedPoint.test.ts` — unit tests.
- `packages/engine/src/__tests__/fixtures.test.ts` — golden fixture runner.
- `test-fixtures/engine-cases.json` — shared cross-language fixtures.

All commits run from the repo root `D:\Claude Code\NodesWars`. Tests run with `pnpm --filter @nodeswars/engine test` (or the recursive form used in CI). Use `corepack pnpm` if `pnpm` is not on PATH locally.

---

## Task 1: Representation and additive core

**Files:**
- Modify: `packages/engine/src/fixedPoint.ts` (replace stub)
- Test: `packages/engine/src/__tests__/fixedPoint.test.ts` (create)

- [ ] **Step 1: Write the failing test**

```ts
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `corepack pnpm --filter @nodeswars/engine test`
Expected: FAIL — `fromInt` throws `not implemented` / assertions fail.

- [ ] **Step 3: Write minimal implementation** (replace the whole file)

```ts
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `corepack pnpm --filter @nodeswars/engine test`
Expected: PASS (the pre-existing smoke test still passes too).

- [ ] **Step 5: Commit**

```bash
git add packages/engine/src/fixedPoint.ts packages/engine/src/__tests__/fixedPoint.test.ts
git commit -s -m "feat(engine): fixed-point representation and additive core"
```

---

## Task 2: Multiply and divide (truncate toward zero)

**Files:**
- Modify: `packages/engine/src/fixedPoint.ts`
- Test: `packages/engine/src/__tests__/fixedPoint.test.ts`

- [ ] **Step 1: Write the failing test** (append inside the file)

```ts
describe('fixedPoint mul/div', () => {
  it('multiplies', () => {
    // 1.5 * 0.5 = 0.75
    const onePointFive = fp.add(fp.fromInt(1), fp.div(fp.fromInt(1), fp.fromInt(2)));
    const half = fp.div(fp.fromInt(1), fp.fromInt(2));
    expect(fp.mul(onePointFive, half)).toBe(fp.mul(fp.fromInt(3), fp.div(fp.fromInt(1), fp.fromInt(4))));
  });
  it('multiply truncates toward zero for negatives', () => {
    // (-1.5) * 0.5 = -0.75 -> stored -49152 exactly
    const negThreeHalves = (-98304n) as fp.Fixed;
    const half = 32768n as fp.Fixed;
    expect(fp.mul(negThreeHalves, half)).toBe(-49152n);
  });
  it('divides', () => {
    expect(fp.div(fp.fromInt(1), fp.fromInt(2))).toBe(32768n); // 0.5
    expect(fp.div(fp.fromInt(-1), fp.fromInt(2))).toBe(-32768n);
  });
  it('throws on divide by zero', () => {
    expect(() => fp.div(fp.fromInt(1), fp.fromInt(0))).toThrow();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `corepack pnpm --filter @nodeswars/engine test`
Expected: FAIL — `mul`/`div` are not defined.

- [ ] **Step 3: Write minimal implementation** (append to `fixedPoint.ts`)

```ts
// bigint `/` truncates toward zero, matching PHP intdiv. This is the parity contract.
export function mul(a: Fixed, b: Fixed): Fixed {
  return asI64((a * b) / SCALE);
}

export function div(a: Fixed, b: Fixed): Fixed {
  if (b === 0n) throw new Error('fixedPoint.div by zero');
  return asI64((a * SCALE) / b);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `corepack pnpm --filter @nodeswars/engine test`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/engine/src/fixedPoint.ts packages/engine/src/__tests__/fixedPoint.test.ts
git commit -s -m "feat(engine): fixed-point mul and div with trunc-toward-zero"
```

---

## Task 3: Exact constructors (fromParts, fromString)

**Files:**
- Modify: `packages/engine/src/fixedPoint.ts`
- Test: `packages/engine/src/__tests__/fixedPoint.test.ts`

- [ ] **Step 1: Write the failing test**

```ts
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `corepack pnpm --filter @nodeswars/engine test`
Expected: FAIL — `fromParts`/`fromString` not defined.

- [ ] **Step 3: Write minimal implementation** (append to `fixedPoint.ts`)

```ts
/** Exact value int + num/den. Sign follows int for whole part; fraction is non-negative. */
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
  const intPart = BigInt(m[2]);
  const fracDigits = m[3] ?? '';
  const den = 10n ** BigInt(fracDigits.length);
  const fracNum = fracDigits === '' ? 0n : BigInt(fracDigits);
  const whole = intPart * SCALE;
  const frac = (fracNum * SCALE) / den; // trunc toward zero
  const mag = whole + frac;
  return asI64(neg ? -mag : mag);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `corepack pnpm --filter @nodeswars/engine test`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/engine/src/fixedPoint.ts packages/engine/src/__tests__/fixedPoint.test.ts
git commit -s -m "feat(engine): exact fixed-point constructors fromParts and fromString"
```

---

## Task 4: Integer square root

**Files:**
- Modify: `packages/engine/src/fixedPoint.ts`
- Test: `packages/engine/src/__tests__/fixedPoint.test.ts`

- [ ] **Step 1: Write the failing test**

```ts
describe('fixedPoint sqrt', () => {
  it('sqrt of perfect squares', () => {
    expect(fp.sqrt(fp.fromInt(4))).toBe(fp.fromInt(2));
    expect(fp.sqrt(fp.fromInt(9))).toBe(fp.fromInt(3));
    expect(fp.sqrt(fp.fromInt(0))).toBe(fp.fromInt(0));
  });
  it('sqrt(mul(x,x)) == abs(x) for in-range x', () => {
    const x = fp.fromInt(123);
    expect(fp.sqrt(fp.mul(x, x))).toBe(fp.abs(x));
  });
  it('sqrt of 2 is ~1.4142', () => {
    // floor(sqrt(2) * 65536) = 92681
    expect(fp.sqrt(fp.fromInt(2))).toBe(92681n);
  });
  it('throws on negative', () => {
    expect(() => fp.sqrt(fp.fromInt(-1))).toThrow();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `corepack pnpm --filter @nodeswars/engine test`
Expected: FAIL — `sqrt` not defined.

- [ ] **Step 3: Write minimal implementation** (append to `fixedPoint.ts`)

```ts
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `corepack pnpm --filter @nodeswars/engine test`
Expected: PASS. (Check `sqrt(2)`: `isqrt(2 * 65536 * 65536) = isqrt(8589934592) = 92681`.)

- [ ] **Step 5: Commit**

```bash
git add packages/engine/src/fixedPoint.ts packages/engine/src/__tests__/fixedPoint.test.ts
git commit -s -m "feat(engine): deterministic integer sqrt"
```

---

## Task 5: Sine table generator and trig

**Files:**
- Create: `packages/engine/scripts/gen-sine-table.mjs`
- Create (generated): `packages/engine/src/sineTable.ts`
- Modify: `packages/engine/src/fixedPoint.ts`
- Test: `packages/engine/src/__tests__/fixedPoint.test.ts`

- [ ] **Step 1: Write the generator**

`packages/engine/scripts/gen-sine-table.mjs`:

```js
// Offline generator. Emits sin(0..90 deg) at 1/16 deg, scaled by 2^16, as integers.
// Run: node packages/engine/scripts/gen-sine-table.mjs
import { writeFileSync } from 'node:fs';

const SCALE = 65536;
const STEPS = 1440; // 90 deg * 16 steps/deg -> 1441 entries (0..1440)
const vals = [];
for (let i = 0; i <= STEPS; i++) {
  const rad = (i / 16) * (Math.PI / 180);
  vals.push(Math.round(Math.sin(rad) * SCALE));
}
const body = vals.join(', ');
const out =
  '// GENERATED by scripts/gen-sine-table.mjs. Do not edit by hand.\n' +
  '// sin(0..90 deg) at 1/16-deg resolution, scaled by 2^16. Index i => (i/16) deg.\n' +
  'export const SINE_TABLE: readonly number[] = [\n  ' + body + ',\n];\n';
writeFileSync(new URL('../src/sineTable.ts', import.meta.url), out);
console.log(`wrote ${vals.length} entries`);
```

- [ ] **Step 2: Generate the table and verify boundaries**

Run: `node packages/engine/scripts/gen-sine-table.mjs`
Expected: prints `wrote 1441 entries`; `packages/engine/src/sineTable.ts` exists with `SINE_TABLE[0] === 0`, `SINE_TABLE[480] === 32768` (30 deg), `SINE_TABLE[1440] === 65536` (90 deg).

- [ ] **Step 3: Write the failing test** (append to `fixedPoint.test.ts`)

```ts
describe('fixedPoint trig', () => {
  it('sinDeg at grid angles', () => {
    expect(fp.sinDeg(fp.fromInt(0))).toBe(0n);
    expect(fp.sinDeg(fp.fromInt(30))).toBe(32768n);   // 0.5
    expect(fp.sinDeg(fp.fromInt(90))).toBe(65536n);   // 1.0
    expect(fp.sinDeg(fp.fromInt(150))).toBe(32768n);  // sin150 = 0.5
    expect(fp.sinDeg(fp.fromInt(180))).toBe(0n);
    expect(fp.sinDeg(fp.fromInt(210))).toBe(-32768n); // sin210 = -0.5
    expect(fp.sinDeg(fp.fromInt(270))).toBe(-65536n);
    expect(fp.sinDeg(fp.fromInt(360))).toBe(0n);
  });
  it('normalizes out-of-range and negative angles', () => {
    expect(fp.sinDeg(fp.fromInt(390))).toBe(fp.sinDeg(fp.fromInt(30)));
    expect(fp.sinDeg(fp.fromInt(-30))).toBe(-32768n);
  });
  it('cosDeg', () => {
    expect(fp.cosDeg(fp.fromInt(0))).toBe(65536n);
    expect(fp.cosDeg(fp.fromInt(60))).toBe(32768n); // cos60 = 0.5
    expect(fp.cosDeg(fp.fromInt(90))).toBe(0n);
  });
  it('interpolates off-grid (45 deg)', () => {
    // 45 deg is on the 1/16 grid (index 720): round(sin45*65536) = 46341
    expect(fp.sinDeg(fp.fromInt(45))).toBe(46341n);
  });
});
```

- [ ] **Step 4: Run test to verify it fails**

Run: `corepack pnpm --filter @nodeswars/engine test`
Expected: FAIL — `sinDeg`/`cosDeg` not defined.

- [ ] **Step 5: Write minimal implementation** (append to `fixedPoint.ts`)

```ts
import { SINE_TABLE } from './sineTable.js';

const DEG360 = 360n * SCALE;
const DEG180 = 180n * SCALE;
const DEG90 = 90n * SCALE;
const STEP = SCALE / 16n; // fixed units per 1/16 degree = 4096
const TABLE_MAX = 1440; // last index

/** First-quadrant sine for a fixed angle in [0, 90*SCALE]. */
function sineFirstQuadrant(a: bigint): bigint {
  let i = a / STEP; // floor, a >= 0
  const frac = a - i * STEP; // [0, STEP)
  if (i >= BigInt(TABLE_MAX)) return BigInt(SINE_TABLE[TABLE_MAX]);
  const lo = BigInt(SINE_TABLE[Number(i)]);
  const hi = BigInt(SINE_TABLE[Number(i) + 1]);
  return lo + ((hi - lo) * frac) / STEP; // trunc toward zero
}

export function sinDeg(angle: Fixed): Fixed {
  // Normalize to [0, 360*SCALE).
  let a = angle % DEG360;
  if (a < 0n) a += DEG360;
  if (a < DEG90) return asI64(sineFirstQuadrant(a));
  if (a < DEG180) return asI64(sineFirstQuadrant(DEG180 - a));
  if (a < 3n * DEG90) return asI64(-sineFirstQuadrant(a - DEG180));
  return asI64(-sineFirstQuadrant(DEG360 - a));
}

export function cosDeg(angle: Fixed): Fixed {
  return sinDeg(asI64(DEG90 - angle) as Fixed);
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `corepack pnpm --filter @nodeswars/engine test`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add packages/engine/scripts/gen-sine-table.mjs packages/engine/src/sineTable.ts packages/engine/src/fixedPoint.ts packages/engine/src/__tests__/fixedPoint.test.ts
git commit -s -m "feat(engine): lookup-table sin and cos"
```

---

## Task 6: Golden fixtures and cross-language fixture runner

**Files:**
- Modify: `test-fixtures/engine-cases.json`
- Create: `packages/engine/src/__tests__/fixtures.test.ts`

- [ ] **Step 1: Write the fixtures** (replace `test-fixtures/engine-cases.json`)

```json
{
  "cases": [
    { "id": "fromInt-3", "op": "fromInt", "args": [3], "expected": "196608" },
    { "id": "add", "op": "add", "args": ["131072", "327680"], "expected": "458752" },
    { "id": "sub-neg", "op": "sub", "args": ["131072", "327680"], "expected": "-196608" },
    { "id": "mul-neg-half", "op": "mul", "args": ["-98304", "32768"], "expected": "-49152" },
    { "id": "div-half", "op": "div", "args": ["65536", "131072"], "expected": "32768" },
    { "id": "div-neg", "op": "div", "args": ["-65536", "131072"], "expected": "-32768" },
    { "id": "fromString-981", "op": "fromString", "args": ["9.81"], "expected": "642908" },
    { "id": "fromParts-half", "op": "fromParts", "args": [0, 1, 2], "expected": "32768" },
    { "id": "sqrt-2", "op": "sqrt", "args": ["131072"], "expected": "92681" },
    { "id": "sin-30", "op": "sinDeg", "args": ["1966080"], "expected": "32768" },
    { "id": "sin-210", "op": "sinDeg", "args": ["13762560"], "expected": "-32768" },
    { "id": "cos-60", "op": "cosDeg", "args": ["3932160"], "expected": "32768" }
  ]
}
```

Note: angle args are Fixed degrees (30 deg = `30 * 65536 = 1966080`). Verify `fromString("9.81")` expected: `9*65536 + (81*65536)/100 = 589824 + 53084 = 642908`.

- [ ] **Step 2: Write the failing test**

`packages/engine/src/__tests__/fixtures.test.ts`:

```ts
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import * as fp from '../fixedPoint.js';

type Case = { id: string; op: string; args: (string | number)[]; expected: string };

const path = fileURLToPath(new URL('../../../../test-fixtures/engine-cases.json', import.meta.url));
const { cases } = JSON.parse(readFileSync(path, 'utf8')) as { cases: Case[] };

const rawOps = new Set(['fromInt', 'fromString', 'fromParts']);

function run(c: Case): bigint {
  const fn = (fp as Record<string, (...a: never[]) => bigint>)[c.op];
  if (typeof fn !== 'function') throw new Error(`unknown op ${c.op}`);
  const args = rawOps.has(c.op)
    ? c.args
    : c.args.map((a) => BigInt(a as string));
  return fn(...(args as never[]));
}

describe('engine golden fixtures', () => {
  it('has cases', () => {
    expect(cases.length).toBeGreaterThan(0);
  });
  for (const c of cases) {
    it(`${c.id} (${c.op})`, () => {
      expect(run(c).toString()).toBe(c.expected);
    });
  }
});
```

- [ ] **Step 3: Run test to verify it fails, then passes**

Run: `corepack pnpm --filter @nodeswars/engine test`
Expected: initially FAIL if any expected value is off; fix the fixture value using the formula in its note until PASS. All fixture cases must pass.

- [ ] **Step 4: Commit**

```bash
git add test-fixtures/engine-cases.json packages/engine/src/__tests__/fixtures.test.ts
git commit -s -m "test(engine): golden fixed-point fixtures and cross-language runner"
```

---

## Task 7: Full suite, typecheck, and build gate

**Files:** none (verification only)

- [ ] **Step 1: Run the engine tests, typecheck, and build**

Run:
```
corepack pnpm --filter @nodeswars/engine test
corepack pnpm typecheck
corepack pnpm -r --if-present build
```
Expected: all pass. `dist/fixedPoint.*` and `dist/sineTable.*` regenerate.

- [ ] **Step 2: Push branch and open PR**

```bash
git push -u origin feat/engine-fixedpoint
gh pr create --base main --repo mylosol/NodesWars \
  --title "feat(engine): fixed-point foundation" \
  --body "Implements the Q-scale-2^16 fixedPoint module per docs/superpowers/specs/2026-07-15-engine-fixedpoint-design.md, with golden fixtures the PHP port must match."
```

- [ ] **Step 3: Confirm CI green on the PR before handing back.**

---

## Self-Review

**Spec coverage:** representation + SCALE (Task 1), rounding contract via mul/div (Task 2), fromParts/fromString exact constructors (Task 3), sqrt (Task 4), lookup-table trig + generator (Task 5), golden int64-string fixtures + runner (Task 6), test/typecheck/build gate (Task 7). `toNumber` fenced to display (Task 1, spec note). Safe-multiply invariant is documented in the spec; it is a caller invariant, enforced later by the coords module, so no task here. Out-of-scope items (gameplay modules, PHP port, coords) are correctly absent.

**Placeholder scan:** no TBD/TODO; every code step has complete code. `fromParts` uses a magnitude convention: `fromParts(-1, 1, 2)` is `-(1 + 1/2) = -1.5` (`-98304n`), not `-0.5`. Negative magnitudes come from a negative `int`; `num` is always non-negative.

**Type consistency:** `Fixed`, `SCALE` (bigint), `asI64`, `fromInt`, `toNumber`, `neg`, `abs`, `add`, `sub`, `cmp`, `mul`, `div`, `fromParts`, `fromString`, `isqrt`, `sqrt`, `sinDeg`, `cosDeg`, and `SINE_TABLE` names are used identically across tasks. Angle unit (Fixed degrees) is consistent between Task 5 and the Task 6 fixtures.
