<?php
namespace App\Services;

use App\Helpers\Security;

/**
 * Audit, login history, and activity logging.
 */
class LogService
{
    private SupabaseClient $db;

    public function __construct()
    {
        $this->db = new SupabaseClient();
    }

    public function recordLogin(?string $userId, string $email, string $status, ?string $role): void
    {
        $this->db->from('login_history', 'POST', [
            'user_id' => $userId,
            'email' => $email,
            'login_status' => $status,
            'ip_address' => Security::clientIp(),
            'browser_info' => Security::browserInfo(),
            'device_info' => Security::deviceInfo(),
            'role' => $role,
        ], null, true);
    }

    public function recordActivity(?string $adminId, string $action, ?string $affected = null, string $severity = 'info'): void
    {
        $this->db->from('activity_logs', 'POST', [
            'admin_id' => $adminId,
            'action' => $action,
            'affected_record' => $affected,
            'ip_address' => Security::clientIp(),
            'browser_info' => Security::browserInfo(),
            'device_info' => Security::deviceInfo(),
            'severity_level' => $severity,
        ], null, true);
    }

    public function getLoginHistory(int $page = 1, int $limit = 20): array
    {
        $offset = ($page - 1) * $limit;
        return $this->db->from(
            'login_history',
            'GET',
            null,
            'select=*&order=created_at.desc&limit=' . $limit . '&offset=' . $offset,
            true
        );
    }

    public function getActivityLogs(int $page = 1, int $limit = 20): array
    {
        $offset = ($page - 1) * $limit;
        return $this->db->from(
            'activity_logs',
            'GET',
            null,
            'select=*,users(fullname,username)&order=created_at.desc&limit=' . $limit . '&offset=' . $offset,
            true
        );
    }
}
