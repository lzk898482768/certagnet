<?php
namespace app\model;

use think\Model;

class CompanyReceiver extends Model
{
    protected $name = 'company_receiver';
    protected $autoWriteTimestamp = true;

    public function company()
    {
        return $this->belongsTo(ClientCompany::class, 'company_id');
    }

    public function getReceiverTypeTextAttr($value, $data): string
    {
        return ($data['receiver_type'] ?? '') == 'agent' ? '中介管理员' : '客户对接人';
    }
}
