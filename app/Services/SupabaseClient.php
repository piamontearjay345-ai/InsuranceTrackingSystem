<?php
namespace App\Services;

use App\Config\Env;

/**
 * HTTP client for Supabase REST API and Auth API.
 */
class SupabaseClient
{
    private string $url;
    private string $anonKey;
    private string $serviceKey;

    public function __construct()
    {
        $this->url = rtrim(Env::get('SUPABASE_URL', ''), '/');
        $this->anonKey = Env::get('SUPABASE_ANON_KEY', '');
        $this->serviceKey = Env::get('SUPABASE_SERVICE_ROLE_KEY', '');
    }

    public function authSignup(array $payload): array
    {
        return $this->request('POST', '/auth/v1/signup', $payload, $this->anonKey);
    }

    public function authLogin(string $email, string $password): array
    {
        return $this->request('POST', '/auth/v1/token?grant_type=password', [
            'email' => $email,
            'password' => $password,
        ], $this->anonKey);
    }

    public function authLogout(string $accessToken): array
    {
        return $this->request('POST', '/auth/v1/logout', [], $this->anonKey, $accessToken);
    }

    public function authPasswordReset(string $email): array
    {
        return $this->request('POST', '/auth/v1/recover', ['email' => $email], $this->anonKey);
    }

    public function authGetUser(string $accessToken): array
    {
        return $this->request('GET', '/auth/v1/user', null, $this->anonKey, $accessToken);
    }

    public function authSignInWithIdToken(string $provider, string $idToken): array
    {
        return $this->request('POST', '/auth/v1/token?grant_type=id_token', [
            'provider' => $provider,
            'id_token' => $idToken,
        ], $this->anonKey);
    }

    public function authVerify(string $type, string $tokenHash): array
    {
        return $this->request('POST', '/auth/v1/verify', [
            'type' => $type,
            'token_hash' => $tokenHash,
        ], $this->anonKey);
    }

    public function adminCreateUser(array $payload): array
    {
        return $this->request('POST', '/auth/v1/admin/users', $payload, $this->serviceKey, $this->serviceKey);
    }

    public function adminGenerateLink(string $type, string $email): array
    {
        return $this->request('POST', '/auth/v1/admin/generate_link', [
            'type' => $type,
            'email' => $email,
        ], $this->serviceKey, $this->serviceKey);
    }

    public function adminUpdateUser(string $userId, array $payload): array
    {
        return $this->request(
            'PUT',
            '/auth/v1/admin/users/' . rawurlencode($userId),
            $payload,
            $this->serviceKey,
            $this->serviceKey
        );
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getAnonKey(): string
    {
        return $this->anonKey;
    }

    /** PostgREST query with user JWT (RLS) or service role. */
    public function from(string $table, string $method = 'GET', ?array $body = null, ?string $query = null, bool $useService = false, ?string $userToken = null): array
    {
        $path = '/rest/v1/' . $table . ($query ? '?' . $query : '');
        $key = $useService ? $this->serviceKey : $this->anonKey;
        $token = $useService ? $this->serviceKey : ($userToken ?? $this->anonKey);
        return $this->request($method, $path, $body, $key, $token);
    }

    public function rpc(string $function, array $body = [], bool $useService = true, ?string $userToken = null): array
    {
        $path = '/rest/v1/rpc/' . $function;
        $key = $useService ? $this->serviceKey : $this->anonKey;
        $token = $useService ? $this->serviceKey : ($userToken ?? $this->anonKey);
        return $this->request('POST', $path, $body, $key, $token);
    }

    private function request(string $method, string $path, ?array $body, string $apiKey, ?string $bearer = null): array
    {
        if ($this->url === '' || $apiKey === '') {
            return ['ok' => false, 'status' => 0, 'error' => 'Supabase URL or API key is not configured in .env', 'data' => null];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'status' => 0, 'error' => 'PHP cURL extension is required. Enable it in php.ini.', 'data' => null];
        }
        $url = $this->url . $path;
        $headers = [
            'apikey: ' . $apiKey,
            'Content-Type: application/json',
        ];
        if ($bearer) {
            $headers[] = 'Authorization: Bearer ' . $bearer;
        }
        if (in_array($method, ['POST', 'PATCH', 'PUT'], true)) {
            $headers[] = 'Prefer: return=representation';
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
        ]);
        if ($body !== null && $method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['ok' => false, 'status' => 0, 'error' => $error ?: 'Request failed', 'data' => null];
        }

        $decoded = json_decode($response, true);
        $ok = $httpCode >= 200 && $httpCode < 300;

        return [
            'ok' => $ok,
            'status' => $httpCode,
            'error' => $ok ? null : ($decoded['msg'] ?? $decoded['error_description'] ?? $decoded['message'] ?? 'Request failed'),
            'data' => $decoded,
        ];
    }
}
