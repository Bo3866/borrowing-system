<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'lib/PHPMailer/PHPMailer.php';
require 'lib/PHPMailer/SMTP.php';
require 'lib/PHPMailer/Exception.php';
require_once __DIR__ . '/config/database.php';

session_start();

$expectedQrToken = 'CHECKIN_GATE_V1';
$incomingQrToken = trim((string)($_GET['qr'] ?? ''));

if (!isset($_SESSION['user_id'])) {
    if ($incomingQrToken !== '') {
        $_SESSION['pending_checkin_qr'] = $incomingQrToken;
    }
    header('Location: login.php?next=checkin.php');
    exit;
}

$currentUserId = (string)$_SESSION['user_id'];
$currentUserName = (string)($_SESSION['full_name'] ?? $_SESSION['user_id']);

if ($incomingQrToken === '' && isset($_SESSION['pending_checkin_qr'])) {
    $incomingQrToken = trim((string)$_SESSION['pending_checkin_qr']);
}
unset($_SESSION['pending_checkin_qr']);

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

$feedbackMessage = '';
$feedbackType = '';
$spaceOptions = [];
$equipmentOptions = [];
$reservationOptions = [];
// 用來記錄最後一次報到類型，決定哪個面板顯示回饋訊息：'equipment' 或 'space'
$lastCheckinType = '';

if ($incomingQrToken !== $expectedQrToken) {
    $feedbackMessage = 'QR Code 無效，請使用管理員提供的官方報到 QR Code。';
    $feedbackType = 'error';
}

if ($dbError === '' && $feedbackType !== 'error') {
    // 使用 reservations.checked_in_at 欄位來紀錄報到時間（checkin_logs 表已移除）
}

$applicantColumn = null;
$borrowStartColumn = null;
$borrowEndColumn = null;
$pickupFlagColumn = null;
$pickupAtColumn = null;

if ($dbError === '' && $feedbackType !== 'error') {
    $reservationColumns = [];
    $columnResult = mysqli_query($link, 'SHOW COLUMNS FROM reservations');
    if ($columnResult) {
        while ($columnRow = mysqli_fetch_assoc($columnResult)) {
            $reservationColumns[] = (string)$columnRow['Field'];
        }
    }

    // 固定使用 `user_id` 作為申請人欄位
    $applicantColumn = 'user_id';
    $borrowStartColumn = pickExistingColumn($reservationColumns, ['borrow_start_at', 'borrow_start_time']);
    $borrowEndColumn = pickExistingColumn($reservationColumns, ['borrow_end_at', 'borrow_ene_at', 'borrow_end_time']);
    $pickupFlagColumn = pickExistingColumn($reservationColumns, ['pickup_confirmed', 'is_picked_up', 'picked_up', 'pickup_status']);
    $pickupAtColumn = pickExistingColumn($reservationColumns, ['pickup_confirmed_at', 'picked_up_at', 'pickup_at']);

    if (!in_array($applicantColumn, $reservationColumns, true)) {
        $dbError = 'reservations 缺少 user_id，無法比對申請人。';
    } elseif ($borrowStartColumn === null || $borrowEndColumn === null) {
        $dbError = 'reservations 缺少借用時段欄位，無法進行報到判斷。';
    }
}

if ($dbError === '' && $feedbackType !== 'error' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // 使用 hidden 欄位 `checkin_kind` 區分器材或場地報到，當沒有傳入特定 equipment_id 時，視為以 reservation_id 為單位的報到
    $post = filter_input_array(INPUT_POST, FILTER_SANITIZE_STRING) ?: [];
    $checkinKind = trim((string)($post['checkin_kind'] ?? ''));
    $selectedReservationId = trim((string)($post['reservation_id'] ?? ''));
    $selectedEquipmentId = trim((string)($post['equipment_id'] ?? ''));
    $selectedSpaceId = trim((string)($post['space_id'] ?? ''));

    if ($checkinKind === 'equipment') {
        if ($selectedReservationId === '') {
            $feedbackMessage = '請選擇要報到的預約（器材）。';
            $feedbackType = 'error';
        } else {
            // 若提供 equipment_id，檢查該器材是否屬於該預約；否則直接以 reservation 為單位記錄器材報到
            if ($selectedEquipmentId !== '') {
                $matchSql = "
                    SELECT r.reservation_id, (SELECT GROUP_CONCAT(ec.equipment_name SEPARATOR '、') FROM equipment_reservation_items eri JOIN equipments e ON e.equipment_id = eri.equipment_id JOIN equipment_categories ec ON ec.equipment_code = e.equipment_code WHERE eri.reservation_id = r.reservation_id) AS equipment_names
                    FROM reservations r
                    JOIN equipment_reservation_items eri ON eri.reservation_id = r.reservation_id
                    WHERE r.`{$applicantColumn}` = ?
                      AND r.approval_status = 'approved'
                      AND r.reservation_id = ?
                      AND eri.equipment_id = ?
                    LIMIT 1
                ";

                $matchStmt = mysqli_prepare($link, $matchSql);
                if (!$matchStmt) {
                    $feedbackMessage = '讀取申請資料失敗：' . mysqli_error($link);
                    $feedbackType = 'error';
                } else {
                    mysqli_stmt_bind_param($matchStmt, 'sss', $currentUserId, $selectedReservationId, $selectedEquipmentId);
                    mysqli_stmt_execute($matchStmt);
                    $matchResult = mysqli_stmt_get_result($matchStmt);
                    $matchedRow = $matchResult ? mysqli_fetch_assoc($matchResult) : null;
                    mysqli_stmt_close($matchStmt);
                }

                if (empty($matchedRow)) {
                    $feedbackMessage = '報到失敗：找不到該筆核准器材申請，請重新確認。';
                    $feedbackType = 'error';
                } else {
                    mysqli_begin_transaction($link);
                    try {
                        $reservationId = (int)$matchedRow['reservation_id'];
                        $updateCheckinStmt = mysqli_prepare($link, 'UPDATE reservations SET checked_in_at = COALESCE(checked_in_at, NOW()) WHERE reservation_id = ? AND user_id COLLATE utf8mb4_unicode_ci = ?');
                        if (!$updateCheckinStmt) { throw new RuntimeException('更新 reservations.checked_in_at 失敗：' . mysqli_error($link)); }
                        mysqli_stmt_bind_param($updateCheckinStmt, 'is', $reservationId, $currentUserId);
                        mysqli_stmt_execute($updateCheckinStmt);
                        mysqli_stmt_close($updateCheckinStmt);
                        // 更新 pickup 欄位如有
                        if ($pickupFlagColumn !== null || $pickupAtColumn !== null) {
                            $setParts = [];
                            if ($pickupFlagColumn !== null) { $setParts[] = "`{$pickupFlagColumn}` = 1"; }
                            if ($pickupAtColumn !== null) { $setParts[] = "`{$pickupAtColumn}` = COALESCE(`{$pickupAtColumn}`, NOW())"; }
                            if (count($setParts) > 0) {
                                $pickupSql = 'UPDATE reservations SET ' . implode(', ', $setParts) . ' WHERE reservation_id = ?';
                                $pickupStmt = mysqli_prepare($link, $pickupSql);
                                if (!$pickupStmt) { throw new RuntimeException('更新報到狀態失敗：' . mysqli_error($link)); }
                                mysqli_stmt_bind_param($pickupStmt, 'i', $reservationId);
                                mysqli_stmt_execute($pickupStmt);
                                mysqli_stmt_close($pickupStmt);
                            }
                        }
                        mysqli_commit($link);
                        $itemsStr = !empty($matchedRow['equipment_names']) ? '（器材：' . $matchedRow['equipment_names'] . '）' : '';
                        $feedbackMessage = '器材報到成功' . $itemsStr . '。';
                        $feedbackType = 'success';
                        $lastCheckinType = 'equipment';
                    } catch (Throwable $exception) {
                        mysqli_rollback($link);
                        $feedbackMessage = '報到失敗：' . $exception->getMessage();
                        $feedbackType = 'error';
                    }
                }
            } else {
                // 以 reservation 為單位的器材報到（不指定某個 equipment_id）
                $matchSql = "SELECT reservation_id, (SELECT GROUP_CONCAT(ec.equipment_name SEPARATOR '、') FROM equipment_reservation_items eri JOIN equipments e ON e.equipment_id = eri.equipment_id JOIN equipment_categories ec ON ec.equipment_code = e.equipment_code WHERE eri.reservation_id = r.reservation_id) AS equipment_names FROM reservations r WHERE r.`{$applicantColumn}` = ? AND r.approval_status = 'approved' AND r.reservation_id = ? LIMIT 1";
                $matchStmt = mysqli_prepare($link, $matchSql);
                if ($matchStmt) {
                    mysqli_stmt_bind_param($matchStmt, 'ss', $currentUserId, $selectedReservationId);
                    mysqli_stmt_execute($matchStmt);
                    $matchResult = mysqli_stmt_get_result($matchStmt);
                    $matchedRow = $matchResult ? mysqli_fetch_assoc($matchResult) : null;
                    mysqli_stmt_close($matchStmt);
                }
                if (empty($matchedRow)) {
                    $feedbackMessage = '報到失敗：找不到該筆核准申請，請重新確認。';
                    $feedbackType = 'error';
                } else {
                    mysqli_begin_transaction($link);
                    try {
                        $reservationId = (int)$matchedRow['reservation_id'];
                        $updateCheckinStmt = mysqli_prepare($link, 'UPDATE reservations SET checked_in_at = COALESCE(checked_in_at, NOW()) WHERE reservation_id = ? AND user_id COLLATE utf8mb4_unicode_ci = ?');
                        if (!$updateCheckinStmt) { throw new RuntimeException('更新 reservations.checked_in_at 失敗：' . mysqli_error($link)); }
                        mysqli_stmt_bind_param($updateCheckinStmt, 'is', $reservationId, $currentUserId);
                        mysqli_stmt_execute($updateCheckinStmt);
                        mysqli_stmt_close($updateCheckinStmt);
                        // update pickup
                        if ($pickupFlagColumn !== null || $pickupAtColumn !== null) {
                            $setParts = [];
                            if ($pickupFlagColumn !== null) { $setParts[] = "`{$pickupFlagColumn}` = 1"; }
                            if ($pickupAtColumn !== null) { $setParts[] = "`{$pickupAtColumn}` = COALESCE(`{$pickupAtColumn}`, NOW())"; }
                            if (count($setParts) > 0) {
                                $pickupSql = 'UPDATE reservations SET ' . implode(', ', $setParts) . ' WHERE reservation_id = ?';
                                $pickupStmt = mysqli_prepare($link, $pickupSql);
                                if (!$pickupStmt) { throw new RuntimeException('更新報到狀態失敗：' . mysqli_error($link)); }
                                mysqli_stmt_bind_param($pickupStmt, 'i', $reservationId);
                                mysqli_stmt_execute($pickupStmt);
                                mysqli_stmt_close($pickupStmt);
                            }
                        }
                        mysqli_commit($link);
                        $itemsStr = !empty($matchedRow['equipment_names']) ? '（器材：' . $matchedRow['equipment_names'] . '）' : '';
                        $feedbackMessage = '器材報到成功' . $itemsStr . '。';
                        $feedbackType = 'success';
                        $lastCheckinType = 'equipment';
                    } catch (Throwable $exception) {
                        mysqli_rollback($link);
                        $feedbackMessage = '報到失敗：' . $exception->getMessage();
                        $feedbackType = 'error';
                    }
                }
            }
        }
    } else {
        // 場地報到
        if ($selectedReservationId === '' && $selectedSpaceId === '') {
            $feedbackMessage = '請先勾選你目前所在的場地或選擇預約。';
            $feedbackType = 'error';
        } else {
            if ($selectedSpaceId !== '') {
                $matchSql = "
                    SELECT r.reservation_id, r.`{$borrowStartColumn}` AS borrow_start_at, r.`{$borrowEndColumn}` AS borrow_end_at, s.space_id, s.space_name
                    FROM reservations r
                    JOIN space_reservation_items sri ON sri.reservation_id = r.reservation_id
                    JOIN spaces s ON s.space_id = sri.space_id
                    WHERE r.`{$applicantColumn}` = ?
                      AND r.approval_status = 'approved'
                      AND s.space_id = ?
                    ORDER BY r.`{$borrowStartColumn}` DESC
                    LIMIT 1
                ";

                $matchStmt = mysqli_prepare($link, $matchSql);
                if ($matchStmt) {
                    mysqli_stmt_bind_param($matchStmt, 'ss', $currentUserId, $selectedSpaceId);
                    mysqli_stmt_execute($matchStmt);
                    $matchResult = mysqli_stmt_get_result($matchStmt);
                    $matchedRow = $matchResult ? mysqli_fetch_assoc($matchResult) : null;
                    mysqli_stmt_close($matchStmt);
                }
            } else {
                $matchSql = "
                    SELECT r.reservation_id, r.`{$borrowStartColumn}` AS borrow_start_at, r.`{$borrowEndColumn}` AS borrow_end_at, (SELECT GROUP_CONCAT(s.space_name SEPARATOR '、') FROM space_reservation_items sri JOIN spaces s ON s.space_id = sri.space_id WHERE sri.reservation_id = r.reservation_id) AS space_names
                    FROM reservations r
                    WHERE r.`{$applicantColumn}` = ?
                      AND r.approval_status = 'approved'
                      AND r.reservation_id = ?
                    LIMIT 1
                ";

                $matchStmt = mysqli_prepare($link, $matchSql);
                if ($matchStmt) {
                    mysqli_stmt_bind_param($matchStmt, 'ss', $currentUserId, $selectedReservationId);
                    mysqli_stmt_execute($matchStmt);
                    $matchResult = mysqli_stmt_get_result($matchStmt);
                    $matchedRow = $matchResult ? mysqli_fetch_assoc($matchResult) : null;
                    mysqli_stmt_close($matchStmt);
                }
            }

            if (empty($matchedRow)) {
                $feedbackMessage = '報到失敗：你選擇的場地或預約與你的核准申請不符，請重新確認。';
                $feedbackType = 'error';
            } else {
                mysqli_begin_transaction($link);
                try {
                    $reservationId = (int)$matchedRow['reservation_id'];
                    $updateCheckinStmt = mysqli_prepare($link, 'UPDATE reservations SET checked_in_at = COALESCE(checked_in_at, NOW()) WHERE reservation_id = ? AND user_id COLLATE utf8mb4_unicode_ci = ?');
                    if (!$updateCheckinStmt) { throw new RuntimeException('更新 reservations.checked_in_at 失敗：' . mysqli_error($link)); }
                    mysqli_stmt_bind_param($updateCheckinStmt, 'is', $reservationId, $currentUserId);
                    mysqli_stmt_execute($updateCheckinStmt);
                    mysqli_stmt_close($updateCheckinStmt);

                    if ($pickupFlagColumn !== null || $pickupAtColumn !== null) {
                        $setParts = [];
                        if ($pickupFlagColumn !== null) { $setParts[] = "`{$pickupFlagColumn}` = 1"; }
                        if ($pickupAtColumn !== null) { $setParts[] = "`{$pickupAtColumn}` = COALESCE(`{$pickupAtColumn}`, NOW())"; }
                        if (count($setParts) > 0) {
                            $pickupSql = 'UPDATE reservations SET ' . implode(', ', $setParts) . ' WHERE reservation_id = ?';
                            $pickupStmt = mysqli_prepare($link, $pickupSql);
                            if (!$pickupStmt) { throw new RuntimeException('更新報到狀態失敗：' . mysqli_error($link)); }
                            mysqli_stmt_bind_param($pickupStmt, 'i', $reservationId);
                            mysqli_stmt_execute($pickupStmt);
                            mysqli_stmt_close($pickupStmt);
                        }
                    }

                    if (!empty($spaceParam)) {
                        $checkinSpaceStmt = mysqli_prepare($link, 'UPDATE spaces s JOIN space_reservation_items sri ON s.space_id = sri.space_id SET s.space_status = "2" WHERE sri.reservation_id = ?');
                        if ($checkinSpaceStmt) {
                            mysqli_stmt_bind_param($checkinSpaceStmt, 'i', $reservationId);
                            mysqli_stmt_execute($checkinSpaceStmt);
                            mysqli_stmt_close($checkinSpaceStmt);
                        }
                    }

                    mysqli_commit($link);
                    $itemsStr = !empty($matchedRow['space_name'] ?? $matchedRow['space_names']) ? '（場地：' . ($matchedRow['space_name'] ?? $matchedRow['space_names']) . '）' : '';
                    $feedbackMessage = '報到成功' . $itemsStr . '。';
                    $feedbackType = 'success';
                    $lastCheckinType = 'space';
                } catch (Throwable $exception) {
                    mysqli_rollback($link);
                    $feedbackMessage = '報到失敗：' . $exception->getMessage();
                    $feedbackType = 'error';
                }
            }
        }
    }
}

                

if ($dbError === '' && $feedbackType !== 'error') {
    $optionsSql = "
        SELECT 
            r.reservation_id,
            r.`{$borrowStartColumn}` AS borrow_start_at,
            r.`{$borrowEndColumn}` AS borrow_end_at,
            (SELECT GROUP_CONCAT(s.space_name SEPARATOR '、') FROM space_reservation_items sri JOIN spaces s ON s.space_id = sri.space_id WHERE sri.reservation_id = r.reservation_id) AS space_names,
            (SELECT GROUP_CONCAT(ec.equipment_name SEPARATOR '、') FROM equipment_reservation_items eri JOIN equipments e ON e.equipment_id = eri.equipment_id JOIN equipment_categories ec ON ec.equipment_code = e.equipment_code WHERE eri.reservation_id = r.reservation_id) AS equipment_names
        FROM reservations r
        WHERE r.`{$applicantColumn}` = ?
          AND r.approval_status = 'approved'
                    -- 時間限制已移除：允許在任何時間對核准的預約進行報到
                    AND r.checked_in_at IS NULL
        ORDER BY r.`{$borrowStartColumn}` ASC
    ";

    $optionsStmt = mysqli_prepare($link, $optionsSql);
    if ($optionsStmt) {
                mysqli_stmt_bind_param($optionsStmt, 's', $currentUserId);
        mysqli_stmt_execute($optionsStmt);
        $optionsResult = mysqli_stmt_get_result($optionsStmt);

        while ($optionsResult && ($row = mysqli_fetch_assoc($optionsResult))) {
            $reservationOptions[] = $row;
        }
        mysqli_stmt_close($optionsStmt);
    }

    // 取得可報到的器材清單（從核准的預約中）
    $equipSql = "
        SELECT DISTINCT e.equipment_id, ec.equipment_name, e.equipment_code
        FROM reservations r
        JOIN equipment_reservation_items eri ON eri.reservation_id = r.reservation_id
        JOIN equipments e ON e.equipment_id = eri.equipment_id
        LEFT JOIN equipment_categories ec ON e.equipment_code = ec.equipment_code
        WHERE r.`{$applicantColumn}` = ?
          AND r.approval_status = 'approved'
        ORDER BY ec.equipment_name ASC
    ";

    $equipStmt = mysqli_prepare($link, $equipSql);
    if ($equipStmt) {
        mysqli_stmt_bind_param($equipStmt, 's', $currentUserId);
        mysqli_stmt_execute($equipStmt);
        $equipResult = mysqli_stmt_get_result($equipStmt);
        while ($equipResult && ($row = mysqli_fetch_assoc($equipResult))) {
            $equipmentOptions[] = [
                'equipment_id' => (string)$row['equipment_id'],
                'equipment_name' => (string)($row['equipment_name'] ?? ''),
                'equipment_code' => (string)($row['equipment_code'] ?? ''),
            ];
        }
        mysqli_stmt_close($equipStmt);
    }
}

if ($link) {
    mysqli_close($link);
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>掃碼報到｜校園資源租借系統</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        :root {
            /* Variables tied to styles.css primary colors */
            --primary: #2c3e50;
            --primary-offset: #1a252f;
            --secondary: #3498db;
            --secondary-offset: #2980b9;
            --success: #27ae60;
            --danger: #e74c3c;
            --muted: #7f8c8d;
            --card-border: #e2e8f0;
            --focus-ring: rgba(52, 152, 219, 0.2);
        }
        
        .two-column {
            display: flex;
            gap: 1.5rem;
            align-items: stretch;
            margin-top: 1rem;
        }
        .two-column .column { flex: 1 1 0%; }
        
        @media (max-width: 900px) {
            .two-column { flex-direction: column; }
        }

        /* Card Unified Style */
        .checkin-card {
            background: #ffffff;
            border-radius: var(--border-radius, 8px);
            padding: 2.5rem;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02), 0 10px 15px -3px rgba(0,0,0,0.03);
            border: 1px solid var(--card-border);
            height: 100%;
            display: flex;
            flex-direction: column;
            transition: all 0.3s ease;
        }
        
        .checkin-card:hover {
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05), 0 8px 10px -6px rgba(0,0,0,0.01);
            border-color: #cbd5e1;
        }

        .checkin-card h2 { 
            margin: 0 0 0.5rem 0; 
            font-size: 1.6rem; 
            font-weight: 600;
            color: var(--primary); 
            position: relative;
            display: inline-block;
            padding-bottom: 0.5rem;
            border-bottom: none; /* override styles.css */
        }
        
        .checkin-card h2::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 40px;
            height: 3px;
            background: var(--secondary);
            border-radius: 2px;
        }

        .checkin-card > p { color: var(--muted); font-size: 0.95rem; margin: 1rem 0 2rem 0; line-height: 1.6; }

        .form-group { margin-bottom: 1.5rem; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 0.5rem; color: var(--primary); font-size: 0.95rem; }
        
        select, input {
            width: 100%; padding: 12px 14px; border: 1px solid #cbd5e1;
            border-radius: var(--border-radius, 8px); background: #f8fafc; font-size: 1rem; color: #2c3e50;
            transition: all 0.2s ease;
            appearance: none;
        }
        select:focus, input:focus { 
            outline: none; 
            border-color: var(--secondary); 
            background: #ffffff;
            box-shadow: 0 0 0 4px var(--focus-ring); 
        }

        .hero-actions.mt-auto { margin-top: auto; }
        
        .hero-actions {
            margin-top: auto;
            display: flex;
            gap: 10px;
        }
        
        .hero-actions button {
            flex: 1; padding: 12px 0; border-radius: var(--border-radius, 8px); font-weight: 600; font-size: 0.95rem;
            cursor: pointer; transition: all 0.2s ease;
        }
        
        /* Action Buttons Unified */
        .btn-primary { 
            background: var(--secondary); color: white; border: 1px solid var(--secondary); 
        }
        .btn-primary:hover:not(:disabled) { 
            background: var(--secondary-offset); 
            border-color: var(--secondary-offset);
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .btn-secondary { 
            background: #ffffff; border: 1px solid var(--secondary); color: var(--secondary); 
        }
        .btn-secondary:hover { 
            background: #f8fbff;
            transform: translateY(-1px);
        }
        .btn-primary:disabled { opacity: 0.6; cursor: not-allowed; transform: none; box-shadow: none; }

        /* Alerts */
        .alert-box {
            padding: 12px 16px; border-radius: var(--border-radius, 8px); margin-bottom: 1.5rem; font-size: 0.95rem; line-height: 1.5; font-weight: 500;
        }
        .login-alert { background: #fdf2f2; color: var(--danger); border-left: 4px solid var(--danger); }
        .borrow-success { background: #f0fdf4; color: var(--success); border-left: 4px solid var(--success); }
        .checkin-empty-hint { color: var(--muted); font-size: 0.9rem; margin-top: 1rem; text-align: center; }
    </style>
 </head>
<body>
    <!-- 導覽列 放到 container 之外以讓背景拉滿整個視窗 -->
    <nav class="navbar">
        <div class="navbar-brand"><h1>📚 校園資源租借系統</h1></div>
        <div class="navbar-menu">
            <button class="nav-btn" onclick="location.href='index.php'">回首頁</button>
            <button class="nav-btn" onclick="location.href='report_maintenance.php'">報修</button>
            <button class="nav-btn" type="button" disabled><?php echo htmlspecialchars($currentUserName, ENT_QUOTES, 'UTF-8'); ?></button>
            <button class="nav-btn" onclick="location.href='logout.php'">登出</button>
        </div>
    </nav>

    <div class="container">

        <main class="main-content">
            <div class="two-column">
                <!-- Equipment Check-in Panel -->
                <div class="column">
                    <section class="checkin-card">
                        <h2>器材報到</h2>
                        <p>從你已核准的申請中選擇要提領的器材，將狀態更新為已報到，或前往列表查看詳細資訊。</p>

                        <?php if ($dbError !== '') { ?>
                            <div class="alert-box login-alert"><?php echo htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php } elseif ($feedbackMessage !== '') { ?>
                            <div class="alert-box <?php echo $feedbackType === 'success' ? 'borrow-success' : 'login-alert'; ?>">
                                <?php echo htmlspecialchars($feedbackMessage, ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                        <?php } ?>

<?php if ($dbError === '' && $incomingQrToken === $expectedQrToken) { 
                    $spaceOptions = [];
                    $equipmentOptions = [];
                    foreach ($reservationOptions as $res) {
                        if (!empty($res['space_names'])) {
                            $spaceOptions[] = $res;
                        }
                        if (!empty($res['equipment_names'])) {
                            $equipmentOptions[] = $res;
                        }
                    }
                ?>
                    <style>
                        .checkin-split-layout {
                            display: flex;
                            gap: 2rem;
                            flex-wrap: wrap;
                            margin-top: 1.5rem;
                        }
                        .checkin-column {
                            flex: 1;
                            min-width: 300px;
                            background: #f9fafb;
                            border: 1px solid #e2e8f0;
                            border-radius: 8px;
                            padding: 1.5rem;
                        }
                        .checkin-column h3 {
                            margin-top: 0;
                            color: #2c3e50;
                            border-bottom: 2px solid #e2e8f0;
                            padding-bottom: 0.5rem;
                            margin-bottom: 1rem;
                        }
                        .checkin-column .btn-primary {
                            width: 100%;
                            margin-top: 1rem;
                        }
                    </style>

                    <div class="checkin-split-layout">
                        <!-- 場地報到 -->
                        <div class="checkin-column">
                            <h3>🏢 場地報到</h3>
                            <form method="post" class="checkin-form">
                                <input type="hidden" name="checkin_kind" value="space">
                                <div class="form-group">
                                    <label for="reservation_id_space">請選擇場地：</label>
                                    <select id="reservation_id_space" name="reservation_id" required>
                                        <option value="">請選擇預約</option>
                                        <?php foreach ($spaceOptions as $res) { ?>
                                            <option value="<?php echo htmlspecialchars((string)$res['reservation_id'], ENT_QUOTES, 'UTF-8'); ?>">
                                                <?php
                                                    $timeStr = date('m/d H:i', strtotime($res['borrow_start_at']));
                                                    $label = "[{$timeStr}] 場地: " . $res['space_names'];
                                                    echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
                                                ?>
                                            </option>
                                        <?php } ?>
                                    </select>
                                </div>
                                <button class="btn-primary" type="submit" <?php echo count($spaceOptions) === 0 ? 'disabled' : ''; ?>>場地報到</button>
                            </form>
                            <?php if (count($spaceOptions) === 0) { ?>
                                <div style="margin-top: 1rem; color: #7f8c8d; font-size: 0.9rem;">目前沒有待報到的場地核准申請。</div>
                            <?php } ?>
                        </div>

                        <!-- 器材報到 -->
                        <div class="checkin-column">
                            <h3>📦 器材報到</h3>
                            <form method="post" class="checkin-form">
                                <input type="hidden" name="checkin_kind" value="equipment">
                                <div class="form-group">
                                    <label for="reservation_id_eq">請選擇器材：</label>
                                    <select id="reservation_id_eq" name="reservation_id" required>
                                        <option value="">請選擇預約</option>
                                        <?php foreach ($equipmentOptions as $res) { ?>
                                            <option value="<?php echo htmlspecialchars((string)$res['reservation_id'], ENT_QUOTES, 'UTF-8'); ?>">
                                                <?php
                                                    $timeStr = date('m/d H:i', strtotime($res['borrow_start_at']));
                                                    $label = "[{$timeStr}] 器材: " . $res['equipment_names'];
                                                    echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
                                                ?>
                                            </option>
                                        <?php } ?>
                                    </select>
                                </div>
                                <button class="btn-primary" type="submit" <?php echo count($equipmentOptions) === 0 ? 'disabled' : ''; ?>>器材報到</button>
                            </form>
                            <?php if (count($equipmentOptions) === 0) { ?>
                                <div style="margin-top: 1rem; color: #7f8c8d; font-size: 0.9rem;">目前沒有待領取的器材核准申請。</div>
                            <?php } ?>
                        </div>
                    </div>

                    <div class="hero-actions" style="margin-top: 2rem; justify-content: center;">
                        <button class="btn-secondary" type="button" onclick="location.href='index.php'">返回首頁</button>
                    </div>

                <?php } ?>
            </section>
        </main>
    </div>
</body>
</html>
