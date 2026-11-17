<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require '../config/database.php';
require '../config/logger.php';
require '../config/helpers.php';
require '../config/security.php';
date_default_timezone_set('Asia/Jakarta');

enforce_admin_access();

// HANDLER PROSES SIMPAN FORM (MANUAL POST)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // 1. Simpan Pengaturan Sistem (Maintenance & Batas Alpa)
        if (isset($_POST['save_settings'])) {
            $maintenance_mode = isset($_POST['maintenance_mode']) ? '1' : '0';
            $batas_alpa       = $_POST['batas_alpa'] ?? '09:00';

            $stmt1 = $pdo->prepare("INSERT INTO settings (setting_name, setting_value) VALUES ('maintenance_mode', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt1->execute([$maintenance_mode, $maintenance_mode]);

            $stmt2 = $pdo->prepare("INSERT INTO settings (setting_name, setting_value) VALUES ('batas_alpa', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt2->execute([$batas_alpa, $batas_alpa]);

            $toleransi = $_POST['izin_jam_toleransi'] ?? '0';
            if (!ctype_digit((string)$toleransi) || (int)$toleransi > 120) {
                throw new Exception("Toleransi izin jam harus berupa angka 0 - 120 menit.");
            }
            set_setting($pdo, 'izin_jam_toleransi', (string)(int)$toleransi);

            $_SESSION['pesan'] = "Pengaturan sistem berhasil disimpan.";
        }

        // 1b. Simpan Lokasi Kantor
        if (isset($_POST['save_location'])) {
            $lat    = $_POST['office_lat'] ?? '';
            $lng    = $_POST['office_lng'] ?? '';
            $radius = $_POST['office_radius'] ?? '';

            if (!valid_coordinates($lat, $lng)) {
                throw new Exception("Koordinat tidak valid. Latitude -90 s.d 90, longitude -180 s.d 180.");
            }
            if (!ctype_digit((string)$radius) || (int)$radius < 10 || (int)$radius > 5000) {
                throw new Exception("Radius harus berupa angka 10 - 5000 meter.");
            }
            set_setting($pdo, 'office_lat', (string)(float)$lat);
            set_setting($pdo, 'office_lng', (string)(float)$lng);
            set_setting($pdo, 'office_radius', (string)(int)$radius);
            logActivity($pdo, $_SESSION['user_id'], "Mengubah lokasi kantor ($lat, $lng) radius {$radius} m");

            $_SESSION['pesan'] = "Lokasi kantor berhasil disimpan.";
        }

        // 2. Simpan Jadwal Kerja Hari
        if (isset($_POST['save_schedules'])) {
            if (isset($_POST['schedules']) && is_array($_POST['schedules'])) {
                foreach ($_POST['schedules'] as $id => $data) {
                    $is_off = isset($data['is_off']) ? 1 : 0;
                    $stmt3 = $pdo->prepare("UPDATE schedules SET check_in=?, batas_terlambat=?, check_out=?, is_off=? WHERE id=?");
                    $stmt3->execute([$data['check_in'], $data['batas_terlambat'], $data['check_out'], $is_off, $id]);
                }
            }
            $_SESSION['pesan'] = "Jadwal kerja berhasil disimpan.";
        }

        header("Location: settings.php");
        exit();
    } catch (Exception $e) {
        $_SESSION['error'] = "Gagal menyimpan pengaturan: " . $e->getMessage();
        header("Location: settings.php");
        exit();
    }
}

$settings_data = [];
foreach ($pdo->query("SELECT * FROM settings") as $row) { 
    $settings_data[$row['setting_name']] = $row['setting_value']; 
}
$schedules = $pdo->query("SELECT * FROM schedules ORDER BY day_index ASC")->fetchAll();

$maintenance_mode = $settings_data['maintenance_mode'] ?? '0';
$office = get_office_location($pdo);
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/navbar.php'; ?>

<div class="container pb-5" style="max-width: 900px;" data-aos="fade-up">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="fw-bold m-0"><i class="fas fa-sliders-h text-primary me-2"></i>Pengaturan Sistem & Jadwal</h4>
    </div>

    <?php if(isset($_SESSION['pesan'])): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-3 border-0 shadow-sm mb-4">
            <i class="fas fa-check-circle me-1"></i> <?= $_SESSION['pesan']; unset($_SESSION['pesan']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if(isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-3 border-0 shadow-sm mb-4">
            <i class="fas fa-exclamation-circle me-1"></i> <?= $_SESSION['error']; unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- PENGATURAN SISTEM & BATAS ALPA -->
    <form method="POST" action="settings.php" class="mb-4">
        <input type="hidden" name="save_settings" value="1">
        <div class="card shadow-sm border-0 rounded-3">
            <div class="card-body p-4">
                <h5 class="fw-bold mb-3"><i class="fas fa-cogs text-primary me-2"></i>Pengaturan Sistem</h5>
                
                <div class="row bg-light p-3 rounded-3 border g-3 mb-3 align-items-center">
                    <!-- MODE MAINTENANCE -->
                    <div class="col-md-6 border-end">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="switchMaintenance" name="maintenance_mode" value="1" <?= $maintenance_mode == '1' ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold text-danger" for="switchMaintenance">
                                <i class="fas fa-tools me-1"></i> Mode Maintenance
                            </label>
                        </div>
                        <small class="text-muted d-block mt-1" style="font-size: 12px;">
                            Jika aktif, fitur alpa otomatis matikan penuh dan validasi lokasi GPS pada absensi siswa/karyawan dinonaktifkan.
                        </small>
                    </div>

                    <!-- JAM BATAS ALPA -->
                    <div class="col-md-6">
                        <label class="form-label small text-muted fw-semibold">Jam Batas Alpa Otomatis</label>
                        <input type="time" name="batas_alpa" id="input_batas_alpa" class="form-control" value="<?= $settings_data['batas_alpa'] ?? '09:00' ?>">
                        <small class="text-muted d-block mt-1" style="font-size: 12px;">
                            Siswa/Karyawan yang belum absen hingga jam ini akan ditandai Alpa.
                        </small>
                    </div>

                    <!-- TOLERANSI IZIN JAM -->
                    <div class="col-12 border-top pt-3">
                        <label class="form-label small text-muted fw-semibold">Toleransi Izin Jam (menit)</label>
                        <input type="number" name="izin_jam_toleransi" class="form-control" style="max-width: 160px;" min="0" max="120" value="<?= (int)($settings_data['izin_jam_toleransi'] ?? 0) ?>">
                        <small class="text-muted d-block mt-1" style="font-size: 12px;">
                            Izin jam dianggap sah jika siswa tiba paling lambat perkiraan tiba + toleransi ini. Jika lebih lambat, dihitung Terlambat biasa. Izin yang disetujui tetapi tidak dipakai sama sekali menjadi Alpa di akhir hari.
                        </small>
                    </div>
                </div>

                <div class="text-end">
                    <button type="submit" class="btn btn-primary px-4 shadow-sm">
                        <i class="fas fa-save me-1"></i> Simpan Pengaturan
                    </button>
                </div>
            </div>
        </div>
    </form>

    <!-- LOKASI KANTOR -->
    <form method="POST" action="settings.php" class="mb-4">
        <input type="hidden" name="save_location" value="1">
        <div class="card shadow-sm border-0 rounded-3">
            <div class="card-body p-4">
                <h5 class="fw-bold mb-1"><i class="fas fa-map-marker-alt text-danger me-2"></i>Lokasi Kantor</h5>
                <p class="text-muted small mb-3">Absen Hadir dan Kunjungan hanya diterima jika pengguna berada dalam radius dari titik ini (tidak berlaku saat Mode Maintenance aktif).</p>

                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-4">
                        <label class="form-label small text-muted fw-semibold">Latitude</label>
                        <input type="text" inputmode="decimal" name="office_lat" id="office_lat" class="form-control" value="<?= htmlspecialchars((string)$office['lat']) ?>" required>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label small text-muted fw-semibold">Longitude</label>
                        <input type="text" inputmode="decimal" name="office_lng" id="office_lng" class="form-control" value="<?= htmlspecialchars((string)$office['lng']) ?>" required>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label small text-muted fw-semibold">Radius (meter)</label>
                        <input type="number" name="office_radius" id="office_radius" class="form-control" min="10" max="5000" value="<?= (int)$office['radius'] ?>" required>
                    </div>
                </div>

                <!-- VISUALISASI RADIUS PETA (LEAFLET) -->
                <div class="mb-3">
                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-1 gap-2">
                        <label class="small text-muted fw-semibold"><i class="fas fa-map-marked-alt text-primary me-1"></i>Visualisasi Titik Kantor &amp; Radius Absen</label>
                        <span class="badge bg-light text-secondary border fw-normal" style="font-size: 11px;">
                            <i class="fas fa-info-circle text-primary me-1"></i>Klik pada peta atau seret pin untuk memindahkan titik
                        </span>
                    </div>
                    <div id="map-admin-settings" style="height: 320px; width: 100%; border-radius: 8px;" class="border shadow-sm"></div>
                </div>

                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="pakaiLokasiSaya(this)">
                            <i class="fas fa-crosshairs me-1"></i> Pakai lokasi saya sekarang
                        </button>
                        <a class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener" id="lnk_maps"
                           href="https://www.google.com/maps?q=<?= $office['lat'] ?>,<?= $office['lng'] ?>">
                            <i class="fas fa-external-link-alt me-1"></i> Cek di Maps
                        </a>
                    </div>
                    <button type="submit" class="btn btn-primary px-4 shadow-sm">
                        <i class="fas fa-save me-1"></i> Simpan Lokasi
                    </button>
                </div>
                <div id="lokasi_info" class="small mt-2"></div>
            </div>
        </div>
    </form>

    <!-- JADWAL KERJA PER HARI -->
    <form method="POST" action="settings.php">
        <input type="hidden" name="save_schedules" value="1">
        <div class="card shadow-sm border-0 rounded-3">
            <div class="card-body p-4">
                <h5 class="fw-bold mb-3"><i class="fas fa-calendar-alt text-primary me-2"></i>Jadwal Kerja per Hari</h5>
                
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Hari</th>
                                <th class="text-center">Libur?</th>
                                <th>Jam Masuk</th>
                                <th>Batas Terlambat</th>
                                <th>Jam Pulang</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($schedules as $s): ?>
                            <tr>
                                <td><strong><?= $s['day_name'] ?></strong></td>
                                <td class="text-center">
                                    <div class="form-check form-switch d-inline-block">
                                        <input class="form-check-input" type="checkbox" role="switch" name="schedules[<?= $s['id'] ?>][is_off]" value="1" <?= $s['is_off'] ? 'checked' : '' ?>>
                                    </div>
                                </td>
                                <td><input type="time" name="schedules[<?= $s['id'] ?>][check_in]" class="form-control form-control-sm" value="<?= $s['check_in'] ?>"></td>
                                <td><input type="time" name="schedules[<?= $s['id'] ?>][batas_terlambat]" class="form-control form-control-sm" value="<?= $s['batas_terlambat'] ?>"></td>
                                <td><input type="time" name="schedules[<?= $s['id'] ?>][check_out]" class="form-control form-control-sm" value="<?= $s['check_out'] ?>"></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="text-end mt-4">
                    <button type="submit" class="btn btn-primary px-4 shadow-sm">
                        <i class="fas fa-save me-1"></i> Simpan Jadwal
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
let mapAdmin = null;
let adminMarker = null;
let adminCircle = null;

function initAdminMap() {
    const latInput = document.getElementById('office_lat');
    const lngInput = document.getElementById('office_lng');
    const radInput = document.getElementById('office_radius');
    if (!latInput || !lngInput || !radInput) return;

    const lat = parseFloat(latInput.value) || -6.9175;
    const lng = parseFloat(lngInput.value) || 106.9272;
    const rad = parseInt(radInput.value) || 100;

    if (typeof L === 'undefined') {
        setTimeout(initAdminMap, 200);
        return;
    }

    mapAdmin = L.map('map-admin-settings').setView([lat, lng], 16);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(mapAdmin);

    adminMarker = L.marker([lat, lng], { draggable: true }).addTo(mapAdmin);
    adminCircle = L.circle([lat, lng], {
        radius: rad,
        color: '#2563eb',
        fillColor: '#3b82f6',
        fillOpacity: 0.18,
        weight: 2
    }).addTo(mapAdmin);

    adminMarker.bindPopup('<b>Titik Kantor</b><br>Radius: ' + rad + ' m').openPopup();

    function updateCoords(newLat, newLng) {
        latInput.value = newLat.toFixed(7);
        lngInput.value = newLng.toFixed(7);
        const lnk = document.getElementById('lnk_maps');
        if (lnk) lnk.href = 'https://www.google.com/maps?q=' + newLat.toFixed(7) + ',' + newLng.toFixed(7);
        adminMarker.setLatLng([newLat, newLng]);
        adminCircle.setLatLng([newLat, newLng]);
    }

    adminMarker.on('dragend', function (e) {
        const p = e.target.getLatLng();
        updateCoords(p.lat, p.lng);
    });

    mapAdmin.on('click', function (e) {
        updateCoords(e.latlng.lat, e.latlng.lng);
    });

    radInput.addEventListener('input', function () {
        const r = parseInt(this.value) || 10;
        adminCircle.setRadius(r);
        adminMarker.setPopupContent('<b>Titik Kantor</b><br>Radius: ' + r + ' m');
    });

    [latInput, lngInput].forEach(inp => {
        inp.addEventListener('change', function () {
            const curLat = parseFloat(latInput.value);
            const curLng = parseFloat(lngInput.value);
            if (!isNaN(curLat) && !isNaN(curLng)) {
                updateCoords(curLat, curLng);
                mapAdmin.setView([curLat, curLng]);
            }
        });
    });
}

document.addEventListener('DOMContentLoaded', initAdminMap);

function pakaiLokasiSaya(btn) {
    const info = document.getElementById('lokasi_info');
    if (!navigator.geolocation) { info.className = 'small mt-2 text-danger'; info.textContent = 'Perangkat tidak mendukung GPS.'; return; }
    btn.disabled = true;
    info.className = 'small mt-2 text-muted';
    info.textContent = 'Mengambil lokasi...';
    navigator.geolocation.getCurrentPosition(function(pos) {
        const lat = pos.coords.latitude;
        const lng = pos.coords.longitude;
        document.getElementById('office_lat').value = lat.toFixed(7);
        document.getElementById('office_lng').value = lng.toFixed(7);
        document.getElementById('lnk_maps').href = 'https://www.google.com/maps?q=' + lat + ',' + lng;
        if (adminMarker && adminCircle && mapAdmin) {
            adminMarker.setLatLng([lat, lng]);
            adminCircle.setLatLng([lat, lng]);
            mapAdmin.setView([lat, lng], 16);
        }
        info.className = 'small mt-2 text-success';
        info.textContent = 'Terisi dari lokasi Anda (akurasi ±' + Math.round(pos.coords.accuracy) + ' m). Klik Simpan Lokasi untuk menerapkan.';
        btn.disabled = false;
    }, function() {
        info.className = 'small mt-2 text-danger';
        info.textContent = 'Gagal mengakses GPS. Pastikan izin lokasi aktif (butuh HTTPS).';
        btn.disabled = false;
    }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 });
}
</script>

<?php include '../includes/footer.php'; ?>