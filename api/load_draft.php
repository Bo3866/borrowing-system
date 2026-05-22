<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'message' => '請先登入'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode([
        'success' => false,
        'message' => '只接受 GET 請求'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$draftId = 0;

if (isset($_GET['draft_id'])) {
    $draftId = (int)$_GET['draft_id'];
} elseif (isset($_GET['id'])) {
    $draftId = (int)$_GET['id'];
}

if ($draftId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => '草稿編號錯誤'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (string)$_SESSION['user_id'];

$dbError = '';
$link = getMysqliConnection($dbError);

if ($dbError !== '') {
    echo json_encode([
        'success' => false,
        'message' => '資料庫連線失敗：' . $dbError
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$sql = "
    SELECT
        draft_id,
        user_id,
        activity_name,
        purpose,
        proposal_file,
        proposal_original_name,
        proposal_uploaded_at,
        current_step,
        draft_data,
        created_at,
        updated_at
    FROM reservation_drafts
    WHERE draft_id = ? AND user_id = ?
    LIMIT 1
";

$stmt = mysqli_prepare($link, $sql);

if (!$stmt) {
    echo json_encode([
        'success' => false,
        'message' => '查詢草稿失敗：' . mysqli_error($link)
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

mysqli_stmt_bind_param($stmt, 'is', $draftId, $userId);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($result);

if (!$row) {
    echo json_encode([
        'success' => false,
        'message' => '草稿不存在或無權限存取'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$decoded = json_decode($row['draft_data'] ?? '', true);

if (!is_array($decoded)) {
    $decoded = [];
}

$formData = $decoded['formData'] ?? [];
$currentStep = $decoded['currentStep'] ?? $row['current_step'];

echo json_encode([
    'success' => true,
    'draft' => [
        'draft_id' => (int)$row['draft_id'],
        'activity_name' => $row['activity_name'],
        'purpose' => $row['purpose'],
        'proposal_file' => $row['proposal_file'],
        'proposal_original_name' => $row['proposal_original_name'],
        'proposal_uploaded_at' => $row['proposal_uploaded_at'],
        'current_step' => $currentStep,
        'formData' => $formData,
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at']
    ]
], JSON_UNESCAPED_UNICODE);

mysqli_stmt_close($stmt);
mysqli_close($link);
?>