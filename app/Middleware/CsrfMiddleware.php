<?php
namespace App\Middleware;

use App\Helpers\Response;
use App\Helpers\Security;

/**
 * Validates CSRF token on state-changing requests.
 */
class CsrfMiddleware
{
    public static function handle(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? null);
        if (!Security::validateCsrf($token)) {
            Response::error('Invalid CSRF token.', 403);
        }
    }
}
