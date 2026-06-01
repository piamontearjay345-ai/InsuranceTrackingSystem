<?php
namespace App\Middleware;

use App\Helpers\Response;
use App\Services\AuthService;

/**
 * Requires authenticated session; optional role check.
 */
class AuthMiddleware
{
    public static function requireAuth(string|array|null $role = null): array
    {
        $auth = new AuthService();
        $user = $auth->currentUser();
        if (!$user) {
            Response::error('Unauthorized. Please sign in.', 401);
        }
        if ($role) {
            $allowed = is_array($role) ? $role : [$role];
            if (!in_array($user['role'] ?? '', $allowed, true)) {
                Response::error('Forbidden.', 403);
            }
        }
        return $user;
    }
}