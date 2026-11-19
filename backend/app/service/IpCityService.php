<?php
namespace app\service;

/**
 * IP 归属地离线解析（v2.99.50）
 *
 * 基于 ip2region 官方 xdb v2 数据文件（IPv4），纯 PHP 实现，零第三方依赖、无需联网，
 * 用于登录风控：受信任设备建立时的城市与本次登录城市不一致 → 判为异地登录。
 *
 * 数据文件：extend/ip2region/ip2region_v4.xdb（随仓库分发，可用 system_config
 * `ip2region_db` 指向自定义路径覆盖）。文件结构：
 *   [0,256)          头：version(u16)/indexPolicy(u16)/createdAt(u32)/
 *                    startIndexPtr(u32)/endIndexPtr(u32)/ipVersion(u16)/runtimePtrBytes(u16)
 *   [256, 256+512K)  向量索引：256×256 个 (startPtr u32, endPtr u32)
 *   ...              段索引（每段 4+4+2+4=14 字节）与数据
 *
 * 解析结果格式（v4 xdb 实际为五段）：`国家|省份|城市|ISP|国家代码`，
 * 如 `中国|河南省|郑州市|电信|CN`；未知项以 `0` 占位。
 *
 * 安全/健壮性：
 *   - 文件缺失、头非法、解析失败一律返回空字符串（判为「归属地未知」），绝不阻断登录；
 *   - 内网/回环/保留地址直接判为未知，避免把 127.0.0.1 当作城市；
 *   - 向量索引仅加载一次（512KB），单次查询约读 1~2 次段索引，性能稳定；
 *   - 结果按 IP 进程内缓存，登录高峰期避免重复计算。
 */
class IpCityService
{
    private const HEADER_LEN = 256;
    private const VECTOR_ROWS = 256;
    private const VECTOR_COLS = 256;
    private const VECTOR_SIZE = 8;
    private const SEGMENT_INDEX_SIZE = 14; // IPv4: 4 + 4 + 2 + 4
    private const IP_BYTES = 4;

    /** 结果缓存上限（防止异常流量打爆内存） */
    private const CACHE_MAX = 512;

    private static $handle = null;
    private static $ready = false;
    private static $vectorIndex = null;
    private static $cache = [];

    /** 自定义数据库路径（system_config.ip2region_db），空则用默认随包文件 */
    public static function dbFile(): string
    {
        $custom = trim((string)sys_config('ip2region_db', ''));
        if ($custom !== '' && is_file($custom)) {
            return $custom;
        }
        return root_path() . 'extend' . DIRECTORY_SEPARATOR . 'ip2region' . DIRECTORY_SEPARATOR . 'ip2region_v4.xdb';
    }

    public static function available(): bool
    {
        return self::init();
    }

    /**
     * 解析 IP 归属地。
     *
     * @return array{country:string,province:string,city:string,isp:string,code:string,text:string}
     */
    public static function lookup(string $ip): array
    {
        $empty = ['country' => '', 'province' => '', 'city' => '', 'isp' => '', 'code' => '', 'text' => ''];
        $ip = self::normIp($ip);
        if ($ip === '' || !self::init()) {
            return $empty;
        }
        if (array_key_exists($ip, self::$cache)) {
            return self::$cache[$ip];
        }

        $raw = self::search($ip);
        if ($raw === '') {
            return self::remember($ip, $empty);
        }
        // 格式：国家|省份|城市|ISP|国家代码（未知项为 0）。
        // 兼容历史五段式 `国家|区域|省份|城市|ISP`：v4 数据的第 5 段固定为
        // 两位大写 ISO 国家代码，旧格式第 5 段是 ISP 中文名，据此判定。
        $parts = explode('|', $raw);
        $pick = static function (int $i) use ($parts): string {
            $v = trim((string)($parts[$i] ?? ''));
            return ($v === '0' || $v === '-') ? '' : $v;
        };
        $c0 = $pick(0);
        $c1 = $pick(1);
        $c2 = $pick(2);
        $c3 = $pick(3);
        $c4 = $pick(4);
        $isOld = $c4 !== '' && !preg_match('/^[A-Z]{2}$/', $c4);
        $r = $isOld
            ? ['country' => $c0, 'province' => $c2, 'city' => $c3, 'isp' => $c4, 'code' => '', 'text' => $raw]
            : ['country' => $c0, 'province' => $c1, 'city' => $c2, 'isp' => $c3, 'code' => $c4, 'text' => $raw];
        return self::remember($ip, $r);
    }

    /**
     * 城市基线（用于异地比对）：`省|市`；归属地未知返回空串。
     * 直辖市（省==市）归一为单段，避免「北京市|北京市」与「北京市」不一致。
     */
    public static function cityOf(string $ip): string
    {
        $r = self::lookup($ip);
        $province = $r['province'];
        $city = $r['city'];
        if ($city === '' && $province === '') {
            return '';
        }
        if ($province !== '' && $city !== '' && $province === $city) {
            return $city;
        }
        return $province !== '' && $city !== '' ? $province . '|' . $city : ($city !== '' ? $city : $province);
    }

    /** 供前端/日志展示的城市名（优先市，其次省，未知返回空） */
    public static function cityName(string $ip): string
    {
        $r = self::lookup($ip);
        return $r['city'] !== '' ? $r['city'] : $r['province'];
    }

    /**
     * 规整 IP：去空白/端口；IPv4-mapped IPv6 转 IPv4；内网、回环、保留地址返回空串。
     */
    public static function normIp(string $ip): string
    {
        $ip = trim($ip);
        if ($ip === '') {
            return '';
        }
        // 去掉可能的端口（如 1.2.3.4:5678）
        if (preg_match('/^\[(.+)\](?::\d+)?$/', $ip, $m)) {
            $ip = $m[1];
        } elseif (substr_count($ip, ':') === 1 && strpos($ip, '.') !== false) {
            $ip = explode(':', $ip)[0];
        }
        // IPv4-mapped IPv6（::ffff:1.2.3.4 / ::ffff:0102:0304）
        if (stripos($ip, '::ffff:') === 0) {
            $tail = substr($ip, 7);
            if (filter_var($tail, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ip = $tail;
            }
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return ''; // IPv6 纯地址：当前随包为 IPv4 库，判为未知
        }
        if (self::isPrivate($ip)) {
            return '';
        }
        return $ip;
    }

    public static function isPrivate(string $ip): bool
    {
        $long = ip2long($ip);
        if ($long === false) {
            return true;
        }
        $long = $long & 0xFFFFFFFF;
        $ranges = [
            ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8],
            ['169.254.0.0', 16], ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.168.0.0', 16],
            ['198.18.0.0', 15], ['224.0.0.0', 4], ['240.0.0.0', 4],
        ];
        foreach ($ranges as [$net, $bits]) {
            $mask = $bits === 0 ? 0 : ((0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF);
            if (($long & $mask) === (ip2long($net) & 0xFFFFFFFF & $mask)) {
                return true;
            }
        }
        return false;
    }

    /** 初始化：打开文件 + 校验头 + 载入向量索引（仅一次） */
    private static function init(): bool
    {
        if (self::$ready) {
            return self::$vectorIndex !== null;
        }
        self::$ready = true;
        try {
            $file = self::dbFile();
            if (!is_file($file)) {
                return false;
            }
            $fh = fopen($file, 'rb');
            if ($fh === false) {
                return false;
            }
            $header = fread($fh, self::HEADER_LEN);
            if ($header === false || strlen($header) !== self::HEADER_LEN) {
                fclose($fh);
                return false;
            }
            $u16 = static function (string $b, int $o): int {
                return ord($b[$o]) | (ord($b[$o + 1]) << 8);
            };
            $version = $u16($header, 0);
            $ipVersion = $u16($header, 16);
            // 兼容 v2（2）与 v3（3）结构；只接受 IPv4 数据
            if (($version !== 2 && $version !== 3) || $ipVersion !== 4) {
                fclose($fh);
                return false;
            }
            if (fseek($fh, self::HEADER_LEN) !== 0) {
                fclose($fh);
                return false;
            }
            $len = self::VECTOR_ROWS * self::VECTOR_COLS * self::VECTOR_SIZE;
            $vector = fread($fh, $len);
            if ($vector === false || strlen($vector) !== $len) {
                fclose($fh);
                return false;
            }
            self::$handle = $fh;
            self::$vectorIndex = $vector;
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** 在 xdb 中检索 IP，返回原始 region 字符串；未命中返回空串 */
    private static function search(string $ip): string
    {
        if (self::$handle === null || self::$vectorIndex === null) {
            return '';
        }
        try {
            $bytes = (string)inet_pton($ip);
            if (strlen($bytes) !== self::IP_BYTES) {
                return '';
            }
            $il0 = ord($bytes[0]);
            $il1 = ord($bytes[1]);
            $idx = $il0 * self::VECTOR_COLS * self::VECTOR_SIZE + $il1 * self::VECTOR_SIZE;
            $sPtr = self::u32(self::$vectorIndex, $idx);
            $ePtr = self::u32(self::$vectorIndex, $idx + 4);
            if ($sPtr === 0 || $ePtr === 0 || $ePtr <= $sPtr) {
                return '';
            }

            $count = intdiv($ePtr - $sPtr, self::SEGMENT_INDEX_SIZE);
            $l = 0;
            $h = $count;
            while ($l <= $h) {
                $m = ($l + $h) >> 1;
                $p = $sPtr + $m * self::SEGMENT_INDEX_SIZE;
                $buff = self::read($p, self::SEGMENT_INDEX_SIZE);
                if ($buff === '') {
                    return '';
                }
                $cmpStart = self::ipSubCompare($bytes, $buff, 0);
                if ($cmpStart < 0) {
                    $h = $m - 1;
                    continue;
                }
                $cmpEnd = self::ipSubCompare($bytes, $buff, self::IP_BYTES);
                if ($cmpEnd > 0) {
                    $l = $m + 1;
                    continue;
                }
                $dataLen = ord($buff[8]) | (ord($buff[9]) << 8);
                $dataPtr = self::u32($buff, 10);
                if ($dataLen === 0 || $dataPtr === 0) {
                    return '';
                }
                return self::read($dataPtr, $dataLen);
            }
            return '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** 小端 u32 */
    private static function u32(string $b, int $o): int
    {
        return ord($b[$o]) | (ord($b[$o + 1]) << 8) | (ord($b[$o + 2]) << 16) | (ord($b[$o + 3]) << 24);
    }

    /** xdb 段索引中的 IP 以小端存储，需反向逐字节比较 */
    private static function ipSubCompare(string $ip, string $buff, int $offset): int
    {
        $len = strlen($ip);
        for ($i = 0, $j = $offset + $len - 1; $i < $len; $i++, $j--) {
            $a = ord($ip[$i]);
            $b = ord($buff[$j]);
            if ($a > $b) {
                return 1;
            }
            if ($a < $b) {
                return -1;
            }
        }
        return 0;
    }

    private static function read(int $offset, int $len): string
    {
        if (self::$handle === null || $len <= 0) {
            return '';
        }
        if (fseek(self::$handle, $offset) !== 0) {
            return '';
        }
        $buff = fread(self::$handle, $len);
        return ($buff === false || strlen($buff) !== $len) ? '' : $buff;
    }

    private static function remember(string $ip, array $r): array
    {
        if (count(self::$cache) >= self::CACHE_MAX) {
            self::$cache = [];
        }
        self::$cache[$ip] = $r;
        return $r;
    }
}
