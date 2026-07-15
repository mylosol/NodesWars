// Blast damage falloff. Stub only.

import type { Vec2 } from './trajectory.js';

export interface BlastInput {
  readonly center: Vec2;
  readonly weaponId: string;
  readonly targets: readonly Vec2[];
}

export interface BlastHit {
  readonly targetIndex: number;
  readonly damage: number;
  readonly distanceM: number;
}

export function resolve(_input: BlastInput): readonly BlastHit[] {
  throw new Error('not implemented');
}
