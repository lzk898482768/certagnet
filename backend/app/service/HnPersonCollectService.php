<?php
namespace app\service;

use think\facade\Cache;
use think\facade\Log;

/**
 * 河南省建筑市场监管公共服务平台 — 从业人员证书一键采集
 *
 * 仅采集平台公开查询页，不识别 / 不绕过图形验证码：平台要求验证码时把图片返回给
 * 业务人员人工识别后提交，再带着同一会话去取查询结果（等价于人工在平台页面操作）。
 *
 * 四个查询连接（按证书类型 / 专业自动选择）：
 *  - 注册类人员  /tBRegPerson/tbregpersonlist   （一级/二级建造师等，身份证参数 idcard18）
 *  - 安管人员    /tBRegPerson/slrylist          （A/B/C1/C2/C3，身份证参数 sfzh）
 *  - 技工证      /tBRegPerson/techlist          （技术工人，身份证参数 idcard）
 *  - 建设厅特种工 /tBRegPerson/tzzylist          （建筑施工特种作业，身份证参数 sfzh；v2.88）
 *
 * 注意：「建设厅特种工」（住建，tzzylist）与「应急厅特种工」（应急管理部）分属不同
 * 发证机关 / 查询平台，本平台仅支持前者，应急厅特种工不走本采集。
 *
 * 验证码会话沿用企业采集同款机制：/setToken?validateCode=xxx 换取本会话访问令牌。
 */
class HnPersonCollectService
{
    public const SOURCE = '河南省建筑市场监管公共服务平台';
    public const BASE_URL = 'https://hngcjs.hnjs.henan.gov.cn';
    public const CAPTCHA_TTL = 300;

    /** 各查询连接配置 */
    private const ENDPOINTS = [
        'tbreg' => [
            'label'        => '注册类人员',
            'master'       => '/tBRegPerson/tbregpersonListMaster',
            'url'          => '/tBRegPerson/tbregpersonlist',
            'idcard_param' => 'idcard18',
            'name_param'   => 'personname',
        ],
        'slry' => [
            'label'        => '安管人员',
            'master'       => '/tBRegPerson/slryListMaster',
            'url'          => '/tBRegPerson/slrylist',
            'idcard_param' => 'sfzh',
            'name_param'   => 'xm',
        ],
        'tech' => [
            'label'        => '技工证',
            'master'       => '/tBRegPerson/techListMaster',
            'url'          => '/tBRegPerson/techlist',
            'idcard_param' => 'idcard',
            'name_param'   => 'personname',
        ],
        // v2.88：建设厅特种工（建筑施工特种作业操作资格证），与技术工人不是同一查询库
        'tzzy' => [
            'label'        => '建设厅特种工',
            'master'       => '/tBRegPerson/tzzyListMaster',
            'url'          => '/tBRegPerson/tzzylist',
            'idcard_param' => 'sfzh',
            'name_param'   => 'xm',
        ],
    ];

    /**
     * 根据证书类型 / 专业确定采集连接。
     *
     * @return array{key?:string,extra?:array,error?:string}
     */
    public static function resolveEndpoint(string $certType, string $certName = ''): array
    {
        $type = trim($certType);
        $name = trim($certName);
        if ($type === '') {
            return ['error' => '请先选择证书类型'];
        }
        // 注册类：建造师 / 注册* 
        if (mb_strpos($type, '建造师') !== false || mb_strpos($type, '注册') !== false) {
            $extra = [];
            if (mb_strpos($type, '一级建造师') !== false) {
                $extra['specialtytypnumstr'] = '81';
            } elseif (mb_strpos($type, '二级建造师') !== false) {
                $extra['specialtytypnumstr'] = '82';
            }
            return ['key' => 'tbreg', 'extra' => $extra];
        }
        // 安管人员
        if (mb_strpos($type, '安管') !== false) {
            $extra = [];
            if (in_array($name, ['A', 'B', 'C', 'C1', 'C2', 'C3'], true)) {
                $extra['zslx'] = $name;
            }
            return ['key' => 'slry', 'extra' => $extra];
        }
        // 建设厅特种工（建筑施工特种作业，tzzylist）。
        // 必须放在技工判断之前：字典一级类型名就是「建设厅特种工」，
        // 旧逻辑按「特种工」关键字误路由到技术工人 techlist，查不到结果。
        // 「应急厅特种工」属应急管理部口径，本平台不支持，返回不支持错误。
        if ($type === '建设厅特种工' || (mb_strpos($type, '特种') !== false && mb_strpos($type, '建设厅') !== false)) {
            return ['key' => 'tzzy', 'extra' => []];
        }
        // 技工（技术工人）
        if (mb_strpos($type, '技工') !== false) {
            return ['key' => 'tech', 'extra' => ['techtypenum' => $name]];
        }
        return ['error' => '该证书类型暂不支持一键采集（仅支持注册类 / 安管人员 / 技工证 / 建设厅特种工）'];
    }

    /**
     * 开始一次人工验证码会话：打开查询页建立会话 + 获取图形验证码。
     *
     * @param string $key    tbreg|slry|tech|tzzy
     * @param string $idcard 身份证号
     * @param string $name   人员姓名
     * @param array  $extra  平台筛选参数（如 specialtytypnumstr / zslx / techtypenum）
     * @param int    $staffId 持证人员ID
     * @param string $certType 证书类型（用于回填）
     * @param string $certName 证书专业
     */
    public static function startCaptcha(
        string $key,
        string $idcard,
        string $name,
        array $extra,
        int $staffId,
        string $certType,
        string $certName
    ): array {
        $session = self::openSession($key);
        $image = self::fetchCaptchaImage($session['cookies'], $key);

        $captchaId = bin2hex(random_bytes(16));
        Cache::set(self::captchaKey($captchaId), [
            'cookies'    => $session['cookies'],
            'key'        => $key,
            'idcard'    => $idcard,
            'name'     => $name,
            'extra'    => $extra,
            'staff_id' => $staffId,
            'cert_type' => $certType,
            'cert_name' => $certName,
            'authed_at' => 0,
            'created_at'=> time(),
        ], self::CAPTCHA_TTL);

        return [
            'captcha_id' => $captchaId,
            'image'      => 'data:image/jpeg;base64,' . base64_encode($image),
            'expires_in' => self::CAPTCHA_TTL,
        ];
    }

    /** 同一会话换一张验证码 */
    public static function refreshCaptcha(string $captchaId): array
    {
        $sess = self::loadCaptcha($captchaId);
        $image = self::fetchCaptchaImage($sess['cookies'], (string)$sess['key']);
        Cache::set(self::captchaKey($captchaId), $sess, self::CAPTCHA_TTL);
        return [
            'captcha_id' => $captchaId,
            'image'      => 'data:image/jpeg;base64,' . base64_encode($image),
            'expires_in' => self::CAPTCHA_TTL,
        ];
    }

    /**
     * 提交人工验证码后查询，返回候选证书列表。
     *
     * @return array{need_captcha:bool,captcha_id?:string,image?:string,message:string,candidates:array}
     */
    public static function collect(string $captchaId, string $captchaCode): array
    {
        $captchaCode = trim($captchaCode);
        if ($captchaCode === '') {
            throw new \RuntimeException('请输入图形验证码');
        }
        $sess = self::loadCaptcha($captchaId);
        $key = (string)$sess['key'];
        $cfg = self::endpoint($key);

        // 1) 提交验证码，换取本会话访问令牌
        $tokenRes = self::httpRequest(
            self::BASE_URL . '/setToken?validateCode=' . rawurlencode($captchaCode),
            [
                'cookies' => $sess['cookies'],
                'accept'  => 'json',
                'referer' => self::BASE_URL . $cfg['master'],
                'timeout' => 15,
            ]
        );
        $token = json_decode($tokenRes['body'], true);
        if (!is_array($token)) {
            throw new \RuntimeException('平台验证码接口返回异常，请稍后重试');
        }
        if ((int)($token['code'] ?? -1) !== 0) {
            // 验证码错误：保留会话，仅提示刷新（与平台页面行为一致）
            Cache::set(self::captchaKey($captchaId), $sess, self::CAPTCHA_TTL);
            throw new \RuntimeException((string)($token['msg'] ?? '验证码错误，请重新输入'));
        }

        // 2) 查询
        $res = self::search($key, $sess, $tokenRes['cookies']);

        if ($res['captcha_required']) {
            // 验证码虽通过但查询仍要求验证码（会话失效）：换一张验证码让用户重输
            $image = self::fetchCaptchaImage($tokenRes['cookies'], $key);
            $sess['cookies'] = $tokenRes['cookies'];
            Cache::set(self::captchaKey($captchaId), $sess, self::CAPTCHA_TTL);
            return [
                'need_captcha' => true,
                'captcha_id'   => $captchaId,
                'image'        => 'data:image/jpeg;base64,' . base64_encode($image),
                'message'      => '平台仍要求验证码（会话已失效），请重新输入',
                'candidates'   => [],
            ];
        }

        $sess['cookies'] = $res['cookies'];
        $sess['authed_at'] = time();
        Cache::set(self::captchaKey($captchaId), $sess, self::CAPTCHA_TTL);

        return [
            'need_captcha' => false,
            'message'      => $res['message'],
            'candidates'   => $res['candidates'],
        ];
    }

    /**
     * 先尝试直接查询（某些会话可能已带令牌无需验证码）；需要验证码时抛特殊标记。
     *
     * @return array{need_captcha:bool,captcha_id?:string,image?:string,message:string,candidates:array}
     */
    public static function tryDirect(string $key, string $idcard, string $name, array $extra, int $staffId, string $certType, string $certName): array
    {
        $session = self::openSession($key);
        $res = self::search($key, [
            'idcard'    => $idcard,
            'name'     => $name,
            'extra'    => $extra,
            'cert_type' => $certType,
            'cert_name' => $certName,
        ], $session['cookies']);

        if ($res['captcha_required']) {
            $cap = self::startCaptcha($key, $idcard, $name, $extra, $staffId, $certType, $certName);
            return [
                'need_captcha' => true,
                'captcha_id'   => $cap['captcha_id'],
                'image'        => $cap['image'],
                'message'      => '平台要求图形验证码，请人工输入后继续采集',
                'candidates'   => [],
            ];
        }

        return [
            'need_captcha' => false,
            'message'      => $res['message'],
            'candidates' => $res['candidates'],
        ];
    }

    // ------------------------------------------------------------
    // 内部实现
    // ------------------------------------------------------------

    private static function endpoint(string $key): array
    {
        if (!isset(self::ENDPOINTS[$key])) {
            throw new \RuntimeException('未知的采集连接：' . $key);
        }
        return self::ENDPOINTS[$key];
    }

    private static function captchaKey(string $captchaId): string
    {
        return 'hn_person_captcha_' . preg_replace('/[^a-f0-9]/i', '', $captchaId);
    }

    private static function loadCaptcha(string $captchaId): array
    {
        $sess = Cache::get(self::captchaKey($captchaId));
        if (!is_array($sess) || !isset($sess['cookies'])) {
            throw new \RuntimeException('验证码已过期，请重新点击「一键采集」');
        }
        return $sess;
    }

    /** 打开查询页建立会话 */
    private static function openSession(string $key): array
    {
        $cfg = self::endpoint($key);
        $res = self::httpRequest(self::BASE_URL . $cfg['master'], [
            'accept'  => 'html',
            'referer' => self::BASE_URL . '/tBRegPerson/tbregpersonListMaster',
            'timeout' => 20,
        ]);
        if ((int)$res['status'] >= 400) {
            throw new \RuntimeException('平台查询页打开失败（HTTP ' . (int)$res['status'] . '），请稍后重试');
        }
        return ['cookies' => $res['cookies']];
    }

    private static function fetchCaptchaImage(array $cookies, string $key): string
    {
        $cfg = self::endpoint($key);
        $res = self::httpRequest(
            self::BASE_URL . '/captcha/captchaImage1?timestamp=' . (int)(microtime(true) * 1000),
            [
                'cookies' => $cookies,
                'accept'  => 'image',
                'referer' => self::BASE_URL . $cfg['master'],
                'timeout' => 15,
            ]
        );
        if ((int)$res['status'] >= 400 || strlen($res['body']) < 200) {
            throw new \RuntimeException('平台验证码加载失败，请稍后重试');
        }
        return $res['body'];
    }

    /**
     * 执行平台查询。
     *
     * @param string $key
     * @param array{idcard:string,name:string,extra:array} $params
     * @param array $cookies
     * @return array{captcha_required:bool,candidates:array,message:string,cookies:array}
     */
    private static function search(string $key, array $params, array $cookies): array
    {
        $cfg = self::endpoint($key);
        $body = [
            $cfg['idcard_param'] => $params['idcard'],
            $cfg['name_param']   => $params['name'],
            'pageNum'  => 1,
            'pageSize' => 50,
        ];
        foreach (($params['extra'] ?? []) as $k => $v) {
            if ($v !== '' && $v !== null) {
                $body[$k] = $v;
            }
        }

        $res = self::httpRequest(self::BASE_URL . $cfg['url'], [
            'method'  => 'POST',
            'body'    => $body,
            'cookies' => $cookies,
            'accept'  => 'json',
            'referer' => self::BASE_URL . $cfg['master'],
            'timeout' => 20,
        ]);

        if (self::isCaptchaRequired($res)) {
            return ['captcha_required' => true, 'candidates' => [], 'message' => '需要验证码', 'cookies' => $res['cookies']];
        }
        if ((int)$res['status'] >= 400) {
            throw new \RuntimeException('平台查询返回异常（HTTP ' . (int)$res['status'] . '），请稍后重试');
        }

        $json = json_decode($res['body'], true);
        if (!is_array($json)) {
            throw new \RuntimeException('平台查询返回异常，请稍后重试或打开平台页面核对');
        }
        $rows = $json['list'] ?? $json['rows'] ?? [];
        if (!is_array($rows)) {
            $rows = [];
        }

        $candidates = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $candidate = self::mapRow($key, $row, $params);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        return [
            'captcha_required' => false,
            'candidates'       => $candidates,
            'message'          => $candidates ? '' : '平台未查询到该人员的证书',
            'cookies'          => $res['cookies'],
        ];
    }

    /** 平台行数据 → 证书表单可回填项 */
    private static function mapRow(string $key, array $row, array $params): ?array
    {
        $requestedType = (string)($params['cert_type'] ?? '');
        $requestedName = (string)($params['cert_name'] ?? '');
        $company = '';
        $remark = [];

        if ($key === 'tbreg') {
            $specialty = (string)($row['specialtytypnum'] ?? '');
            $typeMap = ['81' => '一级建造师', '82' => '二级建造师'];
            $certType = $typeMap[$specialty] ?? $requestedType;
            $certName = self::clean((string)($row['zczy'] ?? '')) ?: $requestedName;
            $certNo = self::clean((string)($row['ecertnum'] ?? '')) ?: self::clean((string)($row['stampnum'] ?? ''));
            $expire = self::normDate((string)($row['effectdate'] ?? ''));
            $company = self::clean((string)($row['corpname'] ?? ''));
            if ($company !== '') {
                $remark[] = '注册单位：' . $company;
            }
            if ($specialty !== '') {
                $remark[] = '平台类别：' . $specialty;
            }
            if ($certName !== '') {
                $remark[] = '注册专业：' . $certName;
            }
            $issueOrg = '河南省住房和城乡建设厅';
            $issueDate = '';
        } elseif ($key === 'slry') {
            $certType = $requestedType !== '' ? $requestedType : '安管人员';
            $certName = self::clean((string)($row['zslx'] ?? '')) ?: $requestedName;
            $certNo = self::clean((string)($row['zsbh'] ?? ''));
            $expire = self::normDate((string)($row['yxqjz'] ?? ''));
            $company = self::clean((string)($row['szqy'] ?? ''));
            if ($company !== '') {
                $remark[] = '企业名称：' . $company;
            }
            if ($certName !== '') {
                $remark[] = '人员类别：' . $certName;
            }
            $issueOrg = '河南省住房和城乡建设厅';
            $issueDate = '';
        } elseif ($key === 'tzzy') {
            // v2.88：建设厅特种工（建筑施工特种作业）
            // 列表字段：xm 姓名 / sfzh 身份证 / gwlb 岗位类别 / zsbh 证书编号 / yxqjz 有效期至
            $certType = $requestedType !== '' ? $requestedType : '建设厅特种工';
            $gwlb = self::clean((string)($row['gwlb'] ?? ''));
            if ($gwlb === '0') {
                $gwlb = '其他';
            }
            $certName = $gwlb ?: $requestedName;
            $certNo = self::clean((string)($row['zsbh'] ?? ''));
            $expire = self::normDate((string)($row['yxqjz'] ?? ''));
            $issueDate = '';
            if ($certName !== '') {
                $remark[] = '岗位类别：' . $certName;
            }
            $issueOrg = '河南省住房和城乡建设厅';
        } else {
            $certName = self::clean((string)($row['techtypenum'] ?? '')) ?: $requestedName;
            $certNo = self::clean((string)($row['certnum'] ?? ''));
            $expire = '';
            $issueDate = self::normDate((string)($row['awarddate'] ?? ''));
            $company = self::clean((string)($row['corpname'] ?? ''));
            if ($company !== '') {
                $remark[] = '企业名称：' . $company;
            }
            if ($certName !== '') {
                $remark[] = '技能类别：' . $certName;
            }
            $issueOrg = '河南省住房和城乡建设厅';
        }

        if ($certNo === '' && $certName === '') {
            return null;
        }

        $remark[] = '来源：' . self::SOURCE;

        return [
            'cert_type'    => $certType,
            'cert_name'    => $certName,
            'cert_no'      => $certNo,
            'issue_date'   => $issueDate,
            'expire_date'  => $expire,
            'issue_org'    => $issueOrg,
            'remark'       => mb_substr(implode('；', $remark), 0, 255),
            'source'       => self::SOURCE,
        ];
    }

    // ------------------------------------------------------------
    // HTTP 基础（与企业采集同款：严格校验证书 / Cookie 会话）
    // ------------------------------------------------------------

    private static function httpRequest(string $url, array $opt = []): array
    {
        $caFile = rtrim((string)app()->getRootPath(), '/\\') . DIRECTORY_SEPARATOR . 'extend'
            . DIRECTORY_SEPARATOR . 'cert' . DIRECTORY_SEPARATOR . 'cacert.pem';

        $accept = ($opt['accept'] ?? 'html') === 'json'
            ? 'application/json, text/plain, */*'
            : ($opt['accept'] === 'image' ? 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8'
                : 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8');

        $headers = [
            'Accept: ' . $accept,
            'Accept-Language: zh-CN,zh;q=0.9',
            'Referer: ' . ($opt['referer'] ?? (self::BASE_URL . '/tBRegPerson/tbregpersonListMaster')),
        ];

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
            $opts[CURLOPT_POSTFIELDS] = http_build_query((array)($opt['body'] ?? []));
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
            Log::warning('河南平台人员采集请求失败：' . $err . ' @ ' . $url);
            throw new \RuntimeException('连接河南省建筑市场监管平台失败，请检查服务器网络后重试');
        }

        return ['status' => $code, 'body' => (string)$body, 'cookies' => $cookies];
    }

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