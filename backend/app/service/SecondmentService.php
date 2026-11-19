<?php
namespace app\service;

use app\model\CertSecondment;
use think\facade\Db;

/**
 * 借调服务：证书的所属企业不再写死在 certificate 上，
 * 而是由「生效中的借调记录」决定，这里统一封装查询与装配逻辑。
 */
class SecondmentService
{
    /**
     * 取一批证书当前生效中的借调记录
     * @return array [cert_id => ['company_id'=>..,'company_name'=>..,'start_date'=>..,'end_date'=>..,...]]
     */
    public static function activeMap(array $certIds): array
    {
        $certIds = array_values(array_filter(array_unique(array_map('intval', $certIds))));
        if (!$certIds) {
            return [];
        }
        $rows = CertSecondment::alias('s')
            ->join('client_company c', 'c.id = s.company_id', 'LEFT')
            ->whereIn('s.cert_id', $certIds)
            ->where('s.status', 1)
            ->field('s.*, c.company_name')
            ->select()->toArray();
        $map = [];
        foreach ($rows as $r) {
            // 同一证书理论上只有一条生效记录，取最新一条兜底
            $map[(int)$r['cert_id']] = $r;
        }
        return $map;
    }

    /**
     * 给证书列表装配当前借调信息（原地修改）
     */
    public static function attach(array &$list, string $pk = 'id'): void
    {
        if (!$list) {
            return;
        }
        $ids = [];
        foreach ($list as $item) {
            $id = (int)($item[$pk] ?? 0);
            if ($id) {
                $ids[] = $id;
            }
        }
        $map = self::activeMap($ids);
        foreach ($list as &$item) {
            $id   = (int)($item[$pk] ?? 0);
            $row  = $map[$id] ?? null;
            $item['secondment_id']    = $row['id'] ?? 0;
            $item['company_id']       = $row['company_id'] ?? 0;
            $item['company_name']     = $row['company_name'] ?? '';
            $item['secondment_start'] = $row['start_date'] ?? '';
            $item['secondment_end']   = $row['end_date'] ?? '';
            $item['secondment_status']      = $row ? (int)$row['status'] : null;
            $item['secondment_status_text'] = $row ? self::statusText((int)$row['status']) : '未借调';
            $item['secondment_fee']   = $row['fee_amount'] ?? 0;
            $item['secondment_remain_days'] = $row ? self::remainDays($row['end_date'] ?? '') : null;
        }
        unset($item);
    }

    /** 某证书当前借调企业ID，0 表示空闲未被借调 */
    public static function companyIdOf(int $certId): int
    {
        $row = CertSecondment::where('cert_id', $certId)->where('status', 1)->find();
        return $row ? (int)$row->company_id : 0;
    }

    /** 某企业借调中的证书ID集合 */
    public static function certIdsOf(array $companyIds, int $status = 1): array
    {
        $companyIds = array_values(array_filter(array_map('intval', $companyIds)));
        if (!$companyIds) {
            return [];
        }
        $q = Db::name('cert_secondment')->whereIn('company_id', $companyIds);
        if ($status >= 0) {
            $q->where('status', $status);
        }
        return array_map('intval', $q->column('cert_id'));
    }

    /** 校验证书是否可借出（未被其他生效的「借出」记录占用） */
    public static function isAvailable(int $certId, int $exceptId = 0): bool
    {
        $q = CertSecondment::where('cert_id', $certId)
            ->where('direction', 1)   // 仅「借出」占用证书
            ->where('status', 1);
        if ($exceptId) {
            $q->where('id', '<>', $exceptId);
        }
        return $q->count() === 0;
    }

    /** 证书是否处于「在库」状态（存在生效的借入记录，可作为可借出的商品库存） */
    public static function isInStock(int $certId): bool
    {
        return CertSecondment::where('cert_id', $certId)
            ->where('direction', 2)
            ->where('status', 1)
            ->count() > 0;
    }

    /** 已到期但仍标记为「借调中」的记录 */
    public static function expiredActives(string $date = ''): array
    {
        $date = $date ?: date('Y-m-d');
        return CertSecondment::with(['certificate', 'company', 'staff'])
            ->where('status', 1)
            ->where('end_date', '<>', null)
            ->where('end_date', '<', $date)
            ->select()->toArray();
    }

    /**
     * 「已到期」统一口径：已标记「已到期」(status=2)，或仍为「借调中」但结束日期已过。
     * 兼容定时任务未运行/漏跑的场景，避免逾期记录被统计漏掉。
     */
    public static function scopeExpired($query, string $date = '')
    {
        $date = $date ?: date('Y-m-d');
        return $query->where(function ($w) use ($date) {
            $w->where('status', 2)
              ->whereOr(function ($w2) use ($date) {
                  $w2->where('status', 1)
                     ->whereNotNull('end_date')
                     ->where('end_date', '<', $date);
              });
        });
    }

    /** 已到期数量（口径见 scopeExpired） */
    public static function expiredCount($query, string $date = ''): int
    {
        return (int)self::scopeExpired(clone $query, $date)->count();
    }

    /**
     * 「即将到期」筛选：借调中且结束日期在 N 天内（含已逾期未处理的记录），
     * 避免勾选「只看即将到期」时看不到已经过期的记录。
     */
    public static function scopeExpiring($query, int $days, string $date = '')
    {
        $date = $date ?: date('Y-m-d');
        $soon = date('Y-m-d', strtotime('+' . max(0, $days) . ' days', strtotime($date)));
        return $query->where('status', 1)
            ->whereNotNull('end_date')
            ->where('end_date', '<=', $soon);
    }

    public static function statusText(int $status): string
    {
        $map = [1 => '借调中', 0 => '已归还', 2 => '已到期'];
        return $map[$status] ?? '未知';
    }

    public static function remainDays(?string $endDate): ?int
    {
        if (!$endDate) {
            return null;
        }
        $end = strtotime($endDate);
        if ($end === false) {
            return null;
        }
        return (int)ceil(($end - strtotime(date('Y-m-d'))) / 86400);
    }
}
