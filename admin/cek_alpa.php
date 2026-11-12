<?php
$today = date('Y-m-d');
$time_now = date('H:i:s');
$day_index = date('N'); 

// Cek Mode Maintenance
$stmt_maint = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_name = 'maintenance_mode' ORDER BY id DESC LIMIT 1");
$stmt_maint->execute();
$is_maintenance = $stmt_maint->fetchColumn();

if ($is_maintenance === '1') { 
    return; // Hentikan alpa otomatis jika maintenance aktif
}

// 1b. Izin jam yang disetujui tetapi tidak pernah dipakai (tidak absen sampai hari berakhir) -> Alpa
//     Dijalankan sebelum cek hari libur supaya data hari-hari lalu tetap dirapikan.
$stmt_izin_batal = $pdo->prepare("
    UPDATE attendance
    SET status = 'Alpa', approval_status = NULL,
        description = 'Tidak hadir (pengajuan izin jam disetujui, tetapi tidak melakukan absensi)'
    WHERE date < ?
      AND check_in IS NULL
      AND approval_status = 'Disetujui'
      AND (status = 'Izin Jam' OR (status = 'Terlambat' AND description LIKE '[Izin Jam:%'))
");
$stmt_izin_batal->execute([$today]);

// 2. Cek Libur Operasional Mingguan
$stmt_sched = $pdo->prepare("SELECT * FROM schedules WHERE day_index = ?");
$stmt_sched->execute([$day_index]);
$schedule = $stmt_sched->fetch();
if ($schedule && $schedule['is_off'] == 1) { return; }

// 3. Cek Hari Libur Nasional
$stmt_libur = $pdo->prepare("SELECT id FROM holidays WHERE date = ?");
$stmt_libur->execute([$today]);
if ($stmt_libur->rowCount() > 0) { return; }

// 4. Ambil Jam Batas Waktu Otomatis Alpa
$stmt_batas = $pdo->query("SELECT setting_value FROM settings WHERE setting_name = 'batas_alpa'");
$batas_alpa = $stmt_batas->fetchColumn() ?: '09:00:00';

// 5. Proses Eksekusi Otomatis Alpa setelah Lewat Batas Waktu
if ($time_now > $batas_alpa) {
    $stmt_missing = $pdo->prepare("
        SELECT id FROM users 
        WHERE user_type != 'Admin' 
        AND status = 'Aktif' 
        AND id NOT IN (
            SELECT user_id FROM attendance WHERE date = ?
        )
    ");
    $stmt_missing->execute([$today]);
    $users_missing = $stmt_missing->fetchAll();

    if (count($users_missing) > 0) {
        $stmt_insert = $pdo->prepare("INSERT INTO attendance (user_id, date, status, description) VALUES (?, ?, 'Alpa', 'Tidak melakukan absensi')");
        foreach ($users_missing as $u) { 
            $stmt_insert->execute([$u['id'], $today]); 
        }
    }
}
?>