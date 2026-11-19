<?php
/**
 * 证照到期管理系统 - 可视化安装向导
 * 访问方式：http://你的域名/install.php
 * 安装完成后务必确认 runtime/install.lock 已生成；本文件可保留用于后续重装，
 * 重装前需先删除 install.lock。生产环境也可在 Nginx 层额外限制本文件访问。
 */
declare(strict_types=1);

define('ROOT', dirname(__DIR__));

// ThinkPHP 使用 INI_SCANNER_RAW：保留原始引号与反斜杠，并校验解析往返，防止配置注入。
function installEnvValue(string $value): string
{
    if (preg_match('/[\x00-\x1f\x7f]/', $value) || in_array($value, ['true', 'false', 'on', 'off'], true)) {
        throw new InvalidArgumentException('配置值不能包含控制字符或使用 ThinkPHP 布尔保留值');
    }
    $encoded = '"' . $value . '"';
    $parsed = @parse_ini_string('VALUE = ' . $encoded, true, INI_SCANNER_RAW);
    if ($parsed !== ['VALUE' => $value]) {
        throw new InvalidArgumentException('配置值无法安全写入环境文件');
    }
    return $encoded;
}

// 独立探测，不启动 ThinkPHP、不读取现有 .env；仅操作随机探测键。
function installRedisProbe(array $cfg, string $driver = 'auto'): array
{
    $client = null;
    $keys = [];
    try {
        $host = $cfg['redis_host'] ?? '';
        $port = filter_var($cfg['redis_port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        $db = filter_var($cfg['redis_db'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $password = $cfg['redis_password'] ?? '';
        $prefix = $cfg['redis_prefix'] ?? '';
        if (!is_string($host) || !preg_match('/^[a-zA-Z0-9_.:\-]+$/D', $host) || $port === false || $db === false
            || !is_string($password) || !is_string($prefix) || $prefix === '' || strlen($prefix) > 128) {
            return ['ok' => false, 'msg' => 'Redis 配置不合法：请检查主机、端口、数据库编号和非空前缀（最多 128 字节）'];
        }
        installEnvValue($host);
        installEnvValue($password);
        installEnvValue($prefix);
        if ($driver === 'auto') {
            $driver = extension_loaded('redis') ? 'phpredis' : 'predis';
        }
        if ($driver === 'phpredis' && extension_loaded('redis')) {
            $client = new Redis();
            if (!$client->connect($host, $port, 2.0)) {
                throw new RuntimeException('connect');
            }
            $client->setOption(Redis::OPT_READ_TIMEOUT, 2.0);
            if ($password !== '' && !$client->auth($password)) {
                throw new RuntimeException('auth');
            }
            if (!$client->select($db)) {
                throw new RuntimeException('select');
            }
        } elseif ($driver === 'predis') {
            if (!class_exists(\Predis\Client::class) && is_file(ROOT . '/vendor/autoload.php')) {
                require_once ROOT . '/vendor/autoload.php';
            }
            if (!class_exists(\Predis\Client::class)) {
                return ['ok' => false, 'msg' => '缺少 Redis 客户端：请安装 phpredis 扩展或恢复内置 predis/predis 依赖'];
            }
            $params = ['scheme' => 'tcp', 'host' => $host, 'port' => $port, 'database' => $db, 'timeout' => 2.0, 'read_write_timeout' => 2.0];
            if ($password !== '') {
                $params['password'] = $password;
            }
            $client = new \Predis\Client($params);
            $client->connect();
        } else {
            return ['ok' => false, 'msg' => '指定的 Redis 客户端不可用'];
        }
        $key = $prefix . 'install_probe:' . bin2hex(random_bytes(16));
        $keys = [$key . ':expire', $key . ':delete'];
        $value = bin2hex(random_bytes(16));
        if (!$client->setex($keys[0], 1, $value) || $client->get($keys[0]) !== $value) {
            throw new RuntimeException('read/write');
        }
        $ttl = (int)$client->ttl($keys[0]);
        if ($ttl < 0 || $ttl > 1) {
            throw new RuntimeException('ttl');
        }
        if (!$client->setex($keys[1], 15, $value) || $client->get($keys[1]) !== $value
            || (int)$client->del($keys[1]) !== 1 || !in_array($client->get($keys[1]), [null, false], true)) {
            throw new RuntimeException('delete');
        }
        usleep(1100000);
        if (!in_array($client->get($keys[0]), [null, false], true)) {
            throw new RuntimeException('expire');
        }
        return ['ok' => true, 'msg' => '', 'driver' => $driver];
    } catch (Throwable $e) {
        // 客户端异常可能含连接 URI 或认证信息，不回显原始异常。
        return ['ok' => false, 'msg' => 'Redis 检测失败：请检查服务、认证、DB 与 GET/SETEX/TTL/DEL 权限；必须通过读写、实际过期和删除检测后才能安装'];
    } finally {
        if ($client !== null) {
            foreach ($keys as $key) {
                try { $client->del($key); } catch (Throwable $e) { /* 失败探测键由短 TTL 自动清理。 */ }
            }
            try {
                if ($driver === 'phpredis') { $client->close(); } else { $client->disconnect(); }
            } catch (Throwable $e) { /* 关闭连接不影响探测结论。 */ }
        }
    }
}

// ---------------- 环境检查 ----------------
function checkEnv(): array
{
    $items = [];
    $items[] = [
        'name' => 'PHP 版本 >= 8.1',
        'ok'   => version_compare(PHP_VERSION, '8.1.0', '>='),
        'cur'  => PHP_VERSION,
    ];
    foreach (['pdo_mysql' => 'PDO MySQL', 'mbstring' => 'Mbstring', 'json' => 'JSON', 'openssl' => 'OpenSSL'] as $ext => $label) {
        $items[] = ['name' => $label . ' 扩展', 'ok' => extension_loaded($ext), 'cur' => extension_loaded($ext) ? '已加载' : '缺失'];
    }
    $items[] = ['name' => 'GD 扩展（登录验证码）', 'ok' => extension_loaded('gd'), 'cur' => extension_loaded('gd') ? '已加载' : '缺失（验证码不可用）'];
    $items[] = ['name' => 'cURL 扩展（推送用）', 'ok' => extension_loaded('curl'), 'cur' => extension_loaded('curl') ? '已加载' : '缺失（可选）'];

    // 后端依赖已随仓库分发，无需 composer install
    $vendor = ROOT . '/vendor/autoload.php';
    $items[] = [
        'name' => '后端依赖 vendor（已内置，免 composer）',
        'ok'   => is_file($vendor),
        'cur'  => is_file($vendor) ? '存在' : '缺失：请执行 composer install --no-dev',
    ];

    $redisClient = extension_loaded('redis') ? 'phpredis' : '';
    if ($redisClient === '' && is_file($vendor)) {
        try {
            require_once $vendor;
            if (class_exists(\Predis\Client::class)) { $redisClient = 'Predis'; }
        } catch (Throwable $e) { /* 依赖损坏按未通过处理。 */ }
    }
    $items[] = ['name' => 'Redis 客户端（必需，phpredis 或 Predis）', 'ok' => $redisClient !== '', 'cur' => $redisClient ?: '缺失：请恢复内置 Predis 或安装 phpredis'];

    // 验证码字体（内置 DejaVu，避免生产环境找不到系统字体）
    $fontOk = false;
    $fontCur = '缺失';
    foreach (['DejaVuSans-Bold.ttf', 'DejaVuSans.ttf'] as $f) {
        $p = ROOT . '/resource/font/' . $f;
        if (is_file($p) && is_readable($p)) {
            $fontOk  = true;
            $fontCur = '内置 ' . $f;
            break;
        }
    }
    if (!$fontOk && extension_loaded('gd')) {
        $fontCur = '缺失（将降级为 GD 点阵字体，可登录但不美观）';
    }
    $items[] = ['name' => '验证码字体（内置 DejaVu）', 'ok' => $fontOk, 'cur' => $fontCur];

    $dirs = [
        'runtime'            => ROOT . '/runtime',
        'runtime/cache'      => ROOT . '/runtime/cache',
        'runtime/log'        => ROOT . '/runtime/log',
        'runtime/temp'       => ROOT . '/runtime/temp',
        'public/uploads'     => ROOT . '/public/uploads',
        'backend 根目录可写'  => ROOT,
    ];
    foreach ($dirs as $label => $dir) {
        $ok = is_dir($dir) ? is_writable($dir) : @mkdir($dir, 0755, true);
        $items[] = ['name' => '目录可写：' . $label, 'ok' => $ok, 'cur' => $ok ? '可写' : '不可写'];
    }
    $items[] = ['name' => '建库脚本 init.sql', 'ok' => is_file(ROOT . '/database/init.sql'), 'cur' => is_file(ROOT . '/database/init.sql') ? '存在' : '缺失'];
    $items[] = ['name' => '初始化数据 init_data.sql', 'ok' => is_file(ROOT . '/database/init_data.sql'), 'cur' => is_file(ROOT . '/database/init_data.sql') ? '存在' : '缺失'];
    return $items;
}

// ---------------- SQL 拆分（兼容注释与字符串内的分号） ----------------
function splitSql(string $sql): array
{
    $out = [];
    $buf = '';
    $len = strlen($sql);
    $inStr = false;
    $strChar = '';
    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        $n = $i + 1 < $len ? $sql[$i + 1] : '';
        if ($inStr) {
            $buf .= $c;
            if ($c === '\\') { $buf .= $n; $i++; continue; }
            if ($c === $strChar) { $inStr = false; }
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') { $inStr = true; $strChar = $c; $buf .= $c; continue; }
        if ($c === '-' && $n === '-') { // 行注释
            while ($i < $len && $sql[$i] !== "\n") { $i++; }
            continue;
        }
        if ($c === '#') {
            while ($i < $len && $sql[$i] !== "\n") { $i++; }
            continue;
        }
        if ($c === '/' && $n === '*') {
            $i += 2;
            while ($i < $len && !($sql[$i] === '*' && ($i + 1 < $len && $sql[$i + 1] === '/'))) { $i++; }
            $i++;
            continue;
        }
        if ($c === ';') {
            $buf = trim($buf);
            if ($buf !== '') { $out[] = $buf; }
            $buf = '';
            continue;
        }
        $buf .= $c;
    }
    $buf = trim($buf);
    if ($buf !== '') { $out[] = $buf; }
    return $out;
}

// ---------------- 执行安装 ----------------
function doInstall(array $cfg): array
{
    $sqlInit = ROOT . '/database/init.sql';
    $sqlData = ROOT . '/database/init_data.sql';
    $envFile = ROOT . '/.env';
    $lockFile = ROOT . '/runtime/install.lock';
    $log = [];
    if (is_file($lockFile)) {
        return ['ok' => false, 'msg' => '系统已安装，请勿重复安装', 'log' => $log];
    }
    $dbName = $cfg['db'];
    if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/D', $dbName)) {
        return ['ok' => false, 'msg' => '数据库名仅支持 1-64 位字母、数字和下划线', 'log' => $log];
    }
    try {
        $env = "APP_DEBUG = false\nAPP_TRACE = false\n\n[DATABASE]\nTYPE = mysql\n";
        foreach (['HOSTNAME' => 'host', 'DATABASE' => 'db', 'USERNAME' => 'user', 'PASSWORD' => 'pass', 'HOSTPORT' => 'port'] as $name => $key) {
            $env .= $name . ' = ' . installEnvValue((string)$cfg[$key]) . "\n";
        }
        $env .= "CHARSET = utf8mb4\nPREFIX = \"\"\n\n[REDIS]\n";
        foreach (['HOST' => 'redis_host', 'PORT' => 'redis_port', 'PASSWORD' => 'redis_password', 'DB' => 'redis_db', 'PREFIX' => 'redis_prefix'] as $name => $key) {
            $env .= $name . ' = ' . installEnvValue((string)$cfg[$key]) . "\n";
        }
    } catch (InvalidArgumentException $e) {
        return ['ok' => false, 'msg' => '配置含有无法安全写入环境文件的字符', 'log' => $log];
    }
    $redis = installRedisProbe($cfg);
    if (!$redis['ok']) {
        return ['ok' => false, 'msg' => $redis['msg'], 'log' => $log];
    }
    $log[] = 'Redis 读写、实际过期和删除检测通过（' . $redis['driver'] . '）';
    try {
        $dsn = 'mysql:host=' . $cfg['host'] . ';port=' . $cfg['port'] . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
        ]);
    } catch (PDOException $e) {
        return ['ok' => false, 'msg' => '数据库连接失败，请检查服务、账号和权限', 'log' => $log];
    }
    $log[] = '数据库连接成功';

    // 建库
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$dbName}`");
    $log[] = "数据库 `{$dbName}` 已就绪";

    // 导入结构
    foreach ([$sqlInit, $sqlData] as $file) {
        if (!is_file($file)) {
            return ['ok' => false, 'msg' => '缺少文件：' . basename($file), 'log' => $log];
        }
        $statements = splitSql(file_get_contents($file));
        $count = 0;
        foreach ($statements as $s) {
            // 跳过纯注释
            if ($s === '' || strpos($s, '--') === 0) { continue; }
            try {
                $pdo->exec($s);
                $count++;
            } catch (PDOException $e) {
                // 仅允许重试安装时的重复表/记录；其他 SQL 失败禁止生成安装锁。
                if (!in_array((int)($e->errorInfo[1] ?? 0), [1050, 1062], true)) {
                    // v2.99.1：回显 PDO 原始错误（含 SQLSTATE 与具体原因），便于定位 SQL 兼容/权限问题
                    $detail = trim((string)$e->getMessage());
                    if ($detail !== '') {
                        $detail = '（' . $detail . '）';
                    }
                    return ['ok' => false, 'msg' => '数据库初始化失败，请检查 SQL 与数据库权限（错误码 ' . $e->getCode() . '）' . $detail, 'log' => $log];
                }
                $log[] = '跳过已存在的表或记录';
            }
        }
        $log[] = basename($file) . " 执行完成（{$count} 条语句）";
    }

    // 管理员账号
    $hash = password_hash($cfg['admin_pass'], PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("SELECT id FROM sys_user WHERE username = ?");
    $stmt->execute([$cfg['admin_user']]);
    if ($stmt->fetchColumn()) {
        $pdo->prepare("UPDATE sys_user SET password = ?, real_name = ?, role_id = 1, status = 1 WHERE username = ?")
            ->execute([$hash, $cfg['admin_name'], $cfg['admin_user']]);
        $log[] = '管理员账号已更新';
    } else {
        $pdo->prepare("INSERT INTO sys_user (username, password, real_name, role_id, status) VALUES (?, ?, ?, 1, 1)")
            ->execute([$cfg['admin_user'], $hash, $cfg['admin_name']]);
        $log[] = '管理员账号已创建';
    }

    // 写 .env
    if (@file_put_contents($envFile, $env, LOCK_EX) !== strlen($env)) {
        return ['ok' => false, 'msg' => '无法写入 .env，请手动创建：' . $envFile, 'log' => $log];
    }
    $log[] = '.env 配置已生成';

    // 锁文件
    if (!is_dir(dirname($lockFile))) { @mkdir(dirname($lockFile), 0755, true); }
    if (@file_put_contents($lockFile, date('Y-m-d H:i:s'), LOCK_EX) === false) {
        return ['ok' => false, 'msg' => '无法写入安装锁，请检查 runtime 目录权限', 'log' => $log];
    }
    $log[] = '安装完成';

    return ['ok' => true, 'msg' => '', 'log' => $log];
}

function installCsrfValid($token, $expected): bool
{
    return is_string($token) && is_string($expected) && $expected !== '' && hash_equals($expected, $token);
}

// CLI require 时只加载独立函数，不开启会话、不渲染页面或执行安装。
if (PHP_SAPI === 'cli') {
    return;
}

session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Strict', 'use_strict_mode' => true,
    'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
header('Cache-Control: no-store');
$_SESSION['install_csrf'] ??= bin2hex(random_bytes(32));
$lockFile = ROOT . '/runtime/install.lock';
$step = (int)($_GET['step'] ?? 0);
if (is_file($lockFile)) {
    $step = $step === 3 ? 3 : 99;
} elseif ($step === 3) {
    $step = 2;
}

$error = '';
$installLog = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 2) {
    if (!installCsrfValid($_POST['csrf_token'] ?? null, $_SESSION['install_csrf'])) {
        http_response_code(403);
        $error = '安装请求校验失败，请刷新页面后重试';
    } elseif (count(array_filter($_POST, 'is_array')) > 0) {
        $error = '配置参数格式不正确';
        $_POST = [];
    } else {
        $cfg = [
            'host'       => trim($_POST['host'] ?? '127.0.0.1'),
            'port'       => $_POST['port'] ?? '3306',
            'db'         => trim($_POST['db'] ?? 'cert_agent'),
            'user'       => trim($_POST['user'] ?? 'root'),
            'pass'       => $_POST['pass'] ?? '',
            'redis_host' => trim($_POST['redis_host'] ?? ''),
            'redis_port' => $_POST['redis_port'] ?? '',
            'redis_password' => $_POST['redis_password'] ?? '',
            'redis_db'   => $_POST['redis_db'] ?? '',
            'redis_prefix' => $_POST['redis_prefix'] ?? '',
            'admin_user' => trim($_POST['admin_user'] ?? 'admin'),
            'admin_name' => trim($_POST['admin_name'] ?? '系统管理员'),
            'admin_pass' => $_POST['admin_pass'] ?? '',
        ];
        if (!preg_match('/^[a-zA-Z0-9_.:\-]+$/D', $cfg['host'])
            || filter_var($cfg['port'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) {
            $error = '数据库主机或端口不合法';
        } elseif (!preg_match('/^(?=.*[A-Za-z])(?=.*\d).{8,32}$/D', $cfg['admin_pass'])) {
            $error = '管理员密码需 8-32 位且包含字母和数字';
        } else {
            try {
                $res = doInstall($cfg);
                $installLog = $res['log'];
                if ($res['ok']) {
                    unset($_SESSION['install_csrf']);
                    header('Location: install.php?step=3');
                    exit;
                }
                $error = $res['msg'];
            } catch (Throwable $e) {
                $error = '安装失败，请检查数据库权限及配置文件目录写入权限；未生成安装锁';
            }
        }
    }
}

function h($s) { return htmlspecialchars(is_scalar($s) ? (string)$s : '', ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>安装向导 - 证照到期管理系统</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:-apple-system,"Microsoft YaHei",Arial,sans-serif;background:#F5F6F8;color:#1f2329}
.wrap{max-width:760px;margin:40px auto;padding:0 16px}
.card{background:#fff;border-radius:12px;padding:28px;box-shadow:0 2px 12px rgba(0,0,0,.06);margin-bottom:20px}
h1{font-size:22px;margin:0 0 4px}
.sub{color:#8A8F99;font-size:13px;margin-bottom:20px}
.steps{display:flex;gap:8px;margin-bottom:24px}
.steps div{flex:1;text-align:center;font-size:12px;color:#8A8F99;padding:8px 0;border-bottom:2px solid #eee}
.steps div.on{color:#007AFF;border-color:#007AFF;font-weight:600}
.steps div.done{color:#34C759;border-color:#34C759}
table{width:100%;border-collapse:collapse;font-size:14px}
td,th{padding:10px 8px;border-bottom:1px solid #f0f0f0;text-align:left}
.ok{color:#34C759;font-weight:600}.bad{color:#FF3B30;font-weight:600}
label{display:block;font-size:13px;color:#555;margin:14px 0 6px}
input{width:100%;height:40px;border:1px solid #dcdfe6;border-radius:6px;padding:0 12px;font-size:14px}
.row2{display:flex;gap:12px}.row2>div{flex:1}
button{margin-top:22px;width:100%;height:44px;background:#007AFF;color:#fff;border:0;border-radius:6px;font-size:16px;cursor:pointer}
button:active{opacity:.85}
.err{background:#FFF1F0;color:#FF3B30;padding:10px 14px;border-radius:6px;font-size:14px;margin-bottom:14px}
.log{background:#fafafa;border-radius:6px;padding:14px;font-size:13px;color:#555;line-height:1.9}
.tip{font-size:13px;color:#8A8F99;margin-top:14px;line-height:1.8}
code{background:#f5f5f5;padding:2px 6px;border-radius:4px}
a{color:#007AFF}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>证照到期管理系统 · 安装向导</h1>
    <div class="sub">中介版 CertAgent —— ThinkPHP 6 + Vue 3</div>
    <div class="steps">
      <div class="<?php echo $step >= 1 ? 'done' : 'on'; ?>">1 环境检测</div>
      <div class="<?php echo $step == 2 ? 'on' : ($step > 2 ? 'done' : ''); ?>">2 配置与安装</div>
      <div class="<?php echo $step >= 3 ? 'done' : ''; ?>">3 完成</div>
    </div>

<?php if ($step === 99): ?>
    <div class="err">系统已安装完成（检测到 install.lock）。如需重新安装，请先删除 <code>runtime/install.lock</code>。</div>
    <p class="tip">管理后台：<a href="./web/">进入 Web 管理端</a>　|　<a href="./mobile/">移动端 H5</a></p>

<?php elseif ($step <= 1): ?>
    <?php $items = checkEnv(); $pass = true; foreach ($items as $it) { if (!$it['ok'] && strpos($it['name'], '可选') === false && strpos($it['name'], '验证码') === false && strpos($it['name'], '推送用') === false) { $pass = false; } } ?>
    <table>
      <tr><th>检查项</th><th>当前值</th><th>结果</th></tr>
      <?php foreach ($items as $it): ?>
      <tr>
        <td><?php echo h($it['name']); ?></td>
        <td><?php echo h($it['cur']); ?></td>
        <td class="<?php echo $it['ok'] ? 'ok' : 'bad'; ?>"><?php echo $it['ok'] ? '通过' : '不通过'; ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php if (!$pass): ?>
      <div class="err">存在必须通过的检查项未通过，请先处理后再继续。</div>
    <?php endif; ?>
    <form method="get">
      <input type="hidden" name="step" value="2">
      <button type="submit" <?php echo $pass ? '' : 'disabled style="background:#c0c4cc"'; ?>>下一步：配置数据库与 Redis</button>
    </form>

<?php elseif ($step === 2): ?>
    <?php if ($error): ?><div class="err"><?php echo h($error); ?></div><?php endif; ?>
    <?php if ($installLog): ?><div class="log"><?php foreach ($installLog as $l) { echo h($l) . '<br>'; } ?></div><?php endif; ?>
    <form method="post" action="install.php?step=2">
      <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['install_csrf']); ?>">
      <label>数据库主机</label>
      <input name="host" value="<?php echo h($_POST['host'] ?? '127.0.0.1'); ?>" required>
      <div class="row2">
        <div><label>端口</label><input name="port" value="<?php echo h($_POST['port'] ?? '3306'); ?>" required></div>
        <div><label>数据库名</label><input name="db" value="<?php echo h($_POST['db'] ?? 'cert_agent'); ?>" required></div>
      </div>
      <div class="row2">
        <div><label>数据库用户名</label><input name="user" value="<?php echo h($_POST['user'] ?? 'root'); ?>" required></div>
        <div><label>数据库密码（失败后需重新输入）</label><input name="pass" type="password" autocomplete="new-password"></div>
      </div>
      <hr style="border:0;border-top:1px solid #f0f0f0;margin:22px 0">
      <div class="row2">
        <div><label>Redis 主机（必需）</label><input name="redis_host" value="<?php echo h($_POST['redis_host'] ?? '127.0.0.1'); ?>" required></div>
        <div><label>Redis 端口</label><input name="redis_port" type="number" min="1" max="65535" value="<?php echo h($_POST['redis_port'] ?? '6379'); ?>" required></div>
      </div>
      <label>Redis 密码（无密码可留空，失败后需重新输入）</label>
      <input name="redis_password" type="password" autocomplete="new-password">
      <div class="row2">
        <div><label>Redis 数据库编号（DB）</label><input name="redis_db" type="number" min="0" value="<?php echo h($_POST['redis_db'] ?? '0'); ?>" required></div>
        <div><label>Redis 键前缀（不同部署请区分）</label><input name="redis_prefix" maxlength="128" value="<?php echo h($_POST['redis_prefix'] ?? 'cert:'); ?>" required></div>
      </div>
      <p class="tip">安装前必须通过 Redis 读写、实际过期与删除探测；不可用时不建库、不写配置和安装锁，不降级文件缓存。</p>
      <hr style="border:0;border-top:1px solid #f0f0f0;margin:22px 0">
      <div class="row2">
        <div><label>管理员账号</label><input name="admin_user" value="<?php echo h($_POST['admin_user'] ?? 'admin'); ?>" required></div>
        <div><label>管理员姓名</label><input name="admin_name" value="<?php echo h($_POST['admin_name'] ?? '系统管理员'); ?>"></div>
      </div>
      <label>管理员密码（8-32 位，需含字母和数字）</label>
      <input name="admin_pass" type="password" required>
      <button type="submit">开始安装</button>
    </form>
    <p class="tip">数据库不存在时会自动创建。安装会导入建表脚本与演示数据（含示例企业、人员、证书及借调记录）。</p>

<?php elseif ($step === 3): ?>
    <div class="log">安装成功！</div>
    <p class="tip">
      · Web 管理端：<a href="./web/" target="_blank">/web/</a><br>
      · 移动端 H5：<a href="./mobile/" target="_blank">/mobile/</a><br>
      · 默认管理员账号即安装时填写的账号<br>
      · 已生成 <code>runtime/install.lock</code>，安装器会自动锁定，无法被重复执行。<br>
      · 本文件可保留用于后续重装；重装前先删除 <code>runtime/install.lock</code>。生产环境建议在 Nginx 层限制 <code>install.php</code> 仅内网访问。
    </p>
<?php endif; ?>
  </div>
</div>
</body>
</html>
