<?php
namespace app\controller\admin;

use app\service\ExamMaterialService;
use app\service\ExamRuleService;
use app\service\ExamSchemaService;
use app\service\ExamSignupService;
use app\service\ExamStatusMachineService;
use think\facade\Db;

/**
 * 报名-业务动作 / 业务组合（配置中心，v2.85）
 *
 * 业务组合 = 证书类型 × 业务动作，配置规则 JSON / 材料清单 JSON / 状态流覆盖 / 费用。
 * 「新增一种证书业务只加数据不改码」即在此页完成。
 *
 *  - GET  /admin/exam-config/actions          动作列表
 *  - POST /admin/exam-config/action-save      动作新增/编辑（含默认状态流）
 *  - POST /admin/exam-config/action-delete    动作删除
 *  - GET  /admin/exam-config/configs          组合列表
 *  - GET  /admin/exam-config/config-detail    组合详情（规则/材料/流）
 *  - POST /admin/exam-config/config-save      组合新增/编辑
 *  - POST /admin/exam-config/config-delete    组合删除
 *  - POST /admin/exam-config/config-open      开放/停办
 *  - POST /admin/exam-config/trial            规则试算
 */
class ExamConfig extends AdminController
{
    protected function boot(): void
    {
        ExamSchemaService::ensureTables();
    }

    // ---------------- 业务动作 ----------------

    public function actions()
    {
        $this->boot();
        $list = Db::name('exam_biz_action')->where('status', 1)
            ->order('sort asc,id asc')->select()->toArray();
        foreach ($list as &$r) {
            $r['status_flow'] = ExamStatusMachineService::normalize($r['status_flow'] ?? null);
        }
        unset($r);
        return json(r_success(['list' => $list]));
    }

    public function actionSave()
    {
        $this->boot();
        if (!$this->can('examconfig', 'edit')) {
            return json(r_error('无保存权限', 403));
        }
        $p = $this->request->param();
        $id = (int)($p['id'] ?? 0);
        $code = trim((string)($p['code'] ?? ''));
        $name = trim((string)($p['name'] ?? ''));
        if ($code === '' || $name === '') {
            return json(r_error('请填写动作编码与名称'));
        }
        if (!preg_match('/^[a-zA-Z0-9_]{1,50}$/', $code)) {
            return json(r_error('动作编码仅允许字母/数字/下划线'));
        }
        $dup = Db::name('exam_biz_action')->where('code', $code)->where('id', '<>', $id)->find();
        if ($dup) {
            return json(r_error('动作编码已存在'));
        }
        $flow = ExamStatusMachineService::normalize($p['status_flow'] ?? []);
        if (count($flow) < 2) {
            return json(r_error('默认状态流至少需要 2 个节点'));
        }
        $data = [
            'code'        => $code,
            'name'        => mb_substr($name, 0, 100),
            'category'    => mb_substr((string)($p['category'] ?? '报名'), 0, 30),
            'status_flow' => json_encode($flow, JSON_UNESCAPED_UNICODE),
            'sort'        => (int)($p['sort'] ?? 0),
            'remark'      => mb_substr((string)($p['remark'] ?? ''), 0, 255),
            'status'      => 1,
        ];
        if ($id > 0) {
            Db::name('exam_biz_action')->where('id', $id)->update($data + ['update_time' => date('Y-m-d H:i:s')]);
        } else {
            $data['create_by'] = (int)$this->request->userId;
            $data['create_time'] = date('Y-m-d H:i:s');
            $id = (int)Db::name('exam_biz_action')->insertGetId($data);
        }
        $this->log('报名配置', '保存业务动作', $data['name']);
        return json(r_success(['id' => $id], '保存成功'));
    }

    public function actionDelete()
    {
        $this->boot();
        if (!$this->can('examconfig', 'delete')) {
            return json(r_error('无删除权限', 403));
        }
        $id = (int)$this->request->param('id', 0);
        if (Db::name('exam_biz_config')->where('action_id', $id)->where('status', 1)->count() > 0) {
            return json(r_error('该动作已被业务组合使用，不能删除'));
        }
        Db::name('exam_biz_action')->where('id', $id)->update(['status' => 0, 'update_time' => date('Y-m-d H:i:s')]);
        $this->log('报名配置', '删除业务动作', (string)$id);
        return json(r_success([], '删除成功'));
    }

    // ---------------- 业务组合 ----------------

    public function configs()
    {
        $this->boot();
        [$page, $limit] = $this->pageParams();
        $q = Db::name('exam_biz_config')->alias('bc')->where('bc.status', 1);

        $keyword = trim((string)$this->request->param('keyword', ''));
        if ($keyword !== '') {
            $q->where('bc.name', 'like', "%{$keyword}%");
        }
        $certTypeId = (int)$this->request->param('cert_type_id', 0);
        if ($certTypeId > 0) {
            $q->where('bc.cert_type_id', $certTypeId);
        }
        $actionId = (int)$this->request->param('action_id', 0);
        if ($actionId > 0) {
            $q->where('bc.action_id', $actionId);
        }
        if ($this->request->has('is_open')) {
            $q->where('bc.is_open', (int)$this->request->param('is_open'));
        }

        $total = (clone $q)->count();
        $list = (clone $q)
            ->leftJoin('exam_cert_type ct', 'ct.id = bc.cert_type_id')
            ->leftJoin('exam_biz_action ba', 'ba.id = bc.action_id')
            ->field('bc.*,ct.name cert_name,ba.name action_name,ba.code action_code,ct.category cert_category')
            ->order('bc.sort asc,bc.id desc')
            ->page($page, $limit)->select()->toArray();
        foreach ($list as &$r) {
            $r['rules'] = ExamRuleService::normalize($r['rules'] ?? []);
            $r['materials'] = ExamMaterialService::normalize($r['materials'] ?? []);
            $r['rule_count'] = count($r['rules']);
            $r['material_count'] = count($r['materials']);
            unset($r['status_flow']);
        }
        unset($r);
        return json(r_success(['list' => $list, 'total' => $total]));
    }

    public function configDetail()
    {
        $this->boot();
        $id = (int)$this->request->param('id', 0);
        $row = Db::name('exam_biz_config')->find($id);
        if (!$row || (int)$row['status'] !== 1) {
            return json(r_error('业务组合不存在'));
        }
        $row['rules'] = ExamRuleService::normalize($row['rules'] ?? []);
        $row['materials'] = ExamMaterialService::normalize($row['materials'] ?? []);
        $row['status_flow'] = ExamStatusMachineService::normalize($row['status_flow'] ?? null);
        $row['action_flow'] = ExamStatusMachineService::normalize(
            Db::name('exam_biz_action')->where('id', $row['action_id'])->value('status_flow')
        );
        return json(r_success($row));
    }

    public function configSave()
    {
        $this->boot();
        if (!$this->can('examconfig', 'edit')) {
            return json(r_error('无保存权限', 403));
        }
        $p = $this->request->param();
        $id = (int)($p['id'] ?? 0);
        $certTypeId = (int)($p['cert_type_id'] ?? 0);
        $actionId = (int)($p['action_id'] ?? 0);
        if ($certTypeId <= 0 || $actionId <= 0) {
            return json(r_error('请选择证书类型与业务动作'));
        }
        $cert = Db::name('exam_cert_type')->where('id', $certTypeId)->where('status', 1)->find();
        $action = Db::name('exam_biz_action')->where('id', $actionId)->where('status', 1)->find();
        if (!$cert || !$action) {
            return json(r_error('证书类型或业务动作不存在/已停用'));
        }
        $dup = Db::name('exam_biz_config')
            ->where('cert_type_id', $certTypeId)->where('action_id', $actionId)
            ->where('id', '<>', $id)->find();
        if ($dup) {
            return json(r_error('该证书类型与业务动作的组合已存在'));
        }

        $rules = ExamRuleService::normalize($p['rules'] ?? []);
        $materials = ExamMaterialService::normalize($p['materials'] ?? []);
        $flowRaw = $p['status_flow'] ?? [];
        $flow = is_array($flowRaw) && count($flowRaw) ? ExamStatusMachineService::normalize($flowRaw) : [];

        $name = trim((string)($p['name'] ?? ''));
        if ($name === '') {
            $name = $cert['name'] . '-' . $action['name'];
        }

        $data = [
            'cert_type_id' => $certTypeId,
            'action_id'    => $actionId,
            'name'         => mb_substr($name, 0, 150),
            'rules'        => json_encode($rules, JSON_UNESCAPED_UNICODE),
            'materials'    => json_encode($materials, JSON_UNESCAPED_UNICODE),
            'fee'          => round((float)($p['fee'] ?? 0), 2),
            // 空状态流 = 继承动作默认，落 NULL
            'status_flow'  => $flow ? json_encode($flow, JSON_UNESCAPED_UNICODE) : null,
            'is_open'      => (int)($p['is_open'] ?? 1) ? 1 : 0,
            'sort'         => (int)($p['sort'] ?? 0),
            'remark'       => mb_substr((string)($p['remark'] ?? ''), 0, 255),
            'status'       => 1,
        ];
        if ($id > 0) {
            Db::name('exam_biz_config')->where('id', $id)->update($data + ['update_time' => date('Y-m-d H:i:s')]);
        } else {
            $data['create_by'] = (int)$this->request->userId;
            $data['create_time'] = date('Y-m-d H:i:s');
            $id = (int)Db::name('exam_biz_config')->insertGetId($data);
        }
        $this->log('报名配置', '保存业务组合', $data['name']);
        return json(r_success(['id' => $id], '保存成功'));
    }

    public function configDelete()
    {
        $this->boot();
        if (!$this->can('examconfig', 'delete')) {
            return json(r_error('无删除权限', 403));
        }
        $id = (int)$this->request->param('id', 0);
        if (Db::name('exam_signup')->where('biz_config_id', $id)->where('status', 1)->count() > 0) {
            return json(r_error('该业务组合已有报名单，不能删除（可停办）'));
        }
        Db::name('exam_biz_config')->where('id', $id)->update(['status' => 0, 'update_time' => date('Y-m-d H:i:s')]);
        $this->log('报名配置', '删除业务组合', (string)$id);
        return json(r_success([], '删除成功'));
    }

    public function configOpen()
    {
        $this->boot();
        if (!$this->can('examconfig', 'status')) {
            return json(r_error('无操作权限', 403));
        }
        $id = (int)$this->request->param('id', 0);
        $open = (int)$this->request->param('is_open', 1) ? 1 : 0;
        Db::name('exam_biz_config')->where('id', $id)->update(['is_open' => $open, 'update_time' => date('Y-m-d H:i:s')]);
        $this->log('报名配置', $open ? '开放业务组合' : '停办业务组合', (string)$id);
        return json(r_success([], '操作成功'));
    }

    /** 规则试算：传入考生字段/材料编码，返回逐条规则结果 */
    public function trial()
    {
        $this->boot();
        $p = $this->request->param();
        $configId = (int)($p['config_id'] ?? 0);
        if ($configId > 0) {
            $rc = ExamSignupService::resolveConfig($configId);
            $rules = $rc['rules'];
            $customer = (array)($p['customer'] ?? []);
            if (!empty($p['customer_id'])) {
                $customer = Db::name('exam_customer')->find((int)$p['customer_id']) ?: $customer;
            }
            $extra = (array)($p['extra'] ?? []);
            $extra['cert_type_id'] = (int)($rc['config']['cert_type_id'] ?? 0);
            $codes = array_values((array)($p['material_codes'] ?? []));
            $ctx = ExamSignupService::buildContext($customer, $extra, $codes);
        } else {
            // 未保存组合：直接用前端传来的规则与上下文试算
            $rules = ExamRuleService::normalize($p['rules'] ?? []);
            $ctx = (array)($p['context'] ?? []);
        }
        $res = ExamRuleService::evaluate($rules, $ctx);
        return json(r_success($res));
    }
}
