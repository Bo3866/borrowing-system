<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?next=borrow.php');
    exit;
}

$userId = (string)$_SESSION['user_id'];
$displayName = (string)($_SESSION['full_name'] ?? $_SESSION['user_id']);
$roleName = (string)($_SESSION['role_name'] ?? '');

$link = mysqli_connect('localhost', 'root', '', 'borrowing_system', 3307);
$dbError = '';
if (!$link) {
    $dbError = '資料庫連線失敗：' . mysqli_connect_error();
} else {
    mysqli_set_charset($link, 'utf8mb4');
}

$equipmentMap = [];
$spaceMap = [];
if ($dbError === '') {
    $equipmentSql = "
        SELECT
            ec.equipment_code,
            ec.equipment_name,
            ec.borrow_limit_quantity,
            COALESCE(SUM(CASE WHEN e.operation_status = 1 THEN 1 ELSE 0 END), 0) AS available_quantity
        FROM equipment_categories ec
        LEFT JOIN equipments e ON e.equipment_code = ec.equipment_code
        GROUP BY ec.equipment_code, ec.equipment_name, ec.borrow_limit_quantity
        ORDER BY ec.equipment_code ASC
    ";

    $equipmentResult = mysqli_query($link, $equipmentSql);
    if ($equipmentResult) {
        while ($row = mysqli_fetch_assoc($equipmentResult)) {
            $code = (string)$row['equipment_code'];
            $limit = $row['borrow_limit_quantity'] !== null ? (int)$row['borrow_limit_quantity'] : null;
            $equipmentMap[$code] = [
                'equipment_code' => $code,
                'equipment_name' => (string)$row['equipment_name'],
                'borrow_limit_quantity' => $limit,
                'available_quantity' => (int)$row['available_quantity'],
            ];
        }
    } else {
        $dbError = '讀取器材資料失敗：' . mysqli_error($link);
    }

    if ($dbError === '') {
        $spaceSql = "
            SELECT
                s.space_id,
                s.space_name,
                s.capacity,
                s.space_status
            FROM spaces s
            ORDER BY s.space_id ASC
        ";

        $spaceResult = mysqli_query($link, $spaceSql);
        if ($spaceResult) {
            while ($row = mysqli_fetch_assoc($spaceResult)) {
                $spaceId = (string)$row['space_id'];
                $spaceMap[$spaceId] = [
                    'space_id' => $spaceId,
                    'space_name' => (string)$row['space_name'],
                    'capacity' => (int)$row['capacity'],
                    'space_status' => (string)$row['space_status'],
                ];
            }
        } else {
            $dbError = '讀取空間資料失敗：' . mysqli_error($link);
        }
    }
}

$borrowError = '';
$borrowSuccess = '';
$formData = [
    'resource_type' => 'equipment',
    'equipment_code' => '',
    'space_id' => '',
    'borrow_quantity' => '1',
    'borrow_start_at' => '',
    'borrow_end_at' => '',
    'purpose' => '',
    'contact_phone' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData['resource_type'] = trim((string)($_POST['resource_type'] ?? 'equipment'));
    $formData['equipment_code'] = trim((string)($_POST['equipment_code'] ?? ''));
    $formData['space_id'] = trim((string)($_POST['space_id'] ?? ''));
    $formData['borrow_quantity'] = trim((string)($_POST['borrow_quantity'] ?? '1'));
    $formData['borrow_start_at'] = trim((string)($_POST['borrow_start_at'] ?? ''));
    $formData['borrow_end_at'] = trim((string)($_POST['borrow_end_at'] ?? ''));
    $formData['purpose'] = trim((string)($_POST['purpose'] ?? ''));
    $formData['contact_phone'] = trim((string)($_POST['contact_phone'] ?? ''));

    if ($dbError !== '') {
        $borrowError = $dbError;
    } elseif (!in_array($formData['resource_type'], ['equipment', 'space'], true)) {
        $borrowError = '請選擇有效的借用類型。';
    } else {
        $selectedEquipment = null;
        $selectedSpace = null;
        $borrowQuantity = 1;

        if ($formData['resource_type'] === 'equipment') {
            if (!isset($equipmentMap[$formData['equipment_code']])) {
                $borrowError = '請選擇有效的器材項目。';
            } else {
                $selectedEquipment = $equipmentMap[$formData['equipment_code']];
                $borrowQuantity = (int)$formData['borrow_quantity'];
                if ($borrowQuantity <= 0) {
                    $borrowError = '借用數量必須大於 0。';
                } elseif ($selectedEquipment['borrow_limit_quantity'] !== null && $borrowQuantity > (int)$selectedEquipment['borrow_limit_quantity']) {
                    $borrowError = '借用數量超過限借數量。';
                } elseif ($borrowQuantity > (int)$selectedEquipment['available_quantity']) {
                    $borrowError = '借用數量超過目前可借用數量。';
                }
            }
        } else {
            if (!isset($spaceMap[$formData['space_id']])) {
                $borrowError = '請選擇有效的空間項目。';
            } else {
                $selectedSpace = $spaceMap[$formData['space_id']];
                if ($selectedSpace['space_status'] !== 'available') {
                    $borrowError = '所選空間目前不可借用。';
                }
            }
        }

        if ($borrowError !== '') {
            // Keep the first validation error.
        } elseif ($formData['borrow_start_at'] === '' || $formData['borrow_end_at'] === '') {
            $borrowError = '請完整填寫借用起訖時間。';
        } elseif ($formData['purpose'] === '') {
            $borrowError = '請填寫用途說明。';
        } elseif (strtotime($formData['borrow_end_at']) <= strtotime($formData['borrow_start_at'])) {
            $borrowError = '借用結束時間必須晚於開始時間。';
        } else {
            $submittedResourceType = $formData['resource_type'];
            $borrowStartAtSql = date('Y-m-d H:i:s', strtotime($formData['borrow_start_at']));
            $borrowEndAtSql = date('Y-m-d H:i:s', strtotime($formData['borrow_end_at']));

            mysqli_begin_transaction($link);

            try {
                if ($formData['contact_phone'] !== '') {
                    $updatePhoneStmt = mysqli_prepare($link, 'UPDATE users SET phone = ? WHERE user_id = ?');
                    if (!$updatePhoneStmt) {
                        throw new RuntimeException('更新聯絡電話失敗：' . mysqli_error($link));
                    }
                    mysqli_stmt_bind_param($updatePhoneStmt, 'ss', $formData['contact_phone'], $userId);
                    mysqli_stmt_execute($updatePhoneStmt);
                    mysqli_stmt_close($updatePhoneStmt);
                }

                $reservationStmt = mysqli_prepare(
                    $link,
                    'INSERT INTO reservations (applicant_id, borrow_start_at, borrow_end_at, approval_status) VALUES (?, ?, ?, "pending")'
                );
                if (!$reservationStmt) {
                    throw new RuntimeException('建立預約主檔失敗：' . mysqli_error($link));
                }
                mysqli_stmt_bind_param(
                    $reservationStmt,
                    'sss',
                    $userId,
                    $borrowStartAtSql,
                    $borrowEndAtSql
                );
                mysqli_stmt_execute($reservationStmt);
                $reservationId = (int)mysqli_insert_id($link);
                mysqli_stmt_close($reservationStmt);

                if ($formData['resource_type'] === 'equipment') {
                    $selectEquipmentStmt = mysqli_prepare(
                        $link,
                        'SELECT equipment_id FROM equipments WHERE equipment_code = ? AND operation_status = 1 ORDER BY equipment_id ASC LIMIT ?'
                    );
                    if (!$selectEquipmentStmt) {
                        throw new RuntimeException('讀取可借器材失敗：' . mysqli_error($link));
                    }
                    mysqli_stmt_bind_param($selectEquipmentStmt, 'si', $formData['equipment_code'], $borrowQuantity);
                    mysqli_stmt_execute($selectEquipmentStmt);
                    $availableEquipmentResult = mysqli_stmt_get_result($selectEquipmentStmt);

                    $equipmentIds = [];
                    while ($equipmentRow = mysqli_fetch_assoc($availableEquipmentResult)) {
                        $equipmentIds[] = (int)$equipmentRow['equipment_id'];
                    }
                    mysqli_stmt_close($selectEquipmentStmt);

                    if (count($equipmentIds) < $borrowQuantity) {
                        throw new RuntimeException('目前可借器材不足，請調整借用數量。');
                    }

                    $reservationItemStmt = mysqli_prepare(
                        $link,
                        'INSERT INTO equipment_reservation_items (reservation_id, equipment_id, borrow_quantity) VALUES (?, ?, 1)'
                    );
                    if (!$reservationItemStmt) {
                        throw new RuntimeException('建立器材預約明細失敗：' . mysqli_error($link));
                    }

                    foreach ($equipmentIds as $equipmentId) {
                        mysqli_stmt_bind_param($reservationItemStmt, 'ii', $reservationId, $equipmentId);
                        mysqli_stmt_execute($reservationItemStmt);
                    }
                    mysqli_stmt_close($reservationItemStmt);

                    $updateEquipmentStatusStmt = mysqli_prepare(
                        $link,
                        'UPDATE equipments SET operation_status = 2 WHERE equipment_id = ? AND operation_status = 1'
                    );
                    if (!$updateEquipmentStatusStmt) {
                        throw new RuntimeException('更新器材可借狀態失敗：' . mysqli_error($link));
                    }

                    foreach ($equipmentIds as $equipmentId) {
                        mysqli_stmt_bind_param($updateEquipmentStatusStmt, 'i', $equipmentId);
                        mysqli_stmt_execute($updateEquipmentStatusStmt);

                        if (mysqli_stmt_affected_rows($updateEquipmentStatusStmt) !== 1) {
                            throw new RuntimeException('器材狀態更新異常，請重新送出申請。');
                        }
                    }
                    mysqli_stmt_close($updateEquipmentStatusStmt);
                } else {
                    $spaceConflictStmt = mysqli_prepare(
                        $link,
                        'SELECT COUNT(*) AS conflict_count
                         FROM space_reservation_items sri
                         JOIN reservations r ON r.reservation_id = sri.reservation_id
                         WHERE sri.space_id = ?
                           AND r.approval_status IN ("pending", "approved")
                           AND NOT (r.borrow_end_at <= ? OR r.borrow_start_at >= ?)'
                    );
                    if (!$spaceConflictStmt) {
                        throw new RuntimeException('檢查空間時段衝突失敗：' . mysqli_error($link));
                    }
                    mysqli_stmt_bind_param($spaceConflictStmt, 'sss', $formData['space_id'], $borrowStartAtSql, $borrowEndAtSql);
                    mysqli_stmt_execute($spaceConflictStmt);
                    $spaceConflictResult = mysqli_stmt_get_result($spaceConflictStmt);
                    $spaceConflictRow = $spaceConflictResult ? mysqli_fetch_assoc($spaceConflictResult) : null;
                    mysqli_stmt_close($spaceConflictStmt);

                    if ($spaceConflictRow && (int)$spaceConflictRow['conflict_count'] > 0) {
                        throw new RuntimeException('該時段空間已被預約，請改選其他時段或空間。');
                    }

                    $spaceItemStmt = mysqli_prepare(
                        $link,
                        'INSERT INTO space_reservation_items (reservation_id, space_id) VALUES (?, ?)'
                    );
                    if (!$spaceItemStmt) {
                        throw new RuntimeException('建立空間預約明細失敗：' . mysqli_error($link));
                    }
                    mysqli_stmt_bind_param($spaceItemStmt, 'is', $reservationId, $formData['space_id']);
                    mysqli_stmt_execute($spaceItemStmt);
                    mysqli_stmt_close($spaceItemStmt);
                }

                mysqli_commit($link);
                $borrowSuccess = '申請已送出，申請編號：' . $reservationId . '。';

                $formData = [
                    'resource_type' => 'equipment',
                    'equipment_code' => '',
                    'space_id' => '',
                    'borrow_quantity' => '1',
                    'borrow_start_at' => '',
                    'borrow_end_at' => '',
                    'purpose' => '',
                    'contact_phone' => '',
                ];

                if ($submittedResourceType === 'equipment' && $selectedEquipment !== null) {
                    $selectedCode = (string)$selectedEquipment['equipment_code'];
                    if (isset($equipmentMap[$selectedCode])) {
                        $equipmentMap[$selectedCode]['available_quantity'] -= $borrowQuantity;
                        if ($equipmentMap[$selectedCode]['available_quantity'] < 0) {
                            $equipmentMap[$selectedCode]['available_quantity'] = 0;
                        }
                    }
                }
            } catch (Throwable $exception) {
                mysqli_rollback($link);
                $borrowError = $exception->getMessage();
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
    <title>借用申請｜校園資源租借系統</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container">
        <nav class="navbar">
            <div class="navbar-brand">
                <h1>📚 校園資源租借系統</h1>
            </div>
            <div class="navbar-menu">
                <button class="nav-btn" onclick="location.href='index.php'">回首頁</button>
                <button class="nav-btn" type="button" disabled><?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></button>
                <button class="nav-btn" onclick="location.href='logout.php'">登出</button>
            </div>
        </nav>

        <main class="main-content">
            <section class="borrow-page">
                <h2>器材借用申請</h2>
                <p class="borrow-subtitle">角色：<?php echo htmlspecialchars($roleName, ENT_QUOTES, 'UTF-8'); ?>。填寫申請後將送出審核。</p>

                <?php if ($dbError !== '') { ?>
                    <div class="login-alert"><?php echo htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } ?>

                <?php if ($borrowError !== '') { ?>
                    <div class="login-alert"><?php echo htmlspecialchars($borrowError, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } ?>

                <?php if ($borrowSuccess !== '') { ?>
                    <div class="borrow-success"><?php echo htmlspecialchars($borrowSuccess, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } ?>

                <div class="borrow-layout">
                    <section class="card borrow-form-card">
                        <h3>申請資料</h3>
                        <form method="post" class="borrow-form" action="borrow.php">
                            <div class="form-group">
                                <label for="resource_type">借用類型</label>
                                <select id="resource_type" name="resource_type">
                                    <option value="equipment" <?php echo $formData['resource_type'] === 'equipment' ? 'selected' : ''; ?>>器材</option>
                                    <option value="space" <?php echo $formData['resource_type'] === 'space' ? 'selected' : ''; ?>>空間</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="applicant_user_id">申請人帳號</label>
                                <input type="text" id="applicant_user_id" value="<?php echo htmlspecialchars($userId, ENT_QUOTES, 'UTF-8'); ?>" readonly>
                            </div>

                            <div class="form-group" id="equipmentGroup">
                                <label for="equipment_code">器材項目</label>
                                <select id="equipment_code" name="equipment_code">
                                    <option value="">請選擇</option>
                                    <?php foreach ($equipmentMap as $equipment) { ?>
                                        <option
                                            value="<?php echo htmlspecialchars($equipment['equipment_code'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-available="<?php echo (int)$equipment['available_quantity']; ?>"
                                            data-limit="<?php echo $equipment['borrow_limit_quantity'] === null ? '' : (int)$equipment['borrow_limit_quantity']; ?>"
                                            <?php echo $formData['equipment_code'] === $equipment['equipment_code'] ? 'selected' : ''; ?>
                                        >
                                            <?php echo htmlspecialchars($equipment['equipment_code'] . ' - ' . $equipment['equipment_name'], ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php } ?>
                                </select>
                            </div>

                            <div class="form-group" id="spaceGroup">
                                <label for="space_id">空間項目</label>
                                <select id="space_id" name="space_id">
                                    <option value="">請選擇</option>
                                    <?php foreach ($spaceMap as $space) { ?>
                                        <option value="<?php echo htmlspecialchars($space['space_id'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo $formData['space_id'] === $space['space_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($space['space_id'] . ' - ' . $space['space_name'] . ' (' . $space['space_status'] . ')', ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php } ?>
                                </select>
                            </div>

                            <div class="borrow-hint-box" id="equipmentHintBox">
                                <div>目前可借用數量：<strong id="availableQuantityHint">-</strong></div>
                                <div>限借數量：<strong id="borrowLimitHint">-</strong></div>
                            </div>

                            <div class="form-group" id="borrowQuantityGroup">
                                <label for="borrow_quantity">借用數量</label>
                                <input type="number" id="borrow_quantity" name="borrow_quantity" min="1" value="<?php echo htmlspecialchars($formData['borrow_quantity'], ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="borrow_start_at">借用開始時間</label>
                                <input type="datetime-local" id="borrow_start_at" name="borrow_start_at" value="<?php echo htmlspecialchars($formData['borrow_start_at'], ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="borrow_end_at">借用結束時間</label>
                                <input type="datetime-local" id="borrow_end_at" name="borrow_end_at" value="<?php echo htmlspecialchars($formData['borrow_end_at'], ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="contact_phone">聯絡電話</label>
                                <input type="text" id="contact_phone" name="contact_phone" placeholder="例：09XXXXXXXX" value="<?php echo htmlspecialchars($formData['contact_phone'], ENT_QUOTES, 'UTF-8'); ?>">
                            </div>

                            <div class="form-group">
                                <label for="purpose">用途說明</label>
                                <textarea id="purpose" name="purpose" rows="4" required><?php echo htmlspecialchars($formData['purpose'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                            </div>

                            <div class="form-buttons">
                                <button type="submit" class="btn-primary">送出借用申請</button>
                                <button type="button" class="btn-secondary" onclick="location.href='index.php'">取消</button>
                            </div>
                        </form>
                    </section>

                    <section class="card borrow-stock-card">
                        <h3>器材可借狀態</h3>
                        <p class="borrow-table-note">以下為即時可借用數量與限借數量。</p>
                        <div class="borrow-table-wrapper">
                            <table class="management-table borrow-table">
                                <thead>
                                    <tr>
                                        <th>代碼</th>
                                        <th>器材名稱</th>
                                        <th>目前可借</th>
                                        <th>限借</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($equipmentMap as $equipment) { ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($equipment['equipment_code'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars($equipment['equipment_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo (int)$equipment['available_quantity']; ?></td>
                                            <td><?php echo $equipment['borrow_limit_quantity'] === null ? '不限' : (int)$equipment['borrow_limit_quantity']; ?></td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>

                        <h3 style="margin-top: 1.2rem;">空間可借狀態</h3>
                        <div class="borrow-table-wrapper">
                            <table class="management-table borrow-table">
                                <thead>
                                    <tr>
                                        <th>空間 ID</th>
                                        <th>空間名稱</th>
                                        <th>容納人數</th>
                                        <th>狀態</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($spaceMap as $space) { ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($space['space_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars($space['space_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo (int)$space['capacity']; ?></td>
                                            <td><?php echo htmlspecialchars($space['space_status'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
            </section>
        </main>
    </div>

    <script>
        (function () {
            const equipmentSelect = document.getElementById('equipment_code');
            const spaceSelect = document.getElementById('space_id');
            const resourceTypeSelect = document.getElementById('resource_type');
            const quantityInput = document.getElementById('borrow_quantity');
            const availableQuantityHint = document.getElementById('availableQuantityHint');
            const borrowLimitHint = document.getElementById('borrowLimitHint');
            const equipmentGroup = document.getElementById('equipmentGroup');
            const spaceGroup = document.getElementById('spaceGroup');
            const equipmentHintBox = document.getElementById('equipmentHintBox');
            const borrowQuantityGroup = document.getElementById('borrowQuantityGroup');

            function refreshModeUI() {
                const mode = resourceTypeSelect.value;
                const isEquipment = mode === 'equipment';

                equipmentGroup.style.display = isEquipment ? '' : 'none';
                equipmentHintBox.style.display = isEquipment ? '' : 'none';
                borrowQuantityGroup.style.display = isEquipment ? '' : 'none';
                spaceGroup.style.display = isEquipment ? 'none' : '';

                equipmentSelect.required = isEquipment;
                quantityInput.required = isEquipment;
                spaceSelect.required = !isEquipment;

                if (!isEquipment) {
                    quantityInput.value = '1';
                }
            }

            function refreshBorrowHints() {
                const option = equipmentSelect.options[equipmentSelect.selectedIndex];
                if (!option || !option.value) {
                    availableQuantityHint.textContent = '-';
                    borrowLimitHint.textContent = '-';
                    quantityInput.removeAttribute('max');
                    return;
                }

                const available = Number(option.dataset.available || 0);
                const limitRaw = option.dataset.limit;
                const hasLimit = limitRaw !== '';
                const limit = hasLimit ? Number(limitRaw) : null;

                availableQuantityHint.textContent = String(available);
                borrowLimitHint.textContent = hasLimit ? String(limit) : '不限';

                const maxValue = hasLimit ? Math.min(available, limit) : available;
                if (maxValue > 0) {
                    quantityInput.max = String(maxValue);
                    if (Number(quantityInput.value) > maxValue) {
                        quantityInput.value = String(maxValue);
                    }
                } else {
                    quantityInput.max = '0';
                    quantityInput.value = '0';
                }
            }

            resourceTypeSelect.addEventListener('change', refreshModeUI);
            equipmentSelect.addEventListener('change', refreshBorrowHints);
            refreshModeUI();
            refreshBorrowHints();
        })();
    </script>
</body>
</html>
