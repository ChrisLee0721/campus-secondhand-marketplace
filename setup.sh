#!/usr/bin/env bash
# ============================================================
#  闲置集市 —— WSL 部署 / 重置脚本
#
#  用法（在 WSL Ubuntu 里执行）:
#      bash setup.sh            # 全新装（会 DROP 掉现有库）
#      bash setup.sh --keep-db  # 只重装代码和服务器，不动数据库
#
#  这个脚本做的事:
#    1. 装 PHP 8.3 + MariaDB（已装则跳过）
#    2. 把 MariaDB root 改成密码认证（Ubuntu 默认 unix_socket，PHP 连不上）
#    3. 把项目从 /mnt/f 复制到 ~/Server（ext4，比 9p 挂载快得多）
#    4. 建 online_marketplace 库，跑 migrations/*.sql
#    5. 起 PHP 内置服务器
# ============================================================
set -euo pipefail

SRC="/mnt/f/Desktop/Server"
DST="$HOME/Server"
DB="online_marketplace"
MYSQL_ROOT_PW="${MYSQL_ROOT_PW:-}"
PORT="8000"
KEEP_DB=0

for arg in "$@"; do
    case "$arg" in
        --keep-db) KEEP_DB=1 ;;
        *) echo "未知参数: $arg"; exit 2 ;;
    esac
done

say() { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
ok()  { printf '    \033[1;32m✓\033[0m %s\n' "$*"; }
err() { printf '    \033[1;31m✗\033[0m %s\n' "$*"; }

if [ -z "$MYSQL_ROOT_PW" ]; then
    if [ -t 0 ]; then
        read -rsp "MariaDB root 密码（新装会设置为该密码）: " MYSQL_ROOT_PW
        echo
    else
        err "请通过 MYSQL_ROOT_PW 环境变量提供 MariaDB root 密码"
        exit 2
    fi
fi

# ---------- 1. 环境 ----------
say "1/5 检查 PHP 与 MariaDB"
if command -v php >/dev/null && { command -v mariadbd >/dev/null || command -v mysqld >/dev/null || [ -x /usr/sbin/mariadbd ] || [ -x /usr/sbin/mysqld ]; }; then
    ok "已安装，跳过"
else
    export DEBIAN_FRONTEND=noninteractive
    sudo apt-get update -qq
    sudo apt-get install -y -qq php-cli php-mysql php-mbstring mariadb-server
fi

MYSQL_CLIENT="$(command -v mariadb || command -v mysql || true)"
if [ -z "$MYSQL_CLIENT" ]; then
    err "找不到 MariaDB / MySQL 客户端"
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
say "2/5 配置 MariaDB root 认证"
sudo systemctl enable --now mariadb >/dev/null 2>&1 || sudo service mariadb start
sleep 1

if sudo "$MYSQL_CLIENT" -e 'SELECT 1' >/dev/null 2>&1; then
    # 还能免密 socket 登录 → 说明是 unix_socket，改成密码认证
    sudo "$MYSQL_CLIENT" -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '${MYSQL_ROOT_PW}'; FLUSH PRIVILEGES;"
    ok "root 已从 unix_socket 改为密码认证"
else
    ok "root 已是密码认证"
fi

$M -e 'SELECT 1' >/dev/null 2>&1 || { err "root TCP 登录失败"; exit 1; }
ok "TCP 登录 OK（db_connect 走的就是 TCP）"

# ---------- 3. 复制代码 ----------
say "3/5 复制项目到 $DST"
if [ ! -d "$SRC" ]; then err "找不到 $SRC"; exit 1; fi
if [ -f /var/run/php-server.pid ]; then
    sudo kill "$(cat /var/run/php-server.pid)" 2>/dev/null || true
    sudo rm -f /var/run/php-server.pid
fi
sudo pkill -f "php -S 0.0.0.0:${PORT}" 2>/dev/null || true
sleep 1

mkdir -p "$DST"
# 用 rsync 语义手动实现：先清空（保留 uploads），再复制
sudo rm -rf "$DST/app" "$DST/src" "$DST/public" "$DST/config" "$DST/migrations"
cp -r "$SRC/." "$DST/"
mkdir -p "$DST/public/uploads"

# 把数据库密码写进被 .gitignore 忽略的本机配置，避免提交到 Git
php -r 'file_put_contents($argv[1], "<?php\n\ndeclare(strict_types=1);\n\nreturn [\"db\" => [\"password\" => " . var_export($argv[2], true) . "]];\n");' "$DST/config/config.local.php" "$MYSQL_ROOT_PW"
chmod 600 "$DST/config/config.local.php" 2>/dev/null || true

ok "$(find "$DST" -type f -not -path '*/uploads/*' | wc -l) 个文件"

# ---------- 4. 数据库 ----------
say "4/5 初始化数据库 $DB"
if [ "$KEEP_DB" -eq 1 ]; then
    ok "指定了 --keep-db，跳过"
else
    $M -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null
    for f in "$DST"/migrations/*.sql; do
        $M "$DB" < "$f" 2>/dev/null
        ok "已执行 $(basename "$f")"
    done

    # 迁移旧库 db_01 的 100 件商品（旧库不存在会自动跳过）
    if php "$DST/migrations/migrate_legacy.php" 2>&1 | sed 's/^/    /'; then
        ok "旧库迁移完成（或无需迁移）"
    else
        err "旧库迁移失败（不影响新库使用）"
    fi
fi

$M "$DB" -N -B -e "
  SELECT CONCAT('    categories=', (SELECT COUNT(*) FROM categories),
                '  users=',      (SELECT COUNT(*) FROM users),
                '  products=',   (SELECT COUNT(*) FROM products),
                '  cart=',       (SELECT COUNT(*) FROM cart),
                '  orders=',     (SELECT COUNT(*) FROM orders));" 2>/dev/null

# ---------- 5. 起服务 ----------
say "5/5 启动 PHP 内置服务器 :$PORT"
cd "$DST/public"
sudo setsid php -S "0.0.0.0:${PORT}" -t "$DST/public" "$DST/public/router.php" \
    > /var/log/php-dev.log 2>&1 < /dev/null &
sleep 2

if ss -ltn | grep -q ":${PORT}"; then
    ok "已在监听 :${PORT}"
else
    err "端口未监听，日志："; sudo tail -10 /var/log/php-dev.log; exit 1
fi

# 冒烟测试
say "冒烟测试"
HEALTH=$(curl -sS "http://127.0.0.1:${PORT}/api/v1/health" 2>/dev/null || echo '{}')
if echo "$HEALTH" | grep -q '"status":"ok"'; then
    ok "GET /api/v1/health → ok"
else
    err "健康检查失败: $HEALTH"; exit 1
fi

PRODUCTS=$(curl -sS "http://127.0.0.1:${PORT}/api/v1/products?per_page=1" 2>/dev/null)
TOTAL=$(echo "$PRODUCTS" | grep -oE '"total":[0-9]+' | head -1 | cut -d: -f2)
ok "GET /api/v1/products → total=${TOTAL:-?}"

cat <<EOF

============================================================
 部署完成
============================================================

  前端      http://localhost:${PORT}/
  接口      http://localhost:${PORT}/api/v1/health

  演示账号（见 migrations/002_seed.sql）
      admin@example.com / admin1234
      alice@example.com / alice1234
      bob@example.com   / bob1234

  代码位置  $DST     ← 改这里，不要改 /mnt/f 那份
  日志      /var/log/php-dev.log

  常用命令
      前台重启（能看实时日志）:
          cd $DST/public && sudo php -S 0.0.0.0:${PORT} router.php
      停服务器:
          sudo pkill -f "php -S"
      重跑迁移:
          bash $DST/setup.sh

============================================================
EOF
