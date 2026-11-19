<?php
namespace app\service;

use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;

/**
 * 存量身份证号加密回填（v2.99.60）
 *
 * 背景：v2.99.59 起身份证号以 SM4 密文落库 + 盲索引查重，但**存量明文行**需要回填。
 * 原先只有手工命令 `php think idcard:encrypt`，生产升级后没人执行 → 库里仍是明文，
 * 表现为「数据库内现有的身份证号未被处理」。本服务把回填逻辑抽出来，三条路径共用：
 *
 *   1) 在线升级完成后自动执行一次（UpgradeService::apply，限时，失败不影响升级结果）；
 *   2) 运行时自愈：IdCardSchemaService::ensure() 触发 maybeAutoRun()，按 10 分钟节流小批量推进，
 *      即便升级时被限时截断，系统也会在后续访问中自动补齐（无需人工干预）；
 *   3) 手工命令 `php think idcard:encrypt`（支持 --dry-run / --table / --batch / --from-id）。
 *
 * 幂等与并发安全：
 *   - 已是 sm4$1$ 密文的行跳过，只补/修 hash；
 *   - 空值把 hash 置 NULL（唯一索引允许多个 NULL）；
 *   - 加密写回时 WHERE id = ? AND id_card = 原值，防并发覆盖（被改过则跳过，下次再处理）。
 */
class IdCardBackfillService
{
    /** 自动回填节流窗口（秒） */
    public const AUTO_INTERVAL = 600;
    /** 自动回填每次每表行数 */
    public const AUTO_BATCH = 200;
    /** 自动回填时间预算（秒），避免拖慢前台请求 */
    public const AUTO_TIME_LIMIT = 5;

    private const CACHE_KEY = 'idcard_backfill_auto_at';

    /** 是否正在执行显式回填（避免 ensure() 里的自动回填重入） */
    private static bool $running = false;

    /**
     * 执行回填
     *
     * @param array $options 支持：
     *   - tables:    array   指定表，默认三张表
     *   - batch:     int     每批行数，默认 500
     *   - fromId:    int     从主键之后开始（断点续跑），默认 0
     *   - dryRun:    bool    只统计不写库
     *   - timeLimit: int     时间预算（秒），0 表示不限
     *   - logger:    callable(string $line, string $level): void  输出回调
     * @return array{tables: array<string, array>, total: array<string, int>, truncated: bool, elapsed: float}
     */
    public static function run(array $options = []): array
    {
        $tables    = $options['tables'] ?? array_keys(IdCardSchemaService::TABLES);
        $batch     = max(50, (int)($options['batch'] ?? 500));
        $fromId    = max(0, (int)($options['fromId'] ?? 0));
        $dryRun    = (bool)($options['dryRun'] ?? false);
        $timeLimit = max(0, (int)($options['timeLimit'] ?? 0));
        $logger    = $options['logger'] ?? null;

        $started = microtime(true);
        $deadline = $timeLimit > 0 ? $started + $timeLimit : 0.0;

        // 干跑不改结构（尤其不删旧索引）
        if ($dryRun) {
            IdCardSchemaService::$allowLegacyIndexDrop = false;
        }

        // 防重入：ensure() 内会调用 maybeAutoRun()，显式回填期间必须跳过
        $prevRunning = self::$running;
        self::$running = true;
        try {
            IdCardSchemaService::ensure();
            return self::doRun($tables, $batch, $fromId, $dryRun, $deadline, $logger, $started);
        } finally {
            self::$running = $prevRunning;
        }
    }

    /**
     * 实际回填流程（由 run() 包裹防重入与结构自愈）
     *
     * @return array{tables: array<string, array>, total: array<string, int>, truncated: bool, elapsed: float}
     */
    private static function doRun(array $tables, int $batch, int $fromId, bool $dryRun, float $deadline, ?callable $logger, float $started): array
    {
        $truncated = false;

        $total = ['scan' => 0, 'encrypted' => 0, 'hash' => 0, 'cleared' => 0, 'skip' => 0, 'fail' => 0];
        $result = [];

        foreach ($tables as $table) {
            if (!IdCardSchemaService::tableExists($table)) {
                self::emit($logger, "[{$table}] 表不存在，跳过");
                continue;
            }
            self::emit($logger, "[{$table}] 开始处理（batch={$batch}, from-id={$fromId}）");
            $stat = self::processTable($table, $batch, $fromId, $dryRun, $deadline, $logger);
            $result[$table] = $stat;
            foreach ($stat as $k => $v) {
                if (isset($total[$k])) {
                    $total[$k] += $v;
                }
            }
            if ($deadline > 0 && microtime(true) >= $deadline) {
                $truncated = true;
                self::emit($logger, '已达时间预算上限，剩余行将在后续自动回填中继续处理');
                break;
            }
        }

        if (!$dryRun) {
            foreach (array_keys($result) as $table) {
                if (IdCardSchemaService::hasPlaintext($table)) {
                    self::emit($logger, "[{$table}] 仍有明文行（可能被并发写入），本次不删除旧索引，请再次执行");
                } elseif (IdCardSchemaService::dropLegacyIndexWhenBackfilled($table)) {
                    $legacy = IdCardSchemaService::LEGACY_INDEX[$table] ?? '';
                    if ($legacy !== '') {
                        self::emit($logger, "[{$table}] 已确认无明文行，旧索引 {$legacy} 已清理");
                    }
                }
            }
        }

        return [
            'tables'    => $result,
            'total'     => $total,
            'truncated' => $truncated,
            'elapsed'   => round(microtime(true) - $started, 2),
        ];
    }

    /** 统计某表明文行数（空值不算） */
    public static function pendingCount(string $table): int
    {
        if (!IdCardSchemaService::tableExists($table)) {
            return 0;
        }
        try {
            return (int)Db::name($table)
                ->where(IdCardService::FIELD, '<>', '')
                ->where(IdCardService::FIELD, 'not like', IdCardService::PREFIX . '%')
                ->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** 统计三表明文行数 */
    public static function pendingSummary(): array
    {
        $out = [];
        foreach (array_keys(IdCardSchemaService::TABLES) as $table) {
            $n = self::pendingCount($table);
            if ($n > 0) {
                $out[$table] = $n;
            }
        }
        return $out;
    }

    /**
     * 运行时自愈入口：节流执行一小批回填。
     *
     * 只在确实存在明文行时写库；节流键先写后执行，避免并发请求同时回填。
     * 任何异常都只记日志，绝不阻断业务请求。
     */
    public static function maybeAutoRun(): void
    {
        try {
            // 显式回填（升级流程 / CLI / 手工触发）期间不做自动回填
            if (self::$running) {
                return;
            }
            if (Cache::get(self::CACHE_KEY)) {
                return;
            }
            // 先占位再执行：多进程并发时只有一个进程真正回填
            Cache::set(self::CACHE_KEY, time(), self::AUTO_INTERVAL);

            if (!self::pendingSummary()) {
                return;
            }
            $r = self::run([
                'batch'     => self::AUTO_BATCH,
                'timeLimit' => self::AUTO_TIME_LIMIT,
            ]);
            $t = $r['total'];
            if ($t['encrypted'] > 0 || $t['hash'] > 0 || $t['cleared'] > 0) {
                Log::info('[IdCardBackfill] 自动回填：加密 ' . $t['encrypted'] . ' 行，补 hash '
                    . $t['hash'] . ' 行，清空 hash ' . $t['cleared'] . ' 行，用时 ' . $r['elapsed'] . 's');
            }
        } catch (\Throwable $e) {
            Log::warning('[IdCardBackfill] 自动回填失败：' . $e->getMessage());
        }
    }

    /**
     * 按主键区间分批处理单表
     *
     * @param float $deadline 绝对时间戳，0 表示不限
     */
    private static function processTable(string $table, int $batch, int $fromId, bool $dryRun, float $deadline, ?callable $logger): array
    {
        $stat = ['scan' => 0, 'encrypted' => 0, 'hash' => 0, 'cleared' => 0, 'skip' => 0, 'fail' => 0];
        $lastId = $fromId;

        while (true) {
            if ($deadline > 0 && microtime(true) >= $deadline) {
                break;
            }
            try {
                $rows = Db::name($table)
                    ->field('id,' . IdCardService::FIELD . ',' . IdCardService::HASH_FIELD)
                    ->where('id', '>', $lastId)
                    ->order('id', 'asc')
                    ->limit($batch)
                    ->select()->toArray();
            } catch (\Throwable $e) {
                self::emit($logger, "[{$table}] 读取失败：{$e->getMessage()}", 'error');
                $stat['fail']++;
                break;
            }
            if (!$rows) {
                break;
            }

            foreach ($rows as $row) {
                $stat['scan']++;
                $lastId = (int)$row['id'];
                $stored = (string)($row[IdCardService::FIELD] ?? '');
                $oldHash = $row[IdCardService::HASH_FIELD] ?? null;

                if (trim($stored) === '') {
                    // 空值：hash 必须是 NULL（唯一索引允许多个 NULL）
                    if ($oldHash !== null) {
                        $stat['cleared']++;
                        if (!$dryRun) {
                            Db::name($table)->where('id', $lastId)
                                ->update([IdCardService::HASH_FIELD => null]);
                        }
                    } else {
                        $stat['skip']++;
                    }
                    continue;
                }

                $plain = IdCardService::isEncrypted($stored)
                    ? IdCardService::decode($stored)
                    : IdCardService::normalize($stored);
                if ($plain === '' && IdCardService::isEncrypted($stored)) {
                    // 解不开的密文（密钥变更/数据损坏）：保持原样，交人工处理
                    $stat['fail']++;
                    self::emit($logger, "[{$table}] #{$lastId} 密文无法解密，已跳过（请确认 ID_CARD_SM4_KEY 是否变更）", 'error');
                    continue;
                }
                $newHash = IdCardService::hashOrNull($plain);

                $newStored = $stored;
                $needEncrypt = !IdCardService::isEncrypted($stored);
                if ($needEncrypt) {
                    $newStored = IdCardService::encode($plain);
                }
                $needHash = (string)($oldHash ?? '') !== (string)($newHash ?? '');

                if (!$needEncrypt && !$needHash) {
                    $stat['skip']++;
                    continue;
                }
                if ($needEncrypt) {
                    $stat['encrypted']++;
                } elseif ($needHash) {
                    $stat['hash']++;
                }

                if ($dryRun) {
                    continue;
                }

                try {
                    if ($needEncrypt) {
                        // 乐观校验：WHERE id + 原值，防并发覆盖
                        $affected = Db::name($table)
                            ->where('id', $lastId)
                            ->where(IdCardService::FIELD, $stored)
                            ->update([
                                IdCardService::FIELD      => $newStored,
                                IdCardService::HASH_FIELD => $newHash,
                            ]);
                        if (!$affected) {
                            // 行被并发修改，未覆盖；下一轮（或再次执行）会重试
                            $stat['skip']++;
                        }
                    } else {
                        Db::name($table)->where('id', $lastId)
                            ->update([IdCardService::HASH_FIELD => $newHash]);
                    }
                } catch (\Throwable $e) {
                    $stat['fail']++;
                    self::emit($logger, "[{$table}] #{$lastId} 写入失败：{$e->getMessage()}", 'error');
                }
            }

            if (count($rows) < $batch) {
                break;
            }
        }

        self::emit($logger, "[{$table}] 扫描 {$stat['scan']}，加密 {$stat['encrypted']}，补 hash {$stat['hash']}，"
            . "清空 hash {$stat['cleared']}，跳过 {$stat['skip']}，失败 {$stat['fail']}");
        return $stat;
    }

    private static function emit(?callable $logger, string $line, string $level = 'info'): void
    {
        if ($logger) {
            $logger($line, $level);
        }
    }
}
