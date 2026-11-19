<?php
namespace app\controller\admin;

use app\service\MiniAppService;
use app\service\MobileConfigService;

/**
 * 移动端管理（后台，v2.99.26）
 *
 * 合并原「小程序接入」(菜单 75) 与「移动端配置」(菜单 76) 的统一入口：
 *  - 小程序接入：启用 / 一键登录 / AppID / Secret / 接入 Token / 审核模式 / 绑定数
 *  - 移动端配置：显示名称 / 登录渠道 / 底部 tabBar / 版本更新 / uni-push / 订阅消息
 *  - 功能导航：H5 / App / 小程序三端共用一份显隐 / 改名 / 排序（mobile_navs 为唯一数据源）
 *
 *  - GET  /admin/mobile-manage        读取聚合配置
 *  - POST /admin/mobile-manage/save   保存（小程序字段 + 移动端字段分别落库）
 *  - POST /admin/mobile-manage/token  重置小程序接入 Token
 *  - POST /admin/mobile-manage/audit-mode  切换审核模式（只切开关，敏感项读取时强制隐藏）
 *
 * 旧的 /admin/miniapp/* 与 /admin/mobile-config/* 路由保留，向后兼容，不删除。
 */
class MobileManage extends AdminController
{
    /** 聚合读取：小程序接入 + 移动端配置（含统一功能导航目录） */
    public function get()
    {
        $this->requireAdmin();
        try {
            $mini = MiniAppService::adminConfig();
            $mobile = MobileConfigService::adminConfig();
            return json(r_success(array_merge($mobile, [
                // 小程序接入字段（加 mini_ 前缀，避免与移动端字段冲突）
                'mini_enabled'      => $mini['enabled'],
                'mini_login_enabled'=> $mini['login_enabled'],
                'mini_audit_mode'   => $mini['audit_mode'],
                'mini_appid'        => $mini['appid'],
                'mini_secret_set'   => $mini['secret_set'],
                'mini_token'        => $mini['token'],
                'mini_token_set'    => $mini['token_set'],
                'mini_bound_count'  => $mini['bound_count'],
                // 审核敏感项 id，前端用于在导航表中标记并在审核模式下禁用勾选
                'sensitive_ids'     => $mini['sensitive_ids'],
            ])));
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage() ?: '读取移动端管理配置失败'));
        }
    }

    /** 聚合保存：按字段归属分别写入两个服务，功能导航统一走 MobileConfigService */
    public function save()
    {
        $this->requireAdmin();
        $post = $this->request->post();

        try {
            // ① 小程序接入字段（启用 / 一键登录 / 审核模式 / AppID / Secret）
            $miniData = [];
            foreach (['enabled', 'login_enabled', 'audit_mode'] as $flag) {
                $key = 'mini_' . $flag;
                if (array_key_exists($key, $post)) {
                    $miniData[$flag] = (int)$post[$key] === 1 || $post[$key] === true || $post[$key] === '1';
                }
            }
            if (array_key_exists('mini_appid', $post)) {
                $miniData['appid'] = (string)$post['mini_appid'];
            }
            if (array_key_exists('mini_secret', $post) && trim((string)$post['mini_secret']) !== '') {
                $miniData['secret'] = (string)$post['mini_secret'];
            }
            if ($miniData) {
                MiniAppService::save($miniData);
            }

            // ② 移动端配置字段（功能导航 navs 为三端统一数据源，含显隐/改名/排序）
            $mobileData = [];
            if (array_key_exists('display_name', $post)) {
                $mobileData['display_name'] = (string)$post['display_name'];
            }
            foreach (['h5_wecom', 'app_wecom', 'unipush_enabled', 'subscribe_enabled', 'app_force_update'] as $flag) {
                if (array_key_exists($flag, $post)) {
                    $mobileData[$flag] = (int)$post[$flag] === 1 || $post[$flag] === true || $post[$flag] === '1';
                }
            }
            if (isset($post['navs']) && is_array($post['navs'])) {
                $mobileData['navs'] = $post['navs'];
            }
            if (isset($post['tabbars']) && is_array($post['tabbars'])) {
                $mobileData['tabbars'] = $post['tabbars'];
            }
            if (isset($post['subscribe_templates']) && is_array($post['subscribe_templates'])) {
                $mobileData['subscribe_templates'] = $post['subscribe_templates'];
            }
            foreach (['app_version', 'app_version_name', 'app_update_desc', 'app_download_url'] as $k) {
                if (array_key_exists($k, $post)) {
                    $mobileData[$k] = (string)$post[$k];
                }
            }
            if ($mobileData) {
                MobileConfigService::save($mobileData);
            }

            $this->log('移动端管理', '保存配置', (string)($post['display_name'] ?? $post['mini_appid'] ?? ''));
            return json(r_success($this->aggregate(), '保存成功'));
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage() ?: '保存失败', 400));
        }
    }

    /** 重置小程序接入 Token */
    public function token()
    {
        $this->requireAdmin();
        try {
            MiniAppService::regenerateToken();
            $this->log('移动端管理', '重置小程序接入 Token', '');
            return json(r_success($this->aggregate(), '已重新生成，请同步更新小程序服务器设置中的 Token'));
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage() ?: '重置失败'));
        }
    }

    /** 切换审核模式：只切开关，敏感项在小程序读取配置时由 effectiveMenus() 强制隐藏 */
    public function auditMode()
    {
        $this->requireAdmin();
        $on = (int)$this->request->post('enabled', 0) === 1;
        try {
            MiniAppService::save(['audit_mode' => $on]);
            $this->log('移动端管理', $on ? '启用小程序审核模式' : '取消小程序审核模式', '');
            return json(r_success($this->aggregate(), $on ? '已启用审核模式，敏感模块已对小程序隐藏' : '已取消审核模式'));
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage() ?: '操作失败'));
        }
    }

    /** 聚合配置（供保存 / Token / 审核模式后统一回显） */
    private function aggregate(): array
    {
        $mini = MiniAppService::adminConfig();
        $mobile = MobileConfigService::adminConfig();
        return array_merge($mobile, [
            'mini_enabled'       => $mini['enabled'],
            'mini_login_enabled' => $mini['login_enabled'],
            'mini_audit_mode'    => $mini['audit_mode'],
            'mini_appid'         => $mini['appid'],
            'mini_secret_set'    => $mini['secret_set'],
            'mini_token'         => $mini['token'],
            'mini_token_set'     => $mini['token_set'],
            'mini_bound_count'   => $mini['bound_count'],
            'sensitive_ids'      => $mini['sensitive_ids'],
        ]);
    }
}
