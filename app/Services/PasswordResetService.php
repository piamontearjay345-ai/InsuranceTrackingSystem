<?php
namespace App\Services;

use App\Config\Env;
use App\Helpers\Security;

/**
 * Forgot password: 6-digit email code, then set new password via Supabase Admin API.
 */
class PasswordResetService
{
    private SupabaseClient $db;
    private EmailService $mail;
    private int $codeExpiryMinutes;
    private int $resetTokenExpiryMinutes;
    private int $maxAttempts;

    public function __construct()
    {
        $this->db = new SupabaseClient();
        $this->mail = new EmailService();
        $this->codeExpiryMinutes = Env::getInt('PASSWORD_RESET_CODE_EXPIRY_MINUTES', 15);
        $this->resetTokenExpiryMinutes = Env::getInt('PASSWORD_RESET_TOKEN_EXPIRY_MINUTES', 30);
        $this->maxAttempts = Env::getInt('PASSWORD_RESET_MAX_ATTEMPTS', 5);
    }

    public function sendVerificationCode(string $email): array
    {
        $email = strtolower(Security::sanitizeEmail($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Valid email is required.'];
        }

        $profile = $this->findUserByEmail($email);
        if (empty($profile['id'])) {
            return [
                'success' => true,
                'message' => 'If that email is registered, a verification code has been sent.',
            ];
        }

        $this->invalidatePendingCodes($email);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = date('c', time() + ($this->codeExpiryMinutes * 60));

        $insert = $this->db->from('password_reset_codes', 'POST', [
            'email' => $email,
            'code_hash' => password_hash($code, PASSWORD_DEFAULT),
            'expires_at' => $expiresAt,
            'attempts' => 0,
        ], null, true);

        if (!$insert['ok']) {
            return ['success' => false, 'message' => 'Could not start password reset. Please try again.'];
        }

        $appName = Env::get('APP_NAME', 'Insurance Tracking System');
        $subject = $appName . ' — Password reset code';
        $body = "Your password reset verification code is:\n\n"
            . $code . "\n\n"
            . "This code expires in {$this->codeExpiryMinutes} minutes.\n"
            . "If you did not request this, you can ignore this email.";

        $sent = $this->mail->send($email, $subject, $body, $profile['id'] ?? null);
        if (!$sent) {
            $reason = $this->mail->lastError();
            return [
                'success' => false,
                'message' => $reason !== ''
                    ? 'Could not send verification email. ' . $reason
                    : 'Could not send verification email. Check mail settings.',
            ];
        }

        return [
            'success' => true,
            'message' => 'A 6-digit verification code was sent to your email.',
        ];
    }

    public function verifyCode(string $email, string $code): array
    {
        $email = strtolower(Security::sanitizeEmail($email));
        $code = preg_replace('/\D/', '', trim($code)) ?? '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Valid email is required.'];
        }
        if (!preg_match('/^\d{6}$/', $code)) {
            return ['success' => false, 'message' => 'Enter the 6-digit code from your email.'];
        }

        $row = $this->findActiveCodeRow($email);
        if (!$row) {
            return ['success' => false, 'message' => 'Invalid or expired code. Request a new code.'];
        }

        if ((int) ($row['attempts'] ?? 0) >= $this->maxAttempts) {
            return ['success' => false, 'message' => 'Too many attempts. Request a new code.'];
        }

        if (!password_verify($code, (string) ($row['code_hash'] ?? ''))) {
            $this->incrementAttempts($row['id'], (int) ($row['attempts'] ?? 0));
            return ['success' => false, 'message' => 'Incorrect verification code.'];
        }

        $resetToken = bin2hex(random_bytes(32));
        $resetExpires = date('c', time() + ($this->resetTokenExpiryMinutes * 60));

        $patch = $this->db->from('password_reset_codes', 'PATCH', [
            'verified_at' => date('c'),
            'reset_token_hash' => password_hash($resetToken, PASSWORD_DEFAULT),
            'reset_expires_at' => $resetExpires,
        ], 'id=eq.' . rawurlencode((string) $row['id']), true);

        if (!$patch['ok']) {
            return ['success' => false, 'message' => 'Could not verify code. Please try again.'];
        }

        return [
            'success' => true,
            'message' => 'Code verified. You can set a new password.',
            'data' => ['reset_token' => $resetToken],
        ];
    }

    public function resetPassword(string $email, string $resetToken, string $password): array
    {
        $email = strtolower(Security::sanitizeEmail($email));
        $resetToken = trim($resetToken);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Valid email is required.'];
        }
        if ($resetToken === '') {
            return ['success' => false, 'message' => 'Reset session expired. Start again from forgot password.'];
        }
        if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            return ['success' => false, 'message' => 'Password must be at least 8 characters with letters and numbers.'];
        }

        $row = $this->findVerifiedRow($email);
        if (!$row || empty($row['reset_token_hash']) || !empty($row['used_at'])) {
            return ['success' => false, 'message' => 'Reset session expired. Request a new code.'];
        }

        if (!empty($row['reset_expires_at']) && strtotime($row['reset_expires_at']) < time()) {
            return ['success' => false, 'message' => 'Reset session expired. Request a new code.'];
        }

        if (!password_verify($resetToken, (string) $row['reset_token_hash'])) {
            return ['success' => false, 'message' => 'Reset session invalid. Request a new code.'];
        }

        $profile = $this->findUserByEmail($email);
        if (empty($profile['id'])) {
            return ['success' => false, 'message' => 'Account not found.'];
        }

        $updated = $this->db->adminUpdateUser((string) $profile['id'], ['password' => $password]);
        if (!$updated['ok']) {
            $err = is_string($updated['error']) ? $updated['error'] : 'Could not update password.';
            return ['success' => false, 'message' => $err];
        }

        $this->db->from('password_reset_codes', 'PATCH', [
            'used_at' => date('c'),
        ], 'id=eq.' . rawurlencode((string) $row['id']), true);

        $this->invalidatePendingCodes($email);

        return [
            'success' => true,
            'message' => 'Password updated successfully. You can sign in now.',
        ];
    }

    private function findUserByEmail(string $email): array
    {
        $res = $this->db->from(
            'users',
            'GET',
            null,
            'select=id,email,fullname&email=eq.' . rawurlencode($email) . '&is_deleted=eq.false',
            true
        );
        return ($res['ok'] && !empty($res['data'][0])) ? $res['data'][0] : [];
    }

    private function findActiveCodeRow(string $email): ?array
    {
        $res = $this->db->from(
            'password_reset_codes',
            'GET',
            null,
            'select=*&email=eq.' . rawurlencode($email)
            . '&used_at=is.null&verified_at=is.null'
            . '&order=created_at.desc&limit=1',
            true
        );
        if (!$res['ok'] || empty($res['data'][0])) {
            return null;
        }
        $row = $res['data'][0];
        if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time()) {
            return null;
        }
        return $row;
    }

    private function findVerifiedRow(string $email): ?array
    {
        $res = $this->db->from(
            'password_reset_codes',
            'GET',
            null,
            'select=*&email=eq.' . rawurlencode($email)
            . '&used_at=is.null&verified_at=not.is.null'
            . '&order=verified_at.desc&limit=1',
            true
        );
        return ($res['ok'] && !empty($res['data'][0])) ? $res['data'][0] : null;
    }

    private function invalidatePendingCodes(string $email): void
    {
        $this->db->from(
            'password_reset_codes',
            'PATCH',
            ['used_at' => date('c')],
            'email=eq.' . rawurlencode($email) . '&used_at=is.null',
            true
        );
    }

    private function incrementAttempts(string $id, int $current): void
    {
        $this->db->from('password_reset_codes', 'PATCH', [
            'attempts' => $current + 1,
        ], 'id=eq.' . rawurlencode($id), true);
    }
}
