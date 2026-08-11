<?php
require_once __DIR__ . '/../config/config.php';

if (!empty($_SESSION['admin'])) {
    log_activity($pdo, $_SESSION['admin']['id'], 'admin_logout');
}
unset($_SESSION['admin']);

redirect('/admin/login.php');
