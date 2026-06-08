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

    $selectCols = ['r.reservation_id', 'r.user_id', 'r.approval_status', 'r.borrow_start_at', 'r.borrow_end_at', 'r.organization_name', 'r.activity_name', 'r.participant_count', 'r.staff_count', 'r.activity_coordinator', 'r.coordinator_department', 'r.coordinator_phone', 'r.coordinator_other_contact', 'r.setup_flags', 'r.flag_count', 'r.purpose', 'r.proposal_file', 'r.proposal_uploaded_at', 'r.phone'];
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        /* 避免快取問題，在此處再次宣告必要的樣式 */
        
        /* 增強互動性與動態效果 */
        *:not(i):not(svg) { transition: color 0.15s, background-color 0.15s, border-color 0.15s, box-shadow 0.15s !important; }
        .card.borrow-form-card {
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03); 
            transition: transform 0.3s ease, box-shadow 0.3s ease !important;
        }
        .card.borrow-form-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
        
        /* 表單元素焦點效果 */
        input[type="text"], input[type="date"], input[type="number"], select, textarea {
            transition: all 0.2s ease !important;
        }
        input[type="text"]:focus, input[type="date"]:focus, input[type="number"]:focus, select:focus, textarea:focus {
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.3) !important;
            border-color: #3b82f6 !important;
            transform: translateY(-1px);
        }
        
        /* 按鈕互動 */
        .btn-primary, .btn-secondary {
            transition: all 0.2s ease !important;
            position: relative;
            overflow: hidden;
        }
        .btn-primary:hover, .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .btn-primary:active, .btn-secondary:active {
            transform: translateY(0);
        }

        .nav-btn {
            transition: all 0.2s ease !important;
        }
        .nav-btn:hover:not(:disabled) {
            transform: translateY(-2px);
            color: #3b82f6;
        }

        .equipment-selector-container {
            display: flex; gap: 20px; border: 1px solid #ddd;
            border-radius: 8px; padding: 20px; background: #f8fafc;
            align-items: stretch; margin-bottom: 20px;
        }
        @media (max-width: 900px) {
            .equipment-selector-container { flex-direction: column; }
            .es-left, .es-right { height: auto !important; min-height: 400px; }
        }
        .es-left {
            flex: 1.6; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px;
            height: 600px; display: flex; flex-direction: column; width: 100%; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
            transition: box-shadow 0.3s ease !important;
        }
        .es-left:hover { box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); }
        /* flatpickr disabled day custom style for unavailable equipment dates */
        .flatpickr-day.borrow-disabled {
            background: #f8d7da !important;
            color: #721c24 !important;
            border-color: #f5c6cb !important;
        }
        
        .es-right {
            flex: 1; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px;
            height: 600px; display: flex; flex-direction: column; width: 100%; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
            transition: box-shadow 0.3s ease !important;
        }
        .es-right:hover { box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); }
        
        .es-title { padding: 15px; font-weight: bold; border-bottom: 1px solid #e2e8f0; background: #fff; color: #333; display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
        .es-search { padding: 10px 15px; border-bottom: 1px solid #e2e8f0; background: #fff; flex-shrink: 0; }
        .es-search input { width: 100%; padding: 10px 15px; border: 1px solid #ccc; border-radius: 20px; outline: none; font-size: 14px; transition: box-shadow 0.2s, border-color 0.2s !important; }
        .es-search input:focus { box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2) !important; border-color: #3b82f6; }
        
        .es-list { flex: 1; overflow-y: auto; margin: 0; padding: 15px; list-style: none; background: #f8fafc !important; scroll-behavior: smooth; }
        
        .es-item { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; margin-bottom: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); transition: transform 0.2s ease, box-shadow 0.2s ease !important; }
        .es-item:hover { transform: translateY(-3px); box-shadow: 0 8px 12px -3px rgba(0,0,0,0.1); border-color: #cbd5e1; }
        
        .es-item-header { display: flex; align-items: center; justify-content: space-between; padding: 15px; cursor: pointer; transition: background 0.2s; min-height: 70px; border-radius: 10px; }
        .es-item-header:hover { background: #f1f5f9; }
        .es-item-info { display: flex; align-items: flex-start; gap: 15px; flex: 1; min-width: 0; }
        .es-item-icon { width: 40px; height: 40px; border-radius: 8px; background: #f1f5f9; display: flex; align-items: center; justify-content: center; color: #64748b; font-size: 20px; flex-shrink: 0; }
        .es-item-name-block { display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 0; }
        .es-item-title { font-weight: bold; font-size: 16px; color: #1e293b; line-height: 1.3;}
        .es-item-subtitle { font-size: 13px; color: #64748b; display: flex; flex-direction: column; gap: 4px; line-height: 1.3; }
        button.es-btn-invite { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; padding: 6px 0; width: 75px !important; text-align: center; border-radius: 6px; font-weight: 500; font-size: 14px; transition: all 0.2s; white-space: nowrap; flex-shrink: 0; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; margin-left: 15px; }
        .es-btn-invite:hover:not(:disabled) { background: #dbeafe; }
        .es-btn-invite:disabled { background: #f8fafc; color: #94a3b8; cursor: not-allowed; border-color: #e2e8f0; }
        .es-item-body { display: none; padding: 15px; background: #f8f9fa; border-top: 1px dashed #eee; font-size: 14px; }
        .es-item-body.active { display: block; animation: slideDown 0.3s cubic-bezier(0.16, 1, 0.3, 1); border-radius: 0 0 10px 10px; }
        
        @keyframes slideDown {
            0% { opacity: 0; transform: translateY(-10px); }
            100% { opacity: 1; transform: translateY(0); }
        }
        @keyframes fadeIn {
            0% { opacity: 0; }
            100% { opacity: 1; }
        }
        .es-item-details { display: flex; justify-content: space-between; margin-bottom: 15px; color: #666; font-weight: bold; }
        .es-item-action { display: flex; gap: 10px; align-items: center; }
        .es-item-action input[type="number"] { width: 70px; padding: 4px 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; }
        button.es-btn-add { background: #3b82f6; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-weight: 500; font-size: 14px; transition: background 0.2s; width: 100% !important; margin-left: 0; }
        button.es-btn-add:hover { background: #2563eb; }
        .es-right-item { display: flex; justify-content: space-between; align-items: center; padding: 12px; border-bottom: 1px solid #eee; transition: all 0.2s ease !important; border-radius: 8px; margin-bottom: 5px; }
        .es-right-item:hover { background-color: #f1f5f9; transform: translateX(5px); }
        button.es-btn-remove { color: #ef4444; background: none; border: none; cursor: pointer; font-size: 14px; padding: 5px 10px; width: auto !important; transition: all 0.2s ease; border-radius: 6px; }
        button.es-btn-remove:hover { background-color: rgba(239, 68, 68, 0.1); color: #b91c1c; }
        .cart-header { display: flex; justify-content: space-between; padding: 10px 12px; font-weight: bold; color: #64748b; border-bottom: 2px solid #e2e8f0; margin-bottom: 10px; }
        .cart-row { display: flex; justify-content: space-between; align-items: center; width: 100%; gap: 10px; }
        .cart-col-name { flex: 2; font-weight: 500; color: #333; font-size: 14px; }
        .cart-col-qty { flex: 1; text-align: center; }
        .cart-col-action { flex: 1; text-align: right; }
        
        .full-width-layout {
            grid-template-columns: 1fr !important;
        }

        /* 月曆自訂樣式 */
        .cal-grid-header { text-align:center; font-weight:bold; color:#64748b; padding:12px; background:#f1f5f9; border-radius:6px; font-size:14px; }
        .cal-day-cell { min-height:85px; border:1px solid #e2e8f0; border-radius:8px; padding:8px; cursor:pointer; transition:all 0.2s; display:flex; flex-direction:column; background:#ffffff; box-shadow:0 1px 2px rgba(0,0,0,0.02); }
        .cal-day-cell:hover { border-color:#3b82f6; box-shadow:0 0 0 2px rgba(59,130,246,0.15); transform:translateY(-1px); }
        .cal-day-cell.empty { background:transparent; border:none; cursor:default; box-shadow:none; }
        .cal-day-cell.empty:hover { transform:none; box-shadow:none; }
        .cal-day-date { font-weight:bold; color:#334155; margin-bottom:5px; font-size:15px; }
        
        .cal-day-status { font-size:13px; flex:1; display:flex; flex-direction:column; justify-content:center; align-items:center; text-align:center; border-radius:6px; font-weight:bold; padding:4px; }
        .status-full { background:#dcfce7; color:#166534; border:1px solid #bbf7d0;} /* 全天可借 */
        .status-partial { background:#fef9c3; color:#854d0e; border:1px solid #fef08a;} /* 數量變少/部分可借 */
        .status-none { background:#fee2e2; color:#991b1b; border:1px solid #fecaca;} /* 全天無法借 */
        .status-unknown { background:#f1f5f9; color:#64748b; border:1px solid #e2e8f0;} /* 過去日期或無效 */
        
        .period-list { display:grid; grid-template-columns:repeat(auto-fill, minmax(130px, 1fr)); gap:10px; }
        .period-item { padding:12px; border-radius:8px; font-size:13px; text-align:center; box-shadow:0 1px 3px rgba(0,0,0,0.05); transition:transform 0.2s; }
        .period-item:hover { transform:translateY(-2px); }
        .calendar-card { width:100%; border-top:4px solid #3b82f6; }

                /* 草稿功能區 */
        .draft-action-row{
            display:flex;
            gap:15px;
            margin-top:15px;
            width:100%;
        }

        .draft-btn{
            flex:1;
            border:none;
            border-radius:10px;
            padding:14px 20px;
            font-size:15px;
            font-weight:600;
            cursor:pointer;
            transition:all .25s ease;
        }

        .save-btn{
            background:#f59e0b;
            color:#fff;
        }

        .save-btn:hover{
            background:#d97706;
            transform:translateY(-2px);
        }

        .draft-box-btn{
            background:#6366f1;
            color:#fff;
        }

        .draft-box-btn:hover{
            background:#4338ca;
            transform:translateY(-2px);
        }

        .draft-message{
            margin-top:10px;
            font-size:14px;
            color:#64748b;
        }

        /* 草稿保存选择模态窗口 */
        .draft-choice-modal {
            display: none;
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.3s ease;
        }

        .draft-choice-modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .draft-choice-content {
            background: white;
            border-radius: 15px;
            padding: 30px;
            max-width: 450px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            0% { transform: translateY(30px); opacity: 0; }
            100% { transform: translateY(0); opacity: 1; }
        }

        .draft-choice-title {
            font-size: 18px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 15px;
            text-align: center;
        }

        .draft-choice-draft-id {
            text-align: center;
            font-size: 14px;
            color: #64748b;
            margin-bottom: 20px;
            background: #f1f5f9;
            padding: 10px;
            border-radius: 8px;
            font-weight: 500;
        }

        .draft-choice-description {
            font-size: 15px;
            color: #475569;
            margin-bottom: 25px;
            line-height: 1.6;
            text-align: center;
        }

        .draft-choice-buttons {
            display: flex;
            gap: 12px;
            justify-content: center;
        }

        .draft-btn-choice {
            flex: 1;
            padding: 12px 20px;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            min-height: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .draft-btn-update {
            background: #3b82f6;
            color: white;
        }

        .draft-btn-update:hover {
            background: #2563eb;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .draft-btn-new {
            background: #f59e0b;
            color: white;
        }

        .draft-btn-new:hover {
            background: #d97706;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
        }

    </style>
    <!-- 引入 Flatpickr -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/zh-tw.js"></script>
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
            <section class="borrow-page">
                <h2>修改申請</h2>
                <p class="borrow-subtitle">狀態：審核中。僅限尚未審核的申請可修改，調整後請再次送出。</p>

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
                    <div class="borrow-layout full-width-layout" id="mainBorrowLayout">
                        <section class="card borrow-form-card">
                            <div class="stepper-container">
                                <div class="stepper-item active" id="stepper-1" onclick="goToStep(1)" style="cursor:pointer;">
                                    <div class="step-circle">1</div>
                                    <div class="step-label">活動申請(1/2)</div>
                                </div>
                                <div class="stepper-line"></div>
                                <div class="stepper-item" id="stepper-2" onclick="goToStep(2)" style="cursor:pointer;">
                                    <div class="step-circle">2</div>
                                    <div class="step-label">活動申請(2/2)</div>
                                </div>
                                <div class="stepper-line"></div>
                                <div class="stepper-item" id="stepper-3" onclick="goToStep(3)" style="cursor:pointer;">
                                    <div class="step-circle">3</div>
                                    <div class="step-label">器材與場地 (確認送出)</div>
                                </div>
                            </div>

                            <form method="post" enctype="multipart/form-data" action="edit_application.php" novalidate class="borrow-form" id="editApplicationForm">
                                <input type="hidden" name="reservation_id" value="<?php echo (int)$reservationId; ?>">
                                <input type="hidden" name="current_step" id="current_step" value="1">
                                <input type="hidden" name="current_draft_id" id="current_draft_id" value="">
                                <input type="hidden" name="draft_proposal_file" id="draft_proposal_file" value="<?php echo htmlspecialchars($reservationRow['proposal_file'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="draft_proposal_original_name" id="draft_proposal_original_name" value="<?php echo htmlspecialchars($reservationRow['proposal_original_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="draft_proposal_uploaded_at" id="draft_proposal_uploaded_at" value="<?php echo htmlspecialchars($reservationRow['proposal_uploaded_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="file" id="proposal_file" name="proposal_file" accept=".pdf,application/pdf" style="opacity: 0; position: absolute; z-index: -1; width: 0; height: 0;" onchange="if (this.files.length > 0) { document.getElementById('proposal_file_name_display').innerText = '已上傳新企劃書：' + this.files[0].name; const f=document.getElementById('draft_proposal_file'); const n=document.getElementById('draft_proposal_original_name'); const t=document.getElementById('draft_proposal_uploaded_at'); if(f)f.value=''; if(n)n.value=''; if(t)t.value=''; } else { document.getElementById('proposal_file_name_display').innerText = ''; }">

                                <div class="step-content active" id="step-content-1">
                                <h3 class="step-title" style="margin-bottom: 10px;">第一步：活動基本資料</h3>
                                <p class="step-desc" style="color: #7f8c8d; margin-bottom: 20px;">請填寫活動相關資訊與申請日期。</p>

                                <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap; margin-bottom: 20px; background: #eef2ff; padding: 15px; border-radius: 8px; border: 1px solid #c7d2fe;">
                                    <h4 style="margin: 0; color: #1e40af; font-size: 16px;">企劃書</h4>
                                    <label for="proposal_file" style="margin: 0; background-color: #1554b9; color: white; padding: 6px 15px; border-radius: 5px; cursor: pointer; font-size: 16px; font-weight: normal; transition: background 0.2s;">
                                        📤 按此上傳活動企劃書 (僅接受PDF檔)
                                    </label>
                                    <span id="proposal_file_name_display" style="font-size: 14px; color: #1554b9; font-weight: 500;"></span>
                                </div>

                                <div class="form-group" style="margin-top: 10px;">
                                    <label for="organization_name">單位名稱 / 主辦社團 <span style="color:red">*</span></label>
                                    <input type="text" id="organization_name" name="organization_name" class="" placeholder="請輸入主辦單位名稱" value="<?php echo htmlspecialchars((string)($reservationRow['organization_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="activity_name">活動名稱 <span style="color:red">*</span></label>
                                    <input type="text" id="activity_name" name="activity_name" class="form-control" placeholder="請輸入活動名稱" value="<?php echo htmlspecialchars((string)($reservationRow['activity_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                                </div>

                                <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 15px;">
                                    <div class="form-group" style="flex: 1; min-width: 150px;">
                                        <label for="participant_count">活動對象人數 (100人以上的活動只能選擇30天後的日期) <span style="color:red">*</span></label>
                                        <select id="participant_count" name="participant_count" class="" required style="padding: 8px;">
                                            <option value="" <?php echo (($reservationRow['participant_count'] ?? '') === '') ? 'selected' : ''; ?>>請選擇</option>
                                            <option value="50人以下" <?php echo (($reservationRow['participant_count'] ?? '') === '50人以下') ? 'selected' : ''; ?>>50人以下</option>
                                            <option value="50~100人" <?php echo (($reservationRow['participant_count'] ?? '') === '50~100人') ? 'selected' : ''; ?>>50~100人</option>
                                            <option value="100~200人" <?php echo (($reservationRow['participant_count'] ?? '') === '100~200人') ? 'selected' : ''; ?>>100~200人</option>
                                            <option value="200人以上" <?php echo (($reservationRow['participant_count'] ?? '') === '200人以上') ? 'selected' : ''; ?>>200人以上</option>
                                        </select>
                                    </div>
                                    <div class="form-group" style="flex: 1; min-width: 150px;">
                                        <label for="staff_count">工作人員人數 <span style="color:red">*</span></label>
                                        <input type="number" id="staff_count" name="staff_count" class="form-control" placeholder="請輸入人數" min="1" required value="<?php echo htmlspecialchars((string)($reservationRow['staff_count'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                </div>
                             
                                <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 15px;">
                                    <div class="form-group" style="flex: 1; min-width: 150px; margin-bottom: 0;">
                                        <label for="coordinator_department">系級<span style="color:red">*</span></label>
                                        <input type="text" id="coordinator_department" name="coordinator_department" class="form-control" placeholder="請輸入系級" value="<?php echo htmlspecialchars((string)($reservationRow['coordinator_department'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                    <div class="form-group" style="flex: 1; min-width: 150px; margin-bottom: 0;">
                                        <label for="coordinator_phone">聯絡電話<span style="color:red">*</span></label>
                                        <input type="text" id="coordinator_phone" name="coordinator_phone" class="form-control" placeholder="請輸入聯絡電話" value="<?php echo htmlspecialchars((string)($reservationRow['coordinator_phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="coordinator_other_contact">其他聯絡方式</label>
                                    <input type="text" id="coordinator_other_contact" name="coordinator_other_contact" class="form-control" placeholder="請輸入其他聯絡方式（如 Email）" value="<?php echo htmlspecialchars((string)($reservationRow['coordinator_other_contact'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                </div>

                                <div class="form-group" style="margin-top: 10px;">
                                    <label>活動特殊性質（可複選）- 勾選則下一頁將出現表單</label>
                                    <div style="display: flex; gap: 20px; flex-wrap: wrap; margin-top: 8px;">
                                        <label style="display: flex; align-items: center; gap: 8px; margin: 0; font-weight: normal; cursor: pointer; white-space: nowrap;">
                                            <input type="checkbox" name="has_alcohol" value="1" <?php echo (($reservationRow['has_alcohol'] ?? '') === '1') ? 'checked' : ''; ?>>
                                            <span>有酒精</span>
                                        </label>
                                        <label style="display: flex; align-items: center; gap: 8px; margin: 0; font-weight: normal; cursor: pointer; white-space: nowrap;">
                                            <input type="checkbox" name="has_fire" value="1" <?php echo (($reservationRow['has_fire'] ?? '') === '1') ? 'checked' : ''; ?>>
                                            <span>有明火</span>
                                        </label>
                                        <label style="display: flex; align-items: center; gap: 8px; margin: 0; font-weight: normal; cursor: pointer; white-space: nowrap;">
                                            <input type="checkbox" name="has_sales" value="1" <?php echo (($reservationRow['has_sales'] ?? '') === '1') ? 'checked' : ''; ?>>
                                            <span>需擺攤販售</span>
                                        </label>
                                    </div>
                                </div>

                                <div class="form-group" style="margin-top: 20px; border-top: 1px solid #ccc; padding-top: 15px;">
                                    <label>活動開始時間 <span style="color:red">*</span></label>
                                    <div style="display: flex; gap: 10px; margin-bottom: 15px; align-items: center;">
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
                                        <input type="date" id="borrow_start_date" name="borrow_start_date" class="form-control" value="<?php echo htmlspecialchars($bsDate, ENT_QUOTES, 'UTF-8'); ?>" required>
                                        <select name="borrow_start_time_h" class="form-control" required style="padding: 8px; width: 80px;">
                                            <option value="">選擇</option>
                                            <?php for($h=7; $h<=22; $h++) { 
                                                $selected = ($bsH !== '' && (int)$bsH === $h) ? 'selected' : '';
                                            ?>
                                                <option value="<?php echo $h; ?>" <?php echo $selected; ?>><?php echo $h; ?></option>
                                            <?php } ?>
                                        </select>
                                        <span>時</span>
                                        
                                        <select name="borrow_start_time_m" class="form-control" required style="padding: 8px; width: 80px;">
                                            <option value="">選擇</option>
                                            <option value="00" <?php echo ($bsM !== '' && (int)$bsM === 0) ? 'selected' : ''; ?>>00</option>
                                            <option value="10" <?php echo ($bsM !== '' && (int)$bsM === 10) ? 'selected' : ''; ?>>10</option>
                                            <option value="20" <?php echo ($bsM !== '' && (int)$bsM === 20) ? 'selected' : ''; ?>>20</option>
                                            <option value="30" <?php echo ($bsM !== '' && (int)$bsM === 30) ? 'selected' : ''; ?>>30</option>
                                            <option value="40" <?php echo ($bsM !== '' && (int)$bsM === 40) ? 'selected' : ''; ?>>40</option>
                                            <option value="50" <?php echo ($bsM !== '' && (int)$bsM === 50) ? 'selected' : ''; ?>>50</option>
                                        </select>
                                        <span>分</span>
                                    </div>
                                    
                                    <label>活動結束時間 <span style="color:red">*</span></label>
                                    <div style="display: flex; gap: 10px; align-items: center;">
                                        <input type="date" id="borrow_end_date" name="borrow_end_date" class="form-control" value="<?php echo htmlspecialchars($beDate, ENT_QUOTES, 'UTF-8'); ?>" required>
                                        <select name="borrow_end_time_h" class="form-control" required style="padding: 8px; width: 80px;">
                                            <option value="">選擇</option>
                                            <?php for($h=7; $h<=22; $h++) { 
                                                $selected = ($beH !== '' && (int)$beH === $h) ? 'selected' : '';
                                            ?>
                                                <option value="<?php echo $h; ?>" <?php echo $selected; ?>><?php echo $h; ?></option>
                                            <?php } ?>
                                        </select>
                                        <span>時</span>
                                        
                                        <select name="borrow_end_time_m" class="form-control" required style="padding: 8px; width: 80px;">
                                            <option value="">選擇</option>
                                            <option value="00" <?php echo ($beM !== '' && (int)$beM === 0) ? 'selected' : ''; ?>>00</option>
                                            <option value="10" <?php echo ($beM !== '' && (int)$beM === 10) ? 'selected' : ''; ?>>10</option>
                                            <option value="20" <?php echo ($beM !== '' && (int)$beM === 20) ? 'selected' : ''; ?>>20</option>
                                            <option value="30" <?php echo ($beM !== '' && (int)$beM === 30) ? 'selected' : ''; ?>>30</option>
                                            <option value="40" <?php echo ($beM !== '' && (int)$beM === 40) ? 'selected' : ''; ?>>40</option>
                                            <option value="50" <?php echo ($beM !== '' && (int)$beM === 50) ? 'selected' : ''; ?>>50</option>
                                        </select>
                                        <span>分</span>
                                    </div>

                                    <div class="step-actions">
                                        <button type="button" class="btn btn-primary btn-next" onclick="goToStep(2)">下一步 ➔ 場地需求</button>
                                    </div>
                                    
                                    <div class="draft-action-row">
                                        <button type="button" class="draft-btn save-btn saveDraftBtn">暫存申請</button>
                                        <button type="button" class="draft-btn draft-box-btn openDraftBoxBtn">草稿箱</button>
                                    </div>
                                    <div id="submitDebugMsg" class="draft-message"></div>
                                </div>

                                <div class="step-content" id="step-content-2">
                                <h3 class="step-title" style="margin-bottom: 10px;">第二步：場地需求</h3>

                                <div class="form-group" style="margin-top: 20px;">
                                    <label>車輛進出 <span style="color:red">*</span></label>
                                    <div style="margin-top: 8px; display: flex; align-items: center; gap: 20px;">
                                        <label style="display: flex; align-items: center; gap: 5px; font-weight: normal; cursor: pointer; margin: 0;">
                                            <input type="radio" name="vehicle_entry" value="no" id="vehicleNo" style="margin: 0;" <?php echo (($reservationRow['vehicle_entry'] ?? '') === 'no' || empty($reservationRow['vehicle_entry'] ?? '')) ? 'checked' : ''; ?>> 否
                                        </label>
                                        <label style="display: flex; align-items: center; gap: 5px; font-weight: normal; cursor: pointer; margin: 0;">
                                            <input type="radio" name="vehicle_entry" value="yes" id="vehicleYes" style="margin: 0;" <?php echo (($reservationRow['vehicle_entry'] ?? '') === 'yes') ? 'checked' : ''; ?>> 是
                                        </label>
                                    </div>
                                </div>

                                <div class="form-group" style="margin-top: 20px;">
                                    <label>插立旗幟(選擇"是"將填寫旗幟插立表單) <span style="color:red">*</span></label>
                                    <div style="margin-top: 8px; display: flex; align-items: center; gap: 20px;">
                                        <label style="display: flex; align-items: center; gap: 5px; font-weight: normal; cursor: pointer; margin: 0;">
                                            <input type="radio" name="setup_flags" value="no" id="flagOptionNo" style="margin: 0;" <?php echo (($reservationRow['setup_flags'] ?? '') === 'no' || empty($reservationRow['setup_flags'] ?? '')) ? 'checked' : ''; ?> onchange="toggleFlagDetails()"> 否
                                        </label>
                                        <label style="display: flex; align-items: center; gap: 5px; font-weight: normal; cursor: pointer; margin: 0;">
                                            <input type="radio" name="setup_flags" value="yes" id="flagOptionYes" style="margin: 0;" <?php echo (($reservationRow['setup_flags'] ?? '') === 'yes') ? 'checked' : ''; ?> onchange="toggleFlagDetails()"> 是
                                        </label>
                                    </div>
                                </div>

                                <div id="flagDetailsSection" style="display:none; margin-top:20px; background:#fff; border:1px solid #cbd5e1; border-radius:8px;">
                                    <div style="font-weight: bold; font-size: 16px; padding: 15px 20px; border-bottom: 1px solid #e2e8f0; color: #1e293b;">
                                        旗幟插立申請表
                                    </div>

                                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; align-items:start; margin-bottom:12px; padding: 20px 20px 0 20px;">
                                        <div>
                                            <label>申請單位 <span style="color:red">*</span></label>
                                            <input type="text" id="flag_organization_name" name="flag_organization_name" class="form-control" value="<?php echo htmlspecialchars($reservationRow['flag_organization_name'] ?? ($reservationRow['organization_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                        </div>
                                        <div>
                                            <label>活動名稱 <span style="color:red">*</span></label>
                                            <input type="text" id="flag_activity_name" name="flag_activity_name" class="form-control" value="<?php echo htmlspecialchars($reservationRow['flag_activity_name'] ?? ($reservationRow['activity_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                        </div>
                                        <div>
                                            <label>負責人 <span style="color:red">*</span></label>
                                            <input type="text" id="flag_responsible_person" name="flag_responsible_person" class="form-control" value="<?php echo htmlspecialchars($reservationRow['flag_responsible_person'] ?? ($reservationRow['activity_coordinator'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                        </div>
                                        <div>
                                            <label>連絡電話 <span style="color:red">*</span></label>
                                            <input type="text" id="flag_contact_phone" name="flag_contact_phone" class="form-control" value="<?php echo htmlspecialchars($reservationRow['flag_contact_phone'] ?? ($reservationRow['coordinator_phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                        </div>
                                    </div>

                                    <div style="display:flex; gap:15px; align-items:center; margin-bottom:15px; padding: 0 20px;">
                                        <div>
                                            <label>使用日期 <span style="color:red">*</span></label>
                                            <div style="display:flex; gap:8px; align-items:center;">
                                                <input type="date" id="flag_use_start" name="flag_use_start" class="form-control" readonly style="background:#fff;" value="<?php echo htmlspecialchars($reservationRow['borrow_start_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                                <span>至</span>
                                                <input type="date" id="flag_use_end" name="flag_use_end" class="form-control" readonly style="background:#fff;" value="<?php echo htmlspecialchars($reservationRow['borrow_end_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                            </div>
                                            <div style="font-size:12px;color:#64748b;margin-top:6px;">說明：使用日期已自動帶入活動起訖時間，無法修改。</div>
                                        </div>
                                    </div>

                                    <div style="display:flex; gap:15px; align-items:center; margin-bottom:15px; padding: 0 20px 20px 20px;">
                                        <div style="flex:1;">
                                            <label>宣傳旗幟 <span style="color:red">*</span></label>
                                            <div style="display:flex; align-items:center; gap:8px;">
                                                <span>共</span>
                                                <input type="number" name="flag_count" id="flag_count" class="form-control" min="1" max="20" step="1" style="width:100px;height:38px;" placeholder="最多20" value="<?php echo htmlspecialchars((string)($reservationRow['flag_count'] ?? '1'), ENT_QUOTES, 'UTF-8'); ?>">
                                                <span>支</span>
                                            </div>
                                        </div>
                                        <div style="flex:1;">
                                            <label>懸掛位置</label>
                                            <div style="padding:8px 10px; background:#fff; border:1px solid #e2e8f0; border-radius:4px;">中央走道</div>
                                            <input type="hidden" id="flag_location" name="flag_location" value="中央走道">
                                        </div>
                                    </div>

                                    <label style="display: flex; align-items: flex-start; gap: 8px; margin: 0; font-weight: normal; cursor: pointer; background: #eff6ff; padding: 15px 20px; border-top: 1px solid #cbd5e1; border-radius: 0 0 8px 8px;">
                                        <input type="checkbox" name="flag_agreement" id="flag_agreement" value="1" <?php echo (isset($reservationRow['flag_agreement']) && $reservationRow['flag_agreement'] == '1') ? 'checked' : ''; ?> style="margin-top: 2px;" required>
                                        <span style="color: #1e3a8a; line-height: 1.5; font-size: 14px;">本人為旗幟插立總負責人，已詳細閱讀並遵守以下各項注意事項，為維護校園安全與景觀，願無條件承擔所插旗幟所致之一切賠償責任，特此聲明。 <span style="color:red">*</span></span>
                                    </label>
                                </div>

                                <!-- 酒精與明火表單和驗證 JS（來自 borrow.php） -->
                                <div id="alcoholDetailsSection" style="display:none; margin-top:20px; background:#fff; border:1px solid #cbd5e1; border-radius:8px;">
                                    <div style="font-weight: bold; font-size: 16px; padding: 15px 20px; border-bottom: 1px solid #e2e8f0; color: #1e293b;">
                                        輔仁大學學生自治組織暨社團辦理提供酒精飲品活動須知
                                    </div>
                                    <div style="padding: 20px;">
                                        <p style="color: #1e293b; font-size: 15px; margin-bottom: 15px; line-height: 1.6; font-weight: bold;">
                                            關於本校學生社團活動具酒精飲品活動，為避免參與人員酒後行為脫序、危及自身或他人安全，或造成飲用人健康上之負擔，請確認以下事項皆已納入活動規劃，並遵守相關規範︰
                                        </p>
                                        
                                        <div style="display: flex; flex-direction: column; gap: 15px; margin-bottom: 25px;">
                                            <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer; margin-left: -20px; font-weight: bold; color: #3b82f6;">
                                                <input type="checkbox" id="alcohol_agree_all" onchange="toggleAllAlcoholAgreements(this)" style="margin-top: 5px; width: 18px; height: 18px; flex-shrink: 0;">
                                                <span style="font-size: 15px; line-height: 1.6;">同意全部</span>
                                            </label>
                                            <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
                                                <input type="checkbox" name="alcohol_agree_1" value="yes" style="margin-top: 5px; width: 18px; height: 18px; flex-shrink: 0;">
                                                <span style="font-size: 15px; line-height: 1.6;">辦理活動供應酒精性飲品者，需於活動申請時，於企劃書中敘明酒精飲品種類、準備數量、活動形式，連同活動申請表及本須知於活動前一個月送至課外活動指導組。</span>
                                            </label>
                                            <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
                                                <input type="checkbox" name="alcohol_agree_2" value="yes" style="margin-top: 5px; width: 18px; height: 18px; flex-shrink: 0;">
                                                <span style="font-size: 15px; line-height: 1.6;">為避免同學酒後行為脫序、危及自身或他人安全，或造成飲用人健康上之負擔，請於企劃書敘明失序行為因應措施。</span>
                                            </label>
                                            <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
                                                <input type="checkbox" name="alcohol_agree_3" value="yes" style="margin-top: 5px; width: 18px; height: 18px; flex-shrink: 0;">
                                                <span style="font-size: 15px; line-height: 1.6;">於活動期間主辦單位務必於活動現場明顯處所加註「未滿十八歲請勿購買/領取酒精性飲品」及「飲酒過量有害身體健康」與「禁止酒駕」之警語，提醒活動參與者避免飲酒過量。</span>
                                            </label>
                                            <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
                                                <input type="checkbox" name="alcohol_agree_4" value="yes" style="margin-top: 5px; width: 18px; height: 18px; flex-shrink: 0;">
                                                <span style="font-size: 15px; line-height: 1.6;">依「兒童及少年福利與權益保障法」規定，販賣、交付或供應酒或檳榔予兒童及少年者，處新臺幣一萬元以上十萬元以下罰鍰。主辦單位應要求活動中發送或販賣酒精飲料之人員核對領取/購買人身分證明文件，並禁止對未滿十八歲之人發送或販賣。</span>
                                            </label>
                                            <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
                                                <input type="checkbox" name="alcohol_agree_5" value="yes" style="margin-top: 5px; width: 18px; height: 18px; flex-shrink: 0;">
                                                <span style="font-size: 15px; line-height: 1.6;">主辦單位應提供《辦理提供酒精飲品活動理性飲酒同意書》 供有飲酒意願之參加人員簽署，並提醒參加人員有關警語所示事項(包含未滿十八歲請勿飲酒，於活動中飲用酒精飲料者不得駕駛汽車、機車、腳踏車等)。於活動結束翌日(遇例假日順延)將該同意書送至課外活動指導組備查</span>
                                            </label>
                                        </div>

                                        <div style="display: flex; flex-wrap: wrap; gap: 15px; margin-bottom: 20px; align-items: center; background: #f8fafc; padding: 20px; border-radius: 6px; border: 1px solid #e2e8f0;">
                                            <div style="display: flex; align-items: center; gap: 8px;">
                                                <label for="alcohol_coordinator" style="margin: 0; font-size: 15px; font-weight: bold; white-space: nowrap;">活動負責人</label>
                                                <input type="text" id="alcohol_coordinator" name="alcohol_coordinator" placeholder="姓名" value="<?php echo htmlspecialchars($reservationRow['alcohol_coordinator'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="width: 150px; padding: 8px; border: 1px solid #cbd5e1; border-radius: 4px;">
                                            </div>
                                            <div style="display: flex; align-items: center; gap: 8px;">
                                                <label for="alcohol_president" style="margin: 0; font-size: 15px; font-weight: bold; white-space: nowrap;">社長</label>
                                                <input type="text" id="alcohol_president" name="alcohol_president" placeholder="姓名" value="<?php echo htmlspecialchars($reservationRow['alcohol_president'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="width: 150px; padding: 8px; border: 1px solid #cbd5e1; border-radius: 4px;">
                                            </div>
                                            <span style="font-size: 15px; color: #1e293b; font-weight: bold;">已知悉以上事項，願負一切責任。</span>
                                        </div>
                                        
                                        <p style="color: #000; font-size: 15px; font-weight: bold; margin-bottom: 0; text-align: center;">
                                            活動時所有接觸酒精飲品與會者請親自簽署《酒精飲品活動理性飲酒同意書》，請於活動結束翌日(遇例假日順延)送課指組備查。
                                        </p>
                                    </div>
                                </div>

                                <div id="fireDetailsSection" style="display:none; margin-top:20px; background:#fff; border:1px solid #cbd5e1; border-radius:8px;">
                                    <div style="font-weight: bold; font-size: 16px; padding: 15px 20px; border-bottom: 1px solid #e2e8f0; color: #1e293b;">
                                        輔仁大學學生活動上火確認表(火舞)
                                    </div>
                                    <div style="padding: 20px;">
                                        <div class="form-group" style="margin-bottom: 15px;">
                                            <label for="fire_activity_name">活動名稱 <span style="color:red">*</span></label>
                                            <input type="text" id="fire_activity_name" name="fire_activity_name" class="form-control" placeholder="請輸入活動名稱" value="<?php echo htmlspecialchars($reservationRow['fire_activity_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                        </div>

                                        <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 15px;">
                                            <div class="form-group" style="flex: 1; min-width: 150px;">
                                                <label for="fire_date">日期 (限30天後) <span style="color:red">*</span></label>
                                                <input type="date" id="fire_date" name="fire_date" class="form-control" min="<?php echo date('Y-m-d', strtotime('+30 days')); ?>" value="<?php echo htmlspecialchars($reservationRow['fire_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                            </div>
                                            <div class="form-group" style="flex: 1; min-width: 150px;">
                                                <label for="fire_location">地點 <span style="color:red">*</span></label>
                                                <input type="text" id="fire_location" name="fire_location" class="form-control" placeholder="請輸入地點" value="<?php echo htmlspecialchars($reservationRow['fire_location'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                            </div>
                                        </div>

                                        <div class="form-group" style="margin-bottom: 15px;">
                                            <label>時間 <span style="color:red">*</span></label>
                                            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                                <?php
                                                $curFsh = $reservationRow['fire_start_time_h'] ?? '';
                                                $curFsm = $reservationRow['fire_start_time_m'] ?? '';
                                                ?>
                                                <select name="fire_start_time_h" class="form-control" style="padding:8px; width:80px;">
                                                    <option value="">選擇</option>
                                                    <?php for($h=7;$h<=22;$h++){ $sel = ($curFsh !== '' && (int)$curFsh === $h) ? 'selected' : ''; ?>
                                                        <option value="<?php echo $h;?>" <?php echo $sel; ?>><?php echo $h;?></option>
                                                    <?php } ?>
                                                </select>
                                                <span>時</span>
                                                <select name="fire_start_time_m" class="form-control" style="padding:8px; width:80px;">
                                                    <option value="">選擇</option>
                                                    <option value="00">00</option>
                                                    <option value="10">10</option>
                                                    <option value="20">20</option>
                                                    <option value="30">30</option>
                                                    <option value="40">40</option>
                                                    <option value="50">50</option>
                                                </select>
                                            </div>
                                        </div>
                                        
                                        <div style="display: flex; flex-direction: column; gap: 15px; margin-bottom: 25px;">
                                            <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer; margin-left: -20px; font-weight: bold; color: #3b82f6;">
                                                <input type="checkbox" id="fire_ack" name="fire_ack" value="1" style="margin-top: 5px; width: 18px; height: 18px; flex-shrink: 0;">
                                                <span style="font-size: 15px; line-height: 1.6;">已閱讀並同意明火安全規範</span>
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <script>
                                function toggleAllAlcoholAgreements(source) {
                                    const checkboxes = document.querySelectorAll('input[name^="alcohol_agree_"]');
                                    checkboxes.forEach(function(cb) {
                                        if(cb !== source) {
                                            cb.checked = source.checked;
                                        }
                                    });
                                }

                                function isAlcoholEnabled() {
                                    const checkbox = document.querySelector('input[name="has_alcohol"]');
                                    return checkbox ? checkbox.checked : false;
                                }

                                function toggleAlcoholDetails() {
                                    const alcSection = document.getElementById('alcoholDetailsSection');
                                    if (!alcSection) return;
                                    const show = isAlcoholEnabled();
                                    alcSection.style.display = show ? 'block' : 'none';
                                    alcSection.querySelectorAll('input').forEach(function(el) {
                                        if (show) {
                                            el.removeAttribute('disabled');
                                        } else {
                                            el.setAttribute('disabled', 'disabled');
                                        }
                                    });
                                }

                                function validateAlcoholForm() {
                                    if (!isAlcoholEnabled()) return true;
                                    const checkboxes = document.querySelectorAll('#alcoholDetailsSection input[type="checkbox"]');
                                    let allChecked = true;
                                    checkboxes.forEach(function(cb) { if (!cb.checked) allChecked = false; });
                                    const coordinator = document.getElementById('alcohol_coordinator').value.trim();
                                    const president = document.getElementById('alcohol_president').value.trim();
                                    if (!allChecked) { alert('請先勾選並確認遵守「酒精飲品活動須知」的所有規範事項。'); return false; }
                                    if (!coordinator) { alert('請填寫「酒精飲品活動須知」的活動負責人。'); return false; }
                                    if (!president) { alert('請填寫「酒精飲品活動須知」的社長。'); return false; }
                                    return true;
                                }

                                function isFireEnabled() { const checkbox = document.querySelector('input[name="has_fire"]'); return checkbox ? checkbox.checked : false; }

                                function toggleFireDetails() {
                                    const fireSection = document.getElementById('fireDetailsSection');
                                    if (!fireSection) return;
                                    const show = isFireEnabled();
                                    fireSection.style.display = show ? 'block' : 'none';
                                    fireSection.querySelectorAll('input').forEach(function(el) {
                                        if (show) { el.removeAttribute('disabled'); } else { el.setAttribute('disabled', 'disabled'); }
                                    });
                                }

                                function validateFireForm() { if (!isFireEnabled()) return true; return true; }

                                function isFlagEnabled() { const checkedFlag = document.querySelector('input[name="setup_flags"]:checked'); return checkedFlag && checkedFlag.value === 'yes'; }

                                function addWorkDays(startDate, days) {
                                    const date = new Date(startDate.getFullYear(), startDate.getMonth(), startDate.getDate());
                                    let count = 0;
                                    while (count < days) {
                                        date.setDate(date.getDate() + 1);
                                        const weekDay = date.getDay();
                                        if (weekDay !== 0 && weekDay !== 6) { count++; }
                                    }
                                    return date;
                                }

                                function formatDate(date) { const y = date.getFullYear(); const m = String(date.getMonth() + 1).padStart(2, '0'); const d = String(date.getDate()).padStart(2, '0'); return `${y}-${m}-${d}`; }

                                function getMinFlagDate() { return formatDate(addWorkDays(new Date(), 7)); }

                                function toggleFlagDetails() {
                                    const detailsSection = document.getElementById('flagDetailsSection'); if (!detailsSection) return;
                                    const show = isFlagEnabled(); detailsSection.style.display = show ? 'block' : 'none';
                                    detailsSection.querySelectorAll('input, select, textarea').forEach(function (el) { if (show) { el.removeAttribute('disabled'); } else { el.setAttribute('disabled', 'disabled'); } });
                                    if (show) { syncFlagForm(); validateStartDate(); }
                                }

                                function is30DaysRequired() {
                                    const participantCount = document.getElementById('participant_count')?.value;
                                    const hasAlcohol = document.querySelector('input[name="has_alcohol"]')?.checked;
                                    const hasFire = document.querySelector('input[name="has_fire"]')?.checked;
                                    const hasSales = document.querySelector('input[name="has_sales"]')?.checked;
                                    return (participantCount === '100~200人' || participantCount === '200人以上') || hasAlcohol || hasFire || hasSales;
                                }

                                function validateStartDate() {
                                    const startDateInput = document.getElementById('borrow_start_date'); if (!startDateInput || !startDateInput.value) return;
                                    const selectedDate = new Date(startDateInput.value); selectedDate.setHours(0,0,0,0);
                                    const req30 = is30DaysRequired(); const reqFlag = isFlagEnabled(); let errorMsg = '';
                                    if (req30) {
                                        const min30Date = new Date(); min30Date.setDate(min30Date.getDate() + 30); min30Date.setHours(0,0,0,0);
                                        if (selectedDate < min30Date) { errorMsg = '注意：由於您的活動包含特殊性質（酒精、明火、攤販或超過100人），必須在 30 天之前申請！\n系統已清空不合規的日期，請重新選擇至少為 ' + formatDate(min30Date) + ' 的日期。'; }
                                    }
                                    if (!errorMsg && reqFlag) {
                                        const minFlagDateStr = getMinFlagDate(); const minFlagDate = new Date(minFlagDateStr); minFlagDate.setHours(0,0,0,0);
                                        if (selectedDate < minFlagDate) { errorMsg = '注意：插立旗幟的使用日期只能選擇 7 個工作天之後的日期（至少為 ' + minFlagDateStr + '）！\n系統已為您清空不合規的活動開始日期，請重新選擇。'; }
                                    }
                                    if (errorMsg) { alert(errorMsg); startDateInput.value = ''; const sEl = document.getElementById('flag_start_date'); const eEl = document.getElementById('flag_end_date'); if (sEl) sEl.value = ''; if (eEl) eEl.value = ''; }
                                }

                                function syncFlagForm() {
                                    if (!isFlagEnabled()) return; const flagCount = document.getElementById('flag_count'); if (flagCount && flagCount.value !== '' && Number(flagCount.value) > 20) { flagCount.value = 20; }
                                    const bs = document.getElementById('borrow_start_date'); const be = document.getElementById('borrow_end_date'); const fus = document.getElementById('flag_use_start'); const fue = document.getElementById('flag_use_end');
                                    const mapping = [ ['organization_name', 'flag_organization_name'], ['activity_name', 'flag_activity_name'], ['activity_coordinator', 'flag_responsible_person'], ['coordinator_phone', 'flag_contact_phone'] ];
                                    mapping.forEach(function(pair){ const s = document.getElementById(pair[0]); const d = document.getElementById(pair[1]); if (s && d && (d.value === '' || d.value === null)) { d.value = s.value || ''; } });
                                    if (fus && fue && bs && be) {
                                        fus.value = bs.value || ''; fue.value = be.value || '';
                                        try { const min = getMinFlagDate(); fus.setAttribute('min', min); fue.setAttribute('min', min); } catch (e) {}
                                        if (bs.value) {
                                            const minDate = new Date(getMinFlagDate()); const startDate = new Date(bs.value);
                                            if (startDate < minDate) { alert('插立旗幟使用日期必須為 7 個工作天之後，請將活動開始日期調整至 ' + getMinFlagDate() + '（或更晚）。'); bs.focus(); }
                                        }
                                    }
                                }

                                document.addEventListener('DOMContentLoaded', function () {
                                    const flagRadios = document.querySelectorAll('input[name="setup_flags"]');
                                    const flagCount = document.getElementById('flag_count');

                                    flagRadios.forEach(function (radio) {
                                        radio.addEventListener('change', function () { toggleFlagDetails(); syncFlagForm(); });
                                    });

                                    ['borrow_start_date', 'borrow_end_date', 'organization_name', 'activity_name', 'coordinator_phone', 'activity_coordinator', 'participant_count'].forEach(function (id) {
                                        const el = document.getElementById(id); if (el) {
                                            if (id === 'borrow_start_date' || id === 'participant_count') { el.addEventListener('change', function() { validateStartDate(); syncFlagForm(); }); }
                                            else { el.addEventListener('change', syncFlagForm); }
                                            el.addEventListener('input', syncFlagForm);
                                        }
                                    });

                                    ['has_alcohol', 'has_fire', 'has_sales'].forEach(function(name) {
                                        const el = document.querySelector('input[name="' + name + '"]');
                                        if (el) { el.addEventListener('change', function() { if (name === 'has_alcohol' && typeof toggleAlcoholDetails === 'function') { toggleAlcoholDetails(); } if (name === 'has_fire' && typeof toggleFireDetails === 'function') { toggleFireDetails(); } }); }
                                    });

                                    if (typeof toggleAlcoholDetails === 'function') { toggleAlcoholDetails(); }
                                    if (typeof toggleFireDetails === 'function') { toggleFireDetails(); }

                                    (function(){ const pairs = [ ['organization_name', 'flag_organization_name'], ['activity_name', 'flag_activity_name'], ['activity_coordinator', 'flag_responsible_person'], ['coordinator_phone', 'flag_contact_phone'] ]; pairs.forEach(function(pair){ const src = document.getElementById(pair[0]); const dst = document.getElementById(pair[1]); if (!src || !dst) return; dst.value = src.value || dst.value || ''; src.addEventListener('input', function(){ dst.value = src.value; }); src.addEventListener('change', function(){ dst.value = src.value; }); }); })();

                                    if (flagCount) { flagCount.addEventListener('input', function () { if (this.value !== '' && Number(this.value) > 20) { this.value = 20; alert('宣傳旗幟最多只能選 20 支'); } if (this.value !== '' && Number(this.value) < 1) { this.value = 1; } }); }

                                    toggleFlagDetails(); syncFlagForm();

                                    const draftBtns = document.querySelectorAll('.openDraftBoxBtn'); draftBtns.forEach(function (btn) { btn.addEventListener('click', function () { window.location.href = 'drafts.php'; }); });
                                });
                                </script>

                                <div class="step-actions">
                                    <button type="button" class="btn btn-secondary" onclick="goToStep(1)"> ⬅ 回上一步</button>
                                    <button type="button" class="btn btn-primary btn-next" onclick="if(validateAlcoholForm() && validateFireForm()) { goToStep(3); }">下一步 ➔ 挑選器材與場地</button>
                                </div>

                                <div class="draft-action-row">
                                    <button type="button" class="draft-btn save-btn saveDraftBtn">暫存申請</button>
                                    <button type="button" class="draft-btn draft-box-btn openDraftBoxBtn">草稿箱</button>
                                </div>
                                <div id="submitDebugMsg" class="draft-message"></div>
                            </div>

                                <div class="step-content" id="step-content-3">
                                    <h3 class="step-title" style="margin-bottom: 10px;">第三步：器材與場地</h3>
                                    <p class="step-desc" style="color: #7f8c8d; margin-bottom: 20px;">請確認要移除或新增的場地與器材，最後送出修改。</p>

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

                                        function goToStep(stepNumber) {
                                            const steps = [1, 2, 3];
                                            steps.forEach(function(step) {
                                                const content = document.getElementById('step-content-' + step);
                                                const stepper = document.getElementById('stepper-' + step);
                                                if (content && stepper) {
                                                    content.classList.toggle('active', step === stepNumber);
                                                    stepper.classList.toggle('active', step === stepNumber);
                                                }
                                            });
                                            const currentStep = document.getElementById('current_step');
                                            if (currentStep) {
                                                currentStep.value = String(stepNumber);
                                            }
                                            const card = document.getElementById('mainBorrowLayout');
                                            if (card) {
                                                card.scrollIntoView({ behavior: 'smooth', block: 'start' });
                                            }
                                        }
                                    </script>

                                    <div class="step-actions">
                                        <button type="button" class="btn btn-secondary" onclick="goToStep(2)"> ⬅ 回上一步</button>
                                        <button type="submit" class="btn btn-primary btn-next">確認修改</button>
                                    </div>
                                </div>

                            </form>
                        </section>
                    </div>
                <?php } ?>
            </section>
        </main>
    </div>
</body>
</html>
