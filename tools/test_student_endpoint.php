<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/Controllers/AdminController.php';

use App\Controllers\AdminController;

session_start();
$_SESSION['user_id'] = 'test';
$_SESSION['user'] = ['id' => 'test', 'role' => 'admin', 'fullname' => 'Test Admin'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['page'] = 1;
$_GET['limit'] = 10;
$controller = new AdminController();
$controller->students();
