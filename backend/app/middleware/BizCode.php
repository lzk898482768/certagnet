<?php
namespace app\middleware;

/**
 * 业务码响应头（v2.99.1 / BUG-07）
 *
 * 背景：系统沿用「HTTP 200 + body.code」的响应约定，控制器直接 `return json()`
 * 的成功/失败响应不经过 ExceptionHandle，因此只有异常路径会带 X-Biz-Code。
 * 这里统一补一次，让网关 / APM 能对所有响应按业务码统计错误率，
 * 而无需解析响应体。
 *
 * 放在全局中间件最外层：可包住后续所有中间件与控制器返回的响应。
 * 只处理「数组且含 code 字段」的响应，文件流等不受影响。
 */
class BizCode
{
    public function handle($request, \Closure $next)
    {
        $response = $next($request);
        try {
            $data = $response->getData();
            if (is_array($data) && array_key_exists('code', $data)) {
                $response->header(['X-Biz-Code' => (string)$data['code']]);
            }
        } catch (\Throwable $e) {
            // 响应头增强失败不影响主流程
        }
        return $response;
    }
}
