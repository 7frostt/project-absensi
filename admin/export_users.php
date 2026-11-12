<?php
session_start();
require '../config/database.php';
require '../config/security.php';
date_default_timezone_set('Asia/Jakarta');

enforce_admin_access();

$format = isset($_GET['format']) ? $_GET['format'] : 'excel';
$filter_type = isset($_GET['type']) ? $_GET['type'] : '';
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

// Query data sesuai filter yang sedang aktif
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
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$filename = "Laporan_Data_Pengguna_" . date('Y-m-d');

if ($format === 'excel') {
    // Header HTTP untuk download file Excel (.xls)
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=\"$filename.xls\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo "<table border='0'>";
    echo "<tr><th colspan='10' style='font-size: 16px; font-weight: bold; text-align: center;'>SISTEM INFORMASI ABSENSI PKL, MAGANG & KARYAWAN</th></tr>";
    echo "<tr><th colspan='10' style='font-size: 13px; font-weight: bold; text-align: center;'>LAPORAN DATA PENGGUNA / SISWA</th></tr>";
    echo "<tr><td colspan='10' style='text-align: center; font-style: italic;'>Tanggal Cetak: " . date('d-m-Y H:i') . "</td></tr>";
    echo "<tr><td colspan='10'></td></tr>";
    echo "</table>";

    echo "<table border='1'>";
    echo "<thead>
            <tr style='background-color:#f2f2f2; font-weight: bold; text-align: center;'>
                <th>No</th>
                <th>Nama Lengkap</th>
                <th>Jenis Kelamin</th>
                <th>Username</th>
                <th>Email</th>
                <th>No. HP</th>
                <th>Jenis</th>
                <th>Instansi / Sekolah</th>
                <th>Divisi / Jurusan</th>
                <th>Status</th>
            </tr>
          </thead>";
    echo "<tbody>";
    foreach ($users as $i => $u) {
        echo "<tr>";
        echo "<td style='text-align: center;'>" . ($i + 1) . "</td>";
        echo "<td style='text-align: left;'>" . strtoupper(htmlspecialchars($u['name'])) . "</td>";
        echo "<td style='text-align: center;'>" . strtoupper(htmlspecialchars($u['gender'] ?? '-')) . "</td>";
        echo "<td style='text-align: left;'>" . htmlspecialchars($u['username']) . "</td>"; // Username tidak dikapital
        echo "<td style='text-align: left;'>" . htmlspecialchars($u['email']) . "</td>"; // Email tidak dikapital
        echo "<td style='mso-number-format:\"\\@\"; text-align: center;'>" . htmlspecialchars($u['phone']) . "</td>"; 
        echo "<td style='text-align: center;'>" . strtoupper(htmlspecialchars($u['user_type'])) . "</td>";
        echo "<td style='text-align: left;'>" . strtoupper(htmlspecialchars($u['institution'])) . "</td>";
        echo "<td style='text-align: left;'>" . strtoupper(htmlspecialchars($u['department'])) . "</td>";
        echo "<td style='text-align: center;'>" . strtoupper(htmlspecialchars($u['status'])) . "</td>";
        echo "</tr>";
    }
    echo "</tbody></table>";
    exit();

} elseif ($format === 'print') {
    // Tampilan siap cetak / Simpan ke PDF lewat browser
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title>Laporan Data Pengguna</title>
        <style>
            body { font-family: Arial, sans-serif; font-size: 11px; color: #333; margin: 15px; }
            .kop-surat { text-align: center; border-bottom: 3px double #333; padding-bottom: 10px; margin-bottom: 20px; }
            .kop-surat h2 { margin: 0; font-size: 16px; text-transform: uppercase; }
            .kop-surat h4 { margin: 4px 0 0 0; font-size: 13px; color: #555; }
            .kop-surat p { margin: 2px 0 0 0; font-size: 10px; color: #777; }
            
            table { width: 100%; border-collapse: collapse; margin-top: 5px; }
            th, td { border: 1px solid #444; padding: 5px 6px; text-align: left; font-size: 10px; }
            /* Kolom selain username (3) dan email (4) dibuat kapital */
            td:not(:nth-child(3)):not(:nth-child(4)), th { text-transform: uppercase; }
            th { background-color: #f2f2f2; text-align: center; font-weight: bold; }
            .text-center { text-align: center; }
            
            .footer-print { margin-top: 30px; float: right; text-align: center; font-size: 11px; width: 200px; }
            
            @media print {
                .no-print { display: none; }
            }
        </style>
    </head>
    <body onload="window.print()">
        <div class="no-print" style="text-align: right; margin-bottom: 15px;">
            <button onclick="window.print()" style="padding: 8px 15px; background: #0d6efd; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">Cetak / Simpan PDF</button>
        </div>

        <div class="kop-surat">
            <h2>SISTEM INFORMASI ABSENSI PKL, MAGANG & KARYAWAN</h2>
            <h4>LAPORAN DATA PENGGUNA / SISWA</h4>
            <p>Dicetak pada: <?= date('d-m-Y H:i') ?></p>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width: 25px;">No</th>
                    <th>Nama Lengkap</th>
                    <th>Jenis Kelamin</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>No. HP</th>
                    <th>Jenis</th>
                    <th>Instansi / Sekolah</th>
                    <th>Divisi / Jurusan</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $i => $u): ?>
                <tr>
                    <td class="text-center"><?= $i + 1 ?></td>
                    <td><?= strtoupper(htmlspecialchars($u['name'])) ?></td>
                    <td class="text-center"><?= strtoupper(htmlspecialchars($u['gender'] ?? '-')) ?></td>
                    <td><?= htmlspecialchars($u['username']) ?></td> <!-- Username tidak dikapital -->
                    <td><?= htmlspecialchars($u['email']) ?></td> <!-- Email tetap asli -->
                    <td class="text-center"><?= htmlspecialchars($u['phone']) ?></td>
                    <td class="text-center"><?= strtoupper(htmlspecialchars($u['user_type'])) ?></td>
                    <td><?= strtoupper(htmlspecialchars($u['institution'])) ?></td>
                    <td><?= strtoupper(htmlspecialchars($u['department'])) ?></td>
                    <td class="text-center"><?= strtoupper(htmlspecialchars($u['status'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="footer-print">
            <p>Mengetahui,</p>
            <br><br><br>
            <p><b>Administrator</b></p>
        </div>
    </body>
    </html>
    <?php
    exit();
}