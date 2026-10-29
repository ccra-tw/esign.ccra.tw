<?php
// 簽署紀錄頁。顯示簽署內容、完成截圖、錄影與簽名，並當場重算雜湊驗證沒被改過。
require_once __DIR__ . '/../lib/esign.php';
$u = current_user();
$m = es_load((string)($_GET['id'] ?? ''));
if (!$m) { http_response_code(404); page_head('找不到', $u); echo '<p>找不到這筆簽署紀錄。</p>'; page_foot(); exit; }
audit('view', ['id' => $m['id']]);   // 存取紀錄：誰在何時調閱
$v = es_verify($m);
$ok = fn($b) => $b ? '<span style="color:#0a7d3b">✔ 吻合</span>' : '<span style="color:#c62828">✘ 不吻合（檔案或內容被改過）</span>';
page_head('簽署紀錄', $u);
echo '<h1>簽署紀錄 <small class="tag">展示</small></h1>';
echo '<table class="kv">';
echo '<tr><th>伺服器收到時間</th><td>' . h(date('Y-m-d H:i:s', strtotime($m['created_at']))) . '</td></tr>';
if (!empty($m['signed_at_client'])) echo '<tr><th>手機簽署時間</th><td>' . h($m['signed_at_client']) . '（蓋在簽名圖上；影片右上角也有逐秒時間）</td></tr>';
echo '<tr><th>簽署人（代稱）</th><td>' . h($m['signer']) . '</td></tr>';
echo '<tr><th>簽署內容</th><td>' . nl2br(h($m['content'])) . '</td></tr>';
echo '<tr><th>聲明內容</th><td>' . h($m['statement']) . '</td></tr>';
echo '<tr><th>操作同工</th><td>' . h($m['operator']) . '</td></tr>';
echo '<tr><th>簽名合成進錄影</th><td>' . (!empty($m['composite']) ? '是（影片裡看得到簽名過程）' : '否（此裝置不支援合成，簽名另存）') . '</td></tr>';
$g = $m['gps'] ?? null;
if ($g !== null) {
    $gl = ['denied' => '簽署時未允許取得位置', 'unavailable' => '手機取不到位置', 'timeout' => '取得位置逾時', 'unsupported' => '此瀏覽器不支援', 'invalid' => '位置資料格式不對', 'none' => '未取得'];
    echo '<tr><th>簽署位置（GPS）</th><td>' . (($g['status'] ?? '') === 'ok'
        ? h($g['lat'] . ', ' . $g['lng']) . (isset($g['acc_m']) ? '（誤差約 ' . (int)$g['acc_m'] . ' 公尺）' : '') . '<br><a href="https://www.google.com/maps?q=' . rawurlencode($g['lat'] . ',' . $g['lng']) . '" target="_blank" rel="noopener">在地圖上看</a>'
        : h($gl[$g['status'] ?? 'none'] ?? '未取得')) . '</td></tr>';
}
$fc = $m['face'] ?? null;
if ($fc !== null) echo '<tr><th>臉部偵測</th><td>' . (($fc['status'] ?? '') === 'ok'
    ? '錄影期間 ' . (int)$fc['hits'] . '／' . (int)$fc['checks'] . ' 次檢查有偵測到臉（' . round(($fc['ratio'] ?? 0) * 100) . '%）<br><small>只判斷畫面裡有沒有人臉，不辨識是誰</small>'
    : ($fc['status'] === 'unavailable' ? '偵測模組沒載入成功（網路或裝置不支援），未檢查' : '未檢查')) . '</td></tr>';
echo '<tr><th>錄影同意</th><td>' . ($m['consent'] ? '已勾選同意' : '—') . '</td></tr>';
echo '</table>';
if (!empty($m['snapshot_sha256'])) echo '<h2>完成截圖（送出那一刻：鏡頭畫面＋簽名＋時間）</h2><img src="/file.php?id=' . h($m['id']) . '&amp;k=snap" alt="完成截圖" style="width:100%;max-width:420px;border-radius:12px">';
echo '<h2>錄影（聲明＋簽名過程）</h2><video src="/file.php?id=' . h($m['id']) . '&amp;k=video" controls playsinline style="width:100%;max-width:420px;border-radius:12px"></video>';
echo '<h2>手寫簽名（白色筆跡，原本疊在影片上）</h2><img src="/file.php?id=' . h($m['id']) . '&amp;k=sig" alt="簽名" style="width:100%;max-width:600px;border:1px solid #ddd;border-radius:12px;background:#3a3a3a">';
echo '<h2>存證</h2><table class="kv">';
echo '<tr><th>存證碼</th><td><code>' . h($m['seal']) . '</code><br>' . $ok($v['seal']) . '</td></tr>';
echo '<tr><th>簽名 SHA-256</th><td><code>' . h($m['signature_sha256']) . '</code><br>' . $ok($v['signature']) . '</td></tr>';
if (!empty($m['snapshot_sha256'])) echo '<tr><th>完成截圖 SHA-256</th><td><code>' . h($m['snapshot_sha256']) . '</code><br>' . $ok($v['snapshot']) . '</td></tr>';
echo '<tr><th>錄影 SHA-256</th><td><code>' . h($m['video_sha256']) . '</code>（' . h($m['video_mime']) . '，' . number_format($m['video_bytes'] / 1048576, 1) . ' MB）<br>' . $ok($v['video']) . '</td></tr>';
echo '<tr><th>來源 IP</th><td>' . h($m['ip'] ?? '（未記錄）') . '</td></tr>';
echo '<tr><th>儲存方式</th><td>加密存放</td></tr>';
echo '<tr><th>裝置</th><td><small>' . h($m['user_agent']) . '</small></td></tr>';
echo '</table>';
echo '<p class="note">每次打開這頁都會解密並重新計算雜湊；簽名圖、錄影或任何一個欄位被改過，對應那行就會顯示「不吻合」。每天的存證碼另會寄到主機以外的信箱留存，供日後比對。</p>';
echo '<p><a href="/sign.php">再簽一筆</a>　<a href="/">簽署紀錄列表</a></p>';
page_foot();
