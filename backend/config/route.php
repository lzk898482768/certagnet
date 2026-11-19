<?php
// +----------------------------------------------------------------------
// | 路由配置
// +----------------------------------------------------------------------
return [
    'route_annotation'    => false,
    'default_controller'  => 'Index',
    'default_action'      => 'index',
    'url_param_type'      => 1,
    'url_controller_layer'=> 'controller',
    'url_controller_suffix' => '',
    'url_convert'         => true,
    // 只允许 route/app.php 中显式注册的路由。
    // 关闭「未匹配 URL 自动按控制器/方法分发」的兜底，避免绕过路由中间件
    // （鉴权/数据权限）直接访问 admin 控制器。
    'url_route_must'      => true,
    'default_route'       => '',
];
