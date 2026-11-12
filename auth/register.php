<?php
session_start();
require '../config/database.php';
require '../config/security.php';
$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!check_rate_limit($pdo, 'register_user', 10, 900)) {
        http_response_code(429);
        $error = "Terlalu banyak permintaan pendaftaran dari perangkat Anda. Silakan coba beberapa saat lagi.";
    } else {
        $name = trim($_POST['name'] ?? '');
        $username = strtolower(trim($_POST['username'] ?? ''));
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $phone = trim($_POST['phone'] ?? '');
        $gender = $_POST['gender'] ?? '';
        $user_type = $_POST['user_type'] ?? '';
        $institution = trim($_POST['institution'] ?? '');
        $department = trim($_POST['department'] ?? '');

        $valid_types = ['Siswa PKL', 'Mahasiswa Magang', 'Karyawan'];

        if (!in_array($user_type, $valid_types, true)) {
            $error = "Jenis pengguna tidak valid!";
        } elseif (!in_array($gender, ['Laki-laki', 'Perempuan'], true)) {
            $error = "Jenis kelamin wajib dipilih!";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Format email tidak valid!";
        } elseif (strlen($password) < 6) {
            $error = "Password minimal harus 6 karakter!";
        } elseif ($password !== $confirm_password) {
            $error = "Password dan Konfirmasi Password tidak cocok!";
        } else {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? OR username = ?");
            $stmt->execute([$email, $username]);

            if ($stmt->rowCount() > 0) {
                $error = "Email atau Username sudah terdaftar!";
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $sql = "INSERT INTO users (name, username, email, password, phone, gender, user_type, institution, department, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Aktif')";
                $stmt = $pdo->prepare($sql);
                if($stmt->execute([$name, $username, $email, $hashed_password, $phone, $gender, $user_type, $institution, $department])){
                    header("Location: login.php?registered=true");
                    exit();
                } else {
                    $error = "Terjadi kesalahan sistem.";
                }
            }
        }
    }
}
?>
<?php include '../includes/header.php'; ?>

<div class="position-fixed top-0 end-0 p-3 z-3">
    <button type="button" class="btn btn-outline-secondary theme-toggle-btn shadow-sm" aria-label="Ganti Tema" title="Ganti Tema (Light / Dark)">
        <i class="fas fa-moon theme-icon-dark"></i>
        <i class="fas fa-sun theme-icon-light text-warning d-none"></i>
    </button>
</div>
<div class="container mt-4 mb-5">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h3 class="text-center mb-4">Pendaftaran Akun</h3>
                    <?php if($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
                    
                    <form method="POST" id="form-register">
                        <div class="mb-3"><label class="form-label">Nama Lengkap</label><input type="text" name="name" class="form-control" required></div>
                        <div class="row">
                            <div class="col-md-6 mb-3"><label class="form-label">Username</label><input type="text" name="username" class="form-control" required></div>
                            <div class="col-md-6 mb-3"><label class="form-label">Email</label><input type="email" name="email" class="form-control" required></div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Password</label>
                                <div class="input-group">
                                    <input type="password" name="password" id="reg_password" class="form-control" required>
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('reg_password', 'icon_pass')">
                                        <i class="fas fa-eye" id="icon_pass"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Konfirmasi Password</label>
                                <div class="input-group">
                                    <input type="password" name="confirm_password" id="reg_confirm_password" class="form-control" required>
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('reg_confirm_password', 'icon_confirm')">
                                        <i class="fas fa-eye" id="icon_confirm"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3"><label class="form-label">Nomor HP</label><input type="text" name="phone" class="form-control" required></div>
                            <div class="col-md-4 mb-3"><label class="form-label">Jenis Kelamin</label>
                                <select name="gender" class="form-select" required>
                                    <option value="">-- Pilih --</option>
                                    <option value="Laki-laki">Laki-laki</option>
                                    <option value="Perempuan">Perempuan</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3"><label class="form-label">Jenis Pengguna</label>
                                <select name="user_type" class="form-select" required>
                                    <option value="">-- Pilih --</option>
                                    <option value="Siswa PKL">Siswa PKL</option>
                                    <option value="Mahasiswa Magang">Mahasiswa Magang</option>
                                    <option value="Karyawan">Karyawan</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3"><label class="form-label">Instansi / Asal Sekolah / Kampus</label><input type="text" name="institution" class="form-control" placeholder="Contoh: PT ABC / SMKN 1 / Univ X" required></div>
                            <div class="col-md-6 mb-3"><label class="form-label">Divisi / Jurusan</label><input type="text" name="department" class="form-control" placeholder="Contoh: IT Support / RPL / HRD" required></div>
                        </div>

                        <div id="pesan-lokasi" class="text-danger mt-2 mb-3 fw-bold text-center"></div>

                        <button type="button" class="btn btn-primary w-100" id="btn-daftar" onclick="daftarDenganLokasi()">Daftar Sekarang</button>
                    </form>
                    <div class="mt-3 text-center"><small>Sudah punya akun? <a href="login.php">Login di sini</a></small></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function togglePassword(inputId, iconId) {
    const passwordInput = document.getElementById(inputId);
    const toggleIcon = document.getElementById(iconId);
    
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        toggleIcon.classList.remove('fa-eye');
        toggleIcon.classList.add('fa-eye-slash');
    } else {
        passwordInput.type = 'password';
        toggleIcon.classList.remove('fa-eye-slash');
        toggleIcon.classList.add('fa-eye');
    }
}

// Rumus Haversine di Javascript untuk menghitung jarak dua titik koordinat (dalam Meter)
function getJarakMeter(lat1, lon1, lat2, lon2) {
    const R = 6371000; // Radius bumi dalam meter
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLon = (lon2 - lon1) * Math.PI / 180;
    const a = 
        Math.sin(dLat / 2) * Math.sin(dLat / 2) +
        Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) * 
        Math.sin(dLon / 2) * Math.sin(dLon / 2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    return R * c;
}

function daftarDenganLokasi() {
    const pesan = document.getElementById("pesan-lokasi");
    const btnDaftar = document.getElementById("btn-daftar");
    const formRegister = document.getElementById("form-register");

    // Validasi kelengkapan form bawaan HTML
    if (!formRegister.checkValidity()) {
        formRegister.reportValidity();
        return;
    }

    // Koordinat Kantor / Tempat Kerja
    const KANTOR_LAT = -6.9225168806517265;
    const KANTOR_LNG = 106.93325246659839;
    const MAX_RADIUS = 100; // Maksimal radius dalam meter

    pesan.className = "text-primary mt-2 mb-3 fw-bold text-center";
    pesan.innerHTML = "Memeriksa lokasi Anda, mohon tunggu... (Pastikan GPS/Lokasi aktif)";
    btnDaftar.disabled = true;

    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            function(position) {
                const userLat = position.coords.latitude;
                const userLng = position.coords.longitude;
                
                // Hitung jarak user dari kantor
                const jarak = getJarakMeter(KANTOR_LAT, KANTOR_LNG, userLat, userLng);

                if (jarak <= MAX_RADIUS) {
                    pesan.className = "text-success mt-2 mb-3 fw-bold text-center";
                    pesan.innerHTML = "Lokasi terverifikasi. Memproses pendaftaran...";
                    formRegister.submit();
                } else {
                    btnDaftar.disabled = false;
                    pesan.className = "text-danger mt-2 mb-3 fw-bold text-center";
                    pesan.innerHTML = "Pendaftaran gagal! Anda berada di luar lokasi kerja yang diizinkan (" + Math.round(jarak) + " meter dari lokasi).";
                }
            },
            function(error) {
                btnDaftar.disabled = false;
                pesan.className = "text-danger mt-2 mb-3 fw-bold text-center";
                switch(error.code) {
                    case error.PERMISSION_DENIED:
                        pesan.innerHTML = "Akses lokasi ditolak! Anda wajib mengizinkan lokasi untuk mendaftar.";
                        break;
                    case error.POSITION_UNAVAILABLE:
                        pesan.innerHTML = "Informasi lokasi tidak tersedia. Pastikan GPS menyala.";
                        break;
                    case error.TIMEOUT:
                        pesan.innerHTML = "Waktu permintaan lokasi habis. Silakan coba lagi.";
                        break;
                    default:
                        pesan.innerHTML = "Terjadi kesalahan sistem saat mengambil lokasi.";
                        break;
                }
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
        );
    } else {
        btnDaftar.disabled = false;
        pesan.className = "text-danger mt-2 mb-3 fw-bold text-center";
        pesan.innerHTML = "Browser atau perangkat Anda tidak mendukung fitur lokasi.";
    }
}
</script>

<?php include '../includes/footer.php'; ?>