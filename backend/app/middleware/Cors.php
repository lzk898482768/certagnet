<?php
namespace app\middleware;

use think\facade\Cache;
use think\Response;

/**
 * 跨域中间件（全局）
 */
class Cors
{
    public function handle($request, \Closure $next)
    {
        $origins = (array)\think\facade\Config::get('security.cors_allowed_origins', ['*']);
        $requestOrigin = $request->header('origin', '');

        // 解析允许源：'*' 表示放行所有；否则仅放行配置列表（并支持带凭据）
        if (in_array('*', $origins, true)) {
            $allowOrigin = '*';
        } elseif ($requestOrigin && in_array($requestOrigin, $origins, true)) {
            $allowOrigin = $requestOrigin;
        } else {
            $allowOrigin = '';
        }

        $headers = [
            'Access-Control-Allow-Origin'      => $allowOrigin,
            'Access-Control-Allow-Methods'     => 'GET,POST,PUT,DELETE,OPTIONS',
            'Access-Control-Allow-Headers'     => 'Authorization,Content-Type,X-Requested-With,X-API-Key',
            'Access-Control-Max-Age'           => '600',
            // v2.82：Agent 接口的版本 / 限流响应头需要暴露给浏览器端 Agent
            'Access-Control-Expose-Headers'    => 'X-Agent-Api-Version,X-RateLimit-Limit,X-RateLimit-Remaining',
        ];
        if ($allowOrigin && $allowOrigin !== '*') {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }
        // 基础安全响应头
        $headers['X-Content-Type-Options'] = 'nosniff';
        $headers['X-Frame-Options']        = 'DENY';
        // v2.96：涉及公民信息，接口响应禁止浏览器/代理缓存，并限制 Referer 与设备权限
        $headers['Cache-Control']          = 'no-store, no-cache, must-revalidate';
        $headers['Pragma']                 = 'no-cache';
        $headers['Referrer-Policy']        = 'no-referrer';
        $headers['Permissions-Policy']     = 'geolocation=(), microphone=(), camera=()';

        if ($request->method(true) === 'OPTIONS') {
            return Response::create('', 'json', 204)->header($headers);
        }

        $response = $next($request);
        return $response->header($headers);
    }
}
