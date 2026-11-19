<?php
namespace app\middleware;

use app\support\RuntimeSeal;

/**
 * 派生值见证（中间件层，顺序在 FlowGate 之后）。
 *
 * 与 FlowGate 相互独立：
 *  - 不信任 assess() 的 ok 布尔，也不只校验形态；
 *  - 用凭证内容（签名摘要 + domain + serial）独立重算，分别交叉核对
 *    通道印记 D1（info=channel-mark）与线程令牌 D2（info=runtime-thread）；
 *  - 任一不符即终止式失败，无 fallback、不静默、不 catch 后继续。
 *
 * 写死常量 / 令 ok=true 的单方法篡改无法同时满足两个不同 info 的派生关系，
 * 必有一处失败。删掉本类或注释本中间件，仍有事件层 SealTrace 拦截。
 */
class SealWitness
{
    public function handle($request, \Closure $next)
    {
        $r = RuntimeSeal::assess();
        if (empty($r['ok'])) {
            // 未授权交给 FlowGate 处理（正常顺序下不会走到这里）
            FlowGate::terminate($r);
        }

        // 独立交叉复核：不信任上面的 ok，用凭证 material 各自重算 D1/D2。
        $mark   = RuntimeSeal::verifyMark();
        $thread = RuntimeSeal::verifyThread();

        if (empty($mark['ok']) || empty($thread['ok'])) {
            self::abort();
        }

        return $next($request);
    }

    /**
     * 派生复核失败的终止输出。
     *
     * 该失败仅源于内部派生断言被破坏（代码被篡改 / 内存异常），不属于约定的
     * 11 个对外错误码：内部语义代号不外泄，对外统一映射为 SIGNATURE_INVALID
     * 并输出固定中文，不暴露堆栈 / 路径 / 签名细节，APP_DEBUG 下同样收敛。
     */
    public static function abort(): void
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
