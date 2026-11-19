<?php
namespace app\service;

use app\model\SystemConfig;

/**
 * 附件 S3 兼容对象存储（v2.99.48）
 *
 * 零第三方依赖：手写 AWS Signature V4（HMAC-SHA256），仅用 PHP curl + hash 扩展，
 * 同时兼容 AWS S3 与所有 S3 兼容服务（MinIO、阿里云 OSS、腾讯云 COS、华为云 OBS、
 * 七牛、Ceph RGW 等），通过配置 endpoint / region / bucket 接入，适合离线/内网环境。
 *
 * 策略（与 WebDavService 对齐）：
 *   · 系统设置 attach_storage=s3 且配置完整时，上传统一入口把文件 PUT 到对象存储；
 *   · attach_path 记录为 s3://<对象 key>，前端拿到的 url 是**带 HMAC 签名的本站下载
 *     接口** /api/attach/download，服务器端用 SigV4 鉴权代理取流 —— AccessKey 不出
 *     服务器，对象桶不必开公网读；
 *   · 未启用保持原行为（本机 public/uploads 或 WebDAV），历史数据不受影响；
 *   · 默认 path-style（https://host/bucket/key），对 MinIO/内网最友好；AWS 可切
 *     virtual-host 风格；
 *   · 相对对象 key 拒绝 .. 穿越、拒绝绝对路径与前导斜杠。
 *
 * 配置键（system_config，secret/access_key 命中 isSensitiveKey 自动掩码）：
 *   attach_s3_endpoint / attach_s3_region / attach_s3_bucket /
 *   attach_s3_access_key / attach_s3_secret_key / attach_s3_prefix /
 *   attach_s3_path_style / attach_s3_verify
 */
class S3StorageService
{
    public const PREFIX = 's3://';

    /** 每次请求超时（秒） */
    public const HTTP_TIMEOUT = 20;

    /** 服务标识（SigV4 service 名，S3 兼容协议固定 s3） */
    private const SERVICE = 's3';
    private const ALGO = 'AWS4-HMAC-SHA256';

    /**
     * 组装当前配置；未启用或配置不完整返回 null
     *
     * 直连 system_config 表读取（不走 sys_config 进程级静态缓存），
     * 设置页保存后在 FPM 常驻进程里立即生效。
     *
     * @return array|null
     */
    public static function config(): ?array
    {
        if (trim(self::cfg('attach_storage')) !== 's3') {
            return null;
        }
        $endpoint = self::normEndpoint(self::cfg('attach_s3_endpoint'));
        $bucket   = self::sanitizeKey(self::cfg('attach_s3_bucket'));
        $ak       = self::cfg('attach_s3_access_key');
        $sk       = self::cfg('attach_s3_secret_key');
        if ($endpoint === '' || $bucket === '' || $ak === '' || $sk === '') {
            return null;
        }
        return [
            'endpoint'   => $endpoint,
            'region'     => self::cfg('attach_s3_region', 'us-east-1') ?: 'us-east-1',
            'bucket'     => $bucket,
            'access_key' => $ak,
            'secret_key' => $sk,
            'prefix'     => self::sanitizeKey(self::cfg('attach_s3_prefix')),
            // 默认 path-style=1（兼容 MinIO/内网）；显式置 0 才用 virtual-host 风格
            'path_style' => trim(self::cfg('attach_s3_path_style', '1')) !== '0',
            'verify'     => trim(self::cfg('attach_s3_verify', '1')) !== '0',
        ];
    }

    public static function enabled(): bool
    {
        return self::config() !== null;
    }

    /** 直连 system_config 读一个键（表异常时回退默认值），进程内缓存 */
    private static function cfg(string $key, string $default = ''): string
    {
        static $cache = null;
        if ($cache === null) {
            $cache = [];
            try {
                foreach (SystemConfig::select()->toArray() as $r) {
                    $cache[$r['config_key']] = (string)$r['config_value'];
                }
            } catch (\Throwable $e) {
                $cache = [];
            }
        }
        return trim((string)($cache[$key] ?? $default));
    }

    /** 规整 endpoint：仅 http(s)、去尾斜杠、去带 bucket 的路径 */
    public static function normEndpoint(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return '';
        }
        return rtrim($url, '/');
    }

    /** 对象 key 白名单：拒绝穿越、绝对路径、前导斜杠，折叠空段 */
    public static function sanitizeKey(string $key): string
    {
        $key = trim(str_replace('\\', '/', $key), '/');
        if ($key === '') {
            return '';
        }
        $parts = [];
        foreach (explode('/', $key) as $seg) {
            $seg = trim($seg);
            if ($seg === '' || $seg === '.' || $seg === '..') {
                continue;
            }
            $parts[] = $seg;
        }
        return implode('/', $parts);
    }

    /**
     * 转存本机文件到 S3（供上传统一入口调用）
     *
     * @param string $localFile 本机临时文件绝对路径
     * @param string $safeName  上层安全生成的文件名（UUID + 白名单扩展名）
     * @return array{ok:bool,msg:string,path:string,url:string}
     */
    public static function put(string $localFile, string $safeName, string $ext = '', string $mime = ''): array
    {
        $cfg = self::config();
        if (!$cfg) {
            return ['ok' => false, 'msg' => 'S3 存储未启用或配置不完整', 'path' => '', 'url' => ''];
        }
        if (!is_file($localFile)) {
            return ['ok' => false, 'msg' => '无法读取待上传的临时文件', 'path' => '', 'url' => ''];
        }
        $key = self::objectKey($cfg, $safeName);
        if ($key === '') {
            return ['ok' => false, 'msg' => 'S3 对象 key 无效', 'path' => '', 'url' => ''];
        }
        $data = (string)file_get_contents($localFile);
        if ($mime === '') {
            $mime = self::guessMime($ext ?: (string)pathinfo($safeName, PATHINFO_EXTENSION));
        }
        $r = self::request($cfg, 'PUT', $key, $data, ['Content-Type' => $mime]);
        if (!$r['ok']) {
            return ['ok' => false, 'msg' => '文件转存 S3 失败：' . $r['msg'], 'path' => '', 'url' => ''];
        }
        $path = self::PREFIX . $key;
        return [
            'ok'   => true,
            'msg'  => 'ok',
            'path' => $path,
            'url'  => self::signedDownloadUrl($path),
        ];
    }

    /** 生成对象 key：<可选前缀>/<年月>/<安全文件名> */
    private static function objectKey(array $cfg, string $safeName): string
    {
        $safeName = self::sanitizeKey($safeName);
        if ($safeName === '') {
            return '';
        }
        $base = $cfg['prefix'] !== '' ? $cfg['prefix'] . '/' . date('Ym') : date('Ym');
        return self::sanitizeKey($base . '/' . $safeName);
    }

    /** 删除远程对象（尽力而为，失败不抛错；迁移工具/物理清理用） */
    public static function delete(string $pathToken): void
    {
        $cfg = self::config();
        if (!$cfg || stripos($pathToken, self::PREFIX) !== 0) {
            return;
        }
        $key = self::sanitizeKey(substr($pathToken, strlen(self::PREFIX)));
        if ($key !== '') {
            self::request($cfg, 'DELETE', $key);
        }
    }

    /** 读取对象内容（供本站代理下载调用）；失败返回 ok=false */
    public static function getObject(string $key): array
    {
        $cfg = self::config();
        if (!$cfg) {
            return ['ok' => false, 'msg' => 'S3 存储未启用', 'body' => '', 'mime' => ''];
        }
        $key = self::sanitizeKey($key);
        if ($key === '') {
            return ['ok' => false, 'msg' => '无效的对象 key', 'body' => '', 'mime' => ''];
        }
        $r = self::request($cfg, 'GET', $key);
        if (!$r['ok']) {
            return ['ok' => false, 'msg' => $r['msg'], 'body' => '', 'mime' => ''];
        }
        return ['ok' => true, 'msg' => 'ok', 'body' => $r['body'], 'mime' => $r['mime']];
    }

    /**
     * 为 s3:// 路径生成带 HMAC 签名的本站下载 URL（复用附件统一签名密钥）
     */
    public static function signedDownloadUrl(string $pathToken): string
    {
        $key = self::sanitizeKey(substr($pathToken, strlen(self::PREFIX)));
        return AttachSignService::signedUrl($key);
    }

    /**
     * 「测试连接」（管理员专用）：列桶/HEAD 桶 → 写测试对象 → 读回比对 → 删除。
     *
     * @param array|null $override 覆盖型表单值（merge 已保存配置，密钥为掩码/空时用已保存值）
     */
    public static function test(?array $override = null): array
    {
        $saved = [
            'endpoint'   => self::cfg('attach_s3_endpoint'),
            'region'     => self::cfg('attach_s3_region'),
            'bucket'     => self::cfg('attach_s3_bucket'),
            'access_key' => self::cfg('attach_s3_access_key'),
            'secret_key' => self::cfg('attach_s3_secret_key'),
            'prefix'     => self::cfg('attach_s3_prefix'),
            'path_style' => self::cfg('attach_s3_path_style', '1'),
            'verify'     => self::cfg('attach_s3_verify', '1'),
        ];
        $o = array_merge($saved, $override ?? []);
        foreach (['access_key', 'secret_key'] as $k) {
            $o[$k] = trim((string)$o[$k]);
        }
        if ($o['secret_key'] === '******' || $o['secret_key'] === '') {
            $o['secret_key'] = $saved['secret_key'];
        }
        if ($o['access_key'] === '******' || $o['access_key'] === '') {
            $o['access_key'] = $saved['access_key'];
        }
        $cfg = [
            'endpoint'   => self::normEndpoint((string)$o['endpoint']),
            'region'     => trim((string)$o['region']) ?: 'us-east-1',
            'bucket'     => self::sanitizeKey((string)$o['bucket']),
            'access_key' => (string)$o['access_key'],
            'secret_key' => (string)$o['secret_key'],
            'prefix'     => self::sanitizeKey((string)$o['prefix']),
            'path_style' => trim((string)$o['path_style']) !== '0',
            'verify'     => trim((string)$o['verify']) !== '0',
        ];
        if ($cfg['endpoint'] === '') {
            return ['ok' => false, 'msg' => '请填写正确的 S3 Endpoint（http(s):// 开头）', 'steps' => []];
        }
        if ($cfg['bucket'] === '' || $cfg['access_key'] === '' || $cfg['secret_key'] === '') {
            return ['ok' => false, 'msg' => '请填写 Bucket、AccessKeyId 与 SecretAccessKey', 'steps' => []];
        }

        $t0 = microtime(true);
        $steps = [];
        $testKey = self::sanitizeKey(
            ($cfg['prefix'] !== '' ? $cfg['prefix'] . '/' : '') . 'crta_test/' . bin2hex(random_bytes(6)) . '.txt'
        );
        $payload = 'certagent-s3-' . uniqid() . '-' . date('Y-m-d H:i:s');

        // ① 写测试对象
        $pu = self::request($cfg, 'PUT', $testKey, $payload, ['Content-Type' => 'text/plain']);
        $steps[] = self::step('写入测试对象', ($pu['status'] >= 200 && $pu['status'] < 300) ? 'ok' : 'fail',
            'HTTP ' . $pu['status'] . ($pu['ok'] ? '' : '：' . ($pu['msg'] !== '' ? $pu['msg'] : mb_substr($pu['body'], 0, 120))));
        if (!$pu['ok']) {
            return ['ok' => false, 'msg' => '写入测试对象失败（检查 Endpoint / 桶名 / 密钥权限 / 路径风格）', 'steps' => $steps, 'elapsed_ms' => self::ms($t0)];
        }

        // ② 读回校验
        $ge = self::request($cfg, 'GET', $testKey);
        $match = $ge['ok'] && $ge['body'] === $payload;
        $steps[] = self::step('读回校验', $match ? 'ok' : 'fail', $match ? '内容一致' : ('HTTP ' . $ge['status'] . ' 内容不一致或读取失败'));
        if (!$match) {
            return ['ok' => false, 'msg' => '读回校验失败：S3 返回内容与写入不一致', 'steps' => $steps, 'elapsed_ms' => self::ms($t0)];
        }

        // ③ 清理测试对象
        $de = self::request($cfg, 'DELETE', $testKey);
        $steps[] = self::step('清理测试对象', ($de['status'] >= 200 && $de['status'] < 300) ? 'ok' : 'warn',
            'HTTP ' . $de['status'] . ($de['ok'] ? '' : '：失败可手动清理 ' . $testKey));

        return ['ok' => true, 'msg' => '配置有效', 'steps' => $steps, 'elapsed_ms' => self::ms($t0)];
    }

    /* ---------------- SigV4 核心 ---------------- */

    /**
     * 派生 SigV4 签名密钥（纯函数，便于离线测试确定性）
     * 链路：AWS4+sk → 日期 → 区域 → 服务 → aws4_request
     */
    public static function signingKey(string $secretKey, string $dateStamp, string $region): string
    {
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $secretKey, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', self::SERVICE, $kRegion, true);
        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    /**
     * 发起一个带 SigV4 鉴权的请求
     *
     * @param array  $cfg     self::config() 结构
     * @param string $method  HTTP 方法
     * @param string $key     对象 key（已过滤）
     * @param string $body    请求体（PUT 上传内容；GET/DELETE 传空）
     * @param array  $headers 额外头部（如 Content-Type）
     * @return array{ok:bool,msg:string,status:int,body:string,mime:string}
     */
    public static function request(array $cfg, string $method, string $key, string $body = '', array $headers = []): array
    {
        $key = self::sanitizeKey($key);
        if ($key === '') {
            return ['ok' => false, 'msg' => '无效的对象 key', 'status' => 0, 'body' => '', 'mime' => ''];
        }
        [$url, $host, $canonicalUri] = self::buildUrl($cfg, $key);

        $payloadHash = hash('sha256', $body);
        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');

        $hdrs = $headers;
        $hdrs['host'] = $host;
        $hdrs['x-amz-content-sha256'] = $payloadHash;
        $hdrs['x-amz-date'] = $amzDate;
        if (!isset($hdrs['Content-Type']) && $method === 'PUT') {
            $hdrs['Content-Type'] = 'application/octet-stream';
        }

        // 规范化头部：小写键、trim 值、按键排序
        $norm = [];
        foreach ($hdrs as $k => $v) {
            $norm[strtolower(trim($k))] = trim((string)$v);
        }
        ksort($norm);
        $canonicalHeaders = '';
        $signedHeaders = '';
        foreach ($norm as $k => $v) {
            $canonicalHeaders .= $k . ':' . $v . "\n";
            $signedHeaders .= $k . ';';
        }
        $signedHeaders = rtrim($signedHeaders, ';');

        // canonical URI 由 buildUrl 与实际请求路径共用，避免 Path-Style 漏掉 bucket。
        $canonicalRequest = implode("\n", [
            strtoupper($method),
            $canonicalUri,
            '',                       // canonical query string（本实现不用 query 鉴权）
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $credentialScope = $dateStamp . '/' . $cfg['region'] . '/' . self::SERVICE . '/aws4_request';
        $stringToSign = implode("\n", [
            self::ALGO,
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $kSigning = self::signingKey($cfg['secret_key'], $dateStamp, $cfg['region']);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorization = self::ALGO
            . ' Credential=' . $cfg['access_key'] . '/' . $credentialScope
            . ', SignedHeaders=' . $signedHeaders
            . ', Signature=' . $signature;

        $sendHeaders = [];
        foreach ($norm as $k => $v) {
            // curl 会自动生成 Host 与 Content-Length，不重复传
            if ($k === 'host') {
                continue;
            }
            $sendHeaders[] = ($k === 'x-amz-content-sha256' ? 'x-amz-content-sha256' : $k) . ': ' . $v;
        }
        $sendHeaders[] = 'Authorization: ' . $authorization;

        $ch = curl_init();
        $opt = [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => $sendHeaders,
        ];
        if (strtoupper($method) === 'PUT' && $body !== '') {
            $opt[CURLOPT_POSTFIELDS] = $body;
        }
        if (stripos($url, 'https://') === 0) {
            $opt[CURLOPT_SSL_VERIFYPEER] = $cfg['verify'] ? 1 : 0;
            $opt[CURLOPT_SSL_VERIFYHOST] = $cfg['verify'] ? 2 : 0;
        }
        curl_setopt_array($ch, $opt);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['ok' => false, 'msg' => $err !== '' ? $err : '请求失败', 'status' => 0, 'body' => '', 'mime' => ''];
        }
        $ok = $status >= 200 && $status < 300;
        $msg = 'ok';
        if (!$ok) {
            $msg = 'HTTP ' . $status;
            if ($status === 401 || $status === 403) {
                $msg .= '（AccessKey/SecretKey 错误、无权限或系统时间偏差过大）';
            } elseif ($status === 404) {
                $msg .= '（桶或对象不存在，请检查 Bucket / Endpoint / 路径风格）';
            }
            // S3 错误体里常带 <Message>，提取一段便于排障
            if (preg_match('#<Message>(.*?)</Message>#s', (string)$raw, $m)) {
                $msg .= '：' . trim(strip_tags($m[1]));
            }
        }
        return [
            'ok'     => $ok,
            'msg'    => $msg,
            'status' => $status,
            'body'   => (string)$raw,
            'mime'   => $contentType,
        ];
    }

    /**
     * 按 path-style / virtual-host 风格拼 URL，返回 [url, host, canonicalUri]
     *
     * canonicalUri 与真实请求路径必须是同一个字符串：Path-Style 为
     * `[/endpoint-path]/bucket/key`，Virtual-Host 为 `[/endpoint-path]/key`。
     * SigV4 的 canonical request 里少写 bucket 会导致 S3 端签名不一致（403）。
     */
    private static function buildUrl(array $cfg, string $key): array
    {
        $parts = (array)parse_url($cfg['endpoint']);
        $scheme = ($parts['scheme'] ?? 'https') . '://';
        $host = (string)($parts['host'] ?? '');
        if (!empty($parts['port'])) {
            $host .= ':' . $parts['port'];
        }
        $endpointPath = isset($parts['path']) ? trim((string)$parts['path'], '/') : '';
        $encKey = implode('/', array_map('rawurlencode', explode('/', $key)));

        $path = '/';
        if ($endpointPath !== '') {
            $path .= $endpointPath . '/';
        }

        if ($cfg['path_style']) {
            // https://host[/endpoint-path]/bucket/key
            $path .= rawurlencode($cfg['bucket']) . '/' . $encKey;
            return [$scheme . $host . $path, $host, $path];
        }
        // virtual-host：https://bucket.host[/endpoint-path]/key
        $vh = $cfg['bucket'] . '.' . $host;
        $path .= $encKey;
        return [$scheme . $vh . $path, $vh, $path];
    }

    private static function guessMime(string $ext): string
    {
        $map = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'webp' => 'image/webp', 'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'txt' => 'text/plain', 'zip' => 'application/zip',
        ];
        return $map[strtolower($ext)] ?? 'application/octet-stream';
    }

    private static function step(string $name, string $result, string $detail): array
    {
        return ['name' => $name, 'result' => $result, 'detail' => $detail];
    }

    private static function ms(float $t0): int
    {
        return (int)round((microtime(true) - $t0) * 1000);
    }
}
