<?php
namespace App\Controllers;

use App\Helpers\Response;
use App\Helpers\Security;
use App\Helpers\Validator;
use App\Middleware\AuthMiddleware;
use App\Services\AuthService;
use App\Services\LogService;
use App\Services\SupabaseClient;

class BeneficiaryController
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

    /** Student: own beneficiary; Admin/Superadmin: ?user_id= */
    public function show(): void
    {
        $user = AuthMiddleware::requireAuth();
        $token = $this->auth->accessToken();
        $userId = $_GET['user_id'] ?? $user['id'];
        $isStaff = $this->isStaff($user);

        if ($userId !== $user['id'] && !$isStaff) {
            Response::error('Forbidden.', 403);
        }

        $res = $this->db->from(
            'beneficiaries',
            'GET',
            null,
            'select=*&user_id=eq.' . $userId . '&is_deleted=eq.false&limit=1',
            $isStaff,
            $token
        );

        $record = ($res['ok'] && !empty($res['data'][0])) ? $res['data'][0] : null;
        Response::success(['beneficiary' => $record]);
    }

    public function save(): void
    {
        $user = AuthMiddleware::requireAuth();
        $body = Security::jsonBody();
        $errors = Validator::beneficiary($body);
        if ($errors) {
            Response::error('Validation failed.', 422, $errors);
        }

        $token = $this->auth->accessToken();
        $targetUserId = $body['user_id'] ?? $user['id'];
        $isStaff = $this->isStaff($user);
        if ($targetUserId !== $user['id'] && !$isStaff) {
            Response::error('Forbidden.', 403);
        }

        $payload = [
            'user_id' => $targetUserId,
            'fullname' => Security::sanitizeString($body['fullname']),
            'relationship' => Security::sanitizeString($body['relationship']),
            'contact_number' => Security::sanitizeString($body['contact_number']),
            'address' => Security::sanitizeString($body['address']),
            'status' => 'Updated',
            'updated_at' => date('c'),
        ];

        $existing = $this->db->from(
            'beneficiaries',
            'GET',
            null,
            'select=beneficiary_id&user_id=eq.' . $targetUserId . '&is_deleted=eq.false&limit=1',
            $isStaff,
            $token
        );

        if ($existing['ok'] && !empty($existing['data'][0]['beneficiary_id'])) {
            $id = $existing['data'][0]['beneficiary_id'];
            $res = $this->db->from('beneficiaries', 'PATCH', $payload, 'beneficiary_id=eq.' . $id, $isStaff, $token);
        } else {
            $res = $this->db->from('beneficiaries', 'POST', $payload, null, $isStaff, $token);
        }

        if (!$res['ok']) {
            Response::error('Failed to save beneficiary.', 500);
        }

        if ($isStaff && $targetUserId !== $user['id']) {
            $this->logs->recordActivity($user['id'], 'Updated beneficiary for user ' . $targetUserId, $targetUserId);
            $profile = $this->db->from('users', 'GET', null, 'select=email,fullname&id=eq.' . $targetUserId, true);
            if ($profile['ok'] && !empty($profile['data'][0]['email'])) {
                $email = new \App\Services\EmailService();
                $email->send(
                    $profile['data'][0]['email'],
                    'Beneficiary Information Updated',
                    'An administrator updated your beneficiary information. Please review your student dashboard.',
                    $targetUserId
                );
            }
        }

        $saved = is_array($res['data']) ? ($res['data'][0] ?? $res['data']) : $res['data'];
        Response::success(['beneficiary' => $saved], 'Beneficiary information saved successfully.');
    }

    private function isStaff(array $user): bool
    {
        return in_array($user['role'] ?? '', ['admin', 'superadmin'], true);
    }
}