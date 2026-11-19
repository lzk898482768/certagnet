<?php
namespace app\controller\admin;

use app\service\DeleteOtpService;

/**
 * 删除操作 OTP 闸门（v2.99.66）
 *
 * 两段式中的第二段：前端收到 428（need_delete_otp）后弹动态码框，
 * 调用本接口用 6 位动态码换取一次性删除凭证，再带凭证重试原删除请求。
 *
 * 仅做「是否允许执行删除」的二次鉴别，登录态由 Auth 中间件保证；
 * 具体数据范围与业务校验仍由各删除控制器原有的 scope/can 权限控制，
 * 本控制器不豁免任何权限。超级管理员同样必须通过 TOTP。
 */
class DeleteOtp extends AdminController
{
    /**
     * 校验动态码并签发一次性删除 ticket。
     * POST action, code
     */
    public function verify()
    {
        $userId = (int)$this->request->userId;
        $action = (string)$this->request->post('action', '');
        $code   = (string)$this->request->post('code', '');
        $r = DeleteOtpService::verify($userId, $action, $code);
        if (empty($r['ok'])) {
            return json(r_error($r['msg'] ?? '验证失败', 400, [
                'need_bind' => !empty($r['need_bind']),
            ]));
        }
        return json(r_success([
            'ticket'     => $r['ticket'],
            'expires_in' => DeleteOtpService::TICKET_TTL,
        ], '验证通过'));
    }
}
