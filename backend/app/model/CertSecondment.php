<?php
namespace app\model;

use think\Model;

/**
 * 证书借调记录：企业借调人员证书，带期限、合同与费用
 * 状态：1借调中 0已归还 2已到期
 */
class CertSecondment extends Model
{
    protected $name = 'cert_secondment';
    protected $autoWriteTimestamp = true;

    // 让访问器随 toArray() 输出（含被 other models 预载嵌套时的序列化）
    protected $append = ['status_text', 'fee_status_text', 'remain_days', 'direction_text'];

    public function getDirectionTextAttr($value, $data): string
    {
        $map = [1 => '借出', 2 => '借入'];
        return $map[$data['direction'] ?? 1] ?? '借出';
    }

    public function certificate()
    {
        return $this->belongsTo(Certificate::class, 'cert_id');
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }

    public function company()
    {
        return $this->belongsTo(ClientCompany::class, 'company_id');
    }

    public function contract()
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function getStatusTextAttr($value, $data): string
    {
        $map = [1 => '借调中', 0 => '已归还', 2 => '已到期'];
        return $map[$data['status']] ?? '未知';
    }

    public function getFeeStatusTextAttr($value, $data): string
    {
        return empty($data['fee_status']) ? '未结算' : '已结算';
    }

    /** 距离借调到期的天数（负数=已过期） */
    public function getRemainDaysAttr($value, $data): ?int
    {
        if (empty($data['end_date'])) {
            return null;
        }
        $end = strtotime($data['end_date']);
        if ($end === false) {
            return null;
        }
        return (int)ceil(($end - strtotime(date('Y-m-d'))) / 86400);
    }
}
