<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Http\ApiException;
use App\Http\Request;
use App\Http\Response;
use App\Models\ProfileComment;
use App\Models\User;
use App\Support\Validate;

/**
 * 个人主页留言板
 *   GET    /api/v1/users/{id}/comments
 *   POST   /api/v1/users/{id}/comments       body: {body}
 *   DELETE /api/v1/users/{id}/comments/{commentId}
 */
final class ProfileCommentController
{
    public function __construct(private Request $req) {}

    public function index(string $userId): never
    {
        $id = (int) $userId;
        if (!User::exists($id)) {
            throw ApiException::notFound('用户不存在');
        }

        $page = $this->req->queryInt('page', 1, 1);
        $perPage = $this->req->queryInt('per_page', 10, 1, 50);
        $result = ProfileComment::forUser($id, $page, $perPage);

        Response::collection(
            array_map([ProfileComment::class, 'view'], $result['items']),
            [
                'page'        => $result['page'],
                'per_page'    => $result['per_page'],
                'total'       => $result['total'],
                'total_pages' => $result['total_pages'],
            ]
        );
    }

    public function store(string $userId): never
    {
        Auth::requireAuth();

        $data = Validate::make($this->req->body())
            ->required('body')
            ->stringLength('body', 1, 1000)
            ->validate();

        $id = ProfileComment::add(
            (int) $userId,
            (int) Auth::id(),
            trim((string) $data['body'])
        );

        Response::created(['id' => $id]);
    }

    public function destroy(string $userId, string $commentId): never
    {
        Auth::requireAuth();

        $comment = ProfileComment::find((int) $commentId);
        if ($comment === null || (int) $comment['profile_user_id'] !== (int) $userId) {
            throw ApiException::notFound('留言不存在');
        }

        ProfileComment::hide((int) $commentId, (int) Auth::id(), Auth::isAdmin());
        Response::noContent();
    }
}
