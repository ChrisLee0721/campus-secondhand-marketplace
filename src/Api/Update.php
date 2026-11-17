<?php
/**
 * Update — operations that change an existing row
 *
 * Shape: guard, ownership or role check, UPDATE, return 200.
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

/** Issue a password reset code for the given email. */
function requestPasswordReset(): never
{
    $data = Validate::make(api_request()->body())
        ->required('email')
        ->email('email')
        ->validate();

    $email = mb_strtolower(trim((string) $data['email']));
    $user = User::findByEmail($email);
    if ($user === null) {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['message' => 'If the email exists, a code has been sent.']);
        exit;
    }

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    User::setResetCode((int) $user['id'], $code, time() + 900);

    Response::json(['code' => $code]);
}

/** Consume a reset code and set a new password. */
function resetPassword(): never
{
    $data = Validate::make(api_request()->body())
        ->required('email', 'code', 'new_password')
        ->stringLength('code', 6, 6)
        ->stringLength('new_password', 6, 72)
        ->validate();

    $email = mb_strtolower(trim((string) $data['email']));
    $user = User::findByEmail($email);
    if ($user === null
        || empty($user['reset_code'])
        || !hash_equals((string) $user['reset_code'], (string) $data['code'])
        || (int) $user['reset_expires_at'] < time()) {
        throw ApiException::validation(['code' => 'Invalid or expired code']);
    }

    User::resetPassword((int) $user['id'], User::hashPassword((string) $data['new_password']));

    Response::json(['message' => 'Password reset successfully.']);
}

/** Update profile fields of the current user. */
function updateAccount(): never
{
    Auth::requireAuth();
    $user = Auth::user();
    if ($user === null) {
        throw ApiException::unauthorized();
    }

    $body = api_request()->body();
    $hasUsername = array_key_exists('username', $body);
    $hasEmail = array_key_exists('email', $body);
    $hasBio = array_key_exists('bio', $body);
    $hasMeetupAddress = array_key_exists('meetup_address', $body);

    if (!$hasUsername && !$hasEmail && !$hasBio && !$hasMeetupAddress) {
        throw ApiException::badRequest('No fields to update');
    }

    $validator = Validate::make($body);

    if ($hasUsername) {
        $validator->notEmpty('username')
            ->username('username')
            ->stringLength('username', 2, 50)
            ->rule('username', static fn ($v) => is_string($v)
                && User::usernameTaken($v, (int) $user['id'])
                    ? 'Username already taken'
                    : null);
    }

    if ($hasEmail) {
        $validator->notEmpty('email')
            ->email('email')
            ->rule('email', static fn ($v) => is_string($v)
                && User::emailTaken(strtolower($v), (int) $user['id'])
                    ? 'Email already registered'
                    : null);
    }

    if ($hasBio) {
        $validator->stringLength('bio', 0, 255);
    }

    if ($hasMeetupAddress) {
        $validator->stringLength('meetup_address', 0, 255);
    }

    $validator->validate();

    $updates = [];
    if ($hasUsername) {
        $updates['username'] = trim((string) $body['username']);
    }

    $newEmail = null;
    if ($hasEmail) {
        $newEmail = mb_strtolower(trim((string) $body['email']));
        $updates['email'] = $newEmail;
    }

    if ($hasBio) {
        $bio = trim((string) $body['bio']);
        $updates['bio'] = $bio === '' ? null : $bio;
    }

    if ($hasMeetupAddress) {
        $address = trim((string) $body['meetup_address']);
        $updates['meetup_address'] = $address === '' ? null : $address;
    }

    // Changing the email is sensitive, so the current password must be confirmed.
    if ($newEmail !== null && $newEmail !== mb_strtolower((string) $user['email'])) {
        $current = $body['current_password'] ?? '';
        if (!is_string($current) || $current === ''
            || !User::verifyPassword($current, (string) $user['password'])) {
            throw ApiException::validation(['current_password' => 'Current password is incorrect']);
        }
    }

    User::update((int) $user['id'], $updates);
    Auth::refresh();

    Response::json(User::publicView(User::find((int) $user['id']), true));
}

/** Change the password of the current user. */
function changePassword(): never
{
    Auth::requireAuth();
    $user = Auth::user();
    if ($user === null) {
        throw ApiException::unauthorized();
    }

    $data = Validate::make(api_request()->body())
        ->required('current_password', 'password', 'password_confirmation')
        ->stringLength('current_password', 1, 72)
        ->stringLength('password', 6, 72)
        ->confirmed('password', 'password_confirmation')
        ->validate();

    if (!User::verifyPassword((string) $data['current_password'], (string) $user['password'])) {
        throw ApiException::validation(['current_password' => 'Current password is incorrect']);
    }
    if (hash_equals((string) $data['current_password'], (string) $data['password'])) {
        throw ApiException::validation(['password' => 'New password must be different from the current one']);
    }

    User::update((int) $user['id'], [
        'password' => User::hashPassword((string) $data['password']),
    ]);
    Auth::refresh();

    // Rotate the session id after a password change so old cookies stop working.
    session_regenerate_id(true);

    Response::noContent();
}

/** Replace the avatar file of the current user. */
function updateAvatar(): never
{
    Auth::requireAuth();
    $user = Auth::user();
    if ($user === null) {
        throw ApiException::unauthorized();
    }

    $file = api_request()->file('avatar');
    if ($file === null) {
        throw ApiException::badRequest('Please choose an avatar image');
    }

    $path = Upload::storeImage($file);
    $old = $user['avatar_path'] ?? null;

    User::update((int) $user['id'], ['avatar_path' => $path]);
    if ($old !== $path) {
        Upload::deleteStored($old);
    }
    Auth::refresh();

    Response::json(User::publicView(User::find((int) $user['id']), true));
}

/** Edit a listing owned by the current user. */
function updateProduct(string $id): never
{
    Auth::requireAuth();

    $product = Product::find((int) $id);
    if ($product === null) {
        throw ApiException::notFound('Product not found');
    }
    if ((int) $product['seller_id'] !== Auth::id()) {
        throw ApiException::forbidden('You can only edit your own products');
    }
    if ((int) $product['is_sold'] === 1) {
        throw ApiException::conflict('Sold products cannot be edited');
    }

    $body = api_request()->body();

    $data = Validate::make($body)
        ->notEmpty('title', 'price', 'status')
        ->stringLength('title', 2, 100)
        ->numeric('price', 0.01, 99999999.99)
        ->in('status', Product::STATUSES)
        ->integer('category_id', 1, PHP_INT_MAX)
        ->stringLength('description', 0, 5000)
        ->rule('category_id', static function ($v) {
            if ($v === null || $v === '') {
                return null;
            }
            return \App\Models\Category::exists((int) $v) ? null : 'Category not found';
        })
        ->validate();

    $updates = [];
    foreach (['title', 'description', 'status'] as $field) {
        if (array_key_exists($field, $data)) {
            $updates[$field] = trim((string) $data[$field]);
        }
    }
    if (array_key_exists('price', $data)) {
        $updates['price'] = (string) $data['price'];
    }
    if (array_key_exists('category_id', $data)) {
        $cat = $data['category_id'];
        // null or an empty string clears the category; anything else is cast to int.
        $updates['category_id'] = ($cat === null || (is_string($cat) && trim($cat) === ''))
            ? null
            : (int) $cat;
    }

    $file = api_request()->file('image');
    if ($file !== null) {
        $updates['image_path'] = Upload::storeImage($file);
        $stored = Upload::readStoredImage($updates['image_path']);
        if ($stored !== null) {
            $updates['image_blob'] = $stored['body'];
            $updates['image_mime'] = $stored['mime'];
        }
    }

    if ($updates === []) {
        throw ApiException::badRequest('No fields to update');
    }

    Product::update((int) $id, $updates);
    Response::json(Product::view(Product::findDetailed((int) $id)));
}

/** Seller reply to a review. */
function replyToReview(string $reviewId): never
{
    Auth::requireAuth();

    $data = Validate::make(api_request()->body())
        ->required('reply')
        ->stringLength('reply', 1, 1000)
        ->validate();

    $id = (int) $reviewId;
    Review::reply($id, (int) Auth::id(), trim((string) $data['reply']));

    $review = Review::findDetailed($id);
    Response::json(Review::view($review));
}

/** Move available balance out of the wallet. */
function withdrawFunds(): never
{
    Auth::requireAuth();

    $data = Validate::make(api_request()->body())
        ->required('amount')
        ->numeric('amount', 0.01, 99999999.99)
        ->validate();

    $amount = number_format(round((float) $data['amount'], 2), 2, '.', '');
    User::withdraw((int) Auth::id(), $amount);

    Response::json(['message' => 'Withdrawal successful.']);
}

/** Pay an order into escrow. */
function payOrder(string $id): never
{
    Auth::requireAuth();

    $order = Order::findDetailed((int) $id);
    if ($order === null) {
        throw ApiException::notFound('Order not found');
    }

    $userId = (int) Auth::id();
    if ((int) $order['buyer_id'] !== $userId) {
        throw ApiException::forbidden('You can only pay for your own orders');
    }
    if ((int) $order['seller_id'] === $userId) {
        throw ApiException::conflict('You cannot buy your own item');
    }
    if ($order['status'] !== Order::STATUS_PENDING) {
        throw ApiException::conflict('Order is no longer payable');
    }

    Order::pay((int) $order['id'], $userId, (int) $order['seller_id']);

    Response::json(['message' => 'Payment successful. Funds held in escrow.']);
}

/** Confirm receipt and release escrow to the seller. */
function confirmOrder(string $id): never
{
    Auth::requireAuth();

    $order = Order::findDetailed((int) $id);
    if ($order === null) {
        throw ApiException::notFound('Order not found');
    }
    if ((int) $order['buyer_id'] !== (int) Auth::id()) {
        throw ApiException::forbidden('Only the buyer can confirm receipt');
    }

    Order::release((int) $order['id']);
    Response::json(Order::view(Order::findDetailed((int) $order['id'])));
}

/** Advance an order through its state machine. */
function updateOrderStatus(string $id): never
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
        throw ApiException::forbidden('You can only modify your own orders');
    }

    $data = Validate::make(api_request()->body())
        ->required('status')
        ->in('status', [Order::STATUS_PENDING, Order::STATUS_COMPLETED, Order::STATUS_CANCELLED])
        ->validate();

    $newStatus = (string) $data['status'];

    // Idempotent: if the order is already in the target state, return early.
    if ($order['status'] === $newStatus) {
        Response::json(Order::view($order));
    }

    // State machine: only an unpaid pending order can be cancelled directly; paid or completed orders must go through confirm or refund.
    if ($order['status'] !== Order::STATUS_PENDING) {
        throw ApiException::conflict("Order status is {$order['status']}, cannot be changed to {$newStatus}");
    }
    if ($newStatus === Order::STATUS_COMPLETED) {
        throw ApiException::conflict('Buyer must confirm receipt before the order can be completed');
    }
    if ($newStatus !== Order::STATUS_CANCELLED) {
        throw ApiException::badRequest('Order status can only be changed to cancelled');
    }
    if (!$isBuyer) {
        throw ApiException::forbidden('Only the buyer can cancel this order');
    }
    if (($order['escrow_status'] ?? 'unpaid') !== 'unpaid') {
        throw ApiException::conflict('Paid orders require an admin refund');
    }

    \App\Database::transaction(static function ($pdo) use ($order) {
        $stmt = $pdo->prepare(
            'UPDATE orders SET status = ? WHERE id = ? AND status = ? AND escrow_status = ?'
        );
        $stmt->execute([
            Order::STATUS_CANCELLED,
            (int) $order['id'],
            Order::STATUS_PENDING,
            'unpaid',
        ]);
        if ($stmt->rowCount() !== 1) {
            throw ApiException::conflict('Order status changed. Please refresh and try again.');
        }
        $pdo->prepare('UPDATE products SET is_sold = 0 WHERE id = ?')
            ->execute([(int) $order['product_id']]);
    });

    Response::json(Order::view(Order::findDetailed((int) $id)));
}

/** Admin: change admin and active flags of a user. */
function adminUpdateUser(string $id): never
{
    Auth::requireAdmin();

    $targetId = (int) $id;
    $target = User::find($targetId);
    if ($target === null) {
        throw ApiException::notFound('User not found');
    }

    $body = api_request()->body();
    $hasAdmin = array_key_exists('is_admin', $body);
    $hasActive = array_key_exists('is_active', $body);
    $hasLevel = array_key_exists('level', $body);
    $hasSellerLevel = array_key_exists('seller_level', $body);
    if (!$hasAdmin && !$hasActive && !$hasLevel && !$hasSellerLevel) {
        throw ApiException::badRequest('No fields to update');
    }

    Validate::make($body)
        ->boolean('is_admin')
        ->boolean('is_active')
        ->integer('level', 1, 6)
        ->integer('seller_level', 1, 6)
        ->validate();

    $wasAdmin = (bool) ($target['is_admin'] ?? false);
    $wasActive = (bool) ($target['is_active'] ?? true);
    $isAdmin = $hasAdmin ? (bool) $body['is_admin'] : $wasAdmin;
    $isActive = $hasActive ? (bool) $body['is_active'] : $wasActive;

    $self = Auth::id() === $targetId;
    if ($self && !$isActive) {
        throw ApiException::conflict('You cannot disable yourself');
    }
    if ($self && !$isAdmin) {
        throw ApiException::conflict('You cannot remove your own admin rights');
    }

    // The last active admin cannot be demoted or disabled, otherwise the system loses its admin entry point.
    if ($wasAdmin && $wasActive && (!$isAdmin || !$isActive)
        && User::activeAdminCount() <= 1) {
        throw ApiException::conflict('At least one active admin is required');
    }

    User::setFlags($targetId, $isAdmin, $isActive);
    User::setLevels(
        $targetId,
        $hasLevel ? (int) $body['level'] : null,
        $hasSellerLevel ? (int) $body['seller_level'] : null
    );
    if ($self) {
        Auth::refresh();
    }

    Response::json(User::adminView(User::adminFind($targetId) ?? $target));
}

/** Admin: hide or restore a listing. */
function adminUpdateProduct(string $id): never
{
    Auth::requireAdmin();

    $productId = (int) $id;
    if (Product::find($productId) === null) {
        throw ApiException::notFound('Product not found');
    }

    $data = Validate::make(api_request()->body())
        ->required('is_hidden')
        ->boolean('is_hidden')
        ->validate();

    Product::adminSetHidden($productId, (bool) $data['is_hidden']);

    Response::json(Product::adminView(Product::adminFind($productId)));
}

/** Admin: hide or restore a review. */
function adminUpdateReview(string $id): never
{
    Auth::requireAdmin();

    $reviewId = (int) $id;
    if (Review::find($reviewId) === null) {
        throw ApiException::notFound('Review not found');
    }

    $hidden = api_hidden_flag();
    Review::setHidden($reviewId, $hidden);

    Response::json(Review::view(Review::findDetailed($reviewId)));
}

/** Admin: release escrow to the seller. */
function adminReleaseOrder(string $id): never
{
    Auth::requireAdmin();
    if (Order::find((int) $id) === null) {
        throw ApiException::notFound('Order not found');
    }

    Order::release((int) $id);
    Response::json(Order::view(Order::findDetailed((int) $id)));
}

/** Admin: refund escrow to the buyer. */
function adminRefundOrder(string $id): never
{
    Auth::requireAdmin();
    if (Order::find((int) $id) === null) {
        throw ApiException::notFound('Order not found');
    }

    Order::refund((int) $id);
    Response::json(Order::view(Order::findDetailed((int) $id)));
}

/** Admin: mark a report as handled. */
function adminUpdateReport(string $id): never
{
    Auth::requireAdmin();

    $reportId = (int) $id;
    if (Report::adminFind($reportId) === null) {
        throw ApiException::notFound('Report not found');
    }

    $data = Validate::make(api_request()->body())
        ->required('status')
        ->in('status', ['resolved', 'dismissed'])
        ->validate();

    Report::mark($reportId, (string) $data['status'], (int) Auth::id());
    Response::json(Report::view(Report::adminFind($reportId)));
}

/** Admin: hide or restore a listing comment. */
function adminUpdateProductComment(string $id): never
{
    Auth::requireAdmin();

    $commentId = (int) $id;
    if (ProductComment::find($commentId) === null) {
        throw ApiException::notFound('Comment not found');
    }

    $hidden = api_hidden_flag();
    ProductComment::setHidden($commentId, $hidden);

    Response::json(ProductComment::view(ProductComment::findDetailed($commentId)));
}

/** Admin: hide or restore a profile comment. */
function adminUpdateProfileComment(string $id): never
{
    Auth::requireAdmin();

    $commentId = (int) $id;
    if (ProfileComment::find($commentId) === null) {
        throw ApiException::notFound('Comment not found');
    }

    $hidden = api_hidden_flag();
    ProfileComment::setHidden($commentId, $hidden);

    Response::json(ProfileComment::view(ProfileComment::findDetailed($commentId)));
}
