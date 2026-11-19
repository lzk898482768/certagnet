<?php
namespace app\service\crypto;

/**
 * HMAC-SM3（RFC 2104 结构，杂凑函数为 SM3）
 *
 * 用途：身份证号盲索引 id_card_hash = HMAC-SM3(idxKey, 归一化号码)。
 * 盲索引必须能等值比对，但不能由明文直接推导，因此用带密钥的 HMAC
 * 而不是裸 SM3（裸 SM3 对 18 位身份证可被穷举反查）。
 */
final class HmacSm3
{
    private const BLOCK = 64;

    /** 返回 32 字节原始摘要 */
    public static function hash(string $data, string $key): string
    {
        if (strlen($key) > self::BLOCK) {
            $key = Sm3::hash($key);
        }
        $key = str_pad($key, self::BLOCK, "\x00");

        $inner = Sm3::hash(($key ^ str_repeat("\x36", self::BLOCK)) . $data);
        return Sm3::hash(($key ^ str_repeat("\x5c", self::BLOCK)) . $inner);
    }

    /** 返回 64 位小写十六进制摘要 */
    public static function hex(string $data, string $key): string
    {
        return bin2hex(self::hash($data, $key));
    }

    /** 恒定时间比较（用于校验场景） */
    public static function equals(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }
}
