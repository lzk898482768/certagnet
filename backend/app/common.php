<?php
// +----------------------------------------------------------------------
// | 全局辅助函数
// +----------------------------------------------------------------------

if (!function_exists('r_success')) {
    function r_success($data = [], string $msg = 'ok')
    {
        return \app\common\Response::success($data, $msg);
    }
}

if (!function_exists('r_error')) {
    function r_error(string $msg = 'error', int $code = 1, $data = [])
    {
        return \app\common\Response::error($msg, $code, $data);
    }
}

if (!function_exists('sys_config')) {
    /**
     * 读取系统配置（system_config 表，进程内缓存一次）
     */
    function sys_config(string $key, $default = '')
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            try {
                foreach (\app\model\SystemConfig::select()->toArray() as $row) {
                    $map[$row['config_key']] = $row['config_value'];
                }
            } catch (\Throwable $e) {
                $map = [];
            }
        }
        return $map[$key] ?? $default;
    }
}

if (!function_exists('h5_base_url')) {
    /**
     * 移动端 H5 访问根地址（用于推送消息中的详情链接）
     *
     * 解析顺序（重要：定时任务是 CLI 运行，拿不到 HTTP_HOST）：
     *   1) 系统配置 `h5_base_url`（系统信息设置 → 移动端H5地址）
     *   2) 系统配置 `sys_domain`（站点根地址）+ /mobile
     *   3) 当前 HTTP 请求上下文推断（浏览器触发的推送；localhost / 127.0.0.1
     *      等本机地址会被忽略，支持 X-Forwarded-Host / X-Forwarded-Proto）
     *   4) 企微扫码回调地址 `wecom_redirect_uri` 的域名 + /mobile
     *      （生产环境通常已配置为公网域名，可作为 CLI 定时任务的兜底）
     *   5) `APP_HOST`（.env / config app_host）+ /mobile
     *   6) 都没有 → 返回空字符串，由调用方降级（**不再返回相对路径 /mobile**，
     *      否则短信/企微里会收到点不开的链接）
     *
     * @return string 形如 https://域名/mobile；无法确定时返回 ''
     */
    function h5_base_url(): string
    {
        static $resolved = null;
        if ($resolved !== null) {
            return $resolved;
        }

        // 1) 显式配置优先
        $base = trim((string)sys_config('h5_base_url', ''));

        // 2) 站点根地址 + /mobile
        if ($base === '') {
            $root = trim((string)sys_config('sys_domain', ''));
            if ($root !== '') {
                $base = rtrim($root, '/') . '/mobile';
            }
        }

        // 3) HTTP 请求上下文（Web 端触发的推送/测试）
        //    注意：CLI 定时任务没有真实请求，ThinkPHP 的 Request::host() 会回退成
        //    localhost；反向代理/容器场景 Host 也可能被改写成内网地址。这类回环地址
        //    推送出去用户根本点不开，因此只接受「看起来可外网访问」的主机名。
        if ($base === '') {
            try {
                $req  = function_exists('request') ? request() : null;
                $host = '';
                // 反向代理优先取 X-Forwarded-Host（多级代理取第一个）
                $fwdHost = (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? '');
                if ($fwdHost !== '') {
                    $host = trim(explode(',', $fwdHost)[0]);
                }
                if ($host === '') {
                    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
                }
                if ($host === '' && $req) {
                    $host = trim((string)$req->host(true));
                }
                if ($host !== '' && !h5_host_is_loopback($host)) {
                    $fwdProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
                    $https  = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
                        || $fwdProto === 'https'
                        || ($req && $req->isSsl());
                    $scheme = $https ? 'https' : 'http';
                    $base   = $scheme . '://' . $host . '/mobile';
                }
            } catch (\Throwable $e) {
                // 命令行等无请求场景，继续往下兜底
            }
        }

        // 4) 企微扫码回调地址的域名（生产环境通常已配置为公网域名）
        //    定时任务（CLI）没有请求上下文时，用它兜底比 localhost 可靠。
        if ($base === '') {
            $redirect = trim((string)sys_config('wecom_redirect_uri', ''));
            if ($redirect !== '') {
                $p = parse_url($redirect);
                if (!empty($p['scheme']) && !empty($p['host'])
                    && in_array(strtolower((string)$p['scheme']), ['http', 'https'], true)
                    && !h5_host_is_loopback((string)$p['host'])) {
                    $origin = strtolower((string)$p['scheme']) . '://' . $p['host']
                        . (!empty($p['port']) ? ':' . $p['port'] : '');
                    $base = $origin . '/mobile';
                }
            }
        }

        // 5) APP_HOST（.env 里配置 APP_HOST=https://域名）
        if ($base === '') {
            try {
                $appHost = trim((string)\think\facade\Config::get('app.app_host', ''));
                if ($appHost === '') {
                    $appHost = trim((string)(getenv('APP_HOST') ?: ''));
                }
                if ($appHost !== '') {
                    $base = rtrim($appHost, '/') . '/mobile';
                }
            } catch (\Throwable $e) {
            }
        }

        // 6) 仍无法确定 → 告警并返回空串（调用方降级处理）
        if ($base === '') {
            try {
                \think\facade\Log::warning(
                    '[push] 无法确定 H5 访问地址：请在「系统管理 → 系统信息设置 → 站点根地址 / 移动端H5地址」'
                    . '填写完整地址（含 https://），或配置 APP_HOST。'
                    . '否则定时任务推送消息中的详情链接将不可点击。'
                );
            } catch (\Throwable $e) {
            }
            return $resolved = '';
        }

        return $resolved = rtrim($base, '/');
    }
}

if (!function_exists('h5_host_is_loopback')) {
    /**
     * 判断主机名（可带端口）是否为回环/本机地址。
     *
     * 用于 h5_base_url() 过滤请求上下文推断出的无效域名：
     * CLI、反向代理、容器内访问时 Host 常被写成 localhost / 127.0.0.1，
     * 这类地址推送出去外部用户无法访问，必须丢弃并改用显式配置。
     */
    function h5_host_is_loopback(string $host): bool
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return true;
        }
        // 带方括号的 IPv6，如 [::1] / [::1]:8080
        if (str_starts_with($host, '[')) {
            $end = strpos($host, ']');
            $ip  = $end !== false ? substr($host, 1, $end - 1) : trim($host, '[]');
            return in_array($ip, ['::1', '0:0:0:0:0:0:0:1'], true);
        }
        $host = preg_replace('/:\d+$/', '', $host);
        if (in_array($host, ['localhost', '0.0.0.0', '::1'], true)) {
            return true;
        }
        // 127.0.0.0/8 全部属于本机回环
        if (preg_match('/^127\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $host)) {
            return true;
        }
        // *.localhost（如 app.localhost）同样不可外网访问
        return str_ends_with($host, '.localhost');
    }
}

if (!function_exists('h5_detail_url')) {
    /**
     * 推送事项对应的 H5 端详情链接
     *
     * @param int    $certId 证书ID
     * @param string $page   H5 页面路径（默认证书详情）
     * @return string 完整可点击链接；无法确定站点地址时返回 ''
     */
    function h5_detail_url(int $certId, string $page = 'pages/certificate/detail'): string
    {
        $base = h5_base_url();
        if ($base === '') {
            return '';
        }
        return $base . '/#/' . ltrim($page, '/') . '?id=' . $certId;
    }
}

if (!function_exists('strip_empty_detail_link')) {
    /**
     * 清理「详情链接为空」时模板残留的空链接行。
     *
     * 支持两种常见写法：
     *   查看详情：{{detail_url}}
     *   [查看详情]({{detail_url}})   // 企微 markdown
     * 链接为空时整行删除，避免推送出「查看详情：」或「[查看详情]()」这种点不开的内容。
     */
    function strip_empty_detail_link(string $content): string
    {
        $content = preg_replace('/^[ \t]*\[查看详情\]\(\s*\)\s*$/mu', '', $content);
        $content = preg_replace('/^[ \t]*查看详情[:：]\s*$/mu', '', $content);
        return trim((string)$content);
    }
}

if (!function_exists('refresh_table_schema')) {
    /**
     * 强制刷新表结构缓存（v2.99.50）。
     *
     * ThinkPHP 会把表字段缓存在连接对象（fields_strict=true 时按缓存结构校验写入），
     * 运行时 `ensure_column()` 新增列后，同进程内若沿用旧缓存会抛
     * `fields not exists:[新列]`，导致写入静默失败。结构自愈后必须刷新。
     */
    function refresh_table_schema(string $table): void
    {
        try {
            \think\facade\Db::connect()->getSchemaInfo($table, true);
        } catch (\Throwable $e) {
            // 刷新失败不影响自愈结果，下次请求会再次尝试
        }
    }
}

if (!function_exists('ensure_column')) {
    /**
     * 结构自愈：确保某表存在指定列。
     *
     * 背景：部分历史迁移（v2.5 给 fee 加 is_auto 等）曾未回写 init.sql，
     * 全新安装的库会一直缺列，运行时报 SQLSTATE[42S22] 1054 Unknown column。
     * 这里在用到的地方探测一次，缺列就自动补（幂等，失败静默降级）。
     *
     * @return bool 该列当前是否可用
     */
    function ensure_column(string $table, string $column, string $ddl): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        try {
            $rows = \think\facade\Db::query('SHOW COLUMNS FROM `' . $table . '`');
            foreach ($rows as $r) {
                if (strcasecmp($r['Field'] ?? '', $column) === 0) {
                    return $cache[$key] = true;
                }
            }
            \think\facade\Db::execute(
                'ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $ddl
            );
            // v2.99.50：ALTER 前可能已缓存过该表结构，fields_strict 会按旧结构校验
            // 并抛 "fields not exists:[新列]"，必须强制刷新（否则同进程内写入全失败）。
            refresh_table_schema($table);
            return $cache[$key] = true;
        } catch (\Throwable $e) {
            return $cache[$key] = false;
        }
    }
}

if (!function_exists('ensure_table')) {
    /**
     * 结构自愈：确保某表存在。
     *
     * v2.32 起用于 `certificate_attach` 等新建表。老库没有该表时自动建出，
     * 避免依赖一次性 upgrade 脚本（项目约定：结构变更只改 init.sql）。
     *
     * @param string $table 表名
     * @param string $ddlCREATE TABLE 语句（不含表名部分）
     * @return bool 该表当前是否可用
     */
    function ensure_table(string $table, string $ddl): bool
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }
        try {
            $rows = \think\facade\Db::query("SHOW TABLES LIKE '" . addslashes($table) . "'");
            if ($rows) {
                return $cache[$table] = true;
            }
            \think\facade\Db::execute($ddl);
            refresh_table_schema($table);
            return $cache[$table] = true;
        } catch (\Throwable $e) {
            return $cache[$table] = false;
        }
    }
}

if (!function_exists('ensure_index')) {
    /**
     * 结构自愈：确保某表存在指定索引。
     *
     * `ensure_column()` 只能加列，加索引需另写探测（R4 决定不加唯一索引，
     * 仅补普通索引 idx_source 以支撑 转人工去重查询）。
     *
     * @param string $table    表名
     * @param string $index    索引名
     * @param string $ddl ADD KEY 语句（不含 ADD KEY 关键字）
     * @return bool 该索引当前是否可用
     */
    function ensure_index(string $table, string $index, string $ddl): bool
    {
        static $cache = [];
        $key = $table . '.' . $index;
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        try {
            $rows = \think\facade\Db::query('SHOW INDEX FROM `' . $table . '`');
            foreach ($rows as $r) {
                if (strcasecmp($r['Key_name'] ?? '', $index) === 0) {
                    return $cache[$key] = true;
                }
            }
            \think\facade\Db::execute('ALTER TABLE `' . $table . '` ADD KEY `' . $index . '` ' . $ddl);
            return $cache[$key] = true;
        } catch (\Throwable $e) {
            return $cache[$key] = false;
        }
    }
}

if (!function_exists('remain_days')) {
    function remain_days($expireDate): int
    {
        if (empty($expireDate)) {
            return 0;
        }
        $exp = strtotime($expireDate);
        if ($exp === false) {
            return 0;
        }
        return (int)ceil(($exp - time()) / 86400);
    }
}

if (!function_exists('download_utf8')) {
    /**
     * v2.56：带 RFC 5987 UTF-8 文件名的下载响应。
     *
     * 替代 TP6 原生 download()：中文文件名经 filename*=UTF-8'' 百分号编码，
     * 前端 request.js 解析后下载文件名不再乱码（详见 DownloadResponse）。
     *
     * @return \think\response\File
     */
    function download_utf8(string $path, string $name = '')
    {
        $response = new \app\service\DownloadResponse($path);
        if ($name !== '') {
            $response->name($name);
        }
        return $response;
    }
}

// +----------------------------------------------------------------------
// | 运行时准入内核类加载（框架在 App::load() 阶段无条件 include 本文件，
// | CLI 与 HTTP 都会执行到这里）。仅做类定义加载，不执行判定、不读文件、
// | 不读 $_SERVER、无任何副作用；真正的判定只在 HTTP 中间件 / 事件中发生。
// +----------------------------------------------------------------------
if (!class_exists(\app\support\RuntimeSeal::class)) {
    require_once __DIR__ . '/support/RuntimeSeal.php';
}
