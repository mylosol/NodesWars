<!-- Keep this short. Delete sections that do not apply. -->

## What

<!-- What does this change do, in one or two sentences? -->

## Why

<!-- Why is this change needed? Link an issue or RFC if there is one. -->

## Verify

<!-- Exact steps a reviewer can run to convince themselves this works. -->

## Impact Checklist

- [ ] Ledger schema changed
- [ ] Wire format changed
- [ ] Security sensitive (auth, signatures, session keys, secrets)
- [ ] Migrations added under `db/migrations/`
- [ ] Docs updated (README, RFC, or inline)

## Sign Off

- [ ] Commits are signed off (`git commit -s`)
- [ ] `pnpm typecheck && pnpm test` passes
- [ ] `cd apps/api && ./vendor/bin/phpunit` passes
