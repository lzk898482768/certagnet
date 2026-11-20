<?php
declare(strict_types=1);

/**
 * 路由诊断探针（独立入口，不经 index.php / 中间件，锁死态也可访问）。
 *
 * 仅回显与「PATH_INFO / 路由解析」相关的服务器变量，不读取也不输出任何
 * 业务数据、密码、Cookie、授权凭证。用于排查 nginx + php-fpm 下
 * pathinfo 丢失导致所有接口被当成根路径（302 跳 /web/）的问题。
 *
 * 用法：
 *   curl -i "http://域名:端口/envprobe.php/api/captcha"
 *   curl -i "http://域名:端口/envprobe.php?x=/api/captcha"
 *
 * 判定：
 *   - 正常：PATH_INFO=/api/captcha，REQUEST_URI=/envprobe.php/api/captcha
 *   - 异常：PATH_INFO 为空，则 ThinkPHP 会把请求解析为根路径。
 */

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$wantKeys = [
    'REQUEST_URI',
    'PATH_INFO',
    'ORIG_PATH_INFO',
    'REDIRECT_PATH_INFO',
    'REDIRECT_URL',
    'SCRIPT_NAME',
    'SCRIPT_FILENAME',
    'PHP_SELF',
    'DOCUMENT_ROOT',
    'REQUEST_METHOD',
    'HTTP_HOST',
    'SERVER_SOFTWARE',
    'GATEWAY_INTERFACE',
];

$lines = [];
$lines[] = 'envprobe ' . date('c');
$lines[] = 'cgi.fix_pathinfo=' . ini_get('cgi.fix_pathinfo');
$lines[] = 'SAPI=' . PHP_SAPI;
$lines[] = '---';
foreach ($wantKeys as $k) {
    $v = $_SERVER[$k] ?? null;
    if ($v === null) {
        $lines[] = $k . '=(unset)';
    } else {
        $lines[] = $k . '=' . $v;
    }
}

echo implode("\n", $lines) . "\n";
