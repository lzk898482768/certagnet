<?php
namespace app\support;

/**
 * 路由加载后的独立见证（事件层消费点，注册于 app/event.php 的 RouteLoaded）。
 *
 * 存在意义：与中间件注册表（app/middleware.php）是两套不同机制。即便有人
 * 注释掉 middleware.php 里的 FlowGate / SealWitness 两行，每个动态请求在
 * 路由加载后仍会触发 RouteLoaded，本类照常拦截，系统不会「安静全功能」。
 *
 * 实测结论（见验收文档）：
 *  - RouteLoaded 在 /api/ 与页面导航两类请求都会触发；
 *  - 监听器内可通过 request 判类型；CLI（think 命令）不触发任何 HTTP 事件。
 *
 * 本消费点不信任 assess() 的 ok：除了未授权独立终止，还会用凭证 material
 * 独立重算 D1/D2 交叉复核；篡改 assess 令 ok 恒真在此仍被拦下。
 *
 * 关键：Event::trigger 的返回值不会被 Http 当成响应，return Response 会让
 * 请求继续进控制器。因此这里与 FlowGate 一样采用 header()+echo+exit 终止。
 * 正常链路下 FlowGate 已先终止，RouteLoaded 根本不会触发，两处互斥、不重复输出。
 */
class SealTrace
{
    public function handle($event = null): void
    {
        $r = RuntimeSeal::assess();

        if (empty($r['ok'])) {
            // 中间件被摘除时由这里独立终止（共享 assess 判定，输出各自实现）
            $this->deny($r);
        }

        // 独立交叉复核（用与 SealWitness 相同的两个不同 info），
        // 伪造单个派生值 / 写死常量在此同样过不了。
        $mark   = RuntimeSeal::verifyMark();
        $thread = RuntimeSeal::verifyThread();
        if (empty($mark['ok']) || empty($thread['ok'])) {
            $this->abort();
        }
    }

    private function deny(array $r): void
    {
        $code = (string)($r['code'] ?? 'NOT_INSTALLED');
        $msg  = (string)($r['message'] ?? '') ?: RuntimeSeal::messageFor($code);
        $kind = (string)($r['kind'] ?? RuntimeSeal::classifyRequest());

        if (!headers_sent()) {
            if ($kind === 'api') {
                http_response_code(403);
                header('Content-Type: application/json; charset=UTF-8');
                header('Cache-Control: no-store, no-cache, must-revalidate');
                echo json_encode([
                    'code' => 403,
                    'msg'  => $msg,
                    'data' => [
                        'license_code' => $code,
                        'auth_url'     => '/setup.php',
                    ],
                ], JSON_UNESCAPED_UNICODE);
            } else {
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

    /**
     * 派生复核失败：内部语义代号不外泄，对外统一映射 SIGNATURE_INVALID + 固定中文。
     */
    private function abort(): void
    {
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            echo json_encode([
                'code' => 403,
                'msg'  => RuntimeSeal::messageFor('SIGNATURE_INVALID'),
                'data' => ['license_code' => 'SIGNATURE_INVALID'],
            ], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
}
