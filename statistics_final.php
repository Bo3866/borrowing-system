<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?next=statistics_final.php');
    exit;
}

$currentRole = (string)($_SESSION['role_name'] ?? '');
$displayName = (string)($_SESSION['full_name'] ?? $_SESSION['user_id'] ?? '');

$allowedRoles = ['2', '3', 'a', 'b', 'c', 'd'];
if (!in_array($currentRole, $allowedRoles, true)) {
    http_response_code(403);
    echo "<p style=\"padding:1rem;background:#ffecec;border-radius:6px;\">存取被拒：此功能僅限課指組老師或管理人員。</p>";
    exit;
}

$dbError = '';
$link = getMysqliConnection($dbError);

$yearFilter = (string)($_GET['year'] ?? 'all');
$typeFilter = (string)($_GET['type'] ?? 'overall');
if (!in_array($typeFilter, ['overall', 'equipment', 'space'], true)) {
    $typeFilter = 'overall';
}

$q = trim((string)($_GET['q'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

$historyPage = 'history_all.php';
$statisticsRuleText = '審核完成、已報到/領取，且借用結束時間已到的紀錄（不以是否準時歸還作為顯示條件）';

$totalReservations = 0;
$totalResourceBorrows = 0;
$totalDurationMinutes = 0;
$averageDurationMinutes = 0;

$highestResourceName = '-';
$highestDurationText = '0 小時';
$highestBorrowCount = 0;

$lowestResourceName = '-';
$lowestDurationText = '0 小時';
$lowestBorrowCount = 0;

$years = [];
$allResourceRows = [];
$equipmentRows = [];
$spaceRows = [];
$monthlyRows = [];

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function buildQuery(array $params): string
{
    $merged = array_merge($_GET, $params);
    return '?' . http_build_query($merged);
}

function formatDurationMinutes(int $minutes): string
{
    if ($minutes <= 0) {
        return '0 小時';
    }

    $hours = intdiv($minutes, 60);
    $mins = $minutes % 60;

    if ($hours > 0 && $mins > 0) {
        return $hours . ' 小時 ' . $mins . ' 分';
    }

    if ($hours > 0) {
        return $hours . ' 小時';
    }

    return $mins . ' 分';
}

function historyLink(string $historyPage, string $keyword, string $resourceType, string $dateFrom, string $dateTo): string
{
    $params = [
        'q' => $keyword,
        'status' => 'all',
        'type' => $resourceType,
    ];

    if ($dateFrom !== '') {
        $params['start_date'] = $dateFrom;
    }

    if ($dateTo !== '') {
        $params['end_date'] = $dateTo;
    }

    return $historyPage . '?' . http_build_query($params);
}

function historyIndexLink(string $historyPage, string $q, string $typeFilter, string $dateFrom, string $dateTo): string
{
    $historyType = ($typeFilter === 'equipment' || $typeFilter === 'space') ? $typeFilter : 'all';

    $params = [
        'q' => $q,
        'status' => 'all',
        'type' => $historyType,
    ];

    if ($dateFrom !== '') {
        $params['start_date'] = $dateFrom;
    }

    if ($dateTo !== '') {
        $params['end_date'] = $dateTo;
    }

    return $historyPage . '?' . http_build_query($params);
}

if ($dbError === '') {
    $yearSql = "
        SELECT DISTINCT YEAR(borrow_start_at) AS y
        FROM reservations
        WHERE approval_status = 'approved'
          AND borrow_end_at <= NOW()
          AND checked_in_at IS NOT NULL
          AND borrow_start_at IS NOT NULL
        ORDER BY y DESC
    ";

    $yearRes = mysqli_query($link, $yearSql);
    if ($yearRes) {
        while ($row = mysqli_fetch_assoc($yearRes)) {
            if (!empty($row['y'])) {
                $years[] = (string)$row['y'];
            }
        }
        mysqli_free_result($yearRes);
    }

    // 2.5 統計規則：
    // 只納入「審核完成、已報到/領取、借用結束時間已到」的紀錄。
    // 是否已歸還、是否準時歸還不作為 2.5 統計顯示條件。
    $whereReservation = [
        "r.approval_status = 'approved'",
        "r.borrow_end_at <= NOW()",
        "r.checked_in_at IS NOT NULL",
        "r.borrow_start_at IS NOT NULL",
        "r.borrow_end_at IS NOT NULL"
    ];

    if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $dateFromEsc = mysqli_real_escape_string($link, $dateFrom);
        $whereReservation[] = "DATE(r.borrow_start_at) >= '{$dateFromEsc}'";
    }

    if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $dateToEsc = mysqli_real_escape_string($link, $dateTo);
        $whereReservation[] = "DATE(r.borrow_end_at) <= '{$dateToEsc}'";
    }

    if ($yearFilter !== 'all' && preg_match('/^\d{4}$/', $yearFilter)) {
        $escYear = mysqli_real_escape_string($link, $yearFilter);
        $whereReservation[] = "YEAR(r.borrow_start_at) = '{$escYear}'";
    }

    $whereSql = implode(' AND ', $whereReservation);

    $totalSql = "
        SELECT COUNT(*) AS c
        FROM reservations r
        WHERE {$whereSql}
    ";

    $totalRes = mysqli_query($link, $totalSql);
    if ($totalRes) {
        $totalReservations = (int)mysqli_fetch_assoc($totalRes)['c'];
        mysqli_free_result($totalRes);
    }

    $escQ = '';
    if ($q !== '') {
        $escQ = mysqli_real_escape_string($link, $q);
    }

    $equipmentKeywordSql = ($q !== '')
        ? " AND (ec.equipment_name LIKE '%{$escQ}%' OR e.equipment_id LIKE '%{$escQ}%')"
        : '';

    $spaceKeywordSql = ($q !== '')
        ? " AND (s.space_name LIKE '%{$escQ}%' OR s.space_id LIKE '%{$escQ}%')"
        : '';

    $resourceUnionSql = "
        SELECT * FROM (
            SELECT
                'equipment' AS resource_type,
                '器材' AS type_label,
                COALESCE(ec.equipment_name, '未命名器材') AS resource_name,
                e.equipment_id AS resource_id,
                COUNT(*) AS borrow_count,
                SUM(TIMESTAMPDIFF(MINUTE, r.borrow_start_at, r.borrow_end_at)) AS total_duration_minutes,
                MAX(r.borrow_start_at) AS last_used_at
            FROM equipment_reservation_items eri
            JOIN reservations r ON r.reservation_id = eri.reservation_id
            JOIN equipments e ON e.equipment_id = eri.equipment_id
            LEFT JOIN equipment_categories ec ON ec.equipment_code = e.equipment_code
            WHERE {$whereSql}
            {$equipmentKeywordSql}
            GROUP BY e.equipment_id, ec.equipment_name

            UNION ALL

            SELECT
                'space' AS resource_type,
                '場地' AS type_label,
                COALESCE(s.space_name, '未命名場地') AS resource_name,
                s.space_id AS resource_id,
                COUNT(*) AS borrow_count,
                SUM(TIMESTAMPDIFF(MINUTE, r.borrow_start_at, r.borrow_end_at)) AS total_duration_minutes,
                MAX(r.borrow_start_at) AS last_used_at
            FROM space_reservation_items sri
            JOIN reservations r ON r.reservation_id = sri.reservation_id
            JOIN spaces s ON s.space_id = sri.space_id
            WHERE {$whereSql}
            {$spaceKeywordSql}
            GROUP BY s.space_id, s.space_name
        ) resource_summary
        ORDER BY total_duration_minutes DESC, borrow_count DESC, last_used_at DESC
    ";

    $resourceRes = mysqli_query($link, $resourceUnionSql);
    if ($resourceRes) {
        while ($row = mysqli_fetch_assoc($resourceRes)) {
            $allResourceRows[] = $row;

            if ((string)$row['resource_type'] === 'equipment') {
                $equipmentRows[] = $row;
            } elseif ((string)$row['resource_type'] === 'space') {
                $spaceRows[] = $row;
            }
        }
        mysqli_free_result($resourceRes);
    } else {
        $dbError = '讀取資源統計失敗：' . mysqli_error($link);
    }

    foreach ($allResourceRows as $row) {
        $totalDurationMinutes += (int)($row['total_duration_minutes'] ?? 0);
        $totalResourceBorrows += (int)($row['borrow_count'] ?? 0);
    }

    if ($totalResourceBorrows > 0) {
        $averageDurationMinutes = (int)round($totalDurationMinutes / $totalResourceBorrows);
    }

    if (!empty($allResourceRows)) {
        $top = $allResourceRows[0];
        $bottom = $allResourceRows[count($allResourceRows) - 1];

        $highestResourceName = (string)$top['resource_name'];
        $highestDurationText = formatDurationMinutes((int)($top['total_duration_minutes'] ?? 0));
        $highestBorrowCount = (int)($top['borrow_count'] ?? 0);

        $lowestResourceName = (string)$bottom['resource_name'];
        $lowestDurationText = formatDurationMinutes((int)($bottom['total_duration_minutes'] ?? 0));
        $lowestBorrowCount = (int)($bottom['borrow_count'] ?? 0);
    }

    $monthlySql = "
        SELECT
            ym,
            SUM(duration_minutes) AS total_duration_minutes,
            SUM(resource_count) AS resource_count
        FROM (
            SELECT
                DATE_FORMAT(r.borrow_start_at, '%Y-%m') AS ym,
                SUM(TIMESTAMPDIFF(MINUTE, r.borrow_start_at, r.borrow_end_at)) AS duration_minutes,
                COUNT(*) AS resource_count
            FROM equipment_reservation_items eri
            JOIN reservations r ON r.reservation_id = eri.reservation_id
            JOIN equipments e ON e.equipment_id = eri.equipment_id
            LEFT JOIN equipment_categories ec ON ec.equipment_code = e.equipment_code
            WHERE {$whereSql}
            {$equipmentKeywordSql}
            GROUP BY DATE_FORMAT(r.borrow_start_at, '%Y-%m')

            UNION ALL

            SELECT
                DATE_FORMAT(r.borrow_start_at, '%Y-%m') AS ym,
                SUM(TIMESTAMPDIFF(MINUTE, r.borrow_start_at, r.borrow_end_at)) AS duration_minutes,
                COUNT(*) AS resource_count
            FROM space_reservation_items sri
            JOIN reservations r ON r.reservation_id = sri.reservation_id
            JOIN spaces s ON s.space_id = sri.space_id
            WHERE {$whereSql}
            {$spaceKeywordSql}
            GROUP BY DATE_FORMAT(r.borrow_start_at, '%Y-%m')
        ) monthly_summary
        GROUP BY ym
        ORDER BY ym DESC
        LIMIT 12
    ";

    $monthlyRes = mysqli_query($link, $monthlySql);
    if ($monthlyRes) {
        while ($row = mysqli_fetch_assoc($monthlyRes)) {
            $monthlyRows[] = $row;
        }
        mysqli_free_result($monthlyRes);
    }

    if (isset($_GET['export']) && $_GET['export'] === 'excel') {
        $filename = 'resource_statistics_' . date('Ymd_His') . '.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "\xEF\xBB\xBF";

        $out = fopen('php://output', 'w');

        fputcsv($out, ['資源統計分析報表']);
        fputcsv($out, ['匯出時間', date('Y-m-d H:i:s')]);
        fputcsv($out, ['統計規則', $statisticsRuleText]);
        fputcsv($out, ['頁面類型', $typeFilter]);
        fputcsv($out, ['年份篩選', $yearFilter]);
        fputcsv($out, ['開始日期', $dateFrom]);
        fputcsv($out, ['結束日期', $dateTo]);
        fputcsv($out, ['關鍵字', $q]);
        fputcsv($out, []);

        if ($typeFilter === 'overall') {
            fputcsv($out, ['總體分析']);
            fputcsv($out, ['總使用時長', formatDurationMinutes($totalDurationMinutes)]);
            fputcsv($out, ['平均每筆資源使用時長', formatDurationMinutes($averageDurationMinutes)]);
            fputcsv($out, ['最高使用資源', $highestResourceName]);
            fputcsv($out, ['最高使用資源累計時長', $highestDurationText]);
            fputcsv($out, ['最高使用資源借用紀錄數', $highestBorrowCount]);
            fputcsv($out, ['低使用資源', $lowestResourceName]);
            fputcsv($out, ['低使用資源累計時長', $lowestDurationText]);
            fputcsv($out, ['低使用資源借用紀錄數', $lowestBorrowCount]);
            fputcsv($out, []);
            fputcsv($out, ['近 12 個月總使用時長趨勢']);
            fputcsv($out, ['月份', '資源使用筆數', '總使用時長']);
            foreach ($monthlyRows as $row) {
                fputcsv($out, [
                    $row['ym'],
                    $row['resource_count'],
                    formatDurationMinutes((int)($row['total_duration_minutes'] ?? 0)),
                ]);
            }
        } elseif ($typeFilter === 'equipment') {
            fputcsv($out, ['器材總使用時長排行']);
            fputcsv($out, ['排名', '器材名稱', '器材編號', '總使用時長']);
            $rank = 1;
            foreach ($equipmentRows as $row) {
                fputcsv($out, [
                    $rank++,
                    $row['resource_name'],
                    $row['resource_id'],
                    formatDurationMinutes((int)($row['total_duration_minutes'] ?? 0)),
                ]);
            }
        } else {
            fputcsv($out, ['場地總使用時長排行']);
            fputcsv($out, ['排名', '場地名稱', '場地編號', '總使用時長']);
            $rank = 1;
            foreach ($spaceRows as $row) {
                fputcsv($out, [
                    $rank++,
                    $row['resource_name'],
                    $row['resource_id'],
                    formatDurationMinutes((int)($row['total_duration_minutes'] ?? 0)),
                ]);
            }
        }

        fclose($out);
        exit;
    }
}

if ($link) {
    mysqli_close($link);
}

$currentTypeTitle = '總體分析';
if ($typeFilter === 'equipment') {
    $currentTypeTitle = '器材分析';
} elseif ($typeFilter === 'space') {
    $currentTypeTitle = '場地分析';
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>資源統計分析｜全校器材與場地租借系統</title>

    <script src="https://cdn.tailwindcss.com"></script>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@300;400;500;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">

    <style>
        body {
            font-family: 'Noto Sans TC', sans-serif;
        }

        body.history-page {
            background:
                radial-gradient(circle at 8% 10%, #fff5d9 0%, rgba(255, 245, 217, 0) 45%),
                radial-gradient(circle at 90% 5%, #d6eefc 0%, rgba(214, 238, 252, 0) 40%),
                linear-gradient(160deg, #f8fbff 0%, #eef5fa 50%, #fdfdfd 100%);
        }

        .container.main-content {
            max-width: 1200px !important;
            margin: 0 auto !important;
            padding: 0 1rem !important;
        }

        .history-title-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }

        .page-header h2 {
            margin-bottom: 0;
        }

        .module-switch,
        .resource-tabs {
            display: flex;
            background: #f1f5f9;
            padding: .25rem;
            border-radius: .75rem;
            border: 1px solid #e2e8f0;
            width: fit-content;
            gap: .15rem;
        }

        .module-switch a,
        .resource-tabs a {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            padding: .55rem .9rem;
            border-radius: .55rem;
            font-size: .95rem;
            font-weight: 800;
            text-decoration: none;
            color: #64748b;
            transition: .18s ease;
            white-space: nowrap;
        }

        .module-switch a:hover,
        .resource-tabs a:hover {
            color: #334155;
            background: #ffffff;
        }

        .module-switch a.active,
        .resource-tabs a.active {
            background: #ffffff;
            color: #0f172a;
            box-shadow: 0 4px 12px rgba(15, 23, 42, .06);
            border: 1px solid rgba(226, 232, 240, .7);
        }

        .module-switch .icon-history {
            color: #4f46e5;
        }

        .module-switch .icon-stats {
            color: #059669;
        }

        .resource-tabs .icon-overall {
            color: #d97706;
        }

        .resource-tabs .icon-equipment {
            color: #2563eb;
        }

        .resource-tabs .icon-space {
            color: #059669;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 1.25rem;
            margin-bottom: 1.25rem;
        }

        .dashboard-grid .card {
            min-height: 122px;
            overflow: hidden;
        }

        .dashboard-grid .card > div {
            width: 100%;
        }

        .card,
        .filter-card,
        .section-title-card,
        .trend-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 1rem;
            box-shadow: 0 8px 24px rgba(15, 23, 42, .04);
        }

        .card {
            padding: 1.25rem;
        }

        .filter-card {
            padding: 1rem;
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.25rem;
        }

        .filter-main-group {
            display: flex;
            flex-wrap: wrap;
            gap: .75rem;
            align-items: center;
            flex: 1;
        }

        .filter-action-group {
            display: flex;
            flex-wrap: wrap;
            gap: .5rem;
            align-items: center;
            justify-content: flex-end;
        }

        .filter-input,
        .filter-select {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            color: #334155;
            border-radius: .75rem;
            padding: .75rem 1rem;
            outline: none;
            transition: .18s ease;
        }

        .filter-input:focus,
        .filter-select:focus {
            border-color: #6366f1;
            background: #ffffff;
        }

        .title-action-btn {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            padding: .65rem .9rem;
            border-radius: .9rem;
            font-size: .95rem;
            font-weight: 800;
            text-decoration: none;
            border: 1px solid #e2e8f0;
            background: #ffffff;
            color: #475569;
            box-shadow: 0 6px 16px rgba(15, 23, 42, .05);
            transition: .18s ease;
        }

        .title-action-btn:hover {
            transform: translateY(-1px);
            border-color: #c7d2fe;
            color: #4f46e5;
        }

        .title-action-btn.success {
            background: #059669;
            color: #ffffff;
            border-color: #059669;
        }

        .section-title-card {
            padding: 1rem 1.25rem;
            margin-bottom: .75rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .stats-list-header,
        .stats-list-row {
            display: grid;
            grid-template-columns: 90px minmax(260px, 1.4fr) 170px 150px;
            gap: 1rem;
            align-items: center;
        }

        .stats-list-header {
            background: #1e293b;
            color: #e2e8f0;
            padding: 1rem 1.5rem;
            border-radius: 1rem 1rem 0 0;
            font-size: .95rem;
            font-weight: 700;
        }

        .stats-list-row {
            background: #ffffff;
            padding: 1rem 1.5rem;
            border: 1px solid #e2e8f0;
            border-radius: 1rem;
            transition: .18s ease;
        }

        .stats-list-row:hover {
            border-color: #a5b4fc;
            box-shadow: 0 10px 24px rgba(15, 23, 42, .08);
            transform: translateY(-1px);
        }

        .rank-badge {
            width: 34px;
            height: 34px;
            border-radius: .8rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            background: #eef2ff;
            color: #4f46e5;
            border: 1px solid #c7d2fe;
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
            text-decoration: none;
        }

        .stats-list-row:hover .detail-button {
            background: #4f46e5;
            color: #ffffff;
            border-color: #4f46e5;
        }

        .trend-card {
            overflow: hidden;
        }

        @media(max-width: 1024px) {
            .dashboard-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media(max-width: 900px) {
            .stats-list-header {
                display: none;
            }

            .stats-list-row {
                grid-template-columns: 1fr;
                gap: .6rem;
            }

            .filter-main-group,
            .filter-action-group,
            .resource-tabs {
                width: 100%;
            }

            .resource-tabs a {
                flex: 1;
                justify-content: center;
            }

            .filter-input,
            .filter-select {
                width: 100%;
                min-width: 100% !important;
            }
        }

        @media(max-width: 640px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }

            .module-switch {
                width: 100%;
            }

            .module-switch a {
                flex: 1;
                justify-content: center;
            }
        }


        .module-switch a { min-width: 150px; justify-content: center; }
        .top-filter-panel { margin-bottom: 1.25rem; }

        

        /* 統一 2.4 / 2.5 頁面標題位置，避免切換時標題上下跳動 */
        .container.main-content {
            padding-top: 1.25rem !important;
        }

        .page-header.history-title-bar {
            margin: 0 0 1.25rem 0 !important;
            padding: 0 !important;
            min-height: 72px;
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
            background: transparent !important;
            border: 0 !important;
            box-shadow: none !important;
        }

        .page-header.history-title-bar > div:first-child {
            min-height: 64px;
            display: flex;
            align-items: center;
        }

        .page-header.history-title-bar h2 {
            margin: 0 !important;
            padding: 0 0 .55rem 0 !important;
            font-size: 2rem !important;
            line-height: 1.2 !important;
            font-weight: 800 !important;
            color: #0f172a !important;
            border-bottom: 4px solid #2563eb;
            width: fit-content;
        }

        .page-header.history-title-bar .module-switch {
            margin: 0 !important;
            align-self: center !important;
        }

        @media(max-width: 640px) {
            .page-header.history-title-bar {
                min-height: auto;
                align-items: flex-start !important;
            }
            .page-header.history-title-bar > div:first-child {
                min-height: auto;
            }
            .page-header.history-title-bar h2 {
                font-size: 1.55rem !important;
            }
        }

        @media print {
            nav,
            .no-print {
                display: none !important;
            }

            body {
                background: #ffffff;
            }

            .container.main-content {
                max-width: 100% !important;
                padding: 0 !important;
            }

            .card,
            .filter-card,
            .section-title-card,
            .trend-card {
                box-shadow: none !important;
                break-inside: avoid;
            }
        }
    </style>
</head>

<body class="history-page">

    <?php include __DIR__ . '/nav.php'; ?>

<div class="container main-content">

    <div class="page-header history-title-bar">
        <div>
            <h2 class="text-2xl font-bold">資源統計分析</h2>
        </div>

        <div class="module-switch no-print">
            <a href="<?php echo h(historyIndexLink($historyPage, $q, $typeFilter, $dateFrom, $dateTo)); ?>">
                <i class="fa-solid fa-clock-rotate-left icon-history"></i>
                借用紀錄查詢
            </a>

            <a href="statistics_final.php" class="active">
                <i class="fa-solid fa-chart-column icon-stats"></i>
                資源統計分析
            </a>
        </div>
    </div>

    <?php if ($dbError !== '') { ?>
        <div class="bg-rose-50 border border-rose-200 text-rose-800 p-4 rounded-xl text-sm flex items-center gap-3 mb-4">
            <i class="fa-solid fa-circle-exclamation text-rose-500 text-lg"></i>
            <span><?php echo h($dbError); ?></span>
        </div>
    <?php } ?>

    <form method="get" class="filter-card no-print">
            <div class="filter-main-group">
                <div class="relative">
                    <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                    <input type="text" name="q" value="<?php echo h($q); ?>" placeholder="搜尋器材名稱、場地名稱或編號" class="filter-input" style="padding-left:2.5rem;min-width:300px;">
                </div>
    
                <input type="date" name="date_from" value="<?php echo h($dateFrom); ?>" class="filter-input" title="開始日期">
    
                <span style="color:#64748b;font-weight:700;">～</span>
    
                <input type="date" name="date_to" value="<?php echo h($dateTo); ?>" class="filter-input" title="結束日期">
    
                <select name="year" class="filter-select">
                    <option value="all" <?php echo ($yearFilter === 'all') ? 'selected' : ''; ?>>所有年份</option>
                    <?php foreach ($years as $year) { ?>
                        <option value="<?php echo h($year); ?>" <?php echo ($yearFilter === $year) ? 'selected' : ''; ?>>
                            <?php echo h($year); ?> 年
                        </option>
                    <?php } ?>
                </select>
    
                <div class="resource-tabs">
                    <a href="<?php echo h(buildQuery(['type' => 'overall'])); ?>" class="<?php echo ($typeFilter === 'overall') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-chart-pie icon-overall"></i>
                        總體分析
                    </a>
                    <a href="<?php echo h(buildQuery(['type' => 'equipment'])); ?>" class="<?php echo ($typeFilter === 'equipment') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-laptop icon-equipment"></i>
                        器材分析
                    </a>
                    <a href="<?php echo h(buildQuery(['type' => 'space'])); ?>" class="<?php echo ($typeFilter === 'space') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-map-location-dot icon-space"></i>
                        場地分析
                    </a>
                </div>
            </div>
    
            <div class="filter-action-group">
                <button type="submit" class="title-action-btn success" style="cursor:pointer;">
                    <i class="fa-solid fa-filter"></i>
                    套用篩選
                </button>
    
                <a href="statistics_final.php?type=overall" class="title-action-btn">清除</a>
    
                <a href="<?php echo h(buildQuery(['export' => 'excel'])); ?>" class="title-action-btn">
                    <i class="fa-solid fa-file-excel"></i>
                    匯出 Excel
                </a>
    
                <button type="button" onclick="window.print()" class="title-action-btn" style="cursor:pointer;">
                    <i class="fa-solid fa-file-pdf"></i>
                    匯出 PDF
                </button>
            </div>
        </form>

    <?php if ($typeFilter === 'overall') { ?>
    <div class="dashboard-grid">
        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <div>
                    <p style="color:#64748b;margin:0;font-weight:600;">總使用時長</p>
                    <h3 style="font-size:1.35rem;margin:6px 0;color:#334155;font-weight:800;"><?php echo h(formatDurationMinutes($totalDurationMinutes)); ?></h3>
                    <p style="font-size:.85rem;color:#94a3b8;margin:0;">統計區間內累計使用時間</p>
                </div>
                <div style="width:44px;height:44px;border-radius:10px;background:#eef2ff;display:flex;align-items:center;justify-content:center;color:#4f46e5;border:1px solid rgba(79,70,229,.10);">
                    <i class="fa-solid fa-clock"></i>
                </div>
            </div>
        </div>

        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <div>
                    <p style="color:#64748b;margin:0;font-weight:600;">平均每筆時長</p>
                    <h3 style="font-size:1.35rem;margin:6px 0;color:#2563eb;font-weight:800;"><?php echo h(formatDurationMinutes($averageDurationMinutes)); ?></h3>
                    <p style="font-size:.85rem;color:#2563eb;margin:0;">共 <?php echo number_format($totalResourceBorrows); ?> 筆資源使用</p>
                </div>
                <div style="width:44px;height:44px;border-radius:10px;background:#eff6ff;display:flex;align-items:center;justify-content:center;color:#2563eb;border:1px solid rgba(37,99,235,.10);">
                    <i class="fa-solid fa-chart-simple"></i>
                </div>
            </div>
        </div>

        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <div>
                    <p style="color:#64748b;margin:0;font-weight:600;">最高使用資源</p>
                    <h3 style="font-size:1rem;margin:6px 0;color:#d97706;font-weight:800;line-height:1.35;">
                        <?php echo h($highestResourceName); ?><br>
                        <span style="font-size:.92rem;">累計使用 <?php echo h($highestDurationText); ?></span><br>
                        <span style="font-size:.86rem;color:#92400e;">共 <?php echo number_format($highestBorrowCount); ?> 筆借用紀錄</span>
                    </h3>
                </div>
                <div style="width:44px;height:44px;border-radius:10px;background:#fff7ed;display:flex;align-items:center;justify-content:center;color:#d97706;border:1px solid rgba(217,119,6,.10);">
                    <i class="fa-solid fa-ranking-star"></i>
                </div>
            </div>
        </div>

        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <div>
                    <p style="color:#64748b;margin:0;font-weight:600;">低使用資源</p>
                    <h3 style="font-size:1rem;margin:6px 0;color:#64748b;font-weight:800;line-height:1.35;">
                        <?php echo h($lowestResourceName); ?><br>
                        <span style="font-size:.92rem;">累計使用 <?php echo h($lowestDurationText); ?></span><br>
                        <span style="font-size:.86rem;color:#475569;">共 <?php echo number_format($lowestBorrowCount); ?> 筆借用紀錄</span>
                    </h3>
                </div>
                <div style="width:44px;height:44px;border-radius:10px;background:#f8fafc;display:flex;align-items:center;justify-content:center;color:#64748b;border:1px solid rgba(100,116,139,.12);">
                    <i class="fa-solid fa-box-archive"></i>
                </div>
            </div>
        </div>
    </div>
    <?php } ?>
    <?php if ($typeFilter === 'overall') { ?>
        <div class="grid grid-cols-1 gap-5 mb-8">
            <div class="trend-card">
                <div class="px-5 py-4 border-b border-slate-100">
                    <h3 class="font-bold text-slate-800 text-lg">近 12 個月總使用時長趨勢</h3>
                    <p class="text-sm text-slate-400">依月份觀察整體資源使用情形。</p>
                </div>

                <div class="p-5 space-y-3">
                    <?php if (count($monthlyRows) === 0) { ?>
                        <p class="text-slate-400 text-center py-8">目前沒有月份統計資料。</p>
                    <?php } else { ?>
                        <?php
                        $maxMonthly = 1;
                        foreach ($monthlyRows as $row) {
                            $maxMonthly = max($maxMonthly, (int)$row['total_duration_minutes']);
                        }
                        ?>
                        <?php foreach ($monthlyRows as $row) {
                            $width = ((int)$row['total_duration_minutes'] / $maxMonthly) * 100;
                        ?>
                            <div>
                                <div class="flex justify-between text-sm mb-1">
                                    <span class="font-bold text-slate-700"><?php echo h((string)$row['ym']); ?></span>
                                    <span class="text-slate-500">
                                        <?php echo h(formatDurationMinutes((int)($row['total_duration_minutes'] ?? 0))); ?>
                                        ／<?php echo number_format((int)$row['resource_count']); ?> 筆
                                    </span>
                                </div>
                                <div class="h-3 bg-slate-100 rounded-full overflow-hidden">
                                    <div class="h-full bg-indigo-500 rounded-full" style="width: <?php echo $width; ?>%;"></div>
                                </div>
                            </div>
                        <?php } ?>
                    <?php } ?>
                </div>
            </div>
        </div>
    <?php } ?>

    <?php if ($typeFilter === 'equipment') { ?>
        <div class="mb-8">
            <div class="section-title-card">
                <div>
                    <h3 class="font-bold text-slate-800 text-lg">器材總使用時長排行</h3>
                    <p class="text-sm text-slate-400">依器材累計使用時長由高到低排序，可作為維護、汰換與採購參考。</p>
                </div>
                <span class="text-sm text-slate-400">Top <?php echo min(50, count($equipmentRows)); ?></span>
            </div>

            <div class="stats-list-header">
                <div>排名</div>
                <div>器材名稱</div>
                <div class="text-center">總使用時長</div>
                <div class="text-center">操作</div>
            </div>

            <div class="space-y-2">
                <?php if (count($equipmentRows) === 0) { ?>
                    <div class="bg-white p-16 text-center rounded-b-xl border border-slate-200 border-t-0">
                        <i class="fa-solid fa-box-open text-4xl text-slate-300 mb-3"></i>
                        <p class="text-sm text-slate-500">目前沒有符合條件的器材統計資料。</p>
                    </div>
                <?php } else { ?>
                    <?php foreach (array_slice($equipmentRows, 0, 50) as $index => $row) { ?>
                        <div class="stats-list-row">
                            <div><span class="rank-badge"><?php echo $index + 1; ?></span></div>
                            <div class="font-bold text-slate-800"><?php echo h((string)$row['resource_name']); ?></div>
                            <div class="text-center text-blue-700 font-bold"><?php echo h(formatDurationMinutes((int)($row['total_duration_minutes'] ?? 0))); ?></div>
                            <div class="text-center no-print">
                                <a href="<?php echo h(historyLink($historyPage, (string)$row['resource_name'], 'equipment', $dateFrom, $dateTo)); ?>"
                                   class="detail-button"
                                   title="前往 2.4 查看所有包含此器材的申請">
                                    <i class="fa-solid fa-eye"></i>
                                    查看詳情
                                </a>
                            </div>
                        </div>
                    <?php } ?>
                <?php } ?>
            </div>
        </div>
    <?php } ?>

    <?php if ($typeFilter === 'space') { ?>
        <div class="mb-8">
            <div class="section-title-card">
                <div>
                    <h3 class="font-bold text-slate-800 text-lg">場地總使用時長排行</h3>
                    <p class="text-sm text-slate-400">依場地累計使用時長由高到低排序，可作為排程壓力與空間規劃參考。</p>
                </div>
                <span class="text-sm text-slate-400">Top <?php echo min(50, count($spaceRows)); ?></span>
            </div>

            <div class="stats-list-header">
                <div>排名</div>
                <div>場地名稱</div>
                <div class="text-center">總使用時長</div>
                <div class="text-center">操作</div>
            </div>

            <div class="space-y-2">
                <?php if (count($spaceRows) === 0) { ?>
                    <div class="bg-white p-16 text-center rounded-b-xl border border-slate-200 border-t-0">
                        <i class="fa-solid fa-map-location-dot text-4xl text-slate-300 mb-3"></i>
                        <p class="text-sm text-slate-500">目前沒有符合條件的場地統計資料。</p>
                    </div>
                <?php } else { ?>
                    <?php foreach (array_slice($spaceRows, 0, 50) as $index => $row) { ?>
                        <div class="stats-list-row">
                            <div><span class="rank-badge"><?php echo $index + 1; ?></span></div>
                            <div class="font-bold text-slate-800"><?php echo h((string)$row['resource_name']); ?></div>
                            <div class="text-center text-emerald-700 font-bold"><?php echo h(formatDurationMinutes((int)($row['total_duration_minutes'] ?? 0))); ?></div>
                            <div class="text-center no-print">
                                <a href="<?php echo h(historyLink($historyPage, (string)$row['resource_name'], 'space', $dateFrom, $dateTo)); ?>"
                                   class="detail-button"
                                   title="前往 2.4 查看所有包含此場地的申請">
                                    <i class="fa-solid fa-eye"></i>
                                    查看詳情
                                </a>
                            </div>
                        </div>
                    <?php } ?>
                <?php } ?>
            </div>
        </div>
    <?php } ?>

</div>

</body>
</html>
