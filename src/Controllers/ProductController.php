<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Models\Category;
use App\Models\Product;
use App\Models\Review;
use App\Support\Upload;
use App\Support\Validate;

/**
 * 商品接口
 *   GET    /api/v1/products            列表（分类/关键词/价格/状态/卖家/分页）
 *   POST   /api/v1/products            发布（登录）
 *   GET    /api/v1/products/{id}       详情
 *   PATCH  /api/v1/products/{id}       编辑（仅卖家本人）
 *   DELETE /api/v1/products/{id}       删除（仅卖家本人）
 */
final class ProductController
{
    public function __construct(private Request $req) {}

    public function index(): never
    {
        $page    = $this->req->queryInt('page', 1, 1);
        $perPage = $this->req->queryInt('per_page', (int) config('pagination.default_per_page'), 1,
                                        (int) config('pagination.max_per_page'));

        $status = $this->req->queryTrimmed('status');
        if ($status !== '' && !in_array($status, Product::STATUSES, true)) {
            throw ApiException::badRequest('status 只能是 new / used / damaged');
        }

        $filters = [
            'category_id'  => $this->req->queryInt('category_id', 0, 0),
            'seller_id'    => $this->req->queryInt('seller_id', 0, 0),
            'search'       => $this->req->queryTrimmed('search'),
            'min_price'    => $this->req->queryFloatOrNull('min_price'),
            'max_price'    => $this->req->queryFloatOrNull('max_price'),
            'status'       => $status,
            'include_sold' => $this->req->queryTrimmed('include_sold') === '1',
        ];

        if ($filters['min_price'] !== null && $filters['max_price'] !== null
            && $filters['min_price'] > $filters['max_price']) {
            throw ApiException::badRequest('min_price 不能大于 max_price');
        }

        $result = Product::paginate($filters, $page, $perPage);

        Response::collection(
            array_map([Product::class, 'view'], $result['items']),
            [
                'page'        => $result['page'],
                'per_page'    => $result['per_page'],
                'total'       => $result['total'],
                'total_pages' => $result['total_pages'],
            ]
        );
    }

    public function show(string $id): never
    {
        $product = Product::findDetailed((int) $id);
        if ($product === null) {
            throw ApiException::notFound('商品不存在');
        }

        $view = Product::view($product);
        $view['seller_rating'] = Review::sellerRating((int) $product['seller_id']);

        Response::json($view);
    }

    public function store(): never
    {
        Auth::requireAuth();

        $body = $this->req->body();

        $data = Validate::make($body)
            ->required('title', 'price', 'status')
            ->stringLength('title', 2, 100)
            ->numeric('price', 0.01, 99999999.99)
            ->in('status', Product::STATUSES)
            ->integer('category_id', 1, PHP_INT_MAX)
            ->rule('category_id', static function ($v) {
                if ($v === null || $v === '') {
                    return null;
                }
                return \App\Models\Category::exists((int) $v) ? null : '分类不存在';
            })
            ->stringLength('description', 0, 5000)
            ->validate();

        $imagePath = null;
        $file = $this->req->file('image');
        if ($file !== null) {
            $imagePath = Upload::storeImage($file);
        } elseif (!empty($body['image_path']) && is_string($body['image_path'])) {
            // 允许直接给外链（seed 数据里的 unsplash 图就是这种）
            $imagePath = Upload::normalizeImageUrl($body['image_path']);
        }

        $cat = $data['category_id'] ?? null;
        $categoryId = ($cat === null || (is_string($cat) && trim($cat) === ''))
            ? null
            : (int) $cat;

        $id = Product::create([
            'title'       => trim((string) $data['title']),
            'description' => isset($data['description']) ? trim((string) $data['description']) : null,
            'price'       => (string) $data['price'],
            'category_id' => $categoryId,
            'status'      => (string) $data['status'],
            'image_path'  => $imagePath,
            'seller_id'   => (int) Auth::id(),
        ]);

        Response::created(Product::view(Product::findDetailed($id)), '/api/v1/products/' . $id);
    }

    public function update(string $id): never
    {
        Auth::requireAuth();

        $product = Product::find((int) $id);
        if ($product === null) {
            throw ApiException::notFound('商品不存在');
        }
        if ((int) $product['seller_id'] !== Auth::id()) {
            throw ApiException::forbidden('只能修改自己发布的商品');
        }
        if ((int) $product['is_sold'] === 1) {
            throw ApiException::conflict('已售出的商品不能再修改');
        }

        $body = $this->req->body();

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
                return \App\Models\Category::exists((int) $v) ? null : '分类不存在';
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
            // null / 空字符串表示"清空分类"，其余按整数处理
            $updates['category_id'] = ($cat === null || (is_string($cat) && trim($cat) === ''))
                ? null
                : (int) $cat;
        }

        $file = $this->req->file('image');
        if ($file !== null) {
            $updates['image_path'] = Upload::storeImage($file);
        }

        if ($updates === []) {
            throw ApiException::badRequest('没有提供任何要修改的字段');
        }

        Product::update((int) $id, $updates);
        Response::json(Product::view(Product::findDetailed((int) $id)));
    }

    public function destroy(string $id): never
    {
        Auth::requireAuth();

        $product = Product::find((int) $id);
        if ($product === null) {
            throw ApiException::notFound('商品不存在');
        }
        if ((int) $product['seller_id'] !== Auth::id()) {
            throw ApiException::forbidden('只能删除自己发布的商品');
        }

        // 已经产生订单的商品不能删（外键会拦住，但先给出可读的错误）
        $stmt = \App\Database::conn()->prepare('SELECT COUNT(*) FROM orders WHERE product_id = ?');
        $stmt->execute([(int) $id]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw ApiException::conflict('该商品已有订单记录，不能删除');
        }

        Product::delete((int) $id);
        Response::noContent();
    }

    /** 我发布的商品 */
    public function mine(): never
    {
        Auth::requireAuth();

        $page    = $this->req->queryInt('page', 1, 1);
        $perPage = $this->req->queryInt('per_page', 12, 1, (int) config('pagination.max_per_page'));

        $result = Product::paginate(
            ['seller_id' => Auth::id(), 'include_sold' => true],
            $page,
            $perPage
        );

        Response::collection(
            array_map([Product::class, 'view'], $result['items']),
            [
                'page'        => $result['page'],
                'per_page'    => $result['per_page'],
                'total'       => $result['total'],
                'total_pages' => $result['total_pages'],
            ]
        );
    }

    /** 分类列表（放在商品控制器下，路径 /products/categories 不太好，实际挂 /categories） */
    public function categories(): never
    {
        Response::json(array_map([Category::class, 'view'], Category::allWithCounts()));
    }
}
