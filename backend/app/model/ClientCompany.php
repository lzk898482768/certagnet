<?php
namespace app\model;

use think\Model;

class ClientCompany extends Model
{
    protected $name = 'client_company';
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    public function getStatusTextAttr($value, $data): string
    {
        return ($data['status'] ?? 1) == 1 ? '启用' : '停用';
    }

    public function certificates()
    {
        return $this->hasMany(Certificate::class, 'company_id');
    }

    public function staffs()
    {
        return $this->belongsToMany(Staff::class, 'staff_company_rel', 'staff_id', 'company_id');
    }
}
