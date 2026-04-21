<?php
$html = <<<'EOD'
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>線上報修單｜校園資源租借系統</title>
    <link rel="stylesheet" href="styles.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-blue: #0056b3;
            --light-blue: #eef2f7;
            --border-color: #d1d5db;
            --text-main: #374151;
            --text-muted: #6b7280;
            --focus-ring: rgba(0, 86, 179, 0.2);
            --bg-body: #f4f7f6;
            --danger-red: #dc2626;
        }
        body {
            background-color: var(--bg-body);
            color: var(--text-main);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
        }
        .main-content {
            max-width: 800px;
            margin: 2rem auto;
            padding: 0 1rem;
        }
        .maintenance-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            border-top: 5px solid var(--primary-blue);
        }
        .card-header {
            padding: 2rem 2rem 1rem;
            text-align: center;
        }
        .card-header h2 {
            margin: 0 0 0.5rem;
            color: var(--primary-blue);
            font-size: 1.8rem;
        }
        .card-header p.hint {
            color: var(--text-muted);
            margin: 0;
            font-size: 0.95rem;
            line-height: 1.5;
        }
        .form-body {
            padding: 0 2.5rem 2.5rem;
        }
        @media (max-width: 600px) {
            .form-body { padding: 0 1.5rem 1.5rem; }
        }
        
        /* 區塊標題 */
        .section-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-blue);
            margin: 2rem 0 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--light-blue);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        /* 表單列與網格 */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.25rem;
            margin-bottom: 1.25rem;
        }
        @media (max-width: 600px) {
            .form-grid { grid-template-columns: 1fr; }
        }
        .form-row {
            margin-bottom: 1.25rem;
            display: flex;
            flex-direction: column;
        }
        
        /* 標籤 */
        label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-main);
            font-size: 0.9rem;
        }
        .required-mark {
            color: var(--danger-red);
            font-weight: bold;
            margin-left: 2px;
        }

        /* 類型選取 (Radio Buttons as Tiles) */
        .type-selector {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .type-tile {
            position: relative;
            cursor: pointer;
        }
        .type-tile input[type="radio"] {
            position: absolute;
            opacity: 0;
        }
        .tile-content {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 1.25rem;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            background: #fff;
            transition: all 0.2s ease;
            font-weight: 600;
            color: var(--text-muted);
            font-size: 1.1rem;
        }
        .type-tile:hover .tile-content {
            border-color: #a5b4fc;
            background: #f8fafc;
            color: #4f46e5;
        }
        .type-tile input[type="radio"]:checked + .tile-content {
            border-color: var(--primary-blue);
            background: var(--light-blue);
            color: var(--primary-blue);
            box-shadow: inset 0 0 0 1px var(--primary-blue);
        }
        .type-tile input[type="radio"]:focus-visible + .tile-content {
            box-shadow: 0 0 0 3px var(--focus-ring);
        }

        /* 輸入框樣式 */
        input[type="text"],
        input[type="email"],
        select,
        textarea {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 0.95rem;
            color: var(--text-main);
            transition: all 0.2s;
            box-sizing: border-box;
            background-color: #fafafa;
        }
        input[type="text"]:focus,
        input[type="email"]:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: var(--primary-blue);
            background-color: #fff;
            box-shadow: 0 0 0 3px var(--focus-ring);
        }
        input[readonly] {
            background-color: #e5e7eb;
            color: #4b5563;
            cursor: not-allowed;
            border-color: var(--border-color);
        }
        
        /* 檔案上傳樣式 */
        .file-upload-wrapper {
            position: relative;
            border: 2px dashed #9ca3af;
            border-radius: 8px;
            padding: 2.5rem 1rem;
            text-align: center;
            background-color: #f9fafb;
            transition: all 0.2s;
            cursor: pointer;
        }
        .file-upload-wrapper:hover {
            border-color: var(--primary-blue);
            background-color: var(--light-blue);
        }
        .file-upload-wrapper input[type="file"] {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }
        .file-icon {
            font-size: 2.5rem;
            color: #9ca3af;
            margin-bottom: 0.75rem;
            transition: color 0.2s;
        }
        .file-upload-wrapper:hover .file-icon {
            color: var(--primary-blue);
        }
        .attachment-hint {
            margin-top: 0.5rem;
            font-size: 0.85rem;
            color: var(--text-muted);
        }
        .selected-files {
            margin-top: 12px;
            font-weight: 600;
            color: var(--primary-blue);
        }

        /* 警告與成功訊息 */
        .alert-box {
            padding: 1rem 1.25rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-error {
            background-color: #fef2f2;
            color: var(--danger-red);
            border-left: 4px solid var(--danger-red);
        }
        .alert-success {
            background-color: #ecfdf5;
            color: #059669;
            border-left: 4px solid #059669;
        }

        /* 按鈕區 */
        .form-actions {
            margin-top: 2.5rem;
            display: flex;
            gap: 1rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--light-blue);
        }
        .btn {
            flex: 1;
            padding: 0.85rem;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease-in-out;
            text-align: center;
        }
        .btn-submit {
            background-color: var(--primary-blue);
            color: #fff;
            box-shadow: 0 4px 6px -1px rgba(0, 86, 179, 0.2);
        }
        .btn-submit:hover {
            background-color: #004494;
            transform: translateY(-2px);
            box-shadow: 0 6px 8px -1px rgba(0, 86, 179, 0.3);
        }
        .btn-submit:active {
            transform: translateY(0);
        }
        .btn-cancel {
            background-color: #fff;
            color: var(--text-main);
            border: 1px solid var(--border-color);
        }
        .btn-cancel:hover {
            background-color: #f3f4f6;
        }

        /* 隱藏面板過場 */
        .dynamic-panel {
            animation: fadeIn 0.3s ease-in-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .consent-label {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            font-weight: normal;
            background: #fffbeb;
            border: 1px solid #fde68a;
            color: #92400e;
            padding: 1rem 1.25rem;
            border-radius: 8px;
            cursor: pointer;
            margin-top: 2rem;
            transition: all 0.2s;
        }
        .consent-label:hover {
            background: #fef3c7;
        }
        .consent-label input {
            margin-top: 3px;
            transform: scale(1.2);
            cursor: pointer;
        }
    </style>
    <script>
        function toggleTargetFields() {
            const type = document.querySelector('input[name="target_type"]:checked').value;
            document.getElementById('equipment-only-fields').style.display = type === 'equipment' ? 'block' : 'none';
            document.getElementById('space-only-fields').style.display = type === 'space' ? 'block' : 'none';
        }
        function filterEquipments() {}
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
            
            sel.innerHTML = '<option value="">-- 選擇場地分類 --</option>';

            categories.forEach(cat => {
                const opt = document.createElement('option');
                opt.value = cat;
                opt.textContent = cat;
                sel.appendChild(opt);
            });
            
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
            
            if (document.getElementById('eq_category').value !== '') {
                filterEquipments();
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
                <button class="nav-btn" onclick="location.href='index.php'"><i class="fas fa-home"></i> 回首頁</button>
                <button class="nav-btn" onclick="location.href='logout.php'"><i class="fas fa-sign-out-alt"></i> 登出</button>
            </div>
        </nav>

        <main class="main-content">
            <div class="card maintenance-card">
                <div class="card-header">
                    <h2><i class="fas fa-tools"></i> 設備與場地報修單</h2>
                    <p class="hint">請詳細描述有問題的對象與狀況<br>以利維護團隊快速釐清並進行修復，感謝您的協助！</p>
                </div>

                <div class="form-body">
                    <?php if (!empty($errors)) { ?>
                        <div class="alert-box alert-error">
                            <i class="fas fa-exclamation-circle fa-lg"></i>
                            <div><?php echo htmlspecialchars(implode(' / ', $errors), ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                    <?php } ?>

                    <?php if ($success !== '') { ?>
                        <div class="alert-box alert-success">
                            <i class="fas fa-check-circle fa-lg"></i>
                            <div><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                    <?php } ?>

                    <form method="post" action="report_maintenance.php" enctype="multipart/form-data">
                        
                        <div class="section-title"><i class="fas fa-location-crosshairs"></i> 1. 報修對象</div>
                        <div class="type-selector">
                            <label class="type-tile">
                                <input type="radio" name="target_type" value="equipment" <?php echo (($_POST['target_type'] ?? 'equipment') === 'equipment') ? 'checked' : ''; ?>>
                                <div class="tile-content">
                                    <i class="fas fa-laptop"></i> 硬體 / 器材設備
                                </div>
                            </label>
                            <label class="type-tile">
                                <input type="radio" name="target_type" value="space" <?php echo (($_POST['target_type'] ?? '') === 'space') ? 'checked' : ''; ?>>
                                <div class="tile-content">
                                    <i class="fas fa-door-open"></i> 建築 / 場地空間
                                </div>
                            </label>
                        </div>

                        <!-- 動態面板：器材 -->
                        <div id="equipment-only-fields" class="dynamic-panel">
                            <div class="section-title"><i class="fas fa-laptop-medical"></i> 器材詳細資訊</div>
                            <div class="form-grid">
                                <div class="form-row">
                                    <label for="eq_category">器材分類 <span class="required-mark">*</span></label>
                                    <div class="select-wrapper">
                                        <select id="eq_category" name="eq_category" onchange="document.getElementById('equipment_id').value=''; filterEquipments();">
                                            <option value="">-- 請先選擇器材的分類 --</option>
                                            <?php foreach ($equipmentCategories as $cat) { ?>
                                                <option value="<?php echo htmlspecialchars($cat['equipment_code'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo ((isset($_POST['eq_category']) && $_POST['eq_category'] === $cat['equipment_code']) ? 'selected' : ''); ?>><?php echo htmlspecialchars($cat['equipment_name'], ENT_QUOTES, 'UTF-8'); ?></option>
                                            <?php } ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-row">
                                    <label for="equipment_id">明確的器材編號 <span class="required-mark">*</span></label>
                                    <input type="text" id="equipment_id" name="equipment_id" value="<?php echo htmlspecialchars($_POST['equipment_id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="例如：器材標籤上的財產編號">
                                </div>
                            </div>
                        </div>

                        <!-- 動態面板：場地 -->
                        <div id="space-only-fields" class="dynamic-panel" style="display:none;">
                            <div class="section-title"><i class="fas fa-building"></i> 空間詳細資訊</div>
                            <div class="form-grid">
                                <div class="form-row">
                                    <label for="space_category">場地分類 <span class="required-mark">*</span></label>
                                    <select id="space_category" name="space_category" onchange="document.getElementById('space_id').value=''; filterSpaces();">
                                        <option value="">-- 請先選擇場地分類 --</option>
                                    </select>
                                </div>
                                <div class="form-row">
                                    <label for="space_id">故障發生空間 <span class="required-mark">*</span></label>
                                    <select id="space_id" name="space_id">
                                        <option value="">-- 選擇具體的房間或位置 --</option>
                                        <?php foreach ($spaces as $sp) { 
                                            // Extract the first word before space character as category
                                            $cat = explode(' ', trim($sp['space_name']))[0];
                                        ?>
                                            <option data-category="<?php echo htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars($sp['space_id'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo (isset($_POST['space_id']) && $_POST['space_id'] === $sp['space_id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($sp['space_name'] . ' (' . $sp['space_id'] . ')', ENT_QUOTES, 'UTF-8'); ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                            </div>
                            <div class="form-row">
                                <label for="repair_category">損壞類型 <span class="required-mark">*</span></label>
                                <select id="repair_category" name="repair_category" onchange="toggleRepairCategoryOther()">
                                    <option value="">-- 請大略判斷損壞所屬的類別 --</option>
                                    <?php
                                    $cats = ['建築(壁癌)','建築(木作)','建築(天花板)','建築(窗戶)','電器(插座)','電器(電燈)','電器(冷氣)','電器(音響/投影機)','水類(飲水機)','水類(給水/排水/積水/漏水)','水類(馬桶/洗手台/水龍頭)','其他'];
                                    foreach ($cats as $c) {
                                        $selected = (isset($_POST['repair_category']) && $_POST['repair_category'] === $c) ? 'selected' : '';
                                        echo '<option value="' . htmlspecialchars($c, ENT_QUOTES, 'UTF-8') . '" ' . $selected . '>' . htmlspecialchars($c, ENT_QUOTES, 'UTF-8') . '</option>';
                                    }
                                    ?>
                                </select>
                                <div id="repair_category_other_wrap" style="margin-top:10px; display:<?php echo (($_POST['repair_category'] ?? '') === '其他') ? 'block' : 'none'; ?>">
                                    <input type="text" id="repair_category_other" name="repair_category_other" value="<?php echo htmlspecialchars($_POST['repair_category_other'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="請大略描述其他的裝修類別名稱">
                                </div>
                            </div>
                            <div class="form-grid">
                                <div class="form-row" style="grid-column: 1 / -1;">
                                    <label for="repair_item">精確維修項目名稱位置</label>
                                    <input id="repair_item" name="repair_item" type="text" value="<?php echo htmlspecialchars($_POST['repair_item'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="例如：地下室 - 演講廳 - 左後方講台的開關">
                                </div>
                                <div class="form-row" style="grid-column: 1 / -1; display:none;">
                                    <!-- Hide the redundant location_input to clean up UI, as repair_item already asks for detailed location -->
                                    <input id="location_input" name="location_input" type="hidden" value="<?php echo htmlspecialchars($_POST['location_input'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                            </div>
                        </div>

                        <div class="section-title"><i class="fas fa-clipboard-list"></i> 2. 描述損壞狀態</div>
                        <div class="form-row">
                            <label for="damage_detail">損壞情況詳細說明 <span class="required-mark">*</span></label>
                            <textarea id="damage_detail" name="damage_detail" rows="4" placeholder="舉例：按下開關但是投影機無法通電啟動... 盡量說明完整發生過程，維修人員才好評估！"><?php echo htmlspecialchars($_POST['damage_detail'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                        
                        <div class="form-row">
                            <label>上傳佐證照片或檔案</label>
                            <div class="file-upload-wrapper">
                                <i class="fas fa-cloud-upload-alt file-icon"></i>
                                <div style="font-weight: 600; color: var(--primary-blue); font-size:1.1rem; margin-bottom:4px;">點擊此處 或 拖曳檔案來上傳</div>
                                <div class="attachment-hint">輔助照片有助於更快速找到問題。<br>最多支援 5 個檔案，單一檔案上限 10 MB (支援 JPG / PNG / GIF / PDF)</div>
                                <input id="attachments" name="attachments[]" type="file" multiple accept="image/*,.pdf">
                                <div id="attachments-hint" class="selected-files"></div>
                            </div>
                        </div>

                        <div class="form-row">
                            <label for="other_problems">額外補充與其他備註 (選填)</label>
                            <textarea id="other_problems" name="other_problems" rows="2" placeholder="是否有其他想交代管理員的事項呢？"><?php echo htmlspecialchars($_POST['other_problems'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>

                        <div class="section-title"><i class="fas fa-user-edit"></i> 3. 聯絡人資訊</div>
                        <div class="form-grid">
                            <div class="form-row">
                                <label for="name">申請人姓名 <span class="required-mark">*</span></label>
                                <input id="name" name="name" type="text" value="<?php echo htmlspecialchars($_POST['name'] ?? ($_SESSION['full_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required readonly>
                            </div>
                            <div class="form-row">
                                <label for="reporter_id">學號 / 員工編號 <span class="required-mark">*</span></label>
                                <input id="reporter_id" name="reporter_id" type="text" value="<?php echo htmlspecialchars($_POST['reporter_id'] ?? $_SESSION['user_id'], ENT_QUOTES, 'UTF-8'); ?>" required readonly>
                            </div>
                            <div class="form-row">
                                <label for="email">狀態回報信箱</label>
                                <input id="email" name="email" type="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ($_SESSION['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="我們將優先寄送信件到這裡！">
                            </div>
                            <div class="form-row">
                                <label for="phone">聯絡電話</label>
                                <input id="phone" name="phone" type="text" value="<?php echo htmlspecialchars($_POST['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="分機或手機">
                            </div>
                            <div class="form-row" style="grid-column: 1 / -1;">
                                <label for="unit">所屬單位 (院系/社團/行政處室)</label>
                                <input id="unit" name="unit" type="text" value="<?php echo htmlspecialchars($_POST['unit'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="協助我們能夠迅速聯繫單位的窗口">
                            </div>
                        </div>

                        <label class="consent-label">
                            <input id="consent" type="checkbox" name="consent" <?php echo isset($_POST['consent']) ? 'checked' : ''; ?>>
                            <div>
                                <span style="display:block; font-weight:600; margin-bottom:2px;">請同意個資蒐集保護聲明 <span class="required-mark">*</span></span>
                                <span style="font-size:0.9rem; opacity:0.9;">本表單因報修處理之需要，將蒐集上述您填寫的學號與聯絡資訊，僅限輔大課指組維修追蹤使用，絕不外流。勾選即代表同意。</span>
                            </div>
                        </label>

                        <div class="form-actions">
                            <button class="btn btn-cancel" type="button" onclick="location.href='index.php'"><i class="fas fa-times"></i> 取消返回</button>
                            <button class="btn btn-submit" type="submit"><i class="fas fa-paper-plane"></i> 立即送出報修單</button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>

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
                    hint.textContent = '';
                    return;
                }
                if (files.length > maxFiles) {
                    hint.innerHTML = `<span style="color:var(--danger-red);"><i class="fas fa-times-circle"></i> 上傳失敗！已超過選取上限 ${maxFiles} 個檔案。</span>`;
                    attachments.value = ''; // clear
                } else {
                    hint.innerHTML = `<i class="fas fa-check-circle"></i> 成功選取 ${files.length} 個附件！`;
                }
            });

            form.addEventListener('submit', function(e){
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
                    alert('請同意個資聲明（勾選最下方的黃色框框）！');
                    e.preventDefault();
                    return false;
                }
                if (name === '') { alert('請輸入您的姓名。'); e.preventDefault(); return false; }
                if (reporter === '') { alert('請輸入您的學號/員工編號。'); e.preventDefault(); return false; }
                
                if (targetType === 'equipment') {
                    if (!equipmentCategory) { alert('抱歉！請務必「選擇器材分類」。'); e.preventDefault(); return false; }
                    if (!equipmentId) { alert('抱歉！請務必填寫「器材編號」。'); e.preventDefault(); return false; }
                    if (damage === '') { alert('請填寫損壞情況的詳細說明，幫助維修團隊了解狀況。'); e.preventDefault(); return false; }
                } else if (targetType === 'space') {
                    if (!spaceCategory) { alert('抱歉！請「選擇場地分類」。'); e.preventDefault(); return false; }
                    if (!spaceId) { alert('抱歉！請「選擇故障發生空間」。'); e.preventDefault(); return false; }
                    if (!repairCategorySelected) { alert('抱歉！請大略「選擇損壞類型」。'); e.preventDefault(); return false; }
                    if (repairItem === '' && damage === '') {
                        alert('請填寫維修項目或是損壞情況的詳細說明！');
                        e.preventDefault();
                        return false;
                    }
                }

                const files = attachments.files;
                if (files && files.length > 0) {
                    if (files.length > maxFiles) {
                        alert(`最多只能上傳 ${maxFiles} 個附件檔案喔！`);
                        e.preventDefault();
                        return false;
                    }
                    for (let i=0;i<files.length;i++){
                        const f = files[i];
                        if (f.size > maxSize) {
                            alert(`檔案「${f.name}」已超過單一檔案 10MB 的上限，請縮小後再嘗試。`);
                            e.preventDefault();
                            return false;
                        }
                        if (!allowed.includes(f.type)) {
                            alert(`檔案「${f.name}」的格式不被允許，請上傳圖示或 PDF 檔案。`);
                            e.preventDefault();
                            return false;
                        }
                    }
                }
                
                // UX Feedback
                const btn = document.querySelector('.btn-submit');
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 傳送中...';
                btn.style.pointerEvents = 'none';
            });
        })();
    </script>
</body>
</html>
EOD;

$filename = 'report_maintenance.php';
$content = file_get_contents($filename);
$pos = strpos($content, '<!DOCTYPE html>');

if ($pos !== false) {
    $header = substr($content, 0, $pos);
    file_put_contents($filename, $header . $html);
    echo "Updated main file.\n";
} else {
    echo "Could not find HTML delimiter.\n";
}
?>