<?php
declare(strict_types=1);

namespace App\Support;

use App\Http\ApiException;

/**
 * Image upload handling.
 *
 * Safety checks (the previous version had no uploads at all, so all of this is new):
 *  - finfo reads the real MIME type; the client Content-Type and extension are not trusted
 *  - MIME whitelist
 *  - size limit
 *  - random file names, never the name supplied by the user (blocks path traversal and .php drops)
 *  - reject anything getimagesize() cannot read
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

        // Real type: read the file header, ignore the extension
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp) ?: '';
        if (!in_array($mime, $cfg['mime'], true)) {
            throw ApiException::badRequest('Only JPG / PNG / GIF / WebP images are supported');
        }

        // Make sure it really decodes as an image (stops a script renamed to .jpg)
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

        // Random name: date directory plus 16 random bytes
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

        // Store the relative path; the front end turns it into /uploads/...
        return $cfg['dir'] . '/' . $sub . '/' . $name;
    }

    /** External image URLs are allowed for seed data (Unsplash). Only http and https pass. */
    public static function normalizeImageUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        // Already a path inside this project (uploads/...)
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
     * Delete a local upload. External URLs, escaping paths and missing files are skipped silently.
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
     * Read an image under uploads/... and return its bytes and MIME type.
     * Escaping paths, missing files and non-whitelisted MIME types all return null.
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
