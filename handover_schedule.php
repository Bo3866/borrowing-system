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
$canViewEquipment = $currentRole === '9';
$canViewSpace = $currentRole === '8';
$pageModeLabel = $canViewEquipment ? '器材排程' : '空間排程';

if (!$canViewEquipment && !$canViewSpace) {
    http_response_code(403);
    echo '<p style="padding:1rem;background:#ffecec;border-radius:6px;">存取被拒：此功能僅限工讀生或工友。</p>';
    exit;
}

$dbError = '';
$link = getMysqliConnection($dbError);

$pageError = '';
$pageSuccess = '';
$approvedRows = [];
$spaceRows = [];
$spaceError = '';

if ($dbError !== '') {
    $pageError = $dbError;
}

if ($pageError === '' && $link) {
    $createTableSql = "
        CREATE TABLE IF NOT EXISTS handover_schedules (
            handover_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            reservation_id BIGINT UNSIGNED NOT NULL,
            handover_at DATETIME NULL,
            returned_at DATETIME NULL,
            opened_at DATETIME NULL,
            note VARCHAR(500) NULL,
            created_by VARCHAR(10) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (handover_id),
            KEY idx_handover_reservation (reservation_id),
            KEY idx_handover_at (handover_at),
            KEY idx_handover_returned_at (returned_at),
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
    } else {
        $returnedAtColumnResult = mysqli_query($link, "SHOW COLUMNS FROM handover_schedules LIKE 'returned_at'");
        $returnedAtExists = $returnedAtColumnResult && mysqli_num_rows($returnedAtColumnResult) > 0;

        if (!$returnedAtExists) {
            if (!mysqli_query($link, "ALTER TABLE handover_schedules ADD COLUMN returned_at DATETIME NULL AFTER handover_at")) {
                if (mysqli_errno($link) !== 1060) {
                    $pageError = '補強交接歸還欄位失敗：' . mysqli_error($link);
                }
            }
        }

        $openedAtColumnResult = mysqli_query($link, "SHOW COLUMNS FROM handover_schedules LIKE 'opened_at'");
        $openedAtExists = $openedAtColumnResult && mysqli_num_rows($openedAtColumnResult) > 0;
        if (!$openedAtExists) {
            if (!mysqli_query($link, "ALTER TABLE handover_schedules ADD COLUMN opened_at DATETIME NULL AFTER returned_at")) {
                if (mysqli_errno($link) !== 1060) {
                    $pageError = '補強空間開門欄位失敗：' . mysqli_error($link);
                }
            }
        }

        $handoverAtColumnResult = mysqli_query($link, "SHOW COLUMNS FROM handover_schedules LIKE 'handover_at'");
        $handoverAtExists = $handoverAtColumnResult && mysqli_num_rows($handoverAtColumnResult) > 0;
        if ($handoverAtExists) {
            $handoverAtColumnRow = mysqli_fetch_assoc($handoverAtColumnResult);
            $handoverAtNull = strtolower((string)($handoverAtColumnRow['Null'] ?? ''));
            if ($handoverAtNull !== 'yes') {
                if (!mysqli_query($link, "ALTER TABLE handover_schedules MODIFY handover_at DATETIME NULL")) {
                    $pageError = '調整交接時間欄位失敗：' . mysqli_error($link);
                }
            }
        }
    }
}

if ($pageError === '' && $link && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $reservationId = (int)($_POST['reservation_id'] ?? 0);
    $handoverIdPost = (int)($_POST['handover_id'] ?? 0);
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'save_note') {
        $noteInput = trim((string)($_POST['note'] ?? ''));
        $noteToSave = mb_substr($noteInput, 0, 500, 'UTF-8');
        if ($handoverIdPost > 0) {
            $updateNoteStmt = mysqli_prepare($link, 'UPDATE handover_schedules SET note = ? WHERE handover_id = ?');
            if (!$updateNoteStmt) {
                $pageError = '儲存備註失敗：' . mysqli_error($link);
            } else {
                mysqli_stmt_bind_param($updateNoteStmt, 'si', $noteToSave, $handoverIdPost);
                if (mysqli_stmt_execute($updateNoteStmt)) {
                    $pageSuccess = '備註已儲存。';
                } else {
                    $pageError = '儲存備註失敗：' . mysqli_stmt_error($updateNoteStmt);
                }
                mysqli_stmt_close($updateNoteStmt);
            }
        } elseif ($reservationId > 0) {
            $insertNoteStmt = mysqli_prepare(
                $link,
                'INSERT INTO handover_schedules (reservation_id, handover_at, returned_at, note, created_by) VALUES (?, NULL, NULL, ?, ?)'
            );
            if (!$insertNoteStmt) {
                $pageError = '儲存備註失敗：' . mysqli_error($link);
            } else {
                mysqli_stmt_bind_param($insertNoteStmt, 'iss', $reservationId, $noteToSave, $currentUserId);
                if (mysqli_stmt_execute($insertNoteStmt)) {
                    $pageSuccess = '備註已儲存。';
                } else {
                    $pageError = '儲存備註失敗：' . mysqli_stmt_error($insertNoteStmt);
                }
                mysqli_stmt_close($insertNoteStmt);
            }
        } else {
            $pageError = '找不到要儲存備註的申請。';
        }
    } elseif ($reservationId <= 0) {
        $pageError = '請先選擇要標記的申請編號。';
    } elseif (!in_array($action, ['mark_handover', 'mark_return', 'mark_open_door'], true)) {
        $pageError = '不支援的操作。';
    } elseif (($currentRole === '9' && $action === 'mark_open_door') || ($currentRole === '8' && in_array($action, ['mark_handover', 'mark_return'], true))) {
        $pageError = '此角色無權執行這項操作。';
    } else {
        $validReservationStmt = mysqli_prepare(
            $link,
            "SELECT
                r.reservation_id,
                r.approval_status,
                hs.handover_id,
                hs.handover_at,
                hs.returned_at,
                hs.opened_at,
                hs.note
             FROM reservations r
             LEFT JOIN handover_schedules hs ON hs.handover_id = (
                SELECT hs2.handover_id
                FROM handover_schedules hs2
                WHERE hs2.reservation_id = r.reservation_id
                ORDER BY hs2.handover_id DESC
                LIMIT 1
             )
             WHERE r.reservation_id = ?
             LIMIT 1"
        );

        if (!$validReservationStmt) {
            $pageError = '驗證申請資料失敗：' . mysqli_error($link);
        } else {
            mysqli_stmt_bind_param($validReservationStmt, 'i', $reservationId);
            mysqli_stmt_execute($validReservationStmt);
            $validResult = mysqli_stmt_get_result($validReservationStmt);
            $validRow = $validResult ? mysqli_fetch_assoc($validResult) : null;
            mysqli_stmt_close($validReservationStmt);

            if (!$validRow || (string)($validRow['approval_status'] ?? '') !== 'approved') {
                $pageError = '此申請不是已核准狀態。';
            }
        }

        if ($pageError === '') {
            $handoverId = (int)($validRow['handover_id'] ?? 0);
            $openedAtExisting = trim((string)($validRow['opened_at'] ?? ''));

            if ($action === 'mark_handover') {
                $equipCount = 0;
                $countStmt = mysqli_prepare($link, 'SELECT COUNT(*) AS cnt FROM equipment_reservation_items WHERE reservation_id = ?');
                if ($countStmt) {
                    mysqli_stmt_bind_param($countStmt, 'i', $reservationId);
                    mysqli_stmt_execute($countStmt);
                    $cntRes = mysqli_stmt_get_result($countStmt);
                    $cntRow = $cntRes ? mysqli_fetch_assoc($cntRes) : null;
                    $equipCount = (int)($cntRow['cnt'] ?? 0);
                    mysqli_stmt_close($countStmt);
                }
                if ($equipCount === 0) {
                    $pageError = '此申請沒有器材項目，工讀生不得為純場地申請標記交接。';
                }
            }

            $handoverAtExisting = trim((string)($validRow['handover_at'] ?? ''));
            $returnedAtExisting = trim((string)($validRow['returned_at'] ?? ''));

            if ($pageError === '' && $action === 'mark_handover') {
                if ($handoverId > 0 && $handoverAtExisting !== '') {
                    $pageError = '此申請已經有交接紀錄，請直接按「已歸還」。';
                } elseif ($handoverId > 0 && $handoverAtExisting === '') {
                    $updateHandoverStmt = mysqli_prepare(
                        $link,
                        'UPDATE handover_schedules SET handover_at = ? WHERE handover_id = ? AND handover_at IS NULL'
                    );
                    if (!$updateHandoverStmt) {
                        $pageError = '標記已交接失敗：' . mysqli_error($link);
                    } else {
                        $handoverAt = date('Y-m-d H:i:s');
                        mysqli_stmt_bind_param($updateHandoverStmt, 'si', $handoverAt, $handoverId);
                        if (mysqli_stmt_execute($updateHandoverStmt)) {
                            $updateResChkStmt = mysqli_prepare($link, 'UPDATE reservations SET checked_in_at = COALESCE(checked_in_at, ?) WHERE reservation_id = ?');
                            if ($updateResChkStmt) {
                                mysqli_stmt_bind_param($updateResChkStmt, 'si', $handoverAt, $reservationId);
                                mysqli_stmt_execute($updateResChkStmt);
                                mysqli_stmt_close($updateResChkStmt);
                            }
                            $pageSuccess = '已標記為已交接，時間：' . $handoverAt;
                        } else {
                            $pageError = '標記已交接失敗：' . mysqli_stmt_error($updateHandoverStmt);
                        }
                        mysqli_stmt_close($updateHandoverStmt);
                    }
                } else {
                    $insertStmt = mysqli_prepare(
                        $link,
                        'INSERT INTO handover_schedules (reservation_id, handover_at, returned_at, note, created_by) VALUES (?, ?, NULL, NULL, ?)'
                    );

                    if (!$insertStmt) {
                        $pageError = '標記已交接失敗：' . mysqli_error($link);
                    } else {
                        $handoverAt = date('Y-m-d H:i:s');
                        mysqli_stmt_bind_param($insertStmt, 'iss', $reservationId, $handoverAt, $currentUserId);
                        if (mysqli_stmt_execute($insertStmt)) {
                            $updateResChkStmt = mysqli_prepare($link, 'UPDATE reservations SET checked_in_at = COALESCE(checked_in_at, ?) WHERE reservation_id = ?');
                            if ($updateResChkStmt) {
                                mysqli_stmt_bind_param($updateResChkStmt, 'si', $handoverAt, $reservationId);
                                mysqli_stmt_execute($updateResChkStmt);
                                mysqli_stmt_close($updateResChkStmt);
                            }

                            $pageSuccess = '已標記為已交接，時間：' . $handoverAt;
                        } else {
                            $pageError = '標記已交接失敗：' . mysqli_stmt_error($insertStmt);
                        }
                        mysqli_stmt_close($insertStmt);
                    }
                }
            } elseif ($action === 'mark_open_door') {
                $spaceCount = 0;
                $countStmt = mysqli_prepare($link, 'SELECT COUNT(*) AS cnt FROM space_reservation_items WHERE reservation_id = ?');
                if ($countStmt) {
                    mysqli_stmt_bind_param($countStmt, 'i', $reservationId);
                    mysqli_stmt_execute($countStmt);
                    $cntRes = mysqli_stmt_get_result($countStmt);
                    $cntRow = $cntRes ? mysqli_fetch_assoc($cntRes) : null;
                    $spaceCount = (int)($cntRow['cnt'] ?? 0);
                    mysqli_stmt_close($countStmt);
                }

                if ($spaceCount === 0) {
                    $pageError = '此申請沒有空間項目，無法標記已開門。';
                } elseif ($openedAtExisting !== '') {
                    $pageError = '此申請已經標記已開門。';
                } elseif ($handoverId > 0) {
                    $updateOpenStmt = mysqli_prepare(
                        $link,
                        'UPDATE handover_schedules SET opened_at = ? WHERE handover_id = ? AND opened_at IS NULL'
                    );
                    if (!$updateOpenStmt) {
                        $pageError = '標記已開門失敗：' . mysqli_error($link);
                    } else {
                        $openedAt = date('Y-m-d H:i:s');
                        mysqli_stmt_bind_param($updateOpenStmt, 'si', $openedAt, $handoverId);
                        if (mysqli_stmt_execute($updateOpenStmt)) {
                            $pageSuccess = '已標記為已開門，時間：' . $openedAt;
                        } else {
                            $pageError = '標記已開門失敗：' . mysqli_stmt_error($updateOpenStmt);
                        }
                        mysqli_stmt_close($updateOpenStmt);
                    }
                } else {
                    $insertStmt = mysqli_prepare(
                        $link,
                        'INSERT INTO handover_schedules (reservation_id, handover_at, returned_at, opened_at, note, created_by) VALUES (?, NULL, NULL, ?, NULL, ?)'
                    );
                    if (!$insertStmt) {
                        $pageError = '標記已開門失敗：' . mysqli_error($link);
                    } else {
                        $openedAt = date('Y-m-d H:i:s');
                        mysqli_stmt_bind_param($insertStmt, 'iss', $reservationId, $openedAt, $currentUserId);
                        if (mysqli_stmt_execute($insertStmt)) {
                            $pageSuccess = '已標記為已開門，時間：' . $openedAt;
                        } else {
                            $pageError = '標記已開門失敗：' . mysqli_stmt_error($insertStmt);
                        }
                        mysqli_stmt_close($insertStmt);
                    }
                }
            } elseif ($pageError === '') {
                if ($handoverId <= 0 || $handoverAtExisting === '') {
                    $pageError = '此申請尚未交接，不能標記歸還。';
                } elseif ($returnedAtExisting !== '') {
                    $pageError = '此申請已經標記歸還。';
                } else {
                    $updateStmt = mysqli_prepare(
                        $link,
                        'UPDATE handover_schedules SET returned_at = ? WHERE handover_id = ? AND returned_at IS NULL'
                    );

                    if (!$updateStmt) {
                        $pageError = '標記已歸還失敗：' . mysqli_error($link);
                    } else {
                        $returnedAt = date('Y-m-d H:i:s');
                        mysqli_stmt_bind_param($updateStmt, 'si', $returnedAt, $handoverId);
                        if (mysqli_stmt_execute($updateStmt)) {
                            $pageSuccess = '已標記為已歸還，時間：' . $returnedAt;
                        } else {
                            $pageError = '標記已歸還失敗：' . mysqli_stmt_error($updateStmt);
                        }
                        mysqli_stmt_close($updateStmt);
                    }
                }
            }
        }
    }
}
if ($pageError === '' && $link) {
    // 1. 取得搜尋參數（放在條件檢查前或內都可以，這邊先統一撈取）
    $keyword = trim((string)($_GET['keyword'] ?? ''));
    $statusFilter = trim((string)($_GET['status'] ?? ''));

    if ($canViewEquipment) {
    $approvedSql = "
        SELECT
            r.reservation_id,
            r.user_id,
            u.full_name,
            u.email,
            r.actual_pickup_at AS borrow_start_at,
            r.actual_return_at AS borrow_end_at,
            hs.handover_id,
            hs.handover_at AS latest_handover_at,
            hs.returned_at AS latest_returned_at,
            hs.note AS latest_note,
            CASE
                WHEN hs.handover_at IS NULL THEN 'pending'
                WHEN hs.returned_at IS NULL THEN 'handover'
                ELSE 'returned'
            END AS handover_state,
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
            SELECT hs1.*
            FROM handover_schedules hs1
            INNER JOIN (
                SELECT reservation_id, MAX(handover_id) AS max_handover_id
                FROM handover_schedules
                GROUP BY reservation_id
            ) latest ON latest.max_handover_id = hs1.handover_id
        ) hs ON hs.reservation_id = r.reservation_id
        WHERE r.approval_status = 'approved'
          AND EXISTS (
              SELECT 1 FROM equipment_reservation_items eri WHERE eri.reservation_id = r.reservation_id
          )
    ";
    if ($keyword !== '') {
            $escapedKeyword = mysqli_real_escape_string($link, $keyword);
            $approvedSql .= " AND (
                u.full_name LIKE '%{$escapedKeyword}%' 
                OR r.user_id LIKE '%{$escapedKeyword}%'
                OR EXISTS (
                    SELECT 1 FROM equipment_reservation_items eri2
                    JOIN equipments e2 ON e2.equipment_id = eri2.equipment_id
                    JOIN equipment_categories ec2 ON ec2.equipment_code = e2.equipment_code
                    WHERE eri2.reservation_id = r.reservation_id AND ec2.equipment_name LIKE '%{$escapedKeyword}%'
                )
            )";
        }

    // 4. 動態加入狀態篩選條件
        if ($statusFilter !== '') {
            if ($statusFilter === 'pending') {
                $approvedSql .= " AND hs.handover_at IS NULL";
            } elseif ($statusFilter === 'handover') {
                $approvedSql .= " AND hs.handover_at IS NOT NULL AND hs.returned_at IS NULL";
            } elseif ($statusFilter === 'returned') {
                $approvedSql .= " AND hs.returned_at IS NOT NULL";
            }
        }

    // 5. 補上最後的排序與限制
    $approvedSql .= " ORDER BY r.actual_pickup_at ASC LIMIT 300";
    // 6. 執行查詢與資料綁定
    $approvedResult = mysqli_query($link, $approvedSql);
    if ($approvedResult) {
        $approvedRows = [];
        while ($row = mysqli_fetch_assoc($approvedResult)) {
            $approvedRows[] = $row;
        }
    } else {
        $pageError = '讀取已核准申請失敗：' . mysqli_error($link);
    }
    }

    if ($pageError === '' && $canViewSpace) {
$spaceSql = "
            SELECT
                r.reservation_id,
                r.user_id,
                u.full_name,
                u.email,
                r.actual_pickup_at AS borrow_start_at,
                r.actual_return_at AS borrow_end_at,
                hs.handover_id,
                hs.opened_at AS latest_opened_at,
                hs.note AS latest_note,
                CASE
                    WHEN hs.opened_at IS NULL THEN 'pending'
                    ELSE 'opened'
                END AS space_state,
                (
                    SELECT GROUP_CONCAT(DISTINCT s.space_name ORDER BY s.space_name SEPARATOR '、')
                    FROM space_reservation_items sri
                    JOIN spaces s ON s.space_id = sri.space_id
                    WHERE sri.reservation_id = r.reservation_id
                ) AS space_names
            FROM reservations r
            JOIN users u ON u.user_id = r.user_id
            LEFT JOIN (
                SELECT hs1.*
                FROM handover_schedules hs1
                INNER JOIN (
                    SELECT reservation_id, MAX(handover_id) AS max_handover_id
                    FROM handover_schedules
                    GROUP BY reservation_id
                ) latest ON latest.max_handover_id = hs1.handover_id
            ) hs ON hs.reservation_id = r.reservation_id
            WHERE r.approval_status = 'approved'
              AND EXISTS (
                  SELECT 1 FROM space_reservation_items sri WHERE sri.reservation_id = r.reservation_id
              )
            ORDER BY r.actual_pickup_at ASC
            LIMIT 300
            ";

        $spaceResult = mysqli_query($link, $spaceSql);
        if ($spaceResult) {
            while ($row = mysqli_fetch_assoc($spaceResult)) {
                $spaceRows[] = $row;
            }
        } else {
            $spaceError = '讀取空間排程失敗：' . mysqli_error($link);
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
    <title><?php echo htmlspecialchars($pageModeLabel, ENT_QUOTES, 'UTF-8'); ?>｜校園資源租借系統</title>
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
    <style>
        .status-pill {
            display: inline-block;
            padding: 0.2rem 0.55rem;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }
        .status-pending {
            background: #fff7ed;
            color: #9a3412;
        }
        .status-done {
            background: #ecfdf5;
            color: #047857;
        }
        .status-handover {
            background: #eff6ff;
            color: #1d4ed8;
        }
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
        .handover-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 88px;
            padding: 0.45rem 0.75rem;
            border: 0;
            border-radius: 6px;
            background: #0f766e;
            color: #fff;
            cursor: pointer;
            font-size: 14px;
            font-weight: 700;
        }
        .handover-action:disabled {
            background: #94a3b8;
            cursor: not-allowed;
        }
        .hidden {
            display: none;
        }
        .note-editor textarea {
            width: 100%;
            min-width: 220px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 0.45rem 0.55rem;
            font-size: 13px;
            resize: vertical;
        }
        .note-editor-actions {
            display: flex;
            gap: 0.4rem;
            margin-top: 0.4rem;
            flex-wrap: wrap;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/nav.php'; ?>

    <div class="container">
        <main class="main-content">
            <section class="card">
                <h2><?php echo htmlspecialchars($pageModeLabel, ENT_QUOTES, 'UTF-8'); ?>（<?php echo $canViewEquipment ? '工讀生' : '工友'; ?>）</h2>
                <p class="muted"><?php echo $canViewEquipment ? '系統會自動列出所有已核准的器材申請，先按「已交接」記錄交接時間，再按「已歸還」記錄歸還時間。' : '系統會自動列出所有已核准的空間申請，按「已開門」記錄開門時間。'; ?></p>

                <?php if ($pageSuccess !== '') { ?>
                    <div class="borrow-success"><?php echo htmlspecialchars($pageSuccess, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } ?>

                <?php if ($pageError !== '') { ?>
                    <div class="login-alert"><?php echo htmlspecialchars($pageError, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } ?>

                <!-- 搜尋功能區塊 -->
<div class="search-section">
    <div class="search-container">
        <form method="GET" action="handover_schedule.php" class="search-form">
            <div class="search-group">
                <label for="search_keyword" class="search-label">關鍵字搜尋</label>
                <div class="input-with-icon">
                    <input type="text" id="search_keyword" name="keyword" 
                           placeholder="搜尋姓名、學號或器材/空間名稱..." 
                           value="<?php echo htmlspecialchars($_GET['keyword'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
            </div>
            
            <div class="search-group size-small">
                <label for="search_status" class="search-label">狀態篩選</label>
                <select id="search_status" name="status">
                    <option value="">全部狀態</option>
                    <option value="pending" <?php echo ($_GET['status'] ?? '') === 'pending' ? 'selected' : ''; ?>>待處理</option>
                    <option value="handover" <?php echo ($_GET['status'] ?? '') === 'handover' ? 'selected' : ''; ?>>已交接/使用中</option>
                    <option value="returned" <?php echo ($_GET['status'] ?? '') === 'returned' ? 'selected' : ''; ?>>已歸還</option>
                </select>
            </div>
            
            <div class="search-actions">
                <button type="submit" class="btn-search">🔍 搜尋</button>
                <?php if (!empty($_GET['keyword']) || !empty($_GET['status'])): ?>
                    <a href="handover_schedule.php" class="btn-reset">清除條件</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

                <?php if ($pageError === '') { ?>
                    <?php if ($canViewEquipment) { ?>
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
                                                <th>交接狀態</th>
                                                <th>交接時間</th>
                                                <th>歸還時間</th>
                                                <th>備註</th>
                                                <th>操作</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (count($approvedRows) === 0) { ?>
                                                <tr><td colspan="9">目前沒有已核准申請。</td></tr>
                                            <?php } else { ?>
                                                <?php foreach ($approvedRows as $row) { ?>
                                                    <?php
                                                    $items = [];
                                                    $hasEquipment = false;
                                                    if (!empty($row['equipment_names'])) {
                                                        $items[] = '器材：' . $row['equipment_names'];
                                                        $hasEquipment = true;
                                                    }
                                                    if (!empty($row['space_names'])) {
                                                        $items[] = '場地：' . $row['space_names'];
                                                    }
                                                    $itemText = count($items) > 0 ? implode(' ｜ ', $items) : '-';
                                                    $handoverState = (string)($row['handover_state'] ?? 'pending');
                                                    $handoverTimeText = $handoverState === 'pending' ? '-' : (string)($row['latest_handover_at'] ?? '-');
                                                    $returnTimeText = $handoverState === 'returned' ? (string)($row['latest_returned_at'] ?? '-') : '-';
                                                    $existingNote = trim((string)($row['latest_note'] ?? ''));
                                                    $buttonClass = $handoverState === 'handover' ? 'status-handover' : ($handoverState === 'returned' ? 'status-done' : 'status-pending');
                                                    $buttonDisabled = $handoverState === 'returned';
                                                    $buttonLabel = '';
                                                    if ($handoverState === 'pending') {
                                                        if ($hasEquipment) {
                                                            $buttonLabel = '已交接';
                                                            $buttonDisabled = false;
                                                        } else {
                                                            $buttonLabel = '無器材（不可交接）';
                                                            $buttonDisabled = true;
                                                        }
                                                    } else {
                                                        $buttonLabel = $handoverState === 'handover' ? '已歸還' : '已完成';
                                                    }
                                                    $noteEditorId = 'note-editor-' . (int)$row['reservation_id'];
                                                    $noteValueId = 'note-value-' . (int)$row['reservation_id'];
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
                                                        <td>
                                                            <span class="status-pill <?php echo $buttonClass; ?>">
                                                                <?php echo $handoverState === 'pending' ? '待交接' : ($handoverState === 'handover' ? '已交接' : '已歸還'); ?>
                                                            </span>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($handoverTimeText, ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td><?php echo htmlspecialchars($returnTimeText, ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td>
                                                            <div id="<?php echo htmlspecialchars($noteValueId, ENT_QUOTES, 'UTF-8'); ?>" class="muted"><?php echo $existingNote !== '' ? htmlspecialchars($existingNote, ENT_QUOTES, 'UTF-8') : '-'; ?></div>
                                                            <div style="margin-top:0.45rem;">
                                                                <button type="button" class="handover-action" onclick="document.getElementById('<?php echo htmlspecialchars($noteEditorId, ENT_QUOTES, 'UTF-8'); ?>').classList.toggle('hidden');">備註</button>
                                                            </div>
                                                            <div id="<?php echo htmlspecialchars($noteEditorId, ENT_QUOTES, 'UTF-8'); ?>" class="hidden note-editor" style="margin-top:0.5rem;">
                                                                <form method="post" style="margin:0;">
                                                                    <input type="hidden" name="action" value="save_note">
                                                                    <input type="hidden" name="handover_id" value="<?php echo !empty($row['handover_id']) ? (int)$row['handover_id'] : 0; ?>">
                                                                    <input type="hidden" name="reservation_id" value="<?php echo (int)$row['reservation_id']; ?>">
                                                                    <textarea name="note" rows="3"><?php echo htmlspecialchars($existingNote, ENT_QUOTES, 'UTF-8'); ?></textarea>
                                                                    <div class="note-editor-actions">
                                                                        <button type="submit" class="handover-action">儲存</button>
                                                                        <button type="button" class="handover-action" onclick="document.getElementById('<?php echo htmlspecialchars($noteEditorId, ENT_QUOTES, 'UTF-8'); ?>').classList.add('hidden');">取消</button>
                                                                    </div>
                                                                </form>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <form method="post" style="margin:0;">
                                                                <input type="hidden" name="action" value="<?php echo $handoverState === 'pending' ? 'mark_handover' : 'mark_return'; ?>">
                                                                <input type="hidden" name="reservation_id" value="<?php echo (int)$row['reservation_id']; ?>">
                                                                <button type="submit" class="handover-action" <?php echo $buttonDisabled ? 'disabled' : ''; ?>><?php echo htmlspecialchars($buttonLabel, ENT_QUOTES, 'UTF-8'); ?></button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                <?php } ?>
                                            <?php } ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php } elseif ($canViewSpace) { ?>
                        <div class="handover-grid">
                            <div class="card" style="margin:0;">
                                <h3 style="margin-top:0;">空間排程清單</h3>
                                <?php if ($spaceError !== '') { ?>
                                    <div class="login-alert"><?php echo htmlspecialchars($spaceError, ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php } ?>
                                <div class="handover-table-wrapper">
                                    <table class="handover-table">
                                        <thead>
                                            <tr>
                                                <th>申請編號</th>
                                                <th>申請人</th>
                                                <th>借用時段</th>
                                                <th>借用空間</th>
                                                <th>開門狀態</th>
                                                <th>開門時間</th>
                                                <th>備註</th>
                                                <th>操作</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (count($spaceRows) === 0) { ?>
                                                <tr><td colspan="8">目前沒有已核准空間申請。</td></tr>
                                            <?php } else { ?>
                                                <?php foreach ($spaceRows as $row) { ?>
                                                    <?php
                                                    $spaceState = (string)($row['space_state'] ?? 'pending');
                                                    $spaceTimeText = $spaceState === 'opened' ? (string)($row['latest_opened_at'] ?? '-') : '-';
                                                    $spaceNote = trim((string)($row['latest_note'] ?? ''));
                                                    $spaceButtonClass = $spaceState === 'opened' ? 'status-done' : 'status-pending';
                                                    $spaceButtonDisabled = $spaceState === 'opened';
                                                    $spaceButtonLabel = '已開門';
                                                    $spaceNoteEditorId = 'space-note-editor-' . (int)$row['reservation_id'];
                                                    $spaceNoteValueId = 'space-note-value-' . (int)$row['reservation_id'];
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
                                                        <td><?php echo htmlspecialchars((string)($row['space_names'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td>
                                                            <span class="status-pill <?php echo $spaceButtonClass; ?>">
                                                                <?php echo $spaceState === 'opened' ? '已開門' : '待開門'; ?>
                                                            </span>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($spaceTimeText, ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td>
                                                            <div id="<?php echo htmlspecialchars($spaceNoteValueId, ENT_QUOTES, 'UTF-8'); ?>" class="muted"><?php echo $spaceNote !== '' ? htmlspecialchars($spaceNote, ENT_QUOTES, 'UTF-8') : '-'; ?></div>
                                                            <div style="margin-top:0.45rem;">
                                                                <button type="button" class="handover-action" onclick="document.getElementById('<?php echo htmlspecialchars($spaceNoteEditorId, ENT_QUOTES, 'UTF-8'); ?>').classList.toggle('hidden');">備註</button>
                                                            </div>
                                                            <div id="<?php echo htmlspecialchars($spaceNoteEditorId, ENT_QUOTES, 'UTF-8'); ?>" class="hidden note-editor" style="margin-top:0.5rem;">
                                                                <form method="post" style="margin:0;">
                                                                    <input type="hidden" name="action" value="save_note">
                                                                    <input type="hidden" name="handover_id" value="<?php echo !empty($row['handover_id']) ? (int)$row['handover_id'] : 0; ?>">
                                                                    <input type="hidden" name="reservation_id" value="<?php echo (int)$row['reservation_id']; ?>">
                                                                    <textarea name="note" rows="3"><?php echo htmlspecialchars($spaceNote, ENT_QUOTES, 'UTF-8'); ?></textarea>
                                                                    <div class="note-editor-actions">
                                                                        <button type="submit" class="handover-action">儲存</button>
                                                                        <button type="button" class="handover-action" onclick="document.getElementById('<?php echo htmlspecialchars($spaceNoteEditorId, ENT_QUOTES, 'UTF-8'); ?>').classList.add('hidden');">取消</button>
                                                                    </div>
                                                                </form>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <form method="post" style="margin:0;">
                                                                <input type="hidden" name="action" value="mark_open_door">
                                                                <input type="hidden" name="reservation_id" value="<?php echo (int)$row['reservation_id']; ?>">
                                                                <button type="submit" class="handover-action" <?php echo $spaceButtonDisabled ? 'disabled' : ''; ?>><?php echo htmlspecialchars($spaceButtonLabel, ENT_QUOTES, 'UTF-8'); ?></button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                <?php } ?>
                                            <?php } ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php } ?>
                <?php } ?>
            </section>
        </main>
    </div>
</body>
</html>
