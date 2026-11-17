#!/usr/bin/env bash
# ============================================================
#  Image localisation
#
#  Usage (inside WSL): bash migrations/localize_images.sh
#
#  What it does:
#    1. download every external (Unsplash) image into public/uploads/seed/
#       and rewrite image_path to the local relative path
#    2. for images that no longer download (404), delete the listing and print the list
#
#  Why localise:
#    The original 100 listings pointed at Unsplash and four of those URLs now 404 permanently
#    (photo-1639762681057 / photo-1544931170 / photo-1596704017256 /
#      photo-1594631252845). External links expire, and breaking on demo day is not acceptable.
#    Once local, everything works offline and the images never disappear.
#
#  Why delete the listing instead of using a random image:
#    A mismatched photo (a nebula on a dumbbell) is worse than no photo at all,
#    a user spots it instantly. Fewer listings is the better trade.
#
#  Idempotent: downloaded files are not fetched again and local paths are skipped.
# ============================================================
set -uo pipefail

DST="${1:-/root/Server}"
SEED="$DST/public/uploads/seed"

# demo default database password, also documented in the README
MYSQL_PWD="${DB_PASSWORD:-123456}"
export MYSQL_PWD
M="mysql -u root -h 127.0.0.1 online_marketplace -N -B"

mkdir -p "$SEED"

ok()  { printf '  \033[1;32m✓\033[0m %s\n' "$*"; }
err() { printf '  \033[1;31m✗\033[0m %s\n' "$*"; }
inf() { printf '    %s\n' "$*"; }

echo "=============================================="
echo " Image localisation — $DST"
echo "=============================================="

# ---------- 1. Download ----------
echo
echo "[1/3] downloading external images"

mapfile -t REMOTE < <($M -e \
  "SELECT DISTINCT image_path FROM products WHERE image_path LIKE 'http%';" 2>/dev/null)

if [ ${#REMOTE[@]} -eq 0 ]; then
    ok "no external image to process"
else
    echo "    found ${#REMOTE[@]} distinct external URLs"
    downloaded=0
    for url in "${REMOTE[@]}"; do
        [ -z "$url" ] && continue

        # use the Unsplash photo id as the file name: stable and readable
        name=$(echo "$url" | grep -oE 'photo-[0-9a-f]+' | head -1)
        [ -z "$name" ] && name=$(echo "$url" | md5sum | cut -c1-16)

        local_file="$SEED/ext-${name}.jpg"
        rel="uploads/seed/ext-${name}.jpg"

        if [ ! -f "$local_file" ]; then
            # request a sized version, never the multi-megabyte original
            code=$(curl -sSL -o "$local_file.tmp" --max-time 40 -w '%{http_code}' \
                   "${url}?w=800&q=75&fm=jpg" 2>/dev/null)
            if [ "$code" = "200" ] && file -b --mime-type "$local_file.tmp" 2>/dev/null | grep -q '^image/'; then
                mv "$local_file.tmp" "$local_file"
                downloaded=$((downloaded+1))
            else
                rm -f "$local_file.tmp"
                # if it fails, skip it and let step 2 deal with it
                continue
            fi
        fi

        $M -e "UPDATE products SET image_path = '$rel' WHERE image_path = '$url';" 2>/dev/null
    done
    ok "downloaded ${downloaded} new; $(ls "$SEED"/*.jpg 2>/dev/null | wc -l) available locally"
fi

# ---------- 2. Remove listings whose image is gone ----------
echo
echo "[2/3] handling listings with a dead image"

mapfile -t DEAD_IDS < <($M -e \
  "SELECT id FROM products WHERE image_path LIKE 'http%' ORDER BY id;" 2>/dev/null)

if [ ${#DEAD_IDS[@]} -eq 0 ]; then
    ok "no listing to delete"
else
    err "${#DEAD_IDS[@]} listings point at an image that is permanently gone (original 404), deleting:"
    for pid in "${DEAD_IDS[@]}"; do
        [ -z "$pid" ] && continue
        title=$($M -e "SELECT title FROM products WHERE id = $pid;" 2>/dev/null)
        url=$($M -e "SELECT image_path FROM products WHERE id = $pid;" 2>/dev/null)
        inf "#$pid  $title"
        inf "       $url"
        $M -e "DELETE FROM products WHERE id = $pid;" 2>/dev/null
    done
fi

# ---------- 3. Verify ----------
echo
echo "[3/3] verifying"
$M -e 'SELECT
    COUNT(*)                                       AS total,
    SUM(image_path IS NULL OR image_path = "")     AS missing,
    SUM(image_path LIKE "http%")                   AS external,
    SUM(image_path LIKE "uploads/%")               AS local
  FROM products;' 2>/dev/null \
| awk -F'\t' '{printf "    total=%s  missing=%s  external=%s  local=%s\n", $1, $2, $3, $4}'

UNREF=0
for f in "$SEED"/*.jpg; do
    [ -f "$f" ] || continue
    b=$(basename "$f")
    n=$($M -e "SELECT COUNT(*) FROM products WHERE image_path = 'uploads/seed/$b';" 2>/dev/null)
    [ "$n" = "0" ] && { rm -f "$f"; UNREF=$((UNREF+1)); }
done
ok "removed ${UNREF} unreferenced images; $(ls "$SEED"/*.jpg 2>/dev/null | wc -l) local images, $(du -sh "$SEED" 2>/dev/null | cut -f1)"
