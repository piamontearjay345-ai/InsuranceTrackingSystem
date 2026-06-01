<?php
namespace App\Helpers;

/**
 * Security utilities: CSRF, sanitization, client metadata.
 */
class Security
{
    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function validateCsrf(?string $token): bool
    {
        if (!$token || empty($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    public static function sanitizeString(?string $value): string
    {
        if ($value === null) {
            return '';
        }
        return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
    }

    public static function sanitizeEmail(?string $email): string
    {
        return filter_var(trim((string) $email), FILTER_SANITIZE_EMAIL) ?: '';
    }

    public static function clientIp(): string
    {
        return $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '0.0.0.0';
    }

    public static function browserInfo(): string
    {
        return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'), 0, 500);
    }

    public static function deviceInfo(): string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (preg_match('/Mobile|Android|iPhone|iPad/i', $ua)) {
            return 'Mobile';
        }
        return 'Desktop';
    }

    public static function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if (!$raw) {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
