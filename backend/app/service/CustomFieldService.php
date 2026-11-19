<?php
namespace app\service;

use app\model\CustomField;
use app\model\CustomValue;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;
use Throwable;

/**
 * 自定义字段服务
 *
 * 设计：字段定义放在 cert_custom_field，字段值用 EAV 方式放在 cert_custom_value，
 * 这样新增字段无需改动任何业务主表结构，便于生产环境平滑升级。
 *
 * 用法（业务控制器）：
 *   // 列表/详情挂载
 *   CustomFieldService::attachList('certificate', $list);
 *   CustomFieldService::attachOne('certificate', $row);
 *   // 保存
 *   $err = CustomFieldService::validate('certificate', $input);
 *   CustomFieldService::saveValues('certificate', $recordId, $input);
 *   // 删除记录时清理
 *   CustomFieldService::deleteValues('certificate', $recordId);
 */
class CustomFieldService
{
    /** 支持自定义字段的模块 */
    public const MODULES = [
        'certificate' => '证照档案',
        'staff'       => '持证人员',
        'company'     => '客户企业',
        'contract'    => '服务合同',
        'secondment'  => '证书借调',
        'cert_task'   => '配证任务',
        'project'     => '项目',
    ];

    /** 字段类型 */
    public const TYPES = [
        'text'     => '单行文本',
        'textarea' => '多行文本',
        'number'   => '数字',
        'date'     => '日期',
        'datetime' => '日期时间',
        'select'   => '下拉选择',
        'radio'    => '单选框',
        'checkbox' => '多选框',
        'switch'   => '开关',
    ];

    private static function cacheKey(string $module): string
    {
        return 'custom_fields_' . $module;
    }

    public static function moduleName(string $module): string
    {
        return self::MODULES[$module] ?? $module;
    }

    /**
     * 某模块启用中的字段定义（带缓存）
     *
     * v2.81.4：缓存只做加速，读/写失败一律回源数据库，
     * 避免 Redis 抖动把列表接口（合同/证书/企业等）整体打成 500/503。
     */
    public static function fieldsOf(string $module, bool $onlyEnabled = true): array
    {
        if ($module === '') {
            return [];
        }
        $cacheKey = self::cacheKey($module) . ($onlyEnabled ? '' : '_all');
        try {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (Throwable $e) {
            Log::warning('[custom-field] 读取字段缓存失败，已回退数据库：' . $e->getMessage());
        }

        $q = CustomField::where('module', $module);
        if ($onlyEnabled) {
            $q->where('status', 1);
        }
        $list = $q->order('sort', 'asc')->order('id', 'asc')->select()->toArray();

        try {
            Cache::set($cacheKey, $list, 600);
        } catch (Throwable $e) {
            Log::warning('[custom-field] 写入字段缓存失败，本次直接使用数据库结果：' . $e->getMessage());
        }
        return $list;
    }

    public static function clearCache(?string $module = null): void
    {
        try {
            if ($module) {
                Cache::delete(self::cacheKey($module));
                Cache::delete(self::cacheKey($module) . '_all');
                return;
            }
            foreach (array_keys(self::MODULES) as $m) {
                Cache::delete(self::cacheKey($m));
                Cache::delete(self::cacheKey($m) . '_all');
            }
        } catch (Throwable $e) {
            Log::warning('[custom-field] 清理字段缓存失败：' . $e->getMessage());
        }
    }

    /**
     * 单条记录挂载自定义值（挂到 $row['custom']）
     */
    public static function attachOne(string $module, array &$row): void
    {
        $id = (int)($row['id'] ?? 0);
        if (!$id) {
            $row['custom'] = [];
            return;
        }
        $fields = self::fieldsOf($module);
        if (!$fields) {
            $row['custom'] = [];
            return;
        }
        $vals = CustomValue::where('module', $module)
            ->where('record_id', $id)
            ->column('value', 'field_id');
        $out = [];
        foreach ($fields as $f) {
            $out[$f['field_key']] = $vals[$f['id']] ?? ($f['default_value'] ?? '');
        }
        $row['custom'] = self::castOut($fields, $out);
    }

    /**
     * 列表批量挂载自定义值（两条 SQL，避免 N+1）
     */
    public static function attachList(string $module, array &$list): void
    {
        if (!$list) {
            return;
        }
        $fields = self::fieldsOf($module);
        if (!$fields) {
            foreach ($list as &$r) {
                $r['custom'] = [];
            }
            unset($r);
            return;
        }
        $ids = array_values(array_filter(array_map('intval', array_column($list, 'id'))));
        if (!$ids) {
            foreach ($list as &$r) {
                $r['custom'] = [];
            }
            unset($r);
            return;
        }
        $rows = CustomValue::where('module', $module)
            ->whereIn('record_id', $ids)
            ->field('record_id,field_id,value')
            ->select()->toArray();
        $map = [];
        foreach ($rows as $v) {
            $map[(int)$v['record_id']][(int)$v['field_id']] = $v['value'];
        }
        foreach ($list as &$r) {
            $out = [];
            $rid = (int)($r['id'] ?? 0);
            foreach ($fields as $f) {
                $out[$f['field_key']] = $map[$rid][$f['id']] ?? ($f['default_value'] ?? '');
            }
            $r['custom'] = self::castOut($fields, $out);
        }
        unset($r);
    }

    /** 输出时按类型做轻量转换（开关转布尔、多选转数组） */
    private static function castOut(array $fields, array $out): array
    {
        foreach ($fields as $f) {
            $k = $f['field_key'];
            if (!array_key_exists($k, $out)) {
                continue;
            }
            if ($f['field_type'] === 'switch') {
                $out[$k] = (int)$out[$k] === 1;
            } elseif ($f['field_type'] === 'checkbox') {
                $dec = json_decode($out[$k], true);
                $out[$k] = is_array($dec) ? $dec : (($out[$k] === '' || $out[$k] === null) ? [] : [$out[$k]]);
            }
        }
        return $out;
    }

    /**
     * 校验必填，返回错误串（空串表示通过）
     */
    public static function validate(string $module, $input): string
    {
        $fields = self::fieldsOf($module);
        if (!$fields) {
            return '';
        }
        $input = self::normalizeInput($input);
        foreach ($fields as $f) {
            if (empty($f['required'])) {
                continue;
            }
            $v = $input[$f['field_key']] ?? '';
            if ($v === '' || $v === null) {
                return '自定义字段「' . $f['field_label'] . '」为必填项';
            }
        }
        return '';
    }

    /**
     * 保存自定义字段值
     * @param string $module
     * @param int $recordId
     * @param mixed $input 数组或 JSON 串，键为 field_key
     */
    public static function saveValues(string $module, int $recordId, $input): void
    {
        $fields = self::fieldsOf($module);
        if (!$fields || $recordId <= 0) {
            return;
        }
        $input = self::normalizeInput($input);
        $table = 'cert_custom_value';
        foreach ($fields as $f) {
            $key = $f['field_key'];
            if (!array_key_exists($key, $input)) {
                continue; // 未提交不覆盖已有值
            }
            $val = $input[$key];
            // v2.53：选项类字段校验值合法性（select/radio/checkbox），非法值抛异常阻止脏数据
            if (in_array($f['field_type'], ['select', 'radio', 'checkbox'], true)) {
                $opts = array_values(array_filter(array_map('trim', explode(',', (string)($f['options'] ?? '')))));
                if ($opts) {
                    $vals = $f['field_type'] === 'checkbox'
                        ? (is_array($val) ? $val : array_values(array_filter(explode(',', (string)$val), fn($x) => trim($x) !== '')))
                        : [$val];
                    foreach ($vals as $vv) {
                        if (!in_array((string)$vv, $opts, true)) {
                            throw new \RuntimeException('自定义字段「' . $f['field_label'] . '」的值「' . $vv . '」不在可选范围内');
                        }
                    }
                }
            }
            if ($f['field_type'] === 'switch') {
                // v2.53：兼容导入文本「是/否」等写法
                if (is_string($val)) {
                    $val = in_array(mb_strtolower(trim($val)), ['是', '1', 'true', 'yes', 'on'], true) ? 1 : 0;
                } else {
                    $val = ($val === true || $val === 1 || $val === '1' || $val === 'true') ? 1 : 0;
                }
            } elseif (is_bool($val)) {
                $val = $val ? 1 : 0;
            } elseif (is_array($val)) {
                $val = json_encode($val, JSON_UNESCAPED_UNICODE);
            } else {
                $val = (string)$val;
            }
            $where = [
                'module'    => $module,
                'record_id' => $recordId,
                'field_id'  => $f['id'],
            ];
            $exists = Db::name($table)->where($where)->find();
            if ($exists) {
                Db::name($table)->where($where)->update(['value' => $val, 'field_key' => $key]);
            } else {
                Db::name($table)->insert(array_merge($where, [
                    'field_key' => $key,
                    'value'     => $val,
                ]));
            }
        }
    }

    /** 删除记录时清理其自定义值 */
    public static function deleteValues(string $module, int $recordId): void
    {
        if ($recordId <= 0) {
            return;
        }
        Db::name('cert_custom_value')
            ->where('module', $module)
            ->where('record_id', $recordId)
            ->delete();
    }

    /** 删除字段定义时连带清理其值 */
    public static function deleteFieldValues(int $fieldId): void
    {
        Db::name('cert_custom_value')->where('field_id', $fieldId)->delete();
    }

    /** 归一化输入（支持数组 / JSON 串 / 空） */
    private static function normalizeInput($input): array
    {
        if (is_array($input)) {
            return $input;
        }
        if (is_string($input) && $input !== '') {
            $decoded = json_decode($input, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }
}
