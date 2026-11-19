<?php
namespace app\service\crypto;

/**
 * SM4 分组密码（GB/T 32907-2016）
 *
 * 用途：身份证号等敏感字段落库加密（CBC + PKCS#7）。
 *  - 优先使用 OpenSSL 的 sm4-cbc（PHP 7.4+ / OpenSSL 1.1.1+），性能好且经过充分验证；
 *  - 扩展不可用时退回纯 PHP 实现，保证低版本环境仍可用（速度约为 OpenSSL 的 1/50）。
 *
 * 仅处理二进制字符串；填充与 IV 管理由调用方（IdCardService）负责。
 */
final class Sm4
{
    private const MASK = 0xFFFFFFFF;

    /** S 盒 */
    private const SBOX = [
        0xd6, 0x90, 0xe9, 0xfe, 0xcc, 0xe1, 0x3d, 0xb7, 0x16, 0xb6, 0x14, 0xc2, 0x28, 0xfb, 0x2c, 0x05,
        0x2b, 0x67, 0x9a, 0x76, 0x2a, 0xbe, 0x04, 0xc3, 0xaa, 0x44, 0x13, 0x26, 0x49, 0x86, 0x06, 0x99,
        0x9c, 0x42, 0x50, 0xf4, 0x91, 0xef, 0x98, 0x7a, 0x33, 0x54, 0x0b, 0x43, 0xed, 0xcf, 0xac, 0x62,
        0xe4, 0xb3, 0x1c, 0xa9, 0xc9, 0x08, 0xe8, 0x95, 0x80, 0xdf, 0x94, 0xfa, 0x75, 0x8f, 0x3f, 0xa6,
        0x47, 0x07, 0xa7, 0xfc, 0xf3, 0x73, 0x17, 0xba, 0x83, 0x59, 0x3c, 0x19, 0xe6, 0x85, 0x4f, 0xa8,
        0x68, 0x6b, 0x81, 0xb2, 0x71, 0x64, 0xda, 0x8b, 0xf8, 0xeb, 0x0f, 0x4b, 0x70, 0x56, 0x9d, 0x35,
        0x1e, 0x24, 0x0e, 0x5e, 0x63, 0x58, 0xd1, 0xa2, 0x25, 0x22, 0x7c, 0x3b, 0x01, 0x21, 0x78, 0x87,
        0xd4, 0x00, 0x46, 0x57, 0x9f, 0xd3, 0x27, 0x52, 0x4c, 0x36, 0x02, 0xe7, 0xa0, 0xc4, 0xc8, 0x9e,
        0xea, 0xbf, 0x8a, 0xd2, 0x40, 0xc7, 0x38, 0xb5, 0xa3, 0xf7, 0xf2, 0xce, 0xf9, 0x61, 0x15, 0xa1,
        0xe0, 0xae, 0x5d, 0xa4, 0x9b, 0x34, 0x1a, 0x55, 0xad, 0x93, 0x32, 0x30, 0xf5, 0x8c, 0xb1, 0xe3,
        0x1d, 0xf6, 0xe2, 0x2e, 0x82, 0x66, 0xca, 0x60, 0xc0, 0x29, 0x23, 0xab, 0x0d, 0x53, 0x4e, 0x6f,
        0xd5, 0xdb, 0x37, 0x45, 0xde, 0xfd, 0x8e, 0x2f, 0x03, 0xff, 0x6a, 0x72, 0x6d, 0x6c, 0x5b, 0x51,
        0x8d, 0x1b, 0xaf, 0x92, 0xbb, 0xdd, 0xbc, 0x7f, 0x11, 0xd9, 0x5c, 0x41, 0x1f, 0x10, 0x5a, 0xd8,
        0x0a, 0xc1, 0x31, 0x88, 0xa5, 0xcd, 0x7b, 0xbd, 0x2d, 0x74, 0xd0, 0x12, 0xb8, 0xe5, 0xb4, 0xb0,
        0x89, 0x69, 0x97, 0x4a, 0x0c, 0x96, 0x77, 0x7e, 0x65, 0xb9, 0xf1, 0x09, 0xc5, 0x6e, 0xc6, 0x84,
        0x18, 0xf0, 0x7d, 0xec, 0x3a, 0xdc, 0x4d, 0x20, 0x79, 0xee, 0x5f, 0x3e, 0xd7, 0xcb, 0x39, 0x48,
    ];

    /** 系统参数 FK */
    private const FK = [0xa3b1bac6, 0x56aa3350, 0x677d9197, 0xb27022dc];

    /** 固定参数 CK */
    private const CK = [
        0x00070e15, 0x1c232a31, 0x383f464d, 0x545b6269,
        0x70777e85, 0x8c939aa1, 0xa8afb6bd, 0xc4cbd2d9,
        0xe0e7eef5, 0xfc030a11, 0x181f262d, 0x343b4249,
        0x50575e65, 0x6c737a81, 0x888f969d, 0xa4abb2b9,
        0xc0c7ced5, 0xdce3eaf1, 0xf8ff060d, 0x141b2229,
        0x30373e45, 0x4c535a61, 0x686f767d, 0x848b9299,
        0xa0a7aeb5, 0xbcc3cad1, 0xd8dfe6ed, 0xf4fb0209,
        0x10171e25, 0x2c333a41, 0x484f565d, 0x646b7279,
    ];

    /** 当前环境是否支持 OpenSSL 的 sm4-cbc */
    public static function opensslSupported(): bool
    {
        static $supported = null;
        if ($supported === null) {
            $supported = in_array('sm4-cbc', openssl_get_cipher_methods(), true);
        }
        return $supported;
    }

    /**
     * CBC 加密（PKCS#7 填充）。
     *
     * @param string $plain 明文（任意长度）
     * @param string $key   16 字节密钥
     * @param string $iv    16 字节随机 IV
     */
    public static function encryptCbc(string $plain, string $key, string $iv): string
    {
        self::assertParams($key, $iv);
        if (self::opensslSupported()) {
            $out = openssl_encrypt($plain, 'sm4-cbc', $key, OPENSSL_RAW_DATA, $iv);
            if ($out === false) {
                throw new \RuntimeException('SM4 加密失败（OpenSSL）');
            }
            return $out;
        }
        return self::cbcEncryptPure($plain, $key, $iv);
    }

    /**
     * CBC 解密（自动去 PKCS#7 填充）。
     *
     * @param string $cipher 密文（长度必须为 16 的倍数）
     */
    public static function decryptCbc(string $cipher, string $key, string $iv): string
    {
        self::assertParams($key, $iv);
        if ($cipher === '' || strlen($cipher) % 16 !== 0) {
            throw new \RuntimeException('SM4 密文长度非法');
        }
        if (self::opensslSupported()) {
            $out = openssl_decrypt($cipher, 'sm4-cbc', $key, OPENSSL_RAW_DATA, $iv);
            if ($out === false) {
                throw new \RuntimeException('SM4 解密失败（OpenSSL）');
            }
            return $out;
        }
        return self::cbcDecryptPure($cipher, $key, $iv);
    }

    /** 单块加密（16 字节输入，无填充） */
    public static function encryptBlock(string $block, string $key): string
    {
        if (strlen($block) !== 16) {
            throw new \RuntimeException('SM4 分组长度必须为 16 字节');
        }
        $rk = self::keySchedule($key);
        $x  = array_values(unpack('N4', $block));
        $x  = self::crypt($x, $rk);
        return pack('N4', $x[0], $x[1], $x[2], $x[3]);
    }

    // ===================== 纯 PHP 实现 =====================

    private static function cbcEncryptPure(string $plain, string $key, string $iv): string
    {
        $plain = self::pkcs7Pad($plain);
        $rk    = self::keySchedule($key);
        $prev  = $iv;
        $out   = '';
        for ($i = 0, $n = strlen($plain); $i < $n; $i += 16) {
            $block = substr($plain, $i, 16) ^ $prev;
            $prev  = self::encryptBlockWithKey($block, $rk);
            $out  .= $prev;
        }
        return $out;
    }

    private static function cbcDecryptPure(string $cipher, string $key, string $iv): string
    {
        $rk    = self::keySchedule($key);
        $prev  = $iv;
        $out   = '';
        for ($i = 0, $n = strlen($cipher); $i < $n; $i += 16) {
            $block = substr($cipher, $i, 16);
            $out  .= self::decryptBlockWithKey($block, $rk) ^ $prev;
            $prev  = $block;
        }
        return self::pkcs7Unpad($out);
    }

    private static function encryptBlockWithKey(string $block, array $rk): string
    {
        $x = array_values(unpack('N4', $block));
        $x = self::crypt($x, $rk);
        return pack('N4', $x[0], $x[1], $x[2], $x[3]);
    }

    private static function decryptBlockWithKey(string $block, array $rk): string
    {
        $x = array_values(unpack('N4', $block));
        // 解密轮密钥倒序
        $x = self::crypt($x, array_reverse($rk));
        return pack('N4', $x[0], $x[1], $x[2], $x[3]);
    }

    /** 32 轮迭代（加密/解密共用，解密时传入倒序轮密钥） */
    private static function crypt(array $x, array $rk): array
    {
        for ($i = 0; $i < 32; $i++) {
            $t = ($x[$i + 1] ^ $x[$i + 2] ^ $x[$i + 3] ^ $rk[$i]) & self::MASK;
            $x[$i + 4] = ($x[$i] ^ self::tauL($t)) & self::MASK;
        }
        return [$x[35], $x[34], $x[33], $x[32]];
    }

    /** 密钥扩展：MK → rk[0..31] */
    private static function keySchedule(string $key): array
    {
        if (strlen($key) !== 16) {
            throw new \RuntimeException('SM4 密钥必须为 16 字节');
        }
        $k = array_values(unpack('N4', $key));
        for ($i = 0; $i < 4; $i++) {
            $k[$i] = ($k[$i] ^ self::FK[$i]) & self::MASK;
        }
        $rk = [];
        for ($i = 0; $i < 32; $i++) {
            $t = ($k[$i + 1] ^ $k[$i + 2] ^ $k[$i + 3] ^ self::CK[$i]) & self::MASK;
            $k[$i + 4] = ($k[$i] ^ self::tauLPrime($t)) & self::MASK;
            $rk[$i] = $k[$i + 4];
        }
        return $rk;
    }

    /** 合成置换 T（用于轮函数） */
    private static function tauL(int $a): int
    {
        $b = self::tau($a);
        return ($b ^ self::rotl($b, 2) ^ self::rotl($b, 10) ^ self::rotl($b, 18) ^ self::rotl($b, 24)) & self::MASK;
    }

    /** 合成置换 T'（用于密钥扩展） */
    private static function tauLPrime(int $a): int
    {
        $b = self::tau($a);
        return ($b ^ self::rotl($b, 13) ^ self::rotl($b, 23)) & self::MASK;
    }

    /** 非线性变换 τ：逐字节查 S 盒 */
    private static function tau(int $a): int
    {
        return (self::SBOX[($a >> 24) & 0xFF] << 24)
            | (self::SBOX[($a >> 16) & 0xFF] << 16)
            | (self::SBOX[($a >> 8) & 0xFF] << 8)
            | self::SBOX[$a & 0xFF];
    }

    private static function rotl(int $x, int $n): int
    {
        return (($x << $n) | ($x >> (32 - $n))) & self::MASK;
    }

    private static function pkcs7Pad(string $data): string
    {
        $pad = 16 - (strlen($data) % 16);
        return $data . str_repeat(chr($pad), $pad);
    }

    private static function pkcs7Unpad(string $data): string
    {
        $len = strlen($data);
        if ($len === 0 || $len % 16 !== 0) {
            throw new \RuntimeException('SM4 填充非法');
        }
        $pad = ord($data[$len - 1]);
        if ($pad < 1 || $pad > 16) {
            throw new \RuntimeException('SM4 填充非法');
        }
        return substr($data, 0, $len - $pad);
    }

    private static function assertParams(string $key, string $iv): void
    {
        if (strlen($key) !== 16) {
            throw new \RuntimeException('SM4 密钥必须为 16 字节');
        }
        if (strlen($iv) !== 16) {
            throw new \RuntimeException('SM4 IV 必须为 16 字节');
        }
    }
}
