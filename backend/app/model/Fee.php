<?php
namespace app\model;

use think\Model;

/**
 * 费用台账（独立模块）：记录各类收支，可关联借调/合同或其他来源
 * fee_type: income 收入 / expense 支出
 * settle_status: 0 未结算 / 1 已结算
 */
class Fee extends Model
{
    protected $name = 'fee';
    protected $autoWriteTimestamp = true;

    protected $append = ['fee_type_text', 'settle_text'];

    public function company()
    {
        return $this->belongsTo(ClientCompany::class, 'company_id');
    }

    public function getFeeTypeTextAttr($value, $data): string
    {
        return ($data['fee_type'] ?? 'income') === 'expense' ? '支出' : '收入';
    }

    public function getSettleTextAttr($value, $data): string
    {
        return empty($data['settle_status']) ? '未结算' : '已结算';
    }
}
