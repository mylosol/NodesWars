# Deterministic engine: trajectory module

Date: 2026-07-15
Status: design for autonomous build; assumptions flagged for user review

## Goal

Implement `packages/engine/src/trajectory.ts` — the vacuum-parabola artillery
solver — on top of `fixedPoint`. Given a shot (velocity, elevation angle,
compass direction), return the impact point as a local-meter offset from the
launch point, the flight time, and the apogee. Pure and deterministic.

Built stacked on the unmerged `feat/engine-fixedpoint` branch (PR #16).

## Frozen formula (from the ERD)

Position at time t, with elevation `angle`, bearing `direction`, speed `v`,
gravity `g`:

```
x = v * cos(angle) * cos(direction) * t
y = v * cos(angle) * sin(direction) * t
z = v * sin(angle) * t - 0.5 * g * t^2
```

## Derived results

The shell lands when `z` returns to launch altitude (`z = 0`), at

```
flightTime = 2 * v * sin(angle) / g
```

Impact offset (local meters from the launch point):

```
horiz    = v * cos(angle) * flightTime
impact.x = horiz * cos(direction)
impact.y = horiz * sin(direction)
```

Apogee (max height):

```
apogee = (v * sin(angle))^2 / (2 * g)
```

Sanity check: `v = 100`, `angle = 45°` gives range ≈ 1019 m, apogee ≈ 254.8 m,
flightTime ≈ 14.42 s — consistent with the ~1 km fog radius.

## Assumptions (flagged for review)

1. **`g = 9.81 m/s²`**, exported as `GRAVITY = fromString('9.81')`. A `compute`
   parameter lets callers override it; changing the default is one line.
2. **Flat-ground impact:** launch and impact share an altitude, so
   `flightTime = 2 v sin(angle) / g`. Terrain elevation is not modeled in v1.
3. **Units:** `velocity` is treated as m/s and all distances are meters, times
   seconds. The frozen formula is applied literally.
4. **Output is a local-meter offset**, not lat/lon. Converting to a GPS impact
   point is the future `coords` module's job. This intentionally replaces the
   stub's `LatLon` API, per the approved fixed-point design.
5. **Blast radius is out of scope** — it belongs to the `blast` module.

## API

```
interface Vec2 { readonly x: Fixed; readonly y: Fixed } // local meters

interface TrajectoryInput {
  readonly velocity: Fixed;      // muzzle speed, m/s
  readonly angleDeg: Fixed;      // elevation, degrees (domain [0, 90])
  readonly directionDeg: Fixed;  // bearing, degrees (any, normalized by trig)
}

interface TrajectoryResult {
  readonly impact: Vec2;         // offset from launch point, meters
  readonly flightTimeS: Fixed;   // seconds
  readonly apogeeM: Fixed;       // meters
}

const GRAVITY: Fixed;            // 9.81
function compute(input: TrajectoryInput, gravity?: Fixed): TrajectoryResult;
```

Domain note: `angleDeg` outside `[0, 90]` still evaluates per the formula (e.g.
`angle = 0` yields `flightTime = 0` and a zero-offset impact). Callers validate
input ranges; the engine stays pure.

Safe-multiply invariant holds: all intermediates (`v·cos ≤ 100`,
`flightTime ≤ ~20`, `horiz ≤ ~2000`) stay well under ±46,340.

## Testing

- `packages/engine/src/__tests__/trajectory.test.ts` (Vitest):
  - Physics via `toNumber` with a 1 m / 0.1 s tolerance: 45°/dir 0 (range,
    apogee, flight time, `y ≈ 0`); 30°/dir 90 (`x ≈ 0`, `y ≈ range`);
    `angle = 0` (zero flight, zero impact); direction wrap (dir 360 ≡ dir 0).
  - Determinism is inherited from `fixedPoint` (all ops are exact integer
    composition). Cross-language golden fixtures for trajectory's struct output
    land with the PHP port, when the fixture schema gains struct support.

## Out of scope

Blast, fortify, loot, movePool, levelCurve, the `coords` lat/lon conversion,
and the PHP port.
