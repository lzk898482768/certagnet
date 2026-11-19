<?php
namespace app\service;

/**
 * 企业微信「应用消息」发送（message/send）
 *
 * 与群机器人（Webhook）不同，应用消息走 agentid + access_token，
 * 可精准推送给指定 userid / 部门 / 标签，适合到期提醒、审批等场景。
 * DEV 模式：不连企微，返回模拟成功。
 */
class WecomMessageService
{
    /**
     * 发送应用消息
     * @param string $touser  userid 列表（竖线分隔，或 @all）
     * @param string $toparty 部门 id 列表（竖线分隔）
     * @param string $totag   标签 id 列表（竖线分隔）
     * @param string $content 文本内容
     * @param string $msgtype text / markdown
     * @return array ['status'=>1|2,'msg'=>,'simulated'=>bool,'response'=>]
     */
    public static function send(string $touser, string $toparty, string $totag, string $content, string $msgtype = 'text'): array
    {
        $cfg = WecomClient::loadConfig();
        if (WecomClient::isDev($cfg)) {
            return [
                'status'    => 1,
                'msg'       => 'DEV 模式：应用消息模拟发送成功（接收人 ' . ($touser ?: '无') . '）',
                'simulated' => true,
                'response'  => '',
            ];
        }
        if (empty($cfg['wecom_agent_id'])) {
            return ['status' => 2, 'msg' => '未配置企微 AgentID，无法发送应用消息', 'simulated' => false, 'response' => ''];
        }
        $token = WecomClient::getAccessToken($cfg);
        $body  = [
            'touser'   => $touser,
            'toparty'  => $toparty,
            'totag'    => $totag,
            'msgtype'  => $msgtype,
            'agentid'  => (int)$cfg['wecom_agent_id'],
            $msgtype   => ['content' => $content],
        ];
        $resp = WecomClient::httpPost(
            WecomClient::WECOM_API . '/message/send?access_token=' . urlencode($token),
            $body
        );
        if (($resp['errcode'] ?? -1) !== 0) {
            return [
                'status'    => 2,
                'msg'       => '企微应用消息发送失败: ' . ($resp['errmsg'] ?? 'unknown'),
                'simulated' => false,
                'response'  => substr(json_encode($resp), 0, 300),
            ];
        }
        return [
            'status'    => 1,
            'msg'       => '企微应用消息已发送',
            'simulated' => false,
            'response'  => substr(json_encode($resp), 0, 300),
        ];
    }
}
