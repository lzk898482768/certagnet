<?php
namespace app\middleware;

use app\service\DeleteOtpService;

/**
 * 删除操作 OTP 闸门中间件（v2.99.66）
 *
 * 注册在 admin 路由组、permauth 之后：
 *   - 先由 auth / datascope / adminauth / permauth 完成登录与功能权限校验；
 *   - 本中间件只处理 POST，且仅拦截「删除 / 清空 / 吊销归属」类动作；
 *   - 无权限的账号会先被 PermAuth 以 403 拦截，不会触发 OTP 弹窗；
 *   - 已通过动态码验证的请求必须携带一次性 otp_ticket，校验通过即销毁后放行。
 *
 * 未通过时返回业务码 428（HTTP 200，沿用系统统一响应约定），
 * data 中带 need_delete_otp / need_bind / action / action_label，
 * 前端据此弹窗完成验证后自动重试原请求。
 */
class DeleteOtp
{
    public function handle($request, \Closure $next)
    {
        // 删除类接口均为 POST；GET 列表不受影响
        if (strtoupper((string)$request->method()) !== 'POST') {
            return $next($request);
        }

        // pathinfo 形如 api/admin/staff/delete，归一化为 staff/delete
        $action = DeleteOtpService::normalizePath((string)$request->pathinfo());
        if (!DeleteOtpService::isProtectedAction($action)) {
            return $next($request);
        }

        $userId = (int)($request->userId ?? 0);
        if ($userId <= 0) {
            // 登录态异常交给 Auth / AdminAuth 处理，本中间件不改变鉴权语义
            return $next($request);
        }

        $raw = $request->param('otp_ticket', '');
        $ticket = is_string($raw) ? trim($raw) : '';
        if ($ticket !== '' && DeleteOtpService::consume($userId, $action, $ticket)) {
            return $next($request);
        }

        $needBind = DeleteOtpService::needBind($userId);
        if ($ticket === '' && $needBind) {
            // 只是点了删除、还没进入动态码环节：记一条「未绑定被拦截」，与导出闸门口径一致
            DeleteOtpService::auditDenied($userId, $action, '未绑定动态验证码，拦截删除操作');
        }

        if ($needBind) {
            $msg = '删除操作需先绑定动态验证码';
        } elseif ($ticket !== '') {
            $msg = '删除凭证无效或已过期，请重新验证';
        } else {
            $msg = '删除操作需动态验证码二次确认';
        }

        return json(r_error($msg, 428, [
            'need_delete_otp' => true,
            'need_bind'       => $needBind,
            'action'          => $action,
            'action_label'    => DeleteOtpService::actionLabel($action),
        ]));
    }
}
