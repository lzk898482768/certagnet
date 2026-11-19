<?php
namespace app\middleware;

use app\service\OperationLogService;
use think\exception\HttpResponseException;

/**
 * 操作审计兜底中间件（v2.97）
 *
 * 业务代码普遍通过 AdminController::log() 写详细日志；
 * 本中间件只负责两件事：
 *  1. 对没有显式写日志的写请求自动补一条，避免出现审计盲区；
 *  2. 为自动日志补充结果 / 状态码 / 耗时等上下文。
 *
 * 人工日志已记录时不会重复写入（由 OperationLogService 内部去重）。
 */
class OperationLog
{
    /** 不属于业务审计的控制器（登录、验证码、企微、Agent） */
    private const SKIP_CONTROLLERS = ['auth', 'captcha', 'systeminfo', 'wecom', 'agent', 'agentmcp'];

    /** 需要审计的只读动作（导出 / 下载 / 查看明文 / 下载模板） */
    private const SENSITIVE_READ_ACTIONS = ['export', 'download', 'reveal', 'template'];

    public function handle($request, \Closure $next)
    {
        $start = microtime(true);
        $response = null;
        $thrown = null;

        try {
            $response = $next($request);
        } catch (HttpResponseException $e) {
            // 鉴权 / 权限等直接返回的响应，也要记录失败审计
            $response = $e->getResponse();
            $thrown = $e;
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        try {
            [$status, $http, $code, $msg] = $this->responseMeta($response, $thrown);
            $meta = [
                'status'      => $status,
                'http_status' => $http,
                'code'        => $code,
                'result_msg'  => $msg,
                'duration_ms' => (int)round((microtime(true) - $start) * 1000),
            ];
            // 人工日志：回写耗时 / 结果码（业务写日志时响应尚未返回，拿不到这些信息）
            OperationLogService::finishRequest($request, $meta);
            // 无人工日志时由中间件兜底自动采集
            if ($this->shouldLog($request)) {
                OperationLogService::autoRecord($request, $meta);
            }
        } catch (\Throwable $e) {
            // 审计失败不影响业务响应
        }

        if ($thrown) {
            throw $thrown;
        }
        return $response;
    }

    /** 是否为需要审计的请求 */
    private function shouldLog($request): bool
    {
        $path = ltrim((string)$request->pathinfo(), '/');
        if (strpos($path, 'api/') !== 0) {
            return false;
        }
        // 显式路由下 controller()/action() 为空，统一从 pathinfo 反推
        [$controller, $action] = OperationLogService::parsePath($request);
        if (in_array($controller, self::SKIP_CONTROLLERS, true)) {
            return false;
        }

        $method = strtoupper((string)$request->method());
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return in_array($action, self::SENSITIVE_READ_ACTIONS, true)
                || $controller === 'attachdownload';
        }
        return true;
    }

    /**
     * 从响应中提取审计结果。
     *
     * @return array{0:int,1:int,2:int,3:string} [status, http_status, code, result_msg]
     */
    private function responseMeta($response, ?\Throwable $thrown): array
    {
        if ($response) {
            $http = (int)$response->getCode();
            $data = method_exists($response, 'getData') ? $response->getData() : null;
            $code = 0;
            $msg  = '';
            if (is_array($data)) {
                if (array_key_exists('code', $data)) {
                    $code = (int)$data['code'];
                }
                $msg = (string)($data['msg'] ?? '');
            }
            $ok = $http > 0 && $http < 400 && $code === 0;
            return [$ok ? 1 : 0, $http, $code, $msg];
        }
        $msg = $thrown ? (string)$thrown->getMessage() : '';
        return [0, 500, -1, $msg];
    }
}
