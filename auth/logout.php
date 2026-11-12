<?php
session_start();
require '../config/database.php';
require '../config/security.php';

if (isset($_SESSION['user_id'])) {
    clear_user_session($pdo, $_SESSION['user_id']);
}

session_unset();
session_destroy();
header("Location: login.php?logged_out=1");
exit();
?>
