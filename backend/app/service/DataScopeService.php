<?php
namespace app\service;

use think\facade\Db;
use think\exception\HttpResponseException;

/**
 * 数据权限服务（v2.99.36 权限三轴解耦 P2）
 *
 * 替代 v2.14 的 DeptScopeService 部门四级范围（all/dept_tree/dept/self）。
 * 新模型按需求把模块分两组：
 *
 *  - 核心资源 core（client_company / staff / certificate）：
 *      内部办公账号默认「所有人」可见（all）；公海/共同所有人在 P3 落地后细化，
 *      本版先给出策略解析与注入入口，内部账号保持全量可见，与本次「按所有人配置」一致。
 *  - 其他模块 other（合同/费用/跟进/项目/借调/申报/账号库/报名等）：
 *      内部账号按「创建人 create_by = 本人」（self），可被角色/用户级策略放宽为 all。
 *
 * 客户侧账号（role_code=company，带 user_company_rel 企业授权）是独立边界：
 *   其数据由内部员工代为创建，本人通常不是 create_by，故不能套用创建人口径，
 *   仍按授权企业（companyIds）过滤，此分支在各控制器/本服务的 company* 方法中保留。
 *
 * 策略来源（优先级从高到低）：
 *   1. 用户级覆盖 sys_user_data_scope（无记录则下探）
 *   2. 角色级 sys_role.data_policy JSON（NULL 则用内置默认）
 *   3. 内置默认：core=all（含公海），other=self
 *
 * 设计参考成熟 RBAC 系统：功能鉴权（PermAuth）与数据范围（本服务）彻底分离，
 * 数据范围只负责「查询注入 where」与「对象级断言」，不参与菜单/按钮判定。
 */
class DataScopeService
{
    public const GROUP_CORE  = 'core';
    public const GROUP_OTHER = 'other';

    /** other 组合法取值 */
    public const OTHER_SCOPES = ['self', 'all'];
    /** core 组合法取值（pool/self 的公海与共有语义在 P3 启用，本版接受但按 all 兜底） */
    public const CORE_SCOPES  = ['all', 'pool', 'self'];

    /** 请求内缓存：user 标识 => 解析结果，避免同请求重复查库 */
    private static array $policyCache = [];

    /* ============================ 策略解析 ============================ */

    /**
     * 解析当前用户对某模块组的有效策略
     *
     * @param array  $user  request->user（含 id/role_id/role_code/companyIds 上下文外部传入）
     * @param string $group core|other
     * @return array{scope:string, include_pool:bool, all:bool}
     *               all=true 表示该组不做行级限制
     */
    public static function resolvePolicy(array $user, string $group): array
    {
        if (($user['role_code'] ?? '') === 'admin') {
            return ['scope' => 'all', 'include_pool' => true, 'all' => true];
        }
        $cacheKey = ($user['id'] ?? 0) . ':' . $group;
        if (isset(self::$policyCache[$cacheKey])) {
            return self::$policyCache[$cacheKey];
        }

        // 内置默认：核心资源所有人可见，其他模块仅本人创建
        $default = $group === self::GROUP_CORE
            ? ['scope' => 'all', 'include_pool' => true]
            : ['scope' => 'self', 'include_pool' => false];

        $scope = $default['scope'];
        $includePool = $default['include_pool'];

        try {
            // 1) 角色级 data_policy
            $rolePolicy = self::rolePolicy((int)($user['role_id'] ?? 0));
            if (isset($rolePolicy[$group]['scope'])) {
                $scope = (string)$rolePolicy[$group]['scope'];
                $includePool = (bool)($rolePolicy[$group]['include_pool']
                    ?? ($rolePolicy[$group]['pool'] ?? $includePool));
            }
            // 2) 用户级覆盖（优先于角色）
            $override = Db::name('sys_user_data_scope')
                ->where('user_id', (int)($user['id'] ?? 0))
                ->where('scope_group', $group)
                ->find();
            if ($override) {
                $scope = (string)$override['scope'];
                $includePool = (bool)$override['include_pool'];
            }
        } catch (\Throwable $e) {
            // 新表尚未自愈时回退默认，保证不阻断业务
        }

        // 合法值校正
        $valid = $group === self::GROUP_CORE ? self::CORE_SCOPES : self::OTHER_SCOPES;
        if (!in_array($scope, $valid, true)) {
            $scope = $default['scope'];
        }

        $result = [
            'scope'        => $scope,
            'include_pool' => $includePool,
            // other 组 all 才是真正不限；core 组本版内部账号默认 all
            'all'          => $scope === 'all',
        ];
        return self::$policyCache[$cacheKey] = $result;
    }

    /** 读取角色 data_policy（JSON 解码，失败返回 []） */
    public static function rolePolicy(int $roleId): array
    {
        if ($roleId <= 0) {
            return [];
        }
        try {
            $raw = Db::name('sys_role')->where('id', $roleId)->value('data_policy');
            if (!$raw) {
                return [];
            }
            $json = is_string($raw) ? json_decode($raw, true) : (array)$raw;
            return is_array($json) ? $json : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /* ====================== 其他模块：创建人口径 ====================== */

    /**
     * 其他模块列表注入：内部账号按 create_by 过滤；all 策略不限。
     * 客户侧账号（有 companyIds）不在此处理，由控制器走企业维度分支。
     *
     * @param object $query ThinkPHP 查询构造器
     * @param array  $user
     * @param string $col   创建人列名，默认 create_by
     */
    public static function applyCreatorScope($query, array $user, string $col = 'create_by')
    {
        if (($user['role_code'] ?? '') === 'admin') {
            return $query;
        }
        $policy = self::resolvePolicy($user, self::GROUP_OTHER);
        if ($policy['all']) {
            return $query;
        }
        // self：仅本人创建
        return $query->where($col, (int)($user['id'] ?? 0));
    }

    /**
     * 对象级断言（其他模块）：admin / all 直通；否则 create_by 必须是本人。
     * 客户侧账号由调用方先做企业维度断言，不走这里。
     */
    public static function assertCreatorAccess(string $table, int $id, array $user, string $col = 'create_by'): void
    {
        if (($user['role_code'] ?? '') === 'admin' || $id <= 0) {
            return;
        }
        $policy = self::resolvePolicy($user, self::GROUP_OTHER);
        if ($policy['all']) {
            return;
        }
        $owner = (int)Db::name($table)->where('id', $id)->value($col);
        if ($owner !== (int)($user['id'] ?? 0)) {
            throw new HttpResponseException(json(r_error('无权访问该数据', 403)));
        }
    }

    /** 布尔版对象级判断（其他模块），便于控制器复用既有 if-return 风格 */
    public static function canAccessCreator(string $table, int $id, array $user, string $col = 'create_by'): bool
    {
        if (($user['role_code'] ?? '') === 'admin' || $id <= 0) {
            return true;
        }
        $policy = self::resolvePolicy($user, self::GROUP_OTHER);
        if ($policy['all']) {
            return true;
        }
        $owner = (int)Db::name($table)->where('id', $id)->value($col);
        return $owner === (int)($user['id'] ?? 0);
    }

    /* ====================== 核心资源：所有人口径 ====================== */

    /**
     * 核心资源列表注入（v2.99.37 P3）。
     * all：不限；pool：公海 OR 本人共有；self：仅本人共有。
     * 客户侧账号不在此处理（控制器按 companyIds/借调关系过滤）。
     */
    public static function applyCoreScope($query, array $user, string $resType = '', string $pk = 'id')
    {
        if (($user['role_code'] ?? '') === 'admin' || $resType === '') {
            return $query;
        }
        return ResourceOwnerService::applyScope($query, $user, $resType, $pk);
    }

    /**
     * 核心资源对象级断言（v2.99.37 P3）：所有人 / 公海（策略允许时）/ all 直通。
     */
    public static function assertCoreAccess(string $resType, int $resId, array $user): void
    {
        if (($user['role_code'] ?? '') === 'admin' || $resId <= 0) {
            return;
        }
        if (ResourceOwnerService::canAccess($resType, $resId, $user)) {
            return;
        }
        throw new HttpResponseException(json(r_error('无权访问该数据', 403)));
    }

    /** 是否为客户侧账号（带企业授权边界） */
    public static function isCompanyAccount(array $user, array $companyIds): bool
    {
        return ($user['role_code'] ?? '') === 'company' || !empty($companyIds);
    }
}
