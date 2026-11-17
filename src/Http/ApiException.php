<?php
declare(strict_types=1);

namespace App\Http;

/**
 * Exception carrying an HTTP status code.
 *
 * An API function may throw new ApiException(404, 'not_found', 'Product not found');
 * the global handler renders it as {"error":{"code":...,"message":...}}.
 */
class ApiException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        /** @var array<string,mixed> Extra information such as field level errors */
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /** 400 for invalid input. $details uses ['field' => 'reason']. */
    public static function badRequest(string $message, array $details = []): self
    {
        return new self(400, 'bad_request', $message, $details);
    }

    public static function validation(array $details): self
    {
        return new self(422, 'validation_failed', 'Validation failed', $details);
    }

    public static function unauthorized(string $message = 'Please log in'): self
    {
        return new self(401, 'unauthorized', $message);
    }

    public static function forbidden(string $message = 'You do not have permission to do this'): self
    {
        return new self(403, 'forbidden', $message);
    }

    public static function notFound(string $message = 'Resource not found'): self
    {
        return new self(404, 'not_found', $message);
    }

    public static function conflict(string $message): self
    {
        return new self(409, 'conflict', $message);
    }

    public static function methodNotAllowed(string $message = 'Method not allowed'): self
    {
        return new self(405, 'method_not_allowed', $message);
    }
}
