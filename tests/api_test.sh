#!/bin/bash
# ============================================================
#  API 端到端测试
#
#  用法:
#      bash tests/api_test.sh [端口]        # 默认 8000
#
#  覆盖：健康检查 / 认证 / 账号设置 / 商品 / 购物车 / 下单 / 订单状态机 / 取消 /
#        评价 / 评价回复 / 商品留言 / 主页留言 / 管理员后台 / 权限 / 校验
#  用 cookie jar 维持会话，模拟真实浏览器行为。
# ============================================================
set -uo pipefail

PORT="${1:-8000}"
BASE="http://127.0.0.1:${PORT}/api/v1"
JAR_A="/tmp/cookies_alice.txt"     # alice 的会话
JAR_B="/tmp/cookies_bob.txt"       # bob 的会话
JAR_ANON="/tmp/cookies_anon.txt"
JAR_ADMIN="/tmp/cookies_admin.txt" # admin 的会话（管理后台 / 结算跳过测试用）
JAR_ACCT="/tmp/cookies_account.txt" # 账号设置测试用

pass=0; fail=0
rm -f "$JAR_A" "$JAR_B" "$JAR_ANON" "$JAR_ADMIN" "$JAR_ACCT"

# 清理测试数据用的数据库密码：优先环境变量，其次本机 config.local.php
DB_PASSWORD="${DB_PASSWORD:-}"
if [ -z "$DB_PASSWORD" ]; then
    CONFIG_LOCAL="$(cd "$(dirname "$0")/.." && pwd)/config/config.local.php"
    if [ -f "$CONFIG_LOCAL" ]; then
        DB_PASSWORD="$(php -r '$c = require $argv[1]; echo $c["db"]["password"] ?? "";' "$CONFIG_LOCAL" 2>/dev/null)"
    fi
fi

# req METHOD PATH [BODY] [JAR] → 输出 "HTTP_CODE\nBODY"
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
    printf '  \033[1;31mFAIL\033[0m %-46s HTTP %s (期望 %s)\n' "$label" "$actual_code" "$expect_code"
    printf '        %s\n' "$(echo "$body" | head -c 200)"
    fail=$((fail+1)); return
  fi
  if [ -n "$expect_snippet" ] && ! echo "$body" | grep -q "$expect_snippet"; then
    printf '  \033[1;31mFAIL\033[0m %-46s 响应里没有 "%s"\n' "$label" "$expect_snippet"
    printf '        %s\n' "$(echo "$body" | head -c 200)"
    fail=$((fail+1)); return
  fi
  printf '  \033[1;32mok\033[0m   %-46s HTTP %s\n' "$label" "$actual_code"
  pass=$((pass+1))
}

# 从响应里取字段（简易 JSON 取值，够用）
jget() { echo "$1" | grep -oE "\"$2\":(\"[^\"]*\"|[0-9.]+|true|false|null)" | head -1 | cut -d: -f2- | tr -d '"'; }

echo "============================================================"
echo " API 测试  →  $BASE"
echo "============================================================"

# ---------- 健康检查 ----------
echo
echo "[健康检查]"
R=$(req GET /health); BODY="${R%$'\n'*}"; CODE="${R##*$'\n'}"
check "GET /health" 200 "$CODE" "$BODY" '"status":"ok"'

# ---------- 认证 ----------
echo
echo "[认证]"
R=$(req GET /auth/me); CODE="${R##*$'\n'}"
check "未登录 GET /auth/me 返回 data:null" 200 "$CODE" "${R%$'\n'*}" '"data":null'

R=$(req POST /auth/login '{"email":"alice@example.com","password":"wrong"}')
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "错误密码登录被拒" 401 "$CODE" "$BODY" 'invalid_credentials'

R=$(req POST /auth/login '{"email":"alice@example.com","password":"alice1234"}' "$JAR_A")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "alice 登录成功" 200 "$CODE" "$BODY" '"username":"alice"'
ALICE_ID=$(jget "$BODY" id)

R=$(req POST /auth/login '{"email":"bob@example.com","password":"bob1234"}' "$JAR_B")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "bob 登录成功" 200 "$CODE" "$BODY" '"username":"bob"'

# 注册校验：密码不匹配应 422 且带字段级错误
R=$(req POST /auth/register '{"username":"tester1","email":"t1@example.com","password":"abc123","password_confirmation":"abc124"}')
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "注册密码不一致 → 422" 422 "$CODE" "$BODY" 'password_confirmation'

R=$(req POST /auth/register '{"username":"tester2","email":"not-an-email","password":"abc123","password_confirmation":"abc123"}')
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "注册邮箱格式错 → 422" 422 "$CODE" "$BODY" 'email'

R=$(req POST /auth/register '{"username":"tester3","email":"t3@example.com","password":"abc","password_confirmation":"abc"}')
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "注册密码太短 → 422" 422 "$CODE" "$BODY" 'password'

R=$(req POST /auth/register '{"username":"tester4","email":"alice@example.com","password":"abc123","password_confirmation":"abc123"}')
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "注册已存在邮箱 → 422" 422 "$CODE" "$BODY" '已被注册'

# ---------- 账号设置 ----------
echo
echo "[账号设置]"
R=$(req POST /auth/register '{"username":"acct_tmp","email":"acct_tmp@example.com","password":"secret123","password_confirmation":"secret123"}' "$JAR_ACCT")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "注册账号测试用户" 201 "$CODE" "$BODY" '"username":"acct_tmp"'
ACCT_ID=$(jget "$BODY" id)

R=$(req PATCH /account '{"username":"acct_tmp2"}' "$JAR_ACCT")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "修改用户名" 200 "$CODE" "$BODY" '"username":"acct_tmp2"'

R=$(req PATCH /account '{"username":"alice"}' "$JAR_ACCT")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "用户名重复 → 422" 422 "$CODE" "$BODY" '已被占用'

R=$(req PATCH /account '{"email":"acct_tmp2@example.com"}' "$JAR_ACCT")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "改邮箱缺当前密码 → 422" 422 "$CODE" "$BODY" 'current_password'

R=$(req PATCH /account '{"email":"acct_tmp2@example.com","current_password":"secret123"}' "$JAR_ACCT")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "带当前密码修改邮箱" 200 "$CODE" "$BODY" '"email":"acct_tmp2@example.com"'

R=$(req PATCH /account '{"email":"alice@example.com","current_password":"secret123"}' "$JAR_ACCT")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "邮箱重复 → 422" 422 "$CODE" "$BODY" '已被注册'

R=$(req POST /account/password '{"current_password":"wrong","password":"secret456","password_confirmation":"secret456"}' "$JAR_ACCT")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "旧密码错误 → 422" 422 "$CODE" "$BODY" '当前密码不正确'

R=$(req POST /account/password '{"current_password":"secret123","password":"secret456","password_confirmation":"secret456"}' "$JAR_ACCT")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "修改密码" 204 "$CODE" ""

R=$(req POST /auth/logout '' "$JAR_ACCT")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "改密后登出" 204 "$CODE" ""

R=$(req POST /auth/login '{"email":"acct_tmp2@example.com","password":"secret456"}' "$JAR_ACCT")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "用新密码登录" 200 "$CODE" "$BODY" '"username":"acct_tmp2"'

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
AVATAR_FILE="$ROOT/public/uploads/seed2/book-cprog.jpg"
AVATAR_PATH=""
if [ -f "$AVATAR_FILE" ]; then
  R=$(curl -sS -X POST -b "$JAR_ACCT" -c "$JAR_ACCT" -w $'\n%{http_code}' -F "avatar=@$AVATAR_FILE" "$BASE/account/avatar")
  CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
  check "上传头像" 200 "$CODE" "$BODY" '"avatar_path":"uploads/'
  AVATAR_PATH=$(jget "$BODY" avatar_path)

  R=$(req GET /auth/me '' "$JAR_ACCT")
  CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
  check "GET /auth/me 带头像" 200 "$CODE" "$BODY" '"avatar_path":"uploads/'

  echo 'not an image' > "/tmp/notimage_$$.txt"
  R=$(curl -sS -X POST -b "$JAR_ACCT" -c "$JAR_ACCT" -w $'\n%{http_code}' -F "avatar=@/tmp/notimage_$$.txt" "$BASE/account/avatar")
  CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
  check "非图片头像 → 400" 400 "$CODE" "$BODY" '只支持'
  rm -f "/tmp/notimage_$$.txt"
fi

# ---------- 管理员后台 ----------
echo
echo "[管理员后台]"
R=$(req GET /admin/users '' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "普通用户访问管理后台 → 403" 403 "$CODE" "$BODY" '需要管理员'

R=$(req POST /auth/login '{"email":"admin@example.com","password":"admin1234"}' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "admin 登录" 200 "$CODE" "$BODY" '"is_admin":true'

R=$(req GET '/admin/users?per_page=100' '' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "管理员查看用户列表" 200 "$CODE" "$BODY" '"is_active"'
check "用户列表带商品数" 200 "$CODE" "$BODY" '"product_count"'

R=$(req PATCH "/admin/users/$ACCT_ID" '{"is_active":false}' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "禁用用户" 200 "$CODE" "$BODY" '"is_active":false'

R=$(req GET /cart '' "$JAR_ACCT")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "被禁用后会话失效 → 401" 401 "$CODE" "$BODY" 'unauthorized'

R=$(req PATCH "/admin/users/$ACCT_ID" '{"is_active":true}' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "重新启用用户" 200 "$CODE" "$BODY" '"is_active":true'

R=$(req POST /auth/login '{"email":"acct_tmp2@example.com","password":"secret456"}' "$JAR_ACCT")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "启用后可重新登录" 200 "$CODE" "$BODY" '"username":"acct_tmp2"'

R=$(req PATCH "/admin/users/$ACCT_ID" '{"is_admin":true}' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "设为管理员" 200 "$CODE" "$BODY" '"is_admin":true'

R=$(req GET /admin/users '' "$JAR_ACCT")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "新管理员可访问后台" 200 "$CODE" "$BODY" '"username"'

R=$(req PATCH "/admin/users/$ACCT_ID" '{"is_admin":false}' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "取消管理员" 200 "$CODE" "$BODY" '"is_admin":false'

R=$(req GET /admin/users '' "$JAR_ACCT")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "取消后无权访问 → 403" 403 "$CODE" "$BODY" '需要管理员'

R=$(req PATCH /admin/users/1 '{"is_active":false}' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "不能禁用自己 → 409" 409 "$CODE" "$BODY" '不能禁用自己'

R=$(req PATCH /admin/users/1 '{"is_admin":false}' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "不能取消自己的管理员 → 409" 409 "$CODE" "$BODY" '不能取消'

# ---------- 商品列表与筛选 ----------
echo
echo "[商品列表]"
R=$(req GET '/products?per_page=5'); CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "GET /products?per_page=5" 200 "$CODE" "$BODY" '"total_pages"'
P1_IDS=$(echo "$BODY" | grep -oE '"id":[0-9]+' | head -5 | cut -d: -f2 | tr '\n' ' ')

R=$(req GET '/products?per_page=5&page=2'); CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "第 2 页可以取" 200 "$CODE" "$BODY" '"page":2'
P2_IDS=$(echo "$BODY" | grep -oE '"id":[0-9]+' | head -5 | cut -d: -f2 | tr '\n' ' ')

# ★ 分页不重叠 —— 这是老版本最严重的 bug
overlap=0
for id in $P1_IDS; do
  for id2 in $P2_IDS; do
    [ "$id" = "$id2" ] && overlap=$((overlap+1))
  done
done
if [ "$overlap" -eq 0 ]; then
  printf '  \033[1;32mok\033[0m   %-46s 第1页与第2页无重叠\n' "分页不重复"
  pass=$((pass+1))
else
  printf '  \033[1;31mFAIL\033[0m %-46s 有 %s 条重复\n' "分页不重复" "$overlap"
  fail=$((fail+1))
fi

R=$(req GET '/products?status=new&per_page=100'); CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "按 status=new 筛选" 200 "$CODE" "$BODY" '"status":"new"'

R=$(req GET '/products?status=bogus'); CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "非法 status → 400" 400 "$CODE" "$BODY" 'status 只能是'

R=$(req GET '/products?category_id=1'); CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "按分类筛选" 200 "$CODE" "$BODY" '"total"'

R=$(req GET '/products?search=iPhone'); CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "关键词搜索" 200 "$CODE" "$BODY" 'iPhone'

R=$(req GET '/products?min_price=50&max_price=100&per_page=100'); CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "价格区间筛选" 200 "$CODE" "$BODY" '"total"'

R=$(req GET '/products?min_price=500&max_price=100'); CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "min>max → 400" 400 "$CODE" "$BODY" 'min_price'

R=$(req GET '/products?page=abc'); CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "page=abc → 400" 400 "$CODE" "$BODY" '必须是数字'

R=$(req GET '/categories'); CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "GET /categories" 200 "$CODE" "$BODY" 'product_count'

# ---------- 发布商品 ----------
echo
echo "[发布商品]"
R=$(req GET /products/mine '' "$JAR_A"); CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "alice 看自己的商品" 200 "$CODE" "$BODY" '"total"'

R=$(req POST /products '{"title":"测试商品：旧版统计学教材","price":42.5,"status":"used","category_id":1,"description":"自动化测试创建"}' "$JAR_A")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "alice 发布商品" 201 "$CODE" "$BODY" '"title"'
NEW_ID=$(jget "$BODY" id)

R=$(req POST /products '{"title":"x","price":0,"status":"bogus"}' "$JAR_A")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "发布参数不合法 → 422" 422 "$CODE" "$BODY" 'validation_failed'

R=$(req POST /products '{"title":"未登录发布","price":10,"status":"new","category_id":1}')
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "未登录发布 → 401" 401 "$CODE" "$BODY" 'unauthorized'

R=$(req POST /products '{"title":"分类不存在","price":10,"status":"new","category_id":99999}' "$JAR_A")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "不存在的分类 → 422" 422 "$CODE" "$BODY" '分类不存在'

R=$(req GET "/products/${NEW_ID}"); CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "商品详情" 200 "$CODE" "$BODY" 'seller_rating'

# ---------- 权限 ----------
echo
echo "[权限]"
R=$(req PATCH "/products/${NEW_ID}" '{"title":"bob 篡改"}' "$JAR_B")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "非卖家改商品 → 403" 403 "$CODE" "$BODY" 'forbidden'

R=$(req PATCH "/products/${NEW_ID}" '{"title":"alice 改自己的商品","price":39.9}' "$JAR_A")
CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "卖家改自己的商品" 200 "$CODE" "$BODY" 'alice 改自己的商品'

# PATCH 商品：空值不能绕过校验（曾经会清空标题或直接 500）
R=$(req PATCH "/products/$NEW_ID" '{"title":""}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "PATCH 空标题 → 422" 422 "$CODE" "$BODY" 'title'

R=$(req PATCH "/products/$NEW_ID" '{"price":""}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "PATCH 空价格 → 422" 422 "$CODE" "$BODY" 'price'

R=$(req PATCH "/products/$NEW_ID" '{"status":""}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "PATCH 空状态 → 422" 422 "$CODE" "$BODY" 'status'

R=$(req PATCH "/products/$NEW_ID" '{"category_id":null}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "PATCH category_id:null 清空分类" 200 "$CODE" "$BODY" '"category_id":null'

# 编辑表单走的是 multipart PATCH（与前端一致）
R=$(curl -sS -X POST -b "$JAR_A" -c "$JAR_A" -w $'\n%{http_code}' \
  -F "title=测试商品：表单编辑" -F "description=multipart PATCH" \
  -F "price=41.5" -F "status=used" -F "category_id=1" \
  "$BASE/products/$NEW_ID")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "multipart 编辑商品" 200 "$CODE" "$BODY" '"title":"测试商品：表单编辑"'

# 自己发布的商品可以删除（对应"我的发布"页的删除按钮）
R=$(req POST /products '{"title":"测试商品：待删除","price":5,"status":"new","category_id":1}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "创建待删除商品" 201 "$CODE" "$BODY" '"title"'
DEL_ID=$(jget "$BODY" id)

R=$(req DELETE "/products/$DEL_ID" '' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "删除自己的商品" 204 "$CODE" ""

R=$(req GET "/products/$DEL_ID")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "删除后商品不存在 → 404" 404 "$CODE" "$BODY" '商品不存在'

# ---------- 购物车 ----------
echo
echo "[购物车]"
R=$(req GET /cart '' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "alice 看购物车（空）" 200 "$CODE" "$BODY" '"item_count":0'

# alice 买 bob 的商品（找一个 bob 发布的）
R=$(req GET '/products?seller_id=3&per_page=1')
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
BOB_PRODUCT=$(jget "$BODY" id)
check "找到 bob 的商品" 200 "$CODE" "$BODY" '"seller_id":3'

R=$(req POST /cart '{"product_id":'"$BOB_PRODUCT"'}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "alice 加入购物车" 201 "$CODE" "$BODY" '"created":true'
CART_ID=$(jget "$BODY" id)

R=$(req POST /cart '{"product_id":'"$BOB_PRODUCT"'}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "重复加购 → 去重不新建" 200 "$CODE" "$BODY" '"created":false'

R=$(req GET /cart '' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "购物车有 1 项" 200 "$CODE" "$BODY" '"item_count":1'

# 不能加自己的商品
R=$(req GET '/products/mine?per_page=1' '' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
OWN_PRODUCT=$(jget "$BODY" id)
R=$(req POST /cart '{"product_id":'"$OWN_PRODUCT"'}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "加自己的商品 → 400" 400 "$CODE" "$BODY" '不能把自己发布'

R=$(req POST /cart '{"product_id":1.9}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "加购 product_id 小数 → 422" 422 "$CODE" "$BODY" '必须是整数'

R=$(req PATCH "/cart/$CART_ID" '{"quantity":2}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "购物车不再支持改数量 → 405" 405 "$CODE" "$BODY" 'method_not_allowed'

R=$(req GET /cart '' "$JAR_ANON")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "未登录看购物车 → 401" 401 "$CODE" "$BODY" 'unauthorized'

# ---------- 下单 ----------
echo
echo "[下单]"
R=$(req POST /orders '{}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "alice 结算购物车" 201 "$CODE" "$BODY" '"orders"'
ORDER_ID=$(echo "$BODY" | grep -oE '"id":[0-9]+' | head -1 | cut -d: -f2)

R=$(req GET /cart '' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "下单后购物车已清空" 200 "$CODE" "$BODY" '"item_count":0'

R=$(req GET "/products/$BOB_PRODUCT")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "下单后商品标记为已售" 200 "$CODE" "$BODY" '"is_sold":true'

R=$(req POST /cart '{"product_id":'"$BOB_PRODUCT"'}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "已售商品不能加购 → 409" 409 "$CODE" "$BODY" '已售出'

R=$(req POST /orders '{}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "空购物车结算 → 400" 400 "$CODE" "$BODY" '购物车是空的'

R=$(req GET '/orders?role=buyer' '' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "新订单是 pending" 200 "$CODE" "$BODY" '"status":"pending"'

R=$(req POST "/orders/$ORDER_ID/review" '{"rating":5}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "订单未完成不能评价 → 409" 409 "$CODE" "$BODY" '完成'

R=$(req GET '/orders?role=seller' '' "$JAR_B")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "bob 看卖出的" 200 "$CODE" "$BODY" '"seller_id"'

R=$(req GET "/orders/$ORDER_ID" '' "$JAR_ANON")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "未登录看订单详情 → 401" 401 "$CODE" "$BODY" 'unauthorized'

# ---------- 订单状态机 ----------
echo
echo "[订单状态机]"
R=$(req PATCH "/orders/$ORDER_ID" '{"status":"completed"}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "买家标记完成 → 403" 403 "$CODE" "$BODY" '只有卖家'

R=$(req PATCH "/orders/$ORDER_ID" '{"status":"completed"}' "$JAR_B")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "卖家标记完成" 200 "$CODE" "$BODY" '"status":"completed"'

R=$(req PATCH "/orders/$ORDER_ID" '{"status":"cancelled"}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "已完成订单不能取消 → 409" 409 "$CODE" "$BODY" '不能再改为'

# ---------- 评价 ----------
echo
echo "[评价]"
R=$(req POST "/orders/$ORDER_ID/review" '{"rating":5,"comment":"书很新，发货快，好评！"}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "alice 评价订单" 201 "$CODE" "$BODY" '"rating":5'
REVIEW_ID=$(jget "$BODY" id)

R=$(req POST "/orders/$ORDER_ID/review" '{"rating":4,"comment":"再评一次"}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "重复评价 → 409" 409 "$CODE" "$BODY" '已经评价过'

R=$(req POST "/orders/$ORDER_ID/review" '{"rating":9}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "评分 9 → 422" 422 "$CODE" "$BODY" 'rating'

R=$(req GET '/users/3/reviews')
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "GET /users/3/reviews" 200 "$CODE" "$BODY" '"average"'

R=$(req GET '/users/3')
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "GET /users/3 公开资料" 200 "$CODE" "$BODY" 'product_count'

# ---------- 评价回复 / 商品留言 / 主页留言 ----------
echo
echo "[评价回复 / 留言]"
R=$(req POST "/reviews/$REVIEW_ID/reply" '{"reply":"不是卖家"}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "非卖家回复评价 → 403" 403 "$CODE" "$BODY" '只有卖家'

R=$(req POST "/reviews/$REVIEW_ID/reply" '{"reply":"谢谢支持，书确实很新。"}' "$JAR_B")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "卖家回复评价" 200 "$CODE" "$BODY" '"reply_text"'

R=$(req GET '/users/3/reviews')
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "评价列表带卖家回复" 200 "$CODE" "$BODY" '"reply_text"'

R=$(req GET "/products/$BOB_PRODUCT/reviews")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "商品评价分页" 200 "$CODE" "$BODY" '"pagination"'

# 商品留言：alice 问，bob 作为卖家回复
R=$(req POST "/products/$BOB_PRODUCT/comments" '{"body":"请问配件还在吗？"}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "alice 发商品留言" 201 "$CODE" "$BODY" '"id"'
COMMENT_ID=$(jget "$BODY" id)

R=$(req POST "/products/$BOB_PRODUCT/comments" '{"body":"在的，原装充电器也在。","parent_id":'"$COMMENT_ID"'}' "$JAR_B")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "卖家回复商品留言" 201 "$CODE" "$BODY" '"id"'
REPLY_ID=$(jget "$BODY" id)

R=$(req GET "/products/$BOB_PRODUCT/comments")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "商品留言带回复" 200 "$CODE" "$BODY" '"replies"'

R=$(req GET '/admin/product-comments?per_page=5' '' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "管理员看商品留言" 200 "$CODE" "$BODY" '"product_title"'

R=$(req PATCH "/admin/product-comments/$COMMENT_ID" '{"is_hidden":true}' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "管理员隐藏商品留言" 200 "$CODE" "$BODY" '"is_hidden":true'

R=$(req GET "/products/$BOB_PRODUCT/comments")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "隐藏后商品留言不公开" 200 "$CODE" "$BODY" '"total":0'

# 个人主页留言板
R=$(req POST '/users/3/comments' '{"body":"bob 交易很爽快"}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "alice 发主页留言" 201 "$CODE" "$BODY" '"id"'
PCOMMENT_ID=$(jget "$BODY" id)

R=$(req GET '/users/3/comments')
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "主页留言列表" 200 "$CODE" "$BODY" '"author_name"'

R=$(req GET '/admin/profile-comments?per_page=5' '' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "管理员看主页留言" 200 "$CODE" "$BODY" '"profile_name"'

R=$(req DELETE "/users/3/comments/$PCOMMENT_ID" '' "$JAR_B")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "主页主人删除留言" 204 "$CODE" ""

R=$(req GET '/users/3/comments')
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "删除后主页留言不公开" 200 "$CODE" "$BODY" '"total":0'

# 管理员隐藏 / 恢复评价
R=$(req GET '/admin/reviews?per_page=5' '' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "管理员看评价" 200 "$CODE" "$BODY" '"is_hidden"'

R=$(req PATCH "/admin/reviews/$REVIEW_ID" '{"is_hidden":true}' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "管理员隐藏评价" 200 "$CODE" "$BODY" '"is_hidden":true'

R=$(req GET '/users/3/reviews')
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "隐藏后评价不公开" 200 "$CODE" "$BODY" '"total":0'

R=$(req PATCH "/admin/reviews/$REVIEW_ID" '{"is_hidden":false}' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "管理员恢复评价" 200 "$CODE" "$BODY" '"is_hidden":false'


# ---------- 取消订单 ----------
echo
echo "[取消订单]"
R=$(req POST /products '{"title":"测试商品：取消流程","price":9.9,"status":"used","category_id":1}' "$JAR_B")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "bob 发布取消用商品" 201 "$CODE" "$BODY" '"title"'
CANCEL_PRODUCT_ID=$(jget "$BODY" id)

R=$(req POST /cart '{"product_id":'"$CANCEL_PRODUCT_ID"'}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "alice 加购取消用商品" 201 "$CODE" "$BODY" '"created":true'
CANCEL_CART_ID=$(jget "$BODY" id)

R=$(req POST /orders '{"cart_ids":['"$CANCEL_CART_ID"']}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "alice 结算取消用商品" 201 "$CODE" "$BODY" '"orders"'
CANCEL_ORDER_ID=$(echo "$BODY" | grep -oE '"id":[0-9]+' | head -1 | cut -d: -f2)

R=$(req GET "/products/$CANCEL_PRODUCT_ID")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "取消用商品已标记售出" 200 "$CODE" "$BODY" '"is_sold":true'

R=$(req PATCH "/orders/$CANCEL_ORDER_ID" '{"status":"cancelled"}' "$JAR_B")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "卖家取消订单 → 403" 403 "$CODE" "$BODY" '只有买家'

R=$(req PATCH "/orders/$CANCEL_ORDER_ID" '{"status":"cancelled"}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "买家取消订单" 200 "$CODE" "$BODY" '"status":"cancelled"'

R=$(req GET "/products/$CANCEL_PRODUCT_ID")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "取消后商品重新上架" 200 "$CODE" "$BODY" '"is_sold":false'

R=$(req POST "/orders/$CANCEL_ORDER_ID/review" '{"rating":5}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "取消订单不能评价 → 400" 400 "$CODE" "$BODY" '已取消'

# ---------- 结算跳过失效商品 ----------
echo
echo "[结算跳过失效商品]"
R=$(req POST /products '{"title":"测试商品：跳过 A","price":11.0,"status":"used","category_id":1}' "$JAR_B")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "bob 发布跳过 A" 201 "$CODE" "$BODY" '"title"'
SKIP_A=$(jget "$BODY" id)

R=$(req POST /products '{"title":"测试商品：跳过 B","price":12.5,"status":"used","category_id":1}' "$JAR_B")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "bob 发布跳过 B" 201 "$CODE" "$BODY" '"title"'
SKIP_B=$(jget "$BODY" id)

R=$(req POST /cart '{"product_id":'"$SKIP_A"'}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "alice 加购跳过 A" 201 "$CODE" "$BODY" '"created":true'

R=$(req POST /cart '{"product_id":'"$SKIP_B"'}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "alice 加购跳过 B" 201 "$CODE" "$BODY" '"created":true'

R=$(req POST /auth/login '{"email":"admin@example.com","password":"admin1234"}' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "admin 登录成功" 200 "$CODE" "$BODY" '"username":"admin"'

R=$(req POST /cart '{"product_id":'"$SKIP_A"'}' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "admin 加购跳过 A" 201 "$CODE" "$BODY" '"created":true'
ADMIN_CART_ID=$(jget "$BODY" id)

R=$(req POST /orders '{"cart_ids":['"$ADMIN_CART_ID"']}' "$JAR_ADMIN")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "admin 买走跳过 A" 201 "$CODE" "$BODY" '"orders"'
ADMIN_ORDER_ID=$(echo "$BODY" | grep -oE '"id":[0-9]+' | head -1 | cut -d: -f2)

R=$(req GET "/products/$SKIP_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "跳过 A 已售出" 200 "$CODE" "$BODY" '"is_sold":true'

# alice 的购物车里 A 已失效、B 仍在售；结算应只下单 B，A 留在购物车
R=$(req POST /orders '{}' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "结算跳过已售商品" 201 "$CODE" "$BODY" '"orders"'
check "结算金额只含在售商品" 201 "$CODE" "$BODY" '"total":12.5'
SKIP_ORDER_ID=$(echo "$BODY" | grep -oE '"id":[0-9]+' | head -1 | cut -d: -f2)

R=$(req GET /cart '' "$JAR_A")
CODE=$(echo "$R" | tail -n 1); BODY=$(echo "$R" | sed '$d')
check "失效商品仍留在购物车" 200 "$CODE" "$BODY" '"item_count":1'
check "失效商品不计入可结算数量" 200 "$CODE" "$BODY" '"quantity":0'
check "失效商品标记 available:false" 200 "$CODE" "$BODY" '"available":false'

# ---------- 路由细节 ----------
echo
echo "[路由]"
R=$(req GET /nosuchthing); CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "不存在的接口 → 404" 404 "$CODE" "$BODY" 'not_found'

R=$(req DELETE /auth/login); CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "方法不对 → 405" 405 "$CODE" "$BODY" 'method_not_allowed'

R=$(req GET /products/999999); CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "不存在的商品 → 404" 404 "$CODE" "$BODY" 'not_found'

# ---------- 登出 ----------
echo
echo "[登出]"
R=$(req POST /auth/logout '' "$JAR_A"); CODE="${R##*$'\n'}"
check "登出返回 204" 204 "$CODE" ""

R=$(req GET /cart '' "$JAR_A"); CODE="${R##*$'\n'}"; BODY="${R%$'\n'*}"
check "登出后购物车 → 401" 401 "$CODE" "$BODY" 'unauthorized'

# ---------- 清理测试数据 ----------
echo
echo "[清理]"
# 测试中途失败时变量可能为空，先兜底，避免拼出非法 SQL
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

if [ -n "$DB_PASSWORD" ]; then
    MYSQL_PWD="$DB_PASSWORD" mysql -h 127.0.0.1 -u root online_marketplace -e "
      DELETE FROM product_comments WHERE id IN (${COMMENT_ID}, ${REPLY_ID}) OR parent_id = ${COMMENT_ID};
      DELETE FROM profile_comments WHERE id = ${PCOMMENT_ID};
      DELETE FROM reviews WHERE order_id IN (${ORDER_ID}, ${CANCEL_ORDER_ID}, ${ADMIN_ORDER_ID}, ${SKIP_ORDER_ID});
      DELETE FROM orders WHERE id IN (${ORDER_ID}, ${CANCEL_ORDER_ID}, ${ADMIN_ORDER_ID}, ${SKIP_ORDER_ID});
      DELETE FROM products WHERE id IN (${NEW_ID}, ${CANCEL_PRODUCT_ID}, ${SKIP_A}, ${SKIP_B}, ${DEL_ID});
      UPDATE products SET is_sold = 0 WHERE id = ${BOB_PRODUCT};
      DELETE FROM users WHERE id = ${ACCT_ID}
         OR email IN ('t1@example.com','t3@example.com','acct_tmp@example.com','acct_tmp2@example.com');
    " 2>/dev/null && printf '  \033[1;32mok\033[0m   测试数据已清理\n'
else
    printf '  \033[1;33m跳过\033[0m   未提供 DB_PASSWORD，测试数据未清理\n'
fi

if [ -n "$AVATAR_PATH" ]; then
  rm -f "$ROOT/public/$AVATAR_PATH"
fi

echo
echo "============================================================"
printf ' 通过 \033[1;32m%d\033[0m   失败 \033[1;31m%d\033[0m\n' "$pass" "$fail"
echo "============================================================"
[ "$fail" -eq 0 ] || exit 1
