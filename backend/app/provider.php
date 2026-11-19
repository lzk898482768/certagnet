<?php
// +----------------------------------------------------------------------
// | 服务注册（绑定自定义异常处理器）
// +----------------------------------------------------------------------
use think\exception\Handle;
use app\ExceptionHandle;

return [
    Handle::class => ExceptionHandle::class,
];
