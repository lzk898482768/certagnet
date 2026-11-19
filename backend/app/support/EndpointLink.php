<?php
namespace app\support;

/**
 * 被控端在线联动（基础设施层，非业务模块）。
 *
 * 职责：
 *  - 管理端为业务系统签发的被控端身份（管理端地址 + 编号 + 密钥）的持久化；
 *  - 以「72 小时」为周期主动向管理端上报状态并拉取控制指令；
 *  - 将管理端下发的控制状态（active / revoked / expired）落到本地 endpoint.json，
 *    供 RuntimeSeal 在每次请求判定时读取；
 *  - 管理端不可达时按「最后已知状态」继续运行（离线宽限）。
 *
 * 约束：
 *  - 本类不 header / 不 echo / 不 exit，只返回结构化结论；
 *  - 类定义外无任何执行语句、无静态初始化副作用，IO 全部收敛在方法内；
 *  - 网络请求设短超时，失败静默降级，绝不因管理端不可达而阻塞业务过久；
 *  - endpoint_secret 以明文存放于 runtime/vault/endpoint.json（0600），
 *    仅用于 HTTPS 传输给管理端做 hash 比对，业务端不参与验签。
 */
class EndpointLink
{
    /** 心跳周期（秒）：用户指定 72 小时 */
    private const SYNC_INTERVAL = 72 * 3600;

    /** 失败后的重试间隔（秒）：1 小时，避免不可达时每请求重试 */
    private const RETRY_INTERVAL = 3600;

    /**
     * 离线宽限期（秒）：连续 7 天未能成功心跳，则视为未授权（v2.99.23）。
     * 与「管理端不可达时按最后已知状态运行」共同构成离线容错：
     * 短时断网不影响业务，但长期屏蔽管理端将在 7 天后自动锁死。
     */
    private const OFFLINE_GRACE_SECONDS = 7 * 86400;

    /** 网络超时（秒） */
    private const CONNECT_TIMEOUT = 3;
    private const TOTAL_TIMEOUT   = 6;

    /* ------------------------------------------------------------------ *
     * 路径
     * ------------------------------------------------------------------ */

    public static function vaultDir(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'vault';
    }

    public static function configPath(): string
    {
        return self::vaultDir() . DIRECTORY_SEPARATOR . 'endpoint.json';
    }

    private static function credentialPath(): string
    {
        return self::vaultDir() . DIRECTORY_SEPARATOR . 'credential.dat';
    }

    /* ------------------------------------------------------------------ *
     * 配置读写
     * ------------------------------------------------------------------ */

    /**
     * 读取 endpoint.json（不存在或非法返回 null）。
     * @return array<string,mixed>|null
     */
    public static function loadConfig(): ?array
    {
        $path = self::configPath();
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /** @param array<string,mixed> $cfg */
    private static function saveConfig(array $cfg): bool
    {
        $dir = self::vaultDir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        $json = json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            return false;
        }
        $ok = @file_put_contents(self::configPath(), $json, LOCK_EX) !== false;
        if ($ok) {
            @chmod(self::configPath(), 0600);
        }
        return $ok;
    }

    /* ------------------------------------------------------------------ *
     * 顾客在 setup.php 填写 / 更换被控端身份
     * ------------------------------------------------------------------ */

    /**
     * 保存被控端身份。保留既有控制状态与心跳时间（更换身份不等同于恢复授权）。
     *
     * v2.99.1 安全加固：本方法会由服务端主动发起 HTTP 请求，属于典型的 SSRF
     * 落点。除调用方（setup.php）已加鉴权外，这里再做目标地址校验：
     *  - 必须 https://（可用 LICENSE_ENDPOINT_ALLOW_HTTP=1 在纯内网临时放开）；
     *  - 拒绝回环 / 链路本地 / 云元数据地址（可用 LICENSE_ENDPOINT_ALLOW_LOOPBACK=1 临时放开）；
     *  - 域名解析后的所有 IP 都要通过上述校验，防止 DNS 指向内网敏感地址；
     *  - 不跟随跳转、限制响应体大小（见 httpPost）。
     *
     * @param array{management_url:string,endpoint_code:string,endpoint_secret:string} $input
     * @return array{ok:bool,code:string,msg:string}
     */
    public static function configure(array $input): array
    {
        $url    = trim((string)($input['management_url'] ?? ''));
        $code   = trim((string)($input['endpoint_code'] ?? ''));
        $secret = trim((string)($input['endpoint_secret'] ?? ''));

        if ($url === '' || $code === '' || $secret === '') {
            return ['ok' => false, 'code' => 'ENDPOINT_INVALID', 'msg' => '管理端地址、被控端编号、被控端密钥均为必填'];
        }
        $safe = self::validateTarget($url);
        if (!$safe['ok']) {
            return ['ok' => false, 'code' => 'ENDPOINT_INVALID', 'msg' => $safe['msg']];
        }
        if (!preg_match('/^EP-[A-Za-z0-9]{4,32}$/', $code)) {
            return ['ok' => false, 'code' => 'ENDPOINT_INVALID', 'msg' => '被控端编号格式不正确（示例：EP-1A2B3C4D）'];
        }
        if (!preg_match('/^[A-Fa-f0-9]{16,64}$/', $secret)) {
            return ['ok' => false, 'code' => 'ENDPOINT_INVALID', 'msg' => '被控端密钥格式不正确（应为 16-64 位十六进制）'];
        }

        $old = self::loadConfig() ?? [];
        $cfg = array_merge($old, [
            'management_url' => rtrim($url, '/'),
            'endpoint_code'  => $code,
            'endpoint_secret' => $secret,
            'configured_at'  => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        // 更换身份后尽快重新拉取一次控制状态
        $cfg['next_sync_at_ts'] = 0;

        if (!self::saveConfig($cfg)) {
            return ['ok' => false, 'code' => 'ENDPOINT_WRITE_ERROR', 'msg' => '被控端配置写出失败，请检查 runtime/vault 目录权限'];
        }
        return ['ok' => true, 'code' => 'ENDPOINT_OK', 'msg' => '被控端身份已保存'];
    }

    /**
     * 校验被控端地址是否为允许访问的目标（防 SSRF）。
     *
     * @return array{ok:bool,msg:string}
     */
    public static function validateTarget(string $url): array
    {
        $parts = @parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return ['ok' => false, 'code' => 'invalid_url', 'msg' => '管理端地址格式不正确'];
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $allowHttp = self::envFlag('LICENSE_ENDPOINT_ALLOW_HTTP');
        if ($scheme === 'http' && !$allowHttp) {
            return [
                'ok'   => false,
                'code' => 'insecure_scheme',
                'msg'  => '管理端地址必须使用 https://（如确需内网 http，请在 .env 设置 LICENSE_ENDPOINT_ALLOW_HTTP=1）',
            ];
        }
        if ($scheme !== 'https' && $scheme !== 'http') {
            return ['ok' => false, 'code' => 'unsupported_scheme', 'msg' => '管理端地址必须以 https:// 开头'];
        }
        if (!empty($parts['user']) || !empty($parts['pass'])) {
            return ['ok' => false, 'code' => 'invalid_url', 'msg' => '管理端地址不允许包含用户名或密码'];
        }

        $host = strtolower(trim((string)$parts['host'], '[]'));
        if ($host === '') {
            return ['ok' => false, 'code' => 'invalid_url', 'msg' => '管理端地址缺少主机名'];
        }

        $allowLoopback = self::envFlag('LICENSE_ENDPOINT_ALLOW_LOOPBACK');
        if (!$allowLoopback && self::isBlockedHostname($host)) {
            return [
                'ok'   => false,
                'code' => 'blocked_target',
                'msg'  => '管理端地址不允许指向本机、内网管理地址或云元数据地址',
            ];
        }

        // 解析域名，逐个校验 IP，避免 DNS 指向敏感地址绕过主机名判断
        $ips = self::resolveIps($host);
        if ($ips === []) {
            return ['ok' => false, 'code' => 'dns_failed', 'msg' => '无法解析管理端域名，请检查地址是否正确'];
        }
        if (!$allowLoopback) {
            foreach ($ips as $ip) {
                if (self::isBlockedIp($ip)) {
                    return [
                        'ok'   => false,
                        'code' => 'blocked_target',
                        'msg'  => '管理端域名解析到了受限地址（' . $ip . '），已拒绝',
                    ];
                }
            }
        }
        return ['ok' => true, 'code' => '', 'msg' => ''];
    }

    /**
     * 读取布尔型配置。
     *
     * 注意：本类可能被 public/setup.php 在「无框架」场景下直接 require，
     * 此时 ThinkPHP 的 env() 助手函数并不存在，必须降级到 getenv/$_ENV，
     * 否则会直接致命错误。
     */
    private static function envFlag(string $name): bool
    {
        $v = null;
        if (function_exists('env')) {
            try {
                $v = env($name);
            } catch (\Throwable $e) {
                $v = null;
            }
        }
        if ($v === null) {
            $v = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        }
        if (is_bool($v)) {
            return $v;
        }
        $v = strtolower(trim((string)$v));
        return in_array($v, ['1', 'true', 'on', 'yes'], true);
    }

    /** 主机名层面的黑名单（含云厂商元数据域名） */
    private static function isBlockedHostname(string $host): bool
    {
        if (in_array($host, ['localhost', 'localhost.localdomain', 'ip6-localhost', 'ip6-loopback'], true)) {
            return true;
        }
        if (str_ends_with($host, '.localhost') || str_ends_with($host, '.internal') || str_ends_with($host, '.local')) {
            return true;
        }
        // 云元数据常用域名
        foreach (['metadata.google.internal', 'metadata', 'instance-data', 'metadata.azure.com'] as $bad) {
            if ($host === $bad) {
                return true;
            }
        }
        return false;
    }

    /** @return string[] */
    private static function resolveIps(string $host): array
    {
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        if (function_exists('gethostbynamel')) {
            $v4 = @gethostbynamel($host);
            if (is_array($v4)) {
                $ips = array_merge($ips, $v4);
            }
        }
        if (function_exists('dns_get_record')) {
            $v6 = @dns_get_record($host, DNS_AAAA);
            if (is_array($v6)) {
                foreach ($v6 as $rec) {
                    if (!empty($rec['ipv6'])) {
                        $ips[] = (string)$rec['ipv6'];
                    }
                }
            }
        }
        return array_values(array_unique(array_filter($ips)));
    }

    /** 是否为禁止访问的地址（回环 / 未指定 / 链路本地 / 组播 / 云元数据） */
    private static function isBlockedIp(string $ip): bool
    {
        $ip = trim($ip);
        if ($ip === '') {
            return true;
        }
        // 未指定 / 回环
        if ($ip === '0.0.0.0' || $ip === '::' || $ip === '::1') {
            return true;
        }
        if (str_starts_with($ip, '127.')) {
            return true;
        }
        // 链路本地：含云厂商元数据地址 169.254.169.254
        if (str_starts_with($ip, '169.254.')) {
            return true;
        }
        // IPv6 链路本地 / 组播
        if (preg_match('/^fe80:/i', $ip) === 1 || preg_match('/^ff[0-9a-f]{2}:/i', $ip) === 1) {
            return true;
        }

        // 内网地址默认放行（管理端通常部署在内网，这是本功能的正常用法）；
        // 需要更严格时可在 .env 设置 LICENSE_ENDPOINT_BLOCK_PRIVATE=1 一并封禁。
        if (self::envFlag('LICENSE_ENDPOINT_BLOCK_PRIVATE')) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false) {
                return true;
            }
            if (preg_match('/^(fc|fd)/i', $ip) === 1) {
                return true;
            }
        }
        return false;
    }


    /**
     * 供 setup.php 展示的当前被控端配置（不含密钥明文）。
     * @return array<string,mixed>
     */
    public static function currentConfig(): array
    {
        $cfg = self::loadConfig();
        if ($cfg === null) {
            return ['configured' => false];
        }
        $grace = self::offlineGrace($cfg);
        return [
            'configured'      => true,
            'management_url'  => (string)($cfg['management_url'] ?? ''),
            'endpoint_code'   => (string)($cfg['endpoint_code'] ?? ''),
            'configured_at'   => (string)($cfg['configured_at'] ?? ''),
            'last_sync_at'    => (string)($cfg['last_sync_at'] ?? ''),
            'last_attempt_at' => (string)($cfg['last_attempt_at'] ?? ''),
            'last_error'      => (string)($cfg['last_error'] ?? ''),
            'last_error_detail' => (string)($cfg['last_error_detail'] ?? ''),
            'stale'           => $grace['stale'],
            'grace_base_at'   => $grace['base_at'],
            'grace_until'     => $grace['deadline'],
            'grace_days_left' => $grace['days_left'],
            'control'         => is_array($cfg['control'] ?? null) ? $cfg['control'] : null,
        ];
    }

    /**
     * 离线宽限计算：以「最近一次成功心跳」为起点，未成功过则从「配置时间」起算。
     * 连续超过 7 天没有成功心跳即判定为超期（stale）。
     *
     * @param array<string,mixed> $cfg
     * @return array{stale:bool,base_at:string,deadline:string,days_left:int}
     */
    private static function offlineGrace(array $cfg): array
    {
        $base = (string)($cfg['last_sync_at'] ?? '');
        if ($base === '') {
            $base = (string)($cfg['configured_at'] ?? '');
        }
        $ts = $base !== '' ? strtotime($base) : false;
        if ($ts === false) {
            // 历史配置缺少时间戳：不主动锁死，等首次心跳补齐
            return ['stale' => false, 'base_at' => '', 'deadline' => '', 'days_left' => 0];
        }
        $deadlineTs = $ts + self::OFFLINE_GRACE_SECONDS;
        $left = $deadlineTs - time();
        return [
            'stale'     => $left < 0,
            'base_at'   => gmdate('Y-m-d\TH:i:s\Z', $ts),
            'deadline'  => gmdate('Y-m-d\TH:i:s\Z', $deadlineTs),
            'days_left' => $left < 0 ? 0 : (int)ceil($left / 86400),
        ];
    }

    /* ------------------------------------------------------------------ *
     * 控制状态（纯本地，不联网）
     * ------------------------------------------------------------------ */

    /**
     * 读取本地缓存的最后已知控制状态。
     * 未配置被控端时返回「未启用控制」：revoked=false，保持纯离线行为。
     *
     * @return array{revoked:bool,status:string,reason:string,configured:bool}
     */
    public static function controlStatus(): array
    {
        $cfg = self::loadConfig();
        if ($cfg === null) {
            return ['revoked' => false, 'status' => 'unconfigured', 'reason' => '', 'configured' => false, 'stale' => false];
        }
        $ctrl  = is_array($cfg['control'] ?? null) ? $cfg['control'] : [];
        $grace = self::offlineGrace($cfg);
        return [
            'revoked'    => (bool)($ctrl['revoked'] ?? false),
            'status'     => (string)($ctrl['status'] ?? 'unknown'),
            'reason'     => (string)($ctrl['reason'] ?? ''),
            'configured' => true,
            // v2.99.23：连续 7 天未成功心跳 -> 视为未授权
            'stale'      => $grace['stale'],
            'grace_until' => $grace['deadline'],
            'days_left'  => $grace['days_left'],
        ];
    }

    /* ------------------------------------------------------------------ *
     * 心跳同步（72 小时节流）
     * ------------------------------------------------------------------ */

    /**
     * 节流执行一次心跳上报 + 控制拉取。失败静默，按最后已知状态返回。
     *
     * @param bool $force 忽略节流强制同步（供 setup.php 手动触发）
     * @return array{revoked:bool,status:string,reason:string,configured:bool}
     */
    public static function sync(bool $force = false): array
    {
        $cfg = self::loadConfig();
        if ($cfg === null) {
            return ['revoked' => false, 'status' => 'unconfigured', 'reason' => '', 'configured' => false];
        }

        // 节流：未到下一次同步时间则直接返回最后已知状态
        $nextAt = (int)($cfg['next_sync_at_ts'] ?? 0);
        if (!$force && $nextAt > 0 && time() < $nextAt) {
            return self::controlFromConfig($cfg);
        }

        // 无有效凭证（未安装 / 未激活）时不上报，避免把空 domain 送去管理端
        $payload = self::readCredentialPayload();
        if ($payload === null) {
            return self::controlFromConfig($cfg);
        }

        $url = self::endpointApiUrl((string)($cfg['management_url'] ?? ''));
        if ($url === '') {
            return self::controlFromConfig($cfg);
        }
        // v2.99.1：心跳同样做目标校验，避免历史配置里残留的内网/元数据地址被继续访问
        $safe = self::validateTarget((string)($cfg['management_url'] ?? ''));
        if (!$safe['ok']) {
            $cfg['next_sync_at_ts'] = time() + self::RETRY_INTERVAL;
            $cfg['last_attempt_at'] = gmdate('Y-m-d\TH:i:s\Z');
            // 保留具体原因（协议不允许 / 域名解析失败 / 受限地址），避免统一提示成「内网地址被拒」
            $cfg['last_error'] = (string)($safe['code'] ?? '') !== '' ? (string)$safe['code'] : 'blocked_target';
            $cfg['last_error_detail'] = (string)($safe['msg'] ?? '');
            self::saveConfig($cfg);
            return self::controlFromConfig($cfg);
        }

        $domain = (string)($payload['domain'] ?? '');
        if ($domain === '') {
            $domain = self::normalizeHost((string)($_SERVER['HTTP_HOST'] ?? ''));
        }

        $resp = self::httpPost($url, [
            'action'          => 'heartbeat',
            'endpoint_code'   => (string)($cfg['endpoint_code'] ?? ''),
            'endpoint_secret' => (string)($cfg['endpoint_secret'] ?? ''),
            'timestamp'       => (string)time(),
            'domain'          => $domain,
            'serial'          => (string)($payload['serial'] ?? ''),
        ]);

        // 网络层失败（DNS / 连接 / TLS / 超时）：按具体原因记录，便于被控端直接提示怎么修
        if (empty($resp['ok'])) {
            $code = self::classifyTransportError($resp);
            $cfg['next_sync_at_ts'] = time() + self::RETRY_INTERVAL;
            $cfg['last_attempt_at'] = gmdate('Y-m-d\TH:i:s\Z');
            $cfg['last_error'] = $code;
            $cfg['last_error_detail'] = self::transportDetail($resp, $url, $code);
            self::saveConfig($cfg);
            return self::controlFromConfig($cfg);
        }

        $data = json_decode((string)($resp['body'] ?? ''), true);

        // 连接成功但不是 JSON：多为地址指错、endpoint-api.php 不存在或被网关拦截
        if (!is_array($data)) {
            $status = (int)($resp['status'] ?? 0);
            $cfg['next_sync_at_ts'] = time() + self::RETRY_INTERVAL;
            $cfg['last_attempt_at'] = gmdate('Y-m-d\TH:i:s\Z');
            $cfg['last_error'] = $status === 404 ? 'endpoint_api_missing' : 'invalid_response';
            $cfg['last_error_detail'] = '管理端返回了非 JSON 内容（HTTP ' . $status . '）：'
                . self::snippet((string)($resp['body'] ?? ''))
                . '；请确认管理端地址指向授权端，且 /endpoint-api.php 可正常访问';
            self::saveConfig($cfg);
            return self::controlFromConfig($cfg);
        }

        if (($data['ok'] ?? false) !== true) {
            // 管理端明确返回的业务错误：保留最后已知状态，延后 1 小时再试
            $cfg['next_sync_at_ts'] = time() + self::RETRY_INTERVAL;
            $cfg['last_attempt_at'] = gmdate('Y-m-d\TH:i:s\Z');
            $cfg['last_error'] = (string)($data['error'] ?? 'unknown_error');
            $cfg['last_error_detail'] = '';
            self::saveConfig($cfg);
            return self::controlFromConfig($cfg);
        }

        // 成功：更新控制状态与下一次同步时间
        $ctrlIn = is_array($data['control'] ?? null) ? $data['control'] : [];
        $cfg['control'] = [
            'revoked' => (bool)($ctrlIn['revoked'] ?? false),
            'status'  => (string)($ctrlIn['status'] ?? 'unknown'),
            'reason'  => (string)($ctrlIn['reason'] ?? ''),
        ];
        $cfg['control_updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $cfg['last_sync_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $cfg['last_attempt_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $cfg['last_error'] = '';
        $cfg['last_error_detail'] = '';
        $cfg['next_sync_at_ts'] = time() + self::SYNC_INTERVAL;
        self::saveConfig($cfg);

        return self::controlFromConfig($cfg);
    }

    /**
     * 测试授权端连通性（v2.99.23）。
     *
     * 与管理端 endpoint-api.php 的 action=test 对应：只做地址校验、TLS 连接、
     * 被控端编号/密钥校验，不更新心跳时间、不改变控制状态，因此可随时点。
     * 管理端版本较旧（未实现 test）时返回 unknown_action，由页面提示改用「立即心跳」。
     *
     * @return array{
     *   ok:bool, code:string, msg:string, detail:string, latency_ms:int, http_status:int,
     *   server_time:string, endpoint_status:string, server_version:string,
     *   control:array<string,mixed>|null
     * }
     */
    public static function testConnection(): array
    {
        $out = [
            'ok'              => false,
            'code'            => '',
            'msg'             => '',
            'detail'          => '',
            'latency_ms'      => 0,
            'http_status'     => 0,
            'server_time'     => '',
            'endpoint_status' => '',
            'server_version'  => '',
            'control'         => null,
        ];

        $cfg = self::loadConfig();
        if ($cfg === null || trim((string)($cfg['management_url'] ?? '')) === '') {
            $out['code'] = 'not_configured';
            $out['msg']  = '尚未配置被控端身份，请先填写授权端地址、编号与密钥并保存';
            return $out;
        }

        $base = (string)$cfg['management_url'];
        $safe = self::validateTarget($base);
        if (!$safe['ok']) {
            $out['code'] = (string)($safe['code'] ?? 'invalid_url');
            $out['msg']  = (string)($safe['msg'] ?? '管理端地址校验未通过');
            return $out;
        }

        $url = self::endpointApiUrl($base);
        $payload = self::readCredentialPayload();
        $domain = (string)($payload['domain'] ?? '');
        if ($domain === '') {
            $domain = self::normalizeHost((string)($_SERVER['HTTP_HOST'] ?? ''));
        }

        $start = microtime(true);
        $resp  = self::httpPost($url, [
            'action'          => 'test',
            'endpoint_code'   => (string)($cfg['endpoint_code'] ?? ''),
            'endpoint_secret' => (string)($cfg['endpoint_secret'] ?? ''),
            'timestamp'       => (string)time(),
            'domain'          => $domain,
            'serial'          => (string)($payload['serial'] ?? ''),
        ]);
        $out['latency_ms']  = (int)round((microtime(true) - $start) * 1000);
        $out['http_status'] = (int)($resp['status'] ?? 0);

        if (empty($resp['ok'])) {
            $code = self::classifyTransportError($resp);
            $out['code']   = $code;
            $out['msg']    = self::transportDetail($resp, $url, $code);
            $out['detail'] = trim((string)($resp['error'] ?? ''));
            return $out;
        }

        $data = json_decode((string)($resp['body'] ?? ''), true);
        if (!is_array($data)) {
            $out['code'] = (int)($resp['status'] ?? 0) === 404 ? 'endpoint_api_missing' : 'invalid_response';
            $out['msg']  = '管理端返回了非 JSON 内容（HTTP ' . (int)($resp['status'] ?? 0) . '）：'
                . self::snippet((string)($resp['body'] ?? ''))
                . '；请确认地址指向授权端，且 /endpoint-api.php 可正常访问';
            return $out;
        }

        if (($data['ok'] ?? false) !== true) {
            $out['code'] = (string)($data['error'] ?? 'unknown_error');
            $out['msg']  = '管理端返回错误：' . $out['code'];
            return $out;
        }

        $out['ok']              = true;
        $out['code']            = 'OK';
        $out['msg']             = '连接成功，授权端可访问且被控端身份校验通过';
        $out['server_time']     = (string)($data['server_time'] ?? '');
        $out['endpoint_status'] = (string)($data['endpoint']['status'] ?? '');
        $out['server_version']  = (string)($data['server_version'] ?? '');
        $out['control']         = is_array($data['control'] ?? null) ? $data['control'] : null;
        return $out;
    }

    /** @param array<string,mixed> $cfg */
    private static function controlFromConfig(array $cfg): array
    {
        $ctrl = is_array($cfg['control'] ?? null) ? $cfg['control'] : [];
        return [
            'revoked'    => (bool)($ctrl['revoked'] ?? false),
            'status'     => (string)($ctrl['status'] ?? 'unknown'),
            'reason'     => (string)($ctrl['reason'] ?? ''),
            'configured' => true,
        ];
    }

    /* ------------------------------------------------------------------ *
     * 内部原语
     * ------------------------------------------------------------------ */

    /** @return array<string,mixed>|null */
    private static function readCredentialPayload(): ?array
    {
        $path = self::credentialPath();
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return null;
        }
        $doc = json_decode($raw, true);
        if (!is_array($doc) || !is_array($doc['payload'] ?? null)) {
            return null;
        }
        return $doc['payload'];
    }

    private static function endpointApiUrl(string $base): string
    {
        $base = trim($base);
        if ($base === '') {
            return '';
        }
        $base = rtrim($base, '/');
        if (preg_match('#/endpoint-api\.php$#i', $base)) {
            return $base;
        }
        return $base . '/endpoint-api.php';
    }

    /**
     * 短超时 POST。
     *
     * v2.99.1：增加响应体大小上限（1MB），防止恶意管理端返回超大内容打爆内存；
     * 续用 CURLOPT_FOLLOWLOCATION=false，杜绝 302 跳内网导致 SSRF 绕过。
     * v2.99.22：返回结构化结果，把 curl 错误号 / HTTP 状态一并带出，
     * 供上层区分「证书域名不匹配 / 证书过期 / DNS 解析失败 / 超时 / 404」等具体原因。
     *
     * @return array{ok:bool,body:?string,status:int,errno:int,error:string}
     */
    private static function httpPost(string $url, array $fields): array
    {
        $body = http_build_query($fields);
        $maxBytes = 1024 * 1024;

        if (function_exists('curl_init')) {
            $ch = @curl_init($url);
            if ($ch === false) {
                return ['ok' => false, 'body' => null, 'status' => 0, 'errno' => 0, 'error' => 'curl_init failed'];
            }
            @curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT        => self::TOTAL_TIMEOUT,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_MAXFILESIZE    => $maxBytes,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            ]);
            $resp  = @curl_exec($ch);
            $code  = (int)@curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $errno = (int)@curl_errno($ch);
            $err   = (string)@curl_error($ch);
            @curl_close($ch);
            if ($resp === false || $errno !== 0) {
                return ['ok' => false, 'body' => null, 'status' => $code, 'errno' => $errno, 'error' => $err];
            }
            if ($code === 0) {
                return ['ok' => false, 'body' => null, 'status' => 0, 'errno' => $errno, 'error' => 'HTTP 状态未知'];
            }
            return ['ok' => true, 'body' => substr((string)$resp, 0, $maxBytes), 'status' => $code, 'errno' => 0, 'error' => ''];
        }

        // 无 cURL 时的最小回退
        $ctx = @stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => 'Content-Type: application/x-www-form-urlencoded',
                'content'       => $body,
                'timeout'       => self::TOTAL_TIMEOUT,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 1,
            ],
        ]);
        $resp = @file_get_contents($url, false, $ctx, 0, $maxBytes);
        if ($resp === false) {
            $last = error_get_last();
            return [
                'ok'     => false,
                'body'   => null,
                'status' => 0,
                'errno'  => 0,
                'error'  => (string)($last['message'] ?? ''),
            ];
        }
        $status = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string)$line, $m) === 1) {
                    $status = (int)$m[1];
                }
            }
        }
        return ['ok' => true, 'body' => (string)$resp, 'status' => $status, 'errno' => 0, 'error' => ''];
    }

    /**
     * 把底层传输错误归类为可读错误码（写入 last_error，由 setup.php 映射中文提示）。
     *
     * @param array{status:int,errno:int,error:string} $t
     */
    private static function classifyTransportError(array $t): string
    {
        $err   = strtolower((string)($t['error'] ?? ''));
        $errno = (int)($t['errno'] ?? 0);

        // 证书与域名不匹配（curl 60 / schannel SEC_E_WRONG_PRINCIPAL）
        if (preg_match('/no alternative certificate subject name|does not match|subject name|wrong principal|hostname/i', $err) === 1) {
            return 'tls_cert_mismatch';
        }
        if (preg_match('/expired/i', $err) === 1) {
            return 'tls_cert_expired';
        }
        if (preg_match('/self[- ]signed|unable to get local issuer|unknown ca|certificate verify failed|unable to verify|issuer certificate/i', $err) === 1) {
            return 'tls_cert_untrusted';
        }
        if (in_array($errno, [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 83, 90, 91], true)
            || preg_match('/ssl|tls|schannel|handshake|certificate/i', $err) === 1) {
            return 'tls_handshake_failed';
        }
        if ($errno === 28 || preg_match('/timed? ?out|timeout/i', $err) === 1) {
            return 'timeout';
        }
        if ($errno === 6 || preg_match('/could not resolve|name or service not known|getaddrinfo|php_network_getaddresses/i', $err) === 1) {
            return 'dns_failed';
        }
        if ($errno === 7 || preg_match('/could not connect|connection refused|failed to connect|connection reset/i', $err) === 1) {
            return 'connect_failed';
        }
        return 'unreachable';
    }

    /**
     * 具体原因的中文说明（优先于错误码映射展示）。
     *
     * @param array{status:int,errno:int,error:string} $t
     */
    private static function transportDetail(array $t, string $url, string $code): string
    {
        $host   = (string)(parse_url($url, PHP_URL_HOST) ?: $url);
        $status = (int)($t['status'] ?? 0);
        $raw    = trim((string)($t['error'] ?? ''));
        $raw    = $raw === '' ? '' : '（' . self::snippet($raw, 160) . '）';

        switch ($code) {
            case 'tls_cert_mismatch':
                return '管理端 HTTPS 证书与域名不匹配：证书不是颁发给 ' . $host . ' 的，请在管理端服务器为该域名部署匹配的证书' . $raw;
            case 'tls_cert_expired':
                return '管理端 HTTPS 证书已过期，请在管理端服务器续期证书' . $raw;
            case 'tls_cert_untrusted':
                return '管理端 HTTPS 证书不受信任（自签名或缺少中间证书），请更换受信任的证书' . $raw;
            case 'tls_handshake_failed':
                return '与管理端建立 TLS 连接失败，请检查证书链、TLS 版本与 SNI 配置' . $raw;
            case 'timeout':
                return '连接管理端超时，请检查网络、防火墙与安全组是否放行 443 端口' . $raw;
            case 'dns_failed':
                return '无法解析管理端域名 ' . $host . '，请检查服务器 DNS 设置' . $raw;
            case 'connect_failed':
                return '无法连接管理端 ' . $host . '（端口未开放或被防火墙拦截）' . $raw;
            default:
                return '请求管理端失败' . ($status > 0 ? '（HTTP ' . $status . '）' : '') . $raw;
        }
    }

    /** 截断为一行可读文本（避免把整页 HTML 写进配置文件） */
    private static function snippet(string $text, int $limit = 120): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '') {
            return '';
        }
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $limit, 'UTF-8') . (mb_strlen($text, 'UTF-8') > $limit ? '…' : '');
        }
        return substr($text, 0, $limit) . (strlen($text) > $limit ? '…' : '');
    }

    /** 主机名规范化（小写 / 去端口 / 去 www 前缀），与 RuntimeSeal 保持一致。 */
    private static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('#:\d+$#', '', $host);
        $host = rtrim($host, '.');
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        return $host;
    }
}