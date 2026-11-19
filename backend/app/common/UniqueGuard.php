<?php
namespace app\common;

use think\db\exception\PDOException as DbPDOException;

/**
 * 唯一键冲突统一处理（v2.99.1）
 *
 * 背景：多个控制器直接用模型 create()/save() 落库，遇到唯一索引冲突时
 * 抛出 PDOException（SQLSTATE 23000 / MySQL 1062），最终被全局异常处理器
 * 兜成「code=500 系统错误」。用户只看到“系统错误”，既不知道哪一项重复，
 * 也无法自助修正，同时污染错误日志。
 *
 * 本类提供两类能力：
 *  1. guard($uniqueMap)：保存前按字段查重，命中直接返回业务错误（首选，零异常开销）；
 *  2. friendly($e, $fallback)：兜底把驱动的 1062 异常翻译成可读中文，
 *     用于并发写入等「查重之后仍冲突」的极端场景。
 */
class UniqueGuard
{
    /**
     * 保存前查重。
     *
     * @param array<string, array{0: \think\Model|\think\db\Query|string, 1: mixed, 2: string}> $rules
     *        形如 [
     *          'type_code' => [DictTypeModel::class, $code, '字典编码'],
     *          'username'  => [SysUser::class, $username, '登录账号'],
     *        ]
     *        每项为 [查询对象(类名/Query/模型), 待查值, 中文名]。
     * @param int $excludeId 编辑时排除自身主键，新增传 0
     * @return string 空串表示无冲突；否则为可直接返回用户的中文提示
     */
    public static function check(array $rules, int $excludeId = 0): string
    {
        foreach ($rules as $field => $rule) {
            $source = $rule[0] ?? null;
            $value  = $rule[1] ?? null;
            $label  = (string)($rule[2] ?? $field);

            // 允许为空 / 0 的字段不参与查重（唯一索引通常允许多个空值）
            if ($value === null || $value === '' || $value === 0 || $value === '0') {
                continue;
            }

            try {
                if (is_string($source)) {
                    $query = $source::where($field, $value);
                } elseif ($source instanceof \think\Model) {
                    $query = $source->where($field, $value);
                } elseif ($source instanceof \think\db\Query) {
                    $query = $source->where($field, $value);
                } else {
                    continue;
                }
                if ($excludeId > 0) {
                    $query->where('id', '<>', $excludeId);
                }
                if ($query->find()) {
                    return $label . '已存在：' . (is_scalar($value) ? (string)$value : '');
                }
            } catch (\Throwable $e) {
                // 查重自身异常（老库缺列等）不阻断保存，交给下面的兜底翻译
                continue;
            }
        }
        return '';
    }

    /**
     * 判断异常是否为唯一键冲突，并返回可读提示。
     *
     * @return string 空串表示不是唯一键冲突
     */
    public static function friendly(\Throwable $e, string $fallback = '数据已存在，请勿重复提交'): string
    {
        if (!self::isDuplicate($e)) {
            return '';
        }
        $detail = self::duplicateValue($e);
        if ($detail !== '') {
            return $fallback . '（重复项：' . $detail . '）';
        }
        return $fallback;
    }

    public static function isDuplicate(\Throwable $e): bool
    {
        $msg = $e->getMessage();
        if (str_contains($msg, '1062') || str_contains($msg, 'Duplicate entry')) {
            return true;
        }
        if ($e instanceof DbPDOException) {
            $info = method_exists($e, 'getData') ? $e->getData() : null;
            if (is_array($info) && (string)($info['code'] ?? '') === '23000') {
                return true;
            }
        }
        return false;
    }

    /** 从 Duplicate entry 'xxx' for key 'yyy' 中提取重复值 */
    private static function duplicateValue(\Throwable $e): string
    {
        if (preg_match("/Duplicate entry '([^']*)'/i", $e->getMessage(), $m)) {
            return (string)$m[1];
        }
        return '';
    }
}
