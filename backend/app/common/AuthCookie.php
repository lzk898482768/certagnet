<?php
namespace app\common;

use think\Request;
use think\Response;

/**
 * 登录令牌 Cookie 载体（v2.99.1 / INFO-02）
 *
 * 背景：此前 token 由前端存 localStorage，一旦出现 XSS 即可被直接读出复用。
 * 现在登录成功后由服务端写入 HttpOnly + SameSite=Lax 的 Cookie，JS 无法读取；
 * 前端只保留「是否已登录」的非敏感标记用于路由跳转。
 *
 * 兼容性：
 *  - Authorization: Bearer 仍然优先（移动端 H5、Agent、第三方集成不受影响）；
 *  - 仅当请求没有 Authorization 头时才回退读取 Cookie，便于 PC 端免手动带 token。
 *
 * CSRF：
 *  - SameSite=Lax 已阻断绝大多数跨站 POST；
 *  - 再做一层 Origin/Referer 校验：仅对「用 Cookie 认证的写请求」生效，
 *    浏览器同源请求必然带 Origin，跨站伪造会被拦下。
 */
class AuthCookie
{
    public const NAME = 'cert_auth_token';

    /** 待写入响应头的令牌（null=无操作，''=清除） */
    private static ?string $pending = null;

    private static int $pendingTtl = 0;

    /** 登录成功时登记待写入 Cookie 的令牌 */
    public static function queue(string $token, int $ttl = 0): void
    {
        self::$pending    = $token;
        self::$pendingTtl = $ttl;
    }

    /** 退出登录 / 令牌失效时清除 Cookie */
    public static function clear(): void
    {
        self::$pending    = '';
        self::$pendingTtl = 0;
    }

    /** 读取请求携带的 Cookie 令牌（不存在返回空串） */
    public static function read(Request $request): string
    {
        $v = $request->cookie(self::NAME, '');
        return is_string($v) ? trim($v) : '';
    }

    /** 把登记的令牌写入响应 Cookie */
    public static function apply(Response $response, Request $request): Response
    {
        if (self::$pending === null) {
            return $response;
        }
        $token = self::$pending;
        self::$pending = null;

        $secure = $request->isSsl()
            || strtolower((string)($request->server('HTTP_X_FORWARDED_PROTO') ?? '')) === 'https';
        $option = [
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'lax',
            'secure'   => $secure,
            'expire'   => $token === '' ? -1 : max(60, self::$pendingTtl > 0 ? self::$pendingTtl : 3600),
        ];
        return $response->cookie(self::NAME, $token, $option);
    }

    /**
     * 校验「Cookie 认证的写请求」来源是否同源（防 CSRF）。
     *
     * 只对没有 Authorization 头的非安全方法生效：脚本 / 移动端 / Agent 调用
     * 通常不带 Cookie，因此不受影响。
     */
    public static function csrfOk(Request $request): bool
    {
        if (in_array(strtoupper((string)$request->method()), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }
        $auth = (string)$request->header('authorization', '');
        if ($auth !== '') {
            return true; // 显式 Bearer 认证不依赖 Cookie，天然免疫 CSRF
        }
        if (self::read($request) === '') {
            return true; // 未使用 Cookie 认证
        }

        $selfHost = strtolower((string)$request->host(true)); // 含端口
        foreach (['origin', 'referer'] as $key) {
            $raw = (string)$request->header($key, '');
            if ($raw === '') {
                continue;
            }
            $host = strtolower((string)parse_url($raw, PHP_URL_HOST));
            $port = parse_url($raw, PHP_URL_PORT);
            if ($host === '') {
                return false;
            }
            $hostWithPort = $port ? ($host . ':' . $port) : $host;
            if ($hostWithPort === $selfHost || $host === $selfHost) {
                return true;
            }
            return false; // 有来源但不同源 → 拒绝
        }
        // 浏览器同源写请求一定带 Origin/Referer；两者都缺失视为非浏览器调用，放行。
        return true;
    }
}
