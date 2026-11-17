#!/usr/bin/env bash
# ============================================================
#  LeeLapYuen / Second-hand Marketplace - WSL deployment script
#  Usage (run inside WSL Ubuntu):
#      bash deploy.sh
#
#  What this script does:
#    1. Install PHP 8.3 + MySQL 8.0
#    2. Switch MySQL root to password authentication (Ubuntu defaults to auth_socket, so PHP cannot connect)
#    3. Copy the project from /mnt/f to ~/Server (ext4, an order of magnitude faster than a 9p mount)
#    4. Create the db_01 database and import the three dumps
#    5. Add a status column to products (the dump has none, but main.php queries it)
#    6. Start the built-in PHP server
# ============================================================
set -euo pipefail

SRC="/mnt/f/Desktop/Server"      # Project location on Windows
DST="$HOME/Server"               # Runtime location inside WSL ext4
DB="db_01"
MYSQL_ROOT_PW="${MYSQL_ROOT_PW:-123456}"  # Default password for the demo project
PORT="8000"

say() { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }

# ---------- 1. Environment ----------
say "1/6 Installing PHP and MySQL"
export DEBIAN_FRONTEND=noninteractive
sudo apt-get update -qq
sudo apt-get install -y -qq php-cli php-mysql php-mbstring mysql-server
php -v | head -1
mysqld --version

# ---------- 2. root password ----------
say "2/6 Switching MySQL root from auth_socket to password authentication"
sudo systemctl enable --now mysql
if mysql -e 'SELECT 1' >/dev/null 2>&1; then
    # Still auth_socket (passwordless direct socket login), so switch to caching_sha2_password
    mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED WITH caching_sha2_password BY '${MYSQL_ROOT_PW}'; FLUSH PRIVILEGES;"
    echo "  root switched to password authentication"
else
    echo "  root already uses password authentication (or was already changed), skipping"
fi
# Verify TCP login (db_connect.php also connects over TCP)
MYSQL_PWD="$MYSQL_ROOT_PW" mysql -h 127.0.0.1 -u root -e "SELECT CURRENT_USER() AS who;" \
  || { echo "  ✗ root TCP login failed"; exit 1; }

# ---------- 3. Copy the project ----------
say "3/6 Copying the project to $DST (ext4)"
[ -d "$SRC" ] || { echo "  ✗ $SRC not found"; exit 1; }
mkdir -p "$DST"
cp -r "$SRC/." "$DST/"
echo "  $(find "$DST" -type f | wc -l) files"

# ---------- 4. Create database + import ----------
say "4/6 Creating database $DB and importing the dumps"
M="mysql -h 127.0.0.1 -u root -p${MYSQL_ROOT_PW}"
$M -e "DROP DATABASE IF EXISTS \`$DB\`;
       CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;" 2>/dev/null

# Note: the dumps contain neither CREATE DATABASE nor USE, so the database name must be specified explicitly
for f in db_01_categories.sql db_01_products.sql db_01_tb_01.sql; do
    $M "$DB" < "$DST/1/$f" 2>/dev/null
    echo "  Imported $f"
done

# ---------- 5. Add the status column ----------
say "5/6 Adding the status column to products (otherwise status filtering returns a 500)"
$M "$DB" -e "
  ALTER TABLE products ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'NEW';
  ALTER TABLE products ADD INDEX idx_status (status);
  ALTER TABLE products ADD INDEX idx_category (category_id);
" 2>/dev/null
$M "$DB" -N -B -e "SELECT CONCAT('  products=', (SELECT COUNT(*) FROM products),
                                 ' categories=', (SELECT COUNT(*) FROM categories),
                                 ' users=', (SELECT COUNT(*) FROM tb_01));" 2>/dev/null

# ---------- 6. Start the service ----------
say "6/6 Starting the built-in PHP server :$PORT"
[ -f /var/run/php-server.pid ] && sudo kill "$(cat /var/run/php-server.pid)" 2>/dev/null || true
sudo pkill -f "php -S 0.0.0.0:$PORT" 2>/dev/null || true
sleep 1
cd "$DST"
sudo setsid php -S "0.0.0.0:$PORT" -t "$DST" > /tmp/php-server.log 2>&1 < /dev/null &
sleep 2
if ss -ltn | grep -q ":$PORT"; then
    echo "  ✓ Listening on :$PORT"
else
    echo "  ✗ Port not listening, log:"; tail -5 /tmp/php-server.log; exit 1
fi

cat <<EOF

============================================================
 Deployment complete
============================================================
  Access URL (open directly in a Windows browser):
      http://localhost:$PORT/main.php

  Code runtime location (edit the code here, not the copy under /mnt/f):
      $DST

  Common commands:
      pkill -f "php -S"              # Stop the server
      cd $DST && php -S 0.0.0.0:$PORT   # Restart in the foreground (live log)

  Notes:
    - The landing page is main.php, not login_regsiter.php (nothing links to the latter)
    - Submitting the registration form in login_regsiter.php includes a non-existent
      login_register.php, which raises a Warning but the page still works
============================================================
EOF
