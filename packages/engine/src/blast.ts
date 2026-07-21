// Blast geometry.
//
// Each weapon has a fixed blast radius per the frozen spec. This module answers
// one question only: how far was each target from the impact point, and did
// that fall inside the radius. Damage magnitude is deliberately not modelled
// here, because per-weapon damage values are not specified anywhere yet.
//
// Radii start at 15 m. GPS accuracy is roughly 5-10 m, so the smallest tier is
// close to the noise floor and near-misses on a poor fix will be contentious.

import { type Fixed, add, cmp, fromInt, mul, sqrt, sub } from './fixedPoint.js';
import type { Vec2 } from './trajectory.js';

export type WeaponId = 'scout' | 'light' | 'medium' | 'heavy' | 'siege';

const RADIUS_M_INT: Readonly<Record<WeaponId, number>> = {
  scout: 15,
  light: 25,
  medium: 50,
  heavy: 100,
  siege: 200,
};

export const BLAST_RADIUS_M: Readonly<Record<WeaponId, Fixed>> = Object.freeze(
  Object.fromEntries(Object.entries(RADIUS_M_INT).map(([id, m]) => [id, fromInt(m)])) as Record<
    WeaponId,
    Fixed
  >,
);

export function isWeaponId(id: string): id is WeaponId {
  return Object.prototype.hasOwnProperty.call(RADIUS_M_INT, id);
}

export function radiusFor(weaponId: WeaponId): Fixed {
  const radius = BLAST_RADIUS_M[weaponId];
  if (radius === undefined) {
    throw new Error(`blast: unknown weapon ${weaponId}`);
  }
  return radius;
}

export interface BlastInput {
  /** Impact point, local-meter offset, as produced by trajectory.compute. */
  readonly center: Vec2;
  readonly weaponId: WeaponId;
  readonly targets: readonly Vec2[];
}

export interface BlastHit {
  readonly targetIndex: number;
  readonly distanceM: Fixed;
  readonly withinRadius: boolean;
}

/** Straight-line distance between two local-meter points. */
export function distance(a: Vec2, b: Vec2): Fixed {
  const dx = sub(a.x, b.x);
  const dy = sub(a.y, b.y);
  return sqrt(add(mul(dx, dx), mul(dy, dy)));
}

/** Distance and hit flag for every target, in input order. */
export function resolve(input: BlastInput): readonly BlastHit[] {
  const radius = radiusFor(input.weaponId);
  return input.targets.map((target, targetIndex) => {
    const distanceM = distance(input.center, target);
    // Exactly on the radius counts as a hit.
    return { targetIndex, distanceM, withinRadius: cmp(distanceM, radius) <= 0 };
  });
}
