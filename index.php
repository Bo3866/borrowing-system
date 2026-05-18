<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config/database.php';

$dbConnected = false;
$dbStatusText = '連線失敗';
$isLoggedIn = isset($_SESSION['user_id']);
$displayName = (string)($_SESSION['full_name'] ?? '訪客');
$currentRole = (string)($_SESSION['role_name'] ?? '');
// Treat roles a, b, c as equivalent to role 3 for manager/admin interfaces
$isManager = in_array($currentRole, ['2', '3', 'a', 'b', 'c'], true);

$periodSlots = [
    'D0' => ['label' => '日間第0節', 'start' => '07:10:00', 'end' => '08:00:00'],
    'D1' => ['label' => '日間第1節', 'start' => '08:10:00', 'end' => '09:00:00'],
    'D2' => ['label' => '日間第2節', 'start' => '09:10:00', 'end' => '10:00:00'],
    'D3' => ['label' => '日間第3節', 'start' => '10:10:00', 'end' => '11:00:00'],
    'D4' => ['label' => '日間第4節', 'start' => '11:10:00', 'end' => '12:00:00'],
    'DN' => ['label' => '日間第5節', 'start' => '12:40:00', 'end' => '13:30:00'],
    'D5' => ['label' => '日間第6節', 'start' => '13:40:00', 'end' => '14:30:00'],
    'D6' => ['label' => '日間第7節', 'start' => '14:40:00', 'end' => '15:30:00'],
    'D7' => ['label' => '日間第8節', 'start' => '15:40:00', 'end' => '16:30:00'],
    'D8' => ['label' => '夜間第1節', 'start' => '16:40:00', 'end' => '17:30:00'],
    'E0' => ['label' => '夜間第2節', 'start' => '17:40:00', 'end' => '18:30:00'],
    'E1' => ['label' => '夜間第3節', 'start' => '18:40:00', 'end' => '19:30:00'],
    'E2' => ['label' => '夜間第4節', 'start' => '19:35:00', 'end' => '20:20:00'],
    'E3' => ['label' => '夜間第5節', 'start' => '20:30:00', 'end' => '21:20:00'],
    'E4' => ['label' => '夜間第6節', 'start' => '21:25:00', 'end' => '22:10:00'],
];
$periodOrder = array_keys($periodSlots);

$equipmentMap = [];
$spaceMap = [];

$dbError = '';
$link = getMysqliConnection($dbError);

if ($link) {
    $dbConnected = true;
    $dbStatusText = '已連線';

    $equipmentSql = "
        SELECT
            ec.equipment_code,
            ec.equipment_name
        FROM equipment_categories ec
        LEFT JOIN equipments e ON e.equipment_code = ec.equipment_code
        GROUP BY ec.equipment_code, ec.equipment_name
        ORDER BY ec.equipment_code ASC
    ";
    $equipmentResult = mysqli_query($link, $equipmentSql);
    if ($equipmentResult) {
        while ($row = mysqli_fetch_assoc($equipmentResult)) {
            $code = (string)$row['equipment_code'];
            $equipmentMap[$code] = [
                'equipment_code' => $code,
                'equipment_name' => (string)$row['equipment_name'],
            ];
        }
    }

    $spaceSql = "
        SELECT
            s.space_id,
            s.space_name
        FROM spaces s
        WHERE s.space_status IN ('available', '1')
        ORDER BY s.space_id ASC
    ";
    $spaceResult = mysqli_query($link, $spaceSql);
    if ($spaceResult) {
        while ($row = mysqli_fetch_assoc($spaceResult)) {
            $spaceId = (string)$row['space_id'];
            $spaceMap[$spaceId] = [
                'space_id' => $spaceId,
                'space_name' => (string)$row['space_name'],
            ];
        }
    }
} else {
    error_log('Database connection failed: ' . $dbError);
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>校園空間與器材租借系統</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .cal-grid-header { text-align:center; font-weight:bold; color:#64748b; padding:12px; background:#f1f5f9; border-radius:6px; font-size:14px; }
        .cal-day-cell { min-height:85px; border:1px solid #e2e8f0; border-radius:8px; padding:8px; cursor:pointer; transition:all 0.2s; display:flex; flex-direction:column; background:#ffffff; box-shadow:0 1px 2px rgba(0,0,0,0.02); }
        .cal-day-cell:hover { border-color:#3b82f6; box-shadow:0 0 0 2px rgba(59,130,246,0.15); transform:translateY(-1px); }
        .cal-day-cell.empty { background:transparent; border:none; cursor:default; box-shadow:none; }
        .cal-day-cell.empty:hover { transform:none; box-shadow:none; }
        .cal-day-date { font-weight:bold; color:#334155; margin-bottom:5px; font-size:15px; }
        .cal-day-status { font-size:13px; flex:1; display:flex; flex-direction:column; justify-content:center; align-items:center; text-align:center; border-radius:6px; font-weight:bold; padding:4px; }
        .status-full { background:#dcfce7; color:#166534; border:1px solid #bbf7d0; }
        .status-partial { background:#fef9c3; color:#854d0e; border:1px solid #fef08a; }
        .status-none { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }
        .status-unknown { background:#f1f5f9; color:#64748b; border:1px solid #e2e8f0; }
        .period-list { display:grid; grid-template-columns:repeat(auto-fill, minmax(130px, 1fr)); gap:10px; }
        .period-item { padding:12px; border-radius:8px; font-size:13px; text-align:center; box-shadow:0 1px 3px rgba(0,0,0,0.05); transition:transform 0.2s; }
        .period-item:hover { transform:translateY(-2px); }
        .calendar-card { width:100%; border-top:4px solid #3b82f6; }
    </style>
</head>
<body>
    <div class="container">
        <!-- 導航欄 -->
        <nav class="navbar">
            <div class="navbar-brand">
                <h1>📚 校園資源租借系統</h1>
            </div>
            <div class="navbar-menu">
                <button class="nav-btn" onclick="navigateTo('dashboard')">首頁</button>
                <button class="nav-btn" onclick="handleBorrowClick(event)">我要租借</button>
                <button class="nav-btn" onclick="location.href='return_management.php'">我的申請</button>
                
                <?php if ($isManager) { ?>
                    <button class="nav-btn" onclick="location.href='approve.php'">審核面板</button>
                    <?php if (in_array($currentRole, ['2','3'], true)) { ?>
                        <button class="nav-btn" id="btnManualRemind" type="button" onclick="handleManualRemindClick(event)">檢查逾期並催繳</button>
                        <button class="nav-btn" onclick="location.href='equipment_inventory.php'">庫存管理</button>
                    <?php } ?>
                <?php if (in_array($currentRole, ['3','a','b','c'], true)) { ?>
                    <button class="nav-btn" onclick="location.href='qr_admin.php'">生成報到 QR</button>
                <?php } ?>
                <?php } ?>
                <?php if ($isManager || (isset($currentRole) && $currentRole === '1')) { ?>
                    <button class="nav-btn" onclick="location.href='report_maintenance.php'">報修</button>
                <?php } ?>
                <?php if ($isLoggedIn) { ?>
                    <button class="nav-btn" type="button" disabled><?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></button>
                    <button class="nav-btn" onclick="location.href='logout.php'">登出</button>
                <?php } else { ?>
                    <button class="nav-btn" onclick="location.href='login.php'">登入</button>
                <?php } ?>
            </div>
        </nav>

        <!-- 主要內容區域 -->
        <main class="main-content">
            <!-- 首頁 Dashboard -->
            <section id="dashboard" class="page active">
                <div class="card calendar-card" style="margin-bottom: 20px;">
                    <div class="cal-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; flex-wrap:wrap; gap:10px;">
                        <div style="flex:1; min-width:250px;">
                            <h3 style="margin:0 0 10px 0; color:var(--primary); display:flex; align-items:center; gap:8px;">
                                📅 可用狀態查詢 (即時計算)
                            </h3>
                            <select id="calItemSelect" style="max-width:300px;">
                                <option value="">-- 請選擇要查詢的項目 --</option>
                                <optgroup label="器材">
                                    <?php foreach ($equipmentMap as $equipment) { echo '<option value="equipment|' . htmlspecialchars($equipment['equipment_code'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($equipment['equipment_name'], ENT_QUOTES, 'UTF-8') . '</option>'; } ?>
                                </optgroup>
                                <optgroup label="場地">
                                    <?php foreach ($spaceMap as $space) { echo '<option value="space|' . htmlspecialchars($space['space_id'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($space['space_name'], ENT_QUOTES, 'UTF-8') . '</option>'; } ?>
                                </optgroup>
                            </select>
                        </div>
                        <div style="display:flex; align-items:center; gap:15px;">
                            <button type="button" id="calPrevBtn" class="btn-secondary" style="padding:6px 12px; font-weight:bold;">&lt;</button>
                            <h4 id="calMonthLabel" style="margin:0; min-width:110px; text-align:center; color:#333;">---- 年 - 月</h4>
                            <button type="button" id="calNextBtn" class="btn-secondary" style="padding:6px 12px; font-weight:bold;">&gt;</button>
                        </div>
                    </div>

                    <div id="calLoading" style="display:none; text-align:center; padding:20px; color:#64748b; font-weight:bold;">資料讀取與計算中...</div>
                    <div id="calEmptyMsg" style="text-align:center; padding:20px; color:#64748b;">請從左上方下拉選單選擇想要查詢的項目</div>

                    <div id="calContainer" style="display:none;">
                        <div style="display:grid; grid-template-columns:repeat(7, 1fr); gap:5px; margin-bottom:5px;">
                            <div class="cal-grid-header">日</div><div class="cal-grid-header">一</div><div class="cal-grid-header">二</div>
                            <div class="cal-grid-header">三</div><div class="cal-grid-header">四</div><div class="cal-grid-header">五</div><div class="cal-grid-header">六</div>
                        </div>
                        <div id="calGrid" style="display:grid; grid-template-columns:repeat(7, 1fr); gap:5px; margin-bottom:15px;"></div>
                        <div id="calDetails" style="display:none; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:15px;">
                            <h4 id="calDetailsTitle" style="margin-top:0; color:#1e293b; border-bottom:1px dashed #cbd5e1; padding-bottom:8px;"></h4>
                            <div id="calDetailsGrid" class="period-list"></div>
                        </div>
                    </div>
                </div>

                <div class="dashboard-grid">
                    <div class="card dashboard-card">
                        <h3>🔍 快速查詢</h3>
                        <p>依日期與資源類型，快速找到能借的器材與空間。</p>
                        <button class="btn-primary" onclick="handleBorrowClick(event)">開始查詢</button>
                    </div>
                    <div class="card dashboard-card">
                        <h3>📝 我的申請</h3>
                        <p>追蹤審核進度，掌握每一筆申請目前在哪個階段。</p>
                        <button class="btn-primary" onclick="location.href='return_management.php'">查看申請</button>
                    </div>
                    <div class="card dashboard-card">
                        <h3>📊 資源趨勢</h3>
                        <p>看本月借用熱點與使用趨勢，安排活動時更有底。</p>
                        <button class="btn-primary" onclick="showStats()">查看統計</button>
                    </div>
                    <div class="card dashboard-card">
                        <h3>⚙️ 系統狀態</h3>
                        <p>目前系統運作順暢，審核與通知服務皆在線。</p>
                        <span class="status-badge">穩定運行</span>
                    </div>
                </div>

                <div class="card dashboard-qr-card">
                    <h3>📷 QR Code 掃碼</h3>
                    <p>使用相機掃描 QR Code。若內容是網址將直接導向，其他內容會顯示在下方。</p>
                    <div class="qr-actions">
                        <button class="btn-primary" type="button" onclick="startQrScan()">開始掃描</button>
                        <button class="btn-secondary" type="button" onclick="stopQrScan()">停止掃描</button>
                    </div>
                    <div id="qr-reader" class="qr-reader" aria-live="polite"></div>
                    <div id="qrStatus" class="qr-status">尚未開始掃描</div>
                    <div id="qrResult" class="qr-result" style="display: none;"></div>
                </div>

                <div class="dashboard-ticker" aria-hidden="true">
                    <div class="ticker-track">
                        <span>場地借用提醒：提前 3 天送審，核准成功率更高。</span>
                        <span>器材借用提醒：高峰時段請提早預約。</span>
                        <span>系統公告：每晚 23:30 進行資料備份，服務不中斷。</span>
                        <span>場地借用提醒：提前 3 天送審，核准成功率更高。</span>
                    </div>
                </div>
            </section>

            <!-- 租借申請頁面 -->
            <section id="borrow" class="page">
                <h2>租借資源</h2>
                
                <!-- 篩選條件 -->
                <div class="filter-section">
                    <h3>篩選資源</h3>
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label>資源類型：</label>
                            <select id="resourceType" onchange="filterResources()">
                                <option value="">全部</option>
                                <option value="space">空間</option>
                                <option value="equipment">器材</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>租借日期：</label>
                            <input type="date" id="borrowDate" onchange="filterResources()">
                        </div>
                        <div class="filter-group">
                            <label>搜尋：</label>
                            <input type="text" id="searchKeyword" placeholder="搜尋資源名稱" onkeyup="filterResources()">
                        </div>
                    </div>
                </div>

                <!-- 可用資源列表 -->
                <div class="resources-section">
                    <h3>可用資源</h3>
                    <div id="resourcesList" class="resources-grid">
                        <!-- 資源卡片將由JavaScript填充 -->
                    </div>
                </div>
            </section>

            <!-- 對話框：申請表單 -->
            <div id="borrowModal" class="modal">
                <div class="modal-content">
                    <span class="close" onclick="closeBorrowModal()">&times;</span>
                    <h3>租借申請表</h3>
                    <form id="borrowForm" onsubmit="submitBorrowApplication(event)">
                        <div class="form-group">
                            <label>資源名稱：</label>
                            <input type="text" id="modalResourceName" readonly>
                        </div>
                        <div class="form-group">
                            <label>申請人：<span style="color: red;">*必填</span></label>
                            <input type="text" id="applicantName" placeholder="請輸入您的名字" required>
                        </div>
                        <div class="form-group">
                            <label>申請人聯絡電話：<span style="color: red;">*必填</span></label>
                            <input type="tel" id="applicantPhone" placeholder="例：09XXXXXXXXX" pattern="^09\d{8}$|^\d{10}$" required>
                        </div>
                        <div class="form-group">
                            <label>租借開始日期：<span style="color: red;">*必填</span></label>
                            <input type="datetime-local" id="startDateTime" required>
                        </div>
                        <div class="form-group">
                            <label>租借結束日期：<span style="color: red;">*必填</span></label>
                            <input type="datetime-local" id="endDateTime" required>
                        </div>
                        <div class="form-group">
                            <label>用途說明：<span style="color: red;">*必填</span></label>
                            <textarea id="purpose" required rows="4" placeholder="請詳細說明使用目的"></textarea>
                        </div>
                        <div class="form-group">
                            <label>活動企劃書：</label>
                            <input type="file" id="planDocument" accept=".pdf" onchange="validatePlanDocument()">
                            <small>✓ 格式：PDF 檔案 | ✓ 大小限制：1MB | 提示：上傳活動計畫書可提高核准率</small>
                            <div id="planDocumentError" style="color: #e74c3c; margin-top: 0.5rem; display: none;"></div>
                        </div>
                        <div class="form-buttons">
                            <button type="submit" class="btn-primary">提交申請</button>
                            <button type="button" class="btn-secondary" onclick="saveBorrowDraft()" style="background: #9b59b6;">暫存草稿</button>
                            <button type="button" class="btn-secondary" onclick="closeBorrowModal()">取消</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- 對話框：故障報告 -->
            <div id="maintenanceModal" class="modal">
                <div class="modal-content">
                    <span class="close" onclick="closeMaintenanceModal()">&times;</span>
                    <h3>器材/場地故障報告</h3>
                    <form id="maintenanceForm" onsubmit="submitMaintenanceReport(event)">
                        <div class="form-group">
                            <label>資源名稱：</label>
                            <input type="text" id="maintenanceResourceName" readonly>
                        </div>
                        <div class="form-group">
                            <label>損壞描述：<span style="color: red;">*必填</span></label>
                            <textarea id="maintenanceDescription" required rows="4" placeholder="請詳細描述故障情況"></textarea>
                        </div>
                        <div class="form-group">
                            <label>上傳照片（可選）：</label>
                            <input type="file" id="maintenancePhoto" accept="image/*">
                        </div>
                        <div class="form-buttons">
                            <button type="submit" class="btn-primary">提交報修</button>
                            <button type="button" class="btn-secondary" onclick="closeMaintenanceModal()">取消</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- 我的申請頁面 -->
            <section id="myapplications" class="page">
                <h2>我的申請</h2>
                <div class="filter-section">
                    <label>篩選狀態：</label>
                    <select id="statusFilter" onchange="filterApplications()">
                        <option value="">全部</option>
                        <option value="pending">審核中</option>
                        <option value="approved">已核准</option>
                        <option value="rejected">審核未通過</option>
                        <option value="completed">已完成</option>
                    </select>
                </div>
                <div id="applicationsList" class="applications-list">
                    <!-- 申請列表將由JavaScript填充 -->
                </div>
            </section>
                <!-- 統計標籤 -->
                <div id="statisticsTab" class="tab-content">
                    <div class="statistics-grid">
                        <div class="stat-card">
                            <h4>總租借次數</h4>
                            <p id="totalBorrowCount" class="stat-number">0</p>
                        </div>
                        <div class="stat-card">
                            <h4>本月租借</h4>
                            <p id="monthlyBorrowCount" class="stat-number">0</p>
                        </div>
                        <div class="stat-card">
                            <h4>平均滿意度</h4>
                            <p id="averageSatisfaction" class="stat-number">0%</p>
                        </div>
                        <div class="stat-card">
                            <h4>設備故障率</h4>
                            <p id="equipmentFailureRate" class="stat-number">0%</p>
                        </div>
                    </div>
                </div>
            </section>

            <?php if ($isManager) { ?>
            <!-- 審核面板 -->
            <section id="admin" class="page">
                <h2>審核面板</h2>
                <div class="admin-tabs">
                    <button class="tab-btn active" onclick="switchAdminTab('pending')">待審核</button>
                    <button class="tab-btn" onclick="switchAdminTab('history')">審核紀錄</button>
                </div>

                <!-- 待審核申請 -->
                <div id="pendingTab" class="tab-content active">
                    <div style="margin-bottom: 1rem; padding: 1rem; background: #ecf0f1; border-radius: 8px;">
                        <strong>💡 提示：</strong> 點擊下方的「核准」或「拒絕」按鈕即可快速審核申請。系統會自動產生數位審核紀錄並通知申請人。
                    </div>
                    <div id="pendingApplicationsList" class="admin-applications-list">
                        <!-- 待審核申請將由JavaScript填充 -->
                    </div>
                </div>

                <!-- 審核紀錄 -->
                <div id="historyTab" class="tab-content">
                    <table class="review-table">
                        <thead>
                            <tr>
                                <th>申請ID</th>
                                <th>申請人</th>
                                <th>資源</th>
                                <th>審核狀態</th>
                                <th>審核時間</th>
                            </tr>
                        </thead>
                        <tbody id="reviewHistoryBody">
                            <!-- 審核紀錄將由JavaScript填充 -->
                        </tbody>
                    </table>
                </div>
            </section>
            <?php } ?>
        </main>

        <!-- 頁腳 -->
        <footer class="footer">
            <p>&copy; 2026 校園資源租借系統。所有權利保留。</p>
        </footer>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <script>
        let qrScanner = null;
        let qrIsScanning = false;

        function setQrStatus(message, isError = false) {
            const statusEl = document.getElementById('qrStatus');
            if (!statusEl) {
                return;
            }

            statusEl.textContent = message;
            statusEl.classList.toggle('error', isError);
        }

        function setQrResult(message) {
            const resultEl = document.getElementById('qrResult');
            if (!resultEl) {
                return;
            }

            resultEl.style.display = 'block';
            resultEl.textContent = `掃描結果：${message}`;
        }

        function getBorrowRedirectUrl(qrText) {
            const payload = qrText.trim();
            if (!payload.startsWith('borrow:')) {
                return null;
            }

            const queryString = payload.slice(7).trim();
            if (!queryString) {
                return 'borrow.php';
            }

            return `borrow.php?${queryString}`;
        }

        function handleQrContent(decodedText) {
            const value = decodedText.trim();
            setQrResult(value);

            if (/^https?:\/\//i.test(value)) {
                setQrStatus('偵測到網址，將自動導向...');
                window.location.href = value;
                return;
            }

            const borrowUrl = getBorrowRedirectUrl(value);
            if (borrowUrl !== null) {
                setQrStatus('偵測到借用參數，將導向借用頁面...');
                window.location.href = borrowUrl;
                return;
            }

            setQrStatus('已讀取 QR Code 內容。');
        }

        async function startQrScan() {
            if (qrIsScanning) {
                setQrStatus('掃描器已在運作中。');
                return;
            }

            const qrReaderEl = document.getElementById('qr-reader');
            const qrResultEl = document.getElementById('qrResult');

            if (!qrReaderEl || !qrResultEl) {
                return;
            }

            qrResultEl.style.display = 'none';
            qrResultEl.textContent = '';

            if (typeof Html5Qrcode === 'undefined') {
                setQrStatus('掃碼元件載入失敗，請重新整理頁面。', true);
                return;
            }

            qrScanner = new Html5Qrcode('qr-reader');
            setQrStatus('正在啟動相機...');

            try {
                await qrScanner.start(
                    { facingMode: 'environment' },
                    { fps: 10, qrbox: { width: 240, height: 240 } },
                    (decodedText) => {
                        handleQrContent(decodedText);
                        stopQrScan();
                    },
                    () => {
                        // ignore continuous decode errors while scanning
                    }
                );
                qrIsScanning = true;
                setQrStatus('掃描中，請將 QR Code 對準框內。');
            } catch (error) {
                setQrStatus('無法啟動相機，請檢查權限與裝置。', true);
                if (qrScanner) {
                    qrScanner.clear();
                    qrScanner = null;
                }
                qrIsScanning = false;
                console.error(error);
            }
        }

        async function stopQrScan() {
            if (!qrScanner) {
                qrIsScanning = false;
                setQrStatus('掃描器已停止。');
                return;
            }

            try {
                if (qrIsScanning) {
                    await qrScanner.stop();
                }
                await qrScanner.clear();
            } catch (error) {
                console.error(error);
            }

            qrScanner = null;
            qrIsScanning = false;
            setQrStatus('掃描器已停止。');
        }

        window.addEventListener('beforeunload', () => {
            if (qrIsScanning) {
                stopQrScan();
            }
        });

        function handleBorrowClick(event) {
            if (event) {
                event.preventDefault();
            }

            const isLoggedIn = <?php echo $isLoggedIn ? 'true' : 'false'; ?>;

            if (!isLoggedIn) {
                const shouldGoLogin = window.confirm('目前尚未登入，無法借用。是否前往登入頁？');
                if (shouldGoLogin) {
                    window.location.href = 'login.php?next=borrow.php';
                }
                return false;
            }

            window.location.href = 'borrow.php';

            return false;
        }

        function handleManualRemindClick(event) {
            if (event) {
                event.preventDefault();
            }

            const btn = document.getElementById('btnManualRemind');
            if (!btn) {
                return false;
            }

            if (!window.confirm('確定要立即檢查逾期並發送催繳通知嗎？')) {
                return false;
            }

            const originalText = btn.textContent;
            btn.disabled = true;
            btn.textContent = '處理中...';

            fetch('manual_remind.php', {
                method: 'POST',
                credentials: 'same-origin'
            })
                .then(response => response.json())
                .then(result => {
                    if (result && result.ok) {
                        const output = (result.output || '').trim();
                        window.alert(output || '催繳檢查完成。');
                    } else {
                        window.alert('執行失敗：' + (result && result.error ? result.error : '未知錯誤'));
                    }
                })
                .catch(error => {
                    console.error(error);
                    window.alert('執行失敗：' + error.message);
                })
                .finally(() => {
                    btn.disabled = false;
                    btn.textContent = originalText;
                });

            return false;
        }
    </script>
    <script>
        (function () {
            const calData = {
                currentDate: new Date(),
                selectedItemType: null,
                selectedItemId: null,
                selectedItemName: null,
                totalCapacity: 0,
                reservations: [],
                periodSlots: <?php echo json_encode($periodSlots); ?>,
                periodOrder: <?php echo json_encode($periodOrder); ?>
            };

            const calUI = {
                select: document.getElementById('calItemSelect'),
                prevBtn: document.getElementById('calPrevBtn'),
                nextBtn: document.getElementById('calNextBtn'),
                monthLabel: document.getElementById('calMonthLabel'),
                loading: document.getElementById('calLoading'),
                emptyMsg: document.getElementById('calEmptyMsg'),
                container: document.getElementById('calContainer'),
                grid: document.getElementById('calGrid'),
                details: document.getElementById('calDetails'),
                detailsTitle: document.getElementById('calDetailsTitle'),
                detailsGrid: document.getElementById('calDetailsGrid')
            };

            calData.currentDate.setDate(1);

            function updateCalendarHeader() {
                const y = calData.currentDate.getFullYear();
                const m = calData.currentDate.getMonth() + 1;
                if (calUI.monthLabel) calUI.monthLabel.textContent = `${y} 年 ${m} 月`;
            }

            function calculateAvailableForPeriod(dateStr, startPeriodTime, endPeriodTime) {
                let used = 0;

                if (calData.selectedItemType === 'equipment') {
                    calData.reservations.forEach(r => {
                        const rDate = r.start.substring(0, 10);
                        if (rDate === dateStr) {
                            used += r.qty;
                        }
                    });
                } else {
                    const pStart = new Date(`${dateStr}T${startPeriodTime}`).getTime();
                    const pEnd = new Date(`${dateStr}T${endPeriodTime}`).getTime();

                    calData.reservations.forEach(r => {
                        const rStart = new Date(r.start.replace(' ', 'T')).getTime();
                        const rEnd = new Date(r.end.replace(' ', 'T')).getTime();

                        if (!(pEnd <= rStart || pStart >= rEnd)) {
                            used += r.qty;
                        }
                    });
                }

                const finalAvail = calData.totalCapacity - used;
                return Math.max(0, finalAvail);
            }

            function calculateDayStatus(dateStr) {
                const targetDateObj = new Date(dateStr + 'T00:00:00');
                const today = new Date();
                today.setHours(0, 0, 0, 0);
                if (targetDateObj < today) {
                    return { text: '已過去', color: 'unknown' };
                }

                let minAvail = calData.totalCapacity;
                let maxAvail = 0;

                calData.periodOrder.forEach(pCode => {
                    const pData = calData.periodSlots[pCode];
                    const avail = calculateAvailableForPeriod(dateStr, pData.start, pData.end);
                    if (avail < minAvail) minAvail = avail;
                    if (avail > maxAvail) maxAvail = avail;
                });

                if (calData.totalCapacity === 0) {
                    return { text: '無法出借', color: 'none' };
                }

                if (calData.selectedItemType === 'equipment') {
                    if (minAvail === 0) {
                        return { text: '已借出', color: 'none' };
                    }
                    return { text: `還可借 ${minAvail} 件`, color: 'full' };
                }

                if (minAvail === calData.totalCapacity) {
                    return { text: '全天可借', color: 'full' };
                } else if (minAvail === 0 && maxAvail === 0) {
                    return { text: '已借滿', color: 'none' };
                }

                return { text: '部分時段', color: 'partial' };
            }

            function renderCalendar() {
                calUI.grid.innerHTML = '';

                const y = calData.currentDate.getFullYear();
                const m = calData.currentDate.getMonth();
                const firstDayObj = new Date(y, m, 1);
                const lastDayObj = new Date(y, m + 1, 0);
                let currentDayOfWeek = firstDayObj.getDay();
                const daysInMonth = lastDayObj.getDate();

                for (let i = 0; i < currentDayOfWeek; i++) {
                    const emptyCell = document.createElement('div');
                    emptyCell.className = 'cal-day-cell empty';
                    calUI.grid.appendChild(emptyCell);
                }

                for (let d = 1; d <= daysInMonth; d++) {
                    const dateStr = `${y}-${String(m + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
                    const cell = document.createElement('div');
                    cell.className = 'cal-day-cell';

                    const dayStatus = calculateDayStatus(dateStr);

                    const dateHeader = document.createElement('div');
                    dateHeader.className = 'cal-day-date';
                    dateHeader.textContent = String(d);

                    const statusBox = document.createElement('div');
                    statusBox.className = `cal-day-status status-${dayStatus.color}`;
                    statusBox.innerHTML = `<span>${dayStatus.text}</span>`;

                    cell.appendChild(dateHeader);
                    cell.appendChild(statusBox);

                    if (calData.selectedItemType === 'space') {
                        cell.onclick = () => showDayDetails(dateStr);
                    }

                    calUI.grid.appendChild(cell);
                }
            }

            function showDayDetails(dateStr) {
                calUI.details.style.display = 'block';
                calUI.detailsTitle.textContent = `【${calData.selectedItemName}】 ${dateStr} 詳細時段狀態`;
                calUI.detailsGrid.innerHTML = '';

                calData.periodOrder.forEach(pCode => {
                    const pData = calData.periodSlots[pCode];
                    const avail = calculateAvailableForPeriod(dateStr, pData.start, pData.end);

                    const itemDiv = document.createElement('div');
                    itemDiv.className = 'period-item';

                    let bgColor = '#dcfce7';
                    let textColor = '#166534';
                    let text = `剩餘 ${avail}`;

                    if (avail === 0) {
                        bgColor = '#fee2e2';
                        textColor = '#991b1b';
                        text = '已滿';
                    } else if (avail < calData.totalCapacity) {
                        bgColor = '#fef9c3';
                        textColor = '#854d0e';
                    }

                    if (calData.selectedItemType === 'space') {
                        text = avail === 0 ? '已預約' : '可借用';
                    }

                    itemDiv.style.backgroundColor = bgColor;
                    itemDiv.style.color = textColor;
                    itemDiv.innerHTML = `
                        <div style="font-weight:bold; margin-bottom:3px;">${pData.label}</div>
                        <div style="font-size:11px; color:#666; margin-bottom:5px;">${pData.start.substring(0,5)} ~ ${pData.end.substring(0,5)}</div>
                        <div style="font-weight:bold; font-size:14px; border-top:1px solid rgba(0,0,0,0.1); padding-top:3px;">${text}</div>
                    `;
                    calUI.detailsGrid.appendChild(itemDiv);
                });

                calUI.details.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }

            function fetchAvailability() {
                calUI.loading.style.display = 'block';
                calUI.grid.style.visibility = 'hidden';
                calUI.details.style.display = 'none';

                const year = calData.currentDate.getFullYear();
                const month = calData.currentDate.getMonth() + 1;

                fetch(`api_get_availability.php?type=${calData.selectedItemType}&id=${calData.selectedItemId}&year=${year}&month=${month}`)
                    .then(res => res.json())
                    .then(data => {
                        calUI.loading.style.display = 'none';
                        calUI.grid.style.visibility = 'visible';
                        if (data.error) {
                            alert('載入失敗：' + data.error);
                            return;
                        }
                        calData.totalCapacity = data.total_capacity || 0;
                        calData.reservations = data.reservations || [];
                        renderCalendar();
                    })
                    .catch(err => {
                        console.error('Calendar Fetch Error:', err);
                        calUI.loading.style.display = 'none';
                        alert('無法讀取即時狀態，請重試。');
                    });
            }

            if (calUI.select) {
                calUI.select.addEventListener('change', function (e) {
                    const val = e.target.value;
                    if (!val) {
                        calData.selectedItemType = null;
                        calData.selectedItemId = null;
                        calData.selectedItemName = null;
                        calUI.emptyMsg.style.display = 'block';
                        calUI.container.style.display = 'none';
                        calUI.details.style.display = 'none';
                        return;
                    }

                    const parts = val.split('|');
                    calData.selectedItemType = parts[0];
                    calData.selectedItemId = parts[1];
                    calData.selectedItemName = e.target.options[e.target.selectedIndex].text;

                    calUI.emptyMsg.style.display = 'none';
                    calUI.container.style.display = 'block';
                    fetchAvailability();
                });
            }

            if (calUI.prevBtn) {
                calUI.prevBtn.addEventListener('click', () => {
                    calData.currentDate.setMonth(calData.currentDate.getMonth() - 1);
                    updateCalendarHeader();
                    if (calData.selectedItemId) fetchAvailability();
                });
            }

            if (calUI.nextBtn) {
                calUI.nextBtn.addEventListener('click', () => {
                    calData.currentDate.setMonth(calData.currentDate.getMonth() + 1);
                    updateCalendarHeader();
                    if (calData.selectedItemId) fetchAvailability();
                });
            }

            updateCalendarHeader();
        })();
    </script>
    <script src="app.js"></script>
</body>
</html>
<?php
// 掛載 Web Cron (背景寄信機制，不寫大學名字版本)
register_shutdown_function(function() {
    if (file_exists(__DIR__ . '/auto_remind.php')) {
        include_once __DIR__ . '/auto_remind.php';
        if (function_exists('run_auto_remind')) {
            // call the function that implements the web cron behavior
            try { run_auto_remind(); } catch (Throwable $e) { error_log('run_auto_remind error: '.$e->getMessage()); }
        }
    }
});
?>
