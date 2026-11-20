<?php
// ─── Notifikasi Update Tersedia ─────────────────────────────────────────
// Diekstrak dari helpers.php (pemecahan monolit — memori helpers-split-plan).
// Dimuat via bootstrap.php ($__averionCoreFiles, SETELAH helpers.php).
// Behavior-preserving: pindah utuh (fungsi + docblock), tanpa ubah logika.

/**
 * Versi update yang tersedia (lebih tinggi dari APP_VERSION), atau '' bila
 * tidak ada / belum dicek. Sumber: setting hasil cek harian Auto-Cron.
 */
function updateAvailableVersion(): string
{
    $v = trim((string) getSetting('update_available_version', ''));
    if ($v === '' || !defined('APP_VERSION')) return '';
    return version_compare($v, APP_VERSION, '>') ? $v : '';
}

/**
 * Simpan broadcast dari vendor (field `broadcast` pada respons check-update) ke
 * setting lokal. $b = array dari server, atau apa pun bila tak ada → dibersihkan.
 * SENYAP & defensif: hanya menulis 1 setting; tak menyentuh jalur lain.
 * CTA URL WAJIB https (kalau tidak → tombol dibuang, pesan tetap tampil).
 */
function storeVendorBroadcast($b): void
{
    try {
        if (!is_array($b) || empty($b['id']) || trim((string) ($b['title'] ?? '')) === '') {
            setSetting('vendor_broadcast', '');
            return;
        }
        $ctaUrl   = trim((string) ($b['cta_url'] ?? ''));
        $ctaLabel = mb_substr(trim((string) ($b['cta_label'] ?? '')), 0, 80);
        // Hanya izinkan https absolut & URL valid; selain itu buang tombolnya.
        if ($ctaUrl !== '' && !(preg_match('#^https://#i', $ctaUrl) && filter_var($ctaUrl, FILTER_VALIDATE_URL))) {
            $ctaUrl = '';
        }
        if ($ctaUrl === '') $ctaLabel = '';

        $endsAt = trim((string) ($b['ends_at'] ?? ''));
        $clean  = [
            'id'        => (int) $b['id'],
            'title'     => mb_substr(trim((string) $b['title']), 0, 200),
            'body'      => mb_substr(trim((string) ($b['body'] ?? '')), 0, 4000),
            'cta_label' => $ctaLabel,
            'cta_url'   => $ctaUrl,
            'ends_at'   => ($endsAt !== '' && strtotime($endsAt) !== false) ? $endsAt : null,
        ];
        setSetting('vendor_broadcast', json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    } catch (Throwable $e) {
        error_log('storeVendorBroadcast: ' . $e->getMessage());
    }
}

/**
 * Broadcast vendor yang layak DITAMPILKAN di lonceng admin, atau null.
 * Disaring: belum kedaluwarsa (ends_at) & belum ditutup admin (dismissed_id).
 */
function vendorBroadcast(): ?array
{
    try {
        $raw = trim((string) getSetting('vendor_broadcast', ''));
        if ($raw === '') return null;
        $b = json_decode($raw, true);
        if (!is_array($b) || empty($b['id']) || trim((string) ($b['title'] ?? '')) === '') return null;

        if (!empty($b['ends_at'])) {
            $ts = strtotime((string) $b['ends_at']);
            if ($ts !== false && $ts < time()) return null; // kedaluwarsa
        }
        if ((int) getSetting('vendor_broadcast_dismissed_id', '0') === (int) $b['id']) return null; // sudah ditutup
        return $b;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Cek update tiap 12 jam (klaim via setting update_check_last_run, pola
 * db_backup_last_run: UPDATE WHERE value=lama + rowCount → anti-balapan).
 */
function processUpdateNotifier(): void
{
    try {
        if (!defined('LICENSE_SERVER_URL') || !defined('APP_VERSION')) return;

        $pdo  = getDB();
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'update_check_last_run' LIMIT 1");
        $stmt->execute();
        $lastRaw = $stmt->fetchColumn();
        if ((int) ($lastRaw ?: 0) > time() - 12 * 3600) return;
        if ($lastRaw === false) {
            $pdo->prepare("INSERT IGNORE INTO settings (setting_key, setting_value, setting_group) VALUES ('update_check_last_run', '0', 'general')")->execute();
            $lastRaw = '0';
        }
        $claim = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'update_check_last_run' AND setting_value = ?");
        $claim->execute([(string) time(), (string) $lastRaw]);
        if ($claim->rowCount() < 1) return; // proses lain menang

        // Lisensi wajib active — sama dengan gerbang check-update manual.
        $lic = $pdo->query("SELECT license_key, domain, status FROM license_info ORDER BY id ASC LIMIT 1")->fetch();
        if (!$lic || ($lic['status'] ?? '') !== 'active') return;

        $ch = curl_init(rtrim(LICENSE_SERVER_URL, '/') . '/check-update');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'license_key'     => $lic['license_key'],
                'domain'          => $lic['domain'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost'),
                'current_version' => APP_VERSION,
            ]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response === false || $httpCode !== 200) return; // server tak terjangkau → coba lagi besok

        $data = json_decode((string) $response, true);
        if (!is_array($data)) return;

        // Broadcast vendor (Fase 1): simpan/bersihkan tiap cek. Terpisah dari update.
        storeVendorBroadcast($data['broadcast'] ?? null);

        $latest = preg_replace('/[^0-9.]/', '', (string) ($data['latest_version'] ?? ''));
        if (!empty($data['has_update']) && $latest !== '' && version_compare($latest, APP_VERSION, '>')) {
            setSetting('update_available_version', $latest);
            // Email admin SEKALI per versi.
            if (getSetting('update_notified_version', '') !== $latest) {
                setSetting('update_notified_version', $latest); // set dulu → gagal kirim tak akan spam
                $changelog = trim((string) ($data['changelog'] ?? ''));
                $brand     = (string) getSetting('brand_color', '#10b981');
                if (!preg_match('/^#[0-9a-fA-F]{6}$/', $brand)) { $brand = '#10b981'; }
                $updateUrl = e(baseUrl()) . '/admin/update';
                $clHtml    = formatChangelogHtml(mb_substr($changelog, 0, 1500), $brand);

                $admins = $pdo->query("SELECT name, email FROM users WHERE role = 'admin' AND status = 'active' AND email IS NOT NULL AND email <> ''")->fetchAll();
                foreach ($admins as $adm) {
                    $inner = '<p style="margin:0 0 14px;">Halo <strong>' . e($adm['name']) . '</strong>,</p>'
                        . '<p style="margin:0 0 18px;">Versi baru <strong>' . e(APP_NAME) . ' v' . e($latest) . '</strong> sudah tersedia untuk instalasimu — saat ini kamu di <strong>v' . e(APP_VERSION) . '</strong>.</p>'
                        . ($clHtml !== ''
                            ? '<div style="background:#f8fafc;border:1px solid #eef1f6;border-radius:12px;padding:16px 18px;margin:0 0 20px;">'
                              . '<p style="margin:0 0 10px;font-size:12px;font-weight:700;letter-spacing:0.04em;text-transform:uppercase;color:#94a3b8;">Yang Baru</p>'
                              . $clHtml
                              . '</div>'
                            : '')
                        // Tombol CTA (table-based agar tetap tampil di Outlook)
                        . '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 16px;"><tr>'
                        . '<td style="background:' . $brand . ';border-radius:10px;">'
                        . '<a href="' . $updateUrl . '" style="display:inline-block;padding:12px 26px;font-size:14px;font-weight:700;color:#ffffff;text-decoration:none;">Update Sekarang &rarr;</a>'
                        . '</td></tr></table>'
                        . '<p style="margin:0;font-size:13px;line-height:1.7;color:#94a3b8;">Atau buka <strong>Admin &rarr; Update Sistem</strong> di dashboardmu. Proses berjalan otomatis &amp; file di-backup lebih dulu.</p>';
                    try {
                        sendEmail($adm['email'], 'Update ' . APP_NAME . ' v' . $latest . ' tersedia', emailLayout('Update v' . $latest . ' Tersedia', $inner, 'success', 'left'));
                    } catch (Throwable $e) {
                        error_log('processUpdateNotifier email: ' . $e->getMessage());
                    }
                }
            }
        } else {
            setSetting('update_available_version', ''); // sudah terbaru → bersihkan badge
        }
    } catch (Throwable $e) {
        error_log('processUpdateNotifier error: ' . $e->getMessage());
    }
}
