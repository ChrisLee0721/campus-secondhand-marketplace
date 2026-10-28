<?php
declare(strict_types=1);

namespace App\Http;

/**
 * 带 HTTP 状态码的异常。
 *
 * 控制器里直接 throw new ApiException(404, 'not_found', '商品不存在')，
 * 全局异常处理器会把它渲染成 {"error":{"code":...,"message":...}}。
 */
class ApiException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        /** @var array<string,mixed> 字段级错误等附加信息 */
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /** 400 参数不合法。$details 用 ['字段名' => '说明'] 的形式。 */
    public static function badRequest(string $message, array $details = []): self
    {
        return new self(400, 'bad_request', $message, $details);
    }

    public static function validation(array $details): self
    {
        return new self(422, 'validation_failed', '输入数据未通过校验', $details);
    }

    public static function unauthorized(string $message = '请先登录'): self
    {
        return new self(401, 'unauthorized', $message);
    }

    public static function forbidden(string $message = '没有权限执行此操作'): self
    {
        return new self(403, 'forbidden', $message);
    }

    public static function notFound(string $message = '资源不存在'): self
    {
        return new self(404, 'not_found', $message);
    }

    public static function conflict(string $message): self
    {
        return new self(409, 'conflict', $message);
    }

    public static function methodNotAllowed(string $message = '请求方法不被允许'): self
    {
        return new self(405, 'method_not_allowed', $message);
    }
}
