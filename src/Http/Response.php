<?php
declare(strict_types=1);

namespace App\Http;

/**
 * JSON 响应工具。
 *
 * 统一信封：
 *   成功    { "data": ..., "meta": {...} }
 *   失败    { "error": { "code": ..., "message": ... } }
 *
 * 集合类接口（列表）一定带 meta，里面放分页信息，前端不用猜。
 */
final class Response
{
    public static function json(mixed $data, int $status = 200, array $meta = []): never
    {
        // 只省略空的 meta，不省略空的 data —— [] 也是合法数据，
        // 否则空列表会退化成裸 []，破坏 {"data": ...} 信封。
        $payload = ['data' => $data];
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }
        self::send($status, $payload);
    }

    /** 列表 + 分页。 */
    public static function collection(array $items, array $pagination, int $status = 200): never
    {
        self::send($status, ['data' => array_values($items), 'meta' => ['pagination' => $pagination]]);
    }

    public static function created(mixed $data, string $location = ''): never
    {
        if ($location !== '' && !headers_sent()) {
            header('Location: ' . $location, true, 201);
        }
        self::send(201, ['data' => $data]);
    }

    public static function noContent(): never
    {
        if (!headers_sent()) {
            http_response_code(204);
        }
        exit;
    }

    public static function error(int $status, string $code, string $message, array $details = []): never
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }
        self::send($status, ['error' => $error]);
    }

    private static function send(int $status, array $payload): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            // 开发期让前端 fetch 能读到该读的东西
            header('X-Content-Type-Options: nosniff');
        }
        echo json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        exit;
    }
}
