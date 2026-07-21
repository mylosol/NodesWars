# Nodes Wars — Handoff to Claude Code

**Read this document in full before doing anything else.**

## Why you're reading this

This project was planned and scaffolded in a Cowork session on 2026-07-13. That session hit a hard sandbox limitation: `api.github.com` was blocked at the proxy, which prevented full agentic GitHub workflow. Work is handed off to Claude Code, which runs on the user's local machine with `gh` CLI already authenticated as the user (`mylosol`).

All architectural decisions below are **frozen**. Do not re-litigate them. If a decision seems wrong, raise it explicitly with the user before changing.

## Project overview

Nodes Wars is a **mobile-first Progressive Web App** (Android only for v1) that runs an asynchronous, turn-based, location-based tactical strategy game. Players carry a **Meshtastic LoRa radio node** paired to their phone over BLE. The radio handles GPS and background packet caching; the phone drives the map, the UI, and the local cryptographic ledger.

The full original ERD is at `docs/spec/original-erd.md`. Read it after this handoff doc.

## Locked architectural decisions

### Backend as strict source of truth

The backend is authoritative. Client actions fire optimistically for instant UX (LoRa animation), but the server's ledger is truth. Cellular reconciles offline queues when connectivity returns. This flipped the original spec's "no central real-time DB" language.

### Hosting

- **PWA + PHP backend:** WHM shared hosting (already paid, matches user's PayTracker pattern)
- **DNS + registrar:** Cloudflare (optional proxy for DDoS/CDN)
- **Preview:** `preview.nodeswars.com`, deploy path `/home/lietmbdn/domains/preview.nodeswars.com/public_html`, single shared environment (latest PR wins)
- **Deploy method:** rsync over SSH from GitHub Actions

### Tech stack — frozen

**Frontend (`apps/pwa/`):**

- TypeScript strict, Vite, React 18
- Tailwind, Zustand, Framer Motion, Leaflet
- Workbox service worker (via `vite-plugin-pwa`)
- `@meshtastic/js` for BLE bridge (in Web Worker only)
- `@noble/curves` for Ed25519
- `cbor-x` for CBOR wire format
- `dexie` for IndexedDB (outbox pattern)
- `@sentry/react`

**Backend (`apps/api/`):**

- PHP 8.x + Slim 4 + PSR-4 via Composer
- PDO for PostgreSQL
- `ext-sodium` for Ed25519 verification
- `sentry/sentry-php`
- PHPUnit + PHPStan level 6 + PHP CS Fixer

**Shared (`packages/engine/`):**

- Pure TypeScript deterministic engine, no DOM, no network
- Fixed-point Q32.32 arithmetic (both TS and PHP must produce byte-identical results)
- Consumed by `apps/pwa` via TypeScript project references
- **Golden fixture tests at `test-fixtures/engine-cases.json` must pass identically on both TS and PHP engines**

### Gameplay math — frozen

- **Trajectory:** vacuum parabola in Q32.32. Formula: `x = v*cos(angle)*cos(direction)*t`, `y = v*cos(angle)*sin(direction)*t`, `z = v*sin(angle)*t - 0.5*g*t^2`
- **Blast:** fixed radius per weapon
- **Fortify:** stacking shield HP layer with decay half-life; damage hits shield before base HP
- **Loot multiplier:** `max(0, 1 - (playerLevel - 1) / 10)`
- **3-tier XP scoring:** 20% base strike, 40% suspected hit, 40% confirmed hit
- **Move pool regen:** 5 minutes per move
- **Packet TTL:** 5 minutes

### Crypto — frozen

- **Payload encryption:** AES-GCM 256 via Web Crypto API
- **Signing:** Ed25519 via `@noble/curves` client-side, `ext-sodium` server-side
- **Replay protection:** per-player incrementing nonces
- **Session keys:** server-owned so GM can leave mid-match. Distributed via QR code (encodes match ID + session key + join token).

### Wire format — frozen

- **Structured payloads (strikes, bounties):** CBOR with positional array encoding (no field names). Target: under 100 bytes including 64-byte signature.
- **Heartbeats:** fixed 16-byte binary struct, no CBOR overhead.
- **LoRa channel:** private portnum `2026` (keeps LongFast clean).

### Ledger — frozen

- **Client:** IndexedDB outbox for pending blocks + read cache for server-confirmed blocks. Not authoritative.
- **Server:** append-only Postgres `ledger_blocks` table. Hash chain per player enforced via `prev_hash` check constraint. Unique constraint on `(match_id, player_id, seq_no)`.
- **Fork resolution:** server-side, Lamport timestamp with hash tiebreak. A player who submits two blocks at the same `seq_no` gets **both discarded** and downstream state rolled back for that player only (equivocation slashing).

### Identity — frozen

- Email + magic link for account creation
- Per-install Ed25519 keypair; public key registered with server on first login
- Lose phone = generate new keypair, register against same account
- No cross-device identity sync in v1

### Match lifecycle — frozen

- GM creates match via API
- GM invites players via QR code (contains match ID, session key, join token)
- Server times match end
- GM can leave mid-match without breaking anything (server-owned session key)

### License & repo visibility — frozen

- **License:** proprietary, all rights reserved (`LICENSE` file). Do not use any OSI license.
- **Repo visibility:** private (private on GitHub free plan means no branch protection enforcement; that's acceptable for v1 solo dev; upgrade to GitHub Pro when a first collaborator joins)
- **No open source contributions accepted** — repo stays closed through v1 and initial IAP monetization

## What's already done

- Full monorepo scaffolded at `D:\Claude Code\NodesWars`
- pnpm workspaces, apps (pwa + api), packages (engine), db/migrations, test-fixtures, docs
- All tooling configured: TypeScript strict, Vite, Vitest, ESLint, Prettier, PHP CS Fixer, PHPStan, PHPUnit
- Placeholder tests that pass in TS side (`pnpm test` green)
- Six GitHub Actions workflows: ci, preview-deploy, production-deploy, nightly, auto-fix, release
- Governance files: LICENSE (proprietary), NOTICE, README, CONTRIBUTING, SECURITY, CHANGELOG, CODEOWNERS, PR template, issue templates, dependabot config
- `.gitignore` excludes `docs/qa/test-plan.md` (this file must never be committed)
- Initial commit `chore: initial scaffold` pushed to `main` on `github.com/mylosol/NodesWars`

## Current state / open issues

1. **All six workflow runs failed** on the initial push. Not yet diagnosed. **This is your first task.** Use `gh run list --limit 10` and `gh run view <id> --log-failed`.
2. **Uncommitted diff to `apps/pwa/package.json`** — 2 lines, likely a version pin from the scaffold session. Inspect and either include in your first fix commit or revert.
3. **Sentry projects:** user was in the middle of creating two projects (`nodeswars-pwa`, `nodeswars-api`). Ask before assuming they exist.
4. **WHM SSH secrets:** not configured yet. GitHub Environments `preview` and `production` need `WHM_HOST`, `WHM_USER`, `WHM_SSH_KEY`, and per-environment path secrets. User was going to add these once the repo existed.
5. **API URL structure undecided:** subdomain (`api.nodeswars.com`) vs subfolder (`nodeswars.com/api`). **Ask user which they want** before finalizing deploy workflow paths. My recommendation is subdomain (cleaner, no rewrite rules).

## What you should do first

1. Read this entire document
2. Read `docs/spec/original-erd.md`
3. Run `git status` from the repo root
4. Run `gh run list --limit 10 --repo mylosol/NodesWars`
5. For each unique failure, run `gh run view <id> --log-failed --repo mylosol/NodesWars`
6. Ask the user about API URL structure (subdomain vs subfolder) before touching deploy workflows
7. Create a fix branch: `git checkout -b fix/initial-workflow-failures`
8. Patch what needs patching
9. Commit with DCO sign-off (`git commit -s`) using Conventional Commits format
10. Push and open PR: `gh pr create --base main --title "fix: initial workflow failures" --body "..."`
11. Iterate until CI is green

## Development workflow going forward

- All changes on feature branches, never direct-push to main
- Conventional Commits format for messages (`feat:`, `fix:`, `chore:`, `docs:`, etc.)
- `-s` sign-off flag on every commit (DCO)
- One PR per logical change
- CI must pass before merge
- User reviews and merges

## User preferences (Robert / mylosol)

- **Concise, direct responses.** Cut unnecessary explanation. If you can remove words and keep the meaning, remove them.
- **No hyphens in ordinary prose.** Kebab-case identifiers, package names, and code are fine. But avoid hyphens in English sentences (write "server side" not "server-side", "off grid" not "off-grid"). This applies to chat responses and to documentation like READMEs.
- **Ask a clarifying question before starting substantial work.** Don't guess when the answer would change architecture.
- **Instruct where to find settings.** When explaining a GUI action, always say where the button is ("in the left sidebar, click Settings, then...").

## Suggested implementation order after CI is green

This is a rough sequence for after you clear the failing-workflow work. Don't execute this yet; wait for user go-ahead per phase.

1. Deterministic engine — implement `trajectory.ts`, `blast.ts`, `loot.ts`, `movePool.ts`, `levelCurve.ts`, `fixedPoint.ts`. Populate `test-fixtures/engine-cases.json` with cases. Vitest tests pass.
2. PHP port of the engine. Same golden fixtures. Cross-language byte-identical verification runs in CI.
3. Postgres schema refinement (add constraints, indices, materialized views).
4. Player identity & auth: email magic link, Ed25519 registration, session tokens.
5. Match lifecycle API: create, join, close.
6. Ledger API: submit block, fetch blocks since seq_no, fork detection.
7. Wire format: CBOR encoder/decoder on client, contract tests against golden binary fixtures.
8. LoRa BLE bridge: `@meshtastic/js` in Web Worker, ring buffer drain, outbox integration.
9. UI: map, fog of war, artillery pane, tactical hooks.
10. Service worker for offline install.
11. QA plan (`docs/qa/test-plan.md` — gitignored, ELI5 tone).

## Things you must NOT do

- Do not commit `docs/qa/test-plan.md`. It is gitignored on purpose.
- Do not add an OSI license. Repo stays proprietary.
- Do not use `Math.sin`/`Math.cos`/`Math.pow` in the deterministic engine. Fixed-point arithmetic only.
- Do not send enemy positions to the client from the API. Only "hit / suspected / missed" responses. This is the anti-cheat boundary.
- Do not put game logic in the sandbox / PWA UI layer. It must live in `packages/engine/` and be pure.
- Do not attempt to serve the PWA from mesh IPs. Everything runs from `https://nodeswars.com` via service worker cache.
- Do not scaffold anything iOS-specific. Android only for v1.
- Do not open source anything or accept external contributions in v1.

## Reference: file paths on user's machine

- Repo root: `D:\Claude Code\NodesWars\`
- Original ERD: `docs/spec/original-erd.md`
- This doc: `docs/HANDOFF.md`
- QA plan (gitignored): `docs/qa/test-plan.md`

---

_End of handoff. Good luck._
