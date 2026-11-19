<?php
namespace app\controller\admin;

use app\model\Fee as FeeModel;
use app\model\PayAccount as PayAccountModel;
use think\facade\Db;

/**
 * 收款账户管理（v2.16 创建，v2.18 扩展）
 *
 *  - GET  /admin/pay-account          列表（keyword / status / account_type，含当前余额）
 *  - GET  /admin/pay-account/options  启用中的账户（下拉选择用）
 *  - POST /admin/pay-account/save     新增/编辑
 *  - POST /admin/pay-account/delete   删除
 *  - POST /admin/pay-account/set-default  设为默认
 */
class PayAccount extends AdminController
{
    /** 列表（含余额与完整账号，仅管理员/财务可见） */
    public function index()
    {
        $this->requireAnyRole(['admin', 'finance']);

        $q = PayAccountModel::order('is_default', 'desc')->order('sort', 'asc')->order('id', 'desc');
        $keyword = trim((string)$this->request->param('keyword', ''));
        if ($keyword !== '') {
            $q->where(function ($w) use ($keyword) {
                $w->where('account_name', 'like', "%{$keyword}%")
                  ->whereOr('bank_name', 'like', "%{$keyword}%")
                  ->whereOr('account_no', 'like', "%{$keyword}%");
            });
        }
        $status = $this->request->param('status', '');
        if ($status !== '' && $status !== null) {
            $q->where('status', (int)$status);
        }
        $type = trim((string)$this->request->param('account_type', ''));
        if ($type !== '') {
            $q->where('account_type', $type);
        }
        $total = $q->count();
        $list  = $q->select()->toArray();
        $this->attachBalance($list);
        return json(r_success(['list' => $list, 'total' => $total]));
    }

    /**
     * 给账户列表附加资金流水统计：
     *  - income / expense：通过本账户已收 / 已付金额（取已结算金额 settle_amount）
     *  - fee_count：关联费用笔数
     *  - balance：当前余额 = 初始余额 + 已收 − 已付
     * @param array $list 账户数组（引用修改）
     */
    protected function attachBalance(array &$list): void
    {
        if (!$list) {
            return;
        }
        $ids = array_column($list, 'id');
        // 一次分组查询取各账户 收入/支出 的已结算金额与笔数，避免 N+1
        $rows = FeeModel::whereIn('pay_account_id', $ids)
            ->field("pay_account_id, fee_type,
                     count(*) as fee_count,
                     sum(case when settle_status=1 and fee_type='income'  then settle_amount else 0 end) as income_amt,
                     sum(case when settle_status=1 and fee_type='expense' then settle_amount else 0 end) as expense_amt")
            ->group('pay_account_id, fee_type')
            ->select()->toArray();
        $map = [];
        foreach ($rows as $r) {
            $aid = (int)$r['pay_account_id'];
            if (!isset($map[$aid])) {
                $map[$aid] = ['fee_count' => 0, 'income' => 0.0, 'expense' => 0.0];
            }
            $map[$aid]['fee_count'] += (int)$r['fee_count'];
            $map[$aid]['income']   += (float)$r['income_amt'];
            $map[$aid]['expense']  += (float)$r['expense_amt'];
        }
        foreach ($list as &$a) {
            $m   = $map[(int)$a['id']] ?? ['fee_count' => 0, 'income' => 0.0, 'expense' => 0.0];
            $ini = (float)($a['initial_balance'] ?? 0);
            $a['income']     = round($m['income'], 2);
            $a['expense']    = round($m['expense'], 2);
            $a['fee_count']  = $m['fee_count'];
            $a['balance']    = round($ini + $m['income'] - $m['expense'], 2);
        }
        unset($a);
    }

    /** 启用中的账户（下拉用；非财务角色隐藏完整账号与余额） */
    public function options()
    {
        $list = PayAccountModel::where('status', 1)
            ->order('is_default', 'desc')->order('sort', 'asc')->order('id', 'desc')
            ->field('id,account_name,bank_name,account_no,account_type,currency,initial_balance,is_default')
            ->select()->toArray();
        if (!in_array($this->roleCode(), ['admin', 'finance'], true)) {
            foreach ($list as &$row) {
                $row['account_no'] = $this->maskAccountNo((string)($row['account_no'] ?? ''));
                unset($row['initial_balance']);
            }
            unset($row);
        }
        return json(r_success($list));
    }

    private function maskAccountNo(string $no): string
    {
        $no = trim($no);
        $len = strlen($no);
        if ($len <= 4) {
            return $no === '' ? '' : '****';
        }
        return str_repeat('*', max(0, $len - 4)) . substr($no, -4);
    }

    /** 新增/编辑 */
    public function save()
    {
        $this->requireAnyRole(['admin', 'finance']);
        $id = (int)$this->request->post('id', 0);
        $data = $this->request->post([
            'account_name', 'bank_name', 'branch_name', 'account_no',
            'account_type', 'initial_balance', 'currency', 'contact', 'phone',
            'is_default', 'status', 'sort', 'remark',
        ]);
        $data['account_name'] = trim((string)($data['account_name'] ?? ''));
        $data['account_no']   = trim((string)($data['account_no'] ?? ''));
        if ($data['account_name'] === '') {
            return json(r_error('账户名称（户名）必填', 400));
        }
        // 账户类型：兼容旧值 public/private → bank_public/bank_private
        $data['account_type'] = (string)($data['account_type'] ?? 'bank_public');
        if ($data['account_type'] === 'public')  $data['account_type'] = 'bank_public';
        if ($data['account_type'] === 'private') $data['account_type'] = 'bank_private';
        if (!isset(PayAccountModel::TYPES[$data['account_type']])) {
            return json(r_error('不支持的账户类型', 400));
        }
        // 账号：银行类必填；微信/支付宝/现金等可填（作为账号标识）
        if (PayAccountModel::isBank($data['account_type']) && $data['account_no'] === '') {
            return json(r_error('请填写银行账号', 400));
        }
        $data['initial_balance'] = round((float)($data['initial_balance'] ?? 0), 2);
        $data['currency']     = trim((string)($data['currency'] ?? '')) ?: 'CNY';
        $data['is_default']   = (int)($data['is_default'] ?? 0) === 1 ? 1 : 0;
        $data['status']       = (int)($data['status'] ?? 1) === 0 ? 0 : 1;
        $data['sort']         = (int)($data['sort'] ?? 0);

        // 账号唯一性（软约束，友好提示；仅当填写了账号时校验）
        if ($data['account_no'] !== '') {
            $dup = PayAccountModel::where('account_no', $data['account_no'])
                ->where('id', '<>', $id)->find();
            if ($dup) {
                return json(r_error('该账号已存在（' . $dup->account_name . '）', 400));
            }
        }

        if ($id) {
            PayAccountModel::where('id', $id)->update($data);
            $this->log('收款账户', '更新', "#{$id} {$data['account_name']}");
        } else {
            $data['create_by'] = (int)$this->request->userId;
            $m  = PayAccountModel::create($data);
            $id = (int)$m->id;
            $this->log('收款账户', '新增', "#{$id} {$data['account_name']}");
        }
        if ($data['is_default'] === 1) {
            $this->setDefaultInternal($id);
        }
        return json(r_success(['id' => $id], '保存成功'));
    }

    /** 设为默认 */
    public function setDefault()
    {
        $this->requireAnyRole(['admin', 'finance']);
        $id = (int)$this->request->post('id', 0);
        $acc = PayAccountModel::find($id);
        if (!$acc) {
            return json(r_error('账户不存在', 404));
        }
        if ((int)$acc->status !== 1) {
            return json(r_error('停用的账户不能设为默认', 400));
        }
        $this->setDefaultInternal($id);
        $this->log('收款账户', '设为默认', "#{$id} {$acc->account_name}");
        return json(r_success([], '已设为默认账户'));
    }

    /** 删除 */
    public function delete()
    {
        $this->requireAnyRole(['admin', 'finance']);
        $id = (int)$this->request->post('id', 0);
        if (!$id) {
            return json(r_error('缺少 id', 400));
        }
        $r = $this->deleteOne($id);
        return $r['ok'] ? json(r_success([], '删除成功')) : json(r_error($r['msg'], 400));
    }

    /** 批量删除同样限制为管理员/财务 */
    public function batchDelete()
    {
        $this->requireAnyRole(['admin', 'finance']);
        return parent::batchDelete();
    }

    protected function deleteOne(int $id): array
    {
        $acc = PayAccountModel::find($id);
        if (!$acc) {
            return ['ok' => false, 'msg' => '账户不存在'];
        }
        // 已被费用引用的账户不允许直接删除（避免历史数据失去归属）
        $used = FeeModel::where('pay_account_id', $id)->count();
        if ($used > 0) {
            return ['ok' => false, 'msg' => "该账户已被 {$used} 条费用记录引用，不能删除（可改为停用）"];
        }
        // v2.52：删除前快照进回收站
        try {
            \app\service\RecycleBinService::stash(
                (int)$this->request->userId,
                'pay_account',
                ((string)($acc['account_name'] ?? '') ?: ("#" . $id)),
                $acc->toArray()
            );
        } catch (\Throwable $e) {
            // 快照失败不阻断删除
        }
        PayAccountModel::destroy($id);
        return ['ok' => true, 'msg' => '删除成功'];
    }

    /** 把指定账户置为唯一默认 */
    private function setDefaultInternal(int $id): void
    {
        PayAccountModel::where('id', '<>', $id)->update(['is_default' => 0]);
        PayAccountModel::where('id', $id)->update(['is_default' => 1]);
    }
}
