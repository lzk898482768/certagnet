<?php
// +----------------------------------------------------------------------
// | 会话配置（飞牛 fnOS：强制框架原生 Session 走 Redis，杜绝会话文件落到 NAS 挂载卷）
// +----------------------------------------------------------------------
// 背景：本系统登录态与图形验证码本就基于 Redis（自定义 token_* / captcha_* 缓存键），
//   全局未注册 think\middleware\SessionInit，正常请求不会启动原生 Session。本文件属于
//   防御性兜底——一旦将来有代码或扩展启用原生 Session，也只会落到 Redis，而不会在
//   data/runtime 挂载卷上产生会话文件 IO。无数据迁移、不影响现有登录态、无需重新登录。
//
// TP6 的 session 通过 Cache 驱动桥接：type=cache、store=redis 即复用 config/cache.php
// 里已有的 Redis 连接（带密码、库号、前缀），无需额外连接信息。
return [
    // 驱动类型：cache 桥接到下方 store 指定的缓存通道
    'type'           => 'cache',
    'store'          => 'redis',
    // 会话名称（Cookie 名）
    'name'           => 'PHPSESSID',
    // 会话有效期（秒），默认 2 小时；仅对原生 Session 生效，不影响业务 token TTL
    'expire'         => (int)env('session.expire', 7200),
    // 前缀，避免与业务缓存 key 冲突
    'prefix'         => 'sess:',
    // Cookie 作用路径/域名
    'path'           => '/',
    'domain'         => '',
    'secure'         => false,
    'httponly'       => true,
    'samesite'       => 'Lax',
    'use_trans_sid'  => false,
    'use_strict_mode' => true,
];
