<?php
namespace app\service;

use app\model\SysRole;

/**
 * 功能权限目录（v2.69 RBAC 重构）
 *
 * 设计说明：
 *  - 菜单权限仍由 sys_role.menu_ids 控制「能看到哪些页面」；
 *  - 功能权限（按钮/写操作）由 sys_role.perms 存**扁平权限码**：
 *      {"cert:create":1,"cert:edit":1,"cert:export":1}
 *    权限码统一为「模块:动作」，与后端 AdminController::can()、
 *    PermissionMap 中间件、前端 user.can() 完全同源；
 *  - 旧版嵌套结构 {"cert":{"create":1}} 仍可读取（rolePerms 会自动拍平），
 *    避免升级瞬间自定义角色全部失效；
 *  - NULL 仅表示「超级管理员不限制」，非 admin 角色 NULL = 无任何功能权限
 *    （默认拒绝），不再沿用 v2.14「NULL=不限制」的危险语义。
 */
class PermissionCatalog
{
    /** 权限目录版本：改动模块/动作后 +1，用于角色默认授权矩阵的一次性对齐 */
    public const VERSION = 'v2.100';

    /**
     * 模块与动作蓝图
     * 字段：label 名称 / menu 菜单ID / actions 动作 => 显示名
     */
    public static function modules(): array
    {
        $modules = [
            'cert' => ['label' => '证照台账', 'menu' => 5, 'actions' => [
                'create' => '新增', 'edit' => '编辑', 'delete' => '删除',
                'export' => '导出', 'import' => '导入', 'status' => '状态变更',
            ]],
            'staff' => ['label' => '人员档案', 'menu' => 12, 'actions' => [
                'create' => '新增', 'edit' => '编辑', 'delete' => '删除',
                'export' => '导出', 'import' => '导入',
                // v2.99.59：查看/复制身份证明文（受控出口，含审计；导出是否含明文同此权限）
                'reveal' => '查看明文',
            ]],
            'company' => ['label' => '企业客户', 'menu' => 11, 'actions' => [
                'create' => '新增', 'edit' => '编辑', 'delete' => '删除',
                'export' => '导出', 'import' => '导入', 'qualification' => '资质采集', 'status' => '状态变更',
            ]],
            // v2.94：企业证书（安许 / 资质）集中台账（增改删查 + 导入导出 + 附件）
            'companycert' => ['label' => '企业证书', 'menu' => 68, 'actions' => [
                'create' => '新增', 'edit' => '编辑', 'delete' => '删除',
                'export' => '导出', 'import' => '导入', 'attach' => '附件', 'collect' => '平台采集',
            ]],
            'contract' => ['label' => '服务合同', 'menu' => 13, 'actions' => [
                'create' => '新增', 'edit' => '编辑', 'delete' => '删除',
            ]],
            'crm' => ['label' => '客户跟进', 'menu' => 56, 'actions' => [
                'transfer' => '负责人移交', 'follow' => '跟进记录',
            ]],
            'renewal' => ['label' => '证书延续', 'menu' => 7, 'actions' => [
                'create' => '新增', 'edit' => '编辑', 'delete' => '删除',
            ]],
            'secondment' => ['label' => '证书借调', 'menu' => 26, 'actions' => [
                'create' => '新增', 'edit' => '编辑', 'delete' => '删除', 'return' => '归还',
            ]],
            'alert' => ['label' => '到期提醒', 'menu' => 6, 'actions' => [
                'read' => '标记已读', 'resend' => '重新推送',
            ]],
            'project' => ['label' => '项目配证', 'menu' => 41, 'actions' => [
                'create' => '新增', 'edit' => '编辑', 'delete' => '删除',
                'export' => '导出', 'status' => '状态变更', 'import' => '导入',
            ]],
            'task' => ['label' => '配证任务', 'menu' => 33, 'actions' => [
                'create' => '新增', 'edit' => '编辑', 'delete' => '删除', 'publish' => '发布',
            ]],
            'match' => ['label' => '智能匹配', 'menu' => 42, 'actions' => [
                'run' => '执行匹配', 'select' => '选用', 'giveup' => '放弃',
            ]],
            'conflict' => ['label' => '冲突中心', 'menu' => 43, 'actions' => [
                'scan' => '执行体检', 'rules' => '规则设置',
            ]],
            'policy' => ['label' => '属地政策规则', 'menu' => 44, 'actions' => [
                'create' => '新增', 'edit' => '编辑', 'delete' => '删除',
                'export' => '导出', 'status' => '状态变更', 'import' => '导入',
            ]],
            'fee' => ['label' => '费用台账', 'menu' => 9, 'actions' => [
                'create' => '新增', 'edit' => '编辑', 'delete' => '删除',
                'export' => '导出', 'settle' => '结算',
            ]],
            'pay' => ['label' => '收款账户', 'menu' => 54, 'actions' => [
                'create' => '新增', 'edit' => '编辑', 'delete' => '删除',
            ]],
            'gov' => ['label' => '申报进度', 'menu' => 59, 'actions' => [
                'create' => '新增', 'edit' => '编辑', 'delete' => '删除',
                'export' => '导出', 'import' => '导入', 'sync' => '同步', 'reveal' => '查看密码',
            ]],
            'vault' => ['label' => '账号密码库', 'menu' => 60, 'actions' => [
                'create' => '新增', 'edit' => '编辑', 'delete' => '删除',
                'export' => '导出', 'import' => '导入', 'reveal' => '查看明文',
            ]],
            'push' => ['label' => '推送渠道', 'menu' => 15, 'actions' => [
                'create' => '新增', 'edit' => '编辑', 'delete' => '删除',
                'test' => '测试推送', 'run' => '手动检测',
            ]],
            'template' => ['label' => '消息模板', 'menu' => 16, 'actions' => [
                'create' => '新增', 'edit' => '编辑', 'delete' => '删除',
            ]],
            'receiver' => ['label' => '企业接收人', 'menu' => 17, 'actions' => [
                'create' => '新增', 'edit' => '编辑', 'delete' => '删除',
            ]],
            'pushlog' => ['label' => '推送日志', 'menu' => 18, 'actions' => [
                'delete' => '清空',
            ]],
            // v2.99.40：登录日志清空（PermissionMap 已按 loginlog:clear 校验，原目录缺失导致无法授权）
            'loginlog' => ['label' => '登录日志', 'menu' => 61, 'actions' => [
                'clear' => '清空日志',
            ]],
            'register' => ['label' => '注册轨迹', 'menu' => 31, 'actions' => [
                'edit' => '编辑', 'delete' => '删除',
            ]],
            'recycle' => ['label' => '回收站', 'menu' => 55, 'actions' => [
                'restore' => '恢复', 'purge' => '彻底删除',
            ]],
            'approval' => ['label' => '审批管理', 'menu' => 37, 'actions' => [
                'edit' => '发起/处理',
            ]],
            // v2.82：Agent 接入密钥（写操作默认仅超级管理员，控制器内 requireAdmin 再兜底）
            // v2.98：新增 view 只读动作，允许非超管角色查看密钥列表/能力清单/接入文档
            'agent' => ['label' => 'Agent 接入', 'menu' => 62, 'actions' => [
                'view' => '查看接入', 'create' => '新建密钥', 'edit' => '修改密钥',
                'revoke' => '吊销/启用', 'delete' => '删除密钥',
            ]],

            // ===== v2.85 考试报名 =====
            'examcat' => ['label' => '报名-证书类目', 'menu' => 67, 'actions' => [
                'create' => '新增类目', 'edit' => '编辑类目', 'delete' => '删除类目',
            ]],
            'examconfig' => ['label' => '报名-业务配置', 'menu' => 66, 'actions' => [
                'create' => '新增配置', 'edit' => '编辑配置',
                'delete' => '删除配置', 'status' => '开放/停办',
            ]],
            'exambatch' => ['label' => '报名-批次考点', 'menu' => 65, 'actions' => [
                'create' => '新增批次考点', 'edit' => '编辑批次考点',
                'delete' => '删除批次考点', 'status' => '开放报名/设当前',
            ]],
            'examsignup' => ['label' => '报名-报名工单', 'menu' => 64, 'actions' => [
                'create' => '创建报名单', 'edit' => '编辑/材料/考生',
                'delete' => '删除报名单', 'status' => '状态流转',
                'audit' => '审核通过/驳回',
            ]],
            // v2.99.70：安管人员成绩查询
            'examscore' => ['label' => '报名-成绩查询', 'menu' => 79, 'actions' => [
                'query' => '查询成绩', 'delete' => '删除查询记录',
            ]],

            // ===== v2.99 学历报名 =====
            'edusignup' => ['label' => '学历报名工单', 'menu' => 71, 'actions' => [
                'create' => '创建报名单', 'edit' => '编辑/附件/人员',
                'delete' => '删除报名单', 'status' => '进度流转/反归档',
            ]],
            'eduagency' => ['label' => '学历报名机构', 'menu' => 72, 'actions' => [
                'create' => '新增机构', 'edit' => '编辑机构', 'delete' => '删除机构',
            ]],
            'educonfig' => ['label' => '学历流程配置', 'menu' => 73, 'actions' => [
                'edit' => '自定义进度流程',
            ]],

            // ===== v2.99.1：在线升级（敏感功能，默认仅超级管理员） =====
            'upgrade' => ['label' => '在线升级', 'menu' => 74, 'actions' => [
                'view' => '查看升级', 'run' => '执行升级/备份/恢复',
            ]],

            // ===== v2.100：微信小程序接入（默认仅超级管理员） =====
            'miniapp' => ['label' => '小程序接入', 'menu' => 75, 'actions' => [
                'save' => '保存配置', 'token' => '重置接入Token', 'audit' => '审核模式',
            ]],
        ];

        // 飞牛版关闭在线升级时，功能权限目录同步移除，角色授权页不再展示。
        if (!UpgradeService::enabled()) {
            unset($modules['upgrade']);
        }

        return $modules;
    }

    /** 某模块的全部权限码 */
    public static function codesOf(string $mod): array
    {
        $m = self::modules()[$mod] ?? null;
        if (!$m) {
            return [];
        }
        $out = [];
        foreach (array_keys($m['actions']) as $act) {
            $out[] = $mod . ':' . $act;
        }
        return $out;
    }

    /**
     * 按模块展开为权限码列表
     * @param array $mods ['cert','staff'] 或 ['cert'=>['create','edit']]
     */
    public static function codesFor(array $mods, array $only = []): array
    {
        $out = [];
        foreach ($mods as $key => $val) {
            if (is_int($key)) {
                $mod = (string)$val;
                $actions = array_keys(self::modules()[$mod]['actions'] ?? []);
            } else {
                $mod = (string)$key;
                $actions = is_array($val) ? $val : array_keys(self::modules()[$mod]['actions'] ?? []);
            }
            if ($only && !in_array($mod, $only, true)) {
                continue;
            }
            foreach ($actions as $act) {
                if (isset(self::modules()[$mod]['actions'][$act])) {
                    $out[] = $mod . ':' . $act;
                }
            }
        }
        return array_values(array_unique($out));
    }

    /** 全部权限码 */
    public static function allCodes(): array
    {
        $out = [];
        foreach (self::modules() as $mod => $m) {
            foreach (array_keys($m['actions']) as $act) {
                $out[] = $mod . ':' . $act;
            }
        }
        return $out;
    }

    /**
     * 把任意历史结构归一为扁平权限码映射
     * 支持：{"cert:create":1} / {"cert":{"create":1,"edit":0}} / ["cert:create",...]
     */
    public static function normalize($perms): array
    {
        if ($perms === null || $perms === '' || $perms === 'null') {
            return [];
        }
        if (is_string($perms)) {
            $perms = json_decode($perms, true);
        }
        if (!is_array($perms)) {
            return [];
        }
        $out = [];
        foreach ($perms as $k => $v) {
            if (is_int($k) && is_string($v) && strpos($v, ':') !== false) {
                $out[$v] = 1;
                continue;
            }
            if (is_string($k) && strpos($k, ':') !== false) {
                if ($v) {
                    $out[$k] = 1;
                }
                continue;
            }
            // 旧嵌套：mod => {act: 1}
            if (is_string($k) && is_array($v)) {
                foreach ($v as $act => $on) {
                    if ($on) {
                        $out[$k . ':' . $act] = 1;
                    }
                }
            }
        }
        return $out;
    }

    /** 读取角色的扁平权限码；admin 返回 null（不限制） */
    public static function rolePerms(int $roleId): ?array
    {
        if ($roleId <= 0) {
            return [];
        }
        $role = SysRole::find($roleId);
        if (!$role) {
            return [];
        }
        if ((string)$role->role_code === 'admin') {
            return null;
        }
        return self::normalize($role->perms);
    }

    /** 判断权限码集合是否包含某动作 */
    public static function allows(?array $codes, string $mod, string $act): bool
    {
        if ($codes === null) {
            return true; // 仅 admin
        }
        return isset($codes[$mod . ':' . $act]) || isset($codes[$mod . ':*']);
    }

    /**
     * 前端授权树数据：模块 → 动作
     * @return array [{mod,label,menu,actions:[{act,label,code}]}]
     */
    public static function tree(): array
    {
        $out = [];
        foreach (self::modules() as $mod => $m) {
            $actions = [];
            foreach ($m['actions'] as $act => $label) {
                $actions[] = ['act' => $act, 'label' => $label, 'code' => $mod . ':' . $act];
            }
            $out[] = [
                'mod'     => $mod,
                'label'   => $m['label'],
                'menu'    => (int)$m['menu'],
                'actions' => $actions,
            ];
        }
        return $out;
    }
}
