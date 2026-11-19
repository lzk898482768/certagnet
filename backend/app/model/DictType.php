<?php
namespace app\model;

use think\Model;

class DictType extends Model
{
    protected $name = 'dict_type';
    protected $autoWriteTimestamp = true;

    public function datas()
    {
        return $this->hasMany(DictData::class, 'type_code', 'type_code');
    }
}
