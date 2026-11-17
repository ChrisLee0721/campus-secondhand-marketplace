<?php
/**
 * Global configuration.
 *
 * Database credential precedence:
 *   1. Environment variables (DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASSWORD)
 *   2. config/config.local.php (local development only; ignored by .gitignore)
 *   3. The demo defaults below
 *
 * This is a campus demo project: the default database password is deliberately 123456 and is documented in the README;
 * change it before going live, and never put real data behind this password.
 */
declare(strict_types=1);

$config = [
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'online_marketplace',
        'user'     => 'root',
        'password' => '123456',
        'charset'  => 'utf8mb4',
    ],

    // Session cookie name. Renaming it keeps dev and production sessions from colliding.
    'session_name' => 'leelapyuen_sid',

    // Default page size for product lists, plus a hard cap (so ?per_page=999999 cannot crush the query)
    'pagination' => [
        'default_per_page' => 12,
        'max_per_page'     => 100,
    ],

    // Storage location for uploaded images (relative to public/). URL images use the image_path field instead.
    'uploads' => [
        'dir'      => 'uploads',
        'max_size' => 5 * 1024 * 1024,
        'mime'     => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
    ],

    // eBay Browse API keyset, used by GET /api/v1/products/{id}/ebay-price.
    // The live values are never committed: put them in config.local.php, or set the
    // EBAY_CLIENT_ID / EBAY_CLIENT_SECRET environment variables. With neither set the
    // endpoint answers {"data": null} and the page simply hides the eBay line.
    'ebay_client_id'     => '',
    'ebay_client_secret' => '',

    // Debug mode: when true, the API returns exception details and stack traces to clients.
    // In production APP_DEBUG=0 is mandatory.
    'debug' => getenv('APP_DEBUG') !== '0',
];

// Local machine config (never committed to Git)
$localFile = __DIR__ . '/config.local.php';
if (is_file($localFile)) {
    $override = require $localFile;
    if (is_array($override)) {
        $config = array_replace_recursive($config, $override);
    }
}

// Environment variables take the highest precedence
$envMap = [
    'DB_HOST'     => 'host',
    'DB_PORT'     => 'port',
    'DB_NAME'     => 'name',
    'DB_USER'     => 'user',
    'DB_PASSWORD' => 'password',
];
foreach ($envMap as $env => $key) {
    $value = getenv($env);
    if ($value !== false) {
        $config['db'][$key] = $key === 'port' ? (int) $value : $value;
    }
}

// The eBay keyset has no environment-independent default; both routes are opt-in
$ebayEnvMap = [
    'EBAY_CLIENT_ID'     => 'ebay_client_id',
    'EBAY_CLIENT_SECRET' => 'ebay_client_secret',
];
foreach ($ebayEnvMap as $env => $key) {
    $value = getenv($env);
    if ($value !== false && $value !== '') {
        $config[$key] = $value;
    }
}

return $config;
