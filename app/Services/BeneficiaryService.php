<?php

namespace App\Services;

use App\Services\SupabaseClient;
use App\Helpers\Response;

class BeneficiaryService
{
    private SupabaseClient $db;

    public function __construct()
    {
        $this->db = new SupabaseClient();
    }

    /**
     * Get beneficiary by user ID
     */
    public function getByUserId(string $userId, bool $useServiceRole = false): array
    {
        return $this->db->from(
            'beneficiaries',
            'GET',
            null,
            "user_id=eq.{$userId}&is_deleted=eq.false",
            $useServiceRole
        );
    }

    /**
     * Create beneficiary
     */
    public function create(array $data): array
    {
        return $this->db->from(
            'beneficiaries',
            'POST',
            $data,
            null,
            true
        );
    }

    /**
     * Update beneficiary
     */
    public function update(string $beneficiaryId, array $data): array
    {
        return $this->db->from(
            'beneficiaries',
            'PATCH',
            $data,
            "id=eq.{$beneficiaryId}",
            true
        );
    }

    /**
     * Delete beneficiary (soft delete)
     */
    public function delete(string $beneficiaryId): array
    {
        return $this->update($beneficiaryId, ['is_deleted' => true]);
    }

    /**
     * Get all beneficiaries (admin only)
     */
    public function getAll(bool $useServiceRole = true): array
    {
        return $this->db->from(
            'beneficiaries',
            'GET',
            null,
            'is_deleted=eq.false',
            $useServiceRole
        );
    }
}
