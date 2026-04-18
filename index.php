<?php
declare(strict_types=1);

session_start();

$dbConnected = false;
$dbStatusText = '連線失敗';
$isLoggedIn = isset($_SESSION['user_id']);
$displayName = (string)($_SESSION['full_name'] ?? '訪客');
$currentRole = (string)($_SESSION['role_name'] ?? '');
$isManager = in_array($currentRole, ['2', '3'], true);

$link = mysqli_connect('localhost', 'root', '', 'borrowing_system', 3307);

if ($link) {
    $dbConnected = true;
    $dbStatusText = '已連線';
    mysqli_set_charset($link, 'utf8mb4');
} else {
    error_log('Database connection failed: ' . mysqli_connect_error());
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>校園空間與器材租借系統</title>
    <link rel="stylesheet" href="styles.css">
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
                <button class="nav-btn" onclick="navigateTo('manage')">資源管理</button>
                <button class="nav-btn" onclick="navigateTo('myapplications')">我的申請</button>
                <button class="nav-btn" onclick="location.href='approve.php'">審核面板</button>
                <?php if ($isManager) { ?>
                    <button class="nav-btn" onclick="location.href='return_management.php'">借還管理</button>
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
                <div class="dashboard-hero">
                    <div class="dashboard-hero-copy">
                        <span class="hero-pill">CAMPUS RESOURCE HUB</span>
                        <h2>今天也來把活動辦得更順一點</h2>
                        <p class="hero-subtitle">即時查看器材、空間與申請進度。從查詢到送審，在同一個頁面就能完成。</p>
                        <div class="hero-actions">
                            <button class="btn-primary" onclick="handleBorrowClick(event)">立即查詢資源</button>
                            <button class="btn-secondary" onclick="navigateTo('myapplications')">查看我的申請</button>
                        </div>
                    </div>
                    <div class="hero-live-board">
                        <h3>即時運作狀態</h3>
                        <div class="live-item">
                            <span>系統服務</span>
                            <strong class="live-ok">正常</strong>
                        </div>
                        <div class="live-item">
                            <span>資料庫連線</span>
                            <strong class="<?php echo $dbConnected ? 'live-ok' : ''; ?>"><?php echo htmlspecialchars($dbStatusText, ENT_QUOTES, 'UTF-8'); ?></strong>
                        </div>
                        <div class="live-item">
                            <span>本日可借器材</span>
                            <strong>98 項</strong>
                        </div>
                        <div class="live-item">
                            <span>可用空間</span>
                            <strong>12 間</strong>
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
                        <button class="btn-primary" onclick="navigateTo('myapplications')">查看申請</button>
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
                        <option value="rejected">已拒絕</option>
                        <option value="completed">已完成</option>
                    </select>
                </div>
                <div id="applicationsList" class="applications-list">
                    <!-- 申請列表將由JavaScript填充 -->
                </div>
            </section>

            <!-- 資源管理頁面 (課指組) -->
            <section id="manage" class="page">
                <h2>資源管理</h2>
                <button class="btn-primary" onclick="addNewResource()">新增資源</button>
                
                <div class="management-tabs">
                    <button class="tab-btn active" onclick="switchManagementTab('resources')">資源列表</button>
                    <button class="tab-btn" onclick="switchManagementTab('statistics')">統計分析</button>
                </div>

                <!-- 資源管理標籤 -->
                <div id="resourcesTab" class="tab-content active">
                    <table class="management-table">
                        <thead>
                            <tr>
                                <th>資源ID</th>
                                <th>名稱</th>
                                <th>類型</th>
                                <th>可用性</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody id="resourcesTableBody">
                            <!-- 資源表格將由JavaScript填充 -->
                        </tbody>
                    </table>
                </div>

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
        </main>

        <!-- 頁腳 -->
        <footer class="footer">
            <p>&copy; 2024 校園資源租借系統。所有權利保留。</p>
        </footer>
    </div>

    <script>
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
    </script>
    <script src="app.js"></script>
</body>
</html>
