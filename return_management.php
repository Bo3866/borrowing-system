<?php
declare(strict_types=1);

session_start();

$searchKeyword = trim((string)($_GET['search'] ?? ''));

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

$violationPoints = 0;
$isCertCancelled = false;

// 假設你已經有登入者的 $userId
$userId = $_SESSION['user_id'] ?? 0; 
$violationLogs = [];

if (!empty($userId) && isset($link)) {
    $violationSql = "SELECT vl.*, u.full_name, r.activity_name AS activity_name
                     FROM violation_logs vl
                     JOIN users u ON vl.user_id = u.user_id
                     LEFT JOIN reservations r ON vl.reservation_id = r.reservation_id
                     WHERE vl.user_id = ?
                     ORDER BY vl.created_at DESC";
                     
    if ($violationStmt = mysqli_prepare($link, $violationSql)) {
        // 將變數綁定到 SQL 的問號 (?) 中，'i' 代表整數 (Integer)
        mysqli_stmt_bind_param($violationStmt, 'i', $userId);
        
        // 執行查詢
        if (mysqli_stmt_execute($violationStmt)) {
            $violationResult = mysqli_stmt_get_result($violationStmt);
            if ($violationResult) {
                while ($vRow = mysqli_fetch_assoc($violationResult)) {
                    $violationLogs[] = $vRow;
                }
            }
        }
        mysqli_stmt_close($violationStmt);
    }
}

if (isset($link)) {
    $safeUserId = mysqli_real_escape_string($link, (string)$_SESSION['user_id']);
    
    // 1. 統計該學生的累積違規總點數
        $pointsSql = "SELECT 
                        GREATEST(SUM(CASE WHEN custom_reason LIKE '[系統銷點]%' THEN -points ELSE points END), 0) AS total_points 
                      FROM violation_logs 
                      WHERE user_id = '{$safeUserId}'";

        $pointsResult = mysqli_query($link, $pointsSql);
        if ($pointsResult) {
            $pointsRow = mysqli_fetch_assoc($pointsResult);
            $violationPoints = (int)($pointsRow['total_points'] ?? 0);
        }
    
    // 2. 檢查該學生的器材證狀態
        $certSql = "SELECT valid_until FROM equipment_certificates WHERE holder_id = '{$safeUserId}'";
        $certResult = mysqli_query($link, $certSql);
        
        $hasNoCert = false;        // 新增：是否完全沒有器材證
        $isCertCancelled = false;  // 這裡代表器材證已過期
        
        if ($certResult && mysqli_num_rows($certResult) > 0) {
            $certRow = mysqli_fetch_assoc($certResult);
            
            if (!empty($certRow['valid_until'])) {
                $validUntilTime = strtotime($certRow['valid_until']);
                $now = time();
                
                if ($now > $validUntilTime) {
                    $isCertCancelled = true; // 有證，但過期了
                }
            } else {
                $isCertCancelled = true; // 欄位留白，視為無效
            }
        } else {
            // 💡 資料庫查不到這名學生的資料，代表他「從未申請過器材證」
            $hasNoCert = true;
        }
    }


function pickExistingColumn(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function reservationSelectExpr(array $columns, string $columnName, ?string $alias = null): string
{
    $alias = $alias ?? $columnName;

    if (in_array($columnName, $columns, true)) {
        return 'r.`' . $columnName . '` AS `' . $alias . '`';
    }

    return 'NULL AS `' . $alias . '`';
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
                if (!mysqli_query($link, $migrationSql) && mysqli_errno($link) !== 1060) {
                    throw new RuntimeException(mysqli_error($link));
                }
                $reservationColumns[] = 'returned_at';
            }
        } catch (Throwable $e) {
            // 忽略列已存在錯誤
        }
    }

    // 自動遷移：確保 checked_in_at 欄位存在（報到時間）
    if ($dbError === '') {
        try {
            if (!in_array('checked_in_at', $reservationColumns, true)) {
                $migrationSql = 'ALTER TABLE reservations ADD COLUMN checked_in_at DATETIME NULL COMMENT "報到時間"';
                mysqli_query($link, $migrationSql);
                $reservationColumns[] = 'checked_in_at';
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

    // 自動遷移：新增 revision_data_json 欄位以存儲補件用的原始申請數據
    if ($dbError === '') {
        try {
            if (!in_array('revision_data_json', $reservationColumns, true)) {
                $migrationSql = 'ALTER TABLE reservations ADD COLUMN revision_data_json LONGTEXT NULL COMMENT "補件時的原始申請數據(JSON格式)" AFTER revision_deadline';
                mysqli_query($link, $migrationSql);
                $reservationColumns[] = 'revision_data_json';
            }
        } catch (Throwable $e) {
            // 忽略列已存在錯誤
        }
    }

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
                    'SELECT
                        r.reservation_id,
                        r.returned_at
                    FROM reservations r
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

                if (!empty($reservationRow['returned_at'])) {
                    throw new RuntimeException('此申請已完成歸還或離場。');
                }

                mysqli_begin_transaction($link);

                if ($action === 'confirm_return') {
                    $returnSql = 'UPDATE reservations SET returned_at = COALESCE(returned_at, NOW()) WHERE reservation_id = ?';
                    $returnStmt = mysqli_prepare($link, $returnSql);
                    if (!$returnStmt) {
                        throw new RuntimeException('更新歸還狀態失敗：' . mysqli_error($link));
                    }
                    mysqli_stmt_bind_param($returnStmt, 'i', $reservationId);
                    mysqli_stmt_execute($returnStmt);
                    mysqli_stmt_close($returnStmt);

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
                                require_once __DIR__ . '/config/mail.php';
                                $mail = new PHPMailer(true);
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
                                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                                    $mail->Port       = 465;
                                    $mail->CharSet    = 'UTF-8';

                                    $mail->setFrom($mailFrom, $MAIL_FROM_NAME ?? '器材借用系統');
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
                        $actionMsg .= '（歸還通知信處理時發生錯誤）';
                    }
            }
        } catch (Throwable $e) {
            mysqli_rollback($link);
            $actionMsg = '處理失敗：' . $e->getMessage();
        }
    }

    if ($dbError === '') {
        $selectExpressions = [
            'r.reservation_id',
            'r.`' . $applicantColumn . '` AS applicant_user_id',
            'u.full_name',
            'u.email',
            'r.`' . $borrowStartColumn . '` AS borrow_start_at',
            'r.`' . $borrowEndColumn . '` AS borrow_end_at',
            'r.approval_status',
            'r.approval_stage',
            'r.rejection_reason',
            'r.revision_deadline',
            'r.submitted_at',
            'r.updated_at',
            '(r.returned_at IS NOT NULL) AS return_confirmed',
            'r.returned_at AS return_confirmed_at',
            'r.checked_in_at AS checked_in_at',
            '(r.checked_in_at IS NOT NULL) AS checked_in',
            reservationSelectExpr($reservationColumns, 'organization_name'),
            reservationSelectExpr($reservationColumns, 'activity_name'),
            reservationSelectExpr($reservationColumns, 'participant_count'),
            reservationSelectExpr($reservationColumns, 'staff_count'),
            reservationSelectExpr($reservationColumns, 'club_president'),
            reservationSelectExpr($reservationColumns, 'activity_coordinator'),
            reservationSelectExpr($reservationColumns, 'coordinator_phone'),
            reservationSelectExpr($reservationColumns, 'coordinator_other_contact'),
            reservationSelectExpr($reservationColumns, 'vehicle_entry'),
            reservationSelectExpr($reservationColumns, 'setup_flags'),
            reservationSelectExpr($reservationColumns, 'flag_count'),
            reservationSelectExpr($reservationColumns, 'proposal_file'),
            reservationSelectExpr($reservationColumns, 'proposal_original_name'),
            reservationSelectExpr($reservationColumns, 'proposal_uploaded_at'),
            reservationSelectExpr($reservationColumns, 'has_alcohol'),
            reservationSelectExpr($reservationColumns, 'has_fire'),
            reservationSelectExpr($reservationColumns, 'has_sales'),
            reservationSelectExpr($reservationColumns, 'alcohol_coordinator'),
            reservationSelectExpr($reservationColumns, 'alcohol_president'),
            reservationSelectExpr($reservationColumns, 'fire_activity_name'),
            reservationSelectExpr($reservationColumns, 'fire_date'),
            reservationSelectExpr($reservationColumns, 'fire_start_time'),
            reservationSelectExpr($reservationColumns, 'fire_end_time'),
            reservationSelectExpr($reservationColumns, 'fire_location'),
            reservationSelectExpr($reservationColumns, 'fire_performers'),
            reservationSelectExpr($reservationColumns, 'fire_oilers'),
            reservationSelectExpr($reservationColumns, 'fire_extinguishers'),
            reservationSelectExpr($reservationColumns, 'fire_security'),
            reservationSelectExpr($reservationColumns, 'fire_emergency'),
            reservationSelectExpr($reservationColumns, 'fire_medical'),
            reservationSelectExpr($reservationColumns, 'fire_staff_json'),
            reservationSelectExpr($reservationColumns, 'sales_location'),
            reservationSelectExpr($reservationColumns, 'sales_count'),
            reservationSelectExpr($reservationColumns, 'sales_layout_map'),
            reservationSelectExpr($reservationColumns, 'holiday_fee_count'),
            reservationSelectExpr($reservationColumns, 'holiday_fee'),
            reservationSelectExpr($reservationColumns, 'sales_roster_json'),
            // 交接時間（若有多筆則取最新一筆 handover_at）
            "(
                SELECT hs.handover_at
                FROM handover_schedules hs
                WHERE hs.reservation_id = r.reservation_id
                ORDER BY hs.handover_at DESC
                LIMIT 1
            ) AS handover_at",
        ];

        $safeUserId = mysqli_real_escape_string($link, $currentUserId);
        $listWhere = "r.approval_status IN ('pending', 'approved', 'rejected', 'need_revision', 'revision_overdue') AND r.`{$applicantColumn}` = '{$safeUserId}'";

        // 💡 關鍵加入：如果前端有傳入關鍵字，就動態塞入 SQL 條件
        if ($searchKeyword !== '') {
            $safeKeyword = mysqli_real_escape_string($link, $searchKeyword);
            // 同時支援搜尋「活動名稱」或「借用單號」
            $listWhere .= " AND (r.activity_name LIKE '%{$safeKeyword}%' OR r.reservation_id = '{$safeKeyword}')";
        }

        $updateOverdueSql = "UPDATE reservations SET approval_status = 'revision_overdue' WHERE approval_status = 'need_revision' AND (revision_deadline IS NULL OR revision_deadline < NOW())";
        mysqli_query($link, $updateOverdueSql);

        $listSql = "
            SELECT
                " . implode(",\n                ", $selectExpressions) . ",
                (
                    SELECT GROUP_CONCAT(DISTINCT ec.equipment_name ORDER BY ec.equipment_name SEPARATOR ', ')
                    FROM equipment_reservation_items eri
                    JOIN equipments e ON e.equipment_id = eri.equipment_id
                    JOIN equipment_categories ec ON ec.equipment_code = e.equipment_code
                    WHERE eri.reservation_id = r.reservation_id
                ) AS equipment_names,
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
            WHERE {$listWhere}
            ORDER BY r.reservation_id DESC
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

if ($dbError === '' && count($rows) > 0) {
    foreach ($rows as $idx => $r) {
        $reservationId = (int)$r['reservation_id'];
        $rows[$idx]['_stage_times'] = [];
        $rows[$idx]['_stage_results'] = [];
        $rows[$idx]['_stage_comments'] = [];
        $rows[$idx]['_detail_payload'] = [
            'borrow_start_at' => (string)($r['borrow_start_at'] ?? ''),
            'borrow_end_at' => (string)($r['borrow_end_at'] ?? ''),
            'organization_name' => (string)($r['organization_name'] ?? ''),
            'activity_name' => (string)($r['activity_name'] ?? ''),
            'participant_count' => (string)($r['participant_count'] ?? ''),
            'staff_count' => (string)($r['staff_count'] ?? ''),
            'club_president' => (string)($r['club_president'] ?? ''),
            'activity_coordinator' => (string)($r['activity_coordinator'] ?? ''),
            'coordinator_phone' => (string)($r['coordinator_phone'] ?? ''),
            'coordinator_other_contact' => (string)($r['coordinator_other_contact'] ?? ''),
            'vehicle_entry' => (string)($r['vehicle_entry'] ?? ''),
            'setup_flags' => (string)($r['setup_flags'] ?? ''),
            'flag_count' => (string)($r['flag_count'] ?? ''),
            'proposal_file' => (string)($r['proposal_file'] ?? ''),
            'proposal_original_name' => (string)($r['proposal_original_name'] ?? ''),
            'proposal_uploaded_at' => (string)($r['proposal_uploaded_at'] ?? ''),
            'has_alcohol' => (string)($r['has_alcohol'] ?? ''),
            'has_fire' => (string)($r['has_fire'] ?? ''),
            'has_sales' => (string)($r['has_sales'] ?? ''),
            'alcohol_coordinator' => (string)($r['alcohol_coordinator'] ?? ''),
            'alcohol_president' => (string)($r['alcohol_president'] ?? ''),
            'fire_activity_name' => (string)($r['fire_activity_name'] ?? ''),
            'fire_date' => (string)($r['fire_date'] ?? ''),
            'fire_start_time' => (string)($r['fire_start_time'] ?? ''),
            'fire_end_time' => (string)($r['fire_end_time'] ?? ''),
            'fire_location' => (string)($r['fire_location'] ?? ''),
            'fire_performers' => (string)($r['fire_performers'] ?? ''),
            'fire_oilers' => (string)($r['fire_oilers'] ?? ''),
            'fire_extinguishers' => (string)($r['fire_extinguishers'] ?? ''),
            'fire_security' => (string)($r['fire_security'] ?? ''),
            'fire_emergency' => (string)($r['fire_emergency'] ?? ''),
            'fire_medical' => (string)($r['fire_medical'] ?? ''),
            'fire_staff_json' => (string)($r['fire_staff_json'] ?? ''),
            'sales_location' => (string)($r['sales_location'] ?? ''),
            'sales_count' => (string)($r['sales_count'] ?? ''),
            'sales_layout_map' => (string)($r['sales_layout_map'] ?? ''),
            'sales_roster_json' => (string)($r['sales_roster_json'] ?? ''),
            'holiday_fee_count' => (string)($r['holiday_fee_count'] ?? ''),
            'holiday_fee' => (string)($r['holiday_fee'] ?? ''),
        ];

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
                        $rows[$idx]['_stage_times'][$role] = $reviewedAt;
                        $rows[$idx]['_stage_results'][$role] = $result;
                        $rows[$idx]['_stage_comments'][$role] = $comment;
                    }
                }
            }
            mysqli_stmt_close($logStmt);
        }
        // 讀取交接時間（若有），並把它放到 row 中；若沒有 checked_in_at 我們也用 handover_at 填入以利顯示
        $handoverAt = null; $handoverReturnedAt = null;
        $handoverStmt = mysqli_prepare($link, 'SELECT handover_at, returned_at FROM handover_schedules WHERE reservation_id = ? ORDER BY handover_at DESC LIMIT 1');
        if ($handoverStmt) {
            mysqli_stmt_bind_param($handoverStmt, 'i', $reservationId);
            mysqli_stmt_execute($handoverStmt);
            $handoverRes = mysqli_stmt_get_result($handoverStmt);
            if ($handoverRes) {
                $hRow = mysqli_fetch_assoc($handoverRes);
                if ($hRow) {
                    if (!empty($hRow['handover_at'])) $handoverAt = $hRow['handover_at'];
                    if (!empty($hRow['returned_at'])) $handoverReturnedAt = $hRow['returned_at'];
                }
            }
            mysqli_stmt_close($handoverStmt);
        }

        if ($handoverAt !== null) {
            $rows[$idx]['handover_at'] = $handoverAt;
            // 如果資料表沒有 checked_in_at，或為空，使用 handover_at 作為顯示時間
            if (empty($rows[$idx]['_stage_times']['checked_in_at']) && empty($rows[$idx]['checked_in_at'])) {
                $rows[$idx]['checked_in_at'] = $handoverAt;
            }
        }
        if ($handoverReturnedAt !== null) {
            $rows[$idx]['handover_returned_at'] = $handoverReturnedAt;
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

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@300;400;500;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .stepper-text { white-space: nowrap; }
        .stepper-subtext { white-space: nowrap; }
        /* 避免 Tailwind 蓋掉基本表格線段 */
        .management-table th, .management-table td {
            border-bottom: 1px solid #e5e7eb;
            padding: 12px;
        }
        /* 左側點擊區專屬樣式 */
        .left-trigger-zone:hover {
            background-color: #eff6ff !important;
            color: #2563eb;
        }
        /* Match history_all list styling */
        .history-list-header,
        .history-list-row {
            display: grid;
            grid-template-columns: 140px minmax(220px, 1.1fr) minmax(320px, 1.5fr) 150px;
            gap: 1rem;
            align-items: center;
        }
        .history-list-header {
            background: #1e293b;
            color: #e2e8f0;
            padding: 1rem 1.5rem;
            border-radius: 1rem 1rem 0 0;
            font-size: 0.95rem;
            font-weight: 700;
            letter-spacing: .02em;
        }
        .history-list-row {
            background: #ffffff;
            padding: 1rem 1.5rem;
            border: 1px solid #e2e8f0;
            border-radius: 1rem;
            cursor: pointer;
            transition: .18s ease;
        }
        .history-list-row:hover {
            border-color: #a5b4fc;
            box-shadow: 0 10px 24px rgba(15, 23, 42, .08);
            transform: translateY(-1px);
        }
        .record-id-pill {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            padding: .45rem .75rem;
            border-radius: 999px;
            background: #eef2ff;
            color: #4f46e5;
            border: 1px solid #c7d2fe;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
            font-weight: 800;
            width: fit-content;
        }
        .detail-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .45rem;
            padding: .55rem .85rem;
            border-radius: .8rem;
            background: #f8fafc;
            color: #475569;
            border: 1px solid #e2e8f0;
            font-size: .95rem;
            font-weight: 700;
            transition: .18s ease;
            white-space: nowrap;
        }
        /* 移除整列 Hover 時強制按鈕變色的效果，讓它維持一般按鈕的 Hover 行為即可 */
        .detail-button:hover {
            background: #4f46e5;
            color: #ffffff;
            border-color: #4f46e5;
        }
    
        /* ===== 同步 history_all.php 的整體視覺與字體 ===== */
        body {
            font-family: 'Noto Sans TC', sans-serif;
        }
        .text-xs { font-size: 0.95rem !important; }
        .text-sm { font-size: 1rem !important; }
        .text-[10px] { font-size: 0.85rem !important; }
        .text-2xl { font-size: 1.5rem !important; }
        .text-lg { font-size: 1.125rem !important; }

        .container.main-content {
            max-width: 1200px !important;
            margin: 0 auto !important;
            padding-right: 1rem !important;
            padding-left: 1rem !important;
        }

        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        ::-webkit-scrollbar-track {
            background: #f1f5f9;
        }
        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        #detail-drawer { font-size: 15px; }
        .container.main-content .text-xs,
        .container.main-content .text-[10px],
        .container.main-content .text-[11px],
        .container.main-content .font-mono,
        .container.main-content .text-slate-400,
        .management-table th,
        .management-table td {
            font-size: 1rem !important;
            line-height: 1.25 !important;
        }

        .return-management-table {
            border-collapse: separate !important;
            border-spacing: 0 .5rem !important;
            background: transparent !important;
            box-shadow: none !important;
        }
        .return-management-table thead th {
            background: #1e293b !important;
            color: #e2e8f0 !important;
            padding: 1rem 1.1rem !important;
            font-size: .95rem !important;
            font-weight: 700 !important;
            border-bottom: none !important;
        }
        .return-management-table thead th:first-child {
            border-radius: 1rem 0 0 1rem;
        }
        .return-management-table thead th:last-child {
            border-radius: 0 1rem 1rem 0;
        }
        .return-management-table tbody tr.accordion-trigger {
            background: #ffffff;
            cursor: pointer;
            transition: .18s ease;
        }
        .return-management-table tbody tr.accordion-trigger:hover {
            background: #ffffff !important;
            box-shadow: 0 10px 24px rgba(15, 23, 42, .08);
            transform: translateY(-1px);
        }
        .return-management-table tbody tr.accordion-trigger td {
            border-top: 1px solid #e2e8f0;
            border-bottom: 1px solid #e2e8f0;
            background: #ffffff;
        }
        .return-management-table tbody tr.accordion-trigger td:first-child {
            border-left: 1px solid #e2e8f0;
            border-radius: 1rem 0 0 1rem;
        }
        .return-management-table tbody tr.accordion-trigger td:last-child {
            border-right: 1px solid #e2e8f0;
            border-radius: 0 1rem 1rem 0;
        }
        .return-management-table tbody tr.accordion-content td {
            border: 1px solid #e2e8f0;
            border-radius: 1rem;
            background: #f8fafc;
        }

        .accordion-icon {
            display: inline-block;
            transition: transform .2s ease;
        }

        .detail-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .45rem;
            padding: .55rem .85rem;
            border-radius: .8rem;
            background: #f8fafc;
            color: #475569;
            border: 1px solid #e2e8f0;
            font-size: .95rem;
            font-weight: 700;
            transition: .18s ease;
            white-space: nowrap;
            cursor: pointer;
        }
        .detail-button:hover {
            background: #4f46e5;
            color: #ffffff;
            border-color: #4f46e5;
        }
        .card {
            background: #ffffff;
            border-radius: 1rem;
            border: 1px solid #e2e8f0;
            box-shadow: 0 8px 24px rgba(15, 23, 42, .04);
        }
        .page-header h2,
        section.card h2 {
            margin-bottom: 1rem;
        }

    
        /* Detail Panel：固定在視窗最右側，不受表格/卡片容器限制 */
        #drawer-overlay { position: fixed; inset: 0; }
        #detail-drawer {
            position: fixed !important;
            right: 0 !important;
            top: 0 !important;
            bottom: 0 !important;
            height: 100vh !important;
            max-height: 100vh !important;
            overflow-y: auto !important;
            overscroll-behavior: contain;
        }

        
</style>
 </head>
<body class="history-page">

    <?php include __DIR__ . '/nav.php'; ?>

    <?php if ($isLoggedIn): ?>
    <div style="max-width: 80rem; margin: 1rem auto 0 auto; padding: 0 1rem; font-family: system-ui, sans-serif;">
        
        <?php if ($isCertCancelled): ?>
            <div style="background-color: #fef2f2; border: 2px solid #fca5a5; border-radius: 0.75rem; padding: 1rem; box-shadow: 0 1px 2px rgba(0,0,0,0.05); display: flex; align-items: start; gap: 0.75rem;">
                <div style="color: #ef4444; margin-top: 0.15rem; flex-shrink: 0; width: 16px; height: 16px;">
                    <svg style="width: 16px; height: 16px; display: block;" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                    </svg>
                </div>
                <div style="flex: 1; display: flex; flex-direction: column; md-flex-direction: row; justify-content: space-between; gap: 1rem;">
                    <div>
                        <h4 style="margin: 0; font-weight: 700; font-size: 0.875rem; color: #991b1b;">您的器材證已被註銷！</h4>
                        <p style="margin: 0.15rem 0 0 0; font-size: 0.75rem; color: #b91c1c; line-height: 1.4;">因嚴重違反器材租借管理規範（如器材逾期未領或未歸還超過兩日），課指組老師已註銷您的器材證。您目前無法使用線上預約功能。</p>
                    </div>
                    <a href="history.php" style="font-size: 0.75rem; font-weight: 700; color: #b91c1c; text-decoration: underline; white-space: nowrap; align-self: start;">親洽課指組辦公室 →</a>
                </div>
            </div>

        <?php elseif ($violationPoints > 0): ?>
            <?php if ($violationPoints >= 3): ?>
                <div style="background-color: #fef2f2; border: 2px solid #fca5a5; border-radius: 0.75rem; padding: 1rem; box-shadow: 0 1px 2px rgba(0,0,0,0.05); display: flex; align-items: center; justify-content: space-between; gap: 1rem;">
                    <div style="display: flex; align-items: center; gap: 0.6rem;">
                        <div style="color: #ef4444; flex-shrink: 0; width: 16px; height: 16px;">
                            <svg style="width: 16px; height: 16px; display: block;" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                            </svg>
                        </div>
                        <div style="font-size: 0.75rem; color: #991b1b;">
                            <span style="font-weight: 700;">租借權限受限！</span> 您目前已累計違規達 <span style="font-weight: 900; color: #dc2626; font-size: 0.875rem;"><?php echo $violationPoints; ?></span> 點，系統已自動暫停您的器材預約權限。
                        </div>
                    </div>
                </div>                
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="container main-content">
        <main>
            <!-- ==================== 頁籤切換按鈕 ==================== -->
            <div class="flex border-b border-slate-200 mb-6 gap-2">
                <button type="button" id="tab-btn-apply" onclick="switchTab('apply-tab')" class="px-5 py-2.5 text-sm font-semibold border-b-2 border-indigo-600 text-indigo-600 transition focus:outline-none">
                    我的申請紀錄
                </button>
                <button type="button" id="tab-btn-points" onclick="switchTab('points-tab')" class="px-5 py-2.5 text-sm font-medium border-b-2 border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300 transition focus:outline-none">
                    我的記點紀錄 
                    <?php if (isset($violationPoints) && $violationPoints > 0): ?>
                        <span class="ml-1 px-1.5 py-0.5 text-xs bg-rose-100 text-rose-600 rounded-full font-bold"><?php echo $violationPoints; ?></span>
                    <?php endif; ?>
                </button>
            </div>
            
            <div id="apply-tab-content" class="card bg-white p-6 rounded-xl shadow-sm">
                
                <form method="GET" action="" class="flex items-center gap-2 mb-4">
                    <div class="relative">
                        <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
                        <input type="text" name="search" 
                            placeholder="搜尋活動名稱或單號..." 
                            value="<?php echo htmlspecialchars($searchKeyword, ENT_QUOTES, 'UTF-8'); ?>" 
                            class="pl-9 pr-4 py-2 border border-slate-300 rounded-xl text-sm focus:outline-none focus:border-indigo-500 w-64">
                    </div>
                    <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-sm font-medium transition">
                        搜尋
                    </button>
                    
                    <?php if ($searchKeyword !== ''): ?>
                        <a href="return_management.php" class="px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl text-sm transition">
                            清除
                        </a>
                    <?php endif; ?>
                </form>

                <?php if ($actionMsg !== '') { ?>
                    <div class="borrow-success bg-emerald-50 border border-emerald-200 text-emerald-700 p-4 rounded-lg mb-4"><?php echo htmlspecialchars($actionMsg, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } ?>

                <?php if ($dbError !== '') { ?>
                    <div class="login-alert bg-rose-50 border border-rose-200 text-rose-700 p-4 rounded-lg mb-4"><?php echo htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } else { ?>
                    
                    <div class="borrow-table-wrapper overflow-x-auto">
                        <table class="management-table return-management-table w-full text-left border-collapse min-w-full divide-y divide-slate-200">
                            <thead>
                                <tr class="bg-slate-50 text-slate-600 text-sm">
                                    <th style="width: 50px; text-align: center;">進度</th>
                                    <th style="width: 80px; text-align: center;">單號</th>
                                    <th>申請人</th>
                                    <th>活動名稱</th>
                                    <th>借用時段</th>
                                    <th>借用項目</th>
                                    <th>狀態</th>
                                    <th>操作</th>
                                    <th>歸還</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($rows) === 0) { ?>
                                    <tr><td colspan="9" class="text-center py-8 text-slate-400">目前沒有可顯示的申請資料。</td></tr>
                                <?php } else { ?>
                                    <?php foreach ($rows as $row) { 
                                        $resourceParts = [];
                                        if (!empty($row['equipment_names'])) { $resourceParts[] = '器材: ' . $row['equipment_names']; }
                                        if (!empty($row['space_names'])) { $resourceParts[] = '空間: ' . $row['space_names']; }
                                        $resourceText = count($resourceParts) > 0 ? implode(' | ', $resourceParts) : '-';

                                        $isReturned = (int)$row['return_confirmed'] === 1;
                                        $approvalStatus = (string)$row['approval_status'];
                                        $isCheckedIn = isset($row['checked_in']) && (int)$row['checked_in'] === 1;
                                        $approvalStage = (string)($row['approval_stage'] ?? 'a');

                                        $stageMap = [
                                            'a' => 2, // 輔導人員
                                            'b' => 3, // 軍訓室
                                            'c' => 4, // 學務長
                                            '3' => 5, // 課指組 (對應你的 HTML data-role="3")
                                            'd' => 5  // 課指組備用
                                        ];

                                        if ($approvalStatus === 'rejected' || $approvalStatus === 'revision_overdue') {
                                            $progressStatus = 0;
                                        } 
                                        // 如果還在審核中（pending）或需要補件，就根據當前關卡對照進度
                                        elseif ($approvalStatus === 'need_revision' || $approvalStatus === 'pending') {
                                            $progressStatus = $stageMap[$approvalStage] ?? 2;
                                        } 
                                        // 如果「整筆大狀態」已經是最終審核通過 (approved)
                                        elseif ($approvalStatus === 'approved') {
                                            // 如果已經點了確認歸還，那就是第 7 步，否則進行到第 6 步(已核准可借出報到)
                                            // 註：如果你希望 approved 時是卡在第 5 步，可以把下面的 6 改成 5
                                            $progressStatus = $isReturned ? 7 : 6; 
                                        } else {
                                            $progressStatus = 1;
                                        }
                                    ?>
                                        <tr class="accordion-trigger hover:bg-slate-50/50 transition border-b border-slate-100"
                        onclick="toggleAccordion(this, <?php echo (int)$row['reservation_id']; ?>)"
                        id="row-<?php echo (int)$row['reservation_id']; ?>"
                        data-id="<?php echo (int)$row['reservation_id']; ?>"
                        data-borrower-name="<?php echo htmlspecialchars($row['full_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                        data-borrower-id="<?php echo htmlspecialchars($row['applicant_user_id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                        data-borrower-email="<?php echo htmlspecialchars((string)($row['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                        data-start="<?php echo htmlspecialchars((string)($row['borrow_start_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                        data-end="<?php echo htmlspecialchars((string)($row['borrow_end_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                        data-submitted="<?php echo htmlspecialchars((string)($row['submitted_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                        data-status="<?php echo htmlspecialchars((string)($row['approval_status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                        data-checkin="<?php echo htmlspecialchars((string)($row['checked_in'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                        data-return="<?php echo htmlspecialchars((string)($row['return_confirmed_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                        data-resources="<?php echo htmlspecialchars($resourceText, ENT_QUOTES, 'UTF-8'); ?>"
                        data-coordinator-phone="<?php echo htmlspecialchars((string)($row['coordinator_phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                        data-organization-name="<?php echo htmlspecialchars((string)($row['organization_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                        data-activity-name="<?php echo htmlspecialchars((string)($row['activity_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                        data-stage="<?php echo htmlspecialchars((string)($row['approval_stage'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                        data-handover="<?php echo htmlspecialchars((string)($row['handover_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                        data-application="<?php echo htmlspecialchars(json_encode($row['_detail_payload'] ?? []), ENT_QUOTES, 'UTF-8'); ?>">
                                            
                                            <td class="left-trigger-zone text-center text-indigo-600 font-bold" style="cursor: pointer;" onclick="event.stopPropagation(); toggleAccordion(this.closest('tr'), <?php echo (int)$row['reservation_id']; ?>)">
                            <span class="accordion-icon">▶</span>
                        </td>
                        <td style="text-align: center;">
                            <span class="font-mono text-xs font-bold text-indigo-600 bg-indigo-50 border border-indigo-200 px-1.5 py-0.5 rounded">#<?php echo (int)$row['reservation_id']; ?></span>
                        </td>
                        <td>
                            <span class="font-semibold text-slate-800"><?php echo htmlspecialchars($row['full_name'] . ' (' . $row['applicant_user_id'] . ')', ENT_QUOTES, 'UTF-8'); ?></span><br>
                            <small class="text-slate-500"><?php echo htmlspecialchars((string)$row['email'], ENT_QUOTES, 'UTF-8'); ?></small>
                        </td>
                        <td class="font-medium text-slate-800">
                            <?php echo htmlspecialchars((string)($row['activity_name'] ?? '未填寫活動名稱'), ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                        <td class="text-sm">
                            <?php echo htmlspecialchars((string)$row['borrow_start_at'], ENT_QUOTES, 'UTF-8'); ?><br>
                            ～ <?php echo htmlspecialchars((string)$row['borrow_end_at'], ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                        <td class="text-sm max-w-xs truncate"><?php echo htmlspecialchars($resourceText, ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                                                <?php
                                if ($isReturned) {
                                    echo '<span class="px-2 py-1 rounded bg-emerald-100 text-emerald-800 text-xs font-semibold">已離場</span>';
                                } else {
                                    $statusLabel = '待審核';
                                    $badgeStyle = "bg-amber-100 text-amber-800";
                                    if ($approvalStatus === 'rejected' || $approvalStatus === 'revision_overdue') {
                                        $statusLabel = ($approvalStatus === 'rejected') ? '審核未通過' : '補件逾期';
                                        $badgeStyle = "bg-rose-100 text-rose-800";
                                    } elseif ($approvalStatus === 'need_revision') {
                                        $statusLabel = '需要補件';
                                        $badgeStyle = "bg-yellow-100 text-yellow-800 border border-yellow-200";
                                    } elseif ($approvalStatus === 'approved') {
                                        $statusLabel = '審核完成';
                                        $badgeStyle = "bg-indigo-100 text-indigo-800";
                                    }
                                    echo '<span class="px-2 py-1 rounded text-xs font-semibold ' . $badgeStyle . '">' . htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') . '</span>';
                                }
                            ?>
                        </td>
                        <td style="text-align: center;">
                            <?php
                                // 只有在 pending 狀態，且尚無任何審核階段通過，才允許修改申請
                                $hasAnyApproved = !empty($row['_stage_results']) && in_array('approved', $row['_stage_results'], true);
                                if ($approvalStatus === 'pending' && !$hasAnyApproved) { ?>
                                <a href="edit_application.php?reservation_id=<?php echo (int)$row['reservation_id']; ?>" class="bg-indigo-600 text-white px-2.5 py-1 rounded text-xs inline-block hover:bg-indigo-700 transition" onclick="event.stopPropagation();">修改申請</a>
                            <?php } else { echo '-'; } ?>
                            <div style="margin-top:6px;">
                                <button type="button" class="detail-button px-2 py-1 bg-slate-100 text-slate-700 text-xs rounded hover:bg-slate-200" onclick="event.stopPropagation(); openDrawer(this.closest('tr'))">查看詳情</button>
                            </div>
                        </td>
                        <td>
                            <?php if ($isReturned) { ?>
                                <span class="text-emerald-600 text-xs font-medium">已離場</span>
                            <?php } elseif ($approvalStatus === 'approved' && $isCheckedIn) { ?>
                                <form method="post" class="inline-block m-0" onclick="event.stopPropagation();">
                                    <input type="hidden" name="action" value="confirm_return">
                                    <input type="hidden" name="reservation_id" value="<?php echo (int)$row['reservation_id']; ?>">
                                    <button type="submit" class="bg-slate-800 text-white px-3 py-1 rounded text-xs hover:bg-slate-700 transition" onclick="event.stopPropagation(); return confirm('確認此申請已歸還或離場？')">確認歸還／離場</button>
                                </form>
                            <?php } elseif ($approvalStatus === 'approved' && !$isCheckedIn) { ?>
                                <span class="text-amber-600 text-xs">已核准，尚未報到</span>
                            <?php } else { ?>
                                <span class="text-slate-400 text-xs">不可離場</span>
                            <?php } ?>
                        </td>
                    </tr>
                    
                    <tr class="accordion-content bg-slate-50/50" id="accordion-<?php echo (int)$row['reservation_id']; ?>" style="display: none;">
                        <td colspan="9" class="p-4">
                            <?php
                                $approvedStages = []; $needRevisionStages = []; $rejectedStages = []; $stageTimes = [];
                                if (!empty($row['_stage_results'])) {
                                    foreach ($row['_stage_results'] as $rRole => $rResult) {
                                        if ($rResult === 'approved') $approvedStages[] = $rRole;
                                        if ($rResult === 'need_revision') $needRevisionStages[] = $rRole;
                                        if ($rResult === 'rejected') $rejectedStages[] = $rRole;
                                    }
                                }
                                if (!empty($row['_stage_times'])) {
                                    foreach ($row['_stage_times'] as $rRole => $rTime) { $stageTimes[$rRole] = $rTime; }
                                }
                                if (in_array('3', $approvedStages, true)) { $approvedStages[] = 'd'; }
                                $approvedStages = array_values(array_unique($approvedStages));
                            ?>
                            <?php
                                // 準備 data attributes：已核准與被拒絕的階段清單
                                $approvedAttr = implode(',', $approvedStages);
                                $rejectedAttr = implode(',', $rejectedStages);

                                // helper: 取得該階段的顯示時間與狀態文字
                                $getTs = function($k) use ($stageTimes, $row) {
                                    return htmlspecialchars((string)($stageTimes[$k] ?? '-'), ENT_QUOTES, 'UTF-8');
                                };
                                $getLabel = function($role) use ($approvedStages, $needRevisionStages, $rejectedStages, $approvalStage, $approvalStatus) {
                                    // 以階段順序判斷顯示：先處理拒絕/補件，再依照當前 approval_stage 決定完成/審核中/待審核
                                    if (in_array($role, $rejectedStages, true)) return '審核未通過';
                                    if (in_array($role, $needRevisionStages, true)) return '需要補件';

                                    $stageOrder = [ 'a' => 1, 'b' => 2, 'c' => 3, '3' => 4, 'd' => 4 ];
                                    $currentIndex = $stageOrder[$approvalStage] ?? 1;
                                    $roleIndex = $stageOrder[$role] ?? 1;

                                    // 如果整體狀態為 approved，視為目前及之前都為完成
                                    if ($approvalStatus === 'approved') {
                                        return ($roleIndex <= $currentIndex) ? '審核完成' : '待審核';
                                    }

                                    // 尚未核准的情況：小於當前階段視為已完成
                                    if ($roleIndex < $currentIndex) return '審核完成';
                                    if ($roleIndex === $currentIndex) {
                                        if ($approvalStatus === 'pending' || $approvalStatus === 'need_revision') return '審核中';
                                        return '待審核';
                                    }

                                    return '待審核';
                                };
                            ?>
                            <div class="stepper-simple" data-status="<?php echo $progressStatus; ?>" data-approval="<?php echo htmlspecialchars($approvalStatus, ENT_QUOTES, 'UTF-8'); ?>" data-stage="<?php echo htmlspecialchars($approvalStage, ENT_QUOTES, 'UTF-8'); ?>" data-approved="<?php echo htmlspecialchars($approvedAttr, ENT_QUOTES, 'UTF-8'); ?>" data-rejected="<?php echo htmlspecialchars($rejectedAttr, ENT_QUOTES, 'UTF-8'); ?>" data-handover="<?php echo htmlspecialchars((string)($row['handover_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-handover-return="<?php echo htmlspecialchars((string)($row['handover_returned_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-return="<?php echo htmlspecialchars((string)($row['return_confirmed_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                <div class="stepper-track flex items-center justify-between border border-slate-200 py-4 bg-white rounded-lg p-4 shadow-inner">
                                    <div class="stepper-step text-center text-xs" data-step="1">
                                        <div class="stepper-dot w-3 h-3 rounded-full bg-slate-300 mx-auto mb-1"></div>
                                        <span class="stepper-text block font-medium">申請送出</span>
                                        <span class="stepper-subtext block text-[10px] text-slate-400"><?php echo htmlspecialchars((string)$row['submitted_at'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                    <div class="stepper-step text-center text-xs" data-step="2" data-role="a">
                                        <div class="stepper-dot w-3 h-3 rounded-full bg-slate-300 mx-auto mb-1"></div>
                                        <span class="stepper-text block font-medium">輔導人員審核</span>
                                        <span class="stepper-subtext block text-[10px] text-slate-400"><?php echo $getTs('a'); ?></span>
                                        <span class="stepper-timestamp block text-[10px] text-slate-500"><?php echo $getLabel('a'); ?></span>
                                    </div>
                                    <div class="stepper-step text-center text-xs" data-step="3" data-role="b">
                                        <div class="stepper-dot w-3 h-3 rounded-full bg-slate-300 mx-auto mb-1"></div>
                                        <span class="stepper-text block font-medium">軍訓室審核</span>
                                        <span class="stepper-subtext block text-[10px] text-slate-400"><?php echo $getTs('b'); ?></span>
                                        <span class="stepper-timestamp block text-[10px] text-slate-500"><?php echo $getLabel('b'); ?></span>
                                    </div>
                                    <div class="stepper-step text-center text-xs" data-step="4" data-role="c">
                                        <div class="stepper-dot w-3 h-3 rounded-full bg-slate-300 mx-auto mb-1"></div>
                                        <span class="stepper-text block font-medium">學務長審核</span>
                                        <span class="stepper-subtext block text-[10px] text-slate-400"><?php echo $getTs('c'); ?></span>
                                        <span class="stepper-timestamp block text-[10px] text-slate-500"><?php echo $getLabel('c'); ?></span>
                                    </div>
                                    <div class="stepper-step text-center text-xs" data-step="5" data-role="3">
                                        <div class="stepper-dot w-3 h-3 rounded-full bg-slate-300 mx-auto mb-1"></div>
                                        <span class="stepper-text block font-medium">課指組審核</span>
                                        <span class="stepper-subtext block text-[10px] text-slate-400"><?php echo $getTs('3'); ?></span>
                                        <span class="stepper-timestamp block text-[10px] text-slate-500"><?php echo $getLabel('3'); ?></span>
                                    </div>
                                    <div class="stepper-step text-center text-xs" data-step="6" data-role="3">
                                        <div class="stepper-dot w-3 h-3 rounded-full bg-slate-300 mx-auto mb-1"></div>
                                        <span class="stepper-text block font-medium">借出/報到</span>
                                        <span class="stepper-subtext block text-[10px] text-slate-400"><?php echo htmlspecialchars((string)($row['checked_in_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span>
                                        <span class="stepper-timestamp block text-[10px] text-slate-500"><?php echo ($approvalStatus === 'approved' && empty($row['checked_in_at'])) ? '已核准，尚未報到' : '-'; ?></span>
                                    </div>
                                    <div class="stepper-step text-center text-xs" data-step="7" data-role="3">
                                        <div class="stepper-dot w-3 h-3 rounded-full bg-slate-300 mx-auto mb-1"></div>
                                        <span class="stepper-text block font-medium">歸還/離場</span>
                                        <span class="stepper-subtext block text-[10px] text-slate-400"><?php echo htmlspecialchars((string)($row['return_confirmed_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></span>
                                        <span class="stepper-timestamp block text-[10px] text-slate-500"><?php echo ($row['return_confirmed']) ? '已離場' : '-'; ?></span>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php } ?>
            <?php } ?>
        </tbody>
    </table>
</div>
                <?php } ?>
            </div>
        <div id="points-tab-content" class="card bg-white p-6 rounded-xl shadow-sm" style="display: none;">
                <div class="borrow-table-wrapper overflow-x-auto">
                    <table class="management-table return-management-table w-full text-left border-collapse min-w-full divide-y divide-slate-200">
                        <thead>
                            <tr class="bg-slate-50 text-slate-600 text-sm">
                                <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">活動名稱</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">違規原因</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">備註</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">記點時間</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">點數</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($violationLogs)): ?>
                                <?php foreach ($violationLogs as $log): ?>
                                    <tr class="hover:bg-slate-50 transition border-b border-slate-100">
                                        <td class="px-6 py-4 font-medium text-slate-800">
                                            <?php if (!empty($log['activity_name'])): ?>
                                                <?php echo htmlspecialchars($log['activity_name'], ENT_QUOTES, 'UTF-8'); ?>
                                            <?php elseif (!empty($log['reservation_id'])): ?>
                                                單號: <?php echo htmlspecialchars($log['reservation_id'], ENT_QUOTES, 'UTF-8'); ?>
                                            <?php else: ?>
                                                <span class="text-slate-400">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-slate-700 max-w-xs break-words">
                                            <?php 
                                                $ruleMapping = [
                                                    'rule_1' => '器材逾期領取或逾期歸還 / 未按時領取且未事先取消',
                                                    'rule_2' => '領取或歸還器材時，器材證持有人未親自到場',
                                                    'rule_3' => '未於規定時間內辦理器材預約 (臨時預約)',
                                                    'rule_cancel' => '器材超過兩日未領/未還且未通知，直接註銷器材證',
                                                    'rule_other' => '其他（請見備註）',
                                                ];
                                                $category = $log['reason_category'] ?? '';
                                                echo htmlspecialchars($ruleMapping[$category] ?? $category, ENT_QUOTES, 'UTF-8');
                                            ?>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-slate-500 max-w-xs break-words">
                                            <?php 
                                                if (!empty($log['custom_reason'])) {
                                                    echo htmlspecialchars($log['custom_reason'], ENT_QUOTES, 'UTF-8');
                                                } else {
                                                    echo '<span class="text-slate-400">-</span>';
                                                }
                                            ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-500">
                                            <?php echo date('Y-m-d H:i', strtotime($log['created_at'])); ?>
                                        </td>
                                        <?php $isReduce = (strpos($log['custom_reason'] ?? '', '[系統銷點]') === 0); ?>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold <?php echo $isReduce ? 'text-emerald-600' : 'text-rose-600'; ?>">
                                            <?php echo $isReduce ? '消 ' : '記 '; ?><?php echo (int)$log['points']; ?> 點
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="px-6 py-12 text-center text-sm text-slate-400">
                                        <div class="flex flex-col items-center justify-center space-y-2">
                                            <span class="text-2xl">🎉</span>
                                            <p>目前沒有任何記點紀錄</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
        </div>

            

    <div id="drawer-overlay" class="fixed inset-0 bg-slate-900/40 z-40 hidden backdrop-blur-xs transition-opacity duration-300 opacity-0" onclick="closeDrawer()"></div>
    <div id="detail-drawer" class="fixed right-0 top-0 bottom-0 w-[550px] max-w-[90vw] bg-white border-l border-slate-200 z-50 p-8 shadow-2xl overflow-y-auto translate-x-full transition-transform duration-300 ease-out">
        <div class="flex items-center justify-between border-b border-slate-100 pb-5 mb-6">
            <div class="flex items-center gap-2">
                <span class="px-2.5 py-1 rounded text-xs font-bold bg-indigo-50 text-indigo-700 border border-indigo-200">租借紀錄詳情</span>
                <span class="text-xs text-slate-400 font-mono" id="drawer-record-id">單號: -</span>
            </div>
            <button onclick="closeDrawer()" class="text-slate-400 hover:text-slate-800 transition p-1.5 hover:bg-slate-100 rounded-lg">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>
        <div class="space-y-6">
            <div class="bg-slate-50 p-4 rounded-xl border border-slate-200 space-y-3">
                <h4 class="text-xs font-semibold text-slate-500 uppercase tracking-wider">申請人帳戶資訊</h4>
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 rounded-xl bg-indigo-50 border border-indigo-100 flex items-center justify-center text-indigo-600 text-lg font-bold">
                        <i class="fa-solid fa-id-card"></i>
                    </div>
                    <div>
                        <p class="text-sm font-bold text-slate-800 flex items-center gap-2">
                            <span id="drawer-borrower-name">-</span>
                            <span class="text-xs bg-slate-200 text-slate-600 px-1.5 py-0.5 rounded font-mono" id="drawer-borrower-id">-</span>
                        </p>
                        <p class="text-xs text-slate-500 font-mono mt-0.5" id="drawer-borrower-email">-</p>
                        <p class="text-xs text-slate-500 font-mono mt-1"><span class="text-slate-400">聯絡電話：</span> <span id="drawer-borrower-phone">-</span></p>
                    </div>
                </div>
            </div>

            <div class="space-y-3">
                <h4 class="text-xs font-semibold text-slate-500 uppercase tracking-wider">申借之資源項目</h4>
                <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 text-xs space-y-3" id="drawer-resources-list">
                    <p class="text-slate-400 text-xs text-center py-2">-</p>
                </div>
            </div>

            <div class="space-y-3">
                <h4 class="text-xs font-semibold text-slate-500 uppercase tracking-wider">申請表單資料</h4>
                <div class="bg-slate-50 border border-slate-200 rounded-xl p-4" id="drawer-application-details">
                    <p class="text-slate-400 text-xs text-center py-2">-</p>
                </div>
            </div>

            <!-- 申請時填寫資料區塊已移除 -->

            <div class="grid grid-cols-2 gap-4">
                <div class="bg-slate-50 p-3.5 rounded-xl border border-slate-200 text-xs">
                    <span class="text-slate-400 block mb-1">現場報到登記</span>
                    <p class="font-semibold text-slate-700 flex items-center gap-1.5" id="drawer-checkin-status">-</p>
                </div>
                <div class="bg-slate-50 p-3.5 rounded-xl border border-slate-200 text-xs">
                    <span class="text-slate-400 block mb-1">歸還點收清點</span>
                    <p class="font-semibold text-slate-700 flex items-center gap-1.5" id="drawer-return-status">-</p>
                </div>
            </div>

            <!-- 系統工作紀錄已移除 -->
        </div>
    </div>

    <script>
    function switchTab(tabId) {
        const applyTabBtn = document.getElementById('tab-btn-apply');
        const pointsTabBtn = document.getElementById('tab-btn-points');
        const applyContent = document.getElementById('apply-tab-content');
        const pointsContent = document.getElementById('points-tab-content');

        if (tabId === 'apply-tab') {
            // 顯示 申請紀錄
            applyContent.style.display = 'block';
            pointsContent.style.display = 'none';
            
            // 切換按鈕樣式 (Indigo 啟用高亮)
            applyTabBtn.className = "px-5 py-2.5 text-sm font-semibold border-b-2 border-indigo-600 text-indigo-600 transition focus:outline-none";
            pointsTabBtn.className = "px-5 py-2.5 text-sm font-medium border-b-2 border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300 transition focus:outline-none";
        } else if (tabId === 'points-tab') {
            // 顯示 記點紀錄
            applyContent.style.display = 'none';
            pointsContent.style.display = 'block';
            
            // 切換按鈕樣式 (Indigo 啟用高亮)
            applyTabBtn.className = "px-5 py-2.5 text-sm font-medium border-b-2 border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300 transition focus:outline-none";
            pointsTabBtn.className = "px-5 py-2.5 text-sm font-semibold border-b-2 border-indigo-600 text-indigo-600 transition focus:outline-none";
        }
    }

        /**
         * 點擊整列或左側箭頭展開/收起 Accordion 進度條
         */
        function toggleAccordion(row, reservationId) {
            const accordion = document.getElementById(`accordion-${reservationId}`);
            const icon = row.querySelector('.accordion-icon');

            if (!accordion) return;

            // 關閉其他已開啟的 accordion
            document.querySelectorAll('.accordion-content').forEach(item => {
                if (item.id !== `accordion-${reservationId}`) {
                    item.style.display = 'none';
                    item.classList.remove('show');

                    const otherRow = item.previousElementSibling;
                    if (otherRow && otherRow.querySelector('.accordion-icon')) {
                        otherRow.querySelector('.accordion-icon').textContent = '▶';
                    }
                }
            });

            // 切換當前 accordion 的開啟與圖示
            const isHidden = accordion.style.display === 'none' || accordion.style.display === '';

            if (isHidden) {
                accordion.style.display = 'table-row';
                if (icon) icon.textContent = '▼';
                accordion.classList.add('show');
            } else {
                accordion.style.display = 'none';
                if (icon) icon.textContent = '▶';
                accordion.classList.remove('show');
            }

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

            const approvedRaw = (stepper.getAttribute('data-approved') || '').trim();
            const rejectedRaw = (stepper.getAttribute('data-rejected') || '').trim();
            const approvedStages = approvedRaw ? approvedRaw.split(',').map(s=>s.trim()).filter(Boolean) : [];
            const rejectedStages = rejectedRaw ? rejectedRaw.split(',').map(s=>s.trim()).filter(Boolean) : [];
            
            dots.forEach(dot => {
                dot.className = 'stepper-dot w-3 h-3 rounded-full bg-slate-300 mx-auto mb-1';
                dot.style = ''; 
            });
            
            if (dots[0]) dots[0].className = 'stepper-dot w-3 h-3 rounded-full bg-indigo-600 mx-auto mb-1';

            const roleIndexMap = { 'a':1, 'b':2, 'c':3, 'd':4 };
            approvedStages.forEach(r => {
                const idx = roleIndexMap[r];
                if (idx !== undefined && dots[idx]) {
                    dots[idx].className = 'stepper-dot w-3 h-3 rounded-full bg-emerald-500 mx-auto mb-1';
                }
            });
            rejectedStages.forEach(r => {
                const idx = roleIndexMap[r];
                if (idx !== undefined && dots[idx]) {
                    dots[idx].className = 'stepper-dot w-3 h-3 rounded-full bg-rose-500 mx-auto mb-1';
                }
            });

            // 如果整體為 approved，將角色步驟（輔導/軍訓/學務長/課指組）設為綠色，除非該步驟已被標為拒絕
            if (approval === 'approved' && rejectedStages.length === 0) {
                // role steps 分別位於 dots[1]..dots[4]（1:a,2:b,3:c,4:d/3）
                for (let i = 1; i <= 4; i++) {
                    if (dots[i]) {
                        dots[i].className = 'stepper-dot w-3 h-3 rounded-full bg-emerald-500 mx-auto mb-1';
                    }
                }
            }

            const stageIndexMap = { 'a': 1, 'b': 2, 'c': 3, 'd': 4 };
            const currentStageIndex = stageIndexMap[stage] !== undefined ? stageIndexMap[stage] : 1;

            if (approval === 'pending') {
                if (dots[currentStageIndex]) dots[currentStageIndex].className = 'stepper-dot w-3 h-3 rounded-full bg-amber-500 mx-auto mb-1';
            } else if (approval === 'need_revision') {
                if (dots[currentStageIndex]) {
                    dots[currentStageIndex].style.borderColor = '#ffc107';
                    dots[currentStageIndex].style.backgroundColor = '#fff3cd';
                }
            } else if (approval === 'approved') {
                if (status >= 6 && dots[5]) dots[5].className = 'stepper-dot w-3 h-3 rounded-full bg-indigo-600 mx-auto mb-1';
                if (status >= 7 && dots[6]) dots[6].className = 'stepper-dot w-3 h-3 rounded-full bg-emerald-500 mx-auto mb-1';
            } else if (approval === 'rejected') {
                if (dots[currentStageIndex]) dots[currentStageIndex].className = 'stepper-dot w-3 h-3 rounded-full bg-rose-500 mx-auto mb-1';
            } else if (approval === 'revision_overdue') {
                if (dots[currentStageIndex]) dots[currentStageIndex].className = 'stepper-dot w-3 h-3 rounded-full bg-slate-500 mx-auto mb-1';
            }

            // 如果有交接時間，將第 6 步（借出/報到）標為已借出（綠色），並顯示交接時間與文字標示
            const handoverRaw = (stepper.getAttribute('data-handover') || '').trim();
            if (handoverRaw) {
                if (dots[5]) dots[5].className = 'stepper-dot w-3 h-3 rounded-full bg-emerald-500 mx-auto mb-1';
                const step6 = stepper.querySelector('.stepper-step[data-step="6"]');
                if (step6) {
                    const sub = step6.querySelector('.stepper-subtext');
                    if (sub) sub.textContent = handoverRaw;
                    const label = step6.querySelector('.stepper-timestamp');
                    if (label) label.textContent = '已借出';
                }
            }

            // 如果 handover_schedule.returned_at 有時間，且 reservations.returned_at (data-return) 也有時間，則第7步標示為已離場
            const handoverReturnRaw = (stepper.getAttribute('data-handover-return') || '').trim();
            const reservationReturnRaw = (stepper.getAttribute('data-return') || '').trim();
            if (handoverReturnRaw && reservationReturnRaw) {
                if (dots[6]) dots[6].className = 'stepper-dot w-3 h-3 rounded-full bg-emerald-500 mx-auto mb-1';
                const step7 = stepper.querySelector('.stepper-step[data-step="7"]');
                if (step7) {
                    const sub7 = step7.querySelector('.stepper-subtext');
                    if (sub7) sub7.textContent = reservationReturnRaw;
                    const label7 = step7.querySelector('.stepper-timestamp');
                    if (label7) label7.textContent = '已離場';
                }
            }
        }
        
        /**
         * 將內部狀態碼轉成中文顯示文字
         */
        function mapStatusToChinese(status) {
            if (!status) return '-';
            switch (status) {
                case 'rejected': return '審核未通過';
                case 'need_revision': return '需要補件';
                case 'pending': return '待審核';
                case 'approved': return '審核完成';
                case 'revision_overdue': return '補件逾期';
                default: return status;
            }
        }

        /**
         * 將階段代碼轉成中文階段名稱
         */
        function mapStageName(stage) {
            if (!stage) return '-';
            switch (stage) {
                case 'a': return '學務長審核';
                case 'b': return '軍訓室審核';
                case 'c': return '輔導人員審核';
                case 'd': return '課指組審核';
                case '3': return '課指組(代號3)';
                default: return stage;
            }
        }

        function formatDetailValue(value) {
            if (value === null || value === undefined) return '-';
            const text = String(value).trim();
            return text === '' ? '-' : text;
        }


    /**
     * 嘗試從非結構化的擺攤字串中抽出攤位陣列（簡易啟發式解析）
     * 如果無法抽出攤位則回傳 null。
     */
    function tryParseSalesRaw(raw) {
        if (!raw || String(raw).trim() === '') return null;
        const text = String(raw).replace(/\r\n?/g, '\n').replace(/<br\s*\/?>/gi, '\n');
        const lines = text.split('\n').map(l => l.trim()).filter(Boolean);
        const booths = [];

        for (const line of lines) {
            // 常見標記：攤位: 名稱、#1 攤位: 名稱、名稱 - 負責人、名稱（負責人）
            let m = line.match(/#?\s*(?:攤位|攤|booth)\s*[0-9]*\s*[:：]\s*(.+)/i);
            if (m && m[1]) {
                booths.push({ booth_name: m[1].trim() });
                continue;
            }

            // 名稱 - 負責人
            let m2 = line.match(/^(.+?)\s*[\-–—]\s*(.+)$/);
            if (m2) {
                booths.push({ booth_name: m2[1].trim(), owner: m2[2].trim() });
                continue;
            }

            // 名稱（負責人）或 名稱 (owner)
            let m3 = line.match(/^(.+?)\s*[\(（](.+)[\)）]$/);
            if (m3) {
                booths.push({ booth_name: m3[1].trim(), owner: m3[2].trim() });
                continue;
            }

            // 如果一行中以逗號分隔多個攤位，拆解
            if (line.indexOf(',') !== -1 && line.split(',').length <= 10) {
                line.split(',').map(p => p.trim()).filter(Boolean).forEach(p => booths.push({ booth_name: p }));
                continue;
            }

            // 嘗試從「欄位: 值」形式擷取攤位名稱
            let kv = line.match(/^(?:名稱|攤名|店家|商家)\s*[:：]\s*(.+)$/i);
            if (kv && kv[1]) {
                booths.push({ booth_name: kv[1].trim() });
                continue;
            }
        }

        return booths.length > 0 ? booths : null;
    }
        function formatYesNo(value) {
            if (value === '1' || value === 1 || value === true) return '是';
            if (value === '0' || value === 0 || value === false) return '否';
            const text = String(value || '').trim();
            if (text === '' || text === '-') return '-';
            if (text === 'yes') return '是';
            if (text === 'no') return '否';
            return text;
        }

        function formatTimeRange(startTime, endTime) {
            const start = formatDetailValue(startTime);
            const end = formatDetailValue(endTime);
            if (start === '-' && end === '-') return '-';
            if (start === '-') return end;
            if (end === '-') return start;
            return start + ' ～ ' + end;
        }

        function parseFireStaff(staffJson, fallbackFields) {
            if (staffJson) {
                try {
                    const parsed = JSON.parse(staffJson);
                    if (parsed && typeof parsed === 'object') {
                        return parsed;
                    }
                } catch (e) {
                    // ignore and use the flat columns below
                }
            }

            return {
                fire_performers: fallbackFields.fire_performers || '',
                fire_oilers: fallbackFields.fire_oilers || '',
                fire_extinguishers: fallbackFields.fire_extinguishers || '',
                fire_security: fallbackFields.fire_security || '',
                fire_emergency: fallbackFields.fire_emergency || '',
                fire_medical: fallbackFields.fire_medical || '',
            };
        }

        function buildDetailItem(label, value, extraClass = '') {
            return `
                <div class="rounded-xl border border-slate-200 bg-white p-3 ${extraClass}">
                    <p class="text-[11px] font-semibold text-slate-400 mb-1">${escapeHtml(label)}</p>
                    <p class="text-sm font-medium text-slate-800 whitespace-pre-wrap break-words">${escapeHtml(value)}</p>
                </div>
            `;
        }

        function buildToggleSection(title, enabled, summaryText, detailHtml) {
            const statusLabel = enabled ? '有' : '無';
            if (!enabled) {
                return `
                    <div class="rounded-xl border border-slate-200 bg-white p-3">
                        <div class="flex items-center justify-between gap-3">
                            <p class="text-[11px] font-semibold text-slate-400">${escapeHtml(title)}</p>
                            <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">${statusLabel}</span>
                        </div>
                    </div>
                `;
            }

            return `
                <details class="rounded-xl border border-slate-200 bg-white p-3 group">
                    <summary class="flex list-none cursor-pointer items-center justify-between gap-3">
                        <div>
                            <p class="text-[11px] font-semibold text-slate-400">${escapeHtml(title)}</p>
                            <p class="text-sm font-medium text-slate-800">${escapeHtml(summaryText)}</p>
                        </div>
                        <span class="inline-flex h-8 w-8 items-center justify-center rounded-full border border-slate-200 bg-slate-50 text-slate-500 transition-transform duration-200 group-open:rotate-180">
                            <i class="fa-solid fa-chevron-down text-xs"></i>
                        </span>
                    </summary>
                    <div class="mt-3 space-y-2 border-t border-slate-100 pt-3">
                        ${detailHtml}
                    </div>
                </details>
            `;
        }

        function escapeHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function buildApplicationDetails(data) {
            if (!data) {
                return '<p class="text-slate-400 text-xs text-center py-2">-</p>';
            }

            const fireStaff = parseFireStaff(data.fire_staff_json, data);
            const proposalHref = formatDetailValue(data.proposal_file);
            const proposalName = formatDetailValue(data.proposal_original_name || proposalHref.split('/').pop().split('\\').pop());
            const hasAlcohol = String(data.has_alcohol || '').trim() === '1';
            const hasFire    = String(data.has_fire    || '').trim() === '1';
            const hasSales   = (data.has_sales === '1' || data.has_sales === 1 || data.has_sales === 'Y' || data.has_sales === 'y');
            const hasFlags   = String(data.setup_flags || '').trim() === 'yes';

            // 明火工作人員（grid 2欄卡片）
            const fireStaffHtml = [
                ['表演組',     fireStaff.fire_performers],
                ['加油組',     fireStaff.fire_oilers],
                ['滅火組',     fireStaff.fire_extinguishers],
                ['安全組',     fireStaff.fire_security],
                ['緊急應變組', fireStaff.fire_emergency],
                ['醫護組',     fireStaff.fire_medical],
            ].map(([label, value]) => buildDetailItem(label, formatDetailValue(value))).join('');



            // 明火
            const fireDetailHtml = `
                ${buildDetailItem('明火活動名稱', formatDetailValue(data.fire_activity_name))}
                ${buildDetailItem('明火日期',     formatDetailValue(data.fire_date))}
                ${buildDetailItem('明火時間',     formatTimeRange(data.fire_start_time, data.fire_end_time))}
                ${buildDetailItem('明火地點',     formatDetailValue(data.fire_location))}
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <p class="text-[11px] font-semibold text-slate-400 mb-2">明火工作人員</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                        ${fireStaffHtml}
                    </div>
                </div>
            `;

            // 擺攤：地點＋數量＋配置圖＋清冊
            const rawRoster = (data.sales_roster_json === null || data.sales_roster_json === undefined) ? '' : String(data.sales_roster_json);
            let rosterData = null;
            if (rawRoster.trim() !== '') {
                try { rosterData = JSON.parse(rawRoster); } catch (e) { rosterData = tryParseSalesRaw(rawRoster); }
            }

            let rosterHtml = '';
            if (Array.isArray(rosterData) && rosterData.length > 0) {
                rosterHtml = '<div class="rounded-xl border border-slate-200 bg-white p-3"><p class="text-[11px] font-semibold text-slate-400 mb-2">攤位清冊</p><table class="w-full text-left border-collapse">';
                rosterData.forEach((shop, index) => {
                    const stallName   = shop.booth_name || shop.stall_name || shop.name || '無名稱';
                    const boothNo     = shop.booth_no ? ` #${shop.booth_no}` : '';
                    const managerName = shop.manager || shop.manager_name || shop.owner || shop.contact || '-';
                    const managerPhone = shop.phone || shop.manager_phone || '-';
                    const products    = Array.isArray(shop.products) ? shop.products.join('、') : (shop.content || shop.products || '-');
                    rosterHtml += `<tr style="border-bottom:1px solid #e2e8f0">
                        <td class="py-2 pr-2 font-semibold text-slate-800 text-xs w-8">${index + 1}</td>
                        <td class="py-2">
                            <p class="font-semibold text-slate-800 text-xs">${escapeHtml(stallName)}${escapeHtml(boothNo)}</p>
                            <p class="text-slate-500 text-xs mt-0.5">負責人：${escapeHtml(managerName)}　電話：${escapeHtml(managerPhone)}</p>
                            <p class="text-slate-500 text-xs">販售：${escapeHtml(products)}</p>
                        </td>
                    </tr>`;
                });
                rosterHtml += '</table></div>';
            }

            let mapLinkHtml = '';
            if (data.sales_layout_map && String(data.sales_layout_map).trim() !== '') {
                mapLinkHtml = `<a href="${escapeHtml(String(data.sales_layout_map))}" target="_blank" rel="noopener" class="text-indigo-600 underline font-medium text-xs">📋 查看攤位配置圖</a>`;
            }

            const salesDetailHtml = `
                ${buildDetailItem('攤位地點', formatDetailValue(data.sales_location))}
                ${buildDetailItem('攤位數量', formatDetailValue(data.sales_count))}
                ${mapLinkHtml ? `<div class="rounded-xl border border-slate-200 bg-white p-3">${mapLinkHtml}</div>` : ''}
                ${rosterHtml}
            `;

            // 旗幟
            const flagsDetailHtml = `
                ${buildDetailItem('旗幟數量', formatDetailValue(data.flag_count))}
            `;

            return `
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    ${buildDetailItem('活動開始／結束時間', formatTimeRange(data.borrow_start_at, data.borrow_end_at), 'md:col-span-2')}
                    ${buildDetailItem('單位名稱 / 主辦社團', formatDetailValue(data.organization_name))}
                    ${buildDetailItem('活動名稱', formatDetailValue(data.activity_name))}
                    ${buildDetailItem('活動對象人數', formatDetailValue(data.participant_count))}
                    ${buildDetailItem('工作人員人數', formatDetailValue(data.staff_count))}
                    ${buildDetailItem('活動負責人', formatDetailValue(data.activity_coordinator))}
                    ${buildDetailItem('聯絡電話', formatDetailValue(data.coordinator_phone))}
                    ${buildDetailItem('其他聯絡方式', formatDetailValue(data.coordinator_other_contact))}
                    ${buildDetailItem('是否車輛入校', formatYesNo(data.vehicle_entry))}
                    <div class="md:col-span-2 space-y-3">
                        ${buildDetailItem('酒精', formatYesNo(data.has_alcohol))}
                        ${buildToggleSection('明火', hasFire, '有', fireDetailHtml)}
                        ${buildToggleSection('擺攤', hasSales, '有', salesDetailHtml)}
                        ${buildToggleSection('旗幟', hasFlags, '有', flagsDetailHtml)}
                    </div>
                    <div class="md:col-span-2 rounded-xl border border-slate-200 bg-white p-3">
                        <p class="text-[11px] font-semibold text-slate-400 mb-1">企畫書</p>
                        ${proposalHref !== '-' ? `<a href="${escapeHtml(proposalHref)}" target="_blank" rel="noopener" class="text-sm font-medium text-indigo-600 hover:text-indigo-800 hover:underline break-all">${escapeHtml(proposalName)}</a>` : `<p class="text-sm font-medium text-slate-800 whitespace-pre-wrap break-words">${escapeHtml(proposalName)}</p>`}
                    </div>
                    ${buildDetailItem('企劃書上傳時間', formatDetailValue(data.proposal_uploaded_at))}
                    ${buildDetailItem('假日天數', formatDetailValue(data.holiday_fee_count))}
                    ${buildDetailItem('假日費用', formatDetailValue(data.holiday_fee))}
                </div>
            `;
        }
        
        /**
         * 只有點擊「查看詳情」按鈕才會觸發此開窗函式
         */
        function openDrawer(element) {
            const applicationRaw = element.getAttribute('data-application') || '';
            let applicationData = null;
            if (applicationRaw.trim() !== '') {
                try {
                    applicationData = JSON.parse(applicationRaw);
                } catch (e) {
                    applicationData = null;
                }
            }

            const id = element.getAttribute('data-id');
            const name = element.getAttribute('data-borrower-name') || '-';
            const bId = element.getAttribute('data-borrower-id') || '-';
            const email = element.getAttribute('data-borrower-email') || '-';
            const start = element.getAttribute('data-start') || '-';
            const end = element.getAttribute('data-end') || '-';
            const submitted = element.getAttribute('data-submitted') || '-';
            const status = element.getAttribute('data-status') || '-';
            const checkin = element.getAttribute('data-checkin') || '';
            const returned = element.getAttribute('data-return') || '';
            const resources = element.getAttribute('data-resources') || '';
            const phone = element.getAttribute('data-coordinator-phone') || '';
            const orgName = element.getAttribute('data-organization-name') || '';
            const activityName = element.getAttribute('data-activity-name') || '';

            const setText = (id, text) => { const el = document.getElementById(id); if (el) el.innerText = text; };
            setText('drawer-record-id', '單號: #' + id);
            setText('drawer-borrower-name', name);
            setText('drawer-borrower-id', bId);
            setText('drawer-borrower-email', email);
            setText('drawer-submitted-time', submitted);
            setText('drawer-start-time', start);
            setText('drawer-end-time', end);
            const stage = element.getAttribute('data-stage') || '';
            // 不在抽屜顯示審核階段與狀態（留作內部使用）

            const stageInlineWrap = document.getElementById('drawer-stage-inline-wrap');
            const stageInlineEl = document.getElementById('drawer-stage-inline');
            const stageInlineLabel = document.getElementById('drawer-stage-inline-label');
            if (stageInlineWrap && stageInlineEl) {
                if (stage && stage !== '') {
                    if (stageInlineLabel) stageInlineLabel.style.display = 'inline';
                    stageInlineEl.textContent = mapStageName(stage);
                    stageInlineWrap.style.display = 'block';
                } else {
                    stageInlineWrap.style.display = 'none';
                }
            }

            const phoneEl = document.getElementById('drawer-borrower-phone');
            if (phoneEl) phoneEl.innerHTML = phone && phone.trim() !== '' ? ('<a href="tel:' + encodeURIComponent(phone) + '" class="text-indigo-600 font-medium">' + phone + '</a>') : '-';

            const resourcesContainer = document.getElementById('drawer-resources-list');
            if (resources && resources.trim() !== '') {
                resourcesContainer.innerHTML = '<p class="font-bold text-slate-800 text-xs">' + resources + '</p>';
            } else {
                resourcesContainer.innerHTML = '<p class="text-slate-400 text-xs text-center py-2">-</p>';
            }

            const applicationDetailsEl = document.getElementById('drawer-application-details');
            if (applicationDetailsEl) {
                applicationDetailsEl.innerHTML = buildApplicationDetails(applicationData);
            }

            // 原始申請資料顯示功能已移除

            const checkinStatusEl = document.getElementById('drawer-checkin-status');
            if (checkin && checkin !== '0' && checkin !== '') {
                checkinStatusEl.innerHTML = '<i class="fa-solid fa-circle-check text-emerald-500 mr-1.5"></i> 已簽到 (' + checkin + ')';
            } else {
                checkinStatusEl.innerHTML = '<i class="fa-solid fa-circle-minus text-slate-300 mr-1.5"></i> 尚未現場簽到';
            }

            const returnStatusEl = document.getElementById('drawer-return-status');
            if (returned && returned.trim() !== '') {
                returnStatusEl.innerHTML = '<i class="fa-solid fa-circle-check text-emerald-500 mr-1.5"></i> 已點收歸還 (' + returned + ')';
            } else {
                returnStatusEl.innerHTML = '<i class="fa-solid fa-hourglass-start text-amber-500 mr-1.5"></i> 尚未點收歸還';
            }

            const overlay = document.getElementById('drawer-overlay');
            const drawer = document.getElementById('detail-drawer');
            if (overlay && drawer) {
                overlay.classList.remove('hidden');
                setTimeout(() => { overlay.classList.remove('opacity-0'); drawer.classList.remove('translate-x-full'); }, 10);
            }
        }

        function closeDrawer() {
            const overlay = document.getElementById('drawer-overlay');
            const drawer = document.getElementById('detail-drawer');
            if (overlay && drawer) {
                overlay.classList.add('opacity-0');
                drawer.classList.add('translate-x-full');
                setTimeout(() => { overlay.classList.add('hidden'); }, 300);
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            try { closeDrawer(); } catch (e) { /* ignore */ }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeDrawer();
        });
    </script>
</body>
</html>