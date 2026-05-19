<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?next=amend_application.php');
    exit;
}

$currentUserId = (string)$_SESSION['user_id'];
$displayName = (string)($_SESSION['full_name'] ?? $_SESSION['user_id']);
$reservationId = (int)($_GET['reservation_id'] ?? 0);

if ($reservationId <= 0) {
    header('Location: return_management.php');
    exit;
}

$dbError = '';
$link = getMysqliConnection($dbError);

$amendError = '';
$amendSuccess = '';
$revisionData = [];

if ($dbError === '') {
    $availableCols = [];
    $colRes = mysqli_query($link, 'SHOW COLUMNS FROM reservations');
    if ($colRes) {
        while ($crow = mysqli_fetch_assoc($colRes)) {
            $availableCols[] = (string)$crow['Field'];
        }
        mysqli_free_result($colRes);
    }

    // 擴充 wantedCols，加入所有特殊需求與路旗欄位
    $wantedCols = [
        'reservation_id', 'user_id', 'approval_status', 'revision_data_json', 'revision_deadline',
        'has_alcohol', 'has_fire', 'has_sales',
        'organization_name', 'activity_name', 'participant_count', 'staff_count', 'club_president',
        'activity_coordinator', 'coordinator_department', 'coordinator_phone', 'coordinator_other_contact',
        'vehicle_entry', 'has_alcohol', 'has_fire', 'has_sales',
        'setup_flags', 'flag_count', 'flag_details', 'flag_applicant_unit', 'flag_manager', 
        'flag_phone', 'flag_activity_name', 'flag_start_date', 'flag_end_date', 'flag_location', 'flag_agreement',
        'proposal_file', 'proposal_uploaded_at', 'purpose', 'borrow_start_at', 'borrow_end_at', 'space_id'
    ];
    
    $selectCols = [];
    foreach ($wantedCols as $columnName) {
        if (in_array($columnName, $availableCols, true)) {
            $selectCols[] = 'r.' . $columnName;
        }
    }

    if (empty($selectCols)) {
        $amendError = '資料表欄位不足，無法讀取申請資料。';
        $reservationRow = null;
    } else {
        $checkSql = 'SELECT ' . implode(', ', $selectCols) . ' FROM reservations r WHERE r.reservation_id = ? AND r.user_id = ? LIMIT 1';
        $checkStmt = mysqli_prepare($link, $checkSql);
        if ($checkStmt) {
            mysqli_stmt_bind_param($checkStmt, 'is', $reservationId, $currentUserId);
            mysqli_stmt_execute($checkStmt);
            $checkResult = mysqli_stmt_get_result($checkStmt);
            $reservationRow = $checkResult ? mysqli_fetch_assoc($checkResult) : null;
            mysqli_stmt_close($checkStmt);
        } else {
            $reservationRow = null;
        }
    }

    $equipmentItems = [];
    $equipStmt = mysqli_prepare($link, '
        SELECT eri.equipment_id, e.equipment_code, ec.equipment_name, eri.borrow_quantity
        FROM equipment_reservation_items eri
        JOIN equipments e ON e.equipment_id = eri.equipment_id
        JOIN equipment_categories ec ON ec.equipment_code = e.equipment_code
        WHERE eri.reservation_id = ?
        ORDER BY ec.equipment_code ASC
    ');
    if ($equipStmt) {
        mysqli_stmt_bind_param($equipStmt, 'i', $reservationId);
        mysqli_stmt_execute($equipStmt);
        $equipResult = mysqli_stmt_get_result($equipStmt);
        while ($equipRow = $equipResult ? mysqli_fetch_assoc($equipResult) : null) {
            $equipmentItems[] = $equipRow;
        }
        mysqli_stmt_close($equipStmt);
    }

    $spaceItems = [];
    $spaceStmt = mysqli_prepare($link, '
        SELECT sri.space_id, s.space_name, s.capacity
        FROM space_reservation_items sri
        JOIN spaces s ON s.space_id = sri.space_id
        WHERE sri.reservation_id = ?
        ORDER BY s.space_id ASC
    ');
    if ($spaceStmt) {
        mysqli_stmt_bind_param($spaceStmt, 'i', $reservationId);
        mysqli_stmt_execute($spaceStmt);
        $spaceResult = mysqli_stmt_get_result($spaceStmt);
        while ($spaceRow = $spaceResult ? mysqli_fetch_assoc($spaceResult) : null) {
            $spaceItems[] = $spaceRow;
        }
        mysqli_stmt_close($spaceStmt);
    }

    $hasProposalFileColumn = in_array('proposal_file', $availableCols, true);
    $hasProposalUploadedAtColumn = in_array('proposal_uploaded_at', $availableCols, true);
        
        if (!$reservationRow) {
            $amendError = '找不到該申請或無權限修改。';
        } elseif ($reservationRow['approval_status'] !== 'need_revision') {
            $amendError = '該申請不在補件狀態，無法修改。';
        } else {
            // Prefer revision_data_json when available.
            if (!empty($reservationRow['revision_data_json'])) {
                $revisionData = (array)json_decode($reservationRow['revision_data_json'], true) ?: [];
            }
            
            // Fallback to current reservation fields when revision_data_json is empty.
            if (empty($revisionData)) {
                $revisionData = [
                    'organization_name' => $reservationRow['organization_name'] ?? '',
                    'activity_name' => $reservationRow['activity_name'] ?? '',
                    'participant_count' => $reservationRow['participant_count'] ?? '',
                    'staff_count' => $reservationRow['staff_count'] ?? 0,
                    'club_president' => $reservationRow['club_president'] ?? '',
                    'activity_coordinator' => $reservationRow['activity_coordinator'] ?? '',
                    'coordinator_department' => $reservationRow['coordinator_department'] ?? '',
                    'coordinator_phone' => $reservationRow['coordinator_phone'] ?? '',
                    'coordinator_other_contact' => $reservationRow['coordinator_other_contact'] ?? '',
                    'vehicle_entry' => $reservationRow['vehicle_entry'] ?? 'no',
                    'setup_flags' => $reservationRow['setup_flags'] ?? 'no',
                    'flag_count' => $reservationRow['flag_count'] ?? 0,
                    'proposal_file' => $reservationRow['proposal_file'] ?? '',
                    'proposal_uploaded_at' => $reservationRow['proposal_uploaded_at'] ?? '',
                    'has_alcohol' => $reservationRow['has_alcohol'] ?? '',
                    'has_fire' => $reservationRow['has_fire'] ?? '',
                    'has_sales' => $reservationRow['has_sales'] ?? '',
                    'flag_count' => $reservationRow['flag_count'] ?? 0,
                    'purpose' => $reservationRow['purpose'] ?? '',
                    'borrow_start_at' => $reservationRow['borrow_start_at'] ?? '',
                    'borrow_end_at' => $reservationRow['borrow_end_at'] ?? '',
                    'space_id' => $reservationRow['space_id'] ?? '',
                ];
            }
            
            // 撠身??蝛粹??摮 revisionData
            $revisionData['equipment_items'] = $equipmentItems;
            $revisionData['space_items'] = $spaceItems;
            
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                // ?園?靽格敺?銵典?豢?
                $updatedFields = [
                    'organization_name' => trim((string)($_POST['organization_name'] ?? '')),
                    'activity_name' => trim((string)($_POST['activity_name'] ?? '')),
                    'participant_count' => trim((string)($_POST['participant_count'] ?? '')),
                    'staff_count' => (int)($_POST['staff_count'] ?? 0),
                    'club_president' => trim((string)($_POST['club_president'] ?? '')),
                    'activity_coordinator' => trim((string)($_POST['activity_coordinator'] ?? '')),
                    'coordinator_department' => trim((string)($_POST['coordinator_department'] ?? '')),
                    'coordinator_phone' => trim((string)($_POST['coordinator_phone'] ?? '')),
                    'coordinator_other_contact' => trim((string)($_POST['coordinator_other_contact'] ?? '')),
                    'vehicle_entry' => trim((string)($_POST['vehicle_entry'] ?? 'no')),
                    'setup_flags' => trim((string)($_POST['setup_flags'] ?? 'no')),
                    'flag_count' => (int)($_POST['flag_count'] ?? 0),
                    'flag_agreement' => isset($_POST['flag_agreement']) ? '1' : '',
                    'has_alcohol' => isset($_POST['has_alcohol']) ? '1' : '',
                    'has_fire' => isset($_POST['has_fire']) ? '1' : '',
                    'has_sales' => isset($_POST['has_sales']) ? '1' : '',
                    'purpose' => trim((string)($_POST['purpose'] ?? '')),
                ];

            $uploadedProposalPath = null;
            $uploadedProposalDbPath = null;
            $uploadedProposalAt = null;

            if (isset($_FILES['proposal_file']) && $_FILES['proposal_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                $proposalFile = $_FILES['proposal_file'];
                if ($proposalFile['error'] !== UPLOAD_ERR_OK) {
                    $amendError = '企劃書上傳失敗（錯誤碼：' . (int)$proposalFile['error'] . '）。';
                } else {
                    $maxBytes = 5 * 1024 * 1024;
                    if ((int)$proposalFile['size'] > $maxBytes) {
                        $amendError = '企劃書大小不可超過 5MB。';
                    } else {
                        if (class_exists('finfo')) {
                            $finfo = new finfo(FILEINFO_MIME_TYPE);
                            $mime = (string)$finfo->file($proposalFile['tmp_name']);
                        } elseif (function_exists('mime_content_type')) {
                            $mime = (string)mime_content_type($proposalFile['tmp_name']);
                        } else {
                            $ext = strtolower(pathinfo((string)$proposalFile['name'], PATHINFO_EXTENSION));
                            $mime = ($ext === 'pdf') ? 'application/pdf' : '';
                        }

                        if ($mime !== 'application/pdf') {
                            $amendError = '企劃書格式不支援，僅接受 PDF。';
                        } else {
                            $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'proposals';
                            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                                $amendError = '建立上傳目錄失敗。';
                            } else {
                                $safeBasename = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo((string)$proposalFile['name'], PATHINFO_FILENAME));
                                $targetName = time() . '_' . $safeBasename . '.pdf';
                                $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $targetName;
                                if (!move_uploaded_file($proposalFile['tmp_name'], $targetPath)) {
                                    $amendError = '企劃書儲存失敗。';
                                } else {
                                    $uploadedProposalPath = $targetPath;
                                    $uploadedProposalDbPath = 'uploads/proposals/' . $targetName;
                                    $uploadedProposalAt = date('Y-m-d H:i:s');
                                }
                            }
                        }
                    }
                }
            }
            
            // 驗證必填欄位
            if ($updatedFields['organization_name'] === '') {
                $amendError = '請填寫單位名稱。';
            } elseif ($updatedFields['activity_name'] === '') {
                $amendError = '請填寫活動名稱。';
            } elseif ($updatedFields['purpose'] === '') {
                $amendError = '請填寫用途說明。';
            } elseif ($amendError === '') {
                // 執行更新
                mysqli_begin_transaction($link);
                try {
                    $updateFields = [];
                    $updateValues = [];
                    $updateTypes = '';
                    
                    foreach ($updatedFields as $key => $value) {
                        // 確保該欄位確實在資料庫中才做更新，防止結構衝突
                        if (in_array($key, $availableCols, true)) {
                            $updateFields[] = "{$key} = ?";
                            $updateValues[] = $value;
                            $updateTypes .= is_int($value) ? 'i' : 's';
                        }
                    }
                    
                    $updateValues[] = $reservationId;
                    $updateTypes .= 'i';
                    
                    $updateSql = 'UPDATE reservations SET ' . implode(', ', $updateFields) . ', approval_status = "pending", updated_at = NOW() WHERE reservation_id = ?';
                    $updateStmt = mysqli_prepare($link, $updateSql);
                    
                    if (!$updateStmt) {
                        throw new RuntimeException('準備更新語句失敗：' . mysqli_error($link));
                    }
                    
                    mysqli_stmt_bind_param($updateStmt, $updateTypes, ...$updateValues);
                    mysqli_stmt_execute($updateStmt);
                    mysqli_stmt_close($updateStmt);

                    if ($uploadedProposalDbPath !== null) {
                        if ($hasProposalFileColumn && $hasProposalUploadedAtColumn) {
                            $proposalStmt = mysqli_prepare($link, 'UPDATE reservations SET proposal_file = ?, proposal_uploaded_at = ?, updated_at = NOW() WHERE reservation_id = ?');
                            if (!$proposalStmt) {
                                throw new RuntimeException('準備更新企劃書欄位失敗：' . mysqli_error($link));
                            }
                            mysqli_stmt_bind_param($proposalStmt, 'ssi', $uploadedProposalDbPath, $uploadedProposalAt, $reservationId);
                            mysqli_stmt_execute($proposalStmt);
                            mysqli_stmt_close($proposalStmt);
                        } else {
                            throw new RuntimeException('資料表尚未建立 proposal_file / proposal_uploaded_at 欄位。');
                        }
                    }
                    
                    mysqli_commit($link);
                    $amendSuccess = '補件已提交，已重新進入審核流程。';
                    
                    // 修正：用 array_merge 融合新舊資料，確保設備與空間陣列不會被洗掉
                    $revisionData = array_merge($revisionData, $updatedFields);
                    if ($uploadedProposalDbPath !== null) {
                        $revisionData['proposal_file'] = $uploadedProposalDbPath;
                        $revisionData['proposal_uploaded_at'] = $uploadedProposalAt;
                    }
                } catch (Throwable $e) {
                    mysqli_rollback($link);
                    if ($uploadedProposalPath !== null && is_file($uploadedProposalPath)) {
                        @unlink($uploadedProposalPath);
                    }
                    $amendError = $e->getMessage();
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>補件修改｜校園資源租借系統</title>
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
    <style>
        .amend-header {
            background: #e3f2fd;
            border-left: 4px solid #1976d2;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        .amend-header h3 {
            margin: 0 0 5px 0;
            color: #1565c0;
        }
        .amend-header p {
            margin: 0;
            color: #555;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <nav class="navbar">
            <div class="navbar-brand">
                <h1>📚 校園資源租借系統</h1>
            </div>
            <div class="navbar-menu">
                <button class="nav-btn" onclick="location.href='index.php'">回首頁</button>
                <button class="nav-btn" onclick="location.href='return_management.php'">申請管理</button>
                <button class="nav-btn" type="button" disabled><?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></button>
                <button class="nav-btn" onclick="location.href='logout.php'">登出</button>
            </div>
        </nav>


        <main class="main-content">
            <section class="borrow-page">
                <h2>補件修改</h2>
                
                <?php if ($dbError !== '') { ?>
                    <div class="login-alert"><?php echo htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } ?>

                <?php if ($amendError !== '') { ?>
                    <div class="login-alert"><?php echo htmlspecialchars($amendError, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } ?>

                <?php if ($amendSuccess !== '') { ?>
                    <div class="borrow-success"><?php echo htmlspecialchars($amendSuccess, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } ?>

                <?php 
                    // Debug log
                    @error_log("DEBUG: reservationId=$reservationId, amendError=$amendError, revisionDataCount=" . count($revisionData) . ", jsonDebug=" . json_encode($revisionData));
                ?>

                <?php if ($dbError === '' && $amendSuccess === '' && empty($amendError)) { ?>
                    <div class="amend-header">
                        <h3>補件說明</h3>
                        <p>審核人員要求您修改此申請。請更新下方表單後重新提交，系統將重新進入審核流程。</p>
                    </div>

                    <section class="card borrow-form-card">
                        <form method="post" enctype="multipart/form-data" class="borrow-form" action="amend_application.php?reservation_id=<?php echo (int)$reservationId; ?>" novalidate>
                            <h3 class="step-title" style="margin-bottom: 10px;">修改申請內容</h3>
                            <p class="step-desc" style="color: #7f8c8d; margin-bottom: 20px;">請更新相關欄位後按送出。</p>

                            <div class="form-group">
                                <label for="organization_name">單位名稱 / 主辦社團 <span style="color:red">*</span></label>
                                <input type="text" id="organization_name" name="organization_name" class="form-control" placeholder="請輸入主辦單位名稱" value="<?php echo htmlspecialchars((string)($revisionData['organization_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="activity_name">活動名稱 <span style="color:red">*</span></label>
                                <input type="text" id="activity_name" name="activity_name" class="form-control" placeholder="請輸入活動名稱" value="<?php echo htmlspecialchars((string)($revisionData['activity_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>

                            <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 15px;">
                                <div class="form-group" style="flex: 1; min-width: 150px;">
                                    <label for="participant_count">活動對象人數 <span style="color:red">*</span></label>
                                    <select id="participant_count" name="participant_count" class="form-control" required style="padding: 8px;">
                                        <option value="" <?php echo (($revisionData['participant_count'] ?? '') === '') ? 'selected' : ''; ?>>請選擇</option>
                                        <option value="50人以下" <?php echo (($revisionData['participant_count'] ?? '') === '50人以下') ? 'selected' : ''; ?>>50人以下</option>
                                        <option value="50~100人" <?php echo (($revisionData['participant_count'] ?? '') === '50~100人') ? 'selected' : ''; ?>>50~100人</option>
                                        <option value="100~200人" <?php echo (($revisionData['participant_count'] ?? '') === '100~200人') ? 'selected' : ''; ?>>100~200人</option>
                                        <option value="200人以上" <?php echo (($revisionData['participant_count'] ?? '') === '200人以上') ? 'selected' : ''; ?>>200人以上</option>
                                    </select>
                                </div>
                                <div class="form-group" style="flex: 1; min-width: 150px;">
                                    <label for="staff_count">工作人員人數 <span style="color:red">*</span></label>
                                    <input type="number" id="staff_count" name="staff_count" class="form-control" placeholder="請輸入人數" min="1" value="<?php echo htmlspecialchars((string)($revisionData['staff_count'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                                </div>
                            </div>

                            <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 15px;">
                                <div class="form-group" style="flex: 1; min-width: 150px; margin-bottom: 0;">
                                    <label for="club_president">社 / 會長 <span style="color:red">*</span></label>
                                    <input type="text" id="club_president" name="club_president" class="form-control" placeholder="請輸入姓名" value="<?php echo htmlspecialchars((string)($revisionData['club_president'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                                </div>
                                <div class="form-group" style="flex: 1; min-width: 150px; margin-bottom: 0;">
                                    <label for="activity_coordinator">活動負責人<span style="color:red">*</span></label>
                                    <input type="text" id="activity_coordinator" name="activity_coordinator" class="form-control" placeholder="請輸入活動負責人姓名" value="<?php echo htmlspecialchars((string)($revisionData['activity_coordinator'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                            </div>

                            <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 15px;">
                                <div class="form-group" style="flex: 1; min-width: 150px; margin-bottom: 0;">
                                    <label for="coordinator_department">系級<span style="color:red">*</span></label>
                                    <input type="text" id="coordinator_department" name="coordinator_department" class="form-control" placeholder="請輸入系級" value="<?php echo htmlspecialchars((string)($revisionData['coordinator_department'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                                <div class="form-group" style="flex: 1; min-width: 150px; margin-bottom: 0;">
                                    <label for="coordinator_phone">聯絡電話<span style="color:red">*</span></label>
                                    <input type="text" id="coordinator_phone" name="coordinator_phone" class="form-control" placeholder="請輸入聯絡電話" value="<?php echo htmlspecialchars((string)($revisionData['coordinator_phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="coordinator_other_contact">其他聯絡方式</label>
                                <input type="text" id="coordinator_other_contact" name="coordinator_other_contact" class="form-control" placeholder="請輸入其他聯絡方式（如 Email）" value="<?php echo htmlspecialchars((string)($revisionData['coordinator_other_contact'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>

                            <div class="form-group" style="margin-top: 20px; border-top: 1px solid #ccc; padding-top: 15px;">
                                <label>特殊需求</label>
                                <div style="display: flex; gap: 20px; flex-wrap: wrap; align-items: center;">
                                    <label style="display: flex; align-items: center; gap: 8px; margin: 0;">
                                        <input type="checkbox" name="vehicle_entry" value="yes" <?php echo (($revisionData['vehicle_entry'] ?? '') === 'yes') ? 'checked' : ''; ?>>
                                        <span>需要車輛進場</span>
                                    </label>
                                    <div style="display:flex; align-items:center; gap:12px; margin-left: 8px;">
                                        <span style="font-weight:600;">插立旗幟</span>
                                        <label style="display:flex; align-items:center; gap:8px; margin:0;">
                                            <input type="radio" name="setup_flags" value="no" style="margin:0;" <?php echo (($revisionData['setup_flags'] ?? 'no') === 'no') ? 'checked' : ''; ?> onchange="toggleFlagDetailsAmend()"> 否
                                        </label>
                                        <label style="display:flex; align-items:center; gap:8px; margin:0;">
                                            <input type="radio" name="setup_flags" value="yes" style="margin:0;" <?php echo (($revisionData['setup_flags'] ?? '') === 'yes') ? 'checked' : ''; ?> onchange="toggleFlagDetailsAmend()"> 是
                                        </label>
                                    </div>
                                </div>

                                <div id="flagDetailsSectionAmend" style="display: none; margin-top:12px; background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:12px;">
                                    <div style="display:flex; gap:12px; align-items:center;">
                                        <label style="margin:0;">旗幟數量</label>
                                        <input type="number" name="flag_count" min="1" max="20" value="<?php echo htmlspecialchars((string)($revisionData['flag_count'] ?? 0), ENT_QUOTES, 'UTF-8'); ?>" style="width:100px;">
                                    </div>
                                    <div style="margin-top:8px;">
                                        <label for="flag_agreement" style="display:flex; align-items:center; gap:8px; margin:0;">
                                            <input type="checkbox" name="flag_agreement" id="flag_agreement" value="1" <?php echo (isset($revisionData['flag_agreement']) && $revisionData['flag_agreement']) ? 'checked' : ''; ?> style="width:18px; height:18px;">
                                            <span>我已閱讀並同意旗幟插立注意事項</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                                <div class="form-group" style="margin-top: 12px;">
                                    <label>特殊項目（請勾選適用項目）</label>
                                    <div style="display:flex; gap:20px; margin-top:8px; align-items:center;">
                                        <label style="display:flex; align-items:center; gap:8px; margin:0;">
                                            <input type="checkbox" name="has_fire" value="1" <?php echo (($revisionData['has_fire'] ?? '') === '1') ? 'checked' : ''; ?>>
                                            <span>明火</span>
                                        </label>
                                        <label style="display:flex; align-items:center; gap:8px; margin:0;">
                                            <input type="checkbox" name="has_alcohol" value="1" <?php echo (($revisionData['has_alcohol'] ?? '') === '1') ? 'checked' : ''; ?>>
                                            <span>含酒精</span>
                                        </label>
                                        <label style="display:flex; align-items:center; gap:8px; margin:0;">
                                            <input type="checkbox" name="has_sales" value="1" <?php echo (($revisionData['has_sales'] ?? '') === '1') ? 'checked' : ''; ?>>
                                            <span>販售活動</span>
                                        </label>
                                    </div>
                                </div>

                            <div class="form-group" style="margin-top: 15px;">
                                <label for="purpose">用途說明 <span style="color:red">*</span></label>
                                <textarea id="purpose" name="purpose" class="form-control" placeholder="請說明活動用途" required><?php echo htmlspecialchars((string)($revisionData['purpose'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                            </div>

                            <div class="form-group" style="margin-top: 15px;">
                                <label for="proposal_file">活動企劃書</label>
                                <input type="file" id="proposal_file" name="proposal_file" class="form-control" accept=".pdf,application/pdf">
                                <small style="display:block; margin-top:6px; color:#666;">若需補交或更新企劃書，請上傳 PDF 檔，大小上限 5MB。</small>
                                <?php if (!empty($revisionData['proposal_file'])): ?>
                                    <div style="margin-top:8px; font-size:13px; color:#444;">
                                        目前檔案：
                                        <a href="<?php echo htmlspecialchars((string)$revisionData['proposal_file'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">
                                            <?php echo htmlspecialchars(basename((string)$revisionData['proposal_file']), ENT_QUOTES, 'UTF-8'); ?>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-top: 20px;">
                                <div class="form-group" style="flex: 1; min-width: 150px; margin-bottom: 0;">
                                    <label for="borrow_start_at">借用開始時間</label>
                                    <input type="datetime-local" id="borrow_start_at" name="borrow_start_at" class="form-control" value="<?php echo htmlspecialchars((string)($revisionData['borrow_start_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" readonly style="background-color: #f5f5f5;">
                                </div>
                                <div class="form-group" style="flex: 1; min-width: 150px; margin-bottom: 0;">
                                    <label for="borrow_end_at">借用結束時間</label>
                                    <input type="datetime-local" id="borrow_end_at" name="borrow_end_at" class="form-control" value="<?php echo htmlspecialchars((string)($revisionData['borrow_end_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" readonly style="background-color: #f5f5f5;">
                                </div>
                            </div>

                            <?php if (!empty($revisionData['space_items'])): ?>
                                <div class="form-group" style="margin-top: 15px;">
                                    <label>預約空間</label>
                                    <div style="background: #f9f9f9; padding: 10px; border-radius: 4px; border: 1px solid #e0e0e0;">
                                        <?php foreach ($revisionData['space_items'] as $space): ?>
                                            <div style="padding: 8px 0; border-bottom: 1px solid #e0e0e0;">
                                                <strong><?php echo htmlspecialchars($space['space_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong>
                                                (容納人數: <?php echo htmlspecialchars((string)($space['capacity'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>)
                                                - 代號: <?php echo htmlspecialchars($space['space_id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($revisionData['equipment_items'])): ?>
                                <div class="form-group" style="margin-top: 15px;">
                                    <label>預約設備</label>
                                    <div style="background: #f9f9f9; padding: 10px; border-radius: 4px; border: 1px solid #e0e0e0;">
                                        <?php foreach ($revisionData['equipment_items'] as $equip): ?>
                                            <div style="padding: 8px 0; border-bottom: 1px solid #e0e0e0;">
                                                <strong><?php echo htmlspecialchars($equip['equipment_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong>
                                                - 代號: <?php echo htmlspecialchars($equip['equipment_code'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                                                | 數量: <?php echo htmlspecialchars((string)($equip['borrow_quantity'] ?? '1'), ENT_QUOTES, 'UTF-8'); ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div style="display: flex; gap: 10px; margin-top: 20px; justify-content: flex-end;">
                                <button type="button" class="btn-secondary" onclick="location.href='return_management.php'">取消</button>
                                <button type="submit" class="btn-primary">提交補件</button>
                            </div>
                        </form>
                    </section>
                <?php } ?>
            </section>
        </main>
    </div>
    <script>
        function toggleFlagDetailsAmend() {
            try {
                const yes = document.querySelector('input[name="setup_flags"][value="yes"]');
                const section = document.getElementById('flagDetailsSectionAmend');
                if (!section || !yes) return;
                section.style.display = yes.checked ? 'block' : 'none';
                if (yes.checked) {
                    const fc = section.querySelector('input[name="flag_count"]');
                    if (fc && (fc.value === '' || Number(fc.value) < 1)) fc.value = 1;
                }
            } catch (e) { /* ignore */ }
        }
        document.addEventListener('DOMContentLoaded', function(){
            toggleFlagDetailsAmend();
            // enforce min=1 on change and on submit
            const yes = document.querySelector('input[name="setup_flags"][value="yes"]');
            const no = document.querySelector('input[name="setup_flags"][value="no"]');
            const section = document.getElementById('flagDetailsSectionAmend');
            if (yes && no && section) {
                yes.addEventListener('change', toggleFlagDetailsAmend);
                no.addEventListener('change', toggleFlagDetailsAmend);
            }
            const form = document.querySelector('form[method="post"]');
            if (form) {
                form.addEventListener('submit', function(e){
                    try {
                        const yesChecked = document.querySelector('input[name="setup_flags"][value="yes"]') && document.querySelector('input[name="setup_flags"][value="yes"]').checked;
                        if (yesChecked) {
                            const fc = document.querySelector('#flagDetailsSectionAmend input[name="flag_count"]');
                            if (!fc || fc.value === '' || Number(fc.value) < 1) {
                                e.preventDefault();
                                if (fc) fc.value = 1;
                                alert('宣傳旗幟數量至少為 1 支');
                                if (fc) fc.focus();
                                return false;
                            }
                            const agree = document.querySelector('#flagDetailsSectionAmend input[name="flag_agreement"]');
                            if (!agree || !agree.checked) {
                                e.preventDefault();
                                alert('請勾選：我已閱讀並同意旗幟插立注意事項');
                                if (agree) agree.focus();
                                return false;
                            }
                        }
                    } catch (err) { /* ignore validation errors */ }
                });
            }
        });
    </script>
</body>
</html>