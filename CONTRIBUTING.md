# Contributing

This is a solo project, so this document is mostly a note to future self.

## Dev Environment

See README for prerequisites (Node 20, pnpm 9, PHP 8.2 plus, Composer, Postgres 15 plus).

## Testing

- TypeScript: `pnpm test` runs Vitest across all packages.
- PHP: `cd apps/api && ./vendor/bin/phpunit`.
- Add regression tests for any bug you fix.
- Golden vectors for the engine and wire format live in `test-fixtures/`.

## Commit Style

Use Conventional Commits.

```
feat: add BLE reconnect backoff
fix(engine): correct blast radius rounding
docs: expand SECURITY.md
chore(deps): bump vite to 5.4
```

Types: `feat`, `fix`, `docs`, `refactor`, `test`, `chore`, `perf`, `build`, `ci`.

## DCO Sign Off

Every commit must be signed off:

```bash
git commit -s -m "feat: ..."
```

That appends a `Signed-off-by:` trailer, which asserts the Developer Certificate of Origin.

## Pull Request Checklist

Before opening a PR:

- Tests pass locally (`pnpm test`, PHPUnit).
- `pnpm typecheck` clean.
- `pnpm lint` clean.
- If you changed ledger schema, wire format, or a migration, call it out in the PR description.
- If a change touches auth, signatures, or session keys, run through SECURITY.md.
