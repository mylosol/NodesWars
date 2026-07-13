-- 0002_matches.sql
-- Match sessions. The game master owns the encrypted session key.

BEGIN;

CREATE TABLE IF NOT EXISTS matches (
    id                      uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    gm_player_id            uuid NOT NULL REFERENCES players(id),
    session_key_encrypted   bytea,
    started_at              timestamptz NOT NULL DEFAULT now(),
    ended_at                timestamptz,
    status                  text NOT NULL DEFAULT 'pending'
);

CREATE INDEX IF NOT EXISTS matches_gm_player_id_idx ON matches(gm_player_id);
CREATE INDEX IF NOT EXISTS matches_status_idx ON matches(status);

COMMIT;
