<?php
namespace app\controller\api;

use app\BaseController;
use app\model\SystemConfig;
use think\facade\Cache;

/**
 * 公开的系统基本信息（登录页等未登录场景展示品牌信息）
 * 只返回品牌类配置，不含任何敏感信息
 */
class SystemInfo extends BaseController
{
    public function index()
    {
        $map = Cache::get('sys_config_map');
        if (!is_array($map)) {
            $map = [];
            foreach (SystemConfig::select()->toArray() as $row) {
                $map[$row['config_key']] = $row['config_value'];
            }
            Cache::set('sys_config_map', $map, 300);
        }
        $allow = ['sys_name', 'sys_short', 'sys_logo', 'sys_company', 'sys_contact', 'sys_copyright', 'sys_bei_an', 'login_token_ttl_hours', 'totp_trust_days', 'sys_mp_qrcode'];
        $out = [];
        foreach ($allow as $k) {
            $out[$k] = $map[$k] ?? '';
        }
        return json(r_success($out));
    }
}
