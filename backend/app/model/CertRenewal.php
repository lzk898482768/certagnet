<?php
namespace app\model;

use think\Model;

class CertRenewal extends Model
{
    protected $name = 'cert_renewal';
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = false;

    public function certificate()
    {
        return $this->belongsTo(Certificate::class, 'cert_id');
    }
}
