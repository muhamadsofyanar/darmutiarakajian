<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

$pageTitle   = 'Update Sistem';
$topbarTitle = 'Update Sistem';

$license = null;
$history = [];
try {
    $pdo = getDB();
    $license = $pdo->query("SELECT * FROM license_info ORDER BY id ASC LIMIT 1")->fetch() ?: null;
    $history = $pdo->query("SELECT * FROM update_history ORDER BY applied_at DESC LIMIT 10")->fetchAll();
} catch (Exception $e) {
    error_log('update page error: ' . $e->getMessage());
}

$currentVersion = APP_VERSION;
$csrf = generateCSRF();

$statusBadge = [
    'success' => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
    'failed'  => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
    'partial' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400',
];
$statusLabel = ['success' => 'Sukses', 'failed' => 'Gagal', 'partial' => 'Partial'];

// Update sukses terakhir → info "terakhir diperbarui" di kartu hero.
$lastSuccess = null;
foreach ($history as $h) {
    if (($h['status'] ?? '') === 'success') { $lastSuccess = $h; break; }
}

// Banner peringatan HANYA bila update TERAKHIR berstatus partial. Partial yang
// sudah disusul update sukses berikutnya = skema sudah tersinkron (migrasi
// self-contained) → jangan tampilkan banner usang. ($history DESC → [0] terbaru.)
$partialUpdate = (!empty($history) && ($history[0]['status'] ?? '') === 'partial')
    ? $history[0]
    : null;
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">

  <?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>

  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">

    <?php include __DIR__ . '/../../partials/topbar.php'; ?>

    <main class="flex-1 overflow-y-auto p-4 sm:p-5 lg:p-6">

      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

      <div class="mb-5">
        <h2 class="text-xl font-bold text-gray-900 dark:text-white">Update Sistem</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Cek dan terapkan pembaruan Averion dari server vendor.</p>
      </div>

      <?php if ($partialUpdate): ?>
      <div class="max-w-2xl mb-4 flex items-start gap-2.5 px-4 py-3.5 rounded-xl bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800/40 text-yellow-800 dark:text-yellow-300 text-sm">
        <svg class="w-4 h-4 mt-0.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.008v.008H12v-.008z"/></svg>
        <div>
          <p class="font-medium">Update v<?= e($partialUpdate['to_version']) ?> selesai sebagian (partial)</p>
          <p class="mt-0.5 text-yellow-700 dark:text-yellow-400">File aplikasi sudah terpasang, tetapi <strong>migration database gagal</strong> sehingga skema DB mungkin belum sinkron. Jalankan migration secara manual lalu verifikasi aplikasi.</p>
          <?php if (!empty($partialUpdate['notes'])): ?>
          <p class="mt-1.5 font-mono text-xs bg-yellow-100/60 dark:bg-yellow-900/30 rounded px-2 py-1 break-all"><?= e($partialUpdate['notes']) ?></p>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if (!$license || $license['status'] !== 'active'): ?>
      <div class="max-w-2xl flex items-start gap-2.5 px-4 py-3.5 rounded-xl bg-amber-50 dark:bg-amber-900/20 border border-amber-100 dark:border-amber-800/40 text-amber-800 dark:text-amber-300 text-sm">
        <svg class="w-4 h-4 mt-0.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
        <div>
          <p class="font-medium">Lisensi belum aktif</p>
          <p class="text-xs mt-0.5">Aktifkan lisensi di halaman <a href="<?= e(APP_URL) ?>/admin/license" class="underline font-medium">Lisensi</a> sebelum mengecek update.</p>
        </div>
      </div>
      <?php else: ?>

      <div class="max-w-2xl space-y-5">

        <!-- ── Card 1: Versi Saat Ini (hero) ── -->
        <div class="relative rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden bg-white dark:bg-gray-900">
          <!-- Aura brand lembut, murni dekoratif -->
          <div aria-hidden="true" class="pointer-events-none absolute -top-24 -right-20 w-72 h-72 rounded-full blur-3xl opacity-60 dark:opacity-30"
               style="background:radial-gradient(circle, color-mix(in srgb, var(--brand-color) 18%, transparent), transparent 70%);"></div>

          <div class="relative p-5 sm:p-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
              <div class="flex items-center gap-4 min-w-0">
                <div class="w-12 h-12 rounded-2xl flex items-center justify-center shrink-0 shadow-sm"
                     style="background:linear-gradient(135deg, var(--brand-color), color-mix(in srgb, var(--brand-color) 60%, #000 10%));">
                  <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>
                  </svg>
                </div>
                <div class="min-w-0">
                  <p class="text-[11px] font-semibold uppercase tracking-widest text-gray-400 dark:text-gray-500">Versi Terpasang</p>
                  <div class="flex items-baseline gap-2 flex-wrap">
                    <span class="text-2xl sm:text-3xl font-extrabold font-mono tracking-tight text-gray-900 dark:text-white">v<?= e($currentVersion) ?></span>
                    <span id="version-state" class="inline-flex items-center gap-1 text-[11px] font-semibold text-gray-400 dark:text-gray-500">
                      <span class="w-1.5 h-1.5 rounded-full bg-gray-300 dark:bg-gray-600"></span> belum dicek
                    </span>
                  </div>
                  <?php if ($lastSuccess): ?>
                  <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Terakhir diperbarui <?= e(date('d M Y H:i', strtotime($lastSuccess['applied_at']))) ?> (v<?= e($lastSuccess['from_version']) ?> → v<?= e($lastSuccess['to_version']) ?>)</p>
                  <?php endif; ?>
                </div>
              </div>

              <button id="btn-check-update" type="button"
                      class="group inline-flex items-center gap-2 whitespace-nowrap px-5 h-11 rounded-xl text-sm font-semibold text-white shadow-sm hover:opacity-90 transition disabled:opacity-60 disabled:cursor-wait"
                      style="background:var(--brand-color)">
                <svg id="check-icon" class="w-4 h-4 transition-transform group-hover:rotate-180 duration-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                <span id="check-label">Cek Update</span>
              </button>
            </div>

            <div id="update-result" class="mt-0"></div>
          </div>
        </div>

        <!-- ── Card 2: Proses Update ── -->
        <div id="update-process-card" class="hidden bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
          <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center gap-2.5">
            <span class="relative flex w-2.5 h-2.5">
              <span class="animate-ping absolute inline-flex h-full w-full rounded-full opacity-60" style="background:var(--brand-color)"></span>
              <span class="relative inline-flex rounded-full w-2.5 h-2.5" style="background:var(--brand-color)"></span>
            </span>
            <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Proses Update Berjalan</h3>
          </div>
          <div class="p-5 space-y-5">

            <!-- Progress steps + garis konektor -->
            <div class="relative px-1">
              <div class="absolute top-[14px] left-8 right-8 h-0.5 bg-gray-100 dark:bg-gray-800"></div>
              <div id="update-steps" class="relative flex items-start justify-between text-[11px] gap-1">
                <?php
                $steps = ['Cek', 'Download', 'Verifikasi', 'Backup', 'Apply', 'Selesai'];
                foreach ($steps as $i => $s):
                ?>
                <div class="flex-1 flex flex-col items-center gap-1.5" data-step="<?= $i ?>">
                  <div class="step-dot w-7 h-7 rounded-full flex items-center justify-center font-bold border-2 border-gray-200 dark:border-gray-700 text-gray-400 bg-white dark:bg-gray-900 transition-colors duration-300"><?= $i + 1 ?></div>
                  <span class="text-gray-400 dark:text-gray-500 text-center leading-tight font-medium"><?= e($s) ?></span>
                </div>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="flex items-start gap-2.5 px-3.5 py-3 rounded-xl bg-red-50 dark:bg-red-900/20 border border-red-100 dark:border-red-800/40 text-red-700 dark:text-red-400 text-xs">
              <svg class="w-4 h-4 mt-px shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
              Update akan menerapkan file baru. Pastikan tidak ada transaksi berjalan saat proses berlangsung.
            </div>

            <!-- Log output — gaya terminal -->
            <div id="update-terminal" class="hidden rounded-xl overflow-hidden border border-gray-800 shadow-inner">
              <div class="flex items-center gap-1.5 px-3.5 py-2 bg-gray-800 dark:bg-gray-900 border-b border-gray-700/60">
                <span class="w-2.5 h-2.5 rounded-full bg-red-400"></span>
                <span class="w-2.5 h-2.5 rounded-full bg-yellow-400"></span>
                <span class="w-2.5 h-2.5 rounded-full bg-green-400"></span>
                <span class="ml-2 text-[11px] font-mono text-gray-400">averion-update.log</span>
              </div>
              <div id="update-log" class="font-mono text-xs leading-relaxed bg-gray-900 dark:bg-black text-green-300 p-4 max-h-72 overflow-y-auto"></div>
            </div>
          </div>
        </div>

        <!-- ── Card 3: Histori Update ── -->
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
          <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between gap-3">
            <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Histori Update</h3>
            <div class="flex items-center gap-3">
              <?php if (!empty($history)): ?>
              <span class="text-xs text-gray-400 dark:text-gray-600"><?= count($history) ?> terakhir</span>
              <?php endif; ?>
              <a href="<?= e(APP_URL) ?>/admin/changelog"
                 class="inline-flex items-center gap-1.5 text-xs font-semibold text-[var(--brand-color)] hover:opacity-80 transition">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                Lihat Changelog
              </a>
            </div>
          </div>
          <?php if (empty($history)): ?>
          <div class="py-12 text-center">
            <svg class="w-10 h-10 mx-auto text-gray-200 dark:text-gray-700 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            <p class="text-sm text-gray-400 dark:text-gray-600">Belum ada histori update.</p>
          </div>
          <?php else: ?>
          <div class="divide-y divide-gray-100 dark:divide-gray-800">
            <?php foreach ($history as $h): ?>
            <?php $ok = ($h['status'] ?? '') === 'success'; ?>
            <div class="flex items-center gap-3.5 px-4 sm:px-5 py-3.5 hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors">
              <div class="w-8 h-8 rounded-full flex items-center justify-center shrink-0
                          <?= $ok ? 'bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400'
                                  : (($h['status'] ?? '') === 'partial'
                                      ? 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-600 dark:text-yellow-400'
                                      : 'bg-red-100 dark:bg-red-900/30 text-red-500 dark:text-red-400') ?>">
                <?php if ($ok): ?>
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                <?php elseif (($h['status'] ?? '') === 'partial'): ?>
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>
                <?php else: ?>
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                <?php endif; ?>
              </div>
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-900 dark:text-white">
                  <span class="font-mono text-gray-400 dark:text-gray-500">v<?= e($h['from_version']) ?></span>
                  <svg class="inline w-3.5 h-3.5 mx-0.5 text-gray-300 dark:text-gray-600 -mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                  <span class="font-mono font-bold">v<?= e($h['to_version']) ?></span>
                </p>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5"><?= e(date('d M Y H:i', strtotime($h['applied_at']))) ?></p>
              </div>
              <span class="shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium whitespace-nowrap <?= $statusBadge[$h['status']] ?? '' ?>"><?= e($statusLabel[$h['status']] ?? ucfirst($h['status'])) ?></span>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>

      </div>
      <?php endif; ?>

    </main>
  </div>
</div>

<script>
(function () {
  var APP_URL  = <?= json_encode(rtrim(APP_URL, '/')) ?>;
  var CSRF     = <?= json_encode($csrf) ?>;
  var checkBtn = document.getElementById('btn-check-update');
  if (!checkBtn) return;

  var resultArea   = document.getElementById('update-result');
  var checkIcon    = document.getElementById('check-icon');
  var checkLabel   = document.getElementById('check-label');
  var versionState = document.getElementById('version-state');

  function setVersionState(kind, text) {
    // Indikator kecil di samping nomor versi: netral / hijau (terbaru) / biru (ada update).
    var dot = kind === 'ok' ? 'bg-green-500' : kind === 'update' ? 'bg-blue-500' : 'bg-gray-300 dark:bg-gray-600';
    var col = kind === 'ok' ? 'text-green-600 dark:text-green-400' : kind === 'update' ? 'text-blue-600 dark:text-blue-400' : 'text-gray-400 dark:text-gray-500';
    versionState.className = 'inline-flex items-center gap-1 text-[11px] font-semibold ' + col;
    versionState.innerHTML = '<span class="w-1.5 h-1.5 rounded-full ' + dot + '"></span> ' + text;
  }

  checkBtn.addEventListener('click', function () {
    checkBtn.disabled = true;
    checkIcon.classList.add('animate-spin');
    checkLabel.textContent = 'Memeriksa…';
    resultArea.innerHTML = '';

    fetch(APP_URL + '/actions/admin/check-update?force=1', {
      method: 'GET',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data.has_update) {
        setVersionState('update', 'update tersedia');
        renderUpdateAvailable(data);
      } else if (data.success === false) {
        setVersionState('', 'gagal dicek');
        resultArea.innerHTML = '<div class="mt-4 flex items-start gap-2.5 px-3.5 py-3 rounded-xl bg-red-50 dark:bg-red-900/20 border border-red-100 dark:border-red-800/40 text-sm text-red-600 dark:text-red-400">' +
          '<svg class="w-4 h-4 mt-px shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>' +
          escapeHtml(data.message || 'Gagal mengecek update.') + '</div>';
      } else {
        setVersionState('ok', 'versi terbaru');
        resultArea.innerHTML = '<div class="mt-4 flex items-center gap-2.5 px-3.5 py-3 rounded-xl bg-green-50 dark:bg-green-900/20 border border-green-100 dark:border-green-800/40 text-sm font-medium text-green-700 dark:text-green-400">' +
          '<svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>' +
          'Kamu sudah menggunakan versi terbaru.</div>';
      }
    })
    .catch(function () {
      setVersionState('', 'gagal dicek');
      resultArea.innerHTML = '<p class="mt-4 text-red-500 text-sm">Gagal menghubungi server.</p>';
    })
    .finally(function () {
      checkBtn.disabled = false;
      checkIcon.classList.remove('animate-spin');
      checkLabel.textContent = 'Cek Update';
    });
  });

  function renderUpdateAvailable(data) {
    var forcedBadge = data.is_forced
      ? '<span class="shrink-0 inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400 whitespace-nowrap">UPDATE WAJIB</span>' : '';
    resultArea.innerHTML =
      '<div class="mt-4 rounded-xl overflow-hidden border border-blue-200/70 dark:border-blue-800/40">' +
        '<div class="p-4 sm:p-5 bg-gradient-to-br from-blue-50 to-indigo-50/60 dark:from-blue-900/20 dark:to-indigo-900/10">' +
          '<div class="flex flex-wrap justify-between items-start gap-3">' +
            '<div class="flex items-center gap-3 min-w-0">' +
              '<div class="w-10 h-10 rounded-xl bg-blue-100 dark:bg-blue-900/40 flex items-center justify-center shrink-0">' +
                '<svg class="w-5 h-5 text-blue-600 dark:text-blue-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>' +
              '</div>' +
              '<div class="min-w-0"><p class="font-bold text-blue-900 dark:text-blue-200 text-base">Update tersedia: <span class="font-mono">v' + escapeHtml(data.latest_version) + '</span></p>' +
              '<p class="text-xs text-blue-600 dark:text-blue-400 mt-0.5">' + escapeHtml(data.title || '') + '</p></div>' +
            '</div>' +
            forcedBadge +
          '</div>' +
          '<details class="mt-3 group"><summary class="inline-flex items-center gap-1 text-xs text-blue-700 dark:text-blue-400 cursor-pointer font-semibold select-none">' +
            '<svg class="w-3.5 h-3.5 transition-transform group-open:rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>' +
            'Lihat Changelog</summary>' +
            '<pre class="text-xs mt-2.5 whitespace-pre-wrap text-gray-700 dark:text-gray-300 font-mono bg-white/70 dark:bg-gray-900/40 rounded-lg p-3 border border-blue-100 dark:border-blue-900/40">' + escapeHtml(data.changelog || '') + '</pre>' +
          '</details>' +
          '<button id="btn-start-update" class="mt-4 inline-flex items-center gap-2 whitespace-nowrap px-5 h-11 rounded-xl text-sm font-bold text-white shadow-sm hover:opacity-90 transition disabled:opacity-60" style="background:var(--brand-color)">' +
            '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>' +
            'Update ke v' + escapeHtml(data.latest_version) + '</button>' +
        '</div>' +
      '</div>';
    document.getElementById('btn-start-update').addEventListener('click', function () {
      startUpdate(data.download_token);
    });
  }

  function setStep(idx, state) {
    var el = document.querySelector('#update-steps [data-step="' + idx + '"] .step-dot');
    if (!el) return;
    el.className = 'step-dot w-7 h-7 rounded-full flex items-center justify-center font-bold border-2 bg-white dark:bg-gray-900 transition-colors duration-300 ' +
      (state === 'done'
        ? 'border-green-500 !bg-green-500 text-white'
        : state === 'active'
          ? 'border-[var(--brand-color)] text-[var(--brand-color)] animate-pulse'
          : 'border-gray-200 dark:border-gray-700 text-gray-400');
    // Dot selesai menampilkan ceklis, bukan angka.
    el.innerHTML = state === 'done'
      ? '<svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>'
      : String(idx + 1);
  }

  function startUpdate(downloadToken) {
    showConfirmModal({
      title: 'Mulai Update?',
      message: 'Proses update akan berjalan & tidak bisa dibatalkan di tengah jalan.',
      confirmText: 'Ya, Mulai Update',
      variant: 'warning'
    }).then(function (ok) { if (ok) _runUpdate(downloadToken); });
  }

  function _runUpdate(downloadToken) {
    document.getElementById('update-process-card').classList.remove('hidden');
    document.getElementById('update-terminal').classList.remove('hidden');
    var logArea = document.getElementById('update-log');
    logArea.innerHTML = '';
    for (var i = 0; i < 6; i++) setStep(i, 'pending');
    setStep(0, 'active');

    var startBtn = document.getElementById('btn-start-update');
    if (startBtn) startBtn.disabled = true;

    var body = new URLSearchParams();
    body.append('download_token', downloadToken);
    body.append('csrf_token', CSRF);

    fetch(APP_URL + '/actions/admin/apply-update', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: body
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      (data.messages || []).forEach(function (msg) {
        var line = document.createElement('p');
        line.className = msg.indexOf('ERROR') !== -1 ? 'text-red-400'
                       : msg.indexOf('WARNING') !== -1 ? 'text-yellow-400'
                       : 'text-green-300';
        line.textContent = '> ' + msg;
        logArea.appendChild(line);
        logArea.scrollTop = logArea.scrollHeight;
      });
      if (data.success) {
        for (var i = 0; i < 6; i++) setStep(i, 'done');
        var done = document.createElement('p');
        done.className = 'text-green-400 font-bold mt-1';
        done.textContent = '> Update selesai — memuat ulang halaman…';
        logArea.appendChild(done);
        logArea.scrollTop = logArea.scrollHeight;
        setTimeout(function () { window.location.reload(); }, 3000);
      } else {
        var line = document.createElement('p');
        line.className = 'text-red-400 font-bold mt-1';
        line.textContent = '> ' + (data.message || 'Update gagal.');
        logArea.appendChild(line);
      }
    })
    .catch(function () {
      var line = document.createElement('p');
      line.className = 'text-red-400';
      line.textContent = '> ERROR: Gagal menghubungi server saat update.';
      logArea.appendChild(line);
    });
  }

  function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
})();
</script>

<?php include __DIR__ . '/../../partials/footer.php'; ?>
