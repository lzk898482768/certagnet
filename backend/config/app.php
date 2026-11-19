<?php
// +----------------------------------------------------------------------
// | 应用配置
// +----------------------------------------------------------------------
return [
    // 应用名称
    'app_name'               => '证照到期管理系统',
    // 应用地址
    'app_host'               => '',
    // 应用调试模式
    'app_debug'              => env('app_debug', true),
    // 应用Trace
    'app_trace'              => env('app_trace', false),
    // 默认时区
    'default_timezone'       => 'Asia/Shanghai',
    // 路由使用注解
    'route_annotation'       => false,
    // 控制器后缀
    'controller_suffix'      => false,
    // 默认返回类型
    'default_return_type'    => 'json',
    // 禁止访问模块列表
    'deny_app_list'          => [],
    // 默认 JSON 数据返回格式
    'json_return_to_lower'   => false,
];
