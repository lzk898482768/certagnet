<?php
namespace app\service;

use think\facade\Cache;
use think\facade\Log;

/**
 * 应急管理部特种作业操作证 — CertRelay 中继查询（v2.91）
 *
 * 背景：cx.mem.gov.cn 前置网宿 WAF，服务端直连会被 412 拦截。生产环境不直接跑
 * 无头浏览器（资源开销大），改由独立的 CertRelay 服务（Python + Playwright 过 WAF）代理，
 * 本系统只发两次普通 HTTP 请求（见 CertRelay_对接文档 v2.4）：
 *   1) GET  /api/cert/captcha  → { ok, need_captcha, captcha_token, img }
 *   2) POST /api/cert/verify   → { ok, found, data[], cached }
 *      body: { name, idcard_num, idcard_type, person_type, code, captcha_token }
 *
 * 验证码仍由业务人员人工识别，不做 OCR / 不绕过；captcha_token 一次性使用
 * （服务端默认 300 秒有效，提交后即销毁），验证码输错需重新取码。
 * 中继按「姓名 + 证件号 + 证件类型 + 人员类别」缓存结果（默认 1 天），命中缓存时
 * 返回 cached=true 且不再校验验证码，属服务端预期行为。
 *
 * 配置（系统设置 → 应急厅证书查询中继）：
 *   cert_relay_enable      1/0
 *   cert_relay_base_url    中继根地址，如 http://jj888.softether.net:18923（仅 HTTP）
 *   cert_relay_secret_key  X-API-Key（敏感，自动掩码；中继未启用鉴权时可留空）
 *   cert_relay_timeout     查询超时秒数，默认 120
 */
class MemPersonCollectService
{
    public const SOURCE = '应急管理部特种作业操作证查询平台';
    public const PROVIDER = 'mem';
    public const DEFAULT_BASE_URL = 'http://jj888.softether.net:18923';
    /** 中继默认 session_ttl=300 秒；本地会话与之一致，便于用户点「换一张」 */
    public const CAPTCHA_TTL = 300;

    /** 证书类型是否走应急厅中继（字典一级类型「应急厅特种工」） */
    public static function supports(string $certType): bool
    {
        $t = trim($certType);
        if ($t === '') {
            return false;
        }
        if ($t === '应急厅特种工') {
            return true;
        }
        return mb_strpos($t, '应急') !== false && mb_strpos($t, '特种') !== false;
    }

    /** 是否已启用并配置（中继未启用鉴权时允许不填 API Key） */
    public static function enabled(): bool
    {
        return trim((string)sys_config('cert_relay_enable', '0')) === '1'
            && self::baseUrl() !== '';
    }

    /**
     * 首次采集：中继每次查询都必须带验证码，所以直接取一张码返回。
     *
     * @return array{need_captcha:bool,captcha_id:string,image:string,expires_in:int,provider:string,provider_name:string,message:string,candidates:array}
     */
    public static function tryDirect(
        int $staffId,
        string $idcard,
        string $name,
        string $certType,
        string $certName
    ): array {
        $cap = self::startCaptcha($staffId, $idcard, $name, $certType, $certName);
        return [
            'need_captcha' => true,
            'captcha_id'   => $cap['captcha_id'],
            'image'        => $cap['image'],
            'expires_in'   => $cap['expires_in'],
            'message'      => '应急管理厅查询平台需要图形验证码，请人工输入后继续',
            'candidates'   => [],
            'provider'     => self::PROVIDER,
            'provider_name'=> self::SOURCE,
        ];
    }

    /** 取一张新验证码并建立本地会话 */
    public static function startCaptcha(
        int $staffId,
        string $idcard,
        string $name,
        string $certType,
        string $certName
    ): array {
        self::assertConfigured();
        $res = self::request('GET', '/api/cert/captcha', [], 45, 'captcha');
        $token = self::captchaToken($res);
        $img = (string)($res['img'] ?? '');
        if (empty($res['ok']) || $token === '' || $img === '') {
            throw new \RuntimeException('中继服务未返回有效验证码，请稍后重试');
        }

        $captchaId = bin2hex(random_bytes(16));
        Cache::set(self::captchaKey($captchaId), [
            'captcha_token' => $token,
            'idcard'        => $idcard,
            'name'          => $name,
            'staff_id'      => $staffId,
            'cert_type'     => $certType,
            'cert_name'     => $certName,
            'created_at'    => time(),
        ], self::CAPTCHA_TTL);

        return [
            'captcha_id' => $captchaId,
            'image'      => self::dataUri($img),
            'expires_in' => self::CAPTCHA_TTL,
        ];
    }

    /** 同一会话换一张验证码（旧 token 同时作废） */
    public static function refreshCaptcha(string $captchaId): array
    {
        $sess = self::loadCaptcha($captchaId);
        $res = self::request('GET', '/api/cert/captcha', [], 45, 'captcha');
        $token = self::captchaToken($res);
        $img = (string)($res['img'] ?? '');
        if (empty($res['ok']) || $token === '' || $img === '') {
            throw new \RuntimeException('中继服务未返回有效验证码，请稍后重试');
        }
        $sess['captcha_token'] = $token;
        Cache::set(self::captchaKey($captchaId), $sess, self::CAPTCHA_TTL);
        return [
            'captcha_id'   => $captchaId,
            'image'        => self::dataUri($img),
            'expires_in'   => self::CAPTCHA_TTL,
            'provider'     => self::PROVIDER,
            'provider_name'=> self::SOURCE,
        ];
    }

    /**
     * 提交人工验证码并查询。
     *
     * @return array{need_captcha:bool,message:string,candidates:array,provider:string,provider_name:string}
     */
    public static function collect(string $captchaId, string $captchaCode): array
    {
        $code = trim($captchaCode);
        if ($code === '') {
            throw new \RuntimeException('请输入图形验证码');
        }
        $sess = self::loadCaptcha($captchaId);

        $res = self::request('POST', '/api/cert/verify', [
            'name'          => (string)$sess['name'],
            'idcard_num'    => (string)$sess['idcard'],
            'idcard_type'   => '01',
            'person_type'   => '03',
            'code'          => $code,
            'captcha_token' => (string)$sess['captcha_token'],
        ], self::timeout());

        if (empty($res['ok'])) {
            $msg = trim((string)($res['msg'] ?? '')) ?: '查询失败，请稍后重试';
            // 验证码错误 / 过期 / 凭证无效：中继已作废 token，保留本地会话供「换一张」复用
            if (mb_strpos($msg, '验证码') !== false || mb_strpos($msg, '过期') !== false
                || mb_strpos($msg, '凭证') !== false) {
                throw new \RuntimeException($msg . '，请点击「换一张」后重新输入');
            }
            throw new \RuntimeException($msg);
        }

        $rows = is_array($res['data'] ?? null) ? $res['data'] : [];
        $found = array_key_exists('found', $res) ? (bool)$res['found'] : !empty($rows);
        $cached = !empty($res['cached']);
        $candidates = self::mapRows($rows, $sess);

        // token 一次性使用，查询结束即清理
        Cache::delete(self::captchaKey($captchaId));

        $message = '';
        if (!$candidates) {
            $message = '平台未查询到该人员的证书';
        } elseif ($cached) {
            $message = '已返回中继缓存结果（缓存默认 1 天，平台最新数据可能略有延迟）';
        }

        return [
            'need_captcha' => false,
            'message'      => $message,
            'candidates'   => $candidates,
            'found'        => $found,
            'cached'       => $cached,
            'provider'     => self::PROVIDER,
            'provider_name'=> self::SOURCE,
        ];
    }

    /** 本地是否存在该验证码会话（供控制器区分河南平台 / 应急厅中继） */
    public static function hasSession(string $captchaId): bool
    {
        $sess = Cache::get(self::captchaKey($captchaId));
        return is_array($sess) && !empty($sess['captcha_token']);
    }

    // ------------------------------------------------------------
    // 内部实现
    // ------------------------------------------------------------

    private static function captchaKey(string $captchaId): string
    {
        return 'mem_person_captcha_' . preg_replace('/[^a-f0-9]/i', '', $captchaId);
    }

    private static function loadCaptcha(string $captchaId): array
    {
        if ($captchaId === '') {
            throw new \RuntimeException('验证码会话已失效，请重新点击「一键采集」');
        }
        $sess = Cache::get(self::captchaKey($captchaId));
        if (!is_array($sess) || empty($sess['captcha_token'])) {
            throw new \RuntimeException('验证码已过期，请重新点击「一键采集」获取');
        }
        return $sess;
    }

    /** 平台行数据 → 证书表单可回填项；同一证书的多条记录去重，优先保留含实际复审的 */
    private static function mapRows(array $rows, array $sess): array
    {
        $requestedType = (string)($sess['cert_type'] ?? '');
        $requestedName = (string)($sess['cert_name'] ?? '');
        $byKey = [];
        $order = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $certNo = self::clean((string)($row['certNum'] ?? ''));
            $operItem = self::clean((string)($row['operItemName'] ?? ''));
            $jobType = self::clean((string)($row['jobTypeName'] ?? ''));
            $certName = $operItem !== '' ? $operItem : ($jobType !== '' ? $jobType : $requestedName);
            if ($certNo === '' && $certName === '') {
                continue;
            }

            $issueDate = self::normDate((string)($row['firstCertDate'] ?? ''));
            $beginDate = self::normDate((string)($row['validBeginDate'] ?? ''));
            $endDate = self::normDate((string)($row['validEndDate'] ?? ''));
            $shouldReview = self::normDate((string)($row['shouldReviewDate'] ?? ''));
            $reviewDate = self::normDate((string)($row['reviewDate'] ?? ''));
            $org = self::clean((string)($row['issueOrgName'] ?? ''));
            $reviewOrg = self::clean((string)($row['reviewOrgName'] ?? ''));
            $archives = self::clean((string)($row['archivesNum'] ?? ''));
            $statusCode = self::clean((string)($row['certStatus'] ?? ''));
            $statusText = self::statusText($endDate, (string)($row['certBlacklist'] ?? ''));

            $remark = [];
            $personType = self::clean((string)($row['personTypeName'] ?? ''));
            $sex = self::clean((string)($row['sex'] ?? ''));
            $platformRemark = self::clean((string)($row['remarks'] ?? ''));
            $delayDate = self::normDate((string)($row['delayDate'] ?? ''));
            $delayOrg = self::clean((string)($row['delayOrgName'] ?? ''));
            if ($personType !== '') {
                $remark[] = '人员类别：' . $personType;
            }
            if ($sex !== '') {
                $remark[] = '性别：' . $sex;
            }
            if ($jobType !== '') {
                $remark[] = '作业类别：' . $jobType;
            }
            if ($operItem !== '') {
                $remark[] = '操作项目：' . $operItem;
            }
            if ($shouldReview !== '') {
                $remark[] = '应复审：' . $shouldReview;
            }
            if ($reviewDate !== '') {
                $remark[] = '实际复审：' . $reviewDate . ($reviewOrg !== '' ? '（' . $reviewOrg . '）' : '');
            }
            if ($delayDate !== '' || $delayOrg !== '') {
                $remark[] = '延期：' . ($delayDate !== '' ? $delayDate : '—')
                    . ($delayOrg !== '' ? '（' . $delayOrg . '）' : '');
            }
            if ($statusText !== '') {
                $remark[] = '状态：' . $statusText;
            }
            if ($statusCode !== '') {
                $remark[] = '平台状态码：' . $statusCode;
            }
            if ($archives !== '') {
                $remark[] = '档案号：' . $archives;
            }
            if ($platformRemark !== '') {
                $remark[] = '平台备注：' . $platformRemark;
            }
            $remark[] = '来源：' . self::SOURCE;

            $candidate = [
                'cert_type'           => $requestedType !== '' ? $requestedType : '应急厅特种工',
                'cert_name'           => $certName,
                'cert_no'             => $certNo,
                'issue_date'          => $issueDate,
                'expire_date'         => $endDate,
                'issue_org'           => $org,
                'register_start_date' => $beginDate,
                'register_end_date'   => $endDate,
                'remark'              => mb_substr(implode('；', $remark), 0, 255),
                'source'              => self::SOURCE,
            ];

            // 去重：同一证书编号 + 操作项目只保留信息更完整的一条
            $key = ($certNo !== '' ? $certNo : $certName) . '|' . $operItem;
            $score = ($reviewDate !== '' ? 2 : 0) + ($reviewOrg !== '' ? 1 : 0) + ($statusCode !== '' ? 1 : 0);
            if (!isset($byKey[$key])) {
                $order[] = $key;
                $byKey[$key] = ['score' => $score, 'candidate' => $candidate];
            } elseif ($score > $byKey[$key]['score']) {
                $byKey[$key] = ['score' => $score, 'candidate' => $candidate];
            }
        }

        $out = [];
        foreach ($order as $key) {
            $out[] = $byKey[$key]['candidate'];
        }
        return $out;
    }

    /** 按有效期给出可读状态（平台 certStatus 码含义未公开，原样保留在备注） */
    private static function statusText(string $endDate, string $blacklist): string
    {
        if ($blacklist !== '' && $blacklist !== 'null') {
            return '黑名单';
        }
        if ($endDate !== '' && $endDate < date('Y-m-d')) {
            return '已过期';
        }
        return $endDate !== '' ? '有效' : '';
    }

    private static function dataUri(string $img): string
    {
        $img = trim($img);
        if ($img === '') {
            return '';
        }
        return str_starts_with($img, 'data:') ? $img : 'data:image/jpeg;base64,' . $img;
    }

    private static function baseUrl(): string
    {
        $v = trim((string)sys_config('cert_relay_base_url', ''));
        return $v !== '' ? rtrim($v, '/') : self::DEFAULT_BASE_URL;
    }

    private static function apiKey(): string
    {
        $v = trim((string)sys_config('cert_relay_secret_key', ''));
        // 生产环境允许通过环境变量注入，避免密钥落库
        if ($v === '') {
            $v = trim((string)env('CERT_RELAY_KEY', ''));
        }
        return $v;
    }

    private static function timeout(): int
    {
        $v = (int)sys_config('cert_relay_timeout', 120);
        return $v >= 10 ? min($v, 300) : 120;
    }

    private static function assertConfigured(): void
    {
        if (trim((string)sys_config('cert_relay_enable', '0')) !== '1') {
            throw new \RuntimeException('应急厅证书查询中继未启用，请在「系统设置」中开启');
        }
        if (self::baseUrl() === '') {
            throw new \RuntimeException('应急厅证书查询中继未配置地址，请在「系统设置」中填写');
        }
    }

    /** v2.91：取码响应票据（新版 captcha_token，兼容旧版 uuid） */
    private static function captchaToken(array $res): string
    {
        foreach (['captcha_token', 'uuid'] as $k) {
            $v = trim((string)($res[$k] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }
        return '';
    }

    /**
     * v2.91：中继连接自检（系统设置 →「测试连接」）。
     *
     * 同时调用 /health（免鉴权）与 /api/cert/keycheck（鉴权诊断），
     * 直接给出「地址不通 / Key 不匹配 / IP 未加白名单」的准确原因。
     *
     * @param array{base_url?:string,api_key?:string,timeout?:int} $override 表单未保存时直接测
     * @return array{ok:bool,msg:string,health?:array,keycheck?:array}
     */
    public static function test(array $override = []): array
    {
        $base = trim((string)($override['base_url'] ?? ''));
        $base = rtrim($base !== '' ? $base : self::baseUrl(), '/');
        $key = array_key_exists('api_key', $override)
            ? trim((string)$override['api_key'])
            : self::apiKey();
        $timeout = max(5, min((int)($override['timeout'] ?? 15), 60));

        if (!preg_match('#^https?://#i', $base)) {
            return ['ok' => false, 'msg' => '中继地址必须以 http:// 或 https:// 开头'];
        }

        [$hStatus, $health, $hErr] = self::httpJson('GET', $base . '/health', '', [], $timeout);
        if (!is_array($health)) {
            return ['ok' => false, 'msg' => '无法连接中继服务：' . ($hErr !== '' ? $hErr : 'HTTP ' . $hStatus)];
        }
        if (($health['status'] ?? '') !== 'ok') {
            return ['ok' => false, 'msg' => '中继健康检查未通过', 'health' => $health];
        }

        [$kStatus, $kc, $kErr] = self::httpJson('GET', $base . '/api/cert/keycheck', $key, [], $timeout);
        if (!is_array($kc)) {
            return [
                'ok'     => false,
                'msg'    => '中继鉴权诊断失败：' . ($kErr !== '' ? $kErr : 'HTTP ' . $kStatus),
                'health' => $health,
            ];
        }
        if (!empty($kc['api_key_enabled']) && empty($kc['api_key_ok'])) {
            return [
                'ok'       => false,
                'msg'      => 'API Key 不匹配，请核对中继 config.json 的 api_key 与本系统填写值',
                'health'   => $health,
                'keycheck' => $kc,
            ];
        }
        if (!empty($kc['ip_whitelist_enabled']) && empty($kc['ip_allowed'])) {
            return [
                'ok'       => false,
                'msg'      => '来源 IP 不在中继白名单：' . (string)($kc['client_ip'] ?? ''),
                'health'   => $health,
                'keycheck' => $kc,
            ];
        }
        if (array_key_exists('authorized', $kc) && empty($kc['authorized'])) {
            return [
                'ok'       => false,
                'msg'      => '中继鉴权未通过，请检查 API Key 与 IP 白名单',
                'health'   => $health,
                'keycheck' => $kc,
            ];
        }

        return [
            'ok'       => true,
            'msg'      => '中继连接正常',
            'health'   => $health,
            'keycheck' => $kc,
        ];
    }

    /**
     * 调用中继服务。
     *
     * @param string $stage captcha=取码阶段 / verify=提交查询阶段（决定错误提示文案）
     * @return array 解码后的 JSON
     */
    private static function request(
        string $method,
        string $path,
        array $payload = [],
        int $timeout = 60,
        string $stage = 'verify'
    ): array {
        $url = self::baseUrl() . $path;
        [$status, $json, $err] = self::httpJson($method, $url, self::apiKey(), $payload, $timeout);

        if (!is_array($json)) {
            Log::warning('应急厅证书中继请求失败：' . $err . ' @ ' . $url);
            if (stripos($err, 'HTTP') === 0) {
                throw new \RuntimeException('应急厅证书查询中继返回异常（' . $err . '），请稍后重试');
            }
            throw new \RuntimeException('连接应急厅证书查询中继失败：' . $err . '（' . $url . '）');
        }
        if ($status === 403) {
            $hint = self::apiKey() === ''
                ? '中继服务已启用 API Key 校验，请在「系统设置」填写正确的 Key'
                : '中继服务鉴权失败（API Key 不匹配或来源 IP 未加入白名单），请到中继排查';
            throw new \RuntimeException($hint);
        }
        if ($status >= 500) {
            $msg = trim((string)($json['msg'] ?? ''));
            $detail = $msg !== '' ? '（' . $msg . '）' : '';
            // 取码阶段失败：此时还没有验证码，不能提示「换一张」
            if ($stage === 'captcha') {
                throw new \RuntimeException(
                    '中继服务暂时无法获取验证码' . $detail
                    . '：请稍后重试；若持续失败，请检查中继服务是否正常运行（Chromium / WAF）'
                );
            }
            throw new \RuntimeException(
                '中继查询失败' . $detail
                . '：可能是验证码已过期，请先点「换一张」重试；若持续失败，请检查中继服务是否正常运行'
            );
        }
        return $json;
    }

    /**
     * 执行一次 HTTP 请求并解析 JSON（不抛业务异常，供 request()/test() 复用）。
     *
     * @return array{0:int,1:?array,2:string} [HTTP 状态码, JSON 数组或 null, 错误信息]
     */
    private static function httpJson(
        string $method,
        string $url,
        string $key,
        array $payload,
        int $timeout
    ): array {
        $ch = curl_init();
        $headers = ['Accept: application/json'];
        if ($key !== '') {
            $headers[] = 'X-API-Key: ' . $key;
        }
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => max(5, $timeout),
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'CertAgent/2.91 (+cert-relay)',
        ];
        if (strtoupper($method) === 'POST') {
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $body;
            $headers[] = 'Content-Type: application/json; charset=utf-8';
            $headers[] = 'Content-Length: ' . strlen((string)$body);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;

        $caFile = rtrim((string)app()->getRootPath(), '/\\') . DIRECTORY_SEPARATOR . 'extend'
            . DIRECTORY_SEPARATOR . 'cert' . DIRECTORY_SEPARATOR . 'cacert.pem';
        if (is_file($caFile)) {
            $opts[CURLOPT_CAINFO] = $caFile;
        }

        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $err !== '') {
            return [0, null, $err !== '' ? $err : '请求失败'];
        }
        $json = json_decode((string)$body, true);
        if (!is_array($json)) {
            return [$status, null, 'HTTP ' . $status . ' 返回非 JSON：' . mb_substr((string)$body, 0, 120)];
        }
        return [$status, $json, ''];
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
