<?php
namespace app\controller\api;

use app\BaseController;
use app\service\AgentMcpService;

/**
 * Agent MCP 端点（v2.84）
 *
 * 无状态 Streamable HTTP（JSON-RPC 2.0），供企业微信智能机器人 / 任意
 * MCP 客户端把本系统作为只读数据工具接入。鉴权复用 AgentAuth：
 *   Authorization: Bearer cak_xxx（只读 API Key 即可）
 *
 * 本端点只暴露只读工具，虽为 POST 但在中间件中按只读放行。
 */
class AgentMcp extends BaseController
{
    public function endpoint()
    {
        $raw  = (string)$this->request->getContent();
        $body = json_decode($raw, true);

        if (!is_array($body)) {
            return $this->rpc([
                'jsonrpc' => '2.0', 'id' => null,
                'error'   => ['code' => -32700, 'message' => '请求体不是合法 JSON'],
            ], 400);
        }

        $ctx = [
            'user'        => (array)($this->request->user ?? []),
            'company_ids' => (array)($this->request->companyIds ?? []),
            'role_code'   => (string)($this->request->roleCode ?? ''),
            'is_admin'    => (string)($this->request->roleCode ?? '') === 'admin',
        ];

        $resp = AgentMcpService::handle($body, $ctx);

        // 通知类消息（无 id）：HTTP 202，无响应体
        if ($resp === null) {
            return response('', 202)->header(['Content-Type' => 'application/json']);
        }
        return $this->rpc($resp, 200);
    }

    /** JSON-RPC 响应（无状态模式固定返回 application/json） */
    protected function rpc(array $data, int $status)
    {
        return json($data, $status, [
            'Content-Type'       => 'application/json; charset=utf-8',
            'Cache-Control'      => 'no-store',
            'Mcp-Session-Id'     => bin2hex(random_bytes(16)),
        ]);
    }
}
