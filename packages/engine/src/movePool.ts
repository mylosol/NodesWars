// Move-pool regeneration. See
// docs/superpowers/specs/2026-07-15-engine-frozen-modules-design.md.
// Frozen: one move regenerates every 5 minutes; level raises the max capacity
// (the cap is a caller-supplied balance value, not hardcoded here).
// Integer arithmetic only; integer division matches PHP intdiv for the port.

export const REGEN_INTERVAL_MS = 300_000; // 5 minutes

/** Moves available after elapsedMs, capped at max and never below current. */
export function regen(current: number, max: number, elapsedMs: number): number {
  if (elapsedMs < 0) throw new Error('elapsedMs must be non-negative');
  const gained = Math.floor(elapsedMs / REGEN_INTERVAL_MS);
  return Math.min(max, current + gained);
}
