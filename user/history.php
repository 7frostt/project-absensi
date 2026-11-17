<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require '../config/database.php';
require '../config/helpers.php';
require '../config/security.php';
date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] == 'Admin') {
    header("Location: ../index.php");
    exit();
}

$user_id   = (int)$_SESSION['user_id'];
enforce_user_ownership($user_id);
verify_single_device_session($pdo);
$today     = date('Y-m-d');
$tahun_ini = (int) date('Y');

// ------------------------------------------------------------------
// FILTER (divalidasi)
// ------------------------------------------------------------------
$bulan = isset($_GET['bulan']) ? (int) $_GET['bulan'] : (int) date('n');
$tahun = isset($_GET['tahun']) ? (int) $_GET['tahun'] : $tahun_ini;
if ($bulan < 1 || $bulan > 12) $bulan = (int) date('n');
if ($tahun < $tahun_ini - 2 || $tahun > $tahun_ini) $tahun = $tahun_ini;
$bulan = sprintf('%02d', $bulan);

$nama_bulan = [
    '01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April',
    '05'=>'Mei','06'=>'Juni','07'=>'Juli','08'=>'Agustus',
    '09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'
];
$nama_hari       = [1=>'Senin',2=>'Selasa',3=>'Rabu',4=>'Kamis',5=>'Jumat',6=>'Sabtu',7=>'Minggu'];
$nama_hari_short = [1=>'Sen',2=>'Sel',3=>'Rab',4=>'Kam',5=>'Jum',6=>'Sab',7=>'Min'];

// Gaya per status: [warna latar, warna teks, ikon]
$ST = [
    'Hadir'     => ['#16a34a', '#ffffff', 'check-circle'],
    'Terlambat' => ['#d63384', '#ffffff', 'exclamation-circle'],
    'Kunjungan' => ['#0284c7', '#ffffff', 'briefcase'],
    'Izin Jam'  => ['#d97706', '#ffffff', 'clock'],
    'Izin Hari' => ['#2563eb', '#ffffff', 'envelope'],
    'Sakit'     => ['#7c3aed', '#ffffff', 'notes-medical'],
    'Cuti'      => ['#059669', '#ffffff', 'umbrella-beach'],
    'Alpa'      => ['#dc3545', '#ffffff', 'user-times'],
    'Libur'     => ['#64748b', '#ffffff', 'calendar-times'],
];

function label_status(array $a, ?string $rs = null): string {
    if (($a['approval_status'] ?? '') === 'Ditolak') return 'Alpa';
    if (is_izin_jam_row($a)) {
        // Hasil penilaian: sah -> 'Izin Jam', melewati batas -> 'Terlambat', tidak hadir -> 'Alpa'
        if ($rs === 'Terlambat') return 'Terlambat';
        if ($rs === 'Alpa')      return 'Alpa';
        return 'Izin Jam';
    }
    $s = trim($a['status'] ?? '');
    if ($s === 'Izin') return 'Izin Hari';
    return $s !== '' ? $s : 'Alpa';
}

function format_desc($d): string {
    $d = trim((string) $d);
    if ($d === '') return '';
    if (preg_match('/^\[Izin Jam - Datang Terlambat:\s*(?:s\.d\s*)?(\d{2}:\d{2})\]\s*(.*)$/s', $d, $m)) {
        return 'Izin tiba ~' . $m[1] . ($m[2] !== '' ? ' · ' . $m[2] : '');
    }
    if (preg_match('/^\[Izin Jam - Pulang Lebih Awal:\s*(\d{2}:\d{2})\]\s*(.*)$/s', $d, $m)) {
        return 'Izin pulang ~' . $m[1] . ($m[2] !== '' ? ' · ' . $m[2] : '');
    }
    if (preg_match('/^\[Izin Jam:\s*(\d{2}:\d{2})(?::\d{2})?\s*s\.d\s*(\d{2}:\d{2})(?::\d{2})?\]\s*(.*)$/s', $d, $m)) {
        return 'Izin jam ' . $m[1] . '–' . $m[2] . ($m[3] !== '' ? ' · ' . $m[3] : '');
    }
    if (preg_match('/^\[Kunjungan:\s*([^\]]+)\]\s*(.*)$/s', $d, $m)) {
        return 'Dinas di ' . $m[1] . ($m[2] !== '' ? ' · ' . $m[2] : '');
    }
    return $d;
}

function hist_url($b, $t): string {
    return '?bulan=' . $b . '&tahun=' . $t;
}

// ------------------------------------------------------------------
// DATA
// ------------------------------------------------------------------
$stmt = $pdo->prepare("SELECT * FROM attendance WHERE user_id = ? AND MONTH(date) = ? AND YEAR(date) = ?");
$stmt->execute([$user_id, $bulan, $tahun]);
$attendance_map = [];
foreach ($stmt->fetchAll() as $row) $attendance_map[$row['date']] = $row;

$stmt_h = $pdo->prepare("SELECT date, name FROM holidays WHERE MONTH(date) = ? AND YEAR(date) = ?");
$stmt_h->execute([$bulan, $tahun]);
$holidays_map = [];
foreach ($stmt_h->fetchAll() as $h) $holidays_map[$h['date']] = $h['name'];

$off_days = [];
foreach ($pdo->query("SELECT day_index, is_off FROM schedules")->fetchAll() as $s) {
    if ($s['is_off'] == 1) $off_days[] = (int) $s['day_index'];
}

$tol_izin            = izin_jam_toleransi($pdo);
$jumlah_hari        = cal_days_in_month(CAL_GREGORIAN, (int) $bulan, $tahun);
$hari_pertama_index = (int) date('N', strtotime("$tahun-$bulan-01"));

// Bangun data per hari (dipakai kalender, daftar, dan ringkasan)
$days = [];
for ($d = 1; $d <= $jumlah_hari; $d++) {
    $date = sprintf('%04d-%s-%02d', $tahun, $bulan, $d);
    $dow  = (int) date('N', strtotime($date));
    $a    = $attendance_map[$date] ?? null;
    $hol  = $holidays_map[$date] ?? null;
    $weekly_off = in_array($dow, $off_days, true);
    $is_off = ($hol !== null) || $weekly_off;
    $ket_off = $hol !== null ? 'Libur Nasional: ' . $hol : ($weekly_off ? 'Libur Operasional' : '');

    $x = [
        'd' => $d, 'date' => $date, 'dow' => $dow, 'is_off' => $is_off, 'holiday' => ($hol !== null),
        'has_data' => (bool) $a, 'label' => null, 'in' => '-', 'out' => '-', 'desc' => '', 'pic' => '',
        'pending' => false, 'approval' => '', 'lat' => null, 'lng' => null,
    ];

    if ($a) {
        $rs            = rekap_status($a, $today, $tol_izin);
        $x['label']    = label_status($a, $rs);
        $x['in']       = !empty($a['check_in'])  ? substr($a['check_in'], 0, 5)  : '-';
        $x['out']      = !empty($a['check_out']) ? substr($a['check_out'], 0, 5) : '-';
        $x['desc']     = format_desc($a['description'] ?? '') ?: $ket_off;
        if (is_izin_jam_row($a) && ($a['approval_status'] ?? '') === 'Disetujui') {
            $sampai = izin_jam_sampai_of($a);
            if ($rs === 'Izin Jam') {
                $x['desc'] .= ' · Tiba sesuai izin, tidak dihitung terlambat';
            } elseif ($rs === 'Terlambat' && $sampai) {
                $x['desc'] .= ' · Tiba melewati batas izin (' . substr($sampai, 0, 5) . '), dihitung terlambat';
            } elseif ($rs === 'Alpa') {
                $x['desc'] .= ' · Izin disetujui tetapi tidak hadir, dihitung Alpa';
            } elseif ($rs === null && !empty($sampai)) {
                $x['desc'] .= ' · Perkiraan tiba ' . substr($sampai, 0, 5);
            }
        }
        $x['pic']      = $a['photo'] ?? '';
        $x['approval'] = $a['approval_status'] ?? '';
        $x['pending']  = in_array($x['approval'], ['Pending', 'Menunggu Persetujuan'], true);
        if (is_numeric($a['latitude'] ?? null) && is_numeric($a['longitude'] ?? null)) {
            $x['lat'] = (float) $a['latitude'];
            $x['lng'] = (float) $a['longitude'];
        }
    } elseif ($is_off) {
        $x['label'] = 'Libur';
        $x['desc']  = $ket_off;
    }

    $x['tgl'] = $nama_hari[$dow] . ', ' . $d . ' ' . $nama_bulan[$bulan] . ' ' . $tahun;
    $x['json'] = '';
    if ($x['label'] !== null) {
        $c = $ST[$x['label']] ?? $ST['Alpa'];
        $x['json'] = htmlspecialchars(json_encode([
            'tgl' => $x['tgl'], 'st' => $x['label'], 'bg' => $c[0], 'fg' => $c[1], 'ic' => $c[2],
            'in' => $x['in'], 'out' => $x['out'], 'desc' => $x['desc'], 'pic' => $x['pic'],
            'pending' => $x['pending'], 'approval' => $x['approval'], 'lat' => $x['lat'], 'lng' => $x['lng'],
        ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
    }
    $days[$d] = $x;
}

// Navigasi bulan
$cur  = DateTime::createFromFormat('!Y-m-d', "$tahun-$bulan-01");
$prev = (clone $cur)->modify('-1 month');
$next = (clone $cur)->modify('+1 month');
$can_prev = (int) $prev->format('Y') >= $tahun_ini - 2;
$can_next = $next->format('Y-m') <= date('Y-m');

// Hitung statistik bulanan
$stat = [
    'Hadir'     => 0,
    'Terlambat' => 0,
    'Kunjungan' => 0,
    'Izin Jam'  => 0,
    'Izin Hari' => 0,
    'Sakit'     => 0,
    'Cuti'      => 0,
    'Alpa'      => 0,
];
foreach ($days as $d => $x) {
    if (!empty($x['label']) && array_key_exists($x['label'], $stat)) {
        $stat[$x['label']]++;
    }
}
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/navbar.php'; ?>

<style>
    .hist-container { max-width: 1040px; margin: 0 auto; }
    
    /* Master Card Styling */
    .master-history-card {
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: 14px;
        overflow: hidden;
    }

    /* Grid & Headers */
    .cal-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 6px; }
    .cal-head-pill {
        background: var(--bg-card-subtle);
        border: 1px solid var(--border-color);
        border-radius: 8px;
        padding: 8px 4px;
        text-align: center;
        font-weight: 700;
        font-size: 12px;
        color: var(--text-muted);
        letter-spacing: 0.5px;
    }
    .cal-head-pill.is-sunday { color: #ef4444 !important; font-weight: 800; }

    /* Calendar Cells */
    .cal-cell {
        position: relative;
        appearance: none;
        width: 100%;
        font: inherit;
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        min-height: 56px;
        padding: 5px 3px;
        display: flex;
        flex-direction: column;
        justify-content: flex-start;
        align-items: center;
        gap: 3px;
        text-align: center;
        color: var(--text-main);
        transition: transform 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
    }
    .cal-cell.empty { background: transparent; border: 0; pointer-events: none; }
    .cal-cell.is-off { background: rgba(239, 68, 68, 0.05); border-color: rgba(239, 68, 68, 0.22); }
    .cal-cell.is-future { opacity: .45; background: rgba(148, 163, 184, 0.03); }
    .cal-cell.is-today { outline: 2px solid var(--primary-color) !important; outline-offset: -1px; }

    button.cal-cell { cursor: pointer; }
    button.cal-cell:hover, button.cal-cell:focus-visible {
        border-color: var(--primary-color);
        box-shadow: 0 4px 14px rgba(37, 99, 235, 0.16);
        transform: translateY(-1px);
        outline: none;
    }

    .cal-date-num {
        font-weight: 700;
        font-size: 13px;
        color: var(--text-main);
        line-height: 1;
        width: 22px;
        height: 22px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
    }
    .is-off .cal-date-num { color: #dc2626; }
    [data-theme="dark"] .is-off .cal-date-num { color: #f87171; }
    .is-today .cal-date-num { background: var(--primary-color); color: #ffffff !important; font-weight: 800; }

    .cal-dot { width: 9px; height: 9px; border-radius: 50%; display: block; }
    .cal-pill, .cal-time { display: none; }
    .cal-pending { position: absolute; top: 3px; right: 4px; font-size: 9px; color: #0284c7; }

    @media (min-width: 576px) {
        .cal-grid { gap: 8px; }
        .cal-cell { min-height: 84px; padding: 7px; align-items: stretch; text-align: left; }
        .cal-date-num { font-size: 14px; width: 24px; height: 24px; }
        .cal-dot { display: none; }
        .cal-pill {
            display: block;
            font-size: 10.5px;
            font-weight: 700;
            border-radius: 6px;
            padding: 3px 5px;
            text-align: center;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-top: 3px;
        }
        .cal-time {
            display: block;
            font-size: 10.5px;
            color: var(--text-muted);
            text-align: center;
            margin-top: 2px;
            font-weight: 500;
        }
        .cal-pending { top: 6px; right: 7px; }
    }

    /* KPI Summary Cards */
    .kpi-chip {
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: 10px;
        padding: 8px 10px;
        text-align: center;
    }

    /* Legenda */
    .legend-chip {
        display: inline-flex;
        align-items: center;
        font-size: 12px;
        color: var(--text-muted);
        background: var(--bg-card-subtle);
        border: 1px solid var(--border-color);
        padding: 4px 9px;
        border-radius: 20px;
    }
    .legend-chip .dot { width: 9px; height: 9px; border-radius: 50%; display: inline-block; margin-right: 6px; flex-shrink: 0; }

    /* Detail Modal */
    .detail-time { background: var(--bg-card-subtle); border: 1px solid var(--border-color); border-radius: 12px; padding: .65rem; text-align: center; }
    .detail-time .v { font-size: 1.25rem; font-weight: 800; color: var(--text-main); }
    .detail-desc { background: var(--bg-card-subtle); border: 1px solid var(--border-color); color: var(--text-main); }
</style>

<main class="container hist-container px-3 pb-5 flex-grow-1">

    <!-- 1. HEADER HALAMAN: TITLE & EKSPOR -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 mt-2 gap-2">
        <div>
            <h4 class="fw-bold mb-1"><i class="fas fa-calendar-alt text-primary me-2"></i>Riwayat &amp; Rekap Absensi</h4>
            <p class="text-muted small mb-0">Pantau catatan kehadiran harian, statistik bulanan, dan cetak laporan absensi Anda.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="export_history.php?format=excel&bulan=<?= $bulan ?>&tahun=<?= $tahun ?>" class="btn btn-sm btn-outline-success fw-semibold shadow-sm d-flex align-items-center gap-1.5">
                <i class="fas fa-file-excel"></i><span>Excel</span>
            </a>
            <a href="export_history.php?format=print&bulan=<?= $bulan ?>&tahun=<?= $tahun ?>" target="_blank" class="btn btn-sm btn-outline-danger fw-semibold shadow-sm d-flex align-items-center gap-1.5">
                <i class="fas fa-file-pdf"></i><span>PDF</span>
            </a>
        </div>
    </div>

    <!-- 2. MONTHLY SUMMARY KPI RIBBON -->
    <div class="row g-2 mb-3">
        <div class="col-6 col-sm-3 col-md">
            <div class="kpi-chip shadow-sm">
                <div class="small text-muted fw-semibold">Hadir</div>
                <div class="fs-5 fw-bold text-success mt-0.5"><?= $stat['Hadir'] ?></div>
            </div>
        </div>
        <div class="col-6 col-sm-3 col-md">
            <div class="kpi-chip shadow-sm">
                <div class="small text-muted fw-semibold">Terlambat</div>
                <div class="fs-5 fw-bold text-danger mt-0.5"><?= $stat['Terlambat'] ?></div>
            </div>
        </div>
        <div class="col-6 col-sm-3 col-md">
            <div class="kpi-chip shadow-sm">
                <div class="small text-muted fw-semibold"><i class="fas fa-briefcase text-info me-1"></i>Dinas Luar</div>
                <div class="fs-5 fw-bold text-info mt-0.5"><?= $stat['Kunjungan'] ?></div>
            </div>
        </div>
        <div class="col-6 col-sm-3 col-md">
            <div class="kpi-chip shadow-sm">
                <div class="small text-muted fw-semibold">Izin Jam</div>
                <div class="fs-5 fw-bold text-warning mt-0.5"><?= $stat['Izin Jam'] ?></div>
            </div>
        </div>
        <div class="col-6 col-sm-3 col-md">
            <div class="kpi-chip shadow-sm">
                <div class="small text-muted fw-semibold">Izin Hari</div>
                <div class="fs-5 fw-bold text-primary mt-0.5"><?= $stat['Izin Hari'] ?></div>
            </div>
        </div>
        <div class="col-6 col-sm-3 col-md">
            <div class="kpi-chip shadow-sm">
                <div class="small text-muted fw-semibold">Sakit</div>
                <div class="fs-5 fw-bold text-purple mt-0.5" style="color: #7c3aed;"><?= $stat['Sakit'] ?></div>
            </div>
        </div>
        <div class="col-6 col-sm-3 col-md">
            <div class="kpi-chip shadow-sm">
                <div class="small text-muted fw-semibold">Cuti</div>
                <div class="fs-5 fw-bold text-teal mt-0.5" style="color: #059669;"><?= $stat['Cuti'] ?></div>
            </div>
        </div>
        <div class="col-6 col-sm-3 col-md">
            <div class="kpi-chip shadow-sm">
                <div class="small text-muted fw-semibold">Alpa</div>
                <div class="fs-5 fw-bold text-danger mt-0.5"><?= $stat['Alpa'] ?></div>
            </div>
        </div>
    </div>

    <!-- 3. MASTER CARD: FILTER, KALENDER & TABEL DAFTAR -->
    <div class="master-history-card shadow-sm mb-4">
        
        <!-- CARD HEADER: PERIODE BULAN, NAVIGASI, & VIEW TOGGLE -->
        <div class="p-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-3">
            
            <!-- NAVIGASI BULAN & TAHUN -->
            <form method="GET" class="d-flex align-items-center gap-2 flex-grow-1 flex-md-grow-0" id="formFilter">
                <div class="btn-group btn-group-sm shadow-sm" role="group">
                    <?php if ($can_prev): ?>
                        <a href="<?= hist_url($prev->format('m'), $prev->format('Y')) ?>" class="btn btn-outline-secondary" title="Bulan Sebelumnya"><i class="fas fa-chevron-left"></i></a>
                    <?php else: ?>
                        <span class="btn btn-outline-secondary disabled"><i class="fas fa-chevron-left"></i></span>
                    <?php endif; ?>

                    <a href="<?= hist_url(date('m'), date('Y')) ?>" class="btn btn-outline-secondary fw-semibold" title="Kembali ke Bulan Sekarang">Hari Ini</a>

                    <?php if ($can_next): ?>
                        <a href="<?= hist_url($next->format('m'), $next->format('Y')) ?>" class="btn btn-outline-secondary" title="Bulan Berikutnya"><i class="fas fa-chevron-right"></i></a>
                    <?php else: ?>
                        <span class="btn btn-outline-secondary disabled"><i class="fas fa-chevron-right"></i></span>
                    <?php endif; ?>
                </div>

                <div class="d-flex align-items-center gap-1.5">
                    <select name="bulan" class="form-select form-select-sm fw-semibold shadow-sm" onchange="this.form.submit()">
                        <?php foreach ($nama_bulan as $n => $m): ?>
                            <option value="<?= $n ?>" <?= $bulan == $n ? 'selected' : '' ?>><?= $m ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="tahun" class="form-select form-select-sm fw-semibold shadow-sm" style="max-width: 95px;" onchange="this.form.submit()">
                        <?php for ($y = $tahun_ini; $y >= $tahun_ini - 2; $y--): ?>
                            <option value="<?= $y ?>" <?= $tahun == $y ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </form>

            <!-- TABS VIEW: KALENDER VS TABEL DAFTAR -->
            <ul class="nav nav-pills nav-pills-sm p-1 rounded-3 bg-body-tertiary border" id="viewToggleTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active py-1 px-3 small fw-semibold" id="btn-view-cal" type="button" onclick="switchView('cal')">
                        <i class="fas fa-calendar-alt me-1"></i> Kalender
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link py-1 px-3 small fw-semibold" id="btn-view-table" type="button" onclick="switchView('table')">
                        <i class="fas fa-list me-1"></i> Tabel Daftar
                    </button>
                </li>
            </ul>

        </div>

        <!-- CARD BODY -->
        <div class="p-3 p-md-4">
            
            <!-- VIEW 1: KALENDER VIEW -->
            <div id="wrapper-view-cal">
                
                <!-- HEADER HARI (SEN - MIN) -->
                <div class="cal-grid mb-2">
                    <?php foreach ($nama_hari_short as $i => $hn): ?>
                        <div class="cal-head-pill <?= $i == 7 ? 'is-sunday' : '' ?>"><?= $hn ?></div>
                    <?php endforeach; ?>
                </div>

                <!-- GRID TANGGAL -->
                <div class="cal-grid">
                    <?php
                    for ($k = 1; $k < $hari_pertama_index; $k++) echo '<div class="cal-cell empty"></div>';

                    foreach ($days as $d => $x) {
                        $cls = 'cal-cell'
                             . ($x['is_off'] ? ' is-off' : '')
                             . ($x['date'] === $today ? ' is-today' : '')
                             . ($x['date'] > $today ? ' is-future' : '');
                        $c = $x['label'] !== null ? ($ST[$x['label']] ?? $ST['Alpa']) : null;

                        if ($x['label'] !== null) {
                            echo '<button type="button" class="' . $cls . '" data-detail="' . $x['json'] . '" aria-label="' . htmlspecialchars($x['tgl'] . ', ' . $x['label']) . '">';
                        } else {
                            echo '<div class="' . $cls . '">';
                        }

                        echo '<div class="w-100 d-flex justify-content-between align-items-center">';
                        echo '<span class="cal-date-num">' . $d . '</span>';
                        if ($x['pending']) echo '<i class="fas fa-hourglass-half text-info small" title="Menunggu persetujuan"></i>';
                        echo '</div>';

                        if ($c) {
                            echo '<span class="cal-dot" style="background:' . $c[0] . ';"></span>';
                            $icon_tag = !empty($c[2]) ? '<i class="fas fa-' . $c[2] . ' me-1"></i>' : '';
                            echo '<span class="cal-pill shadow-xs" style="background:' . $c[0] . ';color:' . $c[1] . ';">' . $icon_tag . htmlspecialchars($x['label']) . '</span>';
                            if ($x['in'] !== '-') echo '<span class="cal-time"><i class="far fa-clock me-1"></i>' . $x['in'] . '</span>';
                        }

                        echo $x['label'] !== null ? '</button>' : '</div>';
                    }

                    $total_cell = ($hari_pertama_index - 1) + $jumlah_hari;
                    while ($total_cell % 7 != 0) { echo '<div class="cal-cell empty"></div>'; $total_cell++; }
                    ?>
                </div>

                <!-- LEGENDA STATUS -->
                <div class="mt-4 pt-3 border-top">
                    <div class="small fw-semibold text-muted mb-2"><i class="fas fa-tags me-1"></i>Keterangan Warna Status:</div>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($ST as $nama => $c): ?>
                            <span class="legend-chip">
                                <span class="dot" style="background: <?= $c[0] ?>;"></span>
                                <strong><?= $nama ?></strong>
                                <?php if ($nama === 'Kunjungan'): ?>
                                    <small class="text-muted ms-1">(Dihitung Hadir Kerja)</small>
                                <?php endif; ?>
                            </span>
                        <?php endforeach; ?>
                        <span class="legend-chip">
                            <i class="fas fa-hourglass-half text-info me-1.5"></i>Menunggu Verifikasi
                        </span>
                    </div>
                </div>

            </div>

            <!-- VIEW 2: TABEL DAFTAR VIEW -->
            <div id="wrapper-view-table" class="d-none">
                <div class="table-responsive">
                    <table class="table table-hover align-middle border mb-0" style="font-size: 13px;">
                        <thead class="table-light text-uppercase text-muted" style="letter-spacing: 0.5px;">
                            <tr>
                                <th class="text-center" style="width: 45px;">No</th>
                                <th>Hari &amp; Tanggal</th>
                                <th class="text-center">Jam Masuk</th>
                                <th class="text-center">Jam Pulang</th>
                                <th class="text-center">Status</th>
                                <th>Keterangan / Agenda</th>
                                <th class="text-center" style="width: 70px;">Detail</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $nomor = 1;
                            foreach ($days as $d => $x): 
                                $c = $x['label'] !== null ? ($ST[$x['label']] ?? $ST['Alpa']) : null;
                                $is_tgl_hari_ini = ($x['date'] === $today);
                            ?>
                                <tr class="<?= $is_tgl_hari_ini ? 'table-primary bg-primary-subtle' : '' ?>">
                                    <td class="text-center text-muted fw-bold"><?= $nomor++ ?></td>
                                    <td>
                                        <div class="fw-bold <?= $x['dow'] == 7 ? 'text-danger' : 'text-body' ?>">
                                            <?= htmlspecialchars($x['tgl']) ?>
                                            <?php if ($is_tgl_hari_ini): ?>
                                                <span class="badge bg-primary text-white ms-1">Hari Ini</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="text-center fw-semibold"><?= $x['in'] !== '-' ? $x['in'] . ' WIB' : '-' ?></td>
                                    <td class="text-center fw-semibold"><?= $x['out'] !== '-' ? $x['out'] . ' WIB' : '-' ?></td>
                                    <td class="text-center">
                                        <?php if ($c): ?>
                                            <span class="badge rounded-pill px-2.5 py-1.5 text-white" style="background: <?= $c[0] ?>;">
                                                <i class="fas fa-<?= $c[2] ?> me-1"></i><?= htmlspecialchars($x['label']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="small text-muted" style="max-width: 280px; word-break: break-word;">
                                            <?= htmlspecialchars($x['desc'] ?: '-') ?>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($x['label'] !== null): ?>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" data-detail="<?= $x['json'] ?>" title="Lihat Detail">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

    </div>

</main>

<!-- MODAL DETAIL -->
<div class="modal fade" id="modalDetail" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-3">
            <div class="modal-header border-bottom py-3 px-4">
                <h6 class="modal-title fw-bold text-dark"><i class="fas fa-info-circle text-primary me-2"></i>Detail Catatan Absensi</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body p-4 text-start">
                <div class="text-center mb-3">
                    <div class="fw-bold fs-6 text-dark mb-1" id="d_tgl"></div>
                    <span class="badge px-3 py-2 fs-6 shadow-sm" id="d_st"></span>
                    <div class="small mt-2 d-none fw-semibold" id="d_note"></div>
                </div>

                <div class="row g-2 mb-3" id="d_times">
                    <div class="col-6">
                        <div class="detail-time">
                            <div class="small text-muted fw-semibold">Waktu Masuk</div>
                            <div class="v" id="d_in">-</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="detail-time">
                            <div class="small text-muted fw-semibold">Waktu Pulang</div>
                            <div class="v" id="d_out">-</div>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small text-muted fw-semibold mb-1">Keterangan / Keperluan / Alasan</label>
                    <div id="d_desc" class="small detail-desc p-3 rounded-2" style="word-break: break-word;"></div>
                </div>

                <div class="d-grid gap-2">
                    <a id="d_loc" href="#" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary d-none">
                        <i class="fas fa-map-marked-alt text-primary me-1.5"></i>Lihat Titik Koordinat GPS di Google Maps
                    </a>
                    <a id="d_pic" href="#" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary d-none">
                        <i class="fas fa-paperclip me-1.5"></i>Lihat Lampiran / Foto Bukti Dinas
                    </a>
                </div>
            </div>
            <div class="modal-footer border-top py-2.5 px-4 bg-light">
                <button type="button" class="btn btn-secondary btn-sm px-4" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<script>
function switchView(mode) {
    const calWrap = document.getElementById('wrapper-view-cal');
    const tableWrap = document.getElementById('wrapper-view-table');
    const btnCal = document.getElementById('btn-view-cal');
    const btnTable = document.getElementById('btn-view-table');

    if (mode === 'table') {
        calWrap.classList.add('d-none');
        tableWrap.classList.remove('d-none');
        btnCal.classList.remove('active');
        btnTable.classList.add('active');
    } else {
        tableWrap.classList.add('d-none');
        calWrap.classList.remove('d-none');
        btnTable.classList.remove('active');
        btnCal.classList.add('active');
    }
}

document.addEventListener('click', function (e) {
    const el = e.target.closest('[data-detail]');
    if (!el) return;
    try { showDetail(JSON.parse(el.dataset.detail)); } catch (err) { console.error(err); }
});

function showDetail(d) {
    document.getElementById('d_tgl').textContent = d.tgl;

    const st = document.getElementById('d_st');
    st.textContent = d.st;
    st.style.background = d.bg;
    st.style.color = d.fg;

    const note = document.getElementById('d_note');
    note.className = 'small mt-2 fw-semibold';
    if (d.pending) {
        note.textContent = 'Menunggu persetujuan Administrator';
        note.classList.add('text-info');
    } else if (d.approval === 'Ditolak') {
        note.textContent = 'Pengajuan ditolak Administrator (dihitung Alpa)';
        note.classList.add('text-danger');
    } else if (d.st === 'Kunjungan') {
        note.textContent = '✓ Tugas Kedinasan Luar (Dihitung Hadir Kerja Penuh)';
        note.classList.add('text-success');
    } else {
        note.classList.add('d-none');
    }

    const adaWaktu = d.in !== '-' || d.out !== '-';
    document.getElementById('d_times').classList.toggle('d-none', !adaWaktu);
    document.getElementById('d_in').textContent = d.in !== '-' ? d.in + ' WIB' : '-';
    document.getElementById('d_out').textContent = d.out !== '-' ? d.out + ' WIB' : '-';
    document.getElementById('d_desc').textContent = d.desc || 'Tidak ada keterangan tambahan.';

    const loc = document.getElementById('d_loc');
    if (d.lat !== null && d.lng !== null) {
        loc.href = 'https://www.google.com/maps?q=' + encodeURIComponent(d.lat) + ',' + encodeURIComponent(d.lng);
        loc.classList.remove('d-none');
    } else {
        loc.classList.add('d-none');
    }

    const pic = document.getElementById('d_pic');
    if (d.pic) {
        pic.href = '../uploads/attendance/' + encodeURIComponent(d.pic);
        pic.classList.remove('d-none');
    } else {
        pic.classList.add('d-none');
    }

    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalDetail')).show();
}
</script>

<?php include '../includes/footer.php'; ?>
