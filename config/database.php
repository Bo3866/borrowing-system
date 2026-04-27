<?php
declare(strict_types=1);

function envOrNull(string $name): ?string
{
    $value = getenv($name);
    if ($value === false) {
        return null;
    }

    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function getDatabaseConfig(): array
{
    return [
        'host' => envOrNull('DB_HOST') ?? '127.0.0.1',
        'port' => envOrNull('DB_PORT') !== null ? (int)envOrNull('DB_PORT') : 0,
        'database' => envOrNull('DB_NAME') ?? 'borrowing_system',
        'username' => envOrNull('DB_USER') ?? 'root',
        'password' => envOrNull('DB_PASSWORD') ?? '',
    ];
}

function getDatabaseConnectionCandidates(): array
{
    $envHost = envOrNull('DB_HOST');
    $envPort = envOrNull('DB_PORT');
    $envDatabase = envOrNull('DB_NAME');
    $envUser = envOrNull('DB_USER');
    $envPassword = envOrNull('DB_PASSWORD');

    $candidates = [];

    if ($envHost !== null || $envPort !== null || $envDatabase !== null || $envUser !== null || $envPassword !== null) {
        $candidates[] = [
            'host' => $envHost ?? '127.0.0.1',
            'port' => $envPort !== null ? (int)$envPort : 3307,
            'database' => $envDatabase ?? 'borrowing_system',
            'username' => $envUser ?? 'root',
            'password' => $envPassword ?? '',
        ];
    }

    $candidates[] = [
        'host' => '127.0.0.1',
        'port' => 3307,
        'database' => 'borrowing_system',
        'username' => 'root',
        'password' => '',
    ];

    $candidates[] = [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'borrowing_system',
        'username' => 'root',
        'password' => '12345678',
    ];

    $candidates[] = [
        'host' => 'localhost',
        'port' => 3307,
        'database' => 'borrowing_system',
        'username' => 'root',
        'password' => '',
    ];

    $candidates[] = [
        'host' => 'localhost',
        'port' => 3306,
        'database' => 'borrowing_system',
        'username' => 'root',
        'password' => '12345678',
    ];

    return $candidates;
}

function getMysqliConnection(?string &$error = null): ?mysqli
{
    foreach (getDatabaseConnectionCandidates() as $config) {
        $link = @mysqli_connect(
            $config['host'],
            $config['username'],
            $config['password'],
            $config['database'],
            $config['port']
        );

        if ($link) {
            mysqli_set_charset($link, 'utf8mb4');
            return $link;
        }
    }

    $error = mysqli_connect_error();
    return null;
}

function getDatabaseConnection(): PDO
{
    $lastError = null;

    foreach (getDatabaseConnectionCandidates() as $config) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'],
            $config['database']
        );

        try {
            return new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (Throwable $t) {
            $lastError = $t->getMessage();
        }
    }

    throw new RuntimeException('資料庫連線失敗：' . ($lastError ?? 'unknown error'));
}
