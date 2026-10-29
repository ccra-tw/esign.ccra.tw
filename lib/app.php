<?php
// 電子簽章展示站（esign.ccra.tw）共用函式。
// 熊哥 2026-10-29：「電子簽章 先獨立 不算在急難救助系統 就是單純電子簽章展示 不要有急難救助系統的選項」，選新網址 esign.ccra.tw。
// 功能沿用 relief.ccra.tw v0.3.18 的錄影簽名雛形，但不共用帳號、資料庫與資料夾：
//   登入：Cloudflare Access（名單在 Access 應用上），本站驗證它簽發的 JWT 取得 Email，不另設帳號密碼
//   資料：每筆一個資料夾，加密存放，在 /var/lib/esign（不進資料庫）
//   金鑰：/etc/esign/secret.ini 的 key（base64，32 bytes），與 relief 的個資金鑰分開
date_default_timezone_set('Asia/Taipei');
require_once __DIR__ . '/version.php';

const ESIGN_INI = '/etc/esign/secret.ini';

function data_dir(): string { return getenv('ESIGN_DATA_DIR') ?: '/var/lib/esign'; }

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function session_boot(): void {
    if (PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) return;
    session_name('esign');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
function csrf_token(): string {
    session_boot();
    if (PHP_SAPI === 'cli') return 'cli';
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}
function csrf_check(): void {
    if (PHP_SAPI === 'cli') return;
    session_boot();
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(400); exit('頁面已過期，請重新整理後再送出。'); }
}

function host_config(): array {
    static $c = null;
    return $c ??= (@parse_ini_file(PHP_SAPI === 'cli' && getenv('ESIGN_INI') ? getenv('ESIGN_INI') : ESIGN_INI) ?: []);
}

// 目前使用者：驗證 Cloudflare Access 簽發的 JWT（Cf-Access-Jwt-Assertion），取其中的 email。
// 不直接信 Cf-Access-Authenticated-User-Email 這種純文字標頭：它可以被偽造，簽章不行。
// 設定（主機 /etc/esign/secret.ini）：access_team＝團隊網域（xxx.cloudflareaccess.com）、access_aud＝Access 應用的 AUD。
function current_user(): array {
    if (PHP_SAPI === 'cli' && ($t = getenv('ESIGN_TEST_USER'))) return ['email' => strtolower($t)];
    $email = access_email((string)($_SERVER['HTTP_CF_ACCESS_JWT_ASSERTION'] ?? ''));
    if ($email === null) {
        http_response_code(403);
        exit('請由 https://esign.ccra.tw 登入後使用。');
    }
    return ['email' => $email];
}

function b64url(string $s): string|false { return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true); }

function access_email(string $jwt): ?string {
    $c = host_config();
    $team = (string)($c['access_team'] ?? ''); $aud = (string)($c['access_aud'] ?? '');
    $p = explode('.', $jwt);
    if (!$team || !$aud || count($p) !== 3) return null;
    $hd = json_decode((string)b64url($p[0]), true); $pl = json_decode((string)b64url($p[1]), true); $sig = b64url($p[2]);
    if (!is_array($hd) || !is_array($pl) || $sig === false || ($hd['alg'] ?? '') !== 'RS256') return null;
    $pem = access_cert($team, (string)($hd['kid'] ?? ''));
    if (!$pem || openssl_verify($p[0] . '.' . $p[1], $sig, $pem, OPENSSL_ALGO_SHA256) !== 1) return null;
    if (!in_array($aud, (array)($pl['aud'] ?? []), true) || ($pl['iss'] ?? '') !== "https://$team" || (int)($pl['exp'] ?? 0) < time()) return null;
    $email = strtolower((string)($pl['email'] ?? ''));
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
}

// Access 的公開金鑰，快取一小時；找不到 kid（金鑰輪替）就重抓一次
function access_cert(string $team, string $kid): ?string {
    $f = data_dir() . '/access_certs.json';
    for ($try = 0; $try < 2; $try++) {
        $certs = is_file($f) && ($try === 0) && filemtime($f) > time() - 3600 ? json_decode((string)file_get_contents($f), true) : null;
        if (!is_array($certs)) {
            $raw = @file_get_contents("https://$team/cdn-cgi/access/certs", false, stream_context_create(['http' => ['timeout' => 5]]));
            $certs = is_string($raw) ? (json_decode($raw, true)['public_certs'] ?? null) : null;
            if (!is_array($certs)) return null;
            @file_put_contents($f, json_encode($certs));
        }
        foreach ($certs as $x) if (($x['kid'] ?? '') === $kid) return (string)$x['cert'];
    }
    return null;
}

function secret_key(): string {
    if (PHP_SAPI === 'cli' && ($t = getenv('ESIGN_TEST_KEY'))) return str_pad($t, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, "\0");
    $raw = base64_decode((string)(host_config()['key'] ?? ''), true);
    if (!$raw || strlen($raw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new RuntimeException('加密金鑰未設定');
    return $raw;
}

// 存取紀錄：誰在何時做了什麼（一行一筆 JSON，只追加）
function audit(string $action, array $detail = []): void {
    $line = json_encode(['ts' => date('c'), 'user' => PHP_SAPI === 'cli' ? (getenv('ESIGN_TEST_USER') ?: 'cli') : current_user()['email'],
        'ip' => (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? ''), 'action' => $action, 'detail' => $detail], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    @file_put_contents(data_dir() . '/audit.log', $line . "\n", FILE_APPEND | LOCK_EX);
}

function page_head(string $title, ?array $u = null): void {
    echo '<!doctype html><html lang="zh-Hant"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title) . '｜電子簽章展示｜中華基督教救助協會</title><link rel="icon" href="/assets/ccra-logo.png">';
    echo '<link rel="stylesheet" href="/assets/site.css?v=' . ESIGN_VERSION . '"></head><body>';
    echo '<header class="top"><a class="brand" href="/"><img src="/assets/ccra-logo.png" alt="中華基督教救助協會標誌" width="46" height="46">'
       . '<span><b>電子簽章展示</b><small>中華基督教救助協會</small></span></a><span class="tag">展示版</span>';
    if ($u) echo '<span class="me"><span>' . h($u['email']) . '</span></span>';
    echo '</header><main>';
}
function page_foot(): void {
    echo '</main><footer class="foot"><p>電子簽章展示 v' . h(ESIGN_VERSION) . '（' . h(ESIGN_RELEASED) . '）・資訊部維護</p>'
       . '<p>© ' . date('Y') . ' 社團法人中華基督教救助協會 Chinese Christian Relief Association. 版權所有。</p></footer></body></html>';
}
