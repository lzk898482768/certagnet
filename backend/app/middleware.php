<?php
// +----------------------------------------------------------------------
// | 全局中间件定义文件（ThinkPHP 6 自动加载本文件，而非 config/middleware.php）
// +----------------------------------------------------------------------
// 说明：框架 Http::loadMiddleware() 只读取 app_path() . 'middleware.php'。
//       此前中间件误注册在 config/middleware.php，该文件不会被全局加载，
//       导致 Cors 跨域头与 v2.97 操作审计兜底均未生效。v2.97 修正到此处。
return [
    // 业务码响应头（v2.99.1 / BUG-07）：必须最外层，才能覆盖全部响应
    \app\middleware\BizCode::class,
    // 准入门禁：未授权时终止式导向安装页 / 返回 403（必须排在最前，
    // 顺序固定 FlowGate 在前、SealWitness 在后，反序会让未授权先抛错而非 302）
    \app\middleware\FlowGate::class,
    // 派生值见证：独立交叉复核，删除门禁后仍能拦截
    \app\middleware\SealWitness::class,
    // 跨域与基础安全响应头
    \app\middleware\Cors::class,
    // v2.100：微信小程序接入 Token 校验（仅对带 X-Client: miniapp 的请求生效）
    \app\middleware\MiniAppAuth::class,
    // 令牌 Cookie 落盘（v2.99.1 / INFO-02）：登录/退出登记的 Set-Cookie 统一在此写出
    \app\middleware\AuthTicket::class,
    // 操作审计兜底：人工日志已写时不重复记录，并回写耗时 / 结果码
    \app\middleware\OperationLog::class,
];
