<?php
namespace app\model;

use think\Model;

/**
 * 系统基本信息配置（键值对）
 */
class SystemConfig extends Model
{
    protected $name = 'system_config';
    protected $autoWriteTimestamp = true;
}
