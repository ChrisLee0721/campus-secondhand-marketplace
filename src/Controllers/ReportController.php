<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Models\Product;
use App\Models\Report;
use App\Support\Validate;

final class ReportController
{
    public function __construct(private Request $req) {}

    public function store(string $productId): never
    {
        Auth::requireAuth();

        $product = Product::find((int) $productId);
        if ($product === null) {
            throw ApiException::notFound('Product not found');
        }
        if ((int) $product['seller_id'] === (int) Auth::id()) {
            throw ApiException::conflict('You cannot report your own product');
        }

        $data = Validate::make($this->req->body())
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
}
