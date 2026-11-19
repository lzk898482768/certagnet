<?php
namespace app\service\push;

/**
 * uni-push 2.0 推送驱动（v2.99.24）
 *
 * 走 DCloud 官方服务端 REST API（https://api.uniapp.dev/api/v1/push），
 * 需要 DCloud 开发者中心的应用 AppID / AppKey / AppSecret（master secret）。
 * 接收人需携带 client 推送 cid（receiver['cid'] / receiver['clientid']）。
 *
 * 参考文档：
 *  - https://uniapp.dcloud.net.cn/unipush-v2.html
 *  - https://uniapp.dcloud.net.cn/unipush-v2-api.html
 */
class UniPushDriver implements PushDriverInterface
{
    protected $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function send(array $receiver, string $content): array
    {
        $appid  = trim((string)($this->config['appid'] ?? ''));
        $appkey = trim((string)($this->config['appkey'] ?? ''));
        $secret = trim((string)($this->config['appsecret'] ?? ''));
        if ($appid === '' || $appkey === '' || $secret === '') {
            return [
                'status'    => 1,
                'msg'       => '未配置 uni-push（AppID/AppKey/AppSecret），已记录(模拟发送)',
                'simulated' => true,
                'response'  => '',
            ];
        }

        $cid = (string)($receiver['cid'] ?? ($receiver['clientid'] ?? ''));
        if ($cid === '') {
            return [
                'status'    => 2,
                'msg'       => '缺少接收人 cid（uni-push 客户端推送标识），请在 App 端初始化 uni-push 后重试',
                'simulated' => false,
                'response'  => '',
            ];
        }

        try {
            $title = $this->titleOf($content);
            $payload = [];
            $url = h5_base_url();
            if ($url !== '') {
                $payload['url'] = $url;
            }
            $body = json_encode([
                'cid'     => $cid,
                'title'   => $title,
                'content' => $content,
                'payload' => $payload,
            ], JSON_UNESCAPED_UNICODE);

            $ch = curl_init('https://api.uniapp.dev/api/v1/push');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'appid: ' . $appid,
                    'appkey: ' . $appkey,
                    'appsecret: ' . $secret,
                ],
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
            ]);
            $resp = curl_exec($ch);
            $err  = curl_error($ch);
            curl_close($ch);
            $resp = (string)$resp;

            if ($err) {
                return ['status' => 2, 'msg' => 'uni-push 推送失败: ' . $err, 'simulated' => false, 'response' => ''];
            }
            $arr = json_decode($resp, true);
            if (is_array($arr)) {
                $errCode = (int)($arr['err_code'] ?? ($arr['code'] ?? 0));
                if ($errCode !== 0) {
                    return [
                        'status'    => 2,
                        'msg'       => 'uni-push 返回错误 ' . ($arr['err_code'] ?? '') . ' ' . ($arr['err_msg'] ?? $resp),
                        'simulated' => false,
                        'response'  => substr($resp, 0, 300),
                    ];
                }
            }
            return [
                'status'    => 1,
                'msg'       => 'uni-push 已推送: ' . substr($resp, 0, 80),
                'simulated' => false,
                'response'  => substr($resp, 0, 300),
            ];
        } catch (\Throwable $e) {
            return [
                'status'    => 2,
                'msg'       => 'uni-push 推送异常: ' . $e->getMessage(),
                'simulated' => false,
                'response'  => '',
            ];
        }
    }

    /** 取内容首行作为通知标题（uni-push 标题限 50 字符） */
    protected function titleOf(string $content): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $title = trim((string)($lines[0] ?? ''));
        if ($title === '') {
            $title = '证照到期提醒';
        }
        return mb_substr($title, 0, 50);
    }
}