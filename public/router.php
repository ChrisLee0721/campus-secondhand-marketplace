<?php
/**
 * Front controller for the PHP built-in server.
 *
 * Usage:
 *     php -S 0.0.0.0:8000 -t public public/router.php
 *
 * Behaviour:
 *   1. A real file under public/ is served by the built-in server itself.
 *   2. /api/v1/* goes to public/api/index.php.
 *   3. / serves the archive portal, /market serves the market single page app.
 *   4. Anything else is a 404.
 *
 * Production uses the Apache rewrite rules in the sibling .htaccess file.
 */
declare(strict_types=1);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// 1. Existing static file: hand it back to the built-in server.
if ($uri !== '/' && is_file(__DIR__ . $uri)) {
    return false;
}

// 2. API requests.
if (str_starts_with($uri, '/api/')) {
    require __DIR__ . '/api/index.php';
    return true;
}

// 3. The two HTML entry points.
$pages = [
    '/'        => 'index.html',
    '/index'   => 'index.html',
    '/market'  => 'market.html',
    '/market/' => 'market.html',
];

$path = '/' . trim($uri, '/');
if (isset($pages[$path])) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/' . $pages[$path]);
    return true;
}

// 4. Unknown path or missing static asset.
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "404 Not Found: {$uri}\n";
return true;
