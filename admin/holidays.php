<?php
session_start();
require '../config/database.php';
require '../config/logger.php';
require '../config/security.php';
date_default_timezone_set('Asia/Jakarta');

enforce_admin_access();

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action == 'add') {
        $date = trim($_POST['date']);
        $name = trim($_POST['name']);
        $description = trim($_POST['description'] ?? '');

        if (!empty($date) && !empty($name)) {
            $stmt_check = $pdo->prepare("SELECT id FROM holidays WHERE date = ?");
            $stmt_check->execute([$date]);

            if ($stmt_check->rowCount() > 0) {
                $_SESSION['error'] = "Hari libur untuk tanggal tersebut sudah terdaftar!";
            } else {
                $stmt = $pdo->prepare("INSERT INTO holidays (date, name, description) VALUES (?, ?, ?)");
                $stmt->execute([$date, $name, $description]);
                logActivity($pdo, $_SESSION['user_id'], "Menambahkan hari libur: $name ($date)");
                $_SESSION['pesan'] = "Hari libur berhasil ditambahkan!";
            }
        } else {
            $_SESSION['error'] = "Tanggal dan Nama hari libur wajib diisi!";
        }
        header("Location: holidays.php");
        exit();
    }

    if ($action == 'edit') {
        $id = $_POST['id'];
        $date = trim($_POST['date']);
        $name = trim($_POST['name']);
        $description = trim($_POST['description'] ?? '');

        if (!empty($id) && !empty($date) && !empty($name)) {
            $stmt_check = $pdo->prepare("SELECT id FROM holidays WHERE date = ? AND id != ?");
            $stmt_check->execute([$date, $id]);

            if ($stmt_check->rowCount() > 0) {
                $_SESSION['error'] = "Tanggal tersebut sudah digunakan oleh hari libur lain!";
            } else {
                $stmt = $pdo->prepare("UPDATE holidays SET date = ?, name = ?, description = ? WHERE id = ?");
                $stmt->execute([$date, $name, $description, $id]);
                logActivity($pdo, $_SESSION['user_id'], "Mengubah hari libur ID: $id");
                $_SESSION['pesan'] = "Hari libur berhasil diperbarui!";
            }
        }
        header("Location: holidays.php");
        exit();
    }

    if ($action == 'delete') {
        $id = $_POST['id'];
        if (!empty($id)) {
            $stmt = $pdo->prepare("DELETE FROM holidays WHERE id = ?");
            $stmt->execute([$id]);
            logActivity($pdo, $_SESSION['user_id'], "Menghapus hari libur ID: $id");
            $_SESSION['pesan'] = "Hari libur berhasil dihapus!";
        }
        header("Location: holidays.php");
        exit();
    }
}

$stmt = $pdo->query("SELECT * FROM holidays ORDER BY date DESC");
$holidays = $stmt->fetchAll();

function getNamaHari($dateStr) {
    $days = [
        'Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa',
        'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'
    ];
    $dayEng = date('l', strtotime($dateStr));
    return $days[$dayEng] ?? $dayEng;
}
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/navbar.php'; ?>

<div class="container pb-5 flex-grow-1">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="fw-bold mb-1"><i class="fas fa-calendar-times text-danger me-2"></i>Pengaturan Hari Libur</h4>
            <p class="text-muted small mb-0">Kelola daftar hari libur nasional atau libur khusus instansi</p>
        </div>
        <button type="button" class="btn btn-primary rounded-2 px-4 fw-semibold shadow-sm" data-bs-toggle="modal" data-bs-target="#modalTambahLibur">
            <i class="fas fa-plus me-1"></i> Tambah Hari Libur
        </button>
    </div>

    <?php if (isset($_SESSION['pesan'])): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-3">
            <i class="fas fa-check-circle me-2"></i><?= $_SESSION['pesan']; unset($_SESSION['pesan']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-3">
            <i class="fas fa-exclamation-circle me-2"></i><?= $_SESSION['error']; unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-3">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-uppercase text-muted" style="font-size: 14px;">
                        <tr>
                            <th class="ps-4 py-3" style="width: 50px;">NO</th>
                            <th class="py-3">HARI & TANGGAL</th>
                            <th class="py-3">NAMA HARI LIBUR</th>
                            <th class="py-3">KETERANGAN</th>
                            <th class="text-center py-3" style="width: 150px;">AKSI</th>
                        </tr>
                    </thead>
                    <tbody style="font-size: 13px;">
                        <?php if (empty($holidays)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-5 text-muted">
                                    <div class="py-3">
                                        <i class="fas fa-calendar-alt fa-3x text-secondary opacity-25 mb-3 d-block"></i>
                                        <span class="fw-semibold">Belum ada data hari libur yang ditambahkan.</span>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php $no = 1; foreach ($holidays as $h): ?>
                                <tr>
                                    <td class="ps-4 fw-semibold"><?= $no++ ?></td>
                                    <td>
                                        <span class="fw-bold text-dark"><?= getNamaHari($h['date']) ?></span>,
                                        <small class="text-muted"><?= date('d F Y', strtotime($h['date'])) ?></small>
                                    </td>
                                    <td class="fw-semibold text-primary"><?= htmlspecialchars($h['name']) ?></td>
                                    <td class="text-muted small"><?= htmlspecialchars($h['description'] ?: '-') ?></td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-outline-warning rounded-2 px-3 me-1" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#modalEditLibur<?= $h['id'] ?>">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-danger rounded-2 px-3" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#modalHapusLibur<?= $h['id'] ?>">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>

                                <!-- MODAL EDIT -->
                                <div class="modal fade" id="modalEditLibur<?= $h['id'] ?>" tabindex="-1">
                                    <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content border-0 shadow rounded-3">
                                            <form action="holidays.php" method="POST">
                                                <div class="modal-header border-bottom-0">
                                                    <h5 class="modal-title fw-bold"><i class="fas fa-edit text-warning me-2"></i>Edit Hari Libur</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body p-4 text-start">
                                                    <input type="hidden" name="action" value="edit">
                                                    <input type="hidden" name="id" value="<?= $h['id'] ?>">
                                                    
                                                    <div class="mb-3">
                                                        <label class="form-label small text-muted fw-semibold">Tanggal</label>
                                                        <input type="date" name="date" class="form-control" value="<?= $h['date'] ?>" required>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label small text-muted fw-semibold">Nama Hari Libur</label>
                                                        <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($h['name']) ?>" required>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label small text-muted fw-semibold">Keterangan</label>
                                                        <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($h['description'] ?? '') ?></textarea>
                                                    </div>
                                                </div>
                                                <div class="modal-footer border-top-0">
                                                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                                                    <button type="submit" class="btn btn-warning px-4">Simpan Perubahan</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                                <!-- MODAL HAPUS -->
                                <div class="modal fade" id="modalHapusLibur<?= $h['id'] ?>" tabindex="-1">
                                    <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content border-0 shadow rounded-3">
                                            <form action="holidays.php" method="POST">
                                                <div class="modal-header border-bottom-0">
                                                    <h5 class="modal-title fw-bold"><i class="fas fa-trash text-danger me-2"></i>Hapus Hari Libur</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body p-4 text-center">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?= $h['id'] ?>">
                                                    <p class="mb-0">Apakah kamu yakin ingin menghapus hari libur <strong><?= htmlspecialchars($h['name']) ?></strong> pada tanggal <strong><?= date('d-m-Y', strtotime($h['date'])) ?></strong>?</p>
                                                </div>
                                                <div class="modal-footer border-top-0 justify-content-center">
                                                    <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Batal</button>
                                                    <button type="submit" class="btn btn-danger px-4">Ya, Hapus</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- MODAL TAMBAH LIBUR -->
<div class="modal fade" id="modalTambahLibur" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-3">
            <form action="holidays.php" method="POST">
                <div class="modal-header border-bottom-0">
                    <h5 class="modal-title fw-bold"><i class="fas fa-calendar-plus text-primary me-2"></i>Tambah Hari Libur</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 text-start">
                    <input type="hidden" name="action" value="add">
                    <div class="mb-3">
                        <label class="form-label small text-muted fw-semibold">Tanggal Libur</label>
                        <input type="date" name="date" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-muted fw-semibold">Nama Hari Libur</label>
                        <input type="text" name="name" class="form-control" placeholder="Contoh: Hari Raya Idul Fitri" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-muted fw-semibold">Keterangan (Opsional)</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Tambahkan catatan jika ada"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-top-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary px-4 fw-bold">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>