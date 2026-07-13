-- 0005_npns.sql
-- Named Physical Nodes: geographic points of interest players have discovered.

BEGIN;

CREATE TABLE IF NOT EXISTS npns (
    id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    lat             numeric(9, 6) NOT NULL,
    lon             numeric(9, 6) NOT NULL,
    discovered_at   timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS npns_lat_lon_idx ON npns(lat, lon);

COMMIT;
