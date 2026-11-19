<?php
namespace app\service;

use think\facade\Cache;
use think\facade\Log;

/**
 * 河南省建筑市场监管公共服务平台（hngcjs.hnjs.henan.gov.cn）企业信息采集
 *
 * 采集范围（仅公开页面，不绕过任何验证码 / 限流）：
 *  - 企业基础信息：名称、统一社会信用代码、法定代表人、注册属地、经营地址
 *  - 安全生产许可证：证号、发证日期、有效期、证书状态
 *  - 企业资质资格明细：由 /companyTab/compQualificationList 提供，平台要求图形验证码，
 *    本模块不识别、不绕过验证码：把验证码图片返回给业务人员人工识别后提交，
 *    再带着同一会话去取资质明细（等价于人工在平台页面上操作）。
 *  - 施工劳务等备案类资质只在省平台展示，全国建筑市场监管公共服务平台不收录，
 *    因此以省平台为准。
 *
 * 说明：
 *  - 单次请求 15s 超时；平台真实返回 429 时自动退避重试一次（不对本地做 2 秒硬拦截）。
 *  - 企业公开信息缓存 10 分钟；资质明细缓存 10 分钟，其中 2 分钟内重复读取直接复用，
 *    平台频控时用最近一次结果兜底。
 */
class CompanyPlatformService
{
    public const SOURCE = '河南省建筑市场监管公共服务平台';
    public const BASE_URL = 'https://hngcjs.hnjs.henan.gov.cn';
    public const SOURCE_KEY = 'hn_platform';

    /** 人工识别验证码的会话有效期（秒） */
    public const CAPTCHA_TTL = 300;

    /** 资质明细缓存保留时长（秒）：平台频控时作为兜底结果 */
    public const QUAL_CACHE_TTL = 600;

    /** 资质明细缓存新鲜期（秒）：该时间内重复读取直接复用，不再请求平台 */
    public const QUAL_CACHE_FRESH = 120;

    /** 资质类型（平台 certtypenum）文案 */
    public const CERT_TYPES = [
        1  => '工程勘察',
        2  => '工程设计',
        5  => '工程监理',
        7  => '建筑业',
        10 => '质量检测',
    ];

    /** 采集（带 10 分钟缓存；fresh=true 强制刷新） */
    public static function fetch(string $name, string $creditCode = '', bool $fresh = false): array
    {
        $name = trim($name);
        $creditCode = strtoupper(trim($creditCode));
        if ($name === '' && $creditCode === '') {
            throw new \RuntimeException('企业名称与统一社会信用代码不能同时为空');
        }

        $key = 'hn_company_' . md5($name . '|' . $creditCode);
        if (!$fresh) {
            $cached = Cache::get($key);
            if (is_array($cached)) {
                $cached['cached'] = true;
                return $cached;
            }
        }

        // 频控：同一服务器 2 秒内最多请求一次。
        // 这里是"等待补足间隔"，不再直接报错——批量采集时旧逻辑会让第 2 家起全部失败。
        $last = (int)Cache::get('hn_company_last_fetch', 0);
        if ($last > 0) {
            $wait = 2 - (time() - $last);
            if ($wait > 0) {
                usleep($wait * 1000000);
            }
        }
        Cache::set('hn_company_last_fetch', time(), 60);

        $url = self::buildDetailUrl($name, $creditCode);
        $html = self::httpGet($url);

        if (str_contains($html, '3303') || str_contains($html, 'TOO_MANY_REQUESTS')) {
            throw new \RuntimeException('平台触发访问保护（验证码 / 频控），请稍后重试或直接打开平台页面查看');
        }
        if (str_contains($html, '查询不到该企业信息') || str_contains($html, '查询不到信息')) {
            throw new \RuntimeException('平台未查询到该企业，请核对名称或统一社会信用代码');
        }

        $parsed = self::parseDetail($html);
        if (($parsed['company']['name'] ?? '') === '' && ($parsed['company']['credit_code'] ?? '') === '') {
            throw new \RuntimeException('平台页面结构已变化，未能解析到企业信息，请稍后重试或联系管理员');
        }

        // 仅有名称时补齐信用代码后，再用 corpcode 拉取一次（信息更完整，如安许证号全称）
        if ($creditCode === '' && ($parsed['company']['credit_code'] ?? '') !== '') {
            $creditCode = (string)$parsed['company']['credit_code'];
            $full = self::httpGet(self::buildDetailUrl($name, $creditCode));
            if (!str_contains($full, '3303') && !str_contains($full, '查询不到该企业信息')) {
                $fullParsed = self::parseDetail($full);
                if (($fullParsed['company']['credit_code'] ?? '') !== '') {
                    $parsed = $fullParsed;
                }
            }
        }

        $parsed['source'] = self::SOURCE;
        $parsed['source_key'] = self::SOURCE_KEY;
        $parsed['source_url'] = $url;
        $parsed['qualification_url'] = self::BASE_URL . '/companyTab/compQualificationInfo?corpcode='
            . rawurlencode((string)($parsed['company']['credit_code'] ?? $creditCode))
            . '&corpname=' . rawurlencode((string)($parsed['company']['name'] ?? $name));
        // 资质明细需人工输入验证码，前端据此提示并弹出验证码
        $parsed['need_captcha'] = true;
        $parsed['corpcode'] = strtoupper((string)($parsed['company']['credit_code'] ?? $creditCode));
        $parsed['qualification_note'] = '省平台的「企业资质资格」需要在弹出的验证码框中人工输入图形验证码后才能读取；'
            . '施工劳务等备案类资质只在本平台展示，全国平台不收录。';
        $parsed['fetched_at'] = date('Y-m-d H:i:s');
        $parsed['cached'] = false;

        Cache::set($key, $parsed, 600);
        return $parsed;
    }

    private static function buildDetailUrl(string $name, string $creditCode): string
    {
        if ($creditCode !== '') {
            return self::BASE_URL . '/company/enterpriseDetail?corpcode=' . rawurlencode($creditCode)
                . '&corpname=' . rawurlencode($name) . '&certType=7';
        }
        return self::BASE_URL . '/company/enterpriseDetailByName?corpname=' . rawurlencode($name);
    }

    /** 公共 GET：严格校验证书；政务站点证书链需完整 CA，仓库内置 cacert.pem */
    private static function httpGet(string $url): string
    {
        $res = self::httpRequestRetry($url, ['accept' => 'html']);
        if (self::isRateLimited($res)) {
            throw new \RuntimeException('平台访问过于频繁（429），请等待约 1 分钟后再试');
        }
        if ($res['status'] >= 400) {
            throw new \RuntimeException('平台返回异常状态（HTTP ' . $res['status'] . '），请稍后重试');
        }
        return $res['body'];
    }

    /**
     * 判断平台是否触发了频控。
     * 不能只判断 body 中是否出现字符串 429：证书编号、日期等业务数据也可能包含 429。
     */
    private static function isRateLimited(array $res): bool
    {
        if ((int)($res['status'] ?? 0) === 429) {
            return true;
        }
        $body = (string)($res['body'] ?? '');
        if ($body === '') {
            return false;
        }
        if (stripos($body, 'TOO_MANY_REQUESTS') !== false
            || str_contains($body, '访问过于频繁')
            || str_contains($body, '请求过于频繁')) {
            return true;
        }
        $json = json_decode($body, true);
        if (is_array($json)) {
            foreach (['code', 'status'] as $k) {
                if ((int)($json[$k] ?? 0) === 429) {
                    return true;
                }
            }
            $msg = (string)($json['msg'] ?? $json['message'] ?? '');
            if (str_contains($msg, '频繁') || stripos($msg, 'TOO_MANY_REQUESTS') !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * 判断平台是否要求先通过验证码（返回 400 + message=3303）。
     * 只认 JSON 的 message/status 字段，避免证书编号里的 "3303" 误判。
     */
    private static function isCaptchaRequired(array $res): bool
    {
        if ((int)($res['status'] ?? 0) !== 400) {
            return false;
        }
        $json = json_decode((string)($res['body'] ?? ''), true);
        if (!is_array($json)) {
            return false;
        }
        return trim((string)($json['message'] ?? $json['msg'] ?? '')) === '3303';
    }

    /**
     * 平台请求重试：仅在明确触发频控时重试（默认 1 次，退避 1.2 秒）。
     * 网络错误、业务错误不重试，避免放大政务站点压力。
     */
    private static function httpRequestRetry(string $url, array $opt = [], int $retries = 1): array
    {
        $res = self::httpRequest($url, $opt);
        $attempt = 0;
        while ($attempt < $retries && self::isRateLimited($res)) {
            $attempt++;
            usleep(1200000 * $attempt);
            if (!empty($res['cookies'])) {
                $opt['cookies'] = $res['cookies'];
            }
            $res = self::httpRequest($url, $opt);
        }
        return $res;
    }

    /** 资质明细缓存键：优先按信用代码，退化为企业名称 */
    private static function qualCacheKey(string $corpcode, string $name): string
    {
        $corpcode = strtoupper(trim($corpcode));
        $name = trim($name);
        return 'hn_qual_' . md5($corpcode !== '' ? $corpcode : mb_strtolower($name));
    }

    /**
     * 底层 HTTP 请求（支持 Cookie 会话 / POST，用于验证码流程）
     *
     * @param string $url
     * @param array  $opt method|body|cookies(数组)|accept|referer|timeout
     * @return array{status:int,body:string,cookies:array<string,string>}
     */
    private static function httpRequest(string $url, array $opt = []): array
    {
        $caFile = rtrim((string)app()->getRootPath(), '/\\') . DIRECTORY_SEPARATOR . 'extend'
            . DIRECTORY_SEPARATOR . 'cert' . DIRECTORY_SEPARATOR . 'cacert.pem';

        $accept = ($opt['accept'] ?? 'html') === 'json'
            ? 'application/json, text/plain, */*'
            : 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';
        $headers = [
            'Accept: ' . $accept,
            'Accept-Language: zh-CN,zh;q=0.9',
            'Referer: ' . ($opt['referer'] ?? (self::BASE_URL . '/company/constructionInfo')),
        ];

        // 收集 Set-Cookie，回填到会话
        $cookies = is_array($opt['cookies'] ?? null) ? $opt['cookies'] : [];
        $ch = curl_init();
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => (int)($opt['timeout'] ?? 20),
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$cookies) {
                if (stripos($line, 'Set-Cookie:') === 0) {
                    $pair = explode(';', trim(substr($line, 11)))[0];
                    if (str_contains($pair, '=')) {
                        [$k, $v] = explode('=', $pair, 2);
                        $k = trim($k);
                        if ($k !== '') {
                            $cookies[$k] = trim($v);
                        }
                    }
                }
                return strlen($line);
            },
        ];
        if ($cookies) {
            $pairs = [];
            foreach ($cookies as $k => $v) {
                $pairs[] = $k . '=' . $v;
            }
            $opts[CURLOPT_COOKIE] = implode('; ', $pairs);
        }
        if (strtoupper((string)($opt['method'] ?? 'GET')) === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = (string)($opt['body'] ?? '');
            $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded; charset=UTF-8';
            $opts[CURLOPT_HTTPHEADER][] = 'X-Requested-With: XMLHttpRequest';
        }
        if (is_file($caFile)) {
            $opts[CURLOPT_CAINFO] = $caFile;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $err !== '') {
            Log::warning('河南平台请求失败：' . $err . ' @ ' . $url);
            throw new \RuntimeException('连接河南省建筑市场监管平台失败，请检查服务器网络后重试');
        }

        return ['status' => $code, 'body' => (string)$body, 'cookies' => $cookies];
    }

    // ------------------------------------------------------------
    // 资质明细：图形验证码（人工识别，不绕过）
    // ------------------------------------------------------------

    /**
     * 获取平台图形验证码
     *
     * 返回 ['captcha_id' => 会话标识, 'image' => data:image/jpeg;base64,图片, 'expires_in' => 秒]
     */
    public static function captcha(string $corpcode = '', string $corpname = ''): array
    {
        // 先打开资质页面建立会话（平台按会话校验验证码）
        $session = self::httpRequestRetry(self::qualificationPageUrl($corpcode, $corpname));
        if (self::isRateLimited($session)) {
            throw new \RuntimeException('平台访问过于频繁（已自动重试仍未恢复），请等待约 1 分钟后再获取验证码');
        }
        if ((int)$session['status'] >= 400) {
            throw new \RuntimeException('平台资质页面打开失败（HTTP ' . (int)$session['status'] . '），请稍后重试');
        }
        $image = self::fetchCaptchaImage($session['cookies']);

        $captchaId = bin2hex(random_bytes(16));
        Cache::set(self::captchaKey($captchaId), [
            'cookies'   => $session['cookies'],
            'corpcode'  => strtoupper(trim($corpcode)),
            'corpname'  => trim($corpname),
            'authed_at' => 0,
            'created_at'=> time(),
        ], self::CAPTCHA_TTL);

        return [
            'captcha_id' => $captchaId,
            'image'      => 'data:image/jpeg;base64,' . base64_encode($image),
            'expires_in' => self::CAPTCHA_TTL,
        ];
    }

    /** 同一会话换一张验证码（验证码识别错误 / 看不清时） */
    public static function refreshCaptcha(string $captchaId): array
    {
        $sess = self::loadCaptcha($captchaId);
        $image = self::fetchCaptchaImage($sess['cookies']);
        Cache::set(self::captchaKey($captchaId), $sess, self::CAPTCHA_TTL);

        return [
            'captcha_id' => $captchaId,
            'image'      => 'data:image/jpeg;base64,' . base64_encode($image),
            'expires_in' => self::CAPTCHA_TTL,
        ];
    }

    /**
     * 人工输入验证码后采集资质明细
     *
     * @param string $captchaId captcha()/refreshCaptcha() 返回的会话标识
     * @param string $code      业务人员识别的验证码
     * @param string $name      企业名称
     * @param string $corpcode  统一社会信用代码
     */
    public static function fetchQualifications(string $captchaId, string $code, string $name, string $corpcode): array
    {
        $code = trim($code);
        if ($code === '') {
            throw new \RuntimeException('请输入图形验证码');
        }
        $sess = self::loadCaptcha($captchaId);
        $corpcode = strtoupper(trim($corpcode !== '' ? $corpcode : (string)$sess['corpcode']));
        $name = trim($name !== '' ? $name : (string)$sess['corpname']);
        if ($corpcode === '' && $name === '') {
            throw new \RuntimeException('请先填写企业名称或统一社会信用代码');
        }

        // 1) 短期缓存：同一企业在 2 分钟内重复读取直接复用，避免反复打扰平台，
        //    也避免平台频控/验证码一次性导致「再读一次」必然失败。
        $cacheKey = self::qualCacheKey($corpcode, $name);
        $cached = Cache::get($cacheKey);
        $cachedAt = is_array($cached) ? (int)($cached['fetched_at_ts'] ?? 0) : 0;
        if (is_array($cached) && $cachedAt > 0 && time() - $cachedAt <= self::QUAL_CACHE_FRESH) {
            return self::fromQualCache($cached, '');
        }

        // 2) 提交验证码，换取本会话的访问令牌
        $tokenRes = self::httpRequest(
            self::BASE_URL . '/setToken?validateCode=' . rawurlencode($code),
            [
                'cookies' => $sess['cookies'],
                'accept'  => 'json',
                'referer' => self::qualificationPageUrl($corpcode, $name),
                'timeout' => 15,
            ]
        );
        if (self::isRateLimited($tokenRes)) {
            if (is_array($cached) && $cachedAt > 0 && time() - $cachedAt <= self::QUAL_CACHE_TTL) {
                $mins = max(1, (int)ceil((time() - $cachedAt) / 60));
                return self::fromQualCache($cached, "平台访问过于频繁，已返回 {$mins} 分钟前的采集结果，请稍后再试");
            }
            throw new \RuntimeException('平台访问过于频繁（验证码提交被限流），请等待约 1 分钟后再试');
        }
        $token = json_decode($tokenRes['body'], true);
        if (!is_array($token)) {
            throw new \RuntimeException('平台验证码接口返回异常，请稍后重试');
        }
        if ((int)($token['code'] ?? -1) !== 0) {
            // 验证码错误：保留会话，仅提示刷新（与平台页面行为一致）
            Cache::set(self::captchaKey($captchaId), $sess, self::CAPTCHA_TTL);
            throw new \RuntimeException((string)($token['msg'] ?? '验证码错误，请重新输入'));
        }

        // 3) 拉取资质明细。
        // 旧逻辑在提交验证码后用本地 2 秒频控直接报错，导致重试必然失败；
        // 现在改为只对平台真实 429 做退避重试，不再本地硬拦截。
        $query = http_build_query([
            'corpcode' => $corpcode,
            'corpname' => $name,
            'pageNum'  => 1,
            'pageSize' => 50,
        ]);
        $listRes = self::httpRequestRetry(
            self::BASE_URL . '/companyTab/compQualificationList',
            [
                'method'  => 'POST',
                'body'    => $query,
                'cookies' => $tokenRes['cookies'],
                'accept'  => 'json',
                'referer' => self::qualificationPageUrl($corpcode, $name),
                'timeout' => 20,
            ]
        );
        $sess['cookies'] = $listRes['cookies'];
        $sess['authed_at'] = time();
        $sess['corpcode'] = $corpcode;
        $sess['corpname'] = $name;
        Cache::set(self::captchaKey($captchaId), $sess, self::CAPTCHA_TTL);

        if (self::isRateLimited($listRes)) {
            if (is_array($cached) && $cachedAt > 0 && time() - $cachedAt <= self::QUAL_CACHE_TTL) {
                $mins = max(1, (int)ceil((time() - $cachedAt) / 60));
                return self::fromQualCache($cached, "平台访问过于频繁，已返回 {$mins} 分钟前的采集结果，请稍后再试");
            }
            throw new \RuntimeException('平台访问过于频繁（已自动重试仍未恢复），请等待约 1 分钟后再试');
        }
        if (self::isCaptchaRequired($listRes)) {
            throw new \RuntimeException('平台仍要求验证码（验证码已失效），请重新获取验证码');
        }

        $json = json_decode($listRes['body'], true);
        if (!is_array($json)) {
            throw new \RuntimeException('平台资质明细返回异常，请稍后重试或打开平台页面核对');
        }
        $rows = $json['rows'] ?? [];
        if (!is_array($rows)) {
            $rows = [];
        }

        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $item = self::mapQualificationRow($row);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        $result = [
            'total'   => (int)($json['total'] ?? count($items)),
            'items'   => $items,
            'message' => $items ? '' : '平台未查询到该企业的资质证书（施工劳务等备案类资质在省平台展示）',
            'cached'  => false,
            'fetched_at' => date('Y-m-d H:i:s'),
        ];
        Cache::set($cacheKey, [
            'total'         => $result['total'],
            'items'         => $items,
            'message'       => $result['message'],
            'fetched_at'    => $result['fetched_at'],
            'fetched_at_ts' => time(),
        ], self::QUAL_CACHE_TTL);

        return $result;
    }

    /** 资质缓存结果 → 接口出参 */
    private static function fromQualCache(array $cached, string $message): array
    {
        $items = is_array($cached['items'] ?? null) ? $cached['items'] : [];
        return [
            'total'      => (int)($cached['total'] ?? count($items)),
            'items'      => $items,
            'message'    => $message !== '' ? $message : (string)($cached['message'] ?? ''),
            'cached'     => true,
            'fetched_at' => (string)($cached['fetched_at'] ?? ''),
        ];
    }

    private static function captchaKey(string $captchaId): string
    {
        return 'hn_captcha_' . preg_replace('/[^a-f0-9]/i', '', $captchaId);
    }

    private static function loadCaptcha(string $captchaId): array
    {
        $sess = Cache::get(self::captchaKey($captchaId));
        if (!is_array($sess) || !isset($sess['cookies'])) {
            throw new \RuntimeException('验证码已过期，请重新点击「获取验证码」');
        }
        return $sess;
    }

    private static function fetchCaptchaImage(array $cookies): string
    {
        $res = self::httpRequestRetry(
            self::BASE_URL . '/captcha/captchaImage1?timestamp=' . (int)(microtime(true) * 1000),
            [
                'cookies' => $cookies,
                'accept'  => 'image',
                'referer' => self::BASE_URL . '/companyTab/compQualificationInfo',
                'timeout' => 15,
            ]
        );
        if (self::isRateLimited($res)) {
            throw new \RuntimeException('平台验证码接口访问过于频繁（已自动重试仍未恢复），请等待约 1 分钟后再点「换一张」');
        }
        if ((int)$res['status'] >= 400 || strlen($res['body']) < 200) {
            throw new \RuntimeException('平台验证码加载失败，请稍后重试');
        }
        return $res['body'];
    }

    private static function qualificationPageUrl(string $corpcode, string $corpname): string
    {
        return self::BASE_URL . '/companyTab/compQualificationInfo?corpcode=' . rawurlencode(strtoupper(trim($corpcode)))
            . '&corpname=' . rawurlencode(trim($corpname));
    }

    /** 平台资质明细行 → 台账可导入项 */
    private static function mapQualificationRow(array $row): ?array
    {
        $mark = self::clean((string)($row['mark'] ?? ''));
        $certNo = self::clean((string)($row['certid'] ?? ''));
        if ($mark === '' && $certNo === '') {
            return null;
        }
        $certType = (int)($row['certtypenum'] ?? 0);
        $typeText = self::CERT_TYPES[$certType] ?? '';
        $issueOrg = self::clean((string)($row['organname'] ?? ''));
        $issueDate = self::normDate((string)($row['organdate'] ?? ''));
        $expireDate = self::normDate((string)($row['zzxenddate'] ?? '')) ?: self::normDate((string)($row['enddate'] ?? ''));
        $region = self::clean((string)($row['address'] ?? ''));

        $remark = [];
        if ($typeText !== '') {
            $remark[] = '资质类型：' . $typeText;
        }
        if ($issueOrg !== '') {
            $remark[] = '发证机关：' . $issueOrg;
        }
        if ($region !== '') {
            $remark[] = '注册地址：' . $region;
        }
        $remark[] = '来源：' . self::SOURCE;

        return [
            'q_type'     => 'qualification',
            'cert_name'  => $mark,
            'cert_level' => self::guessLevel($mark),
            'cert_no'    => $certNo,
            'cert_scope' => $mark,
            'issue_org'  => $issueOrg,
            'issue_date' => $issueDate,
            'expire_date'=> $expireDate,
            'remark'     => mb_substr(implode('；', $remark), 0, 255),
        ];
    }

    /** 从资质名称里提取等级（一级 / 贰级 / 甲级 / 不分等级…），取不到则为空 */
    private static function guessLevel(string $name): string
    {
        $map = [
            '特级' => '特级', '一级' => '一级', '二级' => '二级', '三级' => '三级',
            '壹级' => '一级', '贰级' => '二级', '叁级' => '三级',
            '甲级' => '甲级', '乙级' => '乙级', '丙级' => '丙级',
            '不分等级' => '不分等级',
        ];
        foreach ($map as $k => $v) {
            if (str_contains($name, $k)) {
                return $v;
            }
        }
        return '';
    }

    /** 解析企业详情页（DOM，容错字段缺失） */
    private static function parseDetail(string $html): array
    {
        $result = [
            'company' => [
                'name' => '', 'credit_code' => '', 'legal_person' => '', 'reg_type' => '',
                'region_path' => '', 'address' => '', 'registered_capital' => '',
                'safety_status' => '',
            ],
            'items' => [],
            'warnings' => [],
        ];

        if (trim($html) === '') {
            return $result;
        }

        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        $xp = new \DOMXPath($doc);

        $name = '';
        foreach ($xp->query('//div[contains(@class,"hdhd")]//h2') as $h) {
            $name = self::clean($h->textContent);
            if ($name !== '') {
                break;
            }
        }
        $result['company']['name'] = $name;

        $pairs = [];
        foreach ($xp->query('//table[contains(@class,"tableBox")]/tr') as $tr) {
            $cells = [];
            foreach ($xp->query('./td|./th', $tr) as $td) {
                $cells[] = self::clean($td->textContent);
            }
            for ($i = 0; $i + 1 < count($cells); $i += 2) {
                $label = rtrim($cells[$i], "：: \t");
                if ($label !== '') {
                    $pairs[$label] = $cells[$i + 1];
                }
            }
        }
        libxml_use_internal_errors($prev);

        $pick = static function (array $map, array $keys): string {
            foreach ($keys as $k) {
                foreach ($map as $label => $value) {
                    if ($label === $k || str_contains($label, $k)) {
                        if ($value !== '') {
                            return $value;
                        }
                    }
                }
            }
            return '';
        };

        $result['company']['credit_code'] = strtoupper($pick($pairs, ['统一社会信用代码', '信用代码']));
        $result['company']['legal_person'] = $pick($pairs, ['企业法定代表人', '法定代表人', '法人']);
        $result['company']['reg_type'] = $pick($pairs, ['企业登记注册类型', '注册类型']);
        $result['company']['region_path'] = str_replace('-', '/', $pick($pairs, ['企业注册属地', '注册属地']));
        $result['company']['address'] = $pick($pairs, ['企业经营地址', '经营地址', '企业地址']);
        $result['company']['registered_capital'] = $pick($pairs, ['注册资本']);
        $result['company']['safety_status'] = $pick($pairs, ['证书状态']);

        $safetyNo = $pick($pairs, ['安全生产许可证：', '安全生产许可证']);
        if ($safetyNo !== '' && $safetyNo !== '—' && $safetyNo !== '-') {
            $issueDate = self::normDate($pick($pairs, ['发证日期']));
            $expireDate = self::normDate($pick($pairs, ['安全生产许可证有效日期', '有效日期', '有效期']));
            $status = $result['company']['safety_status'];
            if ($status === '' && $expireDate !== '') {
                $status = strtotime($expireDate) >= strtotime(date('Y-m-d')) ? '有效' : '已过期';
            }
            $result['items'][] = [
                'q_type'     => 'safety',
                'cert_name'  => '安全生产许可证',
                'cert_level' => '',
                'cert_no'    => $safetyNo,
                'cert_scope' => '',
                'issue_org'  => '河南省住房和城乡建设厅',
                'issue_date' => $issueDate,
                'expire_date'=> $expireDate,
                'remark'     => '来源：' . self::SOURCE . ($status !== '' ? '；证书状态：' . $status : ''),
            ];
        } else {
            $result['warnings'][] = '平台公开页面未查询到安全生产许可证信息';
        }

        $result['warnings'][] = '平台「企业资质资格」明细需图形验证码，已改为人工输入验证码后采集（点「采集资质明细」）';

        return $result;
    }

    private static function clean(string $v): string
    {
        $v = str_replace(["\xc2\xa0", "\u{3000}"], ' ', $v);
        return trim(preg_replace('/\s+/u', ' ', $v) ?? '');
    }

    private static function normDate(string $v): string
    {
        $v = trim($v);
        if ($v === '' || $v === '—' || $v === '-') {
            return '';
        }
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/', $v, $m)) {
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }
        $ts = strtotime($v);
        return $ts ? date('Y-m-d', $ts) : '';
    }
}
