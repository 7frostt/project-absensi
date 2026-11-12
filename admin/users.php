<?php
session_start();
require '../config/database.php';
require '../config/logger.php';
require '../config/security.php';

enforce_admin_access();

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // Pengecekan username/email unik (abaikan user yang sedang diedit)
    $cek_unik = function ($username, $email, $exclude_id = 0) use ($pdo) {
        if (!preg_match('/^[a-z0-9._]{3,30}$/', $username)) {
            return "Username harus 3-30 karakter (huruf, angka, titik, atau garis bawah).";
        }
        $q = $pdo->prepare("SELECT username FROM users WHERE (username = ? OR email = ?) AND id != ?");
        $q->execute([$username, $email, $exclude_id]);
        $row = $q->fetch();
        if ($row) {
            return ($row['username'] === $username) ? "Username @$username sudah dipakai user lain." : "Email sudah dipakai user lain.";
        }
        return null;
    };

    try {
        if ($action == 'add') {
            $username = strtolower(trim($_POST['username']));
            $email    = trim($_POST['email']);
            if (!in_array($_POST['gender'] ?? '', ['Laki-laki', 'Perempuan'], true)) {
                $_SESSION['error'] = "Jenis kelamin wajib dipilih.";
            } elseif ($err = $cek_unik($username, $email)) {
                $_SESSION['error'] = $err;
            } else {
                $stmt = $pdo->prepare("INSERT INTO users (name, username, email, password, phone, gender, user_type, institution, department, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Aktif')");
                $stmt->execute([trim($_POST['name']), $username, $email, password_hash($_POST['password'], PASSWORD_DEFAULT), $_POST['phone'], $_POST['gender'], $_POST['user_type'], $_POST['institution'], $_POST['department']]);
                logActivity($pdo, $_SESSION['user_id'], "Menambahkan user baru: " . $_POST['name']);
                $_SESSION['pesan'] = "User ditambahkan.";
            }
        } elseif ($action == 'edit') {
            $username = strtolower(trim($_POST['username']));
            $email    = trim($_POST['email']);
            $status   = in_array($_POST['status'], ['Aktif', 'Nonaktif'], true) ? $_POST['status'] : 'Aktif';
            if (!in_array($_POST['gender'] ?? '', ['Laki-laki', 'Perempuan'], true)) {
                $_SESSION['error'] = "Jenis kelamin wajib dipilih.";
            } elseif ($err = $cek_unik($username, $email, (int)$_POST['id'])) {
                $_SESSION['error'] = $err;
            } else {
                $stmt = $pdo->prepare("UPDATE users SET name=?, username=?, email=?, phone=?, gender=?, user_type=?, institution=?, department=?, status=? WHERE id=? AND user_type != 'Admin'");
                $stmt->execute([trim($_POST['name']), $username, $email, $_POST['phone'], $_POST['gender'], $_POST['user_type'], $_POST['institution'], $_POST['department'], $status, (int)$_POST['id']]);
                logActivity($pdo, $_SESSION['user_id'], "Memperbarui data user: " . $_POST['name']);
                $_SESSION['pesan'] = "User diperbarui.";
            }
        } elseif ($action == 'change_password') {
            $new_pass = $_POST['new_password'];
            $confirm_pass = $_POST['confirm_password'];

            if ($new_pass === '' ) {
                $_SESSION['error'] = "Password baru tidak boleh kosong!";
            } elseif ($new_pass === $confirm_pass) {
                $stmt = $pdo->prepare("UPDATE users SET password=? WHERE id=? AND user_type != 'Admin'");
                $stmt->execute([password_hash($new_pass, PASSWORD_DEFAULT), (int)$_POST['id']]);
                logActivity($pdo, $_SESSION['user_id'], "Mengubah password user ID: " . (int)$_POST['id']);
                $_SESSION['pesan'] = "Password user berhasil diubah.";
            } else {
                $_SESSION['error'] = "Konfirmasi password tidak cocok!";
            }
        } elseif ($action == 'delete') {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id=? AND user_type != 'Admin'");
            $stmt->execute([(int)$_POST['id']]);
            logActivity($pdo, $_SESSION['user_id'], "Menghapus user ID: " . (int)$_POST['id']);
            $_SESSION['pesan'] = "User dihapus.";
        } elseif ($action == 'reset_session') {
            clear_user_session($pdo, (int)$_POST['id']);
            logActivity($pdo, $_SESSION['user_id'], "Mereset kunci sesi perangkat user ID: " . (int)$_POST['id']);
            $_SESSION['pesan'] = "Sesi login perangkat user berhasil di-reset. User kini dapat login di perangkat baru.";
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Terjadi kesalahan database: " . $e->getMessage();
    }
    header("Location: users.php");
    exit();
}

// Lencana jenis kelamin (dipakai di daftar user)
function badge_jk($g) {
    if ($g === 'Laki-laki')  return '<span class="badge border" style="background-color: rgba(13,110,253,.08); color: #0d6efd;"><i class="fas fa-mars me-1"></i>Laki-laki</span>';
    if ($g === 'Perempuan') return '<span class="badge border" style="background-color: rgba(214,51,132,.08); color: #d63384;"><i class="fas fa-venus me-1"></i>Perempuan</span>';
    return '<span class="text-muted">-</span>';
}

// FITUR FILTER & PENCARIAN (VIA USERNAME, EMAIL, ATAU NAMA)
$filter_type = isset($_GET['type']) ? $_GET['type'] : '';
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

$query = "SELECT * FROM users WHERE user_type != 'Admin'";
$params = [];

if ($filter_type) { 
    $query .= " AND user_type = ?"; 
    $params[] = $filter_type; 
}

if ($search_query) {
    $query .= " AND (username LIKE ? OR email LIKE ? OR name LIKE ?)";
    $search_param = '%' . $search_query . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$query .= " ORDER BY name ASC";
$stmt = $pdo->prepare($query); 
$stmt->execute($params); 
$users = $stmt->fetchAll();
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/navbar.php'; ?>

<div class="container pb-5 flex-grow-1">
    <!-- HEADER BAR: TITLE -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
        <h4 class="fw-bold m-0"><i class="fas fa-users text-primary me-2"></i>Data User</h4>
    </div>
    
    <!-- NOTIFIKASI PESAN/ERROR -->
    <?php if(isset($_SESSION['pesan'])): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-3">
            <i class="fas fa-check-circle me-1"></i><?= $_SESSION['pesan']; unset($_SESSION['pesan']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if(isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-3">
            <i class="fas fa-exclamation-circle me-1"></i><?= $_SESSION['error']; unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm mb-4">
        <div class="card-body p-4">
            <!-- FORM FILTER, SEARCH BAR, DAN TOMBOL AKSI -->
            <form method="GET" class="row g-2 mb-4 align-items-center">
                <!-- Dropdown Filter -->
                <div class="col-lg-3 col-md-4 col-12">
                    <select name="type" class="form-select" onchange="this.form.submit()">
                        <option value="">Semua Jenis User</option>
                        <option value="Siswa PKL" <?= $filter_type=='Siswa PKL'?'selected':'' ?>>Siswa PKL</option>
                        <option value="Mahasiswa Magang" <?= $filter_type=='Mahasiswa Magang'?'selected':'' ?>>Mahasiswa Magang</option>
                        <option value="Karyawan" <?= $filter_type=='Karyawan'?'selected':'' ?>>Karyawan</option>
                    </select>
                </div>
                
                <!-- Kolom Search -->
                <div class="col-lg-4 col-md-8 col-12">
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" name="search" class="form-control border-start-0" placeholder="Cari Nama, Username, atau Email..." value="<?= htmlspecialchars($search_query) ?>">
                        <button type="submit" class="btn btn-primary px-3">Cari</button>
                    </div>
                </div>
                
                <!-- Kumpulan Tombol Aksi -->
                <div class="col-lg-5 col-12 d-flex flex-wrap justify-content-lg-end gap-2 mt-3 mt-lg-0">
                    <?php if($filter_type || $search_query): ?>
                        <a href="users.php" class="btn btn-outline-secondary d-flex align-items-center"><i class="fas fa-redo me-1"></i> Reset</a>
                    <?php endif; ?>
                    <a href="export_users.php?format=excel&type=<?= urlencode($filter_type) ?>&search=<?= urlencode($search_query) ?>" class="btn btn-success rounded-3 px-3 text-nowrap shadow-sm d-flex align-items-center">
                        <i class="fas fa-file-excel me-1"></i> Excel
                    </a>
                    <a href="export_users.php?format=print&type=<?= urlencode($filter_type) ?>&search=<?= urlencode($search_query) ?>" target="_blank" class="btn btn-danger rounded-3 px-3 text-nowrap shadow-sm d-flex align-items-center">
                        <i class="fas fa-file-pdf me-1"></i> PDF
                    </a>
                    <button type="button" class="btn btn-primary rounded-3 px-3 shadow-sm d-flex align-items-center" data-bs-toggle="modal" data-bs-target="#modalAdd">
                        <i class="fas fa-plus me-1"></i> Tambah User
                    </button>
                </div>
            </form>

            <!-- TABEL DATA PENGGUNA -->
            <div class="table-responsive">
                <table class="table table-hover align-middle border mb-0">
                    <thead class="table-light text-uppercase text-muted" style="font-size: 13px; letter-spacing: 0.5px;">
                        <tr>
                            <th class="ps-3 py-3">NO</th>
                            <th class="py-3">NAMA & USERNAME</th>
                            <th class="py-3">JK</th>
                            <th class="py-3">EMAIL</th>
                            <th class="py-3">JENIS</th>
                            <th class="py-3">INSTANSI / DIVISI</th>
                            <th class="py-3">STATUS</th>
                            <th class="text-center py-3">AKSI</th>
                        </tr>
                    </thead>
                    <tbody style="font-size: 14px;">
                        <?php foreach($users as $i => $u): ?>
                        <tr>
                            <td class="ps-3"><?= $i+1 ?></td>
                            <td>
                                <div class="fw-bold text-dark"><?= htmlspecialchars($u['name']) ?></div>
                                <small class="text-muted">@<?= htmlspecialchars($u['username']) ?></small>
                            </td>
                            <td class="text-nowrap"><?= badge_jk($u['gender'] ?? null) ?></td>
                            <td><?= htmlspecialchars($u['email']) ?></td>
                            <td><span class="badge bg-light text-primary border px-2 py-1"><?= htmlspecialchars($u['user_type']) ?></span></td>
                            <td>
                                <div><?= htmlspecialchars($u['institution']) ?></div>
                                <small class="text-muted"><?= htmlspecialchars($u['department']) ?></small>
                            </td>
                            <td><span class="badge bg-<?= $u['status']=='Aktif'?'success':'danger' ?>"><?= strtoupper($u['status']) ?></span></td>
                            <td class="text-center">
                                <div class="d-flex justify-content-center gap-1">
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Reset sesi login perangkat user ini? User dapat login di perangkat baru.');">
                                        <input type="hidden" name="action" value="reset_session">
                                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                        <button class="btn btn-sm btn-outline-secondary shadow-sm" title="Reset Kunci Perangkat"><i class="fas fa-mobile-alt"></i></button>
                                    </form>
                                    <button class="btn btn-sm btn-warning shadow-sm" data-bs-toggle="modal" data-bs-target="#modalEdit<?= $u['id'] ?>" title="Edit User"><i class="fas fa-edit"></i></button>
                                    <button class="btn btn-sm btn-info text-white shadow-sm" data-bs-toggle="modal" data-bs-target="#modalPassword<?= $u['id'] ?>" title="Ubah Password"><i class="fas fa-key"></i></button>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Yakin ingin menghapus user ini?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                        <button class="btn btn-sm btn-danger shadow-sm" title="Hapus User"><i class="fas fa-trash"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>

                        <!-- Modal Edit User -->
                        <div class="modal fade" id="modalEdit<?= $u['id'] ?>" tabindex="-1">
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content border shadow-sm rounded-3">
                                    <form method="POST">
                                        <div class="modal-header border-bottom-0 pb-0">
                                            <h5 class="fw-bold"><i class="fas fa-user-edit text-primary me-2"></i>Edit User</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body p-4">
                                            <input type="hidden" name="action" value="edit">
                                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                            <div class="mb-3"><label class="form-label small text-muted fw-semibold">Nama Lengkap</label><input type="text" name="name" class="form-control" value="<?= htmlspecialchars($u['name']) ?>" required></div>
                                            <div class="mb-3"><label class="form-label small text-muted fw-semibold">Username</label><input type="text" name="username" class="form-control" value="<?= htmlspecialchars($u['username']) ?>" required></div>
                                            <div class="mb-3"><label class="form-label small text-muted fw-semibold">Email</label><input type="email" name="email" class="form-control" value="<?= htmlspecialchars($u['email']) ?>" required></div>
                                            <div class="mb-3"><label class="form-label small text-muted fw-semibold">No. Telepon</label><input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($u['phone']) ?>" required></div>
                                            <div class="mb-3"><label class="form-label small text-muted fw-semibold">Jenis Kelamin</label>
                                                <select name="gender" class="form-select" required>
                                                    <option value="" <?= empty($u['gender'])?'selected':'' ?>>-- Pilih --</option>
                                                    <option value="Laki-laki" <?= ($u['gender'] ?? '')=='Laki-laki'?'selected':'' ?>>Laki-laki</option>
                                                    <option value="Perempuan" <?= ($u['gender'] ?? '')=='Perempuan'?'selected':'' ?>>Perempuan</option>
                                                </select>
                                            </div>
                                            <div class="mb-3"><label class="form-label small text-muted fw-semibold">Jenis Pengguna</label>
                                                <select name="user_type" class="form-select">
                                                    <option value="Siswa PKL" <?= $u['user_type']=='Siswa PKL'?'selected':'' ?>>Siswa PKL</option>
                                                    <option value="Mahasiswa Magang" <?= $u['user_type']=='Mahasiswa Magang'?'selected':'' ?>>Mahasiswa Magang</option>
                                                    <option value="Karyawan" <?= $u['user_type']=='Karyawan'?'selected':'' ?>>Karyawan</option>
                                                </select>
                                            </div>
                                            <div class="mb-3"><label class="form-label small text-muted fw-semibold">Instansi / Sekolah</label><input type="text" name="institution" class="form-control" value="<?= htmlspecialchars($u['institution']) ?>" required></div>
                                            <div class="mb-3"><label class="form-label small text-muted fw-semibold">Divisi / Jurusan</label><input type="text" name="department" class="form-control" value="<?= htmlspecialchars($u['department']) ?>" required></div>
                                            <div class="mb-3"><label class="form-label small text-muted fw-semibold">Status Akun</label>
                                                <select name="status" class="form-select">
                                                    <option value="Aktif" <?= $u['status']=='Aktif'?'selected':'' ?>>Aktif</option>
                                                    <option value="Nonaktif" <?= $u['status']=='Nonaktif'?'selected':'' ?>>Nonaktif</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="modal-footer border-top-0 pt-0">
                                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                                            <button type="submit" class="btn btn-primary px-4">Simpan Perubahan</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Modal Ubah Password -->
                        <div class="modal fade" id="modalPassword<?= $u['id'] ?>" tabindex="-1">
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content border shadow-sm rounded-3">
                                    <form method="POST" onsubmit="return checkPasswordMatch(<?= $u['id'] ?>)">
                                        <div class="modal-header border-bottom-0 pb-0">
                                            <h5 class="fw-bold"><i class="fas fa-key text-primary me-2"></i>Ubah Password - <?= htmlspecialchars($u['name']) ?></h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body p-4">
                                            <input type="hidden" name="action" value="change_password">
                                            <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                            
                                            <div class="mb-3">
                                                <label class="form-label small text-muted fw-semibold">Password Baru</label>
                                                <div class="input-group">
                                                    <input type="password" name="new_password" id="pass_user_<?= $u['id'] ?>" class="form-control" required minlength="6">
                                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('pass_user_<?= $u['id'] ?>', 'icon_user_<?= $u['id'] ?>')">
                                                        <i class="fas fa-eye" id="icon_user_<?= $u['id'] ?>"></i>
                                                    </button>
                                                </div>
                                            </div>

                                            <div class="mb-2">
                                                <label class="form-label small text-muted fw-semibold">Konfirmasi Password Baru</label>
                                                <div class="input-group">
                                                    <input type="password" name="confirm_password" id="confirm_pass_user_<?= $u['id'] ?>" class="form-control" required minlength="6">
                                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('confirm_pass_user_<?= $u['id'] ?>', 'confirm_icon_user_<?= $u['id'] ?>')">
                                                        <i class="fas fa-eye" id="confirm_icon_user_<?= $u['id'] ?>"></i>
                                                    </button>
                                                </div>
                                                <small id="err_msg_<?= $u['id'] ?>" class="text-danger small mt-1 d-block"></small>
                                            </div>
                                        </div>
                                        <div class="modal-footer border-top-0 pt-0">
                                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                                            <button type="submit" class="btn btn-primary px-4">Ubah Password</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <?php if(empty($users)): ?>
                            <tr><td colspan="8" class="text-center text-muted py-4">Data pengguna tidak ditemukan.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal Tambah User -->
<div class="modal fade" id="modalAdd" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border shadow-sm rounded-3">
            <form method="POST">
                <div class="modal-header border-bottom-0 pb-0">
                    <h5 class="fw-bold"><i class="fas fa-user-plus text-primary me-2"></i>Tambah User Baru</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" name="action" value="add">
                    <div class="mb-3"><label class="form-label small text-muted fw-semibold">Nama Lengkap</label><input type="text" name="name" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label small text-muted fw-semibold">Username</label><input type="text" name="username" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label small text-muted fw-semibold">Email</label><input type="email" name="email" class="form-control" required></div>
                    <div class="mb-3">
                        <label class="form-label small text-muted fw-semibold">Password</label>
                        <div class="input-group">
                            <input type="password" name="password" id="add_password" class="form-control" required minlength="6">
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('add_password', 'icon_add_pass')">
                                <i class="fas fa-eye" id="icon_add_pass"></i>
                            </button>
                        </div>
                    </div>
                    <div class="mb-3"><label class="form-label small text-muted fw-semibold">No. Telepon</label><input type="text" name="phone" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label small text-muted fw-semibold">Jenis Kelamin</label>
                        <select name="gender" class="form-select" required>
                            <option value="">-- Pilih --</option>
                            <option value="Laki-laki">Laki-laki</option>
                            <option value="Perempuan">Perempuan</option>
                        </select>
                    </div>
                    <div class="mb-3"><label class="form-label small text-muted fw-semibold">Jenis Pengguna</label>
                        <select name="user_type" class="form-select">
                            <option value="Siswa PKL">Siswa PKL</option>
                            <option value="Mahasiswa Magang">Mahasiswa Magang</option>
                            <option value="Karyawan">Karyawan</option>
                        </select>
                    </div>
                    <div class="mb-3"><label class="form-label small text-muted fw-semibold">Instansi / Sekolah</label><input type="text" name="institution" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label small text-muted fw-semibold">Divisi / Jurusan</label><input type="text" name="department" class="form-control" required></div>
                </div>
                <div class="modal-footer border-top-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary px-4">Simpan User</button>
                </div>
            </form>
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

function checkPasswordMatch(userId) {
    const p1 = document.getElementById('pass_user_' + userId).value;
    const p2 = document.getElementById('confirm_pass_user_' + userId).value;
    const errMsg = document.getElementById('err_msg_' + userId);

    if (p1 !== p2) {
        errMsg.innerHTML = "Konfirmasi password baru tidak cocok!";
        return false;
    }
    errMsg.innerHTML = "";
    return true;
}
</script>

<?php include '../includes/footer.php'; ?>