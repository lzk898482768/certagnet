<?php
namespace app\middleware;

use app\common\AuthCookie;

/**
 * 令牌 Cookie 落盘中间件（v2.99.1 / INFO-02）
 *
 * 登录 / 退出会在业务层调用 AuthCookie::queue() / clear() 登记动作，
 * 这里统一把登记结果写入响应 Set-Cookie。放在全局中间件的靠后位置，
 * 保证能包住控制器返回（包括异常处理器给出的响应）。
 */
class AuthTicket
{
    public function handle($request, \Closure $next)
    {
        $response = $next($request);
        try {
            return AuthCookie::apply($response, $request);
        } catch (\Throwable $e) {
            return $response;
        }
    }
}
