<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => '請先登入'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => '只接受 POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (string)$_SESSION['user_id'];

$dbError = '';
$link = getMysqliConnection($dbError);

if ($dbError !== '') {
    echo json_encode(['success' => false, 'message' => $dbError], JSON_UNESCAPED_UNICODE);
    exit;
}

$draftId = isset($_POST['draft_id']) && $_POST['draft_id'] !== ''
    ? (int)$_POST['draft_id']
    : 0;

$currentStep = (string)($_POST['currentStep'] ?? $_POST['current_step'] ?? '1');

$formData = $_POST;
unset($formData['draft_id'], $formData['currentStep']);

$activityName = trim((string)($_POST['activity_name'] ?? '未填寫活動名稱'));
$purpose = trim((string)($_POST['purpose'] ?? ''));

$proposalFile = null;
$proposalOriginalName = null;
$proposalUploadedAt = null;

if (
    isset($_FILES['proposal_file']) &&
    $_FILES['proposal_file']['error'] !== UPLOAD_ERR_NO_FILE
) {
    $file = $_FILES['proposal_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => '企劃書上傳失敗'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => '企劃書不可超過 5MB'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));

    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($file['tmp_name']);
    } elseif (function_exists('mime_content_type')) {
        $mime = (string)mime_content_type($file['tmp_name']);
    } else {
        $mime = '';
    }

    if ($mime !== 'application/pdf' && $ext !== 'pdf') {
        echo json_encode(['success' => false, 'message' => '企劃書只能上傳 PDF'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 專門存放草稿企劃書 PDF 的資料夾
    $uploadDir = dirname(__DIR__) . '/uploads/draft_proposals';

    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            echo json_encode(['success' => false, 'message' => '建立企劃書資料夾失敗'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $originalName = basename((string)$file['name']);
    $safeName = date('Ymd_His') . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $userId) . '_' . bin2hex(random_bytes(6)) . '.pdf';
    $targetPath = $uploadDir . '/' . $safeName;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        echo json_encode(['success' => false, 'message' => '企劃書檔案儲存失敗'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $proposalFile = 'uploads/draft_proposals/' . $safeName;
    $proposalOriginalName = $originalName;
    $proposalUploadedAt = date('Y-m-d H:i:s');
}

$draftJson = json_encode([
    'formData' => $formData,
    'currentStep' => $currentStep
], JSON_UNESCAPED_UNICODE);

if ($draftJson === false) {
    echo json_encode(['success' => false, 'message' => '草稿資料轉換失敗'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($draftId > 0) {
    if ($proposalFile !== null) {
        $sql = "
            UPDATE reservation_drafts
            SET activity_name = ?,
                purpose = ?,
                proposal_file = ?,
                proposal_original_name = ?,
                proposal_uploaded_at = ?,
                current_step = ?,
                draft_data = ?
            WHERE draft_id = ? AND user_id = ?
        ";

        $stmt = mysqli_prepare($link, $sql);
        mysqli_stmt_bind_param(
            $stmt,
            'sssssssis',
            $activityName,
            $purpose,
            $proposalFile,
            $proposalOriginalName,
            $proposalUploadedAt,
            $currentStep,
            $draftJson,
            $draftId,
            $userId
        );
    } else {
        $sql = "
            UPDATE reservation_drafts
            SET activity_name = ?,
                purpose = ?,
                current_step = ?,
                draft_data = ?
            WHERE draft_id = ? AND user_id = ?
        ";

        $stmt = mysqli_prepare($link, $sql);
        mysqli_stmt_bind_param(
            $stmt,
            'ssssis',
            $activityName,
            $purpose,
            $currentStep,
            $draftJson,
            $draftId,
            $userId
        );
    }

    if (!$stmt || !mysqli_stmt_execute($stmt)) {
        echo json_encode(['success' => false, 'message' => '草稿更新失敗：' . mysqli_error($link)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => '草稿已更新',
        'draft_id' => $draftId,
        'proposal_file' => $proposalFile,
        'proposal_original_name' => $proposalOriginalName
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$sql = "
    INSERT INTO reservation_drafts
    (
        user_id,
        activity_name,
        purpose,
        proposal_file,
        proposal_original_name,
        proposal_uploaded_at,
        current_step,
        draft_data
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
";

$stmt = mysqli_prepare($link, $sql);
mysqli_stmt_bind_param(
    $stmt,
    'ssssssss',
    $userId,
    $activityName,
    $purpose,
    $proposalFile,
    $proposalOriginalName,
    $proposalUploadedAt,
    $currentStep,
    $draftJson
);

if (!$stmt || !mysqli_stmt_execute($stmt)) {
    echo json_encode(['success' => false, 'message' => '草稿新增失敗：' . mysqli_error($link)], JSON_UNESCAPED_UNICODE);
    exit;
}

$newDraftId = (int)mysqli_insert_id($link);

echo json_encode([
    'success' => true,
    'message' => '草稿已暫存',
    'draft_id' => $newDraftId,
    'proposal_file' => $proposalFile,
    'proposal_original_name' => $proposalOriginalName
], JSON_UNESCAPED_UNICODE);
?>
