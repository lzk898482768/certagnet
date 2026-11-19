<?php
namespace app\model;

use think\Model;

class SysRole extends Model
{
    protected $name = 'sys_role';
    protected $autoWriteTimestamp = true;

    public function users()
    {
        return $this->hasMany(SysUser::class, 'role_id');
    }

    public function getMenuIdsAttr($value)
    {
        return $value ? explode(',', $value) : [];
    }

    public function setMenuIdsAttr($value)
    {
        return is_array($value) ? implode(',', $value) : $value;
    }
}
