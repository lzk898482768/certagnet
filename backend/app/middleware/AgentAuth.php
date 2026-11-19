<?php
namespace app\middleware;

use app\common\Response as R;
use app\model\SysUser;
use app\model\UserCompanyRel;
use app\service\AgentApiService;
use app\service\DeptScopeService;
use think\exception\HttpResponseException;

/**
 * Agent API 鉴权中间件（v2.82）
 *
 * 与后台登录态完全隔离：
 *  - 只接受独立 API Key（Authorization: Bearer cak_xxx 或 X-API-Key）；
 *  - 密钥绑定一个内部账号，数据范围、脱敏规则全部沿用该账号；
 *  - read 密钥禁止任何写操作，write 密钥才放行受控写接口；
 *  - 分钟级限流，Redis 异常时直接放行（不因缓存故障阻断 Agent）。
 */
class AgentAuth
{
    public function handle($request, \Closure $next)
    {
        // v2.99.36：Agent 侧同样保证数据权限基座表结构（进程内幂等，失败不阻断）
        \app\service\DataScopeSchemaService::ensure();
        $plain = $this->extractKey($request);
        if ($plain === '') {
            throw new HttpResponseException(json(
                R::error('缺少 API Key：请使用 Authorization: Bearer <key> 或 X-API-Key: <key>', 401),
                401
            ));
        }

        $key = AgentApiService::verifyKey($plain);
        if (!$key) {
            throw new HttpResponseException(json(R::error('API Key 无效', 401), 401));
        }
        if ((int)$key['status'] !== 1) {
            throw new HttpResponseException(json(R::error('API Key 已停用，请联系管理员', 403), 403));
        }
        if (!empty($key['expires_at']) && strtotime((string)$key['expires_at']) < time()) {
            throw new HttpResponseException(json(R::error('API Key 已过期，请联系管理员续期', 403), 403));
        }

        $user = SysUser::with(['role'])->find((int)$key['owner_user_id']);
        if (!$user || (int)$user->status !== 1) {
            throw new HttpResponseException(json(
                R::error('API Key 绑定的账号已被禁用或不存在，请联系管理员', 403),
                403
            ));
        }

        $payload = [
            'id'         => (int)$user->id,
            'username'   => $user->username,
            'real_name'  => $user->real_name,
            'role_id'    => (int)$user->role_id,
            'role_code'  => $user->role ? (string)$user->role->role_code : '',
            'dept_id'    => (int)$user->dept_id,
            'data_scope' => (string)($user->data_scope ?: 'self'),
        ];
        $isAdmin = $payload['role_code'] === 'admin';

        $request->user       = $payload;
        $request->userId     = $payload['id'];
        $request->roleCode   = $payload['role_code'];
        $request->role_id    = $payload['role_id'];
        $request->agentKey   = $key;
        $request->isAgent    = true;
        $request->agentScopes = array_values(array_filter(explode(',', (string)$key['scopes'])));
        $request->dataScopeAll = $isAdmin;
        $request->companyIds   = $isAdmin
            ? []
            : UserCompanyRel::where('user_id', $payload['id'])->column('company_id');
        $request->deptScope    = DeptScopeService::resolve($payload);

        // 只读密钥禁止写操作
        $method = strtoupper((string)$request->method());
        if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)
            && !in_array(AgentApiService::SCOPE_WRITE, $request->agentScopes, true)) {
            // MCP 端点虽为 POST，但只暴露只读工具（无任何写工具），按只读放行。
            $path = (string)$request->pathinfo();
            $isMcp = $path === 'api/agent/mcp' || substr($path, -10) === 'agent/mcp';
            if (!$isMcp) {
                throw new HttpResponseException(json(
                    R::error('当前 API Key 只有只读权限，写操作需要 write 作用域', 403),
                    403
                ));
            }
        }

        [$allowed, $remaining] = AgentApiService::hitRateLimit((int)$key['id']);
        if (!$allowed) {
            throw new HttpResponseException(json(
                R::error('调用过于频繁：单密钥每分钟最多 ' . AgentApiService::RATE_LIMIT_PER_MINUTE . ' 次', 429),
                429
            ));
        }

        AgentApiService::touch((int)$key['id'], (string)$request->ip());

        $response = $next($request);
        return $response->header([
            'X-Agent-Api-Version'  => AgentApiService::VERSION,
            'X-RateLimit-Limit'    => (string)AgentApiService::RATE_LIMIT_PER_MINUTE,
            'X-RateLimit-Remaining' => (string)$remaining,
        ]);
    }

    /** 从请求头提取 API Key（不读取 query，避免密钥进日志/浏览器历史） */
    protected function extractKey($request): string
    {
        $auth = trim((string)$request->header('authorization', ''));
        if ($auth !== '' && stripos($auth, 'Bearer ') === 0) {
            return trim(substr($auth, 7));
        }
        return trim((string)$request->header('x-api-key', ''));
    }
}
