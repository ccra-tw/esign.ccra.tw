<?php
// 測試：php tests/run.php（用暫存資料夾與測試金鑰，不碰正式資料）
$tmp = sys_get_temp_dir() . '/esign-test-' . bin2hex(random_bytes(4));
mkdir($tmp, 0770, true);
putenv("ESIGN_DATA_DIR=$tmp");
putenv('ESIGN_TEST_KEY=test-key-only-for-tests');
putenv('ESIGN_TEST_USER=worker@example.test');
require __DIR__ . '/../lib/esign.php';

$fail = 0;
function ok(bool $c, string $name): void { global $fail; echo ($c ? '  ✅ ' : '  ❌ ') . $name . "\n"; if (!$c) $fail++; }
function page(string $file, array $get = []): string {
    $_GET = $get;
    ob_start();
    include __DIR__ . '/../public/' . $file;
    return (string)ob_get_clean();
}

$u = ['email' => 'worker@example.test'];
$png = hex2bin('89504e470d0a1a0a') . str_repeat('p', 200);
$jpg = hex2bin('ffd8ffe0') . str_repeat('j', 500);
$mp4 = tempnam(sys_get_temp_dir(), 'es');
file_put_contents($mp4, "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41" . str_repeat("\0", 4000));
$base = ['signer' => '測試甲', 'content' => '同意書（測試）', 'consent' => '1', 'statement' => '我是測試甲'];

echo "存檔與驗證\n";
ok(es_save(['signer' => '測試甲', 'content' => 'x'], $png, $mp4, $u)[0] === null, '沒勾同意錄影就退件');
ok(es_save($base + ['content' => ''], $png, $mp4, $u)[0] === null || es_save(array_merge($base, ['content' => '']), $png, $mp4, $u)[0] === null, '沒填簽署內容就退件');
ok(es_save($base, 'not-png', $mp4, $u)[0] === null, '沒有簽名就退件');
ok(es_save($base, $png, $mp4, $u, 'not-a-jpeg')[0] === null, '完成截圖不是 JPEG 就退件');
[$id, $err] = es_save($base + ['composite' => '1', 'signed_at_client' => '2026/10/29 15:00:01<script>', 'gps_status' => 'ok', 'gps_lat' => '25.03', 'gps_lng' => '121.56', 'gps_acc' => '8.4', 'face_status' => 'ok', 'face_checks' => '10', 'face_hits' => '99'], $png, $mp4, $u, $jpg);
ok(es_valid_id($id) && $err === null, '簽名＋截圖＋錄影＋同意可以存檔');
$m = es_load((string)$id);
ok($m !== null && es_verify($m) === ['signature' => true, 'video' => true, 'snapshot' => true, 'seal' => true], '存檔後各雜湊與存證碼都吻合');
ok($m['operator'] === 'worker@example.test' && $m['signed_at_client'] === '2026/10/29 15:00:01', '記錄操作同工；手機時間只留數字與時間符號');
ok($m['gps'] == ['status' => 'ok', 'lat' => 25.03, 'lng' => 121.56, 'acc_m' => 8] && $m['face'] == ['status' => 'ok', 'checks' => 10, 'hits' => 10, 'ratio' => 1.0], '位置與臉部偵測結果正規化（有臉次數不超過檢查次數）');   // JSON 存回後 1.0 會變成 1，所以用 == 比
ok(es_gps(['gps_status' => 'denied', 'gps_lat' => '25']) === ['status' => 'denied'] && es_gps(['gps_status' => 'ok', 'gps_lat' => '999', 'gps_lng' => '1'])['status'] === 'invalid' && es_gps(['gps_status' => '<x>'])['status'] === 'none', 'GPS 不允許只記原因；亂填的不收');
ok(es_read($m, 'sig') === $png && es_read($m, 'snap') === $jpg, '解密後簽名與截圖逐位元相同');
$raw = file_get_contents("$tmp/records/$id/signature.png.enc") . file_get_contents("$tmp/records/$id/" . $m['video_file'] . '.enc');
ok(!str_contains($raw, 'ftypisom') && substr($raw, 0, 8) !== hex2bin('89504e470d0a1a0a'), '簽名與錄影加密存放，磁碟上看不到原始內容');
$m2 = $m; $m2['content'] = '同意書（測試）改';
ok(es_verify($m2)['seal'] === false, '內容被改，存證碼就對不上');
$m3 = $m; $m3['gps']['lat'] = 24.0;
ok(es_verify($m3)['seal'] === false, '座標被改，存證碼就對不上');

echo "頁面\n";
$o = page('index.php');
ok(str_contains($o, '測試甲') && str_contains($o, '電子簽章') && !str_contains($o, '急難救助'), '列表頁有紀錄，沒有急難救助系統字樣');
$o = page('view.php', ['id' => $id]);
ok(str_contains($o, '25.03, 121.56') && str_contains($o, '10／10') && substr_count($o, '✔ 吻合') === 4, '紀錄頁顯示位置、臉部偵測，四項驗證都吻合');
$o = page('sign.php');
ok(str_contains($o, 'name="signer"') && str_contains($o, 'name="content"') && !str_contains($o, '急難救助') && !str_contains($o, '物資'), '簽署頁是通用欄位，沒有急難救助／物資字樣');
ok(str_contains(file_get_contents("$tmp/audit.log"), '"action":"view"'), '調閱紀錄寫進 audit.log');

echo "每日存證碼清單與保存期限\n";
$today = date('Y-m-d');
$d1 = es_digest($today); $d2 = es_digest($today); $nf = count(glob("$tmp/outbox/*.txt"));
ok($d1 === 1 && $d2 === 1 && $nf === 1, "當天清單寫進 outbox，重跑不重複產生（{$d1}/{$d2}/{$nf}）");
$body = file_get_contents("$tmp/outbox/digest-$today.txt");
ok(str_contains($body, $m['seal']) && !str_contains($body, '測試甲'), '清單只有編號與存證碼，沒有姓名');
$old = json_decode(file_get_contents("$tmp/records/$id/meta.json"), true);
$old['created_at'] = date('c', time() - (ES_KEEP_DAYS + 1) * 86400);
file_put_contents("$tmp/records/$id/meta.json", json_encode($old, JSON_UNESCAPED_UNICODE));
ok(es_purge_expired() === 1 && !is_dir("$tmp/records/$id"), '超過保存期限的紀錄會被刪除');

echo "PHP 字串插值（避免 \"\$x：\" 這類全形標點緊接變數）\n";
$bad = [];
foreach (array_merge(glob(__DIR__ . '/../lib/*.php'), glob(__DIR__ . '/../public/*.php'), glob(__DIR__ . '/../bin/*.php')) as $f)
    if (preg_match('/"[^"\n]*\$[a-z_]+[^\x00-\x7f]/u', file_get_contents($f))) $bad[] = basename($f);
ok(!$bad, '沒有變數後面直接接中文的字串插值' . ($bad ? '：' . implode('、', $bad) : ''));

exec('rm -rf ' . escapeshellarg($tmp));
@unlink($mp4);
echo $fail ? "\n❌ $fail 項失敗\n" : "\n全部通過\n";
exit($fail ? 1 : 0);
