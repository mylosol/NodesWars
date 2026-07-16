// 3-tier hit scoring split. See
// docs/superpowers/specs/2026-07-15-engine-frozen-modules-design.md.
// Frozen: 20% base strike, 40% suspected hit, 40% confirmed hit.

import { type Fixed, fromParts, mul, sub } from './fixedPoint.js';

export interface ScoreSplit {
  readonly base: Fixed; // 20%, locked on broadcast
  readonly suspected: Fixed; // 40%, on suspected hit
  readonly confirmed: Fixed; // remaining 40%, on confirmed hit
}

const PCT20 = fromParts(0, 20, 100);
const PCT40 = fromParts(0, 40, 100);

/** Split a move's max XP into the three tiers; they sum to exactly maxXp. */
export function split(maxXp: Fixed): ScoreSplit {
  const base = mul(maxXp, PCT20);
  const suspected = mul(maxXp, PCT40);
  const confirmed = sub(sub(maxXp, base), suspected);
  return { base, suspected, confirmed };
}
