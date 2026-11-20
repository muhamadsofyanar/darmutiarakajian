<?php
// POST /actions/admin/apply-update
// Handler paling kritikal: download → verifikasi → backup → extract → apply →
// migration → catat histori → cleanup. Semua dalam try/catch dengan rollback.
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../helpers/update-backups.php';
requireAdmin();

set_time_limit(600); // proses bisa lama (download besar)
ignore_user_abort(true);
header('Content-Type: application/json');

/**
 * Pecah file SQL migrasi menjadi statement, SADAR komentar & string literal.
 * Menggantikan explode(';') naif yang pecah kalau ada ';' di dalam komentar
 * (mis. "-- fix a; b") atau string. Menangani komentar -- dan blok, string
 * ' " `, dan quote ganda (''). Komentar di-strip sebelum eksekusi.
 */
if (!function_exists('_averionSplitMigrationSql')) {
    function _averionSplitMigrationSql(string $sql): array
    {
        $statements = [];
        $current    = '';
        $inString   = false;
        $stringChar = '';
        $len        = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($inString) {
                $current .= $ch;
                if ($ch === '\\') {
                    if ($i + 1 < $len) { $current .= $sql[++$i]; }
                } elseif ($ch === $stringChar) {
                    if ($i + 1 < $len && $sql[$i + 1] === $stringChar) {
                        $current .= $sql[++$i]; // quote ganda ('' atau "")
                    } else {
                        $inString = false;
                    }
                }
            } elseif ($ch === "'" || $ch === '"' || $ch === '`') {
                $inString = true; $stringChar = $ch; $current .= $ch;
            } elseif ($ch === '-' && $i + 1 < $len && $sql[$i + 1] === '-') {
                while ($i < $len && $sql[$i] !== "\n") { $i++; } // skip komentar --
            } elseif ($ch === '#') {
                while ($i < $len && $sql[$i] !== "\n") { $i++; } // skip komentar #
            } elseif ($ch === '/' && $i + 1 < $len && $sql[$i + 1] === '*') {
                $i += 2;
                while ($i < $len - 1 && !($sql[$i] === '*' && $sql[$i + 1] === '/')) { $i++; }
                $i += 1; // hentikan tepat di '/'; loop for menaikkan 1 lagi
            } elseif ($ch === ';') {
                $stmt = trim($current);
                if ($stmt !== '') { $statements[] = $stmt; }
                $current = '';
            } else {
                $current .= $ch;
            }
        }
        $stmt = trim($current);
        if ($stmt !== '') { $statements[] = $stmt; }
        return $statements;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Token keamanan tidak valid.']);
    exit;
}

$downloadToken = $_POST['download_token'] ?? '';
if ($downloadToken === '') {
    echo json_encode(['success' => false, 'message' => 'Download token tidak ada. Cek update ulang.']);
    exit;
}

// ─── Gerbang lisensi (defense-in-depth) ───────────────────────
// Update hanya boleh diterapkan bila lisensi berstatus 'active'. Mencegah
// endpoint ini dipanggil langsung saat lisensi belum terhubung/expired.
try {
    $lic = getDB()->query("SELECT status FROM license_info ORDER BY id ASC LIMIT 1")->fetch();
} catch (Exception $e) {
    $lic = null;
}
if (!$lic || ($lic['status'] ?? '') !== 'active') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Lisensi belum aktif/terhubung. Update dibatalkan.']);
    exit;
}

// ─── Akumulasi log untuk ditampilkan di client ────────────────
$logMessages = [];
$log = function (string $msg) use (&$logMessages): void {
    $logMessages[] = $msg;
};

$appRoot = dirname(__DIR__, 2); // root folder averion/

// ─── Resolusi path TAHAN-HOSTING ─────────────────────────────
// sys_get_temp_dir() (/tmp) & folder di luar webroot sering diblokir
// open_basedir di shared hosting/cPanel → mkdir gagal ("Gagal membuat folder
// temporary"). Coba path dari config; bila tak bisa dibuat/ditulis, fallback
// ke dalam aplikasi (storage/) yang dijamin writable & di dalam open_basedir.
try {
    $tempBaseResolved   = updateBackupWritableBase(UPDATE_TEMP_PATH, $appRoot . '/storage/update-tmp', $appRoot);
    $backupBaseResolved = updateBackupWritableBase(UPDATE_BACKUP_PATH, $appRoot . '/storage/update-backups', $appRoot);
} catch (Throwable $e) {
    error_log('apply-update folder kerja: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Folder kerja update tidak dapat dibuat atau ditulis.']);
    exit;
}

// ─── Helper cleanup yang AMAN (hanya boleh hapus di dalam temp base) ───
$cleanup = function (string $dir) use ($tempBaseResolved): void {
    $tempBase = rtrim(str_replace('\\', '/', $tempBaseResolved), '/');
    $target   = rtrim(str_replace('\\', '/', $dir), '/');
    if ($tempBase === '' || !str_starts_with($target . '/', $tempBase . '/')) {
        error_log('apply-update cleanup: menolak hapus di luar temp: ' . $dir);
        return;
    }
    if (!is_dir($dir)) return;
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
};

$tempDir   = $tempBaseResolved . '/' . uniqid('update_');
$backupDir = $backupBaseResolved . '/backup_' . date('Ymd_His') . '_v' . APP_VERSION . '_' . bin2hex(random_bytes(3));
$zipFile   = $tempDir . '/update.zip';
$backupCompleted = false;

$fromVersion  = APP_VERSION;
$latestVersion = $_SESSION['update_check_' . date('Ymd')]['latest_version'] ?? 'unknown';
$expectedMd5   = $_SESSION['update_check_' . date('Ymd')]['checksum_md5'] ?? '';

try {
    $pdo = getDB();

    // ====== STEP 1: PERSIAPAN ======
    $log('Memulai proses update dari v' . $fromVersion . '...');
    if (!is_dir($tempDir) && !mkdir($tempDir, 0750, true) && !is_dir($tempDir)) {
        throw new RuntimeException('Gagal membuat folder temporary.');
    }
    // ====== STEP 2: DOWNLOAD ======
    $log('Mengunduh paket update dari server...');
    $ch = curl_init(rtrim(LICENSE_SERVER_URL, '/') . '/download-update?token=' . urlencode($downloadToken));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $zipContent = curl_exec($ch);
    $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr    = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200 || $zipContent === false || strlen($zipContent) < 1000) {
        $log('ERROR: Gagal mengunduh paket. HTTP ' . $httpCode . ($curlErr ? ' — ' . $curlErr : ''));
        $cleanup($tempDir);
        echo json_encode(['success' => false, 'messages' => $logMessages, 'message' => 'Gagal mengunduh paket update.']);
        exit;
    }

    if (file_put_contents($zipFile, $zipContent) !== strlen($zipContent)) {
        throw new RuntimeException('Gagal menyimpan paket update sementara.');
    }
    $log('Download selesai: ' . number_format(strlen($zipContent) / 1024, 1) . ' KB');

    // ====== STEP 3: VERIFIKASI CHECKSUM ======
    $log('Memverifikasi integritas file...');

    // Checksum WAJIB ada. Jika kosong, sesi cek-update sudah kadaluarsa/hilang →
    // tolak update agar paket tidak pernah diterapkan tanpa verifikasi integritas.
    if ($expectedMd5 === '') {
        $log('ERROR: Checksum tidak tersedia (sesi kadaluarsa).');
        $cleanup($tempDir);
        echo json_encode(['success' => false, 'messages' => $logMessages, 'message' => 'Sesi kadaluarsa, silakan Cek Update ulang.']);
        exit;
    }

    $downloadedMd5 = md5_file($zipFile);
    if (!hash_equals($expectedMd5, $downloadedMd5)) {
        $log('ERROR: Checksum tidak cocok! File mungkin rusak atau dimanipulasi.');
        $cleanup($tempDir);
        echo json_encode(['success' => false, 'messages' => $logMessages, 'message' => 'Verifikasi checksum gagal.']);
        exit;
    }
    $log('Checksum valid ✓');

    // ====== STEP 4: BACKUP FILE LAMA ======
    $log('Membuat backup ke: ' . basename($backupDir) . '...');
    if (!@mkdir($backupDir, 0750) || file_put_contents($backupDir . '/.snapshot-incomplete', date('c')) === false) {
        throw new RuntimeException('Gagal menyiapkan folder snapshot.');
    }
    $snapshotStats = updateBackupCopySnapshot($appRoot, $backupDir, [
        $appRoot . '/config.php',
        $appRoot . '/assets/uploads',
        $appRoot . '/backups',
        $appRoot . '/storage/update-tmp',
        $appRoot . '/storage/update-backups',
        $tempBaseResolved,
        $backupBaseResolved,
    ]);
    if (file_put_contents($backupDir . '/.snapshot-complete', json_encode($snapshotStats)) === false) {
        throw new RuntimeException('Gagal menandai snapshot lengkap.');
    }
    @unlink($backupDir . '/.snapshot-incomplete');
    $backupCompleted = true;
    $log('Backup selesai: ' . $snapshotStats['files'] . ' file ✓');

    // ====== STEP 5: EXTRACT ZIP ======
    $log('Mengekstrak paket update...');
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) {
        throw new RuntimeException('File ZIP tidak bisa dibuka.');
    }
    $extractedRoot = $tempDir . '/extracted/';
    $extracted = $zip->extractTo($extractedRoot);
    $zip->close();
    if (!$extracted) throw new RuntimeException('Gagal mengekstrak paket update.');
    $log('Ekstrak selesai ✓');

    // Cari root folder di dalam zip (kadang ada satu subfolder pembungkus).
    $contents = array_values(array_diff(scandir($extractedRoot) ?: [], ['.', '..']));
    if (count($contents) === 1 && is_dir($extractedRoot . $contents[0])) {
        $extractedRoot .= $contents[0] . '/';
    }

    // ====== STEP 6: APPLY FILE ======
    $log('Menerapkan update...');
    $neverOverwrite = ['config.php', 'assets/uploads/'];
    // Pengecualian: file .htaccess SISTEM di folder upload WAJIB tetap diperbarui
    // (bukan data user). Versi lama tak-portabel bikin gambar 500/tak tampil di
    // sebagian hosting; perbaikan harus bisa terkirim lewat update.
    $alwaysUpdate   = ['assets/uploads/.htaccess', 'assets/uploads/canvas/.htaccess'];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($extractedRoot, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    $filesApplied = 0;
    foreach ($iterator as $item) {
        if (!$item->isFile()) continue;
        $relativePath = str_replace($extractedRoot, '', $item->getPathname());
        $relNorm      = str_replace('\\', '/', $relativePath);

        $skip = false;
        if (!in_array(ltrim($relNorm, '/'), $alwaysUpdate, true)) {
            foreach ($neverOverwrite as $p) {
                if ($relNorm === $p || str_starts_with($relNorm, $p)) { $skip = true; break; }
            }
        }
        if ($skip) continue;

        $destPath = $appRoot . '/' . $relativePath;
        $destDir  = dirname($destPath);
        if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
        @copy($item->getPathname(), $destPath);
        $filesApplied++;
    }
    $log('File diterapkan: ' . $filesApplied . ' file ✓');

    // ====== STEP 7: MIGRATION SQL (opsional) ======
    // Jalankan SEMUA migrasi antara versi terpasang dan versi target (bukan
    // hanya update-{target}.sql) — client yang melompat beberapa versi tetap
    // mendapat perubahan skema versi di antaranya. Error "sudah ada" (duplicate
    // column/table/key) ditelan per-statement agar re-run/catch-up idempotent.
    $migrationFailed = false;
    $migrationError  = '';
    $pendingMigrations = [];
    foreach (glob($extractedRoot . 'migrations/update-*.sql') ?: [] as $mf) {
        if (!preg_match('/update-(\d+(?:\.\d+)*)\.sql$/', basename($mf), $m)) continue;
        $mv = $m[1];
        if (version_compare($mv, $fromVersion, '<=')) continue;
        if ($latestVersion !== 'unknown' && version_compare($mv, $latestVersion, '>')) continue;
        $pendingMigrations[$mv] = $mf;
    }
    uksort($pendingMigrations, 'version_compare');

    if ($pendingMigrations) {
        $log('Menjalankan database migration (' . count($pendingMigrations) . ' file)...');
        // Errno MySQL/MariaDB yang berarti "perubahan sudah pernah diterapkan"
        // (aman dilewati saat re-run/catch-up): 1050 tabel ada, 1060 kolom ada,
        // 1061 nama index ada, 1062 entri duplikat, 1091 objek utk di-drop tak ada,
        // 1826 nama FK duplikat (MySQL 8), 121 duplikat FK/key on write (InnoDB).
        $alreadyAppliedErrnos = [1050, 1060, 1061, 1062, 1091, 1826, 121];
        // GOTCHA lintas-engine: MariaDB lama (10.x, umum di shared hosting) melaporkan
        // NAMA FK DUPLIKAT sebagai 1005 "Can't create table (errno: 121)" — bukan 1826.
        // errorInfo[1] = 1005 (generik "Can't create table"), errno asli (121) hanya
        // ada di TEKS pesan. Karena 1005 juga dipakai error FK sungguhan (errno 150:
        // tabel/kolom rujukan hilang) yang TIDAK boleh ditelan, pembedanya = teks pesan.
        $isDuplicateConstraint = static function (PDOException $pe): bool {
            $errno = (int) ($pe->errorInfo[1] ?? 0);
            $msg   = (string) ($pe->errorInfo[2] ?? '');
            return $errno === 1005 && (
                stripos($msg, 'errno: 121') !== false      // duplikat FK/constraint
                || stripos($msg, 'Duplicate key on write') !== false
            );
        };
        foreach ($pendingMigrations as $mv => $migrationFile) {
            try {
                $sql        = file_get_contents($migrationFile);
                $statements = _averionSplitMigrationSql($sql); // sadar komentar & string
                foreach ($statements as $stmt) {
                    if (trim($stmt) === '') continue;
                    try {
                        $pdo->exec($stmt);
                    } catch (PDOException $pe) {
                        $errno = (int) ($pe->errorInfo[1] ?? 0);
                        if (in_array($errno, $alreadyAppliedErrnos, true) || $isDuplicateConstraint($pe)) {
                            $log('  · v' . $mv . ': statement dilewati (sudah diterapkan, errno ' . $errno . ')');
                            continue;
                        }
                        throw $pe;
                    }
                }
                $log('Migration v' . $mv . ' berhasil ✓');
            } catch (Exception $e) {
                // Migration gagal: file sudah ter-apply tapi skema belum sinkron.
                // Berhenti di file ini (urutan versi penting), tandai 'partial'.
                $migrationFailed = true;
                $remaining = array_filter(
                    array_keys($pendingMigrations),
                    fn ($v) => version_compare($v, $mv, '>=')
                );
                $migrationError = 'update-' . $mv . '.sql: ' . $e->getMessage()
                    . ' (belum jalan: ' . implode(', ', $remaining) . ')';
                $log('WARNING: Migration v' . $mv . ' gagal: ' . $e->getMessage());
                $log('Status update: PARTIAL — file terpasang tapi migration DB perlu dijalankan manual.');
                break;
            }
        }
    }

    // ====== STEP 8: CATAT HISTORI ======
    $historyStatus = $migrationFailed ? 'partial' : 'success';
    $historyNotes  = $migrationFailed
        ? 'Migration DB gagal — jalankan manual file di migrations/. Error: ' . $migrationError
        : null;
    $pdo->prepare(
        "INSERT INTO update_history (from_version, to_version, backup_path, status, notes)
         VALUES (?, ?, ?, ?, ?)"
    )->execute([$fromVersion, $latestVersion, $backupDir, $historyStatus, $historyNotes]);

    // ====== STEP 9: CLEANUP ======
    $cleanup($tempDir);
    // Invalidate cache cek update agar versi baru terdeteksi.
    unset($_SESSION['update_check_' . date('Ymd')]);
    // Bersihkan badge/toast update + reset jatah cek otomatis, supaya
    // instalasi yang baru update cepat mengetahui rilis berikutnya.
    try {
        $pdo->prepare("UPDATE settings SET setting_value = '' WHERE setting_key = 'update_available_version'")->execute();
        $pdo->prepare("UPDATE settings SET setting_value = '0' WHERE setting_key = 'update_check_last_run'")->execute();
    } catch (Exception $e) {
        error_log('apply-update reset notifier: ' . $e->getMessage());
    }

    if ($migrationFailed) {
        $log('Update ke v' . $latestVersion . ' selesai dengan PERINGATAN (migration DB gagal).');
    } else {
        $log('Update ke v' . $latestVersion . ' berhasil! 🎉');
    }
    $log('Silakan refresh halaman untuk melihat perubahan.');

    echo json_encode([
        'success'     => true,
        'status'      => $historyStatus,
        'partial'     => $migrationFailed,
        'messages'    => $logMessages,
        'new_version' => $latestVersion,
    ]);

} catch (Throwable $e) {
    error_log('apply-update error: ' . $e->getMessage());
    $log('ERROR: ' . $e->getMessage());

    // Catat kegagalan ke histori (best-effort).
    try {
        getDB()->prepare(
            "INSERT INTO update_history (from_version, to_version, backup_path, status, notes)
             VALUES (?, ?, ?, 'failed', ?)"
        )->execute([$fromVersion, $latestVersion, $backupCompleted ? $backupDir : null, $e->getMessage()]);
    } catch (Throwable $e2) {
        error_log('apply-update history log error: ' . $e2->getMessage());
    }

    $cleanup($tempDir);
    echo json_encode([
        'success'  => false,
        'messages' => $logMessages,
        'message'  => $backupCompleted
            ? 'Update gagal. Snapshot lengkap tersimpan di: ' . basename($backupDir)
            : 'Update gagal sebelum snapshot lengkap. File aplikasi belum diterapkan.',
    ]);
}
