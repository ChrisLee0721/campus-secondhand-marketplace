#!/usr/bin/env bash
# ============================================================
#  LeeLapYuen / 闲置交易平台 —— WSL 部署脚本
#  用法（在 WSL Ubuntu 里执行）:
#      bash deploy.sh
#
#  这个脚本做的事:
#    1. 装 PHP 8.3 + MySQL 8.0
#    2. 把 MySQL root 改成密码认证（Ubuntu 默认是 auth_socket，PHP 连不上）
#    3. 把项目从 /mnt/f 复制到 ~/Server（ext4，比 9p 挂载快一个数量级）
#    4. 建 db_01 库并导入三个 dump
#    5. 给 products 补 status 列（dump 里没有，但 main.php 要查它）
#    6. 起 PHP 内置服务器
# ============================================================
set -euo pipefail

SRC="/mnt/f/Desktop/Server"      # Windows 上的项目位置
DST="$HOME/Server"               # WSL ext4 里的运行位置
DB="db_01"
MYSQL_ROOT_PW="${MYSQL_ROOT_PW:-123456}"  # 演示项目默认密码
PORT="8000"

say() { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }

# ---------- 1. 环境 ----------
say "1/6 安装 PHP 与 MySQL"
export DEBIAN_FRONTEND=noninteractive
sudo apt-get update -qq
sudo apt-get install -y -qq php-cli php-mysql php-mbstring mysql-server
php -v | head -1
mysqld --version

# ---------- 2. root 密码 ----------
say "2/6 把 MySQL root 从 auth_socket 改成密码认证"
sudo systemctl enable --now mysql
if mysql -e 'SELECT 1' >/dev/null 2>&1; then
    # 说明还是 auth_socket（socket 直连免密），改成 caching_sha2_password
    mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED WITH caching_sha2_password BY '${MYSQL_ROOT_PW}'; FLUSH PRIVILEGES;"
    echo "  root 已改为密码认证"
else
    echo "  root 已是密码认证（或已改过），跳过"
fi
# 验证 TCP 登录（db_connect.php 走的就是 TCP）
MYSQL_PWD="$MYSQL_ROOT_PW" mysql -h 127.0.0.1 -u root -e "SELECT CURRENT_USER() AS who;" \
  || { echo "  ✗ root TCP 登录失败"; exit 1; }

# ---------- 3. 复制项目 ----------
say "3/6 复制项目到 $DST（ext4）"
[ -d "$SRC" ] || { echo "  ✗ 找不到 $SRC"; exit 1; }
mkdir -p "$DST"
cp -r "$SRC/." "$DST/"
echo "  $(find "$DST" -type f | wc -l) 个文件"

# ---------- 4. 建库 + 导入 ----------
say "4/6 建库 $DB 并导入 dump"
M="mysql -h 127.0.0.1 -u root -p${MYSQL_ROOT_PW}"
$M -e "DROP DATABASE IF EXISTS \`$DB\`;
       CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;" 2>/dev/null

# 注意: dump 里既没有 CREATE DATABASE 也没有 USE，所以必须显式指定库名
for f in db_01_categories.sql db_01_products.sql db_01_tb_01.sql; do
    $M "$DB" < "$DST/1/$f" 2>/dev/null
    echo "  已导入 $f"
done

# ---------- 5. 补 status 列 ----------
say "5/6 给 products 补 status 列（否则状态筛选会 500）"
$M "$DB" -e "
  ALTER TABLE products ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT '全新';
  ALTER TABLE products ADD INDEX idx_status (status);
  ALTER TABLE products ADD INDEX idx_category (category_id);
" 2>/dev/null
$M "$DB" -N -B -e "SELECT CONCAT('  products=', (SELECT COUNT(*) FROM products),
                                 ' categories=', (SELECT COUNT(*) FROM categories),
                                 ' users=', (SELECT COUNT(*) FROM tb_01));" 2>/dev/null

# ---------- 6. 起服务 ----------
say "6/6 启动 PHP 内置服务器 :$PORT"
[ -f /var/run/php-server.pid ] && sudo kill "$(cat /var/run/php-server.pid)" 2>/dev/null || true
sudo pkill -f "php -S 0.0.0.0:$PORT" 2>/dev/null || true
sleep 1
cd "$DST"
sudo setsid php -S "0.0.0.0:$PORT" -t "$DST" > /tmp/php-server.log 2>&1 < /dev/null &
sleep 2
if ss -ltn | grep -q ":$PORT"; then
    echo "  ✓ 已在监听 :$PORT"
else
    echo "  ✗ 端口未监听，日志："; tail -5 /tmp/php-server.log; exit 1
fi

cat <<EOF

============================================================
 部署完成
============================================================
  访问地址（Windows 浏览器直接打开）:
      http://localhost:$PORT/main.php

  代码运行位置（改代码改这里，不要改 /mnt/f 那份）:
      $DST

  常用命令:
      pkill -f "php -S"              # 停服务器
      cd $DST && php -S 0.0.0.0:$PORT   # 前台重启（能看实时日志）

  注意:
    - 首页是 main.php，不是 login_regsiter.php（后者没有任何入口链接）
    - login_regsiter.php 里的注册表单提交后会 include 一个不存在的
      login_register.php，会报 Warning 但页面仍能用
============================================================
EOF
