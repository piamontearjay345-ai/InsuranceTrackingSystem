<?php
namespace App\Controllers;

use App\Helpers\Response;
use App\Helpers\Security;
use App\Middleware\AuthMiddleware;
use App\Config\Env;
use App\Services\AuthService;
use App\Services\EmailService;
use App\Services\LogService;
use App\Services\SupabaseClient;

class AdminController
{
    private SupabaseClient $db;
    private AuthService $auth;
    private LogService $logs;

    public function __construct()
    {
        $this->db = new SupabaseClient();
        $this->auth = new AuthService();
        $this->logs = new LogService();
    }

    /** @return list<mixed> */
    private function listRows(array $res): array
    {
        return is_array($res['data'] ?? null) ? $res['data'] : [];
    }

    public function stats(): void
    {
        // Manual auth check so we can provide diagnostic info when requests are unauthorized (helpful during local debugging).
        // Avoids a hard exit inside AuthMiddleware so we can include cookie/session diagnostics when APP_DEBUG is enabled.
        @session_start();
        $user = $this->auth->currentUser();
        $allowedRoles = ['admin', 'superadmin'];
        if (!$user || !in_array($user['role'] ?? '', $allowedRoles, true)) {
            // Run the queries anyway (service role) to show actual counts for debugging.
            $students = $this->db->from('users', 'GET', null, 'select=id&role=eq.student&is_deleted=eq.false', true);
            $admins = $this->db->from('users', 'GET', null, 'select=id&role=eq.admin&is_deleted=eq.false', true);
            $beneficiaries = $this->db->from('beneficiaries', 'GET', null, 'select=status,user_id&is_deleted=eq.false', true);

            $totalStudents = is_array($students['data']) ? count($students['data']) : 0;
            $totalAdmins = is_array($admins['data']) ? count($admins['data']) : 0;
            $totalBeneficiaries = is_array($beneficiaries['data']) ? count($beneficiaries['data']) : 0;

            $debug = [
                'cookies' => $_COOKIE ?? [],
                'session_active' => session_status() === PHP_SESSION_ACTIVE,
                'session_name' => session_name(),
                'session_id' => session_id(),
                'client_ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ];

            // If debugging is enabled, include diagnostics; otherwise respond with a simple 401.
            if (Env::getBool('APP_DEBUG', false)) {
                Response::error('Unauthorized. Please sign in.', 401, array_merge($debug, [
                    'counts' => [
                        'total_students' => $totalStudents,
                        'total_admins' => $totalAdmins,
                        'total_beneficiaries' => $totalBeneficiaries,
                    ],
                ]));
            }
            Response::error('Unauthorized. Please sign in.', 401);
        }

        $students = $this->db->from('users', 'GET', null, 'select=id&role=eq.student&is_deleted=eq.false', true);
        $admins = $this->db->from('users', 'GET', null, 'select=id&role=eq.admin&is_deleted=eq.false', true);
        $beneficiaries = $this->db->from('beneficiaries', 'GET', null, 'select=status,user_id&is_deleted=eq.false', true);

        $totalStudents = is_array($students['data']) ? count($students['data']) : 0;
        $totalAdmins = is_array($admins['data']) ? count($admins['data']) : 0;
        $totalBeneficiaries = is_array($beneficiaries['data']) ? count($beneficiaries['data']) : 0;
        $updated = 0;
        $notUpdated = 0;

        if (!empty($beneficiaries['data'])) {
            foreach ($beneficiaries['data'] as $b) {
                if (($b['status'] ?? '') === 'Updated') {
                    $updated++;
                } else {
                    $notUpdated++;
                }
            }
        }

        $withoutRecord = max(0, $totalStudents - ($updated + $notUpdated));
        $notUpdated += $withoutRecord;

        Response::success([
            'total_students' => $totalStudents,
            'total_admins' => $totalAdmins,
            'total_beneficiaries' => $totalBeneficiaries,
            'updated_records' => $updated,
            'not_updated_records' => $notUpdated,
        ]);
    }

    public function createUser(): void
    {
        $actor = AuthMiddleware::requireAuth('superadmin');
        $body = Security::jsonBody();

        $email = strtolower(Security::sanitizeEmail($body['email'] ?? ''));
        $username = Security::sanitizeString($body['username'] ?? '');
        $fullname = trim($body['fullname'] ?? '');
        $password = $body['password'] ?? '';
        $role = trim($body['role'] ?? 'admin');

        if ($email === '' || $username === '' || $fullname === '' || $password === '') {
            Response::error('Email, username, full name, and password are required.');
        }
        if (!in_array($role, ['admin', 'superadmin'], true)) {
            Response::error('Role must be admin or superadmin.');
        }

        $result = $this->auth->registerRole([
            'email' => $email,
            'username' => $username,
            'fullname' => $fullname,
            'student_id' => $body['student_id'] ?? '',
            'password' => $password,
        ], $role);

        if (!$result['success']) {
            Response::error($result['message'], 400);
        }

        $this->logs->recordActivity($actor['id'], 'Created ' . $role . ' account for ' . $email);
        Response::success(null, $result['message']);
    }

    public function resetUserPassword(): void
    {
        $actor = AuthMiddleware::requireAuth('superadmin');
        $body = Security::jsonBody();
        $email = strtolower(Security::sanitizeEmail($body['email'] ?? ''));
        if ($email === '') {
            Response::error('Email is required.');
        }

        $result = (new \App\Services\PasswordResetService())->sendVerificationCode($email);
        if (!$result['success']) {
            Response::error($result['message'], 500);
        }

        $this->logs->recordActivity($actor['id'], 'Requested password reset for ' . $email);
        Response::success(null, $result['message']);
    }

    public function students(): void
    {
        AuthMiddleware::requireAuth(['admin', 'superadmin']);

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = min(50, max(5, (int) ($_GET['limit'] ?? 10)));
        $offset = ($page - 1) * $limit;
        $search = trim($_GET['search'] ?? '');
        $statusFilter = $_GET['status'] ?? '';

        $allowedStatuses = ['Updated', 'Not Updated', 'Update Beneficiary'];
        $hasStatusFilter = in_array($statusFilter, $allowedStatuses, true);
        $query = 'select=id,student_id,fullname,email,username,created_at,beneficiaries(status,updated_at,fullname,relationship,contact_number,address)&role=eq.student&is_deleted=eq.false&order=fullname.asc';

        if ($search !== '') {
            $s = rawurlencode('%' . $search . '%');
            $query .= '&or=(fullname.ilike.' . $s . ',email.ilike.' . $s . ',student_id.ilike.' . $s . ',username.ilike.' . $s . ')';
        }
        $query .= $hasStatusFilter ? '&limit=10000' : '&limit=' . $limit . '&offset=' . $offset;

        $res = $this->db->from('users', 'GET', null, $query, true);
        if (!$res['ok']) {
            Response::error($res['error'] ?? 'Failed to load students.', $res['status'] ?: 500);
        }
        $rows = $this->listRows($res);

        if ($hasStatusFilter) {
            $rows = array_values(array_filter($rows, function ($row) use ($statusFilter) {
                $ben = $row['beneficiaries'][0] ?? null;
                $status = $ben['status'] ?? 'Not Updated';
                return $status === $statusFilter;
            }));
            $rows = array_slice($rows, $offset, $limit);
        }

        Response::success([
            'students' => $rows,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    public function notifications(): void
    {
        AuthMiddleware::requireAuth(['admin', 'superadmin']);
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = min(50, max(5, (int) ($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $res = $this->db->from(
            'notifications',
            'GET',
            null,
            'select=*,users(fullname,email)&order=created_at.desc&limit=' . $limit . '&offset=' . $offset,
            true
        );
        if (!$res['ok']) {
            Response::error($res['error'] ?? 'Failed to load notifications.', $res['status'] ?: 500);
        }
        Response::success(['notifications' => $this->listRows($res)]);
    }

    public function beneficiaryUpdateRequests(): void
    {
        AuthMiddleware::requireAuth(['admin', 'superadmin']);

        $search = trim($_GET['search'] ?? '');
        $query = 'select=id,student_id,fullname,email,beneficiaries(status)&role=eq.student&is_deleted=eq.false&order=fullname.asc&limit=10000';

        if ($search !== '') {
            $s = rawurlencode('%' . $search . '%');
            $query .= '&or=(fullname.ilike.' . $s . ',email.ilike.' . $s . ',student_id.ilike.' . $s . ',username.ilike.' . $s . ')';
        }

        $res = $this->db->from('users', 'GET', null, $query, true);
        if (!$res['ok']) {
            Response::error($res['error'] ?? 'Failed to load beneficiary requests.', $res['status'] ?: 500);
        }
        Response::success(['students' => $this->listRows($res)]);
    }

    public function sendBeneficiaryUpdateRequest(): void
    {
        $admin = AuthMiddleware::requireAuth(['admin', 'superadmin']);
        $body = Security::jsonBody();
        $userId = trim($body['user_id'] ?? '');
        if ($userId === '') {
            Response::error('Student user id is required.');
        }

        $student = $this->findStudent($userId);
        if (!$student) {
            Response::error('Student not found.', 404);
        }

        $result = $this->requestBeneficiaryUpdate($student, $admin['id']);
        $message = $this->beneficiaryRequestMessage($result);
        Response::success($result, $message);
    }

    public function sendAllBeneficiaryUpdateRequests(): void
    {
        $admin = AuthMiddleware::requireAuth(['admin', 'superadmin']);
        $res = $this->db->from(
            'users',
            'GET',
            null,
            'select=id,student_id,fullname,email&role=eq.student&is_deleted=eq.false&order=fullname.asc&limit=10000',
            true
        );

        $students = $res['data'] ?? [];
        $sent = 0;
        $mailFailed = 0;
        $statusFailed = 0;
        foreach ($students as $student) {
            $result = $this->requestBeneficiaryUpdate($student, $admin['id']);
            if (!empty($result['email_sent'])) {
                $sent++;
            } elseif (!empty($result['status_updated'])) {
                $mailFailed++;
            } else {
                $statusFailed++;
            }
        }

        Response::success([
            'total' => count($students),
            'sent' => $sent,
            'mail_failed' => $mailFailed,
            'status_failed' => $statusFailed,
        ], 'Beneficiary update requests processed.');
    }

    public function failedNotifications(): void
    {
        AuthMiddleware::requireAuth(['admin', 'superadmin']);
        $res = $this->db->from('failed_notifications', 'GET', null, 'select=*&order=created_at.desc&limit=50', true);
        if (!$res['ok']) {
            Response::error($res['error'] ?? 'Failed to load failed notifications.', $res['status'] ?: 500);
        }
        Response::success(['failed' => $this->listRows($res)]);
    }

    public function retryNotification(): void
    {
        $admin = AuthMiddleware::requireAuth(['admin', 'superadmin']);
        $body = Security::jsonBody();
        $id = $body['id'] ?? '';
        if (!$id) {
            Response::error('Notification id required.');
        }
        $email = new EmailService();
        $result = $email->retryFailed($id);
        $this->logs->recordActivity($admin['id'], 'Retried failed notification ' . $id);
        if (!$result['success']) {
            Response::error($result['message'], 500);
        }
        Response::success(null, $result['message']);
    }

    public function loginHistory(): void
    {
        AuthMiddleware::requireAuth(['admin', 'superadmin']);
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $res = $this->logs->getLoginHistory($page);
        if (!$res['ok']) {
            Response::error($res['error'] ?? 'Failed to load login history.', $res['status'] ?: 500);
        }
        Response::success(['history' => $this->listRows($res)]);
    }

    public function activityLogs(): void
    {
        AuthMiddleware::requireAuth(['admin', 'superadmin']);
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $res = $this->logs->getActivityLogs($page);
        if (!$res['ok']) {
            Response::error($res['error'] ?? 'Failed to load activity logs.', $res['status'] ?: 500);
        }
        Response::success(['logs' => $this->listRows($res)]);
    }

    public function users(): void
    {
        AuthMiddleware::requireAuth('superadmin');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = min(100, max(5, (int) ($_GET['limit'] ?? 25)));
        $offset = ($page - 1) * $limit;
        $search = trim($_GET['search'] ?? '');
        $role = trim($_GET['role'] ?? '');

        $query = 'select=id,student_id,fullname,email,username,role,permissions,is_deleted,created_at&order=created_at.desc&limit=' . $limit . '&offset=' . $offset;
        if (in_array($role, ['student', 'admin', 'superadmin'], true)) {
            $query .= '&role=eq.' . $role;
        }
        if ($search !== '') {
            $s = rawurlencode('%' . $search . '%');
            $query .= '&or=(fullname.ilike.' . $s . ',email.ilike.' . $s . ',student_id.ilike.' . $s . ',username.ilike.' . $s . ')';
        }

        $res = $this->db->from('users', 'GET', null, $query, true);
        if (!$res['ok']) {
            Response::error($res['error'] ?? 'Failed to load users.', $res['status'] ?: 500);
        }
        Response::success([
            'users' => $this->listRows($res),
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    public function updateUser(): void
    {
        $actor = AuthMiddleware::requireAuth('superadmin');
        $body = Security::jsonBody();
        $id = trim($body['id'] ?? '');
        if ($id === '') {
            Response::error('User id is required.');
        }

        $payload = [];
        if (isset($body['role'])) {
            $role = trim((string) $body['role']);
            if (!in_array($role, ['student', 'admin', 'superadmin'], true)) {
                Response::error('Invalid role.');
            }
            $payload['role'] = $role;
        }
        if (array_key_exists('permissions', $body)) {
            $payload['permissions'] = $this->sanitizePermissions($body['permissions']);
        }
        if (array_key_exists('is_deleted', $body)) {
            if ($id === $actor['id'] && (bool) $body['is_deleted']) {
                Response::error('You cannot disable your own account.');
            }
            $payload['is_deleted'] = (bool) $body['is_deleted'];
        }

        if (!$payload) {
            Response::error('No changes submitted.');
        }

        $res = $this->db->from('users', 'PATCH', $payload, 'id=eq.' . rawurlencode($id), true);
        if (!$res['ok']) {
            Response::error($res['error'] ?: 'Failed to update user.', 500);
        }

        $this->logs->recordActivity($actor['id'], 'Updated user role/permissions', $id);
        Response::success(['user' => $res['data'][0] ?? null], 'User updated.');
    }

    private function sanitizePermissions(mixed $permissions): array
    {
        $input = is_array($permissions) ? $permissions : [];
        return [
            'manage_students' => !empty($input['manage_students']),
            'track_insurance' => !empty($input['track_insurance']),
            'manage_notifications' => !empty($input['manage_notifications']),
            'view_logs' => !empty($input['view_logs']),
        ];
    }

    private function findStudent(string $userId): ?array
    {
        $res = $this->db->from(
            'users',
            'GET',
            null,
            'select=id,student_id,fullname,email&role=eq.student&is_deleted=eq.false&id=eq.' . rawurlencode($userId) . '&limit=1',
            true
        );

        return ($res['ok'] && !empty($res['data'][0])) ? $res['data'][0] : null;
    }

    private function requestBeneficiaryUpdate(array $student, string $adminId): array
    {
        $userId = $student['id'] ?? '';
        if ($userId === '') {
            return [
                'status_updated' => false,
                'email_sent' => false,
                'error' => 'Student user id is missing.',
            ];
        }

        $existing = $this->db->from(
            'beneficiaries',
            'GET',
            null,
            'select=beneficiary_id&user_id=eq.' . rawurlencode($userId) . '&is_deleted=eq.false&limit=1',
            true
        );

        $payload = [
            'user_id' => $userId,
            'status' => 'Update Beneficiary',
            'updated_at' => date('c'),
        ];

        if ($existing['ok'] && !empty($existing['data'][0]['beneficiary_id'])) {
            $statusRes = $this->db->from(
                'beneficiaries',
                'PATCH',
                $payload,
                'beneficiary_id=eq.' . rawurlencode($existing['data'][0]['beneficiary_id']),
                true
            );
        } else {
            $statusRes = $this->db->from('beneficiaries', 'POST', array_merge($payload, [
                'fullname' => '',
                'relationship' => '',
                'contact_number' => '',
                'address' => '',
            ]), null, true);
        }

        if (empty($statusRes['ok'])) {
            $this->logs->recordActivity($adminId, 'Failed beneficiary update request status change', $userId);
            return [
                'status_updated' => false,
                'email_sent' => false,
                'status' => 'Update Beneficiary',
                'error' => $statusRes['error'] ?? 'Could not update beneficiary status.',
                'hint' => 'Run db/beneficiary_update_request_status_migration.sql in Supabase if the status check constraint still only allows Updated and Not Updated.',
            ];
        }

        $email = new EmailService();
        $sent = $email->send(
            (string) ($student['email'] ?? ''),
            'Beneficiary Information Update Required',
            'You are required to review and update your beneficiary information in the Student Insurance Tracking System. Please sign in to your student dashboard, review your details, and click Save after making the required updates.',
            $userId
        );

        $this->logs->recordActivity($adminId, 'Sent beneficiary update request', $userId);
        return [
            'status_updated' => true,
            'email_sent' => $sent,
            'status' => 'Update Beneficiary',
            'error' => $sent ? null : $email->lastError(),
            'hint' => $sent ? null : 'Configure XAMPP sendmail/SMTP. The student status was updated, but the email was not sent.',
        ];
    }

    private function beneficiaryRequestMessage(array $result): string
    {
        if (!empty($result['status_updated']) && !empty($result['email_sent'])) {
            return 'Notification sent and status updated.';
        }
        if (!empty($result['status_updated'])) {
            return 'Status updated to Update Beneficiary, but the email was not sent. Configure the mail service.';
        }
        return 'Could not update the student status. Run the beneficiary status migration and try again.';
    }
}
