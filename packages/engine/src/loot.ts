// Diminishing-returns reward multiplier. See
// docs/superpowers/specs/2026-07-15-engine-frozen-modules-design.md.
//
// Frozen: max(0, 1 - (playerLevel - 1) / 10). Level 1 = 100%, 5 = 60%, 11+ = 0%.
//
// The ERD introduces this specifically "to protect Non-Played Nodes (NPNs,
// unengaged nodes mapped by the server) from high-level farming". It is an
// anti-farming rule, not a progression brake, so it applies only to rewards
// taken from NPN targets. Hits on real players always pay in full.
//
// Scoping matters here: applied to everything, it drops XP per confirmed hit to
// 4 at level 10 while the level curve keeps climbing, which puts 53% of the
// entire grind in the final level.

import { type Fixed, div, fromInt, mul, sub } from './fixedPoint.js';

/** Who was hit. Only NPN rewards are scaled down. */
export type TargetKind = 'npn' | 'player';

/** Reward multiplier in [0, 1] for a given player level. */
export function multiplier(playerLevel: number): Fixed {
  if (!Number.isInteger(playerLevel)) throw new Error('playerLevel must be an integer');
  const raw = sub(fromInt(1), div(fromInt(playerLevel - 1), fromInt(10)));
  return (raw < 0n ? 0n : raw) as Fixed;
}

/** The multiplier actually applied, given who was hit. */
export function effectiveMultiplier(playerLevel: number, target: TargetKind): Fixed {
  if (target === 'player') return fromInt(1);
  return multiplier(playerLevel);
}

/**
 * Scale a base reward. NPN rewards decay with level; player rewards do not.
 * Defaults to 'npn', the conservative case, so a caller that forgets to say
 * cannot accidentally hand out unscaled farming rewards.
 */
export function applyReward(
  baseReward: Fixed,
  playerLevel: number,
  target: TargetKind = 'npn',
): Fixed {
  return mul(baseReward, effectiveMultiplier(playerLevel, target));
}
