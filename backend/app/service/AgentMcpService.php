<?php
namespace app\service;

/**
 * Agent MCP 接入（v2.84）
 *
 * 设计原则：系统只做「数据工具」，不做意图理解、不做自然语言排版。
 * 语义分析、工具选择、参数抽取、多轮追问、最终回答，全部由企业微信
 * 智能机器人侧的大模型完成。本端点是无状态的 MCP（Streamable HTTP /
 * JSON-RPC 2.0）适配层，工具实现直接复用 AgentDataService 的只读查询，
 * 不新增任何业务逻辑，尽量降低系统负担。
 *
 * 鉴权复用 AgentAuth（同一个只读 API Key，Bearer），数据范围跟随
 * 密钥绑定账号，脱敏口径与 PC / REST 完全一致。
 *
 * 协议：https://modelcontextprotocol.io （2024-11-05 / 2025 系列版本
 * 的 initialize / tools/list / tools/call 消息结构一致）。
 */
class AgentMcpService
{
    public const SERVER_NAME    = 'certagent';
    public const SERVER_VERSION = '1.1';
    public const PROTOCOL       = '2024-11-05';
    /** v2.84.1：MCP 列表工具不传 limit 时一次取满 100，避免大模型只读第一页导致“数据被截断” */
    public const DEFAULT_LIMIT  = 100;

    /**
     * 处理一条 JSON-RPC 请求（已解码为数组），返回可 JSON 编码的响应数组；
     * 通知类消息（无 id）返回 null，由控制器回 202 空体。
     */
    public static function handle(array $req, array $ctx): ?array
    {
        // JSON-RPC 批量请求
        if (isset($req[0])) {
            $out = [];
            foreach ($req as $one) {
                $r = self::handleOne((array)$one, $ctx);
                if ($r !== null) {
                    $out[] = $r;
                }
            }
            // 全部是通知时无响应体
            return $out ? $out : null;
        }
        return self::handleOne($req, $ctx);
    }

    protected static function handleOne(array $req, array $ctx): ?array
    {
        $id = array_key_exists('id', $req) ? $req['id'] : null;
        $method = (string)($req['method'] ?? '');

        // 通知（无 id）：仅初始化通知需要识别并静默确认，其余一律 202
        if ($id === null && !array_key_exists('id', $req)) {
            return null;
        }

        switch ($method) {
            case 'initialize':
                return self::result($id, [
                    'protocolVersion' => self::pickProtocol((string)($req['params']['protocolVersion'] ?? '')),
                    'capabilities'    => ['tools' => ['listChanged' => false]],
                    'serverInfo'      => ['name' => self::SERVER_NAME, 'version' => self::SERVER_VERSION],
                ]);

            case 'ping':
                return self::result($id, []);

            case 'tools/list':
                return self::result($id, ['tools' => self::tools()]);

            case 'tools/call':
                $name = (string)($req['params']['name'] ?? '');
                $args = (array)($req['params']['arguments'] ?? []);
                try {
                    [$data, $isError] = self::callTool($name, $args, $ctx);
                } catch (\Throwable $e) {
                    $data = '工具执行失败：' . $e->getMessage();
                    $isError = true;
                }
                return self::result($id, [
                    'content' => [['type' => 'text', 'text' => is_string($data) ? $data : self::json($data)]],
                    'isError' => $isError,
                ]);

            default:
                return self::error($id, -32601, '方法不支持：' . $method);
        }
    }

    /** 兼容客户端声明的协议版本；不在已知集合内时回退默认版本 */
    protected static function pickProtocol(string $v): string
    {
        return in_array($v, ['2024-11-05', '2025-03-26', '2025-06-18'], true)
            ? $v
            : self::PROTOCOL;
    }

    protected static function result($id, array $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    protected static function error($id, int $code, string $msg): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $msg]];
    }

    protected static function json($data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    // ==================================================================
    // 工具清单（inputSchema 供大模型理解与抽取参数）
    // ==================================================================

    protected static function tools(): array
    {
        return [
            [
                'name'        => 'get_overview',
                'description' => '获取证照管理系统的总体统计：企业客户数、在职人员数、证照总数、执行中合同数、30天内到期证照数、未读提醒数。回答“整体情况/概览/有多少”类问题时优先调用。',
                'inputSchema' => ['type' => 'object', 'properties' => (object)[]],
            ],
            [
                'name'        => 'list_expiring_certificates',
                'description' => '查询未来 N 天内即将到期的证照，按到期日升序。回答“快到期/即将到期/续期提醒/90天内哪些证到期”类问题。days 不传默认 30，范围 1-365。分页：返回体含 total/page/limit，每页最多 100 条；当 total 大于本次返回条数时，必须递增 page 继续调用直到取全，严禁只依据第一页就声称“共有 N 条”或遗漏数据。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'days'  => ['type' => 'integer', 'description' => '未来天数，默认 30，范围 1-365'],
                        'page'  => ['type' => 'integer', 'description' => '页码，默认 1'],
                        'limit' => ['type' => 'integer', 'description' => '每页条数，默认 100，最大 100'],
                    ],
                ],
            ],
            [
                'name'        => 'search_certificates',
                'description' => '按条件检索证照台账（证书名称/编号/类型/专业关键字、持证人、企业、状态、到期天数等）。回答“某人有什么证、某企业在借哪些证、二建/一建/安全员”等问题。关键字可同时包含证书与人员信息，建议先用 search_staff 查到 staff_id 精确过滤。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'keyword'    => ['type' => 'string', 'description' => '证书名称/编号/类型/专业关键字'],
                        'staff_id'   => ['type' => 'integer', 'description' => '持证人ID（来自 search_staff）'],
                        'company_id' => ['type' => 'integer', 'description' => '企业ID（来自 search_companies），按在借关系过滤'],
                        'cert_type'  => ['type' => 'string', 'description' => '证书类型/专业，精确匹配'],
                        'cert_status'=> ['type' => 'integer', 'description' => '证书状态：0已过期 1正常 2挂失 3注销'],
                        'expire_days'=> ['type' => 'integer', 'description' => '仅返回该天数内到期的正常证照'],
                        'only_free'  => ['type' => 'boolean', 'description' => 'true=只看当前未被借调（空闲）的证照'],
                        'page'       => ['type' => 'integer', 'description' => '页码，默认 1'],
                        'limit'      => ['type' => 'integer', 'description' => '每页条数，默认 100，最大 100；当返回的 total 大于本次条数时，递增 page 取全，不要只取第一页'],
                    ],
                ],
            ],
            [
                'name'        => 'get_certificate',
                'description' => '按ID查询单张证照的完整详情（持证人、编号、专业、发证/到期日、状态、当前借调企业与费用等）。通常在 search_certificates / list_expiring_certificates 拿到 id 后调用。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'integer', 'description' => '证照ID']],
                    'required' => ['id'],
                ],
            ],
            [
                'name'        => 'search_staff',
                'description' => '按姓名/手机号关键字或所属企业检索人员，返回人员ID、部门、岗位、电话等。需要查“某人的证”时先调这里拿 staff_id。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'keyword'    => ['type' => 'string', 'description' => '姓名/手机号关键字'],
                        'company_id' => ['type' => 'integer', 'description' => '企业ID，按任职/借调关系过滤'],
                        'page'       => ['type' => 'integer', 'description' => '页码，默认 1'],
                        'limit'      => ['type' => 'integer', 'description' => '每页条数，默认 100，最大 100；当返回的 total 大于本次条数时，递增 page 取全，不要只取第一页'],
                    ],
                ],
            ],
            [
                'name'        => 'get_staff',
                'description' => '按ID查询人员完整档案：基本信息、部门岗位、联系方式、关联企业，以及持有的证照列表。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'integer', 'description' => '人员ID']],
                    'required' => ['id'],
                ],
            ],
            [
                'name'        => 'search_companies',
                'description' => '按企业名称/简称/统一社会信用代码检索客户企业，返回企业ID、在借证照数量与借调费用。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'keyword' => ['type' => 'string', 'description' => '企业名称/简称/信用代码关键字'],
                        'page'    => ['type' => 'integer', 'description' => '页码，默认 1'],
                        'limit'   => ['type' => 'integer', 'description' => '每页条数，默认 100，最大 100；当返回的 total 大于本次条数时，递增 page 取全，不要只取第一页'],
                    ],
                ],
            ],
            [
                'name'        => 'get_company',
                'description' => '按ID查询企业详情：联系人、在借证照数、关联人员，以及服务合同列表。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'integer', 'description' => '企业ID']],
                    'required' => ['id'],
                ],
            ],
            [
                'name'        => 'search_contracts',
                'description' => '检索服务合同（编号/标题/甲乙方关键字、企业、状态、即将到期天数）。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'keyword'    => ['type' => 'string', 'description' => '合同编号/标题/甲乙方关键字'],
                        'company_id' => ['type' => 'integer', 'description' => '企业ID'],
                        'status'     => ['type' => 'integer', 'description' => '合同状态码'],
                        'expiring'   => ['type' => 'integer', 'description' => '仅返回该天数内到期的执行中合同'],
                        'page'       => ['type' => 'integer', 'description' => '页码，默认 1'],
                        'limit'      => ['type' => 'integer', 'description' => '每页条数，默认 100，最大 100；当返回的 total 大于本次条数时，递增 page 取全，不要只取第一页'],
                    ],
                ],
            ],
            [
                'name'        => 'get_contract',
                'description' => '按ID查询合同完整详情，含签约企业、金额、起止日期、关联的借调（证照/人员）信息。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'integer', 'description' => '合同ID']],
                    'required' => ['id'],
                ],
            ],
        ];
    }

    // ==================================================================
    // 工具执行：纯转发到 AgentDataService，不做二次加工
    // @return array{0:mixed,1:bool} [数据, 是否错误]
    // ==================================================================

    protected static function callTool(string $name, array $a, array $ctx): array
    {
        switch ($name) {
            case 'get_overview':
                return [AgentDataService::overview($ctx), false];

            case 'list_expiring_certificates':
                $a['days'] = isset($a['days']) ? (int)$a['days'] : 30;
                return [AgentDataService::expiring($ctx, self::norm($a)), false];

            case 'search_certificates':
                return [AgentDataService::certificates($ctx, self::norm($a)), false];

            case 'get_certificate':
                $row = AgentDataService::certificate($ctx, (int)($a['id'] ?? 0));
                return $row ? [$row, false] : ['未找到该证照，或当前 API Key 绑定账号无权查看', true];

            case 'search_staff':
                return [AgentDataService::staffList($ctx, self::norm($a)), false];

            case 'get_staff':
                $row = AgentDataService::staff($ctx, (int)($a['id'] ?? 0));
                return $row ? [$row, false] : ['未找到该人员，或当前 API Key 绑定账号无权查看', true];

            case 'search_companies':
                return [AgentDataService::companies($ctx, self::norm($a)), false];

            case 'get_company':
                $row = AgentDataService::company($ctx, (int)($a['id'] ?? 0));
                return $row ? [$row, false] : ['未找到该企业，或当前 API Key 绑定账号无权查看', true];

            case 'search_contracts':
                return [AgentDataService::contracts($ctx, self::norm($a)), false];

            case 'get_contract':
                $row = AgentDataService::contract($ctx, (int)($a['id'] ?? 0));
                return $row ? [$row, false] : ['未找到该合同，或当前 API Key 绑定账号无权查看', true];

            default:
                return ['未知工具：' . $name, true];
        }
    }

    /** 布尔/整型参数规范化（大模型偶尔会传字符串）；列表工具默认取满一页 100 条 */
    protected static function norm(array $a): array
    {
        foreach (['staff_id', 'company_id', 'cert_status', 'expire_days', 'expiring', 'status', 'page', 'limit', 'days'] as $k) {
            if (isset($a[$k]) && $a[$k] !== '') {
                $a[$k] = (int)$a[$k];
            }
        }
        if (!isset($a['limit']) || $a['limit'] < 1) {
            $a['limit'] = self::DEFAULT_LIMIT;
        }
        if (isset($a['only_free'])) {
            $a['only_free'] = filter_var($a['only_free'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        return $a;
    }
}
