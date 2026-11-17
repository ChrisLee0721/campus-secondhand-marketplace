<?php
declare(strict_types=1);

namespace App\Http;

/**
 * JSON response helpers.
 *
 * One envelope for everything:
 *   success  { "data": ..., "meta": {...} }
 *   failure  { "error": { "code": ..., "message": ... } }
 *
 * Collection endpoints always carry pagination in meta so the front end never has to guess.
 */
final class Response
{
    public static function json(mixed $data, int $status = 200, array $meta = []): never
    {
        // Only an empty meta is omitted, never empty data: [] is valid data
        // and dropping it would break the {"data": ...} envelope.
        $payload = ['data' => $data];
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }
        self::send($status, $payload);
    }

    /** Collection plus pagination. */
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

    /** Stream a binary image (the listing image endpoint) instead of JSON. */
    public static function image(string $body, string $mime, int $maxAge = 86400): never
    {
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . strlen($body));
            header('Cache-Control: public, max-age=' . $maxAge);
            header('X-Content-Type-Options: nosniff');
        }
        echo $body;
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
            // Let the front end read what it needs during development
            header('X-Content-Type-Options: nosniff');
        }
        echo json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        exit;
    }
}
