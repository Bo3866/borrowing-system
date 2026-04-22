<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'lib/PHPMailer/PHPMailer.php';
require 'lib/PHPMailer/SMTP.php';
require 'lib/PHPMailer/Exception.php';

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

$link = mysqli_connect('localhost', 'root', '12345678', 'borrowing_system');
$dbError = '';
if (!$link) {
    $dbError = '資料庫連線失敗：' . mysqli_connect_error();
} else {
    mysqli_set_charset($link, 'utf8mb4');
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

$feedbackMessage = '';
$feedbackType = '';
$reservationOptions = [];

if ($incomingQrToken !== $expectedQrToken) {
    $feedbackMessage = 'QR Code 無效，請使用管理員提供的官方報到 QR Code。';
    $feedbackType = 'error';
}

if ($dbError === '' && $feedbackType !== 'error') {
    $createLogTableSql = "
        CREATE TABLE IF NOT EXISTS checkin_logs (
            checkin_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            reservation_id BIGINT UNSIGNED NOT NULL,
            user_id VARCHAR(10) NOT NULL,
            checked_in_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            checkin_source VARCHAR(20) NOT NULL DEFAULT 'qr',
            PRIMARY KEY (checkin_id),
            UNIQUE KEY uq_checkin_once (reservation_id, user_id),
            KEY idx_checkin_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";

    try {
        if (!mysqli_query($link, $createLogTableSql)) {
            throw new RuntimeException(mysqli_error($link));
        }
    } catch (Throwable $exception) {
        error_log('checkin_logs create skipped: ' . $exception->getMessage());
    }
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
    $selectedReservationId = trim((string)($_POST['reservation_id'] ?? ''));

    if ($selectedReservationId === '') {
        $feedbackMessage = '請選擇要報到的預約。';
        $feedbackType = 'error';
    } else {
        $matchSql = "
            SELECT
                r.reservation_id,
                r.`{$borrowStartColumn}` AS borrow_start_at,
                r.`{$borrowEndColumn}` AS borrow_end_at,
                (SELECT GROUP_CONCAT(s.space_name SEPARATOR '、') FROM space_reservation_items sri JOIN spaces s ON s.space_id = sri.space_id WHERE sri.reservation_id = r.reservation_id) AS space_names,
                (SELECT GROUP_CONCAT(ec.equipment_name SEPARATOR '、') FROM equipment_reservation_items eri JOIN equipments e ON e.equipment_id = eri.equipment_id JOIN equipment_categories ec ON ec.equipment_code = e.equipment_code WHERE eri.reservation_id = r.reservation_id) AS equipment_names
            FROM reservations r
            WHERE r.`{$applicantColumn}` = ?
              AND r.approval_status = 'approved'
              AND r.reservation_id = ?
            LIMIT 1
        ";

        $matchStmt = mysqli_prepare($link, $matchSql);
        if (!$matchStmt) {
            $feedbackMessage = '讀取申請資料失敗：' . mysqli_error($link);
            $feedbackType = 'error';
        } else {
            mysqli_stmt_bind_param($matchStmt, 'si', $currentUserId, $selectedReservationId);
            mysqli_stmt_execute($matchStmt);
            $matchResult = mysqli_stmt_get_result($matchStmt);
            $matchedRow = $matchResult ? mysqli_fetch_assoc($matchResult) : null;
            mysqli_stmt_close($matchStmt);

            if (!$matchedRow) {
                $feedbackMessage = '報到失敗：找不到該筆核准申請，請重新確認。';
                $feedbackType = 'error';
            } else {
                mysqli_begin_transaction($link);

                try {
                    $reservationId = (int)$matchedRow['reservation_id'];

                    $insertLogStmt = mysqli_prepare(
                        $link,
                        'INSERT INTO checkin_logs (reservation_id, user_id, checkin_source) VALUES (?, ?, "qr")'
                    );
                    if (!$insertLogStmt) {
                        throw new RuntimeException('寫入報到紀錄失敗：' . mysqli_error($link));
                    }

                    mysqli_stmt_bind_param($insertLogStmt, 'is', $reservationId, $currentUserId);
                    mysqli_stmt_execute($insertLogStmt);
                    mysqli_stmt_close($insertLogStmt);

                    if ($pickupFlagColumn !== null || $pickupAtColumn !== null) {
                        $setParts = [];
                        if ($pickupFlagColumn !== null) {
                            $setParts[] = "`{$pickupFlagColumn}` = 1";
                        }
                        if ($pickupAtColumn !== null) {
                            $setParts[] = "`{$pickupAtColumn}` = COALESCE(`{$pickupAtColumn}`, NOW())";
                        }

                        if (count($setParts) > 0) {
                            $pickupSql = 'UPDATE reservations SET ' . implode(', ', $setParts) . ' WHERE reservation_id = ?';
                            $pickupStmt = mysqli_prepare($link, $pickupSql);
                            if (!$pickupStmt) {
                                throw new RuntimeException('更新報到狀態失敗：' . mysqli_error($link));
                            }
                            mysqli_stmt_bind_param($pickupStmt, 'i', $reservationId);
                            mysqli_stmt_execute($pickupStmt);
                            mysqli_stmt_close($pickupStmt);
                        }
                    }

                    // 報到成功後，將該預約的所有場地狀態設為 '2' (已借出)
                    $checkinSpaceStmt = mysqli_prepare(
                        $link,
                        'UPDATE spaces s JOIN space_reservation_items sri ON s.space_id = sri.space_id SET s.space_status = "2" WHERE sri.reservation_id = ?'
                    );
                    if ($checkinSpaceStmt) {
                        mysqli_stmt_bind_param($checkinSpaceStmt, 'i', $reservationId);
                        mysqli_stmt_execute($checkinSpaceStmt);
                        mysqli_stmt_close($checkinSpaceStmt);
                    }

                    mysqli_commit($link);
                    $successItems = [];
                    if (!empty($matchedRow['space_names'])) {
                        $successItems[] = '場地：' . $matchedRow['space_names'];
                    }
                    if (!empty($matchedRow['equipment_names'])) {
                        $successItems[] = '器材：' . $matchedRow['equipment_names'];
                    }
                    $itemsStr = implode('；', $successItems);
                    $itemsStr = $itemsStr !== '' ? " ($itemsStr)" : '';
                    $feedbackMessage = '報到成功' . $itemsStr . '。';
                    $feedbackType = 'success';

                    // 發送報到成功通知信
                    $userEmail = '';
                    $userName = $currentUserName;
                    $emailSql = "SELECT email, full_name FROM users WHERE user_id = ?";
                    $emailStmt = mysqli_prepare($link, $emailSql);
                    if ($emailStmt) {
                        mysqli_stmt_bind_param($emailStmt, 's', $currentUserId);
                        mysqli_stmt_execute($emailStmt);
                        $emailRes = mysqli_stmt_get_result($emailStmt);
                        if ($emailRow = mysqli_fetch_assoc($emailRes)) {
                            $userEmail = (string)$emailRow['email'];
                            $userName = (string)$emailRow['full_name'];
                        }
                        mysqli_stmt_close($emailStmt);
                    }

                    if ($userEmail !== '') {
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

                            $mail->setFrom('sasass041919@gmail.com', '校園資源租借系統');
                            $mail->addAddress($userEmail, $userName);

                            $mail->isHTML(true);
                            $mail->Subject = '【系統通知】預約項目報到成功';
                            $mail->Body    = "您好，{$userName}：<br><br>您的借用項目已經成功完成報到。<br><br><b>報到項目：</b><br>{$itemsStr}<br><br>感謝您的使用！";
                            $mail->send();
                        } catch (Exception $e) {
                            error_log("Mailer Error: {$mail->ErrorInfo}");
                        }
                    }
                } catch (Throwable $exception) {
                    mysqli_rollback($link);
                    if ((int)mysqli_errno($link) === 1062) {
                        $feedbackMessage = '你已完成本次申請的報到，請勿重複操作。';
                    } else {
                        $feedbackMessage = '報到失敗：' . $exception->getMessage();
                    }
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
          AND NOT EXISTS (
              SELECT 1
              FROM checkin_logs cl
              WHERE cl.reservation_id = r.reservation_id
                AND cl.user_id = ?
          )
        ORDER BY r.`{$borrowStartColumn}` ASC
    ";

    $optionsStmt = mysqli_prepare($link, $optionsSql);
    if ($optionsStmt) {
        mysqli_stmt_bind_param($optionsStmt, 'ss', $currentUserId, $currentUserId);
        mysqli_stmt_execute($optionsStmt);
        $optionsResult = mysqli_stmt_get_result($optionsStmt);

        while ($optionsResult && ($row = mysqli_fetch_assoc($optionsResult))) {
            $reservationOptions[] = $row;
        }
        mysqli_stmt_close($optionsStmt);
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
</head>
<body>
    <div class="container">
        <nav class="navbar">
            <div class="navbar-brand"><h1>📚 校園資源租借系統</h1></div>
            <div class="navbar-menu">
                <button class="nav-btn" onclick="location.href='index.php'">回首頁</button>
                <button class="nav-btn" onclick="location.href='report_maintenance.php'">報修</button>
                <button class="nav-btn" type="button" disabled><?php echo htmlspecialchars($currentUserName, ENT_QUOTES, 'UTF-8'); ?></button>
                <button class="nav-btn" onclick="location.href='logout.php'">登出</button>
            </div>
        </nav>

        <main class="main-content">
            <section class="card checkin-form-card">
                <h2>掃碼報到</h2>
                <p>請勾選你目前所在場地，系統會自動比對你的核准申請與場地是否一致。</p>

                <?php if ($dbError !== '') { ?>
                    <div class="login-alert"><?php echo htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } elseif ($feedbackMessage !== '') { ?>
                    <div class="<?php echo $feedbackType === 'success' ? 'borrow-success' : 'login-alert'; ?>">
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
