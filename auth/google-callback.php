<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\AuthService;

$loginUrl = '../login.html';

if (!empty($_GET['error'])) {
    $msg = $_GET['error_description'] ?? $_GET['error'] ?? 'Google sign-in was cancelled.';
    header('Location: ' . $loginUrl . '?error=' . rawurlencode((string) $msg));
    exit;
}

$code = trim($_GET['code'] ?? '');
$state = trim($_GET['state'] ?? '');

if ($code === '') {
    header('Location: ' . $loginUrl . '?error=' . rawurlencode('Google sign-in did not return an authorization code.'));
    exit;
}

$result = (new AuthService())->completeGoogleOAuth($code, $state);
if (!$result['success']) {
    header('Location: ' . $loginUrl . '?error=' . rawurlencode($result['message']));
    exit;
}

$redirect = $result['data']['redirect'] ?? 'student/dashboard.html';
header('Location: ../' . ltrim($redirect, '/'));
exit;
