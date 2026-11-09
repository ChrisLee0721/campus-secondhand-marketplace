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
            throw ApiException::badRequest('status must be new / used / damaged');
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
            throw ApiException::badRequest('min_price cannot be greater than max_price');
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
            throw ApiException::notFound('Product not found');
        }

        $view = Product::view($product);
        $view['seller_rating'] = Review::sellerRating((int) $product['seller_id']);
        $view['seller_level'] = (int) ($product['seller_level'] ?? 1);

        Response::json($view);
    }

    public function image(string $id): never
    {
        $product = Product::find((int) $id);
        if ($product === null) {
            throw ApiException::notFound('Product not found');
        }

        if (!empty($product['image_blob'])) {
            $mime = (string) ($product['image_mime'] ?? '');
            $cfg = config('uploads');
            if (!in_array($mime, $cfg['mime'], true)) {
                $mime = 'application/octet-stream';
            }
            Response::image((string) $product['image_blob'], $mime);
        }

        $path = (string) ($product['image_path'] ?? '');
        if ($path === '') {
            throw ApiException::notFound('Product has no image');
        }

        // 旧数据允许外链，直接跳转
        if (preg_match('#^https?://#i', $path)) {
            header('Location: ' . $path, true, 302);
            exit;
        }

        $stored = Upload::readStoredImage($path);
        if ($stored === null) {
            throw ApiException::notFound('Image not found');
        }

        Response::image($stored['body'], $stored['mime']);
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
                return \App\Models\Category::exists((int) $v) ? null : 'Category not found';
            })
            ->stringLength('description', 0, 5000)
            ->validate();

        $imagePath = null;
        $imageBlob = null;
        $imageMime = null;
        $file = $this->req->file('image');
        if ($file !== null) {
            $imagePath = Upload::storeImage($file);
            $stored = Upload::readStoredImage($imagePath);
            if ($stored !== null) {
                $imageBlob = $stored['body'];
                $imageMime = $stored['mime'];
            }
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
            'image_blob'  => $imageBlob,
            'image_mime'  => $imageMime,
            'seller_id'   => (int) Auth::id(),
        ]);

        Response::created(Product::view(Product::findDetailed($id)), '/api/v1/products/' . $id);
    }

    public function update(string $id): never
    {
        Auth::requireAuth();

        $product = Product::find((int) $id);
        if ($product === null) {
            throw ApiException::notFound('Product not found');
        }
        if ((int) $product['seller_id'] !== Auth::id()) {
            throw ApiException::forbidden('You can only edit your own products');
        }
        if ((int) $product['is_sold'] === 1) {
            throw ApiException::conflict('Sold products cannot be edited');
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
                return \App\Models\Category::exists((int) $v) ? null : 'Category not found';
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
            $stored = Upload::readStoredImage($updates['image_path']);
            if ($stored !== null) {
                $updates['image_blob'] = $stored['body'];
                $updates['image_mime'] = $stored['mime'];
            }
        }

        if ($updates === []) {
            throw ApiException::badRequest('No fields to update');
        }

        Product::update((int) $id, $updates);
        Response::json(Product::view(Product::findDetailed((int) $id)));
    }

    public function destroy(string $id): never
    {
        Auth::requireAuth();

        $product = Product::find((int) $id);
        if ($product === null) {
            throw ApiException::notFound('Product not found');
        }
        if ((int) $product['seller_id'] !== Auth::id()) {
            throw ApiException::forbidden('You can only delete your own products');
        }

        // 已经产生订单的商品不能删（外键会拦住，但先给出可读的错误）
        $stmt = \App\Database::conn()->prepare('SELECT COUNT(*) FROM orders WHERE product_id = ?');
        $stmt->execute([(int) $id]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw ApiException::conflict('This product has order records and cannot be deleted');
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
