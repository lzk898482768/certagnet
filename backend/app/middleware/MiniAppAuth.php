<?php
namespace app\middleware;

use app\service\MiniAppService;
use Closure;
use think\Request;

/**
 * 微信小程序接入鉴权（v2.100）
 *
 * 规则：
 *  - 仅对携带 X-Client: miniapp 或 X-Miniapp-Token 的请求生效，H5 / PC / App 不受影响；
 *  - 后台已生成接入 Token 时，请求必须携带完全一致的 X-Miniapp-Token；
 *  - 后台尚未生成 Token（首次接入）时放行，保存小程序配置后会自动生成；
 *  - /api/miniapp/config 为引导接口，始终放行，便于小程序提示「需要填写 Token」。
 */
class MiniAppAuth
{
    /** 无需校验的引导接口 */
    private const EXEMPT = ['api/miniapp/config'];

    public function handle(Request $request, Closure $next)
    {
        $path = strtolower(trim((string)$request->pathinfo(), '/'));
        if ($path === '' || strpos($path, 'api/') !== 0) {
            return $next($request);
        }
        foreach (self::EXEMPT as $exempt) {
            if ($path === $exempt) {
                return $next($request);
            }
        }

        $client = strtolower(trim((string)$request->header('x-client', '')));
        $given  = trim((string)$request->header('x-miniapp-token', ''));
        $isMini = $client === 'miniapp' || $given !== '';
        if (!$isMini) {
            return $next($request);
        }

        try {
            $ok = MiniAppService::verifyToken($given);
        } catch (\Throwable $e) {
            // 配置读取异常时不阻断业务接口，避免数据库抖动导致小程序整体不可用
            $ok = true;
        }
        if (!$ok) {
            return json([
                'code' => 403,
                'msg'  => '小程序接入 Token 校验失败：请在「服务器设置」填写后台生成的接入 Token',
                'data' => null,
            ], 403);
        }
        return $next($request);
    }
}
