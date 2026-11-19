<?php
namespace app\controller\admin;

use app\BaseController;
use app\service\OperationLogService;
use think\exception\HttpResponseException;
use think\facade\Db;

/**
 * 后台控制器基类：自动数据权限过滤 + 操作日志
 */
class AdminController extends BaseController
{
    protected function isAdmin(): bool
    {
        return ($this->request->roleCode ?? '') === 'admin';
    }

    protected function companyIds(): array
    {
        return $this->request->companyIds ?? [];
    }

    protected function roleCode(): string
    {
        return (string)($this->request->roleCode ?? '');
    }

    /**
     * 是否客户侧账号（带 user_company_rel 企业授权边界）
     *
     * v2.99.36：客户账号的数据由内部员工代为创建，本人通常不是 create_by，
     * 因此其数据范围仍按授权企业过滤；只有「内部办公账号」才切到创建人口径。
     */
    protected function isCompanyBound(): bool
    {
        return $this->roleCode() === 'company' || !empty($this->companyIds());
    }

    /**
     * 当前账号可见企业 ID 集合（v2.99.36 新模型）
     *
     *  - admin：null = 不限制；
     *  - 客户侧账号（user_company_rel / company 角色）：该授权企业集合（空集合=无权限）；
     *  - 内部办公账号：null = 不限制（核心资源按「所有人」配置，部门四级范围已废弃）。
     *
     * 注意：本方法只用于「企业维度」的系统/通知类查询与核心企业台账；
     *      合同/费用/借调等「其他模块」内部账号改走创建人口径（applyModuleScope）。
     *
     * @return array<int>|null
     */
    protected function visibleCompanyIds(): ?array
    {
        if ($this->isAdmin()) {
            return null;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $this->companyIds()))));
        if ($ids) {
            return $ids;
        }
        if ($this->roleCode() === 'company') {
            return [];
        }
        // 内部办公账号：核心资源所有人可见，不再按部门换算
        return null;
    }

    /**
     * 「其他模块」列表数据范围（v2.99.36）
     *
     *  - admin：不限；
     *  - 客户侧账号：仍按授权企业（company_id）过滤；
     *  - 内部办公账号：按创建人 create_by = 本人（角色/用户级策略可放宽为 all）。
     *
     * @param object|string $table 表名，用于客户账号分支的 company_id 字段语义核对（可选）
     */
    protected function applyModuleScope($query, string $table = '', string $col = 'create_by', string $companyCol = 'company_id')
    {
        if ($this->isAdmin()) {
            return $query;
        }
        if ($this->isCompanyBound()) {
            return $this->applyCompanyScope($query, $companyCol);
        }
        return \app\service\DataScopeService::applyCreatorScope($query, $this->request->user ?? [], $col);
    }

    /**
     * 「其他模块」对象级断言（v2.99.36）：客户账号按企业边界，内部账号按创建人。
     */
    protected function canAccessModule(string $table, int $id, string $col = 'create_by', string $companyCol = 'company_id'): bool
    {
        if ($this->isAdmin() || $id <= 0) {
            return true;
        }
        if ($this->isCompanyBound()) {
            $companyId = (int)Db::name($table)->where('id', $id)->value($companyCol);
            return $companyId > 0 && in_array($companyId, array_map('intval', $this->companyIds()), true);
        }
        return \app\service\DataScopeService::canAccessCreator($table, $id, $this->request->user ?? [], $col);
    }

    protected function assertModuleAccess(string $table, int $id, string $col = 'create_by', string $companyCol = 'company_id'): void
    {
        if (!$this->canAccessModule($table, $id, $col, $companyCol)) {
            throw new HttpResponseException(json(r_error('无权访问该数据', 403)));
        }
    }

    /**
     * 给查询追加企业维度数据权限（v2.77）
     * 可见企业为 null 时视为不限制；空集合用 [0] 保证查不到数据。
     */
    protected function applyCompanyScope($query, string $field = 'company_id')
    {
        $ids = $this->visibleCompanyIds();
        if ($ids !== null) {
            $query->whereIn($field, $ids ?: [0]);
        }
        return $query;
    }

    /**
     * 仅内置管理员可操作（账号、角色、菜单、系统配置、企微配置等）。
     * 直接抛 403 响应，避免每个控制器重复写 return json(...)。
     */
    protected function requireAdmin(): void
    {
        if (!$this->isAdmin()) {
            throw new HttpResponseException(json(r_error('仅管理员可操作', 403)));
        }
    }

    /**
     * 允许指定角色操作（例如收款账户仅 admin / finance）。
     */
    protected function requireAnyRole(array $roles): void
    {
        if (!in_array($this->roleCode(), $roles, true)) {
            throw new HttpResponseException(json(r_error('当前角色无权执行此操作', 403)));
        }
    }

    /**
     * 判断当前账号是否可访问某企业（v2.67 与 scopeCompany 列表口径一致）。
     * - admin：全部
     * - 有企业关联：必须在 user_company_rel 授权企业内；
     * - company 角色无关联：无权限；
     * - 无企业关联的内部账号：按部门数据范围（负责人 owner_user_id）判断。
     */
    protected function canAccessCompany(int $companyId): bool
    {
        if ($this->isAdmin()) {
            return true;
        }
        if ($companyId <= 0) {
            return false;
        }
        $ids = $this->visibleCompanyIds();
        if ($ids === null) {
            // 内部办公账号：核心资源按所有人/公海策略判定（默认 all）
            return \app\service\ResourceOwnerService::canAccess('company', $companyId, $this->request->user ?? []);
        }
        return in_array($companyId, $ids, true);
    }

    protected function assertCompanyAccess(int $companyId): void
    {
        if (!$this->canAccessCompany($companyId)) {
            throw new HttpResponseException(json(r_error('无权访问该企业数据', 403)));
        }
    }

    /**
     * 人员对象级权限（v2.99.36）
     * - 客户侧账号：需存在「企业-人员」关系或被这些企业借调；
     * - 内部办公账号：核心资源按「所有人」配置，可访问全部人员。
     */
    protected function canAccessStaff(int $staffId): bool
    {
        if ($staffId <= 0) {
            return false;
        }
        if ($this->isAdmin()) {
            return true;
        }
        $ids = array_map('intval', $this->companyIds());
        if ($ids) {
            $rel = Db::name('staff_company_rel')
                ->where('staff_id', $staffId)->whereIn('company_id', $ids)->count();
            if ($rel > 0) {
                return true;
            }
            $sec = Db::name('cert_secondment')
                ->where('staff_id', $staffId)->whereIn('company_id', $ids)
                ->where('status', 1)->count();
            return $sec > 0;
        }
        if ($this->roleCode() === 'company') {
            return false;
        }
        // 内部办公账号：核心资源按所有人/公海策略判定（默认 all）
        return \app\service\ResourceOwnerService::canAccess('staff', $staffId, $this->request->user ?? []);
    }

    protected function assertStaffAccess(int $staffId): void
    {
        if (!$this->canAccessStaff($staffId)) {
            throw new HttpResponseException(json(r_error('无权访问该人员档案', 403)));
        }
    }

    /**
     * 证书对象级权限（v2.99.36）：客户账号按借调关系；内部账号所有人可见。
     */
    protected function canAccessCert(int $certId): bool
    {
        if ($certId <= 0) {
            return false;
        }
        if ($this->isAdmin()) {
            return true;
        }
        $ids = array_map('intval', $this->companyIds());
        if ($ids) {
            $certIds = \app\service\SecondmentService::certIdsOf($ids, 1);
            return in_array($certId, array_map('intval', $certIds), true);
        }
        if ($this->roleCode() === 'company') {
            return false;
        }
        // 内部办公账号：核心资源按所有人/公海策略判定（默认 all）
        return \app\service\ResourceOwnerService::canAccess('certificate', $certId, $this->request->user ?? []);
    }

    protected function assertCertAccess(int $certId): void
    {
        if (!$this->canAccessCert($certId)) {
            throw new HttpResponseException(json(r_error('无权访问该证书数据', 403)));
        }
    }

    /**
     * 客户企业账号查看人员/证书详情时，隐藏其他企业的借调记录。
     * 内部账号不限制（借调列表本身按其创建人范围控制）。
     */
    protected function restrictSecondmentQuery($query, string $field = 'company_id')
    {
        return $this->isCompanyBound() ? $this->applyCompanyScope($query, $field) : $query;
    }

    // 对查询追加企业数据权限（v2.77：统一走 visibleCompanyIds）
    protected function scope($query, string $field = 'company_id')
    {
        return $this->applyCompanyScope($query, $field);
    }

    /**
     * 证书级数据权限（v2.99.36）
     *  - 客户侧账号：只看「本企业借调中」的证书（企业边界不变）；
     *  - 内部办公账号：核心资源按「所有人」配置，不限制。
     */
    protected function scopeCert($query, string $field = 'id')
    {
        if (!$this->isAdmin()) {
            $ids = array_map('intval', $this->companyIds());
            if ($ids) {
                $certIds = \app\service\SecondmentService::certIdsOf($ids, 1);
                $query->whereIn($field, $certIds ?: [0]);
            } elseif ($this->roleCode() === 'company') {
                $query->whereIn($field, [0]);
            } else {
                // 内部办公账号：按证书自身归属（多所有人/公海）过滤
                $query = \app\service\ResourceOwnerService::applyScope($query, $this->request->user ?? [], 'certificate', $field);
            }
        }
        return $query;
    }

    /**
     * 企业级数据权限：client_company 主键为 id
     *  - 客户侧账号：只看授权企业；
     *  - 内部办公账号：核心资源所有人可见（visibleCompanyIds 返回 null，不追加限制）。
     */
    protected function scopeCompany($query, string $field = 'id')
    {
        if ($this->isAdmin()) {
            return $query;
        }
        if ($this->isCompanyBound()) {
            return $this->applyCompanyScope($query, $field);
        }
        // 内部办公账号：按企业自身归属（多所有人/公海）过滤
        return \app\service\ResourceOwnerService::applyScope($query, $this->request->user ?? [], 'company', $field);
    }

    /**
     * 人员维度数据权限（v2.99.36）
     *  - 客户侧账号：企业-人员关系内；
     *  - 内部办公账号：所有人，不限制。
     */
    protected function applyStaffScope($query)
    {
        if ($this->isAdmin()) {
            return $query;
        }
        $ids = array_values(array_filter(array_map('intval', $this->companyIds())));
        if ($ids) {
            $staffIds = Db::table('staff_company_rel')->whereIn('company_id', $ids)->column('staff_id');
            $query->whereIn('id', array_map('intval', $staffIds ?: [0]));
        } elseif ($this->roleCode() === 'company') {
            $query->whereIn('id', [0]);
        } else {
            // 内部办公账号：按人员自身归属（多所有人/公海）过滤
            $query = \app\service\ResourceOwnerService::applyScope($query, $this->request->user ?? [], 'staff', 'id');
        }
        return $query;
    }

    /**
     * 功能权限判断（v2.69 RBAC）
     *  - admin：恒通过（perms = null）
     *  - 其余角色：只认 sys_role.perms 中显式授予的权限码，**默认拒绝**
     *  - 兼容旧版嵌套结构 {cert:{create:1}}（PermissionCatalog::normalize 拍平）
     * 用法：if (!$this->can('cert', 'delete')) { return json(r_error('无删除权限', 403)); }
     */
    protected function can(string $mod, string $act): bool
    {
        if ($this->isAdmin()) {
            return true;
        }
        $codes = $this->request->permCodes ?? null;
        if ($codes === null || !is_array($codes)) {
            // PermAuth 未覆盖的入口（如 client 路由）按角色实时计算
            $codes = \app\service\PermissionCatalog::rolePerms((int)($this->request->role_id ?? 0));
        }
        return \app\service\PermissionCatalog::allows($codes, $mod, $act);
    }

    /**
     * 敏感导出 OTP 闸门（v2.99.49）：在 Staff / CredentialVault / Certificate
     * 三个导出方法入口调用。校验前端用动态码换来的一次性 ticket，
     * 失败直接中断并返回 403（不产出任何文件），通过则继续原导出逻辑。
     *
     * @param string $module staff / credential_vault / certificate
     */
    protected function requireExportTicket(string $module): void
    {
        $ticket = (string)$this->request->param('otp_ticket', '');
        if (!\app\service\ExportOtpService::consume((int)$this->request->userId, $module, $ticket)) {
            throw new HttpResponseException(json(r_error('导出二次验证失效，请重新验证动态验证码后再导出', 403)));
        }
    }

    /**
     * 借调记录数据范围（v2.99.36）
     *  - 客户侧账号：按授权企业（company_id）；
     *  - 内部办公账号：其他模块按创建人 create_by。
     */
    protected function applySecondmentScope($query)
    {
        return $this->applyModuleScope($query, 'cert_secondment', 'create_by', 'company_id');
    }

    /**
     * 借调记录级数据权限（v2.77：内部账号不再恒空）
     */
    protected function scopeSecondment($query)
    {
        return $this->applySecondmentScope($query);
    }

    /**
     * 批量删除（通用）：子类实现 deleteOne($id) 返回 ['ok'=>bool,'msg'=>string]，
     * 逐条复用单条删除的全部安全校验（引用检查、关联清理、操作日志）。
     * 单条可删则删、不可删则记录原因，最终返回成功/失败汇总，不因个别失败中断整批。
     */
    public function batchDelete()
    {
        $perm = $this->deletePerm();
        if ($perm && !$this->can($perm[0], $perm[1])) {
            return json(r_error('暂无删除权限，请联系管理员', 403));
        }
        $ids = array_values(array_filter(array_map('intval', (array)$this->request->post('ids/a', []))));
        if (!$ids) {
            return json(r_error('未选择要删除的数据', 400));
        }
        $ok = 0;
        $fail = [];
        foreach ($ids as $id) {
            $r = $this->deleteOne((int)$id);
            if (!empty($r['ok'])) {
                $ok++;
            } else {
                $fail[] = "#{$id}：" . ($r['msg'] ?? '删除失败');
            }
        }
        $msg = "已删除 {$ok} 条";
        if ($fail) {
            $msg .= '，' . count($fail) . ' 条未能删除';
        }
        return json(r_success(['ok' => $ok, 'fail' => $fail], $msg));
    }

    /**
     * 删除功能权限（返回 [模块, 动作]；null = 不额外校验）。
     * 仅证书等已接入功能权限（v2.14）的模块需要覆写。
     */
    protected function deletePerm(): ?array
    {
        return null;
    }

    /**
     * 单条删除核心逻辑：子类实现。
     * 成功返回 ['ok'=>true,'msg'=>'...']，失败返回 ['ok'=>false,'msg'=>'原因']。
     */
    protected function deleteOne(int $id): array
    {
        return ['ok' => false, 'msg' => '未实现'];
    }

    protected function log(string $module, string $action, string $remark = ''): void
    {
        OperationLogService::record($module, $action, $remark);
        // v2.38：写操作的公共出口。业务写路径都经由本方法记录操作日志，
        // 故在此统一失效工作台统计缓存——避免每个控制器各挂一次导致遗漏，
        // 也避免"刚录入证书/借调，工作台数字还是旧的"。
        try {
            Dashboard::forgetDashboard();
        } catch (\Throwable $e) {
            // 缓存失效失败不阻断业务写入（下次 TTL 到期自愈）
        }
        // 角色/菜单/组织架构调整会改变可见菜单树与数据范围，
        // 同样在这条公共出口失效，避免"改了授权还得等 30 分钟"
        if (in_array($module, ['菜单', '角色', '组织架构', '用户', '部门'], true)) {
            try {
                \app\service\MenuCatalog::flushThrottle();
                \app\service\DeptScopeService::clearCache();
            } catch (\Throwable $e) {
                // 同上，不阻断写入
            }
        }
    }

    // 分页参数
    protected function pageParams(): array
    {
        $page  = (int)$this->request->param('page', 1);
        $limit = (int)$this->request->param('limit', 15);
        // 上限 500：既兼容下拉/导出场景，又避免 limit=999999 拖垮数据库。
        $limit = $limit < 1 ? 15 : min($limit, 500);
        return [$page < 1 ? 1 : $page, $limit];
    }

    /**
     * 把指定字段的空字符串统一转成 null。
     *
     * 背景：前端 el-date-picker 未选值时传 ''，而 date/datetime 列在 MySQL
     * 严格模式（5.7/8.0 默认）下不接受 ''，会抛
     * SQLSTATE[22007] 1292 Incorrect date value，导致整个保存失败。
     * 所有写入 date 类型列的控制器都应调用本方法。
     */
    protected function emptyToNull(array $data, array $fields): array
    {
        foreach ($fields as $f) {
            if (array_key_exists($f, $data) && (string)$data[$f] === '') {
                $data[$f] = null;
            }
        }
        return $data;
    }

    /**
     * 结构自愈：确保某表存在指定列。
     *
     * 背景：历史迁移脚本（如 v2.5 给 fee 加 is_auto）曾经没有回写到 init.sql，
     * 用 init.sql 全新安装的库会一直缺列，运行时报
     * SQLSTATE[42S22] 1054 Unknown column 'xxx'。
     * 这里在用到的地方先探测一次，缺列就尝试自动补上（幂等、失败静默降级）。
     *
     * @param string $table  表名（不含前缀）
     * @param string $column 列名
     * @param string $ddl    ADD COLUMN 定义，如 "tinyint NOT NULL DEFAULT 0 COMMENT '...'"
     * @return bool 该列当前是否可用
     */
    protected function ensureColumn(string $table, string $column, string $ddl): bool
    {
        return ensure_column($table, $column, $ddl);
    }

    /**
     * 同上，但作用于全部字段（值为 '' 一律置 null）。
     * 用于整表字段都允许为空的场景。
     */
    protected function blankToNull(array $data, array $except = []): array
    {
        foreach ($data as $k => $v) {
            if (in_array($k, $except, true)) {
                continue;
            }
            if (is_string($v) && $v === '') {
                $data[$k] = null;
            }
        }
        return $data;
    }
}
