// Blast geometry and damage.
//
// The weapon roster lives in data/weapons.json and is compiled into
// weapons.generated.ts. Adding, removing or retuning a weapon is a data edit,
// not a code edit.
//
// Radii start at 15 m. GPS accuracy is roughly 5-10 m, so the smallest tier is
// close to the noise floor and near-misses on a poor fix will be contentious.

import { type Fixed, add, cmp, div, fromInt, mul, sqrt, sub } from './fixedPoint.js';
import type { Vec2 } from './trajectory.js';
import { WEAPONS, type WeaponId, type WeaponSpec } from './weapons.generated.js';

export { WEAPONS, WEAPON_IDS } from './weapons.generated.js';
export type { WeaponId, WeaponSpec, Falloff } from './weapons.generated.js';

export function isWeaponId(id: string): id is WeaponId {
  return Object.prototype.hasOwnProperty.call(WEAPONS, id);
}

export function specFor(weaponId: WeaponId): WeaponSpec {
  const spec = WEAPONS[weaponId];
  if (spec === undefined) {
    throw new Error(`blast: unknown weapon ${weaponId}`);
  }
  return spec;
}

export function radiusFor(weaponId: WeaponId): Fixed {
  return fromInt(specFor(weaponId).blastRadiusM);
}

export function damageFor(weaponId: WeaponId): Fixed {
  return fromInt(specFor(weaponId).damage);
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
  /** Zero outside the radius. */
  readonly damage: Fixed;
}

/** Straight-line distance between two local-meter points. */
export function distance(a: Vec2, b: Vec2): Fixed {
  const dx = sub(a.x, b.x);
  const dy = sub(a.y, b.y);
  return sqrt(add(mul(dx, dx), mul(dy, dy)));
}

/**
 * Damage a weapon deals at `distanceM` from the impact point. Zero outside the
 * radius; exactly on the radius still counts as a hit, though linear falloff
 * makes that worth nothing.
 */
export function damageAt(weaponId: WeaponId, distanceM: Fixed): Fixed {
  if (distanceM < 0n) throw new Error('blast: distance must be non-negative');

  const spec = specFor(weaponId);
  const radius = fromInt(spec.blastRadiusM);
  if (cmp(distanceM, radius) > 0) return fromInt(0);

  const full = fromInt(spec.damage);
  if (spec.falloff === 'flat') return full;

  // Linear: full damage at the centre, zero at the edge.
  const remaining = sub(fromInt(1), div(distanceM, radius));
  return mul(full, remaining);
}

/** Distance, hit flag and damage for every target, in input order. */
export function resolve(input: BlastInput): readonly BlastHit[] {
  const radius = radiusFor(input.weaponId);
  return input.targets.map((target, targetIndex) => {
    const distanceM = distance(input.center, target);
    // Exactly on the radius counts as a hit.
    const withinRadius = cmp(distanceM, radius) <= 0;
    return {
      targetIndex,
      distanceM,
      withinRadius,
      damage: damageAt(input.weaponId, distanceM),
    };
  });
}
