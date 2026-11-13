<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Models\Order;
use App\Models\User;
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

    public function show(string $id): never
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

    public function pay(string $id): never
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

        Response::json(['message' => 'Payment successful.']);
    }

    public function update(string $id): never
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
            throw ApiException::conflict("Order status is {$order['status']}, cannot be changed to {$newStatus}");
        }

        if ($newStatus === Order::STATUS_CANCELLED) {
            // 取消订单要把商品放回在售
            if (!$isBuyer) {
                throw ApiException::forbidden('Only the buyer can cancel this order');
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
                    throw ApiException::conflict('Order status changed. Please refresh and try again.');
                }
                $pdo->prepare('UPDATE products SET is_sold = 0 WHERE id = ?')
                    ->execute([(int) $order['product_id']]);
            });
        } elseif ($newStatus === Order::STATUS_COMPLETED) {
            if (!$isSeller) {
                throw ApiException::forbidden('Only the seller can mark this order as completed');
            }
            \App\Database::transaction(static function ($pdo) use ($order) {
                $stmt = $pdo->prepare(
                    'UPDATE orders SET status = ? WHERE id = ? AND status = ?'
                );
                $stmt->execute([Order::STATUS_COMPLETED, (int) $order['id'], Order::STATUS_PENDING]);
                if ($stmt->rowCount() !== 1) {
                    throw ApiException::conflict('Order status changed. Please refresh and try again.');
                }
                User::addSpending((int) $order['buyer_id'], (string) $order['total_price']);
                User::addSellerSale((int) $order['seller_id']);
            });
        } else {
            throw ApiException::badRequest('Order status can only be changed to completed or cancelled');
        }

        Response::json(Order::view(Order::findDetailed((int) $id)));
    }
}
