<?php
namespace app\controller\api;

use app\BaseController;
use app\service\AgentApiService;
use app\service\AgentDataService;

/**
 * Agent 开放接口（v2.82）
 *
 * 全部路由挂 agentauth 中间件，使用独立 API Key 鉴权，
 * 不走登录 token、不走后台菜单权限（数据范围由密钥绑定账号决定）。
 */
class Agent extends BaseController
{
    /** 当前请求的权限上下文 */
    protected function ctx(): array
    {
        return [
            'user'        => (array)($this->request->user ?? []),
            'company_ids' => (array)($this->request->companyIds ?? []),
            'role_code'   => (string)($this->request->roleCode ?? ''),
            'is_admin'    => (string)($this->request->roleCode ?? '') === 'admin',
        ];
    }

    /** 审计用操作人描述：账号名（Agent:密钥名） */
    protected function actor(): string
    {
        $user = (array)($this->request->user ?? []);
        $name = (string)($user['real_name'] ?? '') ?: (string)($user['username'] ?? '');
        $keyName = (string)($this->request->agentKey['name'] ?? 'Agent');
        return $name . '（Agent:' . $keyName . '）';
    }

    public function ping()
    {
        $key = (array)($this->request->agentKey ?? []);
        return json(r_success([
            'ok'       => true,
            'service'  => 'cert-expiry-manager',
            'api'      => AgentApiService::VERSION,
            'time'     => date('Y-m-d H:i:s'),
            'key_name' => $key['name'] ?? '',
            'scopes'   => $this->request->agentScopes ?? [],
            'owner'    => $this->request->user['real_name'] ?? '',
        ]));
    }

    public function capabilities()
    {
        return json(r_success([
            'api'      => AgentApiService::VERSION,
            'base_url' => AgentApiService::baseUrl($this->request),
            'tools'    => AgentApiService::capabilities(),
        ]));
    }

    /** Skill 包（SKILL.md 渲染结果） */
    public function skill()
    {
        $base = AgentApiService::baseUrl($this->request);
        return json(r_success([
            'filename'  => 'SKILL.md',
            'base_url'  => $base,
            'content'   => AgentApiService::skillMarkdown($base),
            'reference' => AgentApiService::referenceMarkdown($base),
        ]));
    }

    /** OpenAPI 3.0 描述（直接返回规范本身，便于客户端加载） */
    public function openapi()
    {
        return json(AgentApiService::openapi(AgentApiService::baseUrl($this->request)));
    }

    public function overview()
    {
        return json(r_success(AgentDataService::overview($this->ctx())));
    }

    public function certificates()
    {
        return json(r_success(AgentDataService::certificates($this->ctx(), $this->request->param())));
    }

    public function certificateDetail()
    {
        $id = (int)$this->request->param('id', 0);
        $row = AgentDataService::certificate($this->ctx(), $id);
        if (!$row) {
            return json(r_error('证书不存在或无权访问', 404), 404);
        }
        return json(r_success($row));
    }

    public function staff()
    {
        return json(r_success(AgentDataService::staffList($this->ctx(), $this->request->param())));
    }

    public function staffDetail()
    {
        $id = (int)$this->request->param('id', 0);
        $row = AgentDataService::staff($this->ctx(), $id);
        if (!$row) {
            return json(r_error('人员不存在或无权访问', 404), 404);
        }
        return json(r_success($row));
    }

    public function companies()
    {
        return json(r_success(AgentDataService::companies($this->ctx(), $this->request->param())));
    }

    public function companyDetail()
    {
        $id = (int)$this->request->param('id', 0);
        $row = AgentDataService::company($this->ctx(), $id);
        if (!$row) {
            return json(r_error('企业不存在或无权访问', 404), 404);
        }
        return json(r_success($row));
    }

    public function contracts()
    {
        return json(r_success(AgentDataService::contracts($this->ctx(), $this->request->param())));
    }

    public function contractDetail()
    {
        $id = (int)$this->request->param('id', 0);
        $row = AgentDataService::contract($this->ctx(), $id);
        if (!$row) {
            return json(r_error('合同不存在或无权访问', 404), 404);
        }
        return json(r_success($row));
    }

    public function expiring()
    {
        return json(r_success(AgentDataService::expiring($this->ctx(), $this->request->param())));
    }

    /** 受控写入：更新证书状态 */
    public function updateCertStatus()
    {
        $id     = (int)$this->request->post('id', 0);
        $status = (int)$this->request->post('cert_status', -1);
        $remark = (string)$this->request->post('remark', '');

        $r = AgentDataService::updateCertStatus($this->ctx(), $id, $status, $remark, $this->actor());
        if (empty($r['ok'])) {
            return json(r_error($r['msg'] ?? '更新失败', (int)($r['code'] ?? 400)), (int)($r['code'] ?? 400));
        }
        return json(r_success($r['data'] ?? [], $r['msg'] ?? '已更新'));
    }

    /** 受控写入：追加跟进记录 */
    public function addFollow()
    {
        $r = AgentDataService::addFollow(
            $this->ctx(),
            $this->request->post(),
            (int)($this->request->userId ?? 0),
            $this->actor()
        );
        if (empty($r['ok'])) {
            return json(r_error($r['msg'] ?? '写入失败', (int)($r['code'] ?? 400)), (int)($r['code'] ?? 400));
        }
        return json(r_success($r['data'] ?? [], $r['msg'] ?? '已记录'));
    }
}
