<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | 该文件供 `php think run` 的内置开发服务器作为路由脚本使用：
// | 1) 命中 public/ 下的真实静态资源时直接返回（含目录回退 index.html）；
// | 2) 其余请求统一转发给入口 index.php 由 ThinkPHP 处理。
// +----------------------------------------------------------------------
namespace think;

$path = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// H5 入口禁止缓存：构建后资源文件名会变化，缓存旧 index.html 会导致分包 404。
if ($path === '/mobile') {
    header('Location: /mobile/', true, 301);
    exit;
}
if ($path === '/mobile/' || $path === '/mobile/index.html') {
    $index = __DIR__ . '/mobile/index.html';
    if (is_file($index)) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Content-Type: text/html; charset=UTF-8');
        readfile($index);
        exit;
    }
}

// PC 入口禁止缓存：构建后资源文件名会变化，缓存旧 index.html 会导致分包 404。
if ($path === '/web') {
    header('Location: /web/', true, 301);
    exit;
}
if ($path === '/web/' || $path === '/web/index.html') {
    $index = __DIR__ . '/web/index.html';
    if (is_file($index)) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Content-Type: text/html; charset=UTF-8');
        readfile($index);
        exit;
    }
}

// 安装向导是独立脚本，不经过框架
if ($path === '/install.php' || preg_match('#^/install\.php#i', $path)) {
    $_SERVER['SCRIPT_NAME'] = '/install.php';
    require __DIR__ . '/install.php';
    exit;
}

if ($path !== '/') {
    $file = __DIR__ . $path;
    if (is_dir($file)) {
        $file = rtrim($file, '/') . '/index.html';
    }
    if (is_file($file) && pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
        // 交由内置服务器直接返回静态资源，避免走框架路由
        return false;
    }
}

require __DIR__ . '/index.php';
