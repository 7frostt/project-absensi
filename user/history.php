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
    'Izin Hari' => ['#0d6efd', '#ffffff', 'envelope'],
    'Izin Jam'  => ['#fd7e14', '#ffffff', 'clock'],
    'Sakit'     => ['#6f42c1', '#ffffff', 'medkit'],
    'Cuti'      => ['#20c997', '#ffffff', 'umbrella-beach'],
    'Kunjungan' => ['#17a2b8', '#ffffff', 'walking'],
    'Alpa'      => ['#dc3545', '#ffffff', 'user-times'],
    'Libur'     => ['#94a3b8', '#ffffff', 'calendar-times'],
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

?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/navbar.php'; ?>

<style>
    .hist { max-width: 900px; }
    .nav-bulan .btn-nav { width: 38px; height: 38px; padding: 0; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
    /* header.php menimpa padding .form-select sehingga teks menabrak panah dropdown: sisakan ruang di kanan */
    .nav-bulan .form-select { height: 38px; padding: 6px 34px 6px 12px; background-position: right 10px center; background-size: 14px 10px; min-width: 0; line-height: 1.4; }
    .cal-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 4px; }
    .cal-head { text-align: center; font-weight: 700; font-size: 12px; color: var(--text-muted); padding: 4px 0; }
    .cal-cell { position: relative; appearance: none; width: 100%; font: inherit; border: 1px solid var(--border-color); background: var(--bg-card); border-radius: 10px;
                min-height: 54px; padding: 5px 2px; display: flex; flex-direction: column; align-items: center; gap: 4px; text-align: center; color: var(--text-main); }
    .cal-cell.empty { background: transparent; border: 0; }
    .cal-cell.is-off { background: rgba(239, 68, 68, 0.08); border-color: rgba(239, 68, 68, 0.25); }
    .cal-cell.is-future { opacity: .5; }
    .cal-cell.is-today { outline: 2px solid var(--primary-color); outline-offset: -1px; }
    button.cal-cell { cursor: pointer; transition: border-color .15s, box-shadow .15s; }
    button.cal-cell:hover, button.cal-cell:focus-visible { border-color: var(--primary-color); box-shadow: 0 2px 10px rgba(37,99,235,.15); outline: none; }
    .cal-num { font-weight: 700; font-size: 13px; color: var(--text-main); line-height: 1; }
    .is-off .cal-num { color: #dc2626; }
    [data-theme="dark"] .is-off .cal-num { color: #f87171; }
    .is-today .cal-num { color: var(--primary-color); }
    .cal-dot { width: 9px; height: 9px; border-radius: 50%; display: block; }
    .cal-pill, .cal-time { display: none; }
    .cal-pending { position: absolute; top: 3px; right: 4px; font-size: 9px; color: #0284c7; }
    @media (min-width: 576px) {
        .cal-grid { gap: 6px; }
        .cal-cell { min-height: 88px; padding: 6px; align-items: stretch; text-align: left; }
        .cal-num { font-size: 14px; }
        .cal-dot { display: none; }
        .cal-pill { display: block; font-size: 10px; font-weight: 700; border-radius: 7px; padding: 3px 4px; text-align: center; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .cal-time { display: block; font-size: 10px; color: var(--text-muted); text-align: center; }
        .cal-pending { top: 6px; right: 7px; }
    }

    /* LEGENDA */
    .legend { display: flex; flex-wrap: wrap; gap: 6px 12px; font-size: 11.5px; color: var(--text-muted); }
    .legend .dot { width: 9px; height: 9px; border-radius: 50%; display: inline-block; margin-right: 5px; }

    /* DETAIL */
    .detail-time { background: var(--bg-card-subtle); border: 1px solid var(--border-color); border-radius: 12px; padding: .6rem; text-align: center; }
    .detail-time .v { font-size: 1.2rem; font-weight: 800; color: var(--text-main); }
    .detail-desc { background: var(--bg-card-subtle); border: 1px solid var(--border-color); color: var(--text-main); }
    @media (max-width: 575.98px) {
        .hist .card-body { padding: .75rem; }
    }
</style>

<main class="container hist px-3 pb-5">

    <!-- JUDUL -->
    <div class="my-3">
        <h5 class="fw-bold mb-0"><i class="fas fa-calendar-alt text-primary me-2"></i>Riwayat Absensi</h5>
        <p class="text-muted small mb-0">Pantau kehadiran Anda setiap bulan. Ketuk tanggal untuk melihat detail.</p>
    </div>

    <!-- FILTER & EXPORT -->
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-stretch align-items-md-center gap-2">
                <form method="GET" class="nav-bulan d-flex align-items-center gap-2 flex-grow-1 flex-md-grow-0" id="formFilter">
                    <?php if ($can_prev): ?>
                        <a href="<?= hist_url($prev->format('m'), $prev->format('Y')) ?>" class="btn btn-outline-secondary btn-nav shadow-sm" aria-label="Bulan sebelumnya"><i class="fas fa-chevron-left"></i></a>
                    <?php else: ?>
                        <span class="btn btn-outline-secondary btn-nav disabled" aria-hidden="true"><i class="fas fa-chevron-left"></i></span>
                    <?php endif; ?>

                    <select name="bulan" class="form-select form-select-sm flex-grow-1" aria-label="Bulan" onchange="this.form.submit()">
                        <?php foreach ($nama_bulan as $n => $m): ?>
                            <option value="<?= $n ?>" <?= $bulan == $n ? 'selected' : '' ?>><?= $m ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="tahun" class="form-select form-select-sm" style="max-width: 96px;" aria-label="Tahun" onchange="this.form.submit()">
                        <?php for ($y = $tahun_ini; $y >= $tahun_ini - 2; $y--): ?>
                            <option value="<?= $y ?>" <?= $tahun == $y ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>

                    <?php if ($can_next): ?>
                        <a href="<?= hist_url($next->format('m'), $next->format('Y')) ?>" class="btn btn-outline-secondary btn-nav shadow-sm" aria-label="Bulan berikutnya"><i class="fas fa-chevron-right"></i></a>
                    <?php else: ?>
                        <span class="btn btn-outline-secondary btn-nav disabled" aria-hidden="true"><i class="fas fa-chevron-right"></i></span>
                    <?php endif; ?>
                    <noscript><button type="submit" class="btn btn-sm btn-primary">Cari</button></noscript>
                </form>

                <div class="d-flex gap-2">
                    <a href="export_history.php?format=excel&bulan=<?= $bulan ?>&tahun=<?= $tahun ?>" class="btn btn-sm btn-success flex-fill">
                        <i class="fas fa-file-excel me-1"></i> Excel
                    </a>
                    <a href="export_history.php?format=print&bulan=<?= $bulan ?>&tahun=<?= $tahun ?>" target="_blank" class="btn btn-sm btn-danger flex-fill">
                        <i class="fas fa-file-pdf me-1"></i> PDF
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="fw-bold text-dark mb-2"><?= $nama_bulan[$bulan] ?> <?= $tahun ?></div>


    <!-- KALENDER -->
        <div class="card shadow-sm border-0">
            <div class="card-body">
                <div class="cal-grid mb-1">
                    <?php foreach ($nama_hari_short as $i => $hn): ?>
                        <div class="cal-head <?= $i == 7 ? 'text-danger' : '' ?>"><?= $hn ?></div>
                    <?php endforeach; ?>
                </div>

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

                        echo '<span class="cal-num">' . $d . '</span>';
                        if ($c) {
                            echo '<span class="cal-dot" style="background:' . $c[0] . ';"></span>';
                            echo '<span class="cal-pill" style="background:' . $c[0] . ';color:' . $c[1] . ';">' . htmlspecialchars($x['label']) . '</span>';
                            if ($x['in'] !== '-') echo '<span class="cal-time">' . $x['in'] . '</span>';
                            if ($x['pending']) echo '<i class="fas fa-hourglass-half cal-pending" title="Menunggu persetujuan"></i>';
                        }

                        echo $x['label'] !== null ? '</button>' : '</div>';
                    }

                    $total_cell = ($hari_pertama_index - 1) + $jumlah_hari;
                    while ($total_cell % 7 != 0) { echo '<div class="cal-cell empty"></div>'; $total_cell++; }
                    ?>
                </div>

                <div class="legend mt-3 pt-3 border-top">
                    <?php foreach ($ST as $nama => $c): ?>
                        <span><i class="dot" style="background: <?= $c[0] ?>;"></i><?= $nama ?></span>
                    <?php endforeach; ?>
                    <span><i class="fas fa-hourglass-half me-1" style="color:#0284c7;"></i>Menunggu persetujuan</span>
                </div>
            </div>
        </div>

</main>

<!-- MODAL DETAIL -->
<div class="modal fade" id="modalDetail" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-3">
            <div class="modal-header border-0 pb-0">
                <h6 class="modal-title fw-bold" id="d_tgl">Detail Absensi</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <span class="badge px-3 py-2 fs-6" id="d_st"></span>
                    <div class="small mt-2 d-none" id="d_note"></div>
                </div>

                <div class="row g-2 mb-3" id="d_times">
                    <div class="col-6"><div class="detail-time"><div class="small text-muted">Masuk</div><div class="v" id="d_in">-</div></div></div>
                    <div class="col-6"><div class="detail-time"><div class="small text-muted">Pulang</div><div class="v" id="d_out">-</div></div></div>
                </div>

                <div class="mb-3">
                    <small class="text-muted d-block mb-1">Keterangan</small>
                    <div id="d_desc" class="small detail-desc p-2.5 rounded-3" style="word-break: break-word;"></div>
                </div>

                <div class="d-grid gap-2">
                    <a id="d_loc" href="#" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary d-none"><i class="fas fa-map-marker-alt me-1"></i>Lihat lokasi absen</a>
                    <a id="d_pic" href="#" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary d-none"><i class="fas fa-paperclip me-1"></i>Lihat bukti</a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
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
    note.className = 'small mt-2';
    if (d.pending) {
        note.textContent = 'Menunggu persetujuan admin';
        note.classList.add('text-info');
    } else if (d.approval === 'Ditolak') {
        note.textContent = 'Pengajuan ditolak admin (dihitung Alpa)';
        note.classList.add('text-danger');
    } else {
        note.classList.add('d-none');
    }

    const adaWaktu = d.in !== '-' || d.out !== '-';
    document.getElementById('d_times').classList.toggle('d-none', !adaWaktu);
    document.getElementById('d_in').textContent = d.in;
    document.getElementById('d_out').textContent = d.out;
    document.getElementById('d_desc').textContent = d.desc || '-';

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
