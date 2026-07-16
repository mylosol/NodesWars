// Diminishing-returns reward multiplier. See
// docs/superpowers/specs/2026-07-15-engine-frozen-modules-design.md.
// Frozen: max(0, 1 - (playerLevel - 1) / 10). Level 1 = 100%, 5 = 60%, 11+ = 0%.

import { type Fixed, div, fromInt, mul, sub } from './fixedPoint.js';

/** Reward multiplier in [0, 1] for a given player level. */
export function multiplier(playerLevel: number): Fixed {
  if (!Number.isInteger(playerLevel)) throw new Error('playerLevel must be an integer');
  const raw = sub(fromInt(1), div(fromInt(playerLevel - 1), fromInt(10)));
  return (raw < 0n ? 0n : raw) as Fixed;
}

/** Scale a base reward by the level multiplier. */
export function applyReward(baseReward: Fixed, playerLevel: number): Fixed {
  return mul(baseReward, multiplier(playerLevel));
}
