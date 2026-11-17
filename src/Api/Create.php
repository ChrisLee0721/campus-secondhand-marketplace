<?php
/**
 * Create — operations that insert a new row
 *
 * Shape: guard, validate, INSERT, return 201.
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
use App\Support\Upload;
use App\Models\User;
use App\Support\Validate;

/** Create an account and log the new user in. */
function registerUser(): never
{
    $data = Validate::make(api_request()->body())
        ->required('username', 'email', 'password', 'password_confirmation')
        ->username('username')
        ->stringLength('username', 2, 50)
        ->email('email')
        ->stringLength('password', 6, 72)
        ->confirmed('password', 'password_confirmation')
        ->rule('username', static fn ($v) => $v !== null && User::usernameTaken((string) $v)
            ? 'Username already taken' : null)
        ->rule('email', static fn ($v) => $v !== null && User::emailTaken((string) $v)
            ? 'Email already registered' : null)
        ->validate();

    // Key fix: a validation failure throws 422 and stops here, unlike the
    //   previous version that echoed an error but inserted the row anyway.
    $id = User::create([
        'username' => trim((string) $data['username']),
        'email'    => mb_strtolower(trim((string) $data['email'])),
        'password' => User::hashPassword((string) $data['password']),
    ]);

    $user = User::find($id);
    Auth::login($user);

    Response::created(User::publicView($user, true), '/api/v1/users/' . $id);
}

/** Publish a listing owned by the current user. */
function createProduct(): never
{
    Auth::requireAuth();

    $body = api_request()->body();

    $data = Validate::make($body)
        ->required('title', 'price', 'status')
        ->stringLength('title', 2, 100)
        ->numeric('price', 0.01, 99999999.99)
        ->in('status', Product::STATUSES)
        ->integer('category_id', 1, PHP_INT_MAX)
        ->rule('category_id', static function ($v) {
            if ($v === null || $v === '') {
                return null;
            }
            return \App\Models\Category::exists((int) $v) ? null : 'Category not found';
        })
        ->stringLength('description', 0, 5000)
        ->validate();

    $imagePath = null;
    $imageBlob = null;
    $imageMime = null;
    $file = api_request()->file('image');
    if ($file !== null) {
        $imagePath = Upload::storeImage($file);
        $stored = Upload::readStoredImage($imagePath);
        if ($stored !== null) {
            $imageBlob = $stored['body'];
            $imageMime = $stored['mime'];
        }
    } elseif (!empty($body['image_path']) && is_string($body['image_path'])) {
        // External URLs are allowed; the seed data uses Unsplash links.
        $imagePath = Upload::normalizeImageUrl($body['image_path']);
    }

    $cat = $data['category_id'] ?? null;
    $categoryId = ($cat === null || (is_string($cat) && trim($cat) === ''))
        ? null
        : (int) $cat;

    $id = Product::create([
        'title'       => trim((string) $data['title']),
        'description' => isset($data['description']) ? trim((string) $data['description']) : null,
        'price'       => (string) $data['price'],
        'category_id' => $categoryId,
        'status'      => (string) $data['status'],
        'image_path'  => $imagePath,
        'image_blob'  => $imageBlob,
        'image_mime'  => $imageMime,
        'seller_id'   => (int) Auth::id(),
    ]);

    Response::created(Product::view(Product::findDetailed($id)), '/api/v1/products/' . $id);
}

/** Add a comment to a listing. */
function createProductComment(string $productId): never
{
    Auth::requireAuth();

    $data = Validate::make(api_request()->body())
        ->required('body')
        ->stringLength('body', 1, 1000)
        ->integer('parent_id', 1, PHP_INT_MAX)
        ->validate();

    $parentId = isset($data['parent_id']) && $data['parent_id'] !== ''
        ? (int) $data['parent_id']
        : null;

    $id = ProductComment::add(
        (int) $productId,
        (int) Auth::id(),
        trim((string) $data['body']),
        $parentId
    );

    Response::created(['id' => $id]);
}

/** Post a comment on a user profile. */
function createProfileComment(string $userId): never
{
    Auth::requireAuth();

    $data = Validate::make(api_request()->body())
        ->required('body')
        ->stringLength('body', 1, 1000)
        ->validate();

    $id = ProfileComment::add(
        (int) $userId,
        (int) Auth::id(),
        trim((string) $data['body'])
    );

    Response::created(['id' => $id]);
}

/** File a report against a listing. */
function reportProduct(string $productId): never
{
    Auth::requireAuth();

    $product = Product::find((int) $productId);
    if ($product === null) {
        throw ApiException::notFound('Product not found');
    }
    if ((int) $product['seller_id'] === (int) Auth::id()) {
        throw ApiException::conflict('You cannot report your own product');
    }

    $data = Validate::make(api_request()->body())
        ->required('reason')
        ->in('reason', Report::REASONS)
        ->stringLength('details', 0, 1000)
        ->validate();

    if (Report::pendingExists((int) Auth::id(), (int) $productId)) {
        throw ApiException::conflict('You already have a pending report for this product');
    }

    $id = Report::create([
        'reporter_id' => (int) Auth::id(),
        'product_id'  => (int) $productId,
        'reason'      => (string) $data['reason'],
        'details'     => isset($data['details']) ? trim((string) $data['details']) : null,
    ]);

    Response::created(['id' => $id], '/api/v1/admin/reports/' . $id);
}

/** Review a completed order. */
function createReview(string $orderId): never
{
    Auth::requireAuth();

    $data = Validate::make(api_request()->body())
        ->required('rating')
        ->integer('rating', Review::MIN_RATING, Review::MAX_RATING)
        ->stringLength('comment', 0, 1000)
        ->validate();

    $comment = isset($data['comment']) ? trim((string) $data['comment']) : '';

    $id = Review::createForOrder(
        (int) $orderId,
        (int) Auth::id(),
        (int) $data['rating'],
        $comment
    );

    $review = Review::findDetailed($id);
    Response::created(Review::view($review), '/api/v1/orders/' . $orderId . '/review');
}

/** Add a listing to the cart. */
function addCartItem(): never
{
    Auth::requireAuth();

    $data = Validate::make(api_request()->body())
        ->required('product_id')
        ->integer('product_id', 1, PHP_INT_MAX)
        ->validate();

    $result = CartItem::add((int) Auth::id(), (int) $data['product_id']);

    Response::json([
        'id'       => $result['id'],
        'quantity' => $result['quantity'],
        'created'  => $result['created'],
    ], $result['created'] ? 201 : 200);
}

/** Check out the cart into an escrow-backed order. */
function createOrder(): never
{
    Auth::requireAuth();

    $body = api_request()->body();
    $cartIds = [];

    if (isset($body['cart_ids'])) {
        if (!is_array($body['cart_ids'])) {
            throw ApiException::badRequest('cart_ids must be an array');
        }
        $cartIds = array_map('intval', $body['cart_ids']);
    }

    $result = Order::checkout((int) Auth::id(), $cartIds);

    Response::created([
        'orders' => $result['orders'],
        'total'  => $result['total'],
    ], '/api/v1/orders');
}
