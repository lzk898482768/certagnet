<?php
namespace app\service\push;

use think\facade\Db;

/**
 * 微信小程序订阅消息驱动（v2.99.24）
 *
 * 走微信服务端接口：
 *  - 获取 access_token：GET /cgi-bin/token?grant_type=client_credential
 *  - 发送订阅消息：POST /cgi-bin/message/subscribe/send
 *
 * 配置（PushChannel.config_json）：
 *  - appid / appsecret：小程序 AppID / AppSecret（未配置时模拟发送）
 *  - template_id：订阅消息模板 ID
 *  - page：点击消息跳转的小程序页面（可选，默认工作台）
 *  - data：JSON 字段映射，如 {"thing1":"{content}","time2":"{time}"}
 *          占位符：{content} 正文 / {title} 首行标题 / {time} HH:mm / {date} Y-m-d
 *          未配置时默认 {"thing1":"{content}"}
 *
 * 接收人：
 *  - receiver['openid']：微信 openid（优先）
 *  - receiver['user_id']：从 sys_miniapp_binding 反查 openid
 */
class MiniAppTemplateDriver implements PushDriverInterface
{
    protected $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function send(array $receiver, string $content): array
    {
        $appid  = trim((string)($this->config['appid'] ?? ''));
        $secret = trim((string)($this->config['appsecret'] ?? ''));
        if ($appid === '' || $secret === '') {
            return [
                'status'    => 1,
                'msg'       => '未配置小程序订阅消息（AppID/AppSecret），已记录(模拟发送)',
                'simulated' => true,
                'response'  => '',
            ];
        }

        $openid = $this->resolveOpenid($receiver);
        if ($openid === '') {
            return [
                'status'    => 2,
                'msg'       => '缺少接收人 openid（用户未绑定微信小程序）',
                'simulated' => false,
                'response'  => '',
            ];
        }

        try {
            $token = $this->getAccessToken($appid, $secret);
            if ($token === '') {
                return [
                    'status'    => 2,
                    'msg'       => '获取小程序 access_token 失败（请检查 AppID/AppSecret 与服务器 IP 白名单）',
                    'simulated' => false,
                    'response'  => '',
                ];
            }

            $templateId = trim((string)($this->config['template_id'] ?? ''));
            if ($templateId === '') {
                return [
                    'status'    => 2,
                    'msg'       => '缺少订阅消息模板 ID（template_id），请在「移动端配置」中配置后重试',
                    'simulated' => false,
                    'response'  => '',
                ];
            }

            $url  = 'https://api.weixin.qq.com/cgi-bin/message/subscribe/send?access_token=' . urlencode($token);
            $body = json_encode([
                'touser'      => $openid,
                'template_id' => $templateId,
                'page'        => trim((string)($this->config['page'] ?? 'pages/dashboard/dashboard')),
                'data'        => $this->renderData($content),
                'miniprogram_state' => 'formal',
            ], JSON_UNESCAPED_UNICODE);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
            ]);
            $resp = curl_exec($ch);
            $err  = curl_error($ch);
            curl_close($ch);
            $resp = (string)$resp;

            if ($err) {
                return ['status' => 2, 'msg' => '小程序订阅消息推送失败: ' . $err, 'simulated' => false, 'response' => ''];
            }
            $arr = json_decode($resp, true);
            if (is_array($arr)) {
                $errcode = (int)($arr['errcode'] ?? 0);
                if ($errcode !== 0) {
                    $msg = '小程序返回错误 errcode=' . $errcode . ' ' . ($arr['errmsg'] ?? '');
                    if ($errcode === 43101) {
                        $msg = '用户未订阅该消息模板（errcode=43101），请在小程序端引导用户授权订阅';
                    } elseif ($errcode === 40001) {
                        $msg = 'access_token 无效或已过期，请检查 AppSecret 配置';
                    }
                    return [
                        'status'    => 2,
                        'msg'       => $msg,
                        'simulated' => false,
                        'response'  => substr($resp, 0, 300),
                    ];
                }
            }
            return [
                'status'    => 1,
                'msg'       => '小程序订阅消息已推送: ' . substr($resp, 0, 80),
                'simulated' => false,
                'response'  => substr($resp, 0, 300),
            ];
        } catch (\Throwable $e) {
            return [
                'status'    => 2,
                'msg'       => '小程序订阅消息推送异常: ' . $e->getMessage(),
                'simulated' => false,
                'response'  => '',
            ];
        }
    }

    protected function resolveOpenid(array $receiver): string
    {
        $openid = trim((string)($receiver['openid'] ?? ''));
        if ($openid !== '') {
            return $openid;
        }
        $userId = (int)($receiver['user_id'] ?? 0);
        if ($userId > 0) {
            try {
                $row = Db::name('sys_miniapp_binding')
                    ->where('user_id', $userId)
                    ->order('id', 'desc')
                    ->find();
                return trim((string)($row['openid'] ?? ''));
            } catch (\Throwable $e) {
                return '';
            }
        }
        return '';
    }

    protected function renderData(string $content): array
    {
        $raw = $this->config['data'] ?? '';
        if (is_array($raw)) {
            $map = $raw;
        } else {
            $decoded = json_decode((string)$raw, true);
            $map = is_array($decoded) ? $decoded : [];
        }
        if (!$map) {
            $map = ['thing1' => '{content}'];
        }

        $lines = preg_split('/\r\n|\r|\n/', $content);
        $title = mb_substr(trim((string)($lines[0] ?? '')), 0, 20);
        $now = time();
        $vars = [
            '{content}' => mb_substr($content, 0, 200),
            '{title}'   => $title !== '' ? $title : '证照到期提醒',
            '{time}'    => date('H:i', $now),
            '{date}'    => date('Y-m-d', $now),
        ];

        $data = [];
        foreach ($map as $field => $template) {
            $field = (string)$field;
            $value = (string)($template ?? '');
            foreach ($vars as $k => $v) {
                $value = str_replace($k, $v, $value);
            }
            // 微信「thing」类字段限 20 个字符，其余字段也做长度保护
            $data[$field] = ['value' => mb_substr($value, 0, str_starts_with($field, 'thing') ? 20 : 50)];
        }
        return $data;
    }

    protected function getAccessToken(string $appid, string $secret): string
    {
        $url = "https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid={$appid}&secret={$secret}";
        $ctx = stream_context_create(['http' => ['timeout' => 8]]);
        $resp = @file_get_contents($url, false, $ctx);
        if (!$resp) {
            return '';
        }
        $arr = json_decode($resp, true);
        return $arr['access_token'] ?? '';
    }
}