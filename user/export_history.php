<?php
session_start();
require '../config/database.php';
require '../config/helpers.php';
require '../config/security.php';
date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] == 'Admin') {
    http_response_code(403);
    exit('403 Forbidden: Akses ditolak');
}

$user_id = (int)$_SESSION['user_id'];
enforce_user_ownership($user_id);
$tahun_ini = (int) date('Y');

// Filter (divalidasi)
$bulan = isset($_GET['bulan']) ? (int) $_GET['bulan'] : (int) date('n');
$tahun = isset($_GET['tahun']) ? (int) $_GET['tahun'] : $tahun_ini;
if ($bulan < 1 || $bulan > 12) $bulan = (int) date('n');
if ($tahun < $tahun_ini - 2 || $tahun > $tahun_ini) $tahun = $tahun_ini;
$bulan  = sprintf('%02d', $bulan);
$format = (($_GET['format'] ?? 'excel') === 'print') ? 'print' : 'excel';

// Data pengguna (hanya diri sendiri)
$stmt_user = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt_user->execute([$user_id]);
$u = $stmt_user->fetch();
if (!$u) exit('Pengguna tidak ditemukan');

$jumlah_hari = cal_days_in_month(CAL_GREGORIAN, (int) $bulan, $tahun);

$stmt_holidays = $pdo->prepare("SELECT date, name FROM holidays WHERE MONTH(date) = ? AND YEAR(date) = ?");
$stmt_holidays->execute([$bulan, $tahun]);
$holidays_raw = $stmt_holidays->fetchAll(PDO::FETCH_KEY_PAIR);

// Absensi bulan ini (aturan sama dengan export admin)
$stmt_att = $pdo->prepare("SELECT *, DAY(date) AS tgl FROM attendance WHERE user_id = ? AND MONTH(date) = ? AND YEAR(date) = ?");
$stmt_att->execute([$user_id, $bulan, $tahun]);
$attendance_data = [];
$today_ymd = date('Y-m-d');
$tol_izin  = izin_jam_toleransi($pdo);

while ($row = $stmt_att->fetch()) {
    // Aturan rekap sama dengan admin (lihat rekap_status di config/helpers.php)
    $st = rekap_status($row, $today_ymd, $tol_izin);
    if ($st !== null) {
        $attendance_data[$row['tgl']] = $st;
    }
}

$nama_bulan = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
    '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
    '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
];

// Hitung isi sel per tanggal + total
$tot = ['H' => 0, 'T' => 0, 'IJ' => 0, 'K' => 0, 'I' => 0, 'C' => 0, 'S' => 0, 'A' => 0];
$cells = [];       // [tanggal => huruf]
$libur_head = [];  // [tanggal => bool]
for ($d = 1; $d <= $jumlah_hari; $d++) {
    $tgl_str = sprintf("%s-%s-%02d", $tahun, $bulan, $d);
    $day_num = date('N', strtotime($tgl_str));
    $is_libur = ($day_num == 7 || isset($holidays_raw[$tgl_str]));
    $st = $attendance_data[$d] ?? null;

    if ($st == 'Hadir')                                                   $k = 'H';
    elseif ($st == 'Terlambat')                                           $k = 'T';
    elseif ($st == 'Kunjungan')                                           $k = 'K';
    elseif ($st == 'Izin Jam')                                            $k = 'IJ';
    elseif (in_array($st, ['Izin', 'Izin Hari']))                         $k = 'I';
    elseif ($st == 'Cuti')                                                $k = 'C';
    elseif ($st == 'Sakit')                                               $k = 'S';
    elseif ($st == 'Alpa')                                                $k = 'A';
    else                                                                  $k = $is_libur ? 'L' : '-';

    if (isset($tot[$k])) $tot[$k]++;
    $cells[$d] = $k;
    $libur_head[$d] = $is_libur;
}

$nama_file = trim(preg_replace('/[^A-Za-z0-9]+/', '_', $u['name']), '_');
$judul_file = "Rekap_Absensi_" . $nama_file . "_" . $nama_bulan[$bulan] . "_" . $tahun;

// ======================================================================
// EXCEL
// ======================================================================
if ($format === 'excel') {
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=\"$judul_file.xls\"");
    ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        table { border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; font-size: 11px; table-layout: fixed; }
        th, td { border: 1px solid #999999; padding: 5px 2px; text-align: center; vertical-align: middle; }
        .th-header { background-color: #f2f2f2; font-weight: bold; }
        .bg-libur { background-color: #ef4444; color: #ffffff; font-weight: bold; }
        .bg-h { background-color: #10b981; color: #ffffff; font-weight: bold; }
        .bg-t { background-color: #d63384; color: #ffffff; font-weight: bold; }
        .bg-ij { background-color: #fd7e14; color: #ffffff; font-weight: bold; }
        .bg-k { background-color: #17a2b8; color: #ffffff; font-weight: bold; }
        .bg-i { background-color: #0d6efd; color: #ffffff; font-weight: bold; }
        .bg-c { background-color: #20c997; color: #ffffff; font-weight: bold; }
        .bg-s { background-color: #6f42c1; color: #ffffff; font-weight: bold; }
        .bg-a { background-color: #ef4444; color: #ffffff; font-weight: bold; }
        .bg-l { background-color: #212529; color: #ffffff; font-weight: bold; }
        .bg-dash { background-color: #64748b; color: #ffffff; font-weight: bold; }
        .legend-cell { border: none; }
    </style>
</head>
<body>
    <h3 style="text-align: center; margin-bottom: 2px;">REKAP ABSENSI PKL, MAGANG &amp; KARYAWAN</h3>
    <p style="text-align: center; margin-top: 0;">PERIODE BULAN <?= strtoupper($nama_bulan[$bulan]) ?> TAHUN <?= $tahun ?></p>

    <table>
        <colgroup>
            <col style="width: 35px;">   <!-- No -->
            <col style="width: 150px;">  <!-- Nama -->
            <col style="width: 130px;">  <!-- Sekolah / Instansi -->
            <col style="width: 110px;">  <!-- Jurusan / Divisi -->
            <col style="width: 70px;">   <!-- Jenis -->
            <?php for ($d = 1; $d <= $jumlah_hari; $d++): ?>
                <col style="width: 25px;">
            <?php endfor; ?>
            <col style="width: 25px;"><col style="width: 25px;"><col style="width: 25px;"><col style="width: 25px;"><col style="width: 25px;"><col style="width: 25px;"><col style="width: 25px;"><col style="width: 25px;"> <!-- Total H,T,IJ,K,I,C,S,A -->
        </colgroup>
        <thead>
            <tr>
                <th rowspan="2" class="th-header">No</th>
                <th rowspan="2" class="th-header" style="text-align: left; padding-left: 5px;">Nama</th>
                <th rowspan="2" class="th-header" style="text-align: left; padding-left: 5px;">Sekolah / Instansi</th>
                <th rowspan="2" class="th-header" style="text-align: left; padding-left: 5px;">Jurusan / Divisi</th>
                <th rowspan="2" class="th-header">Jenis</th>
                <th colspan="<?= $jumlah_hari ?>" class="th-header"><?= strtoupper($nama_bulan[$bulan]) ?></th>
                <th colspan="8" class="th-header">Total</th>
            </tr>
            <tr>
                <?php for ($d = 1; $d <= $jumlah_hari; $d++): ?>
                    <th class="<?= $libur_head[$d] ? 'bg-libur' : 'th-header' ?>"><?= $d ?></th>
                <?php endfor; ?>
                <th class="bg-h">H</th>
                <th class="bg-t">T</th>
                <th class="bg-ij">I</th>
                <th class="bg-k">K</th>
                <th class="bg-i">I</th>
                <th class="bg-c">C</th>
                <th class="bg-s">S</th>
                <th class="bg-a">A</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>1</td>
                <td style="text-align: left; padding-left: 5px; font-weight: bold; text-transform: uppercase;"><?= strtoupper(htmlspecialchars($u['name'])) ?></td>
                <td style="text-align: left; padding-left: 5px;"><?= htmlspecialchars($u['institution'] ?: '-') ?></td>
                <td style="text-align: left; padding-left: 5px;"><?= htmlspecialchars($u['department'] ?: '-') ?></td>
                <td><?= htmlspecialchars($u['user_type']) ?></td>

                <?php for ($d = 1; $d <= $jumlah_hari; $d++): $k = $cells[$d]; ?>
                    <?php if ($k === '-'): ?>
                        <td>-</td>
                    <?php else: ?>
                        <td class="bg-<?= strtolower($k) ?>"><?= $k === 'IJ' ? 'I' : $k ?></td>
                    <?php endif; ?>
                <?php endfor; ?>

                <td style="font-weight: bold; background-color: #E7F8F2; color: #198754;"><?= $tot['H'] ?></td>
                <td style="font-weight: bold; background-color: #FBEBF3; color: #d63384;"><?= $tot['T'] ?></td>
                <td style="font-weight: bold; background-color: #FFF2E8; color: #fd7e14;"><?= $tot['IJ'] ?></td>
                <td style="font-weight: bold; background-color: #E8F6F8; color: #17a2b8;"><?= $tot['K'] ?></td>
                <td style="font-weight: bold; background-color: #E7F1FF; color: #0d6efd;"><?= $tot['I'] ?></td>
                <td style="font-weight: bold; background-color: #E4F9F3; color: #14a085;"><?= $tot['C'] ?></td>
                <td style="font-weight: bold; background-color: #F1ECF9; color: #6f42c1;"><?= $tot['S'] ?></td>
                <td style="font-weight: bold; background-color: #FDECEC; color: #dc3545;"><?= $tot['A'] ?></td>
            </tr>
        </tbody>
        <tbody>
            <tr><td colspan="5" style="border: none; height: 10px;"></td></tr>
            <tr>
                <td colspan="2" style="border: none; text-align: left; font-weight: bold;">Keterangan:</td>
                <td class="bg-h">H: Hadir</td>
                <td class="bg-t">T: Terlambat</td>
                <td class="bg-ij">I: Izin Jam</td>
            </tr>
            <tr>
                <td colspan="2" class="legend-cell"></td>
                <td class="bg-k">K: Kunjungan</td>
                <td class="bg-i">I: Izin</td>
                <td class="bg-c">C: Cuti</td>
            </tr>
            <tr>
                <td colspan="2" class="legend-cell"></td>
                <td class="bg-s">S: Sakit</td>
                <td class="bg-a">A: Alpa/Ditolak</td>
                <td class="bg-l">L: Libur</td>
            </tr>
            <tr>
                <td colspan="2" class="legend-cell"></td>
                <td class="bg-dash" style="font-size: 10px;">-: Belum/Absen Kosong</td>
                <td colspan="2" class="legend-cell"></td>
            </tr>
        </tbody>
    </table>
</body>
</html>
<?php
    exit();
}

// ======================================================================
// PDF (halaman cetak -> Simpan sebagai PDF)
// ======================================================================
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($judul_file) ?></title>
    <style>
        @page { size: A4 landscape; margin: 8mm; }
        body { font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 9px; color: #333; margin: 0; padding: 0; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        .header { text-align: center; margin-bottom: 10px; }
        .header h2 { margin: 0; font-size: 14px; text-transform: uppercase; }
        .header p { margin: 2px 0 0 0; font-size: 10px; font-weight: bold; color: #555; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 5px; table-layout: fixed; }
        th, td { border: 1px solid #444; padding: 3px 1px; text-align: center; font-size: 8.5px; overflow: hidden; }
        th { background-color: #f0f0f0; font-weight: bold; }
        
        .bg-libur-head { background-color: #ef4444 !important; color: #fff !important; }
        .bg-h { background-color: #10b981 !important; color: #fff !important; font-weight: bold; }
        .bg-t { background-color: #d63384 !important; color: #fff !important; font-weight: bold; }
        .bg-ij { background-color: #fd7e14 !important; color: #fff !important; font-weight: bold; }
        .bg-k { background-color: #17a2b8 !important; color: #fff !important; font-weight: bold; }
        .bg-i { background-color: #0d6efd !important; color: #fff !important; font-weight: bold; }
        .bg-c { background-color: #20c997 !important; color: #fff !important; font-weight: bold; }
        .bg-s { background-color: #6f42c1 !important; color: #fff !important; font-weight: bold; }
        .bg-a { background-color: #ef4444 !important; color: #fff !important; font-weight: bold; }
        .bg-l { background-color: #212529 !important; color: #fff !important; font-weight: bold; }
        .bg-dash { background-color: #64748b !important; color: #fff !important; font-weight: bold; }

        .legend { margin-top: 10px; font-size: 8.5px; display: flex; gap: 6px; flex-wrap: wrap; }
        .legend-item { display: inline-block; padding: 2px 5px; border-radius: 3px; font-weight: bold; }
    </style>
</head>
<body onload="window.print()">
    <div class="header">
        <h2>REKAP ABSENSI PKL, MAGANG &amp; KARYAWAN</h2>
        <p>PERIODE BULAN <?= strtoupper($nama_bulan[$bulan]) ?> TAHUN <?= $tahun ?></p>
    </div>

    <table>
        <colgroup>
            <col style="width: 25px;">   <!-- No -->
            <col style="width: 120px;">  <!-- Nama -->
            <col style="width: 100px;">  <!-- Sekolah / Instansi -->
            <col style="width: 90px;">   <!-- Jurusan / Divisi -->
            <col style="width: 60px;">   <!-- Jenis -->
            <?php for ($d = 1; $d <= $jumlah_hari; $d++): ?>
                <col style="width: 17px;">
            <?php endfor; ?>
            <col style="width: 19px;"><col style="width: 19px;"><col style="width: 19px;"><col style="width: 19px;"><col style="width: 19px;"><col style="width: 19px;"><col style="width: 19px;"><col style="width: 19px;"> <!-- Total H,T,IJ,K,I,C,S,A -->
        </colgroup>
        <thead>
            <tr>
                <th rowspan="2">No</th>
                <th rowspan="2" style="text-align: left; padding-left: 4px;">Nama</th>
                <th rowspan="2" style="text-align: left; padding-left: 4px;">Sekolah / Instansi</th>
                <th rowspan="2" style="text-align: left; padding-left: 4px;">Jurusan / Divisi</th>
                <th rowspan="2">Jenis</th>
                <th colspan="<?= $jumlah_hari ?>"><?= strtoupper($nama_bulan[$bulan]) ?></th>
                <th colspan="8">Total</th>
            </tr>
            <tr>
                <?php for ($d = 1; $d <= $jumlah_hari; $d++): ?>
                    <th class="<?= $libur_head[$d] ? 'bg-libur-head' : '' ?>"><?= $d ?></th>
                <?php endfor; ?>
                <th class="bg-h">H</th>
                <th class="bg-t">T</th>
                <th class="bg-ij">I</th>
                <th class="bg-k">K</th>
                <th class="bg-i">I</th>
                <th class="bg-c">C</th>
                <th class="bg-s">S</th>
                <th class="bg-a">A</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>1</td>
                <td style="text-align: left; padding-left: 4px; font-weight: bold; text-transform: uppercase;"><?= strtoupper(htmlspecialchars($u['name'])) ?></td>
                <td style="text-align: left; padding-left: 4px;"><?= htmlspecialchars($u['institution'] ?: '-') ?></td>
                <td style="text-align: left; padding-left: 4px;"><?= htmlspecialchars($u['department'] ?: '-') ?></td>
                <td><?= htmlspecialchars($u['user_type']) ?></td>

                <?php for ($d = 1; $d <= $jumlah_hari; $d++): $k = $cells[$d]; ?>
                    <?php if ($k === '-'): ?>
                        <td style="color: #888;">-</td>
                    <?php else: ?>
                        <td class="bg-<?= strtolower($k) ?>"><?= $k === 'IJ' ? 'I' : $k ?></td>
                    <?php endif; ?>
                <?php endfor; ?>

                <td style="font-weight: bold; background-color: #E7F8F2; color: #198754;"><?= $tot['H'] ?></td>
                <td style="font-weight: bold; background-color: #FBEBF3; color: #d63384;"><?= $tot['T'] ?></td>
                <td style="font-weight: bold; background-color: #FFF2E8; color: #fd7e14;"><?= $tot['IJ'] ?></td>
                <td style="font-weight: bold; background-color: #E8F6F8; color: #17a2b8;"><?= $tot['K'] ?></td>
                <td style="font-weight: bold; background-color: #E7F1FF; color: #0d6efd;"><?= $tot['I'] ?></td>
                <td style="font-weight: bold; background-color: #E4F9F3; color: #14a085;"><?= $tot['C'] ?></td>
                <td style="font-weight: bold; background-color: #F1ECF9; color: #6f42c1;"><?= $tot['S'] ?></td>
                <td style="font-weight: bold; background-color: #FDECEC; color: #dc3545;"><?= $tot['A'] ?></td>
            </tr>
        </tbody>
    </table>

    <div class="legend">
        <span class="legend-item bg-h">H: Hadir</span>
        <span class="legend-item bg-t">T: Terlambat</span>
        <span class="legend-item bg-ij">I: Izin Jam</span>
        <span class="legend-item bg-k">K: Kunjungan</span>
        <span class="legend-item bg-i">I: Izin</span>
        <span class="legend-item bg-c">C: Cuti</span>
        <span class="legend-item bg-s">S: Sakit</span>
        <span class="legend-item bg-a">A: Alpa/Ditolak</span>
        <span class="legend-item bg-l">L: Libur</span>
        <span class="legend-item bg-dash">-: Belum/Absen Kosong</span>
    </div>
</body>
</html>
