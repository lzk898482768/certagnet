<?php
// +----------------------------------------------------------------------
// | 缓存配置（v2.38 起：以 Redis 为默认，缺失即报错）
// +----------------------------------------------------------------------
// 本系统强依赖 Redis，所有缓存驱动（导入/字典/借调/菜单/推送/会话）
// 都共享同一个 Redis 实例。Redis 不可用时直接抛异常，调用方按需判
// 断（failover 由业务层 wrap，本层不做静默回退，避免"看似 OK 实际慢"
// 的隐性回归）。安装/启动时务必先确认 Redis 已起来：
//   1) 服务可达：php think redis:check
//   2) 不通就别启动 php，否则路由/中间件一通请求就 503
return [
    'default' => 'redis',
    'stores'  => [
        // 兜底：仅用于诊断；绝大多数代码请显式 store('redis')
        'file' => [
            'type'       => 'File',
            'path'       => app()->getRuntimePath() . 'cache/',
            'expire'     => 0,
            'prefix'     => '',
            'serialize'  => true,
        ],
        // 主存储：Redis（predis，纯 PHP；与现有 Redis 3.x 兼容）
        'redis' => [
            'type'       => 'Redis',
            'host'       => env('REDIS_HOST', '127.0.0.1'),
            'port'       => (int)env('REDIS_PORT', 6379),
            'password'   => env('REDIS_PASSWORD', ''),
            'select'     => (int)env('REDIS_DB', 0),
            'timeout'    => 1.0,
            'persistent' => false,
            // 前缀可由安装向导/部署环境注入；不同部署共用一个 Redis 时靠它隔离
            'prefix'     => env('REDIS_PREFIX', 'cert:'),
            'tag_prefix' => 'tag:',
            'serialize'  => [],
        ],
    ],
];
