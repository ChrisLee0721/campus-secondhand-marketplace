<?php
/**
 * Delete — operations that remove or invalidate a row
 *
 * Shape: guard, ownership or role check, DELETE, return 204.
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

/** Destroy the current session. */
function logout(): never
{
    Auth::logout();
    Response::noContent();
}

/** Delete a listing owned by the current user. */
function deleteProduct(string $id): never
{
    Auth::requireAuth();

    $product = Product::find((int) $id);
    if ($product === null) {
        throw ApiException::notFound('Product not found');
    }
    if ((int) $product['seller_id'] !== Auth::id()) {
        throw ApiException::forbidden('You can only delete your own products');
    }

    // A listing that already has orders cannot be deleted; the foreign key would block it, so fail early with a readable message.
    $stmt = \App\Database::conn()->prepare('SELECT COUNT(*) FROM orders WHERE product_id = ?');
    $stmt->execute([(int) $id]);
    if ((int) $stmt->fetchColumn() > 0) {
        throw ApiException::conflict('This product has order records and cannot be deleted');
    }

    Product::delete((int) $id);
    Response::noContent();
}

/** Delete a listing comment as author, seller or admin. */
function deleteProductComment(string $productId, string $commentId): never
{
    Auth::requireAuth();

    $comment = ProductComment::find((int) $commentId);
    if ($comment === null || (int) $comment['product_id'] !== (int) $productId) {
        throw ApiException::notFound('Comment not found');
    }

    ProductComment::hide((int) $commentId, (int) Auth::id(), Auth::isAdmin());
    Response::noContent();
}

/** Delete a profile comment as author, owner or admin. */
function deleteProfileComment(string $userId, string $commentId): never
{
    Auth::requireAuth();

    $comment = ProfileComment::find((int) $commentId);
    if ($comment === null || (int) $comment['profile_user_id'] !== (int) $userId) {
        throw ApiException::notFound('Comment not found');
    }

    ProfileComment::hide((int) $commentId, (int) Auth::id(), Auth::isAdmin());
    Response::noContent();
}

/** Remove one line from the cart. */
function removeCartItem(string $id): never
{
    Auth::requireAuth();

    if (!CartItem::removeForUser((int) $id, (int) Auth::id())) {
        throw ApiException::notFound('Cart item not found');
    }
    Response::noContent();
}

/** Empty the cart. */
function clearCart(): never
{
    Auth::requireAuth();
    CartItem::clearForUser((int) Auth::id());
    Response::noContent();
}

/** Admin: delete any listing. */
function adminDeleteProduct(string $id): never
{
    Auth::requireAdmin();

    $productId = (int) $id;
    if (Product::find($productId) === null) {
        throw ApiException::notFound('Product not found');
    }

    Product::adminDelete($productId);
    Response::noContent();
}
