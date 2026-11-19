<?php
namespace app\controller\admin;

use app\model\AlertLog;
use app\model\Certificate;
use app\model\ClientCompany;
use app\model\Contract;
use app\model\CustomField;
use app\model\Fee;
use app\model\Staff;
use app\service\SecondmentService;
use think\facade\Db;

class Dashboard extends AdminController
{
    /**
     * 工作台统计（v2.38：整份结果按「用户 + 数据范围」指纹缓存）
     *
     * 原实现每次进首页都要跑 20+ 个 COUNT/聚合，是全站最重的只读接口。
     * 统计数字允许秒级延迟，故：
     *   - 缓存键 = md5(userId + 是否管理员 + 可见企业范围)，不同数据范围互不串味；
     *   - TTL 60 秒（RedisCache::TTL_SHORT）；
     *   - 证书/借调/费用/合同/人员/企业 写路径调 forgetDashboard() 立即失效，
     *     避免"刚录入就看不到数字变化"。
     */
    public function stat()
    {
        $uid     = (int)($this->request->userId ?? 0);
        $isAdmin = $this->isAdmin();
        // v2.77：内网账号未关联企业时按部门范围换算可见企业（null=不限制）
        $companyIds = $this->visibleCompanyIds();
        // 数据范围指纹：范围变了缓存自然不共享
        $scopeKey = $companyIds === null ? 'all' : md5(implode(',', array_map('intval', $companyIds)));
        $cacheKey = 'stat_' . $uid . '_' . $scopeKey;

        $data = \app\service\RedisCache::remember(
            \app\service\RedisCache::NS_DASH,
            $cacheKey,
            \app\service\RedisCache::TTL_SHORT,
            fn() => $this->buildStat($isAdmin, $companyIds)
        );

        return json(r_success($data));
    }

    /** 工作台统计清缓存（业务写路径调用） */
    public static function forgetDashboard(): void
    {
        \app\service\RedisCache::flushNs(\app\service\RedisCache::NS_DASH);
    }

    /**
     * v2.99.36：申报进度的数据范围（与 GovProgress::scopeGov 同口径）。
     * 客户侧账号按企业过滤；内部账号按创建人 create_by。
     */
    protected function applyGovDashboardScope($query)
    {
        if ($this->isAdmin()) {
            return $query;
        }
        $relIds = array_filter(array_map('intval', $this->companyIds()));
        if ($relIds) {
            return $query->whereIn('company_id', $relIds);
        }
        if ($this->roleCode() === 'company') {
            return $query->where('company_id', 0);
        }
        return \app\service\DataScopeService::applyCreatorScope($query, $this->request->user ?? [], 'create_by');
    }

    /** 实际统计计算（仅在缓存未命中时执行） */
    protected function buildStat(bool $isAdmin, ?array $companyIds): array
    {
        $today    = date('Y-m-d');
        $soonDate = date('Y-m-d', strtotime('+30 days'));

        // v2.77：内部办公账号（未关联企业的非 company 角色）证照/人员按部门范围统计，
        // 与证书列表、人员列表口径保持一致。
        $internal = false;
        if (!$isAdmin) {
            $relIds = array_filter(array_map('intval', $this->companyIds()));
            $internal = !$relIds && $this->roleCode() !== 'company';
        }

        $totalCompany = ClientCompany::where('status', 1)
            ->when($companyIds !== null, fn($q) => $q->whereIn('id', $companyIds ?: [0]))->count();

        $staffIds = null;
        if (!$isAdmin && !$internal) {
            $staffIds = Db::table('staff_company_rel')->whereIn('company_id', $companyIds ?: [0])->column('staff_id') ?: [0];
        }
        $staffQ = Staff::when($staffIds !== null, fn($q) => $q->whereIn('id', array_map('intval', $staffIds)));
        // v2.99.36：内部办公账号按「所有人」可见人员（部门四级范围已废弃）
        $totalStaff = $staffQ->count();

        // 证书不再有 company_id：客户侧账号可见证书 = 借调中的证书；内部账号「所有人」可见
        $certIds = (!$isAdmin && !$internal) ? SecondmentService::certIdsOf($companyIds ?: [], 1) : null;
        $certScope = function ($q) use ($internal, $certIds) {
            if ($internal) {
                return $q; // 核心资源所有人可见
            }
            if ($certIds !== null) {
                $q->whereIn('id', $certIds ?: [0]);
            }
            return $q;
        };
        $certQ        = $certScope(Certificate::whereIn('cert_status', [1, 0]));
        $totalCert    = (clone $certQ)->count();
        $expireSoon   = (clone $certQ)->where('cert_status', 1)
            ->whereBetween('expire_date', [$today, $soonDate])->count();
        $expired      = (clone $certQ)->where(function ($w) use ($today) {
            $w->where('cert_status', 0)->whereOr(function ($w2) use ($today) {
                $w2->where('cert_status', 1)->where('expire_date', '<', $today);
            });
        })->count();
        $urgent       = (clone $certQ)->where('cert_status', 1)->where('priority', '紧急')->count();
        $totalFee     = (clone $certQ)->sum('cert_fee');

        // 各企业借调证书分布
        $byCompany = ClientCompany::field('id,company_name')
            ->when($companyIds !== null, fn($q) => $q->whereIn('id', $companyIds ?: [0]))
            ->select()->toArray();
        $secStatQ = $this->applySecondmentScope(
            Db::name('cert_secondment')->field('company_id, count(*) as sec_count, sum(fee_amount) as sec_fee')
                ->where('status', 1)
        );
        $secStat = $secStatQ->group('company_id')->select()->toArray();
        $secMap = [];
        foreach ($secStat as $s) {
            $secMap[(int)$s['company_id']] = $s;
        }
        foreach ($byCompany as &$c) {
            $s = $secMap[(int)$c['id']] ?? null;
            $c['sec_count'] = (int)($s['sec_count'] ?? 0);
            $c['sec_fee']   = (float)($s['sec_fee'] ?? 0);
        }
        unset($c);

        // 状态分布
        $statusDist = $certScope(Certificate::field('cert_status,count(*) as cnt'))
            ->group('cert_status')->select()->toArray();

        // 即将到期 Top10
        $expiringList = $certScope(Certificate::with(['staff'])->where('cert_status', 1))
            ->whereBetween('expire_date', [$today, $soonDate])
            ->orderRaw('expire_date IS NULL ASC, expire_date ASC')->limit(10)->select()->toArray();
        SecondmentService::attach($expiringList);
        $expiringList = \app\service\MaskService::certListFor($expiringList, $this->request->user ?? []);

        // 近期提醒
        $recentAlerts = AlertLog::with(['company', 'staff', 'certificate'])
            ->when($companyIds !== null, fn($q) => $q->whereIn('company_id', $companyIds ?: [0]))
            ->order('id', 'desc')->limit(8)->select()->toArray();
        $user = $this->request->user ?? [];
        foreach ($recentAlerts as &$alert) {
            if (!empty($alert['staff']) && is_array($alert['staff'])) {
                $alert['staff'] = \app\service\MaskService::staff($alert['staff'], false, $user);
            }
            if (!empty($alert['certificate']) && is_array($alert['certificate'])) {
                $alert['certificate'] = \app\service\MaskService::cert($alert['certificate'], $user);
            }
        }
        unset($alert);

        // 合同即将到期
        $contractSoon = $this->applyModuleScope(
            Contract::where('status', 1)->whereBetween('end_date', [$today, $soonDate]),
            'contract'
        )->count();

        // 借调统计（v2.77：内部账号走企业∪人员部门范围）
        $secQ    = $this->applySecondmentScope(Db::name('cert_secondment')->where('status', 1));
        $secTotal   = (clone $secQ)->count();
        $secSoon    = (clone $secQ)->whereBetween('end_date', [$today, $soonDate])->count();
        $secExpired = SecondmentService::expiredCount(
            $this->applySecondmentScope(Db::name('cert_secondment')),
            $today
        );
        $freeCert   = $certScope(Certificate::whereIn('cert_status', [1, 0]))
            ->whereNotIn('id', Db::name('cert_secondment')->where('status', 1)->where('direction', 1)->column('cert_id') ?: [0])
            ->count();

        // 借调方向统计（借入在库 / 借出在外）
        $inStock   = (clone $this->applySecondmentScope(Db::name('cert_secondment')))->where('direction', 2)->where('status', 1)->count();
        $outActive = (clone $this->applySecondmentScope(Db::name('cert_secondment')))->where('direction', 1)->where('status', 1)->count();

        // 费用台账统计（v2.99.36：客户账号按企业，内部账号按创建人）
        $feeQ = $this->applyModuleScope(Db::name('fee'), 'fee');
        $feeUnsettled = (float)((clone $feeQ)->where('settle_status', 0)->sum('amount') ?? 0);
        $feeTotal     = (float)((clone $feeQ)->sum('amount') ?? 0);
        $feeIncome    = (float)((clone $feeQ)->where('fee_type', 'income')->sum('amount') ?? 0);
        $feeExpense   = (float)((clone $feeQ)->where('fee_type', 'expense')->sum('amount') ?? 0);

        // 合同统计
        $contractQ = $this->applyModuleScope(new Contract(), 'contract');
        $contractTotal     = (clone $contractQ)->count();
        $contractExecuting = (clone $contractQ)->where('status', 1)->count();

        $customFieldCount = CustomField::count();

        // ===== v2.27 产品对齐指标：配证缺口 / 冲突 / 利用率 / 闲置 =====
        // 这几项来自新模块，老库缺表时降级为 0，不影响工作台可用性
        $align = ['project_total' => 0, 'project_need' => 0, 'project_matched' => 0,
                  'conflict_total' => 0, 'cert_idle' => 0, 'cert_use_rate' => 0, 'idle_long' => 0];
        try {
            \app\service\PersonProfileService::ensureTables();
            $projectIds = Db::name('project')
                ->when($companyIds !== null, fn($q) => $q->whereIn('client_id', $companyIds ?: [0]))
                ->column('id');
            $reqs = Db::name('project_cert_req')
                ->when($companyIds !== null, fn($q) => $q->whereIn('project_id', $projectIds ?: [0]))
                ->field('COALESCE(SUM(need_count),0) AS need, COALESCE(SUM(matched_count),0) AS matched')->find();
            $align['project_total']  = count($projectIds);
            $align['project_need']   = (int)($reqs['need'] ?? 0);
            $align['project_matched']= (int)($reqs['matched'] ?? 0);

            // 冲突扫描目前是全局服务，未接受企业范围参数；非管理员不展示全局数字，避免聚合泄露。
            if ($isAdmin) {
                $conflicts = \app\service\ConflictService::listConflicts(['limit' => 200]);
                $align['conflict_total'] = (int)$conflicts['total'];
            }

            // 利用率与闲置：基于当前可见证书范围
            $usedSet = array_flip(array_map('intval',
                Db::name('cert_secondment')->where('status', 1)->where('direction', 1)->column('cert_id') ?: []));
            $visibleQ = $certScope(Certificate::whereIn('cert_status', [1, 0]));
            $alignIdle = 0;
            $idleIds = [];
            foreach ((clone $visibleQ)->field('id,expire_date')->select()->toArray() as $cr) {
                if (!isset($usedSet[(int)$cr['id']])) {
                    $alignIdle++;
                    if (remain_days((string)($cr['expire_date'] ?? '')) > 180) { $idleIds[] = (int)$cr['id']; }
                }
            }
            $align['cert_idle'] = $alignIdle;
            $align['idle_long'] = count($idleIds);
            $visTotal = (clone $visibleQ)->count();
            $align['cert_use_rate'] = $visTotal > 0
                ? round((($visTotal - $alignIdle) * 100 / $visTotal), 1) : 0;
        } catch (\Throwable $e) {
            // 新表尚未就绪时保持 0，不阻断工作台
        }

        // ===== v2.93 风险卡片：企业证书（安许/资质）临期 / 已过期 =====
        // 复用 company_qualification 表，企业维度数据范围（客户账号限本企业，内部账号按部门范围）
        $companyCertSoon = 0;
        $companyCertExpired = 0;
        try {
            \app\service\CompanyQualificationService::ensureSchema();
            $ccIds = $companyIds;
            if ($internal) {
                // 内部账号：按负责人部门范围换算可见企业（与企业客户列表口径一致）
                $ccQ = $this->scopeCompany(\app\model\ClientCompany::field('id')->where('status', 1));
                $ccIds = array_map('intval', $ccQ->column('id'));
            }
            $ccBase = Db::name('company_qualification')->where('status', 1)
                ->when($ccIds !== null, fn($q) => $q->whereIn('company_id', $ccIds ?: [0]));
            $companyCertSoon = (clone $ccBase)->whereNotNull('expire_date')
                ->whereBetween('expire_date', [$today, $soonDate])->count();
            $companyCertExpired = (clone $ccBase)->whereNotNull('expire_date')
                ->where('expire_date', '<', $today)->count();
        } catch (\Throwable $e) {
            // 表未就绪时降级为 0
        }

        // ===== v2.93 风险卡片：政务网申报承诺逾期 / 7 天内到期（未办结未归档） =====
        // v2.99.46：新增退件/补正待处理口径，与 GovProgress 控制器 stat() 保持一致
        $govOverdue = 0;
        $govSoon = 0;
        $govReturned = 0;
        try {
            \app\service\GovProgressService::ensureTables();
            $govBase = $this->applyGovDashboardScope(Db::name('gov_progress'))
                ->where('is_finished', 0)->where('is_archived', 0);
            $govQ = (clone $govBase)->whereNotNull('promise_time');
            $govOverdue = (clone $govQ)->where('promise_time', '<', $today)->count();
            $govSoon = (clone $govQ)->whereBetween('promise_time', [$today, date('Y-m-d', strtotime('+7 days'))])->count();
            $govReturned = (clone $govBase)->where(function ($w) {
                $w->where('is_returned', 1)
                  ->whereOr('current_node', 'like', '%退件%')
                  ->whereOr('current_node', 'like', '%退回%')
                  ->whereOr('current_node', 'like', '%补正%')
                  ->whereOr('handle_state', 'like', '%退件%')
                  ->whereOr('handle_state', 'like', '%补正%');
            })->count();
        } catch (\Throwable $e) {
            // 表未就绪时降级为 0
        }

        return [
            'align'          => $align,
            'total_company' => $totalCompany,
            'total_staff'   => $totalStaff,
            'total_cert'    => $totalCert,
            'expire_soon'   => $expireSoon,
            'expired'       => $expired,
            'urgent'        => $urgent,
            'total_fee'     => $totalFee,
            'contract_soon' => $contractSoon,
            'company_cert_soon'    => $companyCertSoon,
            'company_cert_expired' => $companyCertExpired,
            'gov_overdue'   => $govOverdue,
            'gov_soon'      => $govSoon,
            'gov_returned'  => $govReturned,
            'by_company'    => $byCompany,
            'status_dist'   => $statusDist,
            'expiring_list' => $expiringList,
            'recent_alerts' => $recentAlerts,
            'secondment_total'   => $secTotal,
            'secondment_soon'    => $secSoon,
            'secondment_expired' => $secExpired,
            'secondment_in_stock' => $inStock,
            'secondment_out_active' => $outActive,
            'fee_unsettled'   => $feeUnsettled,
            'fee_total'       => $feeTotal,
            'fee_income'      => $feeIncome,
            'fee_expense'     => $feeExpense,
            'fee_profit'      => $feeIncome - $feeExpense,
            'contract_total'  => $contractTotal,
            'contract_executing' => $contractExecuting,
            'custom_field_count' => $customFieldCount,
            'free_cert'          => $freeCert,
        ];
    }
}
