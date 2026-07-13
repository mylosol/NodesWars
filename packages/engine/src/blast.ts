// Blast damage falloff. Stub only.

import type { LatLon } from './trajectory.js';

export interface BlastInput {
  readonly center: LatLon;
  readonly weaponId: string;
  readonly targets: readonly LatLon[];
}

export interface BlastHit {
  readonly targetIndex: number;
  readonly damage: number;
  readonly distanceM: number;
}

export function resolve(_input: BlastInput): readonly BlastHit[] {
  throw new Error('not implemented');
}
