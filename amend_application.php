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
        SELECT eri.equipment_id, e.equipment_code, ec.equipment_name
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
        if (!empty($reservationRow['revision_data_json'])) {
            $revisionData = (array)json_decode($reservationRow['revision_data_json'], true) ?: [];
        }
        
        // Fallback 初始化，已補齊所有必備及漏掉的欄位
        if (empty($revisionData)) {
            $revisionData = [
                'organization_name'         => $reservationRow['organization_name'] ?? '',
                'activity_name'             => $reservationRow['activity_name'] ?? '',
                'participant_count'         => $reservationRow['participant_count'] ?? '',
                'staff_count'               => $reservationRow['staff_count'] ?? 0,
                'club_president'            => $reservationRow['club_president'] ?? '',
                'activity_coordinator'      => $reservationRow['activity_coordinator'] ?? '',
                'coordinator_department'    => $reservationRow['coordinator_department'] ?? '',
                'coordinator_phone'         => $reservationRow['coordinator_phone'] ?? '',
                'coordinator_other_contact' => $reservationRow['coordinator_other_contact'] ?? '',
                'vehicle_entry'             => $reservationRow['vehicle_entry'] ?? 'no',
                
                // 特殊勾選
                'has_alcohol'               => $reservationRow['has_alcohol'] ?? '0',
                'has_fire'                  => $reservationRow['has_fire'] ?? '0',
                'has_sales'                 => $reservationRow['has_sales'] ?? '0',
                
                // 路旗欄位
                'setup_flags'               => $reservationRow['setup_flags'] ?? 'no',
                'flag_count'                => $reservationRow['flag_count'] ?? 0,
                'flag_details'              => $reservationRow['flag_details'] ?? '',
                'flag_applicant_unit'       => $reservationRow['flag_applicant_unit'] ?? '',
                'flag_manager'              => $reservationRow['flag_manager'] ?? '',
                'flag_phone'                => $reservationRow['flag_phone'] ?? '',
                'flag_activity_name'        => $reservationRow['flag_activity_name'] ?? '',
                'flag_start_date'           => $reservationRow['flag_start_date'] ?? '',
                'flag_end_date'             => $reservationRow['flag_end_date'] ?? '',
                'flag_location'             => $reservationRow['flag_location'] ?? '',
                'flag_agreement'            => $reservationRow['flag_agreement'] ?? '0',
                
                'proposal_file'             => $reservationRow['proposal_file'] ?? '',
                'proposal_uploaded_at'      => $reservationRow['proposal_uploaded_at'] ?? '',
                'purpose'                   => $reservationRow['purpose'] ?? '',
                'borrow_start_at'           => $reservationRow['borrow_start_at'] ?? '',
                'borrow_end_at'             => $reservationRow['borrow_end_at'] ?? '',
                'space_id'                  => $reservationRow['space_id'] ?? '',
            ];
        }
        
        $revisionData['equipment_items'] = $equipmentItems;
        $revisionData['space_items'] = $spaceItems;
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // 收集並處理所有變更欄位（含路旗與勾選機制）
            $updatedFields = [
                'organization_name'         => trim((string)($_POST['organization_name'] ?? '')),
                'activity_name'             => trim((string)($_POST['activity_name'] ?? '')),
                'participant_count'         => trim((string)($_POST['participant_count'] ?? '')),
                'staff_count'               => (int)($_POST['staff_count'] ?? 0),
                'club_president'            => trim((string)($_POST['club_president'] ?? '')),
                'activity_coordinator'      => trim((string)($_POST['activity_coordinator'] ?? '')),
                'coordinator_department'    => trim((string)($_POST['coordinator_department'] ?? '')),
                'coordinator_phone'         => trim((string)($_POST['coordinator_phone'] ?? '')),
                'coordinator_other_contact' => trim((string)($_POST['coordinator_other_contact'] ?? '')),
                'vehicle_entry'             => isset($_POST['vehicle_entry']) ? 'yes' : 'no',
                
                // 補齊特殊活動勾選處理
                'has_alcohol'               => isset($_POST['has_alcohol']) ? '1' : '0',
                'has_fire'                  => isset($_POST['has_fire']) ? '1' : '0',
                'has_sales'                 => isset($_POST['has_sales']) ? '1' : '0',
                
                // 補齊路旗相關 POST 處理
                'setup_flags'               => trim((string)($_POST['setup_flags'] ?? 'no')),
                'flag_count'                => trim((string)($_POST['flag_count'] ?? '')),
                'flag_details'              => trim((string)($_POST['flag_details'] ?? '')),
                'flag_applicant_unit'       => trim((string)($_POST['flag_applicant_unit'] ?? '')),
                'flag_manager'              => trim((string)($_POST['flag_manager'] ?? '')),
                'flag_phone'                => trim((string)($_POST['flag_phone'] ?? '')),
                'flag_activity_name'        => trim((string)($_POST['flag_activity_name'] ?? '')),
                'flag_start_date'           => trim((string)($_POST['flag_start_date'] ?? '')),
                'flag_end_date'             => trim((string)($_POST['flag_end_date'] ?? '')),
                'flag_location'             => trim((string)($_POST['flag_location'] ?? '')),
                'flag_agreement'            => isset($_POST['flag_agreement']) ? '1' : '0',
                
                'purpose'                   => trim((string)($_POST['purpose'] ?? '')),
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
                            if ($key === 'flag_count') {
                                $value = ($updatedFields['setup_flags'] ?? 'no') === 'yes' && $value !== '' ? (int)$value : null;
                            }
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

                    // 重新計算並更新例假日費用（若資料表有相關欄位）
                    try {
                        $HOLIDAY_RATE = 200;
                        $feeCount = 0;
                        $feeAmount = 0;
                        $fetchStmt = mysqli_prepare($link, 'SELECT borrow_start_at, borrow_end_at FROM reservations WHERE reservation_id = ? LIMIT 1');
                        if ($fetchStmt) {
                            mysqli_stmt_bind_param($fetchStmt, 'i', $reservationId);
                            mysqli_stmt_execute($fetchStmt);
                            $fres = mysqli_stmt_get_result($fetchStmt);
                            $frow = $fres ? mysqli_fetch_assoc($fres) : null;
                            mysqli_stmt_close($fetchStmt);
                            if ($frow && !empty($frow['borrow_start_at']) && !empty($frow['borrow_end_at'])) {
                                $start = date('Y-m-d', strtotime($frow['borrow_start_at']));
                                $end = date('Y-m-d', strtotime($frow['borrow_end_at']));
                                $startDate = DateTime::createFromFormat('Y-m-d', $start);
                                $endDate = DateTime::createFromFormat('Y-m-d', $end);
                                if ($startDate && $endDate && $startDate <= $endDate) {
                                    $holidayDates = [];
                                    $holTableRes = mysqli_query($link, "SHOW TABLES LIKE 'holidays'");
                                    if ($holTableRes && mysqli_num_rows($holTableRes) > 0) {
                                        $safeStart = mysqli_real_escape_string($link, $startDate->format('Y-m-d'));
                                        $safeEnd = mysqli_real_escape_string($link, $endDate->format('Y-m-d'));
                                        $holRes = mysqli_query($link, "SELECT `date` FROM `holidays` WHERE `date` BETWEEN '{$safeStart}' AND '{$safeEnd}'");
                                        if ($holRes) {
                                            while ($h = mysqli_fetch_assoc($holRes)) { $holidayDates[] = $h['date']; }
                                        }
                                    }
                                    $d = clone $startDate;
                                    while ($d <= $endDate) {
                                        $ymd = $d->format('Y-m-d');
                                        $weekday = (int)$d->format('w');
                                        $isHoliday = in_array($ymd, $holidayDates, true);
                                        if (empty($holidayDates) && ($weekday === 0 || $weekday === 6)) $isHoliday = true;
                                        if ($isHoliday) $feeCount++;
                                        $d->modify('+1 day');
                                    }
                                    $feeAmount = $feeCount * $HOLIDAY_RATE;
                                }
                            }
                        }
                        if (in_array('holiday_fee', $availableCols, true) || in_array('holiday_fee_count', $availableCols, true)) {
                            $updCols = [];
                            $types = '';
                            $vals = [];
                            if (in_array('holiday_fee_count', $availableCols, true)) { $updCols[] = 'holiday_fee_count = ?'; $types .= 'i'; $vals[] = $feeCount; }
                            if (in_array('holiday_fee', $availableCols, true)) { $updCols[] = 'holiday_fee = ?'; $types .= 'i'; $vals[] = $feeAmount; }
                            if (!empty($updCols)) {
                                $vals[] = $reservationId; $types .= 'i';
                                $updSql = 'UPDATE reservations SET ' . implode(', ', $updCols) . ' WHERE reservation_id = ?';
                                $updStmt = mysqli_prepare($link, $updSql);
                                if ($updStmt) {
                                    mysqli_stmt_bind_param($updStmt, $types, ...$vals);
                                    mysqli_stmt_execute($updStmt);
                                    mysqli_stmt_close($updStmt);
                                }
                            }
                        }
                    } catch (Throwable $e) {
                        @error_log('Holiday fee update failed for reservation ' . $reservationId . ': ' . $e->getMessage());
                    }
                    
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
            // 寄送修改成功通知（含需繳金額）
            if ($amendSuccess !== '' && isset($reservationId) && $reservationId > 0) {
                $userEmail = null;
                $userEmailStmt = mysqli_prepare($link, 'SELECT email FROM users WHERE user_id = ? LIMIT 1');
                if ($userEmailStmt) {
                    mysqli_stmt_bind_param($userEmailStmt, 's', $currentUserId);
                    mysqli_stmt_execute($userEmailStmt);
                    $ures = mysqli_stmt_get_result($userEmailStmt);
                    if ($urow = mysqli_fetch_assoc($ures)) {
                        $userEmail = $urow['email'];
                    }
                    mysqli_stmt_close($userEmailStmt);
                }
                if (!empty($userEmail)) {
                    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
                        require_once __DIR__ . '/lib/PHPMailer/Exception.php';
                        require_once __DIR__ . '/lib/PHPMailer/PHPMailer.php';
                        require_once __DIR__ . '/lib/PHPMailer/SMTP.php';
                    }
                    require_once __DIR__ . '/config/mail.php';
                    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                    try {
                        if (empty($MAIL_ENABLED) || empty($MAIL_USERNAME) || empty($MAIL_PASSWORD)) {
                            throw new RuntimeException('郵件設定未啟用或未完成，請檢查 config/mail.php');
                        }
                        $mailFrom = !empty($MAIL_FROM) ? $MAIL_FROM : $MAIL_USERNAME;
                        $mail->isSMTP();
                        $mail->Host       = 'smtp.gmail.com';
                        $mail->SMTPAuth   = true;
                        $mail->Username   = $MAIL_USERNAME;
                        $mail->Password   = $MAIL_PASSWORD;
                        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                        $mail->Port       = 465;
                        $mail->CharSet    = 'UTF-8';
                        $mail->setFrom($mailFrom, $MAIL_FROM_NAME ?? '器材借用系統');
                        $mail->addAddress($userEmail, $displayName);
                        $mail->isHTML(true);
                        $mail->Subject = '【系統通知】申請修改已提交';

                        // 取得此申請的 holiday_fee 總和
                        $totalDue = 0;
                        $feeSql = "SELECT COALESCE(SUM(holiday_fee),0) AS total_due FROM reservations WHERE reservation_id = " . intval($reservationId);
                        $feeRes = mysqli_query($link, $feeSql);
                        if ($feeRes) { $frow = mysqli_fetch_assoc($feeRes); $totalDue = isset($frow['total_due']) ? (int)$frow['total_due'] : 0; }

                        if ($totalDue > 0) {
                            $mail->Body = "您好，{$displayName}：<br><br>您的申請（單號：{$reservationId}）已修改成功，目前狀態為<b>「審核中」</b>。<br><br>※ 本次申請需繳費：<b>新台幣 {$totalDue} 元</b>。<br><br>管理團隊將儘速處理，審核結果會再通知您。<br><br>感謝您的使用！";
                            $mail->AltBody = "您好，{$displayName}：\n\n您的申請（單號：{$reservationId}）已修改成功，目前狀態為「審核中」。\n\n※ 本次申請需繳費：新台幣 {$totalDue} 元。\n\n管理團隊將儘速處理，審核結果會再通知您。\n\n感謝您的使用！";
                        } else {
                            $mail->Body = "您好，{$displayName}：<br><br>您的申請（單號：{$reservationId}）已修改成功，目前狀態為<b>「審核中」</b>。管理團隊將儘速處理，審核結果會再通知您。<br><br>感謝您的使用！";
                            $mail->AltBody = "您好，{$displayName}：\n\n您的申請（單號：{$reservationId}）已修改成功，目前狀態為「審核中」。管理團隊將儘速處理，審核結果會再通知您。\n\n感謝您的使用！";
                        }
                        $mail->send();
                        @file_put_contents(__DIR__ . '/borrow_debug.log', date('c') . " 補件/修改成功通知已寄送至: " . $userEmail . "\n", FILE_APPEND | LOCK_EX);
                    } catch (Exception $e) {
                        @file_put_contents(__DIR__ . '/borrow_debug.log', date('c') . " 補件/修改成功通知寄送失敗 (to: {$userEmail}): " . $e->getMessage() . " | ErrorInfo: " . $mail->ErrorInfo . "\n", FILE_APPEND | LOCK_EX);
                    }
                }
            }
    }
}

// 頁面用的假日清單（JS 端會用到，若存在 holidays 資料表則輸出）
$pageHolidayDates = [];
if ($dbError === '') {
    $holTableRes = mysqli_query($link, "SHOW TABLES LIKE 'holidays'");
    if ($holTableRes && mysqli_num_rows($holTableRes) > 0) {
        $hres = mysqli_query($link, "SELECT `date` FROM `holidays` ORDER BY `date` ASC");
        if ($hres) {
            while ($hrow = mysqli_fetch_assoc($hres)) {
                $pageHolidayDates[] = $hrow['date'];
            }
            mysqli_free_result($hres);
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
    <!-- 導覽列 放到 container 之外以讓背景拉滿整個視窗 -->
        <?php include __DIR__ . '/nav.php'; ?>

    <div class="container">

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
                                    <label style="display: flex; align-items: center; gap: 8px; margin: 0;">
                                        <input type="checkbox" name="has_alcohol" value="1" <?php echo (($revisionData['has_alcohol'] ?? '0') === '1') ? 'checked' : ''; ?>>
                                        <span>有酒精</span>
                                    </label>
                                    <label style="display: flex; align-items: center; gap: 8px; margin: 0;">
                                        <input type="checkbox" name="has_fire" value="1" <?php echo (($revisionData['has_fire'] ?? '0') === '1') ? 'checked' : ''; ?>>
                                        <span>有明火</span>
                                    </label>
                                    <label style="display: flex; align-items: center; gap: 8px; margin: 0;">
                                        <input type="checkbox" name="has_sales" value="1" <?php echo (($revisionData['has_sales'] ?? '0') === '1') ? 'checked' : ''; ?>>
                                        <span>需擺攤販售</span>
                                    </label>
                                </div>
                            </div>
                                <div class="form-group" style="margin-top: 12px;">
                                    <label>特殊項目（請勾選適用項目）</label>
                                    <div style="display:flex; gap:20px; margin-top:8px; align-items:center;">
                                        <label style="display:flex; align-items:center; gap:8px; margin:0;">
                                            <input type="checkbox" name="has_fire" value="1" >
                                            <span>明火</span>
                                        </label>
                                        <label style="display:flex; align-items:center; gap:8px; margin:0;">
                                            <input type="checkbox" name="has_alcohol" value="1" >
                                            <span>含</span>
                                        </label>
                                        <label style="display:flex; align-items:center; gap:8px; margin:0;">
                                            <input type="checkbox" name="has_sales" value="1" >
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

                            <div class="form-group" style="margin-top:10px;">
                                <label>例假日加收</label>
                                <div id="holiday-fee-display" style="padding:8px; background:#fff; border:1px solid #e6e6e6; border-radius:6px;">例假日收場地費 200 元/次。已選 0 天，費用：0 元</div>
                                <input type="hidden" name="holiday_fee_count" id="holiday_fee_count" value="<?php echo isset($revisionData['holiday_fee_count'])?(int)$revisionData['holiday_fee_count']:0; ?>">
                                <input type="hidden" name="holiday_fee" id="holiday_fee" value="<?php echo isset($revisionData['holiday_fee'])?(int)$revisionData['holiday_fee']:0; ?>">
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
                                                | 器材編號: <?php echo htmlspecialchars((string)($equip['equipment_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
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
            } catch (e) { /* ignore */ }
        }
        document.addEventListener('DOMContentLoaded', function(){
            toggleFlagDetailsAmend();
        });
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', function(){
            function countHolidayDaysJS(startStr, endStr) {
                if (!startStr || !endStr) return 0;
                const s = new Date(startStr);
                const e = new Date(endStr);
                if (isNaN(s.getTime()) || isNaN(e.getTime()) || s > e) return 0;
                const holidays = (window.__PAGE_HOLIDAYS__ || []);
                let cnt = 0;
                for (let d = new Date(s.getFullYear(), s.getMonth(), s.getDate()); d <= e; d.setDate(d.getDate()+1)) {
                    const y = d.getFullYear();
                    const m = String(d.getMonth()+1).padStart(2,'0');
                    const day = String(d.getDate()).padStart(2,'0');
                    const key = `${y}-${m}-${day}`;
                    if (holidays.length > 0) {
                        if (holidays.indexOf(key) !== -1) cnt++;
                    } else {
                        const wd = d.getDay(); if (wd === 0 || wd === 6) cnt++;
                    }
                }
                return cnt;
            }

            function getStartDate() {
                const d1 = document.getElementById('borrow_start_date');
                if (d1 && d1.value) return d1.value;
                const dt = document.getElementById('borrow_start_at');
                if (dt && dt.value) return dt.value.substring(0,10);
                return '';
            }
            function getEndDate() {
                const d1 = document.getElementById('borrow_end_date');
                if (d1 && d1.value) return d1.value;
                const dt = document.getElementById('borrow_end_at');
                if (dt && dt.value) return dt.value.substring(0,10);
                return '';
            }

            function updateHolidayFeeDisplay() {
                const start = getStartDate();
                const end = getEndDate();
                const cnt = countHolidayDaysJS(start, end);
                const fee = cnt * 200;
                const disp = document.getElementById('holiday-fee-display');
                if (disp) disp.textContent = `例假日收場地費 200 元/次。已選 ${cnt} 天，費用：${fee} 元`;
                const hfCnt = document.getElementById('holiday_fee_count');
                const hf = document.getElementById('holiday_fee');
                if (hfCnt) hfCnt.value = cnt;
                if (hf) hf.value = fee;
            }

            try { window.__PAGE_HOLIDAYS__ = <?php echo json_encode($pageHolidayDates, JSON_HEX_TAG); ?> || []; } catch(e){ window.__PAGE_HOLIDAYS__ = []; }

            document.getElementById('borrow_start_at')?.addEventListener('change', updateHolidayFeeDisplay);
            document.getElementById('borrow_end_at')?.addEventListener('change', updateHolidayFeeDisplay);
            document.getElementById('borrow_start_date')?.addEventListener('change', updateHolidayFeeDisplay);
            document.getElementById('borrow_end_date')?.addEventListener('change', updateHolidayFeeDisplay);
            updateHolidayFeeDisplay();
        });
    </script>
</body>
</html>