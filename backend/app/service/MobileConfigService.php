<?php
namespace app\service;

use app\model\SystemConfig;
use think\facade\Cache;

/**
 * 移动端后台配置（v2.99.24）
 *
 * 后台「系统管理 → 移动端配置」集中管理所有能被后台控制的移动端项：
 *  - 显示名称（登录页品牌名 / 工作台标题）
 *  - 功能导航（工作台工具目录：显隐 / 名称 / 排序）
 *  - 底部菜单导航（tabBar：显隐 / 名称 / 排序）
 *  - 登录渠道（H5 端企微登录 / APP 端企微登录开关）
 *  - 在线版本更新推送（APP 版本号 / 版本名 / 更新说明 / 下载地址 / 强制更新）
 *  - uni-push 2.0（APP 端离线推送开关）
 *  - 微信小程序订阅消息（启用开关 + 各场景模板 ID）
 *
 * 存储沿用 system_config 键值对（mobile_*），读写模式参考 MiniAppService：
 *  - settings()      读取原始配置
 *  - adminConfig()   管理端完整配置（含目录，供后台页面编辑）
 *  - publicConfig()  移动端公开配置（不含敏感信息，供 /api/mobile/config 下发）
 *  - save()          白名单校验后落库并失效缓存
 */
class MobileConfigService
{
    /** 蓝图/迁移版本：v2.103 合并小程序 menus 与移动端 navs 为统一功能导航 */
    public const VERSION = 'v2.103';

    /** 一次性迁移标记（写入 system_config，跑完即置位，避免重复迁移覆盖管理员新配置） */
    private const MIGRATION_KEY = 'mobile_nav_merge_migrated';

    /**
     * 功能导航统一目录（v2.99.26 合并小程序 menus 与移动端 navs 后的唯一数据源）。
     *
     * 三端（H5 / App / 微信小程序）共用同一份显隐 / 改名 / 排序配置。
     * - id 必须与移动端 dashboard 的 TOOL_CATALOG、小程序 ROUTE_TOOLS 保持一致；
     * - group=common 工作台常用工具，manage 收进「更多功能」；
     * - sensitive=true 为微信审核敏感项，审核模式下强制隐藏（见 MiniAppService）；
     * - mp_only=true 表示仅小程序工作台提供入口（H5/App 无对应页面，自动不渲染）。
     */
    public static function navCatalog(): array
    {
        return [
            ['id' => 'project',       'label' => '项目配证', 'group' => 'common', 'path' => 'pages/project/list'],
            ['id' => 'match',         'label' => '智能匹配', 'group' => 'common', 'path' => 'pages/match/index'],
            ['id' => 'secondment',    'label' => '证书借调', 'group' => 'common', 'path' => 'pages/secondment/list'],
            ['id' => 'alert',         'label' => '到期提醒', 'group' => 'common', 'path' => 'pages/alert/list'],
            ['id' => 'fee',           'label' => '费用台账', 'group' => 'common', 'path' => 'pages/fee/list'],
            ['id' => 'contract',      'label' => '服务合同', 'group' => 'common', 'path' => 'pages/contract/list'],
            ['id' => 'company',       'label' => '客户档案', 'group' => 'common', 'path' => 'pages/company/list'],
            ['id' => 'company-cert',  'label' => '企业证书', 'group' => 'common', 'path' => 'pages/company-cert/list'],
            ['id' => 'exam-signup',   'label' => '考试报名', 'group' => 'common', 'path' => 'pages/exam-signup/list', 'sensitive' => true],
            ['id' => 'exam-score',    'label' => '成绩查询', 'group' => 'common', 'path' => 'pages/exam-score/list', 'sensitive' => true],
            ['id' => 'edu-signup',    'label' => '学历报名', 'group' => 'common', 'path' => 'pages/edu-signup/list'],
            ['id' => 'staff',         'label' => '人员档案', 'group' => 'common', 'path' => 'pages/staff/list'],
            ['id' => 'follow-ledger', 'label' => '跟进台账', 'group' => 'manage', 'path' => 'pages/crm/ledger'],
            ['id' => 'cert-renewal',  'label' => '证书延续', 'group' => 'manage', 'path' => 'pages/cert/renewal'],
            ['id' => 'cert-status-log', 'label' => '状态日志', 'group' => 'manage', 'path' => 'pages/cert/status-log'],
            ['id' => 'certificate',   'label' => '证照台账', 'group' => 'common', 'path' => 'pages/certificate/list', 'mp_only' => true],
            ['id' => 'cert-task',     'label' => '配证任务', 'group' => 'common', 'path' => 'pages/cert-task/index', 'mp_only' => true],
            ['id' => 'conflict',      'label' => '冲突中心', 'group' => 'manage', 'path' => 'pages/conflict/index'],
            ['id' => 'report',        'label' => '统计报表', 'group' => 'manage', 'path' => 'pages/report/list'],
            ['id' => 'pay-account',   'label' => '收款账户', 'group' => 'manage', 'path' => 'pages/pay-account/index'],
            ['id' => 'org-dept',      'label' => '组织架构', 'group' => 'manage', 'path' => 'pages/org-dept/index'],
            ['id' => 'custom-field',  'label' => '自定义字段', 'group' => 'manage', 'path' => 'pages/custom-field/list'],
            ['id' => 'gov-progress',  'label' => '申报进度', 'group' => 'manage', 'path' => 'pages/gov-progress/index', 'sensitive' => true, 'mp_only' => true],
            ['id' => 'credential-vault', 'label' => '账号密码库', 'group' => 'manage', 'path' => 'pages/credential-vault/index', 'sensitive' => true, 'mp_only' => true],
        ];
    }

    /** 微信审核敏感项（审核模式下强制隐藏），与 MiniAppService 历史口径一致 */
    public static function sensitiveNavIds(): array
    {
        $out = [];
        foreach (self::navCatalog() as $item) {
            if (!empty($item['sensitive'])) {
                $out[] = $item['id'];
            }
        }
        return $out;
    }

    /** 底部菜单导航目录（与移动端 pages.json tabBar / TabBar.vue 保持一致） */
    public static function tabbarCatalog(): array
    {
        return [
            ['pagePath' => 'pages/dashboard/dashboard', 'label' => '首页', 'toolId' => ''],
            ['pagePath' => 'pages/certificate/list',   'label' => '证照', 'toolId' => 'certificate'],
            ['pagePath' => 'pages/secondment/list',    'label' => '借调', 'toolId' => 'secondment'],
            ['pagePath' => 'pages/project/list',       'label' => '项目', 'toolId' => 'project'],
            ['pagePath' => 'pages/profile/profile',    'label' => '我的', 'toolId' => ''],
        ];
    }

    /** 小程序订阅消息场景目录（与消息模板 scene 保持一致） */
    public static function subscribeScenes(): array
    {
        return [
            'cert_expire'          => '证书到期',
            'company_cert_expire'  => '企业证书到期',
            'secondment_approach'  => '借调临期',
            'secondment_expire'    => '借调到期',
            'secondment_renew'     => '借调续借',
            'gov_progress'         => '申报进度',
        ];
    }

    /** 默认显示名称：未配置时取系统品牌名 */
    public static function displayName(): string
    {
        $name = trim((string)self::settings()['display_name']);
        if ($name !== '') {
            return $name;
        }
        $sys = sys_config('sys_name', '');
        return $sys !== '' ? (string)$sys : '证照到期管理';
    }

    public static function settings(): array
    {
        self::migrateLegacyMenusOnce();
        try {
            $rows = SystemConfig::column('config_value', 'config_key');
        } catch (\Throwable $e) {
            $rows = [];
        }
        return [
            'display_name'        => trim((string)($rows['mobile_display_name'] ?? '')),
            'navs'                => self::decodeJson($rows['mobile_navs'] ?? ''),
            'tabbars'             => self::decodeJson($rows['mobile_tabbars'] ?? ''),
            'h5_wecom'            => (string)($rows['mobile_login_h5_wecom'] ?? '1') === '1',
            'app_wecom'           => (string)($rows['mobile_login_app_wecom'] ?? '1') === '1',
            'unipush_enabled'     => (string)($rows['mobile_unipush_enabled'] ?? '0') === '1',
            'subscribe_enabled'   => (string)($rows['mobile_miniapp_subscribe_enabled'] ?? '0') === '1',
            'subscribe_templates' => self::decodeJson($rows['mobile_subscribe_templates'] ?? ''),
            'app_version'         => (string)($rows['mobile_app_version'] ?? ''),
            'app_version_name'    => (string)($rows['mobile_app_version_name'] ?? ''),
            'app_update_desc'     => (string)($rows['mobile_app_update_desc'] ?? ''),
            'app_download_url'    => (string)($rows['mobile_app_download_url'] ?? ''),
            'app_force_update'    => (string)($rows['mobile_app_force_update'] ?? '0') === '1',
        ];
    }

    /** 管理端完整配置（含目录，供后台编辑页回显） */
    public static function adminConfig(): array
    {
        $cfg = self::settings();
        return [
            'display_name'        => $cfg['display_name'],
            'navs'                => self::effectiveNavs(),
            'tabbars'             => self::effectiveTabbars(),
            'nav_catalog'         => self::navCatalog(),
            'tabbar_catalog'      => self::tabbarCatalog(),
            'subscribe_scenes'    => self::subscribeScenes(),
            'h5_wecom'            => $cfg['h5_wecom'],
            'app_wecom'           => $cfg['app_wecom'],
            'unipush_enabled'     => $cfg['unipush_enabled'],
            'subscribe_enabled'   => $cfg['subscribe_enabled'],
            'subscribe_templates' => $cfg['subscribe_templates'],
            'app_version'         => $cfg['app_version'],
            'app_version_name'    => $cfg['app_version_name'],
            'app_update_desc'     => $cfg['app_update_desc'],
            'app_download_url'    => $cfg['app_download_url'],
            'app_force_update'    => $cfg['app_force_update'],
        ];
    }

    /** 移动端公开配置（不含任何敏感配置项） */
    public static function publicConfig(): array
    {
        $cfg = self::settings();
        return [
            'display_name'        => self::displayName(),
            'navs'                => self::effectiveNavs(),
            'tabbars'             => self::effectiveTabbars(),
            'h5_wecom'            => $cfg['h5_wecom'],
            'app_wecom'           => $cfg['app_wecom'],
            'unipush_enabled'     => $cfg['unipush_enabled'],
            'subscribe_enabled'   => $cfg['subscribe_enabled'],
            'subscribe_templates' => $cfg['subscribe_templates'],
            'app_version'         => $cfg['app_version'],
            'app_version_name'    => $cfg['app_version_name'],
            'app_update_desc'     => $cfg['app_update_desc'],
            'app_download_url'    => $cfg['app_download_url'],
            'app_force_update'    => $cfg['app_force_update'],
            // v2.99.26：小程序审核模式与敏感项下发，供小程序端在统一导航上叠加强制隐藏
            'mp_audit_mode'       => MiniAppService::publicConfig()['audit_mode'] ?? false,
            'sensitive_ids'       => self::sensitiveNavIds(),
        ];
    }

    /**
     * 保存配置。
     * $data 允许键：display_name / navs / tabbars / h5_wecom / app_wecom /
     * unipush_enabled / subscribe_enabled / subscribe_templates / app_version /
     * app_version_name / app_update_desc / app_download_url / app_force_update
     */
    public static function save(array $data): array
    {
        $cfg = self::settings();
        $rows = [];

        if (array_key_exists('display_name', $data)) {
            $rows['mobile_display_name'] = mb_substr(trim((string)$data['display_name']), 0, 50);
        }

        // 开关 → 配置键 显式映射，避免生成错误的 mobile_login_* 键
        $flagKeys = [
            'h5_wecom'          => 'mobile_login_h5_wecom',
            'app_wecom'         => 'mobile_login_app_wecom',
            'unipush_enabled'   => 'mobile_unipush_enabled',
            'subscribe_enabled' => 'mobile_miniapp_subscribe_enabled',
            'app_force_update'  => 'mobile_app_force_update',
        ];
        foreach ($flagKeys as $flag => $key) {
            if (array_key_exists($flag, $data)) {
                $rows[$key] = $data[$flag] ? '1' : '0';
            }
        }

        if (array_key_exists('navs', $data) && is_array($data['navs'])) {
            $rows['mobile_navs'] = json_encode(
                self::normalizeNavs($data['navs']),
                JSON_UNESCAPED_UNICODE
            );
        }

        if (array_key_exists('tabbars', $data) && is_array($data['tabbars'])) {
            $rows['mobile_tabbars'] = json_encode(
                self::normalizeTabbars($data['tabbars']),
                JSON_UNESCAPED_UNICODE
            );
        }

        if (array_key_exists('subscribe_templates', $data) && is_array($data['subscribe_templates'])) {
            $rows['mobile_subscribe_templates'] = json_encode(
                self::normalizeSubscribeTemplates($data['subscribe_templates']),
                JSON_UNESCAPED_UNICODE
            );
        }

        if (array_key_exists('app_version', $data)) {
            $ver = preg_replace('/\D+/', '', (string)$data['app_version']);
            $rows['mobile_app_version'] = $ver;
        }
        if (array_key_exists('app_version_name', $data)) {
            $rows['mobile_app_version_name'] = mb_substr(trim((string)$data['app_version_name']), 0, 30);
        }
        if (array_key_exists('app_update_desc', $data)) {
            $rows['mobile_app_update_desc'] = mb_substr(trim((string)$data['app_update_desc']), 0, 1000);
        }
        if (array_key_exists('app_download_url', $data)) {
            $rows['mobile_app_download_url'] = trim((string)$data['app_download_url']);
        }

        if (!$rows) {
            throw new \Exception('没有需要保存的内容');
        }

        foreach ($rows as $key => $value) {
            $row = SystemConfig::where('config_key', $key)->find();
            if ($row) {
                $row->save(['config_value' => (string)$value]);
            } else {
                SystemConfig::create([
                    'config_key'   => $key,
                    'config_value' => (string)$value,
                    'config_group' => 'mobile',
                    'config_name'  => $key,
                ]);
            }
        }
        Cache::delete('sys_config_map');
        return self::adminConfig();
    }

    // ============================================================
    // 内部辅助
    // ============================================================

    /**
     * 一次性迁移：把 v2.99.25 之前分散的两套功能导航合并为统一 mobile_navs。
     *
     * 旧数据：
     *  - miniapp_menus：{id: bool} 仅小程序端显隐（19 项）
     *  - mobile_navs ：{id: {label,enabled,sort}} 三端显隐/改名/排序（15 项）
     *
     * 合并口径（两者都存在时取交集，任一关即关，避免合并后意外开放敏感功能）：
     *  - enabled：mobile_navs 有配置取其值，否则回退 miniapp_menus 的布尔值，再缺省 true；
     *  - label/sort：沿用 mobile_navs，缺省取目录默认 label 与索引 sort；
     *  - 迁移后写标记，仅执行一次；之后管理员在新页面的调整不会被覆盖。
     * 任一旧数据都不存在（全新安装）时仅写标记，不产生导航配置。
     */
    protected static function migrateLegacyMenusOnce(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            if (sys_config(self::MIGRATION_KEY, '') !== '') {
                return;
            }
            $legacyMini = self::decodeJson((string)sys_config('miniapp_menus', ''));
            $legacyNav  = self::decodeJson((string)sys_config('mobile_navs', ''));

            if ($legacyMini || $legacyNav) {
                $merged = [];
                foreach (self::navCatalog() as $i => $item) {
                    $id = $item['id'];
                    $enabled = true;
                    // 小程序旧开关（仅在确实配置了该 id 时参与）
                    if (is_array($legacyMini) && array_key_exists($id, $legacyMini)) {
                        $enabled = !empty($legacyMini[$id]);
                    }
                    // 移动端旧配置优先（三端主数据）
                    if (is_array($legacyNav) && isset($legacyNav[$id]) && is_array($legacyNav[$id])) {
                        $enabled = !empty($legacyNav[$id]['enabled']);
                    }
                    $label = trim((string)($legacyNav[$id]['label'] ?? ''));
                    $merged[$id] = [
                        'label'   => $label !== '' ? mb_substr($label, 0, 20) : $item['label'],
                        'enabled' => $enabled,
                        'sort'    => isset($legacyNav[$id]['sort']) ? (int)$legacyNav[$id]['sort'] : $i,
                    ];
                }
                self::writeConfig('mobile_navs', json_encode($merged, JSON_UNESCAPED_UNICODE));
            }
            self::writeConfig(self::MIGRATION_KEY, self::VERSION);
            Cache::delete('sys_config_map');
        } catch (\Throwable $e) {
            // 迁移失败不阻断配置读取，下次请求重试（标记未写）
            $done = false;
        }
    }

    /** 写入/更新单个 system_config 键（迁移专用，复用 mobile 分组） */
    protected static function writeConfig(string $key, string $value): void
    {
        $row = SystemConfig::where('config_key', $key)->find();
        if ($row) {
            $row->save(['config_value' => $value]);
        } else {
            SystemConfig::create([
                'config_key'   => $key,
                'config_value' => $value,
                'config_group' => 'mobile',
                'config_name'  => $key,
            ]);
        }
    }

    protected static function decodeJson(string $json): array
    {
        if ($json === '') {
            return [];
        }
        $arr = json_decode($json, true);
        return is_array($arr) ? $arr : [];
    }

    /** 实际生效的功能导航（后台保存值 + 新版本新增项默认值，按 sort 排序） */
    public static function effectiveNavs(): array
    {
        $out = [];
        foreach (self::navCatalog() as $i => $item) {
            $out[$item['id']] = [
                'label'   => $item['label'],
                'enabled' => true,
                'sort'    => $i,
            ];
        }
        foreach (self::settings()['navs'] as $id => $v) {
            $id = (string)$id;
            if (!array_key_exists($id, $out) || !is_array($v)) {
                continue;
            }
            $label = trim((string)($v['label'] ?? ''));
            if ($label !== '') {
                $out[$id]['label'] = $label;
            }
            $out[$id]['enabled'] = !empty($v['enabled']);
            if (isset($v['sort'])) {
                $out[$id]['sort'] = (int)$v['sort'];
            }
        }
        uasort($out, static fn ($a, $b) => $a['sort'] <=> $b['sort']);
        return $out;
    }

    /** 实际生效的底部导航（后台保存值 + 默认项，按 sort 排序） */
    public static function effectiveTabbars(): array
    {
        $out = [];
        foreach (self::tabbarCatalog() as $i => $item) {
            $out[$item['pagePath']] = [
                'label'   => $item['label'],
                'enabled' => true,
                'sort'    => $i,
            ];
        }
        foreach (self::settings()['tabbars'] as $pagePath => $v) {
            $pagePath = (string)$pagePath;
            if (!array_key_exists($pagePath, $out) || !is_array($v)) {
                continue;
            }
            $label = trim((string)($v['label'] ?? ''));
            if ($label !== '') {
                $out[$pagePath]['label'] = $label;
            }
            $out[$pagePath]['enabled'] = !empty($v['enabled']);
            if (isset($v['sort'])) {
                $out[$pagePath]['sort'] = (int)$v['sort'];
            }
        }
        uasort($out, static fn ($a, $b) => $a['sort'] <=> $b['sort']);
        return $out;
    }

    /**
     * 按 {id: bool} 批量更新统一功能导航的显隐（保留既有改名与排序）。
     *
     * 供合并后的「移动端管理」页 / 历史小程序保存入口复用：
     * 只改 enabled，不动 label/sort；目录外的 id 忽略，未提交的项保持现值。
     * 落库 mobile_navs 并失效配置缓存，返回更新后的 effectiveNavs()。
     */
    public static function updateNavVisibility(array $visibility): array
    {
        $current = self::effectiveNavs();
        foreach ($visibility as $id => $enabled) {
            $id = (string)$id;
            if (array_key_exists($id, $current)) {
                $current[$id]['enabled'] = !empty($enabled);
            }
        }
        $saved = self::normalizeNavs($current);
        self::writeConfig('mobile_navs', json_encode($saved, JSON_UNESCAPED_UNICODE));
        Cache::delete('sys_config_map');
        return self::effectiveNavs();
    }

    protected static function normalizeNavs(array $navs): array
    {
        $valid = array_column(self::navCatalog(), 'id');
        $out = [];
        foreach ($navs as $id => $v) {
            $id = (string)$id;
            if (!in_array($id, $valid, true) || !is_array($v)) {
                continue;
            }
            $out[$id] = [
                'label'   => mb_substr(trim((string)($v['label'] ?? '')), 0, 20),
                'enabled' => !empty($v['enabled']),
                'sort'    => (int)($v['sort'] ?? 0),
            ];
        }
        return $out;
    }

    protected static function normalizeTabbars(array $tabbars): array
    {
        $valid = array_column(self::tabbarCatalog(), 'pagePath');
        $out = [];
        foreach ($tabbars as $pagePath => $v) {
            $pagePath = (string)$pagePath;
            if (!in_array($pagePath, $valid, true) || !is_array($v)) {
                continue;
            }
            $out[$pagePath] = [
                'label'   => mb_substr(trim((string)($v['label'] ?? '')), 0, 10),
                'enabled' => !empty($v['enabled']),
                'sort'    => (int)($v['sort'] ?? 0),
            ];
        }
        return $out;
    }

    protected static function normalizeSubscribeTemplates(array $templates): array
    {
        $scenes = array_keys(self::subscribeScenes());
        $out = [];
        foreach ($templates as $scene => $templateId) {
            $scene = (string)$scene;
            if (!in_array($scene, $scenes, true)) {
                continue;
            }
            $out[$scene] = mb_substr(trim((string)$templateId), 0, 64);
        }
        return $out;
    }
}