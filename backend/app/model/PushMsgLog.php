<?php
namespace app\model;

use think\Model;

class PushMsgLog extends Model
{
    protected $name = 'push_msg_log';
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = false;

    public function channel()
    {
        return $this->belongsTo(PushChannel::class, 'channel_id');
    }

    public function certificate()
    {
        return $this->belongsTo(Certificate::class, 'cert_id');
    }

    public function company()
    {
        return $this->belongsTo(ClientCompany::class, 'company_id');
    }

    public function getSendStatusTextAttr($value, $data): string
    {
        if ((int)($data['send_status'] ?? 0) === 1 && !empty($data['is_simulated'])) {
            return '模拟发送';
        }
        $map = [0 => '待发送', 1 => '成功', 2 => '失败'];
        return $map[$data['send_status']] ?? '未知';
    }

    /** 是否模拟发送（渠道未配置完整，仅写日志未真实外发） */
    public function getIsSimulatedAttr($value, $data): bool
    {
        return !empty($data['err_msg']) && str_starts_with((string)$data['err_msg'], '模拟发送');
    }

    /** 是否测试推送 */
    public function getIsTestTextAttr($value, $data): string
    {
        return !empty($data['is_test']) ? '测试' : '正式';
    }

    /** 渠道类型文案（渠道被删除时仍能显示） */
    public function getChannelTypeTextAttr($value, $data): string
    {
        $map = ['sms' => '短信', 'email' => '邮件', 'wecom' => '企微群机器人', 'wecom_app' => '企微应用消息', 'mp' => '微信公众号'];
        $t   = $data['channel_type'] ?? '';
        if ($t === '' && !empty($data['channel_id'])) {
            $ch = PushChannel::find($data['channel_id']);
            $t  = $ch ? $ch->channel_type : '';
        }
        return $map[$t] ?? ($t ?: '');
    }
}
