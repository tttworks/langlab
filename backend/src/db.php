<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;

require __DIR__ . '/../vendor/autoload.php';

// 时区：数据库里的 day / Y-m-d 全部按本地时间，避免「今天」错位导致 streak 算错
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Shanghai');

$baseDir = dirname(__DIR__);

// 兼容 illuminate/database 独立使用：定义 Laravel 的 base_path() 助手
if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        $base = dirname(__DIR__);
        if ($path === '') {
            return $base;
        }
        if (str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:/', $path)) {
            return $path;
        }
        return $base . DIRECTORY_SEPARATOR . ltrim($path, '\\/');
    }
}

Dotenv::createImmutable($baseDir)->safeLoad();

$driver = $_ENV['DB_CONNECTION'] ?? 'sqlite';

$config = ['driver' => $driver, 'prefix' => ''];

if ($driver === 'sqlite') {
    $dbDir = $baseDir . '/data';
    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0755, true);
    }
    $dbFile = $dbDir . '/langlab.sqlite';
    if (!file_exists($dbFile)) {
        touch($dbFile); // 确保文件存在，realpath() 能解析
    }
    $config['database'] = $dbFile;
    $config['foreign_key_constraints'] = true;
} else {
    $config['host'] = $_ENV['DB_HOST'] ?? '127.0.0.1';
    $config['port'] = (int) ($_ENV['DB_PORT'] ?? 3306);
    $config['database'] = $_ENV['DB_DATABASE'] ?? 'langlab';
    $config['username'] = $_ENV['DB_USERNAME'] ?? 'root';
    $config['password'] = $_ENV['DB_PASSWORD'] ?? '';
    $config['charset'] = 'utf8mb4';
    $config['collation'] = 'utf8mb4_unicode_ci';
}

$capsule = new Capsule();
$capsule->addConnection($config);
$capsule->setAsGlobal();
$capsule->bootEloquent();

return $capsule;
