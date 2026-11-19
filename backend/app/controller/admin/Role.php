<?php
namespace app\controller\admin;

use app\model\SysRole;
use app\model\SysUser;
use app\service\PermissionCatalog;
use app\service\RoleCatalog;

class Role extends AdminController
{
    public function index()
    {
        $this->requireAdmin();
        $list = SysRole::order('id', 'asc')->select()->toArray();
        foreach ($list as &$r) {
            // menu_ids：模型访问器 getMenuIdsAttr 已转成数组；兼容原始逗号串
            $mi = $r['menu_ids'] ?? [];
            $r['menu_ids'] = is_array($mi)
                ? array_values(array_map('intval', $mi))
                : array_values(array_filter(array_map('intval', explode(',', (string)$mi))));
            // v2.69：扁平权限码；紧凑返回供前端勾选
            $r['perms'] = \app\service\PermissionCatalog::normalize($r['perms'] ?? null);
            // v2.99.36：数据策略（core/other 两组），NULL 表示用内置默认
            $dp = $r['data_policy'] ?? null;
            $r['data_policy'] = $dp ? (json_decode((string)$dp, true) ?: null) : null;
            $r['is_admin'] = (string)$r['role_code'] === 'admin';
        }
        unset($r);
        return json(r_success($list));
    }

    public function save()
    {
        $this->requireAdmin();

        $id = (int)$this->request->post('id', 0);
        $data = $this->request->post(['role_name', 'role_code', 'menu_ids', 'remark', 'status']);
        if (empty($data['role_name'])) {
            return json(r_error('角色名称必填', 400));
        }
        $roleCode = trim((string)($data['role_code'] ?? ''));
        if ($roleCode === '') {
            return json(r_error('角色编码必填', 400));
        }
        if (!preg_match('/^[a-z][a-z0-9_]{1,31}$/', $roleCode)) {
            return json(r_error('角色编码需为 2-32 位小写字母/数字/下划线，且以字母开头', 400));
        }
        $data['role_code'] = $roleCode;
        $dup = SysRole::where('role_code', $roleCode)->where('id', '<>', $id)->find();
        if ($dup) {
            return json(r_error('角色编码已存在：' . $roleCode, 400));
        }
        // 内置 admin 角色不允许改名/停用，避免系统失去管理员角色。
        if ($id) {
            $old = SysRole::find($id);
            if ($old && $old->role_code === 'admin'
                && ($roleCode !== 'admin' || (int)($data['status'] ?? 1) !== 1)) {
                return json(r_error('内置管理员角色不允许修改编码或停用', 400));
            }
        }
        if (is_array($data['menu_ids'] ?? null)) {
            // v2.99.1（BUG-08）：自动补齐父级菜单，避免只授权子项导致侧栏不显示
            $data['menu_ids'] = implode(',', \app\service\RoleCatalog::withAncestors($data['menu_ids']));
        } elseif (isset($data['menu_ids']) && is_string($data['menu_ids']) && trim($data['menu_ids']) !== '') {
            // 兼容直接提交逗号串的调用方
            $raw = array_filter(array_map('intval', explode(',', $data['menu_ids'])));
            $data['menu_ids'] = implode(',', \app\service\RoleCatalog::withAncestors($raw));
        }
        // 功能权限（v2.69）：扁平权限码对象 {"cert:create":1}；admin 强制不限制
        $perms = $this->request->post('perms', '__absent__');
        if ($perms !== '__absent__') {
            if ($roleCode === 'admin') {
                $data['perms'] = null;
            } else {
                $data['perms'] = json_encode(
                    \app\service\PermissionCatalog::normalize($perms),
                    JSON_UNESCAPED_UNICODE
                );
            }
        } elseif (!$id) {
            $data['perms'] = '{}'; // 新角色默认无写权限，由管理员显式勾选
        }
        // 数据权限策略（v2.99.36）：core 核心资源 / other 其他模块
        // 注意：用原始请求体判断「字段是否出现」——post('x', default) 会把 JSON null
        // 当作未提交，而 NULL 的语义是「清空策略回退内置默认」，必须支持显式清空。
        $hasPolicy = false;
        $policy    = null;
        $rawBody   = json_decode((string)$this->request->getInput(), true);
        if (is_array($rawBody) && array_key_exists('data_policy', $rawBody)) {
            $hasPolicy = true;
            $policy    = $rawBody['data_policy'];
        } else {
            $policyRaw = $this->request->post('data_policy', '__absent__');
            if ($policyRaw !== '__absent__') {
                $hasPolicy = true;
                $policy    = $policyRaw;
            }
        }
        if ($hasPolicy) {
            if ($roleCode === 'admin') {
                $data['data_policy'] = null; // 管理员始终不限
            } else {
                $data['data_policy'] = $this->normalizeDataPolicy($policy);
            }
        }
        if ($id) {
            SysRole::where('id', $id)->update($data);
            $this->log('角色', '更新', "#{$id}");
        } else {
            $role = SysRole::create($data);
            $id   = (int)$role->id;
            $this->log('角色', '新增', "#{$id}");
        }
        return json(r_success(['id' => $id], '保存成功'));
    }

    public function delete()
    {
        $this->requireAdmin();

        $id = (int)$this->request->post('id', 0);
        $role = SysRole::find($id);
        if (!$role) {
            return json(r_error('角色不存在', 404));
        }
        if ($role->role_code === 'admin') {
            return json(r_error('内置管理员角色不可删除', 400));
        }
        $used = SysUser::where('role_id', $id)->count();
        if ($used > 0) {
            return json(r_error("该角色仍有 {$used} 个账号在用，请先调整账号角色", 400));
        }
        SysRole::destroy($id);
        $this->log('角色', '删除', "#{$id}");
        return json(r_success([], '删除成功'));
    }

    /**
     * 功能权限目录（v2.69）
     * 角色授权页据此动态渲染「模块 × 动作」矩阵，不再硬编码只有证照 4 项。
     */
    public function permissions()
    {
        $this->requireAdmin();
        return json(r_success([
            'version' => PermissionCatalog::VERSION,
            'modules' => PermissionCatalog::tree(),
        ]));
    }

    /** 恢复内置角色的蓝图默认授权（菜单 + 功能权限） */
    public function reset()
    {
        $this->requireAdmin();
        $id = (int)$this->request->post('id', 0);
        if ($id <= 0 || !RoleCatalog::resetRole($id)) {
            return json(r_error('该角色没有默认授权模板（仅内置岗位角色可恢复）', 400));
        }
        $this->log('角色', '恢复默认授权', "#{$id}");
        return json(r_success([], '已恢复默认授权'));
    }

    /**
     * 规范化角色数据策略（v2.99.36）
     *
     * 入参形如 {"core":{"scope":"all","include_pool":1},"other":{"scope":"self"}}；
     * 缺省字段回退内置默认（core=all 含公海 / other=self）。
     * 返回 JSON 字符串，非法输入回退默认。
     */
    protected function normalizeDataPolicy($raw): ?string
    {
        if ($raw === null || $raw === '' || $raw === 'null') {
            return null; // 明确清空 = 使用内置默认
        }
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return null;
        }
        $core  = is_array($raw['core'] ?? null) ? $raw['core'] : [];
        $other = is_array($raw['other'] ?? null) ? $raw['other'] : [];

        $coreScope = (string)($core['scope'] ?? 'all');
        if (!in_array($coreScope, \app\service\DataScopeService::CORE_SCOPES, true)) {
            $coreScope = 'all';
        }
        $otherScope = (string)($other['scope'] ?? 'self');
        if (!in_array($otherScope, \app\service\DataScopeService::OTHER_SCOPES, true)) {
            $otherScope = 'self';
        }
        $policy = [
            'core'  => [
                'scope'        => $coreScope,
                'include_pool' => (int)($core['include_pool'] ?? ($core['pool'] ?? 1)) ? 1 : 0,
            ],
            'other' => [
                'scope' => $otherScope,
            ],
        ];
        return json_encode($policy, JSON_UNESCAPED_UNICODE);
    }
}
