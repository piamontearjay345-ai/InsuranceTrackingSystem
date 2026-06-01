<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/Services/SupabaseClient.php';

use App\Services\SupabaseClient;

$db = new SupabaseClient();
$query = 'select=id,student_id,fullname,email,username,created_at,beneficiaries(status,updated_at,fullname,relationship,contact_number,address)&role=eq.student&is_deleted=eq.false&order=fullname.asc&limit=10&offset=0';
$res = $db->from('users', 'GET', null, $query, true);
var_export($res);
