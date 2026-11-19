<?php
namespace app\service;

/**
 * 报名校验规则引擎（配置驱动，禁止写死业务规则）
 *
 * 移植自 exam-reg RuleEngineService，去掉外部依赖。
 * 规则结构：[{ field, op, value, tip }]
 * 运算符：>= <= == != between in exists expire_before
 * 字段示例：age education work_years cert_status cert_expire_date
 *           has_business_license has_physical_exam
 */
class ExamRuleService
{
    /** 学历等级（用于比较运算） */
    public const EDU_RANK = [
        '小学' => 1, '初中' => 2, '高中' => 3, '中专' => 4,
        '大专' => 5, '本科' => 6, '硕士' => 7, '博士' => 8,
    ];

    /** 字段中文名（用于提示） */
    public const FIELD_LABEL = [
        'age'                  => '年龄',
        'education'            => '学历',
        'edu_type'             => '学历性质',
        'edu_cert_no'          => '毕业证书编号',
        'graduate_date'        => '毕业日期',
        'graduate_years'       => '毕业年限',
        'work_years'           => '工作年限',
        'cert_status'          => '证书状态',
        'cert_expire_date'     => '证书有效期至',
        'has_business_license' => '营业执照',
        'has_physical_exam'    => '体检证明',
        'gender'               => '性别',
        'city'                 => '所属地市',
        'unit_name'            => '单位名称',
    ];

    /** 学历性质候选 */
    public const EDU_TYPES = ['无', '全日制', '非全日制'];

    /** 规则归一化（容错：字符串/单对象/缺字段） */
    public static function normalize($rules): array
    {
        if (is_string($rules)) {
            $rules = json_decode($rules, true) ?: [];
        }
        $rules = (array)$rules;
        if ($rules && !array_is_list($rules)) {
            $rules = [$rules];
        }
        $out = [];
        foreach ($rules as $r) {
            if (!is_array($r)) {
                continue;
            }
            $field = (string)($r['field'] ?? '');
            if ($field === '') {
                continue;
            }
            $out[] = [
                'field' => $field,
                'op'    => (string)($r['op'] ?? '=='),
                'value' => $r['value'] ?? null,
                'tip'   => (string)($r['tip'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * 执行规则，返回每条明细
     * @return array{pass:bool,items:array}
     */
    public static function evaluate(array $rules, array $ctx): array
    {
        $items = [];
        $all   = true;
        foreach ($rules as $r) {
            if (!is_array($r)) {
                continue;
            }
            $field  = (string)($r['field'] ?? '');
            $op     = (string)($r['op'] ?? '==');
            $value  = $r['value'] ?? null;
            $tip    = (string)($r['tip'] ?? '');
            $actual = $ctx[$field] ?? null;
            $pass   = self::check($field, $op, $value, $actual);
            if (!$pass) {
                $all = false;
            }
            $items[] = [
                'field'  => $field,
                'label'  => self::FIELD_LABEL[$field] ?? $field,
                'op'     => $op,
                'value'  => $value,
                'actual' => $actual,
                'pass'   => $pass,
                'tip'    => $tip !== '' ? $tip : (self::FIELD_LABEL[$field] ?? $field) . '不满足条件',
            ];
        }
        return ['pass' => $all, 'items' => $items];
    }

    /** 单条规则判定 */
    public static function check(string $field, string $op, $expect, $actual): bool
    {
        switch ($op) {
            case '>=':
                return self::cmp($field, $actual, $expect) >= 0;
            case '<=':
                return self::cmp($field, $actual, $expect) <= 0;
            case '==':
                return self::eq($field, $actual, $expect);
            case '!=':
                return !self::eq($field, $actual, $expect);
            case 'between':
                if (!is_array($expect) || count($expect) < 2) {
                    return false;
                }
                return self::cmp($field, $actual, $expect[0]) >= 0
                    && self::cmp($field, $actual, $expect[1]) <= 0;
            case 'in':
                if (!is_array($expect)) {
                    return false;
                }
                foreach ($expect as $v) {
                    if (self::eq($field, $actual, $v)) {
                        return true;
                    }
                }
                return false;
            case 'exists':
                return $actual !== null && $actual !== '' && $actual !== [];
            case 'expire_before':
                // 证书有效期满落在 [今天, 今天+N天] 窗口内
                $days = (int)$expect;
                if (!$actual) {
                    return false;
                }
                $ts  = strtotime((string)$actual);
                $now = strtotime(date('Y-m-d'));
                if ($ts === false) {
                    return false;
                }
                return $ts >= $now && $ts <= strtotime("+{$days} day", $now);
            default:
                return false;
        }
    }

    /** 比较（支持数值 / 学历等级 / 日期） */
    private static function cmp(string $field, $a, $b): int
    {
        if ($field === 'education') {
            $a = self::EDU_RANK[(string)$a] ?? 0;
            $b = self::EDU_RANK[(string)$b] ?? 0;
            return $a <=> $b;
        }
        if ($field === 'cert_expire_date' || str_ends_with($field, '_date') || str_ends_with($field, '_at')) {
            $a = $a ? strtotime((string)$a) : 0;
            $b = $b ? strtotime((string)$b) : 0;
            return ($a ?: 0) <=> ($b ?: 0);
        }
        return (float)$a <=> (float)$b;
    }

    /** 相等（布尔 / 学历 / 普通） */
    private static function eq(string $field, $a, $b): bool
    {
        if (is_bool($b)) {
            return (bool)$a === $b;
        }
        if ($field === 'education') {
            return (self::EDU_RANK[(string)$a] ?? 0) === (self::EDU_RANK[(string)$b] ?? 0);
        }
        return (string)$a === (string)$b;
    }
}
