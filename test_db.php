<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

try {
    $pdo = getDatabaseConnection();
    $stmt = $pdo->query('SELECT 1 AS ok');
    $row = $stmt->fetch();
    echo "DB OK\n";
} catch (Throwable $t) {
    echo "DB ERROR: " . $t->getMessage() . "\n";
    exit(1);
}
