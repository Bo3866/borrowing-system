<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?next=report_maintenance.php');
    exit;
}

$errors = [];
$pdo = null;
try {
    require_once __DIR__ . '/config/database.php';
    $pdo = getDatabaseConnection();
} catch (Throwable $t) {
    $pdo = null;
    $errors[] = '無法連線資料庫：' . $t->getMessage();
}
$success = '';

// load equipment categories & list
$equipmentCategories = [];
$equipments = [];
$spaces = [];
if ($pdo !== null) {
    try {
        $stmt = $pdo->query('SELECT * FROM equipment_categories ORDER BY equipment_name');
        $equipmentCategories = $stmt->fetchAll();

        $stmt = $pdo->query('SELECT e.equipment_id, e.operation_status, e.operation_remark, ec.equipment_code, ec.equipment_name FROM equipments e JOIN equipment_categories ec ON e.equipment_code = ec.equipment_code ORDER BY ec.equipment_name, e.equipment_id');
        $equipments = $stmt->fetchAll();
    } catch (Throwable $t) {
        $equipments = [];
        $errors[] = '載入器材清單失敗：' . $t->getMessage();
    }

    // load spaces
    try {
        $stmt = $pdo->query('SELECT space_id, space_name, capacity, space_status FROM spaces ORDER BY space_name');
        $spaces = $stmt->fetchAll();
    } catch (Throwable $t) {
        $spaces = [];
        $errors[] = '載入空間清單失敗：' . $t->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reporterId = (string)$_SESSION['user_id'];
    $targetType = $_POST['target_type'] ?? '';

    // collect form fields (allow prefilling from session when available)
    $email = trim((string)($_POST['email'] ?? ($_SESSION['email'] ?? '')));
    $name = trim((string)($_POST['name'] ?? ($_SESSION['full_name'] ?? '')));
    $reporterIdInput = trim((string)($_POST['reporter_id'] ?? $reporterId));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $unit = trim((string)($_POST['unit'] ?? ''));
    $consent = isset($_POST['consent']);
    $eqCategory = trim((string)($_POST['eq_category'] ?? ''));
    $spaceCategory = trim((string)($_POST['space_category'] ?? ''));
    $repairCategory = trim((string)($_POST['repair_category'] ?? ''));
    $repairCategoryOther = trim((string)($_POST['repair_category_other'] ?? ''));
    $repairItem = trim((string)($_POST['repair_item'] ?? ''));
    $damageDetail = trim((string)($_POST['damage_detail'] ?? ''));
    $locationInput = trim((string)($_POST['location_input'] ?? ''));
    $otherProblems = trim((string)($_POST['other_problems'] ?? ''));

    if (!$consent) {
        $errors[] = '請同意資料蒐集聲明。';
    }

    if ($targetType === 'equipment' && $damageDetail === '') {
        $errors[] = '請填寫損壞情況說明。';
    }

    if ($targetType === 'space' && $repairItem === '' && $damageDetail === '') {
        $errors[] = '請填寫維修項目或損壞情況說明。';
    }

    if ($pdo === null) {
        $errors[] = '無法儲存報修：尚未與資料庫建立連線。';
    }

    // handle file uploads (collect file contents to store into DB)
    $uploadedPaths = [];
    $uploadedFiles = []; // each: ['name'=>..., 'mime'=>..., 'content'=>...] 
    if (!empty($_FILES['attachments']) && is_array($_FILES['attachments']['name'])) {
        $maxFiles = 5;
        $maxSize = 10 * 1024 * 1024; // 10MB
        $allowed = ['image/jpeg','image/png','image/gif','application/pdf','image/webp'];
        $uploadDir = __DIR__ . '/uploads/maintenance';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }

        $count = count($_FILES['attachments']['name']);
        if ($count > $maxFiles) {
            $errors[] = "最多只能上傳 {$maxFiles} 個檔案。";
        }

        for ($i = 0; $i < $count && $i < $maxFiles; $i++) {
            $err = $_FILES['attachments']['error'][$i];
            if ($err !== UPLOAD_ERR_OK) {
                if ($err === UPLOAD_ERR_NO_FILE) continue;
                $errors[] = '上傳檔案發生錯誤。';
                continue;
            }
            $tmp = $_FILES['attachments']['tmp_name'][$i];
            $nameOrig = basename((string)$_FILES['attachments']['name'][$i]);
            $size = $_FILES['attachments']['size'][$i];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $tmp);
            finfo_close($finfo);

            if ($size > $maxSize) {
                $errors[] = "檔案 {$nameOrig} 超過大小限制。";
                continue;
            }
            if (!in_array($mime, $allowed, true)) {
                $errors[] = "檔案 {$nameOrig} 類型不被允許。";
                continue;
            }

            // read file content for DB storage
            $content = @file_get_contents($tmp);
            if ($content === false) {
                $errors[] = "無法讀取上傳檔案 {$nameOrig}。";
            } else {
                // still save a disk copy for backup
                $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $nameOrig);
                $targetName = time() . '_' . bin2hex(random_bytes(6)) . '_' . $safe;
                $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $targetName;
                if (@move_uploaded_file($tmp, $targetPath)) {
                    $uploadedPaths[] = 'uploads/maintenance/' . $targetName;
                }
                $uploadedFiles[] = ['name' => $nameOrig, 'mime' => $mime, 'content' => $content];
            }
        }
    }

    // assemble full description
    $parts = [];
    $parts[] = "姓名: {$name}";
    $parts[] = "學號/編號: {$reporterIdInput}";
    if ($email !== '') $parts[] = "電子郵件: {$email}";
    if ($phone !== '') $parts[] = "聯絡電話: {$phone}";
    if ($unit !== '') $parts[] = "單位: {$unit}";
    if ($targetType === 'equipment') {
        if ($eqCategory !== '') $parts[] = "器材分類代碼: {$eqCategory}";
    } elseif ($targetType === 'space') {
        if ($spaceCategory !== '') $parts[] = "場地分類: {$spaceCategory}";
        if ($repairCategory !== '') $parts[] = '報修類別: ' . ($repairCategoryOther !== '' ? ($repairCategory . ' - ' . $repairCategoryOther) : $repairCategory);
        if ($repairItem !== '') $parts[] = '維修項目: ' . $repairItem;
        if ($locationInput !== '') $parts[] = '維修位置: ' . $locationInput;
    }
    if ($damageDetail !== '') $parts[] = '損壞情況說明: ' . $damageDetail;
    if ($otherProblems !== '') $parts[] = '其他問題: ' . $otherProblems;
    if (!empty($uploadedPaths)) $parts[] = '上傳檔案: ' . implode(', ', $uploadedPaths);

    $fullFaultDescription = implode("\n", $parts);

    if (empty($errors)) {
                    if ($targetType === 'equipment') {
            $equipmentId = (int)($_POST['equipment_id'] ?? 0);
            if ($equipmentId <= 0) {
                $errors[] = '請選擇要報修的器材。';
            } else {
                try {
                    // ensure equipment_maintenance has photo_path column (in case schema was modified)
                    try {
                        $pdo->exec("ALTER TABLE equipment_maintenance ADD COLUMN IF NOT EXISTS photo_path VARCHAR(255) NULL");
                    } catch (Throwable $ignore) {
                        // ignore if ALTER not supported or fails; user may have already added column
                    }
                    $insert = $pdo->prepare('INSERT INTO equipment_maintenance (equipment_id, reporter_id, fault_description, photo_path) VALUES (:equipment_id, :reporter_id, :fault_description, :photo_path)');
                    $insert->execute([
                        'equipment_id' => $equipmentId,
                        'reporter_id' => $reporterId,
                        'fault_description' => $damageDetail,
                        'photo_path' => (!empty($uploadedPaths) ? $uploadedPaths[0] : null)
                    ]);
                    // get created maintenance id
                    $maintenanceId = (int)$pdo->lastInsertId();
                    // store uploaded files into maintenance_attachments
                    if (!empty($uploadedFiles)) {
                        // ensure attachments table exists
                        $pdo->exec("CREATE TABLE IF NOT EXISTS maintenance_attachments (
                            attachment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                            maintenance_id BIGINT UNSIGNED NOT NULL,
                            maintenance_type ENUM('equipment','space') NOT NULL,
                            filename VARCHAR(255) NOT NULL,
                            mime VARCHAR(100) NULL,
                            file_content LONGBLOB NOT NULL,
                            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                            PRIMARY KEY (attachment_id),
                            KEY idx_maintenance_attachments_mid (maintenance_id)
                        ) ENGINE=InnoDB");

                        $attStmt = $pdo->prepare('INSERT INTO maintenance_attachments (maintenance_id, maintenance_type, filename, mime, file_content) VALUES (:maintenance_id, :maintenance_type, :filename, :mime, :file_content)');
                        foreach ($uploadedFiles as $f) {
                            $attStmt->bindValue(':maintenance_id', $maintenanceId, PDO::PARAM_INT);
                            $attStmt->bindValue(':maintenance_type', 'equipment', PDO::PARAM_STR);
                            $attStmt->bindValue(':filename', $f['name'], PDO::PARAM_STR);
                            $attStmt->bindValue(':mime', $f['mime'], PDO::PARAM_STR);
                            $attStmt->bindValue(':file_content', $f['content'], PDO::PARAM_LOB);
                            $attStmt->execute();
                        }
                    }
                    // mark equipment as under maintenance (operation_status = 3)
                    try {
                        $upd = $pdo->prepare('UPDATE equipments SET operation_status = 3 WHERE equipment_id = :equipment_id');
                        $upd->execute(['equipment_id' => $equipmentId]);
                    } catch (Throwable $t) {
                        // non-fatal: record error but keep going
                        $errors[] = '更新器材狀態失敗：' . $t->getMessage();
                    }
                    $success = '器材報修已送出，感謝您的回報。';
                } catch (Throwable $t) {
                    $errors[] = '儲存器材報修失敗：' . $t->getMessage();
                }
            }

        } elseif ($targetType === 'space') {
            $spaceId = (string)($_POST['space_id'] ?? '');
            if ($spaceId === '') {
                $errors[] = '請選擇要報修的空間。';
            } else {
                try {
                    // ensure space_maintenance table exists
                    $pdo->exec("CREATE TABLE IF NOT EXISTS space_maintenance (
                maintenance_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                space_id VARCHAR(30) NOT NULL,
                reporter_id VARCHAR(10) NOT NULL,
                reported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                fault_description TEXT NOT NULL,
                photo_path VARCHAR(255) NULL,
                maintenance_status ENUM('pending','in_progress','completed') NOT NULL DEFAULT 'pending',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (maintenance_id)
            ) ENGINE=InnoDB");

                    // also ensure equipment_maintenance has photo_path if space table was created first
                    try {
                        $pdo->exec("ALTER TABLE space_maintenance ADD COLUMN IF NOT EXISTS photo_path VARCHAR(255) NULL");
                    } catch (Throwable $ignore) {
                    }

                    $insert = $pdo->prepare('INSERT INTO space_maintenance (space_id, reporter_id, fault_description, photo_path) VALUES (:space_id, :reporter_id, :fault_description, :photo_path)');
                    $insert->execute([
                        'space_id' => $spaceId,
                        'reporter_id' => $reporterId,
                        'fault_description' => $damageDetail,
                        'photo_path' => (!empty($uploadedPaths) ? $uploadedPaths[0] : null)
                    ]);
                    $maintenanceId = (int)$pdo->lastInsertId();
                    if (!empty($uploadedFiles)) {
                        // ensure attachments table exists (created earlier if not)
                        $pdo->exec("CREATE TABLE IF NOT EXISTS maintenance_attachments (
                            attachment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                            maintenance_id BIGINT UNSIGNED NOT NULL,
                            maintenance_type ENUM('equipment','space') NOT NULL,
                            filename VARCHAR(255) NOT NULL,
                            mime VARCHAR(100) NULL,
                            file_content LONGBLOB NOT NULL,
                            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                            PRIMARY KEY (attachment_id),
                            KEY idx_maintenance_attachments_mid (maintenance_id)
                        ) ENGINE=InnoDB");

                        $attStmt = $pdo->prepare('INSERT INTO maintenance_attachments (maintenance_id, maintenance_type, filename, mime, file_content) VALUES (:maintenance_id, :maintenance_type, :filename, :mime, :file_content)');
                        foreach ($uploadedFiles as $f) {
                            $attStmt->bindValue(':maintenance_id', $maintenanceId, PDO::PARAM_INT);
                            $attStmt->bindValue(':maintenance_type', 'space', PDO::PARAM_STR);
                            $attStmt->bindValue(':filename', $f['name'], PDO::PARAM_STR);
                            $attStmt->bindValue(':mime', $f['mime'], PDO::PARAM_STR);
                            $attStmt->bindValue(':file_content', $f['content'], PDO::PARAM_LOB);
                            $attStmt->execute();
                        }
                    }
                    // mark space as under maintenance (space_status = 'maintenance')
                    try {
                        // Support both legacy numeric statuses (e.g. '1','2') and string statuses ('available','maintenance'):
                        // If current value is numeric, set to '3' (維修中) to match equipments operation_status convention.
                        // Otherwise set to the string 'maintenance'.
                        $upd = $pdo->prepare("UPDATE spaces SET space_status = IF(space_status REGEXP '^[0-9]+$', '3', 'maintenance') WHERE space_id = :space_id");
                        $upd->execute(['space_id' => $spaceId]);
                    } catch (Throwable $t) {
                        // non-fatal: record error but keep going
                        $errors[] = '更新空間狀態失敗：' . $t->getMessage();
                    }
                    $success = '空間報修已送出，感謝您的回報。';
                } catch (Throwable $t) {
                    $errors[] = '儲存空間報修失敗：' . $t->getMessage();
                }
            }

        } else {
            $errors[] = '請選擇報修對象類型。';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>報修回報｜校園資源租借系統</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .form-row { margin-bottom: 0.75rem; }
        .hint { color: #666; font-size: 0.9rem; }
    </style>
    <script>
        function toggleTargetFields() {
            const type = document.querySelector('input[name="target_type"]:checked').value;
            document.getElementById('equipment-only-fields').style.display = type === 'equipment' ? 'block' : 'none';
            document.getElementById('space-only-fields').style.display = type === 'space' ? 'block' : 'none';
        }
        function filterEquipments() {
            // The datalist was removed, so this function is intentionally left empty
            // to fulfill the user's request for manual text input
        }
        function toggleRepairCategoryOther() {
            const cat = document.getElementById('repair_category').value;
            document.getElementById('repair_category_other_wrap').style.display = cat === '其他' ? 'block' : 'none';
        }
        function populateSpaceCategories() {
            const categories = new Set();
            const options = document.querySelectorAll('#space_id option');
            options.forEach(opt => {
                const cat = opt.getAttribute('data-category');
                if (cat) categories.add(cat);
            });
            const sel = document.getElementById('space_category');
            
            // clear existing dynamically generated options
            sel.innerHTML = '<option value="">-- 選擇場地分類 --</option>';

            categories.forEach(cat => {
                const opt = document.createElement('option');
                opt.value = cat;
                opt.textContent = cat;
                sel.appendChild(opt);
            });
            
            // restore previously selected value if any
            const pVal = "<?php echo addslashes($_POST['space_category'] ?? ''); ?>";
            if (pVal && categories.has(pVal)) {
                sel.value = pVal;
            }
        }
        function filterSpaces() {
            const cat = document.getElementById('space_category').value;
            const options = document.querySelectorAll('#space_id option');
            options.forEach(opt => {
                if(opt.value === "") {
                    opt.style.display = '';
                } else if (cat === "" || opt.getAttribute('data-category') === cat) {
                    opt.style.display = '';
                } else {
                    opt.style.display = 'none';
                }
            });
        }
        window.addEventListener('DOMContentLoaded', function(){
            const radios = document.querySelectorAll('input[name="target_type"]');
            radios.forEach(r => r.addEventListener('change', toggleTargetFields));
            toggleTargetFields();
            populateSpaceCategories();
            
            // if form was submitted but had error, we should rerun filters but not reset user selection
            if (document.getElementById('eq_category').value !== '') {
                filterEquipments();
                // input value is already pre-filled via PHP
            }
            if (document.getElementById('space_category').value !== '') {
                filterSpaces();
                document.getElementById('space_id').value = "<?php echo addslashes($_POST['space_id'] ?? ''); ?>";
            }
        });
    </script>
</head>
<body>
    <div class="container">
        <nav class="navbar">
            <div class="navbar-brand"><h1>📚 校園資源租借系統</h1></div>
            <div class="navbar-menu">
                <button class="nav-btn" onclick="location.href='index.php'">回首頁</button>
                <button class="nav-btn" onclick="location.href='logout.php'">登出</button>
            </div>
        </nav>

        <main class="main-content">
            <section class="card maintenance-card">
                <h2>報修回報</h2>
                <p class="hint">請選擇報修對象（空間或器材），並詳述故障情形，我們會盡快處理。</p>

                <!-- 詳細表單請向下填寫 -->

                <?php if (!empty($errors)) { ?>
                    <div class="login-alert"><?php echo htmlspecialchars(implode(' / ', $errors), ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } ?>

                <?php if ($success !== '') { ?>
                    <div class="borrow-success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } ?>

                <form method="post" action="report_maintenance.php" enctype="multipart/form-data">
                    <div class="form-row">
                        <label>報修對象</label><br>
                        <label><input type="radio" name="target_type" value="equipment" <?php echo (($_POST['target_type'] ?? 'equipment') === 'equipment') ? 'checked' : ''; ?>> 器材</label>
                        <label style="margin-left:1rem;"><input type="radio" name="target_type" value="space" <?php echo (($_POST['target_type'] ?? '') === 'space') ? 'checked' : ''; ?>> 空間</label>
                    </div>

                    <div class="form-row">
                        <label for="email">電子郵件</label>
                        <input id="email" name="email" type="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ($_SESSION['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" style="width:100%">
                    </div>

                    <div class="form-row">
                        <label for="name">姓名 <span style="color:#c00">*</span></label>
                        <input id="name" name="name" type="text" value="<?php echo htmlspecialchars($_POST['name'] ?? ($_SESSION['full_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" style="width:100%" required readonly>
                    </div>

                    <div class="form-row">
                        <label for="reporter_id">學號 / 員工編號 <span style="color:#c00">*</span></label>
                        <input id="reporter_id" name="reporter_id" type="text" value="<?php echo htmlspecialchars($_POST['reporter_id'] ?? $_SESSION['user_id'], ENT_QUOTES, 'UTF-8'); ?>" style="width:100%" required readonly>
                    </div>

                    <div class="form-row">
                        <label for="phone">聯絡電話</label>
                        <input id="phone" name="phone" type="text" value="<?php echo htmlspecialchars($_POST['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="width:100%">
                    </div>

                    <div class="form-row">
                        <label for="unit">單位（社團/學會）</label>
                        <input id="unit" name="unit" type="text" value="<?php echo htmlspecialchars($_POST['unit'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="width:100%">
                    </div>

                    <div id="equipment-only-fields">
                        <div class="form-row">
                            <label for="eq_category">選擇器材分類 <span style="color:#c00">*</span></label>
                            <select id="eq_category" name="eq_category" onchange="document.getElementById('equipment_id').value=''; filterEquipments();" style="width:100%">
                                <option value="">-- 選擇器材分類 --</option>
                                <?php foreach ($equipmentCategories as $cat) { ?>
                                    <option value="<?php echo htmlspecialchars($cat['equipment_code'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo ((isset($_POST['eq_category']) && $_POST['eq_category'] === $cat['equipment_code']) ? 'selected' : ''); ?>><?php echo htmlspecialchars($cat['equipment_name'], ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php } ?>
                            </select>
                        </div>

                        <div class="form-row">
                            <label for="equipment_id">填寫器材編號 <span style="color:#c00">*</span></label>
                            <input type="text" id="equipment_id" name="equipment_id" value="<?php echo htmlspecialchars($_POST['equipment_id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="width:100%" placeholder="請填寫器材編號">
                        </div>
                    </div>

                    <div id="space-only-fields" style="display:none;">
                        <div class="form-row">
                            <label for="space_category">選擇場地分類 <span style="color:#c00">*</span></label>
                            <select id="space_category" name="space_category" onchange="document.getElementById('space_id').value=''; filterSpaces();" style="width:100%">
                                <option value="">-- 選擇場地分類 --</option>
                            </select>
                        </div>

                        <div class="form-row">
                            <label for="space_id">選擇空間 <span style="color:#c00">*</span></label>
                            <select id="space_id" name="space_id" style="width:100%">
                                <option value="">-- 選擇空間 --</option>
                                <?php foreach ($spaces as $sp) { 
                                    // Extract the first word before space character as category
                                    $cat = explode(' ', trim($sp['space_name']))[0];
                                ?>
                                    <option data-category="<?php echo htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars($sp['space_id'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo (isset($_POST['space_id']) && $_POST['space_id'] === $sp['space_id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($sp['space_name'] . ' (' . $sp['space_id'] . ')', ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php } ?>
                            </select>
                        </div>

                        <div class="form-row">
                            <label for="repair_category">報修類別（簡易判斷即可） <span style="color:#c00">*</span></label>
                            <select id="repair_category" name="repair_category" style="width:100%" onchange="toggleRepairCategoryOther()">
                                <option value="">-- 選擇報修類別 --</option>
                                <?php
                                $cats = ['建築(壁癌)','建築(木作)','建築(天花板)','建築(窗戶)','電器(插座)','電器(電燈)','電器(冷氣)','電器(音響/投影機)','水類(飲水機)','水類(給水/排水/積水/漏水)','水類(馬桶/洗手台/水龍頭)','其他'];
                                foreach ($cats as $c) {
                                    $selected = (isset($_POST['repair_category']) && $_POST['repair_category'] === $c) ? 'selected' : '';
                                    echo '<option value="' . htmlspecialchars($c, ENT_QUOTES, 'UTF-8') . '" ' . $selected . '>' . htmlspecialchars($c, ENT_QUOTES, 'UTF-8') . '</option>';
                                }
                                ?>
                            </select>
                            <div id="repair_category_other_wrap" style="margin-top:6px; display:<?php echo (($_POST['repair_category'] ?? '') === '其他') ? 'block' : 'none'; ?>">
                                <input type="text" id="repair_category_other" name="repair_category_other" value="<?php echo htmlspecialchars($_POST['repair_category_other'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="width:100%" placeholder="請填寫其他裝修類別">
                            </div>
                        </div>

                        <div class="form-row">
                            <label for="repair_item">維修項目（格式：地點-教室名稱-維修項目名稱）</label>
                            <input id="repair_item" name="repair_item" type="text" value="<?php echo htmlspecialchars($_POST['repair_item'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="width:100%" placeholder="例如：地下室-演講廳-左後方講台">
                        </div>

                        <div class="form-row">
                            <label for="location_input">維修項目所在位置</label>
                            <input id="location_input" name="location_input" type="text" value="<?php echo htmlspecialchars($_POST['location_input'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="width:100%" placeholder="詳細描述維修項目處於什麼位置">
                        </div>
                    </div>

                    <div class="form-row">
                        <label for="damage_detail">損壞情況說明（【備註 : 盡可能詳細說明】） <span style="color:#c00">*</span></label>
                        <textarea id="damage_detail" name="damage_detail" rows="5" style="width:100%" placeholder="備註 : 盡可能詳細說明"><?php echo htmlspecialchars($_POST['damage_detail'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>

                    <div class="form-row">
                        <label for="attachments">圖片上傳（最多 5 個，每個上限 10 MB，支援 JPG/PNG/GIF/PDF）</label>
                        <input id="attachments" name="attachments[]" type="file" multiple accept="image/*,.pdf">
                        <div id="attachments-hint" class="hint">尚未選擇檔案</div>
                    </div>

                    <div class="form-row">
                        <label for="other_problems">其他問題</label>
                        <textarea id="other_problems" name="other_problems" rows="3" style="width:100%"><?php echo htmlspecialchars($_POST['other_problems'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>

                    <div class="form-row">
                        <label><input id="consent" type="checkbox" name="consent" <?php echo isset($_POST['consent']) ? 'checked' : ''; ?>> 我同意本表單蒐集之個人資料，僅限於輔大課指組使用。 <span style="color:#c00">*</span></label>
                    </div>

                    <div class="form-row">
                        <button class="btn-primary" type="submit">提交</button>
                        <button class="btn-secondary" type="button" onclick="location.href='index.php'">取消</button>
                    </div>
                </form>
                <script>
                    (function(){
                        const form = document.querySelector('form');
                        const attachments = document.getElementById('attachments');
                        const hint = document.getElementById('attachments-hint');
                        const maxFiles = 5;
                        const maxSize = 10 * 1024 * 1024; // 10MB
                        const allowed = ['image/jpeg','image/png','image/gif','application/pdf','image/webp'];

                        attachments.addEventListener('change', function(){
                            const files = attachments.files;
                            if (!files || files.length === 0) {
                                hint.textContent = '尚未選擇檔案';
                                return;
                            }
                            if (files.length > maxFiles) {
                                hint.textContent = `選取超過 ${maxFiles} 個檔案（已選 ${files.length}）`;
                            } else {
                                hint.textContent = `已選 ${files.length} 個檔案`;
                            }
                        });

                        form.addEventListener('submit', function(e){
                            // client-side validation: consent, name, id, either repair item or damage detail, file constraints
                                const name = document.getElementById('name').value.trim();
                                const reporter = document.getElementById('reporter_id').value.trim();
                                const consent = document.getElementById('consent').checked;
                                const repairItem = document.getElementById('repair_item').value.trim();
                                const damage = document.getElementById('damage_detail').value.trim();
                                const targetType = document.querySelector('input[name="target_type"]:checked').value;
                                const equipmentId = document.getElementById('equipment_id').value;
                                const equipmentCategory = document.getElementById('eq_category').value;
                                const spaceId = document.getElementById('space_id').value;
                                const spaceCategory = document.getElementById('space_category').value;
                                const repairCategorySelected = document.getElementById('repair_category').value;

                                if (!consent) {
                                    alert('請同意資料蒐集聲明。');
                                    e.preventDefault();
                                    return false;
                                }
                                if (name === '') {
                                    alert('請填寫姓名。');
                                    e.preventDefault();
                                    return false;
                                }
                                if (reporter === '') {
                                    alert('請填寫學號或員工編號。');
                                    e.preventDefault();
                                    return false;
                                }
                                
                                if (targetType === 'equipment') {
                                    if (!equipmentCategory) {
                                        alert('請選擇要報修的器材分類。');
                                        e.preventDefault();
                                        return false;
                                    }
                                    if (!equipmentId) {
                                        alert('請選擇要報修的器材編號。');
                                        e.preventDefault();
                                        return false;
                                    }
                                    if (damage === '') {
                                        alert('請填寫損壞情況說明。');
                                        e.preventDefault();
                                        return false;
                                    }
                                } else if (targetType === 'space') {
                                    if (!spaceCategory) {
                                        alert('請選擇場地分類。');
                                        e.preventDefault();
                                        return false;
                                    }
                                    if (!spaceId) {
                                        alert('請選擇要報修的空間。');
                                        e.preventDefault();
                                        return false;
                                    }
                                    if (!repairCategorySelected) {
                                        alert('請選擇報修類別。');
                                        e.preventDefault();
                                        return false;
                                    }
                                    if (repairItem === '' && damage === '') {
                                        alert('請填寫維修項目或損壞情況說明。');
                                        e.preventDefault();
                                        return false;
                                    }
                                }

                            // files
                            const files = attachments.files;
                            if (files && files.length > 0) {
                                if (files.length > maxFiles) {
                                    alert('最多只能上傳 ' + maxFiles + ' 個檔案。');
                                    e.preventDefault();
                                    return false;
                                }
                                for (let i=0;i<files.length;i++){
                                    const f = files[i];
                                    if (f.size > maxSize) {
                                        alert('檔案 ' + f.name + ' 超過 10MB。');
                                        e.preventDefault();
                                        return false;
                                    }
                                    if (!allowed.includes(f.type)) {
                                        alert('檔案 ' + f.name + ' 類型不被允許。');
                                        e.preventDefault();
                                        return false;
                                    }
                                }
                            }
                        });
                    })();
                </script>

                </section>
            </section>
        </main>
    </div>
</body>
</html>
