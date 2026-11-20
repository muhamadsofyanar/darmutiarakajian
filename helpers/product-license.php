<?php
// ─── Lisensi Produk Digital (dijual vendor) ─────────────────────────────────────────
// Diekstrak dari helpers.php (pemecahan monolit — memori helpers-split-plan).
// Dimuat via bootstrap.php ($__averionCoreFiles, SETELAH helpers.php).
// Behavior-preserving: pindah utuh (fungsi + docblock), tanpa ubah logika.

/**
 * Generate license key format: AVRN-XXXX-XXXX-XXXX-XXXX
 * Loop hingga unique di tabel product_licenses.
 */
function generateProductLicenseKey(): string {
    $pdo = getDB();
    do {
        $segments = [];
        for ($i = 0; $i < 4; $i++) {
            $segments[] = strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
        }
        $key = 'AVRN-' . implode('-', $segments);
        $stmt = $pdo->prepare("SELECT id FROM product_licenses WHERE license_key = ?");
        $stmt->execute([$key]);
    } while ($stmt->fetch());
    return $key;
}

/**
 * Issue license key setelah order paid.
 * Dipanggil dari: activateAccess() call sites — checkout, webhook callback,
 * konfirmasi manual admin.
 * Idempotent: jika sudah ada license untuk order+product ini, kembalikan key lama.
 *
 * @return string|null license_key bila berhasil/sudah ada, null bila produk
 *                      tidak mengaktifkan lisensi atau terjadi error.
 */
function issueLicense(int $orderId, int $productId, int $userId): ?string {
    try {
        $pdo = getDB();

        // Idempotency: jangan double-issue
        $existing = $pdo->prepare(
            "SELECT license_key FROM product_licenses WHERE order_id = ? AND product_id = ?"
        );
        $existing->execute([$orderId, $productId]);
        if ($row = $existing->fetch()) {
            return $row['license_key'];
        }

        // Ambil konfigurasi license dari produk
        $product = $pdo->prepare(
            "SELECT license_enabled, license_seats, license_validity_days FROM products WHERE id = ?"
        );
        $product->execute([$productId]);
        $p = $product->fetch();

        // Jika produk tidak enable license, return null (bukan error)
        if (!$p || !$p['license_enabled']) {
            return null;
        }

        // Produk yang dipetakan ke server lisensi VENDOR: kuncinya diterbitkan vendor
        // (lewat antrean vfEnqueue), BUKAN di sini. Tanpa guard ini buyer menerima DUA
        // kunci berformat sama persis (AVRN-XXXX-XXXX-XXXX-XXXX, tak bisa dibedakan
        // mata) — satu di antaranya lokal & akan DITOLAK saat aktivasi.
        if (function_exists('vfPlanForProduct') && vfPlanForProduct($productId) !== '') {
            return null;
        }

        $licenseKey   = generateProductLicenseKey();
        $maxSeats     = max(1, (int) $p['license_seats']);
        $validityDays = $p['license_validity_days'] ? (int) $p['license_validity_days'] : null;
        $expiresAt    = $validityDays
            ? date('Y-m-d H:i:s', strtotime("+{$validityDays} days"))
            : null;

        $pdo->prepare(
            "INSERT INTO product_licenses
             (license_key, order_id, product_id, user_id, status, max_seats, used_seats, expires_at)
             VALUES (?, ?, ?, ?, 'active', ?, 0, ?)"
        )->execute([$licenseKey, $orderId, $productId, $userId, $maxSeats, $expiresAt]);

        return $licenseKey;

    } catch (Exception $e) {
        error_log('issueLicense error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Catat satu request ke public API lisensi (audit trail).
 * Tidak pernah throw — kegagalan logging tidak menghentikan flow API.
 */
function logLicenseApi(
    ?int    $licenseId,
    string  $endpoint,
    ?string $stringIdentifier,
    string  $result,
    string  $message = ''
): void {
    try {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? null;
        if ($ip !== null) {
            // X-Forwarded-For bisa berisi rantai "client, proxy1, proxy2" — ambil yang pertama.
            $ip = substr(trim(explode(',', $ip)[0]), 0, 45);
        }
        getDB()->prepare(
            "INSERT INTO license_api_logs
             (license_id, endpoint, string_identifier, ip_address, result, message)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([$licenseId, $endpoint, $stringIdentifier, $ip, $result, $message]);
    } catch (Exception $e) {
        error_log('logLicenseApi error: ' . $e->getMessage());
    }
}

/**
 * Rate limiting sederhana untuk public API lisensi: maks N request per menit per IP.
 * Memakai tabel license_api_logs sebagai counter. Fail-open saat DB error.
 *
 * @return bool true bila masih boleh request, false bila melebihi limit.
 */
function licenseApiRateLimitOk(int $maxPerMinute = 60): bool {
    try {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        if ($ip !== '') {
            $ip = substr(trim(explode(',', $ip)[0]), 0, 45);
        }
        if ($ip === '') {
            return true; // tanpa IP tak bisa di-rate-limit — biarkan lewat
        }
        $stmt = getDB()->prepare(
            "SELECT COUNT(*) FROM license_api_logs
             WHERE ip_address = ? AND requested_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)"
        );
        $stmt->execute([$ip]);
        return (int) $stmt->fetchColumn() < $maxPerMinute;
    } catch (Exception $e) {
        error_log('licenseApiRateLimitOk error: ' . $e->getMessage());
        return true;
    }
}

/**
 * Aktivasi lisensi Averion ke License Server, lalu simpan hasilnya ke
 * license_info. Satu sumber kebenaran yang dipakai oleh
 * actions/admin/activate-license.php DAN auto re-aktivasi di
 * actions/auth/login.php (hindari duplikasi HTTP call + simpan token).
 *
 * Membedakan tiga hasil:
 *  - network_error = true  → server tidak bisa dihubungi (pemanggil boleh
 *                            lanjut sebagai pending_activation). DB TIDAK diubah.
 *  - success = false (network_error false) → server merespons tapi MENOLAK key
 *                            (invalid/expired/domain lain). DB TIDAK diubah.
 *  - success = true        → tersimpan ke license_info (status sesuai payload,
 *                            signed_token + token_valid_until, pending_reason NULL).
 *
 * Verifikasi signature sesungguhnya terjadi saat enforcement (resolveLicenseState);
 * di sini token disimpan sebagai cache.
 *
 * @return array{success:bool,network_error:bool,message:string,status:?string}
 */
/**
 * Sidik-jari instalasi (watermark) yang stabil per instalasi. Dibuat sekali &
 * disimpan di settings; dikirim ke server lisensi tiap activate/validate agar
 * copy yang dibajak/diklon (instance berbeda) bisa dibedakan & dilacak vendor.
 */
function licenseInstanceId(): string
{
    try {
        $id = (string) getSetting('license_instance_id', '');
        if ($id === '') {
            $id = bin2hex(random_bytes(16));
            setSetting('license_instance_id', $id, 'license');
        }
        return $id;
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * POST JSON ke endpoint License Server via cURL (bukan file_get_contents/stream
 * wrapper). Banyak shared hosting mematikan `allow_url_fopen` demi keamanan —
 * saat itu terjadi, file_get_contents ke URL luar SELALU return false walau
 * cURL & koneksi outbound normal. Semua panggilan aktivasi/validasi lisensi
 * WAJIB lewat sini (bukan lagi stream_context_create) agar konsisten dengan
 * check-update/apply-update/vendor-fulfillment yang sudah pakai cURL.
 *
 * @return array{ok:bool,body:?string,error:string}
 */
function licenseServerPost(string $path, array $payload, int $timeoutSec = 10): array
{
    $url = rtrim(LICENSE_SERVER_URL, '/') . '/' . ltrim($path, '/');

    if (!function_exists('curl_init')) {
        // Fallback defensif — ekstensi curl nyaris selalu ada di hosting PHP modern,
        // tapi jangan sampai instalasi tanpa curl kehilangan aktivasi sama sekali.
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST', 'header' => "Content-Type: application/json\r\nUser-Agent: Averion/1.0\r\n",
                'content' => json_encode($payload), 'timeout' => $timeoutSec, 'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $result = @file_get_contents($url, false, $ctx);
        return $result === false
            ? ['ok' => false, 'body' => null, 'error' => 'Tidak dapat menghubungi server lisensi.']
            : ['ok' => true, 'body' => $result, 'error' => ''];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'User-Agent: Averion/1.0'],
        CURLOPT_TIMEOUT        => $timeoutSec,
        CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSec),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'body' => null, 'error' => 'Tidak dapat menghubungi server lisensi.' . ($curlErr !== '' ? " ($curlErr)" : '')];
    }
    // Endpoint license server selalu balas JSON meski gagal validasi (400/403 dgn
    // {"success":false,"message":...}) — jadi body tetap diteruskan apa pun http code-nya,
    // caller yang menafsirkan payload JSON-nya.
    return ['ok' => true, 'body' => (string) $body, 'error' => ''];
}

function attemptLicenseActivation(string $licenseKey, string $domain): array
{
    $out = ['success' => false, 'network_error' => false, 'message' => '', 'status' => null];

    $payload = [
        'license_key' => $licenseKey,
        'domain'      => $domain,
        'instance_id' => licenseInstanceId(),
        'app_version' => APP_VERSION,
    ];
    $req = licenseServerPost('activate', $payload);

    // ── Kegagalan jaringan (server tak terjangkau) ──
    if (!$req['ok']) {
        $out['network_error'] = true;
        $out['message'] = $req['error'] !== '' ? $req['error'] : 'Tidak dapat menghubungi server lisensi.';
        return $out;
    }
    $result = $req['body'];

    $decoded = json_decode($result, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        $out['message'] = 'Respons server lisensi tidak valid.';
        return $out;
    }

    $signedToken = isset($decoded['signed_token']) ? (string) $decoded['signed_token'] : '';

    // ── Server merespons tapi menolak (kegagalan VALIDASI, bukan jaringan) ──
    if (empty($decoded['success']) || $signedToken === '') {
        $out['message'] = (string) ($decoded['message'] ?? 'Aktivasi ditolak server lisensi. Periksa kembali license key.');
        return $out;
    }

    // ── Sukses: decode payload token (cache; verifikasi saat enforcement) ──
    $tokenPayload = null;
    $tp = explode('.', $signedToken);
    if (count($tp) === 3) {
        $rawP = base64UrlDecode($tp[1]);
        if ($rawP !== false) {
            $d = json_decode($rawP, true);
            if (is_array($d)) $tokenPayload = $d;
        }
    }

    $planAllowed = ['monthly', 'quarterly', 'yearly', 'lifetime'];
    $plan = $tokenPayload['plan'] ?? ($decoded['plan'] ?? null);
    $plan = in_array($plan, $planAllowed, true) ? $plan : null;

    $status = $tokenPayload['status'] ?? ($decoded['status'] ?? 'active');
    if (!in_array($status, ['active', 'expired', 'suspended', 'unknown', 'pending_activation'], true)) {
        $status = 'active';
    }

    $vu = $tokenPayload['valid_until'] ?? ($decoded['valid_until'] ?? null);
    $tokenValidUntil = (!empty($vu) && strtotime((string) $vu) !== false) ? date('Y-m-d H:i:s', strtotime((string) $vu)) : null;

    $ex = $tokenPayload['expires_at'] ?? ($decoded['expires_at'] ?? null);
    $expiresAt = (!empty($ex) && strtotime((string) $ex) !== false) ? date('Y-m-d H:i:s', strtotime((string) $ex)) : null;

    try {
        $pdo = getDB();
        $existingId = $pdo->query("SELECT id FROM license_info ORDER BY id ASC LIMIT 1")->fetchColumn();
        if ($existingId) {
            $pdo->prepare(
                "UPDATE license_info SET
                   license_key = ?, domain = ?, plan = ?, plan_effective = ?, status = ?,
                   activation_pending_reason = NULL, activated_at = NOW(), expires_at = ?,
                   last_checked_at = NOW(), signed_token = ?, token_valid_until = ?,
                   raw_response = ?, updated_at = NOW()
                 WHERE id = ?"
            )->execute([
                $licenseKey, $domain, $plan, $plan, $status,
                $expiresAt, $signedToken, $tokenValidUntil, json_encode($decoded), $existingId,
            ]);
        } else {
            $pdo->prepare(
                "INSERT INTO license_info
                   (license_key, domain, plan, plan_effective, status, activation_pending_reason,
                    activated_at, expires_at, last_checked_at, signed_token, token_valid_until, raw_response)
                 VALUES (?, ?, ?, ?, ?, NULL, NOW(), ?, NOW(), ?, ?, ?)"
            )->execute([
                $licenseKey, $domain, $plan, $plan, $status, $expiresAt, $signedToken, $tokenValidUntil, json_encode($decoded),
            ]);
        }
        setSetting('license_verified_once', '1', 'license');
    } catch (Exception $e) {
        error_log('attemptLicenseActivation save error: ' . $e->getMessage());
        $out['message'] = 'Aktivasi berhasil diverifikasi tetapi gagal disimpan. Coba lagi.';
        return $out;
    }

    $out['success'] = true;
    $out['status']  = $status;
    $out['message'] = 'Lisensi berhasil diaktivasi.';
    return $out;
}
