<?php
namespace app;

use think\exception\Handle;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\exception\ValidateException;
use think\Response;
use app\common\Response as R;
use Throwable;

/**
 * 全局异常处理：统一返回 JSON
 *
 * v2.99.1（BUG-07）：业务错误此前一律 HTTP 200，仅靠 body.code 区分，导致
 * Nginx/网关/APM 无法按 HTTP 状态码统计错误率，第三方集成也容易误判成功。
 * 现在：
 *  - 系统级异常（未捕获的 500）返回真实 HTTP 500；
 *  - 参数校验 / HttpException 返回对应 HTTP 状态码（400 / 4xx）；
 *  - 所有错误响应额外带 X-Biz-Code 响应头（值等于 body.code），
 *    不改变既有「HTTP 200 + code」的控制器返回约定，监控可按需采集。
 */
class ExceptionHandle extends Handle
{
    public function render($request, Throwable $e): Response
    {
        // 中间件/控制器通过 HttpResponseException 主动返回的响应（401 未登录、
        // 403 权限不足等）必须原样透传。否则会被下面的通用 500 分支覆盖成
        // HTTP 200 + code:500 + 空 msg，前端只能提示“请求失败”，且登录失效
        // 无法被识别。该分支必须置于所有异常判断之前。
        if ($e instanceof HttpResponseException) {
            return $this->withBizCode($e->getResponse());
        }
        if ($e instanceof ValidateException) {
            return $this->withBizCode(json(R::error($e->getMessage(), 400)));
        }
        if ($e instanceof HttpException) {
            $code = $e->getStatusCode();
            return $this->withBizCode(json(R::error($e->getMessage() ?: '请求错误', $code), $code));
        }
        // v2.38：本系统强依赖 Redis（config/cache.php default=redis），
        // Redis 不可用属于"部署未就绪"而非业务错误，必须给出可执行的运维提示，
        // 否则用户只会看到 tcp://... 连接失败这类天书。
        if ($this->isRedisUnavailable($e)) {
            $msg = '缓存服务（Redis）不可用，系统无法运行。请确认 Redis 已启动，'
                 . '并在 .env 中核对 REDIS_HOST / REDIS_PORT / REDIS_PASSWORD / REDIS_DB；'
                 . '可用 `php think redis:check` 自检。';
            // 追加底层原因便于排障，但不作为主提示
            return $this->withBizCode(json(R::error($msg . '（底层原因：' . $e->getMessage() . '）', 503), 503));
        }
        // 开发环境展示真实错误，生产环境返回通用错误。
        // v2.99.1：默认值改为 false——配置缺失时不得把内部错误信息暴露给前端。
        if (env('app_debug', false)) {
            return $this->withBizCode(json(R::error($e->getMessage(), 500), 500));
        }
        return $this->withBizCode(json(R::error('系统错误', 500), 500));
    }

    /**
     * 给响应补 X-Biz-Code 头（值取 body.code），并把「鉴权/权限/系统错误」
     * 对齐为真实 HTTP 状态码，便于网关与 APM 统计。
     *
     * 只处理 401 / 403 / 500 三种：其余业务错误（400、404、422 等由控制器直接
     * return json 的响应）不受此处影响，保持既有「HTTP 200 + code」约定，
     * 避免前端大面积改造。body 非「code 结构」时（如文件流）不处理。
     */
    protected function withBizCode(Response $resp): Response
    {
        try {
            $data = $resp->getData();
            if (!is_array($data) || !array_key_exists('code', $data)) {
                return $resp;
            }
            $bizCode = (int)$data['code'];
            $resp->header(['X-Biz-Code' => (string)$bizCode]);
            // 仅在尚未设置具体 HTTP 状态（即 200）时对齐，避免覆盖框架已给的状态
            if (in_array($bizCode, [401, 403, 500], true) && (int)$resp->getCode() === 200) {
                $resp->code($bizCode);
            }
        } catch (Throwable $e) {
            // 响应头增强失败不影响主流程
        }
        return $resp;
    }

    /**
     * 判定异常是否源自 Redis 不可用
     *
     * 覆盖 predis（ConnectionException）与 phpredis（RedisException）两条路径，
     * 均以异常类 + 连接类关键字判断，不依赖具体驱动。
     */
    protected function isRedisUnavailable(Throwable $e): bool
    {
        $class = get_class($e);
        if (str_contains($class, 'Predis\\Connection\\')
            || str_contains($class, 'RedisException')
            || str_contains($class, 'BadFunctionCallException')) {
            return true;
        }
        $msg = $e->getMessage();
        // predis 认证失败与连接失败文案不同，但都属 Redis 侧问题
        return str_contains($msg, 'tcp://')
            || str_contains($msg, 'Connection refused')
            || str_contains($msg, 'NOAUTH')
            || str_contains($msg, 'WRONGPASS')
            || str_contains($msg, 'Redis 连接失败');
    }
}
