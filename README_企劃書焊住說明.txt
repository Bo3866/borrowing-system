本版修正企劃書在以下流程消失的問題：

填到第二步/第三步
→ 暫存
→ 草稿箱繼續編輯
→ 回到第一步
→ 再切回第三步送出

修正方式：
1. load_draft.php 回傳 proposal_file / proposal_original_name / proposal_uploaded_at。
2. borrow.php 把這些值寫入 hidden input。
3. borrow.php 透過 sessionStorage 在切換步驟時補回 hidden input。
4. save_draft.php 在沒有重新選 PDF 時，不會清空舊的 proposal_file。
5. 正式送出時，如果 file input 為空，但 hidden input 有後端 proposal_file，就沿用後端檔案。
6. 請保留資料夾 uploads/draft_proposals。

注意：
瀏覽器不允許用 JS 自動回填 input type=file，這是安全限制。
所以正確做法是保存後端檔案路徑，而不是試圖把檔案塞回 file input。
