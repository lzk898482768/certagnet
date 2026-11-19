<?php
namespace app\service;

use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;
use Throwable;

/**
 * Agent 接入服务（v2.82）
 *
 * 目标：让外部 Agent（Claude Code / OpenCode / Dify / n8n 等）通过独立 API Key
 * 以「只读」或「只读 + 受控写入」方式访问本系统，数据范围跟随密钥绑定账号。
 *
 * 设计要点：
 *  - 密钥只在创建时明文返回一次，库内只存 sha256 哈希与前缀；
 *  - 权限分 read / write 两档，写接口在中间件与控制器双重校验；
 *  - 数据范围复用 DeptScopeService / 企业授权关系，避免绕过现有 RBAC；
 *  - 结构自愈：老库 git pull 后自动建表，无需手工执行 SQL；
 *  - 能力清单（capabilities）同时用于 OpenAPI 生成、设置页展示与 Skill 文档，
 *    避免「文档写了但接口没有」的漂移。
 */
class AgentApiService
{
    /** API 版本（出现在响应与 OpenAPI 中） */
    public const VERSION = 'v1';

    /** 密钥明文前缀，便于在日志/界面中识别 */
    public const KEY_PREFIX = 'cak_';

    /** 作用域 */
    public const SCOPE_READ  = 'read';
    public const SCOPE_WRITE = 'write';

    /** 单密钥每分钟请求上限 */
    public const RATE_LIMIT_PER_MINUTE = 120;

    /** 结构自愈只做一次（进程内） */
    private static bool $schemaReady = false;

    /**
     * 建表（幂等）。老库只执行 git pull 也能自动补齐。
     */
    public static function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }
        try {
            Db::execute("CREATE TABLE IF NOT EXISTS `sys_agent_key` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `name` varchar(64) NOT NULL DEFAULT '' COMMENT '密钥名称/用途',
                `key_prefix` varchar(16) NOT NULL DEFAULT '' COMMENT '明文前缀，便于识别',
                `key_hash` char(64) NOT NULL DEFAULT '' COMMENT 'sha256(完整密钥)',
                `scopes` varchar(64) NOT NULL DEFAULT 'read' COMMENT 'read / read,write',
                `owner_user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '绑定账号，决定数据范围',
                `status` tinyint(4) NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
                `expires_at` datetime NULL DEFAULT NULL COMMENT '过期时间，NULL=永久',
                `last_used_at` datetime NULL DEFAULT NULL,
                `last_used_ip` varchar(64) NOT NULL DEFAULT '',
                `call_count` int unsigned NOT NULL DEFAULT 0,
                `remark` varchar(255) NOT NULL DEFAULT '',
                `create_by` int unsigned NOT NULL DEFAULT 0,
                `create_time` datetime NULL DEFAULT NULL,
                `update_time` datetime NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_key_hash` (`key_hash`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Agent 接入密钥（v2.82）'");
        } catch (Throwable $e) {
            Log::warning('[agent] 创建 sys_agent_key 表失败：' . $e->getMessage());
        }
        self::$schemaReady = true;
    }

    /**
     * Agent 能力清单（唯一事实来源）
     *
     * scope=read  ：只读接口，read / write 密钥均可调用
     * scope=write ：受控写入，仅 write 密钥可调用
     */
    public static function capabilities(): array
    {
        return [
            [
                'name'    => 'ping',
                'scope'   => self::SCOPE_READ,
                'method'  => 'GET',
                'path'    => '/ping',
                'summary' => '健康检查：验证 API Key 是否有效，返回服务名、版本与服务器时间',
                'params'  => [],
            ],
            [
                'name'    => 'overview',
                'scope'   => self::SCOPE_READ,
                'method'  => 'GET',
                'path'    => '/overview',
                'summary' => '数据概览：企业 / 人员 / 证书 / 合同 / 30 天内到期 / 未读提醒数量',
                'params'  => [],
            ],
            [
                'name'    => 'list_certificates',
                'scope'   => self::SCOPE_READ,
                'method'  => 'GET',
                'path'    => '/certificates',
                'summary' => '分页查询证照台账（数据范围跟随密钥绑定账号）',
                'params'  => [
                    ['name' => 'page',          'in' => 'query', 'type' => 'integer', 'required' => false, 'description' => '页码，默认 1'],
                    ['name' => 'limit',         'in' => 'query', 'type' => 'integer', 'required' => false, 'description' => '每页条数，默认 20，最大 100'],
                    ['name' => 'keyword',       'in' => 'query', 'type' => 'string',  'required' => false, 'description' => '证书名称 / 编号 / 专业 / 类型模糊匹配'],
                    ['name' => 'staff_id',      'in' => 'query', 'type' => 'integer', 'required' => false, 'description' => '按持证人 ID 过滤'],
                    ['name' => 'company_id',    'in' => 'query', 'type' => 'integer', 'required' => false, 'description' => '按当前借调企业 ID 过滤'],
                    ['name' => 'cert_type',     'in' => 'query', 'type' => 'string',  'required' => false, 'description' => '证书类型'],
                    ['name' => 'cert_status',   'in' => 'query', 'type' => 'integer', 'required' => false, 'description' => '0 已过期 / 1 正常 / 2 挂失 / 3 注销'],
                    ['name' => 'social_status', 'in' => 'query', 'type' => 'integer', 'required' => false, 'description' => '0 未知 / 1 在缴 / 2 停缴 / 3 未缴纳'],
                    ['name' => 'expire_days',   'in' => 'query', 'type' => 'integer', 'required' => false, 'description' => '仅返回 N 天内到期且状态正常的证书'],
                    ['name' => 'only_free',     'in' => 'query', 'type' => 'integer', 'required' => false, 'description' => '1 = 只看未被借调的证书'],
                ],
            ],
            [
                'name'    => 'get_certificate',
                'scope'   => self::SCOPE_READ,
                'method'  => 'GET',
                'path'    => '/certificates/detail',
                'summary' => '按 ID 查询单张证书详情（含持证人、当前借调）',
                'params'  => [
                    ['name' => 'id', 'in' => 'query', 'type' => 'integer', 'required' => true, 'description' => '证书 ID'],
                ],
            ],
            [
                'name'    => 'list_staff',
                'scope'   => self::SCOPE_READ,
                'method'  => 'GET',
                'path'    => '/staff',
                'summary' => '分页查询人员档案',
                'params'  => [
                    ['name' => 'page',     'in' => 'query', 'type' => 'integer', 'required' => false, 'description' => '页码，默认 1'],
                    ['name' => 'limit',    'in' => 'query', 'type' => 'integer', 'required' => false, 'description' => '每页条数，默认 20，最大 100'],
                    ['name' => 'keyword',  'in' => 'query', 'type' => 'string',  'required' => false, 'description' => '姓名 / 手机号 / 身份证号模糊匹配'],
                    ['name' => 'status',   'in' => 'query', 'type' => 'integer', 'required' => false, 'description' => '1 在职 / 0 离职'],
                    ['name' => 'company_id','in' => 'query', 'type' => 'integer', 'required' => false, 'description' => '按关联企业 ID 过滤'],
                ],
            ],
            [
                'name'    => 'get_staff',
                'scope'   => self::SCOPE_READ,
                'method'  => 'GET',
                'path'    => '/staff/detail',
                'summary' => '按 ID 查询人员档案详情（含关联企业）',
                'params'  => [
                    ['name' => 'id', 'in' => 'query', 'type' => 'integer', 'required' => true, 'description' => '人员 ID'],
                ],
            ],
            [
                'name'    => 'list_companies',
                'scope'   => self::SCOPE_READ,
                'method'  => 'GET',
                'path'    => '/companies',
                'summary' => '分页查询企业客户',
                'params'  => [
                    ['name' => 'page',         'in' => 'query', 'type' => 'integer', 'required' => false, 'description' => '页码，默认 1'],
                    ['name' => 'limit',        'in' => 'query', 'type' => 'integer', 'required' => false, 'description' => '每页条数，默认 20，最大 100'],
                    ['name' => 'keyword',      'in' => 'query', 'type' => 'string',  'required' => false, 'description' => '企业名称 / 简称 / 信用代码模糊匹配'],
                    ['name' => 'status',       'in' => 'query', 'type' => 'integer', 'required' => false, 'description' => '1 启用 / 0 停用'],
                ],
            ],
            [
                'name'    => 'get_company',
                'scope'   => self::SCOPE_READ,
                'method'  => 'GET',
                'path'    => '/companies/detail',
                'summary' => '按 ID 查询企业详情（含资质）',
                'params'  => [
                    ['name' => 'id', 'in' => 'query', 'type' => 'integer', 'required' => true, 'description' => '企业 ID'],
                ],
            ],
            [
                'name'    => 'list_contracts',
                'scope'   => self::SCOPE_READ,
                'method'  => 'GET',
                'path'    => '/contracts',
                'summary' => '分页查询服务合同',
                'params'  => [
                    ['name' => 'page',        'in' => 'query', 'type' => 'integer', 'required' => false, 'description' => '页码，默认 1'],
                    ['name' => 'limit',       'in' => 'query', 'type' => 'integer', 'required' => false, 'description' => '每页条数，默认 20，最大 100'],
                    ['name' => 'keyword',     'in' => 'query', 'type' => 'string',  'required' => false, 'description' => '合同编号 / 名称 / 甲方 / 乙方模糊匹配'],
                    ['name' => 'company_id',  'in' => 'query', 'type' => 'integer', 'required' => false, 'description' => '按企业 ID 过滤'],
                    ['name' => 'status',      'in' => 'query', 'type' => 'integer', 'required' => false, 'description' => '1 执行中 / 0 到期或终止'],
                    ['name' => 'expiring',    'in' => 'query', 'type' => 'integer', 'required' => false, 'description' => '仅返回 N 天内到期的执行中合同'],
                ],
            ],
            [
                'name'    => 'get_contract',
                'scope'   => self::SCOPE_READ,
                'method'  => 'GET',
                'path'    => '/contracts/detail',
                'summary' => '按 ID 查询合同详情',
                'params'  => [
                    ['name' => 'id', 'in' => 'query', 'type' => 'integer', 'required' => true, 'description' => '合同 ID'],
                ],
            ],
            [
                'name'    => 'list_expiring',
                'scope'   => self::SCOPE_READ,
                'method'  => 'GET',
                'path'    => '/expiring',
                'summary' => '查询 N 天内到期的证书（默认 30 天）',
                'params'  => [
                    ['name' => 'days',  'in' => 'query', 'type' => 'integer', 'required' => false, 'description' => '到期天数窗口，默认 30，最大 365'],
                    ['name' => 'page',  'in' => 'query', 'type' => 'integer', 'required' => false, 'description' => '页码，默认 1'],
                    ['name' => 'limit', 'in' => 'query', 'type' => 'integer', 'required' => false, 'description' => '每页条数，默认 20，最大 100'],
                ],
            ],
            [
                'name'    => 'update_certificate_status',
                'scope'   => self::SCOPE_WRITE,
                'method'  => 'POST',
                'path'    => '/certificates/status',
                'summary' => '受控写入：更新证书状态，并写入状态变更日志与操作日志',
                'params'  => [
                    ['name' => 'id',          'in' => 'body', 'type' => 'integer', 'required' => true,  'description' => '证书 ID'],
                    ['name' => 'cert_status', 'in' => 'body', 'type' => 'integer', 'required' => true,  'description' => '0 已过期 / 1 正常 / 2 挂失 / 3 注销'],
                    ['name' => 'remark',      'in' => 'body', 'type' => 'string',  'required' => false, 'description' => '变更说明，最长 200 字'],
                ],
            ],
            [
                'name'    => 'add_follow',
                'scope'   => self::SCOPE_WRITE,
                'method'  => 'POST',
                'path'    => '/crm/follow',
                'summary' => '受控写入：为 企业 / 证书 / 人员 追加一条跟进记录',
                'params'  => [
                    ['name' => 'module',           'in' => 'body', 'type' => 'string',  'required' => true,  'description' => 'company / certificate / staff'],
                    ['name' => 'record_id',        'in' => 'body', 'type' => 'integer', 'required' => true,  'description' => '对应业务记录 ID'],
                    ['name' => 'follow_type',      'in' => 'body', 'type' => 'string',  'required' => true,  'description' => '电话 / 微信 / 上门拜访 / 邮件 / 短信 / 会议 / 其他'],
                    ['name' => 'content',          'in' => 'body', 'type' => 'string',  'required' => true,  'description' => '跟进内容，最长 2000 字'],
                    ['name' => 'next_follow_date', 'in' => 'body', 'type' => 'string',  'required' => false, 'description' => '下次跟进日期 YYYY-MM-DD'],
                ],
            ],
        ];
    }

    /** 能力清单里出现过的全部作用域 */
    public static function allScopes(): array
    {
        return [self::SCOPE_READ, self::SCOPE_WRITE];
    }

    /** 规范化作用域：始终包含 read，write 可选 */
    public static function normalizeScopes($raw): array
    {
        $list = is_array($raw) ? $raw : explode(',', (string)$raw);
        $out = [];
        foreach ($list as $s) {
            $s = strtolower(trim((string)$s));
            if ($s === self::SCOPE_WRITE) {
                $out[self::SCOPE_WRITE] = true;
            }
        }
        // read 是基础能力，不能单独关闭
        return array_values(array_merge([self::SCOPE_READ], array_keys($out)));
    }

    public static function scopesToString(array $scopes): string
    {
        return implode(',', self::normalizeScopes($scopes));
    }

    /** 生成新密钥：返回 [明文, 哈希, 前缀] */
    public static function generateKey(): array
    {
        $plain = self::KEY_PREFIX . bin2hex(random_bytes(24));
        return [$plain, hash('sha256', $plain), substr($plain, 0, 12)];
    }

    /**
     * 创建密钥
     * @return array{id:int, plain_key:string}
     */
    public static function createKey(array $data, int $operatorId): array
    {
        self::ensureSchema();
        [$plain, $hash, $prefix] = self::generateKey();
        $expires = trim((string)($data['expires_at'] ?? ''));
        $id = (int)Db::name('sys_agent_key')->insertGetId([
            'name'          => mb_substr(trim((string)($data['name'] ?? '')), 0, 64) ?: 'Agent 密钥',
            'key_prefix'    => $prefix,
            'key_hash'      => $hash,
            'scopes'        => self::scopesToString($data['scopes'] ?? []),
            'owner_user_id' => (int)($data['owner_user_id'] ?? 0) ?: $operatorId,
            'status'        => 1,
            'expires_at'    => $expires !== '' ? $expires . ' 23:59:59' : null,
            'remark'        => mb_substr(trim((string)($data['remark'] ?? '')), 0, 255),
            'create_by'     => $operatorId,
            'create_time'   => date('Y-m-d H:i:s'),
            'update_time'   => date('Y-m-d H:i:s'),
        ]);
        return ['id' => $id, 'plain_key' => $plain];
    }

    /**
     * 更新密钥元信息（不改变明文）
     */
    public static function updateKey(int $id, array $data): bool
    {
        self::ensureSchema();
        $row = Db::name('sys_agent_key')->where('id', $id)->find();
        if (!$row) {
            return false;
        }
        $upd = [];
        if (array_key_exists('name', $data)) {
            $upd['name'] = mb_substr(trim((string)$data['name']), 0, 64);
        }
        if (array_key_exists('scopes', $data)) {
            $upd['scopes'] = self::scopesToString($data['scopes']);
        }
        if (array_key_exists('owner_user_id', $data)) {
            $upd['owner_user_id'] = (int)$data['owner_user_id'];
        }
        if (array_key_exists('expires_at', $data)) {
            $expires = trim((string)$data['expires_at']);
            $upd['expires_at'] = $expires !== '' ? $expires . ' 23:59:59' : null;
        }
        if (array_key_exists('remark', $data)) {
            $upd['remark'] = mb_substr(trim((string)$data['remark']), 0, 255);
        }
        $upd['update_time'] = date('Y-m-d H:i:s');
        Db::name('sys_agent_key')->where('id', $id)->update($upd);
        return true;
    }

    /** 启用 / 停用 */
    public static function setStatus(int $id, int $status): bool
    {
        self::ensureSchema();
        return Db::name('sys_agent_key')->where('id', $id)->update([
            'status'      => $status ? 1 : 0,
            'update_time' => date('Y-m-d H:i:s'),
        ]) !== false;
    }

    public static function deleteKey(int $id): bool
    {
        self::ensureSchema();
        return Db::name('sys_agent_key')->where('id', $id)->delete() > 0;
    }

    /** 密钥列表（含绑定账号姓名；绝不返回哈希） */
    public static function listKeys(): array
    {
        self::ensureSchema();
        $rows = Db::name('sys_agent_key')->order('id', 'desc')->select()->toArray();
        $userIds = array_values(array_unique(array_filter(array_map('intval', array_column($rows, 'owner_user_id')))));
        $users = [];
        if ($userIds) {
            $userRows = Db::name('sys_user')->whereIn('id', $userIds)
                ->field('id, username, real_name')->select()->toArray();
            foreach ($userRows as $u) {
                $users[(int)$u['id']] = $u;
            }
        }
        foreach ($rows as &$r) {
            $u = $users[(int)$r['owner_user_id']] ?? [];
            $r['owner_name'] = $u['real_name'] ?? ($u['username'] ?? '');
            $r['scopes_arr'] = array_values(array_filter(explode(',', (string)$r['scopes'])));
            unset($r['key_hash']);
        }
        unset($r);
        return $rows;
    }

    /**
     * 校验明文密钥
     *
     * @return array|null 命中返回密钥记录（不含哈希），否则 null
     */
    public static function verifyKey(string $plain): ?array
    {
        self::ensureSchema();
        $plain = trim($plain);
        if ($plain === '' || !str_starts_with($plain, self::KEY_PREFIX)) {
            return null;
        }
        $row = Db::name('sys_agent_key')->where('key_hash', hash('sha256', $plain))->find();
        if (!$row) {
            return null;
        }
        unset($row['key_hash']);
        return $row;
    }

    /**
     * 记录一次调用（用于设置页展示「最近调用 / 调用次数」）
     */
    public static function touch(int $id, string $ip): void
    {
        try {
            Db::name('sys_agent_key')->where('id', $id)->update([
                'last_used_at' => date('Y-m-d H:i:s'),
                'last_used_ip' => mb_substr($ip, 0, 64),
                'call_count'   => Db::raw('call_count + 1'),
            ]);
        } catch (Throwable $e) {
            // 统计失败不影响调用
        }
    }

    /**
     * 分钟级限流（Redis 故障时直接放行，避免缓存问题阻断接入）
     *
     * @return array{0:bool,1:int} [是否允许, 剩余次数]
     */
    public static function hitRateLimit(int $keyId): array
    {
        $bucket = 'agent_rl_' . $keyId . '_' . (int)floor(time() / 60);
        try {
            $n = (int)Cache::get($bucket);
            if ($n >= self::RATE_LIMIT_PER_MINUTE) {
                return [false, 0];
            }
            Cache::set($bucket, $n + 1, 70);
            return [true, max(0, self::RATE_LIMIT_PER_MINUTE - $n - 1)];
        } catch (Throwable $e) {
            return [true, self::RATE_LIMIT_PER_MINUTE];
        }
    }

    /** 当前请求对应的 API Base URL（含反向代理场景） */
    public static function baseUrl($request = null): string
    {
        $request = $request ?: request();
        $domain = '';
        try {
            $domain = (string)$request->domain();
        } catch (Throwable $e) {
            $domain = '';
        }
        if ($domain === '' || preg_match('#^https?://(localhost|127\.0\.0\.1)#i', $domain)) {
            $configured = trim((string)sys_config('sys_domain', ''));
            if ($configured !== '') {
                $domain = rtrim($configured, '/');
            }
        }
        return rtrim($domain, '/') . '/api/agent';
    }

    /**
     * Skill 包目录（仓库根 skills/cert-agent）
     */
    protected static function skillDir(): string
    {
        return dirname(rtrim(app()->getRootPath(), '/\\')) . DIRECTORY_SEPARATOR . 'skills' . DIRECTORY_SEPARATOR . 'cert-agent';
    }

    protected static function readSkillFile(string $name): string
    {
        $path = self::skillDir() . DIRECTORY_SEPARATOR . $name;
        if (!is_file($path)) {
            return '';
        }
        $content = @file_get_contents($path);
        return is_string($content) ? $content : '';
    }

    /** 工具清单 Markdown 表格（由 capabilities 生成，保证文档与接口一致） */
    public static function toolsMarkdown(): string
    {
        $lines = ['| 工具 | 权限 | 方法 | 路径 | 说明 |', '| --- | --- | --- | --- | --- |'];
        foreach (self::capabilities() as $c) {
            $lines[] = '| `' . $c['name'] . '` | ' . $c['scope'] . ' | ' . $c['method'] . ' | `' . $c['path'] . '` | ' . $c['summary'] . ' |';
        }
        return implode("\n", $lines);
    }

    /**
     * 渲染 SKILL.md（替换占位符并追加工具清单）
     */
    public static function skillMarkdown(string $baseUrl): string
    {
        $tpl = self::readSkillFile('SKILL.md');
        if ($tpl === '') {
            $tpl = "# 证照到期管理系统 Agent Skill\n\n通过本 Skill 以 API Key 访问证照到期管理系统。\n\n"
                 . "## 接入信息\n\n- Base URL: `{{BASE_URL}}`\n- 认证: `Authorization: Bearer <API_KEY>`\n\n"
                 . "## 工具\n\n{{TOOLS}}\n";
        }
        return str_replace(
            ['{{BASE_URL}}', '{{TOOLS}}', '{{VERSION}}'],
            [$baseUrl, self::toolsMarkdown(), self::VERSION],
            $tpl
        );
    }

    /** 详细接口参考（reference.md） */
    public static function referenceMarkdown(string $baseUrl): string
    {
        $tpl = self::readSkillFile('reference.md');
        return str_replace(
            ['{{BASE_URL}}', '{{TOOLS}}', '{{VERSION}}'],
            [$baseUrl, self::toolsMarkdown(), self::VERSION],
            $tpl
        );
    }

    /**
     * 生成 OpenAPI 3.0 描述
     */
    public static function openapi(string $baseUrl): array
    {
        $paths = [];
        foreach (self::capabilities() as $c) {
            $path = $c['path'];
            $op = [
                'tags'        => [$c['scope'] === self::SCOPE_WRITE ? 'write' : 'read'],
                'summary'     => $c['summary'],
                'operationId' => $c['name'],
                'responses'   => [
                    '200' => [
                        'description' => '业务响应（code=0 成功；code!=0 失败）',
                        'content'     => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ApiResponse']]],
                    ],
                    '401' => ['description' => 'API Key 缺失或无效'],
                    '403' => ['description' => '密钥停用 / 过期 / 权限不足'],
                    '429' => ['description' => '超出每分钟调用上限'],
                ],
            ];
            if ($c['method'] === 'GET') {
                $params = [];
                foreach ($c['params'] as $p) {
                    $params[] = [
                        'name'        => $p['name'],
                        'in'          => 'query',
                        'required'    => (bool)$p['required'],
                        'description' => $p['description'],
                        'schema'      => ['type' => $p['type']],
                    ];
                }
                if ($params) {
                    $op['parameters'] = $params;
                }
            } else {
                $props = [];
                $required = [];
                foreach ($c['params'] as $p) {
                    $props[$p['name']] = ['type' => $p['type'], 'description' => $p['description']];
                    if (!empty($p['required'])) {
                        $required[] = $p['name'];
                    }
                }
                $op['requestBody'] = [
                    'required' => true,
                    'content'  => ['application/json' => ['schema' => [
                        'type'       => 'object',
                        'properties' => $props,
                        'required'   => $required,
                    ]]],
                ];
            }
            $paths[$path][strtolower($c['method'])] = $op;
        }

        return [
            'openapi' => '3.0.3',
            'info'    => [
                'title'       => '证照到期管理系统 Agent API',
                'version'     => '1.0.0',
                'description' => '通过独立 API Key 访问证照、人员、企业、合同、到期提醒，并执行受控写入。',
            ],
            'servers' => [['url' => $baseUrl]],
            'security' => [['bearerAuth' => []]],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'cak_xxx'],
                ],
                'schemas' => [
                    'ApiResponse' => [
                        'type'       => 'object',
                        'properties' => [
                            'code' => ['type' => 'integer', 'description' => '0=成功，非 0=失败'],
                            'msg'  => ['type' => 'string'],
                            'data' => ['description' => '业务数据，结构随接口不同'],
                        ],
                    ],
                ],
            ],
            'tags' => [
                ['name' => 'read',  'description' => '只读查询'],
                ['name' => 'write', 'description' => '受控写入（需要 write 权限）'],
            ],
            'paths' => $paths,
        ];
    }

    /** 可绑定为密钥归属人的账号（内部账号，排除客户企业账号） */
    public static function ownerOptions(): array
    {
        $rows = Db::name('sys_user')->alias('u')
            ->join('sys_role r', 'r.id = u.role_id', 'LEFT')
            ->where('u.status', 1)
            ->where('r.role_code', '<>', 'company')
            ->field('u.id, u.username, u.real_name, u.dept_id, r.role_code, r.role_name')
            ->order('u.id', 'asc')
            ->select()->toArray();
        foreach ($rows as &$r) {
            $r['label'] = ($r['real_name'] ?: $r['username']) . '（' . ($r['role_name'] ?: $r['role_code']) . '）';
        }
        unset($r);
        return $rows;
    }
}
