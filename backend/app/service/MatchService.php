<?php
namespace app\service;

use think\facade\Db;

/**
 * 智能匹配服务 —— 产品精髓「智能匹配」
 *
 * 产品主张：项目要什么证 → 系统从人才池筛出最优人员 → 一键选用。
 * 本服务用「五维加权评分」实现，规则透明、权重可调、结果可解释（score_detail），
 * 且零外部依赖（纯 PHP），不引入推荐库。
 *
 * 评分模型（满分 100）：
 *   证书匹配 40  精确命中得满分，同类（互斥组内/同类别）得一半
 *   社保匹配 25  按需求的 require_social（0不要求/1必须/2优先）分档
 *   有效期余量 15  ≥365天满分，逐档递减；低于需求下限直接 0
 *   无冲突  15  存在阻断级冲突直接剔除；警告级半分；无冲突满分
 *   政策合规  5  无命中满分；提示级半分；警告/阻断 0 并可能剔除
 */
class MatchService
{
    public const W_CERT = 40.0;
    public const W_SOCIAL = 25.0;
    public const W_EXPIRE = 15.0;
    public const W_NOCONFLICT = 15.0;
    public const W_POLICY = 5.0;
    /** v2.32 证书类型加权（仅加权，不替代 cert_name/核心词匹配） */
    public const W_TYPE_BONUS = 8.0;

    /**
     * 按项目需求推荐人员
     *
     * @param int $reqId project_cert_req.id
     * @param array $opts ['limit'=>20, 'include_conflict'=>false]
     * @return array{items:array,total:int,requirement:array}
     */
    public static function recommend(int $reqId, array $opts = []): array
    {
        $limit   = (int)($opts['limit'] ?? 20);
        $include = !empty($opts['include_conflict']);
        $limit   = $limit > 0 ? min($limit, 200) : 20;

        $req = Db::name('project_cert_req')->where('id', $reqId)->find();
        if (!$req) {
            return ['items' => [], 'total' => 0, 'requirement' => []];
        }
        $projectId = (int)$req['project_id'];

        // 候选集：持有目标证书专业（或同类别）的有效证书
        // 规范化匹配（#235 增强召回）：去空格、全角括号转半角；核心词（去掉括号内说明）做模糊兜底，
        // 解决「一级建造师」与「一级建造师(建筑工程)」等命名不一致导致的匹配不到问题
        $reqName  = trim((string)($req['cert_name'] ?? ''));
        $reqNameN = str_replace([' ', '　', '（', '）'], ['', '', '(', ')'], $reqName);
        $reqCat   = trim((string)($req['cert_category'] ?? ''));
        $reqCatN  = str_replace([' ', '　'], '', $reqCat);
        $core     = trim(preg_replace('/[（(].*?[)）]/u', '', $reqName));
        $hasCat   = $reqCatN !== '';
        $hasCore  = $core !== '' && $core !== $reqName;

        $certQuery = Db::name('certificate')->alias('cert')
            ->where('cert.cert_status', 1)
            ->where(function ($w) use ($reqName, $reqNameN, $reqCat, $reqCatN, $hasCat, $hasCore, $core) {
                // v2.99.79：证书专业可多值（「建筑工程、市政公用工程」），
                // 用「、名称、」包裹后 LIKE 精确命中单个专业，兼容单值与多值。
                if ($reqName !== '') {
                    $w->whereRaw("CONCAT('、', cert.cert_name, '、') LIKE ?", ['%、' . $reqName . '、%']);
                } else {
                    $w->where('cert.cert_name', '');
                }
                if ($reqNameN !== '' && $reqNameN !== $reqName) {
                    $w->whereOrRaw("CONCAT('、', cert.cert_name, '、') LIKE ?", ['%、' . $reqNameN . '、%']);
                }
                if ($hasCat) {
                    $w->whereOr('cert.category', $reqCat)
                      ->whereOr('cert.category', $reqCatN);
                }
                if ($hasCore) {
                    $w->whereOr('cert.cert_name', 'like', '%' . $core . '%');
                }
            })
            ->field('cert.*')
            ->order('cert.expire_date', 'asc');
        // 数据范围（v2.29）：调用方传入 scope 回调约束候选池，与人员列表口径一致
        if (isset($opts['scope']) && is_callable($opts['scope'])) {
            ($opts['scope'])($certQuery);
        }
        $certs = $certQuery->select()->toArray();

        if (!$certs) {
            return ['items' => [], 'total' => 0, 'requirement' => $req];
        }

        // 项目周期（缺失则默认一年）
        $project = Db::name('project')->where('id', $projectId)
            ->field('start_date,end_date,region_name')->find();
        $start = (string)($project['start_date'] ?? '') ?: date('Y-m-d');
        $end   = (string)($project['end_date'] ?? '') ?: date('Y-m-d', strtotime('+1 year'));
        if ($start > $end) { [$start, $end] = [$end, $start]; }

        $items   = [];
        $staffMap = self::staffMap(array_unique(array_map(fn($c) => (int)$c['staff_id'], $certs)));

        foreach ($certs as $cert) {
            $staffId = (int)$cert['staff_id'];
            $staff   = $staffMap[$staffId] ?? null;
            // 离职人员不参与匹配
            if (!$staff || (int)($staff['status'] ?? 0) !== 1) {
                continue;
            }

            [$score, $detail, $blocked, $conflicts] = self::scoreOne($cert, $staff, $req, $start, $end, $projectId);

            if ($blocked && !$include) {
                continue;
            }

            $remain = self::remainDays((string)($cert['expire_date'] ?? ''));
            $user   = $opts['user'] ?? null;
            $items[] = [
                'staff_id'     => $staffId,
                'staff_name'   => MaskService::fieldValue('staff.name', (string)($staff['staff_name'] ?? ''), $user),
                'dept'         => (string)($staff['dept'] ?? ''),
                'phone'        => MaskService::fieldValue('staff.phone', (string)($staff['phone'] ?? ''), $user),
                'cert_id'      => (int)$cert['id'],
                'cert_name'    => (string)$cert['cert_name'],
                'cert_no'      => MaskService::fieldValue('cert.cert_no', (string)($cert['cert_no'] ?? ''), $user),
                'expire_date'  => (string)($cert['expire_date'] ?? ''),
                'remain_days'  => $remain,
                'score'        => round($score, 2),
                'score_detail' => $detail,
                'blocked'      => $blocked,
                'conflicts'    => $conflicts,
            ];
        }

        usort($items, function ($a, $b) {
            return [$b['score'], $b['remain_days'] ?? 0] <=> [$a['score'], $a['remain_days'] ?? 0];
        });

        return [
            'items'       => array_slice($items, 0, $limit),
            'total'       => count($items),
            'requirement' => $req,
            'period'      => ['start' => $start, 'end' => $end, 'region_name' => (string)($project['region_name'] ?? '')],
        ];
    }

    /**
     * 单候选评分
     * @return array [score, detail, blocked, conflicts]
     */
    protected static function scoreOne(array $cert, array $staff, array $req, string $start, string $end, int $projectId): array
    {
        $detail   = [];
        $score    = 0.0;
        $blocked  = false;
        $conflicts = [];

        // ① 证书匹配 40（v2.99.79：多专业证书任一专业与需求相同即视为精确匹配）
        $exact = CertDictService::hasName((string)($cert['cert_name'] ?? ''), (string)($req['cert_name'] ?? ''));
        if ($exact) {
            $score += self::W_CERT;
            $detail['证书专业精确匹配'] = (int)self::W_CERT;
        } else {
            $half = (int)(self::W_CERT / 2);
            $score += $half;
            $detail['证书同类匹配'] = $half;
        }

        // ①.5 证书类型加权项（v2.32）：cert_type 仅作加权，**不删**既有 cert_name/核心词匹配逻辑。
        //   两边 cert_type 均非空且相等 → 加权；任一侧为空 → 退化现状（不加分也不减分），
        //   保证存量 cert_type='' 的需求/证书匹配不受影响，与「证书专业级联」形成软加权。
        $reqType  = trim((string)($req['cert_type'] ?? ''));
        $certType = trim((string)($cert['cert_type'] ?? ''));
        if ($reqType !== '' && $certType !== '' && $reqType === $certType) {
            $score += self::W_TYPE_BONUS;
            $detail['证书类型一致'] = (int)self::W_TYPE_BONUS;
        }

        // ② 社保匹配 25（人档优先，回退证书冗余）
        $social   = PersonProfileService::resolveSocial((int)$cert['staff_id'], [$cert]);
        $require  = (int)($req['require_social'] ?? 0);
        if ($require === 1) {
            if ((int)$social['status'] === 1) {
                $score += self::W_SOCIAL;
                $detail['社保在缴（必须）'] = (int)self::W_SOCIAL;
            } else {
                $detail['社保「' . PersonProfileService::socialText((int)$social['status']) . '」不满足必须条件'] = 0;
            }
        } elseif ($require === 2) {
            $s = (int)$social['status'] === 1 ? (int)self::W_SOCIAL : ((int)$social['status'] === 2 ? 12 : 0);
            $score += $s;
            $detail['社保' . PersonProfileService::socialText((int)$social['status']) . '（优先）'] = $s;
        } else {
            $base = 18;
            $score += $base;
            $detail['社保（无硬性要求）'] = $base;
        }

        // ③ 有效期余量 15
        $remain = self::remainDays((string)($cert['expire_date'] ?? ''));
        $floor  = (int)($req['min_expire_days'] ?? 0);
        if ($floor > 0 && ($remain === null || $remain < $floor)) {
            $detail["有效期低于需求下限({$floor}天)"] = 0;
        } else {
            $e = 15;
            if ($remain === null) {
                $e = 0;
            } elseif ($remain >= 365) { $e = 15; }
            elseif ($remain >= 180)   { $e = 12; }
            elseif ($remain >= 90)    { $e = 8; }
            elseif ($remain >= 30)    { $e = 3; }
            $score += $e;
            $detail['证书剩余' . ($remain === null ? '未知' : $remain . ' 天')] = $e;
        }

        // ④ 冲突检测 15（阻断直接剔除）
        $conflicts = ConflictService::checkStaffInRange((int)$cert['staff_id'], $start, $end, (int)$cert['id']);
        foreach (ConflictService::checkCert((int)$cert['id'], [
            'start_date' => $start, 'end_date' => $end, 'project_id' => $projectId,
        ]) as $cf) {
            $conflicts[] = $cf;
        }
        $maxLevel = 0;
        foreach ($conflicts as $cf) { $maxLevel = max($maxLevel, (int)($cf['level'] ?? 0)); }
        if ($maxLevel >= 2) {
            $blocked = true;
            $detail['存在阻断级冲突'] = 0;
        } elseif ($maxLevel === 1) {
            $score += 8;
            $detail['存在警告级冲突'] = 8;
        } else {
            $score += self::W_NOCONFLICT;
            $detail['该周期无冲突'] = (int)self::W_NOCONFLICT;
        }

        // ⑤ 政策合规 5
        $policyIssues = PolicyRuleService::evaluateCert($cert, $projectId, $social);
        if (!$policyIssues) {
            $score += self::W_POLICY;
            $detail['属地政策合规'] = (int)self::W_POLICY;
        } else {
            $pMax = 0;
            foreach ($policyIssues as $pi) { $pMax = max($pMax, (int)($pi['level'] ?? 0)); }
            if ($pMax === 0) {
                $score += 3;
                $detail['属地政策提示'] = 3;
            } else {
                $detail['属地政策不通过'] = 0;
                if ($pMax >= 2) { $blocked = true; }
                $conflicts = array_merge($conflicts, $policyIssues);
            }
        }

        return [$score, $detail, $blocked, $conflicts];
    }

    /**
     * 解释：某候选为何被剔除（让管理员看到「为什么他不在推荐列表里」）
     */
    public static function why(int $reqId, int $staffId): array
    {
        $req = Db::name('project_cert_req')->where('id', $reqId)->find();
        if (!$req) {
            return [];
        }
        $reqName = trim((string)($req['cert_name'] ?? ''));
        $reqCat  = trim((string)($req['cert_category'] ?? ''));
        $cert = Db::name('certificate')->where('staff_id', $staffId)
            ->where('cert_status', 1)
            ->where(function ($w) use ($reqName, $reqCat) {
                // v2.99.79：多专业证书按单个专业名精确命中
                if ($reqName !== '') {
                    $w->whereRaw("CONCAT('、', cert_name, '、') LIKE ?", ['%、' . $reqName . '、%']);
                }
                if ($reqCat !== '') {
                    if ($reqName !== '') {
                        $w->whereOr('category', $reqCat);
                    } else {
                        $w->where('category', $reqCat);
                    }
                }
                if ($reqName === '' && $reqCat === '') {
                    $w->whereRaw('1=0');
                }
            })
            ->orderRaw('expire_date IS NULL ASC, expire_date ASC')->find();
        if (!$cert) {
            return ['score' => 0, 'detail' => ['无匹配的有效证书' => 0], 'blocked' => true, 'conflicts' => []];
        }
        $staff = Db::name('staff')->where('id', $staffId)->find();
        if (!$staff) {
            return ['score' => 0, 'detail' => ['人员不存在' => 0], 'blocked' => true, 'conflicts' => []];
        }
        $project = Db::name('project')->where('id', $req['project_id'])
            ->field('start_date,end_date')->find();
        $start = (string)($project['start_date'] ?? '') ?: date('Y-m-d');
        $end   = (string)($project['end_date'] ?? '') ?: date('Y-m-d', strtotime('+1 year'));
        if ($start > $end) { [$start, $end] = [$end, $start]; }

        [$score, $detail, $blocked, $conflicts] = self::scoreOne($cert, $staff, $req, $start, $end, (int)$req['project_id']);
        return ['score' => round($score, 2), 'detail' => $detail, 'blocked' => $blocked, 'conflicts' => $conflicts];
    }

    /**
     * 确认选用：事务内写匹配留痕 + 生成借调记录 + 回写需求已匹配数
     * 复用 cert_secondment 现有结构，不新建平行借调流程
     */
    public static function select(array $d): array
    {
        $reqId      = (int)($d['req_id'] ?? 0);
        $certId     = (int)($d['cert_id'] ?? 0);
        $companyId  = (int)($d['company_id'] ?? 0);
        $startDate  = trim((string)($d['start_date'] ?? '')) ?: null;
        $endDate    = trim((string)($d['end_date'] ?? '')) ?: null;
        $createBy   = (int)($d['create_by'] ?? 0);

        $req = Db::name('project_cert_req')->where('id', $reqId)->find();
        if (!$req) {
            return ['ok' => false, 'msg' => '需求不存在'];
        }
        $cert = Db::name('certificate')->where('id', $certId)->find();
        if (!$cert) {
            return ['ok' => false, 'msg' => '证书不存在'];
        }
        // 未指定企业时，取项目所属客户企业
        if (!$companyId) {
            $companyId = (int)(Db::name('project')->where('id', $req['project_id'])->value('client_id') ?? 0);
        }
        if (!$companyId) {
            return ['ok' => false, 'msg' => '请选择借入企业'];
        }
        // 剩余需求人数校验
        $remainCount = max(0, (int)$req['need_count'] - (int)$req['matched_count']);
        if ($remainCount <= 0) {
            return ['ok' => false, 'msg' => '该需求已配满'];
        }
        // 冲突前置校验（v2.29）：阻断级冲突（证书已被占用/互斥/政策）不允许直接选用，
        // 与 Secondment::save 口径一致；确需强制占用请到借调页面操作
        $conflicts = ConflictService::checkCert((int)$cert['id'], [
            'start_date' => (string)$startDate,
            'end_date'   => (string)$endDate,
            'project_id' => (int)$req['project_id'],
        ]);
        $blocked = false;
        foreach ($conflicts as $c) {
            if ((int)($c['level'] ?? 0) >= 2) {
                $blocked = true;
                break;
            }
        }
        if ($blocked) {
            $first = '';
            foreach ($conflicts as $c) {
                if ((int)($c['level'] ?? 0) >= 2) {
                    $first = ConflictService::summaryText($c);
                    break;
                }
            }
            return ['ok' => false, 'msg' => '该证书存在阻断级冲突，不能选用：' . $first];
        }

        $secondmentId = Db::transaction(function () use ($req, $cert, $companyId, $startDate, $endDate, $createBy, $reqId) {
            $sid = Db::name('cert_secondment')->insertGetId([
                'cert_id'     => (int)$cert['id'],
                'staff_id'    => (int)$cert['staff_id'],
                'company_id'  => $companyId,
                'direction'   => 1,
                'start_date'  => $startDate,
                'end_date'    => $endDate,
                'status'      => 1,
                'create_time' => date('Y-m-d H:i:s'),
            ]);

            $why = self::why($reqId, (int)$cert['staff_id']);
            Db::name('match_record')->insert([
                'project_id'    => (int)$req['project_id'],
                'req_id'        => $reqId,
                'staff_id'      => (int)$cert['staff_id'],
                'cert_id'       => (int)$cert['id'],
                'score'         => $why['score'] ?? 0,
                'score_detail'  => json_encode($why['detail'] ?? [], JSON_UNESCAPED_UNICODE),
                'status'        => 1,
                'secondment_id' => $sid,
                'create_by'     => $createBy,
                'create_time'   => date('Y-m-d H:i:s'),
            ]);

            // ★★★ 铁律 C1：此处**禁止** inc('matched_count')。
            // matched_count 与 cert_task_item.done_count 在「转人工配证」场景下
            // 是同一事实的两个视图（两侧各推进 1），用 +1 记账会与「人工填报」路径重复计数。
            // 唯一写入口是 TaskProgressService，它内部按「有无转人工明细」自动分流：
            //   · 有明细 →推进明细 done_count 后 SUM 绝对值重算（与人工填报天然不重复）
            //   · 无明细 → 纯智能匹配场景，允许 +1（无 done_count 事实源，否则需求永远配不满）
            $progress = TaskProgressService::advanceBySmartMatch(
                $reqId,
                '智能匹配选用回写进度：项目需求#' . $reqId
            );
            // 联动配证任务：明细满额时自动置任务完成
            if ($progress['mode'] === 'linked_item') {
                $taskIds = Db::name('cert_task_item')
                    ->where('source_req_id', $reqId)
                    ->distinct(true)
                    ->column('task_id');
                foreach ($taskIds as $tid) {
                    TaskProgressService::maybeCompleteTask((int)$tid);
                }
            }
            return $sid;
        });

        return ['ok' => true, 'secondment_id' => $secondmentId, 'msg' => '已选用并生成借调记录'];
    }

    /**
     * 确认选用（v2.33 改为「先弹窗建借调，再回调记录」）：
     * 前端先在「证书借调」弹窗中人工完善借调期限/企业/费用等，调 secondment/save 生成借调记录，
     * 再携带生成的 secondment_id 回调本方法写匹配留痕 + 回写需求已匹配数。
     * 本方法**不再**一键生成借调，避免跳过人工完善环节（与用户诉求一致）。
     *
     * @param array $d ['req_id','cert_id','secondment_id','create_by']
     */
    public static function record(array $d): array
    {
        $reqId        = (int)($d['req_id'] ?? 0);
        $certId       = (int)($d['cert_id'] ?? 0);
        $secondmentId = (int)($d['secondment_id'] ?? 0);
        $createBy     = (int)($d['create_by'] ?? 0);

        $req = Db::name('project_cert_req')->where('id', $reqId)->find();
        if (!$req) {
            return ['ok' => false, 'msg' => '需求不存在'];
        }
        $cert = Db::name('certificate')->where('id', $certId)->find();
        if (!$cert) {
            return ['ok' => false, 'msg' => '证书不存在'];
        }
        if (!$secondmentId) {
            return ['ok' => false, 'msg' => '借调记录未生成，无法记录选用'];
        }
        $sec = Db::name('cert_secondment')->where('id', $secondmentId)->find();
        if (!$sec || (int)$sec['cert_id'] !== $certId) {
            return ['ok' => false, 'msg' => '借调记录与所选证书不匹配'];
        }
        // 剩余需求人数校验
        $remainCount = max(0, (int)$req['need_count'] - (int)$req['matched_count']);
        if ($remainCount <= 0) {
            return ['ok' => false, 'msg' => '该需求已配满'];
        }
        // 重复选用防护：同一需求+证书已有已选用记录则拒绝
        $dup = Db::name('match_record')
            ->where('req_id', $reqId)
            ->where('cert_id', $certId)
            ->where('status', 1)
            ->find();
        if ($dup) {
            return ['ok' => false, 'msg' => '该证书已在该需求下选用，请勿重复'];
        }

        $why = self::why($reqId, (int)$cert['staff_id']);
        Db::transaction(function () use ($req, $cert, $secondmentId, $createBy, $reqId, $why) {
            Db::name('match_record')->insert([
                'project_id'    => (int)$req['project_id'],
                'req_id'        => $reqId,
                'staff_id'      => (int)$cert['staff_id'],
                'cert_id'       => (int)$cert['id'],
                'score'         => $why['score'] ?? 0,
                'score_detail'  => json_encode($why['detail'] ?? [], JSON_UNESCAPED_UNICODE),
                'status'        => 1,
                'secondment_id' => $secondmentId,
                'create_by'     => $createBy,
                'create_time'   => date('Y-m-d H:i:s'),
            ]);

            // ★★★ 铁律 C1：此处**禁止** inc('matched_count')，
            // 唯一写入口是 TaskProgressService（按「有无转人工明细」自动分流）。
            $progress = TaskProgressService::advanceBySmartMatch(
                $reqId,
                '智能匹配选用回写进度：项目需求#' . $reqId
            );
            // 联动配证任务：明细满额时自动置任务完成
            if ($progress['mode'] === 'linked_item') {
                $taskIds = Db::name('cert_task_item')
                    ->where('source_req_id', $reqId)
                    ->distinct(true)
                    ->column('task_id');
                foreach ($taskIds as $tid) {
                    TaskProgressService::maybeCompleteTask((int)$tid);
                }
            }
        });

        return ['ok' => true, 'secondment_id' => $secondmentId, 'msg' => '已记录选用并关联借调'];
    }

    /** 放弃某候选（留痕，便于分析需求为何长期无法满足） */
    public static function giveUp(int $reqId, int $staffId, int $certId, int $userId = 0, string $reason = ''): array
    {
        Db::name('match_record')->insert([
            'project_id'    => (int)(Db::name('project_cert_req')->where('id', $reqId)->value('project_id') ?? 0),
            'req_id'        => $reqId,
            'staff_id'      => $staffId,
            'cert_id'       => $certId,
            'score'         => 0,
            'score_detail'  => json_encode(['放弃原因' => $reason], JSON_UNESCAPED_UNICODE),
            'status'        => 2,
            'create_by'     => $userId,
            'create_time'   => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => true, 'msg' => '已记录放弃'];
    }

    /**
     * 匹配历史（某项目/某需求的选用与放弃记录）
     */
    public static function history(int $projectId = 0, int $reqId = 0, int $limit = 100, $user = null): array
    {
        $q = Db::name('match_record')->alias('mr')
            ->leftJoin('staff s', 's.id = mr.staff_id')
            ->leftJoin('certificate c', 'c.id = mr.cert_id')
            ->leftJoin('project p', 'p.id = mr.project_id')
            ->field('mr.*, s.staff_name, c.cert_name, c.cert_no, p.project_name')
            ->order('mr.id', 'desc')->limit(max(1, min($limit, 500)));
        if ($projectId) { $q->where('mr.project_id', $projectId); }
        if ($reqId)     { $q->where('mr.req_id', $reqId); }

        $list = $q->select()->toArray();
        foreach ($list as &$mr) {
            $mr['cert_no'] = MaskService::fieldValue('cert.cert_no', (string)($mr['cert_no'] ?? ''), $user);
            $mr['staff_name'] = MaskService::fieldValue('staff.name', (string)($mr['staff_name'] ?? ''), $user);
            $d = $mr['score_detail'] ?? '';
            $mr['score_detail'] = is_string($d) ? (json_decode($d, true) ?: []) : (array)$d;
            $mr['status_text']  = [0 => '待确认', 1 => '已选用', 2 => '已放弃'][(int)$mr['status']] ?? '未知';
        }
        unset($mr);
        return $list;
    }

    /** 批量人员信息（避免 N+1） */
    protected static function staffMap(array $staffIds): array
    {
        $staffIds = array_values(array_filter(array_map('intval', $staffIds)));
        if (!$staffIds) {
            return [];
        }
        $rows = Db::name('staff')->whereIn('id', $staffIds)
            ->field('id,staff_name,dept,phone,status')->select()->toArray();
        $map = [];
        foreach ($rows as $r) { $map[(int)$r['id']] = $r; }
        return $map;
    }

    protected static function remainDays(string $expireDate): ?int
    {
        if ($expireDate === '') {
            return null;
        }
        $t = strtotime($expireDate);
        if ($t === false) {
            return null;
        }
        return (int)ceil(($t - strtotime(date('Y-m-d'))) / 86400);
    }
}