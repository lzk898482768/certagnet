<?php
namespace app\controller\api;

use app\service\MobileConfigService;
use think\App;

/**
 * 移动端公开配置（v2.99.24）
 *
 *  - GET /api/mobile/config  公开：显示名称 / 功能导航 / 底部菜单 / 登录渠道开关 /
 *                             版本更新信息 / uni-push 开关 / 小程序订阅消息模板（不含任何敏感配置）
 */
class MobileConfig
{
    protected $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function config()
    {
        try {
            return json(r_success(MobileConfigService::publicConfig()));
        } catch (\Throwable $e) {
            // 引导接口不因配置读取失败而中断，避免移动端首屏白屏
            return json(r_success([
                'display_name'        => '证照到期管理',
                'navs'                => [],
                'tabbars'             => [],
                'h5_wecom'            => true,
                'app_wecom'           => true,
                'unipush_enabled'     => false,
                'subscribe_enabled'   => false,
                'subscribe_templates' => [],
                'app_version'         => '',
                'app_version_name'    => '',
                'app_update_desc'     => '',
                'app_download_url'    => '',
                'app_force_update'    => false,
            ]));
        }
    }
}