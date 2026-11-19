<?php
namespace app\service;

use think\facade\Db;

/**
 * 配证进度同步 —— 唯一事实源：cert_task_item.done_count
 *
 * ★★★ 铁律C1：project_cert_req.matched_count 禁止任何 inc()/+1 写入。
 *     matched 与 done 是同一事实的两个视图，必须用**绝对值重算**派生，
 *     否则「智能匹配选用 +1」与「人工填报」两条路径会重复计数
 *     （先智能选用1人 + 人工填2 = 进度 3/3，实际应为 2/3）。
 *
 * 唯一口径：matched_count = MIN( SUM(该需求关联明细的 done_count), need_count )
 *
 * ★ 约定 C2：本类内部**绝不开启新事务**，事务边界由调用方决定
 *   （MatchService::select() 的事务闭包必须与借调记录同生共死）。
 *
 * 本类所有方法均为**幂等**：任何时候重复执行结果一致，可重放。
 */
class TaskProgressService
{
    /**
     * 按明细新值回写来源需求的 matched_count（幂等，可重复调用）
     *
     * @param int    $itemIdcert_task_item.id
     * @param int    $newDone    新的 done_count（调用方应已 clamp 到 [0, need_count]）
     * @param string $logContent 进度日志内容（由调用方生成，带业务语义）
     * @return array{req_id:int,old_matched:int,new_matched:int,delta:int,changed:bool,need_count:int,sum_done:int}|null
     *         返回 null 表示该明细无来源需求（手工建单）或来源需求已不存在
     */
    public static function syncReqProgress(int $itemId, int $newDone, string $logContent = ''): ?array
    {
        $item = Db::name('cert_task_item')->where('id', $itemId)->find();
        if (!$item) {
            return null;
        }
        $reqId = (int)($item['source_req_id'] ?? 0);
        if ($reqId <= 0) {
            // 手工建单明细：done_count 由调用方直接写，不联动项目需求
            return null;
        }
        $req = Db::name('project_cert_req')->where('id', $reqId)->find();
        if (!$req) {
            return null;
        }

        // ★ 绝对值重算：用 SUM 而非「当前明细 done + Δ」
        //   同一需求理论上可能有多条明细（历史数据 / 未来 P1-4 合并策略），
        //   SUM 口径任何时候执行都正确，不依赖调用顺序。
        $sumDone = self::sumDone($reqId);
        $need= (int)($req['need_count'] ?? 0);
        $oldMatched = (int)($req['matched_count'] ?? 0);
        $newMatched = max(0, min($sumDone, $need)); // clamp：防「配了5人但需求只要2人」显示 5/2
        $delta = $newMatched - $oldMatched;

        if ($delta !== 0) {
            Db::name('project_cert_req')->where('id', $reqId)->update([
                'matched_count' => $newMatched,
            ]);
        }

        return [
            'req_id'      => $reqId,
            'need_count'  => $need,
            'sum_done'    => $sumDone,
            'old_matched' => $oldMatched,
            'new_matched' => $newMatched,
            'delta'       => $delta,
            'changed'     => $delta !== 0,
            'log_content' => $logContent,
        ];
    }

    /**
     * 批量重算指定需求的matched_count（绝对值重算）
     *
     * @param array<int,int|string> $reqIds project_cert_req.id 列表
     * @return int 实际发生变更的需求数
     */
    public static function recalcByReq(array $reqIds): int
    {
        $reqIds = array_values(array_unique(array_filter(array_map('intval', $reqIds))));
        if (!$reqIds) {
            return 0;
        }
        $changed = 0;
        foreach ($reqIds as $reqId) {
            if ($reqId <= 0) {
                continue;
            }
            $req = Db::name('project_cert_req')->where('id', $reqId)->find();
            if (!$req) {
                continue;
            }
            $sumDone     = self::sumDone($reqId);
            $need        = (int)($req['need_count'] ?? 0);
            $oldMatched  = (int)($req['matched_count'] ?? 0);
            $newMatched  = max(0, min($sumDone, $need));
            if ($newMatched !== $oldMatched) {
                Db::name('project_cert_req')->where('id', $reqId)->update([
                    'matched_count' => $newMatched,
                ]);
                $changed++;
            }
        }
        return $changed;
    }

    /**
     * 全量重算所有「有转人工明细」的需求（修复/补偿入口）
     *
     * @return array{scanned:int,changed:int,details:array}
     */
    public static function recalcAll(): array
    {
        $reqIds = Db::name('cert_task_item')
            ->where('source_req_id', '>', 0)
            ->distinct(true)
            ->column('source_req_id');
        $details = [];
        $scanned = 0;
        $changed = 0;
        foreach ((array)$reqIds as $reqId) {
            $reqId = (int)$reqId;
            if ($reqId <= 0) {
                continue;
            }
            $scanned++;
            $req = Db::name('project_cert_req')->where('id', $reqId)->find();
            if (!$req) {
                continue;
            }
            $sumDone    = self::sumDone($reqId);
            $need       = (int)($req['need_count'] ?? 0);
            $oldMatched = (int)($req['matched_count'] ?? 0);
            $newMatched = max(0, min($sumDone, $need));
            if ($newMatched !== $oldMatched) {
                Db::name('project_cert_req')->where('id', $reqId)->update([
                    'matched_count' => $newMatched,
                ]);
                $changed++;
                $details[] = [
                    'req_id'      => $reqId,
                    'old_matched' => $oldMatched,
                    'new_matched' => $newMatched,
                    'sum_done'    => $sumDone,
                    'need_count'  => $need,
                ];
            }
        }
        return ['scanned' => $scanned, 'changed' => $changed, 'details' => $details];
    }

    /**
     * C9 历史补偿：v2.30 期间人工填的 done_count 当时没回写 matched_count。
     *
     * 口径：**matched_count = MAX(matched_count, 该明细 done_count)**（保守不虚增）。
     *
     * ⚠️ 与 recalcAll() 的关系：
     *   - recalcAll() 是**权威口径**（SUM 绝对值重算），能正确处理一需求多明细；
     *   - compensateHistory() 用 MAX 是「不敢确定明细归属时的保守兜底」，
     *     只保证「用户明明填了N 个已配，页面至少显示 N」，绝不虚增。
     *   - 因此 compensateHistory() 必须**幂等**：第二次执行时 matched 已 >= MAX，
     *     delta = 0，不写库、不重复抬升。
     *
     * @return array{scanned:int,fixed:int,details:array}
     */
    public static function compensateHistory(): array
    {
        // 只处理「来源需求明确且该需求仅关联一条明细」的记录（归属清晰，可信）
        // ⚠️ 不用 join：TP6.1 的 Db::name()->leftJoin 别名会被拼成 xxx_ti，故此处无需 join
        $rows = Db::name('cert_task_item')
            ->field('id,task_id,source_req_id,cert_name,need_count,done_count')
            ->where('source_req_id', '>', 0)
            ->where('done_count', '>', 0)
            ->select()
            ->toArray();

        $grouped = [];
        foreach ($rows as $r) {
            $reqId = (int)$r['source_req_id'];
            $grouped[$reqId][] = $r;
        }

        $scanned = 0;
        $fixed   = 0;
        $details = [];
        foreach ($grouped as $reqId => $items) {
            $req = Db::name('project_cert_req')->where('id', $reqId)->find();
            if (!$req) {
                continue;
            }
            $scanned++;
            $need       = (int)($req['need_count'] ?? 0);
            $oldMatched = (int)($req['matched_count'] ?? 0);
            $sumDone    = array_sum(array_map(static fn($x) => (int)$x['done_count'], $items));
            $maxDone    = max(array_map(static fn($x) => (int)$x['done_count'], $items));

            if (count($items) === 1) {
                // 单明细 → 归属清晰，用 SUM 绝对值口径（更准）
                $newMatched = max(0, min($sumDone, $need));
                $mode       = 'sum';
            } else {
                // 多明细 → 归属存疑，用 MAX 保守兜底（绝不虚增）
                $newMatched = max($oldMatched, min($maxDone, $need));
                $mode       = 'max_conservative';
            }

            if ($newMatched > $oldMatched) {
                Db::name('project_cert_req')->where('id', $reqId)->update([
                    'matched_count' => $newMatched,
                ]);
                $fixed++;
                $details[] = [
                    'req_id'      => $reqId,
                    'mode'        => $mode,
                    'item_count'  => count($items),
                    'old_matched' => $oldMatched,
                    'new_matched' => $newMatched,
                    'need_count'  => $need,
                    'sum_done'    => $sumDone,
                ];
            }
        }
        return ['scanned' => $scanned, 'fixed' => $fixed, 'details' => $details];
    }

    /**
     * 智能匹配选用时推进需求进度（唯一 +1 场景的口径）
     *
     * ★ 为什么这里允许 +1，而 syncReqProgress() 禁止：
     *   - **转人工**的需求（存在 cert_task_item）→ matched 由 done_count SUM 绝对值派生，
     *     再 +1 会与人工填报重复计数，故走 syncReqProgress()。
     *   - **纯智能匹配**的需求（无任何 cert_task_item）→ 没有 done_count 这个事实源，
     *     若不推进则「需求永远配不满」，智能匹配中心会失效。
     *   因此本方法**先判定有无转人工明细**：有则转交 syncReqProgress 绝对值口径，
     * 无则才做一次受 need_count 约束的 +1（并 clamp，仍是绝对值赋值而非裸 inc）。
     *
     * @return array{req_id:int,mode:string,old_matched:int,new_matched:int,delta:int}
     */
    public static function advanceBySmartMatch(int $reqId, string $logContent = ''): array
    {
        $req = Db::name('project_cert_req')->where('id', $reqId)->find();
        if (!$req) {
            return ['req_id' => $reqId, 'mode' => 'none', 'old_matched' => 0, 'new_matched' => 0, 'delta' => 0];
        }
        // 有转人工明细 → 走绝对值口径（明细里已同步 +1，此处不再叠加）
        $hasLinked = (int)Db::name('cert_task_item')
            ->where('source_req_id', $reqId)
            ->count() > 0;
        if ($hasLinked) {
            $linked = Db::name('cert_task_item')
                ->where('source_req_id', $reqId)
                ->whereColumn('done_count', '<', 'need_count')
                ->order('id', 'asc')
                ->find();
            if ($linked) {
                $newDone = (int)$linked['done_count'] + 1;
                Db::name('cert_task_item')->where('id', (int)$linked['id'])->update(['done_count' => $newDone]);
                $r = self::syncReqProgress((int)$linked['id'], $newDone, $logContent);
                return [
                    'req_id'      => $reqId,
                    'mode'        => 'linked_item',
                    'old_matched' => (int)($r['old_matched'] ?? 0),
                    'new_matched' => (int)($r['new_matched'] ?? 0),
                    'delta'       => (int)($r['delta'] ?? 0),
                ];
            }
            // 明细已全部满额 → 仍以 SUM 纠偏
            self::recalcByReq([$reqId]);
            $cur = (int)Db::name('project_cert_req')->where('id', $reqId)->value('matched_count');
            return ['req_id' => $reqId, 'mode' => 'recalc', 'old_matched' => $cur, 'new_matched' => $cur, 'delta' => 0];
        }

        // 无转人工明细 → 纯智能匹配路径，允许推进 1（clamp 到 need_count）
        $need       = (int)($req['need_count'] ?? 0);
        $oldMatched = (int)($req['matched_count'] ?? 0);
        $newMatched = min($oldMatched + 1, $need);
        if ($newMatched !== $oldMatched) {
            Db::name('project_cert_req')->where('id', $reqId)->update(['matched_count' => $newMatched]);
        }
        return [
            'req_id'      => $reqId,
            'mode'        => 'smart_only',
            'old_matched' => $oldMatched,
            'new_matched' => $newMatched,
            'delta'       => $newMatched - $oldMatched,
        ];
    }

    /**
     * 任务全部明细满额 → status=2（已完成）+ 写任务级日志
     * 合并原 CertTask::maybeComplete() 与 MatchService::syncTaskProgress() 两份重复实现。
     *
     * @return bool 本次调用是否把任务置为已完成
     */
    public static function maybeCompleteTask(int $taskId): bool
    {
        if ($taskId <= 0) {
            return false;
        }
        $task = Db::name('cert_task')->where('id', $taskId)->find();
        if (!$task) {
            return false;
        }
        // 已完成/已关闭的任务不回退状态
        if ((int)$task['status'] === 2 || (int)$task['status'] === 3) {
            return false;
        }
        $stat = Db::name('cert_task_item')
            ->where('task_id', $taskId)
            ->field('COUNT(*) AS cnt, COALESCE(SUM(need_count),0) AS need_sum, COALESCE(SUM(done_count),0) AS done_sum')
            ->find();
        $cnt = (int)($stat['cnt'] ?? 0);
        if ($cnt === 0) {
            return false; // 空任务不判定完成
        }
        $needSum  = (int)$stat['need_sum'];
        $doneSum  = (int)$stat['done_sum'];
        if ($doneSum < $needSum) {
            return false;
        }

        Db::name('cert_task')->where('id', $taskId)->update(['status' => 2]);
        try {
            Db::name('cert_task_log')->insert([
                'task_id'     => $taskId,
                'item_id'     => 0,
                'user_id'     => 0,
                'user_name'   => '系统',
                'content'     => "任务已配齐（{$doneSum}/{$needSum}），自动置为已完成",
                'create_time' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // 日志表缺失不阻断
        }
        return true;
    }

    /**
     * 该需求关联明细的 done_count 合计（唯一事实源聚合）
     */
    public static function sumDone(int $reqId): int
    {
        if ($reqId <= 0) {
            return 0;
        }
        return (int)Db::name('cert_task_item')
            ->where('source_req_id', $reqId)
            ->sum('done_count');
    }

    /**
     * 一次性维护入口：结构自愈 + C9 历史补偿（幂等）
     *
     * 由需要「升级后立刻修数据」的场景调用（如 T07 联调、运营手工触发）。
     * compensateHistory 本身幂等，重复执行不会二次虚增。
     *
     * @return array{structure:array,compensate:array}
     */
    public static function ensureAndCompensate(): array
    {
        // ① 结构自愈（与 ensure_column 协同）
        ensure_column('project_cert_req', 'cert_type', "varchar(64) NOT NULL DEFAULT '' COMMENT '证书类型(dict: cert_major 一级)' AFTER `cert_category`");
        ensure_column('cert_task_item', 'cert_type', "varchar(64) NOT NULL DEFAULT '' COMMENT '证书类型(dict: cert_major 一级)' AFTER `category`");
        ensure_column('cert_task_item', 'source_req_id', "int unsigned NOT NULL DEFAULT 0 COMMENT '来源项目需求ID project_cert_req.id（转人工配证时写入）' AFTER `remark`");
        ensure_index('cert_task_item', 'idx_source', '(`task_id`,`source_req_id`)');
        DictCatalog::ensure();

        // ② C9 历史补偿（幂等）
        return [
            'structure'   => ['ok' => true],
            'compensate'  => self::compensateHistory(),
        ];
    }
}