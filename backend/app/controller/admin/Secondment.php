<?php
namespace app\controller\admin;

use app\model\CertSecondment;
use app\model\Certificate as CertificateModel;
use app\model\ClientCompany;
use app\model\Contract;
use app\model\Fee as FeeModel;
use app\service\SecondmentService;
use app\service\CustomFieldService;
use think\facade\Db;

/**
 * 证书借调管理
 * 业务：人员不固定企业；企业借调人员证书，借调有期限，并产生合同与费用
 */
class Secondment extends AdminController
{
    public function index()
    {
        [$page, $limit] = $this->pageParams();
        $companyId = (int)$this->request->param('company_id', 0);
        $staffId   = (int)$this->request->param('staff_id', 0);
        $certId    = (int)$this->request->param('cert_id', 0);
        $status    = $this->request->param('status', '');
        $direction = $this->request->param('direction', '');
        $keyword   = $this->request->param('keyword', '');
        $expiring  = (int)$this->request->param('expiring', 0); // 只看即将到期（含已逾期）
        $expired   = (int)$this->request->param('expired', 0);  // 只看已到期（含借调中但已逾期）

        // 按借调到期时间从早到晚排序；长期有效（end_date IS NULL）排最后，同到期日新记录在前
        $q = CertSecondment::with(['certificate', 'staff', 'company', 'contract'])
            ->orderRaw('end_date IS NULL ASC, end_date ASC, id DESC');
        $q = $this->scopeSecondment($q);

        if ($companyId) {
            $q->where('company_id', $companyId);
        }
        if ($staffId) {
            $q->where('staff_id', $staffId);
        }
        if ($certId) {
            $q->where('cert_id', $certId);
        }
        if ($status !== '') {
            $q->where('status', $status);
        }
        if ($direction !== '') {
            $q->where('direction', (int)$direction);
        }
        if ($expiring) {
            SecondmentService::scopeExpiring($q, $expiring);
        }
        if ($expired) {
            SecondmentService::scopeExpired($q);
        }
        if ($keyword) {
            $certIds = CertificateModel::where('cert_name|cert_no', 'like', "%{$keyword}%")->column('id');
            $staffIds = Db::name('staff')->where('staff_name', 'like', "%{$keyword}%")->column('id');
            $q->where(function ($w) use ($certIds, $staffIds, $keyword) {
                // ★ 修复：whereOrIn 在当前 think-orm 下会抛 "where express error:STAFF_ID"，
                // 改用 whereOr('字段','in',数组) 三参形式（等价 OR IN，语法校验通过）。
                $w->whereIn('cert_id', $certIds ?: [0])
                  ->whereOr('staff_id', 'in', $staffIds ?: [0])
                  ->whereOr('remark', 'like', "%{$keyword}%");
            });
        }

        $total = $q->count();
        $list  = $q->page($page, $limit)->select()->toArray();
        $user  = $this->request->user ?? [];
        foreach ($list as &$item) {
            $item['remain_days'] = SecondmentService::remainDays($item['end_date'] ?? '');
            $item['status_text'] = SecondmentService::statusText((int)$item['status']);
            if (!empty($item['staff']) && is_array($item['staff'])) {
                $item['staff'] = \app\service\MaskService::staff($item['staff'], false, $user);
            }
            if (!empty($item['certificate']) && is_array($item['certificate'])) {
                $item['certificate'] = \app\service\MaskService::cert($item['certificate'], $user);
            }
        }
        unset($item);
        CustomFieldService::attachList('secondment', $list);
        return json(r_success(['list' => $list, 'total' => $total]));
    }

    /** 借调详情 */
    public function detail()
    {
        $id = (int)$this->request->param('id', 0);
        $row = CertSecondment::with(['certificate.staff', 'staff', 'company', 'contract'])->find($id);
        if (!$row) {
            return json(r_error('借调记录不存在', 400));
        }
        // v2.99.36：客户账号按企业边界，内部账号按创建人
        $this->assertModuleAccess('cert_secondment', $id);
        $data = $row->toArray();
        $data['remain_days'] = SecondmentService::remainDays($data['end_date'] ?? '');
        $data['status_text'] = SecondmentService::statusText((int)$data['status']);
        $user = $this->request->user ?? [];
        if (!empty($data['staff']) && is_array($data['staff'])) {
            $data['staff'] = \app\service\MaskService::staff($data['staff'], false, $user);
        }
        if (!empty($data['certificate']) && is_array($data['certificate'])) {
            $data['certificate'] = \app\service\MaskService::cert($data['certificate'], $user);
        }
        CustomFieldService::attachOne('secondment', $data);
        return json(r_success($data));
    }

    /**
     * 新增/编辑借调
     * with_contract=1 时自动生成关联服务合同
     */
    public function save()
    {
        $id = (int)$this->request->post('id', 0);
        $data = $this->request->post([
            'cert_id', 'company_id', 'direction', 'start_date', 'end_date',
            'fee_amount', 'fee_period', 'fee_status', 'status', 'remark',
        ]);
        $withContract = (int)$this->request->post('with_contract', 0);

        // 日期控件未选值会传 ''，MySQL 严格模式 date 列不接受空串 -> 转 null
        $data = $this->emptyToNull($data, ['start_date', 'end_date']);
        // v2.97：借调到期支持「长期」——勾选后结束日期置空（NULL），到期检测与提醒自动跳过
        if ((int)$this->request->post('long_term', 0) === 1) {
            $data['end_date'] = null;
        }
        if (empty($data['cert_id']) || empty($data['company_id'])) {
            return json(r_error('请选择证书与对方企业', 400));
        }
        $direction = (int)($data['direction'] ?? 1);
        if (!in_array($direction, [1, 2], true)) {
            $direction = 1;
        }
        $cert = CertificateModel::find((int)$data['cert_id']);
        if (!$cert) {
            return json(r_error('证书不存在', 400));
        }
        $this->assertCompanyAccess((int)$data['company_id']);
        $this->assertCertAccess((int)$data['cert_id']);
        if ($id) {
            $oldSecondment = CertSecondment::find($id);
            if (!$oldSecondment) {
                return json(r_error('借调记录不存在', 400));
            }
            $this->assertCompanyAccess((int)$oldSecondment->company_id);
            // v2.99.36：内部账号仅能编辑本人创建的借调记录
            $this->assertModuleAccess('cert_secondment', $id);
        }
        if (!empty($data['start_date']) && !empty($data['end_date'])
            && $data['end_date'] < $data['start_date']) {
            return json(r_error('到期日不能早于开始日', 400));
        }
        $status = isset($data['status']) && $data['status'] !== '' ? (int)$data['status'] : 1;
        // v2.55：不再硬拦截「证书已被占用」——改为走下方可配置的冲突检测：
        // 默认「证书占用冲突」为阻断级，前端展示冲突原因并提供「仍然保存」二次确认；
        // 管理员可在冲突中心将规则调整为提示/警告/禁用。
        $customErr = CustomFieldService::validate('secondment', $this->request->post('custom'));
        if ($customErr !== '') {
            return json(r_error($customErr, 400));
        }

        // 冲突前置检测（互斥证书 / 属地政策社保）：
        // 返回冲突明细，前端弹窗展示；force=1 可强制保存（保留人工判断余地，不阻断历史录入）
        $conflicts = [];
        try {
            $conflicts = \app\service\ConflictService::checkCert((int)$data['cert_id'], [
                'start_date' => (string)($data['start_date'] ?? ''),
                'end_date'   => (string)($data['end_date'] ?? ''),
                'project_id' => (int)$this->request->post('project_id', 0),
            ]);
        } catch (\Throwable $e) {
            $conflicts = [];
        }
        $blocked = false;
        foreach ($conflicts as $cf) {
            if ((int)($cf['level'] ?? 0) >= 2) { $blocked = true; break; }
        }
        $force = (int)$this->request->post('force', 0);
        if ($conflicts && !$force) {
            return json(r_error('检测到证书冲突，请确认后再保存', 409, [
                'conflicts' => $conflicts,
                'blocked'   => $blocked,
                'force_tip' => '确认无误可点击「仍然保存」',
            ]));
        }

        $data['staff_id']    = (int)$cert->staff_id;
        $data['direction']   = $direction;
        $data['fee_amount']  = $data['fee_amount'] ?? 0;
        $data['fee_period']  = ($data['fee_period'] ?? '') ?: '一次性';
        $data['fee_status']  = (int)($data['fee_status'] ?? 0);
        $data['status']      = $status;

        $contractId = 0;
        if ($id) {
            $old = CertSecondment::find($id);
            $contractId = $old ? (int)$old->contract_id : 0;
            $oldStatus  = $old ? (int)$old->status : $status;
            CertSecondment::where('id', $id)->update($data);
            // v2.76：编辑弹窗里直接改「借调状态」也要留痕
            if ($old && $oldStatus !== $status) {
                \app\service\CertStatusLogService::secondment(
                    (int)$id,
                    (int)$data['cert_id'],
                    $oldStatus,
                    $status,
                    (string)($this->request->user['real_name'] ?? ''),
                    '编辑借调记录调整状态'
                );
            }
            $this->log('证书借调', '更新', "#{$id}");
        } else {
            // v2.99.36：记录创建人，作为「其他模块」的数据范围依据
            $data['create_by'] = (int)$this->request->userId;
            $row = CertSecondment::create($data);
            $id  = $row->id;
            $this->log('证书借调', '新增', "#{$id}");
        }

        // 一键生成 / 更新关联合同
        if ($withContract) {
            $contractId = $this->syncContract($id, $data, $cert, $contractId);
        }
        if ($contractId) {
            CertSecondment::where('id', $id)->update(['contract_id' => $contractId]);
        }
        CustomFieldService::saveValues('secondment', (int)$id, $this->request->post('custom'));
        // 费用联动：借调费用自动生成到独立费用台账
        $feeId = $this->syncFee($id, $data, $cert);

        return json(r_success([
            'id' => $id, 'contract_id' => $contractId, 'fee_id' => $feeId,
            'conflicts' => $conflicts, 'forced' => (bool)($conflicts && $force),
        ], '保存成功'));
    }

    /**
     * 借调 → 费用台账联动
     * 借出：向客户企业收费（收入）；借入：向人才/供证方付费（支出）
     * 仅维护 is_auto=1 的记录，人工登记的费用不受影响
     */
    protected function syncFee(int $secondmentId, array $data, $cert): int
    {
        $amount = (float)($data['fee_amount'] ?? 0);
        // 老库可能缺 is_auto 列（历史迁移未回写 init.sql），先自愈；补不上则退化为按来源定位
        $hasAuto = $this->ensureColumn(
            'fee', 'is_auto',
            "tinyint NOT NULL DEFAULT 0 COMMENT '1=由借调等业务自动生成' AFTER `source_id`"
        );
        $autoQ = FeeModel::where('source_type', 'secondment')->where('source_id', $secondmentId);
        if ($hasAuto) {
            $autoQ->where('is_auto', 1);
        }
        $auto = $autoQ->find();

        if ($amount <= 0) {
            if ($auto) {
                $auto->delete();
            }
            return 0;
        }

        $direction = (int)($data['direction'] ?? 1);
        $certName  = $cert->cert_name ?? '证书';
        $payload   = [
            'company_id'    => (int)$data['company_id'],
            'source_type'   => 'secondment',
            'source_id'     => $secondmentId,
            'fee_name'      => ($direction === 2 ? '证书借入费·' : '证书借出费·') . $certName,
            'fee_type'      => $direction === 2 ? 'expense' : 'income',
            'amount'        => $amount,
            'occur_date'    => $data['start_date'] ?: date('Y-m-d'),
            'settle_status' => (int)($data['fee_status'] ?? 0),
            'settle_date'   => (int)($data['fee_status'] ?? 0) ? date('Y-m-d') : null,
            'remark'        => '由借调记录自动生成（借调#' . $secondmentId . '）',
        ];
        if ($hasAuto) {
            $payload['is_auto'] = 1;
        }

        if ($auto) {
            $auto->save($payload);
            return (int)$auto->id;
        }
        // v2.99.36：自动生成的费用归属当前操作人，避免创建人数据范围下看不到
        $payload['create_by'] = (int)$this->request->userId;
        $m = FeeModel::create($payload);
        return (int)$m->id;
    }

    /** 归还：把借调标记为已归还 */
    public function returnBack()
    {
        $id     = (int)$this->request->post('id', 0);
        $remark = $this->request->post('remark', '');
        $row    = CertSecondment::find($id);
        if (!$row) {
            return json(r_error('借调记录不存在', 400));
        }
        $this->assertModuleAccess('cert_secondment', $id);
        $oldStatus = (int)$row->status;
        $row->status = 0;
        $note = '归还于 ' . date('Y-m-d');
        if ($remark) {
            $note .= '：' . $remark;
        }
        $row->remark = $row->remark ? $row->remark . '；' . $note : $note;
        $row->save();
        // v2.76：归还同样是借调状态变更，写入状态变更日志
        \app\service\CertStatusLogService::secondment(
            $id,
            (int)$row->cert_id,
            $oldStatus,
            0,
            (string)($this->request->user['real_name'] ?? ''),
            $note
        );
        $this->log('证书借调', '归还', "#{$id}");
        return json(r_success([], '已归还'));
    }

    /**
     * 续借 / 延长借期：延长借调到期日；若已到期(2)则重新置为借调中(1)
     * 可选联动续借费用与关联服务合同，并触发「借调续借」场景推送通知
     */
    public function renew()
    {
        $id        = (int)$this->request->post('id', 0);
        $newEnd    = $this->request->post('new_end_date', '');
        $feeAmount = (float)$this->request->post('fee_amount', 0);
        $feeStatus = (int)$this->request->post('fee_status', 0);
        $remark    = $this->request->post('remark', '');
        $withContract = (int)$this->request->post('with_contract', 0);

        $row = CertSecondment::find($id);
        if (!$row) {
            return json(r_error('借调记录不存在', 400));
        }
        $this->assertModuleAccess('cert_secondment', $id);
        if (!$newEnd || $newEnd < date('Y-m-d')) {
            return json(r_error('续借到期日不能早于今天', 400));
        }
        if ($row->end_date && $newEnd <= $row->end_date) {
            return json(r_error('续借到期日需晚于原到期日（' . $row->end_date . '）', 400));
        }
        $cert = CertificateModel::find((int)$row->cert_id);
        if (!$cert) {
            return json(r_error('关联证书不存在', 400));
        }

        $oldStatus = (int)$row->status;
        $oldEnd    = (string)$row->end_date;

        $update = ['end_date' => $newEnd, 'status' => 1];
        if ($remark) {
            $update['remark'] = ($row->remark ? $row->remark . '；' : '') . '续借至' . $newEnd . '：' . $remark;
        }
        CertSecondment::where('id', $id)->update($update);

        // v2.76：「续借」会把已到期(2)恢复为借调中(1)，属于状态变更，写入状态变更日志。
        // 未到期记录延长借期时 old=new=1，同样留痕（备注里标明期限变化）。
        \app\service\CertStatusLogService::secondment(
            $id,
            (int)$row->cert_id,
            $oldStatus,
            1,
            (string)($this->request->user['real_name'] ?? ''),
            '续借：到期日 ' . ($oldEnd ?: '-') . ' → ' . $newEnd . ($remark ? '；' . $remark : '')
        );
        $this->log('证书借调', '续借', "#{$id} 续借至 {$newEnd}");

        $data = [
            'cert_id'     => $row->cert_id,
            'company_id'  => $row->company_id,
            'direction'   => $row->direction,
            'fee_amount'  => $feeAmount,
            'fee_status'  => $feeStatus,
            'start_date'  => $row->start_date,
            'end_date'    => $newEnd,
        ];
        if ($feeAmount > 0) {
            $this->syncFee($id, $data, $cert);
        }
        if ($withContract) {
            $this->syncContract($id, $data, $cert, (int)$row->contract_id);
        }

        $push = \app\service\CertExpireService::secondmentRenewPush($id);
        return json(r_success([
            'id'         => $id,
            'end_date'   => $newEnd,
            'push'       => $push,
        ], '续借成功，借期已延长至' . $newEnd));
    }

    public function delete()
    {
        $id = (int)$this->request->post('id', 0);
        if (!$id) {
            return json(r_error('参数错误', 400));
        }
        $r = $this->deleteOne($id);
        return $r['ok'] ? json(r_success([], '删除成功')) : json(r_error($r['msg'], 400));
    }

    protected function deleteOne(int $id): array
    {
        $row = CertSecondment::find($id);
        if (!$row) {
            return ['ok' => false, 'msg' => '借调记录不存在'];
        }
        if (!$this->canAccessModule('cert_secondment', $id)) {
            return ['ok' => false, 'msg' => '无权删除该借调记录'];
        }
        if ($row && $row->contract_id) {
            Contract::where('id', $row->contract_id)->update(['secondment_id' => 0]);
        }
        // v2.52：删除前快照进回收站（自动生成的费用 / 自定义值随删，一并快照）
        try {
            $rbFees = Db::name('fee')->where('source_type', 'secondment')->where('source_id', $id)->select()->toArray();
            \app\service\RecycleBinService::stash(
                (int)$this->request->userId,
                'secondment',
                ("#" . $id),
                $row->toArray(),
                array_filter([
                    ['table' => 'fee', 'rows' => $rbFees],
                    \app\service\RecycleBinService::grabCustom('secondment', $id),
                ])
            );
        } catch (\Throwable $e) {
            // 快照失败不阻断删除
        }
        CustomFieldService::deleteValues('secondment', $id);
        // 同步清理由该借调自动生成的费用（人工登记的费用保留）
        $feeQ = FeeModel::where('source_type', 'secondment')->where('source_id', $id);
        if ($this->ensureColumn(
            'fee', 'is_auto',
            "tinyint NOT NULL DEFAULT 0 COMMENT '1=由借调等业务自动生成' AFTER `source_id`"
        )) {
            $feeQ->where('is_auto', 1);
        }
        $feeQ->delete();
        CertSecondment::destroy($id);
        $this->log('证书借调', '删除', "#{$id}");
        return ['ok' => true, 'msg' => '删除成功'];
    }

    /** 可借调的空闲证书（未被任何企业借调中） */
    public function freeCerts()
    {
        $keyword = $this->request->param('keyword', '');
        $staffId = (int)$this->request->param('staff_id', 0);
        $busyIds = Db::name('cert_secondment')->where('status', 1)->column('cert_id');
        $q = CertificateModel::with(['staff'])->where('cert_status', 1)
            ->whereNotIn('id', $busyIds ?: [0]);
        $this->scopeCert($q);
        if ($staffId) {
            $q->where('staff_id', $staffId);
        }
        if ($keyword) {
            $q->where('cert_name|cert_no', 'like', "%{$keyword}%");
        }
        $list = $q->orderRaw('expire_date IS NULL ASC, expire_date ASC')->limit(200)->select()->toArray();
        $list = \app\service\MaskService::certListFor($list, $this->request->user ?? []);
        foreach ($list as &$c) {
            $c['show_name'] = $c['cert_name'] . '（' . ($c['staff']['staff_name'] ?? '') . '｜' . $c['cert_no'] . '）';
            // 是否已在库（存在生效的借入记录），借出时作为商品库存提示
            $c['in_stock'] = SecondmentService::isInStock((int)$c['id']);
        }
        unset($c);
        return json(r_success($list));
    }

    /** 借调统计（供仪表盘/移动端卡片使用） */
    public function stat()
    {
        $q = Db::name('cert_secondment');
        $q = $this->scopeSecondment($q);
        $today    = date('Y-m-d');
        $soonDate = date('Y-m-d', strtotime('+30 days'));
        return json(r_success([
            'active'       => (clone $q)->where('status', 1)->count(),
            'soon'         => (clone $q)->where('status', 1)->whereBetween('end_date', [$today, $soonDate])->count(),
            'expired'      => SecondmentService::expiredCount($q, $today),
            'returned'     => (clone $q)->where('status', 0)->count(),
            'in_stock'     => (clone $q)->where('direction', 2)->where('status', 1)->count(),
            'out_active'   => (clone $q)->where('direction', 1)->where('status', 1)->count(),
            'unsettled_fee' => (float)((clone $q)->where('fee_status', 0)->sum('fee_amount') ?? 0),
        ]));
    }

    /**
     * 生成 / 同步借调关联的服务合同
     */
    protected function syncContract(int $secondmentId, array $data, $cert, int $contractId = 0): int
    {
        $company = ClientCompany::find((int)$data['company_id']);
        $certName = $cert->cert_name;
        $staff    = Db::name('staff')->where('id', (int)$cert->staff_id)->find();
        $direction = (int)($data['direction'] ?? 1);
        if ($direction == 2) {
            // 借入：本中介向提供方（人才/外部）借入证书，合同双方互换
            $contractData = [
                'company_id'    => (int)$data['company_id'],
                'secondment_id' => $secondmentId,
                'contract_no'   => 'JR' . date('Ymd') . str_pad((string)$secondmentId, 4, '0', STR_PAD_LEFT),
                'title'         => '借入 ' . $certName . '（来源：' . ($company->company_name ?? '个人') . '）',
                'contract_type' => '证书借入',
                'party_a'       => $company->company_name ?? '个人提供方',
                'party_b'       => '本中介（猎头服务方）',
                'amount'        => $data['fee_amount'] ?? 0,
                'sign_date'     => date('Y-m-d'),
                'start_date'    => $data['start_date'] ?: date('Y-m-d'),
                'end_date'      => $data['end_date'] ?: null,
                'status'        => 1,
                'remark'        => '由借入记录自动生成（借调#' . $secondmentId . '）',
            ];
        } else {
            $contractData = [
                'company_id'    => (int)$data['company_id'],
                'secondment_id' => $secondmentId,
                'contract_no'   => 'JD' . date('Ymd') . str_pad((string)$secondmentId, 4, '0', STR_PAD_LEFT),
                'title'         => ($company->company_name ?? '') . ' 借调 ' . ($staff['staff_name'] ?? '') . ' ' . $certName,
                'contract_type' => '证书借调',
                'party_a'       => '本中介（服务方）',
                'party_b'       => $company->company_name ?? '',
                'amount'        => $data['fee_amount'] ?? 0,
                'sign_date'     => date('Y-m-d'),
                'start_date'    => $data['start_date'] ?: date('Y-m-d'),
                'end_date'      => $data['end_date'] ?: null,
                'status'        => 1,
                'remark'        => '由借调记录自动生成（借调#' . $secondmentId . '）',
            ];
        }
        if ($contractId) {
            Contract::where('id', $contractId)->update($contractData);
            return $contractId;
        }
        $contractData['create_by'] = (int)$this->request->userId;
        $c = Contract::create($contractData);
        return (int)$c->id;
    }
}
