<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductComment;
use App\Models\ProfileComment;
use App\Models\Report;
use App\Models\Review;
use App\Models\User;
use App\Support\Validate;

/**
 * 管理员后台：用户 / 评价 / 商品留言 / 主页留言
 *   GET   /api/v1/admin/users
 *   PATCH /api/v1/admin/users/{id}
 *   GET   /api/v1/admin/reviews             PATCH /api/v1/admin/reviews/{id}
 *   GET   /api/v1/admin/product-comments    PATCH /api/v1/admin/product-comments/{id}
 *   GET   /api/v1/admin/profile-comments    PATCH /api/v1/admin/profile-comments/{id}
 */
final class AdminController
{
    public function __construct(private Request $req) {}

    public function users(): never
    {
        Auth::requireAdmin();

        $page = $this->req->queryInt('page', 1, 1);
        $perPage = $this->req->queryInt('per_page', 20, 1, 100);
        $search = $this->req->queryTrimmed('search');

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

    public function updateUser(string $id): never
    {
        Auth::requireAdmin();

        $targetId = (int) $id;
        $target = User::find($targetId);
        if ($target === null) {
            throw ApiException::notFound('User not found');
        }

        $body = $this->req->body();
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

        // 最后一个启用状态的管理员不能被降级或禁用，否则系统会失去管理入口。
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

    public function products(): never
    {
        Auth::requireAdmin();

        $page = $this->req->queryInt('page', 1, 1);
        $perPage = $this->req->queryInt('per_page', 20, 1, 100);
        $status = $this->req->queryTrimmed('status');
        if (!in_array($status, ['', 'available', 'sold', 'hidden'], true)) {
            throw ApiException::badRequest('Invalid product status');
        }
        $result = Product::adminPaginate($page, $perPage, $status);

        Response::collection(
            array_map([Product::class, 'adminView'], $result['items']),
            $this->pagination($result)
        );
    }

    public function updateProduct(string $id): never
    {
        Auth::requireAdmin();

        $productId = (int) $id;
        if (Product::find($productId) === null) {
            throw ApiException::notFound('Product not found');
        }

        $data = Validate::make($this->req->body())
            ->required('is_hidden')
            ->boolean('is_hidden')
            ->validate();

        Product::adminSetHidden($productId, (bool) $data['is_hidden']);

        Response::json(Product::adminView(Product::adminFind($productId)));
    }

    public function destroyProduct(string $id): never
    {
        Auth::requireAdmin();

        $productId = (int) $id;
        if (Product::find($productId) === null) {
            throw ApiException::notFound('Product not found');
        }

        Product::adminDelete($productId);
        Response::noContent();
    }

    public function reviews(): never
    {
        Auth::requireAdmin();

        $page = $this->req->queryInt('page', 1, 1);
        $perPage = $this->req->queryInt('per_page', 10, 1, 50);
        $result = Review::adminPaginate($page, $perPage);

        Response::collection(
            array_map([Review::class, 'view'], $result['items']),
            $this->pagination($result)
        );
    }

    public function updateReview(string $id): never
    {
        Auth::requireAdmin();

        $reviewId = (int) $id;
        if (Review::find($reviewId) === null) {
            throw ApiException::notFound('Review not found');
        }

        $hidden = $this->hiddenFlag();
        Review::setHidden($reviewId, $hidden);

        Response::json(Review::view(Review::findDetailed($reviewId)));
    }

    public function orders(): never
    {
        Auth::requireAdmin();

        $page = $this->req->queryInt('page', 1, 1);
        $perPage = $this->req->queryInt('per_page', 20, 1, 100);
        $status = $this->req->queryTrimmed('escrow_status', 'held');
        if (!in_array($status, ['held', 'released', 'refunded', 'unpaid', ''], true)) {
            throw ApiException::badRequest('Invalid escrow status');
        }
        $result = Order::adminPaginate($page, $perPage, $status);

        Response::collection(
            array_map([Order::class, 'view'], $result['items']),
            $this->pagination($result)
        );
    }

    public function releaseOrder(string $id): never
    {
        Auth::requireAdmin();
        if (Order::find((int) $id) === null) {
            throw ApiException::notFound('Order not found');
        }

        Order::release((int) $id);
        Response::json(Order::view(Order::findDetailed((int) $id)));
    }

    public function refundOrder(string $id): never
    {
        Auth::requireAdmin();
        if (Order::find((int) $id) === null) {
            throw ApiException::notFound('Order not found');
        }

        Order::refund((int) $id);
        Response::json(Order::view(Order::findDetailed((int) $id)));
    }

    public function reports(): never
    {
        Auth::requireAdmin();

        $page = $this->req->queryInt('page', 1, 1);
        $perPage = $this->req->queryInt('per_page', 10, 1, 50);
        $result = Report::adminPaginate($page, $perPage);

        Response::collection(
            array_map([Report::class, 'view'], $result['items']),
            $this->pagination($result)
        );
    }

    public function updateReport(string $id): never
    {
        Auth::requireAdmin();

        $reportId = (int) $id;
        if (Report::adminFind($reportId) === null) {
            throw ApiException::notFound('Report not found');
        }

        $data = Validate::make($this->req->body())
            ->required('status')
            ->in('status', ['resolved', 'dismissed'])
            ->validate();

        Report::mark($reportId, (string) $data['status'], (int) Auth::id());
        Response::json(Report::view(Report::adminFind($reportId)));
    }

    public function productComments(): never
    {
        Auth::requireAdmin();

        $page = $this->req->queryInt('page', 1, 1);
        $perPage = $this->req->queryInt('per_page', 10, 1, 50);
        $result = ProductComment::adminPaginate($page, $perPage);

        Response::collection(
            array_map([ProductComment::class, 'view'], $result['items']),
            $this->pagination($result)
        );
    }

    public function updateProductComment(string $id): never
    {
        Auth::requireAdmin();

        $commentId = (int) $id;
        if (ProductComment::find($commentId) === null) {
            throw ApiException::notFound('Comment not found');
        }

        $hidden = $this->hiddenFlag();
        ProductComment::setHidden($commentId, $hidden);

        Response::json(ProductComment::view(ProductComment::findDetailed($commentId)));
    }

    public function profileComments(): never
    {
        Auth::requireAdmin();

        $page = $this->req->queryInt('page', 1, 1);
        $perPage = $this->req->queryInt('per_page', 10, 1, 50);
        $result = ProfileComment::adminPaginate($page, $perPage);

        Response::collection(
            array_map([ProfileComment::class, 'view'], $result['items']),
            $this->pagination($result)
        );
    }

    public function updateProfileComment(string $id): never
    {
        Auth::requireAdmin();

        $commentId = (int) $id;
        if (ProfileComment::find($commentId) === null) {
            throw ApiException::notFound('Comment not found');
        }

        $hidden = $this->hiddenFlag();
        ProfileComment::setHidden($commentId, $hidden);

        Response::json(ProfileComment::view(ProfileComment::findDetailed($commentId)));
    }

    /** @param array{page:int,per_page:int,total:int,total_pages:int} $result */
    private function pagination(array $result): array
    {
        return [
            'page'        => $result['page'],
            'per_page'    => $result['per_page'],
            'total'       => $result['total'],
            'total_pages' => $result['total_pages'],
        ];
    }

    private function hiddenFlag(): bool
    {
        $body = $this->req->body();
        if (!array_key_exists('is_hidden', $body)) {
            throw ApiException::badRequest('Missing is_hidden');
        }
        Validate::make($body)->boolean('is_hidden')->validate();
        return (bool) $body['is_hidden'];
    }
}
