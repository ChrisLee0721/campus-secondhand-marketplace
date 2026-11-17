<?php
declare(strict_types=1);

namespace App\Http;

/**
 * Request wrapper: body, query string and typed accessors.
 *
 * Every accessor narrows the type. The previous version cast $_GET['page'] with (int)
 * and put $_GET['status'] straight into SQL; here everything goes through validation.
 */
final class Request
{
    private ?array $body = null;

    /** @var array<string,mixed> Route parameters, filled in by the Router */
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
     * Parse the request body. JSON and form posts are both supported (the front end sends JSON).
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
                throw ApiException::badRequest('Request body is not valid JSON');
            }
            return $this->body = $decoded;
        }

        // multipart/form-data and urlencoded bodies both arrive in $_POST
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

    /** Integer query parameter, optionally clamped to a range. */
    public function queryInt(string $key, int $default, ?int $min = null, ?int $max = null): int
    {
        $raw = $_GET[$key] ?? null;
        if ($raw === null || $raw === '') {
            return $default;
        }
        if (!is_numeric($raw)) {
            throw ApiException::badRequest("Parameter {$key} must be a number");
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

    /** Nullable float query parameter used for price ranges. An empty string means null. */
    public function queryFloatOrNull(string $key): ?float
    {
        $raw = $_GET[$key] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_numeric($raw)) {
            throw ApiException::badRequest("Parameter {$key} must be a number");
        }
        return (float) $raw;
    }

    public function queryTrimmed(string $key, string $default = ''): string
    {
        $raw = $_GET[$key] ?? null;
        return is_string($raw) ? trim($raw) : $default;
    }

    /** Uploaded file, or null when nothing was sent. */
    public function file(string $key): ?array
    {
        $f = $_FILES[$key] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return $f;
    }
}
