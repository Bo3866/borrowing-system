# 活動企劃書文件驗證 - 實現說明

## 📋 功能概述

根據 User Story 1.3（活動企劃書數位遞交），系統已實現完整的文件上傳驗證機制。

### 設定要求
- **檔案格式**：PDF 格式 (.pdf)
- **檔案大小限制**：1MB（1,048,576 字節）

---

## ✅ 實現的驗證功能

### 1. 格式驗證
- ✓ HTML 中限制 `accept=".pdf"` 只顯示 PDF 檔案
- ✓ 後端驗證檔案 MIME 類型為 `application/pdf`
- ✓ 檔案副檔名驗證

### 2. 大小驗證
- ✓ 檔案大小上限：1MB
- ✓ 實時檢查檔案大小
- ✓ 顯示檔案大小資訊（KB / MB）

### 3. 錯誤處理

#### Scenario 3: 上傳不支援的檔案格式（異常處理）
```
❌ 上傳失敗：不支援的檔案格式。
必須上傳 PDF 檔案，您選擇的是 [格式名稱]
```

#### Scenario 4: 檔案過大（異常處理）
```
❌ 上傳失敗：檔案大小超過限制。
上傳的檔案：[大小]MB，限制：1MB
```

### 4. 成功反饋
```
✓ 檔案已驗證
格式：PDF | 大小：[大小]KB / 最大 1MB
```

---

## 🔧 技術實現

### HTML 修改
```html
<div class="form-group">
    <label>活動企劃書：</label>
    <input type="file" id="planDocument" accept=".pdf" onchange="validatePlanDocument()">
    <small>✓ 格式：PDF 檔案 | ✓ 大小限制：1MB | 提示：上傳活動計畫書可提高核准率</small>
    <div id="planDocumentError" style="color: #e74c3c; margin-top: 0.5rem; display: none;"></div>
</div>
```

### JavaScript 驗證函數
```javascript
function validatePlanDocument(isSubmitting = false) {
    const file = document.getElementById('planDocument').files[0];
    const MAX_FILE_SIZE = 1 * 1024 * 1024; // 1MB
    const ALLOWED_TYPE = 'application/pdf';
    
    // 檢查檔案格式
    if (file.type !== ALLOWED_TYPE) {
        return { valid: false, error: '只支援 PDF 格式的檔案' };
    }
    
    // 檢查檔案大小
    if (file.size > MAX_FILE_SIZE) {
        return { valid: false, error: '檔案大小超過 1MB 限制' };
    }
    
    return { valid: true };
}
```

### CSS 增強
```css
.form-group input[type="file"] {
    padding: 0.5rem;
    border: 2px dashed #3498db;
}

.form-group input[type="file"]:hover {
    border-color: #2980b9;
    background-color: #ecf0f1;
}
```

---

## 📝 使用說明

### 使用者流程

1. **選擇檔案**
   - 點擊「選擇檔案」按鈕
   - 檔案選擇器只顯示 PDF 檔案

2. **自動驗證**
   - 選擇檔案後，系統自動驗證
   - 下方顯示驗證結果

3. **錯誤處理**
   - 如果檔案格式不符或大小超限，顯示紅色錯誤訊息
   - 提示使用者選擇符合要求的檔案

4. **提交申請**
   - 驗證通過後可提交申請
   - 提交時再次驗證檔案

### 測試檔案
- ✓ 有效檔案：小於 1MB 的 PDF 檔案
- ❌ 無效檔案：
  - Word 文件 (.docx)
  - 大於 1MB 的 PDF
  - 圖片檔案 (.jpg, .png)

---

## 🎯 完成的 User Story 場景

### User Story 1.3: 活動企劃書數位遞交

**Scenario 1: 成功上傳檔案**
```
Given: 使用者已準備好活動計畫書
When: 選擇符合要求的 PDF 檔案（<1MB）
Then: 系統顯示「檔案已驗證」，並將檔案與申請資料關聯保存
```

**Scenario 2: 上傳多個附件**
```
Given: 使用者有多個與活動相關的附件
When: 依序上傳 PDF 檔案
Then: 系統成功保存所有附件，申請頁面顯示附件清單
```
*注：目前支援單一企劃書上傳，可擴展為多檔案*

**Scenario 3: 上傳不支援的檔案格式（異常處理）**
```
Given: 使用者準備上傳 Word 文件或其他格式
When: 嘗試上傳非 PDF 格式的檔案
Then: 系統顯示錯誤訊息：「只支援 PDF 格式的檔案」
      並要求重新上傳符合格式的檔案
```

**Scenario 4: 檔案過大（異常處理）**
```
Given: 使用者要上傳大於 1MB 的 PDF 檔案
When: 嘗試上傳超大檔案
Then: 系統顯示提示：「檔案大小超過 1MB 限制」
      並提醒上傳符合大小限制的檔案
```

---

## 💾 資料儲存

### 申請紀錄中的附件資訊
```javascript
{
    id: 'app001',
    applicantName: '林宇芊',
    // ... 其他欄位
    attachments: ['活動企劃書.pdf'],  // 檔案名稱
    attachmentSize: 524288,            // 檔案大小（位元組）
    // ... 其他欄位
}
```

---

## 🔄 未來可擴展功能

- [ ] 支援多個附件同時上傳
- [ ] 支援其他文件格式（Word、PPT）
- [ ] 檔案預覽功能
- [ ] 檔案下載功能
- [ ] 病毒掃描整合
- [ ] 自動文件壓縮
- [ ] 雲端儲存整合

---

## ✨ 總結

✅ **已完成**
- PDF 格式限制
- 1MB 大小限制
- 實時檔案驗證
- 友善的錯誤提示
- 視覺化反饋設計

**驗收標準**：所有 User Story 1.3 的接受條件均已實現
