<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;

/**
 * 用户公开资料
 *   GET /api/v1/users/{id}         公开资料 + 在售商品数 + 评分
 *   GET /api/v1/users/{id}/reviews 见 ReviewController
 */
final class UserController
{
    public function __construct(private Request $req) {}

    public function show(string $id): never
    {
        $user = User::find((int) $id);
        if ($user === null) {
            throw ApiException::notFound('用户不存在');
        }

        // 只暴露公开字段：不含 email（除非是本人）
        $isSelf = \App\Auth\Auth::id() === (int) $id;

        Response::json([
            ...User::publicView($user, $isSelf),
            'product_count' => Product::countBySeller((int) $id),
            'sold_count'    => Product::countSoldBySeller((int) $id),
            'rating'        => Review::sellerRating((int) $id),
        ]);
    }
}
