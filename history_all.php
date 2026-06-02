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
$startDate = '';
$endDate = '';

// Gather dynamic counts and filtered rows when DB is available
if ($dbError === '') {
    // summary counts
    $totalCount = 0;
    $needRevisionCount = 0;
    $overdueCount = 0;
    $todayCheckedIn = 0;

    // 2.4 借用紀錄查詢：只統計「審核完成且借用結束時間已過」的歷史借用紀錄
    $historyBaseWhere = "approval_status = 'approved' AND borrow_end_at <= NOW() AND checked_in_at IS NOT NULL";

    $cRes = mysqli_query($link, "SELECT COUNT(*) AS c FROM reservations WHERE {$historyBaseWhere}");
    if ($cRes) { $totalCount = (int)mysqli_fetch_assoc($cRes)['c']; mysqli_free_result($cRes); }
    $nrRes = mysqli_query($link, "SELECT COUNT(*) AS c FROM reservations WHERE {$historyBaseWhere} AND returned_at IS NOT NULL");
    if ($nrRes) { $needRevisionCount = (int)mysqli_fetch_assoc($nrRes)['c']; mysqli_free_result($nrRes); }
    $odRes = mysqli_query($link, "SELECT COUNT(*) AS c FROM reservations WHERE {$historyBaseWhere} AND returned_at IS NULL");
    if ($odRes) { $overdueCount = (int)mysqli_fetch_assoc($odRes)['c']; mysqli_free_result($odRes); }
    $ciRes = mysqli_query($link, "SELECT COUNT(*) AS c FROM reservations WHERE {$historyBaseWhere} AND DATE(returned_at) = CURDATE()");
    if ($ciRes) { $todayCheckedIn = (int)mysqli_fetch_assoc($ciRes)['c']; mysqli_free_result($ciRes); }

    // read filters from GET
    $q = trim((string)($_GET['q'] ?? ''));
    $statusFilter = (string)($_GET['status'] ?? 'all');
    $typeFilter = (string)($_GET['type'] ?? 'all');
    $startDate = trim((string)($_GET['start_date'] ?? ''));
    $endDate = trim((string)($_GET['end_date'] ?? ''));

    // 動態確認 reservations 實際欄位，避免不同版本資料庫欄位名稱不一致導致活動/單位/電話抓不到
    $reservationColumns = [];
    $colRes = mysqli_query($link, "SHOW COLUMNS FROM reservations");
    if ($colRes) {
        while ($col = mysqli_fetch_assoc($colRes)) {
            $reservationColumns[(string)$col['Field']] = true;
        }
        mysqli_free_result($colRes);
    }

    $pickReservationColumn = function(array $candidates) use ($reservationColumns): string {
        foreach ($candidates as $columnName) {
            if (isset($reservationColumns[$columnName])) {
                return "r.`" . str_replace('`', '``', $columnName) . "`";
            }
        }
        return "''";
    };

    // 主要使用目前系統的欄位名稱，後面是舊版/不同命名的備援欄位
    $organizationExpr = $pickReservationColumn(['organization_name', 'applicant_unit', 'unit_name', 'club_name', 'department_name', 'organization']);
    $activityExpr = $pickReservationColumn(['activity_name', 'event_name', 'project_name', 'event_title', 'activity_title']);
    $phoneExpr = $pickReservationColumn(['coordinator_phone', 'contact_phone', 'applicant_phone', 'phone', 'tel', 'mobile']);

    // 基本規則：此頁只顯示「審核完成」、「已實際報到/領取」且「借用結束時間已過」的歷史借用紀錄
    $where = [ "r.approval_status = 'approved'", "r.borrow_end_at <= NOW()", "r.checked_in_at IS NOT NULL" ];

    // 此下拉選單為借用/歸還狀態篩選，不再篩選審核中、已駁回等非歷史借用案件
    if ($statusFilter === 'returned') {
        $where[] = "r.returned_at IS NOT NULL";
    } elseif ($statusFilter === 'overdue') {
        $where[] = "r.returned_at IS NULL";
    }
    if ($startDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
        $where[] = "DATE(r.borrow_start_at) >= '" . mysqli_real_escape_string($link, $startDate) . "'";
    }
    if ($endDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        $where[] = "DATE(r.borrow_end_at) <= '" . mysqli_real_escape_string($link, $endDate) . "'";
    }
    if ($typeFilter === 'space') {
        $where[] = "EXISTS (SELECT 1 FROM space_reservation_items sri WHERE sri.reservation_id = r.reservation_id)";
    } elseif ($typeFilter === 'equipment') {
        $where[] = "EXISTS (SELECT 1 FROM equipment_reservation_items eri WHERE eri.reservation_id = r.reservation_id)";
    }
    if ($q !== '') {
        $esc = mysqli_real_escape_string($link, $q);
        $where[] = "(u.full_name LIKE '%{$esc}%' OR r.user_id LIKE '%{$esc}%' OR u.email LIKE '%{$esc}%' OR {$phoneExpr} LIKE '%{$esc}%' OR {$organizationExpr} LIKE '%{$esc}%' OR {$activityExpr} LIKE '%{$esc}%' OR EXISTS (SELECT 1 FROM equipment_reservation_items eri JOIN equipments e ON e.equipment_id = eri.equipment_id JOIN equipment_categories ec ON ec.equipment_code = e.equipment_code WHERE eri.reservation_id = r.reservation_id AND ec.equipment_name LIKE '%{$esc}%') OR EXISTS (SELECT 1 FROM space_reservation_items sri JOIN spaces s ON s.space_id = sri.space_id WHERE sri.reservation_id = r.reservation_id AND s.space_name LIKE '%{$esc}%'))";
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
                {$phoneExpr} AS coordinator_phone,
                {$organizationExpr} AS organization_name,
                {$activityExpr} AS activity_name,
                (SELECT GROUP_CONCAT(CONCAT(ec.equipment_name, ' (', e.equipment_id, ')') SEPARATOR ', ') FROM equipment_reservation_items eri JOIN equipments e ON e.equipment_id = eri.equipment_id LEFT JOIN equipment_categories ec ON ec.equipment_code = e.equipment_code WHERE eri.reservation_id = r.reservation_id) AS equipment_names,
                (SELECT GROUP_CONCAT(CONCAT(s.space_name, ' (', sri.space_id, ')') SEPARATOR ', ') FROM space_reservation_items sri JOIN spaces s ON s.space_id = sri.space_id WHERE sri.reservation_id = r.reservation_id) AS space_names
            FROM reservations r
            LEFT JOIN users u ON u.user_id = r.user_id
            WHERE {$whereSql}
            ORDER BY r.borrow_end_at DESC
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
    'a' => '輔導人員',
    'b' => '軍訓室教師',
    'c' => '學務長教師',
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

        .history-list-header,
        .history-list-row {
            display: grid;
            grid-template-columns: 120px minmax(190px, 1fr) minmax(180px, .9fr) minmax(300px, 1.4fr) 145px;
            gap: 1rem;
            align-items: center;
        }
        .history-list-header {
            background: #1e293b;
            color: #e2e8f0;
            padding: 1rem 1.5rem;
            border-radius: 1rem 1rem 0 0;
            font-size: 0.95rem;
            font-weight: 700;
            letter-spacing: .02em;
        }
        .history-list-row {
            background: #ffffff;
            padding: 1rem 1.5rem;
            border: 1px solid #e2e8f0;
            border-radius: 1rem;
            cursor: pointer;
            transition: .18s ease;
        }
        .history-list-row:hover {
            border-color: #a5b4fc;
            box-shadow: 0 10px 24px rgba(15, 23, 42, .08);
            transform: translateY(-1px);
        }
        .record-id-pill {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            padding: .45rem .75rem;
            border-radius: 999px;
            background: #eef2ff;
            color: #4f46e5;
            border: 1px solid #c7d2fe;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
            font-weight: 800;
            width: fit-content;
        }
        .borrower-name-line {
            display: flex;
            align-items: center;
            gap: .5rem;
            min-width: 0;
        }
        .time-range-box {
            display: flex;
            flex-direction: column;
            gap: .35rem;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
            color: #475569;
        }
        .time-range-box .end-time {
            color: #94a3b8;
            padding-left: 1.35rem;
        }
        .detail-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .45rem;
            padding: .55rem .85rem;
            border-radius: .8rem;
            background: #f8fafc;
            color: #475569;
            border: 1px solid #e2e8f0;
            font-size: .95rem;
            font-weight: 700;
            transition: .18s ease;
            white-space: nowrap;
        }
        .history-list-row:hover .detail-button {
            background: #4f46e5;
            color: #ffffff;
            border-color: #4f46e5;
        }
        @media (max-width: 900px) {
            .history-list-header { display: none; }
            .history-list-row {
                grid-template-columns: 1fr;
                gap: .75rem;
            }
            .detail-button { width: 100%; }
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


        /* 右側詳情面板：簡潔乾淨版 */
        #detail-drawer {
            background: #f8fafc !important;
        }
        .drawer-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 18px;
            padding: 18px;
            box-shadow: 0 8px 20px rgba(15, 23, 42, .04);
        }
        .drawer-section-title {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0 0 12px;
            font-size: .98rem !important;
            font-weight: 800;
            color: #334155;
            letter-spacing: .01em;
        }
        .drawer-section-title i {
            color: #64748b;
            font-size: .9rem;
        }
        .applicant-summary {
            display: flex;
            gap: 14px;
            align-items: flex-start;
        }
        .applicant-avatar {
            width: 44px;
            height: 44px;
            border-radius: 14px;
            background: #eef2ff;
            border: 1px solid #c7d2fe;
            color: #4f46e5;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .applicant-main {
            min-width: 0;
            flex: 1;
        }
        .applicant-name-row {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            font-size: 1.05rem;
            font-weight: 800;
            color: #0f172a;
        }
        .drawer-id-badge {
            font-size: .82rem !important;
            padding: 2px 8px;
            border-radius: 8px;
            background: #e2e8f0;
            color: #475569;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
        }
        .drawer-email {
            margin-top: 2px;
            color: #64748b;
            font-size: .92rem !important;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
        }
        .drawer-info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-top: 16px;
        }
        .drawer-info-item {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 12px 14px;
            min-width: 0;
        }
        .drawer-info-item.full {
            grid-column: 1 / -1;
        }
        .drawer-label {
            display: block;
            color: #94a3b8;
            font-size: .86rem !important;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .drawer-value {
            display: block;
            color: #0f172a;
            font-size: 1rem !important;
            font-weight: 700;
            word-break: break-word;
        }
        .resource-list-clean {
            display: flex;
            flex-direction: column;
            gap: 10px;
            background: transparent !important;
            border: 0 !important;
            padding: 0 !important;
        }
        .resource-item-clean {
            padding: 13px 14px;
            border-radius: 14px;
            border: 1px solid #dbeafe;
            background: #eff6ff;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .resource-item-clean.space {
            border-color: #bbf7d0;
            background: #f0fdf4;
        }
        .resource-icon-clean {
            width: 32px;
            height: 32px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #2563eb;
            background: rgba(255,255,255,.75);
            flex-shrink: 0;
        }
        .resource-item-clean.space .resource-icon-clean { color: #059669; }
        .timeline-clean {
            display: grid;
            gap: 10px;
        }
        .timeline-row-clean {
            display: grid;
            grid-template-columns: 132px 1fr;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            background: #ffffff;
        }
        .timeline-row-clean span:first-child {
            color: #64748b;
            font-weight: 700;
        }
        .timeline-row-clean span:last-child {
            color: #0f172a;
            font-weight: 700;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
            text-align: right;
        }
        .status-grid-clean {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .status-card-clean {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 15px 16px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        .status-card-clean.full { grid-column: auto; }
        .status-icon-clean {
            width: 34px;
            height: 34px;
            border-radius: 12px;
            background: #ecfdf5;
            color: #059669;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-top: 2px;
        }
        .status-content-clean {
            min-width: 0;
            flex: 1;
        }
        .status-label-clean {
            color: #94a3b8;
            font-size: .86rem !important;
            font-weight: 700;
            margin-bottom: 6px;
            display: block;
        }
        .status-card-clean p {
            margin: 0;
            line-height: 1.45;
            word-break: break-word;
        }
        @media (max-width: 640px) {
            .drawer-info-grid { grid-template-columns: 1fr; }
            .timeline-row-clean { grid-template-columns: 1fr; gap: 4px; }
            .timeline-row-clean span:last-child { text-align: left; }
        }
    </style>
</head>
<body class="history-page">

    <?php include __DIR__ . '/nav.php'; ?>

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
                        <p style="color:#64748b;margin:0;font-weight:600;">歷史借用紀錄</p>
                        <h3 style="font-size:1.5rem;margin:6px 0;color:var(--text-color);font-weight:700;"><?php echo number_format($totalCount); ?></h3>
                        <p style="font-size:0.85rem;color:#94a3b8;margin:0;">審核完成、已報到且借用結束</p>
                    </div>
                    <div style="width:44px;height:44px;border-radius:10px;background:#f8fafc;display:flex;align-items:center;justify-content:center;color:#64748b;border:1px solid rgba(44,62,80,0.06);">
                        <i class="fa-solid fa-list-ol"></i>
                    </div>
                </div>
            </div>
            <div class="card">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <div>
                        <p style="color:#64748b;margin:0;font-weight:600;">已歸還</p>
                        <h3 style="font-size:1.5rem;margin:6px 0;color:#d97706;font-weight:700;"><?php echo number_format($needRevisionCount); ?> <span style="font-size:0.9rem;color:#94a3b8;font-weight:400;">件</span></h3>
                        <p style="font-size:0.85rem;color:#d97706;margin:0;">已完成點收</p>
                    </div>
                    <div style="width:44px;height:44px;border-radius:10px;background:#fff7ed;display:flex;align-items:center;justify-content:center;color:#d97706;border:1px solid rgba(217,119,6,0.08);">
                        <i class="fa-solid fa-circle-check"></i>
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
                        <p style="color:#64748b;margin:0;font-weight:600;">今日歸還點收</p>
                        <h3 style="font-size:1.5rem;margin:6px 0;color:#059669;font-weight:700;"><?php echo number_format($todayCheckedIn); ?> <span style="font-size:0.9rem;color:#94a3b8;font-weight:400;">場次</span></h3>
                        <p style="font-size:0.85rem;color:#059669;margin:0;">今日完成歸還</p>
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
                          <input id="q" type="text" <?php if ($q !== '') { echo 'value="' . htmlspecialchars($q, ENT_QUOTES, 'UTF-8') . '"'; } ?> placeholder="搜尋申請人/學號/單位/活動/器材/場地" 
                              class="w-full pl-10 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-xs text-slate-800 placeholder-slate-400 focus:outline-none focus:border-indigo-500 focus:bg-white transition">
                </div>
                <select id="statusFilter" class="bg-slate-50 border border-slate-200 text-slate-700 text-xs rounded-lg px-3 py-2.5 focus:outline-none focus:border-indigo-500 focus:bg-white cursor-pointer shrink-0">
                    <option value="all" <?php echo ($statusFilter==='all')? 'selected':''; ?>>所有借用狀態</option>
                    <option value="returned" <?php echo ($statusFilter==='returned')? 'selected':''; ?>>已歸還</option>
                    <option value="overdue" <?php echo ($statusFilter==='overdue')? 'selected':''; ?>>逾期未歸還</option>
                </select>
                <input id="startDate" type="date" value="<?php echo htmlspecialchars($startDate, ENT_QUOTES, 'UTF-8'); ?>" class="bg-slate-50 border border-slate-200 text-slate-700 text-xs rounded-lg px-3 py-2.5 focus:outline-none focus:border-indigo-500 focus:bg-white cursor-pointer shrink-0" title="借用開始日期">
                <input id="endDate" type="date" value="<?php echo htmlspecialchars($endDate, ENT_QUOTES, 'UTF-8'); ?>" class="bg-slate-50 border border-slate-200 text-slate-700 text-xs rounded-lg px-3 py-2.5 focus:outline-none focus:border-indigo-500 focus:bg-white cursor-pointer shrink-0" title="借用結束日期">
            </div>
        </div>

        <!-- 清單表頭：外層只顯示查詢需要的重點，詳細資料點進右側面板查看 -->
        <div class="history-list-header mt-4">
            <div>單號</div>
            <div>申請人</div>
            <div>申請單位</div>
            <div>借用時段</div>
            <div class="text-center">操作</div>
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
                    $borrowStatusLabel = !empty($r['returned_at']) ? '已歸還' : '逾期未歸還';
                    $borrowStatusClass = !empty($r['returned_at'])
                        ? 'bg-emerald-50 text-emerald-700 border-emerald-200/60'
                        : 'bg-rose-50 text-rose-700 border-rose-200/60';
                ?>
                    <div 
                         class="history-list-row group"
                         data-id="<?php echo (int)$r['reservation_id']; ?>"
                         data-borrower-name="<?php echo htmlspecialchars($r['full_name'], ENT_QUOTES, 'UTF-8'); ?>"
                         data-borrower-id="<?php echo htmlspecialchars($r['applicant_user_id'], ENT_QUOTES, 'UTF-8'); ?>"
                         data-borrower-email="<?php echo htmlspecialchars((string)$r['email'], ENT_QUOTES, 'UTF-8'); ?>"
                         data-start="<?php echo htmlspecialchars((string)$r['borrow_start_at'], ENT_QUOTES, 'UTF-8'); ?>"
                         data-end="<?php echo htmlspecialchars((string)$r['borrow_end_at'], ENT_QUOTES, 'UTF-8'); ?>"
                         data-submitted="<?php echo htmlspecialchars((string)$r['submitted_at'], ENT_QUOTES, 'UTF-8'); ?>"
                         data-coordinator-phone="<?php echo htmlspecialchars((string)($r['coordinator_phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                         data-organization-name="<?php echo htmlspecialchars((string)($r['organization_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                         data-activity-name="<?php echo htmlspecialchars((string)($r['activity_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                         data-status="<?php echo htmlspecialchars($statusKey, ENT_QUOTES, 'UTF-8'); ?>"
                         data-status-label="<?php echo htmlspecialchars($statusConf['label'], ENT_QUOTES, 'UTF-8'); ?>"
                         data-borrow-status-label="<?php echo htmlspecialchars($borrowStatusLabel, ENT_QUOTES, 'UTF-8'); ?>"
                         data-checkin="<?php echo $r['checked_in_at'] ? htmlspecialchars((string)$r['checked_in_at'], ENT_QUOTES, 'UTF-8') : '—'; ?>"
                         data-return="<?php echo $r['returned_at'] ? htmlspecialchars((string)$r['returned_at'], ENT_QUOTES, 'UTF-8') : '—'; ?>"
                         data-equipments="<?php echo htmlspecialchars((string)$r['equipment_names'], ENT_QUOTES, 'UTF-8'); ?>"
                         data-spaces="<?php echo htmlspecialchars((string)$r['space_names'], ENT_QUOTES, 'UTF-8'); ?>"
                         data-stage="<?php echo htmlspecialchars((string)(isset($r['approval_status']) && $r['approval_status'] === 'approved' ? '已完成' : ($stageMap[$r['approval_stage'] ?? ''] ?? ($r['approval_stage'] ?? ''))), ENT_QUOTES, 'UTF-8'); ?>">
                        
                        <!-- 1. 單號 -->
                        <div>
                            <span class="record-id-pill">
                                <i class="fa-solid fa-hashtag text-xs"></i><?php echo (int)$r['reservation_id']; ?>
                            </span>
                        </div>

                        <!-- 2. 申請人 -->
                        <div class="min-w-0">
                            <div class="borrower-name-line">
                                <h5 class="font-bold text-slate-800 text-sm truncate group-hover:text-indigo-600 transition">
                                    <?php echo htmlspecialchars($r['full_name'], ENT_QUOTES, 'UTF-8'); ?>
                                </h5>
                                <span class="applicant-badge text-[10px] bg-slate-100 text-slate-500 font-mono px-1.5 py-0.5 rounded">
                                    <?php echo htmlspecialchars($r['applicant_user_id'], ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </div>
                        </div>

                        <!-- 3. 申請單位 -->
                        <div class="min-w-0">
                            <p class="font-semibold text-slate-700 truncate">
                                <?php echo htmlspecialchars((string)(($r['organization_name'] ?? '') !== '' ? $r['organization_name'] : '未填寫'), ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                        </div>

                        <!-- 4. 借用時段 -->
                        <div class="time-range-box text-xs leading-tight">
                            <div><i class="fa-regular fa-clock text-[10px] text-slate-400 mr-1.5"></i><?php echo htmlspecialchars((string)$r['borrow_start_at'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="end-time">至 <?php echo htmlspecialchars((string)$r['borrow_end_at'], ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>

                        <!-- 5. 操作提示 -->
                        <div class="text-center">
                            <button class="detail-button" onclick="event.stopPropagation(); openDrawer(event, this.closest('.history-list-row'))">查看詳情 <i class="fa-solid fa-chevron-right text-xs"></i></button>
                        </div>

                        <!-- 資源內容與審核狀態不在列表外層顯示，保留於右側詳情抽屜中 -->

                    </div>
                <?php } ?>
            <?php } ?>
        </div>

    </div>

    <!-- 3. 右側滑出詳細資訊面板 (Inspection Drawer Modal) -->
    <div id="drawer-overlay" class="fixed inset-0 bg-slate-900/40 z-40 hidden backdrop-blur-xs transition-opacity duration-300 opacity-0" onclick="closeDrawer()"></div>
    <div id="detail-drawer" style="display:none;" class="fixed right-0 top-0 bottom-0 w-[550px] bg-white border-l border-slate-200 z-50 p-8 shadow-2xl overflow-y-auto translate-x-full transition-transform duration-300 ease-out">
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
            <div class="drawer-card">
                <h4 class="drawer-section-title"><i class="fa-solid fa-user"></i>申請人與活動資訊</h4>
                <div class="applicant-summary">
                    <div class="applicant-avatar">
                        <i class="fa-solid fa-id-card"></i>
                    </div>
                    <div class="applicant-main">
                        <div class="applicant-name-row">
                            <span id="drawer-borrower-name">-</span>
                            <span class="drawer-id-badge" id="drawer-borrower-id">-</span>
                        </div>
                        <div class="drawer-email" id="drawer-borrower-email">-</div>

                        <div class="drawer-info-grid">
                            <div class="drawer-info-item">
                                <span class="drawer-label">申請單位 / 主辦單位</span>
                                <span class="drawer-value" id="drawer-organization-name">-</span>
                            </div>
                            <div class="drawer-info-item">
                                <span class="drawer-label">聯絡電話</span>
                                <span class="drawer-value" id="drawer-borrower-phone">-</span>
                            </div>
                            <div class="drawer-info-item full">
                                <span class="drawer-label">活動名稱</span>
                                <span class="drawer-value" id="drawer-activity-name">-</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 資源清單模組 -->
            <div class="drawer-card">
                <h4 class="drawer-section-title"><i class="fa-solid fa-box-open"></i>申借之資源項目</h4>
                <div class="resource-list-clean text-xs" id="drawer-resources-list">
                    <!-- 動態由 JS 塞入場地或器材列表 -->
                </div>
            </div>

            <!-- 簡潔版：借用時序追蹤 -->
            <div class="drawer-card">
                <h4 class="drawer-section-title"><i class="fa-regular fa-clock"></i>借用時序追蹤</h4>
                <div class="timeline-clean">
                    <div class="timeline-row-clean">
                        <span>申請送出時間</span>
                        <span id="drawer-submitted-time">-</span>
                    </div>
                    <div class="timeline-row-clean">
                        <span>借用開始時間</span>
                        <span id="drawer-start-time">-</span>
                    </div>
                    <div class="timeline-row-clean">
                        <span>借用結束時間</span>
                        <span id="drawer-end-time">-</span>
                    </div>
                </div>
            </div>

            <!-- 報到與歸還狀態核對 -->
            <div class="drawer-card">
                <h4 class="drawer-section-title"><i class="fa-solid fa-clipboard-check"></i>借用狀態確認</h4>
                <div class="status-grid-clean">
                    <div class="status-card-clean full">
                        <div class="status-icon-clean"><i class="fa-solid fa-clipboard-list"></i></div>
                        <div class="status-content-clean">
                            <span class="status-label-clean">借用紀錄狀態</span>
                            <p class="font-semibold text-slate-700 flex items-center gap-1.5" id="drawer-borrow-status">-</p>
                        </div>
                    </div>
                    <div class="status-card-clean">
                        <div class="status-icon-clean"><i class="fa-solid fa-user-check"></i></div>
                        <div class="status-content-clean">
                            <span class="status-label-clean">現場報到登記</span>
                            <p class="font-semibold text-slate-700 flex items-center gap-1.5" id="drawer-checkin-status">-</p>
                        </div>
                    </div>
                    <div class="status-card-clean">
                        <div class="status-icon-clean"><i class="fa-solid fa-box-archive"></i></div>
                        <div class="status-content-clean">
                            <span class="status-label-clean">歸還點收清點</span>
                            <p class="font-semibold text-slate-700 flex items-center gap-1.5" id="drawer-return-status">-</p>
                        </div>
                    </div>
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
            window.location.href = buildUrl({ q: qv, status: sv, type: type, start_date: document.getElementById('startDate')?.value || '', end_date: document.getElementById('endDate')?.value || '' });
        }));

        // 審核狀態 Select 下拉選單改動時
        document.getElementById('statusFilter')?.addEventListener('change', function(){
            const typeEl = document.querySelector('.tab.active') || document.querySelector('.tab[class*="bg-white"]');
            const type = typeEl ? typeEl.getAttribute('data-filter') : 'all';
            const qv = document.getElementById('q')?.value || '';
            window.location.href = buildUrl({ q: qv, status: this.value, type: type, start_date: document.getElementById('startDate')?.value || '', end_date: document.getElementById('endDate')?.value || '' });
        });

        // 日期區間篩選變更時
        ['startDate', 'endDate'].forEach(id => {
            document.getElementById(id)?.addEventListener('change', function(){
                const typeEl = document.querySelector('.tab.active') || document.querySelector('.tab[class*="bg-white"]');
                const type = typeEl ? typeEl.getAttribute('data-filter') : 'all';
                const qv = document.getElementById('q')?.value || '';
                const sv = document.getElementById('statusFilter')?.value || 'all';
                window.location.href = buildUrl({ q: qv, status: sv, type: type, start_date: document.getElementById('startDate')?.value || '', end_date: document.getElementById('endDate')?.value || '' });
            });
        });

        // 關鍵字搜尋 Enter 事件
        document.getElementById('q')?.addEventListener('keydown', function(e){
            if(e.key === 'Enter'){
                e.preventDefault();
                const sv = document.getElementById('statusFilter')?.value || 'all';
                const typeEl = document.querySelector('.tab.active') || document.querySelector('.tab[class*="bg-white"]');
                const type = typeEl ? typeEl.getAttribute('data-filter') : 'all';
                window.location.href = buildUrl({ q: this.value, status: sv, type: type, start_date: document.getElementById('startDate')?.value || '', end_date: document.getElementById('endDate')?.value || '' });
            }
        });

        // 控制右側側邊抽屜滑出與資訊塞入
        function openDrawer(evt, element) {
            // debug: log caller and event to help trace unexpected triggers
            try { console.log('openDrawer called', evt, element); console.trace(); } catch(e) {}
            // only respond to real user clicks (prevent programmatic or synthetic triggers)
            if (!evt || !evt.isTrusted) return;
            if (!element) return;
            const id = element.getAttribute('data-id');
            const name = element.getAttribute('data-borrower-name');
            const bId = element.getAttribute('data-borrower-id');
            const email = element.getAttribute('data-borrower-email');
            const start = element.getAttribute('data-start');
            const end = element.getAttribute('data-end');
            const submitted = element.getAttribute('data-submitted');
            const status = element.getAttribute('data-status');
            const statusLabel = element.getAttribute('data-status-label');
            const borrowStatusLabel = element.getAttribute('data-borrow-status-label') || '-';
            const checkin = element.getAttribute('data-checkin');
            const returned = element.getAttribute('data-return');
            const equipmentsStr = element.getAttribute('data-equipments');
            const spacesStr = element.getAttribute('data-spaces');
            const stage = element.getAttribute('data-stage') || 'N/A';
            const phone = element.getAttribute('data-coordinator-phone') || '';
            const orgName = element.getAttribute('data-organization-name') || '';
            const activityName = element.getAttribute('data-activity-name') || '';

            // 塞入基本文字
            document.getElementById('drawer-record-id').innerText = '單號: #' + id;
            document.getElementById('drawer-borrower-name').innerText = name;
            document.getElementById('drawer-borrower-id').innerText = bId;
            document.getElementById('drawer-borrower-email').innerText = email;
            const submittedEl = document.getElementById('drawer-submitted-time');
            const startEl = document.getElementById('drawer-start-time');
            const endEl = document.getElementById('drawer-end-time');
            if (submittedEl) submittedEl.innerText = submitted && submitted.trim() !== '' ? submitted : '-';
            if (startEl) startEl.innerText = start && start.trim() !== '' ? start : '-';
            if (endEl) endEl.innerText = end && end.trim() !== '' ? end : '-';
            const borrowStatusEl = document.getElementById('drawer-borrow-status');
            if (borrowStatusEl) {
                if (borrowStatusLabel === '已歸還') {
                    borrowStatusEl.innerHTML = `<i class="fa-solid fa-circle-check text-emerald-500 mr-1.5"></i> 已歸還`;
                } else if (borrowStatusLabel === '逾期未歸還') {
                    borrowStatusEl.innerHTML = `<i class="fa-solid fa-triangle-exclamation text-rose-500 mr-1.5"></i> 逾期未歸還`;
                } else {
                    borrowStatusEl.textContent = borrowStatusLabel;
                }
            }
            // contact / organization fields
            const phoneEl = document.getElementById('drawer-borrower-phone');
            const orgEl = document.getElementById('drawer-organization-name');
            const actEl = document.getElementById('drawer-activity-name');
            if (phoneEl) {
                if (phone && phone.trim() !== '') {
                    phoneEl.innerHTML = '<a href="tel:' + encodeURIComponent(phone) + '" class="text-indigo-600 font-medium">' + phone + '</a>';
                } else {
                    phoneEl.innerText = '-';
                }
            }
            if (orgEl) {
                orgEl.innerText = orgName && orgName.trim() !== '' ? orgName : '-';
            }
            if (actEl) {
                actEl.innerText = activityName && activityName.trim() !== '' ? activityName : '-';
            }
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
                    <div class="resource-item-clean">
                        <div class="resource-icon-clean"><i class="fa-solid fa-box"></i></div>
                        <p class="font-bold text-slate-800 text-xs">${eq}</p>
                    </div>
                `;
            });

            spaces.forEach(sp => {
                if (sp.trim() === '') return;
                resourcesContainer.innerHTML += `
                    <div class="resource-item-clean space">
                        <div class="resource-icon-clean"><i class="fa-solid fa-map-pin"></i></div>
                        <p class="font-bold text-slate-800 text-xs">${sp}</p>
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
            
            // ensure drawer element is visible (use display to avoid CSS utility mismatch)
            drawer.style.display = 'block';
            overlay.style.display = 'block';
            overlay.style.pointerEvents = 'auto';
            overlay.classList.remove('hidden');
            // kick off CSS transitions on next frame for smooth animation
            requestAnimationFrame(() => {
                overlay.classList.remove('opacity-0');
                // remove translate on next frame so transition runs
                requestAnimationFrame(() => drawer.classList.remove('translate-x-full'));
            });
        }

        function closeDrawer() {
            const overlay = document.getElementById('drawer-overlay');
            const drawer = document.getElementById('detail-drawer');

            overlay.classList.add('opacity-0');
            drawer.classList.add('translate-x-full');

            setTimeout(() => {
                overlay.classList.add('hidden');
                // hide from layout to prevent it showing due to CSS overrides
                try { drawer.style.display = 'none'; overlay.style.display = 'none'; overlay.style.pointerEvents = 'none'; } catch (e) {}
            }, 320);
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
            try { closeDrawer(); } catch (e) { /* ignore */ }
        });
    </script>
</body>
</html>