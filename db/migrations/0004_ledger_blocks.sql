-- 0004_ledger_blocks.sql
-- Signed, hash chained ledger blocks per player per match. The strict source of
-- truth for actions taken in a match. Blocks arrive from clients, get replayed,
-- and are then persisted by the authoritative server.

BEGIN;

CREATE TABLE IF NOT EXISTS ledger_blocks (
    id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    match_id     uuid NOT NULL,
    player_id    uuid NOT NULL,
    seq_no       integer NOT NULL,
    prev_hash    bytea,
    payload      bytea NOT NULL,
    sig          bytea NOT NULL,
    lamport_ts   bigint NOT NULL,
    client_ts    timestamptz NOT NULL,
    server_ts    timestamptz NOT NULL DEFAULT now(),
    hash         bytea NOT NULL,
    CONSTRAINT ledger_blocks_match_player_seq_uk UNIQUE (match_id, player_id, seq_no)
);

CREATE INDEX IF NOT EXISTS ledger_blocks_match_server_ts_idx
    ON ledger_blocks (match_id, server_ts);

COMMIT;
