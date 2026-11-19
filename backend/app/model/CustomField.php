<?php
namespace app\model;

use think\Model;

class CustomField extends Model
{
    protected $name = 'cert_custom_field';
    protected $autoWriteTimestamp = true;

    protected $append = ['options_arr', 'module_name', 'type_name'];

    /** 下拉选项（options 逗号分隔 -> 数组） */
    public function getOptionsArrAttr($value, $data): array
    {
        $s = trim((string)($data['options'] ?? ''));
        if ($s === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $s)), function ($v) {
            return $v !== '';
        }));
    }

    public function getModuleNameAttr($value, $data): string
    {
        return \app\service\CustomFieldService::moduleName((string)($data['module'] ?? ''));
    }

    public function getTypeNameAttr($value, $data): string
    {
        return \app\service\CustomFieldService::TYPES[(string)($data['field_type'] ?? 'text')] ?? '文本';
    }
}
