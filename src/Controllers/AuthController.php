<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Models\User;
use App\Support\Validate;

/**
 * 认证接口
 *   POST /api/v1/auth/register
 *   POST /api/v1/auth/login
 *   POST /api/v1/auth/logout
 *   GET  /api/v1/auth/me
 */
final class AuthController
{
    public function __construct(private Request $req) {}

    public function register(): never
    {
        $data = Validate::make($this->req->body())
            ->required('username', 'email', 'password', 'password_confirmation')
            ->username('username')
            ->stringLength('username', 2, 50)
            ->email('email')
            ->stringLength('password', 6, 72)
            ->confirmed('password', 'password_confirmation')
            ->rule('username', static fn ($v) => $v !== null && User::usernameTaken((string) $v)
                ? '该用户名已被占用' : null)
            ->rule('email', static fn ($v) => $v !== null && User::emailTaken((string) $v)
                ? '该邮箱已被注册' : null)
            ->validate();

        // ★ 关键修复：校验失败会抛 422 直接中断，不会像上一版那样
        //   "只 echo 一条错误提示但照样 INSERT"。
        $id = User::create([
            'username' => trim((string) $data['username']),
            'email'    => mb_strtolower(trim((string) $data['email'])),
            'password' => User::hashPassword((string) $data['password']),
        ]);

        $user = User::find($id);
        Auth::login($user);

        Response::created(User::publicView($user, true), '/api/v1/users/' . $id);
    }

    public function login(): never
    {
        $data = Validate::make($this->req->body())
            ->required('email', 'password')
            ->validate();

        $user = User::findByEmail(mb_strtolower(trim((string) $data['email'])));

        // 用户不存在和密码错误返回同一条消息，不泄露哪个邮箱已注册
        if ($user === null || !User::verifyPassword((string) $data['password'], $user['password'])) {
            throw new ApiException(401, 'invalid_credentials', '电邮或密码错误');
        }

        if ((int) ($user['is_active'] ?? 1) === 0) {
            throw new ApiException(403, 'account_disabled', '账号已被禁用，请联系管理员');
        }

        Auth::login($user);
        Response::json(User::publicView($user, true));
    }

    public function logout(): never
    {
        Auth::logout();
        Response::noContent();
    }

    public function me(): never
    {
        $user = Auth::publicUser();
        if ($user === null) {
            Response::json(null);
        }
        Response::json($user);
    }
}
