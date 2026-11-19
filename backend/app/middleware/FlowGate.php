<?php
namespace app\middleware;

use app\support\RuntimeSeal;
use app\support\EndpointLink;

/**
 * 全局门禁（中间件层，顺序在 SealWitness 之前）。
 *
 * 只消费 RuntimeSeal::assess() 的结论并做终止式输出，本身不含任何验签 /
 * 域名 / 派生逻辑（共享判定，不共享输出）。
 *
 * 本消费点依据 assess() 的 ok 决定是否终止；但 ok 不是唯一防线——其后的
 * SealWitness 与事件层 SealTrace 不信任 ok，各自用凭证 material 独立重算
 * D1/D2 交叉复核。即使本点被绕过（如注释本中间件、或篡改 assess 令 ok 恒真），
 * 后续消费点仍会终止请求。
 *
 * 终止方式必须是 header()+echo+exit：
 *  - 事件监听器 RouteLoaded 的返回值不会被 Http 当成响应；
 *  - 这里若 return Response，请求仍会继续，且会与事件路径二次输出。
 *
 * 删掉本中间件只去掉「跳转 / 403」，SealWitness 与 SealTrace 仍会拦截。
 */
class FlowGate
{
    public function handle($request, \Closure $next)
    {
        // 被控端在线联动：已配置身份时按 72 小时节流上报状态并拉取控制指令。
        // 先同步再判定，使本次拉取到的「吊销 / 恢复」能立即生效；
        // 同步失败（管理端不可达）静默降级，按最后已知状态继续运行。
        try {
            EndpointLink::sync();
        } catch (\Throwable $e) {
            // 心跳异常不得影响准入判定与业务请求
        }

        $r = RuntimeSeal::assess();

        if (!empty($r['ok'])) {
            return $next($request);
        }

        self::terminate($r);
    }

    /**
     * 终止式输出（页面 302 / 接口真实 HTTP 403 JSON）。
     * 不吐堆栈、路径、签名细节；APP_DEBUG 同样固定中文。
     */
    public static function terminate(array $r): void
    {
        $code = (string)($r['code'] ?? 'NOT_INSTALLED');
        $msg  = (string)($r['message'] ?? '') ?: RuntimeSeal::messageFor($code);
        $kind = (string)($r['kind'] ?? RuntimeSeal::classifyRequest());

        if (!headers_sent()) {
            if ($kind === 'api') {
                http_response_code(403);
                header('Content-Type: application/json; charset=UTF-8');
                header('Cache-Control: no-store, no-cache, must-revalidate');
                $body = json_encode([
                    'code' => 403,
                    'msg'  => $msg,
                    'data' => [
                        'license_code' => $code,
                        'auth_url'     => '/setup.php',
                    ],
                ], JSON_UNESCAPED_UNICODE);
                echo $body;
            } else {
                // 先落到未授权提示页（可读的中文原因 + 快捷入口），再由用户点进授权管理页
                $back = (string)($_SERVER['REQUEST_URI'] ?? '/');
                header(
                    'Location: /license-gate.php?code=' . urlencode($code) . '&back=' . urlencode($back),
                    true,
                    302
                );
            }
        }
        exit;
    }
}
