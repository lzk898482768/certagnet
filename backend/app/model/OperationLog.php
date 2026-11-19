<?php
namespace app\model;

use think\Model;

class OperationLog extends Model
{
    protected $name = 'operation_log';
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = false;
}
