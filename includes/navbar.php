<?php
// Deteksi nama file halaman yang sedang aktif saat ini
$current_page = basename($_SERVER['PHP_SELF']);

// Hitung total pengajuan izin (Menunggu Persetujuan) secara unik
if (isset($pdo)) {
    try {
        $stmt_nav_izin = $pdo->query("
            SELECT COUNT(*) FROM (
                SELECT user_id FROM attendance 
                WHERE approval_status = 'Menunggu Persetujuan' 
                GROUP BY user_id, status, description, IFNULL(photo, ''), MONTH(date), YEAR(date)
            ) as total_group
        ");
        $total_pending_izin = $stmt_nav_izin->fetchColumn();
    } catch (PDOException $e) {
        $total_pending_izin = 0;
    }
} else {
    $total_pending_izin = 0;
}
?>

<div class="floating-nav-wrapper">
    <nav class="navbar navbar-expand-lg navbar-floating">
        <div class="container-fluid px-2 px-md-3">
            <!-- BRAND LOGO -->
            <a class="navbar-brand d-flex align-items-center gap-2 fw-bold text-dark flex-shrink-0" href="<?= (isset($_SESSION['user_type']) && $_SESSION['user_type'] == 'Admin') ? '../admin/dashboard.php' : '../user/dashboard.php' ?>">
                <span class="brand-icon">
                    <i class="fas fa-fingerprint"></i>
                </span>
                <span class="brand-title">ABSENSI <span class="fw-normal text-muted d-none d-md-inline" style="font-size: 13px;">| Online</span></span>
            </a>

            <!-- RIGHT UTILITY CONTROLS: SINGLE THEME TOGGLE & BURGER TOGGLER -->
            <div class="d-flex align-items-center gap-2 order-lg-last ms-auto ms-lg-3 flex-shrink-0">
                <button type="button" class="btn btn-outline-secondary theme-toggle-btn" id="themeToggleBtn" aria-label="Ganti Tema" title="Ganti Tema (Light / Dark)">
                    <i class="fas fa-moon theme-icon-dark"></i>
                    <i class="fas fa-sun theme-icon-light text-warning d-none"></i>
                </button>
                <button class="navbar-toggler border-0 shadow-none px-2 py-1" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Navigasi Menu">
                    <span class="navbar-toggler-icon"></span>
                </button>
            </div>

            <!-- NAV MENU -->
            <div class="collapse navbar-collapse justify-content-end" id="navbarNav">
                <ul class="navbar-nav align-items-lg-center gap-1 my-2 my-lg-0">
                    
                    <?php if (isset($_SESSION['user_type']) && $_SESSION['user_type'] == 'Admin'): ?>
                        <!-- MENU ADMIN -->
                        <li class="nav-item">
                            <a class="nav-link <?= ($current_page == 'dashboard.php') ? 'active' : '' ?>" href="../admin/dashboard.php">
                                <i class="fas fa-chart-pie me-1.5 opacity-75"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= ($current_page == 'users.php') ? 'active' : '' ?>" href="../admin/users.php">
                                <i class="fas fa-users me-1.5 opacity-75"></i> Data User
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= ($current_page == 'attendance.php') ? 'active' : '' ?>" href="../admin/attendance.php">
                                <i class="fas fa-calendar-check me-1.5 opacity-75"></i> Data Absensi
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= ($current_page == 'approval.php') ? 'active' : '' ?> d-inline-flex align-items-center gap-1.5" href="../admin/approval.php">
                                <i class="fas fa-file-signature me-1.5 opacity-75"></i> Pengajuan Izin
                                <?php if ($total_pending_izin > 0): ?>
                                    <span class="badge bg-danger rounded-1 text-white ms-1" style="font-size: 11px; padding: 2px 6px;">
                                        <?= (int)$total_pending_izin ?>
                                    </span>
                                <?php endif; ?>
                            </a>
                        </li>
                        
                        <!-- DROPDOWN LAINNYA -->
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle <?= in_array($current_page, ['reports.php', 'holidays.php', 'settings.php']) ? 'active' : '' ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-cog me-1.5 opacity-75"></i> Lainnya
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end border shadow-sm rounded-2 py-1 mt-1">
                                <li>
                                    <a class="dropdown-item py-2 px-3 <?= ($current_page == 'reports.php') ? 'active' : '' ?>" href="../admin/reports.php">
                                        <i class="fas fa-file-invoice me-2 opacity-75"></i> Rekap Absensi
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item py-2 px-3 <?= ($current_page == 'holidays.php') ? 'active' : '' ?>" href="../admin/holidays.php">
                                        <i class="fas fa-calendar-alt me-2 opacity-75"></i> Hari Libur Nasional
                                    </a>
                                </li>
                                <li><hr class="dropdown-divider my-1"></li>
                                <li>
                                    <a class="dropdown-item py-2 px-3 <?= ($current_page == 'settings.php') ? 'active' : '' ?>" href="../admin/settings.php">
                                        <i class="fas fa-sliders-h me-2 opacity-75"></i> Pengaturan Sistem
                                    </a>
                                </li>
                            </ul>
                        </li>

                    <?php else: ?>
                        <!-- MENU USER / SISWA / KARYAWAN -->
                        <li class="nav-item">
                            <a class="nav-link <?= ($current_page == 'dashboard.php') ? 'active' : '' ?>" href="../user/dashboard.php">
                                <i class="fas fa-home me-1.5 opacity-75"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= ($current_page == 'history.php') ? 'active' : '' ?>" href="../user/history.php">
                                <i class="fas fa-history me-1.5 opacity-75"></i> Riwayat Absensi
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= ($current_page == 'profile.php') ? 'active' : '' ?>" href="../user/profile.php">
                                <i class="fas fa-user-circle me-1.5 opacity-75"></i> Profil
                            </a>
                        </li>
                    <?php endif; ?>

                    <!-- USER IDENTITY INDICATOR -->
                    <?php if (isset($_SESSION['name'])): ?>
                    <li class="nav-item d-flex align-items-center ms-lg-2 ps-lg-2 border-start-lg my-1 my-lg-0">
                        <div class="user-chip text-start">
                            <div class="user-chip-name text-truncate" style="max-width: 140px;"><?= htmlspecialchars($_SESSION['name']) ?></div>
                            <div class="user-chip-role text-muted"><?= htmlspecialchars($_SESSION['user_type'] ?? '') ?></div>
                        </div>
                    </li>
                    <?php endif; ?>

                    <!-- LOGOUT BUTTON -->
                    <li class="nav-item ms-lg-2 my-1 my-lg-0">
                        <a class="btn btn-outline-danger d-inline-flex align-items-center gap-1.5" href="../auth/logout.php">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </li>

                </ul>
            </div>
        </div>
    </nav>
</div>
