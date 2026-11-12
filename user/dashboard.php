<?php
session_start();
require '../config/database.php';
require '../config/helpers.php';
require '../config/security.php';

date_default_timezone_set('Asia/Jakarta');
require '../admin/cek_alpa.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] == 'Admin') {
    header("Location: ../index.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
enforce_user_ownership($user_id);
verify_single_device_session($pdo);

$is_maintenance = is_maintenance($pdo);
$office         = get_office_location($pdo); // lat, lng, radius (diatur admin di Pengaturan)

$today     = date('Y-m-d');
$day_index = date('N');
$time_now  = date('H:i:s');

$nama_bulan = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
    '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
    '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
];
$nama_hari = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];
$tanggal_label = $nama_hari[(int)$day_index] . ', ' . date('d') . ' ' . $nama_bulan[date('m')] . ' ' . date('Y');

// 1. JADWAL OPERASIONAL HARI INI
$stmt_sched = $pdo->prepare("SELECT * FROM schedules WHERE day_index = ?");
$stmt_sched->execute([$day_index]);
$schedule = $stmt_sched->fetch();

$jam_masuk       = !empty($schedule['check_in']) ? $schedule['check_in'] : '07:45:00';
$batas_terlambat = !empty($schedule['batas_terlambat']) ? $schedule['batas_terlambat'] : '08:00:00';
$jam_pulang      = !empty($schedule['check_out']) ? $schedule['check_out'] : '16:00:00';
$otomatis_alpa   = get_setting($pdo, 'batas_alpa', !empty($schedule['batas_alpa']) ? $schedule['batas_alpa'] : '09:00:00');
$is_off          = isset($schedule['is_off']) ? $schedule['is_off'] : 0;

$stmt_libur = $pdo->prepare("SELECT name FROM holidays WHERE date = ?");
$stmt_libur->execute([$today]);
$libur_nasional = $stmt_libur->fetch();

if ($libur_nasional) {
    $is_off = 1;
    $ket_libur = "Libur Nasional: " . $libur_nasional['name'];
} else {
    $ket_libur = "Hari ini libur operasional";
}

// 2. DATA USER & ABSENSI HARI INI
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

$stmt_absen = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND date = ?");
$stmt_absen->execute([$user_id, $today]);
$absen_hari_ini = $stmt_absen->fetch();

// 3. HUKUMAN PUSH-UP & PENILAIAN IZIN JAM
$menit_terlambat = 0;
$izin_hasil      = null;
$izin_sampai     = null;
$tol_izin        = izin_jam_toleransi($pdo);
if ($absen_hari_ini && !empty($absen_hari_ini['check_in'])) {
    $waktu_masuk = strtotime($today . ' ' . $absen_hari_ini['check_in']);
    if (is_izin_jam_row($absen_hari_ini)) {
        $izin_hasil  = rekap_status($absen_hari_ini, $today, $tol_izin);
        $izin_sampai = izin_jam_sampai_of($absen_hari_ini);
        if ($izin_hasil === 'Terlambat' && $izin_sampai) {
            $batas_izin = strtotime($today . ' ' . $izin_sampai) + $tol_izin * 60;
            if ($waktu_masuk > $batas_izin) {
                $menit_terlambat = ceil(($waktu_masuk - $batas_izin) / 60);
            }
        }
    } elseif ($absen_hari_ini['status'] == 'Terlambat') {
        $waktu_jam_masuk = strtotime($today . ' ' . $jam_masuk);
        if ($waktu_masuk > $waktu_jam_masuk) {
            $menit_terlambat = ceil(($waktu_masuk - $waktu_jam_masuk) / 60);
        }
    }
}

// 4. STATISTIK BULANAN
$filter_bulan = isset($_GET['bulan']) ? sprintf("%02d", (int)$_GET['bulan']) : date('m');
$filter_tahun = isset($_GET['tahun']) ? (int)$_GET['tahun'] : (int)date('Y');

$stmt_stat = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND MONTH(date) = ? AND YEAR(date) = ?");
$stmt_stat->execute([$user_id, $filter_bulan, $filter_tahun]);

$statistik = ['Hadir' => 0, 'Terlambat' => 0, 'Izin Jam' => 0, 'Kunjungan' => 0, 'Izin Hari' => 0, 'Sakit' => 0, 'Cuti' => 0, 'Alpa' => 0];
while ($row = $stmt_stat->fetch()) {
    $rs = rekap_status($row, $today, $tol_izin);
    if ($rs !== null && array_key_exists($rs, $statistik)) {
        $statistik[$rs]++;
    }
}

function banner($tone, $icon, $html) {
    echo '<div class="status-banner banner-' . htmlspecialchars($tone) . ' mb-3">'
       . '<div class="status-icon"><i class="fas fa-' . $icon . '"></i></div>'
       . '<div class="flex-grow-1">' . $html . '</div>'
       . '</div>';
}

function modal_start($id, $title, $icon, $color, $action) {
    echo '<div class="modal fade" id="' . $id . '" tabindex="-1" aria-hidden="true">'
       . '<div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">'
       . '<div class="modal-content border shadow-sm rounded-3">'
       . '<form action="attendance.php" method="POST" enctype="multipart/form-data" onsubmit="this.querySelector(\'[type=submit]\').disabled=true;">'
       . '<div class="modal-header border-bottom py-3 px-4">'
       . '<h5 class="modal-title fw-bold text-dark"><i class="fas fa-' . $icon . ' me-2" style="color:' . $color . ';"></i>' . $title . '</h5>'
       . '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>'
       . '<div class="modal-body p-4 text-start"><input type="hidden" name="action" value="' . $action . '">';
}

function modal_end($color, $label = 'Kirim Pengajuan') {
    echo '</div><div class="modal-footer border-top py-3 px-4">'
       . '<button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Batal</button>'
       . '<button type="submit" class="btn text-white px-4 shadow-sm" style="background-color:' . $color . ';">' . $label . '</button>'
       . '</div></form></div></div></div>';
}

function modal_alasan($placeholder) {
    echo '<div class="mb-3"><label class="form-label small text-secondary fw-semibold">Alasan / Keterangan <span class="text-danger">*</span></label>'
       . '<textarea name="description" class="form-control" rows="3" maxlength="500" placeholder="' . $placeholder . '" required></textarea></div>';
}

function modal_file($label) {
    echo '<div class="mb-1"><label class="form-label small text-secondary fw-semibold">' . $label . '</label>'
       . '<input type="file" name="photo" class="form-control" accept=".jpg,.jpeg,.png,.pdf">'
       . '<small class="text-muted" style="font-size:11px;">JPG, PNG, atau PDF. Maksimal 2 MB.</small></div>';
}
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/navbar.php'; ?>

<main class="container px-3 pb-5 flex-grow-1" style="max-width: 900px;">

    <?php if (isset($_SESSION['pesan'])): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-3 mt-2 mb-4 small">
            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($_SESSION['pesan']); unset($_SESSION['pesan']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-3 mt-2 mb-4 small">
            <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- 1. USER GREETING BANNER -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h5 class="fw-bold mb-1 text-dark">Selamat Datang, <?= htmlspecialchars($user['name']) ?></h5>
            <div class="text-muted small d-flex flex-wrap align-items-center gap-2">
                <span><i class="far fa-calendar-alt text-primary me-1"></i><?= $tanggal_label ?></span>
                <span>&bull;</span>
                <span class="badge badge-subdued-primary"><?= htmlspecialchars($user['user_type']) ?></span>
                <?php if (!empty($user['institution'])): ?>
                    <span>&bull;</span>
                    <span><?= htmlspecialchars($user['institution']) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <a href="profile.php" class="btn btn-outline-secondary btn-sm shadow-sm">
            <i class="fas fa-user-circle me-1"></i>Profil Saya
        </a>
    </div>

    <!-- 2. ATTENDANCE STATION CARD -->
    <div class="card shadow-sm mb-4">
        <div class="card-body p-4">
            
            <!-- Live Time & Proximity GPS Header -->
            <div class="d-flex flex-wrap justify-content-between align-items-center pb-3 mb-3 border-bottom gap-2">
                <div>
                    <div class="text-uppercase text-muted fw-bold" style="font-size: 11px; letter-spacing: 0.5px;">Waktu Saat Ini</div>
                    <div class="d-flex align-items-baseline gap-2">
                        <div class="clock text-dark" id="jam-realtime" style="font-size: 2.2rem; font-weight: 700;"><?= date('H:i:s') ?></div>
                        <span class="badge bg-light text-secondary border fw-bold" style="font-size: 10px;">WIB</span>
                    </div>
                </div>

                <div class="text-sm-end">
                    <div class="text-uppercase text-muted fw-bold" style="font-size: 11px; letter-spacing: 0.5px;">Radius Kantor</div>
                    <div class="small fw-semibold mt-1" id="status-lokasi">
                        <span class="text-secondary"><i class="fas fa-satellite-dish me-1 text-primary"></i>Maksimal <?= (int)$office['radius'] ?> meter<?= $is_maintenance ? ' (Maintenance: GPS Bebas)' : '' ?></span>
                    </div>
                    <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none mt-1 fw-semibold" onclick="cekLokasiSaya()" id="btn-cek-lokasi" style="font-size: 12px;">
                        <i class="fas fa-crosshairs me-1"></i>Periksa Jarak GPS Saya
                    </button>
                </div>
            </div>

            <!-- Operational Schedule Strip -->
            <div class="p-2.5 px-3 bg-light rounded-2 border mb-3">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 small">
                    <span class="fw-bold text-secondary text-uppercase" style="font-size: 11px;"><i class="fas fa-business-time text-primary me-1"></i>Jadwal Hari Ini:</span>
                    <?php if ($is_off == 1): ?>
                        <span class="badge bg-danger"><i class="fas fa-calendar-times me-1"></i><?= htmlspecialchars($ket_libur) ?></span>
                    <?php else: ?>
                        <div class="d-flex flex-wrap gap-2 gap-sm-3">
                            <span>Masuk: <strong class="text-success"><?= date('H:i', strtotime($jam_masuk)) ?></strong></span>
                            <span>Batas Telat: <strong class="text-warning"><?= date('H:i', strtotime($batas_terlambat)) ?></strong></span>
                            <span>Batas Alpa: <strong class="text-danger"><?= date('H:i', strtotime($otomatis_alpa)) ?></strong></span>
                            <span>Pulang: <strong class="text-primary"><?= date('H:i', strtotime($jam_pulang)) ?></strong></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Attendance Workflow Actions -->
            <?php
            $has_izin_jam_desc = ($absen_hari_ini && is_izin_jam_row($absen_hari_ini));
            $approval          = $absen_hari_ini['approval_status'] ?? null;
            $is_disetujui      = ($absen_hari_ini && $approval == 'Disetujui');
            $is_ditolak        = ($absen_hari_ini && $approval == 'Ditolak');
            $is_menunggu       = ($absen_hari_ini && in_array($approval ?? '', ['Pending', 'Menunggu Persetujuan']));
            $belum_check_in    = ($absen_hari_ini && empty($absen_hari_ini['check_in']));
            $perlu_klik_hadir  = ($has_izin_jam_desc && $is_disetujui && $belum_check_in);
            ?>

            <?php if ($perlu_klik_hadir): ?>
                <?php
                $est      = izin_jam_sampai_of($absen_hari_ini);
                $est_txt  = $est ? substr($est, 0, 5) : null;
                $sudah_lewat = $est && (strtotime($time_now) > strtotime($est) + $tol_izin * 60);
                if ($sudah_lewat) {
                    banner('warning', 'exclamation-triangle', 'Izin Jam disetujui, tetapi perkiraan tiba <strong>' . $est_txt . '</strong> sudah lewat. Jika hadir sekarang, kehadiran dihitung <strong class="text-danger">Terlambat</strong>.');
                } else {
                    banner('success', 'check-circle', 'Izin Jam Anda <strong class="text-success">DISETUJUI</strong>. Klik <strong class="text-success">HADIR</strong> setelah tiba di kantor' . ($est_txt ? ' (perkiraan tiba <strong>' . $est_txt . '</strong>).' : '.'));
                }
                ?>
                <form action="attendance.php" method="POST" id="form-hadir" class="m-0">
                    <input type="hidden" name="action" value="hadir">
                    <input type="hidden" name="latitude" id="latitude">
                    <input type="hidden" name="longitude" id="longitude">
                    <button type="button" class="btn btn-primary btn-lg w-100 py-3 fw-bold shadow-sm" onclick="absenHadir()" id="btn-hadir">
                        <i class="fas fa-sign-in-alt me-2"></i>Absen Hadir Sekarang
                    </button>
                </form>
                <div id="pesan-lokasi" class="pesan-lokasi mt-2 small fw-semibold"></div>

            <?php elseif ($is_menunggu): ?>
                <?php $label = $has_izin_jam_desc ? 'Izin Jam' : $absen_hari_ini['status']; ?>
                <?php banner('info', 'clock', 'Permohonan <strong class="text-primary">' . htmlspecialchars($label) . '</strong> Anda sedang menunggu verifikasi Administrator.'); ?>

            <?php elseif (!$absen_hari_ini || $is_ditolak): ?>
                <?php if ($is_ditolak): ?>
                    <?php banner('danger', 'times-circle', 'Permohonan <strong class="text-danger">' . htmlspecialchars($has_izin_jam_desc ? 'Izin Jam' : $absen_hari_ini['status']) . '</strong> Anda sebelumnya <strong class="text-danger">DITOLAK</strong>. Silakan ajukan ulang.'); ?>
                <?php endif; ?>

                <?php if ($is_off == 1): ?>
                    <?php banner('warning', 'info-circle', htmlspecialchars($ket_libur) . '. Gunakan tombol di bawah jika Anda bertugas kunjungan dinas luar.'); ?>
                <?php endif; ?>

                <!-- TOMBOL UTAMA ABSENSI (Hadir Kantor & Kunjungan Dinas) -->
                <div class="row g-2 mb-3">
                    <div class="col-12 col-md-7">
                        <form action="attendance.php" method="POST" id="form-hadir" class="m-0">
                            <input type="hidden" name="action" value="hadir">
                            <input type="hidden" name="latitude" id="latitude">
                            <input type="hidden" name="longitude" id="longitude">
                            <button type="button" class="btn btn-primary btn-lg w-100 py-3 fw-bold shadow-sm d-flex align-items-center justify-content-center gap-2" onclick="absenHadir()" id="btn-hadir">
                                <i class="fas fa-fingerprint fs-5"></i><span>Absen Hadir di Kantor</span>
                            </button>
                        </form>
                        <div id="pesan-lokasi" class="pesan-lokasi mt-1 small fw-semibold text-center"></div>
                    </div>
                    <div class="col-12 col-md-5">
                        <button type="button" class="btn btn-outline-primary btn-lg w-100 py-3 fw-bold shadow-sm d-flex align-items-center justify-content-center gap-2" data-bs-toggle="modal" data-bs-target="#modalKunjungan">
                            <i class="fas fa-briefcase fs-5"></i><span>Absen Kunjungan / Dinas</span>
                        </button>
                        <small class="text-muted d-block text-center mt-1" style="font-size: 11px;">Bekerja dinas di luar kantor (bebas radius)</small>
                    </div>
                </div>

                <!-- Clean Permission Form Buttons -->
                <div class="row g-2">
                    <div class="col-6 col-md-3">
                        <button type="button" class="btn btn-outline-secondary w-100 py-2 small fw-semibold d-inline-flex align-items-center justify-content-center gap-2" data-bs-toggle="modal" data-bs-target="#modalIzinJam">
                            <i class="fas fa-clock text-warning"></i><span>Izin Jam</span>
                        </button>
                    </div>
                    <div class="col-6 col-md-3">
                        <button type="button" class="btn btn-outline-secondary w-100 py-2 small fw-semibold d-inline-flex align-items-center justify-content-center gap-2" data-bs-toggle="modal" data-bs-target="#modalIzin">
                            <i class="fas fa-envelope text-primary"></i><span>Izin Hari</span>
                        </button>
                    </div>
                    <div class="col-6 col-md-3">
                        <button type="button" class="btn btn-outline-secondary w-100 py-2 small fw-semibold d-inline-flex align-items-center justify-content-center gap-2" data-bs-toggle="modal" data-bs-target="#modalSakit">
                            <i class="fas fa-notes-medical text-danger"></i><span>Sakit</span>
                        </button>
                    </div>
                    <div class="col-6 col-md-3">
                        <button type="button" class="btn btn-outline-secondary w-100 py-2 small fw-semibold d-inline-flex align-items-center justify-content-center gap-2" data-bs-toggle="modal" data-bs-target="#modalCuti">
                            <i class="fas fa-calendar-check text-info"></i><span>Cuti</span>
                        </button>
                    </div>
                </div>

            <?php else: ?>
                <!-- SUDAH ADA DATA ABSENSI -->
                <?php if ($is_disetujui): ?>
                    <?php if (!empty($absen_hari_ini['check_in'])): ?>

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <div class="p-3 bg-light rounded-2 border text-center">
                                    <div class="text-muted small">Waktu Masuk</div>
                                    <div class="fs-5 fw-bold text-dark mt-1"><?= !empty($absen_hari_ini['check_in']) ? substr($absen_hari_ini['check_in'], 0, 5) : '-' ?></div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-3 bg-light rounded-2 border text-center">
                                    <div class="text-muted small">Waktu Pulang</div>
                                    <div class="fs-5 fw-bold text-dark mt-1"><?= !empty($absen_hari_ini['check_out']) ? substr($absen_hari_ini['check_out'], 0, 5) : '-' ?></div>
                                </div>
                            </div>
                        </div>

                        <?php if ($absen_hari_ini['status'] === 'Kunjungan'): ?>
                            <?php banner('info', 'briefcase', 'Status: <strong class="text-primary fs-6">Kunjungan Dinas Luar</strong> &mdash; Anda tercatat bertugas aktif di luar kantor.'); ?>
                        <?php elseif ($has_izin_jam_desc): ?>
                            <?php if ($izin_hasil === 'Terlambat'): ?>
                                <?php banner('warning', 'exclamation-triangle', '<span class="d-block fw-bold text-uppercase" style="font-size:10px;letter-spacing:.5px;color:#b45309;">Status Kehadiran</span><strong class="fs-6">Terlambat</strong> &mdash; tiba melewati batas izin' . ($izin_sampai ? ' (' . substr($izin_sampai, 0, 5) . ')' : '') . '.'); ?>
                                <?php if ($menit_terlambat > 0): ?>
                                    <?php banner('danger', 'dumbbell', 'Melewati batas izin <strong class="text-danger">' . $menit_terlambat . ' menit</strong>. Silakan <strong class="text-danger">Push-up ' . $menit_terlambat . 'x</strong>!'); ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php banner('success', 'check-circle', '<span class="d-block text-success fw-bold text-uppercase" style="font-size:10px;letter-spacing:.5px;">Status Kehadiran</span><strong class="text-success fs-6">Izin Jam</strong> &mdash; tiba sesuai izin yang disetujui.'); ?>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php banner('success', 'check-circle', 'Absensi hari ini tercatat: <strong class="text-success">' . htmlspecialchars($absen_hari_ini['status']) . '</strong>.'); ?>
                            <?php if ($absen_hari_ini['status'] == 'Terlambat' && $menit_terlambat > 0): ?>
                                <?php banner('danger', 'dumbbell', 'Terlambat <strong class="text-danger">' . $menit_terlambat . ' menit</strong>. Silakan <strong class="text-danger">Push-up ' . $menit_terlambat . 'x</strong>!'); ?>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if (empty($absen_hari_ini['check_out'])): ?>
                            <?php 
                            $jam_izin_pulang = izin_jam_pulang_of($absen_hari_ini);
                            $bisa_pulang_awal = $jam_izin_pulang && strtotime($time_now) >= strtotime($jam_izin_pulang);
                            $bisa_pulang = ($absen_hari_ini['status'] === 'Kunjungan' || $bisa_pulang_awal || strtotime($time_now) >= strtotime($jam_pulang)); 
                            ?>

                            <?php if ($bisa_pulang_awal): ?>
                                <?php banner('info', 'door-open', 'Izin Pulang Lebih Awal telah disetujui untuk pukul <strong class="text-primary">' . date('H:i', strtotime($jam_izin_pulang)) . ' WIB</strong>. Tombol absen pulang sudah dibuka.'); ?>
                            <?php endif; ?>

                            <?php if ($bisa_pulang): ?>
                                <form action="attendance.php" method="POST" class="m-0" onsubmit="this.querySelector('button').disabled=true;">
                                    <input type="hidden" name="action" value="pulang">
                                    <button type="submit" class="btn btn-danger btn-lg w-100 py-3 fw-bold shadow-sm">
                                        <i class="fas fa-sign-out-alt me-2"></i>Absen Pulang Sekarang
                                    </button>
                                </form>
                            <?php else: ?>
                                <?php banner('muted', 'lock', 'Absen pulang reguler dibuka pukul <strong class="text-dark">' . date('H:i', strtotime($jam_pulang)) . ' WIB</strong>.'); ?>
                                <div class="text-center mt-2">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modalIzinJam">
                                        <i class="fas fa-door-open me-1"></i> Perlu pulang lebih awal? Ajukan Izin Pulang Awal
                                    </button>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php banner('info', 'check-double', 'Absensi hari ini telah selesai. Terima kasih atas kerja samanya!'); ?>
                        <?php endif; ?>

                    <?php else: ?>
                        <?php $label = $has_izin_jam_desc ? 'Izin Jam' : ($absen_hari_ini['status'] ?? 'Izin'); ?>
                        <?php banner('success', 'check-circle', 'Permohonan <strong class="text-success">' . htmlspecialchars($label) . '</strong> telah disetujui.'); ?>
                    <?php endif; ?>

                <?php elseif (($absen_hari_ini['status'] ?? '') == 'Alpa'): ?>
                    <?php banner('danger', 'user-times', 'Anda tercatat <strong class="text-danger">Alpa</strong> hari ini karena belum absen sampai batas waktu.'); ?>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Collapsible Office Map -->
            <div class="mt-3 pt-3 border-top text-center">
                <a class="small fw-semibold text-decoration-none text-muted" data-bs-toggle="collapse" href="#peta-kantor" role="button" aria-expanded="false" aria-controls="peta-kantor">
                    <i class="fas fa-map-marked-alt text-primary me-1"></i>Lihat Peta Lokasi Kantor & Radius
                </a>
                <div class="collapse mt-2" id="peta-kantor">
                    <div class="ratio ratio-21x9 rounded-2 overflow-hidden border">
                        <iframe title="Peta lokasi kantor" loading="lazy" style="border:0;"
                            src="https://www.openstreetmap.org/export/embed.html?bbox=<?= $office['lng'] - 0.004 ?>%2C<?= $office['lat'] - 0.003 ?>%2C<?= $office['lng'] + 0.004 ?>%2C<?= $office['lat'] + 0.003 ?>&amp;layer=mapnik&amp;marker=<?= $office['lat'] ?>%2C<?= $office['lng'] ?>"></iframe>
                    </div>
                    <div class="text-end mt-1">
                        <a href="https://www.google.com/maps?q=<?= $office['lat'] ?>,<?= $office['lng'] ?>" target="_blank" rel="noopener" class="small text-decoration-none">
                            Buka di Google Maps <i class="fas fa-external-link-alt ms-1"></i>
                        </a>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- 3. MONTHLY ATTENDANCE SUMMARY -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center py-3 px-4 gap-2 border-bottom">
            <div>
                <h6 class="fw-bold mb-0 text-dark"><i class="fas fa-chart-bar text-primary me-2"></i>Ringkasan Kehadiran</h6>
                <small class="text-muted">Bulan <?= $nama_bulan[$filter_bulan] ?> <?= $filter_tahun ?></small>
            </div>
            <form method="GET" class="d-flex align-items-center gap-2">
                <select name="bulan" class="form-select form-select-sm" style="min-width: 140px;" aria-label="Bulan" onchange="this.form.submit()">
                    <?php foreach ($nama_bulan as $k => $v): ?>
                        <option value="<?= $k ?>" <?= $k == $filter_bulan ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="tahun" class="form-select form-select-sm" style="min-width: 100px;" aria-label="Tahun" onchange="this.form.submit()">
                    <?php for ($t = date('Y') - 2; $t <= date('Y') + 1; $t++): ?>
                        <option value="<?= $t ?>" <?= $t == $filter_tahun ? 'selected' : '' ?>><?= $t ?></option>
                    <?php endfor; ?>
                </select>
            </form>
        </div>

        <div class="card-body p-4">
            <div class="row g-3">
                <!-- Hadir -->
                <div class="col-6 col-md-3">
                    <div class="stat-tile">
                        <div class="stat-tile-header">
                            <span class="lbl">Hadir Tepat</span>
                            <span class="icon-badge stat-icon-success"><i class="fas fa-check"></i></span>
                        </div>
                        <div class="num text-success"><?= (int)($statistik['Hadir'] ?? 0) ?></div>
                    </div>
                </div>

                <!-- Terlambat -->
                <div class="col-6 col-md-3">
                    <div class="stat-tile">
                        <div class="stat-tile-header">
                            <span class="lbl">Terlambat</span>
                            <span class="icon-badge stat-icon-warning"><i class="fas fa-clock"></i></span>
                        </div>
                        <div class="num text-warning"><?= (int)($statistik['Terlambat'] ?? 0) ?></div>
                    </div>
                </div>

                <!-- Izin, Sakit & Cuti -->
                <div class="col-6 col-md-3">
                    <div class="stat-tile">
                        <div class="stat-tile-header">
                            <span class="lbl">Dispensasi</span>
                            <span class="icon-badge stat-icon-primary"><i class="fas fa-file-medical"></i></span>
                        </div>
                        <div class="num text-primary">
                            <?= (int)(($statistik['Izin Jam'] ?? 0) + ($statistik['Izin Hari'] ?? 0) + ($statistik['Sakit'] ?? 0) + ($statistik['Cuti'] ?? 0)) ?>
                        </div>
                    </div>
                </div>

                <!-- Alpa -->
                <div class="col-6 col-md-3">
                    <div class="stat-tile">
                        <div class="stat-tile-header">
                            <span class="lbl">Alpa</span>
                            <span class="icon-badge stat-icon-danger"><i class="fas fa-user-times"></i></span>
                        </div>
                        <div class="num text-danger"><?= (int)($statistik['Alpa'] ?? 0) ?></div>
                    </div>
                </div>
            </div>

            <!-- Detail breakdown pills -->
            <div class="d-flex flex-wrap gap-2 pt-3 mt-3 border-top small text-muted">
                <span>Rincian dispensasi:</span>
                <span class="badge bg-light text-secondary border">Izin Jam: <strong><?= (int)($statistik['Izin Jam'] ?? 0) ?></strong></span>
                <span class="badge bg-light text-secondary border">Izin Hari: <strong><?= (int)($statistik['Izin Hari'] ?? 0) ?></strong></span>
                <span class="badge bg-light text-secondary border">Sakit: <strong><?= (int)($statistik['Sakit'] ?? 0) ?></strong></span>
                <span class="badge bg-light text-secondary border">Cuti: <strong><?= (int)($statistik['Cuti'] ?? 0) ?></strong></span>
                <span class="badge bg-light text-secondary border">Kunjungan: <strong><?= (int)($statistik['Kunjungan'] ?? 0) ?></strong></span>
            </div>
        </div>
    </div>

</main>

<!-- ================= MODAL PENGAJUAN ================= -->
<?php
// KUNJUNGAN / DINAS LUAR (Bekerja di luar kantor)
modal_start('modalKunjungan', 'Absen Kunjungan / Dinas Luar', 'briefcase', '#0284c7', 'kunjungan');
echo '<div class="alert alert-info py-2 px-3 small rounded-2 border mb-3">'
   . '<i class="fas fa-info-circle me-1"></i> Gunakan form ini jika Anda bertugas atau bekerja dinas di luar kantor. Jarak lokasi kantor <strong>tidak dibatasi</strong>.'
   . '</div>';
echo '<div class="mb-3">'
   . '<label class="form-label small text-secondary fw-semibold">Tempat / Instansi Tujuan Dinas <span class="text-danger">*</span></label>'
   . '<input type="text" name="instansi_kunjungan" class="form-control" placeholder="Contoh: PT Mitra Solusi / Disdik Kota" required>'
   . '</div>';
echo '<div class="mb-3">'
   . '<label class="form-label small text-secondary fw-semibold">Keperluan / Agenda Dinas <span class="text-danger">*</span></label>'
   . '<textarea name="description" class="form-control" rows="3" maxlength="500" placeholder="Tuliskan keperluan kunjungan atau tugas dinas..." required></textarea>'
   . '</div>';
modal_file('Foto di Lokasi Kunjungan / Surat Tugas (Opsional)');
echo '<input type="hidden" name="latitude" id="lat_kunjungan_modal">';
echo '<input type="hidden" name="longitude" id="lng_kunjungan_modal">';
echo '<div id="info-kunjungan-gps" class="small text-muted mt-2 mb-1"><i class="fas fa-map-marker-alt text-primary me-1"></i> Mengambil koordinat lokasi dinas...</div>';
modal_end('#0284c7', 'Kirim Absen Kunjungan');

// IZIN JAM (Datang Siang, Pulang Awal, atau Jam Tertentu)
modal_start('modalIzinJam', 'Form Pengajuan Izin Jam', 'clock', '#d97706', 'izin_jam');
echo '<div class="mb-3">'
   . '<label class="form-label small text-secondary fw-semibold">Kategori Izin Jam <span class="text-danger">*</span></label>'
   . '<div class="d-flex flex-column gap-2 p-2.5 rounded-2 border bg-light">'
   . '  <div class="form-check">'
   . '    <input class="form-check-input" type="radio" name="tipe_izin_jam" id="ij_datang" value="datang_siang" checked onchange="toggleIzinJamForm()">'
   . '    <label class="form-check-label small fw-semibold" for="ij_datang">'
   . '      <i class="fas fa-sun text-warning me-1"></i> Datang Agak Siang / Terlambat'
   . '      <small class="text-muted d-block fw-normal">Izin tiba di kantor melewati jam masuk normal tanpa dihitung terlambat.</small>'
   . '    </label>'
   . '  </div>'
   . '  <div class="form-check">'
   . '    <input class="form-check-input" type="radio" name="tipe_izin_jam" id="ij_pulang" value="pulang_awal" onchange="toggleIzinJamForm()">'
   . '    <label class="form-check-label small fw-semibold" for="ij_pulang">'
   . '      <i class="fas fa-door-open text-primary me-1"></i> Pulang Lebih Awal'
   . '      <small class="text-muted d-block fw-normal">Izin meninggalkan kantor sebelum jam operasional berakhir.</small>'
   . '    </label>'
   . '  </div>'
   . '  <div class="form-check">'
   . '    <input class="form-check-input" type="radio" name="tipe_izin_jam" id="ij_rentang" value="rentang" onchange="toggleIzinJamForm()">'
   . '    <label class="form-check-label small fw-semibold" for="ij_rentang">'
   . '      <i class="fas fa-clock text-secondary me-1"></i> Izin Keluar Sementara (Di Jam Kerja)'
   . '      <small class="text-muted d-block fw-normal">Izin keluar beberapa jam di tengah jam kerja untuk keperluan mendesak.</small>'
   . '    </label>'
   . '  </div>'
   . '</div>'
   . '</div>';

echo '<div class="mb-3" id="group_ij_datang">'
   . '<label class="form-label small text-secondary fw-semibold">Perkiraan Jam Tiba di Kantor <span class="text-danger">*</span></label>'
   . '<input type="time" name="jam_sampai_siang" id="jam_sampai_siang" class="form-control" value="09:00">'
   . '<small class="text-muted" style="font-size: 11px;">Absen masuk sebelum jam ini tidak dihitung terlambat jika disetujui.</small>'
   . '</div>';

echo '<div class="mb-3 d-none" id="group_ij_pulang">'
   . '<label class="form-label small text-secondary fw-semibold">Jam Izin Pulang <span class="text-danger">*</span></label>'
   . '<input type="time" name="jam_mulai_pulang" id="jam_mulai_pulang" class="form-control" value="14:00">'
   . '<small class="text-muted" style="font-size: 11px;">Absen pulang akan dibuka mulai dari jam ini jika disetujui.</small>'
   . '</div>';

echo '<div class="row g-2 mb-3 d-none" id="group_ij_rentang">'
   . '<div class="col-6"><label class="form-label small text-secondary fw-semibold">Dari Jam <span class="text-danger">*</span></label><input type="time" name="jam_mulai_rentang" id="jam_mulai_rentang" class="form-control" value="10:00"></div>'
   . '<div class="col-6"><label class="form-label small text-secondary fw-semibold">Sampai Jam <span class="text-danger">*</span></label><input type="time" name="jam_sampai_rentang" id="jam_sampai_rentang" class="form-control" value="12:00"></div>'
   . '</div>';

modal_alasan('Tuliskan alasan permohonan izin jam...');
modal_file('Upload Bukti (Opsional)');
modal_end('#d97706');

// IZIN HARI (1 Hari Penuh atau Rentang Tanggal)
modal_start('modalIzin', 'Form Izin Hari', 'envelope', '#2563eb', 'izin_hari');
echo '<div class="mb-3">'
   . '<label class="form-label small text-secondary fw-semibold">Durasi Izin <span class="text-danger">*</span></label>'
   . '<div class="d-flex gap-3 p-2.5 rounded-2 border bg-light">'
   . '  <div class="form-check">'
   . '    <input class="form-check-input" type="radio" name="tipe_durasi_hari" id="ih_sehari" value="1" checked onchange="toggleIzinHariForm()">'
   . '    <label class="form-check-label small fw-semibold" for="ih_sehari"><i class="fas fa-calendar-day text-primary me-1"></i> 1 Hari Penuh</label>'
   . '  </div>'
   . '  <div class="form-check">'
   . '    <input class="form-check-input" type="radio" name="tipe_durasi_hari" id="ih_rentang" value="range" onchange="toggleIzinHariForm()">'
   . '    <label class="form-check-label small fw-semibold" for="ih_rentang"><i class="fas fa-calendar-week text-primary me-1"></i> Beberapa Hari (Rentang)</label>'
   . '  </div>'
   . '</div>'
   . '</div>';

echo '<div class="mb-3" id="group_ih_sehari">'
   . '<label class="form-label small text-secondary fw-semibold">Tanggal Izin <span class="text-danger">*</span></label>'
   . '<input type="date" name="tanggal_sehari" id="tanggal_sehari" class="form-control" value="' . $today . '">'
   . '</div>';

echo '<div class="row g-2 mb-3 d-none" id="group_ih_rentang">'
   . '<div class="col-6"><label class="form-label small text-secondary fw-semibold">Dari tanggal <span class="text-danger">*</span></label><input type="date" name="tanggal_mulai" id="tgl_mulai" class="form-control" value="' . $today . '" onchange="sinkronTanggal()"></div>'
   . '<div class="col-6"><label class="form-label small text-secondary fw-semibold">Sampai tanggal <span class="text-danger">*</span></label><input type="date" name="tanggal_selesai" id="tgl_selesai" class="form-control" value="' . $today . '" min="' . $today . '"></div>'
   . '</div>';

modal_alasan('Tuliskan alasan permohonan izin...');
modal_file('Upload Surat Izin / Bukti (Opsional)');
modal_end('#2563eb');

// SAKIT
modal_start('modalSakit', 'Form Sakit', 'medkit', '#dc2626', 'sakit');
modal_alasan('Tuliskan keterangan sakit...');
modal_file('Upload Surat Dokter / Bukti (Opsional)');
modal_end('#dc2626', 'Kirim Permohonan');

// CUTI
modal_start('modalCuti', 'Form Cuti', 'user-clock', '#0284c7', 'cuti');
modal_alasan('Tuliskan alasan cuti...');
modal_file('Upload Surat Pengajuan Cuti (Opsional)');
modal_end('#0284c7', 'Kirim Permohonan');
?>

<script>
const IS_MAINTENANCE = <?= json_encode($is_maintenance) ?>;
const OFFICE = {
    lat: <?= json_encode($office['lat']) ?>,
    lng: <?= json_encode($office['lng']) ?>,
    radius: <?= json_encode($office['radius']) ?>
};

// Jam realtime
setInterval(function () {
    const d = new Date();
    document.getElementById('jam-realtime').textContent =
        String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0') + ':' + String(d.getSeconds()).padStart(2, '0');
}, 1000);

function sinkronTanggal() {
    const m = document.getElementById('tgl_mulai'), s = document.getElementById('tgl_selesai');
    s.min = m.value;
    if (s.value < m.value) s.value = m.value;
}

function hitungJarak(lat1, lon1, lat2, lon2) {
    const R = 6371000, rad = Math.PI / 180;
    const dLat = (lat2 - lat1) * rad, dLon = (lon2 - lon1) * rad;
    const a = Math.sin(dLat / 2) ** 2 + Math.cos(lat1 * rad) * Math.cos(lat2 * rad) * Math.sin(dLon / 2) ** 2;
    return 2 * R * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

function pesanGpsError(err) {
    if (err && err.code === 1) return 'Izin lokasi ditolak browser. Klik ikon setelan di sebelah kiri URL dan pilih Izinkan Lokasi.';
    if (err && err.code === 3) return 'Pengambilan lokasi timeout. Layanan Lokasi di Windows/HP Anda belum aktif. Jika sedang testing di PC/laptop, aktifkan "Mode Maintenance" di menu Admin > Pengaturan Sistem.';
    return 'Layanan lokasi tidak tersedia di perangkat ini. Pastikan GPS atau Location Services di Windows/HP aktif.';
}

function tampilPesan(el, teks, kelas) {
    el.className = 'pesan-lokasi small fw-semibold ' + kelas;
    el.textContent = teks;
}

// Ambil GPS dengan fallback 2-tier (High Accuracy -> Standar Network/WiFi)
function absenDenganLokasi(formId, btnId, pesanId, latId, lngId) {
    const form = document.getElementById(formId);
    const btn = document.getElementById(btnId);
    const pesan = document.getElementById(pesanId);

    btn.disabled = true;
    if (IS_MAINTENANCE) { form.submit(); return; }

    if (!navigator.geolocation) {
        btn.disabled = false;
        tampilPesan(pesan, 'Perangkat Anda tidak mendukung GPS.', 'text-danger');
        return;
    }
    tampilPesan(pesan, 'Memverifikasi koordinat lokasi...', 'text-muted');

    function suksesPosisi(pos) {
        const jarak = hitungJarak(pos.coords.latitude, pos.coords.longitude, OFFICE.lat, OFFICE.lng);
        if (jarak > OFFICE.radius) {
            btn.disabled = false;
            tampilPesan(pesan, 'Anda berada ' + Math.round(jarak) + ' m dari kantor (maks. ' + OFFICE.radius + ' m). Absen gagal karena berada di luar area kantor.', 'text-danger');
            return;
        }
        document.getElementById(latId).value = pos.coords.latitude;
        document.getElementById(lngId).value = pos.coords.longitude;
        form.submit();
    }

    function gagalPosisi(err) {
        btn.disabled = false;
        tampilPesan(pesan, pesanGpsError(err), 'text-danger');
    }

    // Coba High Accuracy lebih dulu (timeout 5 detik)
    navigator.geolocation.getCurrentPosition(
        suksesPosisi,
        function (err) {
            // Jika timeout atau unavailable di PC/Laptop, coba fallback standar (WiFi / IP)
            if (err.code === 3 || err.code === 2) {
                tampilPesan(pesan, 'Menghubungkan ke lokasi jaringan...', 'text-muted');
                navigator.geolocation.getCurrentPosition(
                    suksesPosisi,
                    gagalPosisi,
                    { enableHighAccuracy: false, timeout: 8000, maximumAge: 60000 }
                );
            } else {
                gagalPosisi(err);
            }
        },
        { enableHighAccuracy: true, timeout: 5000, maximumAge: 10000 }
    );
}

function absenHadir() { absenDenganLokasi('form-hadir', 'btn-hadir', 'pesan-lokasi', 'latitude', 'longitude'); }
function absenKunjungan() { absenDenganLokasi('form-kunjungan', 'btn-kunjungan', 'pesan-lokasi-kunjungan', 'latitude_kunjungan', 'longitude_kunjungan'); }

// Tombol "Cek Jarak GPS Saya"
function cekLokasiSaya() {
    const out = document.getElementById('status-lokasi');
    const btn = document.getElementById('btn-cek-lokasi');
    if (!navigator.geolocation) { tampilPesan(out, 'Perangkat tidak mendukung GPS.', 'text-danger'); return; }
    btn.disabled = true;
    tampilPesan(out, 'Mengambil koordinat lokasi...', 'text-muted');

    function suksesCek(pos) {
        const jarak = Math.round(hitungJarak(pos.coords.latitude, pos.coords.longitude, OFFICE.lat, OFFICE.lng));
        const ok = jarak <= OFFICE.radius;
        tampilPesan(out, (ok ? '✓ Anda dalam jangkauan' : '✗ Di luar jangkauan') + ' (' + jarak + ' m dari kantor, maks ' + OFFICE.radius + ' m).', ok ? 'text-success' : 'text-danger');
        btn.disabled = false;
    }

    function gagalCek(err) {
        tampilPesan(out, pesanGpsError(err), 'text-danger');
        btn.disabled = false;
    }

    navigator.geolocation.getCurrentPosition(
        suksesCek,
        function (err) {
            if (err.code === 3 || err.code === 2) {
                navigator.geolocation.getCurrentPosition(
                    suksesCek,
                    gagalCek,
                    { enableHighAccuracy: false, timeout: 8000, maximumAge: 60000 }
                );
            } else {
                gagalCek(err);
            }
        },
        { enableHighAccuracy: true, timeout: 5000, maximumAge: 10000 }
    );
}

// Toggle input field Izin Jam sesuai kategori yang dipilih
function toggleIzinJamForm() {
    const elDatang = document.getElementById('ij_datang');
    const elPulang = document.getElementById('ij_pulang');
    const elRentang = document.getElementById('ij_rentang');
    if (!elDatang) return;

    const gDatang = document.getElementById('group_ij_datang');
    const gPulang = document.getElementById('group_ij_pulang');
    const gRentang = document.getElementById('group_ij_rentang');

    if (gDatang) gDatang.classList.toggle('d-none', !elDatang.checked);
    if (gPulang) gPulang.classList.toggle('d-none', !elPulang.checked);
    if (gRentang) gRentang.classList.toggle('d-none', !elRentang.checked);
}

// Toggle input field Izin Hari (1 Hari vs Rentang)
function toggleIzinHariForm() {
    const elSehari = document.getElementById('ih_sehari');
    if (!elSehari) return;

    const gSehari = document.getElementById('group_ih_sehari');
    const gRentang = document.getElementById('group_ih_rentang');

    if (gSehari) gSehari.classList.toggle('d-none', !elSehari.checked);
    if (gRentang) gRentang.classList.toggle('d-none', elSehari.checked);
}

// Siapkan GPS otomatis saat modal Kunjungan dibuka
const modalKunjunganEl = document.getElementById('modalKunjungan');
if (modalKunjunganEl) {
    modalKunjunganEl.addEventListener('show.bs.modal', function () {
        const info = document.getElementById('info-kunjungan-gps');
        if (!navigator.geolocation) {
            if (info) info.innerHTML = '<span class="text-muted"><i class="fas fa-info-circle me-1"></i> Perangkat tidak mendukung GPS</span>';
            return;
        }
        navigator.geolocation.getCurrentPosition(function (pos) {
            const latEl = document.getElementById('lat_kunjungan_modal');
            const lngEl = document.getElementById('lng_kunjungan_modal');
            if (latEl) latEl.value = pos.coords.latitude;
            if (lngEl) lngEl.value = pos.coords.longitude;
            if (info) info.innerHTML = '<span class="text-success fw-semibold"><i class="fas fa-check-circle me-1"></i> Titik lokasi dinas berhasil terdeteksi</span>';
        }, function () {
            if (info) info.innerHTML = '<span class="text-muted"><i class="fas fa-info-circle me-1"></i> Lokasi dinas tetap dapat dikirim</span>';
        }, { enableHighAccuracy: false, timeout: 6000, maximumAge: 60000 });
    });
}
</script>
<?php include '../includes/footer.php'; ?>
