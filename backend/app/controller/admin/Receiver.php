<?php
namespace app\controller\admin;

use app\model\CompanyReceiver;

/**
 * 消息接收人管理
 *
 * 两类接收人：
 *  - 企业接收人：只接收所属企业相关证书的通知
 *  - 全局接收人（is_global=1）：接收**所有**证书的通知，常用于中介方自己的管理员
 * 注：企微（群机器人）渠道发到群里，不需要接收人。
 */
class Receiver extends AdminController
{
    public function index()
    {
        [$page, $limit] = $this->pageParams();
        $companyId = (int)$this->request->param('company_id', 0);
        $scope     = $this->request->param('scope', ''); // ''全部 / global仅全局 / company仅企业
        $hasGlobal = $this->ensureColumn(
            'company_receiver', 'is_global',
            "tinyint NOT NULL DEFAULT 0 COMMENT '1=全局接收人（接收所有证书通知）' AFTER `company_id`"
        );
        $q = CompanyReceiver::with(['company']);
        $q = $hasGlobal ? $q->orderRaw('is_global DESC, id DESC') : $q->order('id', 'desc');
        $q = $this->applyCompanyScope($q);
        if ($companyId) {
            $q->where('company_id', $companyId);
        }
        if ($hasGlobal && $scope === 'global') {
            $q->where('is_global', 1);
        } elseif ($hasGlobal && $scope === 'company') {
            $q->where('is_global', 0);
        }
        // v2.99.40：按场景筛选（scene=cert_expire 等），留空=全部场景
        \app\service\PushSceneService::ensureSchema();
        $scene = trim((string)$this->request->param('scene', ''));
        if ($scene !== '') {
            $q->whereRaw("(scenes IS NULL OR scenes = '' OR FIND_IN_SET(:scene, scenes))", ['scene' => $scene]);
        }
        $total = $q->count();
        $list  = $q->page($page, $limit)->select()->toArray();
        foreach ($list as &$r) {
            $r['is_global']     = (int)($r['is_global'] ?? 0);
            $r['scope_text']    = $r['is_global'] ? '全局' : '指定企业';
            $r['company_name']  = $r['company']['company_name'] ?? '';
            $r['scenes']        = \app\service\PushSceneService::normalizeScenes($r['scenes'] ?? '');
            $r['scenes_text']   = \app\service\PushSceneService::scenesText($r['scenes']);
        }
        unset($r);
        return json(r_success(['list' => $list, 'total' => $total]));
    }

    public function save()
    {
        $id = (int)$this->request->post('id', 0);
        $data = $this->request->post([
            'company_id', 'receiver_name', 'receiver_phone', 'receiver_email',
            'receiver_wecom', 'receiver_type', 'is_enable', 'is_global', 'scenes',
        ]);
        $this->ensureColumn(
            'company_receiver', 'is_global',
            "tinyint NOT NULL DEFAULT 0 COMMENT '1=全局接收人（接收所有证书通知）' AFTER `company_id`"
        );
        // v2.99.40：适用功能（场景）。留空=全部功能，保持老数据行为不变。
        \app\service\PushSceneService::ensureSchema();
        $data['scenes'] = implode(',', \app\service\PushSceneService::normalizeScenes($data['scenes'] ?? []));
        $isGlobal = (int)($data['is_global'] ?? 0) === 1;
        if ($isGlobal && !$this->isAdmin()) {
            return json(r_error('仅管理员可配置全局接收人', 403));
        }
        $data['is_global'] = $isGlobal ? 1 : 0;
        if ($isGlobal) {
            // 全局接收人不挂靠任何企业
            $data['company_id'] = 0;
        } elseif ((int)($data['company_id'] ?? 0) > 0
            && !$this->canAccessCompany((int)$data['company_id'])) {
            return json(r_error('无权配置该企业的接收人', 403));
        }
        if (empty($data['receiver_name']) || empty($data['receiver_type'])) {
            return json(r_error('接收人姓名与类型必填', 400));
        }
        // 企微/openid：支持从组织架构多选（前端已解析为 userid），
        // 这里统一分隔符为竖线（企微 message/send 要求的 touser 格式）并去重
        $wecom = (string)($data['receiver_wecom'] ?? '');
        if ($wecom !== '') {
            $parts = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,;|]+/', $wecom)))));
            $data['receiver_wecom'] = implode('|', $parts);
        } else {
            $data['receiver_wecom'] = '';
        }
        if (!$isGlobal && empty($data['company_id'])) {
            return json(r_error('请选择所属企业（或勾选「全局接收人」）', 400));
        }
        if (!$isGlobal
            && empty($data['receiver_phone']) && empty($data['receiver_email'])) {
            // 企微渠道不需要接收人联系方式，这里只做提示性校验：短信/邮件渠道会无人可发
            // 不阻断保存，由推送体检给出明确原因
        }
        if ($id) {
            CompanyReceiver::where('id', $id)->update($data);
            $this->log('消息接收人', '更新', "#{$id}" . ($isGlobal ? '（全局）' : ''));
        } else {
            CompanyReceiver::create($data);
            $this->log('消息接收人', '新增', $isGlobal ? '全局接收人' : '');
        }
        return json(r_success([], '保存成功'));
    }

    public function delete()
    {
        $id = (int)$this->request->post('id', 0);
        if (!$id) {
            return json(r_error('缺少ID', 400));
        }
        $row = CompanyReceiver::find($id);
        if (!$row) {
            return json(r_error('接收人不存在', 404));
        }
        if ((int)($row->is_global ?? 0) === 1) {
            if (!$this->isAdmin()) {
                return json(r_error('仅管理员可删除全局接收人', 403));
            }
        } elseif (!$this->canAccessCompany((int)$row->company_id)) {
            return json(r_error('无权删除该企业的接收人', 403));
        }
        $r = $this->deleteOne($id);
        return $r['ok'] ? json(r_success([], '删除成功')) : json(r_error($r['msg'], 400));
    }

    protected function deleteOne(int $id): array
    {
        // v2.52：删除前快照进回收站
        try {
            $rb = CompanyReceiver::find($id);
            if ($rb) {
                \app\service\RecycleBinService::stash(
                    (int)$this->request->userId,
                    'receiver',
                    ((string)($rb['receiver_name'] ?? '') ?: ("#" . $id)),
                    $rb->toArray()
                );
            }
        } catch (\Throwable $e) {
            // 快照失败不阻断删除
        }
        CompanyReceiver::destroy($id);
        $this->log('消息接收人', '删除', "#{$id}");
        return ['ok' => true, 'msg' => '删除成功'];
    }
}
