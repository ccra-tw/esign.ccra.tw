# esign.ccra.tw（電子簽章展示）

熊哥 2026-10-29：「電子簽章 先獨立 不算在急難救助系統 就是單純電子簽章展示 不要有急難救助系統的選項」。
功能由 relief.ccra.tw v0.3.18 的錄影簽名雛形拆出；之後兩邊各自演進，不共用程式碼。

**本 repo 公開**：程式碼與 Issue 不寫主機 IP、內部網段、帳號密碼與金鑰、真實姓名與個資；細節放 CCRA Planner 對應票（iIl_ELQj）。
金鑰在主機 /etc/esign/secret.ini（不進 repo），欄位 key／access_team／access_aud。
⚠️ **值一律加雙引號**：base64 金鑰結尾的 = 會讓 parse_ini_file 整檔讀失敗，症狀是登入一律 403（錯誤紀錄寫「主機未設定 access_team／access_aud」）——2026-10-29 上線當天踩到。只放測試資料。
