<?php
namespace app\common;

/**
 * 统一 JSON 响应结构
 * { code:0, msg:'ok', data:{}, count:0 }
 */
class Response
{
    public static function success($data = [], string $msg = 'ok')
    {
        return ['code' => 0, 'msg' => $msg, 'data' => $data];
    }

    public static function error(string $msg = 'error', int $code = 1, $data = [])
    {
        return ['code' => $code, 'msg' => $msg, 'data' => $data];
    }

    public static function page($list, int $total, string $msg = 'ok')
    {
        return ['code' => 0, 'msg' => $msg, 'data' => ['list' => $list, 'total' => $total]];
    }
}
