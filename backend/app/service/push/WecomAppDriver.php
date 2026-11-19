<?php
namespace app\service\push;

use app\service\WecomMessageService;

/**
 * 企业微信「应用消息」驱动
 *
 * 与 WecomDriver（群机器人 Webhook）不同，本驱动走应用消息接口，
 * 需要接收人携带企微 userid（receiver['wecom']）。
 */
class WecomAppDriver implements PushDriverInterface
{
    protected $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function send(array $receiver, string $content): array
    {
        $touser = $receiver['wecom'] ?? ($receiver['userid'] ?? '');
        // 兼容多种分隔符（组织架构多选会存成竖线分隔），并归一为企微要求的格式
        if (is_string($touser) && $touser !== '' && $touser !== '@all') {
            $parts  = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,;|]+/', $touser)))));
            $touser = implode('|', $parts);
        }
        if (empty($touser)) {
            return [
                'status'    => 2,
                'msg'       => '企微应用消息缺少接收人(userid)，请在接收人管理中填写企微账号或绑定企微',
                'simulated' => false,
                'response'  => '',
            ];
        }
        return WecomMessageService::send($touser, '', '', $content, 'text');
    }
}
