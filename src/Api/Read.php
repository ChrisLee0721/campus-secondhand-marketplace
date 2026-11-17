<?php
/**
 * Read — operations that only read data
 *
 * Shape: guard, read parameters, SELECT, return 200.
 *
 * Each function is exactly one public API. They are reached only through the
 * route table in public/api/index.php, so a route maps to one function name.
 */
declare(strict_types=1);

use App\Http\ApiException;
use App\Auth\Auth;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductComment;
use App\Models\ProfileComment;
use App\Models\Report;
use App\Http\Request;
use App\Http\Response;
use App\Models\Review;
use App\Models\Stats;
use App\Support\Upload;
use App\Models\User;
use App\Support\Validate;

/** Verify credentials and start a session. */
function login(): never
{
    $data = Validate::make(api_request()->body())
        ->required('email', 'password')
        ->validate();

    $user = User::findByEmail(mb_strtolower(trim((string) $data['email'])));

    // Unknown email and wrong password share one message so the API never reveals which emails exist.
    if ($user === null || !User::verifyPassword((string) $data['password'], $user['password'])) {
        throw new ApiException(401, 'invalid_credentials', 'Incorrect email or password');
    }

    if ((int) ($user['is_active'] ?? 1) === 0) {
        throw new ApiException(403, 'account_disabled', 'Account disabled. Please contact an admin.');
    }

    Auth::login($user);
    Response::json(User::publicView($user, true));
}

/** Return the signed-in user, or null for a guest. */
function me(): never
{
    $user = Auth::publicUser();
    if ($user === null) {
        Response::json(null);
    }
    Response::json($user);
}

/** Return a filtered, paginated page of listings. */
function listProducts(): never
{
    $req = api_request();

    $page    = $req->queryInt('page', 1, 1);
    $perPage = $req->queryInt('per_page', (int) config('pagination.default_per_page'), 1,
                                    (int) config('pagination.max_per_page'));

    $status = $req->queryTrimmed('status');
    if ($status !== '' && !in_array($status, Product::STATUSES, true)) {
        throw ApiException::badRequest('status must be new / used / damaged');
    }

    $filters = [
        'category_id'  => $req->queryInt('category_id', 0, 0),
        'seller_id'    => $req->queryInt('seller_id', 0, 0),
        'search'       => $req->queryTrimmed('search'),
        'min_price'    => $req->queryFloatOrNull('min_price'),
        'max_price'    => $req->queryFloatOrNull('max_price'),
        'status'       => $status,
        'include_sold' => $req->queryTrimmed('include_sold') === '1',
    ];

    if ($filters['min_price'] !== null && $filters['max_price'] !== null
        && $filters['min_price'] > $filters['max_price']) {
        throw ApiException::badRequest('min_price cannot be greater than max_price');
    }

    $result = Product::paginate($filters, $page, $perPage);

    Response::collection(
        array_map([Product::class, 'view'], $result['items']),
        [
            'page'        => $result['page'],
            'per_page'    => $result['per_page'],
            'total'       => $result['total'],
            'total_pages' => $result['total_pages'],
        ]
    );
}

/** Return one listing with seller and category details. */
function readProduct(string $id): never
{
    $product = Product::findDetailed((int) $id);
    if ($product === null) {
        throw ApiException::notFound('Product not found');
    }

    $view = Product::view($product);
    $view['seller_rating'] = Review::sellerRating((int) $product['seller_id']);
    $view['seller_level'] = (int) ($product['seller_level'] ?? 1);

    Response::json($view);
}

/** Estimate a market price from live eBay listings. */
function getEbayPrice(string $id): never
{
    $product = Product::findDetailed((int) $id);
    if ($product === null) {
        throw ApiException::notFound('Product not found');
    }

    $title = trim((string) $product['title']);
    if ($title === '' || !preg_match('/[A-Za-z0-9]/', $title)) {
        Response::json(null);
    }

    $token = api_ebay_token();
    if ($token === null) {
        Response::json(null);
    }

    $result = api_ebay_search($title, $token);
    if ($result === null) {
        Response::json(null);
    }

    $rate = api_usd_to_hkd_rate();
    $result['min_hkd'] = round($result['min'] * $rate, 2);
    $result['max_hkd'] = round($result['max'] * $rate, 2);

    Response::json($result);
}

/** Stream the listing image as binary data. */
function readProductImage(string $id): never
{
    $product = Product::find((int) $id);
    if ($product === null) {
        throw ApiException::notFound('Product not found');
    }

    if (!empty($product['image_blob'])) {
        $mime = (string) ($product['image_mime'] ?? '');
        $cfg = config('uploads');
        if (!in_array($mime, $cfg['mime'], true)) {
            $mime = 'application/octet-stream';
        }
        Response::image((string) $product['image_blob'], $mime);
    }

    $path = (string) ($product['image_path'] ?? '');
    if ($path === '') {
        throw ApiException::notFound('Product has no image');
    }

    // Legacy rows may point at an external URL; redirect to it.
    if (preg_match('#^https?://#i', $path)) {
        header('Location: ' . $path, true, 302);
        exit;
    }

    $stored = Upload::readStoredImage($path);
    if ($stored === null) {
        throw ApiException::notFound('Image not found');
    }

    Response::image($stored['body'], $stored['mime']);
}

/** Return the listings owned by the current user. */
function listMyProducts(): never
{
    $req = api_request();

    Auth::requireAuth();

    $page    = $req->queryInt('page', 1, 1);
    $perPage = $req->queryInt('per_page', 12, 1, (int) config('pagination.max_per_page'));

    $result = Product::paginate(
        ['seller_id' => Auth::id(), 'include_sold' => true],
        $page,
        $perPage
    );

    Response::collection(
        array_map([Product::class, 'view'], $result['items']),
        [
            'page'        => $result['page'],
            'per_page'    => $result['per_page'],
            'total'       => $result['total'],
            'total_pages' => $result['total_pages'],
        ]
    );
}

/** Return the category tree with live listing counts. */
function listCategories(): never
{
    Response::json(array_map([Category::class, 'view'], Category::allWithCounts()));
}

/** Return the comment thread of a listing. */
function listProductComments(string $productId): never
{
    $req = api_request();

    $id = (int) $productId;
    if (Product::find($id) === null) {
        throw ApiException::notFound('Product not found');
    }

    $page = $req->queryInt('page', 1, 1);
    $perPage = $req->queryInt('per_page', 10, 1, 50);
    $result = ProductComment::forProduct($id, $page, $perPage);

    // forProduct() already returns the view shape including replies, so do not map it twice here.
    Response::collection(
        $result['items'],
        [
            'page'        => $result['page'],
            'per_page'    => $result['per_page'],
            'total'       => $result['total'],
            'total_pages' => $result['total_pages'],
        ]
    );
}

/** Return the wall comments of a user profile. */
function listProfileComments(string $userId): never
{
    $req = api_request();

    $id = (int) $userId;
    if (!User::exists($id)) {
        throw ApiException::notFound('User not found');
    }

    $page = $req->queryInt('page', 1, 1);
    $perPage = $req->queryInt('per_page', 10, 1, 50);
    $result = ProfileComment::forUser($id, $page, $perPage);

    Response::collection(
        array_map([ProfileComment::class, 'view'], $result['items']),
        [
            'page'        => $result['page'],
            'per_page'    => $result['per_page'],
            'total'       => $result['total'],
            'total_pages' => $result['total_pages'],
        ]
    );
}

/** Return the reviews received by a seller. */
function listSellerReviews(string $userId): never
{
    $req = api_request();

    $sellerId = (int) $userId;
    if (!User::exists($sellerId)) {
        throw ApiException::notFound('User not found');
    }

    $page = $req->queryInt('page', 1, 1);
    $perPage = $req->queryInt('per_page', 10, 1, 50);
    $role = $req->queryTrimmed('role', 'seller');
    if (!in_array($role, ['seller', 'buyer'], true)) {
        throw ApiException::badRequest('role must be seller or buyer');
    }

    $result = $role === 'buyer'
        ? Review::forBuyer($sellerId, $page, $perPage)
        : Review::forSeller($sellerId, $page, $perPage);

    Response::json([
        'rating' => $role === 'buyer'
            ? Review::buyerRating($sellerId)
            : Review::sellerRating($sellerId),
        'items'  => array_map([Review::class, 'view'], $result['items']),
    ], 200, [
        'pagination' => [
            'page'        => $result['page'],
            'per_page'    => $result['per_page'],
            'total'       => $result['total'],
            'total_pages' => $result['total_pages'],
        ],
    ]);
}

/** Return the reviews written for a listing. */
function listProductReviews(string $productId): never
{
    $req = api_request();

    $product = Product::find((int) $productId);
    if ($product === null) {
        throw ApiException::notFound('Product not found');
    }

    $page = $req->queryInt('page', 1, 1);
    $perPage = $req->queryInt('per_page', 10, 1, 50);
    $result = Review::forProduct((int) $productId, $page, $perPage);

    Response::collection(
        array_map([Review::class, 'view'], $result['items']),
        [
            'page'        => $result['page'],
            'per_page'    => $result['per_page'],
            'total'       => $result['total'],
            'total_pages' => $result['total_pages'],
        ]
    );
}

/** Return a public seller profile. */
function readUser(string $id): never
{
    $user = User::find((int) $id);
    if ($user === null) {
        throw ApiException::notFound('User not found');
    }

    // Only public fields are returned: no email unless this is the owner.
    $isSelf = \App\Auth\Auth::id() === (int) $id;

    Response::json([
        ...User::publicView($user, $isSelf),
        'product_count' => Product::countBySeller((int) $id),
        'sold_count'    => Product::countSoldBySeller((int) $id),
        'rating'        => Review::sellerRating((int) $id),
        'buyer_rating'  => Review::buyerRating((int) $id),
    ]);
}

/** Return the cart with per-line totals. */
function listCart(): never
{
    Auth::requireAuth();
    Response::json(CartItem::summary((int) Auth::id()));
}

/** Return the orders where the user is buyer or seller. */
function listOrders(): never
{
    Auth::requireAuth();
    $userId = (int) Auth::id();

    $role = api_request()->queryTrimmed('role', 'buyer');
    $rows = $role === 'seller'
        ? Order::forSeller($userId)
        : Order::forBuyer($userId);

    Response::json(array_map([Order::class, 'view'], $rows));
}

/** Return one order visible to its buyer or seller. */
function readOrder(string $id): never
{
    Auth::requireAuth();

    $order = Order::findDetailed((int) $id);
    if ($order === null) {
        throw ApiException::notFound('Order not found');
    }

    $userId = (int) Auth::id();
    $isBuyer  = (int) $order['buyer_id'] === $userId;
    $isSeller = (int) $order['seller_id'] === $userId;
    if (!$isBuyer && !$isSeller) {
        throw ApiException::forbidden('You can only view your own orders');
    }

    Response::json(Order::view($order));
}

/** Admin: paginate all users. */
function adminListUsers(): never
{
    $req = api_request();

    Auth::requireAdmin();

    $page = $req->queryInt('page', 1, 1);
    $perPage = $req->queryInt('per_page', 20, 1, 100);
    $search = $req->queryTrimmed('search');

    $result = User::adminPaginate($search, $page, $perPage);

    Response::collection(
        array_map([User::class, 'adminView'], $result['items']),
        [
            'page'        => $result['page'],
            'per_page'    => $result['per_page'],
            'total'       => $result['total'],
            'total_pages' => $result['total_pages'],
        ]
    );
}

/** Admin: paginate every listing. */
function adminListProducts(): never
{
    $req = api_request();

    Auth::requireAdmin();

    $page = $req->queryInt('page', 1, 1);
    $perPage = $req->queryInt('per_page', 20, 1, 100);
    $status = $req->queryTrimmed('status');
    if (!in_array($status, ['', 'available', 'sold', 'hidden'], true)) {
        throw ApiException::badRequest('Invalid product status');
    }
    $result = Product::adminPaginate($page, $perPage, $status);

    Response::collection(
        array_map([Product::class, 'adminView'], $result['items']),
        api_pagination($result)
    );
}

/** Admin: paginate reviews. */
function adminListReviews(): never
{
    $req = api_request();

    Auth::requireAdmin();

    $page = $req->queryInt('page', 1, 1);
    $perPage = $req->queryInt('per_page', 10, 1, 50);
    $result = Review::adminPaginate($page, $perPage);

    Response::collection(
        array_map([Review::class, 'view'], $result['items']),
        api_pagination($result)
    );
}

/** Admin: paginate orders by escrow state. */
function adminListOrders(): never
{
    $req = api_request();

    Auth::requireAdmin();

    $page = $req->queryInt('page', 1, 1);
    $perPage = $req->queryInt('per_page', 20, 1, 100);
    $status = $req->queryTrimmed('escrow_status', 'held');
    if (!in_array($status, ['held', 'released', 'refunded', 'unpaid', ''], true)) {
        throw ApiException::badRequest('Invalid escrow status');
    }
    $result = Order::adminPaginate($page, $perPage, $status);

    Response::collection(
        array_map([Order::class, 'view'], $result['items']),
        api_pagination($result)
    );
}

/** Admin: paginate listing reports. */
function adminListReports(): never
{
    $req = api_request();

    Auth::requireAdmin();

    $page = $req->queryInt('page', 1, 1);
    $perPage = $req->queryInt('per_page', 10, 1, 50);
    $result = Report::adminPaginate($page, $perPage);

    Response::collection(
        array_map([Report::class, 'view'], $result['items']),
        api_pagination($result)
    );
}

/** Admin: paginate listing comments. */
function adminListProductComments(): never
{
    $req = api_request();

    Auth::requireAdmin();

    $page = $req->queryInt('page', 1, 1);
    $perPage = $req->queryInt('per_page', 10, 1, 50);
    $result = ProductComment::adminPaginate($page, $perPage);

    Response::collection(
        array_map([ProductComment::class, 'view'], $result['items']),
        api_pagination($result)
    );
}

/** Admin: paginate profile comments. */
function adminListProfileComments(): never
{
    $req = api_request();

    Auth::requireAdmin();

    $page = $req->queryInt('page', 1, 1);
    $perPage = $req->queryInt('per_page', 10, 1, 50);
    $result = ProfileComment::adminPaginate($page, $perPage);

    Response::collection(
        array_map([ProfileComment::class, 'view'], $result['items']),
        api_pagination($result)
    );
}

/** Report PHP and database versions. */
function healthCheck(): never
{
    $pdo = \App\Database::conn();
    Response::json([
        'status'   => 'ok',
        'php'      => PHP_VERSION,
        'database' => $pdo->query('SELECT VERSION()')->fetchColumn(),
        'time'     => date('c'),
    ]);
}

/** Return the counters shown on the home page. */
function getStats(): never
{
    Response::json(Stats::summary());
}
