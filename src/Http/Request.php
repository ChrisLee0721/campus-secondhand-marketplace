<?php
declare(strict_types=1);

namespace App\Http;

/**
 * 请求封装：读 body、读 query、按类型取值。
 *
 * 所有取值方法都做类型收敛 —— 上一版把 $_GET['page'] 直接 (int) 转换、
 * 把 $_GET['status'] 直接塞进 SQL，这里统一走校验。
 */
final class Request
{
    private ?array $body = null;

    /** @var array<string,mixed> 路由参数由 Router 注入 */
    public array $routeParams = [];

    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public function path(): string
    {
        return parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    }

    /**
     * 解析请求体。支持 JSON 和表单两种（前端 fetch 发 JSON，简单表单发 form-urlencoded）。
     */
    public function body(): array
    {
        if ($this->body !== null) {
            return $this->body;
        }

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input') ?: '';
            if ($raw === '') {
                return $this->body = [];
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw ApiException::badRequest('请求体不是合法的 JSON');
            }
            return $this->body = $decoded;
        }

        // multipart/form-data 与 urlencoded 都落在 $_POST
        return $this->body = $_POST;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $body = $this->body();
        return $body[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    /** 取整数 query 参数，可选夹紧到区间。 */
    public function queryInt(string $key, int $default, ?int $min = null, ?int $max = null): int
    {
        $raw = $_GET[$key] ?? null;
        if ($raw === null || $raw === '') {
            return $default;
        }
        if (!is_numeric($raw)) {
            throw ApiException::badRequest("参数 {$key} 必须是数字");
        }
        $value = (int) $raw;
        if ($min !== null && $value < $min) {
            $value = $min;
        }
        if ($max !== null && $value > $max) {
            $value = $max;
        }
        return $value;
    }

    /** 取可空的浮点 query 参数（价格区间用）。空字符串视为 null。 */
    public function queryFloatOrNull(string $key): ?float
    {
        $raw = $_GET[$key] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_numeric($raw)) {
            throw ApiException::badRequest("参数 {$key} 必须是数字");
        }
        return (float) $raw;
    }

    public function queryTrimmed(string $key, string $default = ''): string
    {
        $raw = $_GET[$key] ?? null;
        return is_string($raw) ? trim($raw) : $default;
    }

    /** 上传文件。返回 null 表示没传。 */
    public function file(string $key): ?array
    {
        $f = $_FILES[$key] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return $f;
    }
}
