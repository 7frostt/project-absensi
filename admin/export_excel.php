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

$stmt_holidays = $pdo->prepare("SELECT date, name FROM holidays WHERE MONTH(date) = ? AND YEAR(date) = ?");
$stmt_holidays->execute([$bulan, $tahun]);
$holidays_raw = $stmt_holidays->fetchAll(PDO::FETCH_KEY_PAIR);

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

$filename = "Rekap_Absensi_" . $nama_bulan[$bulan] . "_" . $tahun . ".xls";

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=\"$filename\"");
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
    <h3 style="text-align: center; margin-bottom: 2px;">REKAP ABSENSI PKL, MAGANG & KARYAWAN</h3>
    <p style="text-align: center; margin-top: 0;">PERIODE BULAN <?= strtoupper($nama_bulan[$bulan]) ?> TAHUN <?= $tahun ?></p>

    <table>
        <colgroup>
            <col style="width: 35px;">   <!-- No -->
            <col style="width: 150px;">  <!-- Nama -->
            <col style="width: 35px;">   <!-- JK -->
            <col style="width: 130px;">  <!-- Sekolah / Instansi -->
            <col style="width: 110px;">  <!-- Jurusan / Divisi -->
            <col style="width: 70px;">   <!-- Jenis -->
            <?php for ($d = 1; $d <= $jumlah_hari; $d++): ?>
                <col style="width: 25px;"> <!-- Kolom Tanggal 1-31 -->
            <?php endfor; ?>
            <col style="width: 25px;"><col style="width: 25px;"><col style="width: 25px;"><col style="width: 25px;"><col style="width: 25px;"><col style="width: 25px;"><col style="width: 25px;"><col style="width: 25px;"> <!-- Total H,T,IJ,K,I,C,S,A -->
        </colgroup>
        <thead>
            <tr>
                <th rowspan="2" class="th-header">No</th>
                <th rowspan="2" class="th-header" style="text-align: left; padding-left: 5px;">Nama</th>
                <th rowspan="2" class="th-header">JK</th>
                <th rowspan="2" class="th-header" style="text-align: left; padding-left: 5px;">Sekolah / Instansi</th>
                <th rowspan="2" class="th-header" style="text-align: left; padding-left: 5px;">Jurusan / Divisi</th>
                <th rowspan="2" class="th-header">Jenis</th>
                <th colspan="<?= $jumlah_hari ?>" class="th-header"><?= strtoupper($nama_bulan[$bulan]) ?></th>
                <th colspan="8" class="th-header">Total</th>
            </tr>
            <tr>
                <?php for ($d = 1; $d <= $jumlah_hari; $d++): ?>
                    <?php 
                    $tgl_str = sprintf("%s-%s-%02d", $tahun, $bulan, $d);
                    $day_num = date('N', strtotime($tgl_str));
                    $is_libur = ($day_num == 7 || isset($holidays_raw[$tgl_str]));
                    ?>
                    <th class="<?= $is_libur ? 'bg-libur' : 'th-header' ?>"><?= $d ?></th>
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
            <?php $no = 1; foreach ($users as $u): ?>
                <?php $tot_h = $tot_t = $tot_ij = $tot_k = $tot_i = $tot_c = $tot_s = $tot_a = 0; ?>
                <tr>
                    <td><?= $no++ ?></td>
                    <td style="text-align: left; padding-left: 5px; font-weight: bold; text-transform: uppercase;"><?= strtoupper(htmlspecialchars($u['name'])) ?></td>
                    <td><?= ($u['gender'] ?? '') === 'Laki-laki' ? 'L' : (($u['gender'] ?? '') === 'Perempuan' ? 'P' : '-') ?></td>
                    <td style="text-align: left; padding-left: 5px;"><?= htmlspecialchars($u['institution'] ?: '-') ?></td>
                    <td style="text-align: left; padding-left: 5px;"><?= htmlspecialchars($u['department'] ?: '-') ?></td>
                    <td><?= htmlspecialchars($u['user_type']) ?></td>

                    <?php for ($d = 1; $d <= $jumlah_hari; $d++): ?>
                        <?php 
                        $tgl_str = sprintf("%s-%s-%02d", $tahun, $bulan, $d);
                        $day_num = date('N', strtotime($tgl_str));
                        $is_libur = ($day_num == 7 || isset($holidays_raw[$tgl_str]));
                        $st = $attendance_data[$u['id']][$d] ?? null;

                        if ($st == 'Hadir') {
                            $tot_h++;
                            echo '<td class="bg-h">H</td>';
                        } elseif ($st == 'Terlambat') {
                            $tot_t++;
                            echo '<td class="bg-t">T</td>';
                        } elseif ($st == 'Izin Jam') {
                            $tot_ij++;
                            echo '<td class="bg-ij">I</td>';
                        } elseif ($st == 'Kunjungan') {
                            $tot_k++;
                            echo '<td class="bg-k">K</td>';
                        } elseif (in_array($st, ['Izin', 'Izin Hari'])) {
                            $tot_i++;
                            echo '<td class="bg-i">I</td>';
                        } elseif ($st == 'Cuti') {
                            $tot_c++;
                            echo '<td class="bg-c">C</td>';
                        } elseif ($st == 'Sakit') {
                            $tot_s++;
                            echo '<td class="bg-s">S</td>';
                        } elseif ($st == 'Alpa') {
                            $tot_a++;
                            echo '<td class="bg-a">A</td>';
                        } else {
                            if ($is_libur) {
                                echo '<td class="bg-l">L</td>';
                            } else {
                                echo '<td>-</td>';
                            }
                        }
                        ?>
                    <?php endfor; ?>

                    <td style="font-weight: bold; background-color: #E7F8F2; color: #198754;"><?= $tot_h ?></td>
                    <td style="font-weight: bold; background-color: #FBEBF3; color: #d63384;"><?= $tot_t ?></td>
                    <td style="font-weight: bold; background-color: #FFF2E8; color: #fd7e14;"><?= $tot_ij ?></td>
                    <td style="font-weight: bold; background-color: #E8F6F8; color: #17a2b8;"><?= $tot_k ?></td>
                    <td style="font-weight: bold; background-color: #E7F1FF; color: #0d6efd;"><?= $tot_i ?></td>
                    <td style="font-weight: bold; background-color: #E4F9F3; color: #14a085;"><?= $tot_c ?></td>
                    <td style="font-weight: bold; background-color: #F1ECF9; color: #6f42c1;"><?= $tot_s ?></td>
                    <td style="font-weight: bold; background-color: #FDECEC; color: #dc3545;"><?= $tot_a ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tbody>
            <tr><td colspan="6" style="border: none; height: 10px;"></td></tr>
            <tr>
                <td colspan="3" style="border: none; text-align: left; font-weight: bold;">Keterangan:</td>
                <td class="bg-h">H: Hadir</td>
                <td class="bg-t">T: Terlambat</td>
                <td class="bg-ij">I: Izin Jam</td>
            </tr>
            <tr>
                <td colspan="3" class="legend-cell"></td>
                <td class="bg-k">K: Kunjungan</td>
                <td class="bg-i">I: Izin</td>
                <td class="bg-c">C: Cuti</td>
            </tr>
            <tr>
                <td colspan="3" class="legend-cell"></td>
                <td class="bg-s">S: Sakit</td>
                <td class="bg-a">A: Alpa/Ditolak</td>
                <td class="bg-l">L: Libur</td>
            </tr>
            <tr>
                <td colspan="3" class="legend-cell"></td>
                <td class="bg-dash" style="font-size: 10px;">-: Belum/Absen Kosong</td>
                <td colspan="2" class="legend-cell"></td>
            </tr>
            <tr>
                <td colspan="3" class="legend-cell"></td>
                <td colspan="3" style="border: none; text-align: left;"><b>JK:</b> Jenis Kelamin (<b>L</b> = Laki-laki, <b>P</b> = Perempuan)</td>
            </tr>
        </tbody>
    </table>
</body>
</html>