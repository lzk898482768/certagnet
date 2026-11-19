<?php
namespace app\controller\admin;

use app\model\MsgTemplate as MsgTemplateModel;

class MsgTemplate extends AdminController
{
    public function index()
    {
        $type  = $this->request->param('template_type', '');
        $scene = $this->request->param('scene', '');
        // 老库可能缺 scene 列：自愈后才有此列可查/可排
        \app\service\CertExpireService::ensureMsgTemplates();
        $q = MsgTemplateModel::order('scene', 'asc')->order('id', 'asc');
        if ($type) {
            $q->where('template_type', $type);
        }
        if ($scene) {
            $q->where('scene', $scene);
        }
        $list = $q->select()->toArray();
        return json(r_success($list));
    }

    public function save()
    {
        $id = (int)$this->request->post('id', 0);
        $data = $this->request->post(['scene', 'template_type', 'template_name', 'content', 'status']);
        if (empty($data['template_type']) || empty($data['content'])) {
            return json(r_error('类型和内容必填', 400));
        }
        // 枚举白名单校验，防止非法模板类型/场景入库
        $allowTypes = ['sms', 'email', 'wecom', 'wecom_app', 'mp'];
        if (!in_array($data['template_type'], $allowTypes, true)) {
            return json(r_error('不支持的模板类型: ' . $data['template_type'], 400));
        }
        // v2.99.1：补齐 company_cert_expire（企业安许/资质到期），与 CertExpireService
        // 的默认模板场景保持一致，否则库里已有该场景模板却无法编辑/新增。
        $allowScenes = [
            'cert_expire',
            'company_cert_expire',
            'secondment_approach',
            'secondment_expire',
            'secondment_renew',
            'gov_progress',
        ];
        if (!empty($data['scene']) && !in_array($data['scene'], $allowScenes, true)) {
            return json(r_error('不支持的提醒场景: ' . $data['scene'], 400));
        }
        if (empty($data['scene'])) {
            $data['scene'] = 'cert_expire';
        }
        // 老库写入时自愈 scene 列，避免在含 scene 字段但未建列的库上 1054 报错
        \app\service\CertExpireService::ensureMsgTemplates();

        // v2.99.1：唯一键 (scene, template_type) 冲突转为业务错误，不再抛 500。
        // 同时兼容老库：若该组合已有模板而本次是「新增」，提示改为编辑。
        $dup = MsgTemplateModel::where('scene', $data['scene'])
            ->where('template_type', $data['template_type'])
            ->where('id', '<>', $id)
            ->find();
        if ($dup) {
            return json(r_error(
                '该场景下已存在「' . $data['template_type'] . '」模板（' . (string)($dup['template_name'] ?? '') . '），请直接编辑',
                400
            ));
        }

        try {
            if ($id) {
                MsgTemplateModel::where('id', $id)->update($data);
                $this->log('消息模板', '更新', "#{$id}");
            } else {
                MsgTemplateModel::create($data);
                $this->log('消息模板', '新增');
            }
        } catch (\Throwable $e) {
            // 并发写入等极端情况下查重仍可能被绕过，这里兜底翻译 1062
            $dupMsg = \app\common\UniqueGuard::friendly($e, '该场景下同类型模板已存在，请直接编辑');
            if ($dupMsg !== '') {
                return json(r_error($dupMsg, 400));
            }
            throw $e;
        }
        return json(r_success([], '保存成功'));
    }

    public function delete()
    {
        $id = (int)$this->request->post('id', 0);
        // v2.52：删除前快照进回收站
        try {
            $rb = MsgTemplateModel::find($id);
            if ($rb) {
                \app\service\RecycleBinService::stash(
                    (int)$this->request->userId,
                    'msg_template',
                    ((string)($rb['template_name'] ?? '') ?: ("#" . $id)),
                    $rb->toArray()
                );
            }
        } catch (\Throwable $e) {
            // 快照失败不阻断删除
        }
        MsgTemplateModel::destroy($id);
        $this->log('消息模板', '删除', "#{$id}");
        return json(r_success([], '删除成功'));
    }
}
