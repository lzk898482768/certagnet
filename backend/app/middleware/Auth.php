<?php
namespace app\middleware;

use think\exception\HttpResponseException;
use think\facade\Cache;
use think\Response;
use app\common\Response as R;
use app\model\SysUser;
use app\service\DeviceService;

/**
 * Token 鉴权中间件
 * 请求头：Authorization: Bearer <token>
 *
 * v2.40：token 命中后实时复核账号状态与角色。
 * 登录缓存里的 role_code 可能因管理员改角色/禁用账号而过期，
 * 只信任 token 会导致最长 12 小时内权限不生效。这里每次请求回查
 * sys_user（轻量主键查询），同时把最新的部门/数据范围写入请求上下文。
 */
class Auth
{
    public function handle($request, \Closure $next)
    {
        $token = $request->header('Authorization', '');
        if ($token && strpos($token, 'Bearer ') === 0) {
            $token = substr($token, 7);
        }
        // v2.99.1（INFO-02）：浏览器端改用 HttpOnly Cookie 承载令牌，
        // 请求头未携带时回退读取 Cookie（移动端 / Agent 仍走 Bearer）。
        if (!$token) {
            $token = \app\common\AuthCookie::read($request);
        }
        if (!$token) {
            throw new HttpResponseException(json(R::error('未登录', 401)));
        }
        // CSRF：仅针对「用 Cookie 认证的写请求」校验来源是否同源
        if (!\app\common\AuthCookie::csrfOk($request)) {
            throw new HttpResponseException(json(R::error('请求来源校验失败，请刷新页面后重试', 403)));
        }

        $cached = Cache::get('token_' . $token);
        if (!$cached || empty($cached['id'])) {
            throw new HttpResponseException(json(R::error('登录已过期，请重新登录', 401)));
        }

        // v2.96：会话被用户 / 超管主动下线，或已过期 → 立即失效
        if (DeviceService::isSessionRevoked($token)) {
            Cache::delete('token_' . $token);
            throw new HttpResponseException(json(R::error('登录会话已失效，请重新登录', 401)));
        }

        $user = SysUser::with(['role'])->find((int)$cached['id']);
        if (!$user || (int)$user->status !== 1) {
            Cache::delete('token_' . $token);
            throw new HttpResponseException(json(R::error('账号已被禁用或不存在，请重新登录', 401)));
        }

        // 以数据库为权威，合并 token 中的会话字段（如企微绑定标记）
        $payload = array_merge($cached, [
            'id'         => (int)$user->id,
            'username'   => $user->username,
            'real_name'  => $user->real_name,
            'role_id'    => (int)$user->role_id,
            'role_code'  => $user->role ? (string)$user->role->role_code : '',
            'dept_id'    => (int)$user->dept_id,
            'data_scope' => (string)($user->data_scope ?: 'self'),
            'wecom_bound'=> !empty($user->wecom_userid),
        ]);

        $request->user      = $payload;
        $request->userId    = $payload['id'];
        $request->roleCode  = $payload['role_code'];
        $request->role_id   = $payload['role_id'];

        return $next($request);
    }
}
