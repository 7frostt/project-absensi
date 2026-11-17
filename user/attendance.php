<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require '../config/database.php';
require '../config/logger.php';
require '../config/helpers.php';
require '../config/security.php';
date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] == 'Admin') {
    header("Location: ../index.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
enforce_user_ownership($user_id);
verify_single_device_session($pdo);
$today    = date('Y-m-d');
$time_now = date('H:i:s');

// ---------------------------------------------------------------
// FUNGSI BANTU
// ---------------------------------------------------------------
function kembali(string $tipe, string $pesan): void {
    $_SESSION[$tipe] = $pesan; // $tipe: 'pesan' (sukses) atau 'error'
    header("Location: dashboard.php");
    exit();
}

/** Boleh membuat pengajuan/absen baru? (belum ada data, atau sebelumnya ditolak) */
function boleh_ajukan_baru($row): bool {
    return !$row || ($row['approval_status'] ?? '') === 'Ditolak';
}

function bersihkan_keterangan(string $s): string {
    return mb_substr(trim($s), 0, 500);
}

// Data hari ini
$day_index = (int) date('N');
$stmt_sched = $pdo->prepare("SELECT * FROM schedules WHERE day_index = ?");
$stmt_sched->execute([$day_index]);
$schedule = $stmt_sched->fetch();

$stmt_libur = $pdo->prepare("SELECT 1 FROM holidays WHERE date = ?");
$stmt_libur->execute([$today]);
$is_off = (!empty($schedule['is_off']) || $stmt_libur->fetchColumn()) ? true : false;

$stmt_ex = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND date = ?");
$stmt_ex->execute([$user_id, $today]);
$existing = $stmt_ex->fetch();

/**
 * Validasi lokasi GPS di sisi server.
 * Dilewati saat Mode Maintenance (sesuai keterangan di pengaturan admin).
 * @return string|null pesan error, atau null jika lolos. $lat/$lng terisi untuk disimpan.
 */
function validasi_lokasi(PDO $pdo, &$lat, &$lng, &$jarak) {
    $lat = $lng = $jarak = null;
    $in_lat = $_POST['latitude']  ?? '';
    $in_lng = $_POST['longitude'] ?? '';
    $ada    = valid_coordinates($in_lat, $in_lng);

    if ($ada) { $lat = (float) $in_lat; $lng = (float) $in_lng; }
    if (is_maintenance($pdo)) return null;

    if (!$ada) return "Lokasi GPS tidak terbaca. Aktifkan izin lokasi lalu coba lagi.";

    $office = get_office_location($pdo);
    $jarak  = haversine_m($lat, $lng, $office['lat'], $office['lng']);
    if ($jarak > $office['radius']) {
        return "Anda berada " . round($jarak) . " m dari lokasi kantor (maksimal " . $office['radius'] . " m). Absen hanya bisa dilakukan di area kantor.";
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // ===========================================================
    // 1. HADIR
    // ===========================================================
    if ($action == 'hadir') {
        if ($is_off) kembali('error', 'Hari ini libur. Gunakan absen kunjungan kantor.');

        if ($existing) {
            if (!empty($existing['check_in'])) kembali('error', 'Anda sudah absen masuk hari ini.');
            $izin_jam_disetujui = is_izin_jam_row($existing) && ($existing['approval_status'] ?? '') === 'Disetujui';
            if (!boleh_ajukan_baru($existing) && !$izin_jam_disetujui) {
                kembali('error', 'Status absensi Anda hari ini tidak memungkinkan absen hadir.');
            }
        }

        if ($err = validasi_lokasi($pdo, $lat, $lng, $jarak)) kembali('error', $err);

        $izin_jam_aktif = $existing && is_izin_jam_row($existing) && ($existing['approval_status'] ?? '') === 'Disetujui';
        if ($izin_jam_aktif) {
            // Izin jam disetujui: sah jika tiba sebelum "perkiraan tiba" (+ toleransi), selebihnya Terlambat
            $sampai = izin_jam_sampai_of($existing);
            $batas  = $sampai !== null ? strtotime($sampai) + izin_jam_toleransi($pdo) * 60 : PHP_INT_MAX;
            $status = (strtotime($time_now) <= $batas) ? 'Izin Jam' : 'Terlambat';
        } else {
            $batas_terlambat = !empty($schedule['batas_terlambat']) ? $schedule['batas_terlambat'] : '08:00:00';
            $status = (strtotime($time_now) > strtotime($batas_terlambat)) ? 'Terlambat' : 'Hadir';
        }

        if ($existing) {
            if (($existing['approval_status'] ?? '') === 'Ditolak') {
                // Pengajuan sebelumnya ditolak -> absen hadir biasa
                $stmt = $pdo->prepare("UPDATE attendance SET check_in = ?, status = ?, latitude = ?, longitude = ?, approval_status = 'Disetujui', description = NULL, photo = NULL WHERE id = ?");
            } else {
                // Izin Jam sudah disetujui -> keterangan izin tetap dipertahankan
                $stmt = $pdo->prepare("UPDATE attendance SET check_in = ?, status = ?, latitude = ?, longitude = ? WHERE id = ?");
            }
            $stmt->execute([$time_now, $status, $lat, $lng, $existing['id']]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO attendance (user_id, date, check_in, status, latitude, longitude, approval_status) VALUES (?, ?, ?, ?, ?, ?, 'Disetujui')");
            $stmt->execute([$user_id, $today, $time_now, $status, $lat, $lng]);
        }
        logActivity($pdo, $user_id, "Absen masuk (" . ($status === 'Izin Jam' ? 'sesuai izin jam' : $status) . ")" . ($jarak !== null ? ", jarak " . round($jarak) . " m dari kantor" : ""));
        kembali('pesan', 'Absensi berhasil dicatat!');
    }

    // ===========================================================
    // 2. PULANG
    // ===========================================================
    elseif ($action == 'pulang') {
        if (!$existing || empty($existing['check_in']) || ($existing['approval_status'] ?? '') !== 'Disetujui') {
            kembali('error', 'Anda belum absen masuk hari ini.');
        }
        if (!empty($existing['check_out'])) kembali('error', 'Anda sudah absen pulang hari ini.');

        $jam_pulang = !empty($schedule['check_out']) ? $schedule['check_out'] : '16:00:00';
        
        // Cek apakah ada izin pulang lebih awal yang disetujui admin
        $izin_pulang_awal = false;
        $jam_izin_pulang = izin_jam_pulang_of($existing);
        if ($jam_izin_pulang && ($existing['approval_status'] ?? '') === 'Disetujui') {
            if (strtotime($time_now) >= strtotime($jam_izin_pulang)) {
                $izin_pulang_awal = true;
            }
        }

        if ($existing['status'] !== 'Kunjungan' && !$izin_pulang_awal && strtotime($time_now) < strtotime($jam_pulang)) {
            kembali('error', 'Absen pulang reguler baru dibuka pukul ' . date('H:i', strtotime($jam_pulang)) . ' WIB. Jika perlu pulang lebih awal, silakan ajukan Izin Pulang Lebih Awal.');
        }

        $stmt = $pdo->prepare("UPDATE attendance SET check_out = ? WHERE id = ?");
        $stmt->execute([$time_now, $existing['id']]);
        logActivity($pdo, $user_id, "Absen pulang" . ($izin_pulang_awal ? " (sesuai Izin Pulang Lebih Awal $jam_izin_pulang)" : ""));
        kembali('pesan', 'Absen pulang berhasil dicatat.');
    }

    // ===========================================================
    // 3. KUNJUNGAN / DINAS LUAR (Bekerja di Luar Kantor - Khusus Karyawan)
    // ===========================================================
    elseif ($action == 'kunjungan') {
        if (($_SESSION['user_type'] ?? '') !== 'Karyawan') {
            kembali('error', 'Fitur kunjungan dinas luar hanya diperuntukkan bagi karyawan.');
        }

        if (!boleh_ajukan_baru($existing)) kembali('error', 'Anda sudah memiliki catatan absensi hari ini.');

        $instansi = bersihkan_keterangan($_POST['instansi_kunjungan'] ?? '');
        $alasan   = bersihkan_keterangan($_POST['description'] ?? '');

        if ($instansi === '') kembali('error', 'Nama tempat / instansi tujuan dinas wajib diisi.');
        if ($alasan === '')   kembali('error', 'Keperluan tugas dinas wajib diisi.');

        $in_lat = $_POST['latitude']  ?? '';
        $in_lng = $_POST['longitude'] ?? '';
        $lat = valid_coordinates($in_lat, $in_lng) ? (float)$in_lat : null;
        $lng = valid_coordinates($in_lat, $in_lng) ? (float)$in_lng : null;

        [$photo, $up_err] = save_attendance_upload('photo', 'kunjungan');
        if ($up_err) kembali('error', $up_err);

        $description = "[Kunjungan: $instansi] " . $alasan;

        if ($existing) {
            $stmt = $pdo->prepare("UPDATE attendance SET check_in = ?, status = 'Kunjungan', latitude = ?, longitude = ?, approval_status = 'Disetujui', description = ?, photo = ? WHERE id = ?");
            $stmt->execute([$time_now, $lat, $lng, $description, $photo, $existing['id']]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO attendance (user_id, date, check_in, status, latitude, longitude, approval_status, description, photo) VALUES (?, ?, ?, 'Kunjungan', ?, ?, 'Disetujui', ?, ?)");
            $stmt->execute([$user_id, $today, $time_now, $lat, $lng, $description, $photo]);
        }
        logActivity($pdo, $user_id, "Absen kunjungan dinas luar ke $instansi" . ($lat && $lng ? " (koordinat: $lat, $lng)" : ""));
        kembali('pesan', 'Absen Kunjungan Dinas Luar berhasil dicatat!');
    }

    // ===========================================================
    // 4. IZIN JAM (Datang Siang, Pulang Awal, atau Izin Jam Tertentu)
    // ===========================================================
    elseif ($action == 'izin_jam') {
        if ($is_off) kembali('error', 'Hari ini libur, tidak perlu mengajukan izin jam.');

        $tipe = $_POST['tipe_izin_jam'] ?? 'datang_siang';
        $alasan = bersihkan_keterangan($_POST['description'] ?? '');
        if ($alasan === '') kembali('error', 'Alasan izin wajib diisi.');

        $jam_mulai  = null;
        $jam_sampai = null;

        if ($tipe === 'pulang_awal') {
            $jam_mulai = normalize_time($_POST['jam_mulai_pulang'] ?? null);
            if (!$jam_mulai) kembali('error', 'Jam izin pulang tidak valid.');
            $description = "[Izin Jam - Pulang Lebih Awal: $jam_mulai] " . $alasan;
        } elseif ($tipe === 'rentang') {
            $jam_mulai  = normalize_time($_POST['jam_mulai_rentang'] ?? null);
            $jam_sampai = normalize_time($_POST['jam_sampai_rentang'] ?? null);
            if (!$jam_mulai || !$jam_sampai) kembali('error', 'Format jam tidak valid.');
            if ($jam_sampai <= $jam_mulai)   kembali('error', 'Jam selesai harus lebih besar dari jam mulai.');
            $description = "[Izin Jam: $jam_mulai s.d $jam_sampai] " . $alasan;
        } else {
            // datang_siang (default)
            $jam_mulai  = !empty($schedule['check_in']) ? $schedule['check_in'] : '07:45:00';
            $jam_sampai = normalize_time($_POST['jam_sampai_siang'] ?? null);
            if (!$jam_sampai) kembali('error', 'Perkiraan jam tiba tidak valid.');
            $description = "[Izin Jam - Datang Terlambat: s.d $jam_sampai] " . $alasan;
        }

        // Boleh ajukan jika belum ada catatan, atau pengajuan sebelumnya ditolak,
        // ATAU jika sudah absen masuk tapi mengajukan izin pulang lebih awal!
        if ($existing) {
            $boleh_pulang_awal = ($tipe === 'pulang_awal' && !empty($existing['check_in']) && empty($existing['check_out']));
            if (!boleh_ajukan_baru($existing) && !$boleh_pulang_awal) {
                kembali('error', 'Anda sudah memiliki catatan absensi hari ini.');
            }
        }

        [$photo, $up_err] = save_attendance_upload('photo', 'izin_jam');
        if ($up_err) kembali('error', $up_err);

        $pakai_kolom = izin_cols_available($pdo);
        if ($existing) {
            if ($tipe === 'pulang_awal' && !empty($existing['check_in'])) {
                // Update baris absensi yang sudah check-in
                $desc_gabung = trim(($existing['description'] ?? '') . ' ' . $description);
                $stmt = $pdo->prepare("UPDATE attendance SET description = ?, izin_jam_mulai = ?, approval_status = 'Menunggu Persetujuan', photo = IFNULL(?, photo) WHERE id = ?");
                $stmt->execute([$desc_gabung, $jam_mulai, $photo, $existing['id']]);
            } else {
                $stmt = $pdo->prepare("UPDATE attendance SET status = 'Izin Jam', description = ?, approval_status = 'Menunggu Persetujuan', photo = ?"
                    . ($pakai_kolom ? ", izin_jam_mulai = ?, izin_jam_sampai = ?" : "") . " WHERE id = ?");
                $stmt->execute(array_merge([$description, $photo], $pakai_kolom ? [$jam_mulai, $jam_sampai] : [], [$existing['id']]));
            }
        } else {
            $stmt = $pdo->prepare("INSERT INTO attendance (user_id, date, status, description, approval_status, photo"
                . ($pakai_kolom ? ", izin_jam_mulai, izin_jam_sampai" : "") . ") VALUES (?, ?, 'Izin Jam', ?, 'Menunggu Persetujuan', ?"
                . ($pakai_kolom ? ", ?, ?" : "") . ")");
            $stmt->execute(array_merge([$user_id, $today, $description, $photo], $pakai_kolom ? [$jam_mulai, $jam_sampai] : []));
        }
        logActivity($pdo, $user_id, "Mengajukan izin jam (" . ($tipe === 'pulang_awal' ? "Pulang Awal $jam_mulai" : "Tiba $jam_sampai") . ")");
        kembali('pesan', 'Pengajuan Izin Jam berhasil dikirim dan menunggu persetujuan Admin.');
    }

    // ===========================================================
    // 5. IZIN HARI (1 Hari Penuh atau Rentang Tanggal)
    // ===========================================================
    elseif ($action == 'izin_hari') {
        $tipe_durasi = $_POST['tipe_durasi_hari'] ?? '1';
        if ($tipe_durasi === '1') {
            $tgl_pilihan = $_POST['tanggal_sehari'] ?? $today;
            $d1 = DateTime::createFromFormat('!Y-m-d', $tgl_pilihan);
            $d2 = DateTime::createFromFormat('!Y-m-d', $tgl_pilihan);
        } else {
            $d1 = DateTime::createFromFormat('!Y-m-d', $_POST['tanggal_mulai'] ?? '');
            $d2 = DateTime::createFromFormat('!Y-m-d', $_POST['tanggal_selesai'] ?? '');
        }
        $alasan = bersihkan_keterangan($_POST['description'] ?? '');

        if (!$d1 || !$d2)  kembali('error', 'Tanggal izin harus diisi dengan benar.');
        if ($d2 < $d1)     kembali('error', 'Tanggal selesai tidak boleh sebelum tanggal mulai.');
        if ($d1->diff($d2)->days > 30) kembali('error', 'Rentang izin maksimal 31 hari.');
        if ($alasan === '') kembali('error', 'Alasan izin wajib diisi.');

        [$photo, $up_err] = save_attendance_upload('photo', 'izin_hari');
        if ($up_err) kembali('error', $up_err);

        // Hari libur mingguan & libur nasional dalam rentang tidak dibuatkan data izin
        $off_week = [];
        foreach ($pdo->query("SELECT day_index FROM schedules WHERE is_off = 1") as $r) $off_week[(int)$r['day_index']] = true;
        $stmt_h = $pdo->prepare("SELECT date FROM holidays WHERE date BETWEEN ? AND ?");
        $stmt_h->execute([$d1->format('Y-m-d'), $d2->format('Y-m-d')]);
        $off_dates = array_flip($stmt_h->fetchAll(PDO::FETCH_COLUMN));

        $stmt_cek = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND date = ?");
        $stmt_up  = $pdo->prepare("UPDATE attendance SET status = 'Izin Hari', check_in = NULL, check_out = NULL, description = ?, approval_status = 'Menunggu Persetujuan', photo = ? WHERE id = ?");
        $stmt_ins = $pdo->prepare("INSERT INTO attendance (user_id, date, status, description, approval_status, photo) VALUES (?, ?, 'Izin Hari', ?, 'Menunggu Persetujuan', ?)");

        $dibuat = 0; $dilewati = 0;
        for ($d = clone $d1; $d <= $d2; $d->modify('+1 day')) {
            $tgl = $d->format('Y-m-d');
            if (isset($off_week[(int)$d->format('N')]) || isset($off_dates[$tgl])) { $dilewati++; continue; }

            $stmt_cek->execute([$user_id, $tgl]);
            $row = $stmt_cek->fetch();
            if ($row && !boleh_ajukan_baru($row)) { $dilewati++; continue; } // jangan timpa absen yang sudah ada

            if ($row) $stmt_up->execute([$alasan, $photo, $row['id']]);
            else      $stmt_ins->execute([$user_id, $tgl, $alasan, $photo]);
            $dibuat++;
        }

        if ($dibuat === 0) kembali('error', 'Tidak ada hari yang bisa diajukan (semua hari libur atau sudah memiliki catatan absensi).');
        logActivity($pdo, $user_id, "Mengajukan izin hari: " . $d1->format('Y-m-d') . " s.d " . $d2->format('Y-m-d') . " ($dibuat hari)");
        kembali('pesan', "Pengajuan Izin Hari untuk $dibuat hari berhasil dikirim dan menunggu persetujuan Admin." . ($dilewati ? " ($dilewati hari dilewati: libur / sudah ada catatan)" : ""));
    }

    // ===========================================================
    // 6 & 7. SAKIT / CUTI (hari ini)
    // ===========================================================
    elseif ($action == 'sakit' || $action == 'cuti') {
        $label = $action == 'sakit' ? 'Sakit' : 'Cuti';
        if ($is_off) kembali('error', "Hari ini libur, tidak perlu mengajukan $label.");
        if (!boleh_ajukan_baru($existing)) kembali('error', 'Anda sudah memiliki catatan absensi hari ini.');

        $alasan = bersihkan_keterangan($_POST['description'] ?? '');
        if ($alasan === '') kembali('error', 'Alasan wajib diisi.');

        [$photo, $up_err] = save_attendance_upload('photo', $action);
        if ($up_err) kembali('error', $up_err);

        if ($existing) {
            $stmt = $pdo->prepare("UPDATE attendance SET status = ?, description = ?, approval_status = 'Menunggu Persetujuan', photo = ? WHERE id = ?");
            $stmt->execute([$label, $alasan, $photo, $existing['id']]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO attendance (user_id, date, status, description, approval_status, photo) VALUES (?, ?, ?, ?, 'Menunggu Persetujuan', ?)");
            $stmt->execute([$user_id, $today, $label, $alasan, $photo]);
        }
        logActivity($pdo, $user_id, "Mengajukan $label");
        kembali('pesan', "Pengajuan $label berhasil dikirim dan menunggu persetujuan Admin.");
    }
}

// Akses langsung tanpa aksi yang valid
header("Location: dashboard.php");
exit();
