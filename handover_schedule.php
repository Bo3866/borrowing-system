<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?next=handover_schedule.php');
    exit;
}

$currentUserId = (string)$_SESSION['user_id'];
$currentRole = (string)($_SESSION['role_name'] ?? '');
$displayName = (string)($_SESSION['full_name'] ?? $_SESSION['user_id']);

if ($currentRole !== '9') {
    http_response_code(403);
    echo '<p style="padding:1rem;background:#ffecec;border-radius:6px;">存取被拒：此功能僅限工讀生。</p>';
    exit;
}

$dbError = '';
$link = getMysqliConnection($dbError);

$pageError = '';
$pageSuccess = '';
$approvedRows = [];
$recentSchedules = [];

if ($dbError !== '') {
    $pageError = $dbError;
}

if ($pageError === '' && $link) {
    $createTableSql = "
        CREATE TABLE IF NOT EXISTS handover_schedules (
            handover_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            reservation_id BIGINT UNSIGNED NOT NULL,
            handover_at DATETIME NOT NULL,
            note VARCHAR(500) NULL,
            created_by VARCHAR(10) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (handover_id),
            KEY idx_handover_reservation (reservation_id),
            KEY idx_handover_at (handover_at),
            KEY idx_handover_created_by (created_by),
            CONSTRAINT fk_handover_reservation
                FOREIGN KEY (reservation_id) REFERENCES reservations (reservation_id)
                ON UPDATE CASCADE ON DELETE CASCADE,
            CONSTRAINT fk_handover_created_by
                FOREIGN KEY (created_by) REFERENCES users (user_id)
                ON UPDATE CASCADE ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    if (!mysqli_query($link, $createTableSql)) {
        $pageError = '建立交接排程資料表失敗：' . mysqli_error($link);
    }
}

if ($pageError === '' && $link && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $reservationId = (int)($_POST['reservation_id'] ?? 0);
    $handoverAtRaw = trim((string)($_POST['handover_at'] ?? ''));
    $note = trim((string)($_POST['note'] ?? ''));

    if ($reservationId <= 0) {
        $pageError = '請先選擇要排程的申請編號。';
    } elseif ($handoverAtRaw === '') {
        $pageError = '請填寫交接時間。';
    } else {
        $handoverAt = str_replace('T', ' ', $handoverAtRaw);
        if (strlen($handoverAt) === 16) {
            $handoverAt .= ':00';
        }

        $validReservationStmt = mysqli_prepare(
            $link,
            "SELECT reservation_id FROM reservations WHERE reservation_id = ? AND approval_status = 'approved' LIMIT 1"
        );

        if (!$validReservationStmt) {
            $pageError = '驗證申請資料失敗：' . mysqli_error($link);
        } else {
            mysqli_stmt_bind_param($validReservationStmt, 'i', $reservationId);
            mysqli_stmt_execute($validReservationStmt);
            $validResult = mysqli_stmt_get_result($validReservationStmt);
            $validRow = $validResult ? mysqli_fetch_assoc($validResult) : null;
            mysqli_stmt_close($validReservationStmt);

            if (!$validRow) {
                $pageError = '此申請不是已核准狀態，無法新增交接排程。';
            }
        }

        if ($pageError === '') {
            $insertStmt = mysqli_prepare(
                $link,
                'INSERT INTO handover_schedules (reservation_id, handover_at, note, created_by) VALUES (?, ?, ?, ?)'
            );

            if (!$insertStmt) {
                $pageError = '新增交接排程失敗：' . mysqli_error($link);
            } else {
                mysqli_stmt_bind_param($insertStmt, 'isss', $reservationId, $handoverAt, $note, $currentUserId);
                if (mysqli_stmt_execute($insertStmt)) {
                    $pageSuccess = '已新增交接排程。';
                } else {
                    $pageError = '新增交接排程失敗：' . mysqli_stmt_error($insertStmt);
                }
                mysqli_stmt_close($insertStmt);
            }
        }
    }
}

if ($pageError === '' && $link) {
    $approvedSql = "
        SELECT
            r.reservation_id,
            r.user_id,
            u.full_name,
            u.email,
            r.borrow_start_at,
            r.borrow_end_at,
            hs.latest_handover_at,
            hs.schedule_count,
            (
                SELECT GROUP_CONCAT(DISTINCT ec.equipment_name ORDER BY ec.equipment_name SEPARATOR '、')
                FROM equipment_reservation_items eri
                JOIN equipments e ON e.equipment_id = eri.equipment_id
                JOIN equipment_categories ec ON ec.equipment_code = e.equipment_code
                WHERE eri.reservation_id = r.reservation_id
            ) AS equipment_names,
            (
                SELECT GROUP_CONCAT(DISTINCT s.space_name ORDER BY s.space_name SEPARATOR '、')
                FROM space_reservation_items sri
                JOIN spaces s ON s.space_id = sri.space_id
                WHERE sri.reservation_id = r.reservation_id
            ) AS space_names
        FROM reservations r
        JOIN users u ON u.user_id = r.user_id
        LEFT JOIN (
            SELECT reservation_id, MAX(handover_at) AS latest_handover_at, COUNT(*) AS schedule_count
            FROM handover_schedules
            GROUP BY reservation_id
        ) hs ON hs.reservation_id = r.reservation_id
        WHERE r.approval_status = 'approved'
        ORDER BY r.borrow_start_at ASC
        LIMIT 300
    ";

    $approvedResult = mysqli_query($link, $approvedSql);
    if ($approvedResult) {
        while ($row = mysqli_fetch_assoc($approvedResult)) {
            $approvedRows[] = $row;
        }
    } else {
        $pageError = '讀取已核准申請失敗：' . mysqli_error($link);
    }
}

if ($pageError === '' && $link) {
    $recentSql = "
        SELECT
            hs.handover_id,
            hs.reservation_id,
            hs.handover_at,
            hs.note,
            hs.created_at,
            u.full_name AS creator_name
        FROM handover_schedules hs
        LEFT JOIN users u ON u.user_id = hs.created_by
        ORDER BY hs.created_at DESC
        LIMIT 50
    ";

    $recentResult = mysqli_query($link, $recentSql);
    if ($recentResult) {
        while ($row = mysqli_fetch_assoc($recentResult)) {
            $recentSchedules[] = $row;
        }
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
    <title>器材交接排程｜校園資源租借系統</title>
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
    <style>
        .handover-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
        }
        .handover-table-wrapper {
            overflow-x: auto;
        }
        .handover-table {
            width: 100%;
            border-collapse: collapse;
        }
        .handover-table th,
        .handover-table td {
            border: 1px solid #e2e8f0;
            padding: 0.6rem;
            text-align: left;
            vertical-align: top;
            font-size: 14px;
        }
        .handover-table th {
            background: #f8fafc;
            color: #334155;
        }
        .muted {
            color: #64748b;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/nav.php'; ?>

    <div class="container">
        <main class="main-content">
            <section class="card">
                <h2>器材交接排程（工讀生）</h2>
                <p class="muted">針對已核准申請，新增交接時間紀錄，方便安排現場交接作業。</p>

                <?php if ($pageSuccess !== '') { ?>
                    <div class="borrow-success"><?php echo htmlspecialchars($pageSuccess, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } ?>

                <?php if ($pageError !== '') { ?>
                    <div class="login-alert"><?php echo htmlspecialchars($pageError, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } ?>

                <?php if ($pageError === '') { ?>
                    <form method="post" class="borrow-form" style="margin-bottom:1rem;">
                        <div class="form-group">
                            <label for="reservation_id">選擇已核准申請 <span style="color:red;">*</span></label>
                            <select id="reservation_id" name="reservation_id" class="form-control" required>
                                <option value="">請選擇申請</option>
                                <?php foreach ($approvedRows as $row) { ?>
                                    <option value="<?php echo (int)$row['reservation_id']; ?>">
                                        <?php
                                            $label = '#'.$row['reservation_id'].' ｜ '.($row['full_name'] ?? '').' ｜ '.($row['borrow_start_at'] ?? '').' ~ '.($row['borrow_end_at'] ?? '');
                                            echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
                                        ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="handover_at">交接時間 <span style="color:red;">*</span></label>
                            <input id="handover_at" name="handover_at" type="datetime-local" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="note">備註</label>
                            <textarea id="note" name="note" class="form-control" placeholder="可填寫集合地點、聯絡方式、注意事項" rows="3"></textarea>
                        </div>
                        <button type="submit" class="btn-primary">新增交接紀錄</button>
                    </form>

                    <div class="handover-grid">
                        <div class="card" style="margin:0;">
                            <h3 style="margin-top:0;">已核准申請清單</h3>
                            <div class="handover-table-wrapper">
                                <table class="handover-table">
                                    <thead>
                                        <tr>
                                            <th>申請編號</th>
                                            <th>申請人</th>
                                            <th>借用時段</th>
                                            <th>借用項目</th>
                                            <th>排程次數</th>
                                            <th>最近交接時間</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (count($approvedRows) === 0) { ?>
                                            <tr><td colspan="6">目前沒有已核准申請。</td></tr>
                                        <?php } else { ?>
                                            <?php foreach ($approvedRows as $row) { ?>
                                                <?php
                                                    $items = [];
                                                    if (!empty($row['equipment_names'])) {
                                                        $items[] = '器材：' . $row['equipment_names'];
                                                    }
                                                    if (!empty($row['space_names'])) {
                                                        $items[] = '場地：' . $row['space_names'];
                                                    }
                                                    $itemText = count($items) > 0 ? implode(' ｜ ', $items) : '-';
                                                ?>
                                                <tr>
                                                    <td>#<?php echo (int)$row['reservation_id']; ?></td>
                                                    <td>
                                                        <?php echo htmlspecialchars((string)$row['full_name'], ENT_QUOTES, 'UTF-8'); ?>
                                                        <div class="muted"><?php echo htmlspecialchars((string)$row['user_id'], ENT_QUOTES, 'UTF-8'); ?></div>
                                                    </td>
                                                    <td>
                                                        <?php echo htmlspecialchars((string)$row['borrow_start_at'], ENT_QUOTES, 'UTF-8'); ?><br>
                                                        ~ <?php echo htmlspecialchars((string)$row['borrow_end_at'], ENT_QUOTES, 'UTF-8'); ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($itemText, ENT_QUOTES, 'UTF-8'); ?></td>
                                                    <td><?php echo (int)($row['schedule_count'] ?? 0); ?></td>
                                                    <td><?php echo htmlspecialchars((string)($row['latest_handover_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                                </tr>
                                            <?php } ?>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="card" style="margin:0;">
                            <h3 style="margin-top:0;">最近新增的交接紀錄</h3>
                            <div class="handover-table-wrapper">
                                <table class="handover-table">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>申請編號</th>
                                            <th>交接時間</th>
                                            <th>備註</th>
                                            <th>建立人</th>
                                            <th>建立時間</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (count($recentSchedules) === 0) { ?>
                                            <tr><td colspan="6">目前沒有交接紀錄。</td></tr>
                                        <?php } else { ?>
                                            <?php foreach ($recentSchedules as $schedule) { ?>
                                                <tr>
                                                    <td><?php echo (int)$schedule['handover_id']; ?></td>
                                                    <td>#<?php echo (int)$schedule['reservation_id']; ?></td>
                                                    <td><?php echo htmlspecialchars((string)$schedule['handover_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                    <td><?php echo htmlspecialchars((string)($schedule['note'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                                    <td><?php echo htmlspecialchars((string)($schedule['creator_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                                    <td><?php echo htmlspecialchars((string)$schedule['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                </tr>
                                            <?php } ?>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php } ?>
            </section>
        </main>
    </div>
</body>
</html>
