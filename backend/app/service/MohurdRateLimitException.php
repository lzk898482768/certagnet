<?php
namespace app\service;

/**
 * 住建部四库一平台 CC 风控专用异常（v2.99.82）
 *
 * jzsc.mohurd.gov.cn 前置 openresty 启用了 CC 频率防护（响应头 x-cc-via）：
 * 按「请求频率 + 出口 IP 信誉」拦截，命中后对 API 路径返回空响应体的 403，
 * 且无图形验证码、无 JS 挑战页，机房 IP 更易被封且恢复较慢（实测十余分钟以上）。
 *
 * 控制器捕获本异常后自动回退河南省平台采集（带人工验证码），保证采集不中断。
 */
class MohurdRateLimitException extends \RuntimeException
{
}
