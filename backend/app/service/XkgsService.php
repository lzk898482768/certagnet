<?php
namespace app\service;

use app\service\crypto\Sm2;
use think\facade\Cache;

/**
 * 国家能源局资质和信用信息系统 —— 行政许可公示（承装（修、试）电力设施许可证）采集
 *
 * 接口：POST https://zzxy.nea.gov.cn/public/login-service/login/xkgsNew
 *  - 公开查询，无需登录 token；请求头 Content-Type: x-www-form-urlencoded + Encrypt: true
 *  - 查询参数 JSON 经 SM2(cipherMode=1) 加密、前缀 "04" 后作为表单字段 param 提交
 *  - 响应 data 为明文记录数组（若未来返回密文，可再用 Sm2::decrypt 兜底）
 *
 * 仅做公开数据查询，不涉及登录态、不绕过验证码或访问控制。
 */
class XkgsService
{
    public const SOURCE     = '国家能源局资质和信用信息系统';
    public const SOURCE_KEY = 'nea_xkgs';
    public const API_URL    = 'https://zzxy.nea.gov.cn/public/login-service/login/xkgsNew';
    public const MIRROR_URL = 'https://zzzy.nea.gov.cn/public/login-service/login/xkgsNew';

    /** 平台公开加密公钥（与国家能源局公示页面一致） */
    public const PUBLIC_KEY = '0460fd9e5175ac0ad19a4aeb45461ecf5e0e9b3063a55d0de12c7dcd2db9a1c00380e19708e89ebcc67aa9e8c2ba90b83fa5cb928349e9ac70f71a8c7a1d7278dd';

    /** 查询缓存时长（秒） */
    private const CACHE_TTL = 600;

    /** 同一服务器最小请求间隔（秒），避免被平台限流 */
    private const MIN_INTERVAL = 2;

    /**
     * 按企业全称查询行政许可公示记录
     *
     * @return array{records:array,total:int,source:string,source_key:string,source_url:string,fetched_at:string,cached:bool}
     */
    public static function fetch(string $enterpriseName, bool $fresh = false): array
    {
        $name = trim($enterpriseName);
        if ($name === '') {
            throw new \RuntimeException('企业名称为空，无法查询承装修试许可证');
        }

        $key = 'nea_xkgs_' . md5($name);
        if (!$fresh) {
            $cached = Cache::get($key);
            if (is_array($cached)) {
                $cached['cached'] = true;
                return $cached;
            }
        }

        // 频控：同一服务器 2 秒内最多请求一次（不足则等待补足）
        $last = (int)Cache::get('nea_xkgs_last_fetch', 0);
        if ($last > 0) {
            $wait = self::MIN_INTERVAL - (time() - $last);
            if ($wait > 0) {
                usleep($wait * 1000000);
            }
        }
        Cache::set('nea_xkgs_last_fetch', time(), 60);

        $payload = json_encode(['entername' => $name], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new \RuntimeException('查询参数编码失败');
        }
        $param = '04' . Sm2::encrypt($payload, self::PUBLIC_KEY);

        $body = self::post($param);
        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new \RuntimeException('国家能源局接口返回格式异常，请稍后重试');
        }
        if ((string)($json['code'] ?? '') !== '200') {
            $msg = (string)($json['errorMessage'] ?? $json['successMsg'] ?? '平台返回失败');
            throw new \RuntimeException('国家能源局查询失败：' . $msg);
        }

        $records = self::normalizeRecords($json['data'] ?? []);
        $res = [
            'records'    => $records,
            'total'      => count($records),
            'source'     => self::SOURCE,
            'source_key' => self::SOURCE_KEY,
            'source_url' => 'https://zzxy.nea.gov.cn/',
            'fetched_at' => date('Y-m-d H:i:s'),
            'cached'     => false,
        ];
        Cache::set($key, $res, self::CACHE_TTL);
        return $res;
    }

    /**
     * 平台记录 → 企业证书台账行（与河南平台 items 同构，便于同一弹窗回填）
     *
     * @param array<int,array<string,mixed>> $records
     * @return array<int,array<string,mixed>>
     */
    public static function toItems(array $records): array
    {
        $items = [];
        foreach ($records as $r) {
            if (!is_array($r)) {
                continue;
            }
            $enterName = trim((string)($r['entername'] ?? ''));
            $licenceNo = trim((string)($r['licenceno'] ?? ''));
            if ($enterName === '' && $licenceNo === '') {
                continue;
            }

            $grades = [];
            foreach (['czgrade' => '承装', 'cxgrade' => '承修', 'csgrade' => '承试'] as $field => $label) {
                $g = trim((string)($r[$field] ?? ''));
                if ($g !== '' && $g !== '0') {
                    $grades[] = $label . self::gradeText($g);
                }
            }

            $items[] = [
                'q_type'          => 'qualification',
                'cert_name'       => '承装（修、试）电力设施许可证',
                'cert_level'      => implode(' / ', $grades),
                'cert_no'         => $licenceNo,
                'cert_scope'      => '承装（修、试）电力设施许可' . ($grades ? '（' . implode('、', $grades) . '）' : ''),
                'issue_org'       => trim((string)($r['acceptorgname'] ?? '')),
                'issue_date'      => self::date($r['licencedate'] ?? ''),
                'expire_date'     => self::date($r['licvalidend'] ?? ''),
                'remark'          => '许可状态：' . self::stateText((string)($r['licstate'] ?? '')) . '；来源：' . self::SOURCE,
                // 来源随条目携带，便于与河南平台结果混合导入时区分台账来源
                'source'          => self::SOURCE_KEY,
                'source_url'      => 'https://zzxy.nea.gov.cn/',
                // 以下字段仅用于采集弹窗展示，入库时会被忽略
                'entername'       => $enterName,
                'socialcreditno'  => trim((string)($r['socialcreditno'] ?? '')),
            ];
        }
        return $items;
    }

    /** 数据可能是数组、JSON 字符串或 SM2 密文 */
    private static function normalizeRecords($data): array
    {
        if (is_string($data)) {
            $data = self::decodeMaybe($data);
        }
        if (!is_array($data)) {
            return [];
        }
        $records = [];
        foreach ($data as $row) {
            if (is_string($row)) {
                $row = self::decodeMaybe($row);
            }
            if (is_array($row)) {
                $records[] = $row;
            }
        }
        return $records;
    }

    private static function decodeMaybe(string $raw)
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $json = json_decode($raw, true);
        if ($json !== null) {
            return $json;
        }
        return $raw;
    }

    private static function stateText(string $value): string
    {
        $map = ['1' => '有效', '2' => '过期', '3' => '注销', '4' => '撤销'];
        return $map[$value] ?? ($value !== '' ? $value : '未知');
    }

    private static function gradeText(string $value): string
    {
        $map = ['1' => '一级', '2' => '二级', '3' => '三级', '4' => '四级', '5' => '五级'];
        return $map[$value] ?? $value . '级';
    }

    private static function date($value): ?string
    {
        $s = trim((string)$value);
        if ($s === '') {
            return null;
        }
        $s = substr($s, 0, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
    }

    /** 发起查询（主域名失败时自动尝试镜像域名） */
    private static function post(string $param): string
    {
        $caFile = rtrim((string)app()->getRootPath(), '/\\') . DIRECTORY_SEPARATOR . 'extend'
            . DIRECTORY_SEPARATOR . 'cert' . DIRECTORY_SEPARATOR . 'cacert.pem';

        $lastErr = '';
        foreach ([self::API_URL, self::MIRROR_URL] as $url) {
            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => 'param=' . urlencode($param),
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/x-www-form-urlencoded',
                    'Encrypt: true',
                    'Accept: application/json, text/plain, */*',
                    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
                ],
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ];
            if (is_file($caFile)) {
                $opts[CURLOPT_CAINFO] = $caFile;
            }
            curl_setopt_array($ch, $opts);
            $body   = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err    = curl_error($ch);
            curl_close($ch);

            if ($body !== false && $status === 200) {
                return (string)$body;
            }
            $lastErr = $err !== '' ? $err : ('HTTP ' . $status);
        }
        throw new \RuntimeException('国家能源局接口请求失败：' . $lastErr);
    }
}
