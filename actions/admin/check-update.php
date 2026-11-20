<?php
// GET/POST /actions/admin/check-update
// Proxy AJAX dari halaman admin ke License Server /api/check-update.
// Hasil di-cache di session selama 24 jam untuk menghindari spam ke server.
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

header('Content-Type: application/json');

// Untuk request POST (mis. tombol "Cek paksa"), validasi CSRF.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validateCSRF($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token keamanan tidak valid.']);
    exit;
}

// Paksa refresh (lewati cache) jika diminta eksplisit.
$forceRefresh = isset($_REQUEST['force']) && $_REQUEST['force'] === '1';
$cacheKey     = 'update_check_' . date('Ymd');

try {
    $pdo  = getDB();
    $stmt = $pdo->query('SELECT * FROM license_info ORDER BY id ASC LIMIT 1');
    $license = $stmt->fetch();

    // Gerbang lisensi: WAJIB ada baris DAN status 'active'. Status lain
    // (unknown/expired/suspended = "belum terhubung") tidak boleh cek update.
    if (!$license || ($license['status'] ?? '') !== 'active') {
        echo json_encode(['success' => false, 'has_update' => false, 'message' => 'Lisensi belum aktif/terhubung. Aktifkan lisensi terlebih dahulu sebelum cek update.']);
        exit;
    }

    // ─── Cache 24 jam ─────────────────────────────────────────
    if (!$forceRefresh && isset($_SESSION[$cacheKey])) {
        echo json_encode($_SESSION[$cacheKey] + ['success' => true, 'cached' => true]);
        exit;
    }

    // ─── Call ke License Server ───────────────────────────────
    $payload = json_encode([
        'license_key'     => $license['license_key'],
        'domain'          => $license['domain'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost'),
        'current_version' => APP_VERSION,
    ]);

    $ch = curl_init(rtrim(LICENSE_SERVER_URL, '/') . '/check-update');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode === 0) {
        error_log('check-update curl error: ' . $curlErr);
        echo json_encode(['success' => false, 'has_update' => false, 'message' => 'Tidak dapat menghubungi server update. Periksa koneksi internet.']);
        exit;
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        echo json_encode(['success' => false, 'has_update' => false, 'message' => 'Respons server update tidak valid.']);
        exit;
    }

    // HTTP non-2xx dari server (404 license, 403 domain, dst) → teruskan pesan.
    if ($httpCode >= 400) {
        echo json_encode($data + ['success' => false]);
        exit;
    }

    // Simpan ke cache session (untuk dipakai apply-update verifikasi checksum).
    $_SESSION[$cacheKey] = $data;

    // Sinkronkan juga ke setting persist yang dipakai cek otomatis harian —
    // badge/toast konsisten antara cek manual & Auto-Cron, dan tidak hilang
    // saat logout.
    try {
        $latest = preg_replace('/[^0-9.]/', '', (string) ($data['latest_version'] ?? ''));
        if (!empty($data['has_update']) && $latest !== '' && version_compare($latest, APP_VERSION, '>')) {
            setSetting('update_available_version', $latest);
        } else {
            setSetting('update_available_version', '');
        }
        // Broadcast vendor: simpan/bersihkan juga saat cek manual (konsisten Auto-Cron).
        storeVendorBroadcast($data['broadcast'] ?? null);
    } catch (Exception $e) {
        error_log('check-update sync setting: ' . $e->getMessage());
    }

    echo json_encode($data + ['success' => true]);

} catch (Exception $e) {
    error_log('check-update error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'has_update' => false, 'message' => 'Terjadi kesalahan saat mengecek update.']);
}
