<?php
namespace app\model;

use think\Model;

class LoginLog extends Model
{
    protected $name = 'login_log';
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = false;
}
