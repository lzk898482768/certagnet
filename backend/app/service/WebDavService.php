<?php
namespace app\service;

use app\model\SystemConfig;
use think\facade\Db;

/**
 * 附件 WebDAV 存储（v2.46）
 *
 * 策略：
 *   · 系统设置 `attach_storage=webdav` 且配置完整时，上传统一入口会把文件转存 WebDAV；
 *   · attach_path 记录为 `webdav://<相对路径>`，前端拿到的 url 是**带签名的本站下载接口**，
 *     服务器端代理取流 —— WebDAV 账号密码不出服务器，文件不落公网直链；
 *   · 未启用（默认）保持原行为：文件存本机 public/uploads，老数据不受影响；
 *   · 签名 URL 有效期默认 1 小时，HMAC 密钥存 `attach_sign_secret`（首次使用时自动生成）。
 *
 * 安全要点：
 *   · 相对路径拒绝 `..` 穿越、拒绝绝对路径；
 *   · TLS 默认强校验（`attach_webdav_verify=0` 可关闭，测试连接会明确提示风险）；
 *   · 测试连接仅管理员可调，密码不在 GET 回显（`attach_webdav_passwd` 命中的脱敏规则自动处理）。
 */
class WebDavService
{
    public const PREFIX = 'webdav://';

    /** 签名 URL 有效期（秒） */
    public const SIGN_TTL = 3600;

    /** 每次请求超时（秒） */
    public const HTTP_TIMEOUT = 10;

    /**
     * 组装当前配置；未启用返回 null
     *
     * 注意：**直连 system_config 表读取**（不走 sys_config 的进程级静态缓存）——
     * 设置页保存后必须立即生效（FPM 常驻进程里 sys_config 静态缓存不会自动失效）。
     *
     * @return array|null{base:string,dir:string,user:string,passwd:string,verify:bool}
     */
    public static function config(): ?array
    {
        if (trim(self::cfg('attach_storage')) !== 'webdav') {
            return null;
        }
        return self::buildConfig();
    }

    /**
     * 历史附件迁移专用：忽略当前 attach_storage 开关，只要保存了 WebDAV 地址就返回配置。
     *
     * 迁移方向是 WebDAV → S3，此时 attach_storage 必须是 s3（上传/下载都走 S3），
     * 若仍用 config() 判断就会永远拿不到 WebDAV 配置，导致历史 webdav:// 附件无法读取。
     * 该方法仅用于只读历史对象，不改变当前生效的存储方式。
     */
    public static function storedConfig(): ?array
    {
        return self::buildConfig();
    }

    /** 按已保存的 WebDAV 字段组装配置；地址为空返回 null */
    private static function buildConfig(): ?array
    {
        $base = self::normBase(self::cfg('attach_webdav_url'));
        if ($base === '') {
            return null;
        }
        return [
            'base'    => $base,
            'dir'     => self::sanitizeRel(self::cfg('attach_webdav_dir')),
            'user'    => self::cfg('attach_webdav_user'),
            'passwd'  => self::cfg('attach_webdav_passwd'),
            'verify'  => trim(self::cfg('attach_webdav_verify', '1')) !== '0',
        ];
    }

    /** 直连 system_config 读一个键（表异常时回退默认值） */
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

    public static function enabled(): bool
    {
        return self::config() !== null;
    }

    /** 规整 Base URL：仅 http(s)、补全尾斜杠、拒绝空 */
    public static function normBase(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return '';
        }
        return rtrim($url, '/') . '/';
    }

    /** 相对路径白名单：拒绝穿越与非法字符 */
    public static function sanitizeRel(string $rel): string
    {
        $rel = trim(str_replace('\\', '/', $rel), '/');
        if ($rel === '') {
            return '';
        }
        $parts = [];
        foreach (explode('/', $rel) as $seg) {
            $seg = trim($seg);
            if ($seg === '' || $seg === '.' || $seg === '..') {
                continue; // 折叠空段与穿越段
            }
            $parts[] = $seg;
        }
        return implode('/', $parts);
    }

    /**
     * 转存一个文件到 WebDAV（供上传统一入口调用）
     *
     * @param string $localFile 本机临时文件绝对路径
     * @param string $safeName  已由上层安全生成的文件名（UUID + 白名单扩展名）
     * @param string $ext      白名单扩展名
     * @return array{ok:bool,msg:string,path:string,url:string}
     */
    public static function put(string $localFile, string $safeName, string $ext): array
    {
        $cfg = self::config();
        if (!$cfg) {
            return ['ok' => false, 'msg' => 'WebDAV 未启用', 'path' => '', 'url' => ''];
        }
        if (!is_file($localFile)) {
            return ['ok' => false, 'msg' => '无法读取待上传的临时文件', 'path' => '', 'url' => ''];
        }
        $ym      = date('Ym');
        $dir     = self::sanitizeRel($cfg['dir']);
        $rel     = self::sanitizeRel(($dir !== '' ? $dir . '/' : '') . $ym . '/' . $safeName);
        $parents = self::sanitizeRel(($dir !== '' ? $dir . '/' : '') . $ym);
        if ($rel === '') {
            return ['ok' => false, 'msg' => 'WebDAV 存储目录配置无效', 'path' => '', 'url' => ''];
        }
        // v2.49：先逐级建集合（右况多数 WebDAV 不会自动创建父目录，PUT 会 409）
        self::ensureDir($cfg, $parents);
        $ok = self::request($cfg, 'PUT', $rel, [
            'upload_path' => $localFile,
        ]);
        if (!$ok['ok'] && (int)$ok['status'] === 409) {
            // 409 兜底：再补建父集合后重试一次
            self::ensureDir($cfg, $parents);
            $ok = self::request($cfg, 'PUT', $rel, [
                'upload_path' => $localFile,
            ]);
        }
        if (!$ok['ok']) {
            $msg = '文件转存 WebDAV 失败：' . $ok['msg'];
            if ((int)$ok['status'] === 409) {
                $msg .= '（父目录创建失败，请检查账号对存储目录是否有建目录/写权限，或把存储目录改为已存在的目录）';
            }
            return ['ok' => false, 'msg' => $msg, 'path' => '', 'url' => ''];
        }
        $path = self::PREFIX . $rel;
        return [
            'ok'   => true,
            'msg'  => 'ok',
            'path' => $path,
            'url'  => self::signedDownloadUrl($path),
        ];
    }

    /**
     * v2.49：逐级 MKCOL 创建集合目录（已存在 / 无权限等失败静默忽略，
     * 由 PUT 409 兜底重试 + 明确报错提示兜住）
     */
    public static function ensureDir(array $cfg, string $relDir): void
    {
        $relDir = self::sanitizeRel($relDir);
        if ($relDir === '') {
            return;
        }
        $acc = '';
        foreach (explode('/', $relDir) as $seg) {
            $acc = $acc === '' ? $seg : $acc . '/' . $seg;
            self::request($cfg, 'MKCOL', $acc);
        }
    }

    /** 删除远程文件（尽力而为，失败不抛错） */
    public static function delete(string $pathToken): void
    {
        $cfg = self::config();
        if (!$cfg || stripos($pathToken, self::PREFIX) !== 0) {
            return;
        }
        self::request($cfg, 'DELETE', substr($pathToken, strlen(self::PREFIX)));
    }

    /**
     * 为 webdav:// 路径生成带签名的本站下载 URL（浏览器 img / iframe / 下载均可直用）
     *
     * v2.99.48：签名实现已抽到 AttachSignService（WebDAV / S3 共用同一密钥与算法），
     * 这里保留方法名做委托，历史调用方与已签发链接完全兼容。
     */
    public static function signedDownloadUrl(string $pathToken, int $ttl = self::SIGN_TTL): string
    {
        $rel = self::sanitizeRel(substr($pathToken, strlen(self::PREFIX)));
        return AttachSignService::signedUrl($rel, $ttl);
    }

    /** 校验签名（供公开下载接口调用），通过返回相对路径，失败返回 null */
    public static function verifySigned(string $p, string $e, string $s): ?string
    {
        return AttachSignService::verify($p, $e, $s);
    }

    /**
     * 完整 WebDAV 请求封装（PUT / GET / DELETE / PROPFIND / OPTIONS）
     *
     * @param array  $cfg       self::config() 结构
     * @param string $method    CURLOPT_CUSTOMREQUEST
     * @param array  $opts      可选：upload_path（PUT 上传本机文件）
     */
    public static function request(array $cfg, string $method, string $rel, array $opts = []): array
    {
        $rel  = self::sanitizeRel($rel);
        if ($rel === '') {
            return ['ok' => false, 'msg' => '无效的路径', 'status' => 0, 'body' => ''];
        }
        $url  = $cfg['base'] . str_replace('%2F', '/', rawurlencode($rel));

        $ch = curl_init();
        $opt = [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false, // 防 WebDAV 服务 302 重定向
            CURLOPT_HEADER         => true,
        ];
        if ($cfg['user'] !== '') {
            $opt[CURLOPT_USERPWD] = $cfg['user'] . ':' . $cfg['passwd'];
        }
        if (stripos($cfg['base'], 'https://') === 0) {
            $opt[CURLOPT_SSL_VERIFYPEER] = $cfg['verify'] ? 1 : 0;
            $opt[CURLOPT_SSL_VERIFYHOST] = $cfg['verify'] ? 2 : 0;
        }
        if (!empty($opts['upload_path']) && is_file((string)$opts['upload_path'])) {
            $opt[CURLOPT_UPLOAD] = true;
            $fp = fopen((string)$opts['upload_path'], 'rb');
            $opt[CURLOPT_INFILE]       = $fp;
            $opt[CURLOPT_INFILESIZE]   = filesize((string)$opts['upload_path']);
        }
        curl_setopt_array($ch, $opt);
        $raw   = curl_exec($ch);
        $hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $body  = is_string($raw) ? substr($raw, $hsize) : '';
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err   = curl_error($ch);
        curl_close($ch);
        if (!empty($fp) && is_resource($fp)) {
            @fclose($fp);
        }
        if ($raw === false) {
            $msg = $err !== '' ? $err : '请求失败';
            return ['ok' => false, 'msg' => $msg, 'status' => 0, 'body' => ''];
        }
        switch ($method) {
            case 'PUT':
                // WebDAV: 200/201/204 均可视为成功
                $ok = $status >= 200 && $status < 300;
                break;
            case 'DELETE':
                $ok = $status >= 200 && $status < 300;
                break;
            case 'GET':
                $ok = $status === 200;
                break;
            default:
                $ok = $status >= 200 && $status < 400;
        }
        if (!$ok) {
            $msg = 'HTTP ' . $status;
            if ($status === 401 || $status === 403) {
                $msg .= '（账号或密码错误，或账号无权限）';
            }
            return ['ok' => false, 'msg' => $msg, 'status' => $status, 'body' => mb_substr($body, 0, 300)];
        }
        return ['ok' => true, 'msg' => 'ok', 'status' => $status, 'body' => $body];
    }

    /**
     * 「测试连接」完整流程（管理员专用接口调用）：
     *   探测 → 建年月目录 → 写测试文件 → 读回比对 → 删除，逐步返回结果与耗时。
     * v2.49：写入路径模拟真实上传（<目录>/<年月>/测试文件），让「父目录不存在 / 无建目录权限」
     * 这类 PUT 409 问题在测试连接阶段即可暴露，而不是等到首次真实上传才报错。
     *
     * @param array|null $override 覆盖型表单值（merge 保存值，密码为空时使用已保存的）
     */
    public static function test(?array $override = null): array
    {
        // 直连读取（cfg() 同 config()，不受 sys_config 静态缓存影响），测试用的可能尚未保存
        $saved = [
            'base'    => self::cfg('attach_webdav_url'),
            'dir'     => self::cfg('attach_webdav_dir'),
            'user'    => self::cfg('attach_webdav_user'),
            'passwd'  => self::cfg('attach_webdav_passwd'),
            'verify'  => trim(self::cfg('attach_webdav_verify', '1')),
        ];
        $o = array_merge($saved, $override ?? []);
        $o['passwd'] = trim((string)$o['passwd']);
        if ($o['passwd'] === '******' || $o['passwd'] === '') {
            $o['passwd'] = $saved['passwd']; // 表单掩码未改 / 未填密码 → 使用已保存的真实密码
        }
        $cfg = [
            'base'   => self::normBase((string)$o['base']),
            'dir'    => self::sanitizeRel((string)$o['dir']),
            'user'   => trim((string)$o['user']),
            'passwd' => (string)$o['passwd'],
            'verify' => trim((string)$o['verify']) !== '0',
        ];
        if ($cfg['base'] === '') {
            return ['ok' => false, 'msg' => '请填写正确的 WebDAV 地址（http:// 或 https:// 开头）', 'steps' => []];
        }

        $t0     = microtime(true);
        $steps  = [];
        $test   = 'crta_test_' . bin2hex(random_bytes(6)) . '.txt';
        $secret = 'certagent-webdav-' . uniqid() . '-' . date('Y-m-d H:i:s');
        // v2.49：模拟真实上传的两级路径 <目录>/<年月>/<文件>
        $parents = self::sanitizeRel(($cfg['dir'] !== '' ? $cfg['dir'] . '/' : '') . date('Ym'));
        $rel     = $parents !== '' ? $parents . '/' . $test : $test;

        // ① 探测基础地址（PROPFIND，Depth:0；不支持返回 404/405 也说明服务可达）
        $probe = self::rawRequest($cfg, $cfg['base'], 'PROPFIND', '<?xml version="1.0"?><a:propfind xmlns:a="DAV:"><a:prop><a:resourcetype/></a:prop></a:propfind>', 'Depth: 0');
        $steps[] = self::step('探测服务', ($probe['status'] > 0 && $probe['status'] < 500) ? 'ok' : 'fail',
            'HTTP ' . $probe['status'] . ($probe['err'] !== '' ? '：' . $probe['err'] : ''));
        if ($probe['status'] === 401 || $probe['status'] === 403) {
            $steps[] = self::step('账号验证', 'fail', '地址可达但被拒绝：请检查用户名 / 密码');
            return ['ok' => false, 'msg' => 'WebDAV 地址可达，账号验证失败', 'steps' => $steps, 'elapsed_ms' => self::ms($t0)];
        }
        if ($probe['status'] === 0) {
            return ['ok' => false, 'msg' => '无法连接到 WebDAV 地址：' . $probe['err'], 'steps' => $steps, 'elapsed_ms' => self::ms($t0)];
        }

        // ② 逐级建目录（<目录>/<年月>），再确认集合存在 —— 模拟真实上传前 ensureDir 的动作
        self::ensureDir($cfg, $parents);
        $pf = self::rawRequest($cfg, $cfg['base'] . str_replace('%2F', '/', rawurlencode($parents)), 'PROPFIND', '<?xml version="1.0"?><a:propfind xmlns:a="DAV:"><a:prop><a:resourcetype/></a:prop></a:propfind>', 'Depth: 0');
        $pfOk = $pf['status'] >= 200 && $pf['status'] < 300;
        $steps[] = self::step('创建年月目录', $pfOk ? 'ok' : 'fail',
            ($pfOk ? '已就绪：' : 'HTTP ' . $pf['status'] . '：') . $parents . ($pfOk ? '' : ($pf['err'] !== '' ? '：' . $pf['err'] : '（建目录失败，请检查账号建目录/写权限）')));
        if (!$pfOk) {
            return ['ok' => false, 'msg' => '无法创建/确认年月目录（' . $parents . '）：请检查账号是否有建目录权限，或把存储目录改为已存在的目录', 'steps' => $steps, 'elapsed_ms' => self::ms($t0)];
        }

        // ③ 写测试文件（两级路径，同级于真实上传）
        $pu = self::rawRequest($cfg, $cfg['base'] . str_replace('%2F', '/', rawurlencode($rel)), 'PUT', $secret, '', 'text/plain');
        $steps[] = self::step('写入测试文件', ($pu['status'] >= 200 && $pu['status'] < 300) ? 'ok' : 'fail',
            'HTTP ' . $pu['status'] . ($pu['status'] >= 200 && $pu['status'] < 300 ? '' : '：' . ($pu['err'] !== '' ? $pu['err'] : mb_substr($pu['body'], 0, 120))));
        if (!($pu['status'] >= 200 && $pu['status'] < 300)) {
            return ['ok' => false, 'msg' => '写入测试文件失败（目录无写权限？）', 'steps' => $steps, 'elapsed_ms' => self::ms($t0)];
        }

        // ④ 读回校验内容一致
        $ge = self::rawRequest($cfg, $cfg['base'] . str_replace('%2F', '/', rawurlencode($rel)), 'GET', '', '');
        $md5 = ($ge['body'] === $secret);
        $steps[] = self::step('读回校验', $md5 ? 'ok' : 'fail', $md5 ? '内容一致' : '读回内容与写入不一致');
        if (!$md5) {
            return ['ok' => false, 'msg' => '读回校验失败：WebDAV 返回内容与写入不一致', 'steps' => $steps, 'elapsed_ms' => self::ms($t0)];
        }

        // ⑤ 清理测试文件（仅删文件；年月目录可能已有真实附件，不递归删除）
        $de = self::rawRequest($cfg, $cfg['base'] . str_replace('%2F', '/', rawurlencode($rel)), 'DELETE', '', '');
        $steps[] = self::step('清理测试文件', ($de['status'] >= 200 && $de['status'] < 300) ? 'ok' : 'warn',
            'HTTP ' . $de['status'] . ($de['status'] >= 200 && $de['status'] < 300 ? '' : '：失败可手动清理 ' . $rel));

        return ['ok' => true, 'msg' => '配置有效', 'steps' => $steps, 'elapsed_ms' => self::ms($t0)];
    }

    /* ---------------- internal ---------------- */

    private static function step(string $name, string $result, string $detail): array
    {
        return ['name' => $name, 'result' => $result, 'detail' => $detail];
    }

    private static function ms(float $t0): int
    {
        return (int)round((microtime(true) - $t0) * 1000);
    }

    /**
     * 底层请求（含探测类非 2xx 需读取真实状态码的场景）
     * @return array{status:int,err:string,body:string}
     */
    private static function rawRequest(array $cfg, string $url, string $method, string $data = '', string $extraHeader = '', string $contentType = ''): array
    {
        $ch = curl_init();
        $opt = [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER         => true,
        ];
        if ($cfg['user'] !== '') {
            $opt[CURLOPT_USERPWD] = $cfg['user'] . ':' . $cfg['passwd'];
        }
        if (stripos($url, 'https://') === 0) {
            $opt[CURLOPT_SSL_VERIFYPEER] = $cfg['verify'] ? 1 : 0;
            $opt[CURLOPT_SSL_VERIFYHOST] = $cfg['verify'] ? 2 : 0;
        }
        if ($data !== '') {
            $opt[CURLOPT_POSTFIELDS] = $data;
        }
        $hdr = [];
        if ($extraHeader !== '') {
            $hdr[] = $extraHeader;
        }
        if ($contentType !== '') {
            $hdr[] = 'Content-Type: ' . $contentType;
        }
        if ($hdr) {
            $opt[CURLOPT_HTTPHEADER] = $hdr;
        }
        curl_setopt_array($ch, $opt);
        $raw = curl_exec($ch);
        $hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $body = is_string($raw) ? substr($raw, $hsize) : '';
        return ['status' => $status, 'err' => $err, 'body' => $body];
    }

    public static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
