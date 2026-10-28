<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use App\Support\Validate;

/**
 * 评价接口
 *   GET  /api/v1/products/{id}/reviews   该商品的评价（分页）
 *   GET  /api/v1/users/{id}/reviews      某卖家收到的评价 + 平均分（分页）
 *   POST /api/v1/orders/{id}/review      对某订单评价（登录 + 仅买家 + completed）
 *   POST /api/v1/reviews/{id}/reply      卖家回复评价
 */
final class ReviewController
{
    public function __construct(private Request $req) {}

    public function forSeller(string $userId): never
    {
        $sellerId = (int) $userId;
        if (!User::exists($sellerId)) {
            throw ApiException::notFound('用户不存在');
        }

        $page = $this->req->queryInt('page', 1, 1);
        $perPage = $this->req->queryInt('per_page', 10, 1, 50);
        $result = Review::forSeller($sellerId, $page, $perPage);

        Response::json([
            'rating' => Review::sellerRating($sellerId),
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

    public function forProduct(string $productId): never
    {
        $product = Product::find((int) $productId);
        if ($product === null) {
            throw ApiException::notFound('商品不存在');
        }

        $page = $this->req->queryInt('page', 1, 1);
        $perPage = $this->req->queryInt('per_page', 10, 1, 50);
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

    public function store(string $orderId): never
    {
        Auth::requireAuth();

        $data = Validate::make($this->req->body())
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

    public function reply(string $reviewId): never
    {
        Auth::requireAuth();

        $data = Validate::make($this->req->body())
            ->required('reply')
            ->stringLength('reply', 1, 1000)
            ->validate();

        $id = (int) $reviewId;
        Review::reply($id, (int) Auth::id(), trim((string) $data['reply']));

        $review = Review::findDetailed($id);
        Response::json(Review::view($review));
    }
}
