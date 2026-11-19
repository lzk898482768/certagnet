<?php
/**
 * 授权系统自助入口（独立于业务系统安装向导 install.php，二者互不调用、互不修改）。
 *
 * 职责：
 *  - 环境自检（宝塔部署友好，给出中文原因与可照做的修复建议）；
 *  - 配置授权端公钥（verifier.pem）并完成安装；
 *  - 粘贴 / 更换凭证（只做结构 + 验签，不做域名比对）；
 *  - 配置被控端身份、手动触发心跳。
 *
 * 本文件不依赖框架、不读业务数据表、不调用业务接口、不要求业务登录态、
 * 不改写任何业务源码。锁死态也能完整渲染，不白屏、不自跳转循环。
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/* ---------------- 分发包内置公钥（与授权端签发私钥配对） ----------------
 * 授权端使用自己的密钥时，客户可在本页粘贴授权端公钥覆盖它。 */
function embedded_public_key(): string
{
    return <<<PEM
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAtpOYL8DpR1Vmf9gxAEFn
CuyCyk4qCsSbKsyfVcFAMjv2AnjMSebZ+R7JIq6v0NMfz/BJteHcbdKBqhr3vxrH
+sXe8Le3kzqdPvAgvsWnbmG6ws+1sET/qLceZY3k09sRUP9+zioWjmqESg0gILsj
frG/lj/RfDrT51WK8hX3PS1dVrphOtmVhmpHQ6xzLVN3wC1rQw/7J0enddvw3/sq
fHq0u28l6MUj9DmVgaQSKwDqri3lE0SPBDxGN4s/HUPPlBC8WJldsPDIhGBHhDSQ
fi45ak7qrGslOmwiTzZ2ANM/hfK4lTzOBdNwvakLExlQonXc9rhcT4cZeqcTuQSm
lwIDAQAB
-----END PUBLIC KEY-----
PEM;
}

const CRED_FIELDS = ['version', 'serial', 'customer', 'domain', 'issued_at', 'expires_at'];

$vaultDir       = dirname(__DIR__) . '/runtime/vault';
$verifierPath   = $vaultDir . '/verifier.pem';
$credentialPath = $vaultDir . '/credential.dat';
$installPath    = $vaultDir . '/install.json';
$setupTokenPath = $vaultDir . '/setup.token';

$installed = is_file($verifierPath);

/* ---------------- 授权管理令牌自愈（v2.99.1） ----------------
 * 令牌原本只在 setup.php 的「安装」动作成功时生成。但实际部署多由
 * install.php（业务安装向导）完成，该流程不经过本页，于是已安装实例
 * 往往没有 setup.token，远程维护时会被判为「未初始化」而无法操作。
 *
 * 这里改成：只要判定为已安装且令牌缺失，就在服务端补生成一次，
 * 保证远程维护有据可依。令牌仍只写磁盘、不在页面回显。
 */
if ($installed && !is_file($setupTokenPath)) {
    ensure_setup_token($vaultDir);
}

/* ---------------- 敏感动作鉴权（v2.99.1 安全加固） ----------------
 * 背景：本页按「自助授权入口」设计，此前 save_public_key / activate /
 * endpoint_configure 完全无鉴权。任何人只要能访问本 URL，就能把我方公钥
 * 换成自己的、再粘贴自签凭证，从而彻底绕过授权校验（已在测试中实证）。
 *
 * 现在的规则：
 *  - 未安装（无 verifier.pem）时：允许安装，符合首次部署流程；
 *  - 已安装时：仅允许「服务器本机」（REMOTE_ADDR 为回环地址，且未经代理转发）
 *    直接操作，其余来源必须提交 runtime/vault/setup.token 中的一次性令牌；
 *  - 令牌在首次安装成功时随机生成（32 位十六进制，0600），只落在服务器磁盘上，
 *    不出现在页面渲染结果里，远程访问者拿不到。
 *
 * 注意：仅当确实是直连本机时才放行。若存在 X-Forwarded-For / X-Real-IP，
 * 说明前面有反向代理，REMOTE_ADDR 恒为 127.0.0.1，此时一律按远程处理。
 */
function request_is_loopback(): bool
{
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR']) || !empty($_SERVER['HTTP_X_REAL_IP'])
        || !empty($_SERVER['HTTP_FORWARDED'])) {
        return false;
    }
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($ip === '') {
        return false;
    }
    return $ip === '::1' || str_starts_with($ip, '127.');
}

/**
 * 敏感动作是否已通过鉴权。
 * @return array{ok:bool,msg:string}
 */
function setup_authorized(string $vaultDir, string $action, bool $installed): array
{
    // 未安装阶段：允许完成首次安装（否则新部署无法自助授权）
    if (!$installed) {
        return ['ok' => true, 'msg' => ''];
    }
    // 服务器本机直连：允许（运维在服务器上操作，等价于已持有服务器访问权）
    if (request_is_loopback()) {
        return ['ok' => true, 'msg' => ''];
    }

    $expect = @file_get_contents($vaultDir . '/setup.token');
    $expect = is_string($expect) ? trim($expect) : '';
    if ($expect === '') {
        return [
            'ok'  => false,
            'msg' => '本机尚未生成授权管理令牌，请先在服务器上访问本页完成初始化，'
                   . '或删除 runtime/vault/verifier.pem 后重新执行安装流程',
        ];
    }
    $given = trim((string)($_POST['admin_token'] ?? ''));
    if ($given === '') {
        return [
            'ok'  => false,
            'msg' => '该操作需要在服务器本机执行，或填写「授权管理令牌」'
                   . '（见服务器 runtime/vault/setup.token）。',
        ];
    }
    if (!hash_equals($expect, $given)) {
        return ['ok' => false, 'msg' => '授权管理令牌不正确。'];
    }
    return ['ok' => true, 'msg' => ''];
}

/** 安装成功时生成授权管理令牌（已存在则保留，避免换凭证后令牌变化） */
function ensure_setup_token(string $vaultDir): string
{
    $path = rtrim($vaultDir, '/\\') . '/setup.token';
    $current = @file_get_contents($path);
    if (is_string($current) && trim($current) !== '') {
        return trim($current);
    }
    if (!is_dir($vaultDir)) {
        @mkdir($vaultDir, 0755, true);
    }
    $token = bin2hex(random_bytes(16));
    @file_put_contents($path, $token . "\n", LOCK_EX);
    @chmod($path, 0600);
    return $token;
}

/** 记录一次被拒绝的敏感操作，便于事后审计（失败不影响主流程） */
function log_setup_denied(string $vaultDir, string $action, string $reason): void
{
    // REMOTE_ADDR 在反向代理后恒为 127.0.0.1，必须同时记录转发头，
    // 否则审计日志无法定位真实来源。
    $forwarded = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($forwarded === '') {
        $forwarded = trim((string)($_SERVER['HTTP_X_REAL_IP'] ?? ''));
    }
    $line = implode("\t", [
        gmdate('Y-m-d H:i:s') . 'Z',
        (string)($_SERVER['REMOTE_ADDR'] ?? '-'),
        $forwarded !== '' ? $forwarded : '-',
        $action,
        'DENIED',
        $reason,
    ]) . "\n";
    @file_put_contents(rtrim($vaultDir, '/\\') . '/setup_access.log', $line, FILE_APPEND | LOCK_EX);
}

/* 被控端在线联动：复用业务侧 EndpointLink（本页独立于框架，直接 require） */
if (!class_exists('app\\support\\EndpointLink')) {
    $endpointLinkFile = dirname(__DIR__) . '/app/support/EndpointLink.php';
    if (is_file($endpointLinkFile)) {
        require_once $endpointLinkFile;
    }
}

/* ---------------- POST 动作（返回 JSON） ---------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    $action = (string)($_POST['action'] ?? '');

    // 敏感动作（改公钥 / 改凭证 / 改被控端）必须鉴权，见 setup_authorized()
    $sensitive = ['save_public_key', 'activate', 'endpoint_configure', 'endpoint_sync', 'endpoint_test'];
    if (in_array($action, $sensitive, true)) {
        $auth = setup_authorized($vaultDir, $action, $installed);
        if (!$auth['ok']) {
            log_setup_denied($vaultDir, $action, $auth['msg']);
            http_response_code(403);
            echo json_encode([
                'ok'   => false,
                'code' => 'SETUP_UNAUTHORIZED',
                'msg'  => $auth['msg'],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    try {
        if ($action === 'install') {
            echo json_encode(handle_install($vaultDir, $verifierPath, $installPath, $installed), JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($action === 'inspect_public_key') {
            echo json_encode(handle_inspect_public_key(), JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($action === 'save_public_key') {
            echo json_encode(handle_save_public_key($vaultDir, $verifierPath, $credentialPath, $installed), JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($action === 'activate') {
            echo json_encode(handle_activate($vaultDir, $verifierPath, $credentialPath, $installed), JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($action === 'endpoint_configure') {
            echo json_encode(handle_endpoint_configure($installed), JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($action === 'endpoint_sync') {
            echo json_encode(handle_endpoint_sync($installed), JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($action === 'endpoint_test') {
            echo json_encode(handle_endpoint_test($installed), JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode(['ok' => false, 'code' => 'ACTIVATE_REJECTED', 'msg' => '未知操作'], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'code' => 'ACTIVATE_REJECTED', 'msg' => '处理失败：' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/* ---------------- GET：渲染页面 ---------------- */
$selfTest  = run_self_test($vaultDir, $verifierPath);
$install   = read_json($installPath);
$current   = inspect_credential($verifierPath, $credentialPath);
$flashCode = isset($_GET['code']) ? (string)$_GET['code'] : '';
$currentPub = '';
if (is_file($verifierPath)) {
    $currentPub = (string)@file_get_contents($verifierPath);
}

render_page($installed, $selfTest, $install, $current, $flashCode, $currentPub);

/* ====================================================================== *
 * 动作处理
 * ====================================================================== */

function handle_install(string $vaultDir, string $verifierPath, string $installPath, bool $installed): array
{
    if ($installed) {
        return ['ok' => false, 'code' => 'INSTALL_LOCKED', 'msg' => message_for('INSTALL_LOCKED')];
    }
    $checks = run_self_test($vaultDir, $verifierPath);
    foreach ($checks as $c) {
        if (!$c['ok']) {
            return ['ok' => false, 'code' => 'INSTALL_LOCKED', 'msg' => '环境自检未通过：' . $c['title'] . ' — ' . $c['advice']];
        }
    }
    if (!mkdir_deep($vaultDir)) {
        return ['ok' => false, 'code' => 'INSTALL_LOCKED', 'msg' => '无法创建授权目录，请检查 runtime 写权限'];
    }

    $pem = trim((string)($_POST['public_key'] ?? ''));
    if ($pem === '') {
        $pem = embedded_public_key();
    }
    $checked = check_public_key($pem);
    if (!$checked['ok']) {
        return ['ok' => false, 'code' => 'INVALID_PUBLIC_KEY', 'msg' => $checked['msg']];
    }

    if (file_put_contents($verifierPath, $checked['pem']) === false) {
        return ['ok' => false, 'code' => 'INSTALL_LOCKED', 'msg' => '公钥写出失败，请检查目录权限'];
    }
    @chmod($verifierPath, 0644);

    $domain = current_domain();
    $install = [
        'domain'       => $domain,
        'installed_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'public_key_fingerprint' => $checked['fingerprint'],
    ];
    if (file_put_contents($installPath, json_encode($install, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) {
        return ['ok' => false, 'code' => 'INSTALL_LOCKED', 'msg' => '安装记录写出失败，请检查目录权限'];
    }
    @chmod($installPath, 0644);

    // v2.99.1：生成授权管理令牌，供远程（非本机）维护时鉴权使用。
    // 令牌只写入服务器磁盘，不在页面回显，避免任何访问者拿到。
    ensure_setup_token($vaultDir);

    return [
        'ok'          => true,
        'code'        => 'INSTALL_OK',
        'msg'         => message_for('INSTALL_OK'),
        'fingerprint' => $checked['fingerprint'],
        'install'     => $install,
    ];
}

/** 只校验公钥格式并返回指纹，不写文件。 */
function handle_inspect_public_key(): array
{
    $pem = trim((string)($_POST['public_key'] ?? ''));
    if ($pem === '') {
        return ['ok' => false, 'code' => 'INVALID_PUBLIC_KEY', 'msg' => '请先粘贴公钥（-----BEGIN PUBLIC KEY----- 开头）'];
    }
    $checked = check_public_key($pem);
    if (!$checked['ok']) {
        return ['ok' => false, 'code' => 'INVALID_PUBLIC_KEY', 'msg' => $checked['msg']];
    }
    return [
        'ok'          => true,
        'code'        => 'PUBLIC_KEY_OK',
        'msg'         => '公钥格式正确，指纹见下方；请与授权端「密钥与配对」页的公钥指纹比对',
        'fingerprint' => $checked['fingerprint'],
    ];
}

/** 保存 / 更换公钥，并回显当前凭证在新公钥下的验签结果。 */
function handle_save_public_key(string $vaultDir, string $verifierPath, string $credentialPath, bool $installed): array
{
    if (!$installed) {
        return ['ok' => false, 'code' => 'NOT_INSTALLED', 'msg' => '请先完成安装（保存公钥即完成安装）'];
    }

    $restore = !empty($_POST['restore']);
    $pem = $restore ? embedded_public_key() : trim((string)($_POST['public_key'] ?? ''));
    if ($pem === '') {
        return ['ok' => false, 'code' => 'INVALID_PUBLIC_KEY', 'msg' => '请先粘贴公钥'];
    }
    $checked = check_public_key($pem);
    if (!$checked['ok']) {
        return ['ok' => false, 'code' => 'INVALID_PUBLIC_KEY', 'msg' => $checked['msg']];
    }
    if (!is_dir($vaultDir) && !mkdir_deep($vaultDir)) {
        return ['ok' => false, 'code' => 'PUBLIC_KEY_WRITE_ERROR', 'msg' => '授权目录不可写，请检查 runtime 权限'];
    }

    $oldFp = public_key_fingerprint((string)@file_get_contents($verifierPath));
    if (file_put_contents($verifierPath, $checked['pem']) === false) {
        return ['ok' => false, 'code' => 'PUBLIC_KEY_WRITE_ERROR', 'msg' => '公钥写出失败，请检查目录权限'];
    }
    @chmod($verifierPath, 0644);

    public_key_history($vaultDir, $oldFp, $checked['fingerprint'], $restore ? 'restore-embedded' : 'paste');

    $out = [
        'ok'          => true,
        'code'        => 'PUBLIC_KEY_SAVED',
        'msg'         => '公钥已保存',
        'fingerprint' => $checked['fingerprint'],
        'restored'    => $restore,
        'checked_at'  => gmdate('Y-m-d H:i:s') . ' UTC',
    ];

    if (is_file($credentialPath)) {
        $verify = verify_credential($verifierPath, (string)@file_get_contents($credentialPath));
        $out['credential'] = [
            'ok'   => $verify['ok'],
            'code' => $verify['code'],
            'msg'  => $verify['ok'] ? '现有凭证与新公钥匹配' : message_for($verify['code']),
        ];
        $out['msg'] = $verify['ok']
            ? '公钥已保存，现有凭证校验通过'
            : '公钥已保存，但现有凭证未通过校验：' . message_for($verify['code']);
    }

    return $out;
}

function handle_activate(string $vaultDir, string $verifierPath, string $credentialPath, bool $installed): array
{
    if (!$installed || !is_file($verifierPath)) {
        return ['ok' => false, 'code' => 'NOT_INSTALLED', 'msg' => message_for('NOT_INSTALLED')];
    }
    $text = (string)($_POST['credential'] ?? '');
    if (trim($text) === '') {
        return ['ok' => false, 'code' => 'ACTIVATE_REJECTED', 'detail' => 'LICENSE_MALFORMED', 'msg' => message_for('LICENSE_MALFORMED')];
    }
    // 只做结构 + 验签，不做域名比对（允许跨域名合法凭证覆盖用于域名迁移）
    $check = verify_credential($verifierPath, $text);
    if (!$check['ok']) {
        return ['ok' => false, 'code' => 'ACTIVATE_REJECTED', 'detail' => $check['code'], 'msg' => message_for($check['code'])];
    }
    if (!is_dir($vaultDir) && !mkdir_deep($vaultDir)) {
        return ['ok' => false, 'code' => 'ACTIVATE_REJECTED', 'msg' => '凭证异常，请检查目录权限'];
    }
    // 唯一写操作：原文落盘（UTF-8 无 BOM，644）
    $written = file_put_contents($credentialPath, $text);
    if ($written === false) {
        return ['ok' => false, 'code' => 'ACTIVATE_REJECTED', 'msg' => '凭证异常，请检查目录权限'];
    }
    @chmod($credentialPath, 0644);
    return ['ok' => true, 'code' => 'ACTIVATE_OK', 'msg' => message_for('ACTIVATE_OK')];
}

/**
 * 保存 / 更换被控端身份（管理端地址 + 编号 + 密钥）。
 * 仅写入 runtime/vault/endpoint.json，不改动凭证文件。
 */
function handle_endpoint_configure(bool $installed): array
{
    if (!$installed) {
        return ['ok' => false, 'code' => 'NOT_INSTALLED', 'msg' => message_for('NOT_INSTALLED')];
    }
    if (!class_exists('app\\support\\EndpointLink')) {
        return ['ok' => false, 'code' => 'ENDPOINT_INVALID', 'msg' => '被控端组件缺失，请检查 app/support/EndpointLink.php 是否存在'];
    }
    $result = \app\support\EndpointLink::configure([
        'management_url'  => (string)($_POST['management_url'] ?? ''),
        'endpoint_code'   => (string)($_POST['endpoint_code'] ?? ''),
        'endpoint_secret' => (string)($_POST['endpoint_secret'] ?? ''),
    ]);
    if ($result['ok']) {
        // 保存成功后立即尝试一次心跳，便于客户当场看到控制状态
        try {
            \app\support\EndpointLink::sync(true);
        } catch (\Throwable $e) {
            // 忽略：管理端不可达时按最后已知状态运行
        }
        $sync = endpoint_sync_state();
        if ($sync['error'] !== '') {
            $result['msg'] = '被控端身份已保存，但首次心跳失败：' . $sync['error_text']
                . '（可稍后点「立即心跳」重试；管理端不可达时系统按最后已知状态继续运行）';
            $result['sync_ok'] = false;
            $result['last_error'] = $sync['error'];
            return $result;
        }
    }
    return $result;
}

/** 手动触发一次心跳（忽略 72 小时节流）。 */
function handle_endpoint_sync(bool $installed): array
{
    if (!$installed) {
        return ['ok' => false, 'code' => 'NOT_INSTALLED', 'msg' => message_for('NOT_INSTALLED')];
    }
    if (!class_exists('app\\support\\EndpointLink')) {
        return ['ok' => false, 'code' => 'ENDPOINT_INVALID', 'msg' => '被控端组件缺失，请检查 app/support/EndpointLink.php 是否存在'];
    }
    $ctrl = \app\support\EndpointLink::sync(true);
    if (empty($ctrl['configured'])) {
        return ['ok' => false, 'code' => 'ENDPOINT_INVALID', 'msg' => '尚未配置被控端身份'];
    }
    // 同步失败时不能再报「心跳完成」，否则会把不可达/密钥错误伪装成成功
    $sync = endpoint_sync_state();
    if ($sync['error'] !== '') {
        $last = !empty($ctrl['revoked'])
            ? '已吊销'
            : '最后已知状态 ' . (string)($ctrl['status'] ?? 'unknown');
        return [
            'ok'        => false,
            'code'      => $sync['error'],
            'msg'       => '心跳失败：' . $sync['error_text'] . '（' . $last . '；管理端不可达时按最后已知状态继续运行）',
            'control'   => $ctrl,
            'last_error' => $sync['error'],
        ];
    }
    $msg = !empty($ctrl['revoked'])
        ? '心跳完成：管理端已吊销本系统授权' . (!empty($ctrl['reason']) ? '（' . $ctrl['reason'] . '）' : '')
        : '心跳完成：当前控制状态 ' . (string)($ctrl['status'] ?? 'unknown');
    return ['ok' => true, 'code' => 'ENDPOINT_OK', 'msg' => $msg, 'control' => $ctrl];
}

/**
 * 测试授权端连通性（v2.99.23）。
 *
 * 与管理端 endpoint-api.php 的 action=test 对应：只校验地址、TLS 连接与被控端身份，
 * 不更新心跳时间、不改控制状态，因此可随时点击排查网络 / 证书 / 地址配置问题。
 */
function handle_endpoint_test(bool $installed): array
{
    if (!$installed) {
        return ['ok' => false, 'code' => 'NOT_INSTALLED', 'msg' => message_for('NOT_INSTALLED')];
    }
    if (!class_exists('app\\support\\EndpointLink')) {
        return ['ok' => false, 'code' => 'ENDPOINT_INVALID', 'msg' => '被控端组件缺失，请检查 app/support/EndpointLink.php 是否存在'];
    }
    $t = \app\support\EndpointLink::testConnection();
    $map = endpoint_error_map();
    $code = (string)($t['code'] ?? '');

    if (empty($t['ok'])) {
        $msg = (string)($t['msg'] ?? '');
        if ($msg === '') {
            $msg = $map[$code] ?? ('连接失败：' . $code);
        }
        // 管理端版本过旧：提示改用「立即心跳」验证
        if ($code === 'unknown_action') {
            $msg = '授权端尚未支持连通性测试接口（action=test），请先升级授权管理端；'
                . '当前可改用「立即心跳」验证能否连通';
        }
        return [
            'ok'         => false,
            'code'       => $code,
            'msg'        => $msg,
            'detail'     => (string)($t['detail'] ?? ''),
            'latency_ms' => (int)($t['latency_ms'] ?? 0),
            'http_status' => (int)($t['http_status'] ?? 0),
        ];
    }

    $ctrl = is_array($t['control'] ?? null) ? $t['control'] : [];
    $ctrlText = '';
    if (!empty($ctrl['revoked'])) {
        $ctrlText = '已吊销' . (!empty($ctrl['reason']) ? '（' . $ctrl['reason'] . '）' : '');
    } elseif (($ctrl['status'] ?? '') !== '') {
        $ctrlText = (string)$ctrl['status'];
    }

    $parts = ['连通性正常'];
    if (!empty($t['latency_ms'])) {
        $parts[] = '延迟 ' . (int)$t['latency_ms'] . ' ms';
    }
    if (!empty($t['http_status'])) {
        $parts[] = 'HTTP ' . (int)$t['http_status'];
    }
    if (!empty($t['endpoint_status'])) {
        $parts[] = '被控端状态 ' . $t['endpoint_status'];
    }
    if ($ctrlText !== '') {
        $parts[] = '控制状态 ' . $ctrlText;
    }
    if (!empty($t['server_time'])) {
        $parts[] = '授权端时间 ' . $t['server_time'];
    }
    if (!empty($t['server_version'])) {
        $parts[] = '授权端版本 ' . $t['server_version'];
    }

    return [
        'ok'         => true,
        'code'       => 'OK',
        'msg'        => implode('；', $parts),
        'detail'     => '',
        'latency_ms' => (int)($t['latency_ms'] ?? 0),
        'http_status' => (int)($t['http_status'] ?? 0),
        'control'    => $ctrl,
    ];
}

/**
 * 读取最近一次心跳结果，并给出可执行的中文提示。
 * @return array{error:string,error_text:string,last_sync_at:string}
 */
function endpoint_sync_state(): array
{
    $cfg = class_exists('app\\support\\EndpointLink')
        ? \app\support\EndpointLink::currentConfig()
        : ['configured' => false];
    $error = (string)($cfg['last_error'] ?? '');
    $detail = trim((string)($cfg['last_error_detail'] ?? ''));
    $map = endpoint_error_map();
    return [
        'error'        => $error,
        'error_text'   => $error === ''
            ? ''
            : ($detail !== '' ? $detail : ($map[$error] ?? ('管理端返回错误：' . $error))),
        'last_sync_at' => (string)($cfg['last_sync_at'] ?? ''),
    ];
}

/** 心跳 / 连通性测试错误码 -> 中文提示（setup 内共用一份） */
function endpoint_error_map(): array
{
    return [
        'unreachable'         => '管理端不可达（请检查管理端地址、网络、防火墙或 HTTPS 证书）',
        'invalid_secret'      => '被控端密钥不正确（请在管理端「被控端 → 详情 → 重置密钥」后重新填写）',
        'endpoint_not_found'  => '被控端编号不存在（请核对管理端下发的编号）',
        'endpoint_disabled'   => '被控端已被停用（请联系软件提供方恢复）',
        'missing_params'      => '请求参数缺失（管理端版本可能过旧）',
        'bad_timestamp'       => '本机时间格式异常，请校准服务器时间',
        'expired_timestamp'   => '本机与服务器时间偏差超过 5 分钟，请校准时间',
        'unknown_action'      => '管理端不支持该心跳指令（请升级管理端）',
        'unknown_error'       => '管理端返回未知错误',
        // 目标地址校验失败：优先展示具体原因（见 last_error_detail）
        'blocked_target'      => '管理端地址指向本机或被禁止的内网/云元数据地址，已拒绝访问（如确为内网部署，请在 .env 设置 LICENSE_ENDPOINT_ALLOW_LOOPBACK=1）',
        'insecure_scheme'     => '管理端地址必须使用 https://（如确需内网 http，请在 .env 设置 LICENSE_ENDPOINT_ALLOW_HTTP=1）',
        'unsupported_scheme'  => '管理端地址必须以 https:// 或 http:// 开头',
        'dns_failed'          => '无法解析管理端域名，请检查地址是否正确、服务器 DNS 是否可用',
        'invalid_url'         => '管理端地址格式不正确，请检查是否缺少域名或包含多余字符',
        // 连接管理端失败：区分 TLS 证书、超时、端口不通等（v2.99.22）
        'tls_cert_mismatch'   => '管理端 HTTPS 证书与域名不匹配（证书未覆盖该域名），请在管理端服务器为该域名部署匹配的证书',
        'tls_cert_expired'    => '管理端 HTTPS 证书已过期，请在管理端服务器续期证书',
        'tls_cert_untrusted'  => '管理端 HTTPS 证书不受信任（自签名或缺少中间证书），请更换受信任的证书',
        'tls_handshake_failed'=> '与管理端建立 TLS 连接失败，请检查证书链、TLS 版本与 SNI 配置',
        'timeout'             => '连接管理端超时，请检查网络、防火墙与安全组是否放行 443 端口',
        'connect_failed'      => '无法连接管理端（端口未开放或被防火墙拦截）',
        'endpoint_api_missing'=> '管理端未找到 /endpoint-api.php（HTTP 404），请确认地址指向授权端且 /endpoint-api.php 可访问',
        'invalid_response'    => '管理端返回了非 JSON 内容，请确认地址指向授权端且 /endpoint-api.php 可访问',
        'not_configured'      => '尚未配置被控端身份，请先填写授权端地址、编号与密钥并保存',
        'invalid_endpoint'    => '被控端编号或密钥格式不正确（编号形如 EP-1A2B3C4D，密钥为 32 位十六进制）',
        'server_error'        => '授权端内部错误（HTTP 500），请检查授权端日志',
        'bad_request'         => '请求被授权端拒绝（HTTP 400），请确认管理端版本',
    ];
}

/* ====================================================================== *
 * 公钥校验 / 指纹
 * ====================================================================== */

/**
 * 校验并规范化公钥 PEM。
 * @return array{ok:bool,msg:string,pem:string,fingerprint:string}
 */
function check_public_key(string $pem): array
{
    $pem = trim($pem);
    $out = ['ok' => false, 'msg' => '', 'pem' => '', 'fingerprint' => ''];
    if ($pem === '') {
        $out['msg'] = '公钥不能为空';
        return $out;
    }
    if (!str_contains($pem, 'BEGIN PUBLIC KEY') && !str_contains($pem, 'BEGIN RSA PUBLIC KEY')) {
        $out['msg'] = '公钥格式不正确：应以 -----BEGIN PUBLIC KEY----- 开头、-----END PUBLIC KEY----- 结尾';
        return $out;
    }
    $key = @openssl_pkey_get_public($pem);
    if ($key === false) {
        $out['msg'] = '公钥无法解析，请确认复制完整（含首尾 BEGIN/END 两行）';
        return $out;
    }
    $details = openssl_pkey_get_details($key);
    $modulus = $details['rsa']['n'] ?? '';
    if ($modulus === '' || strlen($modulus) < 200) {
        $out['msg'] = '这不是有效的 RSA 公钥（需 2048 位）';
        return $out;
    }
    $canonical = (string)($details['key'] ?? $pem);
    $out['ok'] = true;
    $out['pem'] = rtrim($canonical) . "\n";
    $out['fingerprint'] = hash('sha256', $modulus);
    $out['msg'] = '公钥格式正确';
    return $out;
}

function public_key_fingerprint(string $pem): string
{
    if ($pem === '') {
        return '';
    }
    $key = @openssl_pkey_get_public($pem);
    if ($key === false) {
        return '';
    }
    $details = openssl_pkey_get_details($key);
    $modulus = $details['rsa']['n'] ?? '';
    return $modulus === '' ? '' : hash('sha256', $modulus);
}

/** 记录公钥变更，便于事后排查配对问题（失败不影响主流程）。 */
function public_key_history(string $vaultDir, string $oldFp, string $newFp, string $source): void
{
    $line = implode("\t", [
        gmdate('Y-m-d H:i:s') . 'Z',
        (string)($_SERVER['REMOTE_ADDR'] ?? '-'),
        $source,
        $oldFp !== '' ? $oldFp : '-',
        $newFp,
    ]) . "\n";
    @file_put_contents($vaultDir . '/public_key_history.log', $line, FILE_APPEND | LOCK_EX);
}

/* ====================================================================== *
 * 自检（区分权限不足 / open_basedir / 伪静态）
 * ====================================================================== */

function run_self_test(string $vaultDir, string $verifierPath): array
{
    $checks = [];

    $checks[] = [
        'title'  => 'PHP 版本',
        'ok'     => version_compare(PHP_VERSION, '8.1.0', '>='),
        'detail' => '当前 ' . PHP_VERSION,
        'advice' => '请在宝塔切换到 PHP 8.1 或更高版本',
    ];

    $checks[] = [
        'title'  => 'openssl 扩展',
        'ok'     => function_exists('openssl_verify'),
        'detail' => function_exists('openssl_verify') ? '可用' : '不可用',
        'advice' => '在宝塔 PHP 设置中安装/启用 openssl 扩展（通常默认已启用）',
    ];

    $parent = dirname($vaultDir);
    $writable = probe_writable($vaultDir, $parent, $blockedByBaseDir);
    $checks[] = [
        'title'  => '授权目录可写',
        'ok'     => $writable,
        'detail' => $writable ? '可写' : ($blockedByBaseDir ? '被 open_basedir 拦截' : '权限不足'),
        'advice' => $blockedByBaseDir
            ? '在宝塔「网站 -> 限制目录/防跨站」中放行站点根目录，或调整 open_basedir 包含 runtime'
            : '将 runtime 目录属主改为 PHP-FPM 运行用户并授予写权限（宝塔文件权限 755，属主 www）',
    ];

    $baseDir = ini_get('open_basedir');
    $baseOk = ($baseDir === false || $baseDir === '') || path_allowed_by_basedir($vaultDir, $baseDir);
    $checks[] = [
        'title'  => 'open_basedir 放行',
        'ok'     => $baseOk,
        'detail' => $baseDir ? $baseDir : '未限制',
        'advice' => '在宝塔关闭「防跨站攻击(open_basedir)」或将 runtime 路径加入白名单',
    ];

    $selfReachable = probe_self_reachable();
    $checks[] = [
        'title'  => '伪静态 / 入口可达',
        'ok'     => $selfReachable,
        'detail' => $selfReachable ? 'setup.php 可直达' : '请求未回到 setup.php',
        'advice' => '检查宝塔伪静态规则，确保 .php 文件直接交给 PHP 处理，不要把 setup.php 重写到 index.php',
    ];

    return $checks;
}

function probe_writable(string $vaultDir, string $parent, ?bool &$blockedByBaseDir): bool
{
    $blockedByBaseDir = false;
    if (is_dir($vaultDir)) {
        return is_writable($vaultDir);
    }
    if (!is_dir($parent)) {
        return false;
    }
    $probe = rtrim($parent, '/\\') . DIRECTORY_SEPARATOR . '.write_probe_' . bin2hex(random_bytes(4));
    $r = @file_put_contents($probe, '1');
    if ($r === false) {
        $err = error_get_last()['message'] ?? '';
        $blockedByBaseDir = str_contains($err, 'open_basedir');
        return false;
    }
    @unlink($probe);
    return true;
}

function path_allowed_by_basedir(string $path, string $baseDir): bool
{
    $real = realpath($path) ?: $path;
    foreach (explode(PATH_SEPARATOR, $baseDir) as $base) {
        $base = rtrim(trim($base), '/\\');
        if ($base === '') {
            continue;
        }
        if (str_starts_with($real, $base)) {
            return true;
        }
    }
    return false;
}

function probe_self_reachable(): bool
{
    if (PHP_SAPI === 'cli') {
        return true;
    }
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    return str_contains($uri, 'setup.php') || str_contains((string)($_SERVER['SCRIPT_NAME'] ?? ''), 'setup.php');
}

/* ====================================================================== *
 * 凭证校验（与 RuntimeSeal 同规则，本文件独立实现，不依赖框架）
 * ====================================================================== */

function verify_credential(string $verifierPath, string $text): array
{
    $pub = @file_get_contents($verifierPath);
    if ($pub === false) {
        return ['ok' => false, 'code' => 'LICENSE_READ_ERROR'];
    }
    $key = openssl_pkey_get_public($pub);
    if ($key === false) {
        return ['ok' => false, 'code' => 'LICENSE_READ_ERROR'];
    }
    $doc = json_decode($text, true);
    if (!is_array($doc) || empty($doc['payload']) || !is_array($doc['payload']) || !isset($doc['signature'])) {
        return ['ok' => false, 'code' => 'LICENSE_MALFORMED'];
    }
    $payload = $doc['payload'];
    foreach (CRED_FIELDS as $f) {
        if (!array_key_exists($f, $payload) || $payload[$f] === null || $payload[$f] === '') {
            return ['ok' => false, 'code' => 'LICENSE_MALFORMED'];
        }
    }
    if ((int)$payload['version'] !== 1) {
        return ['ok' => false, 'code' => 'LICENSE_MALFORMED'];
    }
    $sig = base64_decode((string)$doc['signature'], true);
    $signing = signing_string($payload, CRED_FIELDS);
    if ($sig === false || @openssl_verify($signing, $sig, $key, OPENSSL_ALGO_SHA256) !== 1) {
        return ['ok' => false, 'code' => 'SIGNATURE_INVALID'];
    }
    return ['ok' => true, 'code' => 'OK', 'payload' => $payload];
}

function signing_string(array $payload, array $fields): string
{
    $parts = [];
    foreach ($fields as $f) {
        $parts[] = (string)($payload[$f] ?? '');
    }
    return implode("\n", $parts);
}

/* ====================================================================== *
 * 当前凭证只读检查
 * ====================================================================== */

function inspect_credential(string $verifierPath, string $credentialPath): array
{
    $out = ['ok' => false, 'code' => '', 'message' => '', 'payload' => null];
    if (!is_file($verifierPath)) {
        $out['code'] = 'NOT_INSTALLED';
        $out['message'] = message_for('NOT_INSTALLED');
        return $out;
    }
    if (!is_file($credentialPath)) {
        $out['code'] = 'LICENSE_MISSING';
        $out['message'] = message_for('LICENSE_MISSING');
        return $out;
    }
    $text = @file_get_contents($credentialPath);
    if ($text === false) {
        $out['code'] = 'LICENSE_READ_ERROR';
        $out['message'] = message_for('LICENSE_READ_ERROR');
        return $out;
    }
    $check = verify_credential($verifierPath, $text);
    if (!$check['ok']) {
        $out['code'] = $check['code'];
        $out['message'] = message_for($check['code']);
        return $out;
    }
    $payload = $check['payload'];
    $host = normalize_host((string)($_SERVER['HTTP_HOST'] ?? ''));
    $domains = normalize_host_list((string)$payload['domain']);
    $domainText = implode(',', $domains);
    if ($host !== '' && ($domains === [] || !in_array($host, $domains, true))) {
        $out['code'] = 'DOMAIN_MISMATCH';
        $out['message'] = message_for('DOMAIN_MISMATCH') . '（授权域名：' . $domainText . '）';
        $out['payload'] = $payload;
        return $out;
    }
    $deadline = strtotime((string)$payload['expires_at'] . ' 23:59:59 UTC');
    if ($deadline === false || time() >= $deadline) {
        $out['code'] = 'LICENSE_EXPIRED';
        $out['message'] = message_for('LICENSE_EXPIRED');
        $out['payload'] = $payload;
        return $out;
    }
    $out['ok'] = true;
    $out['code'] = 'OK';
    $out['message'] = '授权有效';
    $out['payload'] = $payload;
    return $out;
}

/* ====================================================================== *
 * 工具函数
 * ====================================================================== */

function current_domain(): string
{
    return normalize_host((string)($_SERVER['HTTP_HOST'] ?? ''));
}

function normalize_host(string $host): string
{
    $host = strtolower(trim($host));
    $host = preg_replace('#:\d+$#', '', $host);
    $host = rtrim($host, '.');
    if (str_starts_with($host, 'www.')) {
        $host = substr($host, 4);
    }
    return (string)$host;
}

/**
 * 凭证可绑定多个域名（英文/中文逗号、分号分隔），返回规范化后的列表。
 * @return string[]
 */
function normalize_host_list(string $raw): array
{
    $parts = preg_split('/\s*[,，;；]\s*/u', trim($raw)) ?: [];
    $out = [];
    foreach ($parts as $part) {
        $host = normalize_host($part);
        if ($host !== '' && !in_array($host, $out, true)) {
            $out[] = $host;
        }
    }
    return $out;
}

function mkdir_deep(string $dir): bool
{
    if (is_dir($dir)) {
        return is_writable($dir);
    }
    return @mkdir($dir, 0755, true) || is_dir($dir);
}

function read_json(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function message_for(string $code): string
{
    $map = [
        'LICENSE_MISSING'    => '缺少授权凭证，请粘贴软件提供方下发的凭证',
        'LICENSE_READ_ERROR' => '授权凭证或公钥读取异常，请重新保存公钥并粘贴凭证',
        'LICENSE_MALFORMED'  => '授权凭证格式错误，请重新复制完整凭证',
        'SIGNATURE_INVALID'  => '凭证校验未通过：请确认本页公钥与授权端一致，然后重新粘贴凭证',
        'DOMAIN_MISMATCH'    => '当前访问域名与授权绑定域名不一致',
        'LICENSE_EXPIRED'    => '授权已到期，请联系软件提供方续期',
        'LICENSE_REVOKED'    => '授权已被软件提供方吊销，请联系软件提供方恢复',
        'NOT_INSTALLED'      => '尚未完成授权安装，请先保存公钥',
        'ACTIVATE_OK'        => '凭证已生效，请重新访问业务系统（无需重启 PHP-FPM）',
        'ACTIVATE_REJECTED'  => '凭证未通过校验，未写入任何文件',
        'INSTALL_OK'         => '公钥已保存，授权安装完成',
        'INSTALL_LOCKED'     => '系统已完成授权安装；如需更换公钥，请在下方「公钥」处保存',
        'INVALID_PUBLIC_KEY' => '公钥格式不正确',
        'PUBLIC_KEY_OK'      => '公钥格式正确',
        'PUBLIC_KEY_SAVED'   => '公钥已保存',
        'PUBLIC_KEY_WRITE_ERROR' => '公钥写出失败，请检查 runtime 目录权限',
        'ENDPOINT_OK'        => '被控端配置已保存',
        'ENDPOINT_INVALID'   => '被控端配置未通过校验',
        'ENDPOINT_WRITE_ERROR' => '被控端配置写出失败，请检查目录权限',
    ];
    return $map[$code] ?? '授权校验未通过，请检查公钥与凭证是否来自同一个授权端';
}

function h(string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/* ====================================================================== *
 * 页面渲染（CSS 内联，自包含，锁死态可渲染）
 * ====================================================================== */

function render_page(bool $installed, array $checks, array $install, array $current, string $flashCode, string $currentPub): void
{
    $title = '授权管理';
    $flash = $flashCode !== '' ? message_for($flashCode) : '';
    // v2.99.1：远程访问（非服务器本机）时隐藏敏感信息并要求填写令牌
    $vaultDir = dirname(__DIR__) . '/runtime/vault';
    $localOnly = request_is_loopback();
    $tokenExists = is_file($vaultDir . '/setup.token');
    $authorized = $localOnly || !$installed;
    $syncState = function_exists('endpoint_sync_state') ? endpoint_sync_state() : ['error' => '', 'error_text' => ''];
    $pubFp = public_key_fingerprint($currentPub);
    $endpoint = class_exists('app\\support\\EndpointLink')
        ? \app\support\EndpointLink::currentConfig()
        : ['configured' => false];
    $failedChecks = array_values(array_filter($checks, static fn($c) => empty($c['ok'])));
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title><?= h($title) ?></title>
<style>
  * { box-sizing: border-box; }
  body { margin:0; font:14px/1.7 -apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,"PingFang SC","Microsoft YaHei",sans-serif; background:#f5f7fa; color:#303133; }
  .wrap { max-width:860px; margin:40px auto; padding:0 16px; }
  .card { background:#fff; border-radius:10px; box-shadow:0 2px 12px rgba(0,0,0,.06); padding:24px 28px; margin-bottom:20px; }
  h1 { font-size:20px; margin:0 0 4px; }
  .sub { color:#909399; font-size:13px; margin-bottom:18px; }
  h2 { font-size:15px; margin:22px 0 10px; padding-left:10px; border-left:3px solid #409eff; }
  table { width:100%; border-collapse:collapse; font-size:13px; }
  th,td { text-align:left; padding:8px 10px; border-bottom:1px solid #ebeef5; vertical-align:top; }
  .ok { color:#67c23a; font-weight:600; }
  .bad { color:#f56c6c; font-weight:600; }
  .tag { display:inline-block; padding:1px 8px; border-radius:4px; font-size:12px; }
  .tag-green { background:#f0f9eb; color:#67c23a; border:1px solid #e1f3d8; }
  .tag-red { background:#fef0f0; color:#f56c6c; border:1px solid #fde2e2; }
  .tag-gray { background:#f4f4f5; color:#909399; border:1px solid #e9e9eb; }
  textarea { width:100%; min-height:150px; font-family:Consolas,Menlo,monospace; font-size:12px; padding:10px; border:1px solid #dcdfe6; border-radius:6px; resize:vertical; }
  input[type=text] { width:100%; padding:9px 10px; border:1px solid #dcdfe6; border-radius:6px; font-size:13px; }
  button { background:#409eff; color:#fff; border:0; border-radius:6px; padding:10px 22px; font-size:14px; cursor:pointer; }
  button:hover { background:#337ecc; }
  button.ghost { background:#fff; color:#606266; border:1px solid #dcdfe6; }
  button.ghost:hover { color:#409eff; border-color:#409eff; background:#ecf5ff; }
  .actions { display:flex; gap:10px; flex-wrap:wrap; margin-top:12px; }
  pre { background:#f8f8f9; border:1px solid #ebeef5; border-radius:6px; padding:12px; font-size:12px; overflow:auto; white-space:pre-wrap; word-break:break-all; }
  .note { background:#ecf5ff; border:1px solid #d9ecff; color:#409eff; border-radius:6px; padding:10px 14px; font-size:13px; }
  .warn { background:#fdf6ec; border:1px solid #faecd8; color:#b88230; border-radius:6px; padding:10px 14px; font-size:13px; }
  .flash { background:#fef0f0; border:1px solid #fde2e2; color:#f56c6c; border-radius:6px; padding:10px 14px; margin-bottom:16px; font-size:13px; }
  .kv { font-size:13px; color:#606266; }
  .kv b { color:#303133; }
  .muted { color:#909399; font-size:12px; }
  .fp { word-break:break-all; }
  code { background:#f4f4f5; padding:1px 5px; border-radius:4px; font-family:Consolas,Menlo,monospace; font-size:12px; }
  details.card summary { cursor:pointer; font-weight:600; color:#606266; }
  .grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
  @media (max-width:720px) { .grid-2 { grid-template-columns:1fr; } }
</style>
</head>
<body>
<div class="wrap">

  <?php if ($flash !== ''): ?>
    <div class="flash"><?= h($flash) ?></div>
  <?php endif; ?>

  <?php if ($installed && !$authorized): ?>
    <!-- 远程访问：敏感操作需要授权管理令牌（服务器 runtime/vault/setup.token） -->
    <div class="card">
      <h2>授权管理令牌</h2>
      <div class="warn">
        当前是通过远程地址访问，更换公钥 / 提交凭证 / 配置被控端属于敏感操作，
        需要填写服务器上的授权管理令牌。令牌文件位于服务器
        <code>runtime/vault/setup.token</code>（仅服务器本地可读，共 32 位十六进制）。
        <br>若您在服务器本机（<code>127.0.0.1</code>）打开本页，则无需填写。
      </div>
      <p style="margin-top:12px">
        <input type="text" id="admin_token" spellcheck="false" autocomplete="off"
               placeholder="填写 runtime/vault/setup.token 中的 32 位令牌（仅本次会话有效）">
      </p>
      <div class="muted">令牌只保存在当前浏览器会话内，关闭页面后需重新填写。</div>
    </div>
  <?php endif; ?>

  <?php if (!$installed): ?>
    <!-- 尚未安装：公钥 + 凭证 一页完成 -->
    <div class="card">
      <h1>授权配置</h1>
      <div class="sub">两步完成：先确认公钥，再粘贴凭证。只写授权文件，不动数据库、不改业务代码。</div>

      <h2>第 1 步：授权端公钥</h2>
      <div class="note">
        默认使用分发包内置公钥。如果授权端（LicenseAdmin）使用的不是分发包内置密钥，
        请到授权端「密钥与配对」页点「复制公钥」，粘贴到下面覆盖默认值。
        <strong>两端的公钥必须一致</strong>，否则凭证会提示校验失败。
      </div>
      <p style="margin-top:12px"><textarea id="public_key" spellcheck="false"><?= h(embedded_public_key()) ?></textarea></p>
      <div class="actions">
        <button onclick="doInstall()">保存公钥并完成安装</button>
        <button class="ghost" onclick="doInspectPublicKey()">只校验指纹</button>
      </div>
      <div id="install-result"></div>
      <div id="inspect-result"></div>
    </div>

    <div class="card">
      <h2>第 2 步：授权凭证</h2>
      <div class="note">到授权端「授权管理 → 详情」复制凭证 JSON，粘贴到下面提交。验签不通过不会写入任何文件。</div>
      <p style="margin-top:12px"><textarea id="credential" spellcheck="false" placeholder='粘贴完整凭证 JSON（含 payload 与 signature）'></textarea></p>
      <div class="actions"><button onclick="doActivate()">提交凭证</button></div>
      <div id="activate-result"></div>
    </div>

    <details class="card" <?= $failedChecks ? 'open' : '' ?>>
      <summary>环境自检：<?= $failedChecks ? count($failedChecks) . ' 项未通过' : '全部通过' ?></summary>
      <table style="margin-top:12px">
        <tr><th style="width:150px">检查项</th><th style="width:80px">结果</th><th>说明 / 修复建议</th></tr>
        <?php foreach ($checks as $c): ?>
          <tr>
            <td><?= h($c['title']) ?></td>
            <td class="<?= $c['ok'] ? 'ok' : 'bad' ?>"><?= $c['ok'] ? '通过' : '未通过' ?></td>
            <td>
              <div><?= h($c['detail']) ?></div>
              <?php if (!$c['ok']): ?><div class="bad" style="font-weight:400;font-size:12px"><?= h($c['advice']) ?></div><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    </details>

  <?php else: ?>
    <!-- 已安装 -->
    <?php if (($current['ok'] || $authorized) && !$current['ok']): ?>
      <div class="flash">当前授权未通过：<?= h($current['message']) ?></div>
    <?php endif; ?>

    <div class="card">
      <h1>授权管理 <span class="tag <?= $current['ok'] ? 'tag-green' : 'tag-red' ?>"><?= $current['ok'] ? '已授权' : h($current['code']) ?></span></h1>
      <div class="sub">凭证文件实时读取，更换凭证无需重启 PHP-FPM、无需清 OPcache。</div>

      <h2>当前授权状态</h2>
      <div class="kv">
        <?php if ($authorized && !empty($current['payload'])): $p = $current['payload']; ?>
          <div>客户：<b><?= h($p['customer']) ?></b></div>
          <div>绑定域名：<b><?= h($p['domain']) ?></b></div>
          <div>到期日：<b><?= h($p['expires_at']) ?></b>（UTC 当日 23:59:59 前有效）</div>
          <div>序列号：<code><?= h($p['serial']) ?></code></div>
        <?php elseif (!$authorized): ?>
          <div class="muted">远程访问仅显示授权结果；客户、域名、序列号等明细需在服务器本机查看，或填写授权管理令牌。</div>
        <?php endif; ?>
        <?php if ($authorized && !$current['ok']): ?>
          <div class="bad" style="margin-top:6px"><?= h($current['message']) ?></div>
        <?php endif; ?>
      </div>
      <?php if ($pubFp !== '' && $authorized): ?>
        <div class="kv" style="margin-top:10px">本机公钥指纹：<code class="fp"><?= h($pubFp) ?></code></div>
        <div class="muted">必须与授权端「密钥与配对」页显示的「公钥指纹」完全一致。</div>
      <?php endif; ?>
      <?php if (!empty($install['installed_at'])): ?>
        <div class="muted">安装时间：<?= h((string)$install['installed_at']) ?></div>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2>公钥（必须与授权端一致）</h2>
      <div class="warn">
        公钥决定“谁的凭证能被本系统接受”。请从授权端「密钥与配对」页点「复制公钥」粘贴到下面并保存，
        保存后本页指纹必须与授权端显示的一致；不一致时凭证会报「凭证校验未通过」。
        更换公钥后，原有凭证通常会失效，需要重新粘贴与新公钥匹配的凭证。
      </div>
      <p style="margin-top:12px"><textarea id="public_key" spellcheck="false"><?= $authorized ? h($currentPub) : '' ?></textarea></p>
      <div class="actions">
        <button onclick="doSavePublicKey()">保存公钥</button>
        <button class="ghost" onclick="doInspectPublicKey()">只校验指纹</button>
        <button class="ghost" onclick="doRestoreEmbedded()">恢复内置公钥</button>
      </div>
      <div id="inspect-result"></div>
      <div id="pubkey-result"></div>
      <?php if ($pubFp !== '' && $authorized): ?>
        <div class="muted" style="margin-top:10px">当前生效指纹：<code class="fp"><?= h($pubFp) ?></code></div>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2>授权凭证</h2>
      <div class="note">从授权端「授权管理 → 详情」复制凭证 JSON 粘贴到下面。只做结构与签名校验；验签不通过不会覆盖现有凭证。</div>
      <p style="margin-top:12px"><textarea id="credential" spellcheck="false" placeholder='粘贴完整凭证 JSON（含 payload 与 signature）'></textarea></p>
      <div class="actions"><button onclick="doActivate()">提交凭证</button></div>
      <div id="activate-result"></div>
    </div>

    <div class="card">
      <h2>被控端（在线控制）</h2>
      <div class="note">被控端身份由授权端在签发凭证时一并下发（编号如 EP-1A2B3C4D + 32 位密钥）。配置后系统每 72 小时主动上报并拉取控制指令；授权端短时不可达时按最后已知状态继续运行，但连续 7 天未能成功心跳将按未授权处理。</div>
      <?php if (!empty($endpoint['configured'])): $ctrl = is_array($endpoint['control'] ?? null) ? $endpoint['control'] : []; ?>
        <div class="kv" style="margin-top:12px">
          <div>授权端地址：<b><?= h($endpoint['management_url']) ?></b></div>
          <div>被控端编号：<code><?= h($endpoint['endpoint_code']) ?></code></div>
          <div>最近成功心跳：<b><?= h($endpoint['last_sync_at'] !== '' ? $endpoint['last_sync_at'] : '尚未成功') ?></b></div>
          <div>控制状态：
            <?php if (!empty($ctrl['revoked'])): ?>
              <span class="tag tag-red">已吊销<?= !empty($ctrl['reason']) ? '（' . h($ctrl['reason']) . '）' : '' ?></span>
            <?php elseif (($ctrl['status'] ?? '') === 'active'): ?>
              <span class="tag tag-green">有效</span>
            <?php elseif (($ctrl['status'] ?? '') === 'expired'): ?>
              <span class="tag tag-gray">已到期</span>
            <?php else: ?>
              <span class="tag tag-gray">未同步 / 未知</span>
            <?php endif; ?>
          </div>
          <div>离线宽限：
            <?php if (!empty($endpoint['stale'])): ?>
              <span class="tag tag-red">已超期</span>
              <span class="muted">连续 7 天未成功心跳，系统按未授权处理；请检查网络后在下方点「测试服务端授权链接」或「立即心跳」恢复。</span>
            <?php elseif (!empty($endpoint['grace_until'])): ?>
              <span class="tag tag-green">剩余 <?= (int)($endpoint['grace_days_left'] ?? 0) ?> 天</span>
              <span class="muted">截止 <?= h((string)$endpoint['grace_until']) ?>（自最近一次成功心跳起算 7 天）</span>
            <?php else: ?>
              <span class="tag tag-gray">暂无计时</span>
              <span class="muted">完成首次心跳后开始计算</span>
            <?php endif; ?>
          </div>
          <?php if (!empty($endpoint['last_error']) && $authorized): ?>
            <div class="muted">最近一次心跳错误：<?= h($syncState['error_text'] !== '' ? $syncState['error_text'] : $endpoint['last_error']) ?></div>
          <?php endif; ?>
        </div>
        <div class="actions">
          <button class="ghost" onclick="doEndpointTest()">测试服务端授权链接</button>
          <button class="ghost" onclick="doEndpointSync()">立即心跳（拉取控制状态）</button>
        </div>
        <div id="endpoint-test-result"></div>
        <div id="endpoint-sync-result"></div>
      <?php endif; ?>
      <p style="margin-top:12px"><input type="text" id="management_url" placeholder="授权端地址（如 https://license.example.com，无需带 endpoint-api.php）" value="<?= h((string)($endpoint['management_url'] ?? '')) ?>"></p>
      <p style="margin-top:8px"><input type="text" id="endpoint_code" placeholder="被控端编号（如 EP-1A2B3C4D）" value="<?= h((string)($endpoint['endpoint_code'] ?? '')) ?>"></p>
      <p style="margin-top:8px"><input type="text" id="endpoint_secret" placeholder="被控端密钥（32 位十六进制，授权端只显示一次）"></p>
      <div class="actions"><button onclick="doEndpointConfigure()"><?= !empty($endpoint['configured']) ? '更新被控端身份' : '保存被控端身份' ?></button></div>
      <div id="endpoint-result"></div>
      <div class="muted" style="margin-top:10px">
        安全限制：管理端地址必须为 https://，且不允许指向本机、链路本地（含云元数据 169.254.169.254）
        等受限地址；内网部署如需使用 http 或 127.0.0.1，可在 .env 设置
        <code>LICENSE_ENDPOINT_ALLOW_HTTP=1</code> / <code>LICENSE_ENDPOINT_ALLOW_LOOPBACK=1</code>。
      </div>
    </div>

    <div class="card">
      <div class="muted">
        本页与业务系统安装向导 install.php 相互独立，互不修改；健康探针 <code>probe.php</code> 在未授权状态下也返回正常。
        遇到问题请把本页截图（含公钥指纹）发给软件提供方。
      </div>
    </div>
  <?php endif; ?>

</div>
<script>
function val(id) { var el = document.getElementById(id); return el ? el.value : ''; }
function post(data, cb) {
  var fd = new FormData();
  Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
  // v2.99.1：远程访问时带上授权管理令牌
  var tk = val('admin_token');
  if (tk) fd.append('admin_token', tk);
  fetch('?', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(cb)
    .catch(function (e) { cb({ ok: false, msg: '请求失败：' + e }); });
}
function box(id, ok, msg) {
  var el = document.getElementById(id);
  if (!el) return;
  el.textContent = '';
  var d = document.createElement('div');
  d.className = ok ? 'ok' : 'bad';
  d.style.cssText = 'margin-top:10px;font-size:13px;word-break:break-all';
  d.textContent = msg;
  el.appendChild(d);
}
function doInstall() {
  var pem = val('public_key');
  if (!pem) { box('install-result', false, '请先粘贴公钥'); return; }
  post({ action: 'install', public_key: pem }, function (r) {
    box('install-result', r.ok, r.ok ? (r.msg + '（公钥指纹：' + r.fingerprint + '）') : r.msg);
    if (r.ok) setTimeout(function () { location.reload(); }, 1500);
  });
}
function doInspectPublicKey() {
  post({ action: 'inspect_public_key', public_key: val('public_key') }, function (r) {
    box('inspect-result', r.ok, r.ok ? (r.msg + '：' + r.fingerprint) : r.msg);
  });
}
function doSavePublicKey() {
  if (!confirm('保存公钥后，原凭证可能失效，需要重新粘贴与新公钥匹配的凭证。确认保存？')) return;
  post({ action: 'save_public_key', public_key: val('public_key') }, function (r) {
    box('pubkey-result', r.ok, r.ok ? (r.msg + '（公钥指纹：' + r.fingerprint + '）') : r.msg);
    if (r.ok) setTimeout(function () { location.reload(); }, 2000);
  });
}
function doRestoreEmbedded() {
  if (!confirm('恢复为分发包内置公钥？恢复后需要重新粘贴与该公钥匹配的凭证。')) return;
  post({ action: 'save_public_key', restore: '1' }, function (r) {
    box('pubkey-result', r.ok, r.ok ? (r.msg + '（公钥指纹：' + r.fingerprint + '）') : r.msg);
    if (r.ok) setTimeout(function () { location.reload(); }, 2000);
  });
}
function doActivate() {
  var credential = val('credential');
  if (!credential) { box('activate-result', false, '请先粘贴凭证'); return; }
  post({ action: 'activate', credential: credential }, function (r) {
    box('activate-result', r.ok, r.msg + (r.detail ? '（' + r.detail + '）' : ''));
    if (r.ok) setTimeout(function () { location.reload(); }, 1500);
  });
}
function doEndpointConfigure() {
  post({
    action: 'endpoint_configure',
    management_url: val('management_url'),
    endpoint_code: val('endpoint_code'),
    endpoint_secret: val('endpoint_secret')
  }, function (r) {
    box('endpoint-result', r.ok, r.msg + (r.detail ? '（' + r.detail + '）' : ''));
    // 保存成功但首次心跳失败时不刷新，保留失败原因，便于当场排查
    if (r.ok && r.sync_ok !== false) setTimeout(function () { location.reload(); }, 1500);
  });
}
function doEndpointTest() {
  var el = document.getElementById('endpoint-test-result');
  if (el) { el.textContent = ''; var w = document.createElement('div'); w.className = 'muted'; w.style.cssText = 'margin-top:10px;font-size:13px'; w.textContent = '正在连接授权端…'; el.appendChild(w); }
  post({ action: 'endpoint_test' }, function (r) {
    var msg = r.msg || '';
    if (r.detail) msg += '（' + r.detail + '）';
    box('endpoint-test-result', r.ok, msg);
  });
}
function doEndpointSync() {
  post({ action: 'endpoint_sync' }, function (r) {
    box('endpoint-sync-result', r.ok, r.msg + (r.detail ? '（' + r.detail + '）' : ''));
  });
}
</script>
</body>
</html>
<?php
}
