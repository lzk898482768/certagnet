<?php
namespace app\model;

use think\Model;

class Contract extends Model
{
    protected $name = 'contract';
    protected $autoWriteTimestamp = true;

    public function company()
    {
        return $this->belongsTo(ClientCompany::class, 'company_id');
    }

    // 关联借调记录（secondment_id=0 表示非借调产生的独立合同）
    public function secondment()
    {
        return $this->belongsTo(CertSecondment::class, 'secondment_id');
    }

    public function getStatusTextAttr($value, $data): string
    {
        return ($data['status'] ?? 1) == 1 ? '执行中' : '到期/终止';
    }
}
