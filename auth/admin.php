<?php
session_start();
require '../config/database.php';
require '../config/logger.php';
require '../config/security.php';

if (isset($_SESSION['user_id'])) {
    if ($_SESSION['user_type'] == 'Admin') {
        header("Location: ../admin/dashboard.php");
    } else {
        header("Location: ../user/dashboard.php");
    }
    exit();
}

$error = '';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!check_rate_limit($pdo, 'login_admin', 5, 300)) {
        http_response_code(429);
        $error = "Terlalu banyak percobaan login admin. Mohon tunggu 5 menit sebelum mencoba kembali.";
    } else {
        $login_id = trim($_POST['login_id'] ?? '');
        $password = $_POST['password'] ?? '';

        $stmt = $pdo->prepare("SELECT * FROM users WHERE (email = ? OR username = ?) AND user_type = 'Admin' LIMIT 1");
        $stmt->execute([$login_id, $login_id]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            if ($user['status'] == 'Nonaktif') {
                $error = "Akun Administrator Anda dinonaktifkan.";
            } else {
                reset_rate_limit($pdo, 'login_admin');
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['user_type'] = $user['user_type'];
                
                logActivity($pdo, $user['id'], "Administrator {$user['name']} berhasil login.");
                header("Location: ../admin/dashboard.php");
                exit();
            }
        } else {
            $error = "Username/Email atau Password Administrator salah!";
        }
    }
}
?>
<?php include '../includes/header.php'; ?>

<div class="position-fixed top-0 end-0 p-3 z-3">
    <button type="button" class="btn btn-outline-secondary theme-toggle-btn shadow-sm" aria-label="Ganti Tema" title="Ganti Tema (Light / Dark)">
        <i class="fas fa-moon theme-icon-dark"></i>
        <i class="fas fa-sun theme-icon-light text-warning d-none"></i>
    </button>
</div>

<div class="container my-auto py-5 flex-grow-1 d-flex flex-column justify-content-center">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card shadow-sm border-danger rounded-3">
                <div class="card-body p-4">
                    <h3 class="text-center mb-4 text-danger fw-bold"><i class="fas fa-user-shield me-2"></i>LOGIN ADMIN</h3>
                    <?php if($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Username / Email Admin</label>
                            <input type="text" name="login_id" class="form-control" required autofocus>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-danger w-100">Masuk Sebagai Admin</button>
                    </form>
                    <div class="mt-3 text-center">
                        <small><a href="login.php" class="text-decoration-none text-secondary">← Kembali ke Login User</a></small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>