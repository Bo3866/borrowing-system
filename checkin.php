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

$link = mysqli_connect('localhost', 'root', '12345678', 'borrowing_system',3306

);
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
$equipmentOptions = [];
// 用來記錄最後一次報到類型，決定哪個面板顯示回饋訊息：'equipment' 或 'space'
$lastCheckinType = '';

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
    $checkinType = trim((string)($_POST['checkin_type'] ?? ''));
    $lastCheckinType = $checkinType;

    // 器材報到流程
    if ($checkinType === 'equipment') {
        $selectedEquipmentId = trim((string)($_POST['equipment_id'] ?? ''));

        if ($selectedEquipmentId === '') {
            $feedbackMessage = '請先選擇要報到的器材。';
            $feedbackType = 'error';
        } else {
            $matchSql = "
                SELECT
                    r.reservation_id,
                    r.`{$borrowStartColumn}` AS borrow_start_at,
                    r.`{$borrowEndColumn}` AS borrow_end_at,
                    e.equipment_id,
                    ec.equipment_name
                FROM reservations r
                JOIN equipment_reservation_items eri ON eri.reservation_id = r.reservation_id
                JOIN equipments e ON e.equipment_id = eri.equipment_id
                LEFT JOIN equipment_categories ec ON e.equipment_code = ec.equipment_code
                WHERE r.`{$applicantColumn}` = ?
                  AND r.approval_status = 'approved'
                  AND e.equipment_id = ?
                ORDER BY r.`{$borrowStartColumn}` DESC
                LIMIT 1
            ";

            $matchStmt = mysqli_prepare($link, $matchSql);
            if (!$matchStmt) {
                $feedbackMessage = '讀取申請資料失敗：' . mysqli_error($link);
                $feedbackType = 'error';
            } else {
                mysqli_stmt_bind_param($matchStmt, 'ss', $currentUserId, $selectedEquipmentId);
                mysqli_stmt_execute($matchStmt);
                $matchResult = mysqli_stmt_get_result($matchStmt);
                $matchedRow = $matchResult ? mysqli_fetch_assoc($matchResult) : null;
                mysqli_stmt_close($matchStmt);

                if (!$matchedRow) {
                    $feedbackMessage = '器材報到失敗：你選擇的器材不在你的核准申請中。';
                    $feedbackType = 'error';
                } else {
                    mysqli_begin_transaction($link);

                    try {
                        $reservationId = (int)$matchedRow['reservation_id'];

                        $insertLogStmt = mysqli_prepare(
                            $link,
                            'INSERT INTO checkin_logs (reservation_id, user_id, checked_in_space_id, checkin_source) VALUES (?, ?, ?, "equipment")'
                        );
                        if (!$insertLogStmt) {
                            throw new RuntimeException('寫入器材報到紀錄失敗：' . mysqli_error($link));
                        }

                        mysqli_stmt_bind_param($insertLogStmt, 'iss', $reservationId, $currentUserId, $selectedEquipmentId);
                        mysqli_stmt_execute($insertLogStmt);
                        mysqli_stmt_close($insertLogStmt);

                        // 同樣更新 reservations 的 pickup 欄位（如有）
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
                        $feedbackMessage = '器材報到成功：' . ((string)($matchedRow['equipment_name'] ?? $selectedEquipmentId)) . '。';
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

    // 場地報到（原來的處理邏輯）
    } else {
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
            <div class="two-column">
                <!-- Equipment Check-in Panel -->
                <div class="column">
                    <section class="checkin-card">
                        <h2>器材報到</h2>
                        <p>從你已核准的申請中選擇要提領的器材，將狀態更新為已報到，或前往列表查看詳細資訊。</p>

                        <?php if ($dbError !== '') { ?>
                            <div class="alert-box login-alert"><?php echo htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php } elseif ($lastCheckinType === 'equipment' && $feedbackMessage !== '') { ?>
                            <div class="alert-box <?php echo $feedbackType === 'success' ? 'borrow-success' : 'login-alert'; ?>">
                                <?php echo htmlspecialchars($feedbackMessage, ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                        <?php } ?>

                        <form method="post" class="equipment-form" style="flex: 1; display: flex; flex-direction: column;">
                            <input type="hidden" name="checkin_type" value="equipment">
                            <div class="form-group">
                                <label for="equipment_id">選擇器材：</label>
                                <select id="equipment_id" name="equipment_id">
                                    <option value="">請選擇你的器材...</option>
                                    <?php foreach ($equipmentOptions as $eq) { ?>
                                        <option value="<?php echo htmlspecialchars($eq['equipment_id'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php
                                                $labelEq = '';
                                                if ($eq['equipment_code'] !== '') { $labelEq .= $eq['equipment_code'] . ' - '; }
                                                $labelEq .= $eq['equipment_name'];
                                                echo htmlspecialchars($labelEq, ENT_QUOTES, 'UTF-8');
                                            ?>
                                        </option>
                                    <?php } ?>
                                </select>
                            </div>

                            <div class="hero-actions mt-auto">
                                <button class="btn-primary" type="submit" <?php echo count($equipmentOptions) === 0 ? 'disabled' : ''; ?>>確認領取 器材</button>
                                <button class="btn-secondary" type="button" onclick="location.href='borrow.php'">器材列表</button>
                            </div>
                        </form>

                        <?php if (count($equipmentOptions) === 0) { ?>
                            <div class="checkin-empty-hint">目前沒有待領取的核准器材申請</div>
                        <?php } ?>
                    </section>
                </div>

                <!-- Space Check-in Panel -->
                <div class="column">
                    <section class="checkin-card">
                        <h2>場地報到</h2>
                        <p>請勾選你目前所在場地，系統會自動比對你的核准申請與場地是否一致並記錄報到。</p>

                        <?php if ($dbError !== '') { ?>
                            <div class="alert-box login-alert"><?php echo htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php } elseif ($lastCheckinType === 'space' && $feedbackMessage !== '') { ?>
                            <div class="alert-box <?php echo $feedbackType === 'success' ? 'borrow-success' : 'login-alert'; ?>">
                                <?php echo htmlspecialchars($feedbackMessage, ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                        <?php } ?>

                        <?php if ($dbError === '' && $incomingQrToken === $expectedQrToken) { ?>
                            <form method="post" class="checkin-form" style="flex: 1; display: flex; flex-direction: column;">
                                <div class="form-group">
                                    <label for="space_id">我目前在以下場地：</label>
                                    <select id="space_id" name="space_id" required>
                                        <option value="">選擇你所在的場地...</option>
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

                                <div class="hero-actions mt-auto">
                                    <button class="btn-primary" type="submit" <?php echo count($spaceOptions) === 0 ? 'disabled' : ''; ?>>完成場地 報到</button>
                                    <button class="btn-secondary" type="button" onclick="location.href='index.php'">暫不報到 / 返回</button>
                                </div>
                            </form>

                            <?php if (count($spaceOptions) === 0) { ?>
                                <div class="checkin-empty-hint">目前沒有可報到的核准場地申請，或你已報到完畢</div>
                            <?php } ?>
                        <?php } ?>
                    </section>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
