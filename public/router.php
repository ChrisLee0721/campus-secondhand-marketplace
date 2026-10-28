<?php
/**
 * PHP 内置服务器的前端控制器。
 *
 * 用法：
 *     cd public && php -S 0.0.0.0:8000 router.php
 * 或   php -S 0.0.0.0:8000 -t public public/router.php
 *
 * 作用：
 *   1. 静态文件（css/js/图片）直接交给内置服务器返回
 *   2. /api/v1/* 交给 API 控制器
 *   3. 其余路径返回 index.html，让前端的 hash 路由接手
 *
 * 注意：这个文件只用于开发。生产环境用 Apache/Nginx 的 rewrite 规则
 *      （见同目录的 .htaccess）。
 */
declare(strict_types=1);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// 1. 真实存在的静态文件 → 直接返回（返回 false 让内置服务器处理）
$file = __DIR__ . $uri;
if ($uri !== '/' && is_file($file)) {
    return false;
}

// 2. API
if (str_starts_with($uri, '/api/')) {
    require __DIR__ . '/api/index.php';
    return true;
}

// 3. 其余 → 前端壳
if ($uri === '/' || !str_contains(basename($uri), '.')) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/index.html');
    return true;
}

// 4. 想访问一个不存在的静态文件
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "404 Not Found: {$uri}\n";
return true;
