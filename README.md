# esign.ccra.tw

中華基督教救助協會「電子簽章」展示站：錄影＋手寫簽名（簽名壓在影片上）、完成截圖、時間戳、臉部偵測、GPS、加密存放與存證碼。

- 技術：PHP 8.5，無資料庫（每筆一個加密資料夾）；登入由 Cloudflare Access 把關。
- 部署：主機 `sh /opt/esign.ccra.tw/deploy/deploy.sh`（從 GitHub 拉）。
- 測試：`php tests/run.php`。
- 只放測試資料。保存期限：展示站 30 天；正式上線依協會「電子簽章作業辦法」。
