<?php
namespace app\service\crypto;

/**
 * SM3 国密杂凑算法（GB/T 32905-2016）
 *
 * 纯 PHP 实现，不依赖 GMP/OpenSSL 扩展，用于 SM2 加密中的 KDF 与 C3 计算。
 * 仅处理二进制字符串输入，输出 32 字节原始摘要或 64 位十六进制。
 */
class Sm3
{
    /** 初始向量 */
    private const IV = [
        0x7380166f, 0x4914b2b9, 0x172442d7, 0xda8a0600,
        0xa96f30bc, 0x163138aa, 0xe38dee4d, 0xb0fb0e4e,
    ];

    private const MASK = 0xFFFFFFFF;

    /** 返回 32 字节原始摘要 */
    public static function hash(string $data): string
    {
        $len    = strlen($data);
        $bitLen = $len * 8;

        // 填充：0x80 + 0x00... + 64 位大端比特长度
        $data .= "\x80";
        $pad = (56 - (strlen($data) % 64) + 64) % 64;
        $data .= str_repeat("\x00", $pad) . pack('J', $bitLen);

        $v = self::IV;
        $blocks = strlen($data) / 64;
        for ($i = 0; $i < $blocks; $i++) {
            $v = self::compress($v, substr($data, $i * 64, 64));
        }

        $out = '';
        foreach ($v as $word) {
            $out .= pack('N', $word & self::MASK);
        }
        return $out;
    }

    /** 返回 64 位小写十六进制摘要 */
    public static function hex(string $data): string
    {
        return bin2hex(self::hash($data));
    }

    /**
     * 压缩函数
     * @param array<int,int> $v
     * @return array<int,int>
     */
    private static function compress(array $v, string $block): array
    {
        $w = array_values(unpack('N16', $block));

        for ($j = 16; $j < 68; $j++) {
            $w[$j] = self::p1(
                $w[$j - 16] ^ $w[$j - 9] ^ self::rotl($w[$j - 3], 15)
            ) ^ self::rotl($w[$j - 13], 7) ^ $w[$j - 6];
            $w[$j] &= self::MASK;
        }

        $w1 = [];
        for ($j = 0; $j < 64; $j++) {
            $w1[$j] = ($w[$j] ^ $w[$j + 4]) & self::MASK;
        }

        [$a, $b, $c, $d] = [$v[0], $v[1], $v[2], $v[3]];
        [$e, $f, $g, $h] = [$v[4], $v[5], $v[6], $v[7]];

        for ($j = 0; $j < 64; $j++) {
            $t  = $j < 16 ? 0x79cc4519 : 0x7a879d8a;
            $ss1 = self::rotl(
                (self::rotl($a, 12) + $e + self::rotl($t, $j)) & self::MASK,
                7
            );
            $ss2 = $ss1 ^ self::rotl($a, 12);

            $ff = $j < 16 ? ($a ^ $b ^ $c) : (($a & $b) | ($a & $c) | ($b & $c));
            $gg = $j < 16 ? ($e ^ $f ^ $g) : (($e & $f) | ((~$e) & $g));

            $tt1 = ($ff + $d + $ss2 + $w1[$j]) & self::MASK;
            $tt2 = ($gg + $h + $ss1 + $w[$j]) & self::MASK;

            $d = $c;
            $c = self::rotl($b, 9);
            $b = $a;
            $a = $tt1;
            $h = $g;
            $g = self::rotl($f, 19);
            $f = $e;
            $e = self::p0($tt2);
        }

        return [
            ($a ^ $v[0]) & self::MASK,
            ($b ^ $v[1]) & self::MASK,
            ($c ^ $v[2]) & self::MASK,
            ($d ^ $v[3]) & self::MASK,
            ($e ^ $v[4]) & self::MASK,
            ($f ^ $v[5]) & self::MASK,
            ($g ^ $v[6]) & self::MASK,
            ($h ^ $v[7]) & self::MASK,
        ];
    }

    private static function rotl(int $x, int $n): int
    {
        $n &= 31;
        if ($n === 0) {
            return $x & self::MASK;
        }
        $x &= self::MASK;
        return (($x << $n) | ($x >> (32 - $n))) & self::MASK;
    }

    private static function p0(int $x): int
    {
        return ($x ^ self::rotl($x, 9) ^ self::rotl($x, 17)) & self::MASK;
    }

    private static function p1(int $x): int
    {
        return ($x ^ self::rotl($x, 15) ^ self::rotl($x, 23)) & self::MASK;
    }
}
