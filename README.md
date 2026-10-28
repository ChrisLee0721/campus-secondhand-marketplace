# 闲置集市 · LeeLapYuen

校园二手交易平台。原项目是一套原生 PHP 页面直连 MySQL 的实现（`legacy/` 目录留有说明），
现已重构为 **RESTful API + 原生 JS 单页应用**。

> 你的闲置，我的需要。

---

## 技术栈

| 层 | 用什么 | 说明 |
|---|---|---|
| 后端 | PHP 8.3（原生，无框架） | 命名空间 + 自写自动加载，不依赖 Composer |
| 数据库 | MariaDB 10.11+（兼容 MySQL 协议） | InnoDB，带外键与 CHECK 约束 |
| 前端 | 原生 JS + CSS（无框架、无构建） | hash 路由，`fetch` 调 API |
| 服务器 | PHP 内置服务器（开发） / Apache（生产） | `public/router.php` 做前端控制器 |

**为什么不用框架**：原项目就是零依赖的原生 PHP。保持这个形态，把代码组织方式改好
（分层、命名空间、异常处理），比引入 Laravel 更符合这个项目的定位 —— 也更能说明
"我理解这些概念，不是会用一个框架的 API"。

---

## 目录结构

```
Server/
├── config/
│   ├── config.php              配置（默认密码 123456，可被环境变量覆盖）
│   └── config.local.php        本机覆盖配置（已 gitignore，可选）
├── database/
│   └── online_marketplace.sql  完整演示数据（111 商品 / 3 账号 / 示例留言）
├── migrations/
│   ├── 001_schema.sql          建表
│   ├── 002_seed.sql            分类 + 演示账号
│   ├── 003_campus_seed.sql     12 条校园二手商品
│   ├── 004_account_admin.sql   头像 + 账号启用状态
│   ├── 005_reviews_comments.sql 评价回复 / 留言 / 简介
│   └── migrate_legacy.php      把旧库 db_01 的 100 件商品迁过来
├── src/
│   ├── bootstrap.php           自动加载 / 错误处理 / 会话
│   ├── Database.php            PDO 单例 + 事务封装
│   ├── Auth/Auth.php           会话、登录登出、三个守卫
│   ├── Http/
│   │   ├── ApiException.php    带状态码的异常
│   │   ├── Request.php         请求封装与类型收敛
│   │   ├── Response.php        统一 JSON 信封
│   │   └── Router.php          极简路由（动态段 + 分组中间件）
│   ├── Models/
│   │   ├── Model.php           基类：白名单过滤 + CRUD
│   │   ├── User.php
│   │   ├── Product.php         ★ 分页查询（含排序键修复）
│   │   ├── Category.php
│   │   ├── CartItem.php        加购/去重/汇总
│   │   ├── Order.php           ★ 结算事务
│   │   ├── Review.php           ★ 交易评价 + 卖家回复
│   │   ├── ProductComment.php   ★ 商品留言
│   │   ├── ProfileComment.php   ★ 主页留言
│   │   └── Stats.php            首页统计
│   ├── Controllers/            10 个控制器，只做参数校验和调用模型
│   └── Support/
│       ├── Validate.php        链式校验，一次返回所有字段错误
│       └── Upload.php          图片上传（真实 MIME 检查 + 随机文件名）
├── public/                     ← DocumentRoot 应指向这里
│   ├── index.html              前端壳
│   ├── router.php              PHP 内置服务器的前端控制器（开发用）
│   ├── .htaccess               Apache rewrite 规则（生产用）
│   ├── assets/
│   │   ├── app.js              前端应用（路由 + 视图 + API 封装）
│   │   └── style.css
│   └── uploads/                用户上传的图片
├── tests/
│   └── api_test.sh             端到端测试（认证 / 账号 / 商品 / 购物车 / 下单 / 取消 / 评价 / 留言 / 管理员）
├── legacy/                     ← 重构前的旧代码，仅作对照保留，不参与运行
│                                  （旧 SQL dump 仍是 MySQL 8 格式，不要直接导 MariaDB）
│   ├── main.php                    旧商品列表（PHP 直连库渲染）
│   ├── login_regsiter.php          旧登录注册页
│   ├── LeeLapYuen.php              更早的一版注册页（未落库）
│   ├── Check/                      旧的表单处理脚本
│   ├── CSS/ JavaScript/            旧样式与脚本
│   ├── db_connect.php              旧连接文件（含硬编码 root 密码）
│   └── sql-dumps/                  原始 SQL dump（db_01 / online_marketplace / phpmyadmin）
└── setup.sh                    WSL 一键部署 / 重置
```

---

## 快速开始（WSL）

```bash
bash setup.sh            # 装环境 + 建库 + 导入演示数据 + 起服务
bash setup.sh --keep-db  # 只更新代码和服务器，不动数据库
```

> 数据库使用 **MariaDB 10.11+**；`setup.sh` 会自动安装并初始化。
> 全新安装会直接导入仓库里的 `database/online_marketplace.sql`：
> 111 件商品、3 个演示账号、3 条商品留言、1 条主页留言。
> 如果本机原来装的是 MySQL 8，安装 MariaDB 前请先备份并移走 `/var/lib/mysql`：
> MariaDB 不能直接复用 MySQL 8 的数据目录。
> 如果是在已有数据库上只更新代码（`--keep-db`），需要手动执行新增迁移
> `migrations/004_account_admin.sql` 和 `migrations/005_reviews_comments.sql`；
> 全新安装会自动执行。

### 数据库连接（演示环境）

| 项 | 值 |
|---|---|
| 地址 | `127.0.0.1:3306` |
| 数据库 | `online_marketplace` |
| 用户 | `root` |
| 密码 | `123456` |

> 这是演示密码，故意直接写在这里；**上线前必须改掉**，也不要拿它挂真实数据。

然后打开 **http://localhost:8000/**

演示账号（见 `database/online_marketplace.sql`）：

| 邮箱 | 密码 | 说明 |
|---|---|---|
| admin@example.com | admin1234 | 管理员，可进「管理」后台 |
| alice@example.com | alice1234 | 普通用户 |
| bob@example.com | bob1234 | 普通用户 |

**建议的演示动线**：用 alice 登录 → 挑一件 bob 的商品 → 加入购物车 → 结算（生成
`pending` 订单）→ 切到 bob 在「我卖出的」里点「标记完成」→ 切回 alice 去「订单」评价。
这条路径把五个表全用上了。alice 也可以在 `pending` 阶段取消订单，商品会自动重新上架。
侧边栏可以进「账号设置 / 我的发布 / 管理后台」；商品详情页下方是「商品留言」和
「评价」两个独立区域；卖家主页有「在售商品 / 收到的评价 / 留言板」三个标签页。
admin 账号登录后侧边栏会多一个「管理后台」入口。

### 手动启动

```bash
cd ~/Server/public
php -S 0.0.0.0:8000 router.php     # 前台运行，日志直接打在终端
```

默认数据库密码是 `123456`；如果改过密码，用 `DB_PASSWORD` 环境变量或
`config/config.local.php` 覆盖。

### 跑测试

```bash
bash tests/api_test.sh             # 默认 8000 端口
```

测试会创建临时商品/订单并在结束时清理，不会删除 111 件种子商品；清理步骤默认使用
数据库密码 `123456`，也可以用 `DB_PASSWORD` 覆盖。

---

## API 文档

统一信封：

```jsonc
// 成功
{ "data": {...}, "meta": { "pagination": {...} } }

// 失败
{ "error": { "code": "validation_failed", "message": "输入数据未通过校验",
             "details": { "email": "电子邮箱格式不正确" } } }
```

### 认证

| 方法 | 路径 | 说明 |
|---|---|---|
| `POST` | `/api/v1/auth/register` | 注册，成功即自动登录，返回 201 |
| `POST` | `/api/v1/auth/login` | 登录 |
| `POST` | `/api/v1/auth/logout` | 登出，返回 204 |
| `GET` | `/api/v1/auth/me` | 当前用户；未登录返回 `{"data":null}`（不是 401） |

```bash
curl -c jar.txt -H 'Content-Type: application/json' \
  -d '{"email":"alice@example.com","password":"alice1234"}' \
  http://localhost:8000/api/v1/auth/login
```

### 账号设置

| 方法 | 路径 | 说明 |
|---|---|---|
| `PATCH` | `/api/v1/account` | 修改 `username` / `email` / `bio`；改邮箱需同时传 `current_password` |
| `POST` | `/api/v1/account/password` | `current_password` + `password` + `password_confirmation`；成功后换 session id |
| `POST` | `/api/v1/account/avatar` | `multipart/form-data`，字段名 `avatar`；复用图片上传校验 |

`GET /auth/me` 会返回当前用户的 `email`、`avatar_path`、`is_admin`、`is_active`。

### 商品

| 方法 | 路径 | 权限 | 说明 |
|---|---|---|---|
| `GET` | `/api/v1/products` | 公开 | 列表，见下方查询参数 |
| `GET` | `/api/v1/products/mine` | 登录 | 我发布的（含已售出） |
| `POST` | `/api/v1/products` | 登录 | 发布（支持 `multipart/form-data` 传图） |
| `GET` | `/api/v1/products/{id}` | 公开 | 详情（含卖家评分） |
| `PATCH` / `POST` | `/api/v1/products/{id}` | 仅卖家 | 编辑（JSON 用 `PATCH`；multipart 表单用 `POST`，PHP 只为 POST 解析文件） |
| `DELETE` | `/api/v1/products/{id}` | 仅卖家 | 删除（有订单则拒绝） |
| `GET` | `/api/v1/products/{id}/reviews` | 公开 | 该商品的评价（分页，含卖家回复） |
| `GET` | `/api/v1/products/{id}/comments` | 公开 | 商品留言（分页，含卖家回复） |
| `POST` | `/api/v1/products/{id}/comments` | 登录 | 发留言；body `{body, parent_id?}` |
| `DELETE` | `/api/v1/products/{id}/comments/{commentId}` | 登录 | 删除/隐藏留言（作者/卖家/管理员） |
| `GET` | `/api/v1/categories` | 公开 | 分类 + 各类在售数量 |

查询参数：`page`、`per_page`（≤100）、`category_id`、`search`、
`min_price`、`max_price`、`status`（`new`/`used`/`damaged`）、
`seller_id`、`include_sold`（`1` 时含已售出）。

```bash
curl 'http://localhost:8000/api/v1/products?category_id=1&min_price=20&max_price=100&status=used'
```

### 购物车

| 方法 | 路径 | 说明 |
|---|---|---|
| `GET` | `/api/v1/cart` | 明细 + 合计（失效商品单列，不计入总价） |
| `POST` | `/api/v1/cart` | 加入；已存在则直接返回 `created:false`（每件商品只有一份） |
| `DELETE` | `/api/v1/cart/{id}` | 移除单项 |
| `DELETE` | `/api/v1/cart` | 清空 |

### 订单

| 方法 | 路径 | 说明 |
|---|---|---|
| `GET` | `/api/v1/orders?role=buyer\|seller` | 我买到的 / 我卖出的 |
| `POST` | `/api/v1/orders` | 结算购物车，生成 `pending` 订单；body 可带 `{"cart_ids":[...]}` |
| `GET` | `/api/v1/orders/{id}` | 详情（仅买卖双方） |
| `PATCH` | `/api/v1/orders/{id}` | 改状态（状态机见下） |

状态机：`pending → completed`（**卖家**确认成交）/ `pending → cancelled`（**买家**取消）。
取消订单会把商品重新上架（`is_sold` 置回 0）。只有 `completed` 的订单可以评价。
结算时，购物车里已售出的条目会被自动跳过；如果一件可结算的都没有，返回 409。

### 评价 / 用户

| 方法 | 路径 | 说明 |
|---|---|---|
| `POST` | `/api/v1/orders/{id}/review` | 评价订单（仅买家、订单须为 `completed`、一单一评） |
| `POST` | `/api/v1/reviews/{id}/reply` | 卖家回复评价（body `{reply}`，可覆盖） |
| `GET` | `/api/v1/users/{id}` | 公开资料 + 在售/已售数 + 评分 |
| `GET` | `/api/v1/users/{id}/reviews` | 该用户收到的评价 + 平均分（分页） |
| `GET` | `/api/v1/users/{id}/comments` | 个人主页留言（分页） |
| `POST` | `/api/v1/users/{id}/comments` | 登录后可留言；body `{body}` |
| `DELETE` | `/api/v1/users/{id}/comments/{commentId}` | 删除/隐藏留言（作者/主页主人/管理员） |

### 管理员

| 方法 | 路径 | 说明 |
|---|---|---|
| `GET` | `/api/v1/admin/users` | 用户列表，支持 `search`、`page`、`per_page` |
| `PATCH` | `/api/v1/admin/users/{id}` | 修改 `is_admin` / `is_active` |
| `GET` | `/api/v1/admin/reviews` | 评价列表（含隐藏） |
| `PATCH` | `/api/v1/admin/reviews/{id}` | `{is_hidden}` 隐藏/恢复评价 |
| `GET` | `/api/v1/admin/product-comments` | 商品留言列表（含隐藏） |
| `PATCH` | `/api/v1/admin/product-comments/{id}` | 隐藏/恢复商品留言 |
| `GET` | `/api/v1/admin/profile-comments` | 主页留言列表（含隐藏） |
| `PATCH` | `/api/v1/admin/profile-comments/{id}` | 隐藏/恢复主页留言 |

约束：不能禁用或降级自己；系统至少保留一个「启用且是管理员」的账号。被禁用的用户
下次请求会被自动登出，登录时返回 403 `account_disabled`。

### 其他

| 方法 | 路径 | 说明 |
|---|---|---|
| `GET` | `/api/v1/health` | 健康检查（PHP 版本 + 数据库版本 + 时间） |
| `GET` | `/api/v1/stats` | 首页统计（在售 / 已成交 / 用户 / 订单数） |

### 状态码约定

`200` 成功 · `201` 创建成功 · `204` 删除成功无响应体 ·
`400` 参数格式错 · `401` 未登录 · `403` 已登录但无权限 ·
`404` 资源不存在 · `405` 方法不对 · `409` 状态冲突（已售出/重复评价/订单状态不允许/无可结算商品/最后一个管理员）·
`422` 校验失败（带字段级 `details`）· `500` 服务器错误

---

## 数据模型

沿用原 `online_marketplace` 的设计，几处值得说明：

```
users ──┬── products (seller_id)         卖家
        ├── cart (user_id)
        ├── orders (buyer_id)            买家
        └── reviews (buyer_id / seller_id)
                 │
products ────────┴── orders (product_id)
                 └── cart (product_id)
                          │
reviews ──────────────────┴── orders (order_id)   ← 评价挂在订单上
```

`users` 在 004 / 005 迁移后增加了 `avatar_path`（头像）、`is_active`（启用状态）、
`bio`（个人简介）；`is_admin` 现在真正用于管理后台守卫。

`product_comments`（商品留言）和 `profile_comments`（主页留言）是独立的表：
留言不参与评分，评价仍然只属于一次交易。

**评价挂在 `orders` 而不是 `products`** 是这个 schema 最好的一处设计：评价属于
「一次交易」，不是「一件商品」。同一件商品被转卖两次，两次交易应该分别评价。
`UNIQUE KEY uniq_order` 保证一单一评。

`orders` 是「一个商品一行」而不是「一个订单多行」—— 二手平台每件货只有一份，
所以一次结算 N 个购物车项产生 N 条记录，每条 `quantity` 固定为 1。这是原设计的取舍，保留。

---

## 这次重构改了什么

### 修掉的实际缺陷

| 问题 | 原状 | 现在 |
|---|---|---|
| **分页丢商品** | `ORDER BY created_at DESC`，而 100 行 `created_at` 全同值 → 9 页只能拿到 71 条不重复商品，29 条永远翻不到，第 1/2 页重叠 11 条 | `ORDER BY created_at DESC, id DESC`，测试里专门有一条断言两页无重叠 |
| **注册校验形同虚设** | 校验只赋值 `$message` 不中断，非法用户名/短密码照样 INSERT | `Validate` 链式校验，失败抛 422 并带字段级错误，不执行写入 |
| **状态筛选 500** | 代码查 `products.status`，表里没这列，`ERRMODE_EXCEPTION` 下白屏 | schema 与代码对齐 |
| **凭据硬编码** | `db_connect.php` 里写死 root 密码 | 演示项目统一使用简单密码 `123456`（README 明确写出）；可用 `DB_PASSWORD` / `config.local.php` 覆盖，上线前必须改 |
| **明文种子账号** | dump 里有 `('admin','admin','admin@admin.com1')` | 全部 bcrypt 哈希；明文只在 README 的演示账号表里 |
| **反射型 XSS** | `value="<?php echo $_POST['UserName'];?>"` | 前端全部 `esc()` 转义；`legacy/` 里的旧页面已隔离 |
| **会话固定** | 登录不换 session id | `session_regenerate_id(true)` |
| **错误信息泄露** | `$e->getMessage()` 直接回显 | 500 只回通用消息，细节进 `error_log` |
| **失效引用** | `include login_register.php`（文件不存在） | 已删除 |
| **重复实现** | 两套注册逻辑（`Check/` + `LeeLapYuen.php`）并存 | 统一到 `AuthController`；旧代码移入 `legacy/` |

### 新补的功能

- **发布商品** —— 原来最核心的动词完全没实现，现在有表单 + 图片上传
- **商品详情页** —— 原来是几个空 div 的骨架
- **购物车** —— 加购/去重/清空，含合计
- **下单** —— 事务化的结算，含并发保护
- **评价** —— 一单一评，评分 1–5
- **卖家主页** —— 公开资料 + 在售商品 + 评分
- **账号设置** —— 改用户名 / 邮箱 / 密码 / 头像 / 个人简介；改邮箱需当前密码
- **我的发布** —— 编辑、删除自己发布的商品（已售出不可编辑）
- **商品留言** —— 售前问答，卖家可回复；独立于评分
- **评价回复** —— 卖家可回复交易评价
- **个人主页** —— 头像 / 简介 / 在售 / 已售 / 评分 + 评价列表 + 留言板
- **侧边栏** —— 桌面固定导航，移动端抽屉
- **管理后台** —— 用户 / 评价 / 商品留言 / 主页留言的启用禁用与隐藏

### 工程上的变化

- PDO 预处理全程 + `EMULATE_PREPARES=false`；`Model::$fillable` 白名单防止
  请求体塞 `is_admin` 之类的字段提权
- 所有 warning/notice 提升为异常 —— 不再"静默出错 + 返回半个 JSON"
- 结算走 `Database::transaction()`；用 `UPDATE ... WHERE is_sold = 0` 的
  `rowCount()` 做乐观锁，两个人同时买同一件商品时后者会收到 409 而不是双卖
- 改密码要求验证当前密码并 `session_regenerate_id(true)`；改邮箱也要求当前密码
- 被禁用账号在 `Auth::user()` 里自动登出；管理员不能禁用/降级自己，且至少保留一个启用管理员
- 上传走 `finfo` 读真实 MIME + `getimagesize()` 二次确认 + 随机文件名，
  挡掉"改名的 .php 图片"

---

## 已知限制

- **没有 CSRF token**。目前靠 `SameSite=Lax` cookie 缓解。因为是同源 SPA 且
  只在本机跑，风险有限；要上公网应补 token。
- **没有分页的 cursor 版本**。`LIMIT/OFFSET` 在数据量大时性能会退化；这个规模无所谓。
- **每件二手商品只有一份**，购物车和订单的 `quantity` 固定为 1；要支持批量/库存需要加 `stock` 字段。
- **没有密码重置、邮箱验证**；改邮箱只验证当前密码，不验证邮箱归属。
- **管理后台只覆盖用户与内容隐藏**，没有操作日志、举报处理。
- **评价只能买家评卖家**，没有卖家评买家；评价和留言不支持图片。
- **头像/商品图只存文件系统**，多机部署需要换成对象存储。
- **没有任何速率限制**，登录接口可以被暴力尝试。本机演示可接受。

---

## 生产部署（Apache）

1. 把 `public/` 设为 DocumentRoot，启用 `mod_rewrite`、`mod_headers`
2. 设环境变量：`DB_USER`、`DB_PASSWORD`、`DB_NAME`、`APP_DEBUG=0`
3. `chmod 755 public/uploads`（需可写）
4. 确认 `public/.htaccess` 生效（`AllowOverride All`）

若只能部署在子目录，改 `public/.htaccess` 里的 `RewriteBase`。
