<?php
session_start();
require '../config/database.php';
require '../config/logger.php';
require '../config/helpers.php';
require '../config/security.php';

date_default_timezone_set('Asia/Jakarta');

enforce_admin_access();

// LOGIKA HAPUS DATA ABSENSI
if (isset($_POST['action_delete'])) {
    $ids_del = array_values(array_filter(array_map('intval', explode(',', $_POST['attendance_ids'] ?? '')), fn($v) => $v > 0));
    try {
        if (empty($ids_del)) {
            throw new Exception("Tidak ada data yang dipilih.");
        }
        $in_clause = implode(',', array_fill(0, count($ids_del), '?'));
        $stmt_del = $pdo->prepare("DELETE FROM attendance WHERE id IN ($in_clause)");
        $stmt_del->execute($ids_del);
        $_SESSION['pesan'] = "Data absensi berhasil dihapus!";
    } catch (Exception $e) {
        $_SESSION['error'] = "Gagal menghapus data: " . $e->getMessage();
    }
    header("Location: attendance.php");
    exit();
}

// LOGIKA EDIT DATA ABSENSI BY ADMIN
if (isset($_POST['action_edit'])) {
    $id_edit   = (int)($_POST['attendance_id'] ?? 0);
    $ids_group = array_values(array_filter(array_map('intval', explode(',', $_POST['attendance_ids'] ?? '')), fn($v) => $v > 0));
    if (!in_array($id_edit, $ids_group, true)) { $ids_group[] = $id_edit; }
    $check_in  = !empty($_POST['check_in']) ? $_POST['check_in'] : null;
    $check_out = !empty($_POST['check_out']) ? $_POST['check_out'] : null;
    $status    = $_POST['status'] ?? '';
    $desc      = trim($_POST['description'] ?? '');

    $status_valid = ['Hadir', 'Terlambat', 'Izin Jam', 'Izin Hari', 'Sakit', 'Cuti', 'Kunjungan', 'Alpa'];

    try {
        if (!in_array($status, $status_valid, true)) {
            throw new Exception("Status tidak valid.");
        }
        $pdo->beginTransaction();
        // Jam masuk/pulang hanya milik baris yang diedit
        $stmt_upd = $pdo->prepare("UPDATE attendance SET check_in = ?, check_out = ? WHERE id = ?");
        $stmt_upd->execute([$check_in, $check_out, $id_edit]);
        // Status & keterangan berlaku untuk seluruh hari dalam satu pengajuan (izin/sakit/cuti beberapa hari)
        $in_clause = implode(',', array_fill(0, count($ids_group), '?'));
        $stmt_grp = $pdo->prepare("UPDATE attendance SET status = ?, description = ? WHERE id IN ($in_clause)");
        $stmt_grp->execute(array_merge([$status, $desc], $ids_group));
        $pdo->commit();
        $_SESSION['pesan'] = "Data absensi berhasil diperbarui!";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $_SESSION['error'] = "Gagal memperbarui data: " . $e->getMessage();
    }
    header("Location: attendance.php");
    exit();
}

// LOGIKA FILTER
$filter_bulan     = $_GET['bulan'] ?? date('m');
$filter_tahun     = $_GET['tahun'] ?? date('Y');
$filter_status    = $_GET['status'] ?? '';
$filter_user_type = $_GET['user_type'] ?? '';

// PERBAIKAN: Hanya tampilkan data yang benar-benar 'Disetujui' ATAU status normal (Hadir, Terlambat, Kunjungan, Alpa) 
// Serta pastikan status 'Pending' / 'Menunggu Persetujuan' TIDAK IKUT TAMPIL
$where_clauses = [
    "MONTH(a.date) = ?", 
    "YEAR(a.date) = ?",
    "(
        (a.approval_status = 'Disetujui') 
        OR 
        (a.approval_status IS NULL AND a.status IN ('Hadir', 'Terlambat', 'Kunjungan', 'Alpa'))
    )"
];
$params = [$filter_bulan, $filter_tahun];

if (!empty($filter_status)) {
    if ($filter_status === 'Izin Jam') {
        $where_clauses[] = "(a.status = 'Izin Jam' OR a.description LIKE '%Izin Jam%')";
    } else {
        $where_clauses[] = "a.status = ?";
        $params[] = $filter_status;
    }
}

if (!empty($filter_user_type) && in_array($filter_user_type, ['Siswa PKL', 'Mahasiswa Magang', 'Karyawan'], true)) {
    $where_clauses[] = "u.user_type = ?";
    $params[] = $filter_user_type;
}

$where_sql = implode(" AND ", $where_clauses);

$hari_ini  = date('Y-m-d');
$tol_izin  = izin_jam_toleransi($pdo);
$izin_cols = izin_cols_sql('a'); // , a.izin_jam_mulai, a.izin_jam_sampai (bila kolom tersedia)

// Query Data Absensi
// Hanya 'Izin Hari' yang digabung menjadi satu baris (rentang tanggal). Status lain
// (Hadir, Terlambat, Sakit, Cuti, dst.) tampil per hari: id baris ikut di GROUP BY.
$query = "
    SELECT 
        GROUP_CONCAT(a.id ORDER BY a.date ASC) as group_ids,
        MIN(a.date) as min_date,
        MAX(a.date) as max_date,
        COUNT(a.id) as total_hari,
        a.id as main_id,
        a.user_id,
        a.status,
        a.approval_status,
        a.description,
        a.check_in,
        a.check_out{$izin_cols},
        u.name as user_name, 
        u.user_type, 
        u.institution 
    FROM attendance a 
    JOIN users u ON a.user_id = u.id 
    WHERE $where_sql 
    GROUP BY a.user_id, a.status, a.approval_status, a.description, MONTH(a.date), YEAR(a.date),
             IF(a.status IN ('Izin Hari', 'Izin'), 0, a.id)
    ORDER BY min_date DESC, a.id DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$data_absensi = $stmt->fetchAll();

$nama_bulan = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
    '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
    '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
];
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/navbar.php'; ?>

<div class="container pb-5 flex-grow-1">
    <!-- HEADER BAR: TITLE -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
        <div>
            <h4 class="fw-bold m-0"><i class="fas fa-calendar-check text-primary me-2"></i>Riwayat &amp; Data Absensi</h4>
            <small class="text-muted">Pantau riwayat kehadiran berdasarkan kategori Siswa PKL, Mahasiswa Magang, dan Karyawan</small>
        </div>
    </div>

    <!-- ALERT PESAN -->
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

    <div class="card shadow-sm border-0 rounded-3">
        <div class="card-body p-4">
            <!-- TABS PEMISAH KATEGORI: SEMUA, SISWA PKL, MAHASISWA MAGANG, KARYAWAN -->
            <ul class="nav nav-pills mb-4 border-bottom pb-3 gap-2 flex-wrap">
                <li class="nav-item">
                    <a class="nav-link <?= empty($filter_user_type) ? 'active' : 'bg-light text-secondary border' ?>" href="attendance.php?bulan=<?= $filter_bulan ?>&tahun=<?= $filter_tahun ?>&status=<?= urlencode($filter_status) ?>">
                        <i class="fas fa-users me-1.5"></i>Semua Kategori
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $filter_user_type === 'Siswa PKL' ? 'active' : 'bg-light text-secondary border' ?>" href="attendance.php?bulan=<?= $filter_bulan ?>&tahun=<?= $filter_tahun ?>&status=<?= urlencode($filter_status) ?>&user_type=Siswa+PKL">
                        <i class="fas fa-graduation-cap me-1.5"></i>Siswa PKL
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $filter_user_type === 'Mahasiswa Magang' ? 'active' : 'bg-light text-secondary border' ?>" href="attendance.php?bulan=<?= $filter_bulan ?>&tahun=<?= $filter_tahun ?>&status=<?= urlencode($filter_status) ?>&user_type=Mahasiswa+Magang">
                        <i class="fas fa-user-graduate me-1.5"></i>Mahasiswa Magang
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $filter_user_type === 'Karyawan' ? 'active' : 'bg-light text-secondary border' ?>" href="attendance.php?bulan=<?= $filter_bulan ?>&tahun=<?= $filter_tahun ?>&status=<?= urlencode($filter_status) ?>&user_type=Karyawan">
                        <i class="fas fa-briefcase me-1.5"></i>Karyawan
                    </a>
                </li>
            </ul>

            <!-- PANEL FILTER -->
            <form method="GET" action="attendance.php" class="row g-2 mb-4 align-items-center">
                <?php if (!empty($filter_user_type)): ?>
                    <input type="hidden" name="user_type" value="<?= htmlspecialchars($filter_user_type) ?>">
                <?php endif; ?>
                <div class="col-lg-3 col-md-3 col-12">
                    <select name="bulan" class="form-select">
                        <?php foreach($nama_bulan as $k => $v): ?>
                            <option value="<?= $k ?>" <?= $k == $filter_bulan ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-lg-2 col-md-3 col-6">
                    <select name="tahun" class="form-select">
                        <?php 
                        $thn = date('Y');
                        for($t = $thn - 2; $t <= $thn + 1; $t++): 
                        ?>
                            <option value="<?= $t ?>" <?= $t == $filter_tahun ? 'selected' : '' ?>><?= $t ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="col-lg-3 col-md-3 col-6">
                    <select name="status" class="form-select">
                        <option value="">Semua Status</option>
                        <option value="Hadir" <?= $filter_status == 'Hadir' ? 'selected' : '' ?>>Hadir</option>
                        <option value="Terlambat" <?= $filter_status == 'Terlambat' ? 'selected' : '' ?>>Terlambat</option>
                        <option value="Izin Jam" <?= $filter_status == 'Izin Jam' ? 'selected' : '' ?>>Izin Jam</option>
                        <option value="Izin Hari" <?= $filter_status == 'Izin Hari' ? 'selected' : '' ?>>Izin Hari</option>
                        <option value="Sakit" <?= $filter_status == 'Sakit' ? 'selected' : '' ?>>Sakit</option>
                        <option value="Cuti" <?= $filter_status == 'Cuti' ? 'selected' : '' ?>>Cuti</option>
                        <option value="Kunjungan" <?= $filter_status == 'Kunjungan' ? 'selected' : '' ?>>Kunjungan Kantor</option>
                        <option value="Alpa" <?= $filter_status == 'Alpa' ? 'selected' : '' ?>>Alpa</option>
                    </select>
                </div>

                <div class="col-lg-4 col-md-3 col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-primary w-100 rounded-3 shadow-sm fw-semibold">
                        <i class="fas fa-filter me-1"></i> Filter
                    </button>
                    <a href="attendance.php" class="btn btn-outline-secondary rounded-3 shadow-sm fw-semibold text-nowrap d-flex align-items-center" title="Reset Filter">
                        <i class="fas fa-redo me-1"></i> Reset
                    </a>
                </div>
            </form>

            <!-- TABEL DATA ABSENSI -->
            <div class="table-responsive">
                <table class="table table-hover align-middle border mb-0">
                    <thead class="table-light text-uppercase text-muted" style="font-size: 13px; letter-spacing: 0.5px;">
                        <tr>
                            <th class="ps-3 py-3">NO</th>
                            <th class="py-3">NAMA</th>
                            <th class="py-3">TANGGAL</th>
                            <th class="py-3">MASUK</th>
                            <th class="py-3">PULANG</th>
                            <th class="py-3">STATUS ABSENSI</th>
                            <th class="py-3">KETERANGAN</th>
                            <th class="text-center py-3">AKSI</th>
                        </tr>
                    </thead>
                    <tbody style="font-size: 14px;">
                        <?php if (empty($data_absensi)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">
                                    <i class="fas fa-folder-open fa-2x d-block mb-2 text-secondary opacity-50"></i>
                                    Tidak ada data absensi yang ditemukan.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php $no = 1; foreach($data_absensi as $row): ?>
                                <tr>
                                    <td class="ps-3 fw-semibold text-secondary"><?= $no++ ?></td>
                                    <td>
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($row['user_name']) ?></div>
                                        <small class="badge bg-light text-secondary border rounded-2 px-2 py-0"><?= htmlspecialchars($row['user_type']) ?></small>
                                    </td>
                                    <td class="fw-semibold text-secondary">
                                        <?php if ($row['min_date'] == $row['max_date']): ?>
                                            <?= date('d/m/Y', strtotime($row['min_date'])) ?>
                                        <?php else: ?>
                                            <?= date('d/m/Y', strtotime($row['min_date'])) ?> s.d <?= date('d/m/Y', strtotime($row['max_date'])) ?>
                                            <div class="badge bg-light text-primary border mt-1 d-inline-block fw-bold" style="font-size: 10px;"><?= $row['total_hari'] ?> Hari</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="fw-bold text-dark"><?= !empty($row['check_in']) ? date('H:i', strtotime($row['check_in'])) : '-' ?></td>
                                    <td class="fw-bold text-dark"><?= !empty($row['check_out']) ? date('H:i', strtotime($row['check_out'])) : '-' ?></td>
                                    <td>
                                        <?php 
                                        $st = $row['status'];
                                        $bg_badge = ($st == 'Hadir') ? 'success' : (($st == 'Terlambat') ? 'danger' : (($st == 'Alpa') ? 'dark' : 'info'));
                                        $label_badge = $st;
                                        $badge_class = 'bg-' . $bg_badge;
                                        $badge_style = '';
                                        $catatan_ij  = null;

                                        // Izin jam: satu badge sesuai hasil penilaian (aturan di config/helpers.php)
                                        if (is_izin_jam_row($row)) {
                                            $hasil_ij   = rekap_status($row + ['date' => $row['min_date']], $hari_ini, $tol_izin);
                                            $sampai_ij  = izin_jam_sampai_of($row);
                                            if ($hasil_ij === 'Terlambat') {
                                                $label_badge = 'Terlambat';
                                                $badge_class = 'bg-danger';
                                                $catatan_ij  = 'Melewati batas izin' . ($sampai_ij ? ' (' . substr($sampai_ij, 0, 5) . ')' : '');
                                            } elseif ($hasil_ij === 'Alpa') {
                                                $label_badge = 'Alpa';
                                                $badge_class = '';
                                                $catatan_ij  = 'Izin disetujui, tidak hadir';
                                            } else {
                                                $label_badge = 'Izin Jam';
                                                $badge_class = '';
                                                $badge_style = ' background-color: #fd7e14; color: #fff;';
                                                $catatan_ij  = ($hasil_ij === 'Izin Jam') ? 'Sah, tidak dihitung terlambat' : 'Belum hadir';
                                            }
                                        }
                                        // Warna badge disamakan dengan rekap absensi, dashboard, dan export
                                        $warna_status = [
                                            'Hadir'     => '#10b981',
                                            'Terlambat' => '#d63384',
                                            'Izin Jam'  => '#fd7e14',
                                            'Kunjungan' => '#17a2b8',
                                            'Izin Hari' => '#0d6efd',
                                            'Izin'      => '#0d6efd',
                                            'Cuti'      => '#20c997',
                                            'Sakit'     => '#6f42c1',
                                            'Alpa'      => '#ef4444',
                                        ];
                                        if (isset($warna_status[$label_badge])) {
                                            $badge_class = '';
                                            $badge_style = ' background-color: ' . $warna_status[$label_badge] . '; color: #fff;';
                                        }
                                        ?>
                                        <div class="d-flex flex-column align-items-start gap-1">
                                            <span class="badge <?= $badge_class ?> px-2.5 py-1 rounded-2" style="font-size: 11px;<?= $badge_style ?>">
                                                <?= htmlspecialchars($label_badge) ?>
                                            </span>
                                            <?php if ($catatan_ij): ?>
                                                <small class="text-muted" style="font-size: 10px;">
                                                    <i class="fas fa-clock me-1" style="color: #fd7e14;"></i><?= htmlspecialchars($catatan_ij) ?>
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <td class="text-secondary" style="max-width: 250px;">
                                        <?= format_desc_tag($row['description'] ?? '-') ?>
                                    </td>

                                    <td class="text-center">
                                        <div class="d-flex justify-content-center gap-1">
                                            <!-- TOMBOL EDIT -->
                                            <button type="button" class="btn btn-sm btn-warning shadow-sm px-2 py-1" data-bs-toggle="modal" data-bs-target="#modalEditAbsen<?= $row['main_id'] ?>" title="Edit Data">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            
                                            <!-- TOMBOL HAPUS -->
                                            <form method="POST" action="attendance.php" class="m-0" onsubmit="return confirm('Apakah Anda yakin ingin menghapus data absensi ini?')">
                                                <input type="hidden" name="action_delete" value="1">
                                                <input type="hidden" name="attendance_ids" value="<?= $row['group_ids'] ?>">
                                                <button type="submit" class="btn btn-sm btn-danger shadow-sm px-2 py-1" title="Hapus Data">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>

                                        <!-- MODAL EDIT DATA ABSENSI -->
                                        <div class="modal fade" id="modalEditAbsen<?= $row['main_id'] ?>" tabindex="-1">
                                            <div class="modal-dialog modal-dialog-centered">
                                                <div class="modal-content border-0 shadow-lg rounded-3 text-start">
                                                    <form action="attendance.php" method="POST">
                                                        <div class="modal-header border-bottom-0 pb-0 pt-4 px-4">
                                                            <h5 class="modal-title fw-bold text-dark"><i class="fas fa-edit text-warning me-2"></i>Edit Absensi User</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body p-4">
                                                            <input type="hidden" name="action_edit" value="1">
                                                            <input type="hidden" name="attendance_id" value="<?= $row['main_id'] ?>">
                                                            <input type="hidden" name="attendance_ids" value="<?= htmlspecialchars($row['group_ids']) ?>">

                                                            <div class="mb-3">
                                                                <label class="form-label small text-secondary fw-semibold">Nama Siswa</label>
                                                                <input type="text" class="form-control rounded-3 bg-light" value="<?= htmlspecialchars($row['user_name']) ?>" readonly>
                                                            </div>

                                                            <div class="row g-2 mb-3">
                                                                <div class="col-6">
                                                                    <label class="form-label small text-secondary fw-semibold">Jam Masuk</label>
                                                                    <input type="time" name="check_in" class="form-control rounded-3 shadow-sm" value="<?= $row['check_in'] ?>">
                                                                </div>
                                                                <div class="col-6">
                                                                    <label class="form-label small text-secondary fw-semibold">Jam Pulang</label>
                                                                    <input type="time" name="check_out" class="form-control rounded-3 shadow-sm" value="<?= $row['check_out'] ?>">
                                                                </div>
                                                            </div>

                                                            <div class="mb-3">
                                                                <label class="form-label small text-secondary fw-semibold">Status Absensi</label>
                                                                <select name="status" class="form-select rounded-3 shadow-sm" required>
                                                                    <option value="Hadir" <?= $row['status'] == 'Hadir' ? 'selected' : '' ?>>Hadir</option>
                                                                    <option value="Terlambat" <?= $row['status'] == 'Terlambat' ? 'selected' : '' ?>>Terlambat</option>
                                                                    <option value="Izin Jam" <?= $row['status'] == 'Izin Jam' ? 'selected' : '' ?>>Izin Jam</option>
                                                                    <option value="Izin Hari" <?= $row['status'] == 'Izin Hari' ? 'selected' : '' ?>>Izin Hari</option>
                                                                    <option value="Sakit" <?= $row['status'] == 'Sakit' ? 'selected' : '' ?>>Sakit</option>
                                                                    <option value="Cuti" <?= $row['status'] == 'Cuti' ? 'selected' : '' ?>>Cuti</option>
                                                                    <option value="Kunjungan" <?= $row['status'] == 'Kunjungan' ? 'selected' : '' ?>>Kunjungan Kantor</option>
                                                                    <option value="Alpa" <?= $row['status'] == 'Alpa' ? 'selected' : '' ?>>Alpa</option>
                                                                </select>
                                                            </div>

                                                            <div class="mb-2">
                                                                <label class="form-label small text-secondary fw-semibold">Keterangan / Alasan</label>
                                                                <textarea name="description" class="form-control rounded-3 shadow-sm" rows="3"><?= htmlspecialchars($row['description'] ?? '') ?></textarea>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer border-top-0 pt-0 pb-4 px-4">
                                                            <button type="button" class="btn btn-outline-secondary rounded-2 px-4 fw-semibold" data-bs-dismiss="modal">Batal</button>
                                                            <button type="submit" class="btn btn-warning rounded-2 px-4 fw-semibold shadow-sm">Simpan Perubahan</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>

                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>