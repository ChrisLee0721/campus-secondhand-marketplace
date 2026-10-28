<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Models\User;
use App\Support\Upload;
use App\Support\Validate;

/**
 * 当前登录用户的账号设置
 *   PATCH /api/v1/account           改用户名 / 邮箱（改邮箱需当前密码）
 *   POST  /api/v1/account/password  改密码
 *   POST  /api/v1/account/avatar    上传头像（multipart，字段名 avatar）
 */
final class AccountController
{
    public function __construct(private Request $req) {}

    public function update(): never
    {
        Auth::requireAuth();
        $user = Auth::user();
        if ($user === null) {
            throw ApiException::unauthorized();
        }

        $body = $this->req->body();
        $hasUsername = array_key_exists('username', $body);
        $hasEmail = array_key_exists('email', $body);
        $hasBio = array_key_exists('bio', $body);

        if (!$hasUsername && !$hasEmail && !$hasBio) {
            throw ApiException::badRequest('没有提供要修改的字段');
        }

        $validator = Validate::make($body);

        if ($hasUsername) {
            $validator->notEmpty('username')
                ->username('username')
                ->stringLength('username', 2, 50)
                ->rule('username', static fn ($v) => is_string($v)
                    && User::usernameTaken($v, (int) $user['id'])
                        ? '该用户名已被占用'
                        : null);
        }

        if ($hasEmail) {
            $validator->notEmpty('email')
                ->email('email')
                ->rule('email', static fn ($v) => is_string($v)
                    && User::emailTaken(strtolower($v), (int) $user['id'])
                        ? '该邮箱已被注册'
                        : null);
        }

        if ($hasBio) {
            $validator->stringLength('bio', 0, 255);
        }

        $validator->validate();

        $updates = [];
        if ($hasUsername) {
            $updates['username'] = trim((string) $body['username']);
        }

        $newEmail = null;
        if ($hasEmail) {
            $newEmail = mb_strtolower(trim((string) $body['email']));
            $updates['email'] = $newEmail;
        }

        if ($hasBio) {
            $bio = trim((string) $body['bio']);
            $updates['bio'] = $bio === '' ? null : $bio;
        }

        // 改邮箱属于敏感操作，要求验证当前密码。
        if ($newEmail !== null && $newEmail !== mb_strtolower((string) $user['email'])) {
            $current = $body['current_password'] ?? '';
            if (!is_string($current) || $current === ''
                || !User::verifyPassword($current, (string) $user['password'])) {
                throw ApiException::validation(['current_password' => '当前密码不正确']);
            }
        }

        User::update((int) $user['id'], $updates);
        Auth::refresh();

        Response::json(User::publicView(User::find((int) $user['id']), true));
    }

    public function password(): never
    {
        Auth::requireAuth();
        $user = Auth::user();
        if ($user === null) {
            throw ApiException::unauthorized();
        }

        $data = Validate::make($this->req->body())
            ->required('current_password', 'password', 'password_confirmation')
            ->stringLength('current_password', 1, 72)
            ->stringLength('password', 6, 72)
            ->confirmed('password', 'password_confirmation')
            ->validate();

        if (!User::verifyPassword((string) $data['current_password'], (string) $user['password'])) {
            throw ApiException::validation(['current_password' => '当前密码不正确']);
        }
        if (hash_equals((string) $data['current_password'], (string) $data['password'])) {
            throw ApiException::validation(['password' => '新密码不能和当前密码相同']);
        }

        User::update((int) $user['id'], [
            'password' => User::hashPassword((string) $data['password']),
        ]);
        Auth::refresh();

        // 改密码后换 session id，旧 cookie 立即失效
        session_regenerate_id(true);

        Response::noContent();
    }

    public function avatar(): never
    {
        Auth::requireAuth();
        $user = Auth::user();
        if ($user === null) {
            throw ApiException::unauthorized();
        }

        $file = $this->req->file('avatar');
        if ($file === null) {
            throw ApiException::badRequest('请选择头像图片');
        }

        $path = Upload::storeImage($file);
        $old = $user['avatar_path'] ?? null;

        User::update((int) $user['id'], ['avatar_path' => $path]);
        if ($old !== $path) {
            Upload::deleteStored($old);
        }
        Auth::refresh();

        Response::json(User::publicView(User::find((int) $user['id']), true));
    }
}
