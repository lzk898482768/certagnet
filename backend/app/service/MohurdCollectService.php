<?php
namespace app\service;

use think\facade\Log;

/**
 * 住房和城乡建设部「全国建筑市场监管公共服务平台」（四库一平台）— 建造师一键采集
 *
 * v2.99.81：建造师更换为住建部全国接口，替代原河南省平台 tbreg 连接。
 * 与河南省平台相比的优势：
 *  - 免图形验证码、无 429 伪限流，两步 HTTP 即可取数；
 *  - 数据为全国口径，不局限于河南省；
 *  - 同一本建造师证的不同专业（regCertList 逐条）各自带独立注册有效期 REG_EDATE，
 *    解决旧接口「多专业共用一个有效期」的问题。
 *
 * 两步查询：
 *  1) 人员检索：GET /APi/webApi/dataservice/query/staff/list?complexname={身份证}&pg=0&pgsz=15&total=0
 *     → data.list[].RY_ID（住建部人员ID）
 *  2) 人员详情：GET /APi/webApi/dataservice/query/staff/staffDetailAndTrack?staffId={RY_ID}
 *     → data.regCertList[] 每条是一个「级别 + 专业」的注册证书，含独立有效期/证书编号/注册单位
 *
 * 输出与 HnPersonCollectService 相同的 candidates 结构，前端候选弹窗与回填逻辑可直接复用；
 * 每个专业拆成一条候选（cert_name 为单专业），由用户逐专业选用、分别落台账，
 * 从而让每条证书记录都有自己准确的到期日期。
 */
class MohurdCollectService
{
    public const SOURCE = '住房和城乡建设部四库一平台';
    public const BASE_URL = 'https://jzsc.mohurd.gov.cn';

    private const LIST_URL = '/APi/webApi/dataservice/query/staff/list';
    private const DETAIL_URL = '/APi/webApi/dataservice/query/staff/staffDetailAndTrack';

    /** 仅接受有效注册状态，注销/作废等不计入候选 */
    private const VALID_STATUS = 'B_ZGZT_001';

    /** REG_TYPE → 本地证书类型（cert_major 一级），未命中时按名称规整 */
    private const TYPE_MAP = [
        'RY_ZCLB_071' => '一级建造师',
        'RY_ZCLB_072' => '二级建造师',
    ];

    /**
     * 采集入口：按身份证取该人员全部建造师注册证书，逐专业拆成候选。
     *
     * @param int    $staffId   本地持证人员ID（仅用于日志/透传，不参与平台查询）
     * @param string $idCard    身份证号明文
     * @param string $certType  当前表单所选证书类型（用于本地级别优先筛选）
     * @param string $certName  当前表单所选专业（可空）
     * @return array{need_captcha:bool,message:string,candidates:array,source:string}
     */
    public static function collect(int $staffId, string $idCard, string $certType = '', string $certName = ''): array
    {
        $idCard = trim($idCard);
        if ($idCard === '') {
            throw new \RuntimeException('缺少身份证号，无法在住建部平台查询');
        }

        // 第一步：身份证 → 住建部人员ID（同一身份证理论上唯一，仍兼容返回多条的情况）
        $list = self::queryStaffList($idCard);
        if (!$list) {
            return [
                'need_captcha' => false,
                'message'      => '住建部平台未查询到该人员的建造师注册信息',
                'candidates'   => [],
                'source'       => self::SOURCE,
            ];
        }

        // 第二步：逐个 staffId 取详情，汇总 regCertList
        $rows = [];
        foreach ($list as $person) {
            $ryId = trim((string)($person['RY_ID'] ?? ''));
            if ($ryId === '') {
                continue;
            }
            $detail = self::queryStaffDetail($ryId);
            foreach (($detail['regCertList'] ?? []) as $reg) {
                if (is_array($reg)) {
                    $rows[] = $reg;
                }
            }
        }

        $candidates = [];
        foreach ($rows as $reg) {
            $candidate = self::mapRegCert($reg);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        // 去重：同一级别+专业+有效期可能因多次注册重复出现
        $candidates = self::dedupe($candidates);

        // 本地按所选级别优先（一级建造师 / 二级建造师），与旧服务交互保持一致
        [$candidates, $message] = self::filterByRequestedLevel($candidates, $certType);

        return [
            'need_captcha' => false,
            'message'      => $message,
            'candidates'   => $candidates,
            'source'       => self::SOURCE,
        ];
    }

    /** 该服务是否支持某证书类型：仅建造师（含注册建造师） */
    public static function supports(string $certType): bool
    {
        $t = trim($certType);
        return $t !== '' && (mb_strpos($t, '建造师') !== false);
    }

    // ------------------------------------------------------------
    // 平台请求
    // ------------------------------------------------------------

    /** 第一步：人员检索，返回 data.list 原始数组 */
    private static function queryStaffList(string $idCard): array
    {
        $query = http_build_query([
            'complexname' => $idCard,
            'pg'          => 0,
            'pgsz'        => 15,
            'total'       => 0,
        ]);
        $res = self::httpGet(self::BASE_URL . self::LIST_URL . '?' . $query, self::BASE_URL . '/data/person');
        $json = json_decode($res['body'], true);
        if (!is_array($json) || (int)($json['code'] ?? 0) !== 200) {
            $msg = trim((string)($json['message'] ?? ''));
            throw new \RuntimeException('住建部平台人员查询失败' . ($msg !== '' ? '：' . $msg : '，请稍后重试'));
        }
        $list = $json['data']['list'] ?? [];
        return is_array($list) ? $list : [];
    }

    /** 第二步：人员详情，返回 data 节点（含 staffMap / regCertList / erMapList） */
    private static function queryStaffDetail(string $ryId): array
    {
        $query = http_build_query(['staffId' => $ryId]);
        $res = self::httpGet(self::BASE_URL . self::DETAIL_URL . '?' . $query, self::BASE_URL . '/data/person');
        $json = json_decode($res['body'], true);
        if (!is_array($json) || (int)($json['code'] ?? 0) !== 200) {
            $msg = trim((string)($json['message'] ?? ''));
            throw new \RuntimeException('住建部平台证书详情查询失败' . ($msg !== '' ? '：' . $msg : '，请稍后重试'));
        }
        $data = $json['data'] ?? [];
        return is_array($data) ? $data : [];
    }

    /**
     * 一条注册证书 → 台账候选（单专业、独立有效期）。
     * 字段命名与 HnPersonCollectService::mapRow 输出对齐，前端无需区分来源。
     */
    private static function mapRegCert(array $reg): ?array
    {
        // 仅保留有效注册；注销/作废等状态跳过，避免把过期失效专业带进台账
        $status = trim((string)($reg['REG_STATUS'] ?? ''));
        if ($status !== '' && $status !== self::VALID_STATUS) {
            return null;
        }

        $profName = self::clean((string)($reg['REG_PROF_NAME'] ?? ''));
        if ($profName === '') {
            return null;
        }

        $typeCode = trim((string)($reg['REG_TYPE'] ?? ''));
        $certType = self::TYPE_MAP[$typeCode] ?? self::normalizeTypeName((string)($reg['REG_TYPE_NAME'] ?? ''));
        if ($certType === '') {
            $certType = '一级建造师';
        }

        $certNo = self::clean((string)($reg['REG_CERTNO'] ?? ''))
            ?: self::clean((string)($reg['CERT_REG_NO'] ?? ''))
            ?: self::clean((string)($reg['REG_SEAL_CODE'] ?? ''));
        $issueDate = self::normDate((string)($reg['REG_SDATE'] ?? ''));
        $expireDate = self::normDate((string)($reg['REG_EDATE'] ?? ''));
        $company = self::clean((string)($reg['QY_NAME'] ?? ''));

        $remark = [];
        if ($company !== '') {
            $remark[] = '注册单位：' . $company;
        }
        $statusName = self::clean((string)($reg['REG_STATUS_NAME'] ?? ''));
        if ($statusName !== '') {
            $remark[] = '注册状态：' . $statusName;
        }
        $remark[] = '来源：' . self::SOURCE;

        return [
            'cert_type'   => $certType,
            'cert_name'   => $profName,
            'cert_no'     => $certNo,
            'issue_date'  => $issueDate,
            'expire_date' => $expireDate,
            'issue_org'   => '住房和城乡建设部',
            'remark'      => mb_substr(implode('；', $remark), 0, 255),
            'source'      => self::SOURCE,
        ];
    }

    /** 按所选证书级别优先：选了一级/二级建造师时，只保留对应级别，无命中则回退全部并提示 */
    private static function filterByRequestedLevel(array $candidates, string $certType): array
    {
        $level = '';
        if (mb_strpos($certType, '一级建造师') !== false) {
            $level = '一级建造师';
        } elseif (mb_strpos($certType, '二级建造师') !== false) {
            $level = '二级建造师';
        }
        if ($level === '' || !$candidates) {
            return [$candidates, $candidates ? '' : '住建部平台未查询到该人员的建造师注册信息'];
        }
        $matched = array_values(array_filter($candidates, function ($c) use ($level) {
            return (string)($c['cert_type'] ?? '') === $level;
        }));
        if ($matched) {
            return [$matched, ''];
        }
        return [$candidates, '平台未查询到该人员的' . $level . '证书，已列出其全部建造师注册专业，请核对后选择'];
    }

    /** 候选去重：级别 + 专业 + 编号 + 有效期 完全相同视为一条 */
    private static function dedupe(array $candidates): array
    {
        $seen = [];
        $out = [];
        foreach ($candidates as $c) {
            $key = implode('|', [
                $c['cert_type'] ?? '',
                $c['cert_name'] ?? '',
                $c['cert_no'] ?? '',
                $c['expire_date'] ?? '',
            ]);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $c;
        }
        return $out;
    }

    /** 「一级注册建造师」→「一级建造师」，对齐本地 cert_major 一级字典名 */
    private static function normalizeTypeName(string $name): string
    {
        $name = str_replace('注册', '', $name);
        $name = trim($name);
        if (mb_strpos($name, '一级建造师') !== false) {
            return '一级建造师';
        }
        if (mb_strpos($name, '二级建造师') !== false) {
            return '二级建造师';
        }
        return $name;
    }

    // ------------------------------------------------------------
    // HTTP 基础（GET JSON，免 Cookie/验证码；复用项目 CA 证书）
    // ------------------------------------------------------------

    private static function httpGet(string $url, string $referer): array
    {
        $caFile = rtrim((string)app()->getRootPath(), '/\\') . DIRECTORY_SEPARATOR . 'extend'
            . DIRECTORY_SEPARATOR . 'cert' . DIRECTORY_SEPARATOR . 'cacert.pem';

        $ch = curl_init();
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json, text/plain, */*',
                'Accept-Language: zh-CN,zh;q=0.9',
                'Referer: ' . $referer,
            ],
        ];
        if (is_file($caFile)) {
            $opts[CURLOPT_CAINFO] = $caFile;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $err !== '') {
            Log::warning('住建部平台人员采集请求失败：' . $err . ' @ ' . $url);
            throw new \RuntimeException('连接住房和城乡建设部平台失败，请检查服务器网络后重试');
        }
        if ($code >= 400) {
            Log::warning('住建部平台人员采集HTTP异常：' . $code . ' @ ' . $url);
            throw new \RuntimeException('住建部平台暂时无法访问（HTTP ' . $code . '），请稍后重试');
        }
        return ['status' => $code, 'body' => (string)$body];
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
        if (preg_match('/^\d{10,13}$/', $v)) {
            $ts = (int)$v;
            if ($ts > 9999999999) {
                $ts = intdiv($ts, 1000);
            }
            return date('Y-m-d', $ts);
        }
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/', $v, $m)) {
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }
        $ts = strtotime($v);
        return $ts ? date('Y-m-d', $ts) : '';
    }
}
