<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config/database.php';

function formatDatetimeLocalValue(?string $value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return '';
    }

    return date('Y-m-d\TH:i', $timestamp);
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?next=amend_application.php');
    exit;
}

$currentUserId = (string)$_SESSION['user_id'];
$displayName = (string)($_SESSION['full_name'] ?? $_SESSION['user_id']);
$reservationId = (int)($_GET['reservation_id'] ?? 0);

if ($reservationId <= 0) {
    header('Location: return_management.php');
    exit;
}

$dbError = '';
$link = getMysqliConnection($dbError);

$amendError = '';
$amendSuccess = '';
$revisionData = [];

if ($dbError === '') {
    // 查詢該預約是否屬於當前用戶且狀態為 need_revision
    $checkStmt = mysqli_prepare($link, '
         SELECT r.reservation_id, r.user_id, r.approval_status, r.revision_data_json, r.revision_deadline,
             r.organization_name, r.activity_name, r.participant_count, r.staff_count, r.club_president,
             r.activity_coordinator, r.coordinator_department, r.coordinator_phone, r.coordinator_other_contact,
             r.vehicle_entry, r.setup_flags, r.flag_details,
             r.has_alcohol, r.has_fire, r.has_sales, r.purpose, r.phone, r.borrow_start_at, r.borrow_end_at, r.space_id
        FROM reservations r
        WHERE r.reservation_id = ? AND r.user_id = ? LIMIT 1
    ');
    if ($checkStmt) {
        mysqli_stmt_bind_param($checkStmt, 'is', $reservationId, $currentUserId);
        mysqli_stmt_execute($checkStmt);
        $checkResult = mysqli_stmt_get_result($checkStmt);
        $reservationRow = $checkResult ? mysqli_fetch_assoc($checkResult) : null;
        mysqli_stmt_close($checkStmt);
        
        // 查詢該預約的設備項目
        $equipmentItems = [];
        $equipStmt = mysqli_prepare($link, '
            SELECT eri.equipment_id, e.equipment_code, ec.equipment_name, eri.borrow_quantity
            FROM equipment_reservation_items eri
            JOIN equipments e ON e.equipment_id = eri.equipment_id
            JOIN equipment_categories ec ON ec.equipment_code = e.equipment_code
            WHERE eri.reservation_id = ?
            ORDER BY ec.equipment_code ASC
        ');
        if ($equipStmt) {
            mysqli_stmt_bind_param($equipStmt, 'i', $reservationId);
            mysqli_stmt_execute($equipStmt);
            $equipResult = mysqli_stmt_get_result($equipStmt);
            while ($equipRow = $equipResult ? mysqli_fetch_assoc($equipResult) : null) {
                $equipmentItems[] = $equipRow;
            }
            mysqli_stmt_close($equipStmt);
        }
        
        // 查詢該預約的空間項目
        $spaceItems = [];
        $spaceStmt = mysqli_prepare($link, '
            SELECT sri.space_id, s.space_name, s.capacity
            FROM space_reservation_items sri
            JOIN spaces s ON s.space_id = sri.space_id
            WHERE sri.reservation_id = ?
            ORDER BY s.space_id ASC
        ');
        if ($spaceStmt) {
            mysqli_stmt_bind_param($spaceStmt, 'i', $reservationId);
            mysqli_stmt_execute($spaceStmt);
            $spaceResult = mysqli_stmt_get_result($spaceStmt);
            while ($spaceRow = $spaceResult ? mysqli_fetch_assoc($spaceResult) : null) {
                $spaceItems[] = $spaceRow;
            }
            mysqli_stmt_close($spaceStmt);
        }
        
        if (!$reservationRow) {
            $amendError = '找不到該申請或無權限修改。';
        } elseif ($reservationRow['approval_status'] !== 'need_revision') {
            $amendError = '該申請不在補件狀態，無法修改。';
        } else {
            // 解析原始申請數據（優先用 revision_data_json，如果為空則用當前欄位值）
            if (!empty($reservationRow['revision_data_json'])) {
                $revisionData = (array)json_decode($reservationRow['revision_data_json'], true) ?: [];
            }
            
            // 如果 revisionData 仍為空，直接從 reservationRow 取當前值作為備用
            if (empty($revisionData)) {
                $revisionData = [
                    'organization_name' => $reservationRow['organization_name'] ?? '',
                    'activity_name' => $reservationRow['activity_name'] ?? '',
                    'participant_count' => $reservationRow['participant_count'] ?? '',
                    'staff_count' => $reservationRow['staff_count'] ?? 0,
                    'club_president' => $reservationRow['club_president'] ?? '',
                    'activity_coordinator' => $reservationRow['activity_coordinator'] ?? '',
                    'coordinator_department' => $reservationRow['coordinator_department'] ?? '',
                    'coordinator_phone' => $reservationRow['coordinator_phone'] ?? '',
                    'coordinator_other_contact' => $reservationRow['coordinator_other_contact'] ?? '',
                    'vehicle_entry' => $reservationRow['vehicle_entry'] ?? 'no',
                    'setup_flags' => $reservationRow['setup_flags'] ?? 'no',
                    'flag_details' => $reservationRow['flag_details'] ?? '',
                    'has_alcohol' => $reservationRow['has_alcohol'] ?? '',
                    'has_fire' => $reservationRow['has_fire'] ?? '',
                    'has_sales' => $reservationRow['has_sales'] ?? '',
                    'purpose' => $reservationRow['purpose'] ?? '',
                    'phone' => $reservationRow['phone'] ?? '',
                    'borrow_start_at' => $reservationRow['borrow_start_at'] ?? '',
                    'borrow_end_at' => $reservationRow['borrow_end_at'] ?? '',
                    'space_id' => $reservationRow['space_id'] ?? '',
                ];
            }
            
            // 將設備和空間項目存入 revisionData
            $revisionData['equipment_items'] = $equipmentItems;
            $revisionData['space_items'] = $spaceItems;
            
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                // 收集修改後的表單數據
                $updatedFields = [
                    'organization_name' => trim((string)($_POST['organization_name'] ?? '')),
                    'activity_name' => trim((string)($_POST['activity_name'] ?? '')),
                    'participant_count' => trim((string)($_POST['participant_count'] ?? '')),
                    'staff_count' => (int)($_POST['staff_count'] ?? 0),
                    'club_president' => trim((string)($_POST['club_president'] ?? '')),
                    'activity_coordinator' => trim((string)($_POST['activity_coordinator'] ?? '')),
                    'coordinator_department' => trim((string)($_POST['coordinator_department'] ?? '')),
                    'coordinator_phone' => trim((string)($_POST['coordinator_phone'] ?? '')),
                    'coordinator_other_contact' => trim((string)($_POST['coordinator_other_contact'] ?? '')),
                    'vehicle_entry' => trim((string)($_POST['vehicle_entry'] ?? 'no')),
                    'setup_flags' => trim((string)($_POST['setup_flags'] ?? 'no')),
                    'flag_details' => trim((string)($_POST['flag_details'] ?? '')),
                    'has_alcohol' => isset($_POST['has_alcohol']) ? '1' : '',
                    'has_fire' => isset($_POST['has_fire']) ? '1' : '',
                    'has_sales' => isset($_POST['has_sales']) ? '1' : '',
                    'purpose' => trim((string)($_POST['purpose'] ?? '')),
                    'phone' => trim((string)($_POST['phone'] ?? '')),
                ];
                
                // 驗證必填欄位
                if ($updatedFields['organization_name'] === '') {
                    $amendError = '請填寫單位名稱。';
                } elseif ($updatedFields['activity_name'] === '') {
                    $amendError = '請填寫活動名稱。';
                } elseif ($updatedFields['purpose'] === '') {
                    $amendError = '請填寫用途說明。';
                } else {
                    // 更新預約
                    mysqli_begin_transaction($link);
                    try {
                        $updateFields = [];
                        $updateValues = [];
                        $updateTypes = '';
                        
                        foreach ($updatedFields as $key => $value) {
                            $updateFields[] = "{$key} = ?";
                            $updateValues[] = $value;
                            if (is_int($value)) {
                                $updateTypes .= 'i';
                            } else {
                                $updateTypes .= 's';
                            }
                        }
                        
                        $updateValues[] = $reservationId;
                        $updateTypes .= 'i';
                        
                        $updateSql = 'UPDATE reservations SET ' . implode(', ', $updateFields) . ', approval_status = "pending", updated_at = NOW() WHERE reservation_id = ?';
                        $updateStmt = mysqli_prepare($link, $updateSql);
                        
                        if (!$updateStmt) {
                            throw new RuntimeException('準備更新語句失敗：' . mysqli_error($link));
                        }
                        
                        mysqli_stmt_bind_param($updateStmt, $updateTypes, ...$updateValues);
                        mysqli_stmt_execute($updateStmt);
                        mysqli_stmt_close($updateStmt);

                        if (mysqli_errno($link) !== 0) {
                            throw new RuntimeException('更新失敗，請稍後再試。');
                        }
                        
                        mysqli_commit($link);
                        $amendSuccess = '補件已提交，已重新進入審核流程。';
                        // 更新顯示數據
                        $revisionData = $updatedFields;
                    } catch (Throwable $e) {
                        mysqli_rollback($link);
                        $amendError = $e->getMessage();
                    }
                }
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
    <title>補件修改｜校園資源租借系統</title>
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
    <style>
        .amend-header {
            background: #e3f2fd;
            border-left: 4px solid #1976d2;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        .amend-header h3 {
            margin: 0 0 5px 0;
            color: #1565c0;
        }
        .amend-header p {
            margin: 0;
            color: #555;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <nav class="navbar">
            <div class="navbar-brand">
                <h1>📚 校園資源租借系統</h1>
            </div>
            <div class="navbar-menu">
                <button class="nav-btn" onclick="location.href='index.php'">回首頁</button>
                <button class="nav-btn" onclick="location.href='return_management.php'">申請管理</button>
                <button class="nav-btn" type="button" disabled><?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></button>
                <button class="nav-btn" onclick="location.href='logout.php'">登出</button>
            </div>
        </nav>

        <main class="main-content">
            <section class="borrow-page">
                <h2>補件修改</h2>
                
                <?php if ($dbError !== '') { ?>
                    <div class="login-alert"><?php echo htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } ?>

                <?php if ($amendError !== '') { ?>
                    <div class="login-alert"><?php echo htmlspecialchars($amendError, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } ?>

                <?php if ($amendSuccess !== '') { ?>
                    <div class="borrow-success"><?php echo htmlspecialchars($amendSuccess, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } ?>

                <?php 
                    // 調試：顯示當前狀態
                    @error_log("DEBUG: reservationId=$reservationId, amendError=$amendError, revisionDataCount=" . count($revisionData) . ", jsonDebug=" . json_encode($revisionData));
                ?>

                <?php if ($dbError === '' && $amendSuccess === '' && empty($amendError)) { ?>
                    <div class="amend-header">
                        <h3>補件說明</h3>
                        <p>審核人員要求您修改此申請。請更新下方表單的相關資訊後重新提交，系統將進入重新審核流程。</p>
                    </div>

                    <section class="card borrow-form-card">
                        <form method="post" enctype="multipart/form-data" class="borrow-form" action="amend_application.php?reservation_id=<?php echo (int)$reservationId; ?>" novalidate>
                            <h3 class="step-title" style="margin-bottom: 10px;">修改申請內容</h3>
                            <p class="step-desc" style="color: #7f8c8d; margin-bottom: 20px;">請更新相關欄位後按送出。</p>

                            <div class="form-group">
                                <label for="organization_name">單位名稱 / 主辦社團 <span style="color:red">*</span></label>
                                <input type="text" id="organization_name" name="organization_name" class="form-control" placeholder="請輸入主辦單位名稱" value="<?php echo htmlspecialchars((string)($revisionData['organization_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="activity_name">活動名稱 <span style="color:red">*</span></label>
                                <input type="text" id="activity_name" name="activity_name" class="form-control" placeholder="請輸入活動名稱" value="<?php echo htmlspecialchars((string)($revisionData['activity_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>

                            <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 15px;">
                                <div class="form-group" style="flex: 1; min-width: 150px;">
                                    <label for="participant_count">活動對象人數 <span style="color:red">*</span></label>
                                    <select id="participant_count" name="participant_count" class="form-control" required style="padding: 8px;">
                                        <option value="" <?php echo (($revisionData['participant_count'] ?? '') === '') ? 'selected' : ''; ?>>請選擇</option>
                                        <option value="50人以下" <?php echo (($revisionData['participant_count'] ?? '') === '50人以下') ? 'selected' : ''; ?>>50人以下</option>
                                        <option value="50~100人" <?php echo (($revisionData['participant_count'] ?? '') === '50~100人') ? 'selected' : ''; ?>>50~100人</option>
                                        <option value="100~200人" <?php echo (($revisionData['participant_count'] ?? '') === '100~200人') ? 'selected' : ''; ?>>100~200人</option>
                                        <option value="200人以上" <?php echo (($revisionData['participant_count'] ?? '') === '200人以上') ? 'selected' : ''; ?>>200人以上</option>
                                    </select>
                                </div>
                                <div class="form-group" style="flex: 1; min-width: 150px;">
                                    <label for="staff_count">工作人員人數 <span style="color:red">*</span></label>
                                    <input type="number" id="staff_count" name="staff_count" class="form-control" placeholder="請輸入人數" min="1" value="<?php echo htmlspecialchars((string)($revisionData['staff_count'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                                </div>
                            </div>

                            <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 15px;">
                                <div class="form-group" style="flex: 1; min-width: 150px; margin-bottom: 0;">
                                    <label for="club_president">社 / 會長 <span style="color:red">*</span></label>
                                    <input type="text" id="club_president" name="club_president" class="form-control" placeholder="請輸入姓名" value="<?php echo htmlspecialchars((string)($revisionData['club_president'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                                </div>
                                <div class="form-group" style="flex: 1; min-width: 150px; margin-bottom: 0;">
                                    <label for="activity_coordinator">活動負責人<span style="color:red">*</span></label>
                                    <input type="text" id="activity_coordinator" name="activity_coordinator" class="form-control" placeholder="請輸入活動負責人姓名" value="<?php echo htmlspecialchars((string)($revisionData['activity_coordinator'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                            </div>

                            <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 15px;">
                                <div class="form-group" style="flex: 1; min-width: 150px; margin-bottom: 0;">
                                    <label for="coordinator_department">系級<span style="color:red">*</span></label>
                                    <input type="text" id="coordinator_department" name="coordinator_department" class="form-control" placeholder="請輸入系級" value="<?php echo htmlspecialchars((string)($revisionData['coordinator_department'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                                <div class="form-group" style="flex: 1; min-width: 150px; margin-bottom: 0;">
                                    <label for="coordinator_phone">聯絡電話<span style="color:red">*</span></label>
                                    <input type="text" id="coordinator_phone" name="coordinator_phone" class="form-control" placeholder="請輸入聯絡電話" value="<?php echo htmlspecialchars((string)($revisionData['coordinator_phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="coordinator_other_contact">其他聯絡方式</label>
                                <input type="text" id="coordinator_other_contact" name="coordinator_other_contact" class="form-control" placeholder="請輸入其他聯絡方式（如 Email）" value="<?php echo htmlspecialchars((string)($revisionData['coordinator_other_contact'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>

                            <div class="form-group" style="margin-top: 20px; border-top: 1px solid #ccc; padding-top: 15px;">
                                <label>特殊需求</label>
                                <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                                    <label style="display: flex; align-items: center; gap: 8px; margin: 0;">
                                        <input type="checkbox" name="vehicle_entry" value="yes" <?php echo (($revisionData['vehicle_entry'] ?? '') === 'yes') ? 'checked' : ''; ?>>
                                        <span>需要車輛進場</span>
                                    </label>
                                    <label style="display: flex; align-items: center; gap: 8px; margin: 0;">
                                        <input type="checkbox" name="has_alcohol" value="1" <?php echo (($revisionData['has_alcohol'] ?? '') === '1') ? 'checked' : ''; ?>>
                                        <span>有酒精</span>
                                    </label>
                                    <label style="display: flex; align-items: center; gap: 8px; margin: 0;">
                                        <input type="checkbox" name="has_fire" value="1" <?php echo (($revisionData['has_fire'] ?? '') === '1') ? 'checked' : ''; ?>>
                                        <span>有明火</span>
                                    </label>
                                    <label style="display: flex; align-items: center; gap: 8px; margin: 0;">
                                        <input type="checkbox" name="has_sales" value="1" <?php echo (($revisionData['has_sales'] ?? '') === '1') ? 'checked' : ''; ?>>
                                        <span>需擺攤販售</span>
                                    </label>
                                </div>
                            </div>

                            <div class="form-group" style="margin-top: 15px;">
                                <label for="purpose">用途說明 <span style="color:red">*</span></label>
                                <textarea id="purpose" name="purpose" class="form-control" placeholder="請說明活動用途" required><?php echo htmlspecialchars((string)($revisionData['purpose'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                            </div>

                            <!-- 借用時間 -->
                            <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-top: 20px;">
                                <div class="form-group" style="flex: 1; min-width: 150px; margin-bottom: 0;">
                                    <label for="borrow_start_at">借用開始時間</label>
                                    <input type="datetime-local" id="borrow_start_at" name="borrow_start_at" class="form-control" value="<?php echo htmlspecialchars(formatDatetimeLocalValue((string)($revisionData['borrow_start_at'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>" readonly style="background-color: #f5f5f5;">
                                </div>
                                <div class="form-group" style="flex: 1; min-width: 150px; margin-bottom: 0;">
                                    <label for="borrow_end_at">借用結束時間</label>
                                    <input type="datetime-local" id="borrow_end_at" name="borrow_end_at" class="form-control" value="<?php echo htmlspecialchars(formatDatetimeLocalValue((string)($revisionData['borrow_end_at'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>" readonly style="background-color: #f5f5f5;">
                                </div>
                            </div>

                            <!-- 預約空間 -->
                            <?php if (!empty($revisionData['space_items'])): ?>
                                <div class="form-group" style="margin-top: 15px;">
                                    <label>預約空間</label>
                                    <div style="background: #f9f9f9; padding: 10px; border-radius: 4px; border: 1px solid #e0e0e0;">
                                        <?php foreach ($revisionData['space_items'] as $space): ?>
                                            <div style="padding: 8px 0; border-bottom: 1px solid #e0e0e0;">
                                                <strong><?php echo htmlspecialchars($space['space_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong>
                                                (容納人數: <?php echo htmlspecialchars($space['capacity'] ?? '', ENT_QUOTES, 'UTF-8'); ?>)
                                                - 代號: <?php echo htmlspecialchars($space['space_id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- 預約設備 -->
                            <?php if (!empty($revisionData['equipment_items'])): ?>
                                <div class="form-group" style="margin-top: 15px;">
                                    <label>預約設備</label>
                                    <div style="background: #f9f9f9; padding: 10px; border-radius: 4px; border: 1px solid #e0e0e0;">
                                        <?php foreach ($revisionData['equipment_items'] as $equip): ?>
                                            <div style="padding: 8px 0; border-bottom: 1px solid #e0e0e0;">
                                                <strong><?php echo htmlspecialchars($equip['equipment_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong>
                                                - 代號: <?php echo htmlspecialchars($equip['equipment_code'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                                                | 數量: <?php echo htmlspecialchars($equip['borrow_quantity'] ?? '1', ENT_QUOTES, 'UTF-8'); ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="form-group" style="margin-top: 15px;">
                                <label for="phone">聯絡電話</label>
                                <input type="text" id="phone" name="phone" class="form-control" placeholder="請輸入聯絡電話" value="<?php echo htmlspecialchars((string)($revisionData['phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>

                            <div style="display: flex; gap: 10px; margin-top: 20px; justify-content: flex-end;">
                                <button type="button" class="btn-secondary" onclick="location.href='return_management.php'">取消</button>
                                <button type="submit" class="btn-primary">提交補件</button>
                            </div>
                        </form>
                    </section>
                <?php } ?>
            </section>
        </main>
    </div>
</body>
</html>
