<?php
// Shared navbar include. Expects session available and optional variables:
// $displayName, $currentRole, $isManager, $isLoggedIn
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$displayName = $displayName ?? (string)($_SESSION['full_name'] ?? $_SESSION['user_id'] ?? '訪客');
$currentRole = $currentRole ?? (string)($_SESSION['role_name'] ?? '');
$isLoggedIn = $isLoggedIn ?? isset($_SESSION['user_id']);
$isManager = $isManager ?? in_array($currentRole, ['2','3','a','b','c'], true);
$handoverMenuLabel = in_array($currentRole, ['8','9'], true)
    ? ($currentRole === '8' ? '開門排程' : '器材交接排程')
    : '交接/開門排程';
?>
<nav class="navbar">
    <div class="navbar-brand">
        <h1>📚 校園資源租借系統</h1>
    </div>
    <div class="navbar-menu">
        <button class="nav-btn" onclick="location.href='index.php'">首頁</button>
        <button class="nav-btn" onclick="location.href='borrow.php'">我要租借</button>
        <button class="nav-btn" onclick="location.href='return_management.php'">我的申請</button>
        <?php if (in_array($currentRole, ['2','3','a','b','c','d'], true)) { ?>
            <button class="nav-btn" onclick="location.href='history_all.php'">歷史借用紀錄</button>
        <?php } ?>

        <?php if (in_array($currentRole, ['8','9'], true)) { ?>
            <button class="nav-btn" onclick="location.href='handover_schedule.php'"><?php echo htmlspecialchars($handoverMenuLabel, ENT_QUOTES, 'UTF-8'); ?></button>
        <?php } ?>

        <?php if ($isManager) { ?>
            <button class="nav-btn" onclick="location.href='approve.php'">審核面板</button>
            <?php if (in_array($currentRole, ['2','3'], true)) { ?>
                <button class="nav-btn" id="btnManualRemind" type="button" onclick="handleManualRemindClick(event)">檢查逾期並催繳</button>
                <button class="nav-btn" onclick="location.href='equipment_inventory.php'">庫存管理</button>
            <?php } ?>
        <?php if (in_array($currentRole, ['3'], true)) { ?>
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
