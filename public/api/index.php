<?php
/**
 * RESTful API 前端控制器（唯一入口）。
 *
 * 所有 /api/v1/* 的请求都落到这里，由 Router 分发到控制器。
 * 本文件只做路由声明，不放业务逻辑。
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Controllers\AccountController;
use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\CartController;
use App\Controllers\OrderController;
use App\Controllers\ProductCommentController;
use App\Controllers\ProductController;
use App\Controllers\ProfileCommentController;
use App\Controllers\ReviewController;
use App\Controllers\UserController;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;

$request = new Request();

// 跨域：本机开发时前端可能是 :5500 之类的端口。
// 生产环境应改成白名单，不要用 *。
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && (bool) config('debug')) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
}

// 预检请求直接放行
if ($request->method() === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$router = new Router('/api/v1');

// ---------- 健康检查 ----------
$router->get('/health', static function () use ($request): never {
    $pdo = App\Database::conn();
    Response::json([
        'status'    => 'ok',
        'php'       => PHP_VERSION,
        'database'  => $pdo->query('SELECT VERSION()')->fetchColumn(),
        'time'      => date('c'),
    ]);
});

// ---------- 认证 ----------
$router->post('/auth/register', static fn () => (new AuthController($request))->register());
$router->post('/auth/login',    static fn () => (new AuthController($request))->login());
$router->post('/auth/logout',   static fn () => (new AuthController($request))->logout());
$router->get('/auth/me',        static fn () => (new AuthController($request))->me());

// ---------- 账号设置 ----------
$router->patch('/account',          static fn () => (new AccountController($request))->update());
$router->post('/account/password',  static fn () => (new AccountController($request))->password());
$router->post('/account/avatar',    static fn () => (new AccountController($request))->avatar());

// ---------- 站点统计（首页横幅用） ----------
$router->get('/stats', static fn () => Response::json(App\Models\Stats::summary()));

// ---------- 分类 ----------
$router->get('/categories', static fn () => (new ProductController($request))->categories());

// ---------- 商品 ----------
// 注意：具体路径必须注册在 {id} 之前，否则 /products/mine 会被当成 id="mine"
$router->get('/products/mine', static fn () => (new ProductController($request))->mine());
$router->get('/products',      static fn () => (new ProductController($request))->index());
$router->post('/products',     static fn () => (new ProductController($request))->store());
$router->get('/products/{id}/reviews', static fn (string $id) => (new ReviewController($request))->forProduct($id));
$router->get('/products/{id}/comments', static fn (string $id) => (new ProductCommentController($request))->index($id));
$router->post('/products/{id}/comments', static fn (string $id) => (new ProductCommentController($request))->store($id));
$router->delete('/products/{id}/comments/{commentId}', static fn (string $id, string $commentId) => (new ProductCommentController($request))->destroy($id, $commentId));
$router->get('/products/{id}', static fn (string $id) => (new ProductController($request))->show($id));
$router->patch('/products/{id}', static fn (string $id) => (new ProductController($request))->update($id));
$router->put('/products/{id}',   static fn (string $id) => (new ProductController($request))->update($id));
// multipart 表单编辑走 POST：PHP 只为 POST 解析 $_POST/$_FILES，PATCH 的 multipart 拿不到字段
$router->post('/products/{id}',  static fn (string $id) => (new ProductController($request))->update($id));
$router->delete('/products/{id}', static fn (string $id) => (new ProductController($request))->destroy($id));

// ---------- 用户 ----------
$router->get('/users/{id}/reviews', static fn (string $id) => (new ReviewController($request))->forSeller($id));
$router->get('/users/{id}/comments', static fn (string $id) => (new ProfileCommentController($request))->index($id));
$router->post('/users/{id}/comments', static fn (string $id) => (new ProfileCommentController($request))->store($id));
$router->delete('/users/{id}/comments/{commentId}', static fn (string $id, string $commentId) => (new ProfileCommentController($request))->destroy($id, $commentId));
$router->get('/users/{id}',         static fn (string $id) => (new UserController($request))->show($id));

// ---------- 管理员后台 ----------
$router->get('/admin/users',         static fn () => (new AdminController($request))->users());
$router->patch('/admin/users/{id}',  static fn (string $id) => (new AdminController($request))->updateUser($id));
$router->get('/admin/reviews',       static fn () => (new AdminController($request))->reviews());
$router->patch('/admin/reviews/{id}', static fn (string $id) => (new AdminController($request))->updateReview($id));
$router->get('/admin/product-comments', static fn () => (new AdminController($request))->productComments());
$router->patch('/admin/product-comments/{id}', static fn (string $id) => (new AdminController($request))->updateProductComment($id));
$router->get('/admin/profile-comments', static fn () => (new AdminController($request))->profileComments());
$router->patch('/admin/profile-comments/{id}', static fn (string $id) => (new AdminController($request))->updateProfileComment($id));

// ---------- 购物车 ----------
$router->get('/cart',         static fn () => (new CartController($request))->index());
$router->post('/cart',        static fn () => (new CartController($request))->store());
$router->delete('/cart',      static fn () => (new CartController($request))->clear());
$router->delete('/cart/{id}', static fn (string $id) => (new CartController($request))->destroy($id));

// ---------- 订单 ----------
$router->get('/orders',      static fn () => (new OrderController($request))->index());
$router->post('/orders',     static fn () => (new OrderController($request))->store());
$router->get('/orders/{id}', static fn (string $id) => (new OrderController($request))->show($id));
$router->patch('/orders/{id}', static fn (string $id) => (new OrderController($request))->update($id));
$router->post('/orders/{id}/review', static fn (string $id) => (new ReviewController($request))->store($id));
$router->post('/reviews/{id}/reply', static fn (string $id) => (new ReviewController($request))->reply($id));

$router->dispatch($request->method(), $request->path());
