<?php
namespace app\controller\api;

use app\BaseController;
use app\service\AuthService;
use app\service\DeviceService;
use app\service\OperationLogService;
use app\model\SysMenu;
use app\model\SysRole;
use app\model\SysUser;

/**
 * 登录 / 退出 / 当前用户信息
 */
class Auth extends BaseController
{
    // 登录（无需鉴权）
    public function login()
    {
        $username = (string)$this->request->post('username', '');
        $password = (string)$this->request->post('password', '');
        $captchaKey = (string)$this->request->post('captchaKey', '');
        $captchaCode = (string)$this->request->post('captchaCode', '');
        $deviceToken = (string)$this->request->post('device_token', '');
        $deviceId    = (string)$this->request->post('device_id', '');
        if (!$username || !$password) {
            return json(r_error('账号和密码必填', 400));
        }
        try {
            $res = AuthService::login(
                $username,
                $password,
                $captchaKey,
                $captchaCode,
                $this->request->ip(),
                (string)$this->request->header('user-agent', ''),
                $deviceToken,
                $deviceId
            );
        } catch (\Exception $e) {
            return json(r_error($e->getMessage(), 400));
        }
        return json(r_success($res, '登录成功'));
    }

    /**
     * v2.75：二次验证第一步结果查询（企微 OAuth 回调 302 回前端后使用）
     *  - 携带受信任设备令牌时可直接完成登录并返回 token
     */
    public function totpChallenge()
    {
        $challenge   = (string)$this->request->param('challenge', '');
        $deviceToken = (string)$this->request->param('device_token', '');
        if ($challenge === '') {
            return json(r_error('缺少验证会话', 400));
        }
        $info = \app\service\TotpService::challengeInfo(
            $challenge,
            $deviceToken,
            (string)$this->request->ip(),
            (string)$this->request->header('user-agent', ''),
            (string)$this->request->param('device_id', '')
        );
        if ($info === null) {
            return json(r_error('验证会话已过期，请重新登录', 400));
        }
        return json(r_success($info));
    }

    /**
     * v2.75：二次验证（首次绑定 / 日常验证共用）
     */
    public function totpVerify()
    {
        $challenge = (string)$this->request->post('challenge', '');
        $code      = (string)$this->request->post('code', '');
        if ($challenge === '' || $code === '') {
            return json(r_error('验证会话或动态验证码不能为空', 400));
        }
        try {
            $res = \app\service\TotpService::verify(
                $challenge,
                $code,
                (string)$this->request->ip(),
                (string)$this->request->header('user-agent', ''),
                (int)$this->request->post('trust_device', 0) === 1,
                (string)$this->request->post('device_id', '')
            );
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage(), 400));
        }
        return json(r_success($res, '验证成功'));
    }

    public function logout()
    {
        AuthService::logout($this->bearerToken());
        return json(r_success([], '退出成功'));
    }

    // 修改密码（已登录）
    public function changePassword()
    {
        $old = (string)$this->request->post('old_password', '');
        $new = (string)$this->request->post('new_password', '');
        if (!$old || !$new) {
            return json(r_error('原密码和新密码必填', 400));
        }
        if (!SysUser::validatePassword($new)) {
            return json(r_error('新密码需 8-32 位且同时包含字母和数字', 400));
        }
        $user = SysUser::find($this->request->userId);
        if (!$user || !SysUser::checkPassword($old, $user->password)) {
            return json(r_error('原密码错误', 400));
        }
        $user->password = $new;
        $user->save();
        // v2.96：改密后撤销全部登录会话与受信任设备，防止旧令牌 / 旧设备继续使用
        DeviceService::revokeAllSessions((int)$user->id, '修改密码强制重新登录');
        DeviceService::revokeAllTrusted((int)$user->id, '修改密码撤销设备信任');
        OperationLogService::record('账号安全', '修改密码', '修改成功后已下线全部设备并撤销信任');
        // 修改密码后令当前令牌失效，需重新登录
        AuthService::logout($this->bearerToken());
        return json(r_success([], '密码修改成功，请重新登录'));
    }

    /**
     * 当前请求携带的登录令牌
     *
     * v2.99.1（INFO-02）：H5 端改用 HttpOnly Cookie 承载令牌，请求头可能没有
     * Authorization，此时回退读 Cookie，否则退出 / 改密无法吊销服务端会话。
     */
    private function bearerToken(): string
    {
        $token = (string)$this->request->header('Authorization', '');
        if (strpos($token, 'Bearer ') === 0) {
            $token = substr($token, 7);
        }
        if ($token === '') {
            $token = \app\common\AuthCookie::read($this->request);
        }
        return $token;
    }

    /**
     * v2.96：我的登录设备 / 受信任设备
     * 任何已登录用户都可以查看并管理自己的设备，不需要额外权限码。
     */
    public function devices()
    {
        $uid   = (int)$this->request->userId;
        $token = $this->bearerToken();
        $hash  = DeviceService::hashToken($token);

        $sessions = DeviceService::listSessions($uid, true);
        foreach ($sessions as &$s) {
            $s['is_current'] = ((string)($s['token_hash'] ?? '') === $hash);
            unset($s['token_hash']);
        }
        unset($s);

        $trusted = DeviceService::listTrusted($uid, true);
        foreach ($trusted as &$t) {
            unset($t['token_hash']);
        }
        unset($t);

        return json(r_success([
            'sessions'      => $sessions,
            'trusted'       => $trusted,
            'token_ttl_hours' => (int)(AuthService::tokenTtl() / 3600),
            'trust_days'    => DeviceService::trustDays(),
        ]));
    }

    /** v2.96：下线自己的某台登录设备 / 移除自己的某台信任设备 */
    public function revokeDevice()
    {
        $uid  = (int)$this->request->userId;
        $type = (string)$this->request->post('type', 'session');
        $id   = (int)$this->request->post('id', 0);
        if ($id <= 0) {
            return json(r_error('缺少设备编号', 400));
        }
        if ($type === 'trusted') {
            $ok = DeviceService::revokeTrusted($id, $uid, '用户移除设备信任');
            $label = '移除信任';
        } else {
            $ok = DeviceService::revokeSession($id, $uid, '用户主动下线设备');
            $label = '下线设备';
        }
        if (!$ok) {
            return json(r_error('设备不存在或已失效', 404));
        }
        OperationLogService::record('登录设备', $label, '设备ID=' . $id);
        return json(r_success([], $label . '成功'));
    }

    /** v2.96：批量下线其他设备 / 撤销全部信任 */
    public function revokeAllDevices()
    {
        $uid  = (int)$this->request->userId;
        $type = (string)$this->request->post('type', 'all');
        $token = $this->bearerToken();

        $sessions = 0;
        $trusted  = 0;
        if ($type === 'session' || $type === 'all') {
            // 保留当前会话，避免用户把自己也踢下线
            $sessions = DeviceService::revokeAllSessions($uid, '用户下线其他设备', $token);
        }
        if ($type === 'trusted' || $type === 'all') {
            $trusted = DeviceService::revokeAllTrusted($uid, '用户撤销全部设备信任');
        }
        OperationLogService::record('登录设备', '批量管理', "下线会话 {$sessions} 个，撤销信任 {$trusted} 个");
        return json(r_success(['sessions' => $sessions, 'trusted' => $trusted], '操作成功'));
    }

    // 当前登录信息 + 菜单
    public function info()
    {
        // 菜单架构自愈（v2.28）：老库首次登录即对齐新分组，无需手动执行 SQL
        \app\service\MenuCatalog::ensureSchema();

        $user = $this->request->user;
        $role  = SysRole::find($user['role_id']);
        $menus = [];
        if ($role) {
            $menuIds = $role->menu_ids; // 模型访问器：空值 → []
            // v2.38：菜单树按「授权指纹」缓存（Redis，30 分钟）。
            // 管理员改动菜单或角色授权时由 MenuCatalog::flushThrottle() 整命名空间失效，
            // 避免每个页面加载都全表查 sys_menu + buildTree。
            $fingerprint = empty($menuIds) ? 'all' : md5(implode(',', $menuIds));
            $menus = \app\service\RedisCache::remember(
                \app\service\RedisCache::NS_MENU,
                'tree_' . $fingerprint,
                \app\service\RedisCache::TTL_LONG,
                function () use ($menuIds) {
                    $list = empty($menuIds)
                        ? SysMenu::where('status', 1)->order('sort')->select()->toArray()
                        : SysMenu::whereIn('id', $menuIds)->where('status', 1)->order('sort')->select()->toArray();
                    return SysMenu::buildTree($list);
                }
            );
        }
        // 实时查库补充：数据范围 / 功能权限 / 多部门（管理员调整后立即生效，不受登录缓存影响）
        $u = SysUser::find($user['id']);
        // v2.69：扁平权限码 {"cert:create":1}；null = 不限制（仅 admin）
        $perms = \app\service\PermissionCatalog::rolePerms((int)$user['role_id']);
        $deptIds = [];
        if ($u) {
            $deptIds = \think\facade\Db::name('user_dept_rel')
                ->where('user_id', $user['id'])->order('is_primary', 'desc')->column('dept_id');
        }
        $userData = array_merge($user, [
            'data_scope' => $u ? (string)$u->data_scope : 'self',
            'dept_id'    => $u ? (int)$u->dept_id : 0,
            'dept_ids'   => array_map('intval', $deptIds),
            'wecom_bound' => $u ? !empty($u->wecom_userid) : false,
            'totp_enabled' => $u ? !empty($u->totp_enabled) : false,
        ]);
        // admin 角色功能权限恒通过，前端直接置 null（不限制）
        if (($user['role_code'] ?? '') === 'admin') {
            $perms = null;
        }
        // v2.69：空分组不返回（避免侧边栏出现点不动的空目录）
        // 飞牛版关闭在线升级：菜单树可能命中旧 Redis 缓存，输出前再过滤 74 号菜单
        if (!\app\service\UpgradeService::enabled()) {
            $menus = \app\service\MenuCatalog::withoutUpgradeMenu($menus);
        }
        $menus = SysMenu::pruneEmptyGroups($menus);
        return json(r_success(['user' => $userData, 'menus' => $menus, 'perms' => $perms]));
    }
}
