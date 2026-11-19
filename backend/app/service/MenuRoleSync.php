<?php
namespace app\service;

use think\facade\Db;

/**
 * 角色菜单授权诊断（v2.28 引入，v2.69 重构）
 *
 * ⚠️ v2.69 起菜单授权对齐由 RoleCatalog 蓝图版本统一负责
 *    （可增可减，见 RoleCatalog::ensure()）。
 *    本类的 syncAll()/align() 历史上「只增不减」造成 company 角色越权、
 *    finance 空分组等漂移问题，现已停用，仅保留诊断能力。
 */
class MenuRoleSync
{
    /**
     * @deprecated v2.69 不再自动改写角色授权，保留空实现以兼容旧调用。
     */
    public static function syncAll(): array
    {
        return [];
    }

    /**
     * @deprecated v2.69 不再使用。
     */
    public static function align(int $roleId, string $menuIds): string
    {
        return $menuIds;
    }

    /**
     * 诊断：返回当前角色菜单可见性报告（用于排查「功能消失」问题）
     */
    public static function diagnose(): array
    {
        $out = [];
        foreach (Db::name('sys_role')->select()->toArray() as $r) {
            $ids = array_values(array_filter(array_map('intval', explode(',', (string)($r['menu_ids'] ?? '')))));
            $visible = 0;
            $orphan  = [];
            if ($ids) {
                $rows = Db::name('sys_menu')->whereIn('id', $ids)->where('status', 1)
                    ->field('id,parent_id,menu_name')->select()->toArray();
                $map = [];
                foreach ($rows as $m) {
                    $map[(int)$m['id']] = (int)$m['parent_id'];
                }
                foreach ($map as $id => $pid) {
                    if ($pid === 0) {
                        $visible++;
                        continue;
                    }
                    if (isset($map[$pid])) {
                        $visible++;
                    } else {
                        $orphan[] = $id;
                    }
                }
            }
            $out[] = [
                'role_id'    => (int)$r['id'],
                'role_name'  => (string)($r['role_name'] ?? ''),
                'authorized' => count($ids),
                'visible'    => $visible,
                'orphan'     => count($orphan),
            ];
        }
        return $out;
    }
}
