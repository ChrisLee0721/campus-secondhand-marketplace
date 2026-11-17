#!/bin/bash
# ============================================================
#  API end-to-end tests
#
#  Usage:
#      bash tests/api_test.sh [port]        # default 8000
#
#  Covers: health check / auth / account settings / listings / cart / checkout / order state machine /
#          cancellation / reviews / review replies / listing comments / homepage comments / admin backend / perms / validation
#  Keeps sessions in cookie jars, mimicking real browser behavior.
# ============================================================
set -uo pipefail

PORT="${1:-8000}"
BASE="http://127.0.0.1:${PORT}/api/v1"
JAR_A="/tmp/cookies_alice.txt"     # alice's session
JAR_B="/tmp/cookies_bob.txt"       # bob's session
JAR_ANON="/tmp/cookies_anon.txt"
JAR_ADMIN="/tmp/cookies_admin.txt" # admin's session (for admin backend / checkout-skip tests)
JAR_ACCT="/tmp/cookies_account.txt" # for account settings tests
JAR_RESET="/tmp/cookies_reset.txt"   # for password reset tests
JAR_PAY_SELLER="/tmp/cookies_pay_seller.txt"
JAR_PAY_BUYER="/tmp/cookies_pay_buyer.txt"

pass=0; fail=0
rm -f "$JAR_A" "$JAR_B" "$JAR_ANON" "$JAR_ADMIN" "$JAR_ACCT" "$JAR_RESET" "$JAR_PAY_SELLER" "$JAR_PAY_BUYER"

# Demo project default database password; the README says so too
DB_PASSWORD="${DB_PASSWORD:-123456}"

# req METHOD PATH [BODY] [JAR] → prints "HTTP_CODE\nBODY"
req() {
  local method="$1" path="$2" body="${3:-}" jar="${4:-$JAR_ANON}"
  local args=(-sS -X "$method" -b "$jar" -c "$jar" -w $'\n%{http_code}')
  if [ -n "$body" ]; then
    args+=(-H 'Content-Type: application/json' -d "$body")
  fi
  curl "${args[@]}" "${BASE}${path}"
}

check() {
  local label="$1" expect_code="$2" actual_code="$3" body="$4" expect_snippet="${5:-}"
  if [ "$actual_code" != "$expect_code" ]; then
    printf '  \033[1;31mFAIL\033[0m %-46s HTTP %s (expected %s)\n' "$label" "$actual_code" "$expect_code"
    printf '        %s\n' "$(echo "$body" | head -c 200)"
    fail=$((fail+1)); return
  fi
  if [ -n "$expect_snippet" ] && ! echo "$body" | grep -q "$expect_snippet"; then
    printf '  \033[1;31mFAIL\033[0m %-46s response does not contain "%s"\n' "$label" "$expect_snippet"
    printf '        %s\n' "$(echo "$body" | head -c 200)"
    fail=$((fail+1)); return
  fi
  printf '  \033[1;32mok\033[0m   %-46s HTTP %s\n' "$label" "$actual_code"
  pass=$((pass+1))
}

# Pull a field out of the response (simple JSON extraction, good enough)
jget() { echo "$1" | grep -oE "\"$2\":(\"[^\"]*\"|[0-9.]+|true|false|null)" | head -1 | cut -d: -f2- | tr -d '"'; }

echo "============================================================"
echo " API tests  →  $BASE"
echo "============================================================"

# ---------- health check ----------
echo
echo "[health check]"
R=$(req GET /health); BODY="${R%$'\n'*}"; CODE="${R##*$'\n'}"
check "GET /health" 200 "$CODE" "$BODY" '"status":"ok"'

# ---------- auth ----------
echo
echo "[auth]"
R=$(req GET /auth/me); CODE="${R##*$'\n'}"
check "Guest GET /auth/me returns data:null" 200 "$CODE" "${R%$'\n'*}" '"data":null'

R=$(req POST /auth/login '{"email":"alice@example.com","password":"wrong"}')
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Login with wrong password is rejected" 401 "$CODE" "$BODY" 'invalid_credentials'

R=$(req POST /auth/login '{"email":"alice@example.com","password":"alice1234"}' "$JAR_A")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "alice logs in successfully" 200 "$CODE" "$BODY" '"username":"alice"'
ALICE_ID=$(jget "$BODY" id)

R=$(req POST /auth/login '{"email":"bob@example.com","password":"bob1234"}' "$JAR_B")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "bob logs in successfully" 200 "$CODE" "$BODY" '"username":"bob"'

# Registration validation: mismatched passwords should 422 with field-level errors
R=$(req POST /auth/register '{"username":"tester1","email":"t1@example.com","password":"abc123","password_confirmation":"abc124"}')
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Registration password mismatch → 422" 422 "$CODE" "$BODY" 'password_confirmation'

R=$(req POST /auth/register '{"username":"tester2","email":"not-an-email","password":"abc123","password_confirmation":"abc123"}')
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Registration with malformed email → 422" 422 "$CODE" "$BODY" 'email'

R=$(req POST /auth/register '{"username":"tester3","email":"t3@example.com","password":"abc","password_confirmation":"abc"}')
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Registration with too-short password → 422" 422 "$CODE" "$BODY" 'password'

R=$(req POST /auth/register '{"username":"tester4","email":"alice@example.com","password":"abc123","password_confirmation":"abc123"}')
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Registration with existing email → 422" 422 "$CODE" "$BODY" 'Email already registered'

# ---------- account settings ----------
echo
echo "[account settings]"
R=$(req POST /auth/register '{"username":"acct_tmp","email":"acct_tmp@example.com","password":"secret123","password_confirmation":"secret123"}' "$JAR_ACCT")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Register account test user" 201 "$CODE" "$BODY" '"username":"acct_tmp"'
ACCT_ID=$(jget "$BODY" id)

R=$(req PATCH /account '{"username":"acct_tmp2"}' "$JAR_ACCT")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Update username" 200 "$CODE" "$BODY" '"username":"acct_tmp2"'

R=$(req PATCH /account '{"username":"alice"}' "$JAR_ACCT")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Duplicate username → 422" 422 "$CODE" "$BODY" 'Username already taken'

R=$(req PATCH /account '{"email":"acct_tmp2@example.com"}' "$JAR_ACCT")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Changing email without current password → 422" 422 "$CODE" "$BODY" 'current_password'

R=$(req PATCH /account '{"email":"acct_tmp2@example.com","current_password":"secret123"}' "$JAR_ACCT")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Change email with current password" 200 "$CODE" "$BODY" '"email":"acct_tmp2@example.com"'

R=$(req PATCH /account '{"email":"alice@example.com","current_password":"secret123"}' "$JAR_ACCT")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Duplicate email → 422" 422 "$CODE" "$BODY" 'Email already registered'

R=$(req POST /account/password '{"current_password":"wrong","password":"secret456","password_confirmation":"secret456"}' "$JAR_ACCT")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Wrong old password → 422" 422 "$CODE" "$BODY" 'Current password is incorrect'

R=$(req POST /account/password '{"current_password":"secret123","password":"secret456","password_confirmation":"secret456"}' "$JAR_ACCT")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Change password" 204 "$CODE" ""

R=$(req POST /auth/logout '' "$JAR_ACCT")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Logout after password change" 204 "$CODE" ""

R=$(req POST /auth/login '{"email":"acct_tmp2@example.com","password":"secret456"}' "$JAR_ACCT")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Log in with the new password" 200 "$CODE" "$BODY" '"username":"acct_tmp2"'

R=$(req PATCH /account '{"meetup_address":"Library"}' "$JAR_ACCT")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Update meetup address" 200 "$CODE" "$BODY" '"meetup_address":"Library"'

R=$(req GET /auth/me '' "$JAR_ACCT")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Meetup address is saved and returned" 200 "$CODE" "$BODY" '"meetup_address":"Library"'

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
AVATAR_FILE="$ROOT/public/uploads/seed2/book-cprog.jpg"
AVATAR_PATH=""
if [ -f "$AVATAR_FILE" ]; then
  R=$(curl -sS -X POST -b "$JAR_ACCT" -c "$JAR_ACCT" -w $'\n%{http_code}' -F "avatar=@$AVATAR_FILE" "$BASE/account/avatar")
  CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
  check "Upload avatar" 200 "$CODE" "$BODY" '"avatar_path":"uploads/'
  AVATAR_PATH=$(jget "$BODY" avatar_path)

  R=$(req GET /auth/me '' "$JAR_ACCT")
  CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
  check "GET /auth/me includes avatar" 200 "$CODE" "$BODY" '"avatar_path":"uploads/'

  echo 'not an image' > "/tmp/notimage_$$.txt"
  R=$(curl -sS -X POST -b "$JAR_ACCT" -c "$JAR_ACCT" -w $'\n%{http_code}' -F "avatar=@/tmp/notimage_$$.txt" "$BASE/account/avatar")
  CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
  check "Non-image avatar → 400" 400 "$CODE" "$BODY" 'Only JPG'
  rm -f "/tmp/notimage_$$.txt"
fi

# ---------- admin backend ----------
echo
echo "[admin backend]"
R=$(req GET /admin/users '' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Regular user accessing admin backend → 403" 403 "$CODE" "$BODY" 'Admin access required'

R=$(req POST /auth/login '{"email":"admin@example.com","password":"admin1234"}' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "admin login" 200 "$CODE" "$BODY" '"is_admin":true'

R=$(req POST /products '{"title":"Admin manage test item","price":10,"status":"used","category_id":1}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
ADMIN_PRODUCT_ID=$(jget "$BODY" id)

R=$(req PATCH "/admin/products/$ADMIN_PRODUCT_ID" '{"is_hidden":true}' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Admin hides listing" 200 "$CODE" "$BODY" '"is_hidden":true'

R=$(req GET '/products?per_page=100')
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
if echo "$BODY" | grep -q "\"id\":$ADMIN_PRODUCT_ID[,}]"; then
  printf '  \033[1;31mFAIL\033[0m %-46s\n' "Hidden listing is not visible in the storefront"
  fail=$((fail+1))
else
  printf '  \033[1;32mok\033[0m   %-46s\n' "Hidden listing is not visible in the storefront"
  pass=$((pass+1))
fi

R=$(req GET '/admin/products?per_page=100' '' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Admin can see hidden listings" 200 "$CODE" "$BODY" "\"id\":$ADMIN_PRODUCT_ID"

R=$(req GET '/admin/products?status=hidden&per_page=100' '' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Admin filters listings by status=hidden" 200 "$CODE" "$BODY" "\"id\":$ADMIN_PRODUCT_ID"

R=$(req DELETE "/admin/products/$ADMIN_PRODUCT_ID" '' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Admin force-deletes listing" 204 "$CODE" "$BODY"

R=$(req POST /products '{"title":"Report test item","price":10,"status":"used","category_id":1}' "$JAR_B")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
REPORT_PRODUCT_ID=$(jget "$BODY" id)

R=$(req POST "/products/$REPORT_PRODUCT_ID/report" '{"reason":"Counterfeit","details":"Wrong item shown in photos."}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Submit listing report" 201 "$CODE" "$BODY" '"id"'
REPORT_ID=$(jget "$BODY" id)

R=$(req POST "/products/$REPORT_PRODUCT_ID/report" '{"reason":"Counterfeit"}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Duplicate pending report is rejected" 409 "$CODE" "$BODY" 'pending report'

R=$(req GET '/admin/reports?per_page=100' '' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Admin views reports" 200 "$CODE" "$BODY" '"reason":"Counterfeit"'

R=$(req PATCH "/admin/reports/$REPORT_ID" '{"status":"resolved"}' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Admin resolves report" 200 "$CODE" "$BODY" '"status":"resolved"'

R=$(req GET '/admin/users?per_page=100' '' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Admin views user list" 200 "$CODE" "$BODY" '"is_active"'
check "User list includes listing count" 200 "$CODE" "$BODY" '"product_count"'

R=$(req PATCH "/admin/users/$ACCT_ID" '{"level":5}' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Admin updates buyer level" 200 "$CODE" "$BODY" '"level":5'

R=$(req GET "/users/$ACCT_ID")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Buyer level is now 5" 200 "$CODE" "$BODY" '"level":5'

R=$(req PATCH "/admin/users/$ACCT_ID" '{"seller_level":4}' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Admin updates seller level" 200 "$CODE" "$BODY" '"seller_level":4'

R=$(req GET "/users/$ACCT_ID")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Seller level is now 4" 200 "$CODE" "$BODY" '"seller_level":4'

R=$(req PATCH "/admin/users/$ACCT_ID" '{"is_active":false}' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Disable user" 200 "$CODE" "$BODY" '"is_active":false'

R=$(req GET /cart '' "$JAR_ACCT")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Session expires after being disabled → 401" 401 "$CODE" "$BODY" 'unauthorized'

R=$(req PATCH "/admin/users/$ACCT_ID" '{"is_active":true}' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Re-enable user" 200 "$CODE" "$BODY" '"is_active":true'

R=$(req POST /auth/login '{"email":"acct_tmp2@example.com","password":"secret456"}' "$JAR_ACCT")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Can log in again after re-enabling" 200 "$CODE" "$BODY" '"username":"acct_tmp2"'

R=$(req PATCH "/admin/users/$ACCT_ID" '{"is_admin":true}' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Grant admin" 200 "$CODE" "$BODY" '"is_admin":true'

R=$(req GET /admin/users '' "$JAR_ACCT")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "New admin can access the backend" 200 "$CODE" "$BODY" '"username"'

R=$(req PATCH "/admin/users/$ACCT_ID" '{"is_admin":false}' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Revoke admin" 200 "$CODE" "$BODY" '"is_admin":false'

R=$(req GET /admin/users '' "$JAR_ACCT")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "No access after revocation → 403" 403 "$CODE" "$BODY" 'Admin access required'

R=$(req PATCH /admin/users/1 '{"is_active":false}' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Cannot disable yourself → 409" 409 "$CODE" "$BODY" 'You cannot disable yourself'

R=$(req PATCH /admin/users/1 '{"is_admin":false}' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Cannot revoke your own admin rights → 409" 409 "$CODE" "$BODY" 'cannot remove your own admin rights'

# ---------- listing list and filtering ----------
echo
echo "[listing images]"
IMG_SRC="$ROOT/public/uploads/seed2/book-cprog.jpg"

R=$(curl -sS -D /tmp/pimg_headers.txt -o /tmp/pimg_body.bin -w '%{http_code}' "$BASE/products/3/image")
CODE="$R"; BODY=$(cat /tmp/pimg_headers.txt)
check "Legacy image fallback 200" 200 "$CODE" "$BODY" 'Content-Type: image/'
check "Legacy image cache header" 200 "$CODE" "$BODY" 'Cache-Control: public, max-age=86400'

R=$(curl -sS -X POST -b "$JAR_A" -c "$JAR_A" -w $'\n%{http_code}' \
  -F "title=Test listing: BLOB image" -F "price=12" -F "status=used" -F "category_id=1" \
  -F "image=@$IMG_SRC" "$BASE/products")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Publish with image" 201 "$CODE" "$BODY" '"image_path":"uploads/'
BLOB_ID=$(jget "$BODY" id)
BLOB_PATH=$(jget "$BODY" image_path)

curl -sS -o /tmp/pimg_blob.bin "$BASE/products/$BLOB_ID/image"
if [ -s /tmp/pimg_blob.bin ] && cmp -s "$IMG_SRC" /tmp/pimg_blob.bin; then
  printf '  \033[1;32mok\033[0m   %-46s\n' "BLOB image content matches"
  pass=$((pass+1))
else
  printf '  \033[1;31mFAIL\033[0m %-46s\n' "BLOB image content matches"
  fail=$((fail+1))
fi

CODE=$(curl -sS -o /dev/null -w '%{http_code}' "$BASE/products/999999/image")
check "Nonexistent listing image → 404" 404 "$CODE" ''

curl -sS -X DELETE -b "$JAR_A" -o /dev/null "$BASE/products/$BLOB_ID"
[ -n "$BLOB_PATH" ] && rm -f "$ROOT/public/$BLOB_PATH"

echo
echo "[listing list]"
R=$(req GET '/products?per_page=5'); CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "GET /products?per_page=5" 200 "$CODE" "$BODY" '"total_pages"'
P1_IDS=$(echo "$BODY" | grep -oE '"id":[0-9]+' | head -5 | cut -d: -f2 | tr '\n' ' ')

R=$(req GET '/products?per_page=5&page=2'); CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Page 2 can be fetched" 200 "$CODE" "$BODY" '"page":2'
P2_IDS=$(echo "$BODY" | grep -oE '"id":[0-9]+' | head -5 | cut -d: -f2 | tr '\n' ' ')

# ★ Pages do not overlap — this was the worst bug in the old version
overlap=0
for id in $P1_IDS; do
  for id2 in $P2_IDS; do
    [ "$id" = "$id2" ] && overlap=$((overlap+1))
  done
done
if [ "$overlap" -eq 0 ]; then
  printf '  \033[1;32mok\033[0m   %-46s pages 1 and 2 do not overlap\n' "Pagination does not repeat"
  pass=$((pass+1))
else
  printf '  \033[1;31mFAIL\033[0m %-46s has %s duplicates\n' "Pagination does not repeat" "$overlap"
  fail=$((fail+1))
fi

R=$(req GET '/products?status=new&per_page=100'); CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Filter by status=new" 200 "$CODE" "$BODY" '"status":"new"'

R=$(req GET '/products?status=bogus'); CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Invalid status → 400" 400 "$CODE" "$BODY" 'status must be new'

R=$(req GET '/products?category_id=1'); CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Filter by category" 200 "$CODE" "$BODY" '"total"'

R=$(req GET '/products?search=iPhone'); CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Keyword search" 200 "$CODE" "$BODY" 'iPhone'

R=$(req GET '/products?min_price=50&max_price=100&per_page=100'); CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Price range filter" 200 "$CODE" "$BODY" '"total"'

R=$(req GET '/products?min_price=500&max_price=100'); CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "min>max → 400" 400 "$CODE" "$BODY" 'min_price'

R=$(req GET '/products?page=abc'); CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "page=abc → 400" 400 "$CODE" "$BODY" 'must be a number'

R=$(req GET '/categories'); CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "GET /categories" 200 "$CODE" "$BODY" 'product_count'

R=$(req GET '/categories'); CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "GET /categories includes parent_id" 200 "$CODE" "$BODY" '"parent_id"'
check "Category tree includes the new top-level category" 200 "$CODE" "$BODY" '"name":"Vehicles"'
check "Category tree includes Daily Essentials" 200 "$CODE" "$BODY" '"name":"Daily Essentials"'
check "Category tree includes Virtual Items" 200 "$CODE" "$BODY" '"name":"Virtual Items"'

R=$(req GET '/products?category_id=14&per_page=100'); CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Filter by top-level category" 200 "$CODE" "$BODY" '"total"'
check "Top-level filter includes child-category listings" 200 "$CODE" "$BODY" '"id":3,'

# ---------- publish listing ----------
echo
echo "[publish listing]"
R=$(req GET /products/mine '' "$JAR_A"); CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "alice views her own listings" 200 "$CODE" "$BODY" '"total"'

R=$(req POST /products '{"title":"Test listing: old statistics textbook","price":42.5,"status":"used","category_id":1,"description":"Created by automated tests"}' "$JAR_A")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "alice publishes a listing" 201 "$CODE" "$BODY" '"title"'
NEW_ID=$(jget "$BODY" id)

R=$(req POST /products '{"title":"x","price":0,"status":"bogus"}' "$JAR_A")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Invalid publish params → 422" 422 "$CODE" "$BODY" 'validation_failed'

R=$(req POST /products '{"title":"Guest publish","price":10,"status":"new","category_id":1}')
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Guest publishing → 401" 401 "$CODE" "$BODY" 'unauthorized'

R=$(req POST /products '{"title":"Nonexistent category","price":10,"status":"new","category_id":99999}' "$JAR_A")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Nonexistent category → 422" 422 "$CODE" "$BODY" 'Category not found'

R=$(req GET "/products/${NEW_ID}"); CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Listing detail" 200 "$CODE" "$BODY" 'seller_rating'

R=$(req GET '/products/15/ebay-price'); CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "eBay reference price (all-Chinese title)" 200 "$CODE" "$BODY" '"data":null'

# ---------- permissions ----------
echo
echo "[permissions]"
R=$(req PATCH "/products/${NEW_ID}" '{"title":"bob tampering"}' "$JAR_B")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Non-seller editing a listing → 403" 403 "$CODE" "$BODY" 'forbidden'

R=$(req PATCH "/products/${NEW_ID}" '{"title":"alice edits own listing","price":39.9}' "$JAR_A")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Seller edits own listing" 200 "$CODE" "$BODY" 'alice edits own listing'

# PATCH listing: empty values must not bypass validation (used to blank the title or 500 outright)
R=$(req PATCH "/products/$NEW_ID" '{"title":""}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "PATCH with empty title → 422" 422 "$CODE" "$BODY" 'title'

R=$(req PATCH "/products/$NEW_ID" '{"price":""}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "PATCH with empty price → 422" 422 "$CODE" "$BODY" 'price'

R=$(req PATCH "/products/$NEW_ID" '{"status":""}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "PATCH with empty status → 422" 422 "$CODE" "$BODY" 'status'

R=$(req PATCH "/products/$NEW_ID" '{"category_id":null}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "PATCH category_id:null clears the category" 200 "$CODE" "$BODY" '"category_id":null'

# The edit form uses multipart PATCH (same as the frontend)
R=$(curl -sS -X POST -b "$JAR_A" -c "$JAR_A" -w $'\n%{http_code}' \
  -F "title=Test listing: form edit" -F "description=multipart PATCH" \
  -F "price=41.5" -F "status=used" -F "category_id=1" \
  "$BASE/products/$NEW_ID")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "multipart listing edit" 200 "$CODE" "$BODY" '"title":"Test listing: form edit"'

# Your own listing can be deleted (the delete button on the "My listings" page)
R=$(req POST /products '{"title":"Test listing: to be deleted","price":5,"status":"new","category_id":1}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Create listing to be deleted" 201 "$CODE" "$BODY" '"title"'
DEL_ID=$(jget "$BODY" id)

R=$(req DELETE "/products/$DEL_ID" '' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Delete your own listing" 204 "$CODE" ""

R=$(req GET "/products/$DEL_ID")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Listing no longer exists after deletion → 404" 404 "$CODE" "$BODY" 'Product not found'

# ---------- cart ----------
echo
echo "[cart]"
R=$(req GET /cart '' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "alice views cart (empty)" 200 "$CODE" "$BODY" '"item_count":0'

# alice buys bob's listing (find one bob published)
R=$(req GET '/products?seller_id=3&per_page=1')
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
BOB_PRODUCT=$(jget "$BODY" id)
check "Found bob's listing" 200 "$CODE" "$BODY" '"seller_id":3'

R=$(req POST /cart '{"product_id":'"$BOB_PRODUCT"'}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "alice adds to cart" 201 "$CODE" "$BODY" '"created":true'
CART_ID=$(jget "$BODY" id)

R=$(req POST /cart '{"product_id":'"$BOB_PRODUCT"'}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Adding again → deduplicated, no new row" 200 "$CODE" "$BODY" '"created":false'

R=$(req GET /cart '' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Cart has 1 item" 200 "$CODE" "$BODY" '"item_count":1'

# You cannot add your own listing
R=$(req GET '/products/mine?per_page=1' '' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
OWN_PRODUCT=$(jget "$BODY" id)
R=$(req POST /cart '{"product_id":'"$OWN_PRODUCT"'}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Adding your own listing → 400" 400 "$CODE" "$BODY" 'cannot add your own product'

R=$(req POST /cart '{"product_id":1.9}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Adding with fractional product_id → 422" 422 "$CODE" "$BODY" 'must be an integer'

R=$(req PATCH "/cart/$CART_ID" '{"quantity":2}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Cart no longer supports changing quantity → 405" 405 "$CODE" "$BODY" 'method_not_allowed'

R=$(req GET /cart '' "$JAR_ANON")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Guest viewing cart → 401" 401 "$CODE" "$BODY" 'unauthorized'

# ---------- checkout ----------
echo
echo "[checkout]"
R=$(req POST /orders '{}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "alice checks out the cart" 201 "$CODE" "$BODY" '"orders"'
ORDER_ID=$(echo "$BODY" | grep -oE '"id":[0-9]+' | head -1 | cut -d: -f2)

R=$(req GET /cart '' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Cart is empty after checkout" 200 "$CODE" "$BODY" '"item_count":0'

R=$(req GET "/products/$BOB_PRODUCT")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Listing is marked sold after checkout" 200 "$CODE" "$BODY" '"is_sold":true'

R=$(req POST /cart '{"product_id":'"$BOB_PRODUCT"'}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Sold listing cannot be added to cart → 409" 409 "$CODE" "$BODY" 'already been sold'

R=$(req POST /orders '{}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Checkout with an empty cart → 400" 400 "$CODE" "$BODY" 'Your cart is empty'

R=$(req GET '/orders?role=buyer' '' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "New order is pending" 200 "$CODE" "$BODY" '"status":"pending"'

R=$(req POST "/orders/$ORDER_ID/review" '{"rating":5}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Cannot review an unfinished order → 409" 409 "$CODE" "$BODY" 'completed'

R=$(req GET '/orders?role=seller' '' "$JAR_B")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "bob views his sales" 200 "$CODE" "$BODY" '"seller_id"'

R=$(req GET "/orders/$ORDER_ID" '' "$JAR_ANON")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Guest viewing order detail → 401" 401 "$CODE" "$BODY" 'unauthorized'

# ---------- order state machine ----------
echo
echo "[order state machine]"
R=$(req PATCH "/orders/$ORDER_ID" '{"status":"completed"}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Buyer cannot mark it completed directly" 409 "$CODE" "$BODY" 'confirm receipt'

R=$(req PATCH "/orders/$ORDER_ID" '{"status":"completed"}' "$JAR_B")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Seller cannot mark it completed directly" 409 "$CODE" "$BODY" 'confirm receipt'

R=$(req POST "/orders/$ORDER_ID/pay" '' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Order payment goes into escrow" 200 "$CODE" "$BODY" 'Payment successful'

R=$(req GET "/orders/$ORDER_ID" '' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Order awaits confirmation after payment" 200 "$CODE" "$BODY" '"escrow_status":"held"'

R=$(req POST "/orders/$ORDER_ID/confirm" '' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Buyer confirms receipt" 200 "$CODE" "$BODY" '"escrow_status":"released"'

R=$(req PATCH "/orders/$ORDER_ID" '{"status":"cancelled"}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Completed order cannot be cancelled → 409" 409 "$CODE" "$BODY" 'cannot be changed to'

# ---------- reviews ----------
echo
echo "[reviews]"
R=$(req POST "/orders/$ORDER_ID/review" '{"rating":5,"comment":"Book is like new, fast shipping, great seller!"}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "alice reviews the order" 201 "$CODE" "$BODY" '"rating":5'
REVIEW_ID=$(jget "$BODY" id)

R=$(req POST "/orders/$ORDER_ID/review" '{"rating":4,"comment":"Reviewing again"}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Duplicate review → 409" 409 "$CODE" "$BODY" 'already been reviewed'

R=$(req POST "/orders/$ORDER_ID/review" '{"rating":9}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Rating 9 → 422" 422 "$CODE" "$BODY" 'rating'

R=$(req GET '/users/3/reviews')
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "GET /users/3/reviews" 200 "$CODE" "$BODY" '"average"'

R=$(req GET '/users/3')
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "GET /users/3 public profile" 200 "$CODE" "$BODY" 'product_count'

# ---------- review replies / listing comments / homepage comments ----------
echo
echo "[review replies / comments]"
R=$(req POST "/reviews/$REVIEW_ID/reply" '{"reply":"Not the seller"}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Non-seller replying to a review → 403" 403 "$CODE" "$BODY" 'Only the seller'

R=$(req POST "/reviews/$REVIEW_ID/reply" '{"reply":"Thanks for the support, the book really is like new."}' "$JAR_B")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Seller replies to review" 200 "$CODE" "$BODY" '"reply_text"'

R=$(req GET '/users/3/reviews')
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Review list includes the seller reply" 200 "$CODE" "$BODY" '"reply_text"'

R=$(req POST "/orders/$ORDER_ID/review" '{"rating":4,"comment":"Smooth handover, ready on time."}' "$JAR_B")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Seller reviews buyer" 201 "$CODE" "$BODY" '"direction":"seller_to_buyer"'

R=$(req GET '/users/2/reviews?role=buyer')
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Buyer profile rating" 200 "$CODE" "$BODY" '"rating":4'

R=$(req GET "/products/$BOB_PRODUCT/reviews")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Listing review pagination" 200 "$CODE" "$BODY" '"pagination"'

# Listing comments: alice asks, bob replies as the seller
R=$(req POST "/products/$BOB_PRODUCT/comments" '{"body":"Are the accessories still included?"}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "alice posts a listing comment" 201 "$CODE" "$BODY" '"id"'
COMMENT_ID=$(jget "$BODY" id)

R=$(req POST "/products/$BOB_PRODUCT/comments" '{"body":"Yes, the original charger is included too.","parent_id":'"$COMMENT_ID"'}' "$JAR_B")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Seller replies to listing comment" 201 "$CODE" "$BODY" '"id"'
REPLY_ID=$(jget "$BODY" id)

R=$(req GET "/products/$BOB_PRODUCT/comments")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Listing comment includes replies" 200 "$CODE" "$BODY" '"replies"'

R=$(req GET '/admin/product-comments?per_page=5' '' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Admin views listing comments" 200 "$CODE" "$BODY" '"product_title"'

R=$(req PATCH "/admin/product-comments/$COMMENT_ID" '{"is_hidden":true}' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Admin hides listing comment" 200 "$CODE" "$BODY" '"is_hidden":true'

R=$(req GET "/products/$BOB_PRODUCT/comments")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Hidden listing comment is not public" 200 "$CODE" "$BODY" '"total":0'

# Profile comment board
R=$(req POST '/users/3/comments' '{"body":"bob is a pleasure to trade with"}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "alice posts a homepage comment" 201 "$CODE" "$BODY" '"id"'
PCOMMENT_ID=$(jget "$BODY" id)

R=$(req GET '/users/3/comments')
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Homepage comment list" 200 "$CODE" "$BODY" '"author_name"'

R=$(req GET '/admin/profile-comments?per_page=5' '' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Admin views homepage comments" 200 "$CODE" "$BODY" '"profile_name"'

R=$(req DELETE "/users/3/comments/$PCOMMENT_ID" '' "$JAR_B")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Profile owner deletes comment" 204 "$CODE" ""

R=$(req GET '/users/3/comments')
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Deleted homepage comment is not public" 200 "$CODE" "$BODY" '"total":0'

# Admin hides / restores a review
R=$(req GET '/admin/reviews?per_page=5' '' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Admin views reviews" 200 "$CODE" "$BODY" '"is_hidden"'

R=$(req GET '/users/3/reviews?per_page=100')
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
TOTAL_BEFORE=$(jget "$BODY" total)

R=$(req PATCH "/admin/reviews/$REVIEW_ID" '{"is_hidden":true}' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Admin hides review" 200 "$CODE" "$BODY" '"is_hidden":true'

R=$(req GET '/users/3/reviews?per_page=100')
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Hidden review is not public" 200 "$CODE" "$BODY" "\"total\":$((TOTAL_BEFORE - 1))"

if echo "$BODY" | grep -q "\"id\":$REVIEW_ID[,}]"; then
  printf '  \033[1;31mFAIL\033[0m %-46s\n' "Hidden review is not in the public list"
  fail=$((fail+1))
else
  printf '  \033[1;32mok\033[0m   %-46s\n' "Hidden review is not in the public list"
  pass=$((pass+1))
fi

R=$(req PATCH "/admin/reviews/$REVIEW_ID" '{"is_hidden":false}' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Admin restores review" 200 "$CODE" "$BODY" '"is_hidden":false'


# ---------- order cancellation ----------
echo
echo "[order cancellation]"
R=$(req POST /products '{"title":"Test listing: cancellation flow","price":9.9,"status":"used","category_id":1}' "$JAR_B")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "bob publishes a listing for cancellation" 201 "$CODE" "$BODY" '"title"'
CANCEL_PRODUCT_ID=$(jget "$BODY" id)

R=$(req POST /cart '{"product_id":'"$CANCEL_PRODUCT_ID"'}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "alice adds the cancellation listing to cart" 201 "$CODE" "$BODY" '"created":true'
CANCEL_CART_ID=$(jget "$BODY" id)

R=$(req POST /orders '{"cart_ids":['"$CANCEL_CART_ID"']}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "alice checks out the cancellation listing" 201 "$CODE" "$BODY" '"orders"'
CANCEL_ORDER_ID=$(echo "$BODY" | grep -oE '"id":[0-9]+' | head -1 | cut -d: -f2)

R=$(req GET "/products/$CANCEL_PRODUCT_ID")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Cancellation listing is marked sold" 200 "$CODE" "$BODY" '"is_sold":true'

R=$(req PATCH "/orders/$CANCEL_ORDER_ID" '{"status":"cancelled"}' "$JAR_B")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Seller cancels order → 403" 403 "$CODE" "$BODY" 'Only the buyer'

R=$(req PATCH "/orders/$CANCEL_ORDER_ID" '{"status":"cancelled"}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Buyer cancels order" 200 "$CODE" "$BODY" '"status":"cancelled"'

R=$(req GET "/products/$CANCEL_PRODUCT_ID")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Listing is relisted after cancellation" 200 "$CODE" "$BODY" '"is_sold":false'

R=$(req POST "/orders/$CANCEL_ORDER_ID/review" '{"rating":5}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Cancelled order cannot be reviewed → 400" 400 "$CODE" "$BODY" 'Cancelled orders cannot be reviewed'

# ---------- checkout skips unavailable listings ----------
echo
echo "[checkout skips unavailable listings]"
R=$(req POST /products '{"title":"Test listing: skip A","price":11.0,"status":"used","category_id":1}' "$JAR_B")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "bob publishes skip A" 201 "$CODE" "$BODY" '"title"'
SKIP_A=$(jget "$BODY" id)

R=$(req POST /products '{"title":"Test listing: skip B","price":12.5,"status":"used","category_id":1}' "$JAR_B")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "bob publishes skip B" 201 "$CODE" "$BODY" '"title"'
SKIP_B=$(jget "$BODY" id)

R=$(req POST /cart '{"product_id":'"$SKIP_A"'}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "alice adds skip A to cart" 201 "$CODE" "$BODY" '"created":true'

R=$(req POST /cart '{"product_id":'"$SKIP_B"'}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "alice adds skip B to cart" 201 "$CODE" "$BODY" '"created":true'

R=$(req POST /auth/login '{"email":"admin@example.com","password":"admin1234"}' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "admin logs in successfully" 200 "$CODE" "$BODY" '"username":"admin"'

R=$(req POST /cart '{"product_id":'"$SKIP_A"'}' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "admin adds skip A to cart" 201 "$CODE" "$BODY" '"created":true'
ADMIN_CART_ID=$(jget "$BODY" id)

R=$(req POST /orders '{"cart_ids":['"$ADMIN_CART_ID"']}' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "admin buys skip A" 201 "$CODE" "$BODY" '"orders"'
ADMIN_ORDER_ID=$(echo "$BODY" | grep -oE '"id":[0-9]+' | head -1 | cut -d: -f2)

R=$(req GET "/products/$SKIP_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "skip A is sold" 200 "$CODE" "$BODY" '"is_sold":true'

# In alice's cart A is no longer available and B is still on sale; checkout should order only B and leave A in the cart
R=$(req GET /auth/me '' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
ALICE_LEVEL=$(jget "$BODY" level)
EXPECTED_TOTAL=$(LC_ALL=C awk -v level="${ALICE_LEVEL:-1}" 'BEGIN { printf "%.2f", 12.50 * (1 - (level - 1) * 0.01) }')

R=$(req POST /orders '{}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Checkout skips sold listings" 201 "$CODE" "$BODY" '"orders"'
check "Checkout total only covers listings still on sale" 201 "$CODE" "$BODY" "\"total\":$EXPECTED_TOTAL"
SKIP_ORDER_ID=$(echo "$BODY" | grep -oE '"id":[0-9]+' | head -1 | cut -d: -f2)

R=$(req GET /cart '' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "Unavailable listing stays in the cart" 200 "$CODE" "$BODY" '"item_count":1'
check "Unavailable listing is not counted in the checkout quantity" 200 "$CODE" "$BODY" '"quantity":0'
check "Unavailable listing is flagged available:false" 200 "$CODE" "$BODY" '"available":false'

# ---------- routing details ----------
echo
echo "[routing]"
R=$(req GET /nosuchthing); CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Nonexistent endpoint → 404" 404 "$CODE" "$BODY" 'not_found'

R=$(req DELETE /auth/login); CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Wrong method → 405" 405 "$CODE" "$BODY" 'method_not_allowed'

R=$(req GET /products/999999); CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Nonexistent listing → 404" 404 "$CODE" "$BODY" 'not_found'

# ---------- logout ----------
echo
echo "[logout]"
R=$(req POST /auth/logout '' "$JAR_A"); CODE="${R##*$'\n'}"
check "Logout returns 204" 204 "$CODE" ""

R=$(req GET /cart '' "$JAR_A"); CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Cart after logout → 401" 401 "$CODE" "$BODY" 'unauthorized'

# ---------- password reset ----------
echo
echo "[password reset]"
R=$(req POST /auth/register '{"username":"reset_tmp","email":"reset_tmp@example.com","password":"oldpass123","password_confirmation":"oldpass123"}' "$JAR_RESET")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Create reset test account" 201 "$CODE" "$BODY" '"username":"reset_tmp"'

R=$(req POST /auth/forgot-password '{"email":"reset_tmp@example.com"}')
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Forgot password returns code" 200 "$CODE" "$BODY" '"code":"'
RESET_CODE=$(jget "$BODY" code)

R=$(req POST /auth/forgot-password '{"email":"nobody@example.com"}')
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Nonexistent email returns the generic message" 200 "$CODE" "$BODY" 'If the email exists'

R=$(req POST /auth/reset-password '{"email":"reset_tmp@example.com","code":"'"$RESET_CODE"'","new_password":"newpass123"}')
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Reset password" 200 "$CODE" "$BODY" 'Password reset successfully.'

R=$(req POST /auth/reset-password '{"email":"reset_tmp@example.com","code":"'"$RESET_CODE"'","new_password":"another123"}')
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Code cannot be reused" 422 "$CODE" "$BODY" 'Invalid or expired code'

R=$(req POST /auth/login '{"email":"reset_tmp@example.com","password":"newpass123"}' "$JAR_RESET")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Login with the reset password" 200 "$CODE" "$BODY" '"username":"reset_tmp"'

# ---------- simulated payment and wallet ----------
echo
echo "[simulated payment and wallet]"
R=$(req POST /auth/register '{"username":"pay_seller","email":"pay_seller@example.com","password":"pass1234","password_confirmation":"pass1234"}' "$JAR_PAY_SELLER")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Create payment seller" 201 "$CODE" "$BODY" '"username":"pay_seller"'
R=$(req POST /auth/register '{"username":"pay_buyer","email":"pay_buyer@example.com","password":"pass1234","password_confirmation":"pass1234"}' "$JAR_PAY_BUYER")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Create payment buyer" 201 "$CODE" "$BODY" '"username":"pay_buyer"'
PAY_BUYER_ID=$(jget "$BODY" id)

R=$(req PATCH "/admin/users/$PAY_BUYER_ID" '{"level":5}' "$JAR_ADMIN")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Set buyer to Lv5" 200 "$CODE" "$BODY" '"level":5'

R=$(req POST /products '{"title":"Payment test item","price":100,"status":"used","category_id":1}' "$JAR_PAY_SELLER")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Payment seller publishes a listing" 201 "$CODE" "$BODY" '"title":"Payment test item"'
PAY_PRODUCT_ID=$(jget "$BODY" id)

R=$(req PATCH /account '{"meetup_address":"Library"}' "$JAR_PAY_SELLER")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Seller sets meetup address" 200 "$CODE" "$BODY" '"meetup_address":"Library"'

R=$(req GET "/products/$PAY_PRODUCT_ID")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Listing detail includes the seller meetup address" 200 "$CODE" "$BODY" '"seller_meetup_address":"Library"'
check "Listing detail includes the seller's last active time" 200 "$CODE" "$BODY" '"seller_last_active_at"'

R=$(req POST /cart '{"product_id":'"$PAY_PRODUCT_ID"'}' "$JAR_PAY_BUYER")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Payment buyer adds to cart" 201 "$CODE" "$BODY" '"created":true'
PAY_CART_ID=$(jget "$BODY" id)

R=$(req POST /orders '{"cart_ids":['"$PAY_CART_ID"']}' "$JAR_PAY_BUYER")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Payment buyer checks out" 201 "$CODE" "$BODY" '"orders"'
check "Lv5 checkout of 100 pays 96" 201 "$CODE" "$BODY" '"total":96'
check "Platform fee is 10" 201 "$CODE" "$BODY" '"platform_fee":10'
check "Seller receives 90" 201 "$CODE" "$BODY" '"seller_income":90'
PAY_ORDER_ID=$(echo "$BODY" | grep -oE '"id":[0-9]+' | head -1 | cut -d: -f2)

R=$(req POST "/orders/$PAY_ORDER_ID/pay" '' "$JAR_PAY_BUYER")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Buyer pays for the order" 200 "$CODE" "$BODY" 'Payment successful'

R=$(req GET "/orders/$PAY_ORDER_ID" '' "$JAR_PAY_BUYER")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Order enters escrow after payment" 200 "$CODE" "$BODY" '"escrow_status":"held"'

R=$(req GET /auth/me '' "$JAR_PAY_SELLER")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Seller balance unchanged while in escrow" 200 "$CODE" "$BODY" '"balance":0'
check "Seller level unchanged while in escrow" 200 "$CODE" "$BODY" '"seller_level":1'

R=$(req POST "/orders/$PAY_ORDER_ID/pay" '' "$JAR_PAY_BUYER")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Duplicate payment is rejected" 409 "$CODE" "$BODY" 'no longer payable'

R=$(req POST "/orders/$PAY_ORDER_ID/confirm" '' "$JAR_PAY_BUYER")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Funds released after the buyer confirms" 200 "$CODE" "$BODY" '"escrow_status":"released"'

R=$(req GET /auth/me '' "$JAR_PAY_SELLER")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Seller balance increases after release" 200 "$CODE" "$BODY" '"balance":90'
check "Seller level goes up after release" 200 "$CODE" "$BODY" '"seller_level":2'

R=$(req POST /users/withdraw '{"amount":10}' "$JAR_PAY_SELLER")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Seller withdraws" 200 "$CODE" "$BODY" 'Withdrawal successful'

R=$(req GET /auth/me '' "$JAR_PAY_SELLER")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Balance after withdrawal" 200 "$CODE" "$BODY" '"balance":80'

R=$(req POST /users/withdraw '{"amount":999}' "$JAR_PAY_SELLER")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Withdrawal over the balance is rejected" 422 "$CODE" "$BODY" 'Insufficient balance'

# Admin releases funds manually
R=$(req POST /products '{"title":"Escrow release item","price":50,"status":"used","category_id":1}' "$JAR_PAY_SELLER")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
ADMIN_RELEASE_PRODUCT_ID=$(jget "$BODY" id)
R=$(req POST /cart '{"product_id":'"$ADMIN_RELEASE_PRODUCT_ID"'}' "$JAR_PAY_BUYER")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
ADMIN_RELEASE_CART_ID=$(jget "$BODY" id)
R=$(req POST /orders '{"cart_ids":['"$ADMIN_RELEASE_CART_ID"']}' "$JAR_PAY_BUYER")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
ADMIN_RELEASE_ORDER_ID=$(echo "$BODY" | grep -oE '"id":[0-9]+' | head -1 | cut -d: -f2)
R=$(req POST "/orders/$ADMIN_RELEASE_ORDER_ID/pay" '' "$JAR_PAY_BUYER")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Release test order enters escrow" 200 "$CODE" "$BODY" 'Payment successful'

R=$(req GET '/admin/orders?escrow_status=held&per_page=100' '' "$JAR_ADMIN")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Admin views escrow orders" 200 "$CODE" "$BODY" "\"id\":$ADMIN_RELEASE_ORDER_ID"

R=$(req POST "/admin/orders/$ADMIN_RELEASE_ORDER_ID/release" '' "$JAR_ADMIN")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Admin releases funds manually" 200 "$CODE" "$BODY" '"escrow_status":"released"'

# Admin issues a full refund; the listing stays sold and is not relisted automatically
R=$(req POST /products '{"title":"Escrow refund item","price":40,"status":"used","category_id":1}' "$JAR_PAY_SELLER")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
ADMIN_REFUND_PRODUCT_ID=$(jget "$BODY" id)
R=$(req POST /cart '{"product_id":'"$ADMIN_REFUND_PRODUCT_ID"'}' "$JAR_PAY_BUYER")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
ADMIN_REFUND_CART_ID=$(jget "$BODY" id)
R=$(req POST /orders '{"cart_ids":['"$ADMIN_REFUND_CART_ID"']}' "$JAR_PAY_BUYER")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
ADMIN_REFUND_ORDER_ID=$(echo "$BODY" | grep -oE '"id":[0-9]+' | head -1 | cut -d: -f2)
R=$(req POST "/orders/$ADMIN_REFUND_ORDER_ID/pay" '' "$JAR_PAY_BUYER")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
R=$(req POST "/admin/orders/$ADMIN_REFUND_ORDER_ID/refund" '' "$JAR_ADMIN")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Admin issues a full refund" 200 "$CODE" "$BODY" '"escrow_status":"refunded"'

R=$(req GET "/products/$ADMIN_REFUND_PRODUCT_ID")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "Refunded listing stays sold" 200 "$CODE" "$BODY" '"is_sold":true'

# ---------- clean up test data ----------
echo
echo "[cleanup]"
# If a test failed midway a variable may be empty; default it first so we never build invalid SQL
[ -z "$ORDER_ID" ] && ORDER_ID=0
[ -z "$CANCEL_ORDER_ID" ] && CANCEL_ORDER_ID=0
[ -z "$NEW_ID" ] && NEW_ID=0
[ -z "$CANCEL_PRODUCT_ID" ] && CANCEL_PRODUCT_ID=0
[ -z "$BOB_PRODUCT" ] && BOB_PRODUCT=0
[ -z "$ADMIN_ORDER_ID" ] && ADMIN_ORDER_ID=0
[ -z "$SKIP_ORDER_ID" ] && SKIP_ORDER_ID=0
[ -z "$SKIP_A" ] && SKIP_A=0
[ -z "$SKIP_B" ] && SKIP_B=0
[ -z "$DEL_ID" ] && DEL_ID=0
[ -z "$ACCT_ID" ] && ACCT_ID=0
[ -z "$AVATAR_PATH" ] && AVATAR_PATH=""
[ -z "$COMMENT_ID" ] && COMMENT_ID=0
[ -z "$REPLY_ID" ] && REPLY_ID=0
[ -z "$PCOMMENT_ID" ] && PCOMMENT_ID=0
[ -z "$PAY_PRODUCT_ID" ] && PAY_PRODUCT_ID=0
[ -z "$PAY_ORDER_ID" ] && PAY_ORDER_ID=0
[ -z "$ADMIN_PRODUCT_ID" ] && ADMIN_PRODUCT_ID=0
[ -z "$REPORT_PRODUCT_ID" ] && REPORT_PRODUCT_ID=0
[ -z "$ADMIN_RELEASE_ORDER_ID" ] && ADMIN_RELEASE_ORDER_ID=0
[ -z "$ADMIN_RELEASE_PRODUCT_ID" ] && ADMIN_RELEASE_PRODUCT_ID=0
[ -z "$ADMIN_REFUND_ORDER_ID" ] && ADMIN_REFUND_ORDER_ID=0
[ -z "$ADMIN_REFUND_PRODUCT_ID" ] && ADMIN_REFUND_PRODUCT_ID=0

if [ -n "$DB_PASSWORD" ]; then
    MYSQL_PWD="$DB_PASSWORD" mysql -h 127.0.0.1 -u root online_marketplace -e "
      DELETE FROM product_comments WHERE id IN (${COMMENT_ID}, ${REPLY_ID}) OR parent_id = ${COMMENT_ID};
      DELETE FROM profile_comments WHERE id = ${PCOMMENT_ID};
      DELETE FROM reviews WHERE order_id IN (${ORDER_ID}, ${CANCEL_ORDER_ID}, ${ADMIN_ORDER_ID}, ${SKIP_ORDER_ID});
      DELETE FROM orders WHERE id IN (${ORDER_ID}, ${CANCEL_ORDER_ID}, ${ADMIN_ORDER_ID}, ${SKIP_ORDER_ID});
      DELETE FROM products WHERE id IN (${NEW_ID}, ${CANCEL_PRODUCT_ID}, ${SKIP_A}, ${SKIP_B}, ${DEL_ID});
      UPDATE products SET is_sold = 0 WHERE id = ${BOB_PRODUCT};
      DELETE FROM orders WHERE id IN (${PAY_ORDER_ID}, ${ADMIN_RELEASE_ORDER_ID}, ${ADMIN_REFUND_ORDER_ID});
      DELETE FROM products WHERE id IN (${PAY_PRODUCT_ID}, ${ADMIN_PRODUCT_ID}, ${REPORT_PRODUCT_ID}, ${ADMIN_RELEASE_PRODUCT_ID}, ${ADMIN_REFUND_PRODUCT_ID});
      DELETE FROM users WHERE id = ${ACCT_ID}
         OR email IN ('t1@example.com','t3@example.com','acct_tmp@example.com','acct_tmp2@example.com','reset_tmp@example.com','pay_seller@example.com','pay_buyer@example.com');
    " 2>/dev/null && printf '  \033[1;32mok\033[0m   test data cleaned up\n'

    echo '  Note: the level recompute should only run on the demo database, never on production'
    MYSQL_PWD="$DB_PASSWORD" mysql -h 127.0.0.1 -u root online_marketplace -e "
      UPDATE users u
        LEFT JOIN (
          SELECT buyer_id, SUM(total_price) AS spent
            FROM orders WHERE status = 'completed'
           GROUP BY buyer_id
        ) s ON s.buyer_id = u.id
         SET u.total_spent = COALESCE(s.spent, 0.00),
             u.level = CASE
               WHEN COALESCE(s.spent, 0.00) >= 100000 THEN 6
               WHEN COALESCE(s.spent, 0.00) >= 10000  THEN 5
               WHEN COALESCE(s.spent, 0.00) >= 1000   THEN 4
               WHEN COALESCE(s.spent, 0.00) >= 100    THEN 3
               WHEN COALESCE(s.spent, 0.00) >= 10     THEN 2
               ELSE 1
             END;

      UPDATE users u
        LEFT JOIN (
          SELECT p.seller_id, COUNT(*) AS sales
            FROM orders o
            JOIN products p ON o.product_id = p.id
           WHERE o.status = 'completed'
           GROUP BY p.seller_id
        ) s ON s.seller_id = u.id
         SET u.seller_level = CASE
               WHEN COALESCE(s.sales, 0) >= 2000 THEN 6
               WHEN COALESCE(s.sales, 0) >= 1000 THEN 5
               WHEN COALESCE(s.sales, 0) >= 100  THEN 4
               WHEN COALESCE(s.sales, 0) >= 10   THEN 3
               WHEN COALESCE(s.sales, 0) >= 1    THEN 2
               ELSE 1
             END;
    " 2>/dev/null
else
    printf '  \033[1;33mskip\033[0m   DB_PASSWORD not provided, test data not cleaned up\n'
fi

if [ -n "$AVATAR_PATH" ]; then
  rm -f "$ROOT/public/$AVATAR_PATH"
fi

echo
echo "============================================================"
printf ' passed \033[1;32m%d\033[0m   failed \033[1;31m%d\033[0m\n' "$pass" "$fail"
echo "============================================================"
[ "$fail" -eq 0 ] || exit 1
