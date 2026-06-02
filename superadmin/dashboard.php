<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';
use App\Services\AuthService;

$auth = new AuthService();
$user = $auth->currentUser();
if (!$user) {
  header('Location: ../login.html');
  exit;
}
if (($user['role'] ?? '') !== 'superadmin') {
  $target = ($user['role'] ?? '') === 'admin' ? '../admin/dashboard.html' : '../student/dashboard.html';
  header('Location: ' . $target);
  exit;
}
$role = 'superadmin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Superadmin Dashboard - Insurance Tracking</title>
  <link rel="stylesheet" href="../styles.css?v=20260608">
  <link rel="stylesheet" href="../assets/css/app.css?v=20260608">
  <link rel="stylesheet" href="../assets/css/responsive.css?v=20260608">
  <link rel="stylesheet" href="../assets/css/dashboard.css?v=20260608">
</head>
<body class="page-dashboard-fullscreen dashboard-app">
  <div class="top-bar">
    <div class="top-bar-left">Eastern Visayas State University - Ormoc City Campus</div>
    <div class="top-bar-right">
      <span id="admin-name">Super Admin</span>
      <span id="role-badge" class="role-badge">superadmin</span>
      <span>|</span>
      <a href="#" onclick="logout(); return false;">Logout</a>
    </div>
  </div>

  <header class="main-header">
    <div class="logo-title">
      <img src="../EVSU_Official_Logo.png" alt="EVSU Logo" class="logo-image" onerror="this.style.display='none'">
      <div class="title-text">
        <h1>EVSU-OCC</h1>
        <p>Student Insurance Tracking - Super Admin Dashboard</p>
      </div>
    </div>
  </header>

  <div class="dashboard-mobile-toolbar">
    <button type="button" class="dashboard-menu-toggle" id="dashboard-menu-toggle" aria-label="Open menu" aria-expanded="false" aria-controls="dashboard-sidebar">
      <span class="dashboard-menu-toggle-bar"></span>
      <span class="dashboard-menu-toggle-bar"></span>
      <span class="dashboard-menu-toggle-bar"></span>
    </button>
    <span class="dashboard-mobile-toolbar-label">Super Admin Menu</span>
  </div>
  <div class="dashboard-nav-overlay" id="dashboard-nav-overlay" hidden></div>

  <div class="main-layout dashboard-main-layout">
    <aside class="sidebar dashboard-sidebar" id="dashboard-sidebar">
      <div class="nav-section">
        <div class="nav-section-title">Super Admin Menu</div>
        <a href="#" class="nav-item active" data-panel="overview"><span class="nav-icon">#</span><span>Dashboard</span></a>
        <a href="#" class="nav-item" data-panel="students"><span class="nav-icon">S</span><span>Student Records</span></a>
        <a href="#" class="nav-item" data-panel="beneficiary-requests"><span class="nav-icon">B</span><span>Beneficiary Update Requests</span></a>
        <a href="#" class="nav-item" data-panel="admins"><span class="nav-icon">A</span><span>Admin Management</span></a>
        <a href="#" class="nav-item" data-panel="users"><span class="nav-icon">U</span><span>User Management</span></a>
        <a href="#" class="nav-item" data-panel="notifications"><span class="nav-icon">N</span><span>Notifications</span></a>
        <a href="#" class="nav-item" data-panel="login-history"><span class="nav-icon">L</span><span>Login History</span></a>
        <a href="#" class="nav-item" data-panel="activity"><span class="nav-icon">X</span><span>Activity Logs</span></a>
        <a href="#" class="nav-item" data-panel="reports"><span class="nav-icon">R</span><span>Reports</span></a>
        <a href="#" class="nav-item" data-panel="settings"><span class="nav-icon">S</span><span>Settings</span></a>
      </div>
    </aside>

    <div class="main-content">
      <div id="alert-box"></div>
      <header class="dashboard-page-header">
        <h1 class="content-header">Super Admin Dashboard</h1>
        <p class="dashboard-page-subtitle">Full system control: users, admins, reports, settings, and audit logs.</p>
      </header>

      <div id="panel-overview">
        <div class="stats-container stats-container--wide">
          <div class="stat-card" data-student-filter="" role="button" tabindex="0">
            <div class="stat-label">Total Students</div>
            <div class="stat-number" id="stat-total">0</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Total Admins</div>
            <div class="stat-number" id="stat-admins">0</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Total Beneficiaries</div>
            <div class="stat-number" id="stat-beneficiaries">0</div>
          </div>
          <div class="stat-card" data-student-filter="Updated" role="button" tabindex="0">
            <div class="stat-label">Updated Records</div>
            <div class="stat-number" id="stat-updated">0</div>
          </div>
          <div class="stat-card" data-student-filter="Not Updated" role="button" tabindex="0">
            <div class="stat-label">Not Updated Records</div>
            <div class="stat-number" id="stat-not-updated">0</div>
          </div>
        </div>
      </div>

      <div id="panel-students" class="hidden">
        <div class="toolbar">
          <input type="search" id="search-students" placeholder="Search name, email, ID...">
          <select id="filter-status">
            <option value="">All statuses</option>
            <option value="Updated">Updated</option>
            <option value="Update Beneficiary">Update Beneficiary</option>
            <option value="Not Updated">Not Updated</option>
          </select>
          <button type="button" class="btn btn-primary" id="btn-search">Search</button>
        </div>
        <div class="table-scroll">
          <table class="table">
            <thead>
              <tr>
                <th>Student ID</th>
                <th>Name</th>
                <th>Email</th>
                <th>Beneficiary</th>
                <th>Status</th>
                <th>Last Update</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="students-tbody"></tbody>
          </table>
        </div>
        <ul class="pagination" id="students-pagination"></ul>
      </div>

      <div id="panel-admins" class="hidden">
        <div class="toolbar">
          <button type="button" class="btn btn-primary" id="btn-show-create-admin">Create New Admin</button>
          <button type="button" class="btn" id="btn-show-reset-password">Reset Admin Password</button>
        </div>
        <div id="create-admin-card" class="panel-card hidden">
          <h3>Create Admin Account</h3>
          <div class="form-grid">
            <div class="form-group">
              <label for="admin-fullname">Full Name</label>
              <input type="text" id="admin-fullname" class="form-control">
            </div>
            <div class="form-group">
              <label for="admin-email">Email</label>
              <input type="email" id="admin-email" class="form-control">
            </div>
            <div class="form-group">
              <label for="admin-username">Username</label>
              <input type="text" id="admin-username" class="form-control">
            </div>
            <div class="form-group">
              <label for="admin-password">Password</label>
              <input type="password" id="admin-password" class="form-control">
            </div>
            <div class="form-group">
              <label for="admin-role">Role</label>
              <select id="admin-role" class="form-control">
                <option value="admin">Admin</option>
                <option value="superadmin">Superadmin</option>
              </select>
            </div>
          </div>
          <button type="button" class="btn btn-primary" id="create-admin-btn">Create Admin</button>
        </div>
        <div id="reset-admin-card" class="panel-card hidden">
          <h3>Reset Admin Password</h3>
          <div class="form-group full-width">
            <label for="reset-admin-email">Admin Email</label>
            <input type="email" id="reset-admin-email" class="form-control">
          </div>
          <button type="button" class="btn btn-primary" id="reset-admin-btn">Send Reset Link</button>
        </div>
        <div class="table-scroll">
          <table class="table permissions-table">
            <thead>
              <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Permissions</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="admins-tbody"></tbody>
          </table>
        </div>
      </div>

      <div id="panel-beneficiary-requests" class="hidden">
        <div class="toolbar">
          <input type="search" id="search-beneficiary-requests" placeholder="Search student, email, ID...">
          <button type="button" class="btn btn-primary" id="btn-beneficiary-request-search">Search</button>
          <button type="button" class="btn btn-primary" id="btn-send-all-beneficiary-requests">Send Notification to All</button>
        </div>
        <div class="table-scroll">
          <table class="table">
            <thead>
              <tr>
                <th>Student ID</th>
                <th>Student Name</th>
                <th>Email Address</th>
                <th>Current Status</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody id="beneficiary-requests-tbody"></tbody>
          </table>
        </div>
      </div>

      <div id="panel-users" class="hidden">
        <div class="toolbar">
          <input type="search" id="search-users" placeholder="Search users...">
          <select id="filter-user-role">
            <option value="">All roles</option>
            <option value="student">Students</option>
            <option value="admin">Admins</option>
            <option value="superadmin">Superadmins</option>
          </select>
          <button type="button" class="btn btn-primary" id="btn-user-search">Search</button>
        </div>
        <div class="table-scroll">
          <table class="table permissions-table">
            <thead>
              <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Permissions</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="users-tbody"></tbody>
          </table>
        </div>
      </div>

      <div id="panel-notifications" class="hidden">
        <div class="table-scroll">
        <table class="table">
          <thead><tr><th>User</th><th>Title</th><th>Status</th><th>Date</th></tr></thead>
          <tbody id="notifications-tbody"></tbody>
        </table>
        </div>
      </div>

      <div id="panel-login-history" class="hidden">
        <div class="table-scroll">
        <table class="table">
          <thead><tr><th>Email</th><th>Status</th><th>Role</th><th>IP</th><th>Date</th></tr></thead>
          <tbody id="login-tbody"></tbody>
        </table>
        </div>
      </div>

      <div id="panel-activity" class="hidden">
        <div class="table-scroll">
        <table class="table">
          <thead><tr><th>Action</th><th>Record</th><th>Severity</th><th>IP</th><th>Date</th></tr></thead>
          <tbody id="activity-tbody"></tbody>
        </table>
        </div>
      </div>

      <div id="panel-reports" class="hidden">
        <div class="dashboard-content-scroll">
          <div class="panel-card reports-card">
            <h3>Report Tools</h3>
            <p class="muted">Export student data as CSV or print a report. CSV files open in Excel; print mode can save as PDF.</p>
            <div class="reports-actions">
              <button type="button" class="btn btn-primary" id="btn-export-students">Export Students (CSV)</button>
              <button type="button" class="btn btn-secondary" id="btn-print-report">Print Report</button>
            </div>
          </div>
        </div>
      </div>

      <div id="panel-settings" class="hidden">
        <div class="dashboard-content-scroll">
        <div class="panel-card">
          <h3>System Settings</h3>
          <div class="form-grid">
            <div class="form-group">
              <label for="setting-system-name">System Name</label>
              <input type="text" id="setting-system-name" class="form-control">
            </div>
            <div class="form-group">
              <label for="setting-email-sender">Email Sender</label>
              <input type="text" id="setting-email-sender" class="form-control">
            </div>
            <div class="form-group">
              <label for="setting-email-subject">Notification Subject</label>
              <input type="text" id="setting-email-subject" class="form-control">
            </div>
            <div class="form-group">
              <label for="setting-admin-contact">Support Contact</label>
              <input type="text" id="setting-admin-contact" class="form-control">
            </div>
          </div>
          <button type="button" class="btn btn-primary" id="save-settings-btn">Save Settings</button>
        </div>
        </div>
      </div>
    </div>
  </div>

  <div class="modal-overlay" id="editModal">
    <div class="modal-box">
      <div class="modal-header">
        <h3>Edit Beneficiary</h3>
        <button type="button" class="modal-close" id="modal-close">&times;</button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="edit-user-id">
        <div class="form-group full-width">
          <label>Full Name</label>
          <input type="text" id="edit-fullname" class="form-control">
        </div>
        <div class="form-group full-width">
          <label>Relationship</label>
          <input type="text" id="edit-relationship" class="form-control">
        </div>
        <div class="form-group full-width">
          <label>Contact</label>
          <input type="text" id="edit-contact" class="form-control">
        </div>
        <div class="form-group full-width">
          <label>Address</label>
          <textarea id="edit-address" rows="2" class="form-control"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn" id="modal-cancel">Cancel</button>
        <button type="button" class="btn btn-primary" id="save-edit-btn">Save</button>
      </div>
    </div>
  </div>

  <div class="loading-overlay" id="loading"><div class="loading-spinner"></div></div>

  <script src="../assets/js/api.js?v=20260608"></script>
  <script src="../assets/js/auth.js?v=20260608"></script>
  <script>
    window.SUPERADMIN_PAGE = true;
  </script>
  <script src="../assets/js/dashboard-mobile.js?v=20260608"></script>
  <script src="../assets/js/admin-dashboard.js?v=20260608"></script>
</body>
</html>
