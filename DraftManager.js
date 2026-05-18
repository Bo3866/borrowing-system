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
     *   currentStep: number,           // 當前步驟 (1, 2, 或 3)
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

        // 取得當前步驟
        const currentStepInput = form.querySelector('input[name="current_step"]');
        const currentStep = currentStepInput ? parseInt(currentStepInput.value) : 1;

        return {
            draftId: this.generateDraftId(),
            timestamp: this.formatDateTime(new Date()),
            currentStep: currentStep,
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

        // 根據當前步驟進行驗證
        // 第一步：只驗證基本信息
        // 第二步和第三步：更寬鬆的驗證（允許不完整的表單）
        if (draft.currentStep === 1) {
            // 第一步驗證：確保至少有一些基本信息
            // 不強制要求所有字段（因為用戶可能只填了一部分）
        } else if (draft.currentStep === 2 || draft.currentStep === 3) {
            // 第二、三步：更寬鬆的驗證
            // 只在明確的字段為空時才提醒
        }

        // 輕量級驗證：至少檢查是否有某些有意義的數據
        const hasBasicData = draft.phone || draft.purpose || (draft.cartItems && draft.cartItems.length > 0);
        
        if (!hasBasicData && draft.currentStep === 1) {
            // 只在第一步且完全空白時才拒絕
            throw new Error('請至少填寫一些基本信息');
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

            // 設置當前步驟並導航到該步驟
            const currentStep = draft.currentStep || 1;
            const currentStepInput = form.querySelector('input[name="current_step"]');
            if (currentStepInput) {
                currentStepInput.value = currentStep;
            }
            
            // 導航到保存的步驟
            if (typeof goToStep === 'function') {
                setTimeout(() => goToStep(currentStep), 150);
            }

            console.log('Draft loaded successfully:', draft.draftId, 'at step', currentStep);
            return true;
        } catch (e) {
            console.error('Error loading draft to form:', e);
            return false;
        }
    }

    /**
     * 清空表單所有欄位（用於「新增申請」功能和暫存後重置）
     * @param {boolean} resetStep - 是否要重置到第一步
     */
    clearForm(resetStep = false) {
        console.log('DraftManager.clearForm called, resetStep=', resetStep);
        const form = document.querySelector('form.borrow-form');
        if (!form) {
            console.warn('Cannot find borrow form');
            return;
        }

        try {
            // 重置表單
            form.reset();

            // 清空基本欄位
            const resourceTypeSelect = form.querySelector('select[name="resource_type"]');
            if (resourceTypeSelect) resourceTypeSelect.value = 'equipment';
            
            const borrowDateInput = form.querySelector('input[name="borrow_date"]');
            if (borrowDateInput) borrowDateInput.value = '';
            
            const startPeriodSelect = form.querySelector('select[name="start_period_code"]');
            if (startPeriodSelect) startPeriodSelect.value = '';
            
            const endPeriodSelect = form.querySelector('select[name="end_period_code"]');
            if (endPeriodSelect) endPeriodSelect.value = '';
            
            const phoneInput = form.querySelector('input[name="phone"]');
            if (phoneInput) phoneInput.value = '';
            
            const purposeTextarea = form.querySelector('textarea[name="purpose"]');
            if (purposeTextarea) purposeTextarea.value = '';

            // 清空購物車
            const cartItemsInput = form.querySelector('input[name="cart_items"]');
            if (cartItemsInput) {
                cartItemsInput.value = '[]';
                cartItemsInput.dispatchEvent(new Event('change', { bubbles: true }));
            }

            // 清空選中列表
            const esSelectedList = document.getElementById('esSelectedList');
            if (esSelectedList) {
                esSelectedList.innerHTML = '';
            }

            // 清空企劃書名稱
            const proposalName = document.getElementById('proposal_file_name_display');
            if (proposalName) {
                proposalName.textContent = '';
            }

            // 如果需要重置步驟
            if (resetStep) {
                const currentStepInput = form.querySelector('input[name="current_step"]');
                if (currentStepInput) {
                    currentStepInput.value = '1';
                }

                // 隱藏所有步驟，顯示第一步
                document.querySelectorAll('.step-content').forEach(el => {
                    el.classList.remove('active');
                });
                const step1 = document.getElementById('step-content-1');
                if (step1) {
                    step1.classList.add('active');
                }

                // 更新 Stepper
                document.querySelectorAll('.stepper-item').forEach((el, i) => {
                    if (i === 0) {
                        el.classList.add('active');
                    } else {
                        el.classList.remove('active');
                    }
                });
            }

            // 觸發 UI 更新
            if (typeof refreshModeUI === 'function') {
                setTimeout(() => refreshModeUI(), 100);
            }
            if (typeof renderCart === 'function') {
                setTimeout(() => renderCart(), 100);
            }

            console.log('Form cleared', resetStep ? 'and reset to step 1' : '');
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
