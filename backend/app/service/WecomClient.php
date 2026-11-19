<?php
namespace app\service;

use think\facade\Cache;

/**
 * 企业微信底层客户端（登录 / 通讯录同步 / 应用消息 共用）
 *
 * 关键能力：
 *  - getAccessToken：corpid + corpsecret → access_token（缓存 7200s，提前 5 分钟失效）
 *  - httpGet / httpPost：带超时与强制 TLS 证书校验的 JSON 调用
 *  - isDev：DEV CorpID + 显式开发模式 → 离线沙箱，不发起远程请求
 *
 * 注意：本类只负责「拿到 token + 发请求」，不处理业务落库。
 */
class WecomClient
{
    public const WECOM_API = 'https://qyapi.weixin.qq.com/cgi-bin';

    public const DEV_CORP = 'ww_dev_corp_001';

    /** 从 system_config 读取企微配置（统一布尔化） */
    public static function loadConfig(): array
    {
        $rows = \app\model\SystemConfig::column('config_value', 'config_key');
        return [
            'wecom_enabled'      => ($rows['wecom_enabled'] ?? '0') === '1',
            'wecom_corp_id'      => $rows['wecom_corp_id']     ?? '',
            'wecom_agent_id'     => $rows['wecom_agent_id']    ?? '',
            'wecom_app_secret'   => $rows['wecom_app_secret']  ?? '',
            'wecom_redirect_uri' => $rows['wecom_redirect_uri'] ?? '',
            'wecom_auto_create'  => ($rows['wecom_auto_create'] ?? '0') === '1',
            'wecom_default_role' => $rows['wecom_default_role'] ?? '1',
            'wecom_approval_template_id' => $rows['wecom_approval_template_id'] ?? '',
        ];
    }

    /**
     * 是否处于离线沙箱模式（不连真实企微）
     *
     * v2.40：仅凭 CorpID 以 DEV_ 开头即可跳过真实校验过于危险——
     * 生产环境一旦把 CorpID 填成 DEV_ 或默认值，任何人都能伪造 code 登录。
     * 现要求同时满足：① CorpID 是 DEV 沙箱值；② 显式开启开发模式
     * （APP_ENV=dev 或 WECOM_DEV_MODE=1）。
     */
    public static function isDev(array $cfg): bool
    {
        $corp = (string)($cfg['wecom_corp_id'] ?? '');
        $looksDev = str_starts_with($corp, 'DEV_') || $corp === self::DEV_CORP;
        if (!$looksDev) {
            return false;
        }
        $appEnv = strtolower((string)env('APP_ENV', ''));
        $devFlag = (string)env('WECOM_DEV_MODE', '');
        return $appEnv === 'dev' || $appEnv === 'development' || $devFlag === '1';
    }

    /**
     * 获取 access_token（带缓存）
     * @throws \Exception 获取失败时
     */
    public static function getAccessToken(array $cfg, bool $force = false): string
    {
        if (self::isDev($cfg)) {
            return 'DEV_OFFLINE_TOKEN';
        }
        $cacheKey = 'wecom_access_token_' . md5(($cfg['wecom_corp_id'] ?? '') . '|' . ($cfg['wecom_agent_id'] ?? ''));
        if (!$force) {
            $cached = Cache::get($cacheKey);
            if ($cached) {
                return $cached;
            }
        }
        $url = self::WECOM_API . '/gettoken?corpid=' . urlencode($cfg['wecom_corp_id'])
             . '&corpsecret=' . urlencode($cfg['wecom_app_secret']);
        $resp = self::httpGet($url);
        if (($resp['errcode'] ?? -1) !== 0) {
            throw new \Exception('获取 access_token 失败: ' . ($resp['errmsg'] ?? 'unknown'));
        }
        $expires = max(60, (int)($resp['expires_in'] ?? 7200) - 300);
        Cache::set($cacheKey, $resp['access_token'], $expires);
        return $resp['access_token'];
    }

    public static function httpGet(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            // 企微 API 使用受信任证书；生产环境必须校验，防止中间人窃取 Secret。
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = (string)curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err) {
            throw new \Exception('企微接口网络错误: ' . $err);
        }
        $arr = json_decode($raw, true);
        return is_array($arr) ? $arr : ['errcode' => -1, 'errmsg' => 'non-json: ' . substr($raw, 0, 200)];
    }

    public static function httpPost(string $url, array $data): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($data, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = (string)curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err) {
            throw new \Exception('企微接口网络错误: ' . $err);
        }
        $arr = json_decode($raw, true);
        return is_array($arr) ? $arr : ['errcode' => -1, 'errmsg' => 'non-json: ' . substr($raw, 0, 200)];
    }
}
