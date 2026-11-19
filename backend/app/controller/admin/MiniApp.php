<?php
namespace app\controller\admin;

use app\service\MiniAppService;

/**
 * 微信小程序接入配置（后台，v2.100）
 *
 *  - GET  /admin/miniapp/config      读取配置（含接入 Token 完整值，仅超管）
 *  - POST /admin/miniapp/save        保存配置
 *  - POST /admin/miniapp/token       重置接入 Token
 *  - POST /admin/miniapp/audit-mode  一键套用 / 取消审核模式
 */
class MiniApp extends AdminController
{
    public function config()
    {
        $this->requireAdmin();
        try {
            return json(r_success(MiniAppService::adminConfig()));
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage() ?: '读取小程序配置失败'));
        }
    }

    public function save()
    {
        $this->requireAdmin();
        $post = $this->request->post();
        // 兼容前端整体提交 { enabled, appid, secret, menus: {...} }
        $menus = $this->request->post('menus/a', null);
        if ($menus === null && isset($post['menus']) && is_array($post['menus'])) {
            $menus = $post['menus'];
        }
        $data = [];
        foreach (['enabled', 'login_enabled', 'audit_mode'] as $flag) {
            if (array_key_exists($flag, $post)) {
                $data[$flag] = (int)$post[$flag] === 1 || $post[$flag] === true || $post[$flag] === '1';
            }
        }
        if (array_key_exists('appid', $post)) {
            $data['appid'] = (string)$post['appid'];
        }
        if (array_key_exists('secret', $post)) {
            $data['secret'] = (string)$post['secret'];
        }
        if (is_array($menus)) {
            $data['menus'] = $menus;
        }
        try {
            $r = MiniAppService::save($data);
            $this->log('小程序', '保存接入配置', (string)($data['appid'] ?? ''));
            return json(r_success($r, '保存成功'));
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage() ?: '保存失败', 400));
        }
    }

    public function token()
    {
        $this->requireAdmin();
        try {
            $r = MiniAppService::regenerateToken();
            $this->log('小程序', '重置接入 Token', '');
            return json(r_success($r, '已重新生成，请同步更新小程序服务器设置中的 Token'));
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage() ?: '重置失败'));
        }
    }

    public function auditMode()
    {
        $this->requireAdmin();
        $on = (int)$this->request->post('enabled', 0) === 1;
        try {
            // v2.99.26：审核模式只切开关，敏感项的隐藏在 MiniAppService::effectiveMenus()
            // 读取时叠加，不再改写统一功能导航 mobile_navs（保留管理员的显隐/改名/排序）。
            $r = MiniAppService::save([
                'audit_mode' => $on,
            ]);
            $this->log('小程序', $on ? '启用审核模式' : '取消审核模式', '');
            return json(r_success($r, $on ? '已启用审核模式，敏感模块已隐藏' : '已取消审核模式'));
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage() ?: '操作失败'));
        }
    }
}
