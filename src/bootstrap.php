<?php
/**
 * Application bootstrap: PSR-4 autoloading, global error handling, session start.
 *
 * The project deliberately avoids Composer to stay plain PHP,
 * but keeps namespaces and a clear directory layout.
 */
declare(strict_types=1);

// ---------- Configuration ----------
$GLOBALS['__config'] = require dirname(__DIR__) . '/config/config.php';

/** Read configuration with 'a.b.c' dotted paths. */
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

// ---------- Autoloading ----------
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

// ---------- Error handling ----------
// Turn every warning and notice into an exception instead of returning half a JSON body.
// That was the worst flaw of the previous version: the page looked fine while the data was already wrong.
$debug = (bool) config('debug');
error_reporting(E_ALL);
ini_set('display_errors', '0');   // never write errors to the output stream, it would corrupt the JSON
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
                    : ($debug ? $e->getMessage() : 'Internal server error'),
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

// ---------- Session ----------
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

// ---------- Time zone ----------
date_default_timezone_set('Asia/Hong_Kong');
