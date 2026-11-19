<?php
namespace app\controller\admin;

/**
 * 系统缓存管理
 *
 * v2.38 起本系统强依赖 Redis（config/cache.php default=redis），
 * 登录 token、验证码、部门树、自定义字段、字典级联、菜单树、工作台统计、
 * 导入人员/企业快照全部落在 Redis 中。
 *
 * 因此「清除缓存」必须同时处理：
 *   1. Redis：按本系统前缀（默认 cert:）扫描并删除，避免误删同实例的其他部署；
 *   2. file：runtime/cache/ 目录（历史遗留 + 诊断对照用），清理数量一并反馈。
 *
 * 注意：登录 token 也在其中，清空后所有在线用户需重新登录，
 * 前端在调用前必须向用户明确提示这一点。
 */
class Cache extends AdminController
{
    public function clear()
    {
        if (!$this->isAdmin()) {
            return json(r_error('仅管理员可清除缓存', 403));
        }

        // ---- 1. Redis（本系统前缀下的全部键）----
        $redisKeys = 0;
        $redisErr  = '';
        try {
            $cli    = \app\service\RedisCache::handler();
            $prefix = (string)config('cache.stores.redis.prefix', 'cert:');
            $cursor = '0';
            do {
                // predis 2.x 的 scan 不接受 options 数组（会被当成 pattern），
                // 故用 executeRaw 拼命令，返回 [cursor, keys]
                $reply = $cli->executeRaw(['SCAN', $cursor, 'MATCH', $prefix . '*', 'COUNT', '300']);
                if (!is_array($reply) || count($reply) < 2) {
                    break;
                }
                $cursor = (string)$reply[0];
                $keys   = is_array($reply[1]) ? $reply[1] : [$reply[1]];
                if ($keys) {
                    $cli->del($keys);
                    $redisKeys += count($keys);
                }
            } while ($cursor !== '0');
        } catch (\Throwable $e) {
            $redisErr = $e->getMessage();
        }

        // ---- 2. file 驱动目录（历史遗留 / 诊断对照）----
        $path  = app()->getRuntimePath() . 'cache' . DIRECTORY_SEPARATOR;
        $files = 0;
        if (is_dir($path)) {
            $files = $this->clearDir($path);
        }
        // 不调用 CacheFacade::clear()：其 Redis 实现走 FLUSHDB，
        // 会连带清掉同实例下其他部署的键，不符合"只清本系统"的预期。

        $this->log('系统', '清除缓存', "Redis {$redisKeys} 键 / 文件 {$files} 个");

        if ($redisErr !== '') {
            return json(r_error('Redis 清理失败：' . $redisErr . '；文件缓存已清理', 500));
        }

        return json(r_success([
            'redis_keys' => $redisKeys,
            'files'      => $files,
            // 兼容旧前端字段
            'cleared'    => $redisKeys,
        ], '缓存已清除，所有用户需重新登录'));
    }

    /** 递归删除目录内文件（保留目录本身），返回删除文件数 */
    private function clearDir(string $dir): int
    {
        $n = 0;
        $items = @scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $full = $dir . $item;
            if (is_dir($full)) {
                $n += $this->clearDir($full);
                @rmdir($full);
            } elseif (@unlink($full)) {
                $n++;
            }
        }
        return $n;
    }
}