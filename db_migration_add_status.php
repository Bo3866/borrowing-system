<?php
/**
 * 數據庫遷移：為 reservations 表添加 status 欄位（如果不存在）
 * 用途：支持草稿功能
 * 執行方式：訪問 db_migration_add_status.php 進行遷移
 */

require_once __DIR__ . '/config/database.php';

$dbError = '';
$link = getMysqliConnection($dbError);

if ($dbError !== '') {
    die('數據庫連接失敗：' . htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8'));
}

try {
    // 檢查 reservations 表是否已有 status 欄位
    $checkSql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
                 WHERE TABLE_SCHEMA = 'borrowing_system' 
                 AND TABLE_NAME = 'reservations' 
                 AND COLUMN_NAME = 'status'";
    $checkResult = mysqli_query($link, $checkSql);
    
    if (!$checkResult) {
        throw new Exception('查詢表結構失敗：' . mysqli_error($link));
    }
    
    if (mysqli_num_rows($checkResult) === 0) {
        // 添加 status 欄位
        $alterSql = "ALTER TABLE reservations 
                     ADD COLUMN status TINYINT DEFAULT 1 
                     COMMENT '申請狀態：0=草稿, 1=正式送出'
                     AFTER approval_status";
        
        if (!mysqli_query($link, $alterSql)) {
            throw new Exception('添加 status 欄位失敗：' . mysqli_error($link));
        }
        
        echo '✓ 已添加 status 欄位到 reservations 表' . "\n";
    } else {
        echo '✓ status 欄位已存在' . "\n";
    }
    
    // 檢查 rejection_reason 欄位
    $checkSql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
                 WHERE TABLE_SCHEMA = 'borrowing_system' 
                 AND TABLE_NAME = 'reservations' 
                 AND COLUMN_NAME = 'rejection_reason'";
    $checkResult = mysqli_query($link, $checkSql);
    
    if (!$checkResult) {
        throw new Exception('查詢表結構失敗：' . mysqli_error($link));
    }
    
    if (mysqli_num_rows($checkResult) === 0) {
        // 添加 rejection_reason 欄位
        $alterSql = "ALTER TABLE reservations 
                     ADD COLUMN rejection_reason VARCHAR(255) NULL 
                     COMMENT '拒絕原因'
                     AFTER status";
        
        if (!mysqli_query($link, $alterSql)) {
            throw new Exception('添加 rejection_reason 欄位失敗：' . mysqli_error($link));
        }
        
        echo '✓ 已添加 rejection_reason 欄位到 reservations 表' . "\n";
    } else {
        echo '✓ rejection_reason 欄位已存在' . "\n";
    }
    
    // 檢查 purpose 欄位
    $checkSql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
                 WHERE TABLE_SCHEMA = 'borrowing_system' 
                 AND TABLE_NAME = 'reservations' 
                 AND COLUMN_NAME = 'purpose'";
    $checkResult = mysqli_query($link, $checkSql);
    
    if (!$checkResult) {
        throw new Exception('查詢表結構失敗：' . mysqli_error($link));
    }
    
    if (mysqli_num_rows($checkResult) === 0) {
        // 添加 purpose 欄位
        $alterSql = "ALTER TABLE reservations 
                     ADD COLUMN purpose TEXT NULL 
                     COMMENT '用途說明'
                     AFTER rejection_reason";
        
        if (!mysqli_query($link, $alterSql)) {
            throw new Exception('添加 purpose 欄位失敗：' . mysqli_error($link));
        }
        
        echo '✓ 已添加 purpose 欄位到 reservations 表' . "\n";
    } else {
        echo '✓ purpose 欄位已存在' . "\n";
    }
    
    echo "\n✅ 數據庫遷移完成！\n";
    
} catch (Exception $e) {
    echo '❌ 遷移失敗：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "\n";
} finally {
    mysqli_close($link);
}
?>
