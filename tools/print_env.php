<?php
require_once __DIR__ . '/../app/bootstrap.php';
echo 'SUPABASE_SERVICE_ROLE_KEY=' . (App\Config\Env::get('SUPABASE_SERVICE_ROLE_KEY', '(none)')) . PHP_EOL;
