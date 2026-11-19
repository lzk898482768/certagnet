<?php
namespace app\controller\admin;

use app\model\Fee as FeeModel;
use app\model\ClientCompany;
use app\model\PayAccount as PayAccountModel;
use think\facade\Db;

/**
 * 费用台账（一级业务模块）
 * 可独立记账，也可由借调/合同/证书/人员等业务联动生成
 */
class Fee extends AdminController
{
    /** 来源模块字典 */
    public const SOURCES = [
        'secondment'  => '证书借调',
        'contract'    => '服务合同',
        'certificate' => '证照档案',
        'staff'       => '持证人员',
        'renewal'     => '证书续期',
        'education'   => '学历报名',
        'exam'        => '考试报名',
        'other'       => '其他',
    ];

    /** 收付方向：收入=应收（客户欠我们），支出=应付（我们欠对方） */
    public const DIRECTIONS = [
        'receivable' => '应收',
        'payable'    => '应付',
    ];

    /** 结算方式 */
    public const PAY_METHODS = [
        'cash'    => '现金',
        'bank'    => '银行转账',
        'wechat'  => '微信',
        'alipay'  => '支付宝',
        'check'   => '支票',
        'offset'  => '抵扣',
        'other'   => '其他',
    ];

    /** 按收付方向推导：income->receivable, expense->payable */
    protected static function directionOf(string $feeType): string
    {
        return $feeType === 'expense' ? 'payable' : 'receivable';
    }

    /** 给单行补上应收/应付等展示字段 */
    protected static function decorate(array &$row): void
    {
        $row['direction']        = self::directionOf((string)($row['fee_type'] ?? 'income'));
        $row['direction_text']   = self::DIRECTIONS[$row['direction']] ?? $row['direction'];
        $row['settle_amount']    = (float)($row['settle_amount'] ?? 0);
        $row['unsettled_amount'] = round(max(0, (float)($row['amount'] ?? 0) - $row['settle_amount']), 2);
        $row['pay_method_text']  = \app\model\PayAccount::TYPES[(string)($row['pay_method'] ?? '')] ?? '';
        $row['pay_account_id']   = (int)($row['pay_account_id'] ?? 0);
    }

    /**
     * 批量给费用行附加收款账户名称（一次查询回填，避免 N+1）
     * @param array $list 费用数组（引用修改）
     */
    protected static function attachPayAccounts(array &$list): void
    {
        $ids = [];
        foreach ($list as &$row) {
            $aid = (int)($row['pay_account_id'] ?? 0);
            if ($aid > 0) {
                $ids[$aid] = $aid;
            }
            $row['pay_account_name'] = '';
            $row['pay_account_type_text'] = '';
        }
        unset($row);
        if ($ids) {
            $accs = PayAccountModel::whereIn('id', $ids)->select()->toArray();
            $map  = [];
            foreach ($accs as $a) {
                $map[(int)$a['id']] = $a;
            }
            foreach ($list as &$row) {
                $aid = (int)($row['pay_account_id'] ?? 0);
                if ($aid > 0 && isset($map[$aid])) {
                    $row['pay_account_name']      = (string)$map[$aid]['account_name'];
                    $row['pay_account_type_text'] = (string)$map[$aid]['account_type_text'];
                }
            }
            unset($row);
        }
    }

    /**
     * 批量挂载归属对象信息（v2.24）
     * 费用归属对象可以是「客户企业」或「持证人员」，统一输出：
     *   subject_type    company / staff
     *   subject_name    归属对象名称（企业全称 或 人员姓名）
     *   company_name    兼容字段：企业名称（人员时为其所属企业，可空）
     *   staff_name      人员姓名（人员时有效）
     * @param array $list 费用数组（引用修改）
     */
    protected static function attachSubjects(array &$list): void
    {
        $companyIds = [];
        $staffIds   = [];
        foreach ($list as &$row) {
            $row['subject_type'] = (string)($row['subject_type'] ?? 'company') ?: 'company';
            $row['staff_id']     = (int)($row['staff_id'] ?? 0);
            if ((int)($row['company_id'] ?? 0) > 0) {
                $companyIds[(int)$row['company_id']] = 1;
            }
            if ($row['staff_id'] > 0) {
                $staffIds[$row['staff_id']] = 1;
            }
            $row['staff_name']   = '';
            $row['subject_name'] = '';
        }
        unset($row);

        // 人员 → 所属企业（取第一条在册关系）
        $staffCompany = [];
        if ($staffIds) {
            $staffs = \app\model\Staff::whereIn('id', array_keys($staffIds))->select()->toArray();
            $rel = \app\model\StaffCompanyRel::whereIn('staff_id', array_keys($staffIds))->select()->toArray();
            $relMap = [];
            foreach ($rel as $r) {
                $sid = (int)$r['staff_id'];
                if (!isset($relMap[$sid])) {
                    $relMap[$sid] = (int)$r['company_id'];
                }
            }
            $staffMap = [];
            foreach ($staffs as $s) {
                $sid = (int)$s['id'];
                $staffMap[$sid] = $s;
                $cid = (int)($relMap[$sid] ?? 0);
                if ($cid > 0) {
                    $companyIds[$cid] = 1;
                }
            }
            foreach ($list as &$row) {
                $sid = (int)($row['staff_id'] ?? 0);
                if ($sid > 0 && isset($staffMap[$sid])) {
                    $row['staff_name'] = (string)$staffMap[$sid]['staff_name'];
                }
            }
            unset($row);
            $staffCompany = $relMap;
        }

        // 企业名称
        $companyMap = [];
        if ($companyIds) {
            $cos = \app\model\ClientCompany::whereIn('id', array_keys($companyIds))->select()->toArray();
            foreach ($cos as $c) {
                $companyMap[(int)$c['id']] = $c;
            }
        }

        foreach ($list as &$row) {
            $cid  = (int)($row['company_id'] ?? 0);
            $sid  = (int)($row['staff_id'] ?? 0);
            $isSt = $row['subject_type'] === 'staff';

            if ($isSt && $sid > 0) {
                // 人员为归属对象：company_id 若为空则回填其所属企业（方便统计）
                if ($cid <= 0 && !empty($staffCompany[$sid])) {
                    $cid = (int)$staffCompany[$sid];
                    $row['company_id'] = $cid;
                }
                $row['subject_name'] = (string)$row['staff_name'];
            } else {
                $row['subject_name'] = isset($companyMap[$cid])
                    ? (string)$companyMap[$cid]['company_name']
                    : '';
            }
            // 兼容字段：company_name 始终是企业名称
            $row['company_name'] = isset($companyMap[$cid]) ? (string)$companyMap[$cid]['company_name'] : '';
        }
        unset($row);
    }

    public function index()
    {
        [$page, $limit] = $this->pageParams();
        $companyId   = (int)$this->request->param('company_id', 0);
        $feeType     = $this->request->param('fee_type', '');
        $settle      = $this->request->param('settle_status', '');
        $sourceType  = (string)$this->request->param('source_type', '');
        $sourceId    = (int)$this->request->param('source_id', 0);
        $start       = (string)$this->request->param('start_date', '');
        $end         = (string)$this->request->param('end_date', '');
        $keyword     = trim((string)$this->request->param('keyword', ''));
        $direction   = (string)$this->request->param('direction', '');   // receivable / payable
        $unsettled   = (int)$this->request->param('unsettled_only', 0);  // 只看未结清
        $payAccount  = (int)$this->request->param('pay_account_id', 0);  // 收款账户（结算方式）

        $q = FeeModel::with(['company'])->order('id', 'desc');
        $q = $this->applyModuleScope($q, 'fee');
        if ($companyId) {
            $q->where('company_id', $companyId);
        }
        if ($direction === 'receivable') {
            $q->where('fee_type', 'income');
        } elseif ($direction === 'payable') {
            $q->where('fee_type', 'expense');
        }
        if ($feeType !== '') {
            $q->where('fee_type', $feeType);
        }
        if ($unsettled) {
            $q->where('settle_status', 0);
        }
        if ($payAccount) {
            $q->where('pay_account_id', $payAccount);
        }
        if ($settle !== '') {
            $q->where('settle_status', (int)$settle);
        }
        if ($sourceType !== '') {
            $q->where('source_type', $sourceType);
            if ($sourceId) {
                $q->where('source_id', $sourceId);
            }
        }
        if ($start) {
            $q->where('occur_date', '>=', $start);
        }
        if ($end) {
            $q->where('occur_date', '<=', $end);
        }
        if ($keyword) {
            $q->where(function ($w) use ($keyword) {
                $w->whereLike('fee_name', "%{$keyword}%")
                  ->whereOr('remark', 'like', "%{$keyword}%");
            });
        }

        $total = $q->count();
        $list  = $q->page($page, $limit)->select()->toArray();
        foreach ($list as &$row) {
            $row['source_label'] = $this->sourceLabel((string)($row['source_type'] ?? ''), (int)($row['source_id'] ?? 0));
            self::decorate($row);
        }
        unset($row);
        self::attachPayAccounts($list);
        self::attachSubjects($list);
        return json(r_success(['list' => $list, 'total' => $total]));
    }

    /**
     * 导出费用明细表（Excel）
     * 复用 index 的筛选条件，导出当前筛选条件下的全部记录（不分页），
     * 列含：单号/客户企业/费用项目/关联来源/收付方向/收支类型/金额/已收付/未结清/
     * 发生日期/计划收付/结算状态/收款账户/票据号/经办人/备注。
     */
    public function export()
    {
        $companyId   = (int)$this->request->param('company_id', 0);
        $feeType     = $this->request->param('fee_type', '');
        $settle      = $this->request->param('settle_status', '');
        $sourceType  = (string)$this->request->param('source_type', '');
        $sourceId    = (int)$this->request->param('source_id', 0);
        $start       = (string)$this->request->param('start_date', '');
        $end         = (string)$this->request->param('end_date', '');
        $keyword     = trim((string)$this->request->param('keyword', ''));
        $direction   = (string)$this->request->param('direction', '');
        $unsettled   = (int)$this->request->param('unsettled_only', 0);
        $payAccount  = (int)$this->request->param('pay_account_id', 0);

        $q = FeeModel::with(['company'])->order('id', 'desc');
        $q = $this->applyModuleScope($q, 'fee');
        if ($companyId) {
            $q->where('company_id', $companyId);
        }
        if ($direction === 'receivable') {
            $q->where('fee_type', 'income');
        } elseif ($direction === 'payable') {
            $q->where('fee_type', 'expense');
        }
        if ($feeType !== '') {
            $q->where('fee_type', $feeType);
        }
        if ($unsettled) {
            $q->where('settle_status', 0);
        }
        if ($payAccount) {
            $q->where('pay_account_id', $payAccount);
        }
        if ($settle !== '') {
            $q->where('settle_status', (int)$settle);
        }
        if ($sourceType !== '') {
            $q->where('source_type', $sourceType);
            if ($sourceId) {
                $q->where('source_id', $sourceId);
            }
        }
        if ($start) {
            $q->where('occur_date', '>=', $start);
        }
        if ($end) {
            $q->where('occur_date', '<=', $end);
        }
        if ($keyword) {
            $q->where(function ($w) use ($keyword) {
                $w->whereLike('fee_name', "%{$keyword}%")
                  ->whereOr('remark', 'like', "%{$keyword}%");
            });
        }

        $list = $q->limit(5000)->select()->toArray();
        foreach ($list as &$row) {
            $row['source_label'] = $this->sourceLabel((string)($row['source_type'] ?? ''), (int)($row['source_id'] ?? 0));
            self::decorate($row);
        }
        unset($row);
        self::attachPayAccounts($list);
        self::attachSubjects($list);

        $rows = array_map(function ($r) {
            return [
                'id'               => $r['id'],
                'company_name'     => $r['company']['company_name'] ?? '',
                'fee_name'         => $r['fee_name'] ?? '',
                'source_label'     => $r['source_label'] ?? '',
                'direction_text'   => $r['direction_text'] ?? '',
                'fee_type_text'    => $r['fee_type'] === 'expense' ? '支出' : '收入',
                'amount'           => (float)($r['amount'] ?? 0),
                'settle_amount'    => (float)($r['settle_amount'] ?? 0),
                'unsettled_amount' => (float)($r['unsettled_amount'] ?? 0),
                'occur_date'       => $r['occur_date'] ?? '',
                'plan_date'        => $r['plan_date'] ?? '',
                'settle_text'      => empty($r['settle_status']) ? '未结算' : '已结算',
                'pay_account_name' => $r['pay_account_name'] ?? '',
                'invoice_no'       => $r['invoice_no'] ?? '',
                'handler'          => $r['handler'] ?? '',
                'remark'           => $r['remark'] ?? '',
            ];
        }, $list);

        $path = \app\service\ExcelService::exportFee($rows);
        return download_utf8($path, '费用明细表_' . date('Ymd') . '.xlsx');
    }

    /** 应收 / 应付总览（商用财务视角） */
    public function receivablePayable()
    {
        $base = Db::name('fee');
        $base = $this->applyModuleScope($base, 'fee');
        $receivable = (float)(clone $base)->where('fee_type', 'income')->where('settle_status', 0)->sum('amount');
        $payable    = (float)(clone $base)->where('fee_type', 'expense')->where('settle_status', 0)->sum('amount');

        // 已收 / 已付：已结算金额
        $settledBase = Db::name('fee');
        $settledBase = $this->applyModuleScope($settledBase, 'fee');
        $received = (float)(clone $settledBase)->where('fee_type', 'income')->sum('settle_amount');
        $paid     = (float)(clone $settledBase)->where('fee_type', 'expense')->sum('settle_amount');

        return json(r_success([
            'receivable' => round($receivable, 2),
            'payable'    => round($payable, 2),
            'received'   => round($received, 2),
            'paid'       => round($paid, 2),
            'balance'    => round($receivable - $payable, 2),
        ]));
    }

    /** 供业务详情页调用：取某来源关联的费用 */
    public function bySource()
    {
        $type = (string)$this->request->param('source_type', '');
        $id   = (int)$this->request->param('source_id', 0);
        if ($type === '' || !$id) {
            return json(r_success([]));
        }
        $q = FeeModel::with(['company'])->where('source_type', $type)->where('source_id', $id)
            ->order('id', 'desc');
        $q = $this->applyModuleScope($q, 'fee');
        $list = $q->select()->toArray();
        foreach ($list as &$row) {
            $row['source_label'] = $this->sourceLabel($type, $id);
            self::decorate($row);
        }
        unset($row);
        self::attachPayAccounts($list);
        self::attachSubjects($list);
        return json(r_success($list));
    }

    /** 来源字典（前端下拉/展示用） */
    public function meta()
    {
        return json(r_success([
            'sources'     => self::SOURCES,
            'types'       => ['income' => '收入', 'expense' => '支出'],
            'directions'  => self::DIRECTIONS,
            // v2.23 结算方式字典 = 收款账户的账户类型（对公/对私银行账户、微信、支付宝、现金、其他）
            'pay_methods' => \app\model\PayAccount::TYPES,
        ]));
    }

    public function save()
    {
        $id = (int)$this->request->post('id', 0);
        $data = $this->request->post([
            'company_id', 'staff_id', 'subject_type', 'payer_name',
            'source_type', 'source_id', 'fee_name',
            'fee_type', 'amount', 'occur_date', 'settle_status', 'settle_date', 'remark',
            'plan_date', 'settle_amount', 'pay_method', 'invoice_no', 'handler', 'pay_account_id',
        ]);

        // v2.24 归属对象：客户企业 或 持证人员（二选一必填）
        $st = (string)($data['subject_type'] ?? 'company') ?: 'company';
        if (!in_array($st, ['company', 'staff'], true)) {
            $st = 'company';
        }
        $data['subject_type'] = $st;
        $data['staff_id']     = (int)($data['staff_id'] ?? 0);
        $data['company_id']   = (int)($data['company_id'] ?? 0);
        $data['payer_name']   = trim((string)($data['payer_name'] ?? ''));

        if ($st === 'staff') {
            if ($data['staff_id'] <= 0) {
                return json(r_error('请选择持证人员', 400));
            }
            if (!\app\model\Staff::where('id', $data['staff_id'])->find()) {
                return json(r_error('持证人员不存在', 400));
            }
            // 人员归属时，若未指定企业则回填其所属企业（便于按企业汇总统计）
            if ($data['company_id'] <= 0) {
                $rel = \app\model\StaffCompanyRel::where('staff_id', $data['staff_id'])->order('id', 'asc')->find();
                if ($rel) {
                    $data['company_id'] = (int)$rel['company_id'];
                }
            }
        } else {
            if ($data['company_id'] <= 0) {
                return json(r_error('请选择客户企业', 400));
            }
            if (!\app\model\ClientCompany::where('id', $data['company_id'])->find()) {
                return json(r_error('客户企业不存在', 400));
            }
            $data['staff_id'] = 0;
        }

        if (empty($data['fee_name'])) {
            return json(r_error('请填写费用项目', 400));
        }
        // 对象级权限：新增/编辑的目标企业或人员必须在当前账号范围内。
        if ($data['company_id'] > 0) {
            $this->assertCompanyAccess((int)$data['company_id']);
        }
        if ($data['staff_id'] > 0) {
            $this->assertStaffAccess((int)$data['staff_id']);
        }
        if ($id) {
            $oldFee = FeeModel::find($id);
            if (!$oldFee) {
                return json(r_error('费用不存在', 404));
            }
            if ((int)$oldFee->company_id > 0) {
                $this->assertCompanyAccess((int)$oldFee->company_id);
            }
            // v2.99.36：内部账号仅能编辑本人创建的费用
            $this->assertModuleAccess('fee', $id);
        }
        // v2.18 结算方式 = 收款账户（0 = 未指定）
        $data['pay_account_id'] = (int)($data['pay_account_id'] ?? 0);
        if ($data['pay_account_id'] > 0) {
            $acc = PayAccountModel::where('id', $data['pay_account_id'])->where('status', 1)->find();
            if (!$acc) {
                return json(r_error('收款账户不存在或已停用', 400));
            }
        }
        // v2.23 结算方式字典 = 收款账户的账户类型；兼容旧值自动映射
        $pm = (string)($data['pay_method'] ?? '');
        $pmMap = ['bank' => 'bank_public', 'public' => 'bank_public', 'private' => 'bank_private', 'check' => 'other', 'offset' => 'other'];
        if (isset($pmMap[$pm])) {
            $pm = $pmMap[$pm];
        }
        $data['pay_method']     = isset(\app\model\PayAccount::TYPES[$pm]) ? $pm : '';
        $data['fee_type']     = (string)($data['fee_type'] ?? 'income');
        if (!in_array($data['fee_type'], ['income', 'expense'], true)) {
            $data['fee_type'] = 'income';
        }
        $data['amount']       = round((float)($data['amount'] ?? 0), 2);
        if ($data['amount'] < 0) {
            return json(r_error('金额不能为负数', 400));
        }
        $data['source_type']  = (string)($data['source_type'] ?? 'other');
        $data['source_id']    = (int)($data['source_id'] ?? 0);
        $data['settle_status']= (int)($data['settle_status'] ?? 0);
        $data['settle_date']  = $data['settle_status'] ? (($data['settle_date'] ?? '') ?: date('Y-m-d')) : null;
        $data['occur_date']   = $data['occur_date'] ?: date('Y-m-d');

        // v2.8 应收/应付增强字段
        $data['plan_date']    = ($data['plan_date'] ?? '') ?: null;
        $data['pay_method']   = (string)($data['pay_method'] ?? '');
        $data['invoice_no']   = trim((string)($data['invoice_no'] ?? ''));
        $data['handler']      = trim((string)($data['handler'] ?? ''));
        // 已结算金额：不能为负，不能超过总额；整单结算时自动等于总额
        $settleAmount = round((float)($data['settle_amount'] ?? 0), 2);
        if ($settleAmount < 0) {
            $settleAmount = 0;
        }
        if ($settleAmount > $data['amount']) {
            $settleAmount = $data['amount'];
        }
        if ($data['settle_status']) {
            $settleAmount = $data['amount'];
        }
        $data['settle_amount'] = $settleAmount;
        // 部分结算时不算「已结算」，全部收/付完才算
        $data['settle_status'] = ($settleAmount >= $data['amount'] && $data['amount'] > 0) ? 1 : 0;
        if (!$data['settle_status']) {
            $data['settle_date'] = $settleAmount > 0 ? ($data['settle_date'] ?: date('Y-m-d')) : null;
        }

        if ($id) {
            FeeModel::where('id', $id)->update($data);
            $this->log('费用台账', '更新', "#{$id}");
        } else {
            // v2.99.36：记录创建人，作为「其他模块」的数据范围依据
            $data['create_by'] = (int)$this->request->userId;
            $m = FeeModel::create($data);
            $id = (int)$m->id;
            $this->log('费用台账', '新增', "#{$id}");
        }
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
        $row = FeeModel::find($id);
        if (!$row) {
            return ['ok' => false, 'msg' => '费用不存在'];
        }
        if ((int)$row->company_id > 0 && !$this->canAccessCompany((int)$row->company_id)) {
            return ['ok' => false, 'msg' => '无权删除该费用'];
        }
        // v2.99.36：内部账号按创建人
        if (!$this->canAccessModule('fee', $id)) {
            return ['ok' => false, 'msg' => '无权删除该费用'];
        }
        // v2.52：删除前快照进回收站
        try {
            \app\service\RecycleBinService::stash(
                (int)$this->request->userId,
                'fee',
                ((string)($row['fee_name'] ?? '') ?: ($row['remark'] ?? '')) ?: ("#" . $id),
                $row->toArray()
            );
        } catch (\Throwable $e) {
            // 快照失败不阻断删除
        }
        FeeModel::destroy($id);
        $this->log('费用台账', '删除', "#{$id}");
        return ['ok' => true, 'msg' => '删除成功'];
    }

    /**
     * 结算 / 取消结算
     * - settle_status=1 且未传 amount：整单结清
     * - settle_status=1 且传 amount ：本次收/付金额，累加到 settle_amount（支持部分结算）
     * - settle_status=0：取消结算，已结算金额归零
     */
    public function settle()
    {
        $id         = (int)$this->request->post('id', 0);
        $status     = (int)$this->request->post('settle_status', 1);
        $amountRaw  = $this->request->post('amount', null);
        $payMethod  = (string)$this->request->post('pay_method', '');
        $settleDate = (string)$this->request->post('settle_date', '');
        // v2.18 结算时可指定/修改收款账户（不传 = 不修改；传 0 或空 = 清除）
        $payAccount = $this->request->post('pay_account_id', null);
        if ($payAccount !== null) {
            $aid = (int)$payAccount;
            if ($aid > 0) {
                $acc = PayAccountModel::where('id', $aid)->where('status', 1)->find();
                if (!$acc) {
                    return json(r_error('收款账户不存在或已停用', 400));
                }
            }
        } else {
            $aid = -1; // 标记为不修改
        }
        if (!$id) {
            return json(r_error('参数错误', 400));
        }
        $row = FeeModel::find($id);
        if (!$row) {
            return json(r_error('费用不存在', 404));
        }
        if ((int)$row->company_id > 0) {
            $this->assertCompanyAccess((int)$row->company_id);
        }
        // v2.99.36：内部账号按创建人
        $this->assertModuleAccess('fee', $id);
        $total = round((float)$row->amount, 2);

        if (!$status) {
            $data = [
                'settle_status' => 0,
                'settle_amount' => 0,
                'settle_date'   => null,
            ];
        } else {
            $cur  = round((float)($row->settle_amount ?? 0), 2);
            $next = ($amountRaw === null || $amountRaw === '') ? $total : $cur + round((float)$amountRaw, 2);
            if ($next < 0) {
                $next = 0;
            }
            if ($next > $total) {
                $next = $total;
            }
            $done = ($total > 0 && $next >= $total);
            $data = [
                'settle_amount' => $next,
                'settle_status' => $done ? 1 : 0,
                'settle_date'   => $settleDate ?: date('Y-m-d'),
            ];
        }
        if ($payMethod !== '') {
            $data['pay_method'] = $payMethod;
        }
        if ($aid >= 0) {
            $data['pay_account_id'] = $aid;
        }
        FeeModel::where('id', $id)->update($data);

        $msg = $status
            ? (($data['settle_status'] ?? 0) ? '已结清' : '已记录本次收/付，剩余未结清')
            : '已取消结算';
        $this->log('费用台账', '结算', "#{$id}");
        return json(r_success([
            'id'               => $id,
            'settle_status'    => $data['settle_status'],
            'settle_amount'    => $data['settle_amount'],
            'unsettled_amount' => round(max(0, $total - (float)$data['settle_amount']), 2),
        ], $msg));
    }

    /** 按企业费用汇总 */
    public function summary()
    {
        $q = ClientCompany::field('id,company_name');
        $q = $this->scopeCompany($q, 'id');
        $companies = $q->select()->toArray();
        $rows = Db::name('fee')
            ->field('company_id, count(*) as count, sum(amount) as fee')
            ->group('company_id')->select()->toArray();
        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r['company_id']] = $r;
        }
        $incomeRows = Db::name('fee')->field('company_id, sum(amount) as amt')
            ->where('fee_type', 'income')->group('company_id')->select()->toArray();
        $incomeMap = [];
        foreach ($incomeRows as $r) {
            $incomeMap[(int)$r['company_id']] = (float)$r['amt'];
        }
        $expenseRows = Db::name('fee')->field('company_id, sum(amount) as amt')
            ->where('fee_type', 'expense')->group('company_id')->select()->toArray();
        $expenseMap = [];
        foreach ($expenseRows as $r) {
            $expenseMap[(int)$r['company_id']] = (float)$r['amt'];
        }
        $unsettled = Db::name('fee')->where('settle_status', 0);
        $unsettled = $this->applyModuleScope($unsettled, 'fee');
        $unsettledTotal = (float)$unsettled->sum('amount');
        $result = [];
        foreach ($companies as $c) {
            $r = $map[(int)$c['id']] ?? null;
            $u = Db::name('fee')->where('company_id', $c['id'])->where('settle_status', 0);
            // v2.8：按企业拆出应收（收入未结）/ 应付（支出未结）
            $recv = (float)Db::name('fee')->where('company_id', $c['id'])
                ->where('fee_type', 'income')->where('settle_status', 0)->sum('amount');
            $pay  = (float)Db::name('fee')->where('company_id', $c['id'])
                ->where('fee_type', 'expense')->where('settle_status', 0)->sum('amount');
            $result[] = [
                'company_name' => $c['company_name'],
                'count'        => (int)($r['count'] ?? 0),
                'fee'          => (float)($r['fee'] ?? 0),
                'income'       => (float)($incomeMap[(int)$c['id']] ?? 0),
                'expense'      => (float)($expenseMap[(int)$c['id']] ?? 0),
                'unsettled'    => (float)$u->sum('amount'),
                'receivable'   => round($recv, 2),
                'payable'      => round($pay, 2),
            ];
        }
        return json(r_success(['list' => $result, 'unsettled_total' => $unsettledTotal]));
    }

    /** 费用统计：按月收支趋势 + 按来源分布 + 结算情况 */
    public function stat()
    {
        $months = (int)$this->request->param('months', 12);
        $months = max(1, min(36, $months));

        $base = Db::name('fee');
        $base = $this->applyModuleScope($base, 'fee');

        // 按月趋势
        $start = date('Y-m-01', strtotime('-' . ($months - 1) . ' months'));
        $rows  = (clone $base)->where('occur_date', '>=', $start)
            ->field("DATE_FORMAT(occur_date, '%Y-%m') as ym, fee_type, sum(amount) as amt, count(*) as cnt")
            ->group("ym, fee_type")->select()->toArray();

        $trend = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $ym = date('Y-m', strtotime("-{$i} months"));
            $trend[$ym] = ['ym' => $ym, 'income' => 0.0, 'expense' => 0.0, 'count' => 0];
        }
        foreach ($rows as $r) {
            $ym = $r['ym'];
            if (!isset($trend[$ym])) {
                $trend[$ym] = ['ym' => $ym, 'income' => 0.0, 'expense' => 0.0, 'count' => 0];
            }
            $trend[$ym][$r['fee_type']] = (float)$r['amt'];
            $trend[$ym]['count']       += (int)$r['cnt'];
        }

        // 按来源分布
        $bySource = [];
        $srcRows = (clone $base)->field('source_type, count(*) as cnt, sum(amount) as amt')
            ->group('source_type')->select()->toArray();
        foreach ($srcRows as $r) {
            $t = (string)($r['source_type'] ?: 'other');
            $bySource[] = [
                'source_type' => $t,
                'source_name' => self::SOURCES[$t] ?? $t,
                'count'       => (int)$r['cnt'],
                'amount'      => (float)$r['amt'],
            ];
        }

        // 结算情况
        $totalIncome  = (float)(clone $base)->where('fee_type', 'income')->sum('amount');
        $totalExpense = (float)(clone $base)->where('fee_type', 'expense')->sum('amount');
        $settled      = (float)(clone $base)->where('settle_status', 1)->sum('amount');
        $unsettled    = (float)(clone $base)->where('settle_status', 0)->sum('amount');

        // v2.8：应收 / 应付
        $receivable = (float)(clone $base)->where('fee_type', 'income')->where('settle_status', 0)->sum('amount');
        $payable    = (float)(clone $base)->where('fee_type', 'expense')->where('settle_status', 0)->sum('amount');
        $received   = (float)(clone $base)->where('fee_type', 'income')->sum('settle_amount');
        $paid       = (float)(clone $base)->where('fee_type', 'expense')->sum('settle_amount');

        // v2.18：按收款账户统计
        $byAccount = $this->accountStat();

        return json(r_success([
            'trend'         => array_values($trend),
            'by_source'     => $bySource,
            'by_account'    => $byAccount,
            'total_income'  => $totalIncome,
            'total_expense' => $totalExpense,
            'profit'        => $totalIncome - $totalExpense,
            'settled'       => $settled,
            'unsettled'     => $unsettled,
            'receivable'    => round($receivable, 2),
            'payable'       => round($payable, 2),
            'received'      => round($received, 2),
            'paid'          => round($paid, 2),
            'balance'       => round($receivable - $payable, 2),
        ]));
    }

    /**
     * v2.18 按收款账户统计
     *  - 每个账户：收入/支出总额、笔数、已收/已付金额、当前余额
     *  - 当前余额 = 初始余额 + 已收 − 已付
     *  - 未指定收款账户的费用汇总为「未指定账户」一行
     *  - 非管理员时按企业数据权限过滤
     */
    protected function accountStat(): array
    {
        $accounts = PayAccountModel::order('is_default', 'desc')->order('sort', 'asc')->order('id', 'desc')
            ->select()->toArray();
        $q = Db::name('fee');
        $q = $this->applyModuleScope($q, 'fee');
        $rows = $q->field("pay_account_id, count(*) as cnt,
                sum(amount) as amount_total,
                sum(case when fee_type='income'  then amount else 0 end) as income,
                sum(case when fee_type='expense' then amount else 0 end) as expense,
                sum(case when fee_type='income'  and settle_status=1 then settle_amount else 0 end) as received,
                sum(case when fee_type='expense' and settle_status=1 then settle_amount else 0 end) as paid")
            ->group('pay_account_id')->select()->toArray();
        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r['pay_account_id']] = $r;
        }
        $result = [];
        foreach ($accounts as $a) {
            $r        = $map[(int)$a['id']] ?? null;
            $ini      = (float)($a['initial_balance'] ?? 0);
            $received = (float)($r['received'] ?? 0);
            $paid     = (float)($r['paid'] ?? 0);
            $result[] = [
                'pay_account_id'     => (int)$a['id'],
                'account_name'       => (string)$a['account_name'],
                'account_type'       => (string)$a['account_type'],
                'account_type_text'  => (string)($a['account_type_text'] ?? ''),
                'is_default'         => (int)($a['is_default'] ?? 0),
                'count'              => (int)($r['cnt'] ?? 0),
                'income'             => round((float)($r['income'] ?? 0), 2),
                'expense'            => round((float)($r['expense'] ?? 0), 2),
                'received'           => round($received, 2),
                'paid'               => round($paid, 2),
                'initial_balance'    => $ini,
                'balance'            => round($ini + $received - $paid, 2),
            ];
        }
        // 未指定账户的费用（pay_account_id=0）
        $r = $map[0] ?? null;
        if ($r) {
            $result[] = [
                'pay_account_id'     => 0,
                'account_name'       => '未指定账户',
                'account_type'       => '',
                'account_type_text'  => '—',
                'is_default'         => 0,
                'count'              => (int)$r['cnt'],
                'income'             => round((float)$r['income'], 2),
                'expense'            => round((float)$r['expense'], 2),
                'received'           => round((float)$r['received'], 2),
                'paid'               => round((float)$r['paid'], 2),
                'initial_balance'    => 0,
                'balance'            => 0,
            ];
        }
        return $result;
    }

    /** 把来源解析成可读名称 */
    protected function sourceLabel(string $type, int $id): string
    {
        if ($type === '' || !$id) {
            return '手工登记';
        }
        $name = self::SOURCES[$type] ?? $type;
        $title = '';
        switch ($type) {
            case 'secondment':
                $row = Db::name('cert_secondment')->alias('s')
                    ->leftJoin('certificate c', 'c.id = s.cert_id')
                    ->where('s.id', $id)->field('c.cert_name')->find();
                $title = $row['cert_name'] ?? '';
                break;
            case 'contract':
                $title = (string)Db::name('contract')->where('id', $id)->value('title');
                break;
            case 'certificate':
                $title = (string)Db::name('certificate')->where('id', $id)->value('cert_name');
                break;
            case 'staff':
                $title = (string)Db::name('staff')->where('id', $id)->value('staff_name');
                break;
            case 'renewal':
                $title = '续期#' . $id;
                break;
            case 'education':
                $row = Db::name('edu_signup')->where('id', $id)->field('name,signup_no')->find();
                $title = $row ? (($row['name'] ?? '') . '（' . ($row['signup_no'] ?? ('#' . $id)) . '）') : '';
                break;
            case 'exam':
                $row = Db::name('exam_signup')->alias('s')
                    ->leftJoin('exam_customer cu', 'cu.id = s.customer_id')
                    ->where('s.id', $id)
                    ->field('s.signup_no,cu.name customer_name')->find();
                $title = $row ? (($row['customer_name'] ?? '') . '（' . ($row['signup_no'] ?? ('#' . $id)) . '）') : '';
                break;
        }
        return $name . ($title ? '·' . $title : '') . '（#' . $id . '）';
    }
}
