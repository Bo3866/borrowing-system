<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?next=history_all.php');
    exit;
}

$currentUserId = (string)$_SESSION['user_id'];
$currentRole = (string)($_SESSION['role_name'] ?? '');

// display name used in navbar buttons (keep consistent across pages)
$displayName = (string)($_SESSION['full_name'] ?? $_SESSION['user_id']);

// Only allow approver/manager roles to view this page (課指組及相關管理角色)
$allowedRoles = ['2', '3', 'a', 'b', 'c', 'd'];
if (!in_array($currentRole, $allowedRoles, true)) {
    http_response_code(403);
    echo "<p style=\"padding:1rem;background:#ffecec;border-radius:6px;\">存取被拒：此功能僅限課指組老師或管理人員。</p>";
    exit;
}

$dbError = '';
$link = getMysqliConnection($dbError);

// prepare defaults to avoid undefined variable when DB error occurs
$rows = [];
$totalCount = 0;
$needRevisionCount = 0;
$overdueCount = 0;
$todayCheckedIn = 0;

// default filter params
$q = '';
$statusFilter = 'all';
$typeFilter = 'all';

// Gather dynamic counts and filtered rows when DB is available
if ($dbError === '') {
    // summary counts
    $totalCount = 0;
    $needRevisionCount = 0;
    $overdueCount = 0;
    $todayCheckedIn = 0;

    $cRes = mysqli_query($link, 'SELECT COUNT(*) AS c FROM reservations');
    if ($cRes) { $totalCount = (int)mysqli_fetch_assoc($cRes)['c']; mysqli_free_result($cRes); }
    $nrRes = mysqli_query($link, "SELECT COUNT(*) AS c FROM reservations WHERE approval_status = 'need_revision'");
    if ($nrRes) { $needRevisionCount = (int)mysqli_fetch_assoc($nrRes)['c']; mysqli_free_result($nrRes); }
    $odRes = mysqli_query($link, "SELECT COUNT(*) AS c FROM reservations WHERE borrow_end_at < NOW() AND returned_at IS NULL");
    if ($odRes) { $overdueCount = (int)mysqli_fetch_assoc($odRes)['c']; mysqli_free_result($odRes); }
    $ciRes = mysqli_query($link, "SELECT COUNT(*) AS c FROM reservations WHERE DATE(checked_in_at) = CURDATE()");
    if ($ciRes) { $todayCheckedIn = (int)mysqli_fetch_assoc($ciRes)['c']; mysqli_free_result($ciRes); }

    // read filters from GET
    $q = trim((string)($_GET['q'] ?? ''));
    $statusFilter = (string)($_GET['status'] ?? 'all');
    $typeFilter = (string)($_GET['type'] ?? 'all');

    $where = [ '1=1' ];
    if ($statusFilter !== 'all' && $statusFilter !== '') {
        $where[] = "r.approval_status = '" . mysqli_real_escape_string($link, $statusFilter) . "'";
    }
    if ($typeFilter === 'space') {
        $where[] = "EXISTS (SELECT 1 FROM space_reservation_items sri WHERE sri.reservation_id = r.reservation_id)";
    } elseif ($typeFilter === 'equipment') {
        $where[] = "EXISTS (SELECT 1 FROM equipment_reservation_items eri WHERE eri.reservation_id = r.reservation_id)";
    }
    if ($q !== '') {
        $esc = mysqli_real_escape_string($link, $q);
        $where[] = "(u.full_name LIKE '%{$esc}%' OR r.user_id LIKE '%{$esc}%' OR u.email LIKE '%{$esc}%' OR EXISTS (SELECT 1 FROM equipment_reservation_items eri JOIN equipments e ON e.equipment_id = eri.equipment_id JOIN equipment_categories ec ON ec.equipment_code = e.equipment_code WHERE eri.reservation_id = r.reservation_id AND ec.equipment_name LIKE '%{$esc}%') OR EXISTS (SELECT 1 FROM space_reservation_items sri JOIN spaces s ON s.space_id = sri.space_id WHERE sri.reservation_id = r.reservation_id AND s.space_name LIKE '%{$esc}%'))";
    }

    $whereSql = implode(' AND ', $where);

    $sql = "SELECT
                r.reservation_id,
                r.user_id AS applicant_user_id,
                u.full_name,
                u.email,
                r.borrow_start_at,
                r.borrow_end_at,
                r.approval_status,
                r.approval_stage,
                r.submitted_at,
                r.checked_in_at,
                r.returned_at,
                (SELECT GROUP_CONCAT(CONCAT(ec.equipment_name, ' (', e.equipment_id, ')') SEPARATOR ', ') FROM equipment_reservation_items eri JOIN equipments e ON e.equipment_id = eri.equipment_id LEFT JOIN equipment_categories ec ON ec.equipment_code = e.equipment_code WHERE eri.reservation_id = r.reservation_id) AS equipment_names,
                (SELECT GROUP_CONCAT(CONCAT(s.space_name, ' (', sri.space_id, ')') SEPARATOR ', ') FROM space_reservation_items sri JOIN spaces s ON s.space_id = sri.space_id WHERE sri.reservation_id = r.reservation_id) AS space_names
            FROM reservations r
            LEFT JOIN users u ON u.user_id = r.user_id
            WHERE {$whereSql}
            ORDER BY r.borrow_start_at DESC
            LIMIT 2000";

    $res = mysqli_query($link, $sql);
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $rows[] = $r;
        }
        mysqli_free_result($res);
    } else {
        $dbError = '讀取借用紀錄失敗：' . mysqli_error($link);
    }
}

if ($link) mysqli_close($link);

// approval status -> Chinese label & CSS map
$approvalMap = [
    'pending' => [
        'label' => '待審核',
        'class' => 'bg-amber-50 text-amber-700 border-amber-200/60'
    ],
    'approved' => [
        'label' => '審核完成',
        'class' => 'bg-emerald-50 text-emerald-700 border-emerald-200/60'
    ],
    'rejected' => [
        'label' => '審核未通過',
        'class' => 'bg-rose-50 text-rose-700 border-rose-200/60'
    ],
    'need_revision' => [
        'label' => '需要補件',
        'class' => 'bg-orange-50 text-orange-700 border-orange-200/60'
    ],
    'revision_overdue' => [
        'label' => '補件逾期',
        'class' => 'bg-red-50 text-red-700 border-red-200/60'
    ],
];

// approval stage code -> human readable label
$stageMap = [
    'a' => '學務長教師',
    'b' => '軍訓室教師',
    'c' => '輔導人員',
    'd' => '課指組審核',
];
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>歷史借用紀錄｜全校器材與場地租借系統</title>
    <!-- Tailwind CSS (kept for utility layout) -->
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Increase small utility font-sizes to match project defaults */
        .text-xs { font-size: 0.95rem !important; }
        .text-sm { font-size: 1rem !important; }
        .text-[10px] { font-size: 0.85rem !important; }
        .text-2xl { font-size: 1.5rem !important; }
        .text-lg { font-size: 1.125rem !important; }
    </style>
    <!-- FontAwesome 圖示庫 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        /* Center main content and limit width for a denser layout */
        .container.main-content {
            max-width: 1200px !important;
            margin: 0 auto !important;
            padding-right: 1rem !important;
            padding-left: 1rem !important;
        }
        /* ensure grid occupies the full width inside the centered container */
        .container.main-content .grid.grid-cols-12 { width: 100%; }
    </style>
    <!-- Google Fonts: Noto Sans TC -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
    <style>
        body {
            font-family: 'Noto Sans TC', sans-serif;
        }
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        ::-webkit-scrollbar-track {
            background: #f1f5f9;
        }
        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
    </style>
    <style>
        /* Conservative readability adjustments to avoid layout shifts */
        .container.main-content h5 { font-size: 1.125rem; }
        /* Drawer adjustments */
        #detail-drawer { font-size: 15px; }
        /* Applicant ID badge (increase visibility) */
        .applicant-badge {
            font-size: 0.95rem !important;
            padding: 0.25rem 0.6rem !important;
            background-color: #f1f5f9 !important;
            color: #475569 !important;
            border-radius: 8px !important;
            display: inline-block;
            text-align: center;
        }
        /* Increase small helper text (timestamps, small meta) for readability */
        .container.main-content .text-xs,
        .container.main-content .text-[10px],
        .container.main-content .text-[11px],
        .container.main-content .font-mono,
        .container.main-content .text-slate-400 {
            font-size: 1rem !important;
            line-height: 1.25 !important;
        }
    </style>
</head>
<body class="history-page">

    <nav class="navbar">
        <div class="navbar-brand"><h1>📚 校園資源租借系統</h1></div>
        <div class="navbar-menu">
            <button class="nav-btn" onclick="location.href='index.php'">回首頁</button>
            <button class="nav-btn" onclick="location.href='borrow.php'">我要租借</button>
            <button class="nav-btn" onclick="location.href='approve.php'">審核面板</button>
            <button class="nav-btn" onclick="location.href='report_maintenance.php'">報修</button>
            <button class="nav-btn" type="button" disabled><?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></button>
            <button class="nav-btn" onclick="location.href='logout.php'">登出</button>
        </div>
    </nav>

    <!-- 2. 主要內容區 (使用您的 container / main-content 骨架包覆) -->
    <div class="container main-content">

        <!-- 頁面標題 -->
        <div class="page-header mb-4">
            <h2 class="text-2xl font-bold">借用紀錄查詢</h2>
        </div>

        <!-- 資料庫錯誤提示區 -->
        <?php if ($dbError !== '') { ?>
            <div class="bg-rose-50 border border-rose-200 text-rose-800 p-4 rounded-xl text-sm flex items-center gap-3 shadow-xs">
                <i class="fa-solid fa-circle-exclamation text-rose-500 text-lg"></i>
                <span><?php echo htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
        <?php } ?>

        <!-- 數據概覽小看板 (Widgets) -->
        <div id="dashboard" class="dashboard-grid">
            <div class="card">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <div>
                        <p style="color:#64748b;margin:0;font-weight:600;">總借用次數</p>
                        <h3 style="font-size:1.5rem;margin:6px 0;color:var(--text-color);font-weight:700;"><?php echo number_format($totalCount); ?></h3>
                        <p style="font-size:0.85rem;color:#94a3b8;margin:0;">最多 2000 筆</p>
                    </div>
                    <div style="width:44px;height:44px;border-radius:10px;background:#f8fafc;display:flex;align-items:center;justify-content:center;color:#64748b;border:1px solid rgba(44,62,80,0.06);">
                        <i class="fa-solid fa-list-ol"></i>
                    </div>
                </div>
            </div>
            <div class="card">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <div>
                        <p style="color:#64748b;margin:0;font-weight:600;">需要補件</p>
                        <h3 style="font-size:1.5rem;margin:6px 0;color:#d97706;font-weight:700;"><?php echo number_format($needRevisionCount); ?> <span style="font-size:0.9rem;color:#94a3b8;font-weight:400;">件</span></h3>
                        <p style="font-size:0.85rem;color:#d97706;margin:0;">待修改</p>
                    </div>
                    <div style="width:44px;height:44px;border-radius:10px;background:#fff7ed;display:flex;align-items:center;justify-content:center;color:#d97706;border:1px solid rgba(217,119,6,0.08);">
                        <i class="fa-solid fa-file-pen"></i>
                    </div>
                </div>
            </div>
            <div class="card">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <div>
                        <p style="color:#64748b;margin:0;font-weight:600;">逾期未歸還</p>
                        <h3 style="font-size:1.5rem;margin:6px 0;color:#e11d48;font-weight:700;"><?php echo number_format($overdueCount); ?> <span style="font-size:0.9rem;color:#94a3b8;font-weight:400;">件</span></h3>
                        <p style="font-size:0.85rem;color:#e11d48;margin:0;">需催收</p>
                    </div>
                    <div style="width:44px;height:44px;border-radius:10px;background:#fff0f6;display:flex;align-items:center;justify-content:center;color:#e11d48;border:1px solid rgba(225,29,72,0.08);">
                        <i class="fa-solid fa-hourglass-end"></i>
                    </div>
                </div>
            </div>
            <div class="card">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <div>
                        <p style="color:#64748b;margin:0;font-weight:600;">今日已完成報到</p>
                        <h3 style="font-size:1.5rem;margin:6px 0;color:#059669;font-weight:700;"><?php echo number_format($todayCheckedIn); ?> <span style="font-size:0.9rem;color:#94a3b8;font-weight:400;">場次</span></h3>
                        <p style="font-size:0.85rem;color:#059669;margin:0;">今日簽到</p>
                    </div>
                    <div style="width:44px;height:44px;border-radius:10px;background:#ecfdf5;display:flex;align-items:center;justify-content:center;color:#059669;border:1px solid rgba(5,150,105,0.08);">
                        <i class="fa-solid fa-clipboard-user"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- 篩選控制列 (完美對齊) -->
        <div class="bg-white p-4 rounded-xl border border-slate-200 shadow-xs flex flex-wrap gap-4 items-center justify-between">
            <!-- 頁籤切換 (全部/含場地/含器材) -->
            <div class="flex bg-slate-100 p-1 rounded-lg border border-slate-200 shrink-0">
                <button class="tab px-4 py-2 text-xs font-semibold rounded-md transition <?php echo ($typeFilter==='all' || $typeFilter==='')? 'bg-white text-slate-800 shadow-xs border border-slate-200/50':'text-slate-500 hover:text-slate-800'; ?>" data-filter="all">
                    <i class="fa-solid fa-border-all mr-1.5 text-indigo-600"></i>全部紀錄
                </button>
                <button class="tab px-4 py-2 text-xs font-semibold rounded-md transition <?php echo ($typeFilter==='space')? 'bg-white text-slate-800 shadow-xs border border-slate-200/50':'text-slate-500 hover:text-slate-800'; ?>" data-filter="space">
                    <i class="fa-solid fa-map-location-dot mr-1.5 text-emerald-600"></i>包含場地
                </button>
                <button class="tab px-4 py-2 text-xs font-semibold rounded-md transition <?php echo ($typeFilter==='equipment')? 'bg-white text-slate-800 shadow-xs border border-slate-200/50':'text-slate-500 hover:text-slate-800'; ?>" data-filter="equipment">
                    <i class="fa-solid fa-laptop mr-1.5 text-blue-600"></i>包含器材
                </button>
            </div>

            

            <!-- 搜尋與狀態下拉選單 -->
            <div class="flex items-center gap-3 w-full lg:w-auto lg:flex-1 lg:max-w-xl">
                <div class="relative flex-1">
                    <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                          <input id="q" type="text" <?php if ($q !== '') { echo 'value="' . htmlspecialchars($q, ENT_QUOTES, 'UTF-8') . '"'; } ?> placeholder="搜尋姓名/學號/Email/器材/場地" 
                              class="w-full pl-10 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-xs text-slate-800 placeholder-slate-400 focus:outline-none focus:border-indigo-500 focus:bg-white transition">
                </div>
                <select id="statusFilter" class="bg-slate-50 border border-slate-200 text-slate-700 text-xs rounded-lg px-3 py-2.5 focus:outline-none focus:border-indigo-500 focus:bg-white cursor-pointer shrink-0">
                    <option value="all" <?php echo ($statusFilter==='all')? 'selected':''; ?>>所有審核狀態</option>
                    <option value="pending" <?php echo ($statusFilter==='pending')? 'selected':''; ?>>待審核</option>
                    <option value="approved" <?php echo ($statusFilter==='approved')? 'selected':''; ?>>審核完成</option>
                    <option value="need_revision" <?php echo ($statusFilter==='need_revision')? 'selected':''; ?>>需要補件</option>
                    <option value="rejected" <?php echo ($statusFilter==='rejected')? 'selected':''; ?>>審核未通過</option>
                </select>
            </div>
        </div>

        <!-- 表格表頭設計（重新計算比例，移除編號欄，完美對齊） -->
        <div class="bg-slate-800 text-slate-200 px-6 py-4 rounded-t-xl text-xs font-semibold grid grid-cols-12 gap-4 items-center shadow-xs">
            <div class="col-span-3 pl-2">申請人</div>
            <div class="col-span-4">借用時段</div>
            <div class="col-span-3">資源內容</div>
            <div class="col-span-2 text-center">審核狀態</div>
        </div>

        <!-- 歷史紀錄列表容器 (Record List PHP loop) -->
        <div class="space-y-2">
            <?php if (count($rows) === 0) { ?>
                <div class="bg-white p-16 text-center rounded-b-xl border border-slate-200 border-t-0">
                    <i class="fa-solid fa-clipboard-question text-4xl text-slate-300 mb-3"></i>
                    <p class="text-sm text-slate-500">目前沒有符合篩選條件的借用紀錄。</p>
                </div>
            <?php } else { ?>
                <?php foreach ($rows as $r) { 
                    // 解析設備與場地陣列，以便生成視覺化標籤
                    $equipments = !empty($r['equipment_names']) ? explode(', ', $r['equipment_names']) : [];
                    $spaces = !empty($r['space_names']) ? explode(', ', $r['space_names']) : [];
                    
                    // 審核狀態格式化
                    $statusKey = (string)$r['approval_status'];
                    $statusConf = $approvalMap[$statusKey] ?? [
                        'label' => $statusKey, 
                        'class' => 'bg-slate-50 text-slate-700 border-slate-200'
                    ];
                ?>
                    <div onclick="openDrawer(this)" 
                         class="bg-white px-6 py-4.5 rounded-xl border border-slate-200 hover:border-indigo-300 transition-all hover:shadow-xs cursor-pointer grid grid-cols-12 gap-4 items-center group"
                         data-id="<?php echo (int)$r['reservation_id']; ?>"
                         data-borrower-name="<?php echo htmlspecialchars($r['full_name'], ENT_QUOTES, 'UTF-8'); ?>"
                         data-borrower-id="<?php echo htmlspecialchars($r['applicant_user_id'], ENT_QUOTES, 'UTF-8'); ?>"
                         data-borrower-email="<?php echo htmlspecialchars((string)$r['email'], ENT_QUOTES, 'UTF-8'); ?>"
                         data-start="<?php echo htmlspecialchars((string)$r['borrow_start_at'], ENT_QUOTES, 'UTF-8'); ?>"
                         data-end="<?php echo htmlspecialchars((string)$r['borrow_end_at'], ENT_QUOTES, 'UTF-8'); ?>"
                         data-submitted="<?php echo htmlspecialchars((string)$r['submitted_at'], ENT_QUOTES, 'UTF-8'); ?>"
                         data-status="<?php echo htmlspecialchars($statusKey, ENT_QUOTES, 'UTF-8'); ?>"
                         data-status-label="<?php echo htmlspecialchars($statusConf['label'], ENT_QUOTES, 'UTF-8'); ?>"
                         data-checkin="<?php echo $r['checked_in_at'] ? htmlspecialchars((string)$r['checked_in_at'], ENT_QUOTES, 'UTF-8') : '—'; ?>"
                         data-return="<?php echo $r['returned_at'] ? htmlspecialchars((string)$r['returned_at'], ENT_QUOTES, 'UTF-8') : '—'; ?>"
                         data-equipments="<?php echo htmlspecialchars((string)$r['equipment_names'], ENT_QUOTES, 'UTF-8'); ?>"
                         data-spaces="<?php echo htmlspecialchars((string)$r['space_names'], ENT_QUOTES, 'UTF-8'); ?>"
                         data-stage="<?php echo htmlspecialchars((string)(isset($r['approval_status']) && $r['approval_status'] === 'approved' ? '已完成' : ($stageMap[$r['approval_stage'] ?? ''] ?? ($r['approval_stage'] ?? ''))), ENT_QUOTES, 'UTF-8'); ?>">
                        
                        <!-- 1. 申請人 (佔 3 格極寬裕) -->
                        <div class="col-span-3 min-w-0 pl-2">
                            <h5 class="font-bold text-slate-800 text-sm truncate group-hover:text-indigo-600 transition flex items-center gap-1.5">
                                <?php echo htmlspecialchars($r['full_name'], ENT_QUOTES, 'UTF-8'); ?>
                                <span class="applicant-badge text-[10px] bg-slate-100 text-slate-500 font-mono px-1.5 py-0.5 rounded">
                                    <?php echo htmlspecialchars($r['applicant_user_id'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </h5>
                            
                        </div>

                        <!-- 2. 借用時段 (佔 4 格) -->
                        <div class="col-span-4 font-mono text-xs text-slate-600 leading-tight">
                            <div class="flex items-center gap-1.5"><i class="fa-regular fa-clock text-[10px] text-slate-400"></i> <?php echo htmlspecialchars((string)$r['borrow_start_at'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="flex items-center gap-1.5 mt-1 text-slate-400">至 <?php echo htmlspecialchars((string)$r['borrow_end_at'], ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>

                        <!-- 3. 資源內容標籤群 (佔 3 格) -->
                        <div class="col-span-3">
                            <div class="flex flex-col gap-1.5 max-w-full">
                                <?php if (empty($equipments) && empty($spaces)) { ?>
                                    <span class="text-slate-400 text-xs">-</span>
                                <?php } ?>
                                
                                <!-- 渲染器材 Tag -->
                                <?php foreach ($equipments as $eq) { if (trim($eq) === '') continue; ?>
                                    <div class="flex items-center gap-1.5 px-2.5 py-1 rounded text-[11px] font-medium border bg-blue-50 text-blue-800 border-blue-200/60 truncate">
                                        <i class="fa-solid fa-box text-[10px] text-blue-500"></i>
                                        <span class="truncate"><?php echo htmlspecialchars($eq, ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                <?php } ?>

                                <!-- 渲染場地 Tag -->
                                <?php foreach ($spaces as $sp) { if (trim($sp) === '') continue; ?>
                                    <div class="flex items-center gap-1.5 px-2.5 py-1 rounded text-[11px] font-medium border bg-emerald-50 text-emerald-800 border-emerald-200/60 truncate">
                                        <i class="fa-solid fa-map-pin text-[10px] text-emerald-500"></i>
                                        <span class="truncate"><?php echo htmlspecialchars($sp, ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>
                                <?php } ?>
                            </div>
                        </div>

                        <!-- 4. 審核狀態 -->
                        <div class="col-span-2 text-center">
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded text-xs font-semibold border <?php echo $statusConf['class']; ?>">
                                <?php if ($statusKey === 'need_revision') { ?>
                                    <span class="w-1.5 h-1.5 rounded-full bg-orange-500 animate-ping"></span>
                                <?php } ?>
                                <?php echo htmlspecialchars($statusConf['label'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </div>

                        <!-- 報到 / 歸還 欄已移除 -->

                        <!-- 6. 送出時間欄已移除 -->

                    </div>
                <?php } ?>
            <?php } ?>
        </div>

    </div>

    <!-- 3. 右側滑出詳細資訊面板 (Inspection Drawer Modal) -->
    <div id="drawer-overlay" class="fixed inset-0 bg-slate-900/40 z-40 hidden backdrop-blur-xs transition-opacity duration-300 opacity-0" onclick="closeDrawer()"></div>
    <div id="detail-drawer" class="fixed right-0 top-0 bottom-0 w-[550px] bg-white border-l border-slate-200 z-50 p-8 shadow-2xl overflow-y-auto translate-x-full transition-transform duration-300 ease-out">
        <!-- Drawer Header -->
        <div class="flex items-center justify-between border-b border-slate-100 pb-5 mb-6">
            <div class="flex items-center gap-2">
                <span class="px-2.5 py-1 rounded text-xs font-bold bg-indigo-50 text-indigo-700 border border-indigo-200">
                    租借紀錄詳情
                </span>
                <span class="text-xs text-slate-400 font-mono" id="drawer-record-id">單號: -</span>
            </div>
            <button onclick="closeDrawer()" class="text-slate-400 hover:text-slate-800 transition p-1.5 hover:bg-slate-100 rounded-lg">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>

        <!-- Drawer Content -->
        <div class="space-y-6">
            
            <!-- 補件提示區已移除 -->

            <!-- 申請人詳情卡片 -->
            <div class="bg-slate-50 p-4 rounded-xl border border-slate-200 space-y-3">
                <h4 class="text-xs font-semibold text-slate-500 uppercase tracking-wider">申請人帳戶資訊</h4>
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 rounded-xl bg-indigo-50 border border-indigo-100 flex items-center justify-center text-indigo-600 text-lg font-bold">
                        <i class="fa-solid fa-id-card"></i>
                    </div>
                    <div>
                        <p class="text-sm font-bold text-slate-800 flex items-center gap-2">
                            <span id="drawer-borrower-name">-</span>
                            <span class="text-xs bg-slate-200 text-slate-600 px-1.5 py-0.5 rounded font-mono" id="drawer-borrower-id">-</span>
                        </p>
                        <p class="text-xs text-slate-500 font-mono mt-0.5" id="drawer-borrower-email">-</p>
                    </div>
                </div>
            </div>

            <!-- 資源清單模組 -->
            <div class="space-y-3">
                <h4 class="text-xs font-semibold text-slate-500 uppercase tracking-wider">申借之資源項目</h4>
                <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 text-xs space-y-3" id="drawer-resources-list">
                    <!-- 動態由 JS 塞入場地或器材列表 -->
                </div>
            </div>

            <!-- 時間軸 -->
            <div class="space-y-3">
                <h4 class="text-xs font-semibold text-slate-500 uppercase tracking-wider">借用時序追蹤</h4>
                <div class="relative pl-6 border-l border-slate-200 space-y-4 text-xs">
                    <div class="relative">
                        <div class="absolute -left-[30px] top-0.5 w-4 h-4 rounded-full bg-slate-100 border-2 border-slate-400 flex items-center justify-center text-[8px] text-slate-500"><i class="fa-solid fa-paper-plane"></i></div>
                        <p class="font-medium text-slate-700">申請送出時間</p>
                        <p class="text-slate-500 mt-0.5" id="drawer-submitted-time">-</p>
                    </div>
                    <div class="relative">
                        <div class="absolute -left-[30px] top-0.5 w-4 h-4 rounded-full bg-emerald-50 border-2 border-emerald-500 flex items-center justify-center text-[8px] text-emerald-600"><i class="fa-solid fa-play"></i></div>
                        <p class="font-medium text-slate-700">借用起算時段</p>
                        <p class="text-slate-500 mt-0.5" id="drawer-start-time">-</p>
                    </div>
                    <div class="relative">
                        <div class="absolute -left-[30px] top-0.5 w-4 h-4 rounded-full bg-rose-50 border-2 border-rose-500 flex items-center justify-center text-[8px] text-rose-600"><i class="fa-solid fa-flag"></i></div>
                        <p class="font-medium text-slate-700">預計歸還截止</p>
                        <p class="text-slate-500 mt-0.5" id="drawer-end-time">-</p>
                    </div>
                </div>
            </div>

            <!-- 報到與歸還狀態核對 -->
            <div class="grid grid-cols-2 gap-4">
                <div class="bg-slate-50 p-3.5 rounded-xl border border-slate-200 text-xs">
                    <span class="text-slate-400 block mb-1">現場報到登記</span>
                    <p class="font-semibold text-slate-700 flex items-center gap-1.5" id="drawer-checkin-status">
                        -
                    </p>
                </div>
                <div class="bg-slate-50 p-3.5 rounded-xl border border-slate-200 text-xs">
                    <span class="text-slate-400 block mb-1">歸還點收清點</span>
                    <p class="font-semibold text-slate-700 flex items-center gap-1.5" id="drawer-return-status">
                        -
                    </p>
                </div>
            </div>

            <!-- 審批工作階段 -->
            <div class="border-t border-slate-150 pt-5 space-y-3">
                <h4 class="text-xs font-semibold text-slate-500 uppercase tracking-wider">系統工作紀錄</h4>
                <div class="bg-slate-50 p-3.5 rounded-lg border border-slate-200 text-xs space-y-2 text-slate-600">
                    <div class="flex justify-between"><span class="font-medium text-slate-500">當前審核階段 (Stage)：</span><span class="text-slate-800 font-mono" id="drawer-stage">-</span></div>
                    <div class="flex justify-between"><span class="font-medium text-slate-500">案件審核狀態：</span><span class="text-slate-800 font-semibold" id="drawer-status-label">-</span></div>
                </div>
            </div>
        </div>
    </div>

    <!-- 4. JavaScript 邏輯控制與篩選跳轉 (融合原本 PHP 的篩選與導向機制) -->
    <script>
        // 封裝重組 URL 方法
        function buildUrl(params) {
            const base = window.location.pathname;
            const qs = new URLSearchParams(params).toString();
            return base + (qs ? ('?' + qs) : '');
        }

        // Tabs 頁籤點擊事件 (保留原有 type 篩選)
        document.querySelectorAll('.tab').forEach(t => t.addEventListener('click', function(){
            const type = this.getAttribute('data-filter') || 'all';
            const qv = document.getElementById('q')?.value || '';
            const sv = document.getElementById('statusFilter')?.value || 'all';
            window.location.href = buildUrl({ q: qv, status: sv, type: type });
        }));

        // 審核狀態 Select 下拉選單改動時
        document.getElementById('statusFilter')?.addEventListener('change', function(){
            const typeEl = document.querySelector('.tab.active') || document.querySelector('.tab[class*="bg-white"]');
            const type = typeEl ? typeEl.getAttribute('data-filter') : 'all';
            const qv = document.getElementById('q')?.value || '';
            window.location.href = buildUrl({ q: qv, status: this.value, type: type });
        });

        // 關鍵字搜尋 Enter 事件
        document.getElementById('q')?.addEventListener('keydown', function(e){
            if(e.key === 'Enter'){
                e.preventDefault();
                const sv = document.getElementById('statusFilter')?.value || 'all';
                const typeEl = document.querySelector('.tab.active') || document.querySelector('.tab[class*="bg-white"]');
                const type = typeEl ? typeEl.getAttribute('data-filter') : 'all';
                window.location.href = buildUrl({ q: this.value, status: sv, type: type });
            }
        });

        // 控制右側側邊抽屜滑出與資訊塞入
        function openDrawer(element) {
            const id = element.getAttribute('data-id');
            const name = element.getAttribute('data-borrower-name');
            const bId = element.getAttribute('data-borrower-id');
            const email = element.getAttribute('data-borrower-email');
            const start = element.getAttribute('data-start');
            const end = element.getAttribute('data-end');
            const submitted = element.getAttribute('data-submitted');
            const status = element.getAttribute('data-status');
            const statusLabel = element.getAttribute('data-status-label');
            const checkin = element.getAttribute('data-checkin');
            const returned = element.getAttribute('data-return');
            const equipmentsStr = element.getAttribute('data-equipments');
            const spacesStr = element.getAttribute('data-spaces');
            const stage = element.getAttribute('data-stage') || 'N/A';

            // 塞入基本文字
            document.getElementById('drawer-record-id').innerText = '單號: #' + id;
            document.getElementById('drawer-borrower-name').innerText = name;
            document.getElementById('drawer-borrower-id').innerText = bId;
            document.getElementById('drawer-borrower-email').innerText = email;
            document.getElementById('drawer-submitted-time').innerText = submitted;
            document.getElementById('drawer-start-time').innerText = start;
            document.getElementById('drawer-end-time').innerText = end;
            document.getElementById('drawer-stage').innerText = stage;
            document.getElementById('drawer-status-label').innerText = statusLabel;

            // 信箱通知按鈕
            const mailBtn = document.getElementById('drawer-email-btn');
            if(mailBtn) {
                mailBtn.href = `mailto:${email}?subject=【校園資源租借系統】補件通知 - 單號%23${id}&body=${name} 同學/老師 您好：%0D%0A%0D%0A您於 ${submitted} 送出的資源租借申請（單號 %23${id}）目前需要補件。%0D%0A請儘速至系統「個人紀錄」中查看需要補件的詳細原因並上傳相關文件，謝謝！`;
            }

            // 報到與歸還狀態 UI
            const checkinStatusEl = document.getElementById('drawer-checkin-status');
            if (checkin !== '—' && checkin !== '') {
                checkinStatusEl.innerHTML = `<i class="fa-solid fa-circle-check text-emerald-500 mr-1.5"></i> 已簽到 (${checkin})`;
            } else {
                checkinStatusEl.innerHTML = `<i class="fa-solid fa-circle-minus text-slate-300 mr-1.5"></i> 尚未現場簽到`;
            }

            const returnStatusEl = document.getElementById('drawer-return-status');
            if (returned !== '—' && returned !== '') {
                returnStatusEl.innerHTML = `<i class="fa-solid fa-circle-check text-emerald-500 mr-1.5"></i> 已點收歸還 (${returned})`;
            } else {
                returnStatusEl.innerHTML = `<i class="fa-solid fa-hourglass-start text-amber-500 mr-1.5"></i> 尚未點收歸還`;
            }

            // 動態列出資源項目
            const resourcesContainer = document.getElementById('drawer-resources-list');
            resourcesContainer.innerHTML = '';

            const equipments = equipmentsStr ? equipmentsStr.split(', ') : [];
            const spaces = spacesStr ? spacesStr.split(', ') : [];

            equipments.forEach(eq => {
                if (eq.trim() === '') return;
                resourcesContainer.innerHTML += `
                    <div class="p-3 rounded-lg border border-blue-150 bg-blue-50/50 flex items-center justify-between">
                        <div class="flex items-center gap-2.5">
                            <div class="w-8 h-8 rounded-lg bg-blue-100 text-blue-700 flex items-center justify-center text-sm">
                                <i class="fa-solid fa-box"></i>
                            </div>
                            <div>
                                <p class="font-bold text-slate-800 text-xs">${eq}</p>
                            </div>
                        </div>
                    </div>
                `;
            });

            spaces.forEach(sp => {
                if (sp.trim() === '') return;
                resourcesContainer.innerHTML += `
                    <div class="p-3 rounded-lg border border-emerald-150 bg-emerald-50/50 flex items-center justify-between">
                        <div class="flex items-center gap-2.5">
                            <div class="w-8 h-8 rounded-lg bg-emerald-100 text-emerald-700 flex items-center justify-center text-sm">
                                <i class="fa-solid fa-map-pin"></i>
                            </div>
                            <div>
                                <p class="font-bold text-slate-800 text-xs">${sp}</p>
                            </div>
                        </div>
                    </div>
                `;
            });

            if (resourcesContainer.innerHTML === '') {
                resourcesContainer.innerHTML = `<p class="text-slate-400 text-xs text-center py-2">無申請項目資訊</p>`;
            }

            // 補件提示區已移除，不再顯示

            // 開啟 Drawer 的動畫效果
            const overlay = document.getElementById('drawer-overlay');
            const drawer = document.getElementById('detail-drawer');
            
            overlay.classList.remove('hidden');
            setTimeout(() => {
                overlay.classList.remove('opacity-0');
                drawer.classList.remove('translate-x-full');
            }, 10);
        }

        function closeDrawer() {
            const overlay = document.getElementById('drawer-overlay');
            const drawer = document.getElementById('detail-drawer');

            overlay.classList.add('opacity-0');
            drawer.classList.add('translate-x-full');

            setTimeout(() => {
                overlay.classList.add('hidden');
            }, 300);
        }
    </script>
    <script>
        // 強制在 DOM 載入後調整小字字級，確保樣式不被現有框架覆寫
        document.addEventListener('DOMContentLoaded', function () {
            try {
                const sel = '.container.main-content .text-xs, .container.main-content .text-[10px], .container.main-content .text-[11px], .container.main-content .font-mono, .container.main-content .text-slate-400';
                document.querySelectorAll(sel).forEach(el => {
                    el.style.fontSize = '1rem';
                    el.style.lineHeight = '1.25';
                });

                // Drawer and badge adjustments
                const drawer = document.getElementById('detail-drawer');
                if (drawer) drawer.style.fontSize = '15px';

                document.querySelectorAll('.applicant-badge').forEach(el => {
                    el.style.fontSize = '0.95rem';
                    el.style.padding = '0.25rem 0.6rem';
                });
            } catch (e) {
                console.warn('Font adjust script error', e);
            }
        });
    </script>
</body>
</html>