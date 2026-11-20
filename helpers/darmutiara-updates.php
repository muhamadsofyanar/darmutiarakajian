<?php

function darmutiaraUpdateRequest(string $url, ?string $token = null): array
{
    $body = '';
    $curl = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    } else {
        $headers[] = 'Cache-Control: no-cache';
    }
    curl_setopt_array($curl, [
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'Darmutiara-Islamic-Updater',
        CURLOPT_POST => $token !== null,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 65536) {
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    return [$ok !== false, $status, $body];
}

function darmutiaraLatestVersion(): string
{
    [$ok, $status, $body] = darmutiaraUpdateRequest(
        'https://raw.githubusercontent.com/muhamadsofyanar/darmutiarakajian/main/version.php?check=' . time()
    );
    if (!$ok || $status !== 200
        || !preg_match("/define\\(\\s*['\"]APP_VERSION['\"]\\s*,\\s*['\"](\\d+\\.\\d+\\.\\d+)['\"]\\s*\\)/", $body, $match)) {
        throw new RuntimeException('Versi terbaru belum bisa dibaca dari GitHub.');
    }
    return $match[1];
}

function darmutiaraDeployConfig(): ?array
{
    $url = trim((string) getenv('DARMUTIARA_DEPLOY_WEBHOOK'));
    $token = trim((string) getenv('DARMUTIARA_DEPLOY_TOKEN'));
    $parts = parse_url($url);
    if ($token === '' || preg_match('/[\r\n]/', $token) || !$parts
        || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
        || ($parts['path'] ?? '') !== '/api/v1/deploy') {
        return null;
    }
    parse_str($parts['query'] ?? '', $query);
    if (!preg_match('/\A[a-zA-Z0-9-]{8,64}\z/', is_string($query['uuid'] ?? null) ? $query['uuid'] : '')) {
        return null;
    }
    $origin = 'https://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    return [$origin . '/api/v1/deploy?' . http_build_query(['uuid' => $query['uuid'], 'force' => 'false']), $token];
}

function darmutiaraUpdateState(): array
{
    $contents = @file_get_contents(__DIR__ . '/../storage/darmutiara-deploy.json');
    return (is_string($contents) && is_array($state = json_decode($contents, true))) ? $state : [];
}

function darmutiaraQueueUpdate(string $requested): void
{
    $config = darmutiaraDeployConfig();
    if ($config === null) {
        throw new RuntimeException('Webhook dan token deploy Coolify belum disiapkan.');
    }
    $path = __DIR__ . '/../storage/darmutiara-deploy';
    $file = @fopen($path . '.lock', 'c');
    if ($file === false) {
        throw new RuntimeException('Folder storage belum dapat ditulis.');
    }
    try {
        if (!flock($file, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Permintaan update sedang diproses.');
        }
        $old = darmutiaraUpdateState();
        if (version_compare((string) ($old['version'] ?? '0'), APP_VERSION, '>')
            && ($old['status'] ?? '') !== 'rejected' && (int) ($old['at'] ?? 0) > time() - 900) {
            throw new RuntimeException('Permintaan update sebelumnya masih baru. Cek status di Coolify.');
        }
        $latest = darmutiaraLatestVersion();
        if ($requested !== $latest || !version_compare($latest, APP_VERSION, '>')) {
            throw new RuntimeException('Versi berubah atau sudah terpasang. Klik Cek Update lagi.');
        }
        $save = static function (string $status) use ($path, $latest): void {
            $value = json_encode(['version' => $latest, 'at' => time(), 'status' => $status]);
            if (@file_put_contents($path . '.tmp', $value) !== strlen($value)
                || !@rename($path . '.tmp', $path . '.json')) {
                throw new RuntimeException('Status update gagal disimpan. Cek Coolify sebelum mencoba lagi.');
            }
        };
        $save('sent');
        [$ok, $status] = darmutiaraUpdateRequest($config[0], $config[1]);
        if (!$ok || $status >= 500) {
            throw new RuntimeException('Respons Coolify belum bisa dipastikan. Cek Deployments sebelum mencoba ulang.');
        }
        if ($status < 200 || $status >= 300) {
            $save('rejected');
            throw new RuntimeException('Coolify menolak permintaan. Periksa webhook, token, dan izin Deploy.');
        }
        $save('queued');
    } finally {
        fclose($file);
    }
}
