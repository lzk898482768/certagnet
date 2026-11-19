<?php
namespace app\model;

use think\Model;

class StaffCompanyRel extends Model
{
    protected $name = 'staff_company_rel';
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = false;

    public function staff()
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }

    public function company()
    {
        return $this->belongsTo(ClientCompany::class, 'company_id');
    }
}
