<?php
// +----------------------------------------------------------------------
// | 政务网申报进度配置（v2.62）
// +----------------------------------------------------------------------
// 申报密码必须可逆解密后调用政务网接口，故使用对称加密。
// 生产环境务必在 backend/.env 设置独立的 GOV_PASSWORD_KEY（任意长随机串），
// 更换密钥会导致已保存的密码无法解密，需要用户重新填写。
return [
    // 加密密钥（.env: GOV_PASSWORD_KEY）
    'password_key'      => env('gov.password_key', 'cert-agent-gov-progress-default-key-2026'),

    // 河南政务服务网申报进度查询接口
    'api_url'           => env('gov.api_url', 'https://www.hnzwfw.gov.cn/hnzwfw/apasinfo/taskOffGetApasInfoDetailByProjidAndProjpwd'),

    // 单次请求超时（秒）
    'timeout'           => (int)env('gov.timeout', 25),

    // 批量同步每条之间的间隔（毫秒），避免对政务网造成压力
    'request_delay_ms'  => (int)env('gov.request_delay_ms', 300),

    // 定时同步的最小间隔（小时）：0 = 每次定时任务都执行（默认，v2.99.44 起取消 20 小时限制）。
    // 如需降频，可设为正整数小时数；Docker scheduler 与宝塔任务均为每小时循环。
    'daily_min_hours'   => (int)env('gov.daily_min_hours', 0),

    // 宝塔定时任务使用的 PHP CLI 路径（留空则自动推断；宝塔一般填 /www/server/php/82/bin/php）。
    // PHP_BINARY 在 php-fpm 下可能指向 php-fpm，不能直接用于 Shell 定时任务。
    'php_binary'        => (string)env('gov.php_binary', ''),
];
