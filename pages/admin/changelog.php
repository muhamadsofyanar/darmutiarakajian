<?php
// /admin/changelog — Riwayat changelog publik (apa yang berubah untuk pembeli).
// Mengambil daftar rilis dari License Server (/api/changelog), KECUALI rilis
// internal (ditandai di sisi vendor via teks changelog "[internal]"/kosong).
// Di-cache ke settings (JSON) → tetap tampil walau vendor sesaat tak terjangkau,
// dan tak menembak vendor tiap buka halaman. Tombol "Perbarui" memaksa refresh.
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

$pageTitle   = 'Changelog';
$topbarTitle = 'Changelog';

$pdo            = getDB();
$currentVersion = APP_VERSION;

try {
    $license = $pdo->query('SELECT * FROM license_info ORDER BY id ASC LIMIT 1')->fetch() ?: null;
} catch (Throwable $e) {
    $license = null;
}

$CACHE_KEY = 'changelog_feed_cache';
$CACHE_AT  = 'changelog_feed_cache_at';
$CACHE_TTL = 6 * 3600; // 6 jam

/**
 * Ambil daftar changelog dari License Server. Return:
 *   ['ok' => bool, 'releases' => array, 'message' => string]
 */
function fetchVendorChangelog(array $license, string $currentVersion): array
{
    return ['ok' => false, 'releases' => [], 'message' => 'Catatan rilis tersedia di repositori Darmutiara Islamic.'];

}

/**
 * Bersihkan judul rilis: buang awalan "vX.Y.Z" + pemisah bila ada, supaya tak
 * dobel dengan chip versi di sebelahnya. Kembalikan '' bila judul cuma versi.
 */
function changelogCleanTitle(string $title, string $ver): string
{
    $t = trim($title);
    if ($t === '') return '';
    // Buang awalan opsional "Averion" + "vX.Y.Z" + pemisah (—, -, :, ·, |).
    $t = preg_replace('/^(averion\s+)?v?' . preg_quote($ver, '/') . '\s*[—\-:·|]*\s*/ui', '', $t);
    return trim((string) $t);
}

/**
 * Ubah teks changelog mentah jadi HTML rapi & aman (sudah ter-escape):
 * - buang baris "@" (marker committer bocor) & trailer git (Co-Authored-By dll)
 * - buang baris judul yang menduplikasi judul rilis
 * - kelompokkan baris berawalan "- / * / •" jadi daftar, sisanya jadi paragraf
 */
function changelogFormatBody(string $log, string $title, string $ver): string
{
    $log   = str_replace("\r\n", "\n", $log);
    $lines = explode("\n", $log);

    // Judul & tiap baris dinormalisasi sama (buang "Averion"+versi+pemisah) agar
    // baris pembuka yang menduplikasi judul bisa dikenali walau formatnya beda.
    $titleNorm = mb_strtolower(changelogCleanTitle($title, $ver));
    $clean = [];
    foreach ($lines as $ln) {
        $t = trim($ln);
        if ($t === '@') continue;
        if ($t !== '' && preg_match('/^(Co-?Authored-?By|Signed-off-by)\s*:/i', $t)) continue;
        if ($t !== '' && $titleNorm !== '') {
            $norm = mb_strtolower(changelogCleanTitle($t, $ver));
            if ($norm === $titleNorm) continue;
        }
        $clean[] = $t;
    }
    // Buang baris kosong di ujung.
    while ($clean && $clean[0] === '')              array_shift($clean);
    while ($clean && end($clean) === '')            array_pop($clean);

    $html  = '';
    $para  = [];
    $inUl  = false;
    $flushPara = function () use (&$para, &$html) {
        if ($para) {
            $html .= '<p>' . implode(' ', array_map('e', $para)) . '</p>';
            $para = [];
        }
    };
    foreach ($clean as $t) {
        if ($t === '') {
            $flushPara();
            if ($inUl) { $html .= '</ul>'; $inUl = false; }
            continue;
        }
        if (preg_match('/^[-*•]\s+(.*)$/u', $t, $m)) {
            $flushPara();
            if (!$inUl) { $html .= '<ul>'; $inUl = true; }
            $html .= '<li>' . e(trim($m[1])) . '</li>';
        } else {
            if ($inUl) { $html .= '</ul>'; $inUl = false; }
            $para[] = $t;
        }
    }
    $flushPara();
    if ($inUl) $html .= '</ul>';

    if ($html === '') {
        $html = '<p class="cl-muted">Tidak ada catatan untuk versi ini.</p>';
    }
    return $html;
}

$releases    = [];
$fetchError  = '';
$fromCache   = false;
$dataAt      = null;
$sourceLocal = false;
$licenseOk   = $license && ($license['status'] ?? '') === 'active';

// ─── Sumber UTAMA: data/changelog.json (dibundel tiap update) ──────────────
// Vendor hanya menyimpan 3 paket terbaru; file lokal ini memuat riwayat LENGKAP
// sampai versi terpasang (dibuat dari git tag saat rilis via
// tools/build-changelog.php). Tidak bergantung License Server & jalan offline.
$localPath = __DIR__ . '/../../data/changelog.json';
if (is_file($localPath)) {
    $lj = json_decode((string) file_get_contents($localPath), true);
    if (is_array($lj) && !empty($lj['releases']) && is_array($lj['releases'])) {
        foreach ($lj['releases'] as $r) {
            $rv = (string) ($r['version'] ?? '');
            if ($rv === '') continue;
            if (version_compare($rv, $currentVersion, '<=')) $releases[] = $r;
        }
        if (!empty($releases)) {
            usort($releases, fn($a, $b) => version_compare((string) ($b['version'] ?? ''), (string) ($a['version'] ?? '')));
            $sourceLocal = true;
            $dataAt      = @filemtime($localPath) ?: null;
        }
    }
}

// ─── Fallback: feed vendor (bila file lokal belum ada / kosong) ────────────
if (!$sourceLocal) {
    $force      = isset($_GET['refresh']);
    $cacheRaw   = (string) getSetting($CACHE_KEY, '');
    $cacheAtTs  = (int) getSetting($CACHE_AT, '0');
    $cacheFresh = $cacheRaw !== '' && (time() - $cacheAtTs) < $CACHE_TTL;
    $dataAt     = $cacheAtTs > 0 ? $cacheAtTs : null;

    $decodeCache = function () use ($cacheRaw) {
        $d = $cacheRaw !== '' ? json_decode($cacheRaw, true) : [];
        return is_array($d) ? $d : [];
    };

    if ($licenseOk && ($force || !$cacheFresh)) {
        $res = fetchVendorChangelog($license, $currentVersion);
        if ($res['ok']) {
            $releases = $res['releases'];
            setSetting($CACHE_KEY, json_encode($releases, JSON_UNESCAPED_UNICODE), 'update');
            setSetting($CACHE_AT, (string) time(), 'update');
            $dataAt = time();
        } else {
            // Fail-soft: pakai cache basi bila ada.
            $fetchError = $res['message'];
            $releases   = $decodeCache();
            $fromCache  = $cacheRaw !== '';
        }
    } else {
        // Pakai cache (segar, atau lisensi belum aktif).
        $releases  = $decodeCache();
        $fromCache = $cacheRaw !== '';
    }
}
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<style>
  /* ── Changelog timeline (scoped, tak butuh regen Tailwind) ── */
  .cl-rail { position: relative; }
  .cl-item { position: relative; display: flex; gap: 14px; }
  .cl-track { position: relative; flex: 0 0 18px; }
  .cl-track::before {
    content: ''; position: absolute; left: 8px; top: 0; bottom: 0;
    width: 2px; background: rgb(229 231 235);
  }
  .dark .cl-track::before { background: rgb(31 41 55); }
  .cl-item:first-child .cl-track::before { top: 9px; }
  .cl-item:last-child  .cl-track::before { bottom: auto; height: 9px; }
  .cl-node {
    position: absolute; left: 3px; top: 3px; width: 12px; height: 12px;
    border-radius: 50%; background: rgb(203 213 225);
    box-shadow: 0 0 0 4px rgb(249 250 251); z-index: 1;
  }
  .dark .cl-node { background: rgb(55 65 81); box-shadow: 0 0 0 4px rgb(3 7 18); }
  .cl-node.is-current { background: var(--brand-color); }

  .cl-card {
    border-radius: var(--card-radius, 12px);
    border: 1px solid rgb(229 231 235);
    background: #fff;
    overflow: hidden;
  }
  .dark .cl-card { border-color: rgb(31 41 55); background: rgb(17 24 39); }
  .cl-card.is-current { border-color: color-mix(in srgb, var(--brand-color) 45%, transparent); }

  .cl-body { font-size: 14px; line-height: 1.6; color: rgb(55 65 81); }
  .dark .cl-body { color: rgb(209 213 219); }
  .cl-body p  { margin: 0 0 8px; }
  .cl-body p:last-child { margin-bottom: 0; }
  .cl-body ul { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 7px; }
  .cl-body ul + p, .cl-body p + ul { margin-top: 10px; }
  .cl-body li { position: relative; padding-left: 18px; }
  .cl-body li::before {
    content: ''; position: absolute; left: 3px; top: 8px; width: 5px; height: 5px;
    border-radius: 50%; background: var(--brand-color); opacity: .75;
  }
  .cl-muted { color: rgb(156 163 175); font-style: italic; }
  .cl-verchip {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-weight: 700; font-size: 13px; letter-spacing: -.01em;
    padding: 2px 8px; border-radius: 7px;
    background: rgb(243 244 246); color: rgb(17 24 39);
  }
  .dark .cl-verchip { background: rgb(31 41 55); color: #fff; }
  .cl-verchip.is-current { background: var(--brand-color); color: #fff; }
</style>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">

  <?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>

  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">

    <?php include __DIR__ . '/../../partials/topbar.php'; ?>

    <main class="flex-1 overflow-y-auto p-4 sm:p-5 lg:p-6">

      <div class="max-w-2xl mx-auto">

        <div class="mb-6 flex items-start justify-between gap-3">
          <div class="min-w-0">
            <div class="flex items-center gap-2">
              <a href="<?= e(APP_URL) ?>/admin/update" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition" title="Kembali ke Update Sistem">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
              </a>
              <h2 class="text-xl font-bold text-gray-900 dark:text-white tracking-tight">Changelog</h2>
            </div>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 ml-7">Riwayat pembaruan Averion — sampai versi terpasang (<span class="font-semibold text-gray-700 dark:text-gray-300">v<?= e($currentVersion) ?></span>).</p>
          </div>

          <?php if (!$sourceLocal): ?>
          <a href="<?= e(APP_URL) ?>/admin/changelog?refresh=1"
             class="shrink-0 inline-flex items-center gap-2 px-3.5 h-9 text-sm font-semibold text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800 transition"
             style="border-radius:var(--card-radius,12px)">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
            Perbarui
          </a>
          <?php endif; ?>
        </div>

        <?php if ($fetchError !== ''): ?>
        <div class="mb-4 flex items-start gap-2.5 px-4 py-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-100 dark:border-amber-800/40 text-amber-800 dark:text-amber-300 text-sm" style="border-radius:var(--card-radius,12px)">
          <svg class="w-4 h-4 mt-0.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
          <div>
            <p class="font-medium"><?= e($fetchError) ?></p>
            <?php if ($fromCache && !empty($releases)): ?>
            <p class="text-xs mt-0.5">Menampilkan versi tersimpan terakhir<?= $dataAt ? ' (' . e(date('d M Y H:i', $dataAt)) . ')' : '' ?>.</p>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <?php if (!$licenseOk && empty($releases)): ?>
        <div class="flex items-start gap-2.5 px-4 py-3.5 bg-amber-50 dark:bg-amber-900/20 border border-amber-100 dark:border-amber-800/40 text-amber-800 dark:text-amber-300 text-sm" style="border-radius:var(--card-radius,12px)">
          <svg class="w-4 h-4 mt-0.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
          <div>
            <p class="font-medium">Lisensi belum aktif</p>
            <p class="text-xs mt-0.5">Aktifkan lisensi di halaman <a href="<?= e(APP_URL) ?>/admin/license" class="underline font-medium">Lisensi</a> untuk memuat changelog.</p>
          </div>
        </div>
        <?php elseif (empty($releases)): ?>
        <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 shadow-sm py-16 text-center" style="border-radius:var(--card-radius,12px)">
          <svg class="w-10 h-10 mx-auto text-gray-200 dark:text-gray-700 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
          <p class="text-sm text-gray-400 dark:text-gray-600">Belum ada changelog untuk ditampilkan.</p>
        </div>
        <?php else: ?>

        <?php if ($fetchError === ''): ?>
        <p class="text-xs text-gray-400 dark:text-gray-600 mb-4"><?= count($releases) ?> rilis<?php if ($sourceLocal): ?> · riwayat lengkap<?php elseif ($dataAt): ?> · diperbarui <?= e(date('d M Y H:i', $dataAt)) ?><?= $fromCache ? ' (tersimpan)' : '' ?><?php endif; ?></p>
        <?php endif; ?>

        <div class="cl-rail space-y-5">
          <?php foreach ($releases as $r):
            $ver   = (string) ($r['version'] ?? '');
            if ($ver === '') continue;
            $title = changelogCleanTitle((string) ($r['title'] ?? ''), $ver);
            $bodyHtml = changelogFormatBody((string) ($r['changelog'] ?? ''), (string) ($r['title'] ?? ''), $ver);
            $pub   = $r['published_at'] ?? null;
            $isCurrent = $ver === $currentVersion;
          ?>
          <div class="cl-item">
            <div class="cl-track"><span class="cl-node <?= $isCurrent ? 'is-current' : '' ?>"></span></div>
            <div class="flex-1 min-w-0">
              <div class="cl-card <?= $isCurrent ? 'is-current' : '' ?> shadow-sm">
                <div class="px-5 py-3 border-b border-gray-100 dark:border-gray-800 flex flex-wrap items-center gap-x-2.5 gap-y-1">
                  <span class="cl-verchip <?= $isCurrent ? 'is-current' : '' ?>">v<?= e($ver) ?></span>
                  <?php if ($isCurrent): ?>
                  <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold border border-[var(--brand-color)]/40 text-[var(--brand-color)]">Versi Anda</span>
                  <?php endif; ?>
                  <?php if ($title !== ''): ?>
                  <span class="text-sm font-medium text-gray-700 dark:text-gray-200 min-w-0 truncate"><?= e($title) ?></span>
                  <?php endif; ?>
                  <?php if ($pub): $ts = strtotime((string) $pub); if ($ts): ?>
                  <span class="ml-auto text-xs text-gray-400 dark:text-gray-600 whitespace-nowrap"><?= e(date('d M Y', $ts)) ?></span>
                  <?php endif; endif; ?>
                </div>
                <div class="cl-body px-5 py-4"><?= $bodyHtml ?></div>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <?php endif; ?>

      </div>

    </main>
  </div>
</div>
<?php include __DIR__ . '/../../partials/footer.php'; ?>
</body>
</html>
