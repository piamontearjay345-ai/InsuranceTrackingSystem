<?php
namespace App\Controllers;

use App\Helpers\Response;

/**
 * Temporary debug endpoints for local troubleshooting only.
 */
class DebugController
{
    public function session(): void
    {
        // Return server-side visibility: cookies, session, and headers
        $cookies = $_COOKIE ?? [];
        $session = [];
        if (session_status() !== PHP_SESSION_ACTIVE) {
            // attempt to start session if not active
            @session_start();
        }
        $session = $_SESSION ?? [];
        $headers = [];
        foreach (getallheaders() as $k => $v) {
            $headers[$k] = $v;
        }

        Response::success([
            'cookies' => $cookies,
            'session' => $session,
            'headers' => $headers,
            'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
        ], 'Debug info');
    }
}
