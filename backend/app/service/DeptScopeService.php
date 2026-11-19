<?php
namespace app\service;

use app\model\OrgDepartment;
use app\model\SysUser;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;
use Throwable;

/**
 * 部门数据权限服务（v2.14）
 *
 *  - 数据范围四级：all 全公司 / dept_tree 本部门及以下 / dept 本部门 / self 仅自己
 *  - 一人多部门（user_dept_rel + sys_user.dept_id 主部门），部门集合取并集；
 *    范围级别取用户配置（多部门共享同一范围配置）。
 *  - 部门树递归：一次取全表 id/parent_id 内存递归，结果缓存 60 秒；
 *    通讯录同步后调用 clearCache() 立即生效。
 *  - 查询注入：手动调用 applyCertificate()/applyStaff()（与现有 scope() 风格一致），
 *    不做全局 scope，避免影响未适配模块。
 *  - 兜底：用户未配置（data_scope 为空/非法）按 self 处理（最小权限）。
 */
class DeptScopeService
{
    /** 范围级别（数值越大权限越高） */
    public const LEVELS = ['self' => 1, 'dept' => 2, 'dept_tree' => 3, 'all' => 4];

    const CACHE_KEY_DEPT_ALL = 'deptscope_dept_all';
    const CACHE_TTL = 60;

    /**
     * 清空部门树缓存（通讯录同步 / 部门调整后调用）
     *
     * v2.81.4：缓存只是加速层，清理失败不能影响业务调用方
     * （通讯录同步等流程不应因为 Redis 异常整体失败）。
     */
    public static function clearCache(): void
    {
        try {
            Cache::delete(self::CACHE_KEY_DEPT_ALL);
        } catch (Throwable $e) {
            Log::warning('[deptscope] 清理部门树缓存失败：' . $e->getMessage());
        }
    }

    /**
     * 全部门表 id => parent_id 映射（缓存 60s）
     *
     * v2.81.4：缓存是纯加速层，读/写缓存失败必须降级为直接查库，
     * 否则 Redis 连接抖动或缓存 key 类型异常会让「数据权限」这条
     * 所有列表共用的链路整体报错（证照管理员点合同管理 503 的根因）。
     *
     * @return array<int,int>
     */
    public static function deptMap(): array
    {
        try {
            $map = Cache::get(self::CACHE_KEY_DEPT_ALL);
            if (is_array($map) && $map) {
                return $map;
            }
        } catch (Throwable $e) {
            Log::warning('[deptscope] 读取部门树缓存失败，已回退数据库：' . $e->getMessage());
        }

        $map = OrgDepartment::column('parent_id', 'id');
        foreach ($map as $k => $v) {
            $map[(int)$k] = (int)$v;
        }

        try {
            Cache::set(self::CACHE_KEY_DEPT_ALL, $map, self::CACHE_TTL);
        } catch (Throwable $e) {
            Log::warning('[deptscope] 写入部门树缓存失败，本次直接使用数据库结果：' . $e->getMessage());
        }
        return $map;
    }

    /**
     * 某部门及其全部子部门 ID（递归）
     * @return array<int>
     */
    public static function deptIdsWithChildren(int $deptId): array
    {
        if ($deptId <= 0) {
            return [];
        }
        $map = self::deptMap();
        $out = [$deptId];
        // BFS 展开子部门
        $queue = [$deptId];
        $seen  = [$deptId => true];
        while ($queue) {
            $cur = array_shift($queue);
            foreach ($map as $id => $pid) {
                if ((int)$pid === $cur && empty($seen[$id])) {
                    $seen[$id] = true;
                    $out[] = $id;
                    $queue[] = $id;
                }
            }
        }
        return $out;
    }

    /**
     * 某部门 + 其全部上级祖先部门 ID（含自身）
     *
     * 用途：配证任务「可见部门」这类场景，语义是「选中部门及其下属子部门可见」。
     * 与其在查询时把「选中部门」展开成子部门，不如把「用户所在部门」向上展开成祖先链，
     * 再用 FIND_IN_SET(祖先, visible_dept_ids) 判断——
     * 这样存储的是原始选择（回显友好），且 SQL 依然是简单等值判断。
     *
     * @param array<int|string> $deptIds
     * @return array<int>
     */
    public static function ancestorIds(array $deptIds): array
    {
        $map  = self::deptMap();
        $out  = [];
        foreach ($deptIds as $d) {
            $cur   = (int)$d;
            $guard = 0;
            while ($cur > 0 && $guard++ < 100) {
                if (isset($out[$cur])) {
                    break; // 已经处理过该分支
                }
                $out[$cur] = true;
                $cur = (int)($map[$cur] ?? 0);
            }
        }
        return array_keys($out);
    }

    /**
     * 用户全部部门 ID（主部门 + user_dept_rel 多部门，取并集）
     * @return array<int>
     */
    public static function userDeptIds(int $userId): array
    {
        $ids = [];
        $primary = (int)(SysUser::where('id', $userId)->value('dept_id') ?? 0);
        if ($primary > 0) {
            $ids[] = $primary;
        }
        $rel = Db::name('user_dept_rel')->where('user_id', $userId)->column('dept_id');
        foreach ($rel as $d) {
            $d = (int)$d;
            if ($d > 0 && !in_array($d, $ids, true)) {
                $ids[] = $d;
            }
        }
        return $ids;
    }

    /**
     * 解析用户数据权限
     * @param array $user request->user（token payload，需含 id / role_code）
     * @return array{scope:string, dept_ids:array, level:int}
     */
    public static function resolve(array $user): array
    {
        if (($user['role_code'] ?? '') === 'admin') {
            return ['scope' => 'all', 'dept_ids' => [], 'level' => self::LEVELS['all']];
        }
        $row = SysUser::where('id', (int)$user['id'])->field('id,data_scope,dept_id')->find();
        $scope = strtolower((string)($row->data_scope ?? ''));
        if (!isset(self::LEVELS[$scope])) {
            $scope = 'self'; // 兜底：未配置按最小权限
        }
        $deptIds = [];
        if ($scope === 'dept_tree' || $scope === 'dept') {
            $base = self::userDeptIds((int)$user['id']);
            if ($scope === 'dept_tree') {
                $all = [];
                foreach ($base as $d) {
                    foreach (self::deptIdsWithChildren($d) as $x) {
                        $all[$x] = true;
                    }
                }
                $deptIds = array_keys($all);
            } else {
                $deptIds = $base;
            }
        }
        return ['scope' => $scope, 'dept_ids' => array_values($deptIds), 'level' => self::LEVELS[$scope]];
    }

    /**
     * 证照列表注入部门数据权限（certificate.staff_id → staff.dept_id）
     * self 范围按 create_by 过滤。
     */
    public static function applyCertificate($query, array $user)
    {
        $r = self::resolve($user);
        switch ($r['scope']) {
            case 'all':
                break;
            case 'dept_tree':
            case 'dept':
                $staffIds = $r['dept_ids']
                    ? Db::name('staff')->whereIn('dept_id', $r['dept_ids'])->column('id')
                    : [];
                $query->whereIn('staff_id', $staffIds ?: [0]);
                break;
            case 'self':
            default:
                $query->where('create_by', (int)($user['id'] ?? 0));
                break;
        }
        return $query;
    }

    /**
     * 人员列表注入部门数据权限（staff.dept_id / create_by）
     */
    public static function applyStaff($query, array $user)
    {
        $r = self::resolve($user);
        switch ($r['scope']) {
            case 'all':
                break;
            case 'dept_tree':
            case 'dept':
                $query->whereIn('dept_id', $r['dept_ids'] ?: [0]);
                break;
            case 'self':
            default:
                $query->where('create_by', (int)($user['id'] ?? 0));
                break;
        }
        return $query;
    }

    /**
     * 企业客户列表注入部门数据权限（v2.67）
     *
     * 企业表无 dept_id，通过负责人 owner_user_id 归属部门：
     *  - all：不限制；
     *  - dept_tree / dept：负责人（sys_user.dept_id）在数据范围内；
     *  - self：仅当前用户负责的企业。
     */
    public static function applyCompany($query, array $user)
    {
        $r = self::resolve($user);
        switch ($r['scope']) {
            case 'all':
                break;
            case 'dept_tree':
            case 'dept':
                $ownerIds = $r['dept_ids']
                    ? Db::name('sys_user')->whereIn('dept_id', $r['dept_ids'])->column('id')
                    : [];
                $query->whereIn('owner_user_id', $ownerIds ?: [0]);
                break;
            case 'self':
            default:
                $query->where('owner_user_id', (int)($user['id'] ?? 0));
                break;
        }
        return $query;
    }
}
