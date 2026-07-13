# Nodes Wars

> **PROPRIETARY.** Copyright (c) 2026 Robert (mylosol). All Rights Reserved. See LICENSE.

Nodes Wars is a mobile PWA game that uses LoRa mesh radios (Meshtastic hardware) for peer to peer combat. A PHP plus PostgreSQL backend is the strict authoritative source of truth for match state, ledgers, and rewards.

## Tech Stack

- **Frontend PWA** (`apps/pwa`): TypeScript, Vite, React 18, Tailwind, Zustand, Framer Motion, Leaflet, Workbox via `vite-plugin-pwa`, `@meshtastic/js` for BLE, `@noble/curves` for Ed25519, `cbor-x`, `dexie`, Sentry.
- **Backend API** (`apps/api`): PHP 8.x, Slim 4, PDO for PostgreSQL, `ext-sodium` for Ed25519, PHPUnit, PHPStan level 6, PHP CS Fixer, Sentry.
- **Shared engine** (`packages/engine`): pure deterministic TypeScript, no DOM, no network. Consumed by the PWA via TypeScript project references.
- **Database**: PostgreSQL 15 or later. Simple SQL migrations in `db/migrations`.

## Dev Environment Setup

1. Node 20 via nvm. Use `nvm use` in the repo root to pick up `.nvmrc`.
2. pnpm 9 or later: `npm i -g pnpm`.
3. PHP 8.2 or later with `ext-pdo_pgsql` and `ext-sodium` enabled.
4. Composer 2 or later.
5. PostgreSQL 15 or later, running locally.

## Commands

```bash
pnpm install                                    # install workspace deps
pnpm dev                                        # run PWA dev server
pnpm test                                       # unit tests (Vitest)
pnpm typecheck                                  # TypeScript project references
pnpm build                                      # production build

cd apps/api && composer install                 # install PHP deps
./vendor/bin/phpunit                            # PHP tests
./vendor/bin/phpstan analyse                    # static analysis
./vendor/bin/php-cs-fixer fix --dry-run         # style check
```

## Repo Layout

```
NodesWars/
├── apps/
│   ├── pwa/        Vite React TS PWA
│   └── api/        Slim 4 PHP API
├── packages/
│   └── engine/     shared deterministic engine
├── db/migrations/  SQL migrations
├── test-fixtures/  golden vectors for engine and wire format
├── docs/           RFCs and QA notes
├── scripts/        local automation
└── .github/        workflows, issue templates, CODEOWNERS
```

## Applying Migrations

There is no migration runner. Apply files in order:

```bash
psql "$DATABASE_URL" -f db/migrations/0001_players.sql
psql "$DATABASE_URL" -f db/migrations/0002_matches.sql
# ...and so on, in numeric order
```

## Deployment

Deploys happen from GitHub Actions. The workflow rsyncs the built artifacts to WHM over SSH using a deploy key stored in the `preview` or `production` GitHub Environment. Nothing deploys automatically from local checkouts.
