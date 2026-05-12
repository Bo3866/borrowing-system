/**
 * DraftManager.js - 校園資源租借系統 - 草稿管理模組
 * 
 * 功能：
 * - 將表單資料暫存到 LocalStorage
 * - 管理多筆草稿（新增、讀取、刪除）
 * - 提供草稿列表顯示和管理
 */

class DraftManager {
    constructor(storageKey = 'borrowing_system_drafts') {
        this.storageKey = storageKey;
        this.drafts = this.loadAllDrafts();
    }

    /**
     * 生成唯一的 draftId
     * @returns {string} 唯一的草稿 ID
     */
    generateDraftId() {
        return `draft_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
    }

    /**
     * 取得格式化的日期時間字符串
     * @param {Date} date - 日期對象
     * @returns {string} 格式化後的日期時間 (YYYY-MM-DD HH:mm:ss)
     */
    formatDateTime(date) {
        const pad = (n) => String(n).padStart(2, '0');
        return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ` +
               `${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
    }

    /**
     * 生成用途摘要（前 50 個字符）
     * @param {string} purpose - 完整的用途說明
     * @returns {string} 摘要文本
     */
    generatePurposeSummary(purpose) {
        if (!purpose) return '（未填寫）';
        const maxLen = 50;
        return purpose.length > maxLen ? purpose.substring(0, maxLen) + '...' : purpose;
    }

    /**
     * 提取表單資料結構
     * 
     * 資料結構定義：
     * {
     *   draftId: string,              // 唯一標識符
     *   timestamp: string,             // 儲存時間 (YYYY-MM-DD HH:mm:ss)
     *   resourceType: string,          // 資源類型 ('equipment' 或 'space')
     *   cartItems: Array<{
     *     type: string,               // 'equipment' 或 'space'
     *     code: string,               // 器材代碼或空間 ID
     *     quantity?: number           // 數量（僅器材需要）
     *   }>,
     *   borrowDate: string,            // 借用日期 (YYYY-MM-DD)
     *   startPeriodCode: string,       // 開始節次代碼 (如 'D0', 'E1')
     *   endPeriodCode: string,         // 結束節次代碼
     *   phone: string,                 // 聯絡電話
     *   purpose: string                // 用途說明
     * }
     */
    extractFormData() {
        const form = document.querySelector('form.borrow-form');
        if (!form) {
            console.warn('Cannot find borrow form');
            return null;
        }

        // 取得 cart_items 資料
        const cartItemsInput = form.querySelector('input[name="cart_items"]');
        let cartItems = [];
        if (cartItemsInput && cartItemsInput.value) {
            try {
                cartItems = JSON.parse(cartItemsInput.value);
            } catch (e) {
                console.error('Failed to parse cart_items:', e);
            }
        }

        return {
            draftId: this.generateDraftId(),
            timestamp: this.formatDateTime(new Date()),
            resourceType: form.querySelector('select[name="resource_type"]')?.value || 'equipment',
            cartItems: cartItems || [],
            borrowDate: form.querySelector('input[name="borrow_date"]')?.value || '',
            startPeriodCode: form.querySelector('select[name="start_period_code"]')?.value || '',
            endPeriodCode: form.querySelector('select[name="end_period_code"]')?.value || '',
            phone: form.querySelector('input[name="phone"]')?.value || '',
            purpose: form.querySelector('textarea[name="purpose"]')?.value || ''
        };
    }

    /**
     * 儲存新草稿到 LocalStorage
     * @param {Object} draftData - 草稿資料（若為 null，將自動從表單提取）
     * @returns {Object} 已儲存的草稿物件
     */
    saveDraft(draftData = null) {
        const draft = draftData || this.extractFormData();
        
        if (!draft) {
            throw new Error('無法提取表單資料');
        }

        // 驗證必要欄位
        if (!draft.borrowDate || !draft.startPeriodCode || !draft.endPeriodCode) {
            throw new Error('請填寫借用日期和時段');
        }

        if (!draft.phone) {
            throw new Error('請填寫聯絡電話');
        }

        if (!draft.purpose) {
            throw new Error('請填寫用途說明');
        }

        if (!draft.cartItems || draft.cartItems.length === 0) {
            throw new Error('請選擇至少一項器材或場地');
        }

        // 添加或更新草稿
        this.drafts.push(draft);
        this.persistDrafts();

        return draft;
    }

    /**
     * 從 LocalStorage 取得所有草稿
     * @returns {Array} 草稿陣列
     */
    loadAllDrafts() {
        try {
            const stored = localStorage.getItem(this.storageKey);
            return stored ? JSON.parse(stored) : [];
        } catch (e) {
            console.error('Failed to load drafts from localStorage:', e);
            return [];
        }
    }

    /**
     * 根據 draftId 取得單一草稿
     * @param {string} draftId - 草稿 ID
     * @returns {Object|null} 草稿物件或 null
     */
    getDraftById(draftId) {
        return this.drafts.find(d => d.draftId === draftId) || null;
    }

    /**
     * 根據 draftId 刪除草稿
     * @param {string} draftId - 草稿 ID
     * @returns {boolean} 是否成功刪除
     */
    deleteDraft(draftId) {
        const initialLength = this.drafts.length;
        this.drafts = this.drafts.filter(d => d.draftId !== draftId);
        
        if (this.drafts.length < initialLength) {
            this.persistDrafts();
            return true;
        }
        return false;
    }

    /**
     * 刪除所有草稿
     */
    deleteAllDrafts() {
        this.drafts = [];
        this.persistDrafts();
    }

    /**
     * 將草稿資料回填至表單
     * @param {Object} draft - 草稿物件
     * @returns {boolean} 是否成功回填
     */
    loadDraftToForm(draft) {
        const form = document.querySelector('form.borrow-form');
        if (!form) {
            console.warn('Cannot find borrow form');
            return false;
        }

        try {
            // 設置基本欄位
            const resourceTypeSelect = form.querySelector('select[name="resource_type"]');
            if (resourceTypeSelect) {
                resourceTypeSelect.value = draft.resourceType || 'equipment';
                // 觸發 change 事件以更新 UI
                resourceTypeSelect.dispatchEvent(new Event('change', { bubbles: true }));
            }

            const borrowDateInput = form.querySelector('input[name="borrow_date"]');
            if (borrowDateInput) {
                borrowDateInput.value = draft.borrowDate || '';
                borrowDateInput.dispatchEvent(new Event('change', { bubbles: true }));
            }

            const startPeriodSelect = form.querySelector('select[name="start_period_code"]');
            if (startPeriodSelect) {
                startPeriodSelect.value = draft.startPeriodCode || '';
            }

            const endPeriodSelect = form.querySelector('select[name="end_period_code"]');
            if (endPeriodSelect) {
                endPeriodSelect.value = draft.endPeriodCode || '';
            }

            const phoneInput = form.querySelector('input[name="phone"]');
            if (phoneInput) {
                phoneInput.value = draft.phone || '';
            }

            const purposeTextarea = form.querySelector('textarea[name="purpose"]');
            if (purposeTextarea) {
                purposeTextarea.value = draft.purpose || '';
            }

            // 重新設置 cart_items
            const cartItemsInput = form.querySelector('input[name="cart_items"]');
            if (cartItemsInput) {
                cartItemsInput.value = JSON.stringify(draft.cartItems || []);
                cartItemsInput.dispatchEvent(new Event('change', { bubbles: true }));
            }

            // 觸發表單渲染邏輯更新（如果存在）
            if (typeof refreshModeUI === 'function') {
                setTimeout(() => refreshModeUI(), 100);
            }
            if (typeof renderCart === 'function') {
                setTimeout(() => renderCart(), 100);
            }

            console.log('Draft loaded successfully:', draft.draftId);
            return true;
        } catch (e) {
            console.error('Error loading draft to form:', e);
            return false;
        }
    }

    /**
     * 清空表單所有欄位（用於「新增申請」功能）
     */
    clearForm() {
        const form = document.querySelector('form.borrow-form');
        if (!form) {
            console.warn('Cannot find borrow form');
            return;
        }

        try {
            // 清空基本欄位
            form.querySelector('select[name="resource_type"]')!.value = 'equipment';
            form.querySelector('input[name="borrow_date"]')!.value = '';
            form.querySelector('select[name="start_period_code"]')!.value = '';
            form.querySelector('select[name="end_period_code"]')!.value = '';
            form.querySelector('input[name="phone"]')!.value = '';
            form.querySelector('textarea[name="purpose"]')!.value = '';

            // 清空購物車
            const cartItemsInput = form.querySelector('input[name="cart_items"]');
            if (cartItemsInput) {
                cartItemsInput.value = '[]';
                cartItemsInput.dispatchEvent(new Event('change', { bubbles: true }));
            }

            // 觸發 UI 更新
            if (typeof refreshModeUI === 'function') {
                setTimeout(() => refreshModeUI(), 100);
            }
            if (typeof renderCart === 'function') {
                setTimeout(() => renderCart(), 100);
            }

            console.log('Form cleared');
        } catch (e) {
            console.error('Error clearing form:', e);
        }
    }

    /**
     * 獲取草稿統計資訊
     * @returns {Object} 包含數量統計的物件
     */
    getDraftStats() {
        return {
            totalCount: this.drafts.length,
            drafts: this.drafts
        };
    }

    /**
     * 將草稿陣列持久化到 LocalStorage
     */
    persistDrafts() {
        try {
            localStorage.setItem(this.storageKey, JSON.stringify(this.drafts));
        } catch (e) {
            if (e.name === 'QuotaExceededError') {
                console.error('LocalStorage 配額已滿', e);
            } else {
                console.error('Failed to save drafts to localStorage:', e);
            }
        }
    }

    /**
     * 檢查 LocalStorage 是否可用
     * @returns {boolean}
     */
    static isLocalStorageAvailable() {
        try {
            const test = '__storage_test__';
            localStorage.setItem(test, test);
            localStorage.removeItem(test);
            return true;
        } catch (e) {
            return false;
        }
    }
}

// Initialize global instance
window.draftManager = new DraftManager();
console.log('[DraftManager] Initialized successfully');
