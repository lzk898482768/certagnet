<?php
namespace app\service;

use app\model\PushChannel;
use app\service\push\SmsDriver;
use app\service\push\EmailDriver;
use app\service\push\WecomDriver;
use app\service\push\WecomAppDriver;
use app\service\push\MpDriver;
use app\service\push\UniPushDriver;
use app\service\push\MiniAppTemplateDriver;

/**
 * 多渠道统一推送调度
 *
 * 统一返回：
 *   ['status'=>1成功|2失败, 'msg'=>'', 'simulated'=>bool, 'response'=>'原始响应', 'cost_ms'=>int]
 * simulated=true 表示渠道未配置（模拟发送），链路已跑通但不会真正到达用户。
 */
class PushService
{
    public static function send(PushChannel $channel, array $receiver, string $content): array
    {
        $t0     = microtime(true);
        $config = $channel->config_json ?? [];
        if (!is_array($config)) {
            $config = [];
        }
        switch ($channel->channel_type) {
            case 'sms':
                $driver = new SmsDriver($config);
                break;
            case 'email':
                $driver = new EmailDriver($config);
                break;
            case 'wecom':
                $driver = new WecomDriver($config);
                break;
            case 'wecom_app':
                $driver = new WecomAppDriver($config);
                break;
            case 'mp':
                $driver = new MpDriver($config);
                break;
            case 'unipush':
                $driver = new UniPushDriver($config);
                break;
            case 'miniapp':
                $driver = new MiniAppTemplateDriver($config);
                break;
            default:
                return self::pack(['status' => 2, 'msg' => '未知推送渠道: ' . $channel->channel_type], $t0);
        }
        try {
            $res = $driver->send($receiver, $content);
        } catch (\Throwable $e) {
            $res = ['status' => 2, 'msg' => '推送异常: ' . $e->getMessage()];
        }
        return self::pack($res, $t0);
    }

    /** 补全返回结构的缺省字段 */
    protected static function pack(array $res, float $t0): array
    {
        $res['status']    = (int)($res['status'] ?? 2);
        $res['msg']       = (string)($res['msg'] ?? '');
        $res['simulated'] = !empty($res['simulated']);
        $res['response']  = (string)($res['response'] ?? '');
        $res['cost_ms']   = (int)round((microtime(true) - $t0) * 1000);
        return $res;
    }

    /** 渠道类型文案 */
    public static function typeText(string $type): string
    {
        $map = [
            'sms' => '短信', 'email' => '邮件', 'wecom' => '企微群机器人',
            'wecom_app' => '企微应用消息', 'mp' => '公众号',
            'unipush' => 'uni-push', 'miniapp' => '小程序订阅消息',
        ];
        return $map[$type] ?? $type;
    }

    /**
     * 渠道配置完整性检查（用于「推送体检」）
     * @return array ['configured'=>bool, 'missing'=>['字段名',...]]
     */
    public static function checkConfig(string $type, array $config): array
    {
        $need = [
            'sms'   => ['gateway_url'],
            'email' => ['smtp_host', 'username', 'password'],
            'wecom' => ['webhook_url'],
            'wecom_app' => [], // 复用系统「企微登录配置」中的 CorpID/AgentID/Secret
            'mp'    => ['appid', 'appsecret'],
            'unipush' => ['appid', 'appkey', 'appsecret'],
            'miniapp' => ['appid', 'appsecret', 'template_id'],
        ];
        $label = [
            'gateway_url' => '网关地址', 'smtp_host' => 'SMTP服务器', 'username' => '发件账号',
            'password'    => '授权密码', 'webhook_url' => 'Webhook地址', 'appid' => 'AppID',
            'appsecret'   => 'AppSecret', 'template_id' => '模板ID',
            'appkey'      => 'AppKey',
        ];
        $missing = [];
        foreach ($need[$type] ?? [] as $k) {
            if (empty($config[$k])) {
                $missing[] = $label[$k] ?? $k;
            }
        }
        return ['configured' => empty($missing), 'missing' => $missing];
    }
}
