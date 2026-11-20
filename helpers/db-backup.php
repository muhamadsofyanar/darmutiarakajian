<?php
// ─── Auto-Backup Database ──────────────────────────────────────
// Diekstrak dari helpers.php (pemecahan monolit — memori helpers-split-plan).
// Dimuat via bootstrap.php ($__averionCoreFiles, SETELAH helpers.php).
// Behavior-preserving: pindah utuh, tanpa ubah signature/logika.
// Dump pure-PHP (tanpa exec/mysqldump — aman di shared hosting), gzip,
// disimpan di folder backup DI LUAR webroot (konvensi UPDATE_BACKUP_PATH),
// fallback ke dalam aplikasi dengan .htaccess deny. Jadwal harian via Auto-Cron.

function dbBackupDir(): ?string
{
    $candidates = [];
    if (defined('UPDATE_BACKUP_PATH')) {
        $candidates[] = rtrim(UPDATE_BACKUP_PATH, '/\\') . DIRECTORY_SEPARATOR . 'db';
    }
    $candidates[] = dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'db';
    $candidates[] = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'db';

    foreach ($candidates as $dir) {
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        if (is_dir($dir) && is_writable($dir)) {
            // Fallback di dalam webroot → wajib tolak akses langsung via browser.
            if (strpos($dir, dirname(__DIR__) . DIRECTORY_SEPARATOR) === 0) {
                $ht = $dir . DIRECTORY_SEPARATOR . '.htaccess';
                if (!is_file($ht)) {
                    @file_put_contents($ht, "Require all denied\nDeny from all\n");
                }
            }
            return $dir;
        }
    }
    return null;
}

/**
 * Buat backup DB lengkap (struktur + data) sebagai .sql.gz.
 * Pure PHP: SHOW CREATE TABLE + SELECT per-chunk → INSERT batch.
 */
function createDbBackup(): array
{
    $t0  = microtime(true);
    $dir = dbBackupDir();
    if ($dir === null) {
        return ['success' => false, 'message' => 'Folder backup tidak bisa dibuat/ditulis.'];
    }

    $file = $dir . DIRECTORY_SEPARATOR . 'averion-db-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 6) . '.sql.gz';
    $gz   = gzopen($file, 'wb6');
    if ($gz === false) {
        return ['success' => false, 'message' => 'Gagal membuat file backup.'];
    }

    try {
        $pdo = getDB();
        gzwrite($gz, "-- Averion Database Backup\n-- Dibuat  : " . date('Y-m-d H:i:s') . "\n-- Versi   : " . (defined('APP_VERSION') ? APP_VERSION : '-') . "\n\n");
        gzwrite($gz, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

        $tables = [];
        foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
            if (strtoupper((string) $row[1]) === 'BASE TABLE') $tables[] = (string) $row[0];
        }

        $rowTotal = 0;
        foreach ($tables as $tbl) {
            $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $tbl) . '`')->fetch(PDO::FETCH_NUM);
            gzwrite($gz, "DROP TABLE IF EXISTS `$tbl`;\n" . $create[1] . ";\n\n");

            $chunk = 1000; $offset = 0;
            while (true) {
                $stmt = $pdo->query('SELECT * FROM `' . str_replace('`', '``', $tbl) . "` LIMIT $chunk OFFSET $offset");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (!$rows) break;

                $values = [];
                foreach ($rows as $r) {
                    $vals = [];
                    foreach ($r as $v) {
                        $vals[] = ($v === null) ? 'NULL' : $pdo->quote((string) $v);
                    }
                    $values[] = '(' . implode(',', $vals) . ')';
                    $rowTotal++;
                }
                gzwrite($gz, "INSERT INTO `$tbl` VALUES\n" . implode(",\n", $values) . ";\n");

                if (count($rows) < $chunk) break;
                $offset += $chunk;
            }
            gzwrite($gz, "\n");
        }

        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
        gzclose($gz);

        return [
            'success'  => true,
            'file'     => basename($file),
            'path'     => $file,
            'size'     => (int) filesize($file),
            'tables'   => count($tables),
            'rows'     => $rowTotal,
            'duration' => round(microtime(true) - $t0, 2),
        ];
    } catch (Throwable $e) {
        @gzclose($gz);
        @unlink($file);
        error_log('createDbBackup error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Backup gagal: ' . $e->getMessage()];
    }
}

/** Daftar file backup (terbaru dulu): [name, size, mtime]. */
function listDbBackups(): array
{
    $dir = dbBackupDir();
    if ($dir === null) return [];
    $out = [];
    foreach (glob($dir . DIRECTORY_SEPARATOR . 'averion-db-*.sql.gz') ?: [] as $f) {
        $out[] = ['name' => basename($f), 'size' => (int) filesize($f), 'mtime' => (int) filemtime($f)];
    }
    usort($out, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return $out;
}

/** Hapus backup lebih tua dari N hari. Return jumlah file terhapus. */
function pruneDbBackups(int $days): int
{
    $dir = dbBackupDir();
    if ($dir === null) return 0;
    $limit = time() - max(1, $days) * 86400;
    $n = 0;
    foreach (glob($dir . DIRECTORY_SEPARATOR . 'averion-db-*.sql.gz') ?: [] as $f) {
        if (filemtime($f) < $limit && @unlink($f)) $n++;
    }
    return $n;
}

/**
 * Backup harian otomatis (Auto-Cron / cron asli). Klaim optimistik lewat baris
 * settings db_backup_last_run (UPDATE ... WHERE value = nilai-lama, rowCount 1
 * = menang) → aman dijalankan paralel tanpa backup dobel.
 */
function processDbBackups(): array
{
    $result = ['created' => 0, 'pruned' => 0];
    try {
        if (getSetting('db_backup_enabled', '1') !== '1') return $result;

        $pdo  = getDB();
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'db_backup_last_run' LIMIT 1");
        $stmt->execute();
        $lastRaw = $stmt->fetchColumn();
        $last    = (int) ($lastRaw ?: 0);
        if ($last > time() - 24 * 3600) return $result;

        if ($lastRaw === false) {
            $pdo->prepare("INSERT IGNORE INTO settings (setting_key, setting_value, setting_group) VALUES ('db_backup_last_run', '0', 'backup')")->execute();
            $lastRaw = '0';
        }
        $claim = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'db_backup_last_run' AND setting_value = ?");
        $claim->execute([(string) time(), (string) $lastRaw]);
        if ($claim->rowCount() < 1) return $result; // proses lain menang

        $r = createDbBackup();
        if (!empty($r['success'])) {
            $result['created'] = 1;
        } else {
            error_log('processDbBackups: ' . ($r['message'] ?? 'gagal'));
        }
        $result['pruned'] = pruneDbBackups((int) (getSetting('db_backup_retention_days', '7') ?: 7));
    } catch (Throwable $e) {
        error_log('processDbBackups error: ' . $e->getMessage());
    }
    return $result;
}
