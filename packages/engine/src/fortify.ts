// Fortify shields.
//
// A shield is an HP layer that decays on a one hour half-life and absorbs
// damage before base HP. Decay is 2^(-elapsed/halfLife), computed without
// Math.pow: the whole number of half-lives is a bit shift, and the fractional
// remainder comes from a committed table with integer interpolation. Same
// approach as the sine table, and reproducible in the PHP engine.

import { DECAY_STEPS, DECAY_TABLE } from './decayTable.js';
import { type Fixed, asI64, mul } from './fixedPoint.js';

export const HALF_LIFE_MS = 3_600_000;

// Past this many half-lives the shield has decayed below the smallest
// representable Fixed value, so shifting further is pointless.
const MAX_HALF_LIVES = 63;

const HALF_LIFE = BigInt(HALF_LIFE_MS);
const STEPS = BigInt(DECAY_STEPS);

/**
 * Fraction of a shield remaining after `elapsedMs`, as a Fixed in (0, 1].
 * Exposed on its own so the decay curve can be tested directly.
 */
export function decayFactor(elapsedMs: number): Fixed {
  if (!Number.isInteger(elapsedMs)) {
    throw new Error(`fortify: elapsedMs must be an integer, got ${elapsedMs}`);
  }
  if (elapsedMs < 0) {
    throw new Error('fortify: elapsedMs must be non-negative');
  }

  const halfLives = Math.floor(elapsedMs / HALF_LIFE_MS);
  if (halfLives > MAX_HALF_LIVES) return 0n as Fixed;

  const remainder = BigInt(elapsedMs - halfLives * HALF_LIFE_MS);

  // Position within the table, plus the leftover used to interpolate.
  const scaled = remainder * STEPS;
  const index = Number(scaled / HALF_LIFE);
  const frac = scaled % HALF_LIFE;

  const lo = BigInt(DECAY_TABLE[index]!);
  const hi = BigInt(DECAY_TABLE[index + 1]!);
  // The table descends, so interpolate downward from lo.
  const interpolated = lo - ((lo - hi) * frac) / HALF_LIFE;

  return asI64(interpolated >> BigInt(halfLives));
}

/** Shield HP remaining after `elapsedMs` of decay. */
export function remainingShield(shieldHp: Fixed, elapsedMs: number): Fixed {
  if (shieldHp < 0n) {
    throw new Error('fortify: shieldHp must be non-negative');
  }
  return mul(shieldHp, decayFactor(elapsedMs));
}

export interface DamageResult {
  readonly shieldHp: Fixed;
  readonly baseHp: Fixed;
  /** Damage that got past the shield and landed on base HP. */
  readonly spillover: Fixed;
}

/**
 * Applies `damage` to the shield first, then to base HP. Both outputs are
 * floored at zero, so overkill is discarded rather than going negative.
 */
export function applyDamage(shieldHp: Fixed, baseHp: Fixed, damage: Fixed): DamageResult {
  if (shieldHp < 0n || baseHp < 0n) {
    throw new Error('fortify: HP must be non-negative');
  }
  if (damage < 0n) {
    throw new Error('fortify: damage must be non-negative');
  }

  const absorbed = damage < shieldHp ? damage : shieldHp;
  const spillover = asI64(damage - absorbed);
  const remainingBase = baseHp - spillover;

  return {
    shieldHp: asI64(shieldHp - absorbed),
    baseHp: (remainingBase < 0n ? 0n : remainingBase) as Fixed,
    spillover,
  };
}

/** Stacks a new shield layer onto whatever is left of the old one. */
export function stack(currentShieldHp: Fixed, addedHp: Fixed): Fixed {
  if (currentShieldHp < 0n || addedHp < 0n) {
    throw new Error('fortify: shield HP must be non-negative');
  }
  return asI64(currentShieldHp + addedHp);
}
