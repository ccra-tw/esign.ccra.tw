<?php
// 每日把前一天的存證碼清單寫進外寄佇列（/var/lib/esign/outbox），由資訊部的寄送程式取走寄到主機以外的信箱。
// 用法：php bin/digest.php [YYYY-MM-DD]（預設昨天）
require_once __DIR__ . '/../lib/esign.php';
$day = $argv[1] ?? date('Y-m-d', strtotime('yesterday'));
if (!preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $day)) { fwrite(STDERR, "日期格式要 YYYY-MM-DD\n"); exit(2); }
echo date('c') . " digest {$day}：" . es_digest($day) . " 筆\n";
