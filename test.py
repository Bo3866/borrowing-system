import pytest
from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC

class TestResourceBorrowing:
    def setup_method(self):
        # 初始化瀏覽器 (以 Chrome 為例)
        self.driver = webdriver.Chrome()
        self.wait = WebDriverWait(self.driver, 10)

    def teardown_method(self):
        # 測試結束後關閉瀏覽器
        self.driver.quit()

    def test_online_application_submission(self):
        # 1. 開啟借用系統頁面
        self.driver.get("http://your-system-url.com/borrow")

        # 2. 輸入借用資訊 (輸入：借用資源、時段、活動資訊)
        self.driver.find_element(By.ID, "resource_name").send_keys("多功能活動教室 A")
        self.driver.find_element(By.ID, "time_slot").send_keys("2026-05-20 14:00-17:00")
        self.driver.find_element(By.ID, "activity_info").send_keys("系學會期中檢討會議")

        # 3. 提交表單
        self.driver.find_element(By.ID, "submit_button").click()

        # 4. 驗證輸出：是否顯示成功提交的確認訊息
        success_msg = self.wait.until(
            EC.visibility_of_element_located((By.CLASS_NAME, "alert-success"))
        )
        
        assert "申請單成功提交" in success_msg.text
        print("測試通過：申請單已成功提交並顯示確認訊息。")
