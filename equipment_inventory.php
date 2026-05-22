<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/config/database.php';

// 檢查是否登入與權限 (2=管理員, 3=高階管理員)
$isLoggedIn = isset($_SESSION['user_id']);
$currentRole = (string)($_SESSION['role_name'] ?? '');
if (!$isLoggedIn || !in_array($currentRole, ['2', '3'], true)) {
    die('<p style="padding:1rem;background:#ffecec;border-radius:6px;">存取被拒：此功能僅限課指組老師。</p>');
}

$dbConnected = false;
$pdo = null;
try {
    $pdo = getDatabaseConnection();
    $dbConnected = true;
} catch (Exception $e) {
    die("Database connection failed: " . htmlspecialchars($e->getMessage()));
}

// 處理 AJAX 請求：獲取即將刪除的器材資訊
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_equipment') {
    header('Content-Type: application/json; charset=utf-8');
    $eqId = (int)($_GET['equipment_id'] ?? 0);
    
    $stmt = $pdo->prepare("
        SELECT e.equipment_id, e.equipment_code, c.equipment_name 
        FROM equipments e
        JOIN equipment_categories c ON e.equipment_code = c.equipment_code
        WHERE e.equipment_id = ?
    ");
    $stmt->execute([$eqId]);
    $row = $stmt->fetch();
    
    if ($row) {
        echo json_encode(['success' => true, 'data' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => '找不到此器材編號']);
    }
    exit;
}

$message = '';
$error = '';

// 處理入庫與刪除
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    // 判斷是否為 AJAX 請求
    $isAjax = !empty($_POST['is_ajax']);

    if ($action === 'add') {
        $addedDate = $_POST['added_date'] ?? '';
        $cartItemsJson = $_POST['cart_items'] ?? '';
        $cartItems = json_decode($cartItemsJson, true);

        if (empty($addedDate) || empty($cartItems) || !is_array($cartItems)) {
            $error = '請填寫完整入庫資料並選擇至少一個器材！';
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO equipments (equipment_code, added_date, operation_status) VALUES (?, ?, 1)");
                $insertedCnt = 0;
                $insertedIds = [];
                
                foreach ($cartItems as $item) {
                    $code = $item['code'] ?? '';
                    $qty = (int)($item['quantity'] ?? 1);
                    if ($code && $qty > 0) {
                        for ($i = 0; $i < $qty; $i++) {
                            $stmt->execute([$code, $addedDate]);
                            if (count($insertedIds) < 10) {
                                $insertedIds[] = $pdo->lastInsertId();
                            }
                            $insertedCnt++;
                        }
                    }
                }
                
                $pdo->commit();
                
                if ($insertedCnt > 0) {
                    $idStr = implode(', ', $insertedIds) . ($insertedCnt > 10 ? ' 等...' : '');
                    $message = "成功入庫 {$insertedCnt} 件器材！流水編號：{$idStr}";
                } else {
                    $error = '入庫數量為 0，請確認數量。';
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = '入庫失敗：' . htmlspecialchars($e->getMessage());
            }
        }
    } elseif ($action === 'delete') {
        $equipmentId = (int)($_POST['equipment_id'] ?? 0);
        if ($equipmentId <= 0) {
            $error = '無效的器材編號！';
        } else {
            try {
                $stmt = $pdo->prepare("DELETE FROM equipments WHERE equipment_id = ?");
                $stmt->execute([$equipmentId]);
                if ($stmt->rowCount() > 0) {
                    $message = "器材編號 {$equipmentId} 已成功刪除！";
                } else {
                    $error = '刪除失敗：找不到該器材編號。';
                }
            } catch (Exception $e) {
                $error = '刪除失敗：可能該器材已有相關紀錄而無法刪除。詳細錯誤: ' . htmlspecialchars($e->getMessage());
            }
        }
    }

    // 若為 AJAX 請求則拋出 JSON，中斷程式
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => empty($error),
            'message' => empty($error) ? $message : $error
        ]);
        exit;
    }
}

// 取得所有器材總類
$categories = [];
try {
    $stmt = $pdo->query("SELECT equipment_code, equipment_name FROM equipment_categories ORDER BY equipment_code ASC");
    $categories = $stmt->fetchAll();
} catch (Exception $e) {
    $error = "無法載入器材總類: " . htmlspecialchars($e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>庫存管理 (課指組老師)</title>
    <link rel="stylesheet" href="styles.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .inventory-wrapper {
            max-width: 1200px;
            width: 100%;
            margin: 0 auto;
            padding: 20px 15px;
        }
        h2 {
            color: var(--primary-color);
            font-weight: 700;
            letter-spacing: 1px;
            text-shadow: 0 2px 4px rgba(0,0,0,0.02);
            margin-bottom: 2rem;
        }
        .card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.04);
            padding: 2.5rem;
            transition: all 0.3s ease;
            border: 1px solid rgba(226, 232, 240, 0.6);
        }
        .card:hover {
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.08);
        }
        .form-group {
            margin-bottom: 1.8rem;
        }
        .form-group label {
            display: block;
            margin-bottom: 0.6rem;
            font-weight: 600;
            color: #475569;
            font-size: 0.95rem;
        }
        .form-group input[type="text"], 
        .form-group input[type="number"], 
        .form-group input[type="date"], 
        .form-group select {
            width: 100%;
            padding: 0.85rem 1rem;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            font-family: inherit;
            font-size: 1rem;
            background-color: #f8fafc;
            color: #334155;
            transition: all 0.25s ease;
        }
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            background-color: #fff;
            border-color: #3b82f6;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.15);
        }
        button.btn-primary {
            padding: 0.85rem 1.8rem;
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: #fff;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
            transition: all 0.25s ease;
        }
        button.btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(37, 99, 235, 0.3);
        }
        button.btn-primary:active {
            transform: translateY(0);
        }
        button.btn-danger {
            padding: 0.85rem 1.8rem;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: #fff;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.2);
            transition: all 0.25s ease;
        }
        button.btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(239, 68, 68, 0.3);
        }
        button.btn-secondary {
            padding: 0.85rem 1.8rem;
            background: linear-gradient(135deg, #94a3b8, #64748b);
            color: #fff;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(100, 116, 139, 0.2);
            transition: all 0.25s ease;
        }
        button.btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(100, 116, 139, 0.3);
        }
        .msg-alert {
            padding: 1rem 1.5rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.02);
            animation: slideDown 0.3s ease-out;
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .msg-success {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }
        .msg-error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        
        /* 購物車區塊樣式 */
        .equipment-selector-container {
            display: flex;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            overflow: hidden;
            flex-direction: column;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
            transition: all 0.3s ease;
        }
        .equipment-selector-container:hover {
            border-color: #cbd5e1;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.06);
        }
        @media(min-width: 768px) {
            .equipment-selector-container { flex-direction: row; height: 500px; }
        }
        .es-left, .es-right {
            display: flex;
            flex-direction: column;
            flex: 1;
        }
        .es-left { border-right: 1px solid #f1f5f9; }
        .es-title {
            padding: 18px 20px;
            font-weight: 700;
            background: #f8fafc;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            color: #334155;
            font-size: 1.05rem;
        }
        .es-search { padding: 15px; border-bottom: 1px solid #f1f5f9; background: #fff; }
        .es-search input {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            background-color: #f8fafc;
            transition: all 0.2s ease;
        }
        .es-search input:focus {
            outline: none;
            background-color: #fff;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
        }
        .es-list {
            list-style: none;
            padding: 15px;
            margin: 0;
            overflow-y: auto;
            flex: 1;
            background: #fff;
        }
        .es-list::-webkit-scrollbar {
            width: 6px;
        }
        .es-list::-webkit-scrollbar-thumb {
            background-color: #cbd5e1;
            border-radius: 10px;
        }
        .es-item {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            margin-bottom: 12px;
            background: #fff;
            transition: all 0.2s ease;
            overflow: hidden;
            box-shadow: 0 1px 2px rgba(0,0,0,0.02);
            /* 使其不受 Flex 影響而被壓扁 */
            flex-shrink: 0;
        }
        .es-item:last-child {
            margin-bottom: 0;
        }
        .es-item:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            border-color: #cbd5e1;
        }
        .es-item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 20px;
            cursor: pointer;
            transition: all 0.2s ease;
            /* 避免換行時卡片高度錯亂 */
            min-height: 70px;
        }
        .es-item-header:hover { 
            background: #f8fafc; 
        }
        .es-item-body {
            display: none;
            padding: 15px 20px;
            background: #f8fafc;
            border-top: 1px dashed #eee;
            animation: fadeIn 0.25s ease-out;
        }
        .es-btn-invite {
            padding: 6px 0;
            width: 75px !important;
            height: 32px;
            display: inline-flex;
            justify-content: center;
            align-items: center;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            color: #3b82f6;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            white-space: nowrap;
            flex-shrink: 0;
            transition: all 0.2s ease;
        }
        .es-btn-invite:hover {
            background: #f8fafc !important;
            border-color: #3b82f6 !important;
            color: #2563eb !important;
        }
        .cart-header, .cart-row {
            display: flex;
            padding: 12px 20px;
            align-items: center;
            gap: 15px; /* 加入間距避免過度擁擠 */
            border-bottom: 1px solid #f1f5f9;
        }
        .cart-header { 
            font-weight: 600; 
            background: #f8fafc; 
            color: #64748b;
            font-size: 0.9rem;
        }
        .cart-item { background: #fff; transition: background 0.2s; }
        .cart-item:hover { background: #fdfef8; }
        .col-name { flex: 2; font-weight: 600; color: #334155; min-width: 0; word-break: break-word; } /* 防止名稱過長撐破 */
        .col-qty { flex: 1; display: flex; justify-content: center; }
        .col-qty input { 
            width: 70px !important; 
            text-align: center; 
            padding: 6px; 
            border: 1px solid #e2e8f0; 
            border-radius: 6px; 
            background: #f8fafc;
            transition: all 0.2s;
        }
        .col-qty input:focus {
            outline: none;
            border-color: #3b82f6;
            background: #fff;
        }
        .col-action { flex: 1; display: flex; justify-content: flex-end; }
        .btn-remove {
            color: #ef4444; 
            border: none; 
            background: #fef2f2;
            padding: 6px 12px;
            border-radius: 6px;
            cursor: pointer; 
            font-weight: 600;
            font-size: 0.85rem;
            transition: all 0.2s;
        }
        .btn-remove:hover { 
            background: #fee2e2;
            color: #dc2626;
        }

        /* 頁籤樣式 */
        .tabs-header {
            display: flex;
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 2rem;
            gap: 1rem;
        }
        .tab-btn {
            padding: 12px 24px;
            font-size: 1.05rem;
            font-weight: 600;
            color: #64748b;
            background: transparent !important;
            border: none !important;
            cursor: pointer;
            border-bottom: 3px solid transparent !important;
            margin-bottom: -2px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            border-radius: 8px 8px 0 0 !important;
        }
        .tab-btn:hover {
            color: #3b82f6 !important;
            background: #f8fafc !important;
        }
        .tab-btn.active {
            color: #3b82f6 !important;
            border-bottom-color: #3b82f6 !important;
            background: transparent !important;
        }
        .tab-content {
            display: none;
            animation: fadeIn 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .tab-content.active {
            display: block;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
 </head>
<body>

<nav class="navbar">
    <div class="navbar-brand"><h1>📚校園資源租借系統</h1></div>
    <div class="navbar-menu">
        <button class="nav-btn" onclick="location.href='index.php'">回首頁</button>
        <button class="nav-btn" onclick="location.href='approve.php'">審核面板</button>
        <button class="nav-btn" onclick="location.href='report_maintenance.php'">報修</button>
        <button class="nav-btn" type="button" disabled><?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['user_id'], ENT_QUOTES, 'UTF-8'); ?></button>
        <button class="nav-btn" onclick="location.href='logout.php'">登出</button>
    </div>
</nav>

    <main class="main-content">
        <div class="inventory-wrapper">
            <h2 style="margin-bottom: 2rem;">庫存管理系統 (課指組)</h2>

            <?php if ($message): ?>
                <div class="msg-alert msg-success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="msg-alert msg-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <!-- 頁籤按鈕 -->
            <div class="tabs-header">
                <button class="tab-btn active" onclick="switchTab('tab-add', this)">器材入庫</button>
                <button class="tab-btn" onclick="switchTab('tab-delete', this)">刪除庫存</button>
            </div>

            <!-- 入庫區塊 -->
            <div id="tab-add" class="tab-content active">
                <div class="card" style="margin-bottom: 2.5rem;">
                    <form id="add-form" method="POST">
                        
                        <div class="form-group" style="max-width: 250px;">
                            <label for="added_date">入庫日期：</label>
                            <input type="date" id="added_date" name="added_date" required value="<?php echo date('Y-m-d'); ?>">
                        </div>

                        <div id="equipmentSelectorContainer" class="equipment-selector-container form-group">
                        <div class="es-left">
                            <div class="es-title">
                                點擊選擇入庫項目
                            </div>
                            <div class="es-search">
                                <input type="text" id="category_search" placeholder="搜尋名稱或代碼..." oninput="filterCategories()">
                            </div>
                            <ul class="es-list" id="esEquipmentList">
                                <?php foreach ($categories as $cat) { ?>
                                    <li class="es-item" data-name="<?php echo htmlspecialchars($cat['equipment_name']); ?>" data-code="<?php echo htmlspecialchars($cat['equipment_code']); ?>">
                                        <div class="es-item-header" onclick="toggleItemBody(this.parentElement)">
                                            <div style="flex: 1; padding-right: 15px; display: flex; flex-direction: column;">
                                                <strong style="color: #333; font-size: 14px; margin-bottom: 4px;"><?php echo htmlspecialchars($cat['equipment_name']); ?></strong>
                                                <span style="font-size: 13px; color: #64748b;">型號: <?php echo htmlspecialchars($cat['equipment_code']); ?></span>
                                            </div>
                                            <button type="button" class="es-btn-invite">選擇</button>
                                        </div>
                                        <div class="es-item-body">
                                            <div style="display:flex; align-items:center; gap:10px;">
                                                <label>入庫數量：</label>
                                                <input type="number" class="es-qty-input" min="1" value="1" style="width:70px;">
                                                <button type="button" class="btn-primary" style="padding:0.4rem 1rem;" onclick="addToCart(this.parentElement.parentElement.parentElement)">加入清單</button>
                                            </div>
                                        </div>
                                    </li>
                                <?php } ?>
                            </ul>
                        </div>
                        
                        <div class="es-right">
                            <div class="es-title">
                                本次欲入庫清單
                            </div>
                            <div style="flex: 1; display: flex; flex-direction: column; min-height: 0; background:#f8fafc;">
                                <div class="cart-header">
                                    <div class="col-name">器材名稱 (代碼)</div>
                                    <div class="col-qty">數量</div>
                                    <div class="col-action">操作</div>
                                </div>
                                <ul id="esSelectedList" class="es-list" style="background: #f8fafc !important;">
                                    <li style="padding: 15px; text-align: center; color: #94a3b8; font-size: 14px;" id="emptyCartMsg">尚未加入任何器材</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <input type="hidden" name="cart_items" id="cart_items_input">

                        <button type="submit" class="btn-primary" style="width: 100%;">確認入庫所有清單項目</button>
                    </form>
                </div>
            </div>

            <!-- 刪除區塊 -->
            <div id="tab-delete" class="tab-content">
                <div class="card" style="margin-bottom: 2.5rem;">
                    <div class="form-group" style="margin-bottom:0;">
                        <label for="search_eq_id">請輸入要刪除的器材流水編號：</label>
                        <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 0.5rem;">
                            <input type="number" id="search_eq_id" placeholder="例如: 101" style="flex: 1; min-width: 200px; max-width: 300px;">
                            <button type="button" class="btn-secondary" onclick="checkAndDelete()">查詢並刪除</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
// 頁籤切換功能
function switchTab(tabId, btnEl) {
    // 隱藏所有內容
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    // 移除所有按鈕的 active 狀態
    document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
    
    // 顯示目標內容並更新按鈕狀態
    document.getElementById(tabId).classList.add('active');
    btnEl.classList.add('active');
}

// 購物車清單
let cartItems = [];

function toggleItemBody(itemEl) {
    const body = itemEl.querySelector('.es-item-body');
    const isHidden = window.getComputedStyle(body).display === 'none';
    
    // 收起其他
    document.querySelectorAll('.es-item-body').forEach(b => {
        b.style.display = 'none';
    });
    document.querySelectorAll('.es-item').forEach(el => {
        el.style.background = '#fff';
    });
    
    // 展開目前點擊的
    if (isHidden) {
        body.style.display = 'block';
        itemEl.style.background = '#f8fafc';
    }
}

function addToCart(itemEl) {
    const code = itemEl.dataset.code;
    const name = itemEl.dataset.name;
    const qtyInput = itemEl.querySelector('.es-qty-input');
    const qty = parseInt(qtyInput.value, 10);
    
    if(qty < 1) {
        Swal.fire('提示', '數量至少需為 1', 'warning');
        return;
    }

    const existItem = cartItems.find(i => i.code === code);
    if(existItem) {
        existItem.quantity += qty;
    } else {
        cartItems.push({code: code, name: name, quantity: qty});
    }

    // 歸零輸入框並隱藏面板
    qtyInput.value = 1;
    itemEl.querySelector('.es-item-body').style.display = 'none';
    itemEl.style.background = '#fff';
    
    renderCart();
    // 提示動畫
    Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'success',
        title: '已加入清單',
        showConfirmButton: false,
        timer: 1500
    });
}

function renderCart() {
    const cartList = document.getElementById('esSelectedList');
    const inputField = document.getElementById('cart_items_input');
    
    cartList.innerHTML = '';
    
    if (cartItems.length === 0) {
        cartList.innerHTML = '<li style="padding: 15px; text-align: center; color: #94a3b8; font-size: 14px;" id="emptyCartMsg">尚未加入任何器材</li>';
    } else {
        cartItems.forEach((item, idx) => {
            const li = document.createElement('li');
            li.className = 'cart-row cart-item';
            li.innerHTML = `
                <div class="col-name">${item.name}<br><small style="color:#64748b;">${item.code}</small></div>
                <div class="col-qty">
                    <input type="number" min="1" value="${item.quantity}" onchange="updateCartQty(${idx}, this.value)">
                </div>
                <div class="col-action">
                    <button type="button" class="btn-remove" onclick="removeCartItem(${idx})">移除</button>
                </div>
            `;
            cartList.appendChild(li);
        });
    }
    
    inputField.value = JSON.stringify(cartItems);
}

function updateCartQty(index, newQty) {
    const qty = parseInt(newQty, 10);
    if(qty >= 1) {
        cartItems[index].quantity = qty;
    } else {
        renderCart(); // 駁回不合乎邏輯的數值並重繪
    }
    document.getElementById('cart_items_input').value = JSON.stringify(cartItems);
}

function removeCartItem(index) {
    cartItems.splice(index, 1);
    renderCart();
}

// 搜尋總類功能
function filterCategories() {
    let keyword = document.getElementById('category_search').value.toLowerCase();
    let options = document.querySelectorAll('#esEquipmentList .es-item');
    for (let opt of options) {
        let name = opt.getAttribute('data-name').toLowerCase();
        let code = opt.getAttribute('data-code').toLowerCase();
        if (name.includes(keyword) || code.includes(keyword)) {
            opt.style.display = 'block';
        } else {
            opt.style.display = 'none';
        }
    }
}

// 處理入庫表單的 AJAX 提交
document.getElementById('add-form').addEventListener('submit', function(e) {
    e.preventDefault();
    if (cartItems.length === 0) {
        Swal.fire('提示', '請至少選擇一項器材加入入庫清單', 'warning');
        return;
    }

    let formData = new FormData(this);
    formData.append('action', 'add');
    formData.append('is_ajax', '1');

    Swal.fire({
        title: '處理中...',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    fetch('equipment_inventory.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if(data.success) {
                Swal.fire({
                    title: '入庫成功', 
                    text: data.message, 
                    icon: 'success',
                    width: '600px' // 給成功訊息大一點的空間顯示多個流水號
                });
                // 重置清單與表單
                cartItems = [];
                renderCart();
                document.getElementById('added_date').value = new Date().toISOString().split('T')[0];
                document.getElementById('category_search').value = '';
                filterCategories();
            } else {
                Swal.fire('錯誤', data.message, 'error');
            }
        }).catch(err => {
            console.error(err);
            Swal.fire('錯誤', '系統發生錯誤，無法完成入庫', 'error');
        });
});

// 查詢器材並顯示 SweetAlert2 確認框
function checkAndDelete() {
    const eqId = document.getElementById('search_eq_id').value.trim();
    if (!eqId) {
        Swal.fire('提示', '請先輸入器材編號', 'info');
        return;
    }

    Swal.fire({title: '查詢中...', allowOutsideClick: false, didOpen: () => Swal.showLoading()});

    fetch(`equipment_inventory.php?action=get_equipment&equipment_id=${encodeURIComponent(eqId)}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                Swal.fire({
                    title: '確認刪除器材？',
                    html: `即將刪除：<strong>${data.data.equipment_name} (${data.data.equipment_code})</strong><br>
                           流水編號：<strong>${data.data.equipment_id}</strong><br><br>
                           <span style="color:#d33;font-weight:bold;">⚠️ 此動作無法復原！</span>`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: '確定刪除',
                    cancelButtonText: '取消'
                }).then((result) => {
                    if (result.isConfirmed) {
                        doDelete(data.data.equipment_id);
                    }
                });
            } else {
                Swal.fire('找不到器材', data.message || '找不到此流水編號所對應的器材', 'error');
            }
        })
        .catch(err => {
            console.error(err);
            Swal.fire('錯誤', '系統錯誤，無法查詢該器材', 'error');
        });
}

function doDelete(id) {
    let formData = new FormData();
    formData.append('action', 'delete');
    formData.append('equipment_id', id);
    formData.append('is_ajax', '1');

    Swal.fire({title: '刪除中...', allowOutsideClick: false, didOpen: () => Swal.showLoading()});

    fetch('equipment_inventory.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if(data.success) {
                Swal.fire('已刪除', data.message, 'success');
                document.getElementById('search_eq_id').value = '';
            } else {
                Swal.fire('刪除失敗', data.message, 'error');
            }
        }).catch(err => Swal.fire('錯誤', '發生網路錯誤，無法刪除', 'error'));
}
</script>

</body>
</html>