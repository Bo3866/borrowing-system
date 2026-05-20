<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?next=edit_application.php');
    exit;
}

$currentUserId = (string)$_SESSION['user_id'];
$displayName = (string)($_SESSION['full_name'] ?? $_SESSION['user_id']);
$reservationId = (int)($_GET['reservation_id'] ?? $_POST['reservation_id'] ?? 0);

if ($reservationId <= 0) {
    header('Location: return_management.php');
    exit;
}

$dbError = '';
$link = getMysqliConnection($dbError);

$editError = '';
$editSuccess = '';
$reservationRow = null;
$equipment_items = [];
$space_items = [];

if ($dbError === '') {
    // load reservation and available columns
    $availableCols = [];
    $colRes = mysqli_query($link, 'SHOW COLUMNS FROM reservations');
    if ($colRes) {
        while ($crow = mysqli_fetch_assoc($colRes)) {
            $availableCols[] = (string)$crow['Field'];
        }
        mysqli_free_result($colRes);
    }

    $selectCols = ['r.reservation_id', 'r.user_id', 'r.approval_status', 'r.borrow_start_at', 'r.borrow_end_at', 'r.organization_name', 'r.activity_name', 'r.participant_count', 'r.staff_count', 'r.club_president', 'r.activity_coordinator', 'r.coordinator_department', 'r.coordinator_phone', 'r.coordinator_other_contact', 'r.setup_flags', 'r.flag_count', 'r.purpose', 'r.proposal_file', 'r.proposal_uploaded_at', 'r.phone'];
    // filter by available
    $finalCols = [];
    foreach ($selectCols as $c) {
        // extract column name
        $m = null;
        if (preg_match('/\.([a-zA-Z0-9_]+)$/', $c, $m)) {
            $colName = $m[1];
            if (in_array($colName, $availableCols, true)) {
                $finalCols[] = 'r.' . $colName;
            }
        }
    }

    if (empty($finalCols)) {
        $dbError = '資料表欄位不足，無法讀取申請資料。';
    } else {
        $checkSql = 'SELECT ' . implode(', ', $finalCols) . ' FROM reservations r WHERE r.reservation_id = ? AND r.user_id = ? LIMIT 1';
        $checkStmt = mysqli_prepare($link, $checkSql);
        if ($checkStmt) {
            mysqli_stmt_bind_param($checkStmt, 'is', $reservationId, $currentUserId);
            mysqli_stmt_execute($checkStmt);
            $res = mysqli_stmt_get_result($checkStmt);
            $reservationRow = $res ? mysqli_fetch_assoc($res) : null;
            mysqli_stmt_close($checkStmt);
        }
        if (!$reservationRow) {
            $editError = '找不到該申請或無權限修改。';
        } elseif ($reservationRow['approval_status'] !== 'pending') {
            $editError = '此申請已進入審核流程，無法修改。';
        }
    }

    // load equipment items
    $equipment_items = [];
    $equipStmt = mysqli_prepare($link, 'SELECT eri.equipment_id, e.equipment_code, ec.equipment_name FROM equipment_reservation_items eri JOIN equipments e ON e.equipment_id = eri.equipment_id JOIN equipment_categories ec ON ec.equipment_code = e.equipment_code WHERE eri.reservation_id = ? ORDER BY ec.equipment_code ASC');
    if ($equipStmt) {
        mysqli_stmt_bind_param($equipStmt, 'i', $reservationId);
        mysqli_stmt_execute($equipStmt);
        $eRes = mysqli_stmt_get_result($equipStmt);
        while ($er = $eRes ? mysqli_fetch_assoc($eRes) : null) {
            $equipment_items[] = $er;
        }
        mysqli_stmt_close($equipStmt);
    }

    // load space items
    $space_items = [];
    $spaceStmt = mysqli_prepare($link, 'SELECT sri.space_id, s.space_name FROM space_reservation_items sri JOIN spaces s ON s.space_id = sri.space_id WHERE sri.reservation_id = ? ORDER BY s.space_id ASC');
    if ($spaceStmt) {
        mysqli_stmt_bind_param($spaceStmt, 'i', $reservationId);
        mysqli_stmt_execute($spaceStmt);
        $sRes = mysqli_stmt_get_result($spaceStmt);
        while ($sr = $sRes ? mysqli_fetch_assoc($sRes) : null) {
            $space_items[] = $sr;
        }
        mysqli_stmt_close($spaceStmt);
    }

    // handle POST (update)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $editError === '') {
        $updated = [];
        $updated['organization_name'] = trim((string)($_POST['organization_name'] ?? ''));
        $updated['activity_name'] = trim((string)($_POST['activity_name'] ?? ''));
        $updated['participant_count'] = trim((string)($_POST['participant_count'] ?? ''));
        $updated['staff_count'] = (int)($_POST['staff_count'] ?? 0);
        $updated['club_president'] = trim((string)($_POST['club_president'] ?? ''));
        $updated['activity_coordinator'] = trim((string)($_POST['activity_coordinator'] ?? ''));
        $updated['coordinator_department'] = trim((string)($_POST['coordinator_department'] ?? ''));
        $updated['coordinator_phone'] = trim((string)($_POST['coordinator_phone'] ?? ''));
        $updated['coordinator_other_contact'] = trim((string)($_POST['coordinator_other_contact'] ?? ''));
        $updated['setup_flags'] = trim((string)($_POST['setup_flags'] ?? 'no'));
        $updated['flag_count'] = (int)($_POST['flag_count'] ?? 0);
        $updated['purpose'] = trim((string)($_POST['purpose'] ?? ''));

        $borrow_start_date = trim((string)($_POST['borrow_start_date'] ?? ''));
        $borrow_start_time_h = $_POST['borrow_start_time_h'] ?? '';
        $borrow_start_time_m = $_POST['borrow_start_time_m'] ?? '';
        $borrow_end_date = trim((string)($_POST['borrow_end_date'] ?? ''));
        $borrow_end_time_h = $_POST['borrow_end_time_h'] ?? '';
        $borrow_end_time_m = $_POST['borrow_end_time_m'] ?? '';

        $borrow_start_at = '';
        $borrow_end_at = '';
        if ($borrow_start_date !== '' && $borrow_start_time_h !== '' && $borrow_start_time_m !== '') {
            $borrow_start_at = sprintf('%s %02d:%02d:00', $borrow_start_date, (int)$borrow_start_time_h, (int)$borrow_start_time_m);
        }
        if ($borrow_end_date !== '' && $borrow_end_time_h !== '' && $borrow_end_time_m !== '') {
            $borrow_end_at = sprintf('%s %02d:%02d:00', $borrow_end_date, (int)$borrow_end_time_h, (int)$borrow_end_time_m);
        }

        // basic validation
        if ($updated['organization_name'] === '') {
            $editError = '請填寫單位名稱。';
        } elseif ($updated['activity_name'] === '') {
            $editError = '請填寫活動名稱。';
        } elseif ($updated['purpose'] === '') {
            $editError = '請填寫用途說明。';
        } elseif ($borrow_start_at === '' || $borrow_end_at === '') {
            $editError = '請完整填寫借用起訖日期與時間。';
        } elseif (strtotime($borrow_end_at) <= strtotime($borrow_start_at)) {
            $editError = '結束時間不可早於或等於開始時間。';
        }

        // handle proposal_file upload
        $uploadedProposalPath = null;
        $uploadedProposalDbPath = null;
        $uploadedProposalAt = null;
        if (isset($_FILES['proposal_file']) && $_FILES['proposal_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            $proposalFile = $_FILES['proposal_file'];
            if ($proposalFile['error'] !== UPLOAD_ERR_OK) {
                $editError = '企劃書上傳失敗（錯誤碼：' . (int)$proposalFile['error'] . '）。';
            } else {
                $maxBytes = 5 * 1024 * 1024;
                if ((int)$proposalFile['size'] > $maxBytes) {
                    $editError = '企劃書大小不可超過 5MB。';
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
                        $editError = '企劃書格式不支援，僅接受 PDF。';
                    } else {
                        $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'proposals';
                        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                            $editError = '建立上傳目錄失敗。';
                        } else {
                            $safeBasename = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo((string)$proposalFile['name'], PATHINFO_FILENAME));
                            $targetName = time() . '_' . $safeBasename . '.pdf';
                            $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $targetName;
                            if (!move_uploaded_file($proposalFile['tmp_name'], $targetPath)) {
                                $editError = '企劃書儲存失敗。';
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

        if ($editError === '') {
            // perform update
            mysqli_begin_transaction($link);
            try {
                // build update list based on available cols
                $updateFields = [];
                $updateValues = [];
                $updateTypes = '';

                $candidates = [
                    'organization_name' => $updated['organization_name'],
                    'activity_name' => $updated['activity_name'],
                    'participant_count' => $updated['participant_count'],
                    'staff_count' => $updated['staff_count'],
                    'club_president' => $updated['club_president'],
                    'activity_coordinator' => $updated['activity_coordinator'],
                    'coordinator_department' => $updated['coordinator_department'],
                    'coordinator_phone' => $updated['coordinator_phone'],
                    'coordinator_other_contact' => $updated['coordinator_other_contact'],
                    'setup_flags' => $updated['setup_flags'],
                    'flag_count' => $updated['flag_count'],
                    'purpose' => $updated['purpose'],
                    'borrow_start_at' => $borrow_start_at,
                    'borrow_end_at' => $borrow_end_at,
                ];

                foreach ($candidates as $k => $v) {
                    if (in_array($k, $availableCols, true)) {
                        $updateFields[] = "{$k} = ?";
                        $updateValues[] = $v;
                        $updateTypes .= is_int($v) ? 'i' : 's';
                    }
                }

                // if proposal file uploaded, update
                if ($uploadedProposalDbPath !== null && in_array('proposal_file', $availableCols, true) && in_array('proposal_uploaded_at', $availableCols, true)) {
                    $updateFields[] = 'proposal_file = ?';
                    $updateValues[] = $uploadedProposalDbPath;
                    $updateTypes .= 's';

                    $updateFields[] = 'proposal_uploaded_at = ?';
                    $updateValues[] = $uploadedProposalAt;
                    $updateTypes .= 's';
                }

                if (empty($updateFields)) {
                    throw new RuntimeException('無可更新的欄位。');
                }

                $updateValues[] = $reservationId;
                $updateTypes .= 'i';

                $updateSql = 'UPDATE reservations SET ' . implode(', ', $updateFields) . ', updated_at = NOW() WHERE reservation_id = ?';
                $updateStmt = mysqli_prepare($link, $updateSql);
                if (!$updateStmt) {
                    throw new RuntimeException('準備更新語句失敗：' . mysqli_error($link));
                }
                mysqli_stmt_bind_param($updateStmt, $updateTypes, ...$updateValues);
                mysqli_stmt_execute($updateStmt);
                mysqli_stmt_close($updateStmt);

                // handle equipment/space removals and additions
                // removals: remove_equipment[] (equipment_id), remove_space[] (space_id)
                $removeEquipment = $_POST['remove_equipment'] ?? [];
                $removeSpace = $_POST['remove_space'] ?? [];

                // additions: arrays
                $addEquipCodes = $_POST['add_equipment_code'] ?? [];
                $addEquipQtys = $_POST['add_equipment_qty'] ?? [];
                $addSpaceIds = $_POST['add_space_id'] ?? [];

                // remove equipment: delete rows and free equipment status
                if (!empty($removeEquipment) && is_array($removeEquipment)) {
                    $delEquipStmt = mysqli_prepare($link, 'DELETE FROM equipment_reservation_items WHERE reservation_id = ? AND equipment_id = ? LIMIT 1');
                    $freeEquipStmt = mysqli_prepare($link, 'UPDATE equipments SET operation_status = 1 WHERE equipment_id = ? AND operation_status = 2');
                    if (!$delEquipStmt || !$freeEquipStmt) {
                        throw new RuntimeException('準備移除器材失敗：' . mysqli_error($link));
                    }
                    foreach ($removeEquipment as $eid) {
                        $eid = (int)$eid;
                        mysqli_stmt_bind_param($delEquipStmt, 'ii', $reservationId, $eid);
                        mysqli_stmt_execute($delEquipStmt);
                        mysqli_stmt_bind_param($freeEquipStmt, 'i', $eid);
                        mysqli_stmt_execute($freeEquipStmt);
                    }
                    mysqli_stmt_close($delEquipStmt);
                    mysqli_stmt_close($freeEquipStmt);
                }

                // remove spaces
                if (!empty($removeSpace) && is_array($removeSpace)) {
                    $delSpaceStmt = mysqli_prepare($link, 'DELETE FROM space_reservation_items WHERE reservation_id = ? AND space_id = ? LIMIT 1');
                    $freeSpaceStmt = mysqli_prepare($link, 'UPDATE spaces SET space_status = "1" WHERE space_id = ? AND space_status = "2"');
                    if (!$delSpaceStmt || !$freeSpaceStmt) {
                        throw new RuntimeException('準備移除場地失敗：' . mysqli_error($link));
                    }
                    foreach ($removeSpace as $sid) {
                        $sid = (string)$sid;
                        mysqli_stmt_bind_param($delSpaceStmt, 'is', $reservationId, $sid);
                        mysqli_stmt_execute($delSpaceStmt);
                        mysqli_stmt_bind_param($freeSpaceStmt, 's', $sid);
                        mysqli_stmt_execute($freeSpaceStmt);
                    }
                    mysqli_stmt_close($delSpaceStmt);
                    mysqli_stmt_close($freeSpaceStmt);
                }

                // add equipments: for each code/qty, pick available equipment_ids and insert
                if (!empty($addEquipCodes) && is_array($addEquipCodes)) {
                    $selectEquipmentStmt = mysqli_prepare($link, 'SELECT e.equipment_id FROM equipments e WHERE e.equipment_code = ? AND e.operation_status = 1 AND e.equipment_id NOT IN (SELECT eri.equipment_id FROM equipment_reservation_items eri JOIN reservations r ON r.reservation_id = eri.reservation_id WHERE r.approval_status IN ("pending", "approved") AND r.borrow_start_at < ? AND r.borrow_end_at > ?) ORDER BY e.equipment_id ASC LIMIT ?');
                    $insertEquipItemStmt = mysqli_prepare($link, 'INSERT INTO equipment_reservation_items (reservation_id, equipment_id) VALUES (?, ?)');
                    $markEquipUsedStmt = mysqli_prepare($link, 'UPDATE equipments SET operation_status = 2 WHERE equipment_id = ? AND operation_status = 1');
                    if (!$selectEquipmentStmt || !$insertEquipItemStmt || !$markEquipUsedStmt) {
                        throw new RuntimeException('準備新增器材失敗：' . mysqli_error($link));
                    }
                    foreach ($addEquipCodes as $i => $code) {
                        $code = trim((string)$code);
                        $qty = isset($addEquipQtys[$i]) ? (int)$addEquipQtys[$i] : 0;
                        if ($code === '' || $qty <= 0) continue;

                        mysqli_stmt_bind_param($selectEquipmentStmt, 'sssi', $code, $borrow_end_at, $borrow_start_at, $qty);
                        mysqli_stmt_execute($selectEquipmentStmt);
                        $availRes = mysqli_stmt_get_result($selectEquipmentStmt);
                        $equipmentIds = [];
                        while ($rowEq = $availRes ? mysqli_fetch_assoc($availRes) : null) {
                            $equipmentIds[] = (int)$rowEq['equipment_id'];
                        }
                        if (count($equipmentIds) < $qty) {
                            throw new RuntimeException("器材 {$code} 可取得數量不足。請檢查時段或數量。");
                        }
                        foreach (array_slice($equipmentIds, 0, $qty) as $eid) {
                            mysqli_stmt_bind_param($insertEquipItemStmt, 'ii', $reservationId, $eid);
                            mysqli_stmt_execute($insertEquipItemStmt);
                            mysqli_stmt_bind_param($markEquipUsedStmt, 'i', $eid);
                            mysqli_stmt_execute($markEquipUsedStmt);
                        }
                    }
                    mysqli_stmt_close($selectEquipmentStmt);
                    mysqli_stmt_close($insertEquipItemStmt);
                    mysqli_stmt_close($markEquipUsedStmt);
                }

                // add spaces: for each space id, check conflict and insert
                if (!empty($addSpaceIds) && is_array($addSpaceIds)) {
                    $spaceConflictStmt = mysqli_prepare($link, 'SELECT COUNT(*) AS conflict_count FROM space_reservation_items sri JOIN reservations r ON r.reservation_id = sri.reservation_id WHERE sri.space_id = ? AND r.approval_status IN ("pending", "approved") AND NOT (r.borrow_end_at < ? OR r.borrow_start_at > ?)');
                    $insertSpaceItemStmt = mysqli_prepare($link, 'INSERT INTO space_reservation_items (reservation_id, space_id) VALUES (?, ?)');
                    if (!$spaceConflictStmt || !$insertSpaceItemStmt) {
                        throw new RuntimeException('準備新增場地失敗：' . mysqli_error($link));
                    }
                    foreach ($addSpaceIds as $sid) {
                        $sid = trim((string)$sid);
                        if ($sid === '') continue;
                        mysqli_stmt_bind_param($spaceConflictStmt, 'sss', $sid, $borrow_start_at, $borrow_end_at);
                        mysqli_stmt_execute($spaceConflictStmt);
                        $confRes = mysqli_stmt_get_result($spaceConflictStmt);
                        $crow = $confRes ? mysqli_fetch_assoc($confRes) : null;
                        if ($crow && (int)$crow['conflict_count'] > 0) {
                            throw new RuntimeException("場地 {$sid} 時段衝突，無法新增。");
                        }
                        mysqli_stmt_bind_param($insertSpaceItemStmt, 'is', $reservationId, $sid);
                        mysqli_stmt_execute($insertSpaceItemStmt);
                    }
                    mysqli_stmt_close($spaceConflictStmt);
                    mysqli_stmt_close($insertSpaceItemStmt);
                }

                mysqli_commit($link);
                $editSuccess = '申請已更新。';
                // refresh reservationRow values
                $reservationRow = array_merge($reservationRow, $candidates);
                if ($uploadedProposalDbPath !== null) {
                    $reservationRow['proposal_file'] = $uploadedProposalDbPath;
                    $reservationRow['proposal_uploaded_at'] = $uploadedProposalAt;
                }
            } catch (Throwable $e) {
                mysqli_rollback($link);
                if ($uploadedProposalPath !== null && is_file($uploadedProposalPath)) {
                    @unlink($uploadedProposalPath);
                }
                $editError = $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>修改申請｜校園資源租借系統</title>
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
</head>
<body>
    <div class="container">
        <nav class="navbar">
            <div class="navbar-brand"><h1>📚 校園資源租借系統</h1></div>
            <div class="navbar-menu">
                <button class="nav-btn" onclick="location.href='index.php'">回首頁</button>
                <button class="nav-btn" onclick="location.href='return_management.php'">申請管理</button>
                <button class="nav-btn" type="button" disabled><?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></button>
                <button class="nav-btn" onclick="location.href='logout.php'">登出</button>
            </div>
        </nav>

        <main class="main-content">
            <section class="card">
                <h2>修改申請（僅限尚未審核的申請）</h2>

                <?php if ($dbError !== '') { ?>
                    <div class="login-alert"><?php echo htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } ?>
                <?php if ($editError !== '') { ?>
                    <div class="login-alert"><?php echo htmlspecialchars($editError, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } ?>
                <?php if ($editSuccess !== '') { ?>
                    <div class="borrow-success"><?php echo htmlspecialchars($editSuccess, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } ?>

                <?php if ($dbError === '' && $reservationRow && $editError === '') { ?>
                    <form method="post" enctype="multipart/form-data" action="edit_application.php" novalidate>
                        <input type="hidden" name="reservation_id" value="<?php echo (int)$reservationId; ?>">

                        <div class="form-group">
                            <label for="organization_name">單位名稱 / 主辦社團 <span style="color:red">*</span></label>
                            <input type="text" id="organization_name" name="organization_name" value="<?php echo htmlspecialchars((string)($reservationRow['organization_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="activity_name">活動名稱 <span style="color:red">*</span></label>
                            <input type="text" id="activity_name" name="activity_name" value="<?php echo htmlspecialchars((string)($reservationRow['activity_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>

                        <div style="display:flex; gap:12px;">
                            <div class="form-group" style="flex:1;">
                                <label for="participant_count">活動對象人數</label>
                                <input type="text" id="participant_count" name="participant_count" value="<?php echo htmlspecialchars((string)($reservationRow['participant_count'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="form-group" style="flex:1;">
                                <label for="staff_count">工作人員人數</label>
                                <input type="number" id="staff_count" name="staff_count" min="0" value="<?php echo htmlspecialchars((string)($reservationRow['staff_count'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="club_president">社 / 會長</label>
                            <input type="text" id="club_president" name="club_president" value="<?php echo htmlspecialchars((string)($reservationRow['club_president'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>

                        <div class="form-group">
                            <label for="activity_coordinator">活動負責人</label>
                            <input type="text" id="activity_coordinator" name="activity_coordinator" value="<?php echo htmlspecialchars((string)($reservationRow['activity_coordinator'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>

                        <div class="form-group">
                            <label for="coordinator_phone">聯絡電話</label>
                            <input type="text" id="coordinator_phone" name="coordinator_phone" value="<?php echo htmlspecialchars((string)($reservationRow['coordinator_phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>

                        <div class="form-group">
                            <label for="purpose">用途說明 <span style="color:red">*</span></label>
                            <textarea id="purpose" name="purpose" required><?php echo htmlspecialchars((string)($reservationRow['purpose'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>

                        <div style="display:flex; gap:12px;">
                            <div class="form-group">
                                <label>借用開始日期與時間</label>
                                <?php
                                    $bs = $reservationRow['borrow_start_at'] ?? '';
                                    $be = $reservationRow['borrow_end_at'] ?? '';
                                    $bsDate = $bs ? date('Y-m-d', strtotime($bs)) : '';
                                    $bsH = $bs ? date('H', strtotime($bs)) : '';
                                    $bsM = $bs ? date('i', strtotime($bs)) : '';
                                    $beDate = $be ? date('Y-m-d', strtotime($be)) : '';
                                    $beH = $be ? date('H', strtotime($be)) : '';
                                    $beM = $be ? date('i', strtotime($be)) : '';
                                ?>
                                <input type="date" name="borrow_start_date" value="<?php echo htmlspecialchars($bsDate, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="number" name="borrow_start_time_h" min="0" max="23" value="<?php echo htmlspecialchars($bsH, ENT_QUOTES, 'UTF-8'); ?>"> :
                                <input type="number" name="borrow_start_time_m" min="0" max="59" value="<?php echo htmlspecialchars($bsM, ENT_QUOTES, 'UTF-8'); ?>">
                            </div>

                            <div class="form-group">
                                <label>借用結束日期與時間</label>
                                <input type="date" name="borrow_end_date" value="<?php echo htmlspecialchars($beDate, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="number" name="borrow_end_time_h" min="0" max="23" value="<?php echo htmlspecialchars($beH, ENT_QUOTES, 'UTF-8'); ?>"> :
                                <input type="number" name="borrow_end_time_m" min="0" max="59" value="<?php echo htmlspecialchars($beM, ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="proposal_file">活動企劃書（選填，可上傳以更新）</label>
                            <input type="file" id="proposal_file" name="proposal_file" accept=".pdf,application/pdf">
                            <?php if (!empty($reservationRow['proposal_file'])) { ?>
                                <div>目前檔案：<a href="<?php echo htmlspecialchars($reservationRow['proposal_file'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank">查看</a></div>
                            <?php } ?>
                        </div>

                        <div class="form-group">
                            <label>場地（可移除或新增）</label>
                            <?php if (!empty($space_items)) { ?>
                                <div style="margin-bottom:8px;">目前場地：請勾選欲移除的場地</div>
                                <ul>
                                    <?php foreach ($space_items as $s) { ?>
                                        <li>
                                            <label><input type="checkbox" name="remove_space[]" value="<?php echo htmlspecialchars($s['space_id'], ENT_QUOTES, 'UTF-8'); ?>"> <?php echo htmlspecialchars($s['space_name'] . ' (' . $s['space_id'] . ')', ENT_QUOTES, 'UTF-8'); ?></label>
                                        </li>
                                    <?php } ?>
                                </ul>
                            <?php } else { ?>
                                <div>目前沒有場地預約。</div>
                            <?php } ?>

                            <div style="margin-top:10px;">
                                <label>新增場地（輸入場地代碼，每次可新增一個，可重複送出多個）</label>
                                <div style="display:flex; gap:8px; align-items:center;">
                                    <input type="text" name="add_space_id[]" placeholder="場地代碼（例如 S101）">
                                </div>
                                <small style="color:#666;">注意：新增時系統會檢查時段衝突。</small>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>器材（可移除或新增）</label>
                            <?php if (!empty($equipment_items)) { ?>
                                <div style="margin-bottom:8px;">目前器材：請勾選欲移除的器材</div>
                                <ul>
                                    <?php foreach ($equipment_items as $e) { ?>
                                        <li>
                                            <label><input type="checkbox" name="remove_equipment[]" value="<?php echo (int)$e['equipment_id']; ?>"> <?php echo htmlspecialchars($e['equipment_code'] . ' - ' . $e['equipment_name'], ENT_QUOTES, 'UTF-8'); ?></label>
                                        </li>
                                    <?php } ?>
                                </ul>
                            <?php } else { ?>
                                <div>目前沒有器材預約。</div>
                            <?php } ?>

                            <div style="margin-top:10px;">
                                <label>新增器材（輸入器材代碼與數量）</label>
                                <div id="addEquipContainer">
                                    <div style="display:flex; gap:8px; margin-bottom:6px; align-items:center;">
                                        <input type="text" name="add_equipment_code[]" placeholder="器材代碼（例如 MIC01）">
                                        <input type="number" name="add_equipment_qty[]" placeholder="數量" min="1" style="width:80px;">
                                    </div>
                                </div>
                                <button type="button" class="btn-secondary" id="addEquipRowBtn" style="margin-top:6px;">再加一筆</button>
                                <small style="display:block; color:#666; margin-top:6px;">系統會依照時段與可用狀態自動分配實體器材。</small>
                            </div>
                        </div>
                        <script>
                            document.addEventListener('DOMContentLoaded', function(){
                                const btn = document.getElementById('addEquipRowBtn');
                                const container = document.getElementById('addEquipContainer');
                                btn && btn.addEventListener('click', function(){
                                    const div = document.createElement('div');
                                    div.style.display = 'flex'; div.style.gap = '8px'; div.style.marginBottom = '6px'; div.style.alignItems = 'center';
                                    div.innerHTML = '<input type="text" name="add_equipment_code[]" placeholder="器材代碼（例如 MIC01）"> <input type="number" name="add_equipment_qty[]" placeholder="數量" min="1" style="width:80px;"> <button type="button" class="btn-secondary" onclick="this.parentNode.remove()">移除</button>';
                                    container.appendChild(div);
                                });
                            });
                        </script>

                        <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:12px;">
                            <button type="button" class="btn-secondary" onclick="location.href='return_management.php'">取消</button>
                            <button type="submit" class="btn-primary">儲存變更</button>
                        </div>
                    </form>
                <?php } ?>
            </section>
        </main>
    </div>
</body>
</html>
