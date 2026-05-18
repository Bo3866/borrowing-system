# 草稿箱三步驟連動功能使用指南

## 📋 功能概述

實現了校園資源租借系統的三步驟申請表單與草稿箱的完整連動，讓用戶可以：
- 在任何步驟暫存草稿
- 暫存後自動清空表單並回到第一步
- 從草稿箱加載草稿時返回原步驟
- 所有三步驟的暫存按鈕版面完全一致

---

## ✨ 主要特性

### 1. 三步驟暫存按鈕版面一致
所有三個步驟都配備相同的暫存按鈕組：
- **暫存申請** - 橘色按鈕，保存表單數據
- **草稿箱** - 靛藍色按鈕，打開草稿管理

位置：
- ✓ 第一步（活動申請基本信息）- 有暫存按鈕
- ✓ 第二步（場地需求）- 有暫存按鈕
- ✓ 第三步（器材與場地選擇）- 有暫存按鈕

### 2. 暫存後自動清空表單
按「暫存申請」後會發生：
```
1. 草稿已保存 ✓
2. 顯示成功訊息 (3 秒)
3. 表單所有字段清空
4. 購物車清空
5. 回到第一步
6. Stepper 重置
```

### 3. 草稿加載時返回原步驟
從草稿箱加載草稿：
```
1. 進入草稿箱
2. 選擇要載入的草稿
3. 點「載入」按鈕
4. 表單恢復所有數據
5. 自動導航到之前保存的步驟
```

---

## 🎯 使用流程

### 場景 1: 在第一步暫存
```
1. 進入借用申請頁面 (borrow.php)
   └─ 頁面顯示：第一步內容

2. 填寫第一步資料
   └─ 活動名稱、日期、時段等

3. 點擊「暫存申請」
   ├─ ✓ 保存成功訊息
   ├─ 表單清空
   └─ 回到第一步

4. 進度：可以點「下一步」或直接點「草稿箱」
```

### 場景 2: 在第二步暫存
```
1. 進入借用申請頁面
2. 點「下一步」進入第二步
   └─ 頁面顯示：第二步內容

3. 填寫第二步資料
   └─ 車輛進出、帳篷需求等

4. 點擊「暫存申請」
   ├─ ✓ 保存成功訊息
   ├─ 表單清空
   └─ 回到第一步（重置）

5. 進度：等待下次載入草稿
```

### 場景 3: 在第三步暫存
```
1. 進入借用申請頁面
2. 依序點「下一步」進入第三步
   └─ 頁面顯示：器材與場地選擇

3. 選擇器材和場地
   └─ 購物車顯示選擇的項目

4. 點擊「暫存申請」
   ├─ ✓ 保存成功訊息
   ├─ 表單清空
   └─ 回到第一步（重置）

5. 進度：可以開始新申請或加載之前的草稿
```

### 場景 4: 從草稿箱加載並編輯
```
1. 進入借用申請頁面
2. 點「草稿箱」按鈕
   └─ 打開草稿管理中心

3. 查看已保存的草稿列表
   ├─ 暫存時間
   ├─ 用途摘要
   └─ 操作按鈕

4. 找到要編輯的草稿
   └─ 點「載入」按鈕

5. 回到原步驟
   ├─ 表單數據恢復
   ├─ 自動返回保存時的步驟
   └─ 可以繼續填寫或修改

6. 修改完成後
   └─ 可以再次暫存或繼續填寫
```

---

## 📊 數據保存結構

每筆草稿包含以下信息：
```json
{
  "draftId": "draft_1715000000000_abc123def",
  "timestamp": "2025-05-18 14:30:45",
  "currentStep": 2,
  "resourceType": "equipment",
  "cartItems": [
    {
      "type": "equipment",
      "code": "PROJ001",
      "quantity": 1
    }
  ],
  "borrowDate": "2025-05-20",
  "startPeriodCode": "D1",
  "endPeriodCode": "D3",
  "phone": "0912345678",
  "purpose": "班級活動課程展示"
}
```

### 重要字段說明

| 字段 | 說明 |
|------|------|
| `draftId` | 唯一草稿識別碼 |
| `timestamp` | 草稿保存時間 |
| **`currentStep`** | ⭐ 保存時的步驟 (1, 2, 或 3) |
| `resourceType` | 資源類型 (equipment/space) |
| `cartItems` | 購物車項目 |
| `borrowDate` | 借用日期 |
| `startPeriodCode` | 開始節次 |
| `endPeriodCode` | 結束節次 |
| `phone` | 聯絡電話 |
| `purpose` | 用途說明 |

---

## 🔧 技術實現

### 核心函數

#### 1. saveDraft() - 保存草稿
```javascript
function saveDraft() {
    try {
        const draft = window.draftManager.saveDraft();
        showMessage(`✓ 草稿已暫存...，表單已清空，可開始新申請`, 'success', 3000);
        
        // 暫存完畢後，清空所有表單欄位並回到第一步
        setTimeout(() => {
            window.draftManager.clearForm(true);
        }, 500);
    } catch (error) {
        showMessage(`✗ 暫存失敗：${error.message}`, 'error', 3000);
    }
}
```

#### 2. loadDraftToForm(draft) - 載入草稿
```javascript
loadDraftToForm(draft) {
    // 回填表單數據
    // ...
    
    // 導航到保存的步驟
    const currentStep = draft.currentStep || 1;
    if (typeof goToStep === 'function') {
        setTimeout(() => goToStep(currentStep), 150);
    }
    
    return true;
}
```

#### 3. clearForm(resetStep) - 清空表單
```javascript
clearForm(resetStep = false) {
    // 重置所有表單欄位
    form.reset();
    
    // 清空購物車
    cartItemsInput.value = '[]';
    
    // 如果需要重置步驟
    if (resetStep) {
        // 回到第一步
        // 隱藏其他步驟，顯示第一步
        // 更新 Stepper
    }
}
```

---

## 📁 相關文件

| 文件 | 功能 |
|------|------|
| `borrow.php` | 主要申請表單頁面 |
| `DraftManager.js` | 草稿管理核心模組 |
| `drafts.php` 或 `draft_box.php` | 草稿箱頁面 |
| `styles.css` | 樣式定義 |
| `api/save_draft.php` | 保存草稿 API (可選) |
| `api/load_draft.php` | 加載草稿 API (可選) |

---

## ⚙️ 配置說明

### 暫存後清空延遲時間
修改 `borrow.php` 中的延遲：
```javascript
setTimeout(() => {
    window.draftManager.clearForm(true);
}, 500);  // 500ms 延遲
```

### 按鈕顏色自訂
修改 `borrow.php` 內聯 CSS 中的顏色：
```css
.save-btn {
    background: #f59e0b;  /* 橘色 */
}

.draft-box-btn {
    background: #6366f1;  /* 靛藍色 */
}
```

### 訊息顯示時間
修改訊息顯示時長：
```javascript
showMessage(text, type, 3000);  // 3000ms
```

---

## 🐛 常見問題

### Q1: 暫存後表單沒有清空？
**A:** 檢查瀏覽器控制台是否有錯誤。確保 `DraftManager.js` 已正確加載。

### Q2: 載入草稿後沒有回到原步驟？
**A:** 確認 `currentStep` 已保存在草稿中。查看瀏覽器控制台的 `console.log` 輸出。

### Q3: 跨步驟保存的多筆草稿會不會衝突？
**A:** 不會。每筆草稿都有唯一的 `draftId` 和 `currentStep`，完全獨立。

### Q4: 可以修改暫存後回到第一步的行為嗎？
**A:** 可以。修改 `clearForm(true)` 中的 `true` 參數，改為 `false` 則不會重置步驟。

---

## 📝 修改記錄

### 版本 1.0 (2025-05-18)
- ✅ 實現三步驟暫存按鈕版面一致
- ✅ 實現暫存後自動清空表單
- ✅ 實現暫存後自動回到第一步
- ✅ 實現加載時返回原步驟
- ✅ 支持多筆草稿同時保存

---

## 📞 支持

如有問題或需要進一步定製，請聯繫開發團隊。

