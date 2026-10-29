<?php
// 電子簽章：簽署內容＋手寫簽名（壓在錄影上）＋錄影聲明＋完成截圖。
// 每筆存成一個資料夾（meta.json＋signature.png.enc＋snapshot.jpg.enc＋video.*.enc），只放測試資料。
// 存證方式：簽名圖、完成截圖與錄影各算 SHA-256（對原始內容），再把整份 meta（含這些雜湊）正規化後算一個「存證碼」，
// 事後檔案或內容動過任何一處，重算就對不上。
// 合規（依電子簽章法第 4、5、8 條與個資法第 8 條）：
//   1. 先告知並取得同意（不同意改紙本）
//   2. 保存足以確認身分與完整性的資料：時間（伺服器＋手機）、IP、裝置、位置、存證碼
//   3. 每日把當天的存證碼清單放進外寄佇列，由資訊部寄到主機以外的信箱留存；管理者改了主機上的資料也對不上外部那份
//   4. 簽名、截圖與錄影加密存放
require_once __DIR__ . '/app.php';

const ES_VIDEO_MAX = 10 * 1024 * 1024;   // 與 php-fpm upload_max_filesize 一致；前端以約 0.9 Mbps 錄 45 秒約 5 MB
const ES_SIG_MAX = 600 * 1024;
const ES_SNAP_MAX = 2 * 1024 * 1024;
const ES_VIDEO_TYPES = ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov'];
const ES_KEEP_DAYS = 30;   // 展示站：告知的保存期限要真的做到；正式上線的保存期限依「電子簽章作業辦法」（十年）

function rec_dir(): string { return data_dir() . '/records'; }

function es_valid_id(mixed $id): bool {
    return is_string($id) && preg_match('/\A[0-9a-f]{16}\z/', $id) === 1;
}

// 正規化後的雜湊：鍵排序、不跳脫中文，確保同一份內容永遠算出同一個值
function es_seal(array $meta): string {
    unset($meta['seal']);
    ksort($meta);
    return hash('sha256', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function es_enc(string $plain): string {
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return $nonce . sodium_crypto_secretbox($plain, $nonce, secret_key());
}

function es_dec(string $blob): ?string {
    if (strlen($blob) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
    $p = sodium_crypto_secretbox_open(substr($blob, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($blob, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), secret_key());
    return $p === false ? null : $p;
}

function es_read(array $m, string $k): ?string {
    $name = match ($k) { 'sig' => 'signature.png', 'snap' => 'snapshot.jpg', default => $m['video_file'] };
    $p = rec_dir() . '/' . $m['id'] . "/$name.enc";
    return is_file($p) ? es_dec((string)file_get_contents($p)) : null;
}

function es_client_ip(): string {
    // 經 Cloudflare 進來時，真實來源在 CF-Connecting-IP；直接連線才用 REMOTE_ADDR
    return substr((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

// 位置：不允許也照常送出，只記下原因（熊哥 10-29 選 B）
function es_gps(array $f): array {
    $st = in_array($f['gps_status'] ?? '', ['ok', 'denied', 'unavailable', 'timeout', 'unsupported'], true) ? $f['gps_status'] : 'none';
    if ($st !== 'ok') return ['status' => $st];
    $lat = filter_var($f['gps_lat'] ?? '', FILTER_VALIDATE_FLOAT);
    $lng = filter_var($f['gps_lng'] ?? '', FILTER_VALIDATE_FLOAT);
    $acc = filter_var($f['gps_acc'] ?? '', FILTER_VALIDATE_FLOAT);
    if ($lat === false || $lng === false || abs($lat) > 90 || abs($lng) > 180) return ['status' => 'invalid'];
    return ['status' => 'ok', 'lat' => round($lat, 6), 'lng' => round($lng, 6), 'acc_m' => $acc === false ? null : (int)round(max(0, $acc))];
}

// 臉部偵測（手機端 MediaPipe 判斷畫面裡有沒有臉；不是辨識是誰）
function es_face(array $f): array {
    $st = in_array($f['face_status'] ?? '', ['ok', 'unavailable'], true) ? $f['face_status'] : 'none';
    if ($st !== 'ok') return ['status' => $st];
    $n = max(0, min(10000, (int)($f['face_checks'] ?? 0)));
    $hit = max(0, min($n, (int)($f['face_hits'] ?? 0)));
    return ['status' => 'ok', 'checks' => $n, 'hits' => $hit, 'ratio' => $n ? round($hit / $n, 2) : 0.0];
}

// 回傳 [id, null] 或 [null, 錯誤訊息]。$videoTmp 是上傳暫存檔路徑。
function es_save(array $f, string $sigPng, string $videoTmp, array $u, string $snapJpg = ''): array {
    $who = trim((string)($f['signer'] ?? ''));
    $content = trim((string)($f['content'] ?? ''));
    if ($who === '' || mb_strlen($who) > 40) return [null, '請填寫簽署人代稱（40 字以內，展示請勿填真實姓名）。'];
    if ($content === '' || mb_strlen($content) > 200) return [null, '請填寫簽署內容（200 字以內）。'];
    if (($f['consent'] ?? '') !== '1') return [null, '需勾選同意錄影，才能送出。'];
    if (strlen($sigPng) < 100 || strlen($sigPng) > ES_SIG_MAX || substr($sigPng, 0, 8) !== hex2bin('89504e470d0a1a0a')) return [null, '沒有收到簽名，請簽名後再送出。'];
    if ($snapJpg !== '' && (strlen($snapJpg) > ES_SNAP_MAX || substr($snapJpg, 0, 3) !== hex2bin('ffd8ff'))) return [null, '完成截圖格式不對或太大，請再送一次。'];
    if (!is_file($videoTmp) || filesize($videoTmp) < 1000) return [null, '沒有收到錄影，請重新錄影簽名。'];
    if (filesize($videoTmp) > ES_VIDEO_MAX) return [null, '錄影檔太大（上限 10 MB），請錄短一點。'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($videoTmp);
    if (!isset(ES_VIDEO_TYPES[$mime])) return [null, '錄影格式不支援（' . $mime . '）。'];
    $video = (string)file_get_contents($videoTmp);

    $id = bin2hex(random_bytes(8));
    $dir = rec_dir() . "/$id";
    if (!is_dir(rec_dir()) && !mkdir(rec_dir(), 0770, true)) return [null, '存檔失敗（目錄）。'];
    if (!mkdir($dir, 0770)) return [null, '存檔失敗（目錄）。'];
    $vname = 'video.' . ES_VIDEO_TYPES[$mime];
    if (file_put_contents("$dir/$vname.enc", es_enc($video)) === false || file_put_contents("$dir/signature.png.enc", es_enc($sigPng)) === false) return [null, '存檔失敗。'];
    if ($snapJpg !== '' && file_put_contents("$dir/snapshot.jpg.enc", es_enc($snapJpg)) === false) return [null, '存檔失敗。'];
    if (PHP_SAPI !== 'cli') @unlink($videoTmp);

    $meta = [
        'id' => $id,
        'created_at' => date('c'),   // 伺服器收到的時間（以主機時鐘為準）
        'signed_at_client' => substr(preg_replace('/[^0-9\/: ]/', '', (string)($f['signed_at_client'] ?? '')), 0, 19),   // 手機上按送出那一刻（蓋在簽名圖上的時間）
        'signer' => $who,
        'content' => $content,
        'statement' => trim((string)($f['statement'] ?? '')),
        'operator' => $u['email'],   // 操作的同工（Cloudflare Access 登入的 Email）
        'consent' => true,
        'composite' => ($f['composite'] ?? '') === '1',   // 簽名是否合成進錄影畫面
        'signature_sha256' => hash('sha256', $sigPng),
        'snapshot_sha256' => $snapJpg !== '' ? hash('sha256', $snapJpg) : null,
        'video_file' => $vname,
        'video_mime' => $mime,
        'video_bytes' => strlen($video),
        'video_sha256' => hash('sha256', $video),
        'ip' => es_client_ip(),
        'gps' => es_gps($f),
        'face' => es_face($f),
        'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
    ];
    $meta['seal'] = es_seal($meta);
    file_put_contents("$dir/meta.json", json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    audit('create', ['id' => $id]);
    return [$id, null];
}

function es_load(string $id): ?array {
    if (!es_valid_id($id)) return null;
    $p = rec_dir() . "/$id/meta.json";
    if (!is_file($p)) return null;
    $m = json_decode((string)file_get_contents($p), true);
    return is_array($m) ? $m : null;
}

// 重新驗證：解密後的內容雜湊與存證碼是否仍吻合
function es_verify(array $m): array {
    $sig = es_read($m, 'sig');
    $vid = es_read($m, 'video');
    return [
        'signature' => $sig !== null && hash('sha256', $sig) === $m['signature_sha256'],
        'video' => $vid !== null && hash('sha256', $vid) === $m['video_sha256'],
        'snapshot' => empty($m['snapshot_sha256']) ? null : (($s = es_read($m, 'snap')) !== null && hash('sha256', $s) === $m['snapshot_sha256']),
        'seal' => es_seal($m) === ($m['seal'] ?? ''),
    ];
}

function es_purge_expired(): int {
    $n = 0;
    foreach (glob(rec_dir() . '/*/meta.json') ?: [] as $p) {
        $m = json_decode((string)file_get_contents($p), true);
        if (!is_array($m) || strtotime($m['created_at'] ?? 'now') > time() - ES_KEEP_DAYS * 86400) continue;
        $dir = dirname($p);
        foreach (glob("$dir/*") ?: [] as $f) unlink($f);
        rmdir($dir);
        audit('purge', ['id' => $m['id'] ?? '']);
        $n++;
    }
    return $n;
}

function es_list(): array {
    es_purge_expired();
    $out = [];
    foreach (glob(rec_dir() . '/*/meta.json') ?: [] as $p) {
        $m = json_decode((string)file_get_contents($p), true);
        if (is_array($m)) $out[] = $m;
    }
    usort($out, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
    return $out;
}

// 每日存證碼清單：某一天建立的每筆「編號＋時間＋存證碼」與整份清單的雜湊，寫進外寄佇列（outbox），
// 由資訊部的寄送程式取走寄到主機以外的信箱。只放編號與雜湊，不放姓名與內容。同一天重跑不重複產生。回傳筆數。
function es_digest(string $day): int {
    $rows = array_values(array_filter(es_list(), fn($m) => date('Y-m-d', strtotime($m['created_at'])) === $day));
    if (!$rows) return 0;
    $out = data_dir() . '/outbox';
    if (!is_dir($out)) mkdir($out, 0770, true);
    $f = "$out/digest-$day.txt";
    if (is_file($f) || is_file(data_dir() . "/outbox-sent/digest-$day.txt")) return count($rows);
    usort($rows, fn($a, $b) => strcmp($a['created_at'], $b['created_at']));
    $lines = array_map(fn($m) => $m['id'] . '  ' . date('Y-m-d H:i:s', strtotime($m['created_at'])) . '  ' . $m['seal'], $rows);
    $body = "【存證碼】電子簽章展示 {$day}（" . count($rows) . " 筆）\n\n" . implode("\n", $lines)
          . "\n\n清單雜湊（SHA-256）：" . hash('sha256', implode("\n", $lines))
          . "\n\n用途：日後核對主機上的紀錄是否被改過。請保留本信，勿刪除。\n";
    file_put_contents($f, $body);
    return count($rows);
}
