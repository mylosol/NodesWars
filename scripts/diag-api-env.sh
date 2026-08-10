#!/usr/bin/env bash
# NodesWars prod DB connect probe.
# Prints ONLY booleans / lengths / exception classes — never secret values.
# Run on the host:  bash /tmp/diag-api-env.sh <API_TARGET_DIR>
set -euo pipefail

API_TARGET="${1:-}"
if [ -z "$API_TARGET" ]; then
  echo "usage: bash diag-api-env.sh <API_TARGET_DIR>" >&2
  exit 2
fi

ENV_FILE="$API_TARGET/.env"
echo "diag API_TARGET=$API_TARGET"
echo "diag env_exists=$( [ -f "$ENV_FILE" ] && echo yes || echo no )"
echo "diag index_php=$( [ -f "$API_TARGET/public/index.php" ] && echo yes || echo no )"

if [ ! -f "$ENV_FILE" ]; then
  echo "diag result=no_env"
  exit 0
fi

# Only the LENGTHS and the connection exception class/code are printed.
php -r '
require $argv[1] . "/vendor/autoload.php";
$envFile = $argv[1] . "/.env";
Dotenv\Dotenv::createImmutable(dirname($envFile))->safeLoad();
$env = static fn (string $k): string => (string) ($_ENV[$k] ?? getenv($k) ?: "");
$dsn  = $env("DATABASE_URL");
$user = $env("DATABASE_USER") ?: "postgres";
$pass = $env("DATABASE_PASSWORD");
echo "diag env_lines=" . count(file($envFile)) . "\n";
echo "diag dsn_len=" . strlen($dsn) . "\n";
echo "diag user_len=" . strlen($user) . "\n";
echo "diag pass_len=" . strlen($pass) . "\n";
if ($dsn === "") {
    echo "diag result=DATABASE_URL_empty";
    exit(0);
}
try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    echo "diag connect=ok\n";
    # First real query - proves the connection can execute SQL, not just open.
    $pdo->query("SELECT 1")->fetchColumn();
    echo "diag query_select1=ok\n";
    # Does the schema exist? Query information_schema instead of to_regclass
    # (to_regclass with a schema-qualified literal trips the SQL parser).
    $cnt = $pdo->query("SELECT count(*) FROM information_schema.tables WHERE table_name = " . chr(39) . "matches" . chr(39))->fetchColumn();
    echo "diag matches_table=" . ($cnt > 0 ? "exists" : "MISSING") . "\n";
    echo "diag result=CONNECTED\n";
} catch (Throwable $e) {
    $msg = substr($e->getMessage(), 0, 160);
    echo "diag connect=" . get_class($e) . " code=" . $e->getCode() . " msg=" . $msg . "\n";
    echo "diag result=CONNECT_FAILED\n";
}
' "$API_TARGET"

exit 0
