-- 0003_match_players.sql
-- Which players are in which match.

BEGIN;

CREATE TABLE IF NOT EXISTS match_players (
    match_id    uuid NOT NULL REFERENCES matches(id) ON DELETE CASCADE,
    player_id   uuid NOT NULL REFERENCES players(id) ON DELETE CASCADE,
    joined_at   timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (match_id, player_id)
);

CREATE INDEX IF NOT EXISTS match_players_player_id_idx ON match_players(player_id);

COMMIT;
