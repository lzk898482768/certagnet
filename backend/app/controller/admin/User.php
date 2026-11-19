<?php
namespace app\controller\admin;

use app\model\SysRole;
use app\model\SysUser;
use app\service\WecomAuthService;
use app\service\DeptScopeService;
use app\service\TotpService;
use app\service\MiniAppService;
use think\facade\Db;

class User extends AdminController
{
    /** 合法数据范围 */
    private const SCOPES = ['all', 'dept_tree', 'dept', 'self'];

    public function index()
    {
        TotpService::ensureColumns();
        [$page, $limit] = $this->pageParams();
        $q = SysUser::with(['role'])->order('id', 'desc');
        if (!$this->isAdmin()) {
            $q->where('id', $this->request->userId);
        }
        $deptId = (int)$this->request->param('dept_id', 0);
        if ($deptId > 0) {
            // 含子部门一起筛选（与数据权限口径一致）
            $deptIds = DeptScopeService::deptIdsWithChildren($deptId);
            $relUserIds = Db::name('user_dept_rel')->whereIn('dept_id', $deptIds)->column('user_id');
            $q->where(function ($qq) use ($deptIds, $relUserIds) {
                $qq->whereIn('dept_id', $deptIds);
                if ($relUserIds) {
                    $qq->whereOr('id', 'in', $relUserIds);
                }
            });
        }
        // 供「企微通讯录 → 绑定现有账号」下拉使用：未绑定企微的启用账号
        if ((int)$this->request->param('not_bound_wecom', 0) === 1) {
            $q->where(function ($qq) {
                $qq->whereNull('wecom_userid')->whereOr('wecom_userid', '')->whereOr('wecom_userid', '0');
            })->where('status', 1);
        }
        $keyword = trim($this->request->param('keyword', ''));
        if ($keyword !== '') {
            $q->where(function ($qq) use ($keyword) {
                $qq->where('username', 'like', "%{$keyword}%")
                   ->whereOr('real_name', 'like', "%{$keyword}%");
            });
        }
        $total = $q->count();
        $list  = $q->page($page, $limit)->select()->toArray();
        // 批量挂部门名 + 多部门
        $deptNames = \app\model\OrgDepartment::column('name', 'id');
        $uids = array_column($list, 'id');
        $relMap = [];
        if ($uids) {
            $rels = Db::name('user_dept_rel')->whereIn('user_id', $uids)->order('is_primary', 'desc')->select()->toArray();
            foreach ($rels as $r) {
                $relMap[$r['user_id']][] = (int)$r['dept_id'];
            }
        }
        foreach ($list as &$row) {
            $row['dept_id']   = (int)($row['dept_id'] ?? 0);
            $row['dept_name'] = $deptNames[$row['dept_id']] ?? '';
            $row['dept_ids']  = $relMap[$row['id']] ?? [];
            $row['dept_names'] = implode('、', array_values(array_filter(
                array_map(fn ($d) => $deptNames[$d] ?? '', $row['dept_ids'])
            )));
            $row['data_scope'] = $row['data_scope'] ?? 'self';
        }
        unset($row);
        // v2.100：附加微信小程序绑定状态（后台账号列表 / 绑定码入口使用）
        $miniMap = MiniAppService::bindingMap($uids);
        foreach ($list as &$row) {
            $mini = $miniMap[(int)$row['id']] ?? null;
            $row['miniapp_bound']      = (bool)$mini;
            $row['miniapp_openid']     = $mini['openid'] ?? '';
            $row['miniapp_bound_at']   = $mini['bound_at'] ?? null;
            $row['miniapp_last_login'] = $mini['last_login_at'] ?? null;
        }
        unset($row);
        return json(r_success(['list' => $list, 'total' => $total]));
    }

    public function save()
    {
        // 账号新增/编辑涉及角色、数据范围与密码重置，仅管理员可操作。
        // 此前普通账号可通过 id=自己 + role_id=1 自提权。
        $this->requireAdmin();
        TotpService::ensureColumns();

        $id       = (int)$this->request->post('id', 0);
        $password = $this->request->post('password', '');
        $data     = $this->request->post(['username', 'real_name', 'phone', 'role_id', 'status', 'dept_id', 'totp_force', 'totp_tip']);
        $companyIds = $this->request->post('company_ids/a', []);
        $deptIds    = array_values(array_unique(array_filter(array_map('intval', (array)$this->request->post('dept_ids/a', [])))));
        // 只选了「主部门」未选多部门时，用主部门兜底，保证 user_dept_rel 与 sys_user.dept_id 一致
        if (!$deptIds && !empty($this->request->post('dept_id', 0))) {
            $deptIds = [(int)$this->request->post('dept_id', 0)];
        }
        if (isset($data['dept_id'])) {
            $data['dept_id'] = (int)$data['dept_id'];
        }
        // v2.99.36：sys_user.data_scope 部门四级范围已废弃，账号数据范围改由
        // 角色 data_policy（sys_role）与用户级 sys_user_data_scope 决定，此处不再接收该字段。
        if (isset($data['totp_force'])) {
            $data['totp_force'] = (int)!!$data['totp_force'];
        }
        if (isset($data['totp_tip'])) {
            $data['totp_tip'] = mb_substr(trim((string)$data['totp_tip']), 0, 100, 'UTF-8');
        }
        if (empty($data['username']) || empty($data['role_id'])) {
            return json(r_error('账号和角色必填', 400));
        }
        $data['username'] = trim((string)$data['username']);

        // v2.99.1：username 唯一索引 uk_username，保存前查重，避免 1062 变 500
        $dupMsg = \app\common\UniqueGuard::check([
            'username' => [SysUser::class, $data['username'], '登录账号'],
        ], $id);
        if ($dupMsg !== '') {
            return json(r_error($dupMsg, 400));
        }
        // 防止把最后一个可用管理员降级/禁用，导致系统无人可管理。
        if ($id) {
            $existing = SysUser::with(['role'])->find($id);
            if (!$existing) {
                return json(r_error('账号不存在', 404));
            }
            $isAdminUser = ($existing->role->role_code ?? '') === 'admin';
            $newRole     = SysRole::find((int)$data['role_id']);
            $willAdmin   = $newRole && $newRole->role_code === 'admin';
            $willActive  = (int)($data['status'] ?? $existing->status) === 1;
            if ($isAdminUser && (!$willAdmin || !$willActive)) {
                $adminRoleIds = SysRole::where('role_code', 'admin')->column('id');
                $activeAdmins = SysUser::whereIn('role_id', $adminRoleIds ?: [0])
                    ->where('status', 1)->count();
                if ($activeAdmins <= 1) {
                    return json(r_error('系统至少需要保留一个启用状态的管理员账号', 400));
                }
            }
        }
        $savedId = $id;
        try {
            if ($id) {
                if ($password) {
                    if (!SysUser::validatePassword($password)) {
                        return json(r_error('密码需 8-32 位且同时包含字母和数字', 400));
                    }
                    $data['password'] = $password;
                }
                // v2.55.2 修复：必须走模型实例 save() 才能触发 setPasswordAttr（bcrypt），
                // 此前 Query::update() 绕过修改器导致新密码明文入库、登录校验失败。
                $existing->save($data);
                if ($password) {
                    // 管理员重置密码后撤销受信任设备
                    TotpService::revokeDevices($id);
                }
                $this->syncCompanies($id, $companyIds);
                $this->syncDepts($id, $deptIds);
                $this->log('系统账号', '更新', "#{$id}");
            } else {
                if (!$password) {
                    return json(r_error('新增账号必须设置密码', 400));
                }
                if (!SysUser::validatePassword($password)) {
                    return json(r_error('密码需 8-32 位且同时包含字母和数字', 400));
                }
                $data['password'] = $password;
                $user = SysUser::create($data);
                $savedId = (int)$user->id;
                $this->syncCompanies($user->id, $companyIds);
                $this->syncDepts($user->id, $deptIds);
                $this->log('系统账号', '新增');
            }
        } catch (\Throwable $e) {
            // 并发写入等极端情况下查重仍可能被绕过，兜底翻译唯一键冲突
            $dupMsg = \app\common\UniqueGuard::friendly($e, '登录账号已存在');
            if ($dupMsg !== '') {
                return json(r_error($dupMsg, 400));
            }
            throw $e;
        }
        return json(r_success(['id' => $savedId], '保存成功'));
    }

    public function delete()
    {
        $this->requireAdmin();

        $id = (int)$this->request->post('id', 0);
        if ($id == $this->request->userId) {
            return json(r_error('不能删除自己', 400));
        }
        $existing = SysUser::with(['role'])->find($id);
        if (!$existing) {
            return json(r_error('账号不存在', 404));
        }
        if (($existing->role->role_code ?? '') === 'admin') {
            $adminRoleIds = SysRole::where('role_code', 'admin')->column('id');
            $activeAdmins = SysUser::whereIn('role_id', $adminRoleIds ?: [0])
                ->where('status', 1)->count();
            if ($activeAdmins <= 1) {
                return json(r_error('系统至少需要保留一个启用状态的管理员账号', 400));
            }
        }
        SysUser::destroy($id);
        Db::table('user_company_rel')->where('user_id', $id)->delete();
        Db::name('user_dept_rel')->where('user_id', $id)->delete();
        $this->log('系统账号', '删除', "#{$id}");
        return json(r_success([], '删除成功'));
    }

    /**
     * 管理员代解绑企业微信
     *  POST { id }
     */
    public function unbindWecom()
    {
        if (!$this->isAdmin()) {
            return json(r_error('仅管理员可操作', 403));
        }
        $id = (int)$this->request->post('id', 0);
        if (!$id) return json(r_error('缺少 id', 400));
        try {
            WecomAuthService::unbindByAdmin($id);
            $this->log('企微绑定', '代解绑', "#{$id}");
            return json(r_success([], '已解绑'));
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage()));
        }
    }

    /**
     * 管理员代解绑微信小程序
     *  POST { id }
     */
    public function unbindMiniApp()
    {
        if (!$this->isAdmin()) {
            return json(r_error('仅管理员可操作', 403));
        }
        $id = (int)$this->request->post('id', 0);
        if (!$id) return json(r_error('缺少 id', 400));
        try {
            MiniAppService::unbind($id);
            $this->log('小程序绑定', '代解绑', "#{$id}");
            return json(r_success([], '已解绑'));
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage() ?: '解绑失败'));
        }
    }

    /**
     * 管理员为指定账号生成小程序绑定码
     *  POST { id }
     */
    public function miniappBindCode()
    {
        if (!$this->isAdmin()) {
            return json(r_error('仅管理员可操作', 403));
        }
        $id = (int)$this->request->post('id', 0);
        if (!$id) return json(r_error('缺少 id', 400));
        try {
            $r = MiniAppService::createBindCode($id);
            $this->log('小程序绑定', '生成绑定码', "#{$id}");
            return json(r_success($r, '绑定码已生成，10 分钟内有效'));
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage() ?: '生成失败'));
        }
    }

    /**
     * 管理员设置「强制开启二次验证」
     *  POST { id, force: 0|1 }
     */
    public function totpForce()
    {
        $this->requireAdmin();
        $id = (int)$this->request->post('id', 0);
        if (!$id) return json(r_error('缺少 id', 400));
        $force = (int)$this->request->post('force', 0) === 1;
        try {
            TotpService::setForce($id, $force);
            $this->log('账号安全', $force ? '强制开启二次验证' : '取消强制二次验证', "#{$id}");
            return json(r_success([], $force ? '已强制开启，用户下次登录需绑定腾讯身份验证器' : '已取消强制'));
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage()));
        }
    }

    /**
     * 管理员设置「二次验证绑定提示语」（展示在用户绑定腾讯身份验证器的弹窗里）
     *  POST { id, tip }
     */
    public function totpTip()
    {
        $this->requireAdmin();
        $id = (int)$this->request->post('id', 0);
        if (!$id) return json(r_error('缺少 id', 400));
        $tip = (string)$this->request->post('tip', '');
        if (mb_strlen(trim($tip), 'UTF-8') > 100) {
            return json(r_error('提示语最多 100 个字', 400));
        }
        try {
            TotpService::setTip($id, $tip);
            $this->log('账号安全', '设置二次验证提示语', "#{$id}");
            return json(r_success([], '已保存绑定提示语'));
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage()));
        }
    }

    /**
     * 管理员解绑腾讯身份验证器（用户手机丢失时的兜底）
     *  POST { id }
     */
    public function totpUnbind()
    {
        $this->requireAdmin();
        $id = (int)$this->request->post('id', 0);
        if (!$id) return json(r_error('缺少 id', 400));
        try {
            TotpService::unbindByAdmin($id);
            $this->log('账号安全', '解绑腾讯身份验证器', "#{$id}");
            return json(r_success([], '已解绑，该用户所有受信任设备已失效'));
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage()));
        }
    }

    public function companies($userId)
    {
        $userId = (int)$userId;
        if (!$this->isAdmin() && $userId !== (int)$this->request->userId) {
            return json(r_error('无权限', 403));
        }
        $ids = Db::table('user_company_rel')->where('user_id', $userId)->column('company_id');
        return json(r_success($ids));
    }

    public function depts($userId)
    {
        $userId = (int)$userId;
        if (!$this->isAdmin() && $userId !== (int)$this->request->userId) {
            return json(r_error('无权限', 403));
        }
        $ids = Db::name('user_dept_rel')->where('user_id', $userId)->order('is_primary', 'desc')->column('dept_id');
        return json(r_success(array_map('intval', $ids)));
    }

    /**
     * 同步账号-企业关联
     * 注意：user_company_rel 表只有 id/user_id/company_id 三列（无 create_time），
     * 早期版本曾写入 create_time，会报 fields not exists:[create_time]，切勿再加回。
     */
    protected function syncCompanies($userId, array $ids): void
    {
        Db::table('user_company_rel')->where('user_id', $userId)->delete();
        foreach ($ids as $cid) {
            $cid = (int)$cid;
            if ($cid <= 0) continue;
            Db::table('user_company_rel')->insert([
                'user_id'    => $userId,
                'company_id' => $cid,
            ]);
        }
    }

    /**
     * 同步账号-部门多对多；第一个视为主部门并回写 sys_user.dept_id
     */
    protected function syncDepts($userId, array $ids): void
    {
        Db::name('user_dept_rel')->where('user_id', $userId)->delete();
        foreach (array_values($ids) as $i => $did) {
            if ($did <= 0) continue;
            Db::name('user_dept_rel')->insert([
                'user_id'    => $userId,
                'dept_id'    => $did,
                'is_primary' => $i === 0 ? 1 : 0,
                'create_time' => date('Y-m-d H:i:s'),
            ]);
        }
        if ($ids && $ids[0] > 0) {
            SysUser::where('id', $userId)->update(['dept_id' => (int)$ids[0]]);
        }
        DeptScopeService::clearCache();
    }
}
