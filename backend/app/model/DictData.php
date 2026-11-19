<?php
namespace app\model;

use think\Model;

class DictData extends Model
{
    protected $name = 'dict_data';
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = false;

    public function getStatusTextAttr($value, $data): string
    {
        return ($data['status'] ?? 1) == 1 ? '启用' : '停用';
    }
}
