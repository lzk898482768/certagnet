<?php
/**
 * 健康探针：仅检测 PHP 进程是否存活，不检测授权状态。
 * 独立入口，不经 index.php / 中间件，锁死态也必须正常返回固定 ok。
 * 禁止把探活挂到 setup.php 的查询参数上。
 */
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
echo 'ok';
