<?php
namespace app\model;

use think\Model;

class AlertLog extends Model
{
    protected $name = 'alert_log';
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = false;

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

    /** 企业证书提醒（alert_type=company_cert）关联的安许 / 资质记录 */
    public function companyQualification()
    {
        return $this->belongsTo(CompanyQualification::class, 'company_qualification_id');
    }
}
