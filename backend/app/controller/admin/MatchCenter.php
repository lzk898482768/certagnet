<?php
namespace app\controller\admin;

use app\service\MatchService;
use app\service\PersonProfileService;
use app\service\TaskProgressService;
use think\facade\Db;

/**
 * 智能匹配工作台 —— 产品精髓「智能匹配」
 *
 * 工作流：左侧选项目 → 选需求项（要什么证、要几人）
 *          右侧一键推荐 → 查看候选人员得分明细与冲突 → 一键选用生成借调
 *
 * 注意：类名不能叫 Match —— PHP 8 起 match 是保留字（match 表达式），
 *       用作类名会直接 parse error（"unexpected token match"）。
 */
class MatchCenter extends AdminController
{
    /**
     * 匹配工作台首页：项目列表 + 各需求匹配进度
     */
    public function index()
    {
        [$page, $limit] = $this->pageParams();
        PersonProfileService::ensureTables();

        $projectId = (int)$this->request->param('project_id', 0);

        // 有项目时返回该项目下的需求明细；否则返回待匹配的需求清单
        if ($projectId) {
            $project = Db::name('project')->where('id', $projectId)->find();
            if (!$project) {
                return json(r_error('项目不存在', 400));
            }
            // v2.77：按项目 ID 直达详情同样受企业/部门数据范围约束
            $this->assertCompanyAccess((int)($project['client_id'] ?? 0));
            $project['status_text'] = \app\controller\admin\Project::STATUS[(int)$project['status']] ?? '未知';
            $project['client_name'] = (string)(Db::name('client_company')
                ->where('id', (int)($project['client_id'] ?? 0))->value('company_name') ?? '');
            $reqs = Db::name('project_cert_req')->where('project_id', $projectId)
                ->order('id', 'asc')->select()->toArray();
            foreach ($reqs as &$r) {
                $r['need_count']    = (int)$r['need_count'];
                $r['matched_count'] = (int)$r['matched_count'];
                $r['remain_count']  = max(0, $r['need_count'] - $r['matched_count']);
                $r['require_social_text'] = [0 => '不要求', 1 => '必须', 2 => '优先'][(int)$r['require_social']] ?? '不要求';
            }
            unset($r);
            return json(r_success(['project' => $project, 'reqs' => $reqs]));
        }

        // 待匹配需求清单（含项目名、属地、缺口人数）
        $q = Db::name('project_cert_req')->alias('r')
            ->leftJoin('project p', 'p.id = r.project_id')
            ->leftJoin('client_company c', 'c.id = p.client_id')
            ->field('r.*, p.project_name, p.region_name, p.start_date, p.end_date, c.company_name')
            ->order('r.matched_count', 'asc')->order('r.id', 'desc');
        $q = $this->applyCompanyScope($q, 'p.client_id');
        $total = (clone $q)->count();
        $list  = (clone $q)->page($page, $limit)->select()->toArray();
        foreach ($list as &$r) {
            $r['need_count']    = (int)$r['need_count'];
            $r['matched_count'] = (int)$r['matched_count'];
            $r['remain_count']  = max(0, $r['need_count'] - $r['matched_count']);
            $r['require_social_text'] = [0 => '不要求', 1 => '必须', 2 => '优先'][(int)$r['require_social']] ?? '不要求';
        }
        unset($r);

        return json(r_success(['list' => $list, 'total' => $total]));
    }

    /**
     * 按需求推荐人员
     */
    public function recommend()
    {
        $reqId = (int)$this->request->param('req_id', 0);
        if (!$reqId) {
            return json(r_error('请选择需求项', 400));
        }
        $limit   = (int)$this->request->param('limit', 20);
        $include = (int)$this->request->param('include_conflict', 0);
        PersonProfileService::ensureTables();
        $this->assertReqAccess($reqId);

        // 数据范围：非管理员仅在其可见人员池内匹配（与人员列表口径一致）
        $scope = null;
        if (!$this->isAdmin()) {
            $ids = $this->companyIds();
            if ($ids) {
                $allowed = Db::table('staff_company_rel')->whereIn('company_id', $ids)->column('staff_id') ?: [];
                $scope = function ($q) use ($allowed) {
                    $q->whereIn('cert.staff_id', $allowed ?: [0]);
                };
            } elseif ($this->roleCode() === 'company') {
                // 客户账号未关联企业：企业维度为空
                $scope = function ($q) {
                    $q->whereIn('cert.staff_id', [0]);
                };
            }
            // 内部办公账号：人员/证书按「所有人」，不加限制（v2.99.36）
        }

        $data = MatchService::recommend($reqId, [
            'limit' => $limit,
            'include_conflict' => (bool)$include,
            'scope' => $scope,
            'user'  => $this->request->user ?? [],
        ]);
        return json(r_success($data));
    }

    /**
     * 解释：某人为何未被推荐 / 得分构成
     */
    public function why()
    {
        $reqId   = (int)$this->request->param('req_id', 0);
        $staffId = (int)$this->request->param('staff_id', 0);
        if (!$reqId || !$staffId) {
            return json(r_error('请选择需求项与人员', 400));
        }
        PersonProfileService::ensureTables();
        $this->assertReqAccess($reqId);
        $data = MatchService::why($reqId, $staffId);
        return json(r_success($data));
    }

    /**
     * 需求项所属项目的数据权限校验（v2.77）
     * 与项目列表口径一致：admin 不限；其余按可见企业（内部账号按部门范围换算）。
     */
    protected function assertReqAccess(int $reqId): void
    {
        if ($this->isAdmin()) {
            return;
        }
        $clientId = (int)(Db::name('project_cert_req')->alias('r')
            ->leftJoin('project p', 'p.id = r.project_id')
            ->where('r.id', $reqId)->value('p.client_id') ?? 0);
        if (!$this->canAccessCompany($clientId)) {
            throw new \think\exception\HttpResponseException(json(r_error('无权访问该项目的配证数据', 403)));
        }
    }

    /**
     * 确认选用（v2.33 改为「先弹窗建借调，再回调记录」）：
     * 前端先在「证书借调」弹窗中人工完善借调期限/企业/费用等，调 secondment/save 生成借调记录，
     * 再携带 secondment_id 回调本方法写匹配留痕 + 回写需求已匹配数。
     * 本方法不再一键生成借调，避免跳过人工完善环节。
     */
    public function record()
    {
        $reqId        = (int)$this->request->post('req_id', 0);
        $certId       = (int)$this->request->post('cert_id', 0);
        $secondmentId = (int)$this->request->post('secondment_id', 0);
        if (!$reqId || !$certId || !$secondmentId) {
            return json(r_error('请选择需求项、证书与借调记录', 400));
        }
        // 数据范围：非管理员只能为可见项目选用（与项目列表口径一致）
        if (!$this->isAdmin()) {
            $clientId = (int)(Db::name('project_cert_req')->alias('r')
                ->leftJoin('project p', 'p.id = r.project_id')
                ->where('r.id', $reqId)->value('p.client_id') ?? 0);
            if (!$this->canAccessCompany($clientId)) {
                return json(r_error('无权操作该项目的配证', 403));
            }
        }
        $userId = (int)($this->request->user['id'] ?? 0);

        $r = MatchService::record([
            'req_id'         => $reqId,
            'cert_id'        => $certId,
            'secondment_id'  => $secondmentId,
            'create_by'      => $userId,
        ]);

        if (empty($r['ok'])) {
            return json(r_error($r['msg'] ?? '选用失败', 400));
        }
        $this->log('智能匹配', '选用', "需求#{$reqId} 证书#{$certId} → 借调#{$secondmentId}");
        return json(r_success(['secondment_id' => $secondmentId], $r['msg'] ?? '已记录选用'));
    }

    /**
     * 一键选用（v2.44 恢复）：带借调参数（可选）事务内建借调 + 匹配留痕 + 推进需求进度。
     *
     * 背景：v2.34 PC 端改为「弹窗完善借调 → secondment/save → record 留痕」后，
     * 控制器方法被一并删除，但路由与移动端 H5 智能匹配（mobile/pages/match）仍指向
     * 本方法，导致移动端「选用」始终报错。此处恢复实现，语义与 MatchService::select 对齐：
     *   · company_id / start_date / end_date 不传时自动取项目客户企业、日期留空由人工后续完善；
     *   · 阻断级冲突直接拒绝（与 Secondment::save 口径一致）；
     *   · matched_count 唯一写入口仍是 TaskProgressService，与转人工配证不重复计数。
     */
    public function select()
    {
        $reqId  = (int)$this->request->post('req_id', 0);
        $certId = (int)$this->request->post('cert_id', 0);
        if (!$reqId || !$certId) {
            return json(r_error('请选择需求项与证书', 400));
        }
        // 数据范围：非管理员只能为可见客户项目选用（与 record 口径一致）
        if (!$this->isAdmin()) {
            $clientId = (int)(Db::name('project_cert_req')->alias('r')
                ->leftJoin('project p', 'p.id = r.project_id')
                ->where('r.id', $reqId)->value('p.client_id') ?? 0);
            if (!$this->canAccessCompany($clientId)) {
                return json(r_error('无权操作该项目的配证', 403));
            }
        }
        $userId = (int)($this->request->user['id'] ?? 0);

        $r = MatchService::select([
            'req_id'      => $reqId,
            'cert_id'     => $certId,
            'company_id'  => (int)$this->request->post('company_id', 0),
            'start_date'  => trim((string)$this->request->post('start_date', '')),
            'end_date'    => trim((string)$this->request->post('end_date', '')),
            'create_by'   => $userId,
        ]);
        if (empty($r['ok'])) {
            return json(r_error($r['msg'] ?? '选用失败', 400));
        }
        $this->log('智能匹配', '一键选用', "需求#{$reqId} 证书#{$certId} → 借调#{$r['secondment_id']}");
        return json(r_success(['secondment_id' => (int)$r['secondment_id']], $r['msg'] ?? '已选用'));
    }

    /**
     * 转人工配证（#236 / v2.32 去重 + 全量）：匹配不到 / 数量不够时，把未满足的需求转为配证任务，
     * 明细字段与配证任务对齐（cert_category → category），并回写 source_req_id 关联，
     * 以便后续人工借调完成时通过 TaskProgressService 同步任务进度与项目进度。
     *
     * 入参：
     *   · req_ids[]（指定需求）或 project_id（自动取该项目下未满足需求）
     *   · scope（默认 all_remain）：all_remain=以 reqIds[0] 的项目为准，
     *     扩展为该项目**全部** need_count > matched_count 的需求（用户诉求「把未匹配专业全转过去」）
     *
     * v2.32 去重（用户诉求「没有校验是否已转人工配证，可以无限创建任务」）：
     *   · 已有「进行中/待发布」任务明细（need_count > done_count）覆盖的需求 → 不再重复建
     *   · 全部已覆盖 → 报错含任务号，**不建任务**
     *   · 部分覆盖 → 建任务 + 返回 skipped[]（前端提示跳过了哪些）
     */
    public function toManual()
    {
        $reqIds = array_values(array_filter(array_map('intval', (array)$this->request->post('req_ids/a', []))));
        $projectId = (int)$this->request->post('project_id', 0);
        $scope = trim((string)$this->request->post('scope', 'all_remain')) ?: 'all_remain';
        PersonProfileService::ensureTables();

        if (!$reqIds && $projectId) {
            $reqs = Db::name('project_cert_req')->where('project_id', $projectId)->select()->toArray();
            foreach ($reqs as $r) {
                if ((int)$r['need_count'] - (int)$r['matched_count'] > 0) {
                    $reqIds[] = (int)$r['id'];
                }
            }
        }
        if (!$reqIds) {
            return json(r_error('请选择要转人工配证的需求', 400));
        }

        // 数据范围：非管理员只能转自己可见客户项目的配证
        if (!$this->isAdmin()) {
            $clientIds = Db::name('project_cert_req')->alias('r')
                ->leftJoin('project p', 'p.id = r.project_id')
                ->whereIn('r.id', $reqIds)->column('p.client_id');
            foreach (array_unique(array_map('intval', $clientIds)) as $cid) {
                if (!$this->canAccessCompany($cid)) {
                    return json(r_error('无权操作该项目的配证', 403));
                }
            }
        }

        $rows = Db::name('project_cert_req')->whereIn('id', $reqIds)->order('id', 'asc')->select()->toArray();
        if (!$rows) {
            return json(r_error('需求不存在', 400));
        }
        $projId = (int)$rows[0]['project_id'];

        // ★ v2.32 scope=all_remain：扩展为该项目全部未满足需求（含未勾选的缺口）
        if ($scope === 'all_remain') {
            $all = Db::name('project_cert_req')->where('project_id', $projId)->order('id', 'asc')->select()->toArray();
            $rows = [];
            foreach ($all as $r) {
                if ((int)$r['need_count'] - (int)$r['matched_count'] > 0) {
                    $rows[] = $r;
                }
            }
        }

        // ★ v2.32 去重：找出这些需求中「已被进行中/待发布任务覆盖且仍有缺口」的部分
        $candidateReqIds = array_map('intval', array_column($rows, 'id'));
        $busyByReq = [];
        if ($candidateReqIds) {
            $busy = Db::name('cert_task_item')->alias('ti')
                ->leftJoin('cert_task t', 't.id = ti.task_id')
                ->whereIn('ti.source_req_id', $candidateReqIds)
                ->whereIn('t.status', [0, 1])
                ->whereRaw('ti.need_count > ti.done_count')
                ->field('ti.source_req_id, ti.task_id, t.title')
                ->select()->toArray();
            foreach ($busy as $b) {
                $rid = (int)$b['source_req_id'];
                if (!isset($busyByReq[$rid])) {
                    $busyByReq[$rid] = ['task_id' => (int)$b['task_id'], 'task_title' => (string)($b['title'] ?? '')];
                }
            }
        }

        $todoReqIds = [];
        $skipped = [];
        foreach ($rows as $r) {
            $rid = (int)$r['id'];
            if (isset($busyByReq[$rid])) {
                $skipped[] = [
                    'req_id'     => $rid,
                    'cert_name'  => (string)$r['cert_name'],
                    'task_id'    => $busyByReq[$rid]['task_id'],
                    'task_title' => $busyByReq[$rid]['task_title'],
                ];
                continue;
            }
            $todoReqIds[] = $rid;
        }

        // 全部已转人工 → 拒绝，不建任务（用户诉求：不可无限创建）
        if (!$todoReqIds) {
            $first = reset($skipped);
            $tid = $first['task_id'] ?? 0;
            return json(r_error("该需求已于「配证任务 #{$tid}」转人工配证，请勿重复创建", 400));
        }

        $todoRows = array_values(array_filter($rows, static function ($r) use ($todoReqIds) {
            return in_array((int)$r['id'], $todoReqIds, true);
        }));

        $project  = Db::name('project')->where('id', $projId)->field('project_name,client_id')->find();
        $companyId = (int)($project['client_id'] ?? 0);
        $userId   = (int)($this->request->user['id'] ?? 0);
        $userName = $this->request->user['real_name'] ?? ($this->request->user['username'] ?? '');

        $taskId = Db::transaction(function () use ($todoRows, $projId, $companyId, $userId, $userName, $project) {
            $taskId = Db::name('cert_task')->insertGetId([
                'publisher_id'   => $userId,
                'publisher_name' => $userName,
                'company_id'    => $companyId,
                'project_id'    => $projId,
                'title'         => '【转人工配证】' . ($project['project_name'] ?? ('项目#' . $projId)),
                'description'   => '由智能匹配不足自动转人工配证',
                'status'        => 1,
                'visible_scope' => 'all',
                'create_time'   => date('Y-m-d H:i:s'),
            ]);
            foreach ($todoRows as $r) {
                $remain = max(0, (int)$r['need_count'] - (int)$r['matched_count']);
                if ($remain <= 0) {
                    continue;
                }
                // v2.36 字段对齐配证任务：cert_category → category（类别维度），
                // 并补上此前遗漏的 cert_type（证书类型）—— 漏传会导致任务明细在
                // 台账/匹配侧丢类型、进度回写三键失配。
                $reqType = trim((string)($r['cert_type'] ?? ''));
                $reqCat  = trim((string)($r['cert_category'] ?? ''));
                if ($reqType !== '' && $reqCat === '') {
                    $reqCat = $reqType; // 类别以类型为准同步，与 save 同口径
                }
                Db::name('cert_task_item')->insert([
                    'task_id'       => $taskId,
                    'cert_name'     => $r['cert_name'],
                    'category'      => $reqCat,
                    'cert_type'     => $reqType,
                    'need_count'    => $remain,
                    'done_count'    => 0,
                    'remark'        => $r['remark'] ?? '',
                    'source_req_id' => (int)$r['id'],
                    'create_time'   => date('Y-m-d H:i:s'),
                ]);
            }
            Db::name('cert_task_log')->insert([
                'task_id'   => $taskId,
                'item_id'   => 0,
                'user_id'   => $userId,
                'user_name' => $userName,
                'content'   => '由智能匹配不足转人工配证，生成 ' . count($todoRows) . ' 条需求',
                'create_time' => date('Y-m-d H:i:s'),
            ]);
            return $taskId;
        });

        // 事务后重算受影响需求进度（新建明细 done=0，确保与事实源一致）
        try {
            TaskProgressService::recalcByReq($todoReqIds);
        } catch (\Throwable $e) {
            // 重算失败不阻断
        }

        $this->log('智能匹配', '转人工配证', "项目#{$projId} → 任务#{$taskId}，需求 " . implode(',', $todoReqIds));
        $msg = '已转人工配证任务';
        if ($skipped) {
            $msg .= '（' . count($skipped) . ' 条已在其他任务中，已跳过）';
        }
        return json(r_success([
            'task_id' => $taskId,
            'created' => count($todoRows),
            'skipped' => $skipped,
        ], $msg));
    }

    /** 放弃候选（留痕） */
    public function giveUp()
    {
        $reqId   = (int)$this->request->post('req_id', 0);
        $staffId = (int)$this->request->post('staff_id', 0);
        $certId  = (int)$this->request->post('cert_id', 0);
        if (!$reqId || !$staffId) {
            return json(r_error('参数不完整', 400));
        }
        $userId = (int)($this->request->user['id'] ?? 0);
        MatchService::giveUp($reqId, $staffId, $certId, $userId,
            trim((string)$this->request->post('reason', '')));
        return json(r_success([], '已记录放弃'));
    }
}