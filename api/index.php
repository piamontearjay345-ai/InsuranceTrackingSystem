<?php
/**
 * API router - all backend endpoints.
 * Works with: /api/index.php?route=/csrf (XAMPP) or rewritten /api/csrf
 */

declare(strict_types=1);

// Always JSON — catch fatals that happen before bootstrap
set_exception_handler(static function (Throwable $e): void {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
    }
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage(),
    ]);
    exit;
});

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_once dirname(__DIR__) . '/app/Controllers/AuthController.php';
require_once dirname(__DIR__) . '/app/Controllers/BeneficiaryController.php';
require_once dirname(__DIR__) . '/app/Controllers/AdminController.php';
require_once dirname(__DIR__) . '/app/Controllers/NotificationController.php';
require_once dirname(__DIR__) . '/app/Controllers/DebugController.php';

use App\Config\Env;
use App\Middleware\CsrfMiddleware;
use App\Helpers\Response;
use App\Controllers\AuthController;
use App\Controllers\BeneficiaryController;
use App\Controllers\AdminController;
use App\Controllers\NotificationController;

/**
 * Resolve API path from ?route=, PATH_INFO, or REQUEST_URI.
 */
function resolveApiPath(): string
{
    if (!empty($_GET['route'])) {
        $route = (string) $_GET['route'];
        $parsed = parse_url($route);
        $path = '/' . trim((string) ($parsed['path'] ?? ''), '/');
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $routeParams);
            foreach ($routeParams as $key => $value) {
                $_GET[$key] = $value;
            }
        }
        return $path === '/' ? '' : $path;
    }

    if (!empty($_SERVER['PATH_INFO'])) {
        $path = '/' . trim((string) $_SERVER['PATH_INFO'], '/');
        return $path === '/' ? '' : $path;
    }

    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

    if (preg_match('#/api/index\.php(/.*)$#i', $uri, $m)) {
        $path = '/' . trim($m[1], '/');
        return $path === '/' ? '' : $path;
    }

    if (preg_match('#/api(/.*)$#i', $uri, $m)) {
        $suffix = trim($m[1], '/');
        if ($suffix === '' || strcasecmp($suffix, 'index.php') === 0) {
            return '';
        }
        $path = '/' . $suffix;
        return $path === '/' ? '' : $path;
    }

    return '';
}

$path = resolveApiPath();
$method = $_SERVER['REQUEST_METHOD'];

// Supabase must be configured
$supabaseUrl = Env::get('SUPABASE_URL', '');
$supabaseKey = Env::get('SUPABASE_ANON_KEY', '');
if ($path !== '' && $path !== '/csrf' && (!$supabaseUrl || !$supabaseKey || str_contains($supabaseUrl, 'your-project'))) {
    Response::error(
        'Supabase is not configured. Copy .env.example to .env and set SUPABASE_URL, SUPABASE_ANON_KEY, and SUPABASE_SERVICE_ROLE_KEY.',
        503
    );
}

$csrfExempt = in_array($path, [
    '/auth/register',
    '/auth/login',
    '/auth/forgot-password',
    '/auth/verify-reset-code',
    '/auth/reset-password',
    '/auth/oauth/complete',
    '/csrf',
], true);

if (!$csrfExempt && !in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
    CsrfMiddleware::handle();
}

try {
    $auth = new AuthController();
    $beneficiary = new BeneficiaryController();
    $admin = new AdminController();
    $notifications = new NotificationController();

    match (true) {
        $path === '/csrf' && $method === 'GET' => $auth->csrf(),

        $path === '/auth/register' && $method === 'POST' => $auth->register(),
        $path === '/auth/login' && $method === 'POST' => $auth->login(),
        $path === '/auth/forgot-password' && $method === 'POST' => $auth->forgotPassword(),
        $path === '/auth/verify-reset-code' && $method === 'POST' => $auth->verifyResetCode(),
        $path === '/auth/reset-password' && $method === 'POST' => $auth->resetPassword(),
        $path === '/auth/oauth/complete' && $method === 'POST' => $auth->oauthComplete(),
        $path === '/auth/google/url' && $method === 'GET' => $auth->googleUrl(),
        $path === '/auth/logout' && $method === 'POST' => $auth->logout(),
        $path === '/auth/me' && $method === 'GET' => $auth->me(),

        $path === '/beneficiary' && $method === 'GET' => $beneficiary->show(),
        $path === '/beneficiary' && in_array($method, ['POST', 'PUT', 'PATCH'], true) => $beneficiary->save(),

        $path === '/notifications' && $method === 'GET' => $notifications->list(),

        $path === '/admin/stats' && $method === 'GET' => $admin->stats(),
        $path === '/admin/students' && $method === 'GET' => $admin->students(),
        $path === '/admin/beneficiary-update-requests' && $method === 'GET' => $admin->beneficiaryUpdateRequests(),
        $path === '/admin/beneficiary-update-request' && $method === 'POST' => $admin->sendBeneficiaryUpdateRequest(),
        $path === '/admin/beneficiary-update-request/all' && $method === 'POST' => $admin->sendAllBeneficiaryUpdateRequests(),
        $path === '/admin/notifications' && $method === 'GET' => $admin->notifications(),
        $path === '/admin/failed-notifications' && $method === 'GET' => $admin->failedNotifications(),
        $path === '/admin/retry-notification' && $method === 'POST' => $admin->retryNotification(),
        $path === '/admin/login-history' && $method === 'GET' => $admin->loginHistory(),
        $path === '/admin/activity-logs' && $method === 'GET' => $admin->activityLogs(),
        $path === '/superadmin/users' && $method === 'GET' => $admin->users(),
        $path === '/debug/session' && $method === 'GET' => (new App\Controllers\DebugController())->session(),
        $path === '/superadmin/user' && $method === 'POST' => $admin->createUser(),
        $path === '/superadmin/user' && in_array($method, ['PUT', 'PATCH'], true) => $admin->updateUser(),
        $path === '/superadmin/user/reset' && $method === 'POST' => $admin->resetUserPassword(),

        $path === '' && $method === 'GET' => Response::success([
            'status' => 'ok',
            'hint' => 'Use ?route=/csrf or /api/index.php/csrf',
        ]),

        default => Response::error('Endpoint not found: ' . ($path ?: '(empty)'), 404),
    };
} catch (Throwable $e) {
    $debug = Env::getBool('APP_DEBUG', false);
    Response::error($debug ? $e->getMessage() : 'Internal server error.', 500);
}
