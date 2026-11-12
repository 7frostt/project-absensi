<?php
session_start();
require '../config/database.php';
require '../config/security.php';
$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!check_rate_limit($pdo, 'login_user', 5, 300)) {
        http_response_code(429);
        $error = "Terlalu banyak percobaan login gagal. Mohon tunggu 5 menit sebelum mencoba kembali.";
    } else {
        $login_input = strtolower(trim($_POST['username_or_email'] ?? ''));
        $password = $_POST['password'] ?? '';

        $stmt = $pdo->prepare("SELECT * FROM users WHERE (email = ? OR username = ?) AND status = 'Aktif'");
        $stmt->execute([$login_input, $login_input]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // Anti-Joki / Single Device Lock: Cegah login bersamaan di perangkat lain
            if ($user['user_type'] !== 'Admin' && is_user_session_active($pdo, $user['id'])) {
                $error = "Akun ini sedang aktif di perangkat lain. Silakan logout terlebih dahulu dari perangkat tersebut sebelum dapat login di sini.";
            } else {
                reset_rate_limit($pdo, 'login_user');
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['user_type'] = $user['user_type'];

                // Kunci sesi perangkat aktif
                register_user_session($pdo, $user['id']);

                if ($user['user_type'] == 'Admin') {
                    header("Location: ../admin/dashboard.php");
                } else {
                    header("Location: ../user/dashboard.php");
                }
                exit();
            }
        } else {
            $error = "Username/Email atau Password salah, atau akun Anda nonaktif.";
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
            <div class="card shadow-sm border-0 rounded-3">
                <div class="card-body p-4">
                    <h3 class="text-center mb-4 fw-bold">Login Pengguna</h3>
                    
                    <?php if(isset($_GET['registered'])): ?>
                        <div class="alert alert-success">Pendaftaran berhasil! Silakan login.</div>
                    <?php endif; ?>

                    <?php if(isset($_GET['kicked'])): ?>
                        <div class="alert alert-warning"><i class="fas fa-exclamation-triangle me-1"></i> Sesi login Anda telah dialihkan atau akun Anda di-reset dari perangkat lain. Silakan login kembali.</div>
                    <?php endif; ?>

                    <?php if(isset($_GET['logged_out'])): ?>
                        <div class="alert alert-info"><i class="fas fa-info-circle me-1"></i> Anda telah berhasil logout. Perangkat Anda telah dilepas.</div>
                    <?php endif; ?>

                    <?php if($error): ?>
                        <div class="alert alert-danger"><?= $error ?></div>
                    <?php endif; ?>

                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Username atau Email</label>
                            <input type="text" name="username_or_email" class="form-control" required autofocus>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <div class="input-group">
                                <input type="password" name="password" id="login_password" class="form-control" required>
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('login_password', 'icon_login')">
                                    <i class="fas fa-eye" id="icon_login"></i>
                                </button>
                            </div>
                        </div>
                        
                        <!-- Tombol Login Pengguna -->
                        <button type="submit" class="btn btn-primary w-100 mb-2">Login</button>

                        <!-- Tombol Pindah ke Halaman Admin (Tepat di Bawah Tombol Login) -->
                        <a href="../auth/admin.php" class="btn btn-outline-secondary w-100">
                            <i class="fas fa-user-shield me-1"></i> Login Sebagai Admin
                        </a>
                    </form>

                    <div class="mt-3 text-center">
                        <small>Belum punya akun? <a href="register.php">Daftar di sini</a></small>
                    </div>

                </div>
            </div>
        </div>
    </div>
</div>

<script>
function togglePassword(inputId, iconId) {
    const passwordInput = document.getElementById(inputId);
    const toggleIcon = document.getElementById(iconId);
    
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        toggleIcon.classList.remove('fa-eye');
        toggleIcon.classList.add('fa-eye-slash');
    } else {
        passwordInput.type = 'password';
        toggleIcon.classList.remove('fa-eye-slash');
        toggleIcon.classList.add('fa-eye');
    }
}
</script>

<?php include '../includes/footer.php'; ?>