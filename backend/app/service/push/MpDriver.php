<?php
namespace app\service\push;

/**
 * 微信公众号模板消息驱动（需 appid/secret/openid）
 *
 * 旧实现把「获取 token 失败 / 接口返回 errcode / 异常」一律判为成功，
 * 导致公众号渠道从未真正发出，日志却全是成功。这里按 errcode 判定。
 */
class MpDriver implements PushDriverInterface
{
    protected $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function send(array $receiver, string $content): array
    {
        $appid  = $this->config['appid'] ?? '';
        $secret = $this->config['appsecret'] ?? '';
        if (empty($appid) || empty($secret)) {
            return [
                'status'    => 1,
                'msg'       => '未配置公众号，已记录(模拟发送)',
                'simulated' => true,
                'response'  => '',
            ];
        }
        if (empty($receiver['openid'])) {
            return [
                'status'    => 2,
                'msg'       => '缺少接收人 openid（公众号需关注者的 openid）',
                'simulated' => false,
                'response'  => '',
            ];
        }
        try {
            $token = $this->getAccessToken($appid, $secret);
            if (!$token) {
                return [
                    'status'    => 2,
                    'msg'       => '获取 access_token 失败（请检查 AppID/AppSecret 与服务器 IP 白名单）',
                    'simulated' => false,
                    'response'  => '',
                ];
            }
            $url  = 'https://api.weixin.qq.com/cgi-bin/message/template/send?access_token=' . $token;
            $body = json_encode([
                'touser'      => $receiver['openid'],
                'template_id' => $this->config['template_id'] ?? '',
                'data'        => ['content' => ['value' => $content]],
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
                return ['status' => 2, 'msg' => '公众号推送失败: ' . $err, 'simulated' => false, 'response' => ''];
            }
            $arr = json_decode($resp, true);
            if (is_array($arr)) {
                $errcode = (int)($arr['errcode'] ?? 0);
                if ($errcode !== 0) {
                    return [
                        'status'    => 2,
                        'msg'       => '公众号返回错误 errcode=' . $errcode . ' ' . ($arr['errmsg'] ?? ''),
                        'simulated' => false,
                        'response'  => substr($resp, 0, 300),
                    ];
                }
            }
            return [
                'status'    => 1,
                'msg'       => '公众号已推送: ' . substr($resp, 0, 80),
                'simulated' => false,
                'response'  => substr($resp, 0, 300),
            ];
        } catch (\Throwable $e) {
            return [
                'status'    => 2,
                'msg'       => '公众号推送异常: ' . $e->getMessage(),
                'simulated' => false,
                'response'  => '',
            ];
        }
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
