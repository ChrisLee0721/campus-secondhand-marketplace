<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Models\CartItem;
use App\Support\Validate;

/**
 * 购物车接口（全部需要登录）
 *   GET    /api/v1/cart               查看（含合计）
 *   POST   /api/v1/cart               加入（每件商品只有一份）
 *   DELETE /api/v1/cart/{id}          移除单项
 *   DELETE /api/v1/cart               清空
 */
final class CartController
{
    public function __construct(private Request $req) {}

    public function index(): never
    {
        Auth::requireAuth();
        Response::json(CartItem::summary((int) Auth::id()));
    }

    public function store(): never
    {
        Auth::requireAuth();

        $data = Validate::make($this->req->body())
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

    public function destroy(string $id): never
    {
        Auth::requireAuth();

        if (!CartItem::removeForUser((int) $id, (int) Auth::id())) {
            throw ApiException::notFound('购物车条目不存在');
        }
        Response::noContent();
    }

    public function clear(): never
    {
        Auth::requireAuth();
        CartItem::clearForUser((int) Auth::id());
        Response::noContent();
    }
}
