<?php
namespace app\model;

use think\Model;

class OrgDepartment extends Model
{
    protected $name = 'org_department';
    protected $autoWriteTimestamp = true;

    public function getStatusTextAttr($value, $data): string
    {
        return ($data['status'] ?? 1) == 1 ? '启用' : '停用';
    }
}
