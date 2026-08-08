#!/usr/bin/env bash
# NodesWars deploy diagnostic — verifies the API .env placement and DB
# connectivity. Prints booleans, lengths and exception classes ONLY, never
# secret values. Used by .github/workflows/production-deploy.yml.
#
# Usage: diag-api-env.sh <API_TARGET>
set -u

API_TARGET="$1"

echo "diag API_TARGET=$API_TARGET"
if [ -f "$API_TARGET/.env" ]; then
  echo "diag env_exists=yes"
else
  echo "diag env_exists=NO"
fi
if [ -f "$API_TARGET/public/index.php" ]; then
  echo "diag index_php=yes"
else
  echo "diag index_php=NO"
fi
grep -c "^DATABASE_" "$API_TARGET/.env" 2>/dev/null | sed 's/^/diag env_lines=/'

# Load the .env with phpdotenv and report parse + connect status. Never
# print values — only lengths and exception classes.
cd "$API_TARGET" && php -r '
require "vendor/autoload.php";
Dotenv\Dotenv::createImmutable(getcwd())->safeLoad();
$env = static fn (string $k): string => (string) ($_ENV[$k] ?? getenv($k) ?: "");
echo "diag parsed_url_len=" . strlen($env("DATABASE_URL")) . PHP_EOL;
echo "diag parsed_user_len=" . strlen($env("DATABASE_USER")) . PHP_EOL;
echo "diag parsed_pass_len=" . strlen($env("DATABASE_PASSWORD")) . PHP_EOL;
try {
  $pdo = new PDO($env("DATABASE_URL"), $env("DATABASE_USER"), $env("DATABASE_PASSWORD"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  echo "diag connect=ok" . PHP_EOL;
} catch (Throwable $e) {
  echo "diag connect=" . get_class($e) . " code=" . $e->getCode() . " msg=" . substr($e->getMessage(), 0, 120) . PHP_EOL;
}
'
