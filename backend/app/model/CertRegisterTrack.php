<?php
namespace app\model;

use think\Model;

/**
 * 建造师注册轨迹
 * 记录一本证书（主要是建造师类）历次的注册单位、注册类型与时间
 */
class CertRegisterTrack extends Model
{
    protected $name = 'cert_register_track';
    protected $autoWriteTimestamp = true;

    protected $append = ['status_text'];

    public function certificate()
    {
        return $this->belongsTo(Certificate::class, 'cert_id');
    }

    public function company()
    {
        return $this->belongsTo(ClientCompany::class, 'company_id');
    }

    public function getStatusTextAttr($value, $data): string
    {
        return (int)($data['status'] ?? 1) === 1 ? '有效' : '已注销';
    }
}
