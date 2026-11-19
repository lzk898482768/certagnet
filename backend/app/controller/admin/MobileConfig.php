<?php
namespace app\controller\admin;

use app\service\MobileConfigService;

/**
 * 移动端配置（后台，v2.99.24）
 *
 *  - GET  /admin/mobile-config       读取配置（显示名称 / 功能导航 / 底部菜单 / 登录渠道 /
 *                                     版本更新 / uni-push / 小程序订阅消息模板）
 *  - POST /admin/mobile-config/save  保存配置
 */
class MobileConfig extends AdminController
{
    public function get()
    {
        $this->requireAdmin();
        try {
            return json(r_success(MobileConfigService::adminConfig()));
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage() ?: '读取移动端配置失败'));
        }
    }

    public function save()
    {
        $this->requireAdmin();
        $post = $this->request->post();

        // 兼容前端整体提交 { navs: {...}, tabbars: {...}, subscribe_templates: {...} }
        $data = [];
        if (array_key_exists('display_name', $post)) {
            $data['display_name'] = (string)$post['display_name'];
        }
        foreach (['h5_wecom', 'app_wecom', 'unipush_enabled', 'subscribe_enabled', 'app_force_update'] as $flag) {
            if (array_key_exists($flag, $post)) {
                $data[$flag] = (int)$post[$flag] === 1 || $post[$flag] === true || $post[$flag] === '1';
            }
        }
        if (isset($post['navs']) && is_array($post['navs'])) {
            $data['navs'] = $post['navs'];
        }
        if (isset($post['tabbars']) && is_array($post['tabbars'])) {
            $data['tabbars'] = $post['tabbars'];
        }
        if (isset($post['subscribe_templates']) && is_array($post['subscribe_templates'])) {
            $data['subscribe_templates'] = $post['subscribe_templates'];
        }
        foreach (['app_version', 'app_version_name', 'app_update_desc', 'app_download_url'] as $k) {
            if (array_key_exists($k, $post)) {
                $data[$k] = (string)$post[$k];
            }
        }

        try {
            $r = MobileConfigService::save($data);
            $this->log('移动端配置', '保存配置', (string)($data['display_name'] ?? ''));
            return json(r_success($r, '保存成功'));
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage() ?: '保存失败', 400));
        }
    }
}