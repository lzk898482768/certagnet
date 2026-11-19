<?php
namespace app\service;

use app\model\SystemConfig;

/**
 * 附件本站下载链接的 HMAC 签名（v2.99.48 抽取）
 *
 * 背景：v2.46 的 WebDavService 内置了一套「路径 + 时间戳 + 密钥」的 HMAC 签名，
 * 用于免登录的 /api/attach/download 代理下载。v2.99.48 新增 S3 存储后，WebDAV 与 S3
 * 两类远端附件都要走同一个本站代理（凭据不出服务器、浏览器可直接 <img>/<a>），
 * 故把签名能力抽到本中立服务，密钥仍沿用 system_config.attach_sign_secret，
 * 保证升级前已签发、尚在有效期内的 WebDAV 链接继续可用。
 *
 * 安全约定：
 *   - 密钥只存服务端 system_config，首次使用自动生成 32 字节随机值；
 *   - 签名参数固定用 sig（框架把 GET 参数 s 当 pathinfo 兼容变量，带 s= 会 404）；
 *   - 链接默认 1 小时时效，hash_equals 防时序攻击；
 *   - 只对相对对象路径签名，调用方须先做穿越过滤。
 */
class AttachSignService
{
    /** 签名 URL 默认有效期（秒） */
    public const SIGN_TTL = 3600;

    private static ?string $secretCache = null;

    /**
     * 生成带签名的本站代理下载 URL（浏览器可直接使用）
     *
     * @param string $rel 已做穿越过滤的相对对象路径（不含存储前缀）
     */
    public static function signedUrl(string $rel, int $ttl = self::SIGN_TTL): string
    {
        $e = (string)time();
        return '/api/attach/download?p=' . rawurlencode(self::b64url($rel))
            . '&e=' . $e . '&sig=' . self::sign($rel, $e, $ttl);
    }

    /**
     * 校验签名：通过返回相对路径，失败返回 null
     */
    public static function verify(string $p, string $e, string $s): ?string
    {
        $bin = (string)base64_decode(strtr((string)$p, '-_', '+/'), true);
        if ($bin === '') {
            return null;
        }
        // 统一穿越过滤：折叠 .. / 空段 / 反斜杠，拒绝绝对路径
        $rel = self::sanitizeRel($bin);
        if ($rel === '') {
            return null;
        }
        $now = time();
        if (!ctype_digit($e) || abs($now - (int)$e) > self::SIGN_TTL + 60) {
            return null;
        }
        if (!hash_equals(self::sign($rel, $e, self::SIGN_TTL), (string)$s)) {
            return null;
        }
        return $rel;
    }

    /** 相对路径白名单：拒绝穿越与非法字符（与 WebDavService 同口径） */
    public static function sanitizeRel(string $rel): string
    {
        $rel = trim(str_replace('\\', '/', $rel), '/');
        if ($rel === '') {
            return '';
        }
        $parts = [];
        foreach (explode('/', $rel) as $seg) {
            $seg = trim($seg);
            if ($seg === '' || $seg === '.' || $seg === '..') {
                continue;
            }
            $parts[] = $seg;
        }
        return implode('/', $parts);
    }

    public static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function sign(string $rel, string $e, int $ttl): string
    {
        return hash_hmac('sha256', $rel . '|' . $e . '|' . $ttl, self::secret());
    }

    /** 签名密钥：首次调用自动生成并落库；直连 system_config 绕过进程级静态缓存 */
    private static function secret(): string
    {
        if (self::$secretCache !== null) {
            return self::$secretCache;
        }
        $saved = trim((string)sys_config('attach_sign_secret', ''));
        if ($saved !== '') {
            return self::$secretCache = $saved;
        }
        $fresh = bin2hex(random_bytes(32));
        try {
            $row = SystemConfig::where('config_key', 'attach_sign_secret')->find();
            if ($row) {
                if (trim((string)$row->config_value) !== '') {
                    return self::$secretCache = trim((string)$row->config_value);
                }
                $row->save(['config_value' => $fresh]);
            } else {
                SystemConfig::create([
                    'config_key'   => 'attach_sign_secret',
                    'config_value' => $fresh,
                    'config_name'  => '附件下载签名密钥',
                ]);
            }
            return self::$secretCache = $fresh;
        } catch (\Throwable $e) {
            // 落库失败时本进程内仍可用，下次请求重试
            return self::$secretCache = $fresh;
        }
    }
}
