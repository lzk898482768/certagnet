<?php
namespace app\model;

use think\Model;

class UserCompanyRel extends Model
{
    protected $name = 'user_company_rel';
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = false;

    public function company()
    {
        return $this->belongsTo(ClientCompany::class, 'company_id');
    }

    public function user()
    {
        return $this->belongsTo(SysUser::class, 'user_id');
    }
}
