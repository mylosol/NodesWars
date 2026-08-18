# Nodes Wars

> **PROPRIETARY.** Copyright (c) 2026 Robert (mylosol). All Rights Reserved. See [LICENSE](LICENSE).

Nodes Wars is a mobile-first PWA game that uses LoRa mesh radios (Meshtastic hardware) for asynchronous, location-based peer-to-peer combat. Players fire artillery strikes at map coordinates; hits are verified off-grid through a signed, append-only ledger. A PHP plus PostgreSQL backend is the strict authoritative source of truth for match state, ledgers, and rewards.

The defining constraint is **determinism across two languages**: strike resolution runs on the client in TypeScript and is re-verified on the server in PHP, and both must produce byte-identical results or the hash-chained ledger stops validating. The shared engine and its golden fixtures exist to guarantee that.

## Tech Stack

- **Frontend PWA** (`apps/pwa`): TypeScript 5.9, Vite 8, React 19, Tailwind, Zustand 5, Framer Motion, Leaflet, Workbox via `vite-plugin-pwa`, `@meshtastic/js` for BLE, `@noble/curves` + `@noble/hashes` for Ed25519, `cbor-x`, `dexie`, Sentry. Tests on Vitest 4.
- **Backend API** (`apps/api`): PHP 8.2+, Slim 4, PDO for PostgreSQL, `ext-sodium` for Ed25519, PHP-DI, Monolog, Sentry. PHPUnit, PHPStan level 6, PHP CS Fixer.
- **Shared engine** (`packages/engine`): pure deterministic TypeScript, no DOM, no network. Fixed-point arithmetic (signed 64-bit, scale 2^16), truncate-toward-zero everywhere, integer lookup tables for trig and decay. Consumed by the PWA via TypeScript project references and re-implemented in PHP under `apps/api/src/Engine`.
- **Database**: PostgreSQL. Ordered SQL migrations in `db/migrations`. Requires the `pgcrypto` extension for `gen_random_uuid()`.

## The deterministic engine

`packages/engine` (TypeScript) and `apps/api/src/Engine` (PHP) are two implementations of the same game math. They must agree exactly.

- **Fixed-point**: a value is a signed 64-bit integer equal to `round(real * 2^16)`. Every division truncates toward zero (`BigInt /` and PHP `intdiv`); this is the cross-language parity contract. No `Math.sin`/`cos`/`pow` — trig comes from a committed integer sine table with interpolation, shield decay from a committed table plus a bit shift.
- **Modules**: `trajectory` (vacuum-parabola solver), `blast` (geometry + per-weapon damage and falloff), `fortify` (decaying stackable shields), `loot`, `scoring`, `levelCurve`, `movePool`, `fixedPoint`.
- **Weapons are data**: `data/weapons.json` is the single source of truth, compiled by `pnpm gen:weapons` into `packages/engine/src/weapons.generated.ts` and `apps/api/src/Engine/Weapons.php`. CI fails on drift; never edit the generated files by hand.
- **Golden fixtures**: `test-fixtures/engine-cases.json` holds 183 cases run identically by both engines. A disagreement means the engines have diverged and the game is no longer deterministic.

## The ledger (server authoritative)

`apps/api/src/Ledger` is the append-only, hash-chained ledger that verifies gameplay.

- **`LedgerBlock`**: an immutable signed block. Canonical preimage (what gets hashed and signed): `prevHash || seqNo(u64 BE) || lamportTs(u64 BE) || payload`, BLAKE2b chain hash, detached Ed25519 signature. Genesis is `seqNo 0`.
- **`ChainValidator`**: enforces the per-player hash chain and signature.
- **`ForkResolver`**: resolves equivocation via Lamport timestamp with hash tiebreak; a player who submits two blocks at the same `seqNo` has both discarded and their downstream state rolled back.
- **`LedgerRepository` / `LedgerController`**: persistence (Postgres) and the HTTP surface.

HTTP routes (Slim):

```
GET    /healthz                                          liveness, never touches the DB
POST   /matches/{matchId}/ledger/blocks                  submit a signed block
GET    /matches/{matchId}/ledger/blocks                  list a match's blocks
DELETE /matches/{matchId}/players/{playerId}/ledger      roll back a player (equivocation)
```

When `DATABASE_URL` is unset the app still boots and `/healthz` works; ledger routes return `503` with a JSON error. The deploy health probe depends on `/healthz`, so `/healthz` must never depend on the DB.

## Dev Environment Setup

1. Node 22 via nvm: `nvm use` in the repo root picks up `.nvmrc`.
2. pnpm 9 via corepack: `corepack enable`. (A bare `pnpm` is often not on PATH; prefer `corepack pnpm ...`.)
3. PHP 8.2+ with `ext-pdo_pgsql` and `ext-sodium` enabled.
4. Composer 2+.
5. PostgreSQL running locally, with the `pgcrypto` extension available.

## Commands

```bash
pnpm install                                    # install workspace deps
pnpm dev                                        # run PWA dev server
pnpm test                                       # unit tests (Vitest, all packages)
pnpm typecheck                                  # TypeScript project references
pnpm lint                                       # ESLint
pnpm format:check                               # Prettier
pnpm build                                      # production build
pnpm gen:weapons                                # regenerate weapon tables from data/weapons.json
pnpm gen:weapons:check                          # fail if the generated tables are stale (CI)

cd apps/api && composer install                 # install PHP deps
./vendor/bin/phpunit                            # PHP tests
./vendor/bin/phpstan analyse                    # static analysis (level 6)
./vendor/bin/php-cs-fixer fix --dry-run --diff  # style check
```

The PHP ledger integration tests need a real Postgres and are skipped otherwise. Point them at a database with:

```bash
NODESWARS_PG_DSN="pgsql:host=127.0.0.1;port=5432;dbname=nodeswars_test" ./vendor/bin/phpunit
```

## Repo Layout

```
NodesWars/
├── apps/
│   ├── pwa/          Vite + React + TS PWA
│   └── api/          Slim 4 PHP API (src/Engine, src/Ledger)
├── packages/
│   └── engine/       shared deterministic engine (TypeScript)
├── data/
│   └── weapons.json  source of truth for the weapon roster
├── db/migrations/    ordered, forward-only SQL migrations
├── test-fixtures/    golden vectors shared by both engines
├── scripts/          weapon codegen, bundle-size check, diagnostics
├── docs/             design specs and notes
└── .github/          workflows, issue templates, CODEOWNERS
```

## Applying Migrations

Forward-only SQL, applied in numeric order. There is no local runner; apply by hand (see `db/README.md`):

```bash
export DATABASE_URL="postgres://user:pass@localhost:5432/nodeswars"
for f in $(ls db/migrations/*.sql | sort); do
  psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f "$f"
done
```

In production, migrations are uploaded and applied on the host as part of the deploy workflow (idempotent; use `IF NOT EXISTS` guards).

## CI and Deployment

- **CI** (`.github/workflows/ci.yml`): the TypeScript job runs weapon-drift check, typecheck, lint, prettier, tests, build, and a bundle-size budget; the PHP job runs PHPUnit, PHPStan level 6, and PHP CS Fixer.
- **Deploy**: GitHub Actions mirrors built artifacts to WHM shared hosting over **SFTP via lftp** (this host has no rsync), then applies DB migrations on the host over the same SSH channel. Deploy keys and DB credentials live in the `preview` or `production` GitHub Environment. Preview deploys from PRs labeled `deploy-preview`; production deploys via `workflow_run` gated on CI success on `main`, so a red `main` blocks the deploy rather than shipping. Nothing deploys from local checkouts. The API `.env` on the host is never overwritten by a deploy.

## License and Use

**Source-available for reference only. This is not open source.**

Copyright (c) 2026 Robert (mylosol). All Rights Reserved. See [LICENSE](LICENSE).

The source is public so it can be read, but no rights are granted to use, copy, modify, fork, redistribute, or ship any part of it. Contributions are not accepted, and pull requests will not be merged. If you want to use any of this, contact the copyright holder for written permission.
