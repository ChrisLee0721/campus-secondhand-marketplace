<?php
declare(strict_types=1);

namespace App\Http;

/**
 * 极简路由：支持 {param} 动态段、按 HTTP 方法分发、分组中间件。
 *
 * 用法：
 *   $r->get('/products/{id}', [ProductController::class, 'show']);
 *   $r->group('/cart', [Auth::class, 'require'], function (Router $r) { ... });
 */
final class Router
{
    /** @var array<int, array{method:string, regex:string, keys:array<int,string>, handler:callable, middleware:array<int,callable>}> */
    private array $routes = [];

    /** @var array<int, callable> 当前分组叠加的中间件栈 */
    private array $middlewareStack = [];

    private string $prefix = '';

    public function __construct(private string $basePath = '') {}

    public function get(string $path, callable $handler): void    { $this->add('GET', $path, $handler); }
    public function post(string $path, callable $handler): void   { $this->add('POST', $path, $handler); }
    public function put(string $path, callable $handler): void    { $this->add('PUT', $path, $handler); }
    public function patch(string $path, callable $handler): void  { $this->add('PATCH', $path, $handler); }
    public function delete(string $path, callable $handler): void { $this->add('DELETE', $path, $handler); }

    /**
     * 给一组路由套中间件。
     * @param array<int, callable> $middleware
     */
    public function group(string $prefix, array $middleware, callable $definer): void
    {
        $savedPrefix = $this->prefix;
        $savedStack  = $this->middlewareStack;

        $this->prefix = $savedPrefix . $prefix;
        $this->middlewareStack = array_merge($savedStack, $middleware);

        $definer($this);

        $this->prefix = $savedPrefix;
        $this->middlewareStack = $savedStack;
    }

    private function add(string $method, string $path, callable $handler): void
    {
        $full = $this->prefix . $path;

        // /products/{id} → #^/products/(?P<id>[^/]+)$#
        $keys = [];
        $regex = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#',
            static function (array $m) use (&$keys): string {
                $keys[] = $m[1];
                return '(?P<' . $m[1] . '>[^/]+)';
            },
            $full
        );

        $this->routes[] = [
            'method'     => $method,
            'regex'      => '#^' . $regex . '$#u',
            'keys'       => $keys,
            'handler'    => $handler,
            'middleware' => $this->middlewareStack,
        ];
    }

    public function dispatch(string $method, string $uri): void
    {
        // 去掉查询串和 basePath
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        if ($this->basePath !== '' && str_starts_with($path, $this->basePath)) {
            $path = substr($path, strlen($this->basePath));
        }
        $path = '/' . trim($path, '/');
        if ($path === '/') {
            $path = '/';
        }

        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }
            $pathMatched = true;

            if ($route['method'] !== $method) {
                continue;
            }

            // 取命名参数
            $params = [];
            foreach ($route['keys'] as $key) {
                $params[$key] = $matches[$key];
            }

            foreach ($route['middleware'] as $mw) {
                $mw();
            }

            ($route['handler'])(...array_values($params));
            return;
        }

        // 路径存在但方法不对 → 405（比 404 更准确，也更好调试）
        if ($pathMatched) {
            throw ApiException::methodNotAllowed("{$method} 不支持 {$path}");
        }

        throw ApiException::notFound("找不到接口 {$path}");
    }
}
