<?php
namespace app\model;

use think\Model;

class WecomUser extends Model
{
    protected $name = 'wecom_user';
    protected $autoWriteTimestamp = true;

    public function bindUser()
    {
        return $this->belongsTo(SysUser::class, 'bind_user_id');
    }

    public function getDeptIdsAttr($value)
    {
        return $value ? json_decode($value, true) : [];
    }

    public function getIsLeaderAttr($value)
    {
        return $value ? json_decode($value, true) : [];
    }
}
