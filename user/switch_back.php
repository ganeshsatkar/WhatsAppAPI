<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

if (!empty($_SESSION['superadmin_orig_id'])) {
    $origId = (int)$_SESSION['superadmin_orig_id'];
    $admin = db()->row('SELECT * FROM users WHERE id = ? AND role = "superadmin"', [$origId]);
    if ($admin) {
        $_SESSION['user_id']   = $admin['id'];
        $_SESSION['user_role'] = $admin['role'];
        $_SESSION['user_name'] = $admin['name'];
    }
    unset($_SESSION['superadmin_orig_id']);
}
unset($_SESSION['admin_view_uid']);
header('Location: /user/admin_users.php');
exit;
