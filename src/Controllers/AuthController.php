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
                ? 'Username already taken' : null)
            ->rule('email', static fn ($v) => $v !== null && User::emailTaken((string) $v)
                ? 'Email already registered' : null)
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
            throw new ApiException(401, 'invalid_credentials', 'Incorrect email or password');
        }

        if ((int) ($user['is_active'] ?? 1) === 0) {
            throw new ApiException(403, 'account_disabled', 'Account disabled. Please contact an admin.');
        }

        Auth::login($user);
        Response::json(User::publicView($user, true));
    }

    public function forgotPassword(): never
    {
        $data = Validate::make($this->req->body())
            ->required('email')
            ->email('email')
            ->validate();

        $email = mb_strtolower(trim((string) $data['email']));
        $user = User::findByEmail($email);
        if ($user === null) {
            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['message' => 'If the email exists, a code has been sent.']);
            exit;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        User::setResetCode((int) $user['id'], $code, time() + 900);

        Response::json(['code' => $code]);
    }

    public function resetPassword(): never
    {
        $data = Validate::make($this->req->body())
            ->required('email', 'code', 'new_password')
            ->stringLength('code', 6, 6)
            ->stringLength('new_password', 6, 72)
            ->validate();

        $email = mb_strtolower(trim((string) $data['email']));
        $user = User::findByEmail($email);
        if ($user === null
            || empty($user['reset_code'])
            || !hash_equals((string) $user['reset_code'], (string) $data['code'])
            || (int) $user['reset_expires_at'] < time()) {
            throw ApiException::validation(['code' => 'Invalid or expired code']);
        }

        User::resetPassword((int) $user['id'], User::hashPassword((string) $data['new_password']));

        Response::json(['message' => 'Password reset successfully.']);
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
