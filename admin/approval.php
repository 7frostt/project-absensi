<?php
session_start();
require '../config/database.php';
require '../config/logger.php';
require '../config/security.php';

date_default_timezone_set('Asia/Jakarta');

enforce_admin_access();

// 1. HITUNG BADGE NOTIFIKASI PENDING UNTUK MASING-MASING TAB (DENGAN SUBQUERY AMAN NULL)
// Tab Izin Hari & Sakit
$stmt_c_sakit = $pdo->query("
    SELECT COUNT(*) FROM (
        SELECT user_id FROM attendance 
        WHERE approval_status = 'Menunggu Persetujuan' AND status IN ('Izin', 'Izin Hari', 'Sakit')
        GROUP BY user_id, status, description, IFNULL(photo, ''), MONTH(date), YEAR(date)
    ) as total_group
");
$pending_sakit = $stmt_c_sakit->fetchColumn();

// Tab Izin Jam
$stmt_c_jam = $pdo->query("
    SELECT COUNT(*) FROM attendance 
    WHERE approval_status = 'Menunggu Persetujuan' 
    AND (status = 'Izin Jam' OR description LIKE '%Izin Jam%')
");
$pending_jam = $stmt_c_jam->fetchColumn();

// Tab Cuti
$stmt_c_cuti = $pdo->query("
    SELECT COUNT(*) FROM (
        SELECT user_id FROM attendance 
        WHERE approval_status = 'Menunggu Persetujuan' AND status = 'Cuti'
        GROUP BY user_id, status, description, IFNULL(photo, ''), MONTH(date), YEAR(date)
    ) as total_group
");
$pending_cuti = $stmt_c_cuti->fetchColumn();


// 2. LOGIKA AKSI APPROVE / REJECT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_approval'])) {
    $ids_array = array_values(array_filter(array_map('intval', explode(',', $_POST['attendance_ids'] ?? '')), fn($v) => $v > 0));
    $status_approval = $_POST['status_approval'] ?? '';
    
    if (!in_array($status_approval, ['Disetujui', 'Ditolak'], true)) {
        $_SESSION['error'] = "Status pengajuan tidak valid.";
    } elseif (!empty($ids_array)) {
        try {
            $in_clause = implode(',', array_fill(0, count($ids_array), '?'));
            $params_acc = array_merge([$status_approval], $ids_array);
            
            $stmt_app = $pdo->prepare("UPDATE attendance SET approval_status = ? WHERE id IN ($in_clause)");
            $stmt_app->execute($params_acc);
            
            $_SESSION['pesan'] = "Status pengajuan berhasil diperbarui menjadi: " . $status_approval;
        } catch (PDOException $e) {
            $_SESSION['error'] = "Gagal memproses pengajuan: " . $e->getMessage();
        }
    }
    
    $tab_redirect = $_GET['tab'] ?? 'izin_sakit';
    header("Location: approval.php?tab=" . urlencode($tab_redirect));
    exit();
}

// 3. LOGIKA TAB & FILTER DATA
$active_tab = $_GET['tab'] ?? 'izin_sakit';
$filter_status = $_GET['status'] ?? '';
$filter_bulan  = $_GET['bulan'] ?? date('m');
$filter_tahun  = $_GET['tahun'] ?? date('Y');

$where_clauses = ["MONTH(a.date) = ?", "YEAR(a.date) = ?"];
$params = [$filter_bulan, $filter_tahun];

if ($active_tab === 'izin_jam') {
    $where_clauses[] = "(a.status = 'Izin Jam' OR a.description LIKE '%Izin Jam%')";
} elseif ($active_tab === 'cuti') {
    $where_clauses[] = "a.status = 'Cuti'";
} else {
    $where_clauses[] = "a.status IN ('Izin', 'Izin Hari', 'Sakit')";
}

if (!empty($filter_status)) {
    $where_clauses[] = "a.approval_status = ?";
    $params[] = $filter_status;
}

$where_sql = implode(" AND ", $where_clauses);

$query = "
    SELECT 
        GROUP_CONCAT(a.id ORDER BY a.date ASC) as group_ids,
        MIN(a.date) as min_date,
        MAX(a.date) as max_date,
        COUNT(a.id) as total_hari,
        a.user_id,
        a.status,
        a.approval_status,
        a.description,
        a.photo,
        u.name as user_name, 
        u.user_type, 
        u.institution 
    FROM attendance a 
    JOIN users u ON a.user_id = u.id 
    WHERE $where_sql 
    GROUP BY a.user_id, a.status, a.approval_status, a.description, a.photo, MONTH(a.date), YEAR(a.date)
    ORDER BY min_date DESC
";

$stmt_list = $pdo->prepare($query);
$stmt_list->execute($params);
$data_pengajuan = $stmt_list->fetchAll();

$nama_bulan = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
    '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
    '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
];
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/navbar.php'; ?>

<div class="container pb-5 flex-grow-1">
    
    <!-- HEADER BAR: TITLE -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
        <h4 class="fw-bold m-0"><i class="fas fa-check-circle text-primary me-2"></i>Persetujuan Pengajuan</h4>
    </div>

    <!-- NOTIFIKASI PESAN/ERROR -->
    <?php if(isset($_SESSION['pesan'])): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-3">
            <i class="fas fa-check-circle me-1"></i><?= $_SESSION['pesan']; unset($_SESSION['pesan']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if(isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm rounded-3">
            <i class="fas fa-exclamation-circle me-1"></i><?= $_SESSION['error']; unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- TAB NAVIGASI PENGAJUAN -->
    <div class="card border-0 shadow-sm rounded-3 mb-3">
        <div class="card-body p-3 d-flex flex-wrap gap-2">
            
            <a href="approval.php?tab=izin_sakit" class="btn btn-sm rounded-2 px-3 py-1.5 fw-semibold d-inline-flex align-items-center gap-2 <?= ($active_tab === 'izin_sakit') ? 'btn-primary shadow-sm text-white' : 'btn-outline-secondary' ?>">
                <i class="fas fa-envelope-open-text"></i> Izin Hari & Sakit
                <?php if ($pending_sakit > 0): ?>
                    <span class="badge bg-danger text-white rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 20px; height: 20px; font-size: 11px; padding: 0;"><?= $pending_sakit ?></span>
                <?php endif; ?>
            </a>

            <a href="approval.php?tab=izin_jam" class="btn btn-sm rounded-2 px-3 py-1.5 fw-semibold d-inline-flex align-items-center gap-2 <?= ($active_tab === 'izin_jam') ? 'btn-primary shadow-sm text-white' : 'btn-outline-secondary' ?>">
                <i class="fas fa-clock"></i> Izin Jam
                <?php if ($pending_jam > 0): ?>
                    <span class="badge bg-danger text-white rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 20px; height: 20px; font-size: 11px; padding: 0;"><?= $pending_jam ?></span>
                <?php endif; ?>
            </a>

            <a href="approval.php?tab=cuti" class="btn btn-sm rounded-2 px-3 py-1.5 fw-semibold d-inline-flex align-items-center gap-2 <?= ($active_tab === 'cuti') ? 'btn-primary shadow-sm text-white' : 'btn-outline-secondary' ?>">
                <i class="fas fa-user-clock"></i> Cuti
                <?php if ($pending_cuti > 0): ?>
                    <span class="badge bg-danger text-white rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 20px; height: 20px; font-size: 11px; padding: 0;"><?= $pending_cuti ?></span>
                <?php endif; ?>
            </a>

        </div>
    </div>

    <!-- CARD UTAMA KONTEN -->
    <div class="card shadow-sm border-0 rounded-3">
        <div class="card-body p-4">
            <!-- PANEL FILTER -->
            <form method="GET" action="approval.php" class="row g-2 mb-4 align-items-center">
                <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab) ?>">
                
                <div class="col-lg-5 col-md-5 col-12">
                    <select name="status" class="form-select">
                        <option value="">Semua Status Persetujuan</option>
                        <option value="Menunggu Persetujuan" <?= $filter_status == 'Menunggu Persetujuan' ? 'selected' : '' ?>>Menunggu Persetujuan</option>
                        <option value="Disetujui" <?= $filter_status == 'Disetujui' ? 'selected' : '' ?>>Disetujui</option>
                        <option value="Ditolak" <?= $filter_status == 'Ditolak' ? 'selected' : '' ?>>Ditolak</option>
                    </select>
                </div>
                
                <div class="col-lg-3 col-md-3 col-6">
                    <select name="bulan" class="form-select">
                        <?php foreach($nama_bulan as $k => $v): ?>
                            <option value="<?= $k ?>" <?= $k == $filter_bulan ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-lg-2 col-md-2 col-6">
                    <select name="tahun" class="form-select">
                        <?php 
                        $thn = date('Y');
                        for($t = $thn - 2; $t <= $thn + 1; $t++): 
                        ?>
                            <option value="<?= $t ?>" <?= $t == $filter_tahun ? 'selected' : '' ?>><?= $t ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="col-lg-2 col-md-2 col-12">
                    <button type="submit" class="btn btn-primary w-100 rounded-3 shadow-sm fw-semibold">
                        <i class="fas fa-filter me-1"></i> Filter
                    </button>
                </div>
            </form>

            <!-- TABEL DATA APPROVAL -->
            <div class="table-responsive">
                <table class="table table-hover align-middle border mb-0">
                    <thead class="table-light text-uppercase text-muted" style="font-size: 13px; letter-spacing: 0.5px;">
                        <tr>
                            <th class="ps-3 py-3">Tanggal</th>
                            <th class="py-3">Nama</th>
                            <th class="py-3">Pengajuan</th>
                            <th class="py-3">Status ACC</th>
                            <th class="py-3">Keterangan</th>
                            <th class="py-3 text-center">Bukti</th>
                            <th class="text-center py-3">Aksi</th>
                        </tr>
                    </thead>
                    <tbody style="font-size: 14px;">
                        <?php if (empty($data_pengajuan)): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    <i class="fas fa-inbox fa-2x d-block mb-2 text-secondary opacity-50"></i>
                                    Tidak ada data pengajuan pada kategori ini.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach($data_pengajuan as $idx => $row): ?>
                                <tr>
                                    <td class="ps-3 fw-semibold text-secondary">
                                        <?php if ($row['min_date'] == $row['max_date']): ?>
                                            <?= date('d/m/Y', strtotime($row['min_date'])) ?>
                                        <?php else: ?>
                                            <?= date('d/m/Y', strtotime($row['min_date'])) ?> s.d <?= date('d/m/Y', strtotime($row['max_date'])) ?>
                                            <div class="badge bg-light text-primary border mt-1 d-inline-block fw-bold" style="font-size: 10px;"><?= $row['total_hari'] ?> Hari</div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($row['user_name']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($row['institution'] ?? '-') ?></small>
                                    </td>
                                    <td>
                                        <?php 
                                        $has_ij = (($row['status'] ?? '') === 'Izin Jam' || str_contains($row['description'] ?? '', 'Izin Jam'));
                                        $label_pengajuan = $has_ij ? 'Izin Jam' : $row['status'];
                                        $bg_p = ($label_pengajuan == 'Izin Jam') ? '#fd7e14' : (($label_pengajuan == 'Sakit') ? '#6f42c1' : (($label_pengajuan == 'Cuti') ? '#17a2b8' : '#0d6efd'));
                                        ?>
                                        <span class="badge text-white px-2.5 py-1 rounded-2 shadow-sm" style="background-color: <?= $bg_p ?>; font-size: 11px;">
                                            <?= htmlspecialchars($label_pengajuan) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($row['approval_status'] == 'Menunggu Persetujuan'): ?>
                                            <span class="badge bg-warning text-dark px-2.5 py-1 rounded-2" style="font-size: 11px;"><i class="fas fa-clock me-1"></i> Menunggu</span>
                                        <?php elseif ($row['approval_status'] == 'Disetujui'): ?>
                                            <span class="badge bg-success px-2.5 py-1 rounded-2" style="font-size: 11px;"><i class="fas fa-check-circle me-1"></i> Disetujui</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger px-2.5 py-1 rounded-2" style="font-size: 11px;"><i class="fas fa-times-circle me-1"></i> Ditolak</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td class="text-secondary" style="max-width: 260px;">
                                        <?= format_desc_tag($row['description'] ?? '-') ?>
                                    </td>
                                    
                                    <td class="text-center">
                                        <?php if (!empty($row['photo'])): ?>
                                            <?php 
                                            // FIX PATH: Menuju folder uploads/attendance/ sesuai struktur file
                                            $file_path = "../uploads/attendance/" . htmlspecialchars($row['photo']);
                                            $ext = strtolower(pathinfo($row['photo'], PATHINFO_EXTENSION));
                                            $modal_id = "modalBukti_" . $idx;
                                            ?>
                                            <button type="button" class="btn btn-sm btn-outline-info rounded-circle shadow-sm p-1" style="width: 28px; height: 28px;" data-bs-toggle="modal" data-bs-target="#<?= $modal_id ?>" title="Lihat Bukti">
                                                <i class="fas fa-file-alt fa-xs"></i>
                                            </button>

                                            <!-- MODAL POPUP PREVIEW BUKTI -->
                                            <div class="modal fade" id="<?= $modal_id ?>" tabindex="-1" aria-hidden="true">
                                                <div class="modal-dialog modal-dialog-centered modal-lg">
                                                    <div class="modal-content border-0 shadow rounded-3">
                                                        <div class="modal-header border-bottom-0 pb-0">
                                                            <h6 class="modal-title fw-bold text-dark"><i class="fas fa-image text-primary me-2"></i>Bukti Pengajuan - <?= htmlspecialchars($row['user_name']) ?></h6>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <div class="modal-body text-center p-4">
                                                            <?php if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])): ?>
                                                                <img src="<?= $file_path ?>" class="img-fluid rounded-3 border shadow-sm" style="max-height: 500px; object-fit: contain;" alt="Bukti Foto">
                                                            <?php elseif ($ext === 'pdf'): ?>
                                                                <iframe src="<?= $file_path ?>" width="100%" height="500px" class="rounded-3 border"></iframe>
                                                            <?php else: ?>
                                                                <p class="text-muted">Format file tidak dapat dipratinjau secara langsung.</p>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="modal-footer border-top-0 pt-0 justify-content-between">
                                                            <a href="<?= $file_path ?>" target="_blank" class="btn btn-sm btn-outline-primary rounded-2 px-3 fw-semibold">
                                                                <i class="fas fa-external-link-alt me-1"></i> Buka di Tab Baru
                                                            </a>
                                                            <button type="button" class="btn btn-sm btn-light rounded-2 px-4 fw-semibold" data-bs-dismiss="modal">Tutup</button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($row['approval_status'] == 'Menunggu Persetujuan'): ?>
                                            <div class="d-flex justify-content-center gap-1">
                                                <form method="POST" action="approval.php?tab=<?= htmlspecialchars($active_tab) ?>" class="m-0">
                                                    <input type="hidden" name="action_approval" value="1">
                                                    <input type="hidden" name="attendance_ids" value="<?= $row['group_ids'] ?>">
                                                    <input type="hidden" name="status_approval" value="Disetujui">
                                                    <button type="submit" class="btn btn-sm btn-success rounded-2 px-2.5 py-1 shadow-sm fw-semibold" style="font-size: 11px;" onclick="return confirm('Setujui pengajuan ini?')">
                                                        <i class="fas fa-check"></i> Setujui
                                                    </button>
                                                </form>

                                                <form method="POST" action="approval.php?tab=<?= htmlspecialchars($active_tab) ?>" class="m-0">
                                                    <input type="hidden" name="action_approval" value="1">
                                                    <input type="hidden" name="attendance_ids" value="<?= $row['group_ids'] ?>">
                                                    <input type="hidden" name="status_approval" value="Ditolak">
                                                    <button type="submit" class="btn btn-sm btn-danger rounded-2 px-2.5 py-1 shadow-sm fw-semibold" style="font-size: 11px;" onclick="return confirm('Tolak pengajuan ini?')">
                                                        <i class="fas fa-times"></i> Tolak
                                                    </button>
                                                </form>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted fw-semibold small">Selesai</span>
                                        <?php endif; ?>
                                    </td>
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