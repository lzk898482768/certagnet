<?php
namespace app\service;

use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;

/**
 * 安管人员考试成绩查询（v2.99.70）
 *
 * 数据来源：河南省建筑施工企业安全生产管理人员考试服务系统
 *   https://www.hnagks.cn/enroll/sync/dcrysjsync/queryScoreSync
 *
 * 查询接口需要图形验证码（算术题），且验证码与会话 Cookie 绑定。
 * 本服务只做「人工输入验证码」的等价操作：
 *   1) startCaptcha() 打开查询页建立会话并取回验证码图片；
 *   2) 前端把图片交给业务人员人工识别；
 *   3) query() 带同一会话 + 人工验证码提交，成功后把成绩写入 exam_score_query。
 *
 * 查询类型（平台参数 sfsccj）：
 *   new = 1 新取证成绩
 *   edu = 0 继续教育成绩
 *
 * 平台字段映射：
 *   xm 姓名 / qymc 企业名称 / rylb 人员类别 / jxpxri 考试日期 / sfhg 是否合格（1 合格、0 不合格）
 */
class ExamScoreService
{
    public const BASE_URL    = 'https://www.hnagks.cn';
    public const PAGE_URL    = self::BASE_URL . '/enroll/sync/dcrysjsync/queryScoreSync';
    public const LIST_URL    = self::BASE_URL . '/enroll/sync/dcrysjsync/queryScoreSyncList';
    public const CAPTCHA_URL = self::BASE_URL . '/enroll/captcha/captchaImage?type=math';

    /** 验证码会话有效期（秒） */
    public const CAPTCHA_TTL = 300;

    public const SOURCE = '河南安管人员考试系统';
    public const SOURCE_KEY = 'hnagks';

    /** 查询类型：new=新取证（sfsccj=1） / edu=继续教育（sfsccj=0） */
    public const QUERY_TYPES = [
        'new' => '新取证',
        'edu' => '继续教育',
    ];

    /** 查询结果三类状态 */
    public const STATUSES = [
        1 => '合格',
        2 => '不合格',
        3 => '未查询到成绩',
    ];

    /** 安管人员类别 */
    public const PERSON_TYPES = [
        'A' => '企业主要负责人',
        'B' => '项目负责人',
        'C' => '专职安全生产管理人员',
    ];

    public static function ensureTables(): void
    {
        ExamSchemaService::ensureTables();
    }

    public static function typeValue(string $type): int
    {
        if (!isset(self::QUERY_TYPES[$type])) {
            throw new \RuntimeException('未知的查询类型');
        }
        return $type === 'new' ? 1 : 0;
    }

    public static function statusOf(int $isPass): int
    {
        return $isPass === 1 ? 1 : 2;
    }

    public static function personTypeLabel(string $raw): string
    {
        $raw = strtoupper(trim($raw));
        if ($raw === '') {
            return '';
        }
        if (isset(self::PERSON_TYPES[$raw])) {
            return self::PERSON_TYPES[$raw];
        }
        // 平台偶发返回 C1 / C2 / C3 等细分，仍归到专职安全生产管理人员
        if (str_starts_with($raw, 'C')) {
            return self::PERSON_TYPES['C'] . '（' . $raw . '）';
        }
        return $raw;
    }

    /**
     * 开始一次查询：建立平台会话并返回验证码图片
     *
     * @return array{captcha_id:string,image:string,expires_in:int,staff:array,query_type:string}
     */
    public static function startCaptcha(int $staffId, string $type, int $operatorId = 0, int $deptId = 0): array
    {
        self::ensureTables();
        if (!isset(self::QUERY_TYPES[$type])) {
            throw new \RuntimeException('请选择查询类型（新取证 / 继续教育）');
        }
        $staff = self::loadStaff($staffId);

        $session = self::openSession();
        // v2.99.70（修复）：平台在请求验证码图片时才下发 JSESSIONID，
        // 必须把这次响应里的 Cookie 合并进会话，否则提交查询时验证码对不上。
        $cap = self::fetchCaptchaImage($session['cookies']);
        $image = $cap['body'];
        $cookies = $cap['cookies'];

        $captchaId = bin2hex(random_bytes(16));
        Cache::set(self::captchaKey($captchaId), [
            'cookies'    => $cookies,
            'staff_id'   => (int)$staff['id'],
            'name'       => (string)$staff['staff_name'],
            'id_card'    => (string)$staff['id_card_plain'],
            'query_type' => $type,
            'operator_id'=> $operatorId,
            'dept_id'    => $deptId,
            'created_at' => time(),
        ], self::CAPTCHA_TTL);

        return [
            'captcha_id' => $captchaId,
            'image'      => 'data:image/jpeg;base64,' . base64_encode($image),
            'expires_in' => self::CAPTCHA_TTL,
            'query_type' => $type,
            'staff'      => [
                'id'         => (int)$staff['id'],
                'staff_name' => (string)$staff['staff_name'],
                'id_card'    => IdCardService::mask((string)$staff['id_card_plain']),
            ],
        ];
    }

    /**
     * 换一张验证码：重建平台会话后重新取图
     *
     * v2.99.70（修复）：平台的验证码校验是一次性的，首次提交后（无论对错）
     * 会话里的验证码就被销毁；此时在原会话里再取图只会拿到一张永远对不上的图片。
     * 所以「换一张」必须换一个全新的平台会话，并返回新的 captcha_id 给前端。
     */
    public static function refreshCaptcha(string $captchaId): array
    {
        $sess = self::loadCaptcha($captchaId);
        $data = self::startCaptcha(
            (int)$sess['staff_id'],
            (string)$sess['query_type'],
            (int)($sess['operator_id'] ?? 0),
            (int)($sess['dept_id'] ?? 0)
        );
        return [
            'captcha_id' => $data['captcha_id'],
            'image'      => $data['image'],
            'expires_in' => $data['expires_in'],
        ];
    }

    /**
     * 提交人工验证码并查询，成功后自动写入成绩列表
     *
     * @return array{ok:bool,total:int,passed:int,failed:int,not_found:bool,message:string,items:array}
     */
    public static function query(string $captchaId, string $code): array
    {
        $code = trim($code);
        if ($code === '') {
            throw new \RuntimeException('请输入图形验证码');
        }
        $sess = self::loadCaptcha($captchaId);
        $type = (string)$sess['query_type'];

        $res = self::post(self::LIST_URL, [
            'name'         => $sess['name'],
            'card'         => $sess['id_card'],
            'validateCode' => $code,
            'sfsccj'       => self::typeValue($type),
            'pageNum'      => 1,
            'pageSize'     => 50,
            'orderByColumn'=> '',
            'isAsc'        => '',
        ], $sess['cookies'], self::PAGE_URL);

        $json = json_decode($res['body'], true);
        if (!is_array($json)) {
            throw new \RuntimeException('考试系统返回格式异常，请稍后重试');
        }
        $msg = trim((string)($json['msg'] ?? ''));
        if ((int)($json['code'] ?? -1) !== 0) {
            if (str_contains($msg, '验证码')) {
                // 验证码错误/过期：保留会话，前端刷新验证码后重试
                return [
                    'ok'            => false,
                    'captcha_invalid' => true,
                    'message'       => $msg !== '' ? $msg : '验证码错误，请重新输入',
                    'total'         => 0,
                    'passed'        => 0,
                    'failed'        => 0,
                    'not_found'     => false,
                    'items'         => [],
                ];
            }
            throw new \RuntimeException('考试系统查询失败：' . ($msg !== '' ? $msg : '未知错误'));
        }

        $rows = array_values(array_filter((array)($json['rows'] ?? []), 'is_array'));
        $rows = self::filterRowsByName($rows, (string)$sess['name']);
        $saved = self::saveResults($sess, $rows);

        return $saved + ['ok' => true];
    }

    /**
     * 人员档案读取 + 身份证解密
     *
     * @return array{id:int,staff_name:string,id_card_plain:string}
     */
    private static function loadStaff(int $staffId): array
    {
        if ($staffId <= 0) {
            throw new \RuntimeException('请选择要查询的人员');
        }
        $row = Db::name('staff')->where('id', $staffId)->where('status', 1)->find();
        if (!$row) {
            throw new \RuntimeException('人员不存在或已停用，请先在人员档案中新增');
        }
        $plain = IdCardService::decode((string)($row['id_card'] ?? ''));
        if (trim($plain) === '') {
            throw new \RuntimeException('该人员未填写身份证号，请先到人员档案补充');
        }
        if (!preg_match('/^\d{15}$|^\d{17}[\dXx]$/', trim($plain))) {
            throw new \RuntimeException('该人员身份证号格式不正确，请先到人员档案修正');
        }
        return [
            'id'            => (int)$row['id'],
            'staff_name'    => (string)($row['staff_name'] ?? ''),
            'id_card_plain' => trim($plain),
        ];
    }

    private static function captchaKey(string $captchaId): string
    {
        return 'exam_score_captcha_' . preg_replace('/[^a-f0-9]/i', '', $captchaId);
    }

    private static function loadCaptcha(string $captchaId): array
    {
        $sess = Cache::get(self::captchaKey($captchaId));
        if (!is_array($sess) || !isset($sess['cookies'])) {
            throw new \RuntimeException('验证码已过期，请重新发起查询');
        }
        return $sess;
    }

    /** 打开查询页，建立平台会话 */
    private static function openSession(): array
    {
        $res = self::http(self::PAGE_URL, [
            'accept'  => 'html',
            'referer' => self::BASE_URL . '/enroll/',
            'timeout' => 20,
        ]);
        if ((int)$res['status'] >= 400) {
            throw new \RuntimeException('考试系统查询页打开失败（HTTP ' . (int)$res['status'] . '），请稍后重试');
        }
        return ['cookies' => $res['cookies']];
    }

    /**
     * 获取验证码图片，同时回传合并后的会话 Cookie
     *
     * @return array{body:string,cookies:array}
     */
    private static function fetchCaptchaImage(array $cookies): array
    {
        $res = self::http(self::CAPTCHA_URL . '&s=' . rawurlencode((string)microtime(true)), [
            'cookies' => $cookies,
            'accept'  => 'image',
            'referer' => self::PAGE_URL,
            'timeout' => 20,
        ]);
        if ((int)$res['status'] >= 400 || strlen($res['body']) < 200) {
            throw new \RuntimeException('考试系统验证码加载失败，请稍后重试');
        }
        return ['body' => $res['body'], 'cookies' => $res['cookies']];
    }

    /**
     * 平台可能按姓名模糊返回，保存前只保留本次查询人员的成绩
     *
     * @param array<int,array> $rows
     * @return array<int,array>
     */
    private static function filterRowsByName(array $rows, string $name): array
    {
        $target = self::normName($name);
        if ($target === '') {
            return $rows;
        }
        $matched = array_values(array_filter($rows, static function ($r) use ($target) {
            return self::normName((string)($r['xm'] ?? '')) === $target;
        }));
        return $matched;
    }

    private static function normName(string $name): string
    {
        $name = str_replace(["\xc2\xa0", "\u{3000}"], ' ', $name);
        return trim(preg_replace('/\s+/u', '', $name) ?? $name);
    }

    /**
     * 保存本次查询结果（该人员 + 该查询类型整体替换）
     *
     * @param array $sess 验证码会话
     * @param array<int,array> $rows 平台成绩行
     * @return array{total:int,passed:int,failed:int,not_found:bool,message:string,items:array}
     */
    private static function saveResults(array $sess, array $rows): array
    {
        $staffId = (int)$sess['staff_id'];
        $type = (string)$sess['query_type'];
        $now = date('Y-m-d H:i:s');
        $idCardPlain = (string)$sess['id_card'];
        $idCardEnc = IdCardService::encode($idCardPlain);
        $idHash = IdCardService::hashOrNull($idCardPlain);

        $companyNames = [];
        foreach ($rows as $r) {
            $cn = self::clean((string)($r['qymc'] ?? ''));
            if ($cn !== '') {
                $companyNames[$cn] = true;
            }
        }
        $companyMap = self::companyMap(array_keys($companyNames));

        $items = [];
        $passed = 0;
        $failed = 0;

        Db::startTrans();
        try {
            Db::name('exam_score_query')->where('staff_id', $staffId)->where('query_type', $type)->delete();

            foreach ($rows as $r) {
                $isPass = (int)($r['sfhg'] ?? 0) === 1 ? 1 : 0;
                $companyName = self::clean((string)($r['qymc'] ?? ''));
                $examDate = self::normDate((string)($r['jxpxri'] ?? ''))
                    ?: self::normDate((string)($r['pxjssj'] ?? ''));
                $personType = strtoupper(trim((string)($r['rylb'] ?? '')));
                $status = self::statusOf($isPass);
                $isPass === 1 ? $passed++ : $failed++;

                $id = (int)Db::name('exam_score_query')->insertGetId([
                    'staff_id'     => $staffId,
                    'name'         => mb_substr(self::clean((string)($r['xm'] ?? $sess['name'])), 0, 64),
                    'id_card'      => $idCardEnc,
                    'id_card_hash' => $idHash,
                    'company_id'   => (int)($companyMap[$companyName] ?? 0),
                    'company_name' => mb_substr($companyName, 0, 255),
                    'person_type'  => mb_substr($personType, 0, 16),
                    'exam_date'    => $examDate !== '' ? $examDate : null,
                    'is_pass'      => $isPass,
                    'query_type'   => $type,
                    'status'       => $status,
                    'source'       => self::SOURCE_KEY,
                    'raw_json'     => (string)json_encode($r, JSON_UNESCAPED_UNICODE),
                    'query_time'   => $now,
                    'create_by'    => (int)$sess['operator_id'],
                    'dept_id'      => (int)$sess['dept_id'],
                    'create_time'  => $now,
                    'update_time'  => $now,
                ]);
                $items[] = [
                    'id'           => $id,
                    'name'         => self::clean((string)($r['xm'] ?? $sess['name'])),
                    'company_name' => $companyName,
                    'person_type'  => $personType,
                    'person_type_label' => self::personTypeLabel($personType),
                    'exam_date'    => $examDate,
                    'is_pass'      => $isPass,
                    'status'       => $status,
                    'query_type'   => $type,
                ];
            }

            if (!$rows) {
                $id = (int)Db::name('exam_score_query')->insertGetId([
                    'staff_id'     => $staffId,
                    'name'         => mb_substr((string)$sess['name'], 0, 64),
                    'id_card'      => $idCardEnc,
                    'id_card_hash' => $idHash,
                    'company_id'   => 0,
                    'company_name' => '',
                    'person_type'  => '',
                    'exam_date'    => null,
                    'is_pass'      => 0,
                    'query_type'   => $type,
                    'status'       => 3,
                    'source'       => self::SOURCE_KEY,
                    'raw_json'     => '',
                    'query_time'   => $now,
                    'create_by'    => (int)$sess['operator_id'],
                    'dept_id'      => (int)$sess['dept_id'],
                    'create_time'  => $now,
                    'update_time'  => $now,
                ]);
                $items[] = [
                    'id'           => $id,
                    'name'         => (string)$sess['name'],
                    'company_name' => '',
                    'person_type'  => '',
                    'person_type_label' => '',
                    'exam_date'    => '',
                    'is_pass'      => 0,
                    'status'       => 3,
                    'query_type'   => $type,
                ];
            }

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw new \RuntimeException('成绩保存失败：' . $e->getMessage());
        }

        $notFound = !$rows;
        $message = $notFound
            ? '未查询到成绩'
            : "查询完成：合格 {$passed} 条，不合格 {$failed} 条";

        return [
            'total'     => count($rows),
            'passed'    => $passed,
            'failed'    => $failed,
            'not_found' => $notFound,
            'message'   => $message,
            'items'     => $items,
        ];
    }

    /** 企业名称 => client_company.id（仅精确同名） */
    private static function companyMap(array $names): array
    {
        if (!$names) {
            return [];
        }
        try {
            $rows = Db::name('client_company')->whereIn('company_name', $names)->field('id,company_name')->select()->toArray();
        } catch (\Throwable $e) {
            return [];
        }
        $map = [];
        foreach ($rows as $r) {
            $map[(string)$r['company_name']] = (int)$r['id'];
        }
        return $map;
    }

    private static function clean(string $v): string
    {
        $v = str_replace(["\xc2\xa0", "\u{3000}"], ' ', $v);
        return trim(preg_replace('/\s+/u', ' ', $v) ?? '');
    }

    private static function normDate(string $v): string
    {
        $v = trim($v);
        if ($v === '' || $v === '-' || $v === '—') {
            return '';
        }
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/', $v, $m)) {
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }
        $ts = strtotime($v);
        return $ts ? date('Y-m-d', $ts) : '';
    }

    // ------------------------------------------------------------
    // HTTP
    // ------------------------------------------------------------

    /**
     * @param array{method?:string,body?:array,cookies?:array,accept?:string,referer?:string,timeout?:int} $opt
     * @return array{status:int,body:string,cookies:array}
     */
    private static function http(string $url, array $opt = []): array
    {
        $caFile = rtrim((string)app()->getRootPath(), '/\\') . DIRECTORY_SEPARATOR
            . 'extend' . DIRECTORY_SEPARATOR . 'cert' . DIRECTORY_SEPARATOR . 'cacert.pem';
        $cookies = (array)($opt['cookies'] ?? []);
        $headers = [
            'Accept: ' . ($opt['accept'] === 'json'
                ? 'application/json, text/javascript, */*; q=0.01'
                : ($opt['accept'] === 'image' ? 'image/avif,image/webp,image/*,*/*;q=0.8' : 'text/html,application/xhtml+xml,*/*;q=0.8')),
            'Accept-Language: zh-CN,zh;q=0.9',
            'Cache-Control: no-cache',
        ];
        if (!empty($opt['referer'])) {
            $headers[] = 'Referer: ' . $opt['referer'];
        }

        $ch = curl_init($url);
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
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $err !== '') {
            Log::warning('[ExamScore] 请求失败：' . $err . ' @ ' . $url);
            throw new \RuntimeException('连接河南安管人员考试系统失败，请检查服务器网络后重试');
        }

        return ['status' => $status, 'body' => (string)$body, 'cookies' => $cookies];
    }

    private static function post(string $url, array $body, array $cookies, string $referer): array
    {
        return self::http($url, [
            'method'  => 'POST',
            'body'    => $body,
            'cookies' => $cookies,
            'accept'  => 'json',
            'referer' => $referer,
            'timeout' => 30,
        ]);
    }
}
