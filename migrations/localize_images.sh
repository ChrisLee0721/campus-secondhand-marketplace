#!/usr/bin/env bash
# ============================================================
#  图片本地化
#
#  用法（在 WSL 里）: bash migrations/localize_images.sh
#
#  做什么：
#    1. 把数据库里所有外链图片（unsplash）下载到 public/uploads/seed/
#       并改写 image_path 为本地相对路径
#    2. 下载失败的（原图已 404）→ 删除该商品，并打印清单
#
#  为什么必须本地化：
#    原始的 100 件商品用的是 unsplash 外链，实测其中 4 个已经永久 404
#    （photo-1639762681057 / photo-1544931170 / photo-1596704017256 /
#      photo-1594631252845）。外链随时会失效，演示当天挂掉就麻烦了。
#    本地化之后断网也能跑，图片永远有效。
#
#  为什么是「删商品」而不是「随便配张图」：
#    给商品配一张对不上的图（比如给哑铃配星云照片）比没有图更糟 ——
#    用户一眼就看出来是假的。宁可少几件商品。
#
#  幂等：已下载的文件不重复下；已是本地路径的不再处理。
# ============================================================
set -uo pipefail

DST="${1:-/root/Server}"
SEED="$DST/public/uploads/seed"

# 演示项目默认数据库密码；README 里也写了
MYSQL_PWD="${DB_PASSWORD:-123456}"
export MYSQL_PWD
M="mysql -u root -h 127.0.0.1 online_marketplace -N -B"

mkdir -p "$SEED"

ok()  { printf '  \033[1;32m✓\033[0m %s\n' "$*"; }
err() { printf '  \033[1;31m✗\033[0m %s\n' "$*"; }
inf() { printf '    %s\n' "$*"; }

echo "=============================================="
echo " 图片本地化 —— $DST"
echo "=============================================="

# ---------- 1. 下载 ----------
echo
echo "[1/3] 下载外链图片到本地"

mapfile -t REMOTE < <($M -e \
  "SELECT DISTINCT image_path FROM products WHERE image_path LIKE 'http%';" 2>/dev/null)

if [ ${#REMOTE[@]} -eq 0 ]; then
    ok "没有外链图片需要处理"
else
    echo "    发现 ${#REMOTE[@]} 个不同的外链"
    downloaded=0
    for url in "${REMOTE[@]}"; do
        [ -z "$url" ] && continue

        # 用 unsplash 的 photo id 做文件名，稳定且可读
        name=$(echo "$url" | grep -oE 'photo-[0-9a-f]+' | head -1)
        [ -z "$name" ] && name=$(echo "$url" | md5sum | cut -c1-16)

        local_file="$SEED/ext-${name}.jpg"
        rel="uploads/seed/ext-${name}.jpg"

        if [ ! -f "$local_file" ]; then
            # 带尺寸参数，别下原图（原图好几 MB）
            code=$(curl -sSL -o "$local_file.tmp" --max-time 40 -w '%{http_code}' \
                   "${url}?w=800&q=75&fm=jpg" 2>/dev/null)
            if [ "$code" = "200" ] && file -b --mime-type "$local_file.tmp" 2>/dev/null | grep -q '^image/'; then
                mv "$local_file.tmp" "$local_file"
                downloaded=$((downloaded+1))
            else
                rm -f "$local_file.tmp"
                # 下不来就跳过，留给第 2 步处理
                continue
            fi
        fi

        $M -e "UPDATE products SET image_path = '$rel' WHERE image_path = '$url';" 2>/dev/null
    done
    ok "新下载 ${downloaded} 张；本地现有 $(ls "$SEED"/*.jpg 2>/dev/null | wc -l) 张"
fi

# ---------- 2. 清理下载失败的商品 ----------
echo
echo "[2/3] 处理图片已失效的商品"

mapfile -t DEAD_IDS < <($M -e \
  "SELECT id FROM products WHERE image_path LIKE 'http%' ORDER BY id;" 2>/dev/null)

if [ ${#DEAD_IDS[@]} -eq 0 ]; then
    ok "没有商品需要删除"
else
    err "${#DEAD_IDS[@]} 件商品的图片已永久失效（原图 404），删除："
    for pid in "${DEAD_IDS[@]}"; do
        [ -z "$pid" ] && continue
        title=$($M -e "SELECT title FROM products WHERE id = $pid;" 2>/dev/null)
        url=$($M -e "SELECT image_path FROM products WHERE id = $pid;" 2>/dev/null)
        inf "#$pid  $title"
        inf "       $url"
        $M -e "DELETE FROM products WHERE id = $pid;" 2>/dev/null
    done
fi

# ---------- 3. 验证 ----------
echo
echo "[3/3] 验证"
$M -e 'SELECT
    COUNT(*)                                       AS 总数,
    SUM(image_path IS NULL OR image_path = "")     AS 无图,
    SUM(image_path LIKE "http%")                   AS 仍是外链,
    SUM(image_path LIKE "uploads/%")               AS 本地图
  FROM products;' 2>/dev/null \
| awk -F'\t' '{printf "    总数=%s  无图=%s  仍是外链=%s  本地图=%s\n", $1, $2, $3, $4}'

UNREF=0
for f in "$SEED"/*.jpg; do
    [ -f "$f" ] || continue
    b=$(basename "$f")
    n=$($M -e "SELECT COUNT(*) FROM products WHERE image_path = 'uploads/seed/$b';" 2>/dev/null)
    [ "$n" = "0" ] && { rm -f "$f"; UNREF=$((UNREF+1)); }
done
ok "清理未引用的图片 ${UNREF} 张；本地图片共 $(ls "$SEED"/*.jpg 2>/dev/null | wc -l) 张，占用 $(du -sh "$SEED" 2>/dev/null | cut -f1)"
