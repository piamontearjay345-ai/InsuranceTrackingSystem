<?php
require_once __DIR__ . '/../app/bootstrap.php';
use App\Services\SupabaseClient;
$db = new SupabaseClient();
$res = $db->from('users', 'GET', null, 'select=id,email,role&role=eq.student&is_deleted=eq.false', true);
var_export($res);
echo PHP_EOL;
