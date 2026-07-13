// Fixed point arithmetic primitives for deterministic engine math.
//
// The engine cannot use JS floating point for game-affecting calculations
// because rounding differs across CPUs and JIT states. All physics and
// probability math will go through this module once implemented.

export type Fixed = bigint & { readonly __brand: 'Fixed' };

export const SCALE = 1_000_000n;

export function fromInt(_n: number): Fixed {
  throw new Error('not implemented');
}

export function fromString(_s: string): Fixed {
  throw new Error('not implemented');
}

export function toNumber(_x: Fixed): number {
  throw new Error('not implemented');
}

export function add(_a: Fixed, _b: Fixed): Fixed {
  throw new Error('not implemented');
}

export function sub(_a: Fixed, _b: Fixed): Fixed {
  throw new Error('not implemented');
}

export function mul(_a: Fixed, _b: Fixed): Fixed {
  throw new Error('not implemented');
}

export function div(_a: Fixed, _b: Fixed): Fixed {
  throw new Error('not implemented');
}
