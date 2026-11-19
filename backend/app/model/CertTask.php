<?php
namespace app\model;

use think\Model;

class CertTask extends Model
{
    protected $name = 'cert_task';
    protected $autoWriteTimestamp = true;

    /**
     * 虚拟属性必须 append 才会进入 toArray()，
     * 否则列表接口返回里没有 status_text / visible_scope_text。
     */
    protected $append = ['status_text', 'visible_scope_text'];

    public function getStatusTextAttr($value, $data): string
    {
        $map = [0 => '草稿', 1 => '进行中', 2 => '已完成', 3 => '已关闭'];
        return $map[$data['status'] ?? 0] ?? '草稿';
    }

    public function getVisibleScopeTextAttr($value, $data): string
    {
        $map = [
            'all'  => '全员可见',
            'dept' => '指定部门',
            'user' => '指定人员',
            'self' => '仅发布人',
        ];
        return $map[(string)($data['visible_scope'] ?? 'all')] ?? '全员可见';
    }
}
