<?php
namespace app\service\push;

/**
 * 短信驱动（未配置网关时模拟发送，可对接阿里云/腾讯云）
 *
 * 返回结构统一：['status'=>1成功|2失败, 'msg'=>'', 'simulated'=>bool, 'response'=>'原始响应']
 * 说明：未配置网关地址时视为「模拟发送」（status=1 但 simulated=true），
 *      便于在没有短信服务商账号时仍能走通整条推送链路并留下日志。
 */
class SmsDriver implements PushDriverInterface
{
    protected $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function send(array $receiver, string $content): array
    {
        if (empty($receiver['phone'])) {
            return ['status' => 2, 'msg' => '缺少接收手机号', 'simulated' => false, 'response' => ''];
        }
        // 已配置网关地址则真实调用，否则模拟
        if (!empty($this->config['gateway_url'])) {
            return $this->httpPost($this->config['gateway_url'], [
                'phone'   => $receiver['phone'],
                'sign'    => $this->config['sign_name'] ?? '',
                'tpl'     => $this->config['template_code'] ?? '',
                'content' => $content,
            ]);
        }
        return [
            'status'    => 1,
            'msg'       => '未配置短信网关，已记录(模拟发送)',
            'simulated' => true,
            'response'  => '',
        ];
    }

    protected function httpPost(string $url, array $data): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            return ['status' => 2, 'msg' => '短信网关请求失败: ' . $err, 'simulated' => false, 'response' => ''];
        }
        $resp = (string)$resp;
        // HTTP 4xx/5xx 视为失败，避免「网关拒绝但仍标记成功」
        if ($code >= 400 || $code === 0) {
            return [
                'status'    => 2,
                'msg'       => '短信网关返回异常 HTTP ' . $code,
                'simulated' => false,
                'response'  => substr($resp, 0, 300),
            ];
        }
        // 常见网关以 code/result/success 字段标识结果
        $arr = json_decode($resp, true);
        if (is_array($arr)) {
            $bizFail = false;
            foreach (['code', 'result', 'status', 'success'] as $k) {
                if (!isset($arr[$k])) {
                    continue;
                }
                $v = $arr[$k];
                if (($k === 'success' && !$v) || (is_numeric($v) && (int)$v !== 0 && (int)$v !== 200)) {
                    $bizFail = true;
                    break;
                }
            }
            if ($bizFail) {
                return [
                    'status'    => 2,
                    'msg'       => '短信网关返回失败: ' . ($arr['msg'] ?? $arr['message'] ?? substr($resp, 0, 100)),
                    'simulated' => false,
                    'response'  => substr($resp, 0, 300),
                ];
            }
        }
        return [
            'status'    => 1,
            'msg'       => '已提交网关 HTTP ' . $code,
            'simulated' => false,
            'response'  => substr($resp, 0, 300),
        ];
    }
}
