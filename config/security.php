<?php
/**
 * Security & Anti-Abuse Protection Helpers
 * Enforces rate limiting, Anti-IDOR validation, single-device session locking, and sanitization.
 */

if (!function_exists('get_client_ip')) {
    function get_client_ip() {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}

/**
 * Enforce fixed-window rate limiting using IP address.
 * Returns true if allowed, or false if rate limit exceeded.
 */
if (!function_exists('check_rate_limit')) {
    function check_rate_limit($pdo, $action, $max_attempts = 5, $window_seconds = 300) {
        if (!$pdo) return true;
        
        $ip = get_client_ip();
        $now = time();
        $window_start = $now - $window_seconds;

        try {
            // Clean up expired records
            $clean_stmt = $pdo->prepare("DELETE FROM rate_limits WHERE last_attempt < ?");
            $clean_stmt->execute([$window_start]);

            // Check current attempts
            $stmt = $pdo->prepare("SELECT attempts, last_attempt FROM rate_limits WHERE ip = ? AND action = ?");
            $stmt->execute([$ip, $action]);
            $record = $stmt->fetch();

            if ($record) {
                if ($record['attempts'] >= $max_attempts) {
                    return false; // Rate limit exceeded
                }
                $update = $pdo->prepare("UPDATE rate_limits SET attempts = attempts + 1, last_attempt = ? WHERE ip = ? AND action = ?");
                $update->execute([$now, $ip, $action]);
            } else {
                $insert = $pdo->prepare("INSERT INTO rate_limits (ip, action, attempts, last_attempt) VALUES (?, ?, 1, ?)");
                $insert->execute([$ip, $action, $now]);
            }
        } catch (PDOException $e) {
            return true;
        }

        return true;
    }
}

/**
 * Reset rate limit counter upon successful action (e.g., successful login)
 */
if (!function_exists('reset_rate_limit')) {
    function reset_rate_limit($pdo, $action) {
        if (!$pdo) return;
        $ip = get_client_ip();
        try {
            $stmt = $pdo->prepare("DELETE FROM rate_limits WHERE ip = ? AND action = ?");
            $stmt->execute([$ip, $action]);
        } catch (PDOException $e) {}
    }
}

/**
 * Strict Server-Side Anti-IDOR Authorization Guard
 * Ensures non-admin users cannot access or tamper with resources owned by other users.
 */
if (!function_exists('enforce_user_ownership')) {
    function enforce_user_ownership($target_user_id) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            header("Location: ../auth/login.php");
            exit();
        }

        $current_role = $_SESSION['user_type'] ?? '';
        $current_user_id = (int)$_SESSION['user_id'];

        if ($current_role === 'Admin') {
            return true;
        }

        if ($current_user_id !== (int)$target_user_id) {
            http_response_code(403);
            die("403 Forbidden: Akses ditolak. Anda tidak memiliki izin untuk melihat atau mengelola data pengguna lain.");
        }

        return true;
    }
}

/**
 * Strict Admin-Only Authorization Guard
 */
if (!function_exists('enforce_admin_access')) {
    function enforce_admin_access() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? '') !== 'Admin') {
            http_response_code(403);
            die("403 Forbidden: Akses ditolak. Halaman ini memerlukan hak akses Administrator.");
        }
    }
}

/**
 * Single-Device Login Guard Helpers
 */

/**
 * Check if the user already has an active session on another device.
 * Considers session timed out if inactive for more than $timeout_seconds (default 2 hours).
 */
if (!function_exists('is_user_session_active')) {
    function is_user_session_active($pdo, $user_id, $timeout_seconds = 7200) {
        if (!$pdo || empty($user_id)) return false;

        try {
            $stmt = $pdo->prepare("SELECT active_session_id, UNIX_TIMESTAMP(last_activity_at) as last_act FROM users WHERE id = ?");
            $stmt->execute([(int)$user_id]);
            $user = $stmt->fetch();

            if (!$user || empty($user['active_session_id'])) {
                return false;
            }

            $current_session = session_id();
            if ($user['active_session_id'] === $current_session) {
                return false; // Same session
            }

            // Check if older session has timed out due to inactivity
            $last_act = (int)($user['last_act'] ?? 0);
            if ($last_act > 0 && (time() - $last_act) > $timeout_seconds) {
                clear_user_session($pdo, $user_id);
                return false;
            }

            return true; // Still actively logged in on another device
        } catch (PDOException $e) {
            return false;
        }
    }
}

/**
 * Bind user to the current session (Single Device registration)
 */
if (!function_exists('register_user_session')) {
    function register_user_session($pdo, $user_id) {
        if (!$pdo || empty($user_id)) return;

        $sess_id = session_id();
        $ip = get_client_ip();
        $device = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 255);

        try {
            $stmt = $pdo->prepare("UPDATE users SET active_session_id = ?, last_activity_at = NOW(), last_ip = ?, device_info = ? WHERE id = ?");
            $stmt->execute([$sess_id, $ip, $device, (int)$user_id]);
        } catch (PDOException $e) {}
    }
}

/**
 * Clear user session binding on logout or admin reset
 */
if (!function_exists('clear_user_session')) {
    function clear_user_session($pdo, $user_id) {
        if (!$pdo || empty($user_id)) return;
        try {
            $stmt = $pdo->prepare("UPDATE users SET active_session_id = NULL WHERE id = ?");
            $stmt->execute([(int)$user_id]);
        } catch (PDOException $e) {}
    }
}

/**
 * Verify active session on every request for non-admin users.
 * If user logged in from another device or session was cleared, redirect to login.
 */
if (!function_exists('verify_single_device_session')) {
    function verify_single_device_session($pdo) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['user_id'])) {
            return;
        }

        // Only enforce for regular users (Siswa PKL, Mahasiswa Magang, Karyawan)
        if (($_SESSION['user_type'] ?? '') === 'Admin') {
            return;
        }

        $user_id = (int)$_SESSION['user_id'];
        $current_sess = session_id();

        try {
            $stmt = $pdo->prepare("SELECT active_session_id FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $db_sess = $stmt->fetchColumn();

            // If session was cleared or active_session_id does not match
            if ($db_sess !== $current_sess) {
                session_unset();
                session_destroy();
                header("Location: ../auth/login.php?kicked=1");
                exit();
            }

            // Throttle last_activity_at update (every 60 seconds)
            if (!isset($_SESSION['_last_db_touch']) || (time() - $_SESSION['_last_db_touch']) > 60) {
                $_SESSION['_last_db_touch'] = time();
                $update = $pdo->prepare("UPDATE users SET last_activity_at = NOW() WHERE id = ?");
                $update->execute([$user_id]);
            }
        } catch (PDOException $e) {}
    }
}

/**
 * Input sanitization helper
 */
if (!function_exists('clean_string')) {
    function clean_string($data) {
        return htmlspecialchars(trim((string)$data), ENT_QUOTES, 'UTF-8');
    }
}
?>
