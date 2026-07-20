// XP to level curve.
//
// Levels run 1..11. Advancing from level L to L+1 costs
// round(100 * 1.3^(L-1)) XP, an exponential curve per the frozen spec. The
// thresholds are precomputed and committed rather than exponentiated at
// runtime: there are only ten of them, and a constant table is exactly
// reproducible in the PHP engine without a fixed-point pow.
//
// This curve interacts with the frozen reward multiplier
// max(0, 1 - (level-1)/10), which scales XP as well as coins. A confirmed hit
// on a 100 XP strike is worth 40 XP at level 1 but only 4 XP at level 10, so
// the last level is roughly half the total grind. Reaching level 11 takes
// about 500 confirmed hits.

import { type Fixed, cmp, fromInt, sub } from './fixedPoint.js';

export const LEVEL_MIN = 1;
export const LEVEL_CAP = 11;

// Cumulative XP required to *be* a given level. Indexed by level, so index 0
// is unused padding. Index 1 is 0: everyone starts at level 1.
const THRESHOLDS_INT: readonly number[] = [
  0, 0, 100, 230, 399, 619, 905, 1276, 1759, 2386, 3202, 4262,
];

const THRESHOLDS: readonly Fixed[] = THRESHOLDS_INT.map((n) => fromInt(n));

function assertLevel(level: number): void {
  if (!Number.isInteger(level)) {
    throw new Error(`levelCurve: level must be an integer, got ${level}`);
  }
  if (level < LEVEL_MIN || level > LEVEL_CAP) {
    throw new Error(`levelCurve: level ${level} out of range ${LEVEL_MIN}..${LEVEL_CAP}`);
  }
}

/** Total XP required to reach `level` from zero. */
export function xpForLevel(level: number): Fixed {
  assertLevel(level);
  return THRESHOLDS[level]!;
}

/** XP required to advance from `level` to the next one. Zero at the cap. */
export function xpToNext(level: number): Fixed {
  assertLevel(level);
  if (level === LEVEL_CAP) return fromInt(0);
  return sub(THRESHOLDS[level + 1]!, THRESHOLDS[level]!);
}

/** Highest level fully paid for by `totalXp`, clamped to the cap. */
export function levelForXp(totalXp: Fixed): number {
  if (totalXp < 0n) {
    throw new Error('levelCurve: totalXp must be non-negative');
  }
  let level = LEVEL_MIN;
  for (let candidate = LEVEL_MIN + 1; candidate <= LEVEL_CAP; candidate++) {
    if (cmp(totalXp, THRESHOLDS[candidate]!) < 0) break;
    level = candidate;
  }
  return level;
}
