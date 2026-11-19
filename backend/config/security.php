<?php
// +----------------------------------------------------------------------
// | 安全配置
// +----------------------------------------------------------------------
return [
    // 是否开启登录图形验证码
    'captcha_enabled'     => true,
    // 验证码有效期（秒）
    'captcha_expire'      => 300,
    // 验证码字符长度
    'captcha_length'      => 4,
    // 验证码字符集（去掉易混淆 0/o/1/l/I）
    'captcha_chars'       => '23456789ABCDEFGHJKLMNPQRSTUVWXYZ',

    // 登录失败锁定策略
    'login_max_fail'      => 5,        // 连续失败次数上限
    'login_lock_seconds'  => 900,      // 锁定时长（15 分钟）

    // CORS 允许的前端源（* 表示不限制；建议填写具体域名，多个用数组）
    'cors_allowed_origins' => [
        'http://127.0.0.1:8000',
        'http://localhost:8000',
        'http://127.0.0.1:5173',
        'http://localhost:5173',
    ],
];
