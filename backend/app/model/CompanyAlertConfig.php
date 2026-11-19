<?php
namespace app\model;

use think\Model;

class CompanyAlertConfig extends Model
{
    protected $name = 'company_alert_config';
    protected $autoWriteTimestamp = true;

    public function company()
    {
        return $this->belongsTo(ClientCompany::class, 'company_id');
    }

    public function getAlertNodesAttr($value)
    {
        return $value ? json_decode($value, true) : [];
    }

    public function setAlertNodesAttr($value)
    {
        return is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value;
    }

    public function getAlertWaysAttr($value)
    {
        return $value ? json_decode($value, true) : [];
    }

    public function setAlertWaysAttr($value)
    {
        return is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value;
    }
}
