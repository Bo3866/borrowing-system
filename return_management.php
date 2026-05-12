<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config/database.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'lib/PHPMailer/PHPMailer.php';
require 'lib/PHPMailer/SMTP.php';
require 'lib/PHPMailer/Exception.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?next=return_management.php');
    exit;
}

$currentUserId = (string)$_SESSION['user_id'];

$dbError = '';
$link = getMysqliConnection($dbError);

function pickExistingColumn(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

$actionMsg = '';
$rows = [];

if ($dbError === '') {
    $reservationColumns = [];
    $columnResult = mysqli_query($link, 'SHOW COLUMNS FROM reservations');
    if ($columnResult) {
        while ($columnRow = mysqli_fetch_assoc($columnResult)) {
            $reservationColumns[] = (string)$columnRow['Field'];
        }
    }

    // 固定使用 `user_id` 作為申請人欄位
    $applicantColumn = 'user_id';
    if (!in_array($applicantColumn, $reservationColumns, true)) {
        $dbError = 'reservations 缺少 user_id 欄位，無法關聯 users 資料。';
    }

    $borrowStartColumn = pickExistingColumn($reservationColumns, ['borrow_start_at', 'borrow_start_time']);
    $borrowEndColumn = pickExistingColumn($reservationColumns, ['borrow_end_at', 'borrow_ene_at', 'borrow_end_time']);
    if ($dbError === '' && ($borrowStartColumn === null || $borrowEndColumn === null)) {
        $dbError = 'reservations 缺少借用起訖欄位，請確認 borrow_start_at / borrow_end_at（或 borrow_ene_at）。';
    }

    // 自動遷移：確保 returned_at 欄位存在
    if ($dbError === '') {
        try {
            if (!in_array('returned_at', $reservationColumns, true)) {
                $migrationSql = 'ALTER TABLE reservations ADD COLUMN returned_at DATETIME NULL COMMENT "歸還完成時間"';
                mysqli_query($link, $migrationSql);
                $reservationColumns[] = 'returned_at';
            }
        } catch (Throwable $e) {
            // 忽略列已存在錯誤
        }
    }

    // 自動遷移：確保 rejection_reason 欄位存在
    if ($dbError === '') {
        try {
            if (!in_array('rejection_reason', $reservationColumns, true)) {
                $migrationSql = 'ALTER TABLE reservations ADD COLUMN rejection_reason VARCHAR(500) NULL COMMENT "拒絕原因"';
                mysqli_query($link, $migrationSql);
                $reservationColumns[] = 'rejection_reason';
            }
        } catch (Throwable $e) {
            // 忽略列已存在錯誤
        }
    }

    // 自動遷移：擴充 approval_status 與新增 revision_deadline
    if ($dbError === '') {
        try {
            if (!in_array('revision_deadline', $reservationColumns, true)) {
                $migrationSql1 = "ALTER TABLE reservations MODIFY COLUMN approval_status ENUM('pending', 'approved', 'rejected', 'need_revision', 'revision_overdue') NOT NULL DEFAULT 'pending'";
                mysqli_query($link, $migrationSql1);
                
                $migrationSql2 = 'ALTER TABLE reservations ADD COLUMN revision_deadline DATETIME NULL COMMENT "補件期限" AFTER approval_status';
                mysqli_query($link, $migrationSql2);
                $reservationColumns[] = 'revision_deadline';
            }
        } catch (Throwable $e) {
            // 忽略列已存在錯誤
        }
    }

    // 報到狀態：使用 checkin_logs（無需再查 pickup 欄位）
    // 歸還狀態：使用 returned_at 欄位

    if ($dbError === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');

        try {
            if (in_array($action, ['confirm_return'], true)) {
                $reservationId = (int)($_POST['reservation_id'] ?? 0);
                if ($reservationId <= 0) {
                    throw new RuntimeException('無效的申請編號。');
                }

                $ownershipStmt = mysqli_prepare(
                    $link,
                    'SELECT r.reservation_id, r.returned_at, cl.checked_in_at
                     FROM reservations r
                     LEFT JOIN checkin_logs cl
                       ON cl.reservation_id = r.reservation_id
                      AND cl.user_id COLLATE utf8mb4_unicode_ci = r.`' . $applicantColumn . '` COLLATE utf8mb4_unicode_ci
                     WHERE r.reservation_id = ?
                       AND r.`' . $applicantColumn . '` COLLATE utf8mb4_unicode_ci = ?
                     LIMIT 1'
                );
                if (!$ownershipStmt) {
                    throw new RuntimeException('驗證申請權限失敗：' . mysqli_error($link));
                }

                mysqli_stmt_bind_param($ownershipStmt, 'is', $reservationId, $currentUserId);
                mysqli_stmt_execute($ownershipStmt);
                $ownershipResult = mysqli_stmt_get_result($ownershipStmt);
                $reservationRow = $ownershipResult ? mysqli_fetch_assoc($ownershipResult) : null;
                mysqli_stmt_close($ownershipStmt);

                if (!$reservationRow) {
                    throw new RuntimeException('找不到可操作的申請資料，或此申請不屬於目前使用者。');
                }

                if (empty($reservationRow['checked_in_at'])) {
                    throw new RuntimeException('尚未報到，無法確認歸還或離場。');
                }

                if (!empty($reservationRow['returned_at'])) {
                    throw new RuntimeException('此申請已完成歸還或離場。');
                }

                mysqli_begin_transaction($link);

                if ($action === 'confirm_return') {
                    // 更新歸還時間
                    $returnSql = 'UPDATE reservations SET returned_at = COALESCE(returned_at, NOW()) WHERE reservation_id = ?';
                    $returnStmt = mysqli_prepare($link, $returnSql);
                    if (!$returnStmt) {
                        throw new RuntimeException('更新歸還狀態失敗：' . mysqli_error($link));
                    }
                    mysqli_stmt_bind_param($returnStmt, 'i', $reservationId);
                    mysqli_stmt_execute($returnStmt);
                    mysqli_stmt_close($returnStmt);

                    // 還原借出的器材狀態
                    $restoreStmt = mysqli_prepare(
                        $link,
                        'UPDATE equipments e
                         JOIN equipment_reservation_items eri ON eri.equipment_id = e.equipment_id
                         SET e.operation_status = 1
                         WHERE eri.reservation_id = ? AND e.operation_status = 2'
                    );
                    if (!$restoreStmt) {
                        throw new RuntimeException('還原器材可借狀態失敗：' . mysqli_error($link));
                    }
                    mysqli_stmt_bind_param($restoreStmt, 'i', $reservationId);
                    mysqli_stmt_execute($restoreStmt);
                    mysqli_stmt_close($restoreStmt);

                    // 還原場地狀態（若原先標為 2 已借出，則改回 1 可借用）
                    $restoreSpaceStmt = mysqli_prepare(
                        $link,
                        'UPDATE spaces s
                         JOIN space_reservation_items sri ON s.space_id = sri.space_id
                         SET s.space_status = "1"
                         WHERE sri.reservation_id = ? AND s.space_status = "2"'
                    );
                    if (!$restoreSpaceStmt) {
                        throw new RuntimeException('還原場地可借狀態失敗：' . mysqli_error($link));
                    }
                    mysqli_stmt_bind_param($restoreSpaceStmt, 'i', $reservationId);
                    mysqli_stmt_execute($restoreSpaceStmt);
                    mysqli_stmt_close($restoreSpaceStmt);

                    $actionMsg = '已確認歸還／離場。';
                }

                mysqli_commit($link);

                    // 寄送歸還通知信給申請人（非必要失敗不回滾）
                    try {
                        $emailStmt = mysqli_prepare(
                            $link,
                            'SELECT u.email, u.full_name FROM reservations r JOIN users u ON u.user_id = r.user_id WHERE r.reservation_id = ? LIMIT 1'
                        );
                        if ($emailStmt) {
                            mysqli_stmt_bind_param($emailStmt, 'i', $reservationId);
                            mysqli_stmt_execute($emailStmt);
                            $emailRes = mysqli_stmt_get_result($emailStmt);
                            $userRow = $emailRes ? mysqli_fetch_assoc($emailRes) : null;
                            mysqli_stmt_close($emailStmt);

                            if ($userRow && !empty($userRow['email'])) {
                                $mail = new PHPMailer(true);
                                try {
                                    $mail->isSMTP();
                                    $mail->Host       = 'smtp.gmail.com';
                                    $mail->SMTPAuth   = true;
                                    $mail->Username   = 'sasass041919@gmail.com';
                                    $mail->Password   = 'xogusuplsoapxayc';
                                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                                    $mail->Port       = 465;
                                    $mail->CharSet    = 'UTF-8';

                                    $mail->setFrom('sasass041919@gmail.com', '器材借用系統');
                                    $mail->addAddress($userRow['email'], $userRow['full_name']);

                                    $mail->isHTML(true);
                                    $mail->Subject = '已確認歸還／離場';
                                    $mail->Body    = "您好：<br><br>您的借用申請（編號：{$reservationId}）已確認歸還／離場。<br><br>感謝您！";
                                    $mail->AltBody = "您好：\n\n您的借用申請（編號：{$reservationId}）已確認歸還／離場。\n\n感謝您！";

                                    $mail->send();
                                    $actionMsg .= ' 已寄出歸還通知信。';
                                } catch (Exception $e) {
                                    $actionMsg .= " 但歸還通知信寄送失敗： {$mail->ErrorInfo}";
                                }
                            }
                        }
                    } catch (Throwable $e) {
                        // 不要因為通知失敗而回滾主要交易
                        $actionMsg .= '（歸還通知信處理時發生錯誤）';
                    }
            }
        } catch (Throwable $e) {
            mysqli_rollback($link);
            $actionMsg = '處理失敗：' . $e->getMessage();
        }
    }

    if ($dbError === '') {
        // 顯示所有申請狀態（待審、已批准、已拒絕、待補件、補件逾期）
        $listWhere = "r.approval_status IN ('pending', 'approved', 'rejected', 'need_revision', 'revision_overdue')";
        $safeUserId = mysqli_real_escape_string($link, $currentUserId);
        $listWhere .= " AND r.`{$applicantColumn}` = '{$safeUserId}'";

        // 更新逾期的自動狀態 (包含舊的缺漏deadline的測試資料)
        $updateOverdueSql = "UPDATE reservations SET approval_status = 'revision_overdue' WHERE approval_status = 'need_revision' AND (revision_deadline IS NULL OR revision_deadline < NOW())";
        mysqli_query($link, $updateOverdueSql);

        // 查詢邏輯：
        // - pickup_confirmed: 使用 checkin_logs.checked_in_at 判斷（NULL = 未報到，NOT NULL = 已報到）
        // - return_confirmed: 使用 reservations.returned_at 判斷（NULL = 未歸還，NOT NULL = 已歸還）
        $listSql = "
            SELECT
                r.reservation_id,
                r.`{$applicantColumn}` AS applicant_user_id,
                u.full_name,
                u.email,
                r.`{$borrowStartColumn}` AS borrow_start_at,
                r.`{$borrowEndColumn}` AS borrow_end_at,
                r.approval_status,
                r.approval_stage,
                r.rejection_reason,
                r.revision_deadline,
                r.submitted_at,
                r.updated_at,
                (cl.checked_in_at IS NOT NULL) AS pickup_confirmed,
                cl.checked_in_at AS pickup_confirmed_at,
                (r.returned_at IS NOT NULL) AS return_confirmed,
                r.returned_at AS return_confirmed_at,
                (
                    SELECT GROUP_CONCAT(DISTINCT ec.equipment_code ORDER BY ec.equipment_code SEPARATOR ', ')
                    FROM equipment_reservation_items eri
                    JOIN equipments e ON e.equipment_id = eri.equipment_id
                    JOIN equipment_categories ec ON ec.equipment_code = e.equipment_code
                    WHERE eri.reservation_id = r.reservation_id
                ) AS equipment_codes,
                (
                    SELECT GROUP_CONCAT(DISTINCT sri.space_id ORDER BY sri.space_id SEPARATOR ', ')
                    FROM space_reservation_items sri
                    WHERE sri.reservation_id = r.reservation_id
                ) AS space_ids,
                (
                    SELECT GROUP_CONCAT(DISTINCT s.space_name ORDER BY s.space_name SEPARATOR ', ')
                    FROM space_reservation_items sri
                    JOIN spaces s ON s.space_id = sri.space_id
                    WHERE sri.reservation_id = r.reservation_id
                ) AS space_names
            FROM reservations r
            JOIN users u ON u.user_id COLLATE utf8mb4_unicode_ci = r.`{$applicantColumn}` COLLATE utf8mb4_unicode_ci
            LEFT JOIN checkin_logs cl ON cl.reservation_id = r.reservation_id AND cl.user_id COLLATE utf8mb4_unicode_ci = r.`{$applicantColumn}` COLLATE utf8mb4_unicode_ci
            WHERE {$listWhere}
            ORDER BY r.`{$borrowEndColumn}` DESC
            LIMIT 300
        ";

        $listResult = mysqli_query($link, $listSql);
        if ($listResult) {
            while ($row = mysqli_fetch_assoc($listResult)) {
                $rows[] = $row;
            }
        } else {
            $dbError = '讀取借還管理資料失敗：' . mysqli_error($link);
        }
    }
}

// Attach per-reservation approval log info (timestamps and results) for UI
if ($dbError === '' && count($rows) > 0) {
    foreach ($rows as $idx => $r) {
        $reservationId = (int)$r['reservation_id'];
        $rows[$idx]['_stage_times'] = [];
        $rows[$idx]['_stage_results'] = [];
        $rows[$idx]['_stage_comments'] = [];

        $logStmt = mysqli_prepare($link, 'SELECT al.reviewed_at, al.review_result, al.review_comment, u.role_name FROM approval_logs al JOIN users u ON u.user_id = al.reviewer_id WHERE al.reservation_id = ? ORDER BY al.reviewed_at ASC');
        if ($logStmt) {
            mysqli_stmt_bind_param($logStmt, 'i', $reservationId);
            mysqli_stmt_execute($logStmt);
            $logRes = mysqli_stmt_get_result($logStmt);
            if ($logRes) {
                while ($logRow = mysqli_fetch_assoc($logRes)) {
                    $role = (string)($logRow['role_name'] ?? '');
                    $reviewedAt = $logRow['reviewed_at'] ?? null;
                    $result = $logRow['review_result'] ?? null;
                    $comment = $logRow['review_comment'] ?? null;
                    if ($role !== '') {
                        // normalize roles '2' acting as '3' handled elsewhere
                        $rows[$idx]['_stage_times'][$role] = $reviewedAt;
                        $rows[$idx]['_stage_results'][$role] = $result;
                        $rows[$idx]['_stage_comments'][$role] = $comment;
                    }
                }
            }
            mysqli_stmt_close($logStmt);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>借還管理｜校園資源租借系統</title>
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
    <style>
        /* Prevent step labels from wrapping */
        .stepper-text { white-space: nowrap; }
        .stepper-subtext { white-space: nowrap; }
    </style>
</head>
<body>
    <div class="container">
        <nav class="navbar">
            <div class="navbar-brand"><h1>📚 校園資源租借系統</h1></div>
            <div class="navbar-menu">
                <button class="nav-btn" onclick="location.href='index.php'">回首頁</button>
                <button class="nav-btn" onclick="location.href='report_maintenance.php'">報修</button>
                <button class="nav-btn" type="button" disabled><?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['user_id'], ENT_QUOTES, 'UTF-8'); ?></button>
                <button class="nav-btn" onclick="location.href='logout.php'">登出</button>
            </div>
        </nav>

        <main class="main-content">
            <section class="card">
                <h2>我的申請紀錄</h2>

                <?php if ($actionMsg !== '') { ?>
                    <div class="borrow-success"><?php echo htmlspecialchars($actionMsg, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } ?>

                <?php if ($dbError !== '') { ?>
                    <div class="login-alert"><?php echo htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } else { ?>
                    <div class="borrow-table-wrapper">
                        <table class="management-table return-management-table">
                            <thead>
                                <tr>
                                    <th style="width: 30px;"></th>
                                    <th>申請人</th>
                                    <th>借用時段</th>
                                    <th>借用項目</th>
                                    <th>是否已報到</th>
                                    <th>歸還</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($rows) === 0) { ?>
                                    <tr><td colspan="6">目前沒有可顯示的申請資料。</td></tr>
                                <?php } else { ?>
                                    <?php foreach ($rows as $row) { ?>
                                        <?php
                                            $resourceParts = [];
                                            if (!empty($row['equipment_codes'])) {
                                                $resourceParts[] = '器材: ' . $row['equipment_codes'];
                                            }
                                            if (!empty($row['space_ids'])) {
                                                $resourceParts[] = '空間: ' . $row['space_ids'];
                                            }
                                            $resourceText = count($resourceParts) > 0 ? implode(' | ', $resourceParts) : '-';
                                            $isPickup = (int)$row['pickup_confirmed'] === 1;
                                            $isReturned = (int)$row['return_confirmed'] === 1;
                                            $approvalStatus = (string)$row['approval_status'];
                                            $approvalStage = (string)($row['approval_stage'] ?? 'a');

                                            // Map approval stages to step indices:
                                            // 1 = 申請送出
                                            // 2 = 學務長 (a)
                                            // 3 = 軍訓室 (b)
                                            // 4 = 輔導人員 (c)
                                            // 5 = 課指組 (d)  <-- final approval now
                                            // 6 = 使用中
                                            // 7 = 已歸還
                                            $stageMap = ['a' => 2, 'b' => 3, 'c' => 4, 'd' => 5];

                                            if ($approvalStatus === 'rejected' || $approvalStatus === 'revision_overdue') {
                                                $progressStatus = 0; // 已拒絕或逾期視為失敗
                                            } elseif ($approvalStatus === 'need_revision') {
                                                // 停留在目前審核階段
                                                $progressStatus = $stageMap[$approvalStage] ?? 2;
                                            } elseif ($approvalStatus === 'pending') {
                                                // 依據目前 approval_stage 顯示在哪一階段等待審核
                                                $progressStatus = $stageMap[$approvalStage] ?? 2;
                                            } elseif ($approvalStatus === 'approved') {
                                                if ($isReturned) {
                                                    $progressStatus = 7; // 已歸還
                                                } elseif ($isPickup) {
                                                    $progressStatus = 6; // 使用中
                                                } else {
                                                    $progressStatus = 5; // 所有審核完成（課指組已通過）
                                                }
                                            } else {
                                                $progressStatus = 1; // 預設：剛送出
                                            }
                                        ?>
                                        <tr class="accordion-trigger" onclick="toggleAccordion(this, <?php echo (int)$row['reservation_id']; ?>)">
                                            <td style="text-align: center; cursor: pointer;">
                                                <span class="accordion-icon">▶</span>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($row['full_name'] . ' (' . $row['applicant_user_id'] . ')', ENT_QUOTES, 'UTF-8'); ?><br>
                                                <small><?php echo htmlspecialchars((string)$row['email'], ENT_QUOTES, 'UTF-8'); ?></small>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars((string)$row['borrow_start_at'], ENT_QUOTES, 'UTF-8'); ?><br>
                                                ～ <?php echo htmlspecialchars((string)$row['borrow_end_at'], ENT_QUOTES, 'UTF-8'); ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($resourceText, ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td>
                                                <?php
                                                    $isPickup = (int)$row['pickup_confirmed'] === 1;
                                                    $checkinTime = $row['pickup_confirmed_at'];
                                                ?>
                                                <span class="return-status <?php echo $isPickup ? 'return-status-ok' : 'return-status-pending'; ?>">
                                                    <?php echo $isPickup ? '已報到' : '未報到'; ?>
                                                </span>
                                                <?php if ($isPickup) { ?>
                                                    <br><small>時間: <?php echo htmlspecialchars((string)$checkinTime, ENT_QUOTES, 'UTF-8'); ?></small>
                                                <?php } ?>
                                            </td>
                                            <td>
                                                <?php
                                                    $isReturned = (int)$row['return_confirmed'] === 1;
                                                    $returnTime = $row['return_confirmed_at'];
                                                ?>
                                                <span class="return-status <?php echo $isReturned ? 'return-status-ok' : 'return-status-pending'; ?>">
                                                        <?php echo $isReturned ? '已離場' : '可離場'; ?>
                                                </span>
                                                <?php if ($isReturned) { ?>
                                                        <br><small>時間: <?php echo htmlspecialchars((string)$returnTime, ENT_QUOTES, 'UTF-8'); ?></small>
                                                    <?php } elseif ($isPickup) { ?>
                                                    <br>
                                                    <form method="post" style="margin-top: 8px;" onclick="event.stopPropagation();">
                                                        <input type="hidden" name="action" value="confirm_return">
                                                        <input type="hidden" name="reservation_id" value="<?php echo (int)$row['reservation_id']; ?>">
                                                            <button type="submit" class="btn-secondary" style="font-size: 12px; padding: 4px 12px;" onclick="return confirm('確認此申請已歸還或離場？')">確認歸還／離場</button>
                                                    </form>
                                                <?php } ?>
                                            </td>
                                        </tr>
                                        <!-- 展開式進度條 -->
                                        <tr class="accordion-content" id="accordion-<?php echo (int)$row['reservation_id']; ?>" style="display: none;">
                                            <td colspan="6">
                                                <?php
                                                    // Prepare approved/rejected stage lists and timestamps for this row
                                                    $approvedStages = [];
                                                    $rejectedStages = [];
                                                    $stageTimes = [];
                                                    if (!empty($row['_stage_results'])) {
                                                        foreach ($row['_stage_results'] as $rRole => $rResult) {
                                                            if ($rResult === 'approved') $approvedStages[] = $rRole;
                                                            if ($rResult === 'rejected') $rejectedStages[] = $rRole;
                                                        }
                                                    }
                                                    if (!empty($row['_stage_times'])) {
                                                        foreach ($row['_stage_times'] as $rRole => $rTime) {
                                                            $stageTimes[$rRole] = $rTime;
                                                        }
                                                    }

                                                    // Normalize legacy '3' (old final approver) to 'd' so UI shows properly
                                                    if (in_array('3', $approvedStages, true)) {
                                                        $approvedStages[] = 'd';
                                                    }
                                                    if (in_array('3', $rejectedStages, true)) {
                                                        $rejectedStages[] = 'd';
                                                    }
                                                    // remove duplicates
                                                    $approvedStages = array_values(array_unique($approvedStages));
                                                    $rejectedStages = array_values(array_unique($rejectedStages));

                                                    // If a later stage has been approved (e.g. role '3'), mark all prior stages as approved for visual clarity
                                                    $order = ['a','b','c','d','3'];
                                                    if (!empty($approvedStages)) {
                                                        $approvedMap = array_fill_keys($approvedStages, true);
                                                        $maxIdx = -1;
                                                        foreach ($order as $i => $r) {
                                                            if (isset($approvedMap[$r])) {
                                                                $maxIdx = max($maxIdx, $i);
                                                            }
                                                        }
                                                        if ($maxIdx >= 0) {
                                                            for ($j = 0; $j <= $maxIdx; $j++) {
                                                                $approvedMap[$order[$j]] = true;
                                                                // if earlier stage lacks a timestamp, use the latest approved stage's time
                                                                if (empty($stageTimes[$order[$j]])) {
                                                                    // pick time from the furthest approved stage available (search backwards)
                                                                    for ($k = $maxIdx; $k >= 0; $k--) {
                                                                        $candidate = $order[$k];
                                                                        if (!empty($stageTimes[$candidate])) {
                                                                            $stageTimes[$order[$j]] = $stageTimes[$candidate];
                                                                            break;
                                                                        }
                                                                    }
                                                                }
                                                            }
                                                        }
                                                        $approvedStages = array_keys($approvedMap);
                                                    }
                                                ?>
                                                <div class="stepper-simple" data-status="<?php echo $progressStatus; ?>" data-approval="<?php echo htmlspecialchars($approvalStatus, ENT_QUOTES, 'UTF-8'); ?>" data-stage="<?php echo htmlspecialchars($approvalStage, ENT_QUOTES, 'UTF-8'); ?>" data-approved="<?php echo htmlspecialchars(implode(',', $approvedStages), ENT_QUOTES, 'UTF-8'); ?>" data-rejected="<?php echo htmlspecialchars(implode(',', $rejectedStages), ENT_QUOTES, 'UTF-8'); ?>">
                                                    <div class="stepper-track">
                                                        <div class="stepper-step" data-step="1">
                                                            <div class="stepper-dot"></div>
                                                            <div class="stepper-time">
                                                                <span class="stepper-text">申請送出</span>
                                                                <span class="stepper-timestamp"><?php echo htmlspecialchars((string)$row['submitted_at'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                            </div>
                                                        </div>
                                                        <div class="stepper-line"></div>

                                                        <div class="stepper-step" data-step="2" data-role="a">
                                                            <div class="stepper-dot"></div>
                                                            <div class="stepper-time">
                                                                <span class="stepper-text">學務長審核</span>
                                                                <span class="stepper-subtext approval-text">
                                                                    <?php
                                                                        $t = $stageTimes['a'] ?? null;
                                                                        $res = in_array('a', $approvedStages, true) ? 'approved' : (in_array('a', $rejectedStages, true) ? 'rejected' : ($row['_stage_results']['a'] ?? null));
                                                                        if ($res === 'approved') {
                                                                            echo '審核通過';
                                                                        } elseif ($res === 'rejected') {
                                                                            echo '審核未通過';
                                                                        } elseif ($approvalStage === 'a' && $approvalStatus === 'pending') {
                                                                            echo '審核中';
                                                                        }
                                                                    ?>
                                                                </span>
                                                                <span class="stepper-timestamp"><?php echo $stageTimes['a'] ? htmlspecialchars((string)$stageTimes['a'], ENT_QUOTES, 'UTF-8') : htmlspecialchars((string)$row['submitted_at'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                            </div>
                                                        </div>
                                                        <div class="stepper-line"></div>

                                                        <div class="stepper-step" data-step="3" data-role="b">
                                                            <div class="stepper-dot"></div>
                                                            <div class="stepper-time">
                                                                <span class="stepper-text">軍訓室審核</span>
                                                                <span class="stepper-subtext">
                                                                    <?php
                                                                        $t = $stageTimes['b'] ?? null;
                                                                        $res = in_array('b', $approvedStages, true) ? 'approved' : (in_array('b', $rejectedStages, true) ? 'rejected' : ($row['_stage_results']['b'] ?? null));
                                                                        if ($res === 'approved') {
                                                                            echo '審核通過';
                                                                        } elseif ($res === 'rejected') {
                                                                            echo '審核未通過';
                                                                        } elseif ($approvalStage === 'b' && $approvalStatus === 'pending') {
                                                                            echo '審核中';
                                                                        }
                                                                    ?>
                                                                </span>
                                                                <span class="stepper-timestamp"><?php echo $stageTimes['b'] ? htmlspecialchars((string)$stageTimes['b'], ENT_QUOTES, 'UTF-8') : '-'; ?></span>
                                                            </div>
                                                        </div>
                                                        <div class="stepper-line"></div>

                                                        <div class="stepper-step" data-step="4" data-role="c">
                                                            <div class="stepper-dot"></div>
                                                            <div class="stepper-time">
                                                                <span class="stepper-text">輔導人員審核</span>
                                                                <span class="stepper-subtext">
                                                                    <?php
                                                                        $t = $stageTimes['c'] ?? null;
                                                                        $res = in_array('c', $approvedStages, true) ? 'approved' : (in_array('c', $rejectedStages, true) ? 'rejected' : ($row['_stage_results']['c'] ?? null));
                                                                        if ($res === 'approved') {
                                                                            echo '審核通過';
                                                                        } elseif ($res === 'rejected') {
                                                                            echo '審核未通過';
                                                                        } elseif ($approvalStage === 'c' && $approvalStatus === 'pending') {
                                                                            echo '審核中';
                                                                        }
                                                                    ?>
                                                                </span>
                                                                <span class="stepper-timestamp"><?php echo $stageTimes['c'] ? htmlspecialchars((string)$stageTimes['c'], ENT_QUOTES, 'UTF-8') : '-'; ?></span>
                                                            </div>
                                                        </div>
                                                        <div class="stepper-line"></div>

                                                        <div class="stepper-step" data-step="5" data-role="d">
                                                            <div class="stepper-dot"></div>
                                                            <div class="stepper-time">
                                                                <span class="stepper-text">課指組審核</span>
                                                                <span class="stepper-subtext">
                                                                    <?php
                                                                        $t = $stageTimes['d'] ?? null;
                                                                        $res = in_array('d', $approvedStages, true) ? 'approved' : (in_array('d', $rejectedStages, true) ? 'rejected' : ($row['_stage_results']['d'] ?? null));
                                                                        if ($res === 'approved') {
                                                                            echo '審核通過';
                                                                        } elseif ($res === 'rejected') {
                                                                            echo '審核未通過';
                                                                        } elseif ($approvalStage === 'd' && $approvalStatus === 'pending') {
                                                                            echo '審核中';
                                                                        }
                                                                    ?>
                                                                </span>
                                                                <span class="stepper-timestamp"><?php echo $stageTimes['d'] ? htmlspecialchars((string)$stageTimes['d'], ENT_QUOTES, 'UTF-8') : '-'; ?></span>
                                                            </div>
                                                        </div>
                                                        <div class="stepper-line"></div>

                                                        <!-- role 3 final approval removed;課指組(d) is final -->

                                                        <div class="stepper-step" data-step="7">
                                                            <div class="stepper-dot"></div>
                                                            <div class="stepper-time">
                                                                <span class="stepper-text">使用中</span>
                                                                <span class="stepper-timestamp"><?php echo $row['pickup_confirmed_at'] ? htmlspecialchars((string)$row['pickup_confirmed_at'], ENT_QUOTES, 'UTF-8') : '-'; ?></span>
                                                            </div>
                                                        </div>
                                                        <div class="stepper-line"></div>

                                                        <div class="stepper-step" data-step="8">
                                                            <div class="stepper-dot"></div>
                                                            <div class="stepper-time">
                                                                <span class="stepper-text">已歸還</span>
                                                                <span class="stepper-timestamp"><?php echo $row['return_confirmed_at'] ? htmlspecialchars((string)$row['return_confirmed_at'], ENT_QUOTES, 'UTF-8') : '-'; ?></span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <?php if ($approvalStatus === 'rejected' && !empty($row['rejection_reason'])) { ?>
                                                    <div class="rejection-alert">
                                                        <div class="rejection-alert-header">
                                                            <span class="rejection-icon">⚠</span>
                                                            <strong>拒絕原因</strong>
                                                        </div>
                                                        <div class="rejection-alert-content">
                                                            <?php echo htmlspecialchars($row['rejection_reason'], ENT_QUOTES, 'UTF-8'); ?>
                                                        </div>
                                                    </div>                                                <?php } elseif ($approvalStatus === 'need_revision') {
                                                    // 顯示補件相關的原因或審核備註給使用者看（使用現有的 rejection_reason 或如果未來有新增 review_comment 此處可調）
                                                ?>
                                                    <div class="rejection-alert" style="border-left-color: #ffc107; background-color: #fffdf5;">
                                                        <div class="rejection-alert-header" style="color: #d39e00;">
                                                            <span class="rejection-icon">⚠</span>
                                                            <strong>補件說明</strong>
                                                        </div>
                                                        <div class="rejection-alert-content">
                                                            請盡快繳交缺件或重新上傳企劃書，以利後續審核。
                                                            <?php if (!empty($row['rejection_reason'])) { ?>
                                                                <br/><strong>備註：</strong><?php echo htmlspecialchars($row['rejection_reason'], ENT_QUOTES, 'UTF-8'); ?>
                                                            <?php } ?>
                                                        </div>
                                                    </div>                                                <?php } ?>
                                            </td>
                                        </tr>
                                    <?php } ?>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                <?php } ?>
            </section>
        </main>
    </div>

    <script>
        /**
         * 展開/收起 Accordion 進度條
         */
        function toggleAccordion(row, reservationId) {
            const accordion = document.getElementById(`accordion-${reservationId}`);
            const icon = row.querySelector('.accordion-icon');
            
            // 關閉其他已開啟的 accordion
            document.querySelectorAll('.accordion-content').forEach(item => {
                if (item.id !== `accordion-${reservationId}`) {
                    item.style.display = 'none';
                    const otherRow = item.previousElementSibling;
                    if (otherRow && otherRow.querySelector('.accordion-icon')) {
                        otherRow.querySelector('.accordion-icon').textContent = '▶';
                    }
                }
            });
            
            // 切換當前 accordion
            if (accordion.style.display === 'none') {
                accordion.style.display = 'table-row';
                icon.textContent = '▼';
                accordion.classList.add('show');
            } else {
                accordion.style.display = 'none';
                icon.textContent = '▶';
                accordion.classList.remove('show');
            }
            
            // 初始化進度條
            const stepper = accordion.querySelector('.stepper-simple');
            if (stepper) {
                updateStepper(stepper);
            }
        }
        
        /**
         * 更新進度條視覺狀態
         */
        function updateStepper(stepper) {
            const status = parseInt(stepper.getAttribute('data-status'));
            const approval = stepper.getAttribute('data-approval');
            const stage = (stepper.getAttribute('data-stage') || 'a');
            const dots = stepper.querySelectorAll('.stepper-dot');
            const lines = stepper.querySelectorAll('.stepper-line');
            const approvalText = stepper.querySelector('.approval-text');

            // parse per-stage approved/rejected lists from data attributes
            const approvedRaw = (stepper.getAttribute('data-approved') || '').trim();
            const rejectedRaw = (stepper.getAttribute('data-rejected') || '').trim();
            const approvedStages = approvedRaw ? approvedRaw.split(',').map(s=>s.trim()).filter(Boolean) : [];
            const rejectedStages = rejectedRaw ? rejectedRaw.split(',').map(s=>s.trim()).filter(Boolean) : [];
            
            // 先清除所有樣式
            dots.forEach(dot => {
                dot.classList.remove('active', 'rejected', 'pending', 'approved', 'overdue');
            });
            lines.forEach(line => {
                line.classList.remove('active', 'rejected', 'overdue');
            });
            
            // 第1步：始終完成（藍色✓ - 申請送出）
            if (dots[0]) dots[0].classList.add('active');

            // Apply per-stage approved/rejected markers first
            const roleIndexMap = { 'a':1, 'b':2, 'c':3, 'd':4 };
            approvedStages.forEach(r => {
                const idx = roleIndexMap[r];
                if (idx !== undefined && dots[idx]) {
                    dots[idx].classList.add('approved');
                    if (lines[idx - 1]) lines[idx - 1].classList.add('approved');
                }
            });
            rejectedStages.forEach(r => {
                const idx = roleIndexMap[r];
                if (idx !== undefined && dots[idx]) {
                    dots[idx].classList.add('rejected');
                    if (lines[idx - 1]) lines[idx - 1].classList.add('rejected');
                }
            });

            // map stage to dot index (dots: 0..7 for steps 1..8)
            const stageIndexMap = { 'a': 1, 'b': 2, 'c': 3, 'd': 4 };
            const currentStageIndex = stageIndexMap[stage] !== undefined ? stageIndexMap[stage] : 1;

            if (approval === 'pending') {
                // 顯示目前階段為待審
                if (dots[currentStageIndex]) dots[currentStageIndex].classList.add('pending');
                if (lines[0]) lines[0].classList.add('active');
            } else if (approval === 'need_revision') {
                // 停在目前階段，標示補件（黃色）
                if (dots[currentStageIndex]) {
                    dots[currentStageIndex].classList.add('pending');
                    dots[currentStageIndex].style.borderColor = '#ffc107';
                    dots[currentStageIndex].style.backgroundColor = '#fff3cd';
                }
                if (lines[0]) lines[0].classList.add('active');
            } else if (approval === 'approved') {
                // 已通過（final）: already handled per-stage above; ensure usage/returned reflect status
                if (status >= 6) {
                    if (dots[5]) dots[5].classList.add('active');
                    if (lines[4]) lines[4].classList.add('active');
                }
                if (status >= 7) {
                    if (dots[6]) dots[6].classList.add('active');
                    if (lines[5]) lines[5].classList.add('active');
                }

                // keep subtext color default; per-step dot/line indicate approval
            } else if (approval === 'rejected') {
                // 在拒絕的階段顯示紅色
                if (dots[currentStageIndex]) dots[currentStageIndex].classList.add('rejected');
                if (lines[currentStageIndex - 1]) lines[currentStageIndex - 1].classList.add('rejected');
                // keep subtext color default for rejected status
            } else if (approval === 'revision_overdue') {
                if (dots[currentStageIndex]) dots[currentStageIndex].classList.add('overdue');
                if (lines[currentStageIndex - 1]) lines[currentStageIndex - 1].classList.add('overdue');
                // keep subtext color default for overdue
            }
        }
    </script>
</body>
</html>
