<?php
namespace app\service;

use app\model\SysMenu;
use think\facade\Db;

/**
 * 菜单蓝图与运行时自愈
 *
 * v2.28 菜单信息架构重构：9 个混杂分组归并为 6 大分组，以「人 → 证 → 项目 → 借调」组织。
 * v2.30 重复模块合并：
 *   - 「持证人员档案」与「人员全景档案」合并为「人员档案」（全景改为档案抽屉，53 停用）
 *   - 「项目管理」与「配证任务」合并为「项目配证」（任务并入项目详情，33 停用）
 *   - 「费用统计」与「费用台账」合并（统计本就是台账内的 tab，30 停用）
 *
 * 为什么要运行时自愈：
 *   仓库约定不提供 upgrade_*.sql，老库升级只执行 git pull；
 *   若菜单分类只写在 init_data.sql，老库将永远停留在旧分类。
 *   因此这里内置一份与 init_data.sql 一致的蓝图，由菜单接口入口幂等 upsert，
 *   保证「git pull + php think clear」后菜单自动对齐新架构。
 *
 * 幂等策略：只在「蓝图项不存在」或「归属/名称与蓝图不符」时写库，
 *          管理员手工调整过的 sort/status 等非架构字段不会被覆盖。
 */
class MenuCatalog
{
    /** 自愈版本标记：写入 system_config，改动蓝图时 +1 可强制重刷 */
    // v2.99.52：v2.99.47 新增蓝图项 78（敏感访问审计）时漏递增版本，已部署老库
    // 门控判定 menu_catalog_version 已等于 v2.103 而不再补录，导致该菜单永久缺失；
    // 递增到 v2.104 强制全量重刷一次（全新安装走 init_data.sql 种子，不受影响）。
    // v2.99.70：新增蓝图项 79（考试报名-成绩查询），递增版本强制老库刷新一次。
    public const VERSION = 'v2.105';

    /**
     * 菜单蓝图（与 database/init_data.sql 的 v2.30 段保持一致）
     * 字段：id, pid, name, icon, path, component, sort
     *
     * v2.57 菜单整理：
     *   - 原「人员与证书」是 5 项大杂烩，拆出「客户与跟进」分组（企业客户 / 服务合同 / 跟进台账）；
     *   - 「人员与证书」收敛为人员档案 + 证照台账；
     *   - 系统管理内部序号重排（组织架构 / 菜单 / 字典 / 自定义字段 / 提醒规则 / 系统信息 / 日志 / 回收站）。
     */
    public static function blueprint(): array
    {
        return [
            // ---- 01 工作台 ----
            [1,  0, '工作台',        'Odometer', '',                     '',                1],
            [2,  1, '首页总览',      'HomeFilled', '/dashboard',        'dashboard/index', 1],
            [3,  1, '统计报表',      'DataAnalysis', '/report',         'report/index',    2],
            [31, 1, '一键查询中心',  'Search',    '/search-center',       'search-center/index', 3],

            // ---- 02 客户与跟进（v2.57 从「人员与证书」拆出） ----
            [57, 0, '客户与跟进',    'OfficeBuilding', '',               '',                 2],
            [11, 57, '企业客户',      'OfficeBuilding', '/company',      'company/index',    1],
            // v2.93：企业证书（安许 / 资质）集中台账，紧邻企业客户
            [68, 57, '企业证书',      'Medal',       '/company-cert',     'companyCert/index',2],
            [13, 57, '服务合同管理',  'Files',     '/contract',           'contract/index',   3],
            [56, 57, '跟进台账',      'Notebook',  '/follow-ledger',      'followLedger/index', 4],

            // ---- 03 人员与证书 ----
            [50, 0, '人员与证书',    'UserFilled', '',                    '',                 3],
            [12, 50, '人员档案',      'User',      '/staff',              'staff/index',      1],
            [5,  50, '证照台账',      'Tickets',   '/certificate',        'certificate/index',2],
            // v2.68：「证书延续」从「借调与提醒」移入「人员与证书」，紧邻证照台账
            [7,  50, '证书延续',      'Refresh',   '/cert-renewal',       'certRenewal/index',3],

            // ---- 04 借调与提醒（v2.74：上移到「项目与配证」之前） ----
            [51, 0, '借调与提醒',    'Switch',    '',                    '',                 4],
            [26, 51, '证书借调',      'Switch',    '/secondment',         'secondment/index', 1],
            [8,  51, '状态变更日志',  'Document',  '/cert-status-log',    'certStatusLog/index',2],

            // ---- 05 项目与配证 ----
            [40, 0, '项目与配证',    'Briefcase', '',                    '',                 5],
            [41, 40, '项目配证',      'FolderOpened', '/project',         'project/index',    1],
            [33, 40, '配证任务',      'Tickets',   '/cert-task',          'cert-task/index',  2],
            [42, 40, '智能匹配',      'Aim',       '/match',              'match/index',      3],
            [43, 40, '冲突中心',      'WarningFilled', '/conflict',       'conflict/index',   4],
            [44, 40, '属地政策规则',  'SetUp',     '/policy-rule',        'policy-rule/index',5],

            // ---- 06 政务网进度（v2.62，v2.63 新增账号密码库并前移到费用之前） ----
            [58, 0,  '政务网进度',    'Promotion', '',                    '',                 6],
            [59, 58, '申报进度',      'DocumentChecked', '/gov-progress', 'gov-progress/index', 1],
            [60, 58, '账号密码库',    'Key',       '/credential-vault',   'credential-vault/index', 2],

            // ---- 07 考试报名（v2.85，移植中介代报名「人员报名」） ----
            [63, 0,  '考试报名',    'Reading',    '',                    '',                 7],
            [64, 63, '报名工单',    'Tickets',    '/exam-signup',        'examSignup/index', 1],
            [65, 63, '批次考点',    'Location',   '/exam-batch',         'examBatch/index',  2],
            [66, 63, '业务配置',    'SetUp',      '/exam-config',        'examConfig/index', 3],
            [67, 63, '证书类目',    'Collection', '/exam-cert',          'examCert/index',   4],
            // v2.99.70：安管人员成绩查询（新取证 / 继续教育）
            [79, 63, '成绩查询',    'Trophy',     '/exam-score',         'examScore/index',  5],

            // ---- 07.5 学历报名（v2.99） ----
            [70, 0,  '学历报名',    'School',     '',                    '',                 8],
            [71, 70, '报名工单',    'Tickets',    '/edu-signup',         'eduSignup/index',  1],
            [72, 70, '报名机构',    'OfficeBuilding', '/edu-agency',     'eduAgency/index',  2],
            [73, 70, '流程配置',    'SetUp',      '/edu-config',         'eduConfig/index',  3],

            // ---- 08 费用与收款 ----
            [52, 0, '费用与收款',    'Money',     '',                    '',                 9],
            [9,  52, '费用台账',      'Money',     '/fee',                'fee/index',        1],
            [54, 52, '收款账户',      'Wallet',    '/pay-account',        'payAccount/index', 2],

            // ---- 09 推送中心 ----
            // v2.73：「到期提醒中心」更名「提醒中心」并移入「推送中心」，原「推送与系统」同步更名
            [14, 0, '推送中心',      'Setting',   '',                    '',                 10],
            [6,  14, '提醒中心',      'AlarmClock','/alert',              'alert/index',      1],
            [15, 14, '推送渠道管理',  'Connection','/push-channel',       'pushChannel/index',2],
            [16, 14, '消息模板管理',  'ChatDotRound','/msg-template',     'msgTemplate/index',3],
            [17, 14, '企业接收人',    'Avatar',    '/receiver',           'receiver/index',   4],
            [18, 14, '推送消息日志',  'List',      '/push-log',           'pushLog/index',    5],

            // ---- 10 系统管理 ----
            [19, 0,  '系统管理',      'Setting',   '',                    '',                 11],
            [32, 19, '组织架构',      'Share',     '',                    '',                 1],
            [34, 32, '部门管理',      'OfficeBuilding', '/org-dept',       'org-dept/index',   1],
            [21, 32, '账号权限管理',  'Key',       '/user',               'user/index',       2],
            [22, 32, '角色管理',      'UserFilled','/role',               'role/index',       3],
            // v2.57：企微通讯录 / 审批管理为后续新增功能，纳入蓝图统一排序
            [36, 32, '企微通讯录',    'ChatDotSquare', '/wecom-org',      'wecom-org/index',  4],
            [37, 32, '审批管理',      'Stamp',     '/approval',           'approval/index',   5],
            [23, 19, '菜单管理',      'Menu',      '/menu',               'menu/index',       2],
            [24, 19, '字典管理',      'Collection','/dict',               'dict/index',       3],
            [27, 19, '自定义字段',    'EditPen',   '/custom-field',       'custom-field/index',4],
            [20, 19, '提醒规则设置',  'Timer',     '/alert-config',       'alertConfig/index',5],
            [28, 19, '系统信息设置',  'Setting',   '/system-info',        'system-info/index',6],
            [35, 19, '企微登录配置',  'ChatDotRound','/wecom-config',     'wecom-config/index',7],
            // v2.99.26：合并原「小程序接入」(75) 与「移动端配置」(76) 为「移动端管理」，
            // 统一 H5/App/小程序三端接入与功能导航（默认仅超级管理员可见）
            [77, 19, '移动端管理',    'Cellphone', '/mobile-manage',     'mobileManage/index',8],
            [25, 19, '操作日志',      'Memo',      '/operation-log',      'operationLog/index',9],
            // v2.70：用户登录日志（仅超级管理员默认可见）
            [61, 19, '登录日志',      'Tickets',   '/login-log',          'loginLog/index',   10],
            // v2.99.47：敏感信息明文访问审计（国密合规 P1，仅超级管理员默认可见）
            [78, 19, '敏感访问审计',  'Lock',      '/sensitive-access',   'sensitiveAccess/index', 11],
            // v2.52：全模块回收站（管理员专用删除缓冲）
            [55, 19, '回收站',        'Delete',    '/recycle-bin',        'recycleBin/index', 12],
            // v2.82：Agent 接入（API Key 管理，默认仅超级管理员可见）
            [62, 19, 'Agent 接入',    'MagicStick','/agent',              'agent/index',      13],
            // v2.96：登录设备管理（超级管理员，查看/下线全部用户设备）
            [69, 19, '登录设备',      'Monitor',   '/login-device',       'loginDevice/index',14],
            // v2.99.1：在线升级（默认仅超级管理员可见，升级/备份/恢复）
            [74, 19, '在线升级',      'Refresh',   '/upgrade',            'upgrade/index',     15],
            // v2.99.26：原 75 小程序接入 / 76 移动端配置已合并进 77 移动端管理，
            // 两者在 deprecatedMenus() 中停用隐藏（保留数据，不删除，避免角色授权成孤儿）。

        ];
    }

    /** 已被合并的旧顶级分组（停用但保留数据，避免子项成孤儿） */
    public static function deprecatedGroups(): array
    {
        return [4, 10, 29];
    }

    /**
     * v2.30 因模块合并而停用的子菜单（功能已并入其他模块）：
     *   53 人员全景档案 → 并入 12 人员档案（档案抽屉）
     *   30 费用统计     → 并入 9 费用台账（统计 tab）
     *   38 收款账户     → 与 54 重复（同路径同页面），54 为权威实现；
     *                     38 挂在已废弃分组 29 下，status=1 但父组停用时不可见，
     *                     属活跃脏数据，v2.44 一并停用
     *
     * ⚠️ v2.32：33 配证任务**已恢复为独立菜单**（用户诉求「把配证任务放到菜单里」），
     *    故从停用清单移除，改在 blueprint() 中以 [33, 40, ...] 重新启用。
     *
     * v2.99.26：75 小程序接入 / 76 移动端配置合并为 77 移动端管理，两者停用隐藏
     *    （保留 sys_menu 数据与角色授权关系，避免成孤儿；需要时仍可从菜单管理恢复）。
     */
    public static function deprecatedMenus(): array
    {
        return [53, 30, 38, 75, 76];
    }

    /**
     * v2.32 重新启用的菜单（曾被系统在更早版本停用，需强制恢复 status=1）
     *
     * ⚠️ 为什么必须是显式恢复而非只靠 blueprint()：
     *   ensureSchema() 对已存在菜单「纠正架构字段但**不纠正 status**」（保护管理员手动停用）。
     *   而 33 配证任务在老库是「系统自动停用」（v2.30 合并所致），并非管理员手动停用，
     *   若不显式置回 status=1，老库升级后菜单仍不可见（新库靠 init_data.sql 种子）。
     *   仅在同版本重刷门控内执行一次，之后管理员若手动停用不会被覆盖。
     */
    public static function restoredMenus(): array
    {
        return [33];
    }

    /**
     * 幂等自愈：确保蓝图中的菜单存在且归属/名称/路径正确
     * @param bool $force true=强制重刷（版本号变更时用）
     */
    public static function ensureSchema(bool $force = false): void
    {
        // ⓪ v2.38 节流：本方法在每次 Auth@info / Menu@index 都会调用，原先每次
        //    全表同步角色授权（sys_role 全表 + sys_menu 全表），是后台每个页面
        //    都要付的固定开销。用 Redis 打 60 秒闸：一分钟内只真正同步一次。
        //    · 管理员「重置架构」与诊断接口走 force=true，不受节流影响；
        //    · Redis 不可用时**直接抛出**（本系统强依赖 Redis，见 config/cache.php），
        //      错误信息由 RedisCache 统一给出，便于按 redis:check 提示排障。
        if (!$force && !self::schemaThrottlePass()) {
            return;
        }

        // ① v2.30：先补齐 OA 角色组蓝图。
        //    v2.69：角色蓝图版本变更时整体对齐菜单 + 功能权限（蓝图为准，可增可减），
        //    返回 true 表示本次发生了对齐，需要清空菜单树缓存。
        $roleChanged = false;
        try {
            $roleChanged = \app\service\RoleCatalog::ensure();
        } catch (\Throwable $e) {
            // 角色自愈失败不阻断主流程
        }

        // ② v2.32 菜单恢复必须独立于 VERSION 门控（血的教训：版本号 early-return
        //    会屏蔽后续新增的修复）。这里用**独立的迁移标记**保证「只跑一次」：
        //    · 老库升级（不论 menu_catalog_version 是否已是 v2.32）都会执行一次；
        //    · 执行后写入标记，此后管理员若手动停用 33 不会被反复覆盖。
        try {
            self::restoreOnce();
        } catch (\Throwable $e) {
            // 恢复失败不阻断主流程
        }

        // ③ 菜单蓝图 upsert（版本门控）
        //    v2.57 调整顺序：必须**先于**角色授权同步执行。否则新增分组
        //    （如「客户与跟进」57）在本次请求内尚不存在，MenuRoleSync 无法
        //    为既有角色补齐祖先分组，表现为新分组整体不可见。
        try {
            $applied = sys_config('menu_catalog_version', '');
            if ($force || $applied !== self::VERSION) {
                Db::transaction(function () {
                    foreach (self::blueprint() as [$id, $pid, $name, $icon, $path, $component, $sort]) {
                        $exist = Db::name('sys_menu')->where('id', $id)->find();
                        if (!$exist) {
                            Db::name('sys_menu')->insert([
                                'id' => $id, 'parent_id' => $pid, 'menu_name' => $name,
                                'icon' => $icon, 'path' => $path, 'component' => $component,
                                'sort' => $sort, 'status' => 1,
                            ]);
                            continue;
                        }
                        // 已存在：架构字段一律纠正为蓝图值。
                        // sort 也必须纠正 —— 老库的 sort 是历史遗留值（如工作台=3、系统管理=8），
                        // 不纠正会导致分组顺序错乱、侧边栏序号乱序。
                        // status 例外：管理员手动停用的菜单不应被自动恢复。
                        $dirty = [];
                        if ((int)$exist['parent_id'] !== $pid) { $dirty['parent_id'] = $pid; }
                        if ((string)$exist['menu_name'] !== $name) { $dirty['menu_name'] = $name; }
                        if ((string)$exist['path'] !== $path) { $dirty['path'] = $path; }
                        if ((string)$exist['component'] !== $component) { $dirty['component'] = $component; }
                        if ((int)$exist['sort'] !== (int)$sort) { $dirty['sort'] = (int)$sort; }
                        if ($dirty) {
                            Db::name('sys_menu')->where('id', $id)->update($dirty);
                        }
                    }
                    // 停用已合并的旧顶级分组
                    Db::name('sys_menu')->whereIn('id', self::deprecatedGroups())->update(['status' => 0]);
                    // 停用因模块合并而退役的子菜单（功能已并入其他模块）
                    Db::name('sys_menu')->whereIn('id', self::deprecatedMenus())->update(['status' => 0]);
                });

                self::markVersion();
                // v2.68：蓝图变更后清空菜单树缓存，确保 parent_id 调整立即生效
                // （菜单树缓存按角色 menu_ids 指纹存储，仅调整分组不会改变指纹，需主动失效）
                self::flushThrottle();
            }
        } catch (\Throwable $e) {
            // 菜单自愈失败不应阻断登录等主流程，交由日志排查
        }

        // ③.5 v2.62：政务网进度菜单一次性补授权
        //      RoleCatalog::ensure() 对已存在角色不改 menu_ids，故必须显式补。
        //      仅授权业务岗位（不含 company / finance），执行一次后写标记，
        //      之后管理员手工收回不会被反复覆盖。
        try {
            self::grantGovMenusOnce();
        } catch (\Throwable $e) {
            // 授权失败不阻断主流程
        }

        // ③.6 v2.63：账号密码库菜单（60）一次性补授权
        try {
            self::grantCredentialVaultMenuOnce();
        } catch (\Throwable $e) {
            // 授权失败不阻断主流程
        }

        // ③.7 v2.93：企业证书菜单（68）一次性补授权
        try {
            self::grantCompanyCertMenuOnce();
        } catch (\Throwable $e) {
            // 授权失败不阻断主流程
        }

        // ④ v2.69：不再调用 MenuRoleSync::syncAll()。
        //    历史实现「只增不减」是授权漂移的根因；现在角色授权由
        //    RoleCatalog 蓝图版本对齐（①），自定义角色由管理员在角色页维护。
        if ($roleChanged) {
            self::flushThrottle();
        }
    }

    /**
     * v2.38：菜单自愈节流闸（Redis，60 秒）
     *
     * 返回 true = 本轮应真正执行同步；false = 最近 60 秒内已同步过，跳过。
     * Redis 作为唯一真源：不用进程内 static，因为 PHP 内置服务器/FPM 每请求
     * 可能是新进程，static 不可靠；也不落库，避免多写一行 config。
     *
     * 失败即上抛（不静默）——本系统已选「强依赖 Redis」，静默跳过会让
     * 「菜单莫名消失」这类问题更难排查。
     */
    protected static function schemaThrottlePass(): bool
    {
        $key = 'schema_synced_at';
        if (\app\service\RedisCache::get(\app\service\RedisCache::NS_MENU, $key) !== null) {
            return false;
        }
        \app\service\RedisCache::put(\app\service\RedisCache::NS_MENU, $key, time(), 60);
        return true;
    }

    /** 手动重置节流 + 清空菜单树缓存（菜单/角色/授权变动后调用） */
    public static function flushThrottle(): void
    {
        \app\service\RedisCache::flushNs(\app\service\RedisCache::NS_MENU);
    }

    /**
     * v2.32 一次性菜单恢复（独立迁移标记，不受 VERSION 门控影响）
     *
     * 为什么独立：ensureSchema() 的 VERSION early-return 会屏蔽「版本写入之后」
     * 新增的任何修复逻辑（本项目已复发多次）。用独立 config_key 记录，
     * 保证老库升级时**恰好执行一次**，此后管理员手动停用不会被反复覆盖。
     */
    protected static function restoreOnce(): void
    {
        $flag = 'menu_restore_v232_done';
        if (sys_config($flag, '') === '1') {
            return; // 已执行过
        }
        // 仅在菜单 33 确实存在时恢复（新库由 init_data.sql 种子直接为 status=1）
        $exists = Db::name('sys_menu')->where('id', 33)->find();
        if ($exists) {
            // 蓝图中 33 的权威定义（与 blueprint() 保持一致）
            $b = [33, 40, '配证任务', 'Tickets', '/cert-task', 'cert-task/index', 2];
            $dirty = [];
            if ((int)$exists['status'] !== 1)      { $dirty['status'] = 1; }
            if ((int)$exists['parent_id'] !== $b[1]) { $dirty['parent_id'] = $b[1]; }
            if ((string)$exists['menu_name'] !== $b[2]) { $dirty['menu_name'] = $b[2]; }
            if ((string)$exists['icon'] !== $b[3])  { $dirty['icon'] = $b[3]; }
            if ((string)$exists['path'] !== $b[4])  { $dirty['path'] = $b[4]; }
            if ((string)$exists['component'] !== $b[5]) { $dirty['component'] = $b[5]; }
            if ((int)$exists['sort'] !== $b[6])     { $dirty['sort'] = $b[6]; }
            if ($dirty) {
                Db::name('sys_menu')->where('id', 33)->update($dirty);
            }
        }
        // 写标记（幂等：已存在则更新）
        $row = Db::name('system_config')->where('config_key', $flag)->find();
        if ($row) {
            Db::name('system_config')->where('id', $row['id'])->update(['config_value' => '1']);
        } else {
            Db::name('system_config')->insert([
                'config_key'   => $flag,
                'config_value' => '1',
                'remark'       => 'v2.32 菜单33（配证任务）恢复标记，保证仅自愈一次',
            ]);
        }
    }

    /**
     * v2.63 账号密码库菜单一次性授权
     *
     * 新菜单 60 不在 v2.62 的授权标记内，必须独立补一次；
     * 与申报进度一致：证照管理员 / 项目配证专员 / 只读观察员可见。
     */
    protected static function grantCredentialVaultMenuOnce(): void
    {
        $flag = 'credential_vault_menu_grant_v263_done';
        if (sys_config($flag, '') === '1') {
            return;
        }
        $menuOk = Db::name('sys_menu')->where('id', 60)->where('status', 1)->count();
        if ((int)$menuOk < 1) {
            return;
        }

        $roles = Db::name('sys_role')
            ->whereIn('role_code', ['cert_manager', 'project_officer', 'viewer'])
            ->select()->toArray();
        foreach ($roles as $role) {
            $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($role['menu_ids'] ?? ''))))));
            if (!$ids) {
                continue; // 空 = 不限制，无需补
            }
            $ids[] = 58;
            $ids[] = 59;
            $ids[] = 60;
            $ids = array_values(array_unique($ids));
            sort($ids);
            $new = implode(',', $ids);
            if ($new !== (string)$role['menu_ids']) {
                Db::name('sys_role')->where('id', $role['id'])->update(['menu_ids' => $new]);
            }
        }

        $row = Db::name('system_config')->where('config_key', $flag)->find();
        if ($row) {
            Db::name('system_config')->where('id', $row['id'])->update(['config_value' => '1']);
        } else {
            Db::name('system_config')->insert([
                'config_key'   => $flag,
                'config_value' => '1',
                'remark'       => 'v2.63 账号密码库菜单（60）一次性授权标记',
            ]);
        }
    }

    /**
     * v2.93 企业证书菜单一次性授权
     *
     * 新菜单 68 挂在「客户与跟进」下；客户企业账号（company 角色）也开放查看本企业证书。
     * 与其他一次性授权一致：菜单未落库不写标记，执行后管理员可自由收回。
     */
    protected static function grantCompanyCertMenuOnce(): void
    {
        $flag = 'company_cert_menu_grant_v293_done';
        if (sys_config($flag, '') === '1') {
            return;
        }
        $menuOk = Db::name('sys_menu')->where('id', 68)->where('status', 1)->count();
        if ((int)$menuOk < 1) {
            return;
        }

        $roles = Db::name('sys_role')
            ->whereIn('role_code', ['cert_manager', 'project_officer', 'viewer', 'company'])
            ->select()->toArray();
        foreach ($roles as $role) {
            $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($role['menu_ids'] ?? ''))))));
            if (!$ids) {
                continue; // 空 = 不限制，无需补
            }
            // 客户与跟进分组（57）本就在这些角色的 menu_ids 中
            $ids[] = 68;
            $ids = array_values(array_unique($ids));
            sort($ids);
            $new = implode(',', $ids);
            if ($new !== (string)$role['menu_ids']) {
                Db::name('sys_role')->where('id', $role['id'])->update(['menu_ids' => $new]);
            }
        }

        $row = Db::name('system_config')->where('config_key', $flag)->find();
        if ($row) {
            Db::name('system_config')->where('id', $row['id'])->update(['config_value' => '1']);
        } else {
            Db::name('system_config')->insert([
                'config_key'   => $flag,
                'config_value' => '1',
                'remark'       => 'v2.93 企业证书菜单（68）一次性授权标记',
            ]);
        }
    }

    /**
     * v2.62 政务网进度菜单一次性授权
     *
     * 新菜单 58（分组）/ 59（申报进度）不在既有角色白名单中，而 RoleCatalog::ensure()
     * 对已存在角色只纠正名称/备注/状态、不动 menu_ids。为避免「菜单建了但没人看得到」，
     * 这里按岗位显式补授权：证照管理员、项目配证专员、只读观察员。
     * 企业查看者与财务不开放；执行一次后写标记，管理员后续可自由收回。
     */
    protected static function grantGovMenusOnce(): void
    {
        $flag = 'gov_menu_grant_v262_done';
        if (sys_config($flag, '') === '1') {
            return;
        }
        // 菜单尚未落库时不写标记，等待下一轮自愈重试
        $menuOk = Db::name('sys_menu')->whereIn('id', [58, 59])->where('status', 1)->count();
        if ((int)$menuOk < 2) {
            return;
        }

        $roles = Db::name('sys_role')
            ->whereIn('role_code', ['cert_manager', 'project_officer', 'viewer'])
            ->select()->toArray();
        foreach ($roles as $role) {
            $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($role['menu_ids'] ?? ''))))));
            if (!$ids) {
                continue; // 空 = 不限制，无需补
            }
            $ids[] = 58;
            $ids[] = 59;
            $ids = array_values(array_unique($ids));
            sort($ids);
            $new = implode(',', $ids);
            if ($new !== (string)$role['menu_ids']) {
                Db::name('sys_role')->where('id', $role['id'])->update(['menu_ids' => $new]);
            }
        }

        $row = Db::name('system_config')->where('config_key', $flag)->find();
        if ($row) {
            Db::name('system_config')->where('id', $row['id'])->update(['config_value' => '1']);
        } else {
            Db::name('system_config')->insert([
                'config_key'   => $flag,
                'config_value' => '1',
                'remark'       => 'v2.62 政务网进度菜单（58/59）一次性授权标记',
            ]);
        }
    }

    /** 记录已应用版本（下次请求直接跳过，零额外查询开销） */
    protected static function markVersion(): void
    {
        try {
            $row = Db::name('system_config')->where('config_key', 'menu_catalog_version')->find();
            if ($row) {
                Db::name('system_config')->where('id', $row['id'])->update(['config_value' => self::VERSION]);
            } else {
                Db::name('system_config')->insert([
                    'config_key' => 'menu_catalog_version', 'config_value' => self::VERSION,
                    'remark' => '菜单蓝图版本（改动 MenuCatalog::blueprint 后请 +1 以触发重刷）',
                ]);
            }
        } catch (\Throwable $e) {
            // 忽略
        }
    }

    /**
     * 分组统计：供 PC 侧边栏与 H5 分组导航共用
     * @return array [{id,menu_name,icon,sort,total,paths:[]}]
     */
    public static function groupStat(): array
    {
        $rows = SysMenu::where('status', 1)->order('sort', 'asc')->select()->toArray();
        $groups = [];
        $ids = [];
        foreach ($rows as $r) { $ids[(int)$r['id']] = $r; }

        foreach ($rows as $r) {
            $pid = (int)$r['parent_id'];
            if ($pid === 0) {
                $groups[$pid] = $groups[$pid] ?? [
                    'id' => $pid, 'menu_name' => $r['menu_name'], 'icon' => $r['icon'],
                    'sort' => (int)$r['sort'], 'total' => 0, 'children' => [],
                ];
                continue;
            }
            if (!isset($ids[$pid])) {
                continue;
            }
            $groups[$pid] = $groups[$pid] ?? [
                'id' => $pid, 'menu_name' => $ids[$pid]['menu_name'], 'icon' => $ids[$pid]['icon'],
                'sort' => (int)$ids[$pid]['sort'], 'total' => 0, 'children' => [],
            ];
            $groups[$pid]['children'][] = [
                'id' => (int)$r['id'], 'menu_name' => $r['menu_name'],
                'path' => $r['path'], 'icon' => $r['icon'],
            ];
            $groups[$pid]['total']++;
        }

        $out = array_values($groups);
        usort($out, fn($a, $b) => $a['sort'] <=> $b['sort']);
        return $out;
    }
}