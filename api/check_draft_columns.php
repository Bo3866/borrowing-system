<?php
/**
 * 检查数据库表列
 */

require_once dirname(__DIR__) . '/config/database.php';

header('Content-Type: application/json; charset=utf-8');

$dbError = '';
$link = getMysqliConnection($dbError);

if ($dbError !== '') {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '数据库连接失败：' . $dbError]);
    exit;
}

try {
    $checkSql = "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE 
                 FROM INFORMATION_SCHEMA.COLUMNS 
                 WHERE TABLE_SCHEMA = 'borrowing_system' 
                 AND TABLE_NAME = 'reservations'
                 ORDER BY ORDINAL_POSITION";
    
    $result = mysqli_query($link, $checkSql);
    if (!$result) {
        throw new Exception('查询失败：' . mysqli_error($link));
    }
    
    $columns = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $columns[] = $row;
    }
    
    // 检查必要字段
    $requiredFields = ['status', 'purpose', 'rejection_reason'];
    $foundFields = array_column($columns, 'COLUMN_NAME');
    $missingFields = array_diff($requiredFields, $foundFields);
    
    echo json_encode([
        'success' => true,
        'columns' => $columns,
        'missing_fields' => $missingFields,
        'status' => empty($missingFields) ? '✓ 所有必要字段已存在' : '✗ 缺少字段：' . implode(', ', $missingFields)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} finally {
    mysqli_close($link);
}
?>
