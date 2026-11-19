<?php
namespace app\service;

use think\facade\Cache;
use Predis\Client;
use think\cache\driver\Redis as RedisDriver;

/**
 * Redis 缓存统一入口（v2.38）
 *
 * 目标：
 * 1. 把"按键读、按键写、按模式失效"集中到一处，业务方只引用 key namespace + TTL。
 * 2. 强依赖 Redis（cache.php default='redis'），缺失即报清晰错误。
 *    业务层不静默回退到 file/db，避免"看似 OK 实际慢"的隐性回归。
 * 3. 自带 check() 方法，部署/启动/CI 都可拉一遍。
 *
 * 接入点（已迁移 / 将迁移）：
 *  - ImportService: staff 按 身份证/姓名 缓存、company 按名称缓存
 *  - CertDictService: cascade() 缓存
 *  - MenuRoleSync: 角色菜单快照缓存
 *  - LoginService: 登录 token/session
 *  - CertExpireService: 推送查用户表缓存（防止大批推送时反复查库）
 *
 * 写策略：
 *  - remember(name, ttl, fn) → 读穿透（带 try/catch Redis 故障时降级重新计算并抛出）
 *  - forget*() → 主动失效；写路径触发，彻底绕开 TTL 漂移导致的不一致
 */
class RedisCache
{
    /** 默认 TTL（5 分钟）：适合 IM/推送/字典这种"可能漂移，但用户能接受短时不一致"的场景 */
    public const TTL_DEFAULT  = 300;
    /** 短 TTL（60 秒）：适合登录态、token 校验 */
    public const TTL_SHORT    = 60;
    /** 长 TTL（30 分钟）：适合"几乎不变"的字典级联、菜单目录 */
    public const TTL_LONG     = 1800;

    /** 全局键前缀：业务用类前缀命名空间（如 import:staff:by_id_*） */
    public const NS_IMPORT       = 'imp';
    public const NS_CERT_DICT    = 'cdict';
    public const NS_MENU         = 'menu';
    public const NS_AUTH         = 'auth';
    public const NS_PUSH         = 'push';
    public const NS_TENANT       = 'tenant';
    /** 工作台统计：按「用户 + 数据范围」指纹缓存，TTL 短（统计数字允许轻微延迟） */
    public const NS_DASH         = 'dash';

    /**
     * 检测指定缓存 store 健康状态
     *
     * @return array{ok:bool, details?:array<string,string>, error?:string, hints?:string[]}
     */
    public static function check(string $store = 'redis'): array
    {
        $hints = [
            '确认 Redis 已启动：/d/phpstudy_pro/Extensions/redis3.0.504/redis-server.exe（本地）或 docker run -d -p 6379:6379 --name cert-redis redis:7-alpine',
            '检查 .env 中的 REDIS_HOST / REDIS_PORT / REDIS_PASSWORD / REDIS_DB',
            '查端口：netstat -ano | findstr :6379；ping：redis-cli -h 127.0.0.1 -p 6379 ping',
        ];
        try {
            $cfg = config("cache.stores.$store");
            if (!$cfg) {
                return ['ok' => false, 'error' => "cache store '$store' 未配置", 'hints' => $hints];
            }
            if (($cfg['type'] ?? '') !== 'Redis') {
                return ['ok' => false, 'error' => "cache store '$store' type={$cfg['type']}，本系统强依赖 Redis", 'hints' => $hints];
            }
            $drv = Cache::store($store);
            // 1) SET/GET 一次，验证基本读写
            $probeKey = '__probe__';
            $probeVal = 'r:' . random_int(1000, 9999);
            $drv->set($probeKey, $probeVal, 30);
            $got = $drv->get($probeKey);
            if ($got !== $probeVal) {
                throw new \RuntimeException("读写不一致：get=$got");
            }
            // 2) 拉底层 client 拿版本号
            $client = self::handler();
            $info   = $client->info('server') ?: [];
            // info 命令：predis 默认把 INFO 当成 'info server'，redis 3.x 返回键值对。
            // 用底层 client 走 executeRaw，跨版本都能正确解析
            $infoRaw = '';
            try { $infoRaw = (string)self::handler()->executeRaw(['INFO', 'server']); } catch (\Throwable $e) {}
            $info = [];
            foreach (preg_split('/\r?\n/', $infoRaw) as $ln) {
                if (preg_match('/^([a-zA-Z0-9_]+):(.*)$/', $ln, $m)) {
                    $info[$m[1]] = trim($m[2]);
                }
            }
            $details = [
                'store'   => $store,
                'host'    => ($cfg['host'] ?? '?') . ':' . ($cfg['port'] ?? '?'),
                'db'      => $cfg['select'] ?? 0,
                'version' => $info['redis_version'] ?? '?',
                'uptime'  => isset($info['uptime_in_seconds']) ? $info['uptime_in_seconds'] . 's' : '?',
                'keys'    => (int)self::handler()->dbsize(),
            ];
            $drv->delete($probeKey);
            return ['ok' => true, 'details' => $details];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'hints' => $hints];
        }
    }

    /**
     * 取 predis client（用于 info/dbsize/keys 等管理命令，Cache facade 没法干这些）
     */
    public static function handler(): Client
    {
        static $cli = null;
        if ($cli !== null) {
            return $cli;
        }
        $cfg        = config('cache.stores.redis');
        $opts       = [
            'host'     => $cfg['host']     ?? '127.0.0.1',
            'port'     => (int)($cfg['port'] ?? 6379),
            'database' => (int)($cfg['select'] ?? 0),
            'timeout'  => 1.0,
        ];
        if (!empty($cfg['password'])) {
            $opts['password'] = $cfg['password'];
        }
        $cli = new Client($opts);
        return $cli;
    }

    /**
     * 读穿透 + 缓存：先看缓存，没有则回调生成并写入
     *
     * 注意：`$compute` 返回 null 时**不写缓存**（视为"无结果"，由调用方决定是否负缓存）。
     *
     * @template T
     * @param callable():T $compute
     * @return T
     */
    public static function remember(string $ns, string $key, int $ttl, callable $compute): mixed
    {
        $name  = self::name($ns, $key);
        $store = Cache::store('redis');
        $hit   = $store->get($name);
        if ($hit !== null) {
            return $hit;
        }
        $val = $compute();
        if ($val !== null) {
            $store->set($name, $val, $ttl);
        }
        return $val;
    }

    /** 直读（未命中返回 null，用于负缓存等需要区分"命中空值"与"未缓存"的场景） */
    public static function get(string $ns, string $key): mixed
    {
        return Cache::store('redis')->get(self::name($ns, $key));
    }

    /** 直写（同上） */
    public static function put(string $ns, string $key, mixed $val, int $ttl): void
    {
        Cache::store('redis')->set(self::name($ns, $key), $val, $ttl);
    }

    /** 批量读（缺失项返回 null）。键名自动加 ns 前缀。 */
    public static function mget(string $ns, array $keys): array
    {
        $store = Cache::store('redis');
        $out   = [];
        foreach ($keys as $k) {
            $out[$k] = $store->get(self::name($ns, $k));
        }
        return $out;
    }

    /** 批量写 */
    public static function mput(string $ns, array $map, int $ttl): void
    {
        $store = Cache::store('redis');
        foreach ($map as $k => $v) {
            $store->set(self::name($ns, $k), $v, $ttl);
        }
    }

    /**
     * 主动失效（写路径触发，例如人员改档/删档后抹掉 by_id_* 缓存）
     */
    public static function forget(string $ns, string $key): void
    {
        Cache::store('redis')->delete(self::name($ns, $key));
    }

    /**
     * 批量失效
     *
     * 注意：TP 的 Redis 驱动 `delete()` 内部是 `handler->del($key)`（单键），
     * 传数组会触发 predis 参数异常，故此处必须逐键调用。
     */
    public static function forgetMany(string $ns, array $keys): void
    {
        $store = Cache::store('redis');
        foreach ($keys as $k) {
            $store->delete(self::name($ns, $k));
        }
    }

    /**
     * 按 namespace 全清（如字典维护后、批量数据修复后调一次）
     *
     * 用 SCAN 游标遍历（非 KEYS），避免大 key 空间时阻塞 Redis。
     * predis 的 scan 游标是按引用回传的字符串，'0' 表示遍历结束。
     */
    public static function flushNs(string $ns): void
    {
        $prefix  = (string)config('cache.stores.redis.prefix', 'cert:');
        $pattern = $prefix . $ns . ':*';
        $cli     = self::handler();
        $cursor  = '0';
        do {
            // predis 2.x 的 scan() 只接受游标/pattern/count 位置参数，
            // 传 options 数组会被当成 pattern 而抛 "Array to string conversion"，
            // 故直接 executeRaw 拼命令，返回值为 [cursor, keys]。
            $reply = $cli->executeRaw(['SCAN', $cursor, 'MATCH', $pattern, 'COUNT', '200']);
            if (!is_array($reply) || count($reply) < 2) {
                break;
            }
            $cursor = (string)$reply[0];
            $keys   = is_array($reply[1]) ? $reply[1] : [$reply[1]];
            if ($keys) {
                $cli->del($keys);
            }
        } while ($cursor !== '0');
    }

    /** 统一键名（TP 驱动会再加 cache.stores.redis.prefix 前缀） */
    private static function name(string $ns, string $key): string
    {
        return $ns . ':' . $key;
    }
}
