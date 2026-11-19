<?php
namespace app\service;

use app\model\SystemConfig;

/**
 * 腾讯云 OCR 身份证识别（v2.86）
 *
 * 零依赖：不引入 tencentcloud-sdk-php，直接按腾讯云 API 3.0 签名 v3
 * （TC3-HMAC-SHA256）调用 ocr.tencentcloudapi.com 的 IDCardOCR。
 *
 * 配置存 system_config：
 *   tencent_ocr_enable    1/0
 *   tencent_ocr_secret_id  SecretId（敏感，自动掩码）
 *   tencent_ocr_secret_key SecretKey（敏感，自动掩码）
 *   tencent_ocr_region     地域（默认 ap-guangzhou）
 *
 * 返回统一映射：name/id_card/gender(1男2女)/birthday(Y-m-d)/nation/address。
 */
class ExamOcrService
{
    public const HOST = 'ocr.tencentcloudapi.com';
    public const SERVICE = 'ocr';
    public const ACTION = 'IDCardOCR';
    public const ACTION_DIPLOMA = 'GeneralAccurateOCR'; // 毕业证无专用接口，用通用印刷体高精度
    public const VERSION = '2018-11-19';

    /** 读取 OCR 配置（表异常回退默认） */
    public static $override = [];
    private static $configCache = null;

    public static function config(): array
    {
        if (self::$configCache !== null) {
            return self::$configCache;
        }
        $map = [];
        try {
            foreach (SystemConfig::select()->toArray() as $r) {
                $map[$r['config_key']] = (string)$r['config_value'];
            }
        } catch (\Throwable $e) {
            $map = [];
        }
        $ov = self::$override;
        return self::$configCache = [
            'enable'     => isset($ov['enable']) ? $ov['enable'] === '1' : trim($map['tencent_ocr_enable'] ?? '0') === '1',
            'secret_id'  => isset($ov['secret_id']) ? (string)$ov['secret_id'] : trim($map['tencent_ocr_secret_id'] ?? ''),
            'secret_key' => isset($ov['secret_key']) ? (string)$ov['secret_key'] : trim($map['tencent_ocr_secret_key'] ?? ''),
            'region'     => (isset($ov['region']) && $ov['region'] !== '') ? (string)$ov['region']
                : (trim($map['tencent_ocr_region'] ?? '') ?: 'ap-guangzhou'),
        ];
    }

    public static function enabled(): bool
    {
        $c = self::config();
        return $c['enable'] && $c['secret_id'] !== '' && $c['secret_key'] !== '';
    }

    /** 用临时覆盖配置测试连通性（系统设置未保存先测） */
    public static function testWith(array $override): array
    {
        $prev = self::$override;
        self::$override = $override;
        self::$configCache = null;
        try {
            return self::testConfig();
        } finally {
            self::$override = $prev;
            self::$configCache = null;
        }
    }

    /**
     * 识别身份证
     *
     * @param string $imageBase64 图片 base64（不带 data: 前缀）
     * @param string $cardSide    FRONT 人像面 / BACK 国徽面
     * @return array {ok, msg, data}
     */
    public static function idCard(string $imageBase64, string $cardSide = 'FRONT'): array
    {
        $cfg = self::config();
        if (!self::enabled()) {
            return ['ok' => false, 'msg' => '未启用腾讯云 OCR 或密钥未配置（系统设置）'];
        }
        $imageBase64 = preg_replace('#^data:image/[^;]+;base64,#', '', trim($imageBase64));
        if ($imageBase64 === '' || !base64_decode($imageBase64, true)) {
            return ['ok' => false, 'msg' => '图片数据无效'];
        }
        $cardSide = strtoupper($cardSide) === 'BACK' ? 'BACK' : 'FRONT';
        $payload = json_encode([
            'ImageBase64' => $imageBase64,
            'CardSide'    => $cardSide,
        ], JSON_UNESCAPED_UNICODE);

        $resp = self::call($payload, $cfg);
        if (!$resp['ok']) {
            return $resp;
        }
        $d = $resp['data']['Response'] ?? [];
        if (isset($d['Error'])) {
            return ['ok' => false, 'msg' => '腾讯云OCR：' . ($d['Error']['Message'] ?? '识别失败')];
        }

        if ($cardSide === 'BACK') {
            // 国徽面：签发机关/有效期限
            return ['ok' => true, 'side' => 'BACK', 'data' => [
                'issue_authority' => (string)($d['Authority'] ?? ''),
                'valid_period'    => (string)($d['ValidDate'] ?? ''),
            ]];
        }

        // 人像面：姓名/性别/民族/出生/住址/公民身份号码
        $name = trim((string)($d['Name'] ?? ''));
        $idNum = strtoupper(trim((string)($d['IdNum'] ?? '')));
        $sexRaw = trim((string)($d['Sex'] ?? ''));
        $gender = $sexRaw === '女' ? 2 : ($sexRaw === '男' ? 1 : 0);
        $birthday = self::normBirth((string)($d['Birth'] ?? ''), $idNum);
        $address = trim((string)($d['Address'] ?? ''));
        $nation = trim((string)($d['Nation'] ?? ''));

        if ($name === '' && $idNum === '') {
            return ['ok' => false, 'msg' => '未识别到身份证信息，请确认拍摄的是身份证人像面'];
        }
        return ['ok' => true, 'side' => 'FRONT', 'data' => [
            'name'     => $name,
            'id_card'  => $idNum,
            'gender'   => $gender,
            'sex_text' => $sexRaw,
            'birthday' => $birthday,
            'nation'   => $nation,
            'address'  => $address,
        ]];
    }

    /**
     * 识别毕业证书（v2.87）
     *
     * 腾讯云无毕业证专用接口，采用「通用印刷体高精度 GeneralAccurateOCR」
     * 返回全部文本行，再在服务端正则解析：毕业学校 / 毕业证编号 / 毕业日期。
     *
     * @param string $imageBase64 图片 base64（不带 data: 前缀）
     * @return array {ok, msg, data:{school,cert_no,graduate_date}}
     */
    public static function diploma(string $imageBase64): array
    {
        $cfg = self::config();
        if (!self::enabled()) {
            return ['ok' => false, 'msg' => '未启用腾讯云 OCR 或密钥未配置（系统设置）'];
        }
        $imageBase64 = preg_replace('#^data:image/[^;]+;base64,#', '', trim($imageBase64));
        if ($imageBase64 === '' || !base64_decode($imageBase64, true)) {
            return ['ok' => false, 'msg' => '图片数据无效'];
        }
        $payload = json_encode(['ImageBase64' => $imageBase64], JSON_UNESCAPED_UNICODE);

        $resp = self::call($payload, $cfg, self::ACTION_DIPLOMA);
        if (!$resp['ok']) {
            return $resp;
        }
        $d = $resp['data']['Response'] ?? [];
        if (isset($d['Error'])) {
            return ['ok' => false, 'msg' => '腾讯云OCR：' . ($d['Error']['Message'] ?? '识别失败')];
        }
        $lines = [];
        foreach (($d['TextDetections'] ?? []) as $td) {
            $t = trim((string)($td['DetectedText'] ?? ''));
            if ($t !== '') {
                $lines[] = $t; // 保留空格，parseDiploma 内部自行压缩/切段
            }
        }
        if (!$lines) {
            return ['ok' => false, 'msg' => '未识别到毕业证文字，请重新拍摄（完整、清晰、正对证书）'];
        }
        $parsed = self::parseDiploma($lines);
        if ($parsed['school'] === '' && $parsed['cert_no'] === '' && empty($parsed['graduate_date'])) {
            return ['ok' => false, 'msg' => '未解析到毕业学校/编号/日期，请确认拍摄的是毕业证书'];
        }
        return ['ok' => true, 'data' => $parsed];
    }

    /**
     * 从毕业证文本行解析学校 / 证书编号 / 毕业日期
     * 兼容普通高校、成人、中专等版式：编号可能标注「证书编号/电子注册号/学校编号」，
     * 日期形如「二〇一二年六月三十日」或「2018年06月30日」。
     */
    private static function parseDiploma(array $lines): array
    {
        // 保留原始未压缩行（含空格，便于按 在/于 切分校名）；另备压缩行做标签匹配
        $rawLines = $lines;
        $lines = array_map(static fn($s) => preg_replace('/\s+/u', '', $s), $rawLines);
        $all = implode("\n", $lines);

        // ---------- 毕业日期（取证面最后一个日期：顶部通常是出生日期） ----------
        $graduateDate = null;
        // 阿拉伯数字日期：2018年06月30日
        if (preg_match_all('/(20\d{2}|19\d{2})\s*年\s*(\d{1,2})\s*月\s*(\d{1,2})?\s*日?/u', $all, $mm, PREG_SET_ORDER)
            && ($last = end($mm))) {
            $graduateDate = sprintf('%04d-%02d-%02d', (int)$last[1], (int)$last[2], (int)($last[3] ?: 1));
        } elseif (preg_match_all('/(20\d{2}|19\d{2})[\-\/\.](\d{1,2})(?:[\-\/\.](\d{1,2}))?/u', $all, $mm, PREG_SET_ORDER)
            && ($last = end($mm))) {
            $graduateDate = sprintf('%04d-%02d-%02d', (int)$last[1], (int)$last[2], (int)($last[3] ?: 1));
        } elseif (preg_match_all('/([二〇零一二三四五六七八九O]{4})年([\d一二三四五六七八九十]{1,3})月(?:([\d一二三四五六七八九十]{1,3})日)?/u', $all, $mm, PREG_SET_ORDER)
            && ($last = end($mm))) {
            // 中文数字日期：二〇一八年六月三十日
            $y = self::cnYear($last[1]);
            $mo = self::cnNumber($last[2]);
            $da = !empty($last[3]) ? self::cnNumber($last[3]) : 1;
            if ($y) {
                $graduateDate = sprintf('%04d-%02d-%02d', $y, $mo ?: 1, $da ?: 1);
            }
        }

        // ---------- 毕业证编号（带标签优先，标签值不做身份证误判） ----------
        $certNo = '';
        $labelRe = '/(?:证书编号|电子注册号|注册号|学校编号|证书序号|毕证字第?|电子注册备案号)/u';
        foreach ($lines as $ln) {
            if (!preg_match($labelRe, $ln) && !preg_match('/(?:No|NO|no)[\.．:：]/u', $ln)) {
                continue;
            }
            // 去掉标签本身及 No. 等前缀，取后面首个 6~20 位字母数字串
            $tail = preg_replace('/^.*(?:证书编号|电子注册号|注册号|学校编号|证书序号|毕证字第?|电子注册备案号|No|NO|no)[\.．:：]?/u', '', $ln);
            if (preg_match('/(?<![0-9A-Za-z])([0-9]{6,19}[A-Za-z]?|[0-9A-Za-z]{8,20})(?![0-9A-Za-z])/u', $tail, $c)) {
                $certNo = strtoupper($c[1]);
                break;
            }
        }
        if ($certNo === '') {
            // 无标签：取独立成行/行尾的长数字串（排除身份证与短年份）
            foreach ($lines as $ln) {
                if (preg_match('/(?<![0-9A-Za-z])([0-9]{16,19}[A-Za-z]?)(?![0-9A-Za-z年月])/u', $ln, $m)) {
                    if (!preg_match('/^\d{17}[\dX]$/', $m[1])) {
                        $certNo = strtoupper($m[1]);
                        break;
                    }
                }
            }
        }

        // ---------- 毕业学校 ----------
        $school = '';
        // 证面通用标题黑名单（含「学校」但不是校名）
        $titleBlacklist = ['普通高等学校', '成人高等学校', '成人高等教育', '高等教育自学考试',
            '中等专业学校', '职业技术学校毕业证书', '毕业证书', '结业证书', '学位证书'];
        // 1) 显式标签
        foreach ($lines as $ln) {
            if (preg_match('/(?:校名|毕业院校|毕业学校|学校名称|院校名称|就读院校|培养单位)[：:\s]*(.+)$/u', $ln, $m)) {
                $school = self::cleanSchool($m[1]);
                break;
            }
        }
        // 2) 在含校名关键字的行里，按「在/于/逗号」切段后取以大学/学院/学校结尾的短词
        if ($school === '') {
            $best = '';
            $bestScore = -1;
            foreach ($rawLines as $i => $raw) {
                $compact = preg_replace('/\s+/u', '', $raw);
                if (!preg_match('/(大学|学院|学校)/u', $compact)) {
                    continue;
                }
                if (preg_match('/(毕业证书|毕业証|结业证书|学位证书|批准|教育部|监制)/u', $compact)) {
                    continue;
                }
                $segments = preg_split('/[在于，,。；;：:、\s]+/u', $raw);
                foreach ($segments as $seg) {
                    $seg = preg_replace('/\s+/u', '', $seg);
                    if (!preg_match('/([\x{4e00}-\x{9fa5}（）()]{2,24}?(?:大学|学院|学校))/u', $seg, $sm)) {
                        continue;
                    }
                    $candidate = self::cleanSchool($sm[1]);
                    if ($candidate === '' || in_array($candidate, $titleBlacklist, true)) {
                        continue;
                    }
                    $score = 0;
                    if (preg_match('/(大学|学院|学校)$/u', $candidate)) {
                        $score += 3;
                    }
                    $len = mb_strlen($candidate);
                    if ($len >= 6 && $len <= 24) {
                        $score += 2;
                    }
                    if ($i < count($rawLines) * 0.7) {
                        $score += 1; // 校名一般在证面上部（编号/日期之下，学生信息之上，整体偏前）
                    }
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $best = $candidate;
                    }
                }
            }
            $school = $best;
        }

        return [
            'school'         => $school,
            'cert_no'        => $certNo,
            'graduate_date'  => $graduateDate,
        ];
    }

    /** 清洗校名行：去标签前缀、标点、「校长/专业」等尾部噪声 */
    private static function cleanSchool(string $s): string
    {
        $s = preg_replace('/^.*(?:毕业院校|毕业学校|学校名称|院校名称|就读院校|培养单位)[：:\s]*/u', '', $s);
        $s = preg_replace('/[，,。.；;].*$/u', '', $s);
        $s = preg_replace('/(校长|院长|签名|盖章|印章|专业|院系|本科|专科|函授|业余).*$/u', '', $s);
        return trim($s, " 　\t\n\r\0\x0B");
    }

    /** 中文四位年份（二〇一二 / 二O一二）→ 2012 */
    private static function cnYear(string $s): int
    {
        $map = ['〇' => 0, '零' => 0, 'O' => 0, 'o' => 0, '一' => 1, '二' => 2,
                '三' => 3, '四' => 4, '五' => 5, '六' => 6, '七' => 7, '八' => 8, '九' => 9];
        $s = str_replace('两', '二', $s);
        $y = 0;
        $len = mb_strlen($s);
        if ($len !== 4) {
            return 0;
        }
        for ($i = 0; $i < 4; $i++) {
            $ch = mb_substr($s, $i, 1);
            if (!isset($map[$ch])) {
                return 0;
            }
            $y = $y * 10 + $map[$ch];
        }
        // 公元年份转译（“二〇一二” 即 2012）
        return $y >= 100 ? $y : 0;
    }

    /** 中文数字（一~九十九），用于月/日 */
    private static function cnNumber(string $s): int
    {
        if ($s === '') {
            return 0;
        }
        if (preg_match('/^\d+$/', $s)) {
            return (int)$s;
        }
        $map = ['一' => 1, '二' => 2, '两' => 2, '三' => 3, '四' => 4, '五' => 5,
                '六' => 6, '七' => 7, '八' => 8, '九' => 9];
        if ($s === '十') {
            return 10;
        }
        if (preg_match('/^十(.)$/u', $s, $m)) {
            return 10 + ($map[$m[1]] ?? 0);
        }
        if (preg_match('/^(.)十(.)?$/u', $s, $m)) {
            return ($map[$m[1]] ?? 0) * 10 + (isset($map[$m[2] ?? '']) ? $map[$m[2]] : 0);
        }
        $n = 0;
        $len = mb_strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($s, $i, 1);
            if (!isset($map[$ch])) {
                return 0;
            }
            $n = $n * 10 + $map[$ch];
        }
        return $n;
    }

    /** 腾讯云出生格式为 YYYY*M*D（如 1990/3/7），统一为 Y-m-d；缺失时从身份证推导 */
    private static function normBirth(string $birth, string $idNum): ?string
    {
        if ($birth !== '') {
            if (preg_match('/(\d{4})\D+(\d{1,2})\D+(\d{1,2})/', $birth, $m)) {
                return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
            }
            if (($t = strtotime($birth)) !== false) {
                return date('Y-m-d', $t);
            }
        }
        if (preg_match('/^\d{17}[\dXx]$/', $idNum)) {
            return substr($idNum, 6, 4) . '-' . substr($idNum, 10, 2) . '-' . substr($idNum, 12, 2);
        }
        return null;
    }

    /** 发起 TC3-HMAC-SHA256 签名请求（v2.87：$action 支持身份证/毕业证等多接口） */
    private static function call(string $payload, array $cfg, string $action = self::ACTION): array
    {
        $timestamp = time();
        $date = gmdate('Y-m-d', $timestamp);

        // 1. 规范请求串
        $httpMethod = 'POST';
        $canonicalUri = '/';
        $canonicalQuery = '';
        $canonicalHeaders = 'content-type:application/json; charset=utf-8' . "\n"
            . 'host:' . self::HOST . "\n"
            . 'x-tc-action:' . strtolower($action) . "\n";
        $signedHeaders = 'content-type;host;x-tc-action';
        $hashedPayload = hash('SHA256', $payload);
        $canonicalRequest = implode("\n", [
            $httpMethod, $canonicalUri, $canonicalQuery,
            $canonicalHeaders, $signedHeaders, $hashedPayload,
        ]);

        // 2. 待签名字符串
        $algorithm = 'TC3-HMAC-SHA256';
        $credentialScope = $date . '/' . self::SERVICE . '/tc3_request';
        $stringToSign = implode("\n", [
            $algorithm, $timestamp, $credentialScope, hash('SHA256', $canonicalRequest),
        ]);

        // 3. 逐层派生签名
        $secretDate = hash_hmac('SHA256', $date, 'TC3' . $cfg['secret_key'], true);
        $secretService = hash_hmac('SHA256', self::SERVICE, $secretDate, true);
        $secretSigning = hash_hmac('SHA256', 'tc3_request', $secretService, true);
        $signature = hash_hmac('SHA256', $stringToSign, $secretSigning);

        $authorization = $algorithm . ' '
            . 'Credential=' . $cfg['secret_id'] . '/' . $credentialScope . ', '
            . 'SignedHeaders=' . $signedHeaders . ', '
            . 'Signature=' . $signature;

        $headers = [
            'Authorization: ' . $authorization,
            'Content-Type: application/json; charset=utf-8',
            'Host: ' . self::HOST,
            'X-TC-Action: ' . $action,
            'X-TC-Timestamp: ' . $timestamp,
            'X-TC-Version: ' . self::VERSION,
            'X-TC-Region: ' . $cfg['region'],
        ];

        $ch = curl_init('https://' . self::HOST);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $errmsg = curl_error($ch);
        // 部分 Windows/phpstudy 环境未配置 CA 证书（curl.cainfo 为空），
        // 仅在证书校验失败时降级为不校验，保证 OCR 可用；其他错误直接返回。
        if ($errno === CURLE_SSL_CACERT || $errno === CURLE_SSL_PEER_CERTIFICATE
            || str_contains($errmsg, 'certificate')) {
            curl_close($ch);
            $ch = curl_init('https://' . self::HOST);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $body = curl_exec($ch);
            $errno = curl_errno($ch);
            $errmsg = curl_error($ch);
        }
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            return ['ok' => false, 'msg' => '网络请求失败：' . $errmsg];
        }
        $json = json_decode((string)$body, true);
        if (!is_array($json)) {
            return ['ok' => false, 'msg' => '腾讯云返回异常（HTTP ' . $httpCode . '）：' . mb_substr((string)$body, 0, 200)];
        }
        return ['ok' => true, 'data' => $json];
    }

    /** 测试配置是否可用（系统设置「测试」按钮） */
    public static function testConfig(): array
    {
        if (!self::enabled()) {
            return ['ok' => false, 'msg' => '未启用或 SecretId/SecretKey 为空'];
        }
        // 用 1x1 无效像素请求，预期返回业务参数错误（证明鉴权链路通），
        // 若返回 AuthFailure.SecretIdNotFound/SignatureFailure 则说明密钥问题。
        $pixel = base64_encode('GIF89a');
        $r = self::idCard($pixel, 'FRONT');
        if ($r['ok']) {
            return ['ok' => true, 'msg' => '配置有效'];
        }
        $msg = $r['msg'] ?? '';
        // 网络/服务端异常 → 无法确认配置，直接失败
        if (str_contains($msg, '网络') || str_contains($msg, '腾讯云返回异常')) {
            return ['ok' => false, 'msg' => $msg];
        }
        // 明确的鉴权/签名错误 → 配置无效；
        // 其余错误（图片无效等业务错误）说明鉴权链路已通，配置有效。
        if ($msg !== '' && preg_match('/AuthFailure|SecretIdNotFound|SecretIdNotExist|SecretId is not found|SignatureFailure|signature|UnauthorizedOperation|secretid.*not.*correct|token.*invalid|身份|密钥|签名/i', $msg)) {
            return ['ok' => false, 'msg' => $msg];
        }
        // 鉴权已通过、仅图片无效 → 配置本身可用
        return ['ok' => true, 'msg' => '配置有效（鉴权通过）'];
    }
}
