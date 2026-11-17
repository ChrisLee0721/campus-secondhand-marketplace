#!/usr/bin/env bash
# ============================================================
#  Campus Market — WSL deployment and reset script
#
#  Usage (inside WSL Ubuntu):
#      bash setup.sh            # fresh install (drops the existing database)
#      bash setup.sh --keep-db  # reinstall the code and the server only, keep the database
#
#  What this script does:
#    1. install PHP 8.3 and MariaDB (skipped when already present)
#    2. switch MariaDB root to password auth (Ubuntu ships unix_socket, which PHP cannot use)
#    3. copy the project from /mnt/f to ~/Server (ext4, far faster than the 9p mount)
#    4. create the online_marketplace database and run migrations/*.sql
#    5. start the PHP built-in server
# ============================================================
set -euo pipefail

SRC="/mnt/f/Desktop/Server"
DST="$HOME/Server"
DB="online_marketplace"
MYSQL_ROOT_PW="${MYSQL_ROOT_PW:-123456}"  # demo default password, also documented in the README
PORT="8000"
KEEP_DB=0

for arg in "$@"; do
    case "$arg" in
        --keep-db) KEEP_DB=1 ;;
        *) echo "Unknown option: $arg"; exit 2 ;;
    esac
done

say() { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
ok()  { printf '    \033[1;32m✓\033[0m %s\n' "$*"; }
err() { printf '    \033[1;31m✗\033[0m %s\n' "$*"; }

# ---------- 1. Environment ----------
say "1/5 checking PHP and MariaDB"
if command -v php >/dev/null && { command -v mariadbd >/dev/null || command -v mysqld >/dev/null || [ -x /usr/sbin/mariadbd ] || [ -x /usr/sbin/mysqld ]; }; then
    ok "already installed, skipping"
else
    export DEBIAN_FRONTEND=noninteractive
    sudo apt-get update -qq
    sudo apt-get install -y -qq php-cli php-mysql php-mbstring mariadb-server
fi

MYSQL_CLIENT="$(command -v mariadb || command -v mysql || true)"
if [ -z "$MYSQL_CLIENT" ]; then
    err "MariaDB / MySQL client not found"
    exit 1
fi
M="$MYSQL_CLIENT -h 127.0.0.1 -u root -p${MYSQL_ROOT_PW}"

MARIADBD_BIN="$(command -v mariadbd || command -v mysqld || true)"
if [ -z "$MARIADBD_BIN" ] && [ -x /usr/sbin/mariadbd ]; then MARIADBD_BIN=/usr/sbin/mariadbd; fi
if [ -z "$MARIADBD_BIN" ] && [ -x /usr/sbin/mysqld ]; then MARIADBD_BIN=/usr/sbin/mysqld; fi

ok "PHP  $(php -r 'echo PHP_VERSION;')"
DB_VERSION="$("$MARIADBD_BIN" --version 2>/dev/null || true)"
ok "DB   $(printf '%s' "$DB_VERSION" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)"

# ---------- 2. MariaDB root ----------
say "2/5 configuring MariaDB root authentication"
sudo systemctl enable --now mariadb >/dev/null 2>&1 || sudo service mariadb start
sleep 1

if sudo "$MYSQL_CLIENT" -e 'SELECT 1' >/dev/null 2>&1; then
    # passwordless socket login still works, so root uses unix_socket: switch it to password auth
    sudo "$MYSQL_CLIENT" -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '${MYSQL_ROOT_PW}'; FLUSH PRIVILEGES;"
    ok "root switched from unix_socket to password auth"
else
    ok "root already uses password auth"
fi

$M -e 'SELECT 1' >/dev/null 2>&1 || { err "root TCP login failed"; exit 1; }
ok "TCP login OK (db_connect uses TCP)"

# ---------- 3. Copy the code ----------
say "3/5 copying the project to $DST"
if [ ! -d "$SRC" ]; then err "$SRC not found"; exit 1; fi
if [ -f /var/run/php-server.pid ]; then
    sudo kill "$(cat /var/run/php-server.pid)" 2>/dev/null || true
    sudo rm -f /var/run/php-server.pid
fi
sudo pkill -f "php -S 0.0.0.0:${PORT}" 2>/dev/null || true
sleep 1

mkdir -p "$DST"
# rsync semantics by hand: empty the target first (keeping uploads), then copy
sudo rm -rf "$DST/app" "$DST/src" "$DST/public" "$DST/config" "$DST/migrations"
cp -r "$SRC/." "$DST/"
mkdir -p "$DST/public/uploads"

# Write the database password into the git-ignored local config so it is never committed
php -r 'file_put_contents($argv[1], "<?php\n\ndeclare(strict_types=1);\n\nreturn [\"db\" => [\"password\" => " . var_export($argv[2], true) . "]];\n");' "$DST/config/config.local.php" "$MYSQL_ROOT_PW"
chmod 600 "$DST/config/config.local.php" 2>/dev/null || true

ok "$(find "$DST" -type f -not -path '*/uploads/*' | wc -l) files"

# ---------- 4. Database ----------
say "4/5 initialising database $DB"
if [ "$KEEP_DB" -eq 1 ]; then
    ok "--keep-db given, skipping"
else
    $M -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null

    if [ -f "$DST/database/online_marketplace.sql" ]; then
        # the repository ships a full demo dataset (111 listings, 3 accounts, comments)
        $M "$DB" < "$DST/database/online_marketplace.sql" 2>/dev/null
        ok "imported database/online_marketplace.sql"
    else
        for f in "$DST"/migrations/*.sql; do
            $M "$DB" < "$f" 2>/dev/null
            ok "ran $(basename "$f")"
        done

        # without a full dump, try migrating the legacy db_01 database (skipped when absent)
        if php "$DST/migrations/migrate_legacy.php" 2>&1 | sed 's/^/    /'; then
            ok "legacy migration finished (or nothing to migrate)"
        else
            err "legacy migration failed (the new database is still usable)"
        fi
    fi
fi

$M "$DB" -N -B -e "
  SELECT CONCAT('    categories=', (SELECT COUNT(*) FROM categories),
                '  users=',      (SELECT COUNT(*) FROM users),
                '  products=',   (SELECT COUNT(*) FROM products),
                '  cart=',       (SELECT COUNT(*) FROM cart),
                '  orders=',     (SELECT COUNT(*) FROM orders));" 2>/dev/null

# ---------- 5. Start the server ----------
say "5/5 starting the PHP built-in server on :$PORT"
cd "$DST/public"
sudo setsid php -S "0.0.0.0:${PORT}" -t "$DST/public" "$DST/public/router.php" \
    > /var/log/php-dev.log 2>&1 < /dev/null &
sleep 2

if ss -ltn | grep -q ":${PORT}"; then
    ok "already listening on :${PORT}"
else
    err "port is not listening, tail of the log:"; sudo tail -10 /var/log/php-dev.log; exit 1
fi

# smoke test
say "smoke test"
HEALTH=$(curl -sS "http://127.0.0.1:${PORT}/api/v1/health" 2>/dev/null || echo '{}')
if echo "$HEALTH" | grep -q '"status":"ok"'; then
    ok "GET /api/v1/health → ok"
else
    err "health check failed: $HEALTH"; exit 1
fi

PRODUCTS=$(curl -sS "http://127.0.0.1:${PORT}/api/v1/products?per_page=1" 2>/dev/null)
TOTAL=$(echo "$PRODUCTS" | grep -oE '"total":[0-9]+' | head -1 | cut -d: -f2)
ok "GET /api/v1/products → total=${TOTAL:-?}"

cat <<EOF

============================================================
 Deployment complete
============================================================

  Front end     http://localhost:${PORT}/
  API           http://localhost:${PORT}/api/v1/health

  Demo accounts (see migrations/002_seed.sql)
      admin@example.com / admin1234
      alice@example.com / alice1234
      bob@example.com   / bob1234

  Code location $DST     <- edit this copy, not the one on /mnt/f
  Log           /var/log/php-dev.log

  Common commands
      restart in the foreground (live log):
          cd $DST/public && sudo php -S 0.0.0.0:${PORT} router.php
      stop the server:
          sudo pkill -f "php -S"
      re-run the migrations:
          bash $DST/setup.sh

============================================================
EOF
