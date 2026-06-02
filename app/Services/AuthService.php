<?php
namespace App\Services;

use App\Config\Env;
use App\Helpers\Security;

/**
 * Authentication: Supabase Auth + profile in public.users + PHP session.
 */
class AuthService
{
    private SupabaseClient $db;
    private LogService $logs;

    public function __construct()
    {
        $this->db = new SupabaseClient();
        $this->logs = new LogService();
    }

    public function register(array $data): array
    {
        $email = strtolower(Security::sanitizeEmail($data['email']));
        $username = Security::sanitizeString($data['username']);
        $role = 'student';

        // Check duplicate email/username via service role
        $existing = $this->db->from(
            'users',
            'GET',
            null,
            'select=id&or=(email.eq.' . rawurlencode($email) . ',username.eq.' . rawurlencode($username) . ')&is_deleted=eq.false',
            true
        );
        if ($existing['ok'] && !empty($existing['data'])) {
            return ['success' => false, 'message' => 'Email or username already exists.'];
        }

        $signup = $this->db->authSignup([
            'email' => $email,
            'password' => $data['password'],
            'data' => [
                'fullname' => $data['fullname'],
                'student_id' => $data['student_id'],
                'username' => $username,
                'role' => $role,
            ],
            'options' => [
                'emailRedirectTo' => $this->emailConfirmRedirectUrl(),
            ],
        ]);

        if (!$signup['ok']) {
            $msg = is_string($signup['error']) ? $signup['error'] : 'Registration failed.';
            if (str_contains(strtolower($msg), 'already')) {
                $msg = 'Email already registered.';
            }
            return ['success' => false, 'message' => $msg];
        }

        $userId = $signup['data']['id'] ?? ($signup['data']['user']['id'] ?? null);
        if ($userId) {
            // Upsert profile (trigger may have inserted; ensure fields)
            $this->db->from('users', 'PATCH', [
                'student_id' => $data['student_id'],
                'fullname' => $data['fullname'],
                'email' => $email,
                'username' => $username,
                'role' => $role,
                'is_deleted' => false,
            ], 'id=eq.' . $userId, true);
        }

        $needsEmailConfirm = $this->signupNeedsEmailConfirmation($signup['data'] ?? []);

        return [
            'success' => true,
            'message' => $needsEmailConfirm
                ? 'Registration successful. Please check your email and click the confirmation link, then sign in.'
                : 'Registration successful. Please sign in.',
            'data' => ['email_confirmation_required' => $needsEmailConfirm],
        ];
    }

    public function confirmEmail(string $tokenHash, string $type = 'signup', string $code = ''): array
    {
        $allowed = ['signup', 'email', 'email_change', 'invite', 'magiclink', 'recovery'];
        $type = in_array($type, $allowed, true) ? $type : 'signup';

        if ($tokenHash !== '') {
            $verify = $this->db->authVerify($type, $tokenHash);
        } elseif ($code !== '') {
            $verify = $this->db->authVerifyOtp($type, $code);
        } else {
            return ['success' => false, 'message' => 'Invalid confirmation link.'];
        }

        if (!$verify['ok']) {
            $msg = is_string($verify['error']) ? $verify['error'] : 'Invalid or expired confirmation link.';
            return ['success' => false, 'message' => $msg];
        }

        return [
            'success' => true,
            'message' => 'Email confirmed successfully. You can now log in.',
        ];
    }

    private function emailConfirmRedirectUrl(): string
    {
        $base = rtrim(Env::get('APP_URL', ''), '/');
        if ($base === '') {
            return '/auth/email-confirmed.html';
        }
        return $base . '/auth/email-confirmed.html';
    }

    private function signupNeedsEmailConfirmation(array $signupData): bool
    {
        if (isset($signupData['session']['access_token']) && $signupData['session']['access_token'] !== '') {
            return false;
        }
        if (!empty($signupData['access_token'])) {
            return false;
        }
        $user = $signupData['user'] ?? $signupData;
        if (is_array($user) && empty($user['email_confirmed_at']) && !empty($user['confirmation_sent_at'])) {
            return true;
        }
        if (is_array($user) && empty($user['email_confirmed_at']) && ($user['confirmed_at'] ?? null) === null) {
            return true;
        }
        return false;
    }

    public function registerRole(array $data, string $role): array
    {
        $email = strtolower(Security::sanitizeEmail($data['email']));
        $username = Security::sanitizeString($data['username']);
        $role = trim($role) === 'superadmin' ? 'superadmin' : 'admin';

        $existing = $this->db->from(
            'users',
            'GET',
            null,
            'select=id&or=(email.eq.' . rawurlencode($email) . ',username.eq.' . rawurlencode($username) . ')&is_deleted=eq.false',
            true
        );
        if ($existing['ok'] && !empty($existing['data'])) {
            return ['success' => false, 'message' => 'Email or username already exists.'];
        }

        $signup = $this->db->authSignup([
            'email' => $email,
            'password' => $data['password'],
            'data' => [
                'fullname' => $data['fullname'],
                'student_id' => $data['student_id'] ?? '',
                'username' => $username,
                'role' => $role,
            ],
        ]);

        if (!$signup['ok']) {
            $msg = is_string($signup['error']) ? $signup['error'] : 'User creation failed.';
            if (str_contains(strtolower($msg), 'already')) {
                $msg = 'Email already registered.';
            }
            return ['success' => false, 'message' => $msg];
        }

        $userId = $signup['data']['id'] ?? ($signup['data']['user']['id'] ?? null);
        if ($userId) {
            $this->db->from('users', 'PATCH', [
                'student_id' => $data['student_id'] ?? '',
                'fullname' => $data['fullname'],
                'email' => $email,
                'username' => $username,
                'role' => $role,
                'is_deleted' => false,
            ], 'id=eq.' . $userId, true);
        }

        return ['success' => true, 'message' => 'User account created successfully.'];
    }

    public function requestPasswordReset(string $email): array
    {
        return (new PasswordResetService())->sendVerificationCode($email);
    }

    public function verifyPasswordResetCode(string $email, string $code): array
    {
        return (new PasswordResetService())->verifyCode($email, $code);
    }

    public function resetPasswordWithToken(string $email, string $resetToken, string $password): array
    {
        return (new PasswordResetService())->resetPassword($email, $resetToken, $password);
    }

    public function login(string $identifier, string $password): array
    {
        $identifier = trim($identifier);
        $maxAttempts = Env::getInt('LOGIN_MAX_ATTEMPTS', 5);
        $lockMinutes = Env::getInt('LOGIN_LOCKOUT_MINUTES', 15);

        $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL);
        $profile = $this->resolveProfile($identifier);
        $email = !empty($profile['email'])
            ? strtolower(trim($profile['email']))
            : ($isEmail ? strtolower($identifier) : null);

        if (!$email) {
            $this->logs->recordLogin(null, $identifier, 'failed', $profile['role'] ?? null);
            return ['success' => false, 'message' => 'Invalid email/username or password.'];
        }

        $isLocked = !empty($profile['locked_until']) && strtotime($profile['locked_until']) > time();

        $auth = $this->db->authLogin($email, $password);
        if (!$auth['ok']) {
            if ($isLocked) {
                $this->logs->recordLogin($profile['id'] ?? null, $email, 'locked', $profile['role'] ?? null);
                return ['success' => false, 'message' => 'Account temporarily locked. Try again later.'];
            }
            $this->handleFailedLogin($profile, $maxAttempts, $lockMinutes);
            $this->logs->recordLogin($profile['id'] ?? null, $email, 'failed', $profile['role'] ?? null);
            $msg = is_string($auth['error']) && $auth['error'] !== ''
                ? $auth['error']
                : 'Invalid email/username or password.';
            return ['success' => false, 'message' => $msg];
        }

        $accessToken = $auth['data']['access_token'] ?? '';
        $refreshToken = $auth['data']['refresh_token'] ?? '';
        $user = $auth['data']['user'] ?? [];

        // Reset failed attempts and clear lock if the credentials are correct
        if (!empty($profile['id'])) {
            $this->db->from('users', 'PATCH', [
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ], 'id=eq.' . $profile['id'], true);
        }

        $authUserId = (string) ($user['id'] ?? $profile['id'] ?? '');
        if ($authUserId !== '' && !empty($profile['id']) && $profile['id'] === $authUserId) {
            $fullProfile = $profile;
        } else {
            $fullProfile = $this->getProfileById($authUserId ?: null, $accessToken) ?: $profile;
        }

        if (empty($fullProfile['id']) && $authUserId !== '') {
            $fullProfile = $this->ensureProfileFromAuthUser($user, $accessToken);
        }

        $this->establishSession($fullProfile, $accessToken, $refreshToken);
        $this->logs->recordLogin($fullProfile['id'] ?? null, $email, 'success', $fullProfile['role'] ?? null);

        $redirect = 'student/dashboard.html';
        if ($fullProfile['role'] === 'superadmin' || $fullProfile['role'] === 'super_admin') {
            $redirect = 'superadmin/dashboard.php';
        } elseif ($fullProfile['role'] === 'admin') {
            $redirect = 'admin/dashboard.html';
        }

        return [
            'success' => true,
            'message' => 'Login successful.',
            'data' => [
                'role' => $fullProfile['role'],
                'redirect' => $redirect,
                'user' => $this->publicUser($fullProfile),
            ],
        ];
    }

    public function startGoogleSignIn(): string
    {
        $clientId = trim(Env::get('GOOGLE_CLIENT_ID', '') ?? '');
        $clientSecret = trim(Env::get('GOOGLE_CLIENT_SECRET', '') ?? '');
        if ($clientId !== '' && $clientSecret !== '') {
            return $this->buildDirectGoogleOAuthUrl($clientId);
        }
        return '';
    }

    public function completeGoogleOAuth(string $code, string $state): array
    {
        $expectedState = $_SESSION['google_oauth_state'] ?? '';
        unset($_SESSION['google_oauth_state']);
        if ($expectedState === '' || !hash_equals($expectedState, $state)) {
            return ['success' => false, 'message' => 'Google sign-in session expired. Please try again.'];
        }

        $tokens = $this->exchangeGoogleAuthCode($code);
        if (!$tokens['ok']) {
            return ['success' => false, 'message' => $tokens['error'] ?? 'Could not complete Google sign-in.'];
        }

        $idToken = $tokens['id_token'] ?? '';
        $claims = $this->verifyGoogleIdToken($idToken);
        if (!$claims['ok']) {
            return ['success' => false, 'message' => $claims['error'] ?? 'Could not verify Google account.'];
        }

        $email = strtolower(trim($claims['email'] ?? ''));
        $fullName = trim($claims['name'] ?? '');
        if ($email === '') {
            return ['success' => false, 'message' => 'Google account did not provide an email address.'];
        }

        if ($idToken !== '') {
            $supabase = $this->db->authSignInWithIdToken('google', $idToken);
            if ($supabase['ok'] && !empty($supabase['data']['access_token'])) {
                return $this->finishAuthTokenResponse($supabase['data'], $email);
            }
        }

        return $this->loginWithGoogleEmailViaAdmin($email, $fullName);
    }

    public function googleAuthorizeUrl(): string
    {
        $appUrl = rtrim(Env::get('APP_URL', ''), '/');
        $callback = $appUrl . '/auth/oauth-callback.html';
        $base = $this->db->getUrl();
        if ($base === '') {
            return '';
        }
        $query = http_build_query([
            'provider' => 'google',
            'redirect_to' => $callback,
        ]);
        return $base . '/auth/v1/authorize?' . $query;
    }

    public function loginWithOAuthTokens(string $accessToken, string $refreshToken = ''): array
    {
        $accessToken = trim($accessToken);
        if ($accessToken === '') {
            return ['success' => false, 'message' => 'Google sign-in did not return a valid session.'];
        }

        $authUser = $this->db->authGetUser($accessToken);
        if (!$authUser['ok'] || empty($authUser['data']['id'])) {
            return ['success' => false, 'message' => 'Could not verify Google account.'];
        }

        $user = $authUser['data'];
        $profile = $this->getProfileById($user['id'], $accessToken);
        if (!$profile) {
            $profile = $this->ensureProfileFromAuthUser($user, $accessToken);
        }

        if (!empty($profile['is_deleted'])) {
            return ['success' => false, 'message' => 'This account is disabled.'];
        }

        $email = strtolower(trim($profile['email'] ?? $user['email'] ?? ''));
        $this->establishSession($profile, $accessToken, $refreshToken);
        $this->logs->recordLogin($profile['id'] ?? null, $email, 'success', $profile['role'] ?? 'student');

        $redirect = 'student/dashboard.html';
        if (($profile['role'] ?? '') === 'superadmin' || ($profile['role'] ?? '') === 'super_admin') {
            $redirect = 'superadmin/dashboard.php';
        } elseif (($profile['role'] ?? '') === 'admin') {
            $redirect = 'admin/dashboard.html';
        }

        return [
            'success' => true,
            'message' => 'Login successful.',
            'data' => [
                'role' => $profile['role'] ?? 'student',
                'redirect' => $redirect,
                'user' => $this->publicUser($profile),
            ],
        ];
    }

    public function logout(): void
    {
        $token = $_SESSION['access_token'] ?? null;
        if ($token) {
            $this->db->authLogout($token);
        }
        session_unset();
        session_destroy();
    }

    public function currentUser(): ?array
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }
        return $_SESSION['user'] ?? null;
    }

    public function accessToken(): ?string
    {
        return $_SESSION['access_token'] ?? null;
    }

    public function requireRole(string $role): bool
    {
        $user = $this->currentUser();
        return $user && ($user['role'] ?? '') === $role;
    }

    private function resolveProfile(string $identifier): array
    {
        $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL);
        $filter = $isEmail
            ? 'or=(email.eq.' . rawurlencode($identifier) . ',username.eq.' . rawurlencode($identifier) . ')'
            : 'or=(username.eq.' . rawurlencode($identifier) . ',email.eq.' . rawurlencode($identifier) . ')';

        $res = $this->db->from('users', 'GET', null, 'select=*&' . $filter . '&is_deleted=eq.false', true);
        if ($res['ok'] && !empty($res['data'][0])) {
            return $res['data'][0];
        }

        if (!$isEmail) {
            $res = $this->db->from('users', 'GET', null,
                'select=*&username.ilike.' . rawurlencode($identifier) . '&is_deleted=eq.false',
                true
            );
            if ($res['ok'] && !empty($res['data'][0])) {
                return $res['data'][0];
            }
        }

        return [];
    }

    private function getProfileById(?string $id, string $token): ?array
    {
        if (!$id) {
            return null;
        }
        $res = $this->db->from('users', 'GET', null, 'select=*&id=eq.' . $id, false, $token);
        return ($res['ok'] && !empty($res['data'][0])) ? $res['data'][0] : null;
    }

    private function handleFailedLogin(array $profile, int $maxAttempts, int $lockMinutes): void
    {
        if (empty($profile['id'])) {
            return;
        }
        $attempts = (int) ($profile['failed_login_attempts'] ?? 0) + 1;
        $patch = ['failed_login_attempts' => $attempts];
        if ($attempts >= $maxAttempts) {
            $patch['locked_until'] = date('c', time() + ($lockMinutes * 60));
            $patch['failed_login_attempts'] = 0;
        }
        $this->db->from('users', 'PATCH', $patch, 'id=eq.' . $profile['id'], true);
    }

    private function establishSession(array $profile, string $accessToken, string $refreshToken): void
    {
        $_SESSION['user_id'] = $profile['id'];
        $_SESSION['access_token'] = $accessToken;
        $_SESSION['refresh_token'] = $refreshToken;
        $_SESSION['user'] = $this->publicUser($profile);
        $_SESSION['last_activity'] = time();
    }

    private function ensureProfileFromAuthUser(array $user, string $accessToken): array
    {
        $meta = $user['user_metadata'] ?? [];
        $email = strtolower(trim($user['email'] ?? ''));
        $username = Security::sanitizeString($meta['username'] ?? '');
        if ($username === '' && $email !== '') {
            $username = preg_replace('/[^A-Za-z0-9_]/', '_', strstr($email, '@', true) ?: 'user');
            $username = substr($username, 0, 20);
        }
        $profile = [
            'id' => $user['id'],
            'student_id' => $meta['student_id'] ?? '',
            'fullname' => $meta['fullname'] ?? ($meta['full_name'] ?? ($user['email'] ?? 'User')),
            'email' => $email,
            'username' => $username,
            'role' => $meta['role'] ?? 'student',
            'permissions' => [],
            'is_deleted' => false,
        ];

        $payload = [
            'student_id' => $profile['student_id'],
            'fullname' => $profile['fullname'],
            'email' => $profile['email'],
            'username' => $profile['username'],
            'role' => $profile['role'],
            'is_deleted' => false,
        ];
        $this->db->from('users', 'PATCH', $payload, 'id=eq.' . rawurlencode((string) $user['id']), true);
        $loaded = $this->getProfileById($user['id'], $accessToken);
        if ($loaded) {
            return $loaded;
        }
        $this->db->from('users', 'POST', array_merge(['id' => $user['id']], $payload), null, true);
        return $this->getProfileById($user['id'], $accessToken) ?: $profile;
    }

    private function publicUser(array $profile): array
    {
        return [
            'id' => $profile['id'],
            'student_id' => $profile['student_id'] ?? '',
            'fullname' => $profile['fullname'] ?? '',
            'email' => $profile['email'] ?? '',
            'username' => $profile['username'] ?? '',
            'role' => $profile['role'] ?? 'student',
            'permissions' => $profile['permissions'] ?? [],
        ];
    }

    private function buildDirectGoogleOAuthUrl(string $clientId): string
    {
        $appUrl = rtrim(Env::get('APP_URL', ''), '/');
        $redirectUri = $appUrl . '/auth/google-callback.php';
        $state = bin2hex(random_bytes(16));
        $_SESSION['google_oauth_state'] = $state;

        $query = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'access_type' => 'online',
            'prompt' => 'select_account',
        ]);

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . $query;
    }

    private function exchangeGoogleAuthCode(string $code): array
    {
        $clientId = trim(Env::get('GOOGLE_CLIENT_ID', '') ?? '');
        $clientSecret = trim(Env::get('GOOGLE_CLIENT_SECRET', '') ?? '');
        $appUrl = rtrim(Env::get('APP_URL', ''), '/');
        $redirectUri = $appUrl . '/auth/google-callback.php';

        if ($clientId === '' || $clientSecret === '') {
            return ['ok' => false, 'error' => 'Google OAuth is not configured on the server.'];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'PHP cURL extension is required.'];
        }

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_POSTFIELDS => http_build_query([
                'code' => $code,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            $decoded = is_string($response) ? json_decode($response, true) : null;
            $msg = is_array($decoded) ? ($decoded['error_description'] ?? $decoded['error'] ?? 'Google token exchange failed.') : 'Google token exchange failed.';
            return ['ok' => false, 'error' => (string) $msg];
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data) || empty($data['id_token'])) {
            return ['ok' => false, 'error' => 'Google did not return a valid ID token.'];
        }

        return ['ok' => true, 'id_token' => $data['id_token']];
    }

    private function verifyGoogleIdToken(string $idToken): array
    {
        if ($idToken === '') {
            return ['ok' => false, 'error' => 'Missing Google ID token.'];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'PHP cURL extension is required.'];
        }

        $ch = curl_init('https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($idToken));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            return ['ok' => false, 'error' => 'Invalid Google ID token.'];
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            return ['ok' => false, 'error' => 'Invalid Google ID token response.'];
        }

        $clientId = trim(Env::get('GOOGLE_CLIENT_ID', '') ?? '');
        if ($clientId !== '' && ($data['aud'] ?? '') !== $clientId) {
            return ['ok' => false, 'error' => 'Google token audience mismatch.'];
        }
        if (($data['email_verified'] ?? '') !== 'true' && ($data['email_verified'] ?? false) !== true) {
            return ['ok' => false, 'error' => 'Google email is not verified.'];
        }

        return [
            'ok' => true,
            'email' => $data['email'] ?? '',
            'name' => $data['name'] ?? '',
        ];
    }

    private function loginWithGoogleEmailViaAdmin(string $email, string $fullName): array
    {
        $profile = $this->resolveProfile($email);
        if (empty($profile['id'])) {
            $username = preg_replace('/[^A-Za-z0-9_]/', '_', strstr($email, '@', true) ?: 'user');
            $username = substr($username, 0, 20);
            $password = bin2hex(random_bytes(24));
            $created = $this->db->adminCreateUser([
                'email' => $email,
                'password' => $password,
                'email_confirm' => true,
                'user_metadata' => [
                    'fullname' => $fullName !== '' ? $fullName : $email,
                    'username' => $username,
                    'role' => 'student',
                ],
            ]);
            if (!$created['ok']) {
                $err = is_string($created['error']) ? $created['error'] : 'Could not create account for Google sign-in.';
                if (!str_contains(strtolower($err), 'already') && !str_contains(strtolower($err), 'registered')) {
                    return ['success' => false, 'message' => $err];
                }
            } else {
                $userId = $created['data']['id'] ?? ($created['data']['user']['id'] ?? null);
                if ($userId) {
                    $this->db->from('users', 'PATCH', [
                        'fullname' => $fullName !== '' ? $fullName : $email,
                        'email' => $email,
                        'username' => $username,
                        'role' => 'student',
                        'is_deleted' => false,
                    ], 'id=eq.' . rawurlencode((string) $userId), true);
                }
            }
        }

        $session = $this->createSupabaseSessionForEmail($email);
        if (!$session['ok']) {
            return ['success' => false, 'message' => $session['error'] ?? 'Could not start session after Google sign-in.'];
        }

        return $this->finishAuthTokenResponse($session['data'], $email);
    }

    private function createSupabaseSessionForEmail(string $email): array
    {
        $link = $this->db->adminGenerateLink('magiclink', $email);
        if (!$link['ok']) {
            return ['ok' => false, 'error' => is_string($link['error']) ? $link['error'] : 'Could not sign in with Google.'];
        }

        $tokenHash = $link['data']['hashed_token'] ?? '';
        if ($tokenHash === '') {
            return ['ok' => false, 'error' => 'Could not create sign-in session.'];
        }

        $verify = $this->db->authVerify('magiclink', $tokenHash);
        if (!$verify['ok'] || empty($verify['data']['access_token'])) {
            return ['ok' => false, 'error' => is_string($verify['error']) ? $verify['error'] : 'Could not verify Google sign-in session.'];
        }

        return ['ok' => true, 'data' => $verify['data']];
    }

    private function finishAuthTokenResponse(array $tokenData, string $email): array
    {
        $accessToken = $tokenData['access_token'] ?? '';
        $refreshToken = $tokenData['refresh_token'] ?? '';
        if ($accessToken === '') {
            return ['success' => false, 'message' => 'Sign-in did not return a valid session.'];
        }

        $authUser = $this->db->authGetUser($accessToken);
        if (!$authUser['ok'] || empty($authUser['data']['id'])) {
            return ['success' => false, 'message' => 'Could not load account after Google sign-in.'];
        }

        $user = $authUser['data'];
        $profile = $this->getProfileById($user['id'], $accessToken);
        if (!$profile) {
            $profile = $this->ensureProfileFromAuthUser($user, $accessToken);
        }

        if (!empty($profile['is_deleted'])) {
            return ['success' => false, 'message' => 'This account is disabled.'];
        }

        $this->establishSession($profile, $accessToken, $refreshToken);
        $this->logs->recordLogin($profile['id'] ?? null, $email, 'success', $profile['role'] ?? 'student');

        $redirect = 'student/dashboard.html';
        if (($profile['role'] ?? '') === 'superadmin' || ($profile['role'] ?? '') === 'super_admin') {
            $redirect = 'superadmin/dashboard.php';
        } elseif (($profile['role'] ?? '') === 'admin') {
            $redirect = 'admin/dashboard.html';
        }

        return [
            'success' => true,
            'message' => 'Login successful.',
            'data' => [
                'role' => $profile['role'] ?? 'student',
                'redirect' => $redirect,
                'user' => $this->publicUser($profile),
            ],
        ];
    }
}
