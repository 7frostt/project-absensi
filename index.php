<?php
session_start();
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['user_type'] == 'Admin') {
        header("Location: admin/dashboard.php");
    } else {
        header("Location: user/dashboard.php");
    }
    exit();
} else {
    header("Location: auth/login.php");
    exit();
}
?>