<?php
namespace App\Helpers;

use App\Config\Env;

/**
 * Public site URL for links in emails (must be reachable on phones, not localhost).
 */
class SiteUrl
{
    public static function publicBase(): string
    {
        $public = rtrim((string) Env::get('PUBLIC_APP_URL', ''), '/');
        if ($public !== '') {
            return $public;
        }

        $app = rtrim((string) Env::get('APP_URL', ''), '/');
        if ($app !== '' && !self::isLocalHost($app)) {
            return $app;
        }

        return $app;
    }

    public static function emailConfirmRedirectUrl(): string
    {
        $base = self::publicBase();
        if ($base === '') {
            return '/auth/email-confirmed.html';
        }
        return $base . '/auth/email-confirmed.html';
    }

    public static function isLocalHost(string $url): bool
    {
        return (bool) preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?#i', $url);
    }
}
