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
echo json_encode(['success' => false, 'message' => 'Pembaruan Darmutiara Islamic dipasang melalui GitHub.']);
