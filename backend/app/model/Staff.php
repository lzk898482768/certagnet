<?php
namespace app\model;

use think\Model;

class Staff extends Model
{
    protected $name = 'staff';
    protected $autoWriteTimestamp = true;

    public function getStatusTextAttr($value, $data): string
    {
        return ($data['status'] ?? 1) == 1 ? '在职' : '离职';
    }

    public function certificates()
    {
        return $this->hasMany(Certificate::class, 'staff_id');
    }

    public function companies()
    {
        return $this->belongsToMany(ClientCompany::class, 'staff_company_rel', 'company_id', 'staff_id');
    }
}
