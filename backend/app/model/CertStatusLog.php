<?php
namespace app\model;

use think\Model;

class CertStatusLog extends Model
{
    protected $name = 'cert_status_log';
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = false;

    public function certificate()
    {
        return $this->belongsTo(Certificate::class, 'cert_id');
    }

    public function getStatusTextAttr($value, $data): string
    {
        // v2.76：借调状态与证书状态共用一张日志表，状态码含义不同
        if (($data['biz_type'] ?? 'cert') === 'secondment') {
            return \app\service\CertStatusLogService::secondmentStatusText((int)$data['new_status']);
        }
        $map = [1 => '正常', 0 => '已过期', 2 => '挂失', 3 => '注销'];
        return $map[$data['new_status']] ?? '未知';
    }
}
