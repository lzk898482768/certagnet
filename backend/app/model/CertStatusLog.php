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

    /**
     * 关联借调记录（biz_type=secondment 时有效）。
     * 一并预载持证人员与借调企业，供状态变更日志列表直接展示
     * 人员姓名、借调企业、借调起止时间，避免前端只看到 #ID。
     */
    public function secondment()
    {
        return $this->belongsTo(CertSecondment::class, 'secondment_id');
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
