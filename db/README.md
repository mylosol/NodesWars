# Database Migrations

Simple, ordered SQL files. There is no runner. Apply them by hand in numeric order:

```bash
export DATABASE_URL="postgres://user:pass@localhost:5432/nodeswars"

for f in $(ls db/migrations/*.sql | sort); do
  echo "applying $f"
  psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f "$f"
done
```

Or one at a time:

```bash
psql "$DATABASE_URL" -f db/migrations/0001_players.sql
psql "$DATABASE_URL" -f db/migrations/0002_matches.sql
```

## Conventions

- Filenames: `NNNN_description.sql`, zero padded to four digits.
- Migrations are forward only. No down migrations. To revert something, write a new migration.
- Always wrap DDL in a transaction where Postgres allows it.
- Prefer `IF NOT EXISTS` guards so re-running a migration is safe during dev.

## Extensions

Enable `pgcrypto` in the database once, so we can use `gen_random_uuid()`:

```sql
CREATE EXTENSION IF NOT EXISTS pgcrypto;
```
