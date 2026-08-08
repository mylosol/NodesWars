# Phase 2 — Ledger Domain Design (server side, PHP)

Date: 2026-08-07
Status: Implemented (PHP), TS port pending
Author: Hermes Agent (takeover from Claude Code)

## Why this document exists

Phase 2 (the server side ledger) needs a byte level encoding contract before
the TypeScript client ledger can be written. The project's defining rule is
determinism across two languages: whatever the PHP server hashes and verifies,
the TypeScript client must reproduce exactly. This document pins the canonical
encoding so the TS port has a target.

## Scope

Implemented in this slice, all under `apps/api/src/Ledger/`:

| Class            | Responsibility                                                                      |
| ---------------- | ----------------------------------------------------------------------------------- |
| `LedgerBlock`    | Immutable signed block, canonical preimage, hash, genesis flag                      |
| `Ed25519`        | Sign/verify wrappers over ext-sodium                                                |
| `ChainValidator` | Per player chain rules (prevHash link, seqNo +1, Lamport non decreasing, signature) |
| `ForkResolver`   | Same seqNo conflict: Lamport timestamp, hash tiebreak, equivocation slashing        |

Schema already exists (`db/migrations/0004_ledger_blocks.sql`), unchanged.

## The canonical preimage (the parity contract)

A block's signature and chain hash both cover the same bytes:

```
prevHash(64 hex chars) || seqNo(u64 BE) || lamportTs(u64 BE) || payload(raw)
```

- `prevHash` is hex text (64 chars), NOT raw bytes. It is the previous
  block's BLAKE2b 512 hash, hex encoded.
- `seqNo` and `lamportTs` are unsigned 64 bit, big endian (`pack('J')`).
- `payload` is the raw CBOR action payload bytes, appended verbatim.

Block hash = `BLAKE2b(preimage)` via `sodium_crypto_generichash` (libsodium
default: **32 byte output, BLAKE2b-256**, hex encoded). The TS port uses
`@noble/hashes` blake2b with `dkLen: 32` to match. NOTE: an earlier draft of
this doc said BLAKE2b-512 — that is WRONG for this codebase. libsodium's
one-argument `sodium_crypto_generichash` returns 32 bytes (BLAKE2b-256), and
the deployed PHP (PR #55) and TS port (PR #57) are pinned to that via golden
fixtures. Do not "fix" the hash length without re-pinning both sides.

Genesis block: `prevHash = 64 zeros`, `seqNo = 0`.

## Chain rules (enforced server side)

1. First block for a player must be genesis (seqNo 0, zero prevHash).
2. Block N's prevHash must equal block N-1's computed hash.
3. seqNo increments by exactly 1.
4. Lamport timestamps are non decreasing.
5. Every signature verifies over the canonical preimage with the block's
   public key.

`ChainValidator::validate()` returns a list of violated rules (empty = valid),
so a rejecting caller can log every reason, not just the first.

## Fork resolution (frozen spec, implemented)

Two blocks at the same seqNo from the same player:

- If they are the SAME block (duplicate LoRa delivery), keep one, discard the
  copies. This is not cheating, it is radio retransmission.
- If they are DIFFERENT blocks (different hashes), that is equivocation:
  BOTH are discarded and the player's downstream state is rolled back. The
  caller performs the rollback; `ForkResolver` reports which blocks to drop.

`ForkResolver::compare()` orders any two blocks by (lamportTs, hash) for
timeline merging, tie broken by hex string comparison of the chain hash.

## Decisions made here (flag if you disagree)

1. **BLAKE2b 512 for chain hashes.** Sodium's generichash, 64 byte output,
   hex encoded. Chosen over SHA 256 for speed and availability on both sides.
2. **prevHash stored/transmitted as hex, not raw bytes.** Keeps payloads
   printable and matches the schema's `bytea` column either way; the preimage
   length is fixed at 64 + 8 + 8 + payload.
3. **u64 big endian.** Fixed, documented, tested (`test_canonical_preimage`).
4. **`isGenesis()` is seqNo === 0**, not prevHash === zeros. A non genesis
   block with a zero prevHash is invalid, not genesis.
5. **Duplicate delivery is not equivocation.** A radio mesh retransmits; the
   same block arriving twice is normal and must not slash a player.

## Tests

36 new tests in `tests/Ledger/` (231 API total):

- `Ed25519Test` (7): keypair shape, sign/verify roundtrips, tamper and wrong
  key rejection, garbage key tolerance.
- `LedgerBlockTest` (9): preimage layout, hash determinism and sensitivity to
  every field, genesis flag, input validation.
- `ChainValidatorTest` (12): genesis rules, chaining, seqNo, Lamport, tampered
  payload, forged key, multi error collection, full chain walks.
- `ForkResolverTest` (7): equivocation slashing, duplicate delivery kept,
  Lamport ordering, hash tiebreak, empty input.

## Next steps (not in this slice)

1. TypeScript ledger port with golden fixture parity (same fixture pattern as
   `test-fixtures/engine-cases.json`).
2. Postgres repository: `INSERT` with `ON CONFLICT` handling of the
   `(match_id, player_id, seq_no)` unique constraint wired to ForkResolver.
3. Ledger API endpoints: submit block, fetch blocks since seqNo, fork report.
4. CBOR encode/decode of the action payload (frozen: positional arrays,
   under 100 bytes including the 64 byte signature).
