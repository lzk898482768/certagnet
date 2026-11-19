<?php
namespace app\controller\admin;

use app\service\DeviceService;
use think\facade\Db;

/**
 * 登录设备管理（超级管理员，v2.96）
 *
 *  - GET  /admin/login-device            全部用户的登录会话 / 信任设备（分页）
 *  - GET  /admin/login-device/users      用户选择器
 *  - POST /admin/login-device/revoke     下线单台设备 / 移除单个信任
 *  - POST /admin/login-device/revoke-all 下线某用户全部会话 / 撤销某用户全部信任
 *
 * 涉及公民信息，设备与会话属于安全敏感数据：仅超级管理员可访问。
 */
class LoginDevice extends AdminController
{
    public function index()
    {
        $this->requireAdmin();
        DeviceService::ensureTables();
        [$page, $limit] = $this->pageParams();

        $tab     = (string)$this->request->param('tab', 'session');
        $keyword = trim((string)$this->request->param('keyword', ''));
        $userId  = (int)$this->request->param('user_id', 0);
        $status  = $this->request->param('status', '1');

        $table = $tab === 'trusted' ? 'sys_trusted_device' : 'sys_login_session';
        $q = Db::name($table)->alias('d')
            ->leftJoin('sys_user u', 'u.id = d.user_id')
            ->field('d.*, u.username, u.real_name');

        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $q->where(function ($w) use ($like) {
                $w->whereLike('u.username', $like)
                  ->whereOr('u.real_name', 'like', $like)
                  ->whereOr('d.device_name', 'like', $like)
                  ->whereOr('d.ip', 'like', $like);
            });
        }
        if ($userId > 0) {
            $q->where('d.user_id', $userId);
        }
        if ($status !== '' && $status !== null) {
            $q->where('d.status', (int)$status);
            if ((int)$status === 1) {
                $q->where('d.expires_at', '>', date('Y-m-d H:i:s'));
            }
        }

        $total = $q->count();
        $list  = $q->order('d.id', 'desc')->page($page, $limit)->select()->toArray();
        $now   = time();
        foreach ($list as &$row) {
            unset($row['token_hash']);
            $row['username']    = (string)($row['username'] ?? '');
            $row['real_name']   = (string)($row['real_name'] ?? '');
            $row['is_expired']  = !empty($row['expires_at']) && strtotime((string)$row['expires_at']) < $now;
            $row['status_text'] = ((int)$row['status'] === 1 && !$row['is_expired']) ? '有效' : (((int)$row['status'] === 1) ? '已过期' : '已撤销');
        }
        unset($row);

        return json(r_success([
            'list'  => $list,
            'total' => $total,
            'tab'   => $tab,
        ]));
    }

    /** 用户选择器（仅返回启用账号，最多 500 条） */
    public function users()
    {
        $this->requireAdmin();
        $keyword = trim((string)$this->request->param('keyword', ''));
        $q = Db::name('sys_user')->field('id,username,real_name')->where('status', 1);
        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $q->where(function ($w) use ($like) {
                $w->whereLike('username', $like)->whereOr('real_name', 'like', $like);
            });
        }
        $list = $q->order('id', 'asc')->limit(500)->select()->toArray();
        return json(r_success(['list' => $list]));
    }

    /** 下线单条会话 / 撤销单个信任设备 */
    public function revoke()
    {
        $this->requireAdmin();
        $type = (string)$this->request->post('type', 'session');
        $id   = (int)$this->request->post('id', 0);
        if ($id <= 0) {
            return json(r_error('缺少设备编号', 400));
        }

        if ($type === 'trusted') {
            $ok = DeviceService::revokeTrusted($id, 0, '超级管理员撤销设备信任');
            $label = '撤销信任';
        } else {
            $ok = DeviceService::revokeSession($id, 0, '超级管理员强制下线');
            $label = '强制下线';
        }
        if (!$ok) {
            return json(r_error('设备不存在或已失效', 404));
        }
        $this->log('登录设备', $label, '设备ID=' . $id);
        return json(r_success([], $label . '成功'));
    }

    /** 下线某用户全部会话 / 撤销全部信任 */
    public function revokeAll()
    {
        $this->requireAdmin();
        $userId = (int)$this->request->post('user_id', 0);
        $type   = (string)$this->request->post('type', 'session');
        if ($userId <= 0) {
            return json(r_error('请选择用户', 400));
        }
        $user = \app\model\SysUser::find($userId);
        if (!$user) {
            return json(r_error('用户不存在', 404));
        }

        $sessions = 0;
        $trusted  = 0;
        if ($type === 'session' || $type === 'all') {
            $sessions = DeviceService::revokeAllSessions($userId, '超级管理员强制下线');
        }
        if ($type === 'trusted' || $type === 'all') {
            $trusted = DeviceService::revokeAllTrusted($userId, '超级管理员撤销设备信任');
        }
        $this->log('登录设备', '批量管理', "用户 {$user->username}：下线会话 {$sessions} 个，撤销信任 {$trusted} 个");
        return json(r_success(['sessions' => $sessions, 'trusted' => $trusted], '操作成功'));
    }
}
