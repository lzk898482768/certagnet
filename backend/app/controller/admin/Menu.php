<?php
namespace app\controller\admin;

use app\model\SysMenu;
use app\service\MenuCatalog;

class Menu extends AdminController
{
    public function index()
    {
        $this->requireAdmin();
        MenuCatalog::ensureSchema();
        $list = SysMenu::order('sort', 'asc')->select()->toArray();
        return json(r_success(SysMenu::buildTree($list)));
    }

    /**
     * 平铺菜单列表（供菜单管理页表格）
     * 默认返回数组（向后兼容既有调用方，如角色授权树）；
     * with_groups=1 时返回对象 {list, groups, catalog_version}，供 H5 分组导航复用。
     */
    public function list()
    {
        $this->requireAdmin();
        MenuCatalog::ensureSchema();
        $list = SysMenu::order('sort', 'asc')->select()->toArray();
        if ((int)$this->request->param('with_groups', 0) !== 1) {
            return json(r_success($list));
        }
        return json(r_success([
            'list'    => $list,
            'groups'  => MenuCatalog::groupStat(),
            'version' => MenuCatalog::VERSION,
        ]));
    }

    public function save()
    {
        $this->requireAdmin();
        $id = (int)$this->request->post('id', 0);
        $data = $this->request->post(['parent_id', 'menu_name', 'path', 'component', 'sort', 'status']);
        if (empty($data['menu_name'])) {
            return json(r_error('菜单名称必填', 400));
        }
        if ($id) {
            SysMenu::where('id', $id)->update($data);
            $this->log('菜单', '更新', "#{$id}");
        } else {
            SysMenu::create($data);
            $this->log('菜单', '新增');
        }
        return json(r_success([], '保存成功'));
    }

    public function delete()
    {
        $this->requireAdmin();
        $id = (int)$this->request->post('id', 0);
        if (SysMenu::where('parent_id', $id)->find()) {
            return json(r_error('请先删除子菜单', 400));
        }
        SysMenu::destroy($id);
        $this->log('菜单', '删除', "#{$id}");
        return json(r_success([], '删除成功'));
    }

    /**
     * 手动触发菜单蓝图重刷
     * 用途：管理员手工调整过菜单后想恢复 v2.28 标准架构时使用
     */
    public function resetCatalog()
    {
        $this->requireAdmin();
        MenuCatalog::flushThrottle();
        MenuCatalog::ensureSchema(true);
        // v2.99.1（BUG-08）：重置架构时顺带修复各角色的孤儿授权，
        // 否则「重置」只修菜单表、不修角色表，功能仍然看不见。
        $repaired = $this->repairOrphanMenus();
        $this->log('菜单', '重置架构', MenuCatalog::VERSION . ($repaired ? "，修复孤儿授权 {$repaired} 项" : ''));
        $list = SysMenu::order('sort', 'asc')->select()->toArray();
        return json(r_success([
            'list'         => $list,
            'groups'       => MenuCatalog::groupStat(),
            'version'      => MenuCatalog::VERSION,
            'repaired'     => $repaired,
        ], '菜单架构已重置为 ' . MenuCatalog::VERSION
            . ($repaired > 0 ? "，并修复 {$repaired} 项孤儿菜单授权" : '')));
    }

    /**
     * 修复各角色的孤儿菜单授权（v2.99.1 / BUG-08）。
     *
     * 只做「补齐祖先」，不删除任何已授权项：菜单被停用时可能是有意为之，
     * 贸然删授权会让管理员重新手工配置。
     *
     * @return int 实际被改写的角色数
     */
    private function repairOrphanMenus(): int
    {
        $fixed = 0;
        $roles = \think\facade\Db::name('sys_role')->field('id,menu_ids')->select()->toArray();
        foreach ($roles as $role) {
            $raw = trim((string)($role['menu_ids'] ?? ''));
            if ($raw === '') {
                continue; // 空 = 不限制（admin），不处理
            }
            $ids = array_values(array_filter(array_map('intval', explode(',', $raw))));
            $with = \app\service\RoleCatalog::withAncestors($ids);
            if ($with !== $ids) {
                \think\facade\Db::name('sys_role')->where('id', (int)$role['id'])
                    ->update(['menu_ids' => implode(',', $with)]);
                $fixed++;
            }
        }
        return $fixed;
    }

    /**
     * 菜单可见性诊断
     * 排查「重构后某些功能消失」：对比各角色已授权数、可见数与孤儿节点数
     */
    public function diagnose()
    {
        $this->requireAdmin();
        MenuCatalog::flushThrottle(); // 诊断需拿实时状态，先重置节流
        MenuCatalog::ensureSchema();
        $changes = \app\service\MenuRoleSync::syncAll();
        $roles = \app\service\MenuRoleSync::diagnose();
        // v2.99.1（BUG-08）：把孤儿节点展开成「名称 + 路径」，
        // 只给数量时管理员无法判断该补哪一个父级。
        $orphanNames = $this->orphanMenuNames($roles);
        foreach ($roles as &$role) {
            $role['orphan_menus'] = $orphanNames[(int)$role['role_id']] ?? [];
        }
        unset($role);
        return json(r_success([
            'roles'    => $roles,
            'role_groups' => \app\service\RoleCatalog::diagnose(),
            'changes'  => $changes,
            'version'  => MenuCatalog::VERSION,
            'top_groups' => SysMenu::where('parent_id', 0)->where('status', 1)
                ->order('sort', 'asc')->field('id,menu_name,sort')->select()->toArray(),
        ], count($changes) > 0
            ? ('已自动修复 ' . count($changes) . ' 个角色的菜单授权')
            : '菜单授权正常，无需修复'));
    }

    /**
     * 汇总各角色的孤儿菜单（已授权，但父级未授权 / 父级停用 / 菜单不存在）。
     * 与 MenuRoleSync::diagnose() 的 orphan 计数口径保持一致。
     *
     * @param array $roles MenuRoleSync::diagnose() 的结果
     * @return array<int,string[]> role_id => ['菜单名（/path）', ...]
     */
    private function orphanMenuNames(array $roles): array
    {
        $out = [];
        $roleIds = array_map(static fn($r) => (int)$r['role_id'], $roles);
        if (!$roleIds) {
            return $out;
        }
        $menuRows = SysMenu::field('id,parent_id,menu_name,path,status')->select()->toArray();
        $menuMap  = [];
        foreach ($menuRows as $m) {
            $menuMap[(int)$m['id']] = $m;
        }
        foreach (\think\facade\Db::name('sys_role')->whereIn('id', $roleIds)
                     ->field('id,menu_ids')->select()->toArray() as $role) {
            $ids = array_values(array_filter(array_map('intval', explode(',', (string)$role['menu_ids']))));
            if (!$ids) {
                continue;
            }
            $names = [];
            foreach ($ids as $id) {
                $m = $menuMap[$id] ?? null;
                if (!$m || (int)$m['status'] !== 1) {
                    continue; // 不存在 / 已停用：不计入「父级缺失」孤儿
                }
                $pid = (int)$m['parent_id'];
                if ($pid === 0) {
                    continue;
                }
                // 与 MenuRoleSync::diagnose() 同口径：父级必须「已授权 + 启用 + 存在」，
                // 否则前端侧栏渲染不到该子项。
                $parent = $menuMap[$pid] ?? null;
                if (!in_array($pid, $ids, true) || !$parent || (int)$parent['status'] !== 1) {
                    $label = (string)$m['menu_name'];
                    if ((string)($m['path'] ?? '') !== '') {
                        $label .= '（' . $m['path'] . '）';
                    }
                    $names[] = $label;
                }
            }
            if ($names) {
                $out[(int)$role['id']] = $names;
            }
        }
        return $out;
    }
}