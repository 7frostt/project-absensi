<?php
session_start();
require '../config/database.php';
require '../config/helpers.php';
require '../config/security.php';
date_default_timezone_set('Asia/Jakarta');

enforce_admin_access();

$bulan = isset($_GET['bulan']) ? sprintf("%02d", $_GET['bulan']) : date('m');
$tahun = isset($_GET['tahun']) ? $_GET['tahun'] : date('Y');
$jenis = isset($_GET['jenis']) ? $_GET['jenis'] : 'Semua';

$jumlah_hari = cal_days_in_month(CAL_GREGORIAN, (int)$bulan, (int)$tahun);

// 1. AMBIL DATA HARI LIBUR NASIONAL / INSTANSI
$stmt_holidays = $pdo->prepare("SELECT date, name FROM holidays WHERE MONTH(date) = ? AND YEAR(date) = ?");
$stmt_holidays->execute([$bulan, $tahun]);
$holidays_raw = $stmt_holidays->fetchAll(PDO::FETCH_KEY_PAIR);

// 2. AMBIL DATA USER
$query_users = "SELECT * FROM users WHERE user_type != 'Admin'";
$params_users = [];
if ($jenis != 'Semua') {
    $query_users .= " AND user_type = ?";
    $params_users[] = $jenis;
}
$query_users .= " ORDER BY name ASC";
$stmt_users = $pdo->prepare($query_users);
$stmt_users->execute($params_users);
$users = $stmt_users->fetchAll();

// 3. AMBIL DATA ABSENSI BULANAN (Hanya yang Disetujui / Sah & Bersihkan dari Status Pending)
$stmt_att = $pdo->prepare("SELECT *, DAY(date) AS tgl FROM attendance WHERE MONTH(date) = ? AND YEAR(date) = ?");
$stmt_att->execute([$bulan, $tahun]);
$attendance_data = [];
$today_ymd = date('Y-m-d');
$tol_izin  = izin_jam_toleransi($pdo);

while ($row = $stmt_att->fetch()) {
    // Aturan rekap ada di config/helpers.php (rekap_status):
    // pending/ditolak tidak dihitung; izin jam dinilai Izin Jam / Terlambat / Alpa
    $st = rekap_status($row, $today_ymd, $tol_izin);
    if ($st !== null) {
        $attendance_data[$row['user_id']][$row['tgl']] = $st;
    }
}

$nama_bulan = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
    '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
    '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
];
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/navbar.php'; ?>

<div class="container pb-5 flex-grow-1">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="fw-bold mb-1"><i class="fas fa-file-alt text-primary me-2"></i>Rekap Absensi Bulanan (Harian)</h4>
        </div>
    </div>

    <!-- FILTER & EXPORT -->
    <div class="card border-0 shadow-sm rounded-3 mb-4">
        <div class="card-body p-3">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-3 col-6">
                    <label class="form-label small text-muted fw-semibold mb-1">Bulan</label>
                    <select name="bulan" class="form-select form-select-sm">
                        <?php foreach ($nama_bulan as $k => $v): ?>
                            <option value="<?= $k ?>" <?= $k == $bulan ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 col-6">
                    <label class="form-label small text-muted fw-semibold mb-1">Tahun</label>
                    <select name="tahun" class="form-select form-select-sm">
                        <?php 
                        $thn_now = date('Y');
                        for ($t = $thn_now - 2; $t <= $thn_now + 1; $t++): 
                        ?>
                            <option value="<?= $t ?>" <?= $t == $tahun ? 'selected' : '' ?>><?= $t ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-3 col-12">
                    <label class="form-label small text-muted fw-semibold mb-1">Jenis</label>
                    <select name="jenis" class="form-select form-select-sm">
                        <option value="Semua" <?= $jenis == 'Semua' ? 'selected' : '' ?>>Semua</option>
                        <option value="Siswa PKL" <?= $jenis == 'Siswa PKL' ? 'selected' : '' ?>>Siswa PKL</option>
                        <option value="Mahasiswa Magang" <?= $jenis == 'Mahasiswa Magang' ? 'selected' : '' ?>>Mahasiswa Magang</option>
                        <option value="Karyawan" <?= $jenis == 'Karyawan' ? 'selected' : '' ?>>Karyawan</option>
                    </select>
                </div>
                <div class="col-md-4 col-12">
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm rounded-3 w-100 fw-semibold">
                            <i class="fas fa-filter me-1"></i> Filter
                        </button>
                        <a href="export_excel.php?bulan=<?= $bulan ?>&tahun=<?= $tahun ?>&jenis=<?= urlencode($jenis) ?>" class="btn btn-success btn-sm rounded-3 w-100 fw-semibold text-nowrap">
                            <i class="fas fa-file-excel me-1"></i> Excel
                        </a>
                        <a href="export_pdf.php?bulan=<?= $bulan ?>&tahun=<?= $tahun ?>&jenis=<?= urlencode($jenis) ?>" target="_blank" class="btn btn-danger btn-sm rounded-3 w-100 fw-semibold text-nowrap">
                            <i class="fas fa-file-pdf me-1"></i> PDF
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- KETERANGAN / LEGEND BADGE (IZIN & CUTI TERPISAH) -->
    <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
        <span class="badge bg-success px-3 py-2">H: Hadir</span>
        <span class="badge text-white px-3 py-2" style="background-color: #d63384;">T: Terlambat</span>
        <span class="badge text-white px-3 py-2" style="background-color: #fd7e14;">I: Izin Jam</span>
        <span class="badge bg-info text-white px-3 py-2" style="background-color: #17a2b8 !important;">K: Kunjungan</span>
        <span class="badge bg-primary px-3 py-2" style="background-color: #0d6efd !important;">I: Izin</span>
        <span class="badge text-white px-3 py-2" style="background-color: #20c997;">C: Cuti</span>
        <span class="badge px-3 py-2 text-white" style="background-color: #6f42c1;">S: Sakit</span>
        <span class="badge bg-danger px-3 py-2">A: Alpa/Ditolak</span>
        <span class="badge bg-dark px-3 py-2">L: Libur (Minggu/Nasional)</span>
        <span class="badge bg-secondary px-3 py-2">-: Belum/Absen Kosong</span>
        <span class="badge bg-light text-dark border px-3 py-2">JK = Jenis Kelamin, L = Laki-laki, P = Perempuan</span>
    </div>

    <!-- TABEL REKAP -->
    <div class="card border-0 shadow-sm rounded-3 overflow-hidden">
        <div class="card-header text-center py-3">
            <h5 class="fw-bold text-uppercase mb-1">REKAP ABSENSI PKL, MAGANG & KARYAWAN</h5>
            <p class="text-muted small mb-0 fw-semibold">PERIODE BULAN <?= strtoupper($nama_bulan[$bulan]) ?> TAHUN <?= $tahun ?></p>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered align-middle text-center mb-0" style="font-size: 12px;">
                    <thead class="table-light">
                        <tr>
                            <th rowspan="2" class="align-middle" style="width: 40px;">No</th>
                            <th rowspan="2" class="align-middle text-start" style="min-width: 180px;">Nama</th>
                            <th rowspan="2" class="align-middle" style="width: 40px;" title="Jenis Kelamin">JK</th>
                            <th rowspan="2" class="align-middle text-start" style="min-width: 160px;">Sekolah / Instansi</th>
                            <th rowspan="2" class="align-middle text-start" style="min-width: 140px;">Jurusan / Divisi</th>
                            <th rowspan="2" class="align-middle" style="min-width: 110px;">Jenis</th>
                            <th colspan="<?= $jumlah_hari ?>" class="text-center text-uppercase fw-bold">
                                <?= strtoupper($nama_bulan[$bulan]) ?>
                            </th>
                            <th colspan="8" class="align-middle" style="min-width: 200px;">Total</th>
                        </tr>
                        <tr>
                            <?php for ($d = 1; $d <= $jumlah_hari; $d++): ?>
                                <?php 
                                $tgl_str = sprintf("%s-%s-%02d", $tahun, $bulan, $d);
                                $day_num = date('N', strtotime($tgl_str));
                                $is_libur = ($day_num == 7 || isset($holidays_raw[$tgl_str]));
                                $judul_libur = isset($holidays_raw[$tgl_str]) ? $holidays_raw[$tgl_str] : ($day_num == 7 ? 'Hari Minggu' : '');
                                ?>
                                <th class="<?= $is_libur ? 'bg-danger text-white' : '' ?>" style="width: 28px;" title="<?= htmlspecialchars($judul_libur) ?>">
                                    <?= $d ?>
                                </th>
                            <?php endfor; ?>
                            <th class="bg-success text-white" style="width: 25px;" title="Hadir">H</th>
                            <th class="text-white" style="width: 25px; background-color: #d63384;" title="Terlambat">T</th>
                            <th class="text-white" style="width: 25px; background-color: #fd7e14;" title="Izin Jam">I</th>
                            <th class="text-white" style="width: 25px; background-color: #17a2b8;" title="Kunjungan">K</th>
                            <th class="bg-primary text-white" style="width: 25px;" title="Izin">I</th>
                            <th class="text-white" style="width: 25px; background-color: #20c997;" title="Cuti">C</th>
                            <th class="text-white" style="width: 25px; background-color: #6f42c1;" title="Sakit">S</th>
                            <th class="bg-danger text-white" style="width: 25px;" title="Alpa">A</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="<?= $jumlah_hari + 14 ?>" class="py-4 text-muted">Data tidak ditemukan.</td>
                            </tr>
                        <?php else: ?>
                            <?php $no = 1; foreach ($users as $u): ?>
                                <?php $tot_h = $tot_t = $tot_ij = $tot_k = $tot_i = $tot_c = $tot_s = $tot_a = 0; ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td class="text-start fw-bold text-uppercase"><?= strtoupper(htmlspecialchars($u['name'])) ?></td>
                                    <?php $jk = $u['gender'] ?? ''; ?>
                                    <td>
                                        <?php if ($jk === 'Laki-laki'): ?>
                                            <span class="badge" style="background-color: rgba(13,110,253,.1); color: #0d6efd;" title="Laki-laki">L</span>
                                        <?php elseif ($jk === 'Perempuan'): ?>
                                            <span class="badge" style="background-color: rgba(214,51,132,.1); color: #d63384;" title="Perempuan">P</span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-start"><?= htmlspecialchars($u['institution'] ?: '-') ?></td>
                                    <td class="text-start"><?= htmlspecialchars($u['department'] ?: '-') ?></td>
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($u['user_type']) ?></span></td>

                                    <?php for ($d = 1; $d <= $jumlah_hari; $d++): ?>
                                        <?php 
                                        $tgl_str = sprintf("%s-%s-%02d", $tahun, $bulan, $d);
                                        $day_num = date('N', strtotime($tgl_str));
                                        $is_libur = ($day_num == 7 || isset($holidays_raw[$tgl_str]));

                                        $st = $attendance_data[$u['id']][$d] ?? null;

                                        if ($st == 'Hadir') {
                                            $tot_h++;
                                            echo '<td><span class="badge bg-success">H</span></td>';
                                        } elseif ($st == 'Terlambat') {
                                            $tot_t++;
                                            echo '<td><span class="badge text-white" style="background-color: #d63384;">T</span></td>';
                                        } elseif ($st == 'Izin Jam') {
                                            $tot_ij++;
                                            echo '<td><span class="badge text-white" style="background-color: #fd7e14;">I</span></td>';
                                        } elseif ($st == 'Kunjungan') {
                                            $tot_k++;
                                            echo '<td><span class="badge text-white" style="background-color: #17a2b8;">K</span></td>';
                                        } elseif (in_array($st, ['Izin', 'Izin Hari'])) {
                                            $tot_i++;
                                            echo '<td><span class="badge bg-primary">I</span></td>';
                                        } elseif ($st == 'Cuti') {
                                            $tot_c++;
                                            echo '<td><span class="badge text-white" style="background-color: #20c997;">C</span></td>';
                                        } elseif ($st == 'Sakit') {
                                            $tot_s++;
                                            echo '<td><span class="badge text-white" style="background-color: #6f42c1;">S</span></td>';
                                        } elseif ($st == 'Alpa') {
                                            $tot_a++;
                                            echo '<td><span class="badge bg-danger">A</span></td>';
                                        } else {
                                            if ($is_libur) {
                                                echo '<td><span class="badge bg-dark">L</span></td>';
                                            } else {
                                                echo '<td class="text-muted">-</td>';
                                            }
                                        }
                                        ?>
                                    <?php endfor; ?>

                                    <td class="fw-bold" style="background-color: rgba(16, 185, 129, 0.1); color: #198754;"><?= $tot_h ?></td>
                                    <td class="fw-bold" style="background-color: rgba(214, 51, 132, 0.1); color: #d63384;"><?= $tot_t ?></td>
                                    <td class="fw-bold" style="background-color: rgba(253, 126, 20, 0.1); color: #fd7e14;"><?= $tot_ij ?></td>
                                    <td class="fw-bold" style="background-color: rgba(23, 162, 184, 0.1); color: #17a2b8;"><?= $tot_k ?></td>
                                    <td class="fw-bold bg-primary bg-opacity-10 text-primary"><?= $tot_i ?></td>
                                    <td class="fw-bold" style="background-color: rgba(32, 201, 151, 0.12); color: #14a085;"><?= $tot_c ?></td>
                                    <td class="fw-bold" style="background-color: rgba(111, 66, 193, 0.1); color: #6f42c1;"><?= $tot_s ?></td>
                                    <td class="fw-bold" style="background-color: rgba(239, 68, 68, 0.1); color: #dc3545;"><?= $tot_a ?></td>
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