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
    // 1. 建立或檢查交接排程資料表
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
        // 2. 檢查並補強 handover_schedules 的 returned_at
        $returnedAtColumnResult = mysqli_query($link, "SHOW COLUMNS FROM handover_schedules LIKE 'returned_at'");
        $returnedAtExists = $returnedAtColumnResult && mysqli_num_rows($returnedAtColumnResult) > 0;

        if (!$returnedAtExists) {
            if (!mysqli_query($link, "ALTER TABLE handover_schedules ADD COLUMN returned_at DATETIME NULL AFTER handover_at")) {
                if (mysqli_errno($link) !== 1060) {
                    $pageError = '補強交接歸還欄位失敗：' . mysqli_error($link);
                }
            }
        }

        // 3. 檢查並補強 handover_schedules 的 opened_at
        $openedAtColumnResult = mysqli_query($link, "SHOW COLUMNS FROM handover_schedules LIKE 'opened_at'");
        $openedAtExists = $openedAtColumnResult && mysqli_num_rows($openedAtColumnResult) > 0;
        if (!$openedAtExists) {
            if (!mysqli_query($link, "ALTER TABLE handover_schedules ADD COLUMN opened_at DATETIME NULL AFTER returned_at")) {
                if (mysqli_errno($link) !== 1060) {
                    $pageError = '補強空間開門欄位失敗：' . mysqli_error($link);
                }
            }
        }

        // 4. 調整 handover_at 允許為 NULL
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

        // 🎯 5. 確保 reservations 資料表也有 returned_at 欄位
        $resReturnedAtRes = mysqli_query($link, "SHOW COLUMNS FROM reservations LIKE 'returned_at'");
        $resReturnedAtExists = $resReturnedAtRes && mysqli_num_rows($resReturnedAtRes) > 0;
        if (!$resReturnedAtExists) {
            if (!mysqli_query($link, "ALTER TABLE reservations ADD COLUMN returned_at DATETIME NULL")) {
                if (mysqli_errno($link) !== 1060) {
                    $pageError = '補強預約資料表歸還欄位失敗：' . mysqli_error($link);
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
                    // 📢 啟動交易機制機制 (Transaction) 確保兩表歸還同步成功
                    mysqli_begin_transaction($link);
                    
                    try {
                        $returnedAt = date('Y-m-d H:i:s');
                        
                        // 🎯 1. 更新交接排程資料表 (handover_schedules) 的 returned_at
                        $updateStmt = mysqli_prepare(
                            $link,
                            'UPDATE handover_schedules SET returned_at = ? WHERE handover_id = ? AND returned_at IS NULL'
                        );
                        if (!$updateStmt) {
                            throw new Exception(mysqli_error($link));
                        }
                        mysqli_stmt_bind_param($updateStmt, 'si', $returnedAt, $handoverId);
                        if (!mysqli_stmt_execute($updateStmt)) {
                            throw new Exception(mysqli_stmt_error($updateStmt));
                        }
                        mysqli_stmt_close($updateStmt);

                        // 🎯 2. 同步更新預約主資料表 (reservations) 的 returned_at
                        $updateResStmt = mysqli_prepare(
                            $link,
                            'UPDATE reservations SET returned_at = ? WHERE reservation_id = ?'
                        );
                        if (!$updateResStmt) {
                            throw new Exception(mysqli_error($link));
                        }
                        mysqli_stmt_bind_param($updateResStmt, 'si', $returnedAt, $reservationId);
                        if (!mysqli_stmt_execute($updateResStmt)) {
                            throw new Exception(mysqli_stmt_error($updateResStmt));
                        }
                        mysqli_stmt_close($updateResStmt);

                        // 兩者皆成功，認可提交！
                        mysqli_commit($link);
                        $pageSuccess = '已標記為已歸還，且已同步更新預約紀錄！時間：' . $returnedAt;
                        
                    } catch (Exception $e) {
                        // 出錯時自動安全回滾
                        mysqli_rollback($link);
                        $pageError = '標記已歸還失敗（資料已同步回滾）：' . $e->getMessage();
                    }
                }
            }
        }
    }
}
if ($pageError === '' && $link) {
    // 1. 取得搜尋參數
    $keyword = trim((string)($_GET['keyword'] ?? ''));
    $statusFilter = trim((string)($_GET['status'] ?? ''));

    $approvedRows = []; // 統一用這個變數儲存等等要給前端印出的資料
    $mainSql = "";

    if ($canViewEquipment) {
    // 【器材排程專屬 SQL】
    $mainSql = "
        SELECT
            r.reservation_id,
            r.user_id,
            u.full_name,
            u.email,
            r.actual_pickup_at AS borrow_start_at,
            r.actual_return_at AS borrow_end_at,
            hs.handover_id,
            hs.handover_at AS latest_opened_at,
            hs.note AS latest_note,
            CASE
                WHEN hs.handover_at IS NULL THEN 'pending'
                WHEN hs.handover_at IS NOT NULL AND hs.returned_at IS NULL THEN 'handover'
                ELSE 'returned'
            END AS handover_state,

            -- 🎯 這裡放入你所描述的：計算同申請、同類別的器材總數
            (
    SELECT GROUP_CONCAT(CONCAT(category_counts.equipment_name, ' x', category_counts.item_count) SEPARATOR '、')
    FROM (
        SELECT 
            eri2.reservation_id, 
            ec2.equipment_name, 
            COUNT(eri2.equipment_id) AS item_count
        FROM equipment_reservation_items eri2
        JOIN equipments e2 ON e2.equipment_id = eri2.equipment_id
        JOIN equipment_categories ec2 ON ec2.equipment_code = e2.equipment_code
        GROUP BY eri2.reservation_id, ec2.equipment_code
    ) AS category_counts
    WHERE category_counts.reservation_id = r.reservation_id
) AS equipment_names,

            NULL AS space_names -- 器材模式下補空欄位
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
        WHERE r.approval_stage = 'd'
          AND EXISTS (
              SELECT 1 FROM equipment_reservation_items eri WHERE eri.reservation_id = r.reservation_id
          )
    ";

        // 動態加入關鍵字搜尋
        if ($keyword !== '') {
            $escapedKeyword = mysqli_real_escape_string($link, $keyword);
            $mainSql .= " AND (
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

        // 動態加入狀態篩選
        if ($statusFilter !== '') {
            if ($statusFilter === 'pending') {
                $mainSql .= " AND hs.handover_at IS NULL";
            } elseif ($statusFilter === 'handover') {
                $mainSql .= " AND hs.handover_at IS NOT NULL AND hs.returned_at IS NULL";
            } elseif ($statusFilter === 'returned') {
                $mainSql .= " AND hs.returned_at IS NOT NULL";
            }
        }

    } elseif ($canViewSpace) {
        // 【空間排程專屬 SQL】
        $mainSql = "
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
                END AS handover_state, -- 統一欄位名稱為 handover_state 方便前端讀取
                NULL AS equipment_names, -- 空間模式下補空欄位
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
            WHERE r.approval_stage = 'd'
              AND EXISTS (
                  SELECT 1 FROM space_reservation_items sri WHERE sri.reservation_id = r.reservation_id
              )
        ";

        // 空間關鍵字搜尋
        if ($keyword !== '') {
            $escapedKeyword = mysqli_real_escape_string($link, $keyword);
            $mainSql .= " AND (
                u.full_name LIKE '%{$escapedKeyword}%' 
                OR r.user_id LIKE '%{$escapedKeyword}%'
                OR EXISTS (
                    SELECT 1 FROM space_reservation_items sri2
                    JOIN spaces s2 ON s2.space_id = sri2.space_id
                    WHERE sri2.reservation_id = r.reservation_id AND s2.space_name LIKE '%{$escapedKeyword}%'
                )
            )";
        }

        // 空間狀態篩選
        if ($statusFilter !== '') {
            if ($statusFilter === 'pending') {
                $mainSql .= " AND hs.opened_at IS NULL";
            } elseif (in_array($statusFilter, ['opened', 'handover', 'returned'], true)) {
                $mainSql .= " AND hs.opened_at IS NOT NULL";
            }
        }
    }

    // 統一執行查詢並將資料灌入 $approvedRows
    if ($mainSql !== '') {
        $mainSql .= " ORDER BY r.actual_pickup_at ASC LIMIT 300";
        $result = mysqli_query($link, $mainSql);
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $approvedRows[] = $row;
            }
        } else {
            $pageError = '讀取排程資料失敗：' . mysqli_error($link);
        }
    }
}

// 【部分二：空間排程查詢】
    if ($pageError === '' && $canViewSpace) {
        $spaceRows = [];
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
            WHERE r.approval_stage = 'd' -- 🎯 修正這裡：與器材排程同步，改為篩選最終審核通過 'd'
              AND EXISTS (
                  SELECT 1 FROM space_reservation_items sri WHERE sri.reservation_id = r.reservation_id
              )
        ";

        // 💡 修正：幫空間排程加上「關鍵字搜尋」邏輯（姓名/學號/空間名稱）
        if ($keyword !== '') {
            $escapedKeyword = mysqli_real_escape_string($link, $keyword);
            $spaceSql .= " AND (
                u.full_name LIKE '%{$escapedKeyword}%' 
                OR r.user_id LIKE '%{$escapedKeyword}%'
                OR EXISTS (
                    SELECT 1 FROM space_reservation_items sri2
                    JOIN spaces s2 ON s2.space_id = sri2.space_id
                    WHERE sri2.reservation_id = r.reservation_id AND s2.space_name LIKE '%{$escapedKeyword}%'
                )
            )";
        }

        // 💡 修正：幫空間排程加上「狀態篩選」邏輯（對應 pending / opened）
        if ($statusFilter !== '') {
            if ($statusFilter === 'pending') {
                $spaceSql .= " AND hs.opened_at IS NULL";
            } elseif ($statusFilter === 'opened' || $statusFilter === 'handover' || $statusFilter === 'returned') {
                $spaceSql .= " AND hs.opened_at IS NOT NULL";
            }
        }

        // 💡 修正：最後才補上排序與限制數量
        $spaceSql .= " ORDER BY r.actual_pickup_at ASC LIMIT 300";

        $spaceResult = mysqli_query($link, $spaceSql);
        if ($spaceResult) {
            while ($row = mysqli_fetch_assoc($spaceResult)) {
                $spaceRows[] = $row;
            }
        } else {
            $spaceError = '讀取空間排程失敗：' . mysqli_error($link);
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Noto Sans TC', sans-serif; }
        .record-id-pill {
            display: inline-flex; align-items: center; gap: .35rem;
            padding: .35rem .7rem; border-radius: 999px;
            background: #eef2ff; color: #4f46e5;
            border: 1px solid #c7d2fe;
            font-family: ui-monospace, monospace; font-weight: 800; font-size: 13px;
            width: fit-content;
        }
        .handover-row {
            background: #ffffff; border: 1px solid #e2e8f0; border-radius: 1rem;
            padding: 1rem 1.25rem; transition: .18s ease; cursor: default;
        }
        .handover-row:hover { border-color: #a5b4fc; box-shadow: 0 8px 20px rgba(15,23,42,.07); transform: translateY(-1px); }
        .status-pill {
            display: inline-flex; align-items: center; gap: .3rem;
            padding: .35rem .85rem; border-radius: 999px; font-size: 15px; font-weight: 700; white-space: nowrap;
        }
        .pill-pending   { background: #fff7ed; color: #9a3412; border: 1px solid #fed7aa; }
        .pill-handover { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
        .pill-done     { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .pill-opened   { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .action-btn {
            display: inline-flex; align-items: center; justify-content: center; gap: .35rem;
            padding: .5rem 1rem; border: none; border-radius: .6rem;
            font-size: 15px; font-weight: 700; cursor: pointer; transition: .15s ease; white-space: nowrap;
        }
        .action-btn-primary   { background: #0f766e; color: #fff; }
        .action-btn-primary:hover:not(:disabled) { background: #0d6560; }
        .action-btn-secondary { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
        .action-btn-secondary:hover { background: #e2e8f0; }
        .action-btn:disabled  { background: #e2e8f0; color: #94a3b8; cursor: not-allowed; }
        .note-panel { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: .75rem; padding: .85rem 1rem; margin-top: .65rem; }
        .note-panel textarea { width: 100%; border: 1px solid #cbd5e1; border-radius: .5rem; padding: .5rem .65rem; font-size: 13px; resize: vertical; outline: none; font-family: 'Noto Sans TC', sans-serif; }
        .note-panel textarea:focus { border-color: #818cf8; box-shadow: 0 0 0 3px rgba(129,140,248,.15); }
        .time-chip { font-size: 15px; color: #64748b; display: flex; align-items: center; gap: .3rem; }
        .list-header {
            background: #1e293b; color: #e2e8f0;
            padding: .85rem 1.25rem; border-radius: 1rem 1rem 0 0;
            font-size: 13px; font-weight: 700; letter-spacing: .03em;
        }
        .hidden { display: none !important; }
        .search-bar-wrapper { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: .85rem; padding: .85rem 1rem; }
    </style>
</head>
<body class="bg-slate-50 min-h-screen">
    <?php include __DIR__ . '/nav.php'; ?>

    <div class="container text-slate-700 max-w-7xl mx-auto px-4">
        <main class="main-content">
            <section class="py-6 px-0">

                <div class="flex items-start justify-between mb-5 flex-wrap gap-3">
                    <div>
                        <h1 class="text-2xl font-bold text-slate-800 flex items-center gap-2">
                            <span class="text-2xl"><?php echo $canViewEquipment ? '📦' : '🏠'; ?></span>
                            <?php echo htmlspecialchars($pageModeLabel, ENT_QUOTES, 'UTF-8'); ?>
                        </h1>
                        <p class="text-sm text-slate-500 mt-1">
                            <?php echo $canViewEquipment
                                ? '列出所有已核准的器材申請，請依序標記「已交接」與「已歸還」。'
                                : '列出所有已核准的空間申請，請在開門後標記「已開門」。'; ?>
                        </p>
                    </div>
                    <div class="text-sm text-slate-500 bg-white border border-slate-200 rounded-lg px-3 py-1.5 font-medium">
                        <?php echo $canViewEquipment ? '🎓 工讀生' : '🔑 工友'; ?>
                        &nbsp;·&nbsp;<?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                </div>

                <?php if ($pageSuccess !== ''): ?>
                    <div class="flex items-center gap-2 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl px-4 py-3 mb-4 text-sm font-medium">
                        <i class="fa-solid fa-circle-check text-emerald-500"></i>
                        <?php echo htmlspecialchars($pageSuccess, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>
                <?php if ($pageError !== ''): ?>
                    <div class="flex items-center gap-2 bg-rose-50 border border-rose-200 text-rose-800 rounded-xl px-4 py-3 mb-4 text-sm font-medium">
                        <i class="fa-solid fa-circle-exclamation text-rose-400"></i>
                        <?php echo htmlspecialchars($pageError, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <div class="search-bar-wrapper mb-5">
                    <form method="GET" action="handover_schedule.php" class="flex flex-wrap gap-3 items-end">
                        <div class="flex-1 min-w-[180px]">
                            <label class="block text-xs font-semibold text-slate-500 mb-1">關鍵字搜尋</label>
                            <input type="text" name="keyword"
                                placeholder="姓名、學號、器材／空間名稱…"
                                value="<?php echo htmlspecialchars($_GET['keyword'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-indigo-400">
                        </div>
                        <div class="min-w-[140px]">
                            <label class="block text-xs font-semibold text-slate-500 mb-1">狀態篩選</label>
                            <select name="status" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300">
                                <option value="">全部狀態</option>
                                <option value="pending"  <?php echo ($_GET['status'] ?? '') === 'pending'  ? 'selected' : ''; ?>>待處理</option>
                                <option value="handover" <?php echo ($_GET['status'] ?? '') === 'handover' ? 'selected' : ''; ?>>已交接／使用中</option>
                                <option value="returned" <?php echo ($_GET['status'] ?? '') === 'returned' ? 'selected' : ''; ?>>已歸還</option>
                            </select>
                        </div>
                        <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm font-semibold hover:bg-indigo-700 transition">
                            <i class="fa-solid fa-magnifying-glass mr-1"></i>搜尋
                        </button>
                    </form>
                </div>

                <div class="mt-6 bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
                    <div class="list-header bg-slate-800 text-white p-4 font-bold flex justify-between items-center">
                        <span>已通過最終審核的申請清單</span>
                        <span class="text-xs bg-indigo-500 text-white px-2.5 py-1 rounded-full">共 <?php echo count($approvedRows); ?> 筆</span>
                    </div>

                    <div class="p-4 space-y-4">
                        <?php if (!empty($approvedRows)): ?>
                            <?php foreach ($approvedRows as $row): ?>
                                <div class="handover-row flex flex-wrap md:flex-nowrap justify-between items-center gap-4 p-4 border border-slate-100 rounded-xl bg-white hover:shadow-md transition">
                                    <div class="space-y-2 flex-1">
                                        <div class="flex items-center gap-3">
                                            <span class="record-id-pill"># <?php echo htmlspecialchars((string)$row['reservation_id'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            <span class="font-bold text-slate-800 text-base"><?php echo htmlspecialchars((string)$row['full_name'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars((string)$row['user_id'], ENT_QUOTES, 'UTF-8'); ?>)</span>
                                            
                                            <?php if ($row['handover_state'] === 'pending'): ?>
                                                <span class="status-pill pill-pending"><i class="fa-regular fa-clock"></i> 待交接</span>
                                            <?php elseif ($row['handover_state'] === 'handover'): ?>
                                                <span class="status-pill pill-handover"><i class="fa-solid fa-box-open"></i> 使用中/已交接</span>
                                            <?php else: ?>
                                                <span class="status-pill pill-done"><i class="fa-regular fa-circle-check"></i> 已歸還</span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="text-sm text-slate-600 space-y-1">
    <?php if ($canViewEquipment): ?>
        <p>
            <i class="fa-solid fa-box text-slate-400 mr-1.5 w-4"></i>
            <strong>借用器材：</strong> 
            <?php 
                $eqName = isset($row['equipment_names']) ? trim((string)$row['equipment_names']) : '';
                echo htmlspecialchars($eqName !== '' ? $eqName : '無器材', ENT_QUOTES, 'UTF-8'); 
            ?>
        </p>
    <?php else: ?>
        <p>
            <i class="fa-solid fa-door-open text-slate-400 mr-1.5 w-4"></i>
            <strong>使用空間：</strong> 
            <?php 
                $spName = isset($row['space_names']) ? trim((string)$row['space_names']) : '';
                echo htmlspecialchars($spName !== '' ? $spName : '無空間', ENT_QUOTES, 'UTF-8'); 
            ?>
        </p>
    <?php endif; ?>
    
    <p><i class="fa-regular fa-calendar text-slate-400 mr-1.5 w-4"></i><strong>使用時間：</strong> <?php echo htmlspecialchars((string)$row['borrow_start_at'], ENT_QUOTES, 'UTF-8'); ?> ～ <?php echo htmlspecialchars((string)$row['borrow_end_at'], ENT_QUOTES, 'UTF-8'); ?></p>
    
    <?php if (!empty($row['latest_note'])): ?>
        <p class="text-xs text-amber-600 bg-amber-50 px-2 py-1 rounded mt-1 inline-block"><i class="fa-solid fa-comment-dots mr-1"></i>備註：<?php echo htmlspecialchars((string)$row['latest_note'], ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
</div>
                                    </div>

                                    <div class="flex items-center gap-2">
    <form method="POST" action="handover_schedule.php" class="inline-block">
        <input type="hidden" name="reservation_id" value="<?php echo $row['reservation_id']; ?>">
        
        <?php if ($canViewEquipment): ?>
            <?php if ($row['handover_state'] === 'pending'): ?>
                <input type="hidden" name="action" value="mark_handover">
                <button type="submit" class="action-btn action-btn-primary bg-teal-600 hover:bg-teal-700 text-white font-bold py-2 px-4 rounded-lg text-sm">
                    <i class="fa-solid fa-handshake mr-1"></i> 辦理交接
                </button>
            <?php elseif ($row['handover_state'] === 'handover'): ?>
                <input type="hidden" name="action" value="mark_return">
                <button type="submit" class="action-btn bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2 px-4 rounded-lg text-sm">
                    <i class="fa-solid fa-rotate-left mr-1"></i> 確認歸還
                </button>
            <?php else: ?>
                <button type="button" disabled class="action-btn bg-slate-200 text-slate-400 font-bold py-2 px-4 rounded-lg text-sm">
                    <i class="fa-solid fa-check mr-1"></i> 流程已結束
                </button>
            <?php endif; ?>
        <?php else: ?>
            <?php if ($row['handover_state'] === 'pending'): ?>
                <input type="hidden" name="action" value="mark_open_door">
                <button type="submit" class="action-btn action-btn-primary bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded-lg text-sm">
                    <i class="fa-solid fa-key mr-1"></i> 標記已開門
                </button>
            <?php else: ?>
                <button type="button" disabled class="action-btn bg-slate-200 text-slate-400 font-bold py-2 px-4 rounded-lg text-sm">
                    <i class="fa-solid fa-door-closed mr-1"></i> 空間已啟用
                </button>
            <?php endif; ?>
        <?php endif; ?>
    </form>
</div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-12 text-slate-400">
                                <i class="fa-regular fa-folder-open text-4xl mb-3 block"></i>
                                目前沒有符合最終審核通過的申請資料。
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </section>
        </main>
    </div>
</body>
</html>