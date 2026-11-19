<?php
// +----------------------------------------------------------------------
// | 中间件配置
// +----------------------------------------------------------------------
return [
    // 注意：ThinkPHP 6 的「全局中间件」只从 app/middleware.php 自动加载，
    // 写在这里的 middleware 键不会被全局加载（历史遗留，曾导致 Cors 不生效）。
    // 全局中间件见同目录上级的 app/middleware.php；本文件仅保留路由中间件别名。
    'alias' => [
        'auth'       => app\middleware\Auth::class,
        'datascope'  => app\middleware\DataScope::class,
        'adminauth'  => app\middleware\AdminAuth::class,
        'permauth'   => app\middleware\PermAuth::class,
        // v2.99.66：删除操作 OTP 闸门（任何人删除业务/重要数据均需动态码）
        'deleteotp'  => app\middleware\DeleteOtp::class,
        // v2.82：Agent 开放接口的独立 API Key 鉴权（不走登录 token）
        'agentauth'  => app\middleware\AgentAuth::class,
    ],
];
