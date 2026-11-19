<?php
namespace app\service\crypto;

/**
 * SM2 公钥加密（GB/T 32918.4-2016，C1C3C2 格式）
 *
 * 纯 PHP 实现：曲线运算使用 bcmath（nginx/宝塔常见 PHP 均带 bcmath），
 * 杂凑使用本目录 Sm3，不依赖 GMP / OpenSSL / 外部命令行。
 *
 * 输出为十六进制字符串（不含 C1 的 "04" 前缀），与前端 sm-crypto
 * `sm2.doEncrypt(msg, pub, 1)` 的返回格式一致；调用方按接口要求补 "04"。
 */
class Sm2
{
    private const P  = 'FFFFFFFEFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFF00000000FFFFFFFFFFFFFFFF';
    private const A  = 'FFFFFFFEFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFF00000000FFFFFFFFFFFFFFFC';
    private const B  = '28E9FA9E9D9F5E344D5A9E4BCF6509A7F39789F515AB8F92DDBCBD414D940E93';
    private const N  = 'FFFFFFFEFFFFFFFFFFFFFFFFFFFFFFFF7203DF6B21C6052B53BBF40939D54123';
    private const GX = '32C4AE2C1F1981195F9904466A39C9948FE30BBFF2660BE1715A4589334C74C7';
    private const GY = 'BC3736A2F4F6779C59BDCEE36B692153D0A9877CC62A474002DF32E52139F0A0';

    /** @var array<string,string>|null 曲线参数（十进制） */
    private static $curve = null;

    /** 加密：返回 C1(x||y) + C3 + C2 的十六进制（不含 04 前缀） */
    public static function encrypt(string $message, string $publicKeyHex): string
    {
        self::assertBc();
        $c = self::curve();

        $publicKeyHex = strtolower(trim($publicKeyHex));
        if (strpos($publicKeyHex, '04') === 0) {
            $publicKeyHex = substr($publicKeyHex, 2);
        }
        if (strlen($publicKeyHex) !== 128) {
            throw new \RuntimeException('SM2 公钥格式错误');
        }
        $px = self::hexToDec(substr($publicKeyHex, 0, 64));
        $py = self::hexToDec(substr($publicKeyHex, 64, 64));

        $len = strlen($message);
        for ($try = 0; $try < 32; $try++) {
            $k  = self::randomScalar();
            $c1 = self::toAffine(self::scalarMultG($k));
            $s  = self::toAffine(self::scalarMult($k, $px, $py));
            if ($s === null) {
                continue;
            }
            $x2 = self::hexPad($s['x'], 64);
            $y2 = self::hexPad($s['y'], 64);
            $t  = self::kdf(hex2bin($x2) . hex2bin($y2), $len);
            if ($t === str_repeat("\x00", $len)) {
                continue; // KDF 全零，换随机数重来
            }
            $c2 = $message ^ $t;
            $c3 = Sm3::hash(hex2bin($x2) . $message . hex2bin($y2));

            return self::hexPad($c1['x'], 64)
                . self::hexPad($c1['y'], 64)
                . bin2hex($c3)
                . bin2hex($c2);
        }
        throw new \RuntimeException('SM2 加密失败：随机数重试次数超限');
    }

    /** 解密：$mode=1 为 C1C3C2，0 为 C1C2C3；失败返回 null */
    public static function decrypt(string $cipherHex, string $privateKeyHex, int $mode = 1): ?string
    {
        self::assertBc();
        $hex = strtolower(preg_replace('/\s+/', '', $cipherHex) ?? '');
        if (strpos($hex, '04') === 0 && strlen($hex) > 130) {
            $hex = substr($hex, 2); // C1 未压缩点前缀
        }
        if (strlen($hex) < 192) {
            return null;
        }

        $x1 = self::hexToDec(substr($hex, 0, 64));
        $y1 = self::hexToDec(substr($hex, 64, 64));
        $rest = substr($hex, 128);

        if ($mode === 1) {
            $c3hex = substr($rest, 0, 64);
            $c2hex = substr($rest, 64);
        } else {
            $c3hex = substr($rest, -64);
            $c2hex = substr($rest, 0, -64);
        }
        $c2 = hex2bin($c2hex);
        if ($c2 === false || strlen($c3hex) !== 64) {
            return null;
        }

        $d = self::hexToDec(trim($privateKeyHex));
        $s = self::toAffine(self::scalarMult($d, $x1, $y1));
        if ($s === null) {
            return null;
        }
        $x2 = self::hexPad($s['x'], 64);
        $y2 = self::hexPad($s['y'], 64);
        $t  = self::kdf(hex2bin($x2) . hex2bin($y2), strlen($c2));
        $m  = $c2 ^ $t;
        $u  = Sm3::hash(hex2bin($x2) . $m . hex2bin($y2));
        if (!hash_equals(bin2hex($u), strtolower($c3hex))) {
            return null;
        }
        return $m;
    }

    private static function assertBc(): void
    {
        if (!extension_loaded('bcmath')) {
            throw new \RuntimeException('服务器未启用 bcmath 扩展，无法执行国密采集');
        }
    }

    /** @return array<string,string> */
    private static function curve(): array
    {
        if (self::$curve === null) {
            self::$curve = [
                'p'  => self::hexToDec(self::P),
                'a'  => self::hexToDec(self::A),
                'b'  => self::hexToDec(self::B),
                'n'  => self::hexToDec(self::N),
                'gx' => self::hexToDec(self::GX),
                'gy' => self::hexToDec(self::GY),
            ];
        }
        return self::$curve;
    }

    /** 随机标量 k ∈ [1, n-1] */
    private static function randomScalar(): string
    {
        $c = self::curve();
        $d = self::hexToDec(bin2hex(random_bytes(32)));
        return bcadd(bcmod($d, bcsub($c['n'], '1', 0), 0), '1', 0);
    }

    /** @return array{x:string,y:string,z:string} */
    private static function inf(): array
    {
        return ['x' => '0', 'y' => '0', 'z' => '0'];
    }

    private static function isInf(array $p): bool
    {
        return $p['z'] === '0';
    }

    private static function scalarMultG(string $k): array
    {
        $c = self::curve();
        return self::scalarMult($k, $c['gx'], $c['gy']);
    }

    /** 标量乘：k * (x,y)，返回雅可比坐标 */
    private static function scalarMult(string $k, string $x, string $y): array
    {
        $bits = self::decToBin($k);
        $r = self::inf();
        $len = strlen($bits);
        for ($i = 0; $i < $len; $i++) {
            $r = self::doublePoint($r);
            if ($bits[$i] === '1') {
                $r = self::mixedAdd($r, $x, $y);
            }
        }
        return $r;
    }

    /** 雅可比坐标倍点 */
    private static function doublePoint(array $p): array
    {
        if (self::isInf($p) || $p['y'] === '0') {
            return self::inf();
        }
        $c = self::curve();
        $mod = static fn(string $v): string => self::mod($v, $c['p']);

        $X = $p['x'];
        $Y = $p['y'];
        $Z = $p['z'];

        $XX   = $mod(bcmul($X, $X, 0));
        $YY   = $mod(bcmul($Y, $Y, 0));
        $YYYY = $mod(bcmul($YY, $YY, 0));
        $ZZ   = $mod(bcmul($Z, $Z, 0));

        // S = 4*X*Y^2
        $S = $mod(bcmul('2', bcsub(bcsub(bcmul(bcadd($X, $YY, 0), bcadd($X, $YY, 0), 0), $XX, 0), $YYYY, 0), 0));
        // M = 3*X^2 + a*Z^4
        $M = $mod(bcadd(bcmul('3', $XX, 0), bcmul($c['a'], $mod(bcmul($ZZ, $ZZ, 0)), 0), 0));

        $X3 = $mod(bcsub(bcmul($M, $M, 0), bcmul('2', $S, 0), 0));
        $Y3 = $mod(bcsub(bcmul($M, bcsub($S, $X3, 0), 0), bcmul('8', $YYYY, 0), 0));
        $Z3 = $mod(bcmul('2', bcmul($Y, $Z, 0), 0));

        return ['x' => $X3, 'y' => $Y3, 'z' => $Z3];
    }

    /** 雅可比点 + 仿射点（Z2=1） */
    private static function mixedAdd(array $p, string $x2, string $y2): array
    {
        if (self::isInf($p)) {
            return ['x' => $x2, 'y' => $y2, 'z' => '1'];
        }
        $c = self::curve();
        $mod = static fn(string $v): string => self::mod($v, $c['p']);

        $X = $p['x'];
        $Y = $p['y'];
        $Z = $p['z'];

        $Z1Z1 = $mod(bcmul($Z, $Z, 0));
        $U2   = $mod(bcmul($x2, $Z1Z1, 0));
        $S2   = $mod(bcmul($y2, bcmul($Z, $Z1Z1, 0), 0));
        $H    = $mod(bcsub($U2, $X, 0));
        $r    = $mod(bcmul('2', bcsub($S2, $Y, 0), 0));

        if ($H === '0') {
            if ($r === '0') {
                return self::doublePoint($p);
            }
            return self::inf();
        }

        $HH = $mod(bcmul($H, $H, 0));
        $I  = $mod(bcmul('4', $HH, 0));
        $J  = $mod(bcmul($H, $I, 0));
        $V  = $mod(bcmul($X, $I, 0));

        $X3 = $mod(bcsub(bcsub(bcmul($r, $r, 0), $J, 0), bcmul('2', $V, 0), 0));
        $Y3 = $mod(bcsub(bcmul($r, bcsub($V, $X3, 0), 0), bcmul('2', bcmul($Y, $J, 0), 0), 0));
        $Z3 = $mod(bcmul(bcsub(bcsub(bcmul(bcadd($Z, '1', 0), bcadd($Z, '1', 0), 0), $Z1Z1, 0), '1', 0), $H, 0));

        return ['x' => $X3, 'y' => $Y3, 'z' => $Z3];
    }

    /** 雅可比 → 仿射；无穷远点返回 null */
    private static function toAffine(array $p): ?array
    {
        if (self::isInf($p)) {
            return null;
        }
        $c   = self::curve();
        $zinv = self::modInverse($p['z'], $c['p']);
        $zinv2 = self::mod(bcmul($zinv, $zinv, 0), $c['p']);
        $zinv3 = self::mod(bcmul($zinv2, $zinv, 0), $c['p']);
        return [
            'x' => self::mod(bcmul($p['x'], $zinv2, 0), $c['p']),
            'y' => self::mod(bcmul($p['y'], $zinv3, 0), $c['p']),
        ];
    }

    /** KDF（SM3 计数器模式） */
    private static function kdf(string $z, int $klen): string
    {
        $out = '';
        $ct  = 1;
        while (strlen($out) < $klen) {
            $out .= Sm3::hash($z . pack('N', $ct));
            $ct++;
        }
        return substr($out, 0, $klen);
    }

    /** 模运算（结果非负） */
    private static function mod(string $a, string $m): string
    {
        $r = bcmod($a, $m, 0);
        if (bccomp($r, '0', 0) < 0) {
            $r = bcadd($r, $m, 0);
        }
        return $r;
    }

    /** 模逆（扩展欧几里得） */
    private static function modInverse(string $a, string $m): string
    {
        $a = self::mod($a, $m);
        $g0 = $m;
        $g1 = $a;
        $u0 = '0';
        $u1 = '1';
        while (bccomp($g1, '0', 0) !== 0) {
            $q  = bcdiv($g0, $g1, 0);
            $g2 = bcsub($g0, bcmul($q, $g1, 0), 0);
            $u2 = bcsub($u0, bcmul($q, $u1, 0), 0);
            [$g0, $g1] = [$g1, $g2];
            [$u0, $u1] = [$u1, $u2];
        }
        if (bccomp($g0, '1', 0) !== 0) {
            throw new \RuntimeException('SM2 模逆不存在');
        }
        return self::mod($u0, $m);
    }

    private static function hexToDec(string $hex): string
    {
        $hex = ltrim(strtolower($hex), '0');
        if ($hex === '') {
            return '0';
        }
        $dec = '0';
        $len = strlen($hex);
        for ($i = 0; $i < $len; $i++) {
            $dec = bcadd(bcmul($dec, '16', 0), (string)hexdec($hex[$i]), 0);
        }
        return $dec;
    }

    private static function decToBin(string $dec): string
    {
        if (bccomp($dec, '0', 0) === 0) {
            return '0';
        }
        $hex = '';
        while (bccomp($dec, '0', 0) > 0) {
            $hex = dechex((int)bcmod($dec, '16', 0)) . $hex;
            $dec = bcdiv($dec, '16', 0);
        }
        $bin = '';
        $len = strlen($hex);
        for ($i = 0; $i < $len; $i++) {
            $bin .= str_pad(decbin(hexdec($hex[$i])), 4, '0', STR_PAD_LEFT);
        }
        return ltrim($bin, '0') ?: '0';
    }

    private static function hexPad(string $dec, int $len): string
    {
        $hex = '';
        while (bccomp($dec, '0', 0) > 0) {
            $hex = dechex((int)bcmod($dec, '16', 0)) . $hex;
            $dec = bcdiv($dec, '16', 0);
        }
        return str_pad($hex === '' ? '0' : $hex, $len, '0', STR_PAD_LEFT);
    }
}
