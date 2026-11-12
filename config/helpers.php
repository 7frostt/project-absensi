<?php
/**
 * Fungsi bantu bersama (pengaturan, lokasi kantor, upload aman).
 * Pemakaian: require '../config/helpers.php'; (setelah database.php)
 */

// ---------------------------------------------------------------
// PENGATURAN
// ---------------------------------------------------------------
function get_setting(PDO $pdo, string $name, $default = null) {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_name = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$name]);
    $val = $stmt->fetchColumn();
    return ($val === false || $val === null || $val === '') ? $default : $val;
}

function set_setting(PDO $pdo, string $name, string $value): void {
    $stmt = $pdo->prepare("INSERT INTO settings (setting_name, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->execute([$name, $value, $value]);
}

function is_maintenance(PDO $pdo): bool {
    return get_setting($pdo, 'maintenance_mode', '0') === '1';
}

// ---------------------------------------------------------------
// LOKASI KANTOR
// ---------------------------------------------------------------
const OFFICE_DEFAULT_LAT    = -6.92252753127481;
const OFFICE_DEFAULT_LNG    = 106.9332846531051;
const OFFICE_DEFAULT_RADIUS = 100;

function get_office_location(PDO $pdo): array {
    return [
        'lat'    => (float) get_setting($pdo, 'office_lat', OFFICE_DEFAULT_LAT),
        'lng'    => (float) get_setting($pdo, 'office_lng', OFFICE_DEFAULT_LNG),
        'radius' => max(10, (int) get_setting($pdo, 'office_radius', OFFICE_DEFAULT_RADIUS)),
    ];
}

/** Jarak dua titik koordinat dalam meter (rumus Haversine). */
function haversine_m(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $R = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return 2 * $R * atan2(sqrt($a), sqrt(1 - $a));
}

function valid_coordinates($lat, $lng): bool {
    return is_numeric($lat) && is_numeric($lng)
        && $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180;
}

// ---------------------------------------------------------------
// WAKTU
// ---------------------------------------------------------------
/** "8:5", "08:05" atau "08:05:33" -> "08:05:00"; null jika tidak valid. */
function normalize_time(?string $t): ?string {
    if ($t === null || !preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($t), $m)) return null;
    if ((int)$m[1] > 23 || (int)$m[2] > 59) return null;
    return sprintf('%02d:%02d:00', $m[1], $m[2]);
}

// ---------------------------------------------------------------
// UPLOAD AMAN
// ---------------------------------------------------------------
/**
 * Simpan file upload bukti (jpg/png/pdf, maks 2 MB).
 * @return array [nama_file|null, pesan_error|null]
 *   - tidak ada file     => [null, null]
 *   - file tidak valid   => [null, "pesan"]
 */
function save_attendance_upload(string $field, string $prefix): array {
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        return [null, 'Upload file gagal, silakan coba lagi.'];
    }
    if ($f['size'] > 2 * 1024 * 1024) {
        return [null, 'Ukuran file maksimal 2 MB.'];
    }

    $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'];
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!isset($allowed[$ext])) {
        return [null, 'Format file harus JPG, PNG, atau PDF.'];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if ($mime !== $allowed[$ext]) {
        return [null, 'Isi file tidak sesuai dengan formatnya.'];
    }

    $dir = __DIR__ . '/../uploads/attendance/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        return [null, 'Folder upload tidak dapat dibuat.'];
    }
    // Pastikan file di folder ini tidak pernah dieksekusi sebagai script (Apache)
    $ht = $dir . '.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht, "<FilesMatch \"\\.(php|phtml|phar|php[0-9])$\">\n    Require all denied\n</FilesMatch>\nOptions -ExecCGI\n");
    }

    $name = $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . $name)) {
        return [null, 'Gagal menyimpan file.'];
    }
    return [$name, null];
}

// ---------------------------------------------------------------
// IZIN JAM
// ---------------------------------------------------------------
// Model data:
//   - status 'Izin Jam' + kolom izin_jam_mulai / izin_jam_sampai (perkiraan tiba)
//   - keterangan tetap berisi tag "[Izin Jam: HH:MM:SS s.d HH:MM:SS] alasan" (untuk tampilan lama)
//   - data lama (status 'Terlambat' + tag di keterangan) tetap dikenali lewat tag tersebut
//
// Aturan penilaian (dipakai rekap, export, dashboard, riwayat, data absensi admin):
//   izin disetujui + jam masuk <= perkiraan tiba (+toleransi)  => "Izin Jam"  (IJ, tidak dihitung terlambat)
//   izin disetujui + jam masuk  > perkiraan tiba (+toleransi)  => "Terlambat"
//   izin disetujui + tidak pernah absen sampai hari berakhir   => "Alpa"
//   izin masih menunggu / ditolak                              => tidak dihitung

/** Pastikan kolom jam izin ada (dibuat otomatis sekali). */
function izin_cols_available(PDO $pdo): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    $sess = (session_status() === PHP_SESSION_ACTIVE);
    if ($sess && !empty($_SESSION['izin_cols_ok'])) return $ok = true;
    try {
        $have = $pdo->query("SHOW COLUMNS FROM attendance LIKE 'izin_jam_sampai'")->fetch();
        if (!$have) {
            $pdo->exec("ALTER TABLE attendance
                ADD COLUMN izin_jam_mulai TIME NULL DEFAULT NULL AFTER check_out,
                ADD COLUMN izin_jam_sampai TIME NULL DEFAULT NULL AFTER izin_jam_mulai");
        }
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }
    if ($ok && $sess) $_SESSION['izin_cols_ok'] = true;
    return $ok;
}

/**
 * Data lama memakai nama "Izin Telat" -> disamakan menjadi "Izin Jam".
 * Aman dijalankan berulang (hanya menyentuh baris yang masih memakai nama lama).
 */
function izin_rapikan_data_lama(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $sess = (session_status() === PHP_SESSION_ACTIVE);
    if ($sess && !empty($_SESSION['izin_nama_ok'])) return;
    $lama = 'Izin ' . 'Telat';
    try {
        $s1 = $pdo->prepare("UPDATE attendance SET status = 'Izin Jam' WHERE status = ?");
        $s1->execute([$lama]);
        $s2 = $pdo->prepare("UPDATE attendance SET description = CONCAT('[Izin Jam:', SUBSTRING(description, ?)) WHERE description LIKE ?");
        $s2->execute([strlen('[' . $lama . ':') + 1, '[' . $lama . ':%']);
        $s3 = $pdo->prepare("UPDATE attendance SET description = REPLACE(description, 'pengajuan telat disetujui', 'pengajuan izin jam disetujui') WHERE description LIKE ?");
        $s3->execute(['%pengajuan telat disetujui%']);
        if ($sess) $_SESSION['izin_nama_ok'] = true;
    } catch (Throwable $e) {
        // abaikan: tidak boleh menggagalkan halaman
    }
}

/** Potongan SQL ", a.izin_jam_mulai, a.izin_jam_sampai" bila kolom tersedia. */
function izin_cols_sql(string $alias = ''): string {
    global $pdo;
    $p = $alias !== '' ? $alias . '.' : '';
    return izin_cols_available($pdo) ? ", {$p}izin_jam_mulai, {$p}izin_jam_sampai" : '';
}

function has_izin_tag($desc): bool {
    return is_string($desc) && preg_match('/^\s*\[Izin Jam/i', $desc) === 1;
}

/** Baris absensi ini pengajuan izin jam? */
function is_izin_jam_row(array $r): bool {
    $st = $r['status'] ?? '';
    if ($st === 'Izin Jam') return true;
    return ($st === 'Terlambat' || $st === 'Hadir') && has_izin_tag($r['description'] ?? '');
}

/** Perkiraan tiba (HH:MM:00) dari kolom, atau dari tag keterangan untuk data lama. */
function izin_jam_sampai_of(array $r): ?string {
    if (!empty($r['izin_jam_sampai'])) return normalize_time(substr($r['izin_jam_sampai'], 0, 8));
    if (preg_match('/\[Izin Jam(?: - Datang Terlambat)?:\s*(?:.*?\s*s\.d\s*)?(\d{1,2}:\d{2})/i', $r['description'] ?? '', $m)) {
        return normalize_time($m[1]);
    }
    return null;
}

/** Jam izin pulang lebih awal (HH:MM:00). */
function izin_jam_pulang_of(array $r): ?string {
    if (!empty($r['izin_jam_mulai']) && preg_match('/Pulang Lebih Awal/i', $r['description'] ?? '')) {
        return normalize_time(substr($r['izin_jam_mulai'], 0, 8));
    }
    if (preg_match('/Pulang Lebih Awal:\s*(\d{1,2}:\d{2})/i', $r['description'] ?? '', $m)) {
        return normalize_time($m[1]);
    }
    return null;
}

/** Render keterangan terformat untuk tampilan tabel dan modal. */
function format_desc_tag($desc): string {
    $desc = (string)$desc;
    if (empty($desc) || $desc === '-') return '-';

    // Datang Siang
    if (preg_match('/\[Izin Jam - Datang Terlambat:\s*(?:s\.d\s*)?(\d{2}:\d{2})\]\s*(.*)/i', $desc, $m)) {
        return '<div class="d-flex flex-column gap-1"><div><span class="badge bg-warning text-dark border px-2 py-0.5"><i class="fas fa-sun me-1"></i>Tiba ~' . $m[1] . ' WIB</span></div>'
             . ($m[2] !== '' ? '<div class="text-secondary small mt-0.5">' . htmlspecialchars($m[2]) . '</div>' : '') . '</div>';
    }
    // Pulang Awal
    if (preg_match('/\[Izin Jam - Pulang Lebih Awal:\s*(\d{2}:\d{2})\]\s*(.*)/i', $desc, $m)) {
        return '<div class="d-flex flex-column gap-1"><div><span class="badge bg-primary text-white border px-2 py-0.5"><i class="fas fa-door-open me-1"></i>Pulang ~' . $m[1] . ' WIB</span></div>'
             . ($m[2] !== '' ? '<div class="text-secondary small mt-0.5">' . htmlspecialchars($m[2]) . '</div>' : '') . '</div>';
    }
    // Rentang Jam
    if (preg_match('/\[Izin Jam:\s*(\d{2}:\d{2})(?::\d{2})?\s*s\.d\s*(\d{2}:\d{2})(?::\d{2})?\]\s*(.*)/i', $desc, $m)) {
        return '<div class="d-flex flex-column gap-1"><div><span class="badge bg-light text-dark border px-2 py-0.5"><i class="fas fa-clock text-warning me-1"></i>' . $m[1] . ' - ' . $m[2] . ' WIB</span></div>'
             . ($m[3] !== '' ? '<div class="text-secondary small mt-0.5">' . htmlspecialchars($m[3]) . '</div>' : '') . '</div>';
    }
    // Kunjungan
    if (preg_match('/\[Kunjungan:\s*([^\]]+)\]\s*(.*)/i', $desc, $m)) {
        return '<div class="d-flex flex-column gap-1"><div><span class="badge bg-info text-white border px-2 py-0.5"><i class="fas fa-briefcase me-1"></i>' . htmlspecialchars($m[1]) . '</span></div>'
             . ($m[2] !== '' ? '<div class="text-secondary small mt-0.5">' . htmlspecialchars($m[2]) . '</div>' : '') . '</div>';
    }
    return htmlspecialchars($desc);
}

/** Toleransi (menit) setelah perkiraan tiba, diatur admin di Pengaturan. */
function izin_jam_toleransi(PDO $pdo): int {
    return max(0, min(120, (int) get_setting($pdo, 'izin_jam_toleransi', 0)));
}

/**
 * Status final satu baris absensi untuk rekap/statistik.
 * Hasil: 'Hadir','Terlambat','Kunjungan','Izin Jam','Izin Hari','Sakit','Cuti','Alpa' atau null (tidak dihitung).
 */
function rekap_status(array $r, string $today, int $tol = 0): ?string {
    $st  = $r['status'] ?? '';
    $app = $r['approval_status'] ?? '';

    if (in_array($app, ['Pending', 'Menunggu Persetujuan', 'Ditolak'], true)) return null;
    if (!($app === 'Disetujui' || empty($app) || in_array($st, ['Hadir', 'Terlambat', 'Kunjungan', 'Alpa'], true))) return null;

    if (is_izin_jam_row($r)) {
        if (empty($r['check_in'])) {
            // belum/tidak hadir: baru dianggap Alpa setelah harinya lewat
            return (($r['date'] ?? $today) < $today) ? 'Alpa' : null;
        }
        $sampai = izin_jam_sampai_of($r);
        if ($sampai === null) return 'Izin Jam';
        return strtotime(substr($r['check_in'], 0, 8)) <= strtotime($sampai) + $tol * 60 ? 'Izin Jam' : 'Terlambat';
    }

    if ($st === 'Izin') return 'Izin Hari';
    return $st !== '' ? $st : null;
}

// Buat kolom otomatis pada pemakaian pertama (butuh hak ALTER; aman bila gagal)
if (isset($pdo) && $pdo instanceof PDO) {
    izin_cols_available($pdo);
    izin_rapikan_data_lama($pdo);
}
