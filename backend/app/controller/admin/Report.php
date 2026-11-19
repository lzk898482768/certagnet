<?php
namespace app\controller\admin;

use app\model\Certificate;
use app\model\ClientCompany;
use app\service\SecondmentService;
use think\facade\Db;

class Report extends AdminController
{
    // 按企业汇总借调证书/费用（保留原接口）
    public function summary()
    {
        $companyId = (int)$this->request->param('company_id', 0);
        $q = ClientCompany::field('id,company_name')->when($companyId, fn($q) => $q->where('id', $companyId));
        $q = $this->scopeCompany($q, 'id'); // v2.77：内部账号按部门范围换算企业
        $companies = $q->select()->toArray();

        // 借调维度统计
        $secRows = Db::name('cert_secondment')
            ->field('company_id, count(*) as sec_total, sum(fee_amount) as fee')
            ->where('status', 1)->group('company_id')->select()->toArray();
        $secMap = [];
        foreach ($secRows as $r) {
            $secMap[(int)$r['company_id']] = $r;
        }

        // 独立费用台账维度
        $feeRows = Db::name('fee')
            ->field('company_id, sum(amount) as fee_total')
            ->group('company_id')->select()->toArray();
        $feeMap = [];
        foreach ($feeRows as $r) {
            $feeMap[(int)$r['company_id']] = (float)$r['fee_total'];
        }

        $today = date('Y-m-d');
        $soon  = date('Y-m-d', strtotime('+30 days'));

        $result = [];
        foreach ($companies as $c) {
            $cid   = (int)$c['id'];
            $certIds = SecondmentService::certIdsOf([$cid], 1);
            $q2    = Certificate::whereIn('id', $certIds ?: [0])->whereIn('cert_status', [1, 0]);
            $total      = (clone $q2)->count();
            $expireSoon = (clone $q2)->where('cert_status', 1)
                ->whereBetween('expire_date', [$today, $soon])->count();
            // 与报表总览一致：状态已是「已过期」，或仍为正常但到期日已过
            $expired    = (clone $q2)->where(function ($w) use ($today) {
                $w->where('cert_status', 0)->whereOr(function ($w2) use ($today) {
                    $w2->where('cert_status', 1)->where('expire_date', '<', $today);
                });
            })->count();
            $s          = $secMap[$cid] ?? null;
            $result[]   = [
                'company_name' => $c['company_name'],
                'total'        => $total,
                'expire_soon'  => $expireSoon,
                'expired'      => $expired,
                'sec_total'    => (int)($s['sec_total'] ?? 0),
                'fee'          => (float)($s['fee'] ?? 0),
                'fee_total'    => (float)($feeMap[$cid] ?? 0),
            ];
        }
        return json(r_success($result));
    }

    /**
     * 综合统计概览（供统计报表页多图表使用）
     * 返回：概览指标、证书状态分布、到期趋势、企业排行、借调分布、费用趋势、证书类型分布
     */
    public function overview()
    {
        $months = (int)$this->request->param('months', 12);
        $months = max(3, min(36, $months));
        $today  = date('Y-m-d');
        $soon   = date('Y-m-d', strtotime('+30 days'));

        // ---------- 证书范围（含数据权限） ----------
        // v2.99.36：客户侧账号按借调关系；内部办公账号证书按「所有人」可见
        $internal = false;
        if (!$this->isAdmin()) {
            $relIds = array_filter(array_map('intval', $this->companyIds()));
            $internal = !$relIds && $this->roleCode() !== 'company';
        }
        $certIds = null;
        if (!$this->isAdmin() && !$internal) {
            $certIds = SecondmentService::certIdsOf($this->companyIds(), 1) ?: [0];
        }
        $certQ = function () use ($certIds, $internal) {
            $q = Db::name('certificate');
            if ($internal) {
                return $q;
            }
            if ($certIds !== null) {
                $q->whereIn('id', $certIds);
            }
            return $q;
        };

        // 证书统计口径与工作台保持一致：只统计在管证书（正常 / 已过期），挂失、注销不计入。
        $totalCert = (clone $certQ())->whereIn('cert_status', [0, 1])->count();
        $normal    = (clone $certQ())->where('cert_status', 1)->where('expire_date', '>', $soon)->count();
        $soonCnt   = (clone $certQ())->where('cert_status', 1)->whereBetween('expire_date', [$today, $soon])->count();
        $expiredCnt= (clone $certQ())->whereIn('cert_status', [0, 1])->where(function ($w) use ($today) {
            $w->where('cert_status', 0)->whereOr(function ($w2) use ($today) {
                $w2->where('cert_status', 1)->where('expire_date', '<', $today);
            });
        })->count();

        // ---------- 借调维度 ----------
        $secQ = $this->applySecondmentScope(Db::name('cert_secondment'));
        $outActive = (clone $secQ)->where('direction', 1)->where('status', 1)->count();
        $inStock   = (clone $secQ)->where('direction', 2)->where('status', 1)->count();
        // 饼图口径：未到期的借出 / 借入，避免与「已到期」重复计数
        $outCurrent = (clone $secQ)->where('direction', 1)->where('status', 1)
            ->where(function ($w) use ($today) {
                $w->whereNull('end_date')->whereOr('end_date', '>=', $today);
            })->count();
        $inCurrent = (clone $secQ)->where('direction', 2)->where('status', 1)
            ->where(function ($w) use ($today) {
                $w->whereNull('end_date')->whereOr('end_date', '>=', $today);
            })->count();
        $secReturn = (clone $secQ)->where('status', 0)->count();
        $secExpire = SecondmentService::expiredCount($secQ, $today);
        // 借调即将到期（借调中且 end_date 落在 [今天, +30天]）——统计报表「借调到期」核心指标
        $secSoon   = (clone $secQ)->where('status', 1)->whereBetween('end_date', [$today, $soon])->count();

        $busyIds = (clone $secQ)->where('direction', 1)->where('status', 1)->column('cert_id') ?: [0];
        $freeCert = (clone $certQ())->where('cert_status', 1)->whereNotIn('id', $busyIds)->count();

        // ---------- 合同 ----------
        $conQ = $this->applyModuleScope(Db::name('contract'), 'contract');
        $contractTotal = (clone $conQ)->count();
        $contractExec  = (clone $conQ)->where('status', 1)->count();
        $contractSoon  = (clone $conQ)->where('status', 1)->whereBetween('end_date', [$today, $soon])->count();

        // ---------- 费用 ----------
        $feeQ = $this->applyModuleScope(Db::name('fee'), 'fee');
        $feeIncome   = (float)(clone $feeQ)->where('fee_type', 'income')->sum('amount');
        $feeExpense  = (float)(clone $feeQ)->where('fee_type', 'expense')->sum('amount');
        $feeUnsettled= (float)(clone $feeQ)->where('settle_status', 0)->sum('amount');

        // ---------- 证书状态分布 ----------
        $certStatusDist = [
            ['name' => '正常', 'value' => (int)$normal],
            ['name' => '30天内到期', 'value' => (int)$soonCnt],
            ['name' => '已过期', 'value' => (int)$expiredCnt],
        ];

        // ---------- 到期趋势（未来 N 个月） ----------
        $trendStart = date('Y-m-01');
        $trendEnd   = date('Y-m-t', strtotime('+' . ($months - 1) . ' months'));
        $trendRows  = (clone $certQ())
            ->where('cert_status', 1)
            ->whereBetween('expire_date', [$trendStart, $trendEnd])
            ->field("DATE_FORMAT(expire_date, '%Y-%m') as ym, count(*) as cnt")
            ->group('ym')->select()->toArray();
        $trendMap = [];
        foreach ($trendRows as $r) {
            $trendMap[$r['ym']] = (int)$r['cnt'];
        }
        $expireTrend = [];
        for ($i = 0; $i < $months; $i++) {
            $ym = date('Y-m', strtotime("+{$i} months"));
            $expireTrend[] = ['ym' => $ym, 'count' => (int)($trendMap[$ym] ?? 0)];
        }

        // ---------- 证书类型分布 ----------
        $typeRows = (clone $certQ())
            ->whereIn('cert_status', [0, 1])
            ->field("IFNULL(NULLIF(cert_type, ''), '未分类') as name, count(*) as value")
            ->group('name')->order('value', 'desc')->limit(10)->select()->toArray();
        $certTypeDist = array_map(fn($r) => ['name' => (string)$r['name'], 'value' => (int)$r['value']], $typeRows);

        // ---------- 企业排行（证书数 + 费用） ----------
        $cQ = ClientCompany::field('id,company_name');
        $cQ = $this->scopeCompany($cQ, 'id');
        $companyList   = $cQ->select()->toArray();
        $companyFeeMap = [];
        foreach ((clone $feeQ)->field('company_id, sum(amount) as amt')->group('company_id')->select()->toArray() as $r) {
            $companyFeeMap[(int)$r['company_id']] = (float)$r['amt'];
        }
        $companyRank = [];
        foreach ($companyList as $c) {
            $ids  = SecondmentService::certIdsOf([(int)$c['id']], 1);
            $cnt  = $ids ? (clone $certQ())->whereIn('id', $ids)->whereIn('cert_status', [0, 1])->count() : 0;
            $companyRank[] = [
                'company_name' => $c['company_name'],
                'cert_count'   => (int)$cnt,
                'fee'          => (float)($companyFeeMap[(int)$c['id']] ?? 0),
            ];
        }
        usort($companyRank, fn($a, $b) => $b['cert_count'] <=> $a['cert_count']);
        $companyRank = array_slice($companyRank, 0, 10);

        // ---------- 借调分布 ----------
        $secondmentDist = [
            ['name' => '借出在外', 'value' => (int)$outCurrent],
            ['name' => '借入在库', 'value' => (int)$inCurrent],
            ['name' => '已归还', 'value'   => (int)$secReturn],
            ['name' => '已到期', 'value'   => (int)$secExpire],
        ];

        // ---------- 费用趋势（近 N 个月） ----------
        $feeStart = date('Y-m-01', strtotime('-' . ($months - 1) . ' months'));
        $feeRows  = (clone $feeQ)->where('occur_date', '>=', $feeStart)
            ->field("DATE_FORMAT(occur_date, '%Y-%m') as ym, fee_type, sum(amount) as amt")
            ->group('ym, fee_type')->select()->toArray();
        $feeTrendMap = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $ym = date('Y-m', strtotime("-{$i} months"));
            $feeTrendMap[$ym] = ['ym' => $ym, 'income' => 0.0, 'expense' => 0.0];
        }
        foreach ($feeRows as $r) {
            $ym = $r['ym'];
            if (!isset($feeTrendMap[$ym])) {
                $feeTrendMap[$ym] = ['ym' => $ym, 'income' => 0.0, 'expense' => 0.0];
            }
            $feeTrendMap[$ym][$r['fee_type']] = (float)$r['amt'];
        }

        return json(r_success([
            'overview' => [
                'total_cert'        => (int)$totalCert,
                'cert_normal'       => (int)$normal,
                'cert_soon'         => (int)$soonCnt,
                'cert_expired'      => (int)$expiredCnt,
                'free_cert'         => (int)$freeCert,
                'out_active'        => (int)$outActive,
                'in_stock'          => (int)$inStock,
                'secondment_soon'   => (int)$secSoon,
                'secondment_expired'=> (int)$secExpire,
                'contract_total'    => (int)$contractTotal,
                'contract_exec'     => (int)$contractExec,
                'contract_soon'     => (int)$contractSoon,
                'fee_income'        => $feeIncome,
                'fee_expense'       => $feeExpense,
                'fee_profit'        => $feeIncome - $feeExpense,
                'fee_unsettled'     => $feeUnsettled,
            ],
            'cert_status_dist' => $certStatusDist,
            'cert_type_dist'   => $certTypeDist,
            'expire_trend'     => $expireTrend,
            'company_rank'     => $companyRank,
            'secondment_dist'  => $secondmentDist,
            'fee_trend'        => array_values($feeTrendMap),
        ]));
    }
}
