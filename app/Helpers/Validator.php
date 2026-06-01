<?php
namespace App\Helpers;

/**
 * Input validation for registration and beneficiary forms.
 */
class Validator
{
    public static function registration(array $data): array
    {
        $errors = [];
        $fullname = trim($data['fullname'] ?? '');
        $studentId = trim($data['student_id'] ?? '');
        $email = trim($data['email'] ?? '');
        $username = trim($data['username'] ?? '');
        $password = $data['password'] ?? '';
        $confirm = $data['confirm_password'] ?? '';

        if ($fullname === '') {
            $errors['fullname'] = 'Full name is required.';
        }
        if ($studentId === '') {
            $errors['student_id'] = 'ID number is required.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Valid email is required.';
        }
        if (!preg_match('/^[A-Za-z0-9_]{3,20}$/', $username)) {
            $errors['username'] = 'Username must be 3-20 characters (letters, numbers, underscore).';
        }
        if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $errors['password'] = 'Password must be at least 8 characters with letters and numbers.';
        }
        if ($password !== $confirm) {
            $errors['confirm_password'] = 'Passwords do not match.';
        }

        return $errors;
    }

    public static function beneficiary(array $data): array
    {
        $errors = [];
        if (trim($data['fullname'] ?? '') === '') {
            $errors['fullname'] = 'Beneficiary full name is required.';
        }
        if (trim($data['relationship'] ?? '') === '') {
            $errors['relationship'] = 'Relationship is required.';
        }
        if (trim($data['contact_number'] ?? '') === '') {
            $errors['contact_number'] = 'Contact number is required.';
        }
        if (trim($data['address'] ?? '') === '') {
            $errors['address'] = 'Address is required.';
        }
        return $errors;
    }
}
