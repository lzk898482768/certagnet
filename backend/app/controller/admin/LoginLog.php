<?php
namespace app\controller\admin;

use app\model\LoginLog as LoginLogModel;
use app\service\LoginLogService;

/**
 * 用户登录日志（v2.70）
 *
 *  - GET  /admin/login-log        列表（菜单授权可见，默认仅超级管理员）
 *  - POST /admin/login-log/clear  清空（仅超级管理员）
 *
 * 数据范围：登录日志属于全局审计数据，**不做用户 / 部门范围过滤**，
 *           管理员（含被授予「登录日志」菜单的角色）看到的是全部用户的记录。
 * 说明：清空属于高危操作，除接口权限码 loginlog:clear 外，
 *       控制器内再强制 isAdmin()，双重拦截非管理员。
 */
class LoginLog extends AdminController
{
    public function index()
    {
        LoginLogService::ensureTables();
        [$page, $limit] = $this->pageParams();

        $keyword   = trim((string)$this->request->param('keyword', ''));
        $status    = $this->request->param('status', '');
        $loginType = trim((string)$this->request->param('login_type', ''));
        $dateStart = trim((string)$this->request->param('date_start', ''));
        $dateEnd   = trim((string)$this->request->param('date_end', ''));

        // 全局审计：不按当前登录人 / 部门过滤，管理员可查看所有用户
        $q = LoginLogModel::order('id', 'desc');
        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $q->where(function ($w) use ($like) {
                $w->whereLike('username', $like)
                  ->whereOr('real_name', 'like', $like)
                  ->whereOr('ip', 'like', $like);
            });
        }
        if ($status !== '' && $status !== null) {
            $q->where('status', (int)$status);
        }
        if ($loginType !== '') {
            $q->where('login_type', $loginType);
        }
        if ($dateStart !== '') {
            $q->where('login_time', '>=', $dateStart . ' 00:00:00');
        }
        if ($dateEnd !== '') {
            $q->where('login_time', '<=', $dateEnd . ' 23:59:59');
        }

        $total = $q->count();
        $list  = $q->page($page, $limit)->select()->toArray();
        foreach ($list as &$row) {
            $row['status_text'] = LoginLogService::STATUSES[(int)($row['status'] ?? 0)] ?? '未知';
            $type = (string)($row['login_type'] ?? 'password');
            $row['login_type']      = isset(LoginLogService::LOGIN_TYPES[$type]) ? $type : 'password';
            $row['login_type_text'] = LoginLogService::LOGIN_TYPES[$row['login_type']];
        }
        unset($row);

        return json(r_success([
            'list'        => $list,
            'total'       => $total,
            'statuses'    => LoginLogService::STATUSES,
            'login_types' => LoginLogService::LOGIN_TYPES,
        ]));
    }

    public function clear()
    {
        // 双重拦截：非 admin 即便被误授权也不允许清空
        $this->requireAdmin();
        LoginLogService::ensureTables();

        $deleted = LoginLogModel::where('id', '>', 0)->delete();
        $this->log('登录日志', '清空', '清空 ' . (int)$deleted . ' 条登录日志');

        return json(r_success(['deleted' => $deleted], '已清空 ' . (int)$deleted . ' 条登录日志'));
    }
}
