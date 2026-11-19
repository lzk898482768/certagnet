<?php
namespace app\service\push;

/**
 * 企业微信机器人 Webhook 驱动
 *
 * 关键点：企微接口 HTTP 200 也可能返回业务失败（errcode != 0，如 invalid webhook url），
 * 旧实现只看 curl_error 就判定成功，导致「日志显示成功但实际没收到」。
 * 这里解析 errcode，非 0 一律判失败。
 */
class WecomDriver implements PushDriverInterface
{
    protected $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function send(array $receiver, string $content): array
    {
        $url = $this->config['webhook_url'] ?? '';
        if (empty($url)) {
            return [
                'status'    => 1,
                'msg'       => '未配置企微Webhook，已记录(模拟发送)',
                'simulated' => true,
                'response'  => '',
            ];
        }
        $payload = json_encode([
            'msgtype'  => 'markdown',
            'markdown' => ['content' => $content],
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $resp = (string)$resp;
        if ($err) {
            return ['status' => 2, 'msg' => '企微推送失败: ' . $err, 'simulated' => false, 'response' => ''];
        }
        $arr = json_decode($resp, true);
        if (is_array($arr)) {
            $errcode = (int)($arr['errcode'] ?? 0);
            if ($errcode !== 0) {
                return [
                    'status'    => 2,
                    'msg'       => '企微返回错误 errcode=' . $errcode . ' ' . ($arr['errmsg'] ?? ''),
                    'simulated' => false,
                    'response'  => substr($resp, 0, 300),
                ];
            }
            return [
                'status'    => 1,
                'msg'       => '企微已推送: ' . ($arr['errmsg'] ?? 'ok'),
                'simulated' => false,
                'response'  => substr($resp, 0, 300),
            ];
        }
        // 非 JSON 响应（代理拦截、返回 HTML 等）
        if ($code >= 400 || $code === 0) {
            return [
                'status'    => 2,
                'msg'       => '企微推送失败 HTTP ' . $code . '，响应非JSON：' . substr($resp, 0, 80),
                'simulated' => false,
                'response'  => substr($resp, 0, 300),
            ];
        }
        return [
            'status'    => 1,
            'msg'       => '企微已推送: ' . substr($resp, 0, 80),
            'simulated' => false,
            'response'  => substr($resp, 0, 300),
        ];
    }
}
