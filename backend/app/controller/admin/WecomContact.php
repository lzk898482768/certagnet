<?php
namespace app\controller\admin;

use app\model\OrgDepartment;
use app\model\SysUser;
use app\model\WecomUser;
use app\service\WecomClient;
use app\service\WecomContactService;
use think\facade\Db;

/**
 * 企业微信通讯录同步（后台）
 *
 *  - POST /admin/wecom-contact/sync             同步（dry_run=1 演练）
 *  - GET  /admin/wecom-contact/users            成员列表（keyword 搜索）
 *  - GET  /admin/wecom-contact/departments      同步后的部门树（source=1）
 *  - POST /admin/wecom-contact/create-account   把某成员创建为系统登录账号
 */
class WecomContact extends AdminController
{
    public function sync()
    {
        $this->requireAdmin();
        $dryRun = (int)$this->request->post('dry_run', 0) === 1;
        try {
            $stat = WecomContactService::sync($dryRun);
        } catch (\Throwable $e) {
            return json(r_error('同步失败: ' . $e->getMessage(), 500));
        }
        if (!$dryRun) {
            $this->log('企业微信', '通讯录同步', ($stat['mode'] === 'dev' ? 'DEV演练' : '正式') . " 部门{$stat['dept_total']}/成员{$stat['user_total']}");
        }
        $msg = $dryRun ? '演练完成（未写入）' : '同步完成';
        return json(r_success($stat, $msg));
    }

    public function users()
    {
        $this->requireAdmin();
        $keyword = trim($this->request->param('keyword', ''));
        $page    = max(1, (int)$this->request->param('page', 1));
        $limit   = min(500, max(1, (int)$this->request->param('limit', 20)));
        $q = WecomUser::order('id', 'asc');
        if ($keyword !== '') {
            $q->where('name', 'like', "%{$keyword}%")
              ->whereOr('wecom_userid', 'like', "%{$keyword}%")
              ->whereOr('mobile', 'like', "%{$keyword}%");
        }
        $total = $q->count();
        $list  = $q->page($page, $limit)->select()->toArray();
        return json(r_success(['list' => $list, 'total' => $total]));
    }

    public function departments()
    {
        $this->requireAdmin();
        $list = OrgDepartment::where('source', 1)
            ->order('wecom_order', 'asc')
            ->order('id', 'asc')
            ->field('id,parent_id,name,wecom_dept_id,wecom_order')
            ->select()
            ->toArray();
        return json(r_success(['list' => $list]));
    }

    /**
     * 把企微成员创建为系统登录账号（自动绑定 wecom_userid）
     */
    public function createAccount()
    {
        $this->requireAdmin();
        $userid = trim($this->request->post('wecom_userid', ''));
        if (!$userid) {
            return json(r_error('缺少 wecom_userid', 400));
        }
        $wu = WecomUser::where('wecom_userid', $userid)->find();
        if (!$wu) {
            return json(r_error('成员不存在，请先同步通讯录', 400));
        }
        if ($wu->bind_user_id) {
            $exists = SysUser::find($wu->bind_user_id);
            if ($exists) {
                return json(r_error('该成员已绑定账号：' . $exists->username, 400));
            }
        }
        $username = 'wc_' . substr(preg_replace('/[^a-zA-Z0-9_]/', '', $userid), 0, 32);
        if (SysUser::where('username', $username)->find()) {
            return json(r_error('账号已存在：' . $username, 400));
        }

        // 默认角色：取企微配置里的默认角色，兜底 viewer；禁止自动授予 admin。
        $cfg = WecomClient::loadConfig();
        try {
            $roleId = \app\service\WecomAuthService::resolveAutoCreateRoleId($cfg);
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage(), 400));
        }
        $plain = self::genPassword();
        $u = new SysUser();
        $u->username       = $username;
        $u->password       = $plain; // setPasswordAttr 会 bcrypt
        $u->real_name      = $wu->name ?: $username;
        $u->phone          = $wu->mobile ?: '';
        $u->role_id        = $roleId;
        $u->status         = 1;
        $u->wecom_userid   = $wu->wecom_userid;
        $u->wecom_avatar   = $wu->avatar ?: null;
        $u->wecom_mobile   = $wu->mobile ?: null;
        $u->wecom_bound_at = date('Y-m-d H:i:s');
        $u->save();

        $wu->bind_user_id = $u->id;
        $wu->save();

        $this->log('企业微信', '创建账号', "{$username} ← {$wu->name}({$userid})");
        return json(r_success([
            'username' => $username,
            'password' => $plain,
            'user_id'  => $u->id,
        ], '已创建系统账号，初始密码请妥善交付（建议用户首次登录后修改）'));
    }

    /**
     * 绑定现有系统账号（v2.14）
     *  POST { id: wecom_user.id, user_id: sys_user.id }
     *  - 校验账号未绑定其他企微身份、成员未绑定其他账号
     *  - 写 sys_user.wecom_* + wecom_user.bind_user_id
     *  - 账号部门与成员部门同步（user_dept_rel）
     */
    public function bindAccount()
    {
        $this->requireAdmin();
        $id     = (int)$this->request->post('id', 0);
        $userId = (int)$this->request->post('user_id', 0);
        if (!$id || !$userId) {
            return json(r_error('缺少 id 或 user_id', 400));
        }
        $wu = WecomUser::find($id);
        if (!$wu) return json(r_error('成员不存在', 404));
        $u = SysUser::find($userId);
        if (!$u) return json(r_error('系统账号不存在', 404));
        if ($u->wecom_userid && $u->wecom_userid !== $wu->wecom_userid) {
            return json(r_error('账号已绑定其他企微身份：' . $u->wecom_userid, 400));
        }
        if ($wu->bind_user_id && (int)$wu->bind_user_id !== $userId) {
            $other = SysUser::find((int)$wu->bind_user_id);
            return json(r_error('该成员已绑定账号：' . ($other ? $other->username : '#' . $wu->bind_user_id), 400));
        }

        $u->wecom_userid   = $wu->wecom_userid;
        $u->wecom_avatar   = $wu->avatar ?: null;
        $u->wecom_mobile   = $wu->mobile ?: null;
        $u->wecom_bound_at = date('Y-m-d H:i:s');
        $u->save();

        $wu->bind_user_id = $userId;
        $wu->save();

        // 部门同步：企微成员部门（wecom_dept_id）→ 本地部门（source=1）→ user_dept_rel
        // 注意：WecomUser::getDeptIdsAttr 访问器已把 dept_ids 解析为数组，这里两种形态都兼容
        $rawDept = $wu->dept_ids;
        $wecomDeptIds = is_array($rawDept) ? $rawDept : (json_decode((string)$rawDept, true) ?: []);
        if ($wecomDeptIds) {
            $localIds = \app\model\OrgDepartment::whereIn('wecom_dept_id', $wecomDeptIds)->column('id');
            if ($localIds) {
                Db::name('user_dept_rel')->where('user_id', $userId)->delete();
                foreach (array_values($localIds) as $i => $did) {
                    Db::name('user_dept_rel')->insert([
                        'user_id'     => $userId,
                        'dept_id'     => (int)$did,
                        'is_primary'  => $i === 0 ? 1 : 0,
                        'create_time' => date('Y-m-d H:i:s'),
                    ]);
                }
                if ($localIds[0]) {
                    $u->dept_id = (int)$localIds[0];
                    $u->save();
                }
                \app\service\DeptScopeService::clearCache();
            }
        }

        $this->log('企业微信', '绑定账号', "{$u->username} ← {$wu->name}({$wu->wecom_userid})");
        return json(r_success(['id' => $userId], '已绑定现有账号'));
    }

    /**
     * 编辑企微成员（本地镜像字段）
     *  - name/mobile/email/position/status
     *  - 若已绑定系统账号，同步 real_name/phone/status 到账号
     */
    public function update()
    {
        $this->requireAdmin();
        $id = (int)$this->request->post('id', 0);
        if (!$id) return json(r_error('缺少 id', 400));
        $wu = WecomUser::find($id);
        if (!$wu) return json(r_error('成员不存在', 404));

        $name    = trim((string)$this->request->post('name', ''));
        $mobile  = trim((string)$this->request->post('mobile', ''));
        $email   = trim((string)$this->request->post('email', ''));
        $position= trim((string)$this->request->post('position', ''));
        $status  = (int)$this->request->post('status', 1);
        if ($name === '') return json(r_error('姓名必填', 400));
        if ($mobile !== '' && !preg_match('/^1[3-9]\d{9}$/', $mobile)) {
            return json(r_error('手机号格式不正确', 400));
        }

        $wu->name     = $name;
        $wu->mobile   = $mobile;
        $wu->email    = $email;
        $wu->position = $position;
        $wu->status   = $status === 1 ? 1 : 2; // 1在职 2离职
        $wu->save();

        // 联动已绑定的系统账号
        if ($wu->bind_user_id) {
            $u = SysUser::find($wu->bind_user_id);
            if ($u) {
                $u->real_name = $name;
                $u->phone     = $mobile;
                $u->status    = $status === 1 ? 1 : 0; // 离职成员 → 禁用账号
                $u->save();
            }
        }
        $this->log('企业微信', '编辑成员', "{$name}({$wu->wecom_userid})");
        return json(r_success(['id' => $wu->id], '保存成功'));
    }

    /**
     * 删除企微成员
     *  - 删除 wecom_user 镜像记录
     *  - 已绑定账号：默认解绑企微并保留账号；delete_account=1 时同时禁用账号
     */
    public function delete()
    {
        $this->requireAdmin();
        $id = (int)$this->request->post('id', 0);
        if (!$id) return json(r_error('缺少 id', 400));
        $wu = WecomUser::find($id);
        if (!$wu) return json(r_error('成员不存在', 404));

        $delAccount = (int)$this->request->post('delete_account', 0) === 1;
        $boundInfo = '';
        if ($wu->bind_user_id) {
            $u = SysUser::find($wu->bind_user_id);
            if ($u) {
                // 解绑企微（账号保留，可改用账号密码登录）
                $u->wecom_userid   = null;
                $u->wecom_avatar   = null;
                $u->wecom_mobile   = null;
                $u->wecom_bound_at = null;
                if ($delAccount) {
                    $u->status = 0; // 禁用而非物理删除，保留业务关联
                    $boundInfo = "，账号 {$u->username} 已禁用";
                } else {
                    $boundInfo = "，账号 {$u->username} 已解绑企微（保留）";
                }
                $u->save();
            }
        }
        $wu->delete();
        $this->log('企业微信', '删除成员', "{$wu->name}({$wu->wecom_userid}){$boundInfo}");
        return json(r_success([], '删除成功' . $boundInfo));
    }

    /** 生成 10 位「字母+数字」初始密码 */
    private static function genPassword(int $len = 10): string
    {
        $letters = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz';
        $digits  = '23456789';
        $out = $letters[random_int(0, strlen($letters) - 1)]
             . $digits[random_int(0, strlen($digits) - 1)];
        $all = $letters . $digits;
        for ($i = 2; $i < $len; $i++) {
            $out .= $all[random_int(0, strlen($all) - 1)];
        }
        // 打乱
        return str_shuffle($out);
    }
}
