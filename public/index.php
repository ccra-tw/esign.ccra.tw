<?php
// 簽署紀錄列表。
require_once __DIR__ . '/../lib/esign.php';
$u = current_user();
page_head('簽署紀錄', $u);
echo '<h1>電子簽章 <small class="tag">展示</small></h1>';
echo '<p class="note">展示「錄影＋手寫簽名」的電子簽署：簽名壓在錄影上、完成截圖、時間戳、位置、臉部偵測與存證碼。只放測試資料，請勿使用真實姓名或個資；紀錄保存 ' . ES_KEEP_DAYS . ' 天後自動刪除。</p>';
echo '<p><a class="btn primary" href="/sign.php">新增一筆簽署</a></p>';
$rows = es_list();
if (!$rows) echo '<p>目前沒有紀錄。</p>';
else {
    echo '<table><thead><tr><th>時間</th><th>簽署人（代稱）</th><th>簽署內容</th><th>操作同工</th><th></th></tr></thead><tbody>';
    foreach ($rows as $m) {
        echo '<tr><td>' . h(date('m/d H:i', strtotime($m['created_at']))) . '</td><td>' . h($m['signer']) . '</td><td>' . h(mb_strimwidth($m['content'], 0, 40, '…')) . '</td><td>' . h($m['operator']) . '</td>'
           . '<td><a href="/view.php?id=' . h($m['id']) . '">紀錄</a></td></tr>';
    }
    echo '</tbody></table>';
}
page_foot();
