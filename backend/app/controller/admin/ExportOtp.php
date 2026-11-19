<?php
namespace app\controller\admin;

use app\service\ExportOtpService;

/**
 * 敏感信息导出 OTP 闸门（v2.99.49）
 *
 * 两段式：前端点导出 → prepare 预检（未绑定 TOTP 直接拦截引导绑定）→
 * verify 校验 6 位动态码换取一次性 ticket → 带 ticket 调原导出接口下载。
 *
 * 仅做「是否允许导出」的二次鉴别，登录态由 Auth 中间件保证；
 * 具体数据范围仍由各导出控制器原有的 scope/can 权限控制，本控制器不豁免任何权限。
 * 超级管理员同样必须通过 TOTP，闸门不可被高权限绕过。
 */
class ExportOtp extends AdminController
{
    /**
     * 导出预检：判断当前账号需要「绑定 TOTP」还是「输入动态码」。
     * POST module=staff|credential_vault|certificate
     */
    public function prepare()
    {
        $userId = (int)$this->request->userId;
        $module = (string)$this->request->post('module', '');
        $r = ExportOtpService::prepare($userId, $module);
        return json(r_success($r, $r['need_bind'] ? '请先绑定动态验证码' : '请完成二次验证'));
    }

    /**
     * 校验动态码并签发一次性导出 ticket。
     * POST module, code
     */
    public function verify()
    {
        $userId = (int)$this->request->userId;
        $module = (string)$this->request->post('module', '');
        $code   = (string)$this->request->post('code', '');
        $r = ExportOtpService::verify($userId, $module, $code);
        if (empty($r['ok'])) {
            return json(r_error($r['msg'] ?? '验证失败', 400));
        }
        return json(r_success(['ticket' => $r['ticket']], '验证通过'));
    }
}
