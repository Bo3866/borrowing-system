<?php
declare(strict_types=1);

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

$link = mysqli_connect('localhost', 'root', '', 'borrowing_system',3307);
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
$spaceOptions = [];

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
            checked_in_space_id VARCHAR(30) NOT NULL,
            checked_in_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            checkin_source VARCHAR(20) NOT NULL DEFAULT 'qr',
            PRIMARY KEY (checkin_id),
            UNIQUE KEY uq_checkin_once (reservation_id, user_id),
            KEY idx_checkin_user (user_id),
            KEY idx_checkin_space (checked_in_space_id)
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
    $selectedSpaceId = trim((string)($_POST['space_id'] ?? ''));

    if ($selectedSpaceId === '') {
        $feedbackMessage = '請先勾選你目前所在的場地。';
        $feedbackType = 'error';
    } else {
        $matchSql = "
            SELECT
                r.reservation_id,
                r.`{$borrowStartColumn}` AS borrow_start_at,
                r.`{$borrowEndColumn}` AS borrow_end_at,
                s.space_id,
                s.space_name
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
        if (!$matchStmt) {
            $feedbackMessage = '讀取申請資料失敗：' . mysqli_error($link);
            $feedbackType = 'error';
        } else {
            mysqli_stmt_bind_param($matchStmt, 'ss', $currentUserId, $selectedSpaceId);
            mysqli_stmt_execute($matchStmt);
            $matchResult = mysqli_stmt_get_result($matchStmt);
            $matchedRow = $matchResult ? mysqli_fetch_assoc($matchResult) : null;
            mysqli_stmt_close($matchStmt);

            if (!$matchedRow) {
                $feedbackMessage = '報到失敗：你勾選的場地與你的核准申請不匹配，請重新確認場地。';
                $feedbackType = 'error';
            } else {
                mysqli_begin_transaction($link);

                try {
                    $reservationId = (int)$matchedRow['reservation_id'];

                    $insertLogStmt = mysqli_prepare(
                        $link,
                        'INSERT INTO checkin_logs (reservation_id, user_id, checked_in_space_id, checkin_source) VALUES (?, ?, ?, "qr")'
                    );
                    if (!$insertLogStmt) {
                        throw new RuntimeException('寫入報到紀錄失敗：' . mysqli_error($link));
                    }

                    mysqli_stmt_bind_param($insertLogStmt, 'iss', $reservationId, $currentUserId, $selectedSpaceId);
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

                    mysqli_commit($link);
                    $feedbackMessage = '報到成功：' . ((string)($matchedRow['space_name'] ?? $selectedSpaceId)) . '。';
                    $feedbackType = 'success';
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
        SELECT DISTINCT
            s.space_id,
            s.space_name
        FROM reservations r
        JOIN space_reservation_items sri ON sri.reservation_id = r.reservation_id
        JOIN spaces s ON s.space_id = sri.space_id
        WHERE r.`{$applicantColumn}` = ?
          AND r.approval_status = 'approved'
          -- 時間限制已移除：允許在任何時間對核准的預約進行報到
          AND NOT EXISTS (
              SELECT 1
              FROM checkin_logs cl
              WHERE cl.reservation_id = r.reservation_id
                AND cl.user_id = ?
          )
                ORDER BY s.space_id ASC
    ";

    $optionsStmt = mysqli_prepare($link, $optionsSql);
    if ($optionsStmt) {
        mysqli_stmt_bind_param($optionsStmt, 'ss', $currentUserId, $currentUserId);
        mysqli_stmt_execute($optionsStmt);
        $optionsResult = mysqli_stmt_get_result($optionsStmt);

        while ($optionsResult && ($row = mysqli_fetch_assoc($optionsResult))) {
            $spaceOptions[] = [
                'space_id' => (string)$row['space_id'],
                'space_name' => (string)($row['space_name'] ?? ''),
            ];
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

                <?php if ($dbError === '' && $incomingQrToken === $expectedQrToken) { ?>
                    <form method="post" class="checkin-form">
                        <div class="form-group">
                            <label for="space_id">我目前在以下場地：</label>
                            <select id="space_id" name="space_id" required>
                                <option value="">請選擇場地</option>
                                <?php foreach ($spaceOptions as $space) { ?>
                                    <option value="<?php echo htmlspecialchars($space['space_id'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php
                                            $label = $space['space_id'];
                                            if ($space['space_name'] !== '') {
                                                $label .= ' - ' . $space['space_name'];
                                            }
                                            echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
                                        ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </div>

                        <div class="hero-actions">
                            <button class="btn-primary" type="submit" <?php echo count($spaceOptions) === 0 ? 'disabled' : ''; ?>>送出報到</button>
                            <button class="btn-secondary" type="button" onclick="location.href='index.php'">返回首頁</button>
                        </div>
                    </form>

                    <?php if (count($spaceOptions) === 0) { ?>
                        <div class="checkin-empty-hint">目前沒有可報到的核准場地申請，請確認你的借用時段與審核狀態。</div>
                    <?php } ?>
                <?php } ?>
            </section>
        </main>
    </div>
</body>
</html>
