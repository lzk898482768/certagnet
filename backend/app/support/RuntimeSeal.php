<?php
namespace app\support;

/**
 * 运行时准入内核（基础设施层，非业务模块）。
 *
 * 职责边界：
 *  - 仅做「读凭证 -> 验签 -> 域名比对 -> 到期判定 -> 派生运行时值 -> 给出结论」；
 *  - assess() 是纯判定：只返回结论，不 header、不 echo、不 exit、不抛业务异常；
 *  - 终止式输出（302 / 403）由各消费点各自实现，本类不集中输出；
 *  - 不读数据库、不写任何文件、不启动 session、不注入全局状态。
 *
 * 两态状态机，无任何放行开关：
 *  - 有效：assess() 返回 ok=true，消费点纯透传；
 *  - 未授权：assess() 返回 ok=false + 固定错误码，消费点终止式拦截，不存在
 *    「改一个常量/一行布尔即可全放行」的入口。
 *
 * 命名刻意不包含 license / activate / guard / auth 等集中靶子词。
 *
 * 重要：本文件在 CLI 也会被 common.php 加载，因此类定义外不得有任何执行语句、
 * 静态初始化副作用、文件读取或 $_SERVER 访问；所有 IO 都收敛在方法内、且仅在
 * HTTP 链路被调用时发生。
 */
class RuntimeSeal
{
    /** 凭证 payload 固定字段与顺序（与签发端一致） */
    private const CRED_FIELDS = ['version', 'serial', 'customer', 'domain', 'issued_at', 'expires_at'];

    /** HKDF info 标签 */
    private const INFO_ROOT   = 'seal-root';
    private const INFO_MARK   = 'channel-mark';
    private const INFO_THREAD = 'runtime-thread';

    /** 派生值固定长度（hex 字符数） */
    private const LEN_MARK   = 48; // 24 字节
    private const LEN_THREAD = 64; // 32 字节

    /** @var array<string,mixed>|null 每请求只判定一次 */
    private static $cached = null;

    /* ------------------------------------------------------------------ *
     * 路径（仅方法内调用，无静态初始化）
     * ------------------------------------------------------------------ */

    public static function vaultDir(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'vault';
    }

    public static function verifierPath(): string
    {
        return self::vaultDir() . DIRECTORY_SEPARATOR . 'verifier.pem';
    }

    public static function credentialPath(): string
    {
        return self::vaultDir() . DIRECTORY_SEPARATOR . 'credential.dat';
    }

    /* ------------------------------------------------------------------ *
     * F2/F3：每请求一次的判定（assess 纯函数语义）
     * ------------------------------------------------------------------ */

    /**
     * 判定当前请求的准入结论。
     *
     * @return array{
     *   ok:bool, code:string, message:string, kind:string,
     *   domain:string, serial:string, expires:string,
     *   mark:string, thread:string, material:array<string,string>|null
     * }
     */
    public static function assess(): array
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        $base = [
            'ok'       => false,
            'code'     => '',
            'message'  => '',
            'kind'     => self::classifyRequest(),
            'domain'   => '',
            'serial'   => '',
            'expires'  => '',
            'mark'     => '',
            'thread'   => '',
            'material' => null,
        ];

        // 未安装（以公钥是否存在为准；清 runtime 也能正确回到未安装态）
        if (!is_file(self::verifierPath())) {
            return self::finishFail($base, self::fail('NOT_INSTALLED'));
        }

        // S1 读取凭证
        $credPath = self::credentialPath();
        if (!is_file($credPath)) {
            return self::finishFail($base, self::fail('LICENSE_MISSING'));
        }
        $raw = self::readFile($credPath);
        if ($raw === null) {
            return self::finishFail($base, self::fail('LICENSE_READ_ERROR'));
        }

        // S2 结构校验
        $doc = json_decode($raw, true);
        if (!is_array($doc) || empty($doc['payload']) || !is_array($doc['payload']) || !isset($doc['signature'])) {
            return self::finishFail($base, self::fail('LICENSE_MALFORMED'));
        }
        $payload = $doc['payload'];
        foreach (self::CRED_FIELDS as $f) {
            if (!array_key_exists($f, $payload) || $payload[$f] === null || $payload[$f] === '') {
                return self::finishFail($base, self::fail('LICENSE_MALFORMED'));
            }
        }
        if ((int)$payload['version'] !== 1) {
            return self::finishFail($base, self::fail('LICENSE_MALFORMED'));
        }

        // S3 验签（公钥读不到 / 损坏统一归 LICENSE_READ_ERROR）
        $pubRaw = self::readFile(self::verifierPath());
        if ($pubRaw === null) {
            return self::finishFail($base, self::fail('LICENSE_READ_ERROR'));
        }
        $publicKey = openssl_pkey_get_public($pubRaw);
        if ($publicKey === false) {
            return self::finishFail($base, self::fail('LICENSE_READ_ERROR'));
        }
        $signing = self::signingString($payload, self::CRED_FIELDS);
        $sigBytes = base64_decode((string)$doc['signature'], true);
        if ($sigBytes === false) {
            return self::finishFail($base, self::fail('SIGNATURE_INVALID'));
        }
        $verify = @openssl_verify($signing, $sigBytes, $publicKey, OPENSSL_ALGO_SHA256);
        if ($verify !== 1) {
            return self::finishFail($base, self::fail('SIGNATURE_INVALID'));
        }

        // S4 域名比对（只信 HTTP_HOST）。凭证可绑定多个域名（英文逗号分隔），
        // 命中任意一个即视为已授权；规范化规则与签发端一致。
        $allowedDomains = self::normalizeHostList((string)$payload['domain']);
        $currentHost    = self::normalizeHost((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($allowedDomains === [] || $currentHost === '' || !in_array($currentHost, $allowedDomains, true)) {
            $r = self::fail('DOMAIN_MISMATCH');
            $r['domain'] = implode(',', $allowedDomains);
            return self::finishFail($base, $r);
        }
        // 派生种子沿用「凭证内的域名串」而不是当前命中域名：
        // 这样同一张凭证在任意授权域名下派生值一致，SealWitness/SealTrace 交叉复核也不会因域名不同而错位。
        $expectDomain = implode(',', $allowedDomains);

        // S5 到期判定（UTC：expires_at 当日 23:59:59 前有效）
        $expires = (string)$payload['expires_at'];
        $deadline = strtotime($expires . ' 23:59:59 UTC');
        if ($deadline === false || time() >= $deadline) {
            $r = self::fail('LICENSE_EXPIRED');
            $r['expires'] = $expires;
            return self::finishFail($base, $r);
        }

        // S5.5 在线吊销判定（读取本地缓存的最后已知控制状态，不联网）。
        // 控制状态由 EndpointLink 每 72 小时心跳拉取；管理端不可达时保持最后已知状态，
        // 未配置被控端的系统此处恒为 revoked=false，等价于原纯离线行为。
        $ctrl = EndpointLink::controlStatus();
        if (!empty($ctrl['revoked'])) {
            $r = self::fail('LICENSE_REVOKED');
            $r['domain']  = $expectDomain;
            $r['serial']  = (string)$payload['serial'];
            $r['expires'] = $expires;
            return self::finishFail($base, $r);
        }

        // S5.6 离线宽限判定（v2.99.23）：连续 7 天未能成功心跳 -> 视为未授权。
        // 未配置被控端的系统 stale 恒为 false，等价于原纯离线行为。
        if (!empty($ctrl['stale'])) {
            $r = self::fail('LICENSE_HEARTBEAT_STALE');
            $r['domain']  = $expectDomain;
            $r['serial']  = (string)$payload['serial'];
            $r['expires'] = $expires;
            return self::finishFail($base, $r);
        }

        // S6 派生运行时值
        $root = self::deriveRoot($sigBytes, $expectDomain, (string)$payload['serial']);
        $mark   = self::hkdfHex($root, self::INFO_MARK, 24);
        $thread = self::hkdfHex($root, self::INFO_THREAD, 32);

        $result = array_merge($base, [
            'ok'      => true,
            'code'    => 'OK',
            'message' => '',
            'domain'  => $expectDomain,
            'serial'  => (string)$payload['serial'],
            'expires' => $expires,
            'mark'    => $mark,
            'thread'  => $thread,
            'material' => [
                'sig_b64' => (string)$doc['signature'],
                'domain'  => $expectDomain,
                'serial'  => (string)$payload['serial'],
            ],
        ]);

        return self::$cached = $result;
    }

    /** 供消费点读取（语义化别名） */
    public static function result(): array
    {
        return self::assess();
    }

    /* ------------------------------------------------------------------ *
     * 供消费点独立复核：不信任 ok 布尔、不只验形态
     *
     * 每个消费点用凭证内容（签名摘要 + domain + serial）自行重算根密钥，
     * 再用各自不同的 info 交叉核对。写死常量 / 令 ok=true 的单方法篡改，
     * 无法同时满足两个不同 info 的派生关系，必有一处显式失败。
     * ------------------------------------------------------------------ */

    /**
     * 复核通道印记（D1）。
     * @return array{ok:bool, expected:string, actual:string}
     */
    public static function verifyMark(): array
    {
        $r = self::assess();
        $expected = '';
        if (!empty($r['material'])) {
            $root = self::deriveRoot(
                base64_decode((string)$r['material']['sig_b64'], true) ?: '',
                (string)$r['material']['domain'],
                (string)$r['material']['serial']
            );
            $expected = self::hkdfHex($root, self::INFO_MARK, 24);
        }
        return [
            'ok'       => $expected !== '' && $r['mark'] === $expected && strlen($r['mark']) === self::LEN_MARK,
            'expected' => $expected,
            'actual'   => (string)$r['mark'],
        ];
    }

    /**
     * 复核运行线程令牌（D2），使用与 D1 不同的 info。
     * @return array{ok:bool, expected:string, actual:string}
     */
    public static function verifyThread(): array
    {
        $r = self::assess();
        $expected = '';
        if (!empty($r['material'])) {
            $root = self::deriveRoot(
                base64_decode((string)$r['material']['sig_b64'], true) ?: '',
                (string)$r['material']['domain'],
                (string)$r['material']['serial']
            );
            $expected = self::hkdfHex($root, self::INFO_THREAD, 32);
        }
        return [
            'ok'       => $expected !== '' && $r['thread'] === $expected && strlen($r['thread']) === self::LEN_THREAD,
            'expected' => $expected,
            'actual'   => (string)$r['thread'],
        ];
    }

    /* ------------------------------------------------------------------ *
     * 凭证纯验签
     * ------------------------------------------------------------------ */

    /**
     * 仅供 setup.php 激活凭证时复用的纯验签（F6：不做域名比对 S4）。
     *
     * @return array{ok:bool, code:string, payload:array<string,mixed>|null}
     */
    public static function verifyCredentialOnly(string $credText): array
    {
        $pubRaw = self::readFile(self::verifierPath());
        if ($pubRaw === null) {
            return ['ok' => false, 'code' => 'LICENSE_READ_ERROR', 'payload' => null];
        }
        $publicKey = openssl_pkey_get_public($pubRaw);
        if ($publicKey === false) {
            return ['ok' => false, 'code' => 'LICENSE_READ_ERROR', 'payload' => null];
        }
        $doc = json_decode($credText, true);
        if (!is_array($doc) || empty($doc['payload']) || !is_array($doc['payload']) || !isset($doc['signature'])) {
            return ['ok' => false, 'code' => 'LICENSE_MALFORMED', 'payload' => null];
        }
        $payload = $doc['payload'];
        foreach (self::CRED_FIELDS as $f) {
            if (!array_key_exists($f, $payload) || $payload[$f] === null || $payload[$f] === '') {
                return ['ok' => false, 'code' => 'LICENSE_MALFORMED', 'payload' => null];
            }
        }
        if ((int)$payload['version'] !== 1) {
            return ['ok' => false, 'code' => 'LICENSE_MALFORMED', 'payload' => null];
        }
        $sigBytes = base64_decode((string)$doc['signature'], true);
        $signing  = self::signingString($payload, self::CRED_FIELDS);
        if ($sigBytes === false || @openssl_verify($signing, $sigBytes, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            return ['ok' => false, 'code' => 'SIGNATURE_INVALID', 'payload' => null];
        }
        return ['ok' => true, 'code' => 'OK', 'payload' => $payload];
    }

    /* ------------------------------------------------------------------ *
     * 请求分类（页面 / 接口）
     * ------------------------------------------------------------------ */

    public static function classifyRequest(): string
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $xrw = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));

        if (str_contains($uri, '/api/') || $uri === '/api' || str_starts_with(ltrim($uri, '/'), 'api/')) {
            return 'api';
        }
        if ($xrw === 'xmlhttprequest' || str_contains($accept, 'application/json')) {
            return 'api';
        }
        return 'page';
    }

    /* ------------------------------------------------------------------ *
     * 错误码 -> 固定中文（setup / FlowGate / SealTrace 共用一份）
     * ------------------------------------------------------------------ */

    public static function messageFor(string $code): string
    {
        $map = [
            'LICENSE_MISSING'    => '系统授权凭证缺失，请联系软件提供方完成授权',
            'LICENSE_READ_ERROR' => '授权凭证或公钥读取异常，请联系软件提供方重新获取授权文件',
            'LICENSE_MALFORMED'  => '授权凭证格式错误，请重新获取授权文件',
            'SIGNATURE_INVALID'  => '授权凭证暂未通过校验，请联系软件提供方重新获取授权文件',
            'DOMAIN_MISMATCH'    => '当前访问域名与授权绑定域名不一致，请联系软件提供方处理域名授权',
            'LICENSE_EXPIRED'    => '系统授权已到期，请联系软件提供方续期',
            'LICENSE_REVOKED'    => '系统授权已被软件提供方吊销，请联系软件提供方恢复授权',
            'LICENSE_HEARTBEAT_STALE' => '系统已连续 7 天未能与授权端完成心跳，按未授权处理；请检查授权端地址与网络，在授权管理页点「测试服务端授权链接」或「立即心跳」恢复',
            'NOT_INSTALLED'      => '系统尚未完成授权安装，请打开授权安装页面完成部署',
            'ACTIVATE_OK'        => '凭证提交成功，授权已生效',
            'ACTIVATE_REJECTED'  => '提交的凭证未通过校验，未写入任何文件',
            'INSTALL_OK'         => '授权安装完成',
            'INSTALL_LOCKED'     => '系统已完成授权安装，如需更换公钥请在授权管理页操作',
        ];
        return $map[$code] ?? '系统授权校验未通过，请联系软件提供方';
    }

    /* ------------------------------------------------------------------ *
     * 内部原语
     * ------------------------------------------------------------------ */

    private static function fail(string $code): array
    {
        return ['ok' => false, 'code' => $code, 'message' => self::messageFor($code)];
    }

    /**
     * 固化失败结论并缓存。失败即 ok=false，无任何旁路放行；
     * 是否终止请求由各消费点依据该结论 + 独立派生复核共同决定。
     */
    private static function finishFail(array $base, array $fail): array
    {
        return self::$cached = array_merge($base, $fail);
    }

    private static function readFile(string $path): ?string
    {
        try {
            if (!is_file($path)) {
                return null;
            }
            $content = @file_get_contents($path);
            return $content === false ? null : $content;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 签名串：固定字段按顺序用 \n 拼接值（与签发端完全一致）。
     */
    private static function signingString(array $payload, array $fields): string
    {
        $parts = [];
        foreach ($fields as $f) {
            $parts[] = (string)($payload[$f] ?? '');
        }
        return implode("\n", $parts);
    }

    /**
     * 主机名三件套规范化：小写、去端口、去 www 前缀。
     * 不采信 X-Forwarded-Host；不做通配 / 正则 / IDN。
     */
    public static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('#:\d+$#', '', $host);
        $host = rtrim($host, '.');
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        return $host;
    }

    /**
     * 规范化一组域名（英文/中文逗号、分号分隔），返回去重后的列表。
     * @return string[]
     */
    public static function normalizeHostList(string $raw): array
    {
        $parts = preg_split('/\s*[,，;；]\s*/u', trim($raw)) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $host = self::normalizeHost($part);
            if ($host !== '' && !in_array($host, $out, true)) {
                $out[] = $host;
            }
        }
        return $out;
    }

    /**
     * 根密钥：HKDF 输入为合法签名的原始字节（私钥产物，无法离线伪造），
     * 绑定 domain + serial。
     */
    private static function deriveRoot(string $signatureBytes, string $domain, string $serial): string
    {
        return hash_hkdf('sha256', $signatureBytes, 32, self::INFO_ROOT, $domain . '|' . $serial);
    }

    private static function hkdfHex(string $root, string $info, int $length): string
    {
        return bin2hex(hash_hkdf('sha256', $root, $length, $info, ''));
    }
}
