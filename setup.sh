#!/usr/bin/env bash
# One-command local setup for SASA. Idempotent: safe to run again.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

say() { printf '\n\033[1;36m▸ %s\033[0m\n' "$1"; }
die() { printf '\n\033[1;31m✗ %s\033[0m\n' "$1" >&2; exit 1; }

say "Checking what is installed"
command -v php >/dev/null      || die "PHP 8.3+ is required."
command -v composer >/dev/null || die "Composer 2 is required."
command -v node >/dev/null     || die "Node 20+ is required."
command -v mysql >/dev/null    || die "A MySQL client is required."

php -r 'exit(version_compare(PHP_VERSION, "8.3", ">=") ? 0 : 1);' || die "PHP 8.3+ is required; found $(php -r 'echo PHP_VERSION;')."

MYSQL_USER="${DB_USERNAME:-root}"
MYSQL_ARGS=(-u "$MYSQL_USER")
[ -n "${DB_PASSWORD:-}" ] && MYSQL_ARGS+=(-p"${DB_PASSWORD}")

say "Creating the databases"
mysql "${MYSQL_ARGS[@]}" -e "
  CREATE DATABASE IF NOT EXISTS sasa         CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE DATABASE IF NOT EXISTS sasa_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
" || die "Could not reach MySQL as '$MYSQL_USER'. Set DB_USERNAME and DB_PASSWORD and try again."

say "Backend"
cd "$ROOT/backend"
composer install --no-interaction
[ -f .env ] || cp .env.example .env
grep -q '^APP_KEY=base64' .env || php artisan key:generate --ansi
php artisan migrate --seed --force

say "Frontend"
cd "$ROOT/web"
npm install --no-audit --no-fund
[ -f .env.local ] || cp .env.example .env.local

cat <<'DONE'

  SASA is ready.

    Terminal 1   cd backend && php artisan serve --port=8010
    Terminal 2   cd backend && php artisan queue:work
    Terminal 3   cd backend && php artisan schedule:work
    Terminal 4   cd web     && npm run dev

    Then open http://localhost:3010

    Sign in with any of the demo accounts — the password is "password":
      executive@sasa.test    what is happening on this project
      grievance@sasa.test    what needs attention today
      cro@sasa.test          who am I engaging, and what did they raise
      field@sasa.test        what do I need to do today (try it offline)
      auditor@sasa.test      read-only, for a lender or an auditor

DONE
