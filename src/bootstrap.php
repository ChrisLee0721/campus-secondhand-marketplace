<?php
/**
 * 应用引导：PSR-4 自动加载 + 全局错误处理 + 会话启动。
 *
 * 这个项目刻意不引入 Composer —— 保持"纯原生 PHP"的形态，
 * 但用命名空间和目录结构把代码组织清楚。
 */
declare(strict_types=1);

// ---------- 配置 ----------
$GLOBALS['__config'] = require dirname(__DIR__) . '/config/config.php';

/** 读取配置，支持 'a.b.c' 点号路径。 */
function config(string $key, mixed $default = null): mixed
{
    $value = $GLOBALS['__config'];
    foreach (explode('.', $key) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }
    return $value;
}

// ---------- 自动加载 ----------
// App\Http\Response  →  src/Http/Response.php
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

// ---------- 错误处理 ----------
// 把所有 warning/notice 提升为异常，避免"静默出错 + 返回半个 JSON"。
// 这是上一版最大的问题：页面上看不出错，但数据已经错了。
$debug = (bool) config('debug');
error_reporting(E_ALL);
ini_set('display_errors', '0');   // 永远不要把错误直接打到输出流，会污染 JSON
ini_set('log_errors', '1');

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $e) use ($debug): void {
    $isApi = str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/');

    if ($isApi) {
        $status = $e instanceof App\Http\ApiException ? $e->status : 500;
        $body = [
            'error' => [
                'code'    => $e instanceof App\Http\ApiException ? $e->errorCode : 'internal_error',
                'message' => $e instanceof App\Http\ApiException
                    ? $e->getMessage()
                    : ($debug ? $e->getMessage() : '服务器内部错误'),
            ],
        ];
        if ($e instanceof App\Http\ApiException && $e->details !== []) {
            $body['error']['details'] = $e->details;
        }
        if ($debug && $status >= 500) {
            $body['error']['trace'] = explode("\n", $e->getTraceAsString());
        }

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo $debug ? (string) $e : 'Internal Server Error';
    }

    error_log(sprintf('[%s] %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
});

// ---------- 会话 ----------
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_name((string) config('session_name'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

// ---------- 时区 ----------
date_default_timezone_set('Asia/Hong_Kong');
