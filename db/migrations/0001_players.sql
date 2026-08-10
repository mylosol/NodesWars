-- 0001_players.sql
-- Player accounts.

BEGIN;

CREATE TABLE IF NOT EXISTS players (
    id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    email           text UNIQUE NOT NULL,
    ed25519_pubkey  bytea,
    xp              integer NOT NULL DEFAULT 0,
    coins           integer NOT NULL DEFAULT 0,
    level           integer NOT NULL DEFAULT 1,
    created_at      timestamptz NOT NULL DEFAULT now()
);

COMMIT;
