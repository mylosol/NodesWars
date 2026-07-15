# Deterministic engine: frozen gameplay modules

Date: 2026-07-15
Status: design for autonomous build (fully frozen formulas, no balance decisions)

## Goal

Implement the three engine modules whose rules are fully specified in the
handoff, needing no invented values: `loot`, `scoring`, and `movePool` regen.
Deferred (need user decisions): `levelCurve` (XP curve unspecified), `blast`
(weapon radius table, fortify half-life).

Built stacked on the unmerged `feat/engine-fixedpoint` branch (PR #16),
parallel to the trajectory branch (PR #17).

## loot (rewrite of the stub)

Frozen: reward multiplier `max(0, 1 - (playerLevel - 1) / 10)`.
Level 1 = 100%, level 5 = 60%, level 11+ = 0%.

```
multiplier(playerLevel: number): Fixed   // the 0..1 multiplier, clamped at 0
applyReward(baseReward: Fixed, playerLevel: number): Fixed  // mul(base, multiplier)
```

Computed in fixed point: `sub(fromInt(1), div(fromInt(level - 1), fromInt(10)))`,
clamped to `0n` when negative.

## scoring (new module)

Frozen 3-tier hit split of a move's max XP: 20% base strike, 40% suspected hit,
40% confirmed hit.

```
interface ScoreSplit { base: Fixed; suspected: Fixed; confirmed: Fixed }
split(maxXp: Fixed): ScoreSplit
```

`base = maxXp * 0.20`, `suspected = maxXp * 0.40`, and
`confirmed = maxXp - base - suspected` (the remainder, so the three tiers always
sum to exactly `maxXp` regardless of fixed-point rounding).

## movePool (rewrite of the stub)

Frozen: one move regenerates every 5 minutes; level raises the max capacity.
The per-level capacity is a balance decision (deferred), so it is a parameter
here, not a hardcoded table. Pure integer arithmetic (move counts, milliseconds).

```
const REGEN_INTERVAL_MS = 300000  // 5 minutes
regen(current: number, max: number, elapsedMs: number): number
```

`regen` returns `min(max, current + floor(elapsedMs / REGEN_INTERVAL_MS))`.
Integer division matches PHP `intdiv` for the future port. `elapsedMs < 0`
throws; the function never exceeds `max` or decreases below `current`.

## Testing

`packages/engine/src/__tests__/{loot,scoring,movePool}.test.ts` (Vitest):
- **loot:** multiplier at levels 1 (1.0), 5 (~0.6), 11 (0.0), 12 (clamped 0);
  applyReward scales a base reward.
- **scoring:** split of 100 XP gives ~20 / ~40 / ~40 and the three sum to
  exactly the input; split of an odd value still sums exactly.
- **movePool:** no time = current; enough time regenerates the right count;
  never exceeds max; negative elapsed throws.

`index.ts` gains `export * as scoring from './scoring.js'`.

## Out of scope

levelCurve, blast, fortify, the coords module, and the PHP port.
