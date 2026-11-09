<?php
declare(strict_types=1);

namespace App\Support;

use App\Http\ApiException;

/**
 * 图片上传处理。
 *
 * 安全检查（上一版完全没有上传功能，这些都是新加的）：
 *  - 用 finfo 读真实 MIME，不信任客户端的 Content-Type 和扩展名
 *  - 白名单 MIME
 *  - 大小上限
 *  - 随机文件名，绝不使用用户提供的名字（防路径穿越 + 防 .php 落地）
 *  - 拒绝 getimagesize() 读不出来的"图片"
 */
final class Upload
{
    public static function storeImage(array $file): string
    {
        $cfg = config('uploads');

        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK) {
            throw ApiException::badRequest(self::errorMessage((int) $error));
        }

        $tmp = $file['tmp_name'] ?? '';
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw ApiException::badRequest('Invalid upload');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > (int) $cfg['max_size']) {
            throw ApiException::badRequest('Image size must not exceed ' . round($cfg['max_size'] / 1048576, 1) . ' MB');
        }

        // 真实类型：读文件头，不看扩展名
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp) ?: '';
        if (!in_array($mime, $cfg['mime'], true)) {
            throw ApiException::badRequest('Only JPG / PNG / GIF / WebP images are supported');
        }

        // 确认真的能被解析成图像（挡掉改名成 .jpg 的脚本）
        if (@getimagesize($tmp) === false) {
            throw ApiException::badRequest('File is not a valid image');
        }

        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            default      => throw ApiException::badRequest('Unsupported image format'),
        };

        $dir = self::uploadDir();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new ApiException(500, 'storage_error', 'Cannot create upload directory');
        }

        // 随机名：日期分目录 + 16 字节随机
        $sub = date('Y/m');
        $targetDir = $dir . '/' . $sub;
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            throw new ApiException(500, 'storage_error', 'Cannot create upload directory');
        }

        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        $target = $targetDir . '/' . $name;

        if (!move_uploaded_file($tmp, $target)) {
            throw new ApiException(500, 'storage_error', 'Failed to save uploaded file');
        }
        @chmod($target, 0644);

        // 存相对路径，前端拼成 /uploads/...
        return $cfg['dir'] . '/' . $sub . '/' . $name;
    }

    /** 允许直接给外部图片链接（seed 数据用的 unsplash）。只放行 http/https。 */
    public static function normalizeImageUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        // 已经是本项目内的相对路径（uploads/...）
        if (!preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) {
            if (str_contains($url, '..')) {
                throw ApiException::badRequest('Invalid image path');
            }
            return ltrim($url, '/');
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw ApiException::badRequest('Image URLs must use http or https');
        }
        if (mb_strlen($url) > 255) {
            throw ApiException::badRequest('Image URL is too long');
        }
        return $url;
    }

    /**
     * 删除本地上传文件。外链、越界路径、不存在的文件都静默跳过。
     */
    public static function deleteStored(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        $dir = rtrim((string) config('uploads.dir'), '/');
        if (!str_starts_with($path, $dir . '/')) {
            return;
        }

        $base = realpath(dirname(__DIR__, 2) . '/public/' . $dir);
        $target = realpath(dirname(__DIR__, 2) . '/public/' . $path);
        if ($base === false || $target === false || !is_file($target)) {
            return;
        }
        if (!str_starts_with($target, $base . DIRECTORY_SEPARATOR)) {
            return;
        }

        @unlink($target);
    }

    /**
     * 读取 uploads/... 下的图片，返回二进制和 MIME。
     * 路径越界、文件不存在、MIME 不在白名单都返回 null。
     */
    public static function readStoredImage(string $path): ?array
    {
        $cfg = config('uploads');
        $dir = rtrim((string) $cfg['dir'], '/');
        if ($path === '' || !str_starts_with($path, $dir . '/')) {
            return null;
        }

        $base = realpath(dirname(__DIR__, 2) . '/public/' . $dir);
        $target = realpath(dirname(__DIR__, 2) . '/public/' . $path);
        if ($base === false || $target === false || !is_file($target)) {
            return null;
        }
        if (!str_starts_with($target, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }

        $body = @file_get_contents($target);
        if ($body === false) {
            return null;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($target) ?: '';
        if (!in_array($mime, $cfg['mime'], true)) {
            return null;
        }

        return ['body' => $body, 'mime' => $mime];
    }

    private static function uploadDir(): string
    {
        return dirname(__DIR__, 2) . '/public/' . config('uploads.dir');
    }

    private static function errorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Image exceeds the size limit',
            UPLOAD_ERR_PARTIAL                        => 'The image was only partially uploaded. Please try again.',
            UPLOAD_ERR_NO_TMP_DIR                     => 'Server is missing a temp directory',
            UPLOAD_ERR_CANT_WRITE                     => 'Server write failed',
            UPLOAD_ERR_EXTENSION                      => 'Upload interrupted',
            default                                   => 'Upload failed',
        };
    }
}
