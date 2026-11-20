<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();

$pageTitle   = 'Aktivasi Lisensi';
$topbarTitle = 'Aktivasi Lisensi';

$license = null;
try {
    $license = getDB()->query("SELECT * FROM license_info ORDER BY id ASC LIMIT 1")->fetch() ?: null;
} catch (Exception $e) {
    error_log('license-activate page error: ' . $e->getMessage());
}

$pendingReason = trim((string) ($license['activation_pending_reason'] ?? ''));
generateCSRF();
?>
<?php include __DIR__ . '/../../partials/head.php'; ?>
<body class="bg-gray-50 dark:bg-gray-950 antialiased">
<div class="flex h-screen overflow-hidden">

  <?php include __DIR__ . '/../../partials/sidebar-admin.php'; ?>

  <div class="flex-1 flex flex-col overflow-hidden lg:ml-60">

    <?php include __DIR__ . '/../../partials/topbar.php'; ?>

    <main class="flex-1 overflow-y-auto p-5 lg:p-6">

      <?php include __DIR__ . '/../../partials/flash-message.php'; ?>

      <div class="max-w-lg mx-auto mt-6">

        <!-- ─── Hero ─────────────────────────────────────────────── -->
        <div class="text-center mb-6">
          <div class="relative w-16 h-16 mx-auto mb-4">
            <span class="absolute inset-0 rounded-2xl blur-xl opacity-30" style="background:var(--brand-color);"></span>
            <span class="relative w-16 h-16 rounded-2xl flex items-center justify-center ring-1"
                  style="background:color-mix(in srgb, var(--brand-color) 12%, transparent); color:var(--brand-color); --tw-ring-color:color-mix(in srgb, var(--brand-color) 28%, transparent);">
              <svg class="w-7 h-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                <path d="M15 7a2 2 0 0 1 2 2m4 0a6 6 0 0 1-7.743 5.743L11 17H9v2H7v2H4a1 1 0 0 1-1-1v-2.586a1 1 0 0 1 .293-.707l5.964-5.964A6 6 0 1 1 21 9z"/>
              </svg>
            </span>
          </div>
          <h2 class="text-2xl font-bold text-gray-900 dark:text-white tracking-tight">Aktivasi Lisensi Diperlukan</h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-1.5">Aktifkan lisensi untuk membuka akses penuh ke admin panel.</p>
        </div>

        <?php if ($pendingReason !== ''): ?>
        <div class="mb-4 flex items-start gap-2.5 px-4 py-3 rounded-xl bg-amber-50 dark:bg-amber-900/20 border border-amber-100 dark:border-amber-800/40 text-amber-800 dark:text-amber-300 text-sm">
          <svg class="w-4 h-4 mt-0.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <div>
            <p class="font-medium">Aktivasi sebelumnya belum tuntas</p>
            <p class="text-xs mt-0.5"><?= e($pendingReason) ?></p>
          </div>
        </div>
        <?php endif; ?>

        <!-- ─── Kartu aktivasi (konsisten dgn /admin/license) ───── -->
        <div class="bg-white dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
          <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center gap-2.5" style="background:color-mix(in srgb, var(--brand-color) 5%, transparent);">
            <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg shrink-0" style="background:color-mix(in srgb, var(--brand-color) 14%, transparent); color:var(--brand-color);">
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </span>
            <h3 class="font-semibold text-gray-900 dark:text-white text-sm">Masukkan License Key</h3>
          </div>
          <form method="POST" action="<?= e(APP_URL) ?>/actions/admin/activate-license">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <div class="p-5 space-y-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">License Key</label>
                <div class="relative">
                  <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-gray-300 dark:text-gray-600">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 7a2 2 0 0 1 2 2m4 0a6 6 0 0 1-7.743 5.743L11 17H9v2H7v2H4a1 1 0 0 1-1-1v-2.586a1 1 0 0 1 .293-.707l5.964-5.964A6 6 0 1 1 21 9z"/></svg>
                  </span>
                  <input type="text" name="license_key" required placeholder="DRM-…" maxlength="47"
                         value="<?= e($license['license_key'] ?? '') ?>"
                         class="w-full pl-10 pr-3.5 py-2.5 rounded-lg text-sm tracking-wider border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white font-mono placeholder-gray-300 dark:placeholder-gray-600 focus:ring-2 focus:ring-[var(--brand-color)] focus:border-transparent outline-none transition"
                         autocomplete="off" spellcheck="false">
                </div>
                <p class="text-xs text-gray-400 dark:text-gray-600 mt-1.5">License key dikirim ke email saat pembelian.</p>
              </div>
            </div>
            <div class="px-5 py-3.5 border-t border-gray-100 dark:border-gray-800 flex justify-end">
              <button type="submit" data-loading data-loading-text="Mengaktifkan..."
                      class="inline-flex items-center gap-2 px-5 py-2 rounded-lg text-sm font-semibold text-white shadow-sm hover:opacity-90 transition"
                      style="background-color: var(--brand-color);">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 12 2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
                Aktivasi Sekarang
              </button>
            </div>
          </form>
        </div>

        <!-- ─── Badge premium: Created by (hardcoded di source) ──── -->
        <div class="flex justify-center mt-7">
          <a href="https://adysheva.com/" target="_blank" rel="noopener noreferrer"
             class="group inline-flex items-center gap-2 pl-2.5 pr-3.5 py-1.5 rounded-full text-xs border shadow-sm transition hover:-translate-y-0.5"
             style="border-color:color-mix(in srgb, var(--brand-color) 22%, transparent); background:color-mix(in srgb, var(--brand-color) 6%, transparent);">
            <span class="inline-flex items-center justify-center w-5 h-5 rounded-full" style="background:color-mix(in srgb, var(--brand-color) 16%, transparent); color:var(--brand-color);">
              <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="currentColor">
                <path d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.716 5.281a1 1 0 0 0 .95.69h5.552c.969 0 1.371 1.24.588 1.81l-4.492 3.263a1 1 0 0 0-.363 1.118l1.716 5.28c.3.922-.755 1.688-1.539 1.118l-4.491-3.263a1 1 0 0 0-1.176 0l-4.491 3.263c-.784.57-1.838-.196-1.539-1.117l1.716-5.281a1 1 0 0 0-.363-1.118L2.51 10.708c-.783-.57-.38-1.81.588-1.81h5.552a1 1 0 0 0 .95-.69l1.716-5.281z"/>
              </svg>
            </span>
            <span class="text-gray-500 dark:text-gray-400">Created by</span>
            <span class="font-bold group-hover:opacity-80 transition" style="color:var(--brand-color);">Ady Sheva</span>
          </a>
        </div>

      </div>
    </main>
  </div>
</div>

<?php include __DIR__ . '/../../partials/footer.php'; ?>
