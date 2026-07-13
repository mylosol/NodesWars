-- 0006_leaderboards.sql
-- Materialized view stub. Refresh strategy is left for a later migration.

BEGIN;

CREATE MATERIALIZED VIEW IF NOT EXISTS leaderboards AS
SELECT
    p.id       AS player_id,
    p.xp       AS xp,
    p.coins    AS coins,
    0::integer AS kills
FROM players p
WITH NO DATA;

CREATE UNIQUE INDEX IF NOT EXISTS leaderboards_player_id_idx
    ON leaderboards(player_id);

COMMIT;
