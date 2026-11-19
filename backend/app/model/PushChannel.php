<?php
namespace app\model;

use think\Model;

class PushChannel extends Model
{
    protected $name = 'push_channel';
    protected $autoWriteTimestamp = true;

    public function getConfigJsonAttr($value)
    {
        return $value ? json_decode($value, true) : [];
    }

    public function setConfigJsonAttr($value)
    {
        return is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value;
    }

    public function getChannelTypeTextAttr($value, $data): string
    {
        $map = ['sms' => '短信', 'email' => '邮件', 'wecom' => '企微Webhook', 'wecom_app' => '企微应用消息', 'mp' => '微信公众号'];
        return $map[$data['channel_type']] ?? $data['channel_type'];
    }
}
