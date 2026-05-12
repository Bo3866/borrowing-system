# 草稿暫存功能使用指南

## 功能概述

本系統實現了「暫存草稿」與「草稿管理中心」功能，允許使用者將未完成的借用申請暫時儲存在本地瀏覽器中（使用 LocalStorage），以便稍後繼續編輯。

## 核心功能

### 1. 暫存申請按鈕
- **位置**：借用申請表單的按鈕區域，位於「確認借用」按鈕左側
- **外觀**：黃色次要按鈕，標籤為「暫存申請」
- **操作**：點擊後將當前表單資料儲存為草稿

### 2. 草稿箱按鈕
- **位置**：暫存申請按鈕右側
- **外觀**：藍色按鈕，標籤為「草稿箱」
- **操作**：點擊後打開草稿管理中心模態框

### 3. 草稿管理中心（Modal）
- **顯示內容**：已儲存的所有草稿列表
- **表格欄位**：
  - 暫存時間：草稿儲存的日期時間
  - 用途摘要：用途說明文本的前50個字符
  - 操作按鈕：「載入」和「刪除」

### 4. 草稿操作
- **載入（Load）**：將選定的草稿回填至表單，自動關閉管理中心
- **刪除（Delete）**：移除不需要的草稿（需確認）
- **新增申請（New）**：清空當前表單，建立新申請（已暫存的草稿不會遺失）

## 資料結構

### 草稿資料格式

每一筆草稿包含以下欄位：

```json
{
  "draftId": "draft_1715000000000_abc123def",
  "timestamp": "2025-05-11 14:30:45",
  "resourceType": "equipment",
  "cartItems": [
    {
      "type": "equipment",
      "code": "PROJ001",
      "quantity": 1
    },
    {
      "type": "space",
      "code": "ROOM_A101"
    }
  ],
  "borrowDate": "2025-05-15",
  "startPeriodCode": "D1",
  "endPeriodCode": "D3",
  "phone": "0912345678",
  "purpose": "班級活動課程展示"
}
```

### 欄位說明

| 欄位 | 類型 | 說明 |
|------|------|------|
| draftId | string | 唯一識別碼（時間戳 + 隨機碼） |
| timestamp | string | 儲存時間（格式: YYYY-MM-DD HH:mm:ss） |
| resourceType | string | 資源類型（'equipment' 或 'space'） |
| cartItems | Array | 購物車物品陣列 |
| borrowDate | string | 借用日期（格式: YYYY-MM-DD） |
| startPeriodCode | string | 開始節次代碼（如 'D0', 'E1'） |
| endPeriodCode | string | 結束節次代碼 |
| phone | string | 聯絡電話 |
| purpose | string | 用途說明 |

## 技術實現

### 使用的技術

- **LocalStorage**：客戶端本地儲存，無需連接伺服器
- **JavaScript 模組**：DraftManager 類管理所有草稿操作
- **JSON 序列化**：將表單資料轉換為 JSON 格式儲存和恢復

### 文件結構

#### 1. DraftManager.js（新增）
- 核心邏輯模組，提供以下方法：
  - `extractFormData()`：從表單提取資料
  - `saveDraft()`：保存新草稿
  - `loadAllDrafts()`：取得所有草稿
  - `getDraftById()`：根據 ID 取得草稿
  - `deleteDraft()`：刪除草稿
  - `loadDraftToForm()`：將草稿回填至表單
  - `clearForm()`：清空表單

#### 2. borrow.php（修改）
**新增內容：**
- 暫存申請和草稿箱按鈕（HTML）
- 草稿管理中心模態框（HTML）
- 草稿功能整合腳本（JavaScript）

**修改位置：**
- 第 ~1273 行：`<div class="form-buttons">` 區段
- 第 ~1303 行：新增模態框 HTML
- 第 ~1322 行：新增 `<script src="DraftManager.js"></script>`
- 第 ~1330 行：新增草稿功能整合代碼

#### 3. styles.css（修改）
**新增 CSS 類別：**
- `.draft-buttons`：按鈕組容器
- `.btn-draft`、`.btn-draft-save`、`.btn-draft-manage`：按鈕樣式
- `.draft-modal-overlay`、`.draft-modal`：模態框樣式
- `.draft-modal-header`、`.draft-modal-content`、`.draft-modal-footer`：模態框部分樣式
- `.draft-table`、`.draft-btn-load`、`.draft-btn-delete`：表格和按鈕樣式
- `.draft-message`：訊息提示樣式

## 使用流程

### 場景 1：暫存申請

1. 使用者填寫借用表單
   - 選擇器材或場地
   - 填寫借用日期、時段
   - 填寫聯絡電話
   - 填寫用途說明

2. 點擊「暫存申請」按鈕
   - 系統驗證必填欄位
   - 生成唯一的 draftId
   - 保存草稿到瀏覽器 LocalStorage
   - 顯示成功提示訊息

### 場景 2：管理草稿

1. 點擊「草稿箱」按鈕
   - 打開草稿管理中心模態框
   - 顯示所有已暫存的草稿列表

2. 在列表中操作
   - **載入**：選擇草稿並點擊「載入」
     - 草稿資料回填至表單
     - 模態框自動關閉
     - 可繼續編輯或直接提交
   
   - **刪除**：點擊「刪除」按鈕
     - 需確認刪除操作
     - 草稿從 LocalStorage 移除
     - 列表重新渲染

### 場景 3：建立新申請

1. 在草稿管理中心中點擊「新增申請」
   - 表單所有欄位被清空
   - 可以開始填寫新的申請
   - 模態框自動關閉
   - 已暫存的草稿不受影響

## 驗證與錯誤處理

### 必填欄位驗證

暫存前必須填寫以下欄位，否則將顯示錯誤訊息：

- ✓ 借用日期
- ✓ 開始節次
- ✓ 結束節次
- ✓ 聯絡電話
- ✓ 用途說明
- ✓ 至少選擇一項器材或場地

### 錯誤訊息類型

| 訊息類型 | 顏色 | 用途 |
|---------|------|------|
| success | 綠色 | 操作成功 |
| error | 紅色 | 操作失敗或驗證錯誤 |
| warning | 黃色 | 警告訊息 |

### LocalStorage 容量考慮

- 瀏覽器 LocalStorage 一般限制為 5-10MB
- 每筆草稿約佔用 500-1000 bytes
- 理論上可暫存 5,000+ 筆草稿
- 超出容量時會顯示警告訊息

## 兼容性

### 支持的瀏覽器

- ✓ Chrome/Chromium（版本 4+）
- ✓ Firefox（版本 3.5+）
- ✓ Safari（版本 4+）
- ✓ Edge（版本 15+）
- ✓ Internet Explorer（版本 8+，但功能受限）

### LocalStorage 可用性檢查

系統會在初始化時檢查 LocalStorage 是否可用。在隱私瀏覽模式下 LocalStorage 可能不可用。

## API 文檔

### DraftManager 類

#### 靜態方法

```javascript
// 檢查 LocalStorage 是否可用
DraftManager.isLocalStorageAvailable()
```

#### 實例方法

```javascript
// 從表單提取資料
draftManager.extractFormData()

// 保存新草稿
draftManager.saveDraft(draftData = null)

// 取得所有草稿
draftManager.loadAllDrafts()

// 根據 ID 取得草稿
draftManager.getDraftById(draftId)

// 刪除草稿
draftManager.deleteDraft(draftId)

// 刪除所有草稿
draftManager.deleteAllDrafts()

// 將草稿回填至表單
draftManager.loadDraftToForm(draft)

// 清空表單
draftManager.clearForm()

// 生成草稿統計
draftManager.getDraftStats()

// 生成唯一 ID
draftManager.generateDraftId()

// 格式化日期時間
draftManager.formatDateTime(date)

// 生成用途摘要
draftManager.generatePurposeSummary(purpose)
```

### 全域函數

```javascript
// 載入指定草稿
window.loadDraft(draftId)

// 刪除指定草稿
window.deleteDraft(draftId)

// 關閉草稿管理中心
window.closeDraftModal()
```

## 故障排除

### 問題 1：暫存按鈕不工作
**原因**：JavaScript 未正確加載或 DraftManager.js 路徑錯誤
**解決方案**：
1. 檢查瀏覽器開發工具控制台是否有錯誤
2. 確認 DraftManager.js 文件在正確位置
3. 檢查 `<script src="DraftManager.js"></script>` 標籤

### 問題 2：草稿無法保存
**原因**：LocalStorage 配額已滿或不可用
**解決方案**：
1. 清除舊草稿或瀏覽器快取
2. 檢查是否在隱私瀏覽模式
3. 檢查瀏覽器 LocalStorage 設置

### 問題 3：載入草稿後表單顯示不完整
**原因**：表單 UI 渲染函數未被觸發
**解決方案**：
1. 檢查 `refreshModeUI()` 和 `renderCart()` 函數是否存在
2. 稍候片刻後手動重新整理表單

### 問題 4：表單清空後購物車不清空
**原因**：購物車渲染邏輯未被正確觸發
**解決方案**：
1. 手動重新載入頁面
2. 檢查瀏覽器控制台錯誤訊息

## 數據持久性

### 重要提示

- ✓ 草稿存儲在**瀏覽器本地**，不同瀏覽器/設備無法同步
- ✓ 清除瀏覽器快取或 LocalStorage 數據時，所有草稿將被刪除
- ✓ 這些草稿是**臨時性的**，不應用於長期存儲
- ✓ 完成借用申請後，正式數據將保存在伺服器數據庫中

### 建議用途

- 申請編輯中途中斷需暫存
- 準備多個類似申請時快速切換
- 避免網絡中斷導致的數據丟失

## 未來改進方向

- [ ] 支持伺服器端草稿同步（跨設備）
- [ ] 草稿自動保存功能（防止意外丟失）
- [ ] 草稿版本控制
- [ ] 草稿導出/導入功能
- [ ] 時間優化限制（如 7 天自動刪除舊草稿）
- [ ] 支持提案文件（proposal_file）暫存

## 聯絡與支持

如有問題或建議，請聯絡系統管理員。
