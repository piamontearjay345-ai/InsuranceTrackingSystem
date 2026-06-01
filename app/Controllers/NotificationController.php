<?php
namespace App\Controllers;

use App\Helpers\Response;
use App\Middleware\AuthMiddleware;
use App\Services\AuthService;
use App\Services\SupabaseClient;

class NotificationController
{
    private SupabaseClient $db;
    private AuthService $auth;

    public function __construct()
    {
        $this->db = new SupabaseClient();
        $this->auth = new AuthService();
    }

    public function list(): void
    {
        $user = AuthMiddleware::requireAuth();
        $token = $this->auth->accessToken();
        $limit = min(50, max(5, (int) ($_GET['limit'] ?? 10)));

        $res = $this->db->from(
            'notifications',
            'GET',
            null,
            'select=*&user_id=eq.' . $user['id'] . '&order=created_at.desc&limit=' . $limit,
            false,
            $token
        );
        Response::success(['notifications' => $res['data'] ?? []]);
    }
}
