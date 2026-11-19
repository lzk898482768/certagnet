<?php
namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
use app\service\RedisCache;

/**
 * Redis 连接与配置自检（安装/启动时跑）
 *
 * 用法：
 *   php think redis:check                # 检测缓存服务（默认 store=redis）
 *   php think redis:check --store=file   # 检测其它 store（仅用于对照）
 *   php think redis:check --flush        # 检测通过后清空本系统缓存键（部署/排障用）
 *   php think redis:check --flush --ns=menu   # 只清某个命名空间
 *
 * 输出：
 *   - ok=true  时打 host:port、版本、是否可读写、PTTL 自检（毫秒级）
 *   - 不可用  时 exit code = 1，并把修复建议写入 stderr，
 *              方便 CI/Docker 健康检查把启动顺序（Redis -> Web）固化成依赖
 *
 * 部署约定：
 *   - 如果执行结果非 0，请先启动 Redis（phpstudy 自带 /d/phpstudy_pro/Extensions/redis3.0.504）
 *     或 docker run -d --name cert-redis -p 6379:6379 redis:7-alpine
 *   - 修改 .env: REDIS_HOST / REDIS_PORT / REDIS_PASSWORD / REDIS_DB 调整连接
 *   - 与缓存相关的所有性能优化都依赖该服务，未启动前页面会立即 5xx
 */
class RedisCheck extends Command
{
    protected function configure()
    {
        $this->setName('redis:check')
             ->addOption('store', null, Option::VALUE_OPTIONAL, '要检测的缓存 store 名', 'redis')
             ->addOption('flush', null, Option::VALUE_NONE, '检测通过后清空缓存键')
             ->addOption('ns', null, Option::VALUE_OPTIONAL, '配合 --flush：只清指定命名空间（imp/cdict/menu/auth/push）')
             ->setDescription('检测 Redis 缓存服务是否可用，输出版本/连接信息/读写吞吐');
    }

    protected function execute(Input $input, Output $output): int
    {
        $store  = $input->getOption('store') ?: 'redis';
        $report = RedisCache::check($store);
        if ($report['ok']) {
            foreach ($report['details'] as $k => $v) {
                $output->writeln("[ok] $k: $v");
            }
            if ($input->getOption('flush')) {
                $ns = (string)($input->getOption('ns') ?: '');
                $before = (int)RedisCache::handler()->dbsize();
                if ($ns !== '') {
                    RedisCache::flushNs($ns);
                } else {
                    self::flushSystemKeys();
                }
                $output->writeln(sprintf(
                    '[ok] 缓存已清理：%s（keys %d → %d）',
                    $ns !== '' ? "命名空间 $ns" : '全部本系统键',
                    $before,
                    (int)RedisCache::handler()->dbsize()
                ));
                $output->writeln('注意：token 缓存一并清除，所有用户需重新登录。');
            }
            $output->writeln('SUCCESS Redis 服务正常，可以启动 Web');
            return 0;
        }
        $output->writeln('[fail] ' . $report['error']);
        foreach (($report['hints'] ?? []) as $h) {
            $output->writeln('  - ' . $h);
        }
        return 1;
    }

    /**
     * 清空本系统写入的所有键（按全局前缀匹配，不误删同实例的其他部署）
     *
     * @var string[] $keys
     */
    protected static function flushSystemKeys(): void
    {
        $prefix  = (string)config('cache.stores.redis.prefix', 'cert:');
        $cli     = RedisCache::handler();
        $cursor  = '0';
        do {
            $reply = $cli->executeRaw(['SCAN', $cursor, 'MATCH', $prefix . '*', 'COUNT', '300']);
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
}
