<?php
// 接收簽名、完成截圖與錄影，回 JSON {id} 或 {error}。
require_once __DIR__ . '/../lib/esign.php';
$u = current_user();
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); exit(json_encode(['error' => '只接受 POST'])); }
csrf_check();
$file = fn($k) => ($_FILES[$k]['error'] ?? 1) === UPLOAD_ERR_OK ? (string)file_get_contents($_FILES[$k]['tmp_name']) : '';
if (($_FILES['video']['error'] ?? 0) === UPLOAD_ERR_INI_SIZE) exit(json_encode(['error' => '錄影檔太大（上限 10 MB），請錄短一點。'], JSON_UNESCAPED_UNICODE));
$vid = ($_FILES['video']['error'] ?? 1) === UPLOAD_ERR_OK ? $_FILES['video']['tmp_name'] : '';
[$id, $err] = es_save($_POST, $file('signature'), $vid, $u, $file('snapshot'));
echo json_encode($id ? ['id' => $id] : ['error' => $err], JSON_UNESCAPED_UNICODE);
