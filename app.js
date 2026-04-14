// ===== 數據存儲 =====
let currentUser = {
    id: 'user001',
    name: '林宇芊',
    role: 'student', // student, staff, admin
    email: 'lin@example.com',
    phone: '0912345678'
};

// 系統通知
let systemNotifications = [];

let resources = [
    {
        id: 'res001',
        name: '教室A101',
        type: 'space',
        description: '主講堂，可容納50人',
        location: '行政大樓1樓',
        available: true,
        capacity: 50
    },
    {
        id: 'res002',
        name: '多功能會議室',
        type: 'space',
        description: '配有投影機和音響設備',
        location: '學生中心2樓',
        available: true,
        capacity: 30
    },
    {
        id: 'res003',
        name: '投影機',
        type: 'equipment',
        description: '高清投影機',
        location: '行政大樓',
        available: true,
        quantity: 5
    },
    {
        id: 'res004',
        name: '音響系統',
        type: 'equipment',
        description: '專業音響設備',
        location: '學生中心',
        available: true,
        quantity: 3
    },
    {
        id: 'res005',
        name: '戶外活動草坪',
        type: 'space',
        description: '用於戶外活動和運動',
        location: '校園中央綠地',
        available: false,
        capacity: 200
    },
    {
        id: 'res006',
        name: '舞臺',
        type: 'equipment',
        description: '活動舞臺設備',
        location: '操場',
        available: true,
        quantity: 1
    }
];

let borrowApplications = [
    {
        id: 'app001',
        applicantName: '林宇芊',
        applicantPhone: '0912345678',
        resourceId: 'res001',
        resourceName: '教室A101',
        startDate: '2024-05-15T09:00',
        endDate: '2024-05-15T12:00',
        purpose: '班級會議',
        status: 'approved',
        submitDate: '2024-05-10',
        attachments: ['planning.pdf'],
        draft: false,
        draftSaveTime: null,
        rejectionReason: null,
        approvedTime: '2024-05-11T10:00',
        returned: false,
        returnedTime: null
    },
    {
        id: 'app002',
        applicantName: '彭歆瑀',
        applicantPhone: '0987654321',
        resourceId: 'res002',
        resourceName: '多功能會議室',
        startDate: '2024-05-20T14:00',
        endDate: '2024-05-20T16:00',
        purpose: '社團聚會',
        status: 'pending',
        submitDate: '2024-05-12',
        attachments: [],
        draft: false,
        draftSaveTime: null,
        rejectionReason: null,
        approvedTime: null,
        returned: false,
        returnedTime: null
    },
    {
        id: 'app003',
        applicantName: '蔡絜涵',
        applicantPhone: '0911223344',
        resourceId: 'res003',
        resourceName: '投影機',
        startDate: '2024-05-18T10:00',
        endDate: '2024-05-18T12:00',
        purpose: '組織報告',
        status: 'rejected',
        submitDate: '2024-05-11',
        attachments: [],
        draft: false,
        draftSaveTime: null,
        rejectionReason: '時間與其他申請衝突',
        approvedTime: null,
        returned: false,
        returnedTime: null
    }
];

// 故障報告
let maintenanceReports = [
    {
        id: 'maint001',
        resourceId: 'res003',
        resourceName: '投影機',
        reportedBy: '林宇芊',
        reportDate: '2024-05-15T15:30',
        status: 'open',
        description: '投影機無法開機',
        attachments: [],
        resolvedTime: null
    }
];

let reviewHistory = [];

// ===== 時段衝突檢測 =====
function checkTimeConflict(resourceId, startTime, endTime) {
    return borrowApplications.find(app => {
        if (app.resourceId !== resourceId) return false;
        if (app.status === 'rejected') return false;
        
        const appStart = new Date(app.startDate);
        const appEnd = new Date(app.endDate);
        
        return (startTime < appEnd && endTime > appStart);
    });
}

// ===== 初始化 =====
document.addEventListener('DOMContentLoaded', function() {
    initializeSystem();
    renderResources();
});

function initializeSystem() {
    console.log('系統初始化...');
    // 可以從服務器加載數據
}

// ===== 頁面導航 =====
function navigateTo(pageId) {
    // 隱藏所有頁面
    const pages = document.querySelectorAll('.page');
    pages.forEach(page => page.classList.remove('active'));

    // 根據角色檢查訪問權限
    if ((pageId === 'manage' || pageId === 'admin') && currentUser.role === 'student') {
        alert('您沒有權限訪問此頁面');
        navigateTo('dashboard');
        return;
    }

    // 顯示選定的頁面
    const selectedPage = document.getElementById(pageId);
    if (selectedPage) {
        selectedPage.classList.add('active');
        window.scrollTo(0, 0);
    }
}

function logout() {
    if (confirm('確定要登出嗎？')) {
        alert('已登出系統');
        // 可以添加登出邏輯
    }
}

// ===== 資源顯示和篩選 =====
function renderResources() {
    const resourcesList = document.getElementById('resourcesList');
    const filtered = filterResourcesData();

    if (filtered.length === 0) {
        resourcesList.innerHTML = '<p style="grid-column: 1/-1; text-align: center; padding: 2rem;">沒有找到符合條件的資源</p>';
        return;
    }

    resourcesList.innerHTML = filtered.map(resource => `
        <div class="resource-card">
            <h4>${resource.type === 'space' ? '🏛️' : '🔧'} ${resource.name}</h4>
            <p class="resource-info"><strong>類型：</strong> ${getResourceTypeLabel(resource.type)}</p>
            <p class="resource-info"><strong>位置：</strong> ${resource.location}</p>
            <p class="resource-info"><strong>說明：</strong> ${resource.description}</p>
            ${resource.type === 'space' ? `<p class="resource-info"><strong>容納人數：</strong> ${resource.capacity}人</p>` : `<p class="resource-info"><strong>數量：</strong> ${resource.quantity}件</p>`}
            
            <div class="resource-availability ${resource.available ? 'availability-available' : 'availability-unavailable'}">
                ${resource.available ? '✓ 可租借' : '✗ 暫不可租'}
            </div>
            
            ${resource.available ? `<button class="btn-primary" onclick="openBorrowModal('${resource.id}', '${resource.name}')">租借此資源</button>` : `<button class="btn-secondary" disabled>暫不可租</button>`}
        </div>
    `).join('');
}

function filterResourcesData() {
    const typeFilter = document.getElementById('resourceType')?.value || '';
    const dateFilter = document.getElementById('borrowDate')?.value || '';
    const searchKeyword = document.getElementById('searchKeyword')?.value.toLowerCase() || '';

    return resources.filter(resource => {
        const typeMatch = !typeFilter || resource.type === typeFilter;
        const searchMatch = !searchKeyword || 
            resource.name.toLowerCase().includes(searchKeyword) ||
            resource.description.toLowerCase().includes(searchKeyword);
        return typeMatch && searchMatch;
    });
}

function filterResources() {
    renderResources();
}

function getResourceTypeLabel(type) {
    return type === 'space' ? '空間' : '器材';
}

// ===== 租借表單處理 =====
function openBorrowModal(resourceId, resourceName) {
    const modal = document.getElementById('borrowModal');
    document.getElementById('modalResourceName').value = resourceName;
    document.getElementById('borrowForm').dataset.resourceId = resourceId;
    modal.classList.add('show');
}

function closeBorrowModal() {
    const modal = document.getElementById('borrowModal');
    modal.classList.remove('show');
    document.getElementById('borrowForm').reset();
}

function closeMaintenanceModal() {
    const modal = document.getElementById('maintenanceModal');
    modal.classList.remove('show');
    document.getElementById('maintenanceForm').reset();
}

function submitBorrowApplication(event) {
    event.preventDefault();

    const resourceId = document.getElementById('borrowForm').dataset.resourceId;
    const applicantName = document.getElementById('applicantName').value;
    const applicantPhone = document.getElementById('applicantPhone').value;
    const startDateTime = document.getElementById('startDateTime').value;
    const endDateTime = document.getElementById('endDateTime').value;
    const purpose = document.getElementById('purpose').value;
    const planDocument = document.getElementById('planDocument').files[0];

    // Scenario 1: 成功提交借用申請 (User Story 1.1)
    // ===== 表單驗證 =====
    if (!applicantName.trim()) {
        alert('錯誤：請填寫申請人姓名');
        return;
    }

    if (!applicantPhone.trim()) {
        alert('錯誤：請填寫聯絡電話');
        return;
    }

    if (!/^\d{10}$|^\d{9}$|^09\d{8}$/.test(applicantPhone)) {
        alert('錯誤：電話格式不正確');
        return;
    }

    if (!startDateTime || !endDateTime) {
        alert('錯誤：請填寫租借時間');
        return;
    }

    if (!purpose.trim()) {
        alert('錯誤：請填寫用途說明');
        return;
    }

    // 驗證活動企劃書 (User Story 1.3)
    if (planDocument) {
        const validation = validatePlanDocument(true);
        if (!validation.valid) {
            alert(`錯誤：${validation.error}`);
            return;
        }
    }

    // Scenario 3: 未填寫必要欄位 (異常處理)
    const start = new Date(startDateTime);
    const end = new Date(endDateTime);

    if (start >= end) {
        alert('錯誤：結束時間必須晚於開始時間');
        return;
    }

    // 檢查時段衝突
    const conflict = checkTimeConflict(resourceId, start, end);
    if (conflict) {
        alert(`警告：此時段與申請 ${conflict.id} 衝突。\n${conflict.resourceName} 於 ${formatDate(conflict.startDate)} 至 ${formatDate(conflict.endDate)} 已被借用。\n衝突的申請已被自動鎖定相鄰時段。`);
        // 自動鎖定相鄰時段 (User Story 2.3)
        lockAdjacentTimeSlots(resourceId, conflict.startDate, conflict.endDate);
        return;
    }

    // 創建新申請
    const newApplication = {
        id: 'app' + Date.now(),
        applicantName: applicantName,
        applicantPhone: applicantPhone,
        resourceId: resourceId,
        resourceName: document.getElementById('modalResourceName').value,
        startDate: startDateTime,
        endDate: endDateTime,
        purpose: purpose,
        status: 'pending',
        submitDate: new Date().toISOString().split('T')[0],
        attachments: planDocument ? [planDocument.name] : [],
        attachmentSize: planDocument ? planDocument.size : 0,
        draft: false,
        draftSaveTime: null,
        rejectionReason: null,
        approvedTime: null,
        returned: false,
        returnedTime: null
    };

    borrowApplications.push(newApplication);
    
    // Scenario 1: 系統應顯示「申請成功」
    console.log('新申請已提交：', newApplication);
    
    // 發送即時通知 (User Story 1.9)
    sendNotification(
        applicantName,
        `您的申請已成功提交！申請編號：${newApplication.id}`,
        'info'
    );

    alert(`✓ 申請成功！申請編號：${newApplication.id}\n\n課指組將在3個工作日內進行審核。\n您可在「我的申請」中查看申請狀態。`);
    closeBorrowModal();
    renderResources();
}

// ===== 系統通知 =====
function validatePlanDocument(isSubmitting = false) {
    // User Story 1.3: 活動企劃書數位遞交 - 檔案驗證
    const fileInput = document.getElementById('planDocument');
    const file = fileInput.files[0];
    const errorDiv = document.getElementById('planDocumentError');
    
    // 定義限制
    const MAX_FILE_SIZE = 1 * 1024 * 1024; // 1MB = 1048576 bytes
    const ALLOWED_TYPE = 'application/pdf';
    
    // 如果沒有選擇檔案
    if (!file) {
        if (errorDiv) {
            errorDiv.style.display = 'none';
            errorDiv.textContent = '';
        }
        return { valid: true }; // 企劃書是可選的
    }
    
    // Scenario 3: 上傳不支援的檔案格式（異常處理）
    if (file.type !== ALLOWED_TYPE && !file.name.toLowerCase().endsWith('.pdf')) {
        const errorMsg = `❌ 上傳失敗：不支援的檔案格式。\n必須上傳 PDF 檔案，您選擇的是 ${file.type || '未知格式'}`;
        if (errorDiv) {
            errorDiv.textContent = errorMsg;
            errorDiv.style.display = 'block';
        }
        if (isSubmitting) {
            console.error(errorMsg);
            return { valid: false, error: '只支援 PDF 格式的檔案' };
        }
        return { valid: false, error: errorMsg };
    }
    
    // Scenario 4: 檔案過大（異常處理）
    if (file.size > MAX_FILE_SIZE) {
        const fileSizeMB = (file.size / (1024 * 1024)).toFixed(2);
        const errorMsg = `❌ 上傳失敗：檔案大小超過限制。\n上傳的檔案：${fileSizeMB}MB，限制：1MB`;
        if (errorDiv) {
            errorDiv.textContent = errorMsg;
            errorDiv.style.display = 'block';
        }
        if (isSubmitting) {
            console.error(errorMsg);
            return { valid: false, error: '檔案大小超過 1MB 限制' };
        }
        return { valid: false, error: errorMsg };
    }
    
    // 驗證通過
    const successMsg = `✓ 檔案已驗證\n格式：PDF | 大小：${(file.size / 1024).toFixed(2)}KB / 最大 1MB`;
    if (errorDiv) {
        errorDiv.textContent = successMsg;
        errorDiv.style.display = 'block';
        errorDiv.style.color = '#27ae60';
    }
    
    console.log('📄 活動企劃書已驗證：', file.name, `(${(file.size / 1024).toFixed(2)}KB)`);
    
    return { valid: true };
}

function sendNotification(recipient, message, type = 'info') {
    const notification = {
        id: 'notif_' + Date.now(),
        recipient: recipient,
        message: message,
        type: type, // info, success, warning, error
        timestamp: new Date(),
        read: false
    };
    
    systemNotifications.push(notification);
    console.log(`📢 通知發送給 ${recipient}: ${message}`);
    
    // 實時顯示通知提示
    if (type === 'warning' || type === 'error') {
        showNotificationBanner(message, type);
    }
}

function showNotificationBanner(message, type) {
    // 在頁面頂部顯示通知橫幅
    const banner = document.createElement('div');
    banner.className = `notification-banner notification-${type}`;
    banner.textContent = message;
    banner.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 1rem 1.5rem;
        background-color: ${type === 'error' ? '#e74c3c' : '#f39c12'};
        color: white;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        z-index: 10000;
        animation: slideIn 0.3s ease;
    `;
    
    document.body.appendChild(banner);
    
    setTimeout(() => {
        banner.remove();
    }, 5000);
}

// ===== 相鄰時段鎖定 =====
function lockAdjacentTimeSlots(resourceId, startDate, endDate) {
    // User Story 2.3: 自動檢查與鄰近時段鎖定
    const lockedSlot = {
        resourceId: resourceId,
        startDate: new Date(new Date(startDate).getTime() - 30 * 60000), // 提前30分鐘
        endDate: new Date(new Date(endDate).getTime() + 30 * 60000), // 延後30分鐘
        locked: true,
        lockReason: '檢查與準備時間'
    };
    
    console.log('🔒 相鄰時段已鎖定:', lockedSlot);
}

// ===== 草稿保存功能 =====
function saveBorrowDraft() {
    const applicantName = document.getElementById('applicantName').value;
    const applicantPhone = document.getElementById('applicantPhone').value;
    const startDateTime = document.getElementById('startDateTime').value;
    const endDateTime = document.getElementById('endDateTime').value;
    const purpose = document.getElementById('purpose').value;
    const resourceId = document.getElementById('borrowForm').dataset.resourceId;

    // 至少填寫部分信息
    if (!applicantName && !applicantPhone && !startDateTime && !endDateTime && !purpose) {
        alert('請至少填寫部分信息後再保存草稿');
        return;
    }

    // Scenario 1: 成功儲存草稿 (User Story 1.4)
    const draftApplication = {
        id: 'draft_' + Date.now(),
        applicantName: applicantName,
        applicantPhone: applicantPhone,
        resourceId: resourceId,
        resourceName: document.getElementById('modalResourceName').value,
        startDate: startDateTime,
        endDate: endDateTime,
        purpose: purpose,
        status: 'draft',
        submitDate: null,
        attachments: [],
        draft: true,
        draftSaveTime: new Date().toISOString(),
        rejectionReason: null,
        approvedTime: null,
        returned: false,
        returnedTime: null
    };

    borrowApplications.push(draftApplication);
    alert('✓ 草稿已保存！您可以稍後繼續修改。');
    closeBorrowModal();
}

// ===== 修改申請 =====
function editApplication(appId) {
    const app = borrowApplications.find(a => a.id === appId);
    if (!app || app.status !== 'pending') {
        alert('此申請無法修改');
        return;
    }

    // Scenario: 申請被退回時，能否直接在原申請單上修改資訊 (User Story 1.8)
    document.getElementById('applicantName').value = app.applicantName;
    document.getElementById('applicantPhone').value = app.applicantPhone;
    document.getElementById('startDateTime').value = app.startDate;
    document.getElementById('endDateTime').value = app.endDate;
    document.getElementById('purpose').value = app.purpose;
    document.getElementById('borrowForm').dataset.resourceId = app.resourceId;
    document.getElementById('modalResourceName').value = app.resourceName;
    
    const modal = document.getElementById('borrowModal');
    modal.classList.add('show');
    alert('編輯模式已啟用。修改完成後點擊「提交申請」即可更新。');
}

// ===== 報修功能 =====
function reportMaintenance(resourceId, resourceName) {
    // User Story 1.13: 器材報修回報
    document.getElementById('maintenanceResourceName').value = resourceName;
    document.getElementById('maintenanceForm').dataset.resourceId = resourceId;
    
    const modal = document.getElementById('maintenanceModal');
    modal.classList.add('show');
}

function submitMaintenanceReport(event) {
    event.preventDefault();

    const resourceId = document.getElementById('maintenanceForm').dataset.resourceId;
    const resourceName = document.getElementById('maintenanceResourceName').value;
    const description = document.getElementById('maintenanceDescription').value;

    if (!description.trim()) {
        alert('請填寫損壞描述');
        return;
    }

    const report = {
        id: 'maint_' + Date.now(),
        resourceId: resourceId,
        resourceName: resourceName,
        reportedBy: currentUser.name,
        reportDate: new Date().toISOString(),
        status: 'open',
        description: description,
        attachments: [],
        resolvedTime: null
    };

    maintenanceReports.push(report);
    
    sendNotification(
        '課指組管理員',
        `⚠️ 新的故障報告\n資源：${resourceName}\n報告人：${currentUser.name}\n描述：${description}`,
        'warning'
    );
    
    sendNotification(
        currentUser.name,
        `✓ 報修成功！報修編號：${report.id}\n課指組人員將盡快處理。`,
        'success'
    );

    alert(`✓ 報修已提交！\n報修編號：${report.id}\n課指組人員將盡快處理此問題。`);
    closeMaintenanceModal();
}
function renderApplications() {
    const applicationsList = document.getElementById('applicationsList');
    const statusFilter = document.getElementById('statusFilter')?.value || '';

    let filtered = borrowApplications.filter(app => 
        app.applicantName === currentUser.name
    );

    if (statusFilter) {
        filtered = filtered.filter(app => app.status === statusFilter);
    }

    if (filtered.length === 0) {
        applicationsList.innerHTML = '<p style="text-align: center; padding: 2rem;">沒有申請記錄</p>';
        return;
    }

    applicationsList.innerHTML = filtered.map(app => `
        <div class="application-card">
            <h4>📋 ${app.resourceName}</h4>
            <p><strong>申請ID：</strong> ${app.id}</p>
            <p><strong>租借期間：</strong> ${formatDate(app.startDate)} 至 ${formatDate(app.endDate)}</p>
            <p><strong>用途：</strong> ${app.purpose}</p>
            <p><strong>提交時間：</strong> ${app.submitDate}</p>
            <span class="application-status ${getStatusClass(app.status)}">
                ${getStatusLabel(app.status)}
            </span>
            ${app.status === 'pending' ? `<button class="btn-secondary" onclick="cancelApplication('${app.id}')" style="margin-top: 1rem;">取消申請</button>` : ''}
        </div>
    `).join('');
}

function filterApplications() {
    renderApplications();
}

function cancelApplication(appId) {
    if (confirm('確定要取消此申請嗎？')) {
        const app = borrowApplications.find(a => a.id === appId);
        if (app && app.status === 'pending') {
            app.status = 'rejected';
            alert('申請已取消');
            renderApplications();
        }
    }
}

function getStatusLabel(status) {
    const labels = {
        pending: '⏳ 審核中',
        approved: '✓ 已核准',
        rejected: '✗ 已拒絕',
        completed: '✓ 已完成'
    };
    return labels[status] || status;
}

function getStatusClass(status) {
    return 'status-' + status;
}

function formatDate(dateStr) {
    const date = new Date(dateStr);
    return date.toLocaleDateString('zh-TW', { 
        year: 'numeric', 
        month: '2-digit', 
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit'
    });
}

// ===== 資源管理 =====
function switchManagementTab(tab) {
    const tabs = document.querySelectorAll('.management-tabs .tab-btn');
    tabs.forEach(t => t.classList.remove('active'));
    
    const contents = document.querySelectorAll('#resourcesTab, #statisticsTab');
    contents.forEach(c => c.classList.remove('active'));

    if (tab === 'resources') {
        tabs[0].classList.add('active');
        document.getElementById('resourcesTab').classList.add('active');
        renderResourcesTable();
    } else {
        tabs[1].classList.add('active');
        document.getElementById('statisticsTab').classList.add('active');
        renderStatistics();
    }
}

function renderResourcesTable() {
    const tbody = document.getElementById('resourcesTableBody');
    tbody.innerHTML = resources.map(resource => `
        <tr>
            <td>${resource.id}</td>
            <td>${resource.name}</td>
            <td>${getResourceTypeLabel(resource.type)}</td>
            <td>
                <span class="availability-${resource.available ? 'available' : 'unavailable'}" style="padding: 0.3rem 0.6rem; border-radius: 4px; font-size: 0.85rem;">
                    ${resource.available ? '可用' : '不可用'}
                </span>
            </td>
            <td>
                <button class="btn-primary" onclick="editResource('${resource.id}')" style="padding: 0.4rem 0.8rem; font-size: 0.85rem;">編輯</button>
                <button class="btn-secondary" onclick="toggleResourceAvailability('${resource.id}')" style="padding: 0.4rem 0.8rem; font-size: 0.85rem; margin-left: 0.5rem;">
                    ${resource.available ? '禁用' : '啟用'}
                </button>
            </td>
        </tr>
    `).join('');
}

function renderStatistics() {
    const totalBorrow = borrowApplications.length;
    const monthlyBorrow = borrowApplications.filter(app => {
        const appDate = new Date(app.submitDate);
        const now = new Date();
        return appDate.getMonth() === now.getMonth() && appDate.getFullYear() === now.getFullYear();
    }).length;

    document.getElementById('totalBorrowCount').textContent = totalBorrow;
    document.getElementById('monthlyBorrowCount').textContent = monthlyBorrow;
    document.getElementById('averageSatisfaction').textContent = '95%';
    document.getElementById('equipmentFailureRate').textContent = '2%';
}

function addNewResource() {
    alert('此功能將開放新的資源添加表單');
    // 可以在這裡添加表單
}

function editResource(resourceId) {
    alert('正在編輯資源: ' + resourceId);
    // 可以在這裡打開編輯表單
}

function toggleResourceAvailability(resourceId) {
    const resource = resources.find(r => r.id === resourceId);
    if (resource) {
        resource.available = !resource.available;
        alert(`資源 "${resource.name}" 已${resource.available ? '啟用' : '禁用'}`);
        renderResourcesTable();
    }
}

// ===== 審核面板 =====
function switchAdminTab(tab) {
    const tabs = document.querySelectorAll('.admin-tabs .tab-btn');
    tabs.forEach(t => t.classList.remove('active'));
    
    const contents = document.querySelectorAll('#pendingTab, #historyTab');
    contents.forEach(c => c.classList.remove('active'));

    if (tab === 'pending') {
        tabs[0].classList.add('active');
        document.getElementById('pendingTab').classList.add('active');
        renderPendingApplications();
    } else {
        tabs[1].classList.add('active');
        document.getElementById('historyTab').classList.add('active');
        renderReviewHistory();
    }
}

function renderPendingApplications() {
    const pending = borrowApplications.filter(app => app.status === 'pending');
    const container = document.getElementById('pendingApplicationsList');

    if (pending.length === 0) {
        container.innerHTML = '<p style="text-align: center; padding: 2rem;">沒有待審核的申請</p>';
        return;
    }

    container.innerHTML = pending.map(app => `
        <div class="admin-application-card">
            <h4>申請 ID: ${app.id}</h4>
            <p><strong>申請人：</strong> ${app.applicantName}</p>
            <p><strong>電話：</strong> ${app.phone || '未提供'}</p>
            <p><strong>資源：</strong> ${app.resourceName}</p>
            <p><strong>租借期間：</strong> ${formatDate(app.startDate)} 至 ${formatDate(app.endDate)}</p>
            <p><strong>用途：</strong> ${app.purpose}</p>
            <div style="margin-top: 1rem;">
                <button class="btn-primary" onclick="approveApplication('${app.id}')" style="margin-right: 0.5rem;">核准</button>
                <button class="btn-secondary" onclick="rejectApplication('${app.id}')">拒絕</button>
            </div>
        </div>
    `).join('');
}

function renderReviewHistory() {
    const tbody = document.getElementById('reviewHistoryBody');
    const reviewed = borrowApplications.filter(app => app.status !== 'pending');

    if (reviewed.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align: center;">暫無審核記錄</td></tr>';
        return;
    }

    tbody.innerHTML = reviewed.map(app => `
        <tr>
            <td>${app.id}</td>
            <td>${app.applicantName}</td>
            <td>${app.resourceName}</td>
            <td><span class="application-status ${getStatusClass(app.status)}">${getStatusLabel(app.status)}</span></td>
            <td>${app.submitDate}</td>
        </tr>
    `).join('');
}

function approveApplication(appId) {
    const app = borrowApplications.find(a => a.id === appId);
    if (app) {
        app.status = 'approved';
        app.approvedTime = new Date().toISOString();
        
        // Scenario: 系統應自動產生數位審核紀錄，取代實體蓋章 (User Story 4.1)
        reviewHistory.push({
            appId: appId,
            status: 'approved',
            timestamp: new Date().toISOString(),
            reviewedBy: currentUser.name,
            remark: '線上核准'
        });
        
        // Scenario: 系統應即時透過電子通知將結果回饋給申請人 (User Story 4.1)
        sendNotification(
            app.applicantName,
            `您的借用申請已核准！\n資源：${app.resourceName}\n借用時間：
${formatDate(app.startDate)} 至 ${formatDate(app.endDate)}\n請於借用時間前往課指組領取。`,
            'success'
        );
        
        alert('✓ 申請已核准\n已發送通知給申請人');
        renderPendingApplications();
    }
}

function rejectApplication(appId) {
    const app = borrowApplications.find(a => a.id === appId);
    if (!app) return;

    // 快速拒絕範本 (User Story 2.4)
    const reasons = [
        '時間與其他申請衝突',
        '資源不可用',
        '申請資料不完整',
        '用途不符合規定',
        '其他'
    ];

    let reason = prompt(
        '請選擇拒絕原因：\n' + 
        reasons.map((r, i) => `${i + 1}. ${r}`).join('\n') +
        '\n\n輸入序號（例如：1）或自訂原因：'
    );

    if (reason === null) return;

    const selectedReason = reasons[parseInt(reason) - 1] || reason;
    const detailReason = reason.includes('.') ? reason.split('.')[1].trim() : reason;

    app.status = 'rejected';
    app.rejectionReason = selectedReason;

    // 記錄審核紀錄 (User Story 4.1)
    reviewHistory.push({
        appId: appId,
        status: 'rejected',
        reason: selectedReason,
        timestamp: new Date().toISOString(),
        reviewedBy: currentUser.name
    });

    // 通知申請人 (User Story 1.9)
    sendNotification(
        app.applicantName,
        `⚠️ 您的借用申請已被拒絕\n資源：${app.resourceName}\n拒絕原因：${selectedReason}\n\n您可修改申請後重新提交。`,
        'warning'
    );

    alert(`✓ 申請已拒絕\n已發送通知給申請人`);
    renderPendingApplications();
}

// ===== 系統維護函數 =====
function checkOverdueApplications() {
    // User Story 2.5: 逾期未還與違規自動催繳
    const now = new Date();
    
    borrowApplications.forEach(app => {
        if (app.status === 'approved' && !app.returned) {
            const endDate = new Date(app.endDate);
            const hoursPassed = (now - endDate) / (1000 * 60 * 60);
            
            if (hoursPassed > 0.5 && !app.overdueNotificationSent) {
                // 超過預定時間30分鐘未歸還
                const message = `⚠️ 逾期未返提醒\n申請人：${app.applicantName}\n資源：${app.resourceName}\n應歸還時間：${formatDate(app.endDate)}\n請立即歸還！`;
                
                sendNotification(app.applicantName, message, 'warning');
                
                // 同步通知社團指導老師（如適用）
                if (app.studentClub) {
                    sendNotification(
                        app.studentClub,
                        `社團成員 ${app.applicantName} 的借用物品 ${app.resourceName} 逾期未返。`,
                        'warning'
                    );
                }
                
                app.overdueNotificationSent = true;
                console.log(`📢 逾期催繳通知已發送: ${app.id}`);
            }
        }
    });
}
function showStats() {
    navigateTo('manage');
    switchManagementTab('statistics');
}

// ===== 監聽頁面變化 =====
const observer = new MutationObserver(function() {
    if (document.getElementById('myapplications').classList.contains('active')) {
        renderApplications();
    }
});

observer.observe(document.getElementById('main-content') || document.body, {
    attributeFilter: ['class'],
    subtree: true
});

// 當導航到我的申請時
document.addEventListener('mousedown', function(e) {
    if (e.target.textContent.includes('我的申請')) {
        setTimeout(renderApplications, 100);
    }
});
