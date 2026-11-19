<?php
namespace app\service;

use think\facade\Db;

/**
 * 角色组蓝图与运行时自愈（v2.30 引入，v2.69 RBAC 重构）
 *
 * 本系统是公司内部业务系统（OA 形态），按「岗位分工」组织角色组。
 * 单角色模型：sys_user.role_id → sys_role（不做一人多角色）。
 *
 * v2.69 起角色包含两部分授权：
 *   - menu_ids：可访问的菜单（页面）ID，空 = 不限制（仅 admin）；
 *   - perms   ：功能权限码 {"cert:create":1,...}，见 PermissionCatalog。
 *
 * 蓝图为准：VERSION 变更时，蓝图内置角色的菜单与功能权限**整体对齐**
 * （可增可减），彻底解决历史「MenuRoleSync 只增不减」造成的授权漂移。
 * 同一版本内管理员的手工调整会保留，另提供「恢复默认授权」按钮。
 */
class RoleCatalog
{
    /** 角色蓝图版本：改动角色/默认授权后 +1，老库自动对齐一次 */
    // v2.99.70：新增菜单 79（成绩查询）并授权证照管理员/项目配证专员。
    public const VERSION = 'v2.101';

    /** 进程内只对齐一次，避免同一请求内 sys_config 缓存导致反复重刷 */
    protected static $synced = false;

    /**
     * 角色组蓝图
     * 字段：id, role_code, role_name, menu_ids, remark, status
     * menu_ids 为空字符串 = 不限制（仅超级管理员）
     */
    public static function blueprint(): array
    {
        return [
            [1, 'admin', '超级管理员', '',
                '系统超级管理员，拥有全部功能与数据权限', 1],
            [4, 'cert_manager', '证照管理员',
                '1,2,3,31,57,11,68,13,56,50,12,5,7,14,6,51,26,8,40,41,33,42,43,44,58,59,60,63,64,65,66,67,79,70,71,72,73,19,62',
                '负责人员档案、证照台账、证书延续、借调、提醒、客户跟进、项目配证、政务网进度、考试报名、成绩查询、学历报名与 Agent 接入（只读）', 1],
            [5, 'project_officer', '项目配证专员',
                '1,2,3,31,57,68,40,41,33,42,43,44,58,59,60,63,64,65,79',
                '负责项目配证、智能匹配、冲突处置、属地政策与报名工单/批次考点/成绩查询', 1],
            [6, 'finance', '财务专员',
                '1,2,3,57,13,52,9,54',
                '负责费用台账、收款账户与服务合同', 1],
            [2, 'company', '企业查看者',
                '1,2,3,31,57,11,68,56,50,12,5,14,51,26,6,63,64,70,71',
                '客户企业账号，仅查看/提交本企业相关数据（考试/学历报名工单可建单）', 1],
            [7, 'viewer', '只读观察员',
                '1,2,3,31,57,11,68,13,56,50,12,5,7,14,40,41,33,42,43,44,51,26,6,8,58,59,60,52,9,54,63,64,65,66,67',
                '全业务只读查看，含提醒中心；不含推送渠道/模板/接收人/日志管理', 1],
        ];
    }

    /**
     * 内置角色的默认功能权限
     * @return array|null null = 不限制（仅 admin）
     */
    public static function defaultPerms(string $roleCode): ?array
    {
        switch ($roleCode) {
            case 'admin':
                return null;
            case 'cert_manager':
                return PermissionCatalog::codesFor([
                    // v2.99.59：staff 改为显式动作列表，默认不授予 reveal（看明文需管理员单独勾选）
                    'staff'   => ['create', 'edit', 'delete', 'export', 'import'],
                    'cert', 'company', 'companycert', 'contract', 'crm',
                    'renewal', 'secondment', 'alert',
                    // v2.98：项目与配证整组全动作
                    'project', 'task', 'match', 'conflict', 'policy',
                    'gov', 'vault',
                    'examcat', 'examconfig', 'exambatch', 'examsignup',
                    // v2.99.70：成绩查询
                    'examscore',
                    // v2.99：学历报名整组全动作
                    'edusignup', 'eduagency', 'educonfig',
                    // v2.98：Agent 接入仅只读查看，写操作（新建/修改/吊销/删除）仍仅超管
                    'agent' => ['view'],
                ]);
            case 'project_officer':
                return PermissionCatalog::codesFor([
                    'project', 'task', 'match', 'conflict', 'policy', 'gov', 'vault',
                    'examsignup', 'exambatch',
                    // v2.99.70：成绩查询
                    'examscore',
                ]);
            case 'finance':
                return PermissionCatalog::codesFor(['fee', 'pay', 'contract']);
            case 'company':
                // 客户企业：可为本单位创建/编辑报名单与上传材料，但不能审核/删除/流转/配置
                return PermissionCatalog::codesFor([
                    'examsignup' => ['create', 'edit'],
                    // v2.99：学历报名可建单/编辑/传附件，不能流转/删除/配置机构与流程
                    'edusignup'  => ['create', 'edit'],
                ]);
            case 'viewer':
                // 只读角色：不授予任何写操作
                return [];
            default:
                return [];
        }
    }

    /**
     * 幂等自愈：补齐蓝图角色；版本变更时整体对齐菜单与功能权限
     * @return bool 本次是否执行了「蓝图为准」的整体对齐（调用方据此清缓存）
     */
    public static function ensure(): bool
    {
        if (self::$synced) {
            return false;
        }
        self::$synced = true;

        try {
            $force = self::appliedVersion() !== self::VERSION;
        } catch (\Throwable $e) {
            return false;
        }

        try {
            foreach (self::blueprint() as [$id, $code, $name, $menuIds, $remark, $status]) {
                $exist = Db::name('sys_role')->where('role_code', $code)->find();
                if (!$exist) {
                    $data = [
                        'role_code' => $code, 'role_name' => $name,
                        'menu_ids'  => $menuIds, 'remark' => $remark, 'status' => $status,
                        'perms'     => self::encodePerms(self::defaultPerms($code)),
                    ];
                    if (!Db::name('sys_role')->where('id', $id)->find()) {
                        $data['id'] = $id;
                    }
                    Db::name('sys_role')->insert($data);
                    continue;
                }

                $dirty = [];
                if ((string)$exist['role_name'] !== $name) { $dirty['role_name'] = $name; }
                if ((string)($exist['remark'] ?? '') !== $remark) { $dirty['remark'] = $remark; }
                if ((int)$exist['status'] !== $status) { $dirty['status'] = $status; }

                if ($force) {
                    // 蓝图为准：菜单与功能权限整体覆盖（可增可减）
                    $dirty['menu_ids'] = $menuIds;
                    $dirty['perms']    = self::encodePerms(self::defaultPerms($code));
                } elseif ($code === 'admin' && trim((string)($exist['menu_ids'] ?? '')) !== '') {
                    // 非版本变更时仍保证 admin 不被锁死
                    $dirty['menu_ids'] = '';
                }

                if ($dirty) {
                    Db::name('sys_role')->where('id', $exist['id'])->update($dirty);
                }
            }

            if ($force) {
                self::retireRemovedRoles();
                self::markVersion();
            }
        } catch (\Throwable $e) {
            // 角色自愈失败不阻断主流程
            return false;
        }

        return $force;
    }

    /** 恢复某个内置角色为蓝图默认授权（角色页「恢复默认」按钮） */
    public static function resetRole(int $roleId): bool
    {
        $role = Db::name('sys_role')->where('id', $roleId)->find();
        if (!$role) {
            return false;
        }
        foreach (self::blueprint() as [$id, $code, $name, $menuIds, $remark, $status]) {
            if ($code !== (string)$role['role_code']) {
                continue;
            }
            Db::name('sys_role')->where('id', $roleId)->update([
                'menu_ids' => $menuIds,
                'perms'    => self::encodePerms(self::defaultPerms($code)),
            ]);
            return true;
        }
        return false;
    }

    /**
     * 退役已从蓝图删除的角色（当前仅 operator）。
     * 无账号在用 → 物理删除；仍有账号 → 仅停用，避免破坏历史账号登录。
     */
    protected static function retireRemovedRoles(): void
    {
        $codes = [];
        foreach (self::blueprint() as [$id, $code]) {
            $codes[] = $code;
        }
        $removed = Db::name('sys_role')
            ->whereNotIn('role_code', $codes ?: ['__none__'])
            ->where('role_code', 'operator')
            ->select()->toArray();
        foreach ($removed as $r) {
            $used = Db::name('sys_user')->where('role_id', (int)$r['id'])->count();
            if ($used > 0) {
                Db::name('sys_role')->where('id', (int)$r['id'])->update([
                    'status' => 0,
                    'remark' => '已从角色蓝图移除（仍有账号在用，请先调整账号角色）',
                ]);
            } else {
                Db::name('sys_role')->where('id', (int)$r['id'])->delete();
            }
        }
    }

    /** 读取已应用版本（直接查库，避免 sys_config 请求内缓存干扰） */
    protected static function appliedVersion(): string
    {
        $v = Db::name('system_config')->where('config_key', 'role_catalog_version')->value('config_value');
        return trim((string)$v);
    }

    protected static function markVersion(): void
    {
        $row = Db::name('system_config')->where('config_key', 'role_catalog_version')->find();
        if ($row) {
            Db::name('system_config')->where('id', $row['id'])->update([
                'config_value' => self::VERSION,
                'update_time'  => date('Y-m-d H:i:s'),
            ]);
        } else {
            Db::name('system_config')->insert([
                'config_key'   => 'role_catalog_version',
                'config_value' => self::VERSION,
                'remark'       => '角色蓝图版本（改动 RoleCatalog 后请 +1 以触发一次整体对齐）',
            ]);
        }
    }

    /**
     * 补齐菜单授权的祖先节点（v2.99.1 / BUG-08）
     *
     * 菜单树是「父分组 → 子菜单」结构，前端只渲染从根可达的节点。
     * 若管理员只勾选了一个子菜单（例如「证照台账」），却没勾它的父分组
     * （「人员与证书」），该子菜单在侧栏永远不可见 —— 表现为「刚授权就消失」。
     *
     * 保存角色时统一补齐祖先链，既修复「功能消失」，也顺带清掉
     * 诊断报告里的「孤儿节点」。空字符串表示不限制（admin），原样返回。
     *
     * @param int[] $menuIds
     * @return int[]
     */
    public static function withAncestors(array $menuIds): array
    {
        $ids = [];
        foreach ($menuIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        if (!$ids) {
            return [];
        }

        // 逐层向上补父节点；菜单表很小，最多两三层，循环上限防御脏数据自环
        $pending = array_keys($ids);
        for ($depth = 0; $depth < 10 && $pending; $depth++) {
            $rows = Db::name('sys_menu')->whereIn('id', $pending)->field('id,parent_id')->select()->toArray();
            $pending = [];
            foreach ($rows as $row) {
                $pid = (int)$row['parent_id'];
                if ($pid > 0 && !isset($ids[$pid])) {
                    $ids[$pid] = true;
                    $pending[] = $pid;
                }
            }
        }

        $out = array_keys($ids);
        sort($out);
        return $out;
    }

    protected static function encodePerms(?array $perms): ?string
    {
        if ($perms === null) {
            return null; // admin 不限制
        }
        return json_encode($perms, JSON_UNESCAPED_UNICODE);
    }

    /** 诊断：当前角色组与蓝图的一致性 */
    public static function diagnose(): array
    {
        $out = [];
        $codes = [];
        foreach (self::blueprint() as [$id, $code, $name]) {
            $codes[$code] = $name;
        }
        foreach (Db::name('sys_role')->select()->toArray() as $r) {
            $out[] = [
                'id'           => (int)$r['id'],
                'role_name'    => (string)$r['role_name'],
                'role_code'    => (string)$r['role_code'],
                'in_blueprint' => isset($codes[(string)$r['role_code']]),
                'menu_count'   => count(array_filter(explode(',', (string)($r['menu_ids'] ?? '')))),
                'perm_count'   => count(PermissionCatalog::normalize($r['perms'] ?? null)),
                'unrestricted' => trim((string)($r['menu_ids'] ?? '')) === '',
                'status'       => (int)$r['status'],
            ];
        }
        return $out;
    }
}
