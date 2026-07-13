// Projectile trajectory math. Stub only.

export interface LatLon {
  readonly lat: number;
  readonly lon: number;
}

export interface TrajectoryInput {
  readonly origin: LatLon;
  readonly bearingDeg: number;
  readonly powerPct: number;
  readonly windMs: number;
  readonly windBearingDeg: number;
}

export interface TrajectoryResult {
  readonly impact: LatLon;
  readonly flightTimeMs: number;
  readonly apogeeM: number;
}

export function compute(_input: TrajectoryInput): TrajectoryResult {
  throw new Error('not implemented');
}
