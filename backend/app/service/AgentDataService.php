<?php
namespace app\service;

use app\model\AlertLog;
use app\model\Certificate as CertificateModel;
use app\model\ClientCompany;
use app\model\Contract as ContractModel;
use app\model\Staff as StaffModel;
use think\facade\Db;

/**
 * Agent 数据访问层（v2.82）
 *
 * 所有查询都复用系统现有的数据权限口径：
 *  - 管理员：不限制；
 *  - 有企业授权的账号：按授权企业；
 *  - company 角色未授权：空集；
 *  - 内部办公账号：按部门数据范围（DeptScopeService）。
 *
 * 这样 Agent 看到的数据与「密钥绑定账号」在 PC 上看到的完全一致，
 * 不会因为接入 Agent 而扩大数据范围。
 */
class AgentDataService
{
    /** 允许的证书状态 */
    public const CERT_STATUS = [0 => '已过期', 1 => '正常', 2 => '挂失', 3 => '注销'];

    /**
     * 可见企业 ID：null=不限制，[]=无权限
     * @return array<int>|null
     */
    public static function visibleCompanyIds(array $ctx): ?array
    {
        if (!empty($ctx['is_admin'])) {
            return null;
        }
        $rel = array_values(array_filter(array_map('intval', (array)($ctx['company_ids'] ?? []))));
        if ($rel) {
            return $rel;
        }
        if ((string)($ctx['role_code'] ?? '') === 'company') {
            return [];
        }
        // v2.99.36：内部办公账号核心资源按「所有人」，不再按部门换算
        return null;
    }

    /** 企业维度过滤 */
    public static function applyCompanyScope($query, array $ctx, string $field = 'id')
    {
        $ids = self::visibleCompanyIds($ctx);
        if ($ids !== null) {
            $query->whereIn($field, $ids ?: [0]);
        }
        return $query;
    }

    /** 人员维度过滤（v2.99.36：内部账号按「所有人」） */
    public static function applyStaffScope($query, array $ctx)
    {
        if (!empty($ctx['is_admin'])) {
            return $query;
        }
        $ids = array_values(array_filter(array_map('intval', (array)($ctx['company_ids'] ?? []))));
        if ($ids) {
            $staffIds = Db::table('staff_company_rel')->whereIn('company_id', $ids)->column('staff_id');
            $query->whereIn('id', array_map('intval', $staffIds ?: [0]));
        } elseif ((string)($ctx['role_code'] ?? '') === 'company') {
            $query->whereIn('id', [0]);
        }
        return $query;
    }

    /** 证书维度过滤（v2.99.36：内部账号按「所有人」） */
    public static function applyCertScope($query, array $ctx, string $field = 'id')
    {
        if (!empty($ctx['is_admin'])) {
            return $query;
        }
        $ids = array_values(array_filter(array_map('intval', (array)($ctx['company_ids'] ?? []))));
        if ($ids) {
            $certIds = SecondmentService::certIdsOf($ids, 1);
            $query->whereIn($field, $certIds ?: [0]);
        } elseif ((string)($ctx['role_code'] ?? '') === 'company') {
            $query->whereIn($field, [0]);
        }
        return $query;
    }

    /**
     * 「其他模块」过滤（v2.99.36）：客户账号按企业，内部账号按创建人 create_by。
     */
    public static function applyModuleScope($query, array $ctx, string $col = 'create_by', string $companyCol = 'company_id')
    {
        if (!empty($ctx['is_admin'])) {
            return $query;
        }
        $ids = array_values(array_filter(array_map('intval', (array)($ctx['company_ids'] ?? []))));
        if ($ids || (string)($ctx['role_code'] ?? '') === 'company') {
            return self::applyCompanyScope($query, $ctx, $companyCol);
        }
        $user = (array)($ctx['user'] ?? []);
        if (empty($user['role_code'])) {
            $user['role_code'] = (string)($ctx['role_code'] ?? '');
        }
        return DataScopeService::applyCreatorScope($query, $user, $col);
    }

    /** 分页参数：Agent 侧最多 100 条，避免拖垮数据库 */
    public static function pageParams(array $p): array
    {
        $page  = max(1, (int)($p['page'] ?? 1));
        $limit = (int)($p['limit'] ?? 20);
        $limit = $limit < 1 ? 20 : min($limit, 100);
        return [$page, $limit];
    }

    // ------------------------------------------------------------------
    // 只读查询
    // ------------------------------------------------------------------

    public static function overview(array $ctx): array
    {
        $today = date('Y-m-d');
        $soon  = date('Y-m-d', strtotime('+30 days'));

        $companyQ = ClientCompany::where('status', 1);
        self::applyCompanyScope($companyQ, $ctx, 'id');

        $staffQ = StaffModel::where('status', 1);
        self::applyStaffScope($staffQ, $ctx);

        $certQ = CertificateModel::where('id', '>', 0);
        self::applyCertScope($certQ, $ctx);

        $expireQ = CertificateModel::where('cert_status', 1)
            ->whereBetween('expire_date', [$today, $soon]);
        self::applyCertScope($expireQ, $ctx);

        $contractQ = ContractModel::where('status', 1);
        self::applyModuleScope($contractQ, $ctx, 'create_by', 'company_id');

        $alertQ = AlertLog::where('is_read', 0);
        self::applyCompanyScope($alertQ, $ctx, 'company_id');

        return [
            'companies'        => $companyQ->count(),
            'staff'            => $staffQ->count(),
            'certificates'     => $certQ->count(),
            'contracts_active' => $contractQ->count(),
            'expiring_30d'     => $expireQ->count(),
            'alerts_unread'    => $alertQ->count(),
            'as_of'            => date('Y-m-d H:i:s'),
        ];
    }

    public static function certificates(array $ctx, array $p): array
    {
        [$page, $limit] = self::pageParams($p);
        $q = CertificateModel::with(['staff'])->orderRaw('expire_date IS NULL ASC, expire_date ASC');
        self::applyCertScope($q, $ctx);

        $keyword = trim((string)($p['keyword'] ?? ''));
        if ($keyword !== '') {
            $q->where(function ($w) use ($keyword) {
                $w->whereLike('cert_name', "%{$keyword}%")
                  ->whereOr('cert_no', 'like', "%{$keyword}%")
                  ->whereOr('cert_type', 'like', "%{$keyword}%")
                  ->whereOr('category', 'like', "%{$keyword}%");
            });
        }
        $staffId = (int)($p['staff_id'] ?? 0);
        if ($staffId > 0) {
            $q->where('staff_id', $staffId);
        }
        $companyId = (int)($p['company_id'] ?? 0);
        if ($companyId > 0) {
            $certIds = SecondmentService::certIdsOf([$companyId], 1);
            $q->whereIn('id', $certIds ?: [0]);
        }
        $certType = trim((string)($p['cert_type'] ?? ''));
        if ($certType !== '') {
            $q->where(function ($w) use ($certType) {
                $w->where('cert_type', $certType)->whereOr('category', $certType);
            });
        }
        if (isset($p['cert_status']) && $p['cert_status'] !== '') {
            $q->where('cert_status', (int)$p['cert_status']);
        }
        if (isset($p['social_status']) && $p['social_status'] !== '') {
            $q->where('social_status', (int)$p['social_status']);
        }
        $days = (int)($p['expire_days'] ?? 0);
        if ($days > 0) {
            $q->where('cert_status', 1)
              ->whereBetween('expire_date', [date('Y-m-d'), date('Y-m-d', strtotime("+{$days} days"))]);
        }
        if ((int)($p['only_free'] ?? 0) === 1) {
            $busyIds = Db::name('cert_secondment')->where('status', 1)->column('cert_id');
            $q->whereNotIn('id', $busyIds ?: [0]);
        }

        $total = $q->count();
        $list  = $q->page($page, $limit)->select()->toArray();
        SecondmentService::attach($list);

        $user = (array)($ctx['user'] ?? []);
        $out = [];
        foreach ($list as $item) {
            if (isset($item['staff']) && is_array($item['staff'])) {
                unset($item['staff']['id_card_hash']);
            }
            $item = MaskService::certFor($item, $user);
            // 顶层冗余持证人信息，便于 Agent 直接使用
            $item['staff_name']  = $item['staff']['staff_name'] ?? '';
            $item['staff_phone'] = MaskService::phone($item['staff']['phone'] ?? '');
            $out[] = $item;
        }
        return ['list' => $out, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    public static function certificate(array $ctx, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $q = CertificateModel::with(['staff']);
        self::applyCertScope($q, $ctx);
        $row = $q->where('id', $id)->find();
        if (!$row) {
            return null;
        }
        $data = $row->toArray();
        $tmp = [$data];
        SecondmentService::attach($tmp);
        $data = $tmp[0];
        if (isset($data['staff']) && is_array($data['staff'])) {
            unset($data['staff']['id_card_hash']);
        }
        return MaskService::certFor($data, (array)($ctx['user'] ?? []));
    }

    public static function staffList(array $ctx, array $p): array
    {
        \app\service\IdCardSchemaService::ensure();
        [$page, $limit] = self::pageParams($p);
        $q = StaffModel::order('id', 'desc');
        self::applyStaffScope($q, $ctx);

        $keyword = trim((string)($p['keyword'] ?? ''));
        if ($keyword !== '') {
            // v2.99.59：身份证密文落库，仅完整号码按盲索引精确命中
            $norm = \app\service\IdCardService::normalize($keyword);
            $hash = preg_match('/^\d{15}$|^\d{17}[\dX]$/', $norm)
                ? \app\service\IdCardService::hash($norm) : '';
            $q->where(function ($w) use ($keyword, $hash, $norm) {
                $w->whereLike('staff_name', "%{$keyword}%")
                  ->whereOr('phone', 'like', "%{$keyword}%");
                if ($hash !== '') {
                    $w->whereOr('id_card_hash', $hash);
                }
                // 兼容加密前的明文历史数据
                if ($norm !== '') {
                    $w->whereOr('id_card', $norm);
                }
            });
        }
        if (isset($p['status']) && $p['status'] !== '') {
            $q->where('status', (int)$p['status']);
        }
        $companyId = (int)($p['company_id'] ?? 0);
        if ($companyId > 0) {
            $relIds = Db::table('staff_company_rel')->where('company_id', $companyId)->column('staff_id');
            $secIds = Db::name('cert_secondment')->where('company_id', $companyId)->where('status', 1)->column('staff_id');
            $ids = array_values(array_unique(array_merge(array_map('intval', $relIds), array_map('intval', $secIds))));
            $q->whereIn('id', $ids ?: [0]);
        }

        $total = $q->count();
        $list  = $q->page($page, $limit)->select()->toArray();
        $user = (array)($ctx['user'] ?? []);
        $out = [];
        foreach ($list as $item) {
            unset($item['id_card_hash']);
            $out[] = MaskService::staffFor($item, $user);
        }
        return ['list' => $out, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    public static function staff(array $ctx, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $q = StaffModel::where('id', $id);
        self::applyStaffScope($q, $ctx);
        $row = $q->find();
        if (!$row) {
            return null;
        }
        $data = $row->toArray();
        unset($data['id_card_hash']);
        $data['companies'] = Db::table('staff_company_rel')->alias('r')
            ->join('client_company c', 'c.id = r.company_id', 'LEFT')
            ->where('r.staff_id', $id)
            ->field('c.id, c.company_name, c.short_name')
            ->select()->toArray();
        return MaskService::staffFor($data, (array)($ctx['user'] ?? []));
    }

    public static function companies(array $ctx, array $p): array
    {
        [$page, $limit] = self::pageParams($p);
        $q = ClientCompany::order('id', 'desc');
        self::applyCompanyScope($q, $ctx, 'id');

        $keyword = trim((string)($p['keyword'] ?? ''));
        if ($keyword !== '') {
            $q->where(function ($w) use ($keyword) {
                $w->whereLike('company_name', "%{$keyword}%")
                  ->whereOr('short_name', 'like', "%{$keyword}%")
                  ->whereOr('credit_code', 'like', "%{$keyword}%");
            });
        }
        if (isset($p['status']) && $p['status'] !== '') {
            $q->where('status', (int)$p['status']);
        }

        $total = $q->count();
        $list  = $q->page($page, $limit)->select()->toArray();
        if ($list) {
            $ids = array_column($list, 'id');
            $rows = Db::name('cert_secondment')
                ->field('company_id, count(*) as sec_count, sum(fee_amount) as sec_fee')
                ->whereIn('company_id', $ids)->where('status', 1)
                ->group('company_id')->select()->toArray();
            $map = [];
            foreach ($rows as $r) {
                $map[(int)$r['company_id']] = $r;
            }
            foreach ($list as &$item) {
                $s = $map[(int)$item['id']] ?? null;
                $item['sec_count'] = (int)($s['sec_count'] ?? 0);
                $item['sec_fee']   = (float)($s['sec_fee'] ?? 0);
            }
            unset($item);
        }
        return ['list' => $list, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    public static function company(array $ctx, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $q = ClientCompany::where('id', $id);
        self::applyCompanyScope($q, $ctx, 'id');
        $row = $q->find();
        if (!$row) {
            return null;
        }
        $data = $row->toArray();
        $data['staff_count'] = (int)Db::table('staff_company_rel')->where('company_id', $id)->count();
        $data['active_secondments'] = (int)Db::name('cert_secondment')->where('company_id', $id)->where('status', 1)->count();
        $data['contracts'] = Db::name('contract')->where('company_id', $id)->order('id', 'desc')->select()->toArray();
        return $data;
    }

    public static function contracts(array $ctx, array $p): array
    {
        [$page, $limit] = self::pageParams($p);
        $q = ContractModel::with(['company'])->order('id', 'desc');
        self::applyModuleScope($q, $ctx, 'create_by', 'company_id');

        $keyword = trim((string)($p['keyword'] ?? ''));
        if ($keyword !== '') {
            $q->where(function ($w) use ($keyword) {
                $w->whereLike('contract_no', "%{$keyword}%")
                  ->whereOr('title', 'like', "%{$keyword}%")
                  ->whereOr('party_a', 'like', "%{$keyword}%")
                  ->whereOr('party_b', 'like', "%{$keyword}%");
            });
        }
        $companyId = (int)($p['company_id'] ?? 0);
        if ($companyId > 0) {
            $q->where('company_id', $companyId);
        }
        if (isset($p['status']) && $p['status'] !== '') {
            $q->where('status', (int)$p['status']);
        }
        $expiring = (int)($p['expiring'] ?? 0);
        if ($expiring > 0) {
            $q->where('status', 1)->whereBetween('end_date', [
                date('Y-m-d'),
                date('Y-m-d', strtotime("+{$expiring} days")),
            ]);
        }

        $total = $q->count();
        $list  = $q->page($page, $limit)->select()->toArray();
        return ['list' => $list, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    public static function contract(array $ctx, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $q = ContractModel::with(['company', 'secondment' => function ($sq) {
            $sq->with(['certificate', 'staff']);
        }]);
        self::applyModuleScope($q, $ctx, 'create_by', 'company_id');
        $row = $q->where('id', $id)->find();
        if (!$row) {
            return null;
        }
        $data = $row->toArray();
        $user = (array)($ctx['user'] ?? []);
        if (!empty($data['secondment']['certificate']) && is_array($data['secondment']['certificate'])) {
            $data['secondment']['certificate'] = MaskService::cert($data['secondment']['certificate'], $user);
        }
        if (!empty($data['secondment']['staff']) && is_array($data['secondment']['staff'])) {
            $data['secondment']['staff'] = MaskService::staff($data['secondment']['staff'], false, $user);
        }
        return $data;
    }

    public static function expiring(array $ctx, array $p): array
    {
        $days = (int)($p['days'] ?? 30);
        $days = $days < 1 ? 30 : min($days, 365);
        [$page, $limit] = self::pageParams($p);

        $q = CertificateModel::with(['staff'])
            ->where('cert_status', 1)
            ->whereBetween('expire_date', [date('Y-m-d'), date('Y-m-d', strtotime("+{$days} days"))])
            ->orderRaw('expire_date IS NULL ASC, expire_date ASC');
        self::applyCertScope($q, $ctx);

        $total = $q->count();
        $list  = $q->page($page, $limit)->select()->toArray();
        SecondmentService::attach($list);
        $user = (array)($ctx['user'] ?? []);
        $out = [];
        foreach ($list as $item) {
            $out[] = MaskService::certFor($item, $user);
        }
        return ['list' => $out, 'total' => $total, 'days' => $days, 'page' => $page, 'limit' => $limit];
    }

    // ------------------------------------------------------------------
    // 受控写入
    // ------------------------------------------------------------------

    /**
     * 更新证书状态
     * @return array{ok:bool,code:int,msg:string,data?:array}
     */
    public static function updateCertStatus(array $ctx, int $id, int $status, string $remark, string $actor): array
    {
        if ($id <= 0) {
            return ['ok' => false, 'code' => 400, 'msg' => '缺少证书 ID'];
        }
        if (!isset(self::CERT_STATUS[$status])) {
            return ['ok' => false, 'code' => 400, 'msg' => 'cert_status 只能是 0/1/2/3'];
        }
        $q = CertificateModel::where('id', $id);
        self::applyCertScope($q, $ctx);
        $cert = $q->find();
        if (!$cert) {
            return ['ok' => false, 'code' => 404, 'msg' => '证书不存在或无权访问'];
        }
        $old = (int)$cert->cert_status;
        if ($old === $status) {
            return ['ok' => true, 'code' => 0, 'msg' => '状态未变化', 'data' => [
                'id' => $id, 'old_status' => $old, 'new_status' => $status,
            ]];
        }
        CertificateModel::where('id', $id)->update([
            'cert_status' => $status,
            'update_time' => date('Y-m-d H:i:s'),
        ]);
        CertStatusLogService::cert($id, $old, $status, $actor, mb_substr(trim($remark), 0, 200));
        OperationLogService::record('证照台账', 'Agent 更新状态', "证书 #{$id}：{$old} → {$status}（{$actor}）");

        return ['ok' => true, 'code' => 0, 'msg' => '已更新', 'data' => [
            'id' => $id, 'old_status' => $old, 'new_status' => $status,
            'old_status_text' => self::CERT_STATUS[$old] ?? '未知',
            'new_status_text' => self::CERT_STATUS[$status] ?? '未知',
        ]];
    }

    /**
     * 追加跟进记录
     * @return array{ok:bool,code:int,msg:string,data?:array}
     */
    public static function addFollow(array $ctx, array $d, int $userId, string $actor): array
    {
        $module = strtolower(trim((string)($d['module'] ?? '')));
        if (!in_array($module, ['company', 'certificate', 'staff'], true)) {
            return ['ok' => false, 'code' => 400, 'msg' => 'module 只能是 company / certificate / staff'];
        }
        $recordId = (int)($d['record_id'] ?? 0);
        if ($recordId <= 0) {
            return ['ok' => false, 'code' => 400, 'msg' => '缺少 record_id'];
        }
        $content = trim((string)($d['content'] ?? ''));
        if ($content === '') {
            return ['ok' => false, 'code' => 400, 'msg' => '跟进内容不能为空'];
        }
        if (mb_strlen($content, 'UTF-8') > 2000) {
            return ['ok' => false, 'code' => 400, 'msg' => '跟进内容最多 2000 字'];
        }
        $followType = trim((string)($d['follow_type'] ?? '其他'));
        if (!in_array($followType, CrmService::FOLLOW_TYPES, true)) {
            $followType = '其他';
        }
        $next = trim((string)($d['next_follow_date'] ?? ''));
        if ($next !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $next)) {
            return ['ok' => false, 'code' => 400, 'msg' => 'next_follow_date 格式应为 YYYY-MM-DD'];
        }

        // 对象级权限：必须能看到该记录
        if ($module === 'company') {
            $q = ClientCompany::where('id', $recordId);
            self::applyCompanyScope($q, $ctx, 'id');
        } elseif ($module === 'certificate') {
            $q = CertificateModel::where('id', $recordId);
            self::applyCertScope($q, $ctx);
        } else {
            $q = StaffModel::where('id', $recordId);
            self::applyStaffScope($q, $ctx);
        }
        if (!$q->find()) {
            return ['ok' => false, 'code' => 404, 'msg' => '记录不存在或无权访问'];
        }

        $id = CrmService::addFollow($userId, $module, $recordId, $followType, $content, $next);
        OperationLogService::record('客户跟进', 'Agent 追加跟进', "{$module} #{$recordId}（{$actor}）");

        return ['ok' => true, 'code' => 0, 'msg' => '已记录', 'data' => [
            'id' => $id, 'module' => $module, 'record_id' => $recordId,
            'follow_type' => $followType, 'next_follow_date' => $next,
        ]];
    }
}
