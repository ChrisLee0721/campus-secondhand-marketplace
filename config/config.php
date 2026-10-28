<?php
/**
 * 全局配置。
 *
 * 数据库凭据优先级：
 *   1. 环境变量（DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASSWORD）
 *   2. config/config.local.php（本机开发用，已被 .gitignore 忽略）
 *   3. 下面的演示默认值
 *
 * 这是校园演示项目，默认数据库密码故意设为 123456，并直接写在 README 里；
 * 上线前必须改掉，不要把真实数据挂在这个密码下。
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

    // 会话 cookie 名。改这个名字可以让开发机和线上互不干扰。
    'session_name' => 'leelapyuen_sid',

    // 商品列表默认每页条数，以及硬上限（防止 ?per_page=999999 拖垮查询）
    'pagination' => [
        'default_per_page' => 12,
        'max_per_page'     => 100,
    ],

    // 上传图片的存储位置（相对 public/）。用 URL 图片时走 image_path 字段。
    'uploads' => [
        'dir'      => 'uploads',
        'max_size' => 5 * 1024 * 1024,
        'mime'     => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
    ],

    // 开发模式：为 true 时接口会把异常详情和堆栈返回给客户端。
    // 线上必须设 APP_DEBUG=0。
    'debug' => getenv('APP_DEBUG') !== '0',
];

// 本机配置（不提交到 Git）
$localFile = __DIR__ . '/config.local.php';
if (is_file($localFile)) {
    $override = require $localFile;
    if (is_array($override)) {
        $config = array_replace_recursive($config, $override);
    }
}

// 环境变量优先级最高
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

return $config;
