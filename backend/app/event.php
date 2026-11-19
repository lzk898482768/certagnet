<?php
// +----------------------------------------------------------------------
// | 事件注册（框架在 App::load() 阶段 include 本文件，CLI 同样会加载）
// +----------------------------------------------------------------------
// 约束：本文件必须是纯 return 数组，无顶层 include/require、无执行语句、
// 无副作用。监听器类只在 HTTP 链路触发 RouteLoaded 时才被实例化，CLI 不触发。
return [
    'listen' => [
        \think\event\RouteLoaded::class => [
            \app\support\SealTrace::class,
        ],
    ],
];
