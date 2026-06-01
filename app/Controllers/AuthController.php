<?php
namespace App\Controllers;

use App\Helpers\Response;
use App\Helpers\Security;
use App\Helpers\Validator;
use App\Services\AuthService;

class AuthController
{
    private AuthService $auth;

    public function __construct()
    {
        $this->auth = new AuthService();
    }

    public function csrf(): void
    {
        Response::success(['token' => Security::csrfToken()]);
    }

    public function register(): void
    {
        $body = Security::jsonBody();
        $errors = Validator::registration($body);
        if ($errors) {
            Response::error('Validation failed.', 422, $errors);
        }
        $result = $this->auth->register($body);
        if (!$result['success']) {
            Response::error($result['message'], 400);
        }
        Response::success(null, $result['message'], 201);
    }

    public function login(): void
    {
        $body = Security::jsonBody();
        $identifier = trim($body['identifier'] ?? $body['email'] ?? $body['username'] ?? '');
        $password = $body['password'] ?? '';
        if ($identifier === '' || $password === '') {
            Response::error('Email/username and password are required.');
        }
        $result = $this->auth->login($identifier, $password);
        if (!$result['success']) {
            Response::error($result['message'], 401);
        }
        Response::success($result['data'], $result['message']);
    }

    public function logout(): void
    {
        $this->auth->logout();
        Response::success(null, 'Logged out.');
    }

    public function me(): void
    {
        $user = $this->auth->currentUser();
        if (!$user) {
            Response::error('Not authenticated.', 401);
        }
        Response::success(['user' => $user]);
    }

    public function forgotPassword(): void
    {
        $body = Security::jsonBody();
        $email = trim($body['email'] ?? '');
        if ($email === '') {
            Response::error('Email address is required.');
        }
        $result = $this->auth->requestPasswordReset($email);
        if (!$result['success']) {
            Response::error($result['message'], 400);
        }
        Response::success($result['data'] ?? null, $result['message']);
    }

    public function verifyResetCode(): void
    {
        $body = Security::jsonBody();
        $email = trim($body['email'] ?? '');
        $code = trim($body['code'] ?? '');
        if ($email === '' || $code === '') {
            Response::error('Email and verification code are required.');
        }
        $result = $this->auth->verifyPasswordResetCode($email, $code);
        if (!$result['success']) {
            Response::error($result['message'], 400);
        }
        Response::success($result['data'] ?? null, $result['message']);
    }

    public function resetPassword(): void
    {
        $body = Security::jsonBody();
        $email = trim($body['email'] ?? '');
        $resetToken = trim($body['reset_token'] ?? '');
        $password = $body['password'] ?? '';
        $confirm = $body['confirm_password'] ?? '';

        if ($email === '' || $resetToken === '') {
            Response::error('Email and reset session are required.');
        }
        if ($password === '') {
            Response::error('New password is required.');
        }
        if ($password !== $confirm) {
            Response::error('Passwords do not match.');
        }

        $result = $this->auth->resetPasswordWithToken($email, $resetToken, $password);
        if (!$result['success']) {
            Response::error($result['message'], 400);
        }
        Response::success(null, $result['message']);
    }

    public function oauthComplete(): void
    {
        $body = Security::jsonBody();
        $accessToken = trim($body['access_token'] ?? '');
        $refreshToken = trim($body['refresh_token'] ?? '');
        $result = $this->auth->loginWithOAuthTokens($accessToken, $refreshToken);
        if (!$result['success']) {
            Response::error($result['message'], 401);
        }
        Response::success($result['data'], $result['message']);
    }

    public function googleUrl(): void
    {
        $url = $this->auth->googleAuthorizeUrl();
        if ($url === '') {
            Response::error('Google sign-in is not configured.', 503);
        }
        Response::success(['url' => $url]);
    }
}
