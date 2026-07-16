// Nodes Wars deterministic engine
//
// Pure TypeScript. No DOM, no network, no globals. Every function here must
// produce the same output on client and server given identical inputs.
//
// Nothing is implemented yet. Every export is a typed stub.

export * as trajectory from './trajectory.js';
export * as blast from './blast.js';
export * as loot from './loot.js';
export * as scoring from './scoring.js';
export * as levelCurve from './levelCurve.js';
export * as movePool from './movePool.js';
export * as fixedPoint from './fixedPoint.js';

export const ENGINE_VERSION = '0.0.0';
