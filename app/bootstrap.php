<?php
/**
 * Application bootstrap - loads config and starts session.
 */

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

require_once APP_ROOT . '/app/Config/Env.php';
require_once APP_ROOT . '/app/Helpers/Response.php';
require_once APP_ROOT . '/app/Helpers/Security.php';
require_once APP_ROOT . '/app/Helpers/Validator.php';
require_once APP_ROOT . '/app/Services/SupabaseClient.php';
require_once APP_ROOT . '/app/Services/AuthService.php';
require_once APP_ROOT . '/app/Services/PasswordResetService.php';
require_once APP_ROOT . '/app/Services/BeneficiaryService.php';
require_once APP_ROOT . '/app/Services/EmailService.php';
require_once APP_ROOT . '/app/Services/LogService.php';
require_once APP_ROOT . '/app/Middleware/CsrfMiddleware.php';
require_once APP_ROOT . '/app/Middleware/AuthMiddleware.php';

App\Config\Env::load(APP_ROOT . '/.env');

// Session cookie path must match subdirectory (e.g. /InsuranceTrackingSystem/)
$cookiePath = '/';
$appUrl = App\Config\Env::get('APP_URL', '');
if ($appUrl) {
    $parsedPath = parse_url($appUrl, PHP_URL_PATH);
    if (is_string($parsedPath) && $parsedPath !== '' && $parsedPath !== '/') {
        $cookiePath = rtrim($parsedPath, '/') . '/';
    }
}

$sessionName = App\Config\Env::get('SESSION_NAME', 'SITS_SESSION');
$secure = App\Config\Env::getBool('COOKIE_SECURE', false);
$sameSite = App\Config\Env::get('COOKIE_SAMESITE', 'Lax');

session_name($sessionName);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => $cookiePath,
    'domain' => '',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => $sameSite,
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$timeout = App\Config\Env::getInt('SESSION_TIMEOUT', 900);
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
    session_unset();
    session_destroy();
    session_start();
}
$_SESSION['last_activity'] = time();
