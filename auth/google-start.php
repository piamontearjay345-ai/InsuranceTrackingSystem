<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\AuthService;

$auth = new AuthService();
$url = $auth->startGoogleSignIn();
if ($url === '') {
    header('Location: ../login.html?error=' . rawurlencode('Google sign-in is not configured. Add GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET to .env.'));
    exit;
}

header('Location: ' . $url);
exit;
