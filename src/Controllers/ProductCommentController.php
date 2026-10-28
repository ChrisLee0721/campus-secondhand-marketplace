<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Models\Product;
use App\Models\ProductComment;
use App\Support\Validate;

/**
 * 商品留言 / 售前问答
 *   GET    /api/v1/products/{id}/comments
 *   POST   /api/v1/products/{id}/comments       body: {body, parent_id?}
 *   DELETE /api/v1/products/{id}/comments/{commentId}
 */
final class ProductCommentController
{
    public function __construct(private Request $req) {}

    public function index(string $productId): never
    {
        $id = (int) $productId;
        if (Product::find($id) === null) {
            throw ApiException::notFound('商品不存在');
        }

        $page = $this->req->queryInt('page', 1, 1);
        $perPage = $this->req->queryInt('per_page', 10, 1, 50);
        $result = ProductComment::forProduct($id, $page, $perPage);

        // forProduct() 已经返回 view 结构（含 replies），这里不要再 map 一次
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

    public function store(string $productId): never
    {
        Auth::requireAuth();

        $data = Validate::make($this->req->body())
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

    public function destroy(string $productId, string $commentId): never
    {
        Auth::requireAuth();

        $comment = ProductComment::find((int) $commentId);
        if ($comment === null || (int) $comment['product_id'] !== (int) $productId) {
            throw ApiException::notFound('留言不存在');
        }

        ProductComment::hide((int) $commentId, (int) Auth::id(), Auth::isAdmin());
        Response::noContent();
    }
}
