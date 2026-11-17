<?php
/**
 * RESTful API front controller — the single entry point.
 *
 * Every /api/v1/* request lands here. This file holds no business logic: it
 * only maps a method plus a path to one named function in src/Api/.
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
require dirname(__DIR__, 2) . '/src/Api/helpers.php';
require dirname(__DIR__, 2) . '/src/Api/Create.php';
require dirname(__DIR__, 2) . '/src/Api/Read.php';
require dirname(__DIR__, 2) . '/src/Api/Update.php';
require dirname(__DIR__, 2) . '/src/Api/Delete.php';

use App\Http\Request;
use App\Http\Router;

$request = api_request();

// CORS is only opened in debug mode, where the front end may run on another port.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && (bool) config('debug')) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
}

// Preflight requests are answered before routing.
if ($request->method() === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$router = new Router('/api/v1');

// ---------- Health ----------
$router->get('/health', 'healthCheck');

// ---------- Auth and account ----------
$router->post('/auth/register', 'registerUser');
$router->post('/auth/login', 'login');
$router->post('/auth/forgot-password', 'requestPasswordReset');
$router->post('/auth/reset-password', 'resetPassword');
$router->post('/auth/logout', 'logout');
$router->get('/auth/me', 'me');
$router->patch('/account', 'updateAccount');
$router->post('/account/password', 'changePassword');
$router->post('/account/avatar', 'updateAvatar');

// ---------- Site stats ----------
$router->get('/stats', 'getStats');

// ---------- Products ----------
$router->get('/categories', 'listCategories');
$router->get('/products/mine', 'listMyProducts');
$router->get('/products', 'listProducts');
$router->post('/products', 'createProduct');
$router->post('/products/{id}/report', 'reportProduct');
$router->get('/products/{id}/reviews', 'listProductReviews');
$router->get('/products/{id}/comments', 'listProductComments');
$router->post('/products/{id}/comments', 'createProductComment');
$router->delete('/products/{id}/comments/{commentId}', 'deleteProductComment');
$router->get('/products/{id}/image', 'readProductImage');
$router->get('/products/{id}/ebay-price', 'getEbayPrice');
$router->get('/products/{id}', 'readProduct');
$router->patch('/products/{id}', 'updateProduct');
$router->put('/products/{id}', 'updateProduct');
$router->post('/products/{id}', 'updateProduct');
$router->delete('/products/{id}', 'deleteProduct');

// ---------- Users ----------
$router->get('/users/{id}/reviews', 'listSellerReviews');
$router->get('/users/{id}/comments', 'listProfileComments');
$router->post('/users/{id}/comments', 'createProfileComment');
$router->delete('/users/{id}/comments/{commentId}', 'deleteProfileComment');
$router->post('/users/withdraw', 'withdrawFunds');
$router->get('/users/{id}', 'readUser');

// ---------- Admin ----------
$router->get('/admin/users', 'adminListUsers');
$router->patch('/admin/users/{id}', 'adminUpdateUser');
$router->get('/admin/products', 'adminListProducts');
$router->patch('/admin/products/{id}', 'adminUpdateProduct');
$router->delete('/admin/products/{id}', 'adminDeleteProduct');
$router->get('/admin/orders', 'adminListOrders');
$router->post('/admin/orders/{id}/release', 'adminReleaseOrder');
$router->post('/admin/orders/{id}/refund', 'adminRefundOrder');
$router->get('/admin/reports', 'adminListReports');
$router->patch('/admin/reports/{id}', 'adminUpdateReport');
$router->get('/admin/reviews', 'adminListReviews');
$router->patch('/admin/reviews/{id}', 'adminUpdateReview');
$router->get('/admin/product-comments', 'adminListProductComments');
$router->patch('/admin/product-comments/{id}', 'adminUpdateProductComment');
$router->get('/admin/profile-comments', 'adminListProfileComments');
$router->patch('/admin/profile-comments/{id}', 'adminUpdateProfileComment');

// ---------- Cart ----------
$router->get('/cart', 'listCart');
$router->post('/cart', 'addCartItem');
$router->delete('/cart', 'clearCart');
$router->delete('/cart/{id}', 'removeCartItem');

// ---------- Orders ----------
$router->get('/orders', 'listOrders');
$router->post('/orders', 'createOrder');
$router->get('/orders/{id}', 'readOrder');
$router->patch('/orders/{id}', 'updateOrderStatus');
$router->post('/orders/{id}/pay', 'payOrder');
$router->post('/orders/{id}/confirm', 'confirmOrder');
$router->post('/orders/{id}/review', 'createReview');
$router->post('/reviews/{id}/reply', 'replyToReview');

$router->dispatch($request->method(), $request->path());
