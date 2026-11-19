<?php
namespace app\controller\admin;

use app\service\AgentApiService;
use app\service\WecomAibotService;
use think\exception\HttpResponseException;

/**
 * Agent 接入密钥管理（v2.82）
 *
 *  - GET  /admin/agent/keys       密钥列表（不含明文与哈希）
 *  - POST /admin/agent/key-save   新建 / 修改（新建时明文只返回一次）
 *  - POST /admin/agent/key-revoke 停用 / 启用
 *  - POST /admin/agent/key-delete 删除
 *  - GET  /admin/agent/catalog    能力清单（设置页展示）
 *  - GET  /admin/agent/skill      SKILL.md + reference.md 渲染结果
 *  - GET  /admin/agent/openapi    OpenAPI 3.0 描述
 *  - GET  /admin/agent/owners     可绑定账号
 *
 * 密钥等同于账号密码，写动作（新建/修改/吊销/删除、企微机器人 Token/AESKey）
 * 全部仅内置管理员可执行（requireAdmin），即便菜单被误授权也不会泄露明文或生成新密钥。
 *
 * v2.98：只读查询（密钥列表/能力清单/SKILL/OpenAPI/可绑定账号）对持有 agent:view
 * 权限的角色开放；密钥明文与哈希本就不下发，企微 Token/AESKey 仍仅管理员可读。
 */
class AgentKey extends AdminController
{
    /**
     * 只读访问门槛：admin 恒通过，其余角色需持有 agent:view 权限码。
     * 菜单级校验已由 PermAuth 中间件完成（须授权菜单 62），这里做功能码兜底。
     */
    protected function requireAgentView(): void
    {
        if (!$this->isAdmin() && !$this->can('agent', 'view')) {
            throw new HttpResponseException(json(r_error('当前角色无 Agent 接入查看权限', 403)));
        }
    }

    public function index()
    {
        $this->requireAgentView();
        AgentApiService::ensureSchema();

        return json(r_success([
            'list'   => AgentApiService::listKeys(),
            'scopes' => [
                ['value' => AgentApiService::SCOPE_READ,  'label' => '只读',        'desc' => '仅可调用查询类接口'],
                ['value' => AgentApiService::SCOPE_WRITE, 'label' => '只读 + 写入', 'desc' => '可更新证书状态、追加跟进记录'],
            ],
            'rate_limit' => AgentApiService::RATE_LIMIT_PER_MINUTE,
        ]));
    }

    public function save()
    {
        $this->requireAdmin();
        AgentApiService::ensureSchema();

        $id     = (int)$this->request->post('id', 0);
        $name   = trim((string)$this->request->post('name', ''));
        $scopes = $this->request->post('scopes', []);
        $owner  = (int)$this->request->post('owner_user_id', 0);
        $expire = trim((string)$this->request->post('expires_at', ''));
        $remark = trim((string)$this->request->post('remark', ''));

        if ($name === '') {
            return json(r_error('请填写密钥名称'), 400);
        }
        if ($expire !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expire)) {
            return json(r_error('有效期格式应为 YYYY-MM-DD'), 400);
        }
        if ($owner > 0 && !$this->ownerExists($owner)) {
            return json(r_error('绑定的账号不存在或已停用'), 400);
        }

        if ($id > 0) {
            $ok = AgentApiService::updateKey($id, [
                'name'          => $name,
                'scopes'        => $scopes,
                'owner_user_id' => $owner > 0 ? $owner : (int)($this->request->userId ?? 0),
                'expires_at'    => $expire,
                'remark'        => $remark,
            ]);
            if (!$ok) {
                return json(r_error('密钥不存在', 404), 404);
            }
            $this->log('Agent 接入', '修改密钥', "密钥 #{$id}：{$name}");

            return json(r_success(['id' => $id], '已保存（密钥明文不变）'));
        }

        $r = AgentApiService::createKey([
            'name'          => $name,
            'scopes'        => $scopes,
            'owner_user_id' => $owner,
            'expires_at'    => $expire,
            'remark'        => $remark,
        ], (int)($this->request->userId ?? 0));

        $this->log('Agent 接入', '创建密钥', "密钥 #{$r['id']}：{$name}");

        return json(r_success([
            'id'        => $r['id'],
            'plain_key' => $r['plain_key'],
        ], '已创建，密钥只显示这一次，请立即复制保存'));
    }

    public function revoke()
    {
        $this->requireAdmin();
        AgentApiService::ensureSchema();

        $id     = (int)$this->request->post('id', 0);
        $status = (int)$this->request->post('status', 0) === 1 ? 1 : 0;
        if ($id <= 0) {
            return json(r_error('缺少密钥 ID'), 400);
        }
        if (!AgentApiService::setStatus($id, $status)) {
            return json(r_error('密钥不存在', 404), 404);
        }
        $this->log('Agent 接入', $status ? '启用密钥' : '吊销密钥', "密钥 #{$id}");

        return json(r_success(['id' => $id, 'status' => $status], $status ? '已启用' : '已吊销'));
    }

    public function delete()
    {
        $this->requireAdmin();
        AgentApiService::ensureSchema();

        $id = (int)$this->request->post('id', 0);
        if ($id <= 0) {
            return json(r_error('缺少密钥 ID'), 400);
        }
        if (!AgentApiService::deleteKey($id)) {
            return json(r_error('密钥不存在', 404), 404);
        }
        $this->log('Agent 接入', '删除密钥', "密钥 #{$id}");

        return json(r_success(['id' => $id], '已删除'));
    }

    /** 能力清单 + 接入信息（设置页展示与复制） */
    public function catalog()
    {
        $this->requireAgentView();
        $base = AgentApiService::baseUrl($this->request);

        return json(r_success([
            'api'        => AgentApiService::VERSION,
            'base_url'   => $base,
            'rate_limit' => AgentApiService::RATE_LIMIT_PER_MINUTE,
            'scopes'     => AgentApiService::allScopes(),
            'tools'      => AgentApiService::capabilities(),
            'endpoints'  => [
                'openapi' => $base . '/openapi',
                'skill'   => $base . '/skill',
                'ping'    => $base . '/ping',
            ],
        ]));
    }

    public function skill()
    {
        $this->requireAgentView();
        $base = AgentApiService::baseUrl($this->request);

        return json(r_success([
            'filename'  => 'SKILL.md',
            'base_url'  => $base,
            'content'   => AgentApiService::skillMarkdown($base),
            'reference' => AgentApiService::referenceMarkdown($base),
        ]));
    }

    public function openapi()
    {
        $this->requireAgentView();

        return json(AgentApiService::openapi(AgentApiService::baseUrl($this->request)));
    }

    public function owners()
    {
        $this->requireAgentView();

        return json(r_success(AgentApiService::ownerOptions()));
    }

    // ------------------------------------------------------------------
    // 企业微信「智能机器人」配置（v2.83）
    // ------------------------------------------------------------------

    /** 读取机器人配置 + 回调地址 */
    public function wecom()
    {
        $this->requireAdmin();

        $cfg  = WecomAibotService::config();
        $base = AgentApiService::baseUrl($this->request);
        $root = preg_replace('#/api/agent/?$#', '', $base);
        if (!$root) {
            $root = rtrim($base, '/');
        }

        return json(r_success([
            'enabled'        => $cfg['enabled'],
            'token'          => $cfg['token'],
            'aes_key'        => $cfg['aes_key'],
            'owner_user_id'  => $cfg['owner_user_id'],
            'welcome'        => $cfg['welcome'] !== '' ? $cfg['welcome'] : WecomAibotService::defaultWelcome(),
            'ready'          => WecomAibotService::ready(),
            // 推荐地址
            'callback_url'   => $root . '/api/wecom/aibot',
            // 兼容：企业微信后台已填 /api/agent 也能用
            'callback_alias' => $base,
            'owners'         => AgentApiService::ownerOptions(),
        ]));
    }

    /** 保存机器人配置 */
    public function wecomSave()
    {
        $this->requireAdmin();

        $r = WecomAibotService::saveConfig([
            'enabled'       => (int)$this->request->post('enabled', 0) === 1,
            'token'         => $this->request->post('token', ''),
            'aes_key'       => $this->request->post('aes_key', ''),
            'owner_user_id' => (int)$this->request->post('owner_user_id', 0),
            'welcome'       => $this->request->post('welcome', ''),
        ]);
        if (!$r['ok']) {
            return json(r_error($r['msg']), 400);
        }
        $this->log('Agent 接入', '保存企业微信机器人', 'Token/EncodingAESKey 已更新');

        return json(r_success([], '已保存'));
    }

    /** 生成随机 Token + EncodingAESKey（长度与字符集符合企业微信要求） */
    public function wecomGen()
    {
        $this->requireAdmin();

        return json(r_success([
            'token'   => WecomAibotService::randomToken(),
            'aes_key' => WecomAibotService::randomAesKey(),
        ]));
    }

    protected function ownerExists(int $userId): bool
    {
        return (bool)\think\facade\Db::name('sys_user')->where('id', $userId)->where('status', 1)->find();
    }
}
