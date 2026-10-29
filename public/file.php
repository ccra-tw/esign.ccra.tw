<?php
// 解密後串流簽名圖、完成截圖或錄影（需經 Cloudflare Access 登入）。支援 Range，手機播放影片才拖得動。
require_once __DIR__ . '/../lib/esign.php';
current_user();
$m = es_load((string)($_GET['id'] ?? ''));
$k = $_GET['k'] ?? '';
if (!$m || !in_array($k, ['sig', 'snap', 'video'], true)) { http_response_code(404); exit('找不到'); }
$data = es_read($m, $k);
if ($data === null) { http_response_code(500); exit('檔案無法讀取或解密失敗'); }
$size = strlen($data); $start = 0; $end = $size - 1;
header('Content-Type: ' . match ($k) { 'sig' => 'image/png', 'snap' => 'image/jpeg', default => $m['video_mime'] });
header('Accept-Ranges: bytes');
header('Cache-Control: private, no-store');
if (preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'] ?? '', $r)) {
    if ($r[1] !== '') $start = (int)$r[1];
    if ($r[2] !== '') $end = min((int)$r[2], $size - 1);
    if ($r[1] === '' && $r[2] !== '') { $start = max(0, $size - (int)$r[2]); $end = $size - 1; }
    if ($start > $end) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}
header('Content-Length: ' . ($end - $start + 1));
echo substr($data, $start, $end - $start + 1);
