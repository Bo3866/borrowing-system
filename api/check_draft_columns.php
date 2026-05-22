<?php
/**
 * 檢查 reservation_drafts 草稿資料表欄位
 */

require_once dirname(__DIR__) . '/config/database.php';

header('Content-Type: application/json; charset=utf-8');

$dbError = '';
$link = getMysqliConnection($dbError);

if ($dbError !== '') {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '資料庫連線失敗：' . $dbError
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $checkSql = "
        SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = 'borrowing_system'
          AND TABLE_NAME = 'reservation_drafts'
        ORDER BY ORDINAL_POSITION
    ";

    $result = mysqli_query($link, $checkSql);

    if (!$result) {
        throw new Exception('查詢失敗：' . mysqli_error($link));
    }

    $columns = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $columns[] = $row;
    }

    $requiredFields = [
        'draft_id',
        'user_id',
        'activity_name',
        'purpose',
        'proposal_file',
        'proposal_original_name',
        'proposal_uploaded_at',
        'current_step',
        'draft_data',
        'created_at',
        'updated_at'
    ];

    $foundFields = array_column($columns, 'COLUMN_NAME');
    $missingFields = array_values(array_diff($requiredFields, $foundFields));

    echo json_encode([
        'success' => true,
        'table' => 'reservation_drafts',
        'columns' => $columns,
        'missing_fields' => $missingFields,
        'status' => empty($missingFields)
            ? '✓ reservation_drafts 欄位完整'
            : '✗ 缺少欄位：' . implode(', ', $missingFields)
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} finally {
    mysqli_close($link);
}
?>