<?php
session_start();
require '../config/database.php';
require '../config/security.php';

date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] == 'Admin') {
    header("Location: ../index.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
enforce_user_ownership($user_id);
verify_single_device_session($pdo);

// PROSES UPDATE EDIT PROFIL
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $name         = trim($_POST['name']);
    $username     = strtolower(trim($_POST['username'] ?? ''));
    $email        = trim($_POST['email']);
    $phone        = trim($_POST['phone']);
    $gender       = $_POST['gender'] ?? '';
    $institution  = trim($_POST['institution']);
    $department   = trim($_POST['department']);

    if (!preg_match('/^[a-z0-9._]{3,30}$/', $username)) {
        $_SESSION['error'] = "Username harus 3-30 karakter dan hanya boleh berisi huruf, angka, titik, atau garis bawah.";
    } elseif (!in_array($gender, ['Laki-laki', 'Perempuan'], true)) {
        $_SESSION['error'] = "Jenis kelamin wajib dipilih.";
    } else {
        try {
            // Pastikan username & email belum dipakai pengguna lain
            $cek = $pdo->prepare("SELECT username, email FROM users WHERE (username = ? OR email = ?) AND id != ?");
            $cek->execute([$username, $email, $user_id]);
            $bentrok = $cek->fetch();

            if ($bentrok && $bentrok['username'] === $username) {
                $_SESSION['error'] = "Username @" . $username . " sudah dipakai pengguna lain.";
            } elseif ($bentrok) {
                $_SESSION['error'] = "Email tersebut sudah dipakai pengguna lain.";
            } else {
                $stmt = $pdo->prepare("UPDATE users SET name = ?, username = ?, email = ?, phone = ?, gender = ?, institution = ?, department = ? WHERE id = ?");
                $stmt->execute([$name, $username, $email, $phone, $gender, $institution, $department, $user_id]);

                $_SESSION['name'] = $name; // Update session nama
                $_SESSION['pesan'] = "Profil berhasil diperbarui!";
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = "Gagal memperbarui profil: " . $e->getMessage();
        }
    }
    header("Location: profile.php");
    exit();
}

// PROSES UBAH KATA SANDI
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $pass_lama  = $_POST['pass_lama'];
    $pass_baru  = $_POST['pass_baru'];
    $konfirmasi = $_POST['konfirmasi_pass'];

    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user_pass = $stmt->fetchColumn();

    if (!password_verify($pass_lama, $user_pass)) {
        $_SESSION['error'] = "Kata sandi lama Anda salah!";
    } elseif ($pass_baru !== $konfirmasi) {
        $_SESSION['error'] = "Konfirmasi kata sandi baru tidak cocok!";
    } else {
        $hash_baru = password_hash($pass_baru, PASSWORD_BCRYPT);
        $stmt_update = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt_update->execute([$hash_baru, $user_id]);
        $_SESSION['pesan'] = "Kata sandi berhasil diubah!";
    }
    header("Location: profile.php");
    exit();
}

// AMBIL DATA USER TERKINI
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/navbar.php'; ?>

<div class="container px-3 px-sm-4 pb-5 flex-grow-1 profile-wrap">
    
    <!-- ALERT PESAN SUKSES / ERROR -->
    <?php if(isset($_SESSION['pesan'])): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-3 mt-3">
            <i class="fas fa-check-circle me-2"></i><?= $_SESSION['pesan']; unset($_SESSION['pesan']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if(isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-3 mt-3">
            <i class="fas fa-exclamation-circle me-2"></i><?= $_SESSION['error']; unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- TOMBOL KEMBALI & HEADER -->
    <div class="d-flex align-items-center flex-wrap gap-2 gap-sm-3 my-3 my-sm-4">
        <a href="dashboard.php" class="btn btn-outline-secondary rounded-2 px-3 py-1 shadow-sm small flex-shrink-0">
            <i class="fas fa-arrow-left me-1"></i> Kembali
        </a>
        <h4 class="fw-bold mb-0 text-dark profile-title"><i class="fas fa-user-circle text-primary me-2"></i>Profil Pengguna</h4>
    </div>

    <!-- KARTU DETAIL PROFIL USER -->
    <div class="card shadow-sm p-3 p-sm-4 text-center mb-4">
        <div class="mb-3">
            <div class="profile-avatar rounded-circle bg-primary bg-opacity-10 text-primary d-inline-flex align-items-center justify-content-center shadow-sm">
                <i class="fas fa-user-alt"></i>
            </div>
        </div>
        
        <h4 class="fw-bold text-dark mb-1 text-break profile-name"><?= htmlspecialchars($user['name']) ?></h4>
        <p class="text-muted small mb-2 text-break">@<?= htmlspecialchars($user['username']) ?></p>
        <div>
            <span class="badge badge-subdued-primary px-3 py-1.5 small"><?= htmlspecialchars($user['user_type']) ?></span>
        </div>

        <hr class="my-4 text-muted opacity-25">

        <div class="text-start profile-list">
            <div class="profile-row">
                <div class="profile-label"><i class="fas fa-venus-mars text-primary me-2"></i>Jenis Kelamin</div>
                <div class="profile-value"><?= htmlspecialchars(!empty($user['gender']) ? $user['gender'] : '-') ?></div>
            </div>
            <div class="profile-row">
                <div class="profile-label"><i class="fas fa-envelope text-primary me-2"></i>Email</div>
                <div class="profile-value"><?= htmlspecialchars(!empty($user['email']) ? $user['email'] : '-') ?></div>
            </div>
            <div class="profile-row">
                <div class="profile-label"><i class="fas fa-phone text-primary me-2"></i>No. Telepon</div>
                <div class="profile-value"><?= htmlspecialchars(!empty($user['phone']) ? $user['phone'] : '-') ?></div>
            </div>
            <div class="profile-row">
                <div class="profile-label"><i class="fas fa-university text-primary me-2"></i>Instansi / Sekolah</div>
                <div class="profile-value"><?= htmlspecialchars(!empty($user['institution']) ? $user['institution'] : '-') ?></div>
            </div>
            <div class="profile-row">
                <div class="profile-label"><i class="fas fa-th-large text-primary me-2"></i>Jurusan / Divisi</div>
                <div class="profile-value"><?= htmlspecialchars(!empty($user['department']) ? $user['department'] : '-') ?></div>
            </div>
        </div>

        <!-- TOMBOL PENGATURAN PROFIL -->
        <div class="d-grid gap-2 mt-3 mt-sm-4">
            <button type="button" class="btn btn-outline-primary py-2 fw-semibold" data-bs-toggle="modal" data-bs-target="#modalEditProfil">
                <i class="fas fa-user-edit me-1"></i> Edit Profil
            </button>
            <button type="button" class="btn btn-outline-secondary py-2 fw-semibold" data-bs-toggle="modal" data-bs-target="#modalUbahSandi">
                <i class="fas fa-key me-1"></i> Ubah Kata Sandi
            </button>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL POP-UP EDIT PROFIL                   -->
<!-- ========================================== -->
<div class="modal fade" id="modalEditProfil" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content border-0 shadow-lg modal-rounded">
            <form action="profile.php" method="POST" class="d-flex flex-column overflow-hidden h-100">
                <div class="modal-header border-bottom-0 pb-0 pt-3 pt-sm-4 px-3 px-sm-4">
                    <h5 class="modal-title fw-bold text-dark"><i class="fas fa-user-edit text-primary me-2"></i>Edit Profil</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-3 p-sm-4 text-start overflow-auto">
                    <input type="hidden" name="action" value="update_profile">
                    
                    <div class="mb-3">
                        <label class="form-label small text-secondary fw-semibold">Nama Lengkap</label>
                        <input type="text" name="name" class="form-control rounded-3 shadow-sm" value="<?= htmlspecialchars($user['name']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-secondary fw-semibold">Username</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white text-muted rounded-start-3">@</span>
                            <input type="text" name="username" class="form-control rounded-end-3 shadow-sm" value="<?= htmlspecialchars($user['username'] ?? '') ?>" required minlength="3" maxlength="30" pattern="[A-Za-z0-9._]{3,30}" autocapitalize="none" autocomplete="username" spellcheck="false">
                        </div>
                        <div class="form-text small">3-30 karakter: huruf, angka, titik, atau garis bawah.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-secondary fw-semibold">Email</label>
                        <input type="email" name="email" class="form-control rounded-3 shadow-sm" value="<?= htmlspecialchars($user['email'] ?? '') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-secondary fw-semibold">No. Telepon / WA</label>
                        <input type="text" name="phone" class="form-control rounded-3 shadow-sm" value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-secondary fw-semibold">Jenis Kelamin</label>
                        <select name="gender" class="form-select rounded-3 shadow-sm" required>
                            <option value="" <?= empty($user['gender']) ? 'selected' : '' ?>>-- Pilih --</option>
                            <option value="Laki-laki" <?= ($user['gender'] ?? '') === 'Laki-laki' ? 'selected' : '' ?>>Laki-laki</option>
                            <option value="Perempuan" <?= ($user['gender'] ?? '') === 'Perempuan' ? 'selected' : '' ?>>Perempuan</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-secondary fw-semibold">Instansi / Sekolah</label>
                        <input type="text" name="institution" class="form-control rounded-3 shadow-sm" value="<?= htmlspecialchars($user['institution'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-secondary fw-semibold">Jurusan / Divisi</label>
                        <input type="text" name="department" class="form-control rounded-3 shadow-sm" value="<?= htmlspecialchars($user['department'] ?? '') ?>">
                    </div>
                </div>
                <div class="modal-footer border-top-0 pt-0 pb-3 pb-sm-4 px-3 px-sm-4 gap-2">
                    <button type="button" class="btn btn-outline-secondary rounded-2 px-4 fw-semibold flex-fill flex-sm-grow-0" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary rounded-2 px-4 fw-semibold shadow-sm flex-fill flex-sm-grow-0">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL POP-UP UBAH KATA SANDI               -->
<!-- ========================================== -->
<div class="modal fade" id="modalUbahSandi" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content border-0 shadow-lg modal-rounded">
            <form action="profile.php" method="POST" class="d-flex flex-column overflow-hidden h-100">
                <div class="modal-header border-bottom-0 pb-0 pt-3 pt-sm-4 px-3 px-sm-4">
                    <h5 class="modal-title fw-bold text-dark"><i class="fas fa-key text-warning me-2"></i>Ubah Kata Sandi</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-3 p-sm-4 text-start overflow-auto">
                    <input type="hidden" name="action" value="change_password">
                    
                    <!-- KATA SANDI LAMA -->
                    <div class="mb-3">
                        <label class="form-label small text-secondary fw-semibold">Kata Sandi Lama <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" name="pass_lama" id="pass_lama" class="form-control rounded-start-3 shadow-sm border-end-0" required>
                            <button type="button" class="btn btn-outline-secondary border shadow-sm rounded-end-3" onclick="togglePassword('pass_lama', 'icon_lama')">
                                <i class="fas fa-eye text-muted" id="icon_lama"></i>
                            </button>
                        </div>
                    </div>

                    <!-- KATA SANDI BARU -->
                    <div class="mb-3">
                        <label class="form-label small text-secondary fw-semibold">Kata Sandi Baru <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" name="pass_baru" id="pass_baru" class="form-control rounded-start-3 shadow-sm border-end-0" required>
                            <button type="button" class="btn btn-outline-secondary border shadow-sm rounded-end-3" onclick="togglePassword('pass_baru', 'icon_baru')">
                                <i class="fas fa-eye text-muted" id="icon_baru"></i>
                            </button>
                        </div>
                    </div>

                    <!-- KONFIRMASI KATA SANDI BARU -->
                    <div class="mb-3">
                        <label class="form-label small text-secondary fw-semibold">Konfirmasi Kata Sandi Baru <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" name="konfirmasi_pass" id="konfirmasi_pass" class="form-control rounded-start-3 shadow-sm border-end-0" required>
                            <button type="button" class="btn btn-outline-secondary border shadow-sm rounded-end-3" onclick="togglePassword('konfirmasi_pass', 'icon_konfirmasi')">
                                <i class="fas fa-eye text-muted" id="icon_konfirmasi"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top-0 pt-0 pb-3 pb-sm-4 px-3 px-sm-4 gap-2">
                    <button type="button" class="btn btn-outline-secondary rounded-2 px-4 fw-semibold flex-fill flex-sm-grow-0" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-warning rounded-2 px-4 fw-semibold shadow-sm flex-fill flex-sm-grow-0">Update Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    .profile-wrap { max-width: 650px; }
    .profile-title { font-size: 1.25rem; }
    .profile-avatar { width: 90px; height: 90px; }
    .profile-avatar i { font-size: 2.5rem; }
    .profile-name { font-size: 1.35rem; }
    .modal-rounded { border-radius: 1rem; }

    .profile-row {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 16px;
        padding: 12px 0;
        border-bottom: 1px dashed rgba(100, 116, 139, 0.2);
    }
    .profile-row:last-child { border-bottom: 0; }
    .profile-label { color: var(--text-muted); font-weight: 600; font-size: 14.5px; flex: 0 0 auto; }
    .profile-value { font-weight: 700; color: var(--text-main); text-align: right; min-width: 0; overflow-wrap: anywhere; font-size: 14.5px; }

    @media (max-width: 575.98px) {
        .profile-title { font-size: 1.1rem; }
        .profile-avatar { width: 76px; height: 76px; }
        .profile-avatar i { font-size: 2rem; }
        .profile-name { font-size: 1.2rem; }
        .modal-rounded { border-radius: 0; }

        /* Label di atas, nilai di bawah agar teks panjang tidak terpotong */
        .profile-row { flex-direction: column; gap: 2px; padding: 10px 0; }
        .profile-label { font-size: 12.5px; }
        .profile-value { text-align: left; font-size: 14.5px; padding-left: 26px; }
        .form-control, .form-select { font-size: 16px; } /* cegah auto-zoom di iOS */
    }
</style>

<script>
// Fungsi Toggle Password Show/Hide
function togglePassword(inputId, iconId) {
    const inputField = document.getElementById(inputId);
    const icon = document.getElementById(iconId);

    if (inputField.type === "password") {
        inputField.type = "text";
        icon.classList.remove("fa-eye");
        icon.classList.add("fa-eye-slash");
    } else {
        inputField.type = "password";
        icon.classList.remove("fa-eye-slash");
        icon.classList.add("fa-eye");
    }
}
</script>

<?php include '../includes/footer.php'; ?>