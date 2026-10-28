<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Models\Order;
use App\Support\Validate;

/**
 * 订单接口（全部需要登录）
 *   GET   /api/v1/orders              我买到的（?role=seller 看我卖出的）
 *   POST  /api/v1/orders              结算购物车
 *   GET   /api/v1/orders/{id}         订单详情
 *   PATCH /api/v1/orders/{id}         改状态（取消 / 完成）
 */
final class OrderController
{
    public function __construct(private Request $req) {}

    public function index(): never
    {
        Auth::requireAuth();
        $userId = (int) Auth::id();

        $role = $this->req->queryTrimmed('role', 'buyer');
        $rows = $role === 'seller'
            ? Order::forSeller($userId)
            : Order::forBuyer($userId);

        Response::json(array_map([Order::class, 'view'], $rows));
    }

    /** 结算。body 可选 {"cart_ids": [1,2,3]}，不传则结算全部。 */
    public function store(): never
    {
        Auth::requireAuth();

        $body = $this->req->body();
        $cartIds = [];

        if (isset($body['cart_ids'])) {
            if (!is_array($body['cart_ids'])) {
                throw ApiException::badRequest('cart_ids 必须是数组');
            }
            $cartIds = array_map('intval', $body['cart_ids']);
        }

        $result = Order::checkout((int) Auth::id(), $cartIds);

        Response::created([
            'orders' => $result['orders'],
            'total'  => $result['total'],
        ], '/api/v1/orders');
    }

    public function show(string $id): never
    {
        Auth::requireAuth();

        $order = Order::findDetailed((int) $id);
        if ($order === null) {
            throw ApiException::notFound('订单不存在');
        }

        $userId = (int) Auth::id();
        $isBuyer  = (int) $order['buyer_id'] === $userId;
        $isSeller = (int) $order['seller_id'] === $userId;
        if (!$isBuyer && !$isSeller) {
            throw ApiException::forbidden('只能查看与自己相关的订单');
        }

        Response::json(Order::view($order));
    }

    public function update(string $id): never
    {
        Auth::requireAuth();

        $order = Order::findDetailed((int) $id);
        if ($order === null) {
            throw ApiException::notFound('订单不存在');
        }

        $userId = (int) Auth::id();
        $isBuyer  = (int) $order['buyer_id'] === $userId;
        $isSeller = (int) $order['seller_id'] === $userId;
        if (!$isBuyer && !$isSeller) {
            throw ApiException::forbidden('只能操作与自己相关的订单');
        }

        $data = Validate::make($this->req->body())
            ->required('status')
            ->in('status', [Order::STATUS_PENDING, Order::STATUS_COMPLETED, Order::STATUS_CANCELLED])
            ->validate();

        $newStatus = (string) $data['status'];

        // 幂等：已经是目标状态就直接返回
        if ($order['status'] === $newStatus) {
            Response::json(Order::view($order));
        }

        // 状态机：只允许 pending → completed / cancelled
        if ($order['status'] !== Order::STATUS_PENDING) {
            throw ApiException::conflict("订单当前状态是 {$order['status']}，不能再改为 {$newStatus}");
        }

        if ($newStatus === Order::STATUS_CANCELLED) {
            // 取消订单要把商品放回在售
            if (!$isBuyer) {
                throw ApiException::forbidden('只有买家可以取消订单');
            }
            \App\Database::transaction(static function ($pdo) use ($order) {
                $stmt = $pdo->prepare(
                    'UPDATE orders SET status = ? WHERE id = ? AND status = ?'
                );
                $stmt->execute([
                    Order::STATUS_CANCELLED,
                    (int) $order['id'],
                    Order::STATUS_PENDING,
                ]);
                if ($stmt->rowCount() !== 1) {
                    throw ApiException::conflict('订单状态已变化，请刷新后重试');
                }
                $pdo->prepare('UPDATE products SET is_sold = 0 WHERE id = ?')
                    ->execute([(int) $order['product_id']]);
            });
        } elseif ($newStatus === Order::STATUS_COMPLETED) {
            if (!$isSeller) {
                throw ApiException::forbidden('只有卖家可以将订单标记为完成');
            }
            $stmt = \App\Database::conn()->prepare(
                'UPDATE orders SET status = ? WHERE id = ? AND status = ?'
            );
            $stmt->execute([Order::STATUS_COMPLETED, (int) $id, Order::STATUS_PENDING]);
            if ($stmt->rowCount() !== 1) {
                throw ApiException::conflict('订单状态已变化，请刷新后重试');
            }
        } else {
            throw ApiException::badRequest('订单状态只能改为 completed 或 cancelled');
        }

        Response::json(Order::view(Order::findDetailed((int) $id)));
    }
}
