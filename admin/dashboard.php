<?php
session_start();
require '../config/database.php';
require '../config/logger.php';
require '../config/helpers.php';
require '../config/security.php';
date_default_timezone_set('Asia/Jakarta');

enforce_admin_access();

// Proses Hapus Activity Log jika ada permintaan POST
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['clear_logs'])) {
    $pdo->query("DELETE FROM activity_logs");
    logActivity($pdo, $_SESSION['user_id'], "Administrator menghapus seluruh riwayat activity logs.");
    $_SESSION['pesan'] = "Semua riwayat aktivitas berhasil dihapus.";
    header("Location: dashboard.php");
    exit();
}

require 'cek_alpa.php';

// STATISTIK USER
$stmt_users = $pdo->query("SELECT user_type, COUNT(*) as total FROM users WHERE status = 'Aktif' AND user_type != 'Admin' GROUP BY user_type");
$users_stat = ['Siswa PKL' => 0, 'Mahasiswa Magang' => 0, 'Karyawan' => 0];
$total_users = 0;
while ($row = $stmt_users->fetch()) { 
    if (array_key_exists($row['user_type'], $users_stat)) {
        $users_stat[$row['user_type']] = $row['total']; 
    }
    $total_users += $row['total']; 
}

// LOGIKA FILTER BULAN & TAHUN UNTUK STATISTIK KEHADIRAN ADMIN
$filter_bulan = isset($_GET['bulan']) ? sprintf("%02d", $_GET['bulan']) : date('m');
$filter_tahun = isset($_GET['tahun']) ? $_GET['tahun'] : date('Y');

// Statistik kehadiran bulan ini (aturan sama dengan rekap: pending/ditolak tidak dihitung,
// izin jam dinilai Izin Jam / Terlambat / Alpa -> lihat rekap_status di config/helpers.php)
$absen_stat = [
    'Hadir'      => 0,
    'Kunjungan'  => 0,
    'Terlambat'  => 0,
    'Izin Jam'   => 0,
    'Izin'       => 0,
    'Sakit'      => 0,
    'Cuti'       => 0,
    'Alpa'       => 0
];

$stmt_stat = $pdo->prepare("SELECT * FROM attendance WHERE MONTH(date) = ? AND YEAR(date) = ?");
$stmt_stat->execute([$filter_bulan, $filter_tahun]);
$tol_izin  = izin_jam_toleransi($pdo);
$hari_ini  = date('Y-m-d');
while ($row = $stmt_stat->fetch()) {
    $rs = rekap_status($row, $hari_ini, $tol_izin);
    if ($rs === 'Izin Hari') $rs = 'Izin';
    if ($rs !== null && array_key_exists($rs, $absen_stat)) {
        $absen_stat[$rs]++;
    }
}

$nama_bulan = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
    '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
    '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
];

// QUERY ACTIVITY LOGS: MENYARING HANYA USER BUKAN ADMIN SERTA MENGABAIKAN LOG LOGIN/LOGOUT ADMIN
$stmt_logs = $pdo->query("
    SELECT l.*, u.name, u.user_type 
    FROM activity_logs l 
    LEFT JOIN users u ON l.user_id = u.id 
    WHERE (u.user_type != 'Admin' OR u.user_type IS NULL) 
    AND l.activity NOT LIKE '%Login%' 
    AND l.activity NOT LIKE '%Logout%' 
    ORDER BY l.created_at DESC 
    LIMIT 10
");
$logs = $stmt_logs->fetchAll();
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/navbar.php'; ?>

<div class="container pb-5 flex-grow-1">
    <!-- HEADER BAR: TITLE -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
        <h4 class="fw-bold m-0"><i class="fas fa-tachometer-alt text-primary me-2"></i>Dashboard Admin</h4>
        <span class="text-muted fw-semibold small"><?= date('d F Y') ?></span>
    </div>
    
    <?php if(isset($_SESSION['pesan'])): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-3">
            <i class="fas fa-check-circle me-1"></i><?= $_SESSION['pesan']; unset($_SESSION['pesan']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- STATISTIK USER (KONSISTEN 2x2 MOBILE, 1x4 DESKTOP) -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card p-3 shadow-sm border h-100">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="small text-muted fw-semibold text-uppercase" style="letter-spacing: 0.5px; font-size: 11px;">Total User Aktif</span>
                    <span class="icon-badge stat-icon-primary"><i class="fas fa-users"></i></span>
                </div>
                <div class="fs-3 fw-bold text-dark"><?= $total_users ?></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card p-3 shadow-sm border h-100">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="small text-muted fw-semibold text-uppercase" style="letter-spacing: 0.5px; font-size: 11px;">Siswa PKL</span>
                    <span class="icon-badge stat-icon-success"><i class="fas fa-graduation-cap"></i></span>
                </div>
                <div class="fs-3 fw-bold text-dark"><?= $users_stat['Siswa PKL'] ?></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card p-3 shadow-sm border h-100">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="small text-muted fw-semibold text-uppercase" style="letter-spacing: 0.5px; font-size: 11px;">Mahasiswa Magang</span>
                    <span class="icon-badge stat-icon-info"><i class="fas fa-user-graduate"></i></span>
                </div>
                <div class="fs-3 fw-bold text-dark"><?= $users_stat['Mahasiswa Magang'] ?></div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card p-3 shadow-sm border h-100">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="small text-muted fw-semibold text-uppercase" style="letter-spacing: 0.5px; font-size: 11px;">Karyawan</span>
                    <span class="icon-badge stat-icon-warning"><i class="fas fa-briefcase"></i></span>
                </div>
                <div class="fs-3 fw-bold text-dark"><?= $users_stat['Karyawan'] ?></div>
            </div>
        </div>
    </div>

    <!-- PANEL STATISTIK KEHADIRAN (NO NESTED CARDS) -->
    <div class="mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div>
                <h5 class="fw-bold mb-0 text-dark">Statistik Kehadiran Keseluruhan</h5>
                <small class="text-muted">Ringkasan kehadiran seluruh pengguna terdaftar</small>
            </div>
            
            <form method="GET" class="d-flex align-items-center flex-wrap gap-2 w-auto">
                <select name="bulan" class="form-select form-select-sm flex-fill flex-sm-grow-0" style="min-width: 125px; width: auto;" aria-label="Pilih Bulan">
                    <?php foreach($nama_bulan as $k => $v): ?>
                        <option value="<?= $k ?>" <?= $k == $filter_bulan ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="tahun" class="form-select form-select-sm flex-fill flex-sm-grow-0" style="min-width: 85px; width: auto;" aria-label="Pilih Tahun">
                    <?php 
                    $tahun_sekarang = date('Y');
                    for($t = $tahun_sekarang - 2; $t <= $tahun_sekarang + 1; $t++): 
                    ?>
                        <option value="<?= $t ?>" <?= $t == $filter_tahun ? 'selected' : '' ?>><?= $t ?></option>
                    <?php endfor; ?>
                </select>
                <button type="submit" class="btn btn-sm btn-primary px-3 flex-fill flex-sm-grow-0"><i class="fas fa-filter me-1"></i> Filter</button>
            </form>
        </div>

        <div class="row g-3">
            <!-- Hadir -->
            <div class="col-md-3 col-6">
                <div class="stat-tile">
                    <div class="stat-tile-header">
                        <span class="lbl">Hadir Tepat</span>
                        <span class="icon-badge stat-icon-success"><i class="fas fa-check"></i></span>
                    </div>
                    <div class="num text-success"><?= (int)($absen_stat['Hadir'] ?? 0) ?></div>
                </div>
            </div>
            <!-- Kunjungan -->
            <div class="col-md-3 col-6">
                <div class="stat-tile">
                    <div class="stat-tile-header">
                        <span class="lbl">Kunjungan</span>
                        <span class="icon-badge stat-icon-primary"><i class="fas fa-building"></i></span>
                    </div>
                    <div class="num text-primary"><?= (int)($absen_stat['Kunjungan'] ?? 0) ?></div>
                </div>
            </div>
            <!-- Terlambat -->
            <div class="col-md-3 col-6">
                <div class="stat-tile">
                    <div class="stat-tile-header">
                        <span class="lbl">Terlambat</span>
                        <span class="icon-badge stat-icon-warning"><i class="fas fa-clock"></i></span>
                    </div>
                    <div class="num text-warning"><?= (int)($absen_stat['Terlambat'] ?? 0) ?></div>
                </div>
            </div>
            <!-- Izin Jam -->
            <div class="col-md-3 col-6">
                <div class="stat-tile">
                    <div class="stat-tile-header">
                        <span class="lbl">Izin Jam</span>
                        <span class="icon-badge stat-icon-info"><i class="fas fa-user-clock"></i></span>
                    </div>
                    <div class="num text-info"><?= (int)($absen_stat['Izin Jam'] ?? 0) ?></div>
                </div>
            </div>
            <!-- Izin Hari -->
            <div class="col-md-3 col-6">
                <div class="stat-tile">
                    <div class="stat-tile-header">
                        <span class="lbl">Izin Hari</span>
                        <span class="icon-badge stat-icon-indigo"><i class="fas fa-calendar-day"></i></span>
                    </div>
                    <div class="num" style="color: #6366f1;"><?= (int)($absen_stat['Izin'] ?? 0) ?></div>
                </div>
            </div>
            <!-- Sakit -->
            <div class="col-md-3 col-6">
                <div class="stat-tile">
                    <div class="stat-tile-header">
                        <span class="lbl">Sakit</span>
                        <span class="icon-badge stat-icon-rose"><i class="fas fa-notes-medical"></i></span>
                    </div>
                    <div class="num" style="color: #e11d48;"><?= (int)($absen_stat['Sakit'] ?? 0) ?></div>
                </div>
            </div>
            <!-- Cuti -->
            <div class="col-md-3 col-6">
                <div class="stat-tile">
                    <div class="stat-tile-header">
                        <span class="lbl">Cuti</span>
                        <span class="icon-badge stat-icon-teal"><i class="fas fa-calendar-check"></i></span>
                    </div>
                    <div class="num" style="color: #0d9488;"><?= (int)($absen_stat['Cuti'] ?? 0) ?></div>
                </div>
            </div>
            <!-- Alpa -->
            <div class="col-md-3 col-6">
                <div class="stat-tile">
                    <div class="stat-tile-header">
                        <span class="lbl">Alpa</span>
                        <span class="icon-badge stat-icon-danger"><i class="fas fa-user-times"></i></span>
                    </div>
                    <div class="num text-danger"><?= (int)($absen_stat['Alpa'] ?? 0) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- RIWAYAT ACTIVITY LOG -->
    <div class="card shadow-sm border-0 rounded-3">
        <div class="card-header bg-white d-flex justify-content-between align-items-center py-3 px-4 border-bottom">
            <h5 class="mb-0 fw-bold text-dark"><i class="fas fa-history text-primary me-2"></i> Riwayat Absensi User Terbaru</h5>
            
            <form method="POST" onsubmit="return confirmClearLogs();" class="m-0">
                <input type="hidden" name="clear_logs" value="1">
                <button type="submit" class="btn btn-sm btn-outline-danger rounded-3 fw-semibold shadow-sm">
                    <i class="fas fa-trash-alt me-1"></i> Hapus Semua Log
                </button>
            </form>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle border mb-0">
                    <thead class="table-light text-uppercase text-muted" style="font-size: 13px; letter-spacing: 0.5px;">
                        <tr>
                            <th class="ps-4 py-3">Waktu</th>
                            <th class="py-3">Pengguna</th>
                            <th class="py-3">Aktivitas</th>
                        </tr>
                    </thead>
                    <tbody style="font-size: 14px;">
                        <?php foreach($logs as $log): ?>
                        <tr>
                            <td class="ps-4 text-secondary"><small><?= $log['created_at'] ?></small></td>
                            <td>
                                <strong class="text-dark"><?= htmlspecialchars($log['name'] ?? 'Sistem') ?></strong><br>
                                <span class="badge bg-light text-secondary border px-2 py-0 mt-1" style="font-size: 10px;"><?= htmlspecialchars($log['user_type'] ?? '-') ?></span>
                            </td>
                            <td class="text-secondary"><?= htmlspecialchars($log['activity']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($logs)): ?>
                            <tr><td colspan="3" class="text-center text-muted py-4">Belum ada aktivitas absensi tercatat.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function confirmClearLogs() {
    let firstConfirm = confirm("Peringatan! Apakah Anda yakin ingin menghapus seluruh riwayat aktivitas?");
    if (firstConfirm) {
        let secondConfirm = confirm("Konfirmasi Terakhir: Tindakan ini tidak dapat dibatalkan. Yakin ingin menghapusnya sekarang?");
        return secondConfirm;
    }
    return false;
}
</script>

<?php include '../includes/footer.php'; ?>