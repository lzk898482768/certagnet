<?php
namespace app\controller\admin;

use app\model\Contract as ContractModel;
use app\service\CustomFieldService;

class Contract extends AdminController
{
    public function index()
    {
        [$page, $limit] = $this->pageParams();
        $companyId = (int)$this->request->param('company_id', 0);
        $status    = $this->request->param('status', '');
        $expiring  = (int)$this->request->param('expiring', 0);
        $keyword   = trim((string)$this->request->param('keyword', ''));

        $q = ContractModel::with([
            'company',
            'secondment' => function ($q) {
                $q->with(['certificate', 'staff']);
            },
        ])->order('id', 'desc');
        $q = $this->applyModuleScope($q, 'contract');
        if ($companyId) {
            $q->where('company_id', $companyId);
        }
        if ($status !== '' && $status !== null) {
            $q->where('status', (int)$status);
        }
        // 工作台「合同即将到期」直达：仅显示执行中且 N 天内到期的合同
        if ($expiring > 0) {
            $q->where('status', 1)->whereBetween('end_date', [
                date('Y-m-d'),
                date('Y-m-d', strtotime('+' . $expiring . ' days')),
            ]);
        }
        if ($keyword) {
            $q->where(function ($q) use ($keyword) {
                $q->whereLike('contract_no', "%{$keyword}%")
                  ->whereLike('title', "%{$keyword}%", 'OR')
                  ->whereLike('party_a', "%{$keyword}%", 'OR')
                  ->whereLike('party_b', "%{$keyword}%", 'OR');
            });
        }
        $total = $q->count();
        $list  = $q->page($page, $limit)->select()->toArray();
        $user  = $this->request->user ?? [];
        foreach ($list as &$item) {
            if (!empty($item['secondment']) && is_array($item['secondment'])) {
                if (!empty($item['secondment']['certificate']) && is_array($item['secondment']['certificate'])) {
                    $item['secondment']['certificate'] = \app\service\MaskService::cert($item['secondment']['certificate'], $user);
                }
                if (!empty($item['secondment']['staff']) && is_array($item['secondment']['staff'])) {
                    $item['secondment']['staff'] = \app\service\MaskService::staff($item['secondment']['staff'], false, $user);
                }
            }
        }
        unset($item);
        CustomFieldService::attachList('contract', $list);
        return json(r_success(['list' => $list, 'total' => $total]));
    }

    public function detail()
    {
        $id = (int)$this->request->param('id', 0);
        $row = ContractModel::with([
            'company',
            'secondment' => function ($q) {
                $q->with(['certificate', 'staff']);
            },
        ])->where('id', $id)->find();
        if (!$row) {
            return json(r_error('合同不存在', 404));
        }
        // v2.99.36：客户账号按企业边界，内部账号按创建人
        $this->assertModuleAccess('contract', $id);
        $data = $row->toArray();
        $user = $this->request->user ?? [];
        if (!empty($data['secondment']) && is_array($data['secondment'])) {
            if (!empty($data['secondment']['certificate']) && is_array($data['secondment']['certificate'])) {
                $data['secondment']['certificate'] = \app\service\MaskService::cert($data['secondment']['certificate'], $user);
            }
            if (!empty($data['secondment']['staff']) && is_array($data['secondment']['staff'])) {
                $data['secondment']['staff'] = \app\service\MaskService::staff($data['secondment']['staff'], false, $user);
            }
        }
        CustomFieldService::attachOne('contract', $data);
        return json(r_success($data));
    }

    public function save()
    {
        $id = (int)$this->request->post('id', 0);
        $data = $this->request->post([
            'company_id', 'contract_no', 'title', 'contract_type', 'party_a', 'party_b',
            'amount', 'sign_date', 'start_date', 'end_date', 'status', 'remark', 'attach_path', 'attach_name',
        ]);
        // v2.50：合同单附件显示名（老库缺列时自愈，补不上则忽略改名不影响主保存）
        $attachName = trim((string)($data['attach_name'] ?? ''));
        if ($attachName !== '' && $attachName !== '0') {
            if ($this->ensureColumn(
                'contract', 'attach_name',
                "varchar(128) NOT NULL DEFAULT '' COMMENT '合同附件显示名' AFTER `attach_path`"
            )) {
                $data['attach_name'] = mb_substr($attachName, 0, 128);
            } else {
                unset($data['attach_name']);
            }
        } else {
            unset($data['attach_name']);
        }
        // 日期控件未选值会传 ''，MySQL 严格模式 date 列不接受空串 -> 转 null
        $data = $this->emptyToNull($data, ['sign_date', 'start_date', 'end_date']);
        if (empty($data['company_id'])) {
            return json(r_error('请选择客户企业', 400));
        }
        $this->assertCompanyAccess((int)$data['company_id']);
        if ($id) {
            $old = ContractModel::where('id', $id)->find();
            if (!$old) {
                return json(r_error('合同不存在', 404));
            }
            $this->assertCompanyAccess((int)$old->company_id);
            // v2.99.36：内部账号仅能编辑本人创建的合同
            $this->assertModuleAccess('contract', $id);
        }
        if (empty($data['sign_date'])) {
            return json(r_error('请选择签订日期', 400));
        }
        if (empty($data['start_date'])) {
            return json(r_error('请选择生效日期', 400));
        }
        if (!empty($data['start_date']) && !empty($data['end_date']) && $data['end_date'] < $data['start_date']) {
            return json(r_error('到期日期不能早于生效日期', 400));
        }
        $customErr = CustomFieldService::validate('contract', $this->request->post('custom'));
        if ($customErr !== '') {
            return json(r_error($customErr, 400));
        }
        if ($id) {
            ContractModel::where('id', $id)->update($data);
            $this->log('服务合同', '更新', "#{$id}");
        } else {
            // v2.99.36：记录创建人，作为「其他模块」的数据范围依据
            $data['create_by'] = (int)$this->request->userId;
            $model = ContractModel::create($data);
            $id    = (int)$model->id;
            $this->log('服务合同', '新增');
        }
        CustomFieldService::saveValues('contract', (int)$id, $this->request->post('custom'));
        return json(r_success(['id' => $id], '保存成功'));
    }

    public function delete()
    {
        $id = (int)$this->request->post('id', 0);
        if (!$id) {
            return json(r_error('缺少ID', 400));
        }
        $r = $this->deleteOne($id);
        return $r['ok'] ? json(r_success([], '删除成功')) : json(r_error($r['msg'], 400));
    }

    protected function deleteOne(int $id): array
    {
        $row = ContractModel::where('id', $id)->find();
        if (!$row) {
            return ['ok' => false, 'msg' => '合同不存在'];
        }
        if (!$this->canAccessModule('contract', $id)) {
            return ['ok' => false, 'msg' => '无权删除该合同'];
        }
        // v2.52：删除前快照进回收站（自定义值随删，一并快照）
        try {
            \app\service\RecycleBinService::stash(
                (int)$this->request->userId,
                'contract',
                ((string)($row['contract_no'] ?? '') ?: (string)($row['title'] ?? '')) ?: ("#" . $id),
                $row->toArray(),
                \app\service\RecycleBinService::grabCustom('contract', $id)
            );
        } catch (\Throwable $e) {
            // 快照失败不阻断删除
        }
        CustomFieldService::deleteValues('contract', $id);
        ContractModel::destroy($id);
        $this->log('服务合同', '删除', "#{$id}");
        return ['ok' => true, 'msg' => '删除成功'];
    }
}
