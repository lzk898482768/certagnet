<?php
namespace app\model;

use think\Model;

/**
 * 客户企业安许 / 资质台账（v2.43 河南平台采集，v2.93 起纳入到期提醒）
 */
class CompanyQualification extends Model
{
    protected $name = 'company_qualification';
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    public function company()
    {
        return $this->belongsTo(ClientCompany::class, 'company_id');
    }
}
