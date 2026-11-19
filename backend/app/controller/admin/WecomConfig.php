<?php
namespace app\controller\admin;

use app\model\SystemConfig;
use app\model\SysRole;
use app\service\WecomClient;
use think\facade\Cache;

/**
 * 企业微信登录配置（后台）
 *
 *  - GET  /admin/wecom-config          读取 7 个 wecom_* 配置（secret 脱敏）
 *  - POST /admin/wecom-config/save     保存配置
 *  - POST /admin/wecom-config/test     校验 CorpID + Secret 能否拿到 access_token
 */
class WecomConfig extends AdminController
{
    /** 允许保存的配置键白名单 */
    protected const KEYS = [
        'wecom_enabled', 'wecom_corp_id', 'wecom_agent_id', 'wecom_app_secret',
        'wecom_redirect_uri', 'wecom_auto_create', 'wecom_default_role',
        'wecom_approval_template_id',
    ];

    /** secret 占位符：前端回显用，提交该值表示「未修改」 */
    public const SECRET_MASK = '******';

    public function get()
    {
        $this->requireAdmin();
        $map = SystemConfig::column('config_value', 'config_key');
        $data = [];
        foreach (self::KEYS as $k) {
            $data[$k] = $map[$k] ?? ($k === 'wecom_enabled' || $k === 'wecom_auto_create' ? '0' : '');
        }
        // secret 脱敏：仅告知是否已设置
        $data['wecom_app_secret_set'] = !empty($data['wecom_app_secret']);
        $data['wecom_app_secret']     = $data['wecom_app_secret'] ? self::SECRET_MASK : '';
        return json(r_success($data));
    }

    public function save()
    {
        $this->requireAdmin();

        $post = $this->request->post();
        $rows = [];
        foreach (self::KEYS as $k) {
            if (!array_key_exists($k, $post)) {
                continue;
            }
            $v = $post[$k];
            if ($k === 'wecom_app_secret') {
                // 前端传回占位符表示未改动，保留原值
                if ($v === self::SECRET_MASK) {
                    $v = SystemConfig::where('config_key', $k)->value('config_value') ?? '';
                }
            } elseif ($k === 'wecom_enabled' || $k === 'wecom_auto_create') {
                $v = $v ? '1' : '0';
            } elseif ($k === 'wecom_default_role') {
                $roleId = (int)$v;
                $role = $roleId > 0 ? SysRole::find($roleId) : null;
                if (!$role || (int)$role->status !== 1) {
                    return json(r_error('企微自动建号默认角色不存在或已停用', 400));
                }
                if ($role->role_code === 'admin') {
                    return json(r_error('安全限制：企微自动建号不能默认授予管理员角色', 403));
                }
                $v = (string)$roleId;
            }
            $rows[$k] = (string)$v;
        }
        if (empty($rows)) {
            return json(r_error('无有效配置', 400));
        }
        foreach ($rows as $k => $v) {
            $row = SystemConfig::where('config_key', $k)->find();
            if ($row) {
                $row->save(['config_value' => $v]);
            } else {
                SystemConfig::create([
                    'config_key'   => $k,
                    'config_value' => $v,
                    'config_group' => 'base',
                    'config_name'  => $k,
                ]);
            }
        }
        Cache::delete('sys_config_map');
        $this->log('企业微信', '保存登录配置', '');
        return json(r_success([], '保存成功'));
    }

    /**
     * 校验配置：用 CorpID + Secret 真实拿一次 access_token。
     * DEV 模式下不连企微，直接返回沙箱可联通提示。
     */
    public function test()
    {
        $this->requireAdmin();

        $cfg = WecomClient::loadConfig();
        if (empty($cfg['wecom_corp_id']) || empty($cfg['wecom_app_secret'])) {
            return json(r_error('请先填写 CorpID 与 Secret'));
        }
        if (WecomClient::isDev($cfg)) {
            return json(r_success([
                'mode'       => 'dev',
                'corp_name'  => 'DEV 离线沙箱',
                'agent_name' => '沙箱应用',
                'msg'        => 'DEV 模式：未真正连接企微，配置可联通（在企微后台填真实 CorpID 后以生产模式校验）',
            ], 'DEV 模式：未真正连接企微，配置可联通（在企微后台填真实 CorpID 后以生产模式校验）'));
        }
        try {
            $token = WecomClient::getAccessToken($cfg, true);
            $info  = WecomClient::httpGet(self::WECOM_API() . '/department/list?access_token=' . urlencode($token));
            $deptCount = isset($info['department']) && is_array($info['department']) ? count($info['department']) : 0;
            $corpName  = $info['department'][0]['name'] ?? '';
            return json(r_success([
                'mode'        => 'prod',
                'access_token'=> substr($token, 0, 6) . '...（已缓存）',
                'dept_count'  => $deptCount,
                'corp_name'   => $corpName,
                'msg'         => "连接成功：access_token 已获取，通讯录可见部门 {$deptCount} 个",
            ], "连接成功：access_token 已获取，通讯录可见部门 {$deptCount} 个"));
        } catch (\Throwable $e) {
            return json(r_error('连接失败: ' . $e->getMessage(), 500));
        }
    }

    /** 暴露 API 域名常量给内部调用 */
    protected static function WECOM_API(): string
    {
        return \app\service\WecomClient::WECOM_API;
    }
}
