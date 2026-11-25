<?php
namespace app\service;

use think\facade\Db;

/**
 * 属地政策规则引擎 —— 产品精髓「多维关联」
 *
 * 解决痛点：不同地市对「社保缴纳地」「证书有效期余量」「可用证书类型」要求不同，
 * 人工梳理极易出错。本服务把规则落到 policy_rule 表（可配置化），
 * 由 PHP 侧求值，零外部依赖，任何管理员都能在页面上配一条新地市规则。
 *
 * 规则参数 rule_expr 采用扁平键值对（如 "min_expire_days=90;require_social=1"），
 * 而非 JSON：MySQL 5.7 无 JSON 函数索引，且求值发生在 PHP 侧，键值对最易读易配。
 */
class PolicyRuleService
{
    /** 规则类型定义 */
    public const TYPES = [
        'social_match' => '社保匹配',
        'expiry_limit' => '有效期下限',
        'cert_limit'   => '证书类型限制',
    ];

    /** 各规则类型可用的参数键（前端据此动态渲染表单） */
    public const PARAMS = [
        'social_match' => [
            ['key' => 'require_social', 'label' => '要求社保在缴', 'type' => 'switch', 'default' => 1],
        ],
        'expiry_limit' => [
            ['key' => 'min_expire_days', 'label' => '剩余有效期下限(天)', 'type' => 'number', 'default' => 90],
        ],
        'cert_limit'   => [
            ['key' => 'allow', 'label' => '允许的证书专业(逗号分隔)', 'type' => 'text', 'default' => ''],
        ],
    ];

    /** 违规级别 */
    public const SEVERITY = [0 => '提示', 1 => '警告', 2 => '阻断'];

    /**
     * 取项目属地；无项目返回空（此时仅全国通用规则生效）
     */
    public static function regionOf(int $projectId): array
    {
        if (!$projectId) {
            return ['code' => '', 'name' => ''];
        }
        try {
            $p = Db::name('project')->where('id', $projectId)
                ->field('region_code,region_name')->find();
        } catch (\Throwable $e) {
            return ['code' => '', 'name' => ''];
        }
        return $p ? ['code' => (string)($p['region_code'] ?? ''), 'name' => (string)($p['region_name'] ?? '')]
                  : ['code' => '', 'name' => ''];
    }

    /**
     * 解析 rule_expr：'a=1;b=2' => ['a'=>'1','b'=>'2']
     */
    public static function parseExpr(string $expr): array
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode(';', (string)$expr))) as $seg) {
            if (strpos($seg, '=') === false) {
                continue;
            }
            [$k, $v] = explode('=', $seg, 2);
            $out[trim($k)] = trim($v);
        }
        return $out;
    }

    /** 反向组装：['a'=>'1'] => 'a=1' */
    public static function buildExpr(array $params): string
    {
        $parts = [];
        foreach ($params as $k => $v) {
            $parts[] = $k . '=' . $v;
        }
        return implode(';', $parts);
    }

    /**
     * 取适用规则：属地精确匹配 + 全国通用(region_code='')，证书类别/专业为空表示全部
     */
    public static function rules(int $projectId, string $certCategory = '', string $certName = ''): array
    {
        $region = self::regionOf($projectId);
        $q = Db::name('policy_rule')->where('status', 1)
            ->where(function ($w) use ($region) {
                $w->where('region_code', $region['code'])
                  ->whereOr(function ($w2) { $w2->where('region_code', ''); });
            });
        if ($certCategory !== '') {
            $q->where(function ($w) use ($certCategory) {
                // v2.36：规则的适用维度已统一为「证书类型」，但 v2.36 之前建的规则
                // 只写了 cert_category，故两个维度都要认，避免老规则静默失配。
                $w->where('cert_category', '')
                  ->whereOr('cert_category', $certCategory)
                  ->whereOr('cert_type', $certCategory);
            });
        }
        if ($certName !== '') {
            $q->where(function ($w) use ($certName) {
                $w->where('cert_name', '')->whereOr('cert_name', $certName);
            });
        }
        return $q->order('severity', 'desc')->order('id', 'asc')->select()->toArray();
    }

    /**
     * 评估单证书是否满足属地政策
     *
     * @param array $cert 证书行（含 cert_name/category/expire_date/social_status）
     * @param int $projectId 项目ID（提供属地）
     * @param array $social 人档社保（可选，含 status），传入可支持「社保上移至人档」后的判定
     * @return array 违规项数组，结构：['type'=>'policy','level'=>int,'title'=>string,'detail'=>string,'ref_id'=>int]
     */
    public static function evaluateCert(array $cert, int $projectId = 0, array $social = []): array
    {
        $region = self::regionOf($projectId);
        $issues = [];
        $today  = date('Y-m-d');
        $src    = $region['name'] !== '' ? ($region['name'] . '政策') : '通用政策';

        // 社保状态取人档优先
        $socialStatus = (int)($social['status'] ?? ($cert['social_status'] ?? 0));

        $certCategory = (string)($cert['category'] ?? '');
        $certName     = (string)($cert['cert_name'] ?? '');
        // v2.99.79：多专业证书按拆分后的专业名匹配规则（任一专业命中即适用）
        $certNames    = CertDictService::splitNames($certName) ?: [$certName];

        $rules = self::rules($projectId, $certCategory, '');
        foreach ($rules as $rule) {
            $ruleName = trim((string)($rule['cert_name'] ?? ''));
            if ($ruleName !== '' && !in_array($ruleName, $certNames, true)) {
                continue;
            }
            $p     = self::parseExpr((string)($rule['rule_expr'] ?? ''));
            $level = (int)($rule['severity'] ?? 1);

            switch ($rule['rule_type']) {
                case 'social_match':
                    if (($p['require_social'] ?? '') === '1' && $socialStatus !== 1) {
                        $issues[] = [
                            'type'   => 'policy',
                            'level'  => $level,
                            'title'  => '社保未在缴',
                            'detail' => "{$src}要求该证书持证人社保在缴，当前状态为「" . self::socialText($socialStatus) . '」',
                            'ref_id' => (int)$rule['id'],
                        ];
                    }
                    break;

                case 'expiry_limit':
                    $min = (int)($p['min_expire_days'] ?? 0);
                    if ($min > 0) {
                        $remain = self::remainDays($cert['expire_date'] ?? '');
                        if ($remain !== null && $remain < $min) {
                            $issues[] = [
                                'type'   => 'policy',
                                'level'  => $level,
                                'title'  => '证书有效期不足',
                                'detail' => "{$src}要求剩余有效期不少于 {$min} 天，当前剩余 " . max(0, $remain) . ' 天',
                                'ref_id' => (int)$rule['id'],
                            ];
                        }
                    }
                    break;

                case 'cert_limit':
                    $allow = array_values(array_filter(array_map('trim', explode(',', (string)($p['allow'] ?? '')))));
                    // v2.99.79：多专业证书只要任一专业在允许清单内即通过
                    if ($allow && !array_intersect($certNames, $allow)) {
                        $issues[] = [
                            'type'   => 'policy',
                            'level'  => $level,
                            'title'  => '证书类型不符属地要求',
                            'detail' => "{$src}仅允许：" . implode('、', $allow),
                            'ref_id' => (int)$rule['id'],
                        ];
                    }
                    break;
            }
        }
        return $issues;
    }

    /**
     * 评估是否通过（无阻断项即通过）
     */
    public static function passes(array $issues): bool
    {
        foreach ($issues as $i) {
            if ((int)($i['level'] ?? 0) >= 2) {
                return false;
            }
        }
        return true;
    }

    /** 剩余天数（负数表示已过期） */
    protected static function remainDays(string $expireDate): ?int
    {
        if (!$expireDate) {
            return null;
        }
        $t = strtotime($expireDate);
        if ($t === false) {
            return null;
        }
        return (int)ceil(($t - strtotime(date('Y-m-d'))) / 86400);
    }

    /** 社保状态文本 */
    public static function socialText(int $status): string
    {
        return [0 => '未知', 1 => '在缴', 2 => '停缴', 3 => '未缴纳'][$status] ?? '未知';
    }

    /**
     * 全库规则命中概览（管理端展示用）：各属地规则数与违规项统计
     */
    public static function overview(int $projectId = 0): array
    {
        $rows = $projectId > 0
            ? self::rules($projectId)
            : Db::name('policy_rule')->where('status', 1)->order('id', 'asc')->select()->toArray();

        $byRegion = [];
        $byType   = [];
        foreach ($rows as $r) {
            $rn = (string)($r['region_name'] ?: '') ?: '全国通用';
            $byRegion[$rn] = ($byRegion[$rn] ?? 0) + 1;
            $tn = self::TYPES[$r['rule_type']] ?? $r['rule_type'];
            $byType[$tn] = ($byType[$tn] ?? 0) + 1;
        }
        return [
            'total'     => count($rows),
            'by_region' => $byRegion,
            'by_type'   => $byType,
            'rules'     => $rows,
        ];
    }
}