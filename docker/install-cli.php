<?php
declare(strict_types=1);

/**
 * 飞牛 fnOS 容器初始化脚本（仅在容器内以 CLI 运行）
 *
 * 复用 backend/public/install.php 中经过生产验证的安装函数：
 *   - doInstall()        建库、导入 init.sql + init_data.sql、创建管理员、写安装锁
 *   - installRedisProbe() Redis 读写 / 过期 / 删除探测
 *   - splitSql()         SQL 拆分
 *
 * 与网页安装器的差异：
 *   - 数据库连接、管理员账号全部来自容器环境变量（compose 注入）
 *   - 额外写入 [GOV] PASSWORD_KEY，保证政务网申报密码可解密
 *   - 飞牛版使用独立数据库（默认 cert_agent_fnos），不与其它部署共用
 *
 * 用法：
 *   php fnos-install.php              首次安装
 *   php fnos-install.php --env-only    仅按环境变量重建 .env（数据库已存在时）
 */

// 容器内固定为 /var/www/backend；FNOS_APP_ROOT 仅用于本地调试安装器
$root = getenv('FNOS_APP_ROOT') ?: '/var/www/backend';
$installer = $root . '/public/install.php';

if (!is_file($installer)) {
    fwrite(STDERR, "[fnos] 缺少安装程序：{$installer}\n");
    exit(1);
}

// CLI 模式下 install.php 定义完函数即 return，不会执行网页安装流程
require $installer;

function fnos_env(string $name, string $default = ''): string
{
    $value = getenv($name);
    return ($value === false || trim((string)$value) === '') ? $default : (string)$value;
}

function fnos_config(): array
{
    return [
        'host'           => fnos_env('FNOS_DB_HOST', 'mysql'),
        'port'           => fnos_env('FNOS_DB_PORT', '3306'),
        'db'             => fnos_env('FNOS_DB_NAME', 'cert_agent_fnos'),
        'user'           => fnos_env('FNOS_DB_USER', 'root'),
        'pass'           => fnos_env('FNOS_DB_PASS'),
        'redis_host'     => fnos_env('FNOS_REDIS_HOST', 'redis'),
        'redis_port'     => fnos_env('FNOS_REDIS_PORT', '6379'),
        'redis_password' => fnos_env('FNOS_REDIS_PASSWORD'),
        'redis_db'       => fnos_env('FNOS_REDIS_DB', '0'),
        'redis_prefix'   => fnos_env('FNOS_REDIS_PREFIX', 'cert:'),
        'admin_user'     => fnos_env('FNOS_ADMIN_USER', 'admin'),
        'admin_pass'     => fnos_env('FNOS_ADMIN_PASS'),
        'admin_name'     => fnos_env('FNOS_ADMIN_NAME', '系统管理员'),
        'gov_key'        => fnos_env('PHP_GOV_PASSWORD_KEY'),
    ];
}

/**
 * 写入完整的 .env（数据库 / Redis / 政务网密钥）。
 */
function fnos_write_env(array $cfg): bool
{
    try {
        $env = "APP_DEBUG = false\nAPP_TRACE = false\n\n[DATABASE]\nTYPE = mysql\n";
        foreach ([
            'HOSTNAME' => 'host',
            'DATABASE' => 'db',
            'USERNAME' => 'user',
            'PASSWORD' => 'pass',
            'HOSTPORT' => 'port',
        ] as $name => $key) {
            $env .= $name . ' = ' . installEnvValue((string)$cfg[$key]) . "\n";
        }
        $env .= "CHARSET = utf8mb4\nPREFIX = \"\"\n\n[REDIS]\n";
        foreach ([
            'HOST'     => 'redis_host',
            'PORT'     => 'redis_port',
            'PASSWORD' => 'redis_password',
            'DB'       => 'redis_db',
            'PREFIX'   => 'redis_prefix',
        ] as $name => $key) {
            $env .= $name . ' = ' . installEnvValue((string)$cfg[$key]) . "\n";
        }
        $env .= "\n[GOV]\nPASSWORD_KEY = " . installEnvValue((string)$cfg['gov_key']) . "\n";
    } catch (InvalidArgumentException $e) {
        fwrite(STDERR, "[fnos] 配置值无法安全写入 .env：{$e->getMessage()}\n");
        return false;
    }

    $file = ROOT . '/.env';
    if (@file_put_contents($file, $env, LOCK_EX) !== strlen($env)) {
        fwrite(STDERR, "[fnos] 无法写入 {$file}，请检查 /data/config 挂载与权限\n");
        return false;
    }

    return true;
}

$envOnly = in_array('--env-only', $argv ?? [], true);
$cfg = fnos_config();

// ---------------- 基础校验 ----------------
if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/D', $cfg['db'])) {
    fwrite(STDERR, "[fnos] 数据库名不合法：仅支持 1-64 位字母、数字和下划线\n");
    exit(1);
}

// 飞牛版必须使用独立库：禁止系统库和主系统库名，避免误导入/覆盖现有生产数据
$reservedDatabases = ['mysql', 'information_schema', 'performance_schema', 'sys', 'cert_agent'];
if (in_array(strtolower($cfg['db']), $reservedDatabases, true)) {
    fwrite(STDERR, "[fnos] 数据库名 {$cfg['db']} 是系统库或主系统库，禁止使用；请改用独立库名（默认 cert_agent_fnos）\n");
    exit(1);
}
if ($cfg['redis_prefix'] === '' || strlen($cfg['redis_prefix']) > 128) {
    fwrite(STDERR, "[fnos] Redis 前缀不能为空且不能超过 128 字节\n");
    exit(1);
}
if ($cfg['gov_key'] === '') {
    fwrite(STDERR, "[fnos] 未设置 GOV_PASSWORD_KEY（政务网申报密码加密密钥）\n");
    exit(1);
}

if ($envOnly) {
    if (!fnos_write_env($cfg)) {
        exit(1);
    }
    echo "[fnos] 已按环境变量重建 .env\n";
    exit(0);
}

if (strlen($cfg['admin_pass']) < 8) {
    fwrite(STDERR, "[fnos] 管理员初始密码至少 8 位（ADMIN_PASS）\n");
    exit(1);
}
if ($cfg['admin_pass'] === 'admin123') {
    fwrite(STDERR, "[fnos] 禁止使用默认密码 admin123，请修改 ADMIN_PASS\n");
    exit(1);
}
if (!preg_match('/^[a-zA-Z0-9_.@-]{1,50}$/D', $cfg['admin_user'])) {
    fwrite(STDERR, "[fnos] 管理员用户名仅支持字母、数字及 _ . @ -（最多 50 位）\n");
    exit(1);
}

// ---------------- 执行安装 ----------------
echo "[fnos] 开始初始化数据库 {$cfg['db']}（主机 {$cfg['host']}:{$cfg['port']}）\n";

$result = doInstall($cfg);

foreach (($result['log'] ?? []) as $line) {
    echo "  - {$line}\n";
}

if (empty($result['ok'])) {
    fwrite(STDERR, "[fnos] 安装失败：" . (string)($result['msg'] ?? '未知错误') . "\n");
    exit(1);
}

// doInstall 已写入 .env；此处重写以补充 [GOV] 段并统一格式
if (!fnos_write_env($cfg)) {
    exit(1);
}

echo "[fnos] 安装完成\n";
echo "[fnos] 访问地址：http://<飞牛IP>:<HTTP_PORT>/setup.php 完成授权，随后使用 /web/ 登录\n";
echo "[fnos] 管理员账号：{$cfg['admin_user']}\n";
exit(0);
