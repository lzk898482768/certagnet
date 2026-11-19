<?php
declare(strict_types=1);

/**
 * 容器健康检查：MySQL / Redis 均可用时返回 0。
 * 仅输出简短原因，不打印连接串或密码。
 */

$env = static function (string $name, string $default = ''): string {
    $value = getenv($name);
    return ($value === false || $value === '') ? $default : (string)$value;
};

try {
    $host = $env('FNOS_DB_HOST', 'mysql');
    $port = $env('FNOS_DB_PORT', '3306');
    $pdo = new PDO(
        "mysql:host={$host};port={$port};charset=utf8mb4",
        $env('FNOS_DB_USER', 'root'),
        $env('FNOS_DB_PASS'),
        [PDO::ATTR_TIMEOUT => 3, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdo->query('SELECT 1');
} catch (Throwable $e) {
    fwrite(STDERR, "database unavailable\n");
    exit(1);
}

try {
    $redis = new Redis();
    if (!$redis->connect($env('FNOS_REDIS_HOST', 'redis'), (int)$env('FNOS_REDIS_PORT', '6379'), 2.0)) {
        throw new RuntimeException('connect');
    }
    $password = $env('FNOS_REDIS_PASSWORD');
    if ($password !== '' && !$redis->auth($password)) {
        throw new RuntimeException('auth');
    }
    if (!$redis->select((int)$env('FNOS_REDIS_DB', '0'))) {
        throw new RuntimeException('select');
    }
    $redis->ping();
} catch (Throwable $e) {
    fwrite(STDERR, "redis unavailable\n");
    exit(1);
}

exit(0);
