<?php
namespace app\controller\admin;

use app\service\DataScopeService;
use app\service\ResourceOwnerService;
use think\facade\Db;

/**
 * 资源归属 / 公海 / 共同所有人接口（v2.99.37 P3）
 *
 * 所有归属写操作统一经 ResourceOwnerService，控制器只负责权限判定与参数校验。
 * 客户侧账号（company 角色 / 带企业授权）不允许操作归属，仍按企业边界只读。
 */
class Resource extends AdminController
{
    private function resType(): string
    {
        return ResourceOwnerService::normalizeType((string)$this->request->param('res_type', ''));
    }

    private function ids(): array
    {
        $raw = $this->request->post('res_ids/a', $this->request->post('ids/a', []));
        return array_values(array_unique(array_filter(array_map('intval', (array)$raw))));
    }

    /** 客户侧账号禁止归属写操作 */
    private function denyCompanyBound(): bool
    {
        return !$this->isAdmin() && $this->isCompanyBound();
    }

    /** 单资源可见性：客户账号走企业边界，内部账号走所有人/公海策略 */
    private function canCore(string $type, int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        if ($this->isAdmin()) {
            return true;
        }
        if ($this->isCompanyBound()) {
            if ($type === 'company') {
                return $this->canAccessCompany($id);
            }
            if ($type === 'staff') {
                return $this->canAccessStaff($id);
            }
            return $this->canAccessCert($id);
        }
        return ResourceOwnerService::canAccess($type, $id, $this->request->user ?? []);
    }

    /** 当前策略是否能看到/认领公海 */
    private function canSeePool(): bool
    {
        if ($this->isAdmin()) {
            return true;
        }
        $policy = DataScopeService::resolvePolicy($this->request->user ?? [], DataScopeService::GROUP_CORE);
        return !empty($policy['all']) || $policy['scope'] === 'pool' || !empty($policy['include_pool']);
    }

    /**
     * 公海列表：GET { res_type, keyword, page, limit }
     */
    public function pool()
    {
        $type = $this->resType();
        if ($type === '') {
            return json(r_error('未知资源类型', 400));
        }
        if (!$this->canSeePool()) {
            return json(r_error('当前数据权限不可见公海', 403));
        }
        [$page, $limit] = $this->pageParams();
        $keyword = trim((string)$this->request->param('keyword', ''));

        [$table, $titleCol, $extra] = $this->poolQueryConfig($type);
        $q = Db::name($table)->where('is_pool', 1);
        if ($keyword !== '') {
            $q->where(function ($w) use ($keyword, $titleCol, $type, $extra) {
                $w->where($titleCol, 'like', "%{$keyword}%");
                foreach ($extra as $col) {
                    $w->whereOr($col, 'like', "%{$keyword}%");
                }
            });
        }
        $total = $q->count();
        $rows = $q->field('id,' . $titleCol . ',' . implode(',', $extra) . ',create_time')
            ->order('id', 'desc')->page($page, $limit)->select()->toArray();

        $list = [];
        foreach ($rows as $row) {
            $list[] = [
                'id'         => (int)$row['id'],
                'res_type'   => $type,
                'title'      => (string)($row[$titleCol] ?? ''),
                'subtitle'   => $this->poolSubtitle($type, $row),
                'is_pool'    => 1,
                'owner_names' => '',
                'owner_count' => 0,
                'create_time' => (string)($row['create_time'] ?? ''),
            ];
        }
        return json(r_success(['list' => $list, 'total' => $total]));
    }

    /** 公海列表主字段配置 */
    private function poolQueryConfig(string $type): array
    {
        if ($type === 'company') {
            return ['client_company', 'company_name', ['credit_code', 'contact', 'contact_phone', 'region_path']];
        }
        if ($type === 'staff') {
            return ['staff', 'staff_name', ['phone', 'id_card', 'dept']];
        }
        return ['certificate', 'cert_no', ['cert_name', 'cert_type', 'category']];
    }

    private function poolSubtitle(string $type, array $row): string
    {
        if ($type === 'company') {
            $parts = array_filter([(string)($row['credit_code'] ?? ''), (string)($row['contact'] ?? ''), (string)($row['region_path'] ?? '')]);
            return implode(' · ', $parts);
        }
        if ($type === 'staff') {
            $parts = array_filter([(string)($row['phone'] ?? ''), (string)($row['dept'] ?? '')]);
            return implode(' · ', $parts);
        }
        $parts = array_filter([(string)($row['cert_name'] ?? ''), (string)($row['cert_type'] ?? ''), (string)($row['category'] ?? '')]);
        return implode(' · ', $parts);
    }

    /**
     * 某资源全部所有人：GET { res_type, res_id }
     */
    public function owners()
    {
        $type = $this->resType();
        $id = (int)$this->request->param('res_id', 0);
        if ($type === '' || $id <= 0) {
            return json(r_error('参数错误', 400));
        }
        if (!$this->canCore($type, $id)) {
            return json(r_error('无权查看该资源归属', 403));
        }
        return json(r_success([
            'owners'  => ResourceOwnerService::owners($type, $id),
            'is_pool' => ResourceOwnerService::isPool($type, $id) ? 1 : 0,
        ]));
    }

    /**
     * 移入公海：POST { res_type, res_ids }（本人或 admin）
     */
    public function toPool()
    {
        if ($this->denyCompanyBound()) {
            return json(r_error('客户账号无权操作资源归属', 403));
        }
        $type = $this->resType();
        $ids = $this->ids();
        if ($type === '' || !$ids) {
            return json(r_error('参数错误', 400));
        }
        $remark = trim((string)$this->request->post('remark', ''));
        $allowed = [];
        foreach ($ids as $id) {
            if ($this->canCore($type, $id)) {
                $allowed[] = $id;
            }
        }
        $r = ResourceOwnerService::transfer(
            $type, $ids, 0, (int)$this->request->userId, $this->isAdmin(), $remark ?: '移入公海', $allowed
        );
        $this->log('资源归属', '移入公海', sprintf('%s×%d 条%s', $type, $r['ok'], $r['fail'] ? '，失败 ' . count($r['fail']) . ' 条' : ''));
        return $this->result($r, '已移入公海');
    }

    /**
     * 公海认领：POST { res_type, res_ids }
     */
    public function claim()
    {
        if ($this->denyCompanyBound()) {
            return json(r_error('客户账号无权认领公海资源', 403));
        }
        $type = $this->resType();
        $ids = $this->ids();
        if ($type === '' || !$ids) {
            return json(r_error('参数错误', 400));
        }
        if (!$this->canSeePool()) {
            return json(r_error('当前数据权限不可见公海', 403));
        }
        $uid = (int)$this->request->userId;
        $ok = 0;
        $fail = [];
        foreach ($ids as $id) {
            if (!$this->canCore($type, $id)) {
                $fail[] = "#{$id}：无权认领该资源";
                continue;
            }
            $r = ResourceOwnerService::claim($type, $id, $uid, $uid);
            if ($r['ok']) {
                $ok++;
            } else {
                $fail[] = "#{$id}：" . $r['msg'];
            }
        }
        $this->log('资源归属', '公海认领', sprintf('%s×%d 条', $type, $ok));
        return $this->result(['ok' => $ok, 'fail' => $fail], '认领成功');
    }

    /**
     * 移交：POST { res_type, res_ids, to_user_id, remark }；to_user_id=0 表示移入公海。
     * 普通用户只能交接自己的份额，admin 强制替换全部所有人。
     */
    public function transfer()
    {
        if ($this->denyCompanyBound()) {
            return json(r_error('客户账号无权操作资源归属', 403));
        }
        $type = $this->resType();
        $ids = $this->ids();
        $toUserId = (int)$this->request->post('to_user_id', -1);
        if ($type === '' || !$ids) {
            return json(r_error('参数错误', 400));
        }
        if ($toUserId < 0) {
            return json(r_error('请选择目标所有人或公海', 400));
        }
        $remark = trim((string)$this->request->post('remark', ''));
        $allowed = [];
        foreach ($ids as $id) {
            if ($this->canCore($type, $id)) {
                $allowed[] = $id;
            }
        }
        $r = ResourceOwnerService::transfer(
            $type, $ids, $toUserId, (int)$this->request->userId, $this->isAdmin(), $remark, $allowed
        );
        $this->log('资源归属', '移交', sprintf('%s×%d 条 → %s', $type, $r['ok'], $toUserId > 0 ? '账号 #' . $toUserId : '公海'));
        return $this->result($r, $toUserId > 0 ? '移交成功' : '已移入公海');
    }

    /**
     * 管理员强制指派：POST { res_type, res_ids, to_user_id }，to_user_id=0 表示移入公海。
     */
    public function assign()
    {
        $this->requireAdmin();
        $type = $this->resType();
        $ids = $this->ids();
        $toUserId = (int)$this->request->post('to_user_id', 0);
        if ($type === '' || !$ids) {
            return json(r_error('参数错误', 400));
        }
        if ($toUserId > 0 && !Db::name('sys_user')->where('id', $toUserId)->where('status', 1)->find()) {
            return json(r_error('目标账号不存在或已停用', 400));
        }
        $remark = trim((string)$this->request->post('remark', '管理员指派'));
        $r = ResourceOwnerService::transfer(
            $type, $ids, $toUserId, (int)$this->request->userId, true, $remark, null, 'assign'
        );
        $this->log('资源归属', '强制指派', sprintf('%s×%d 条 → %s', $type, $r['ok'], $toUserId > 0 ? '账号 #' . $toUserId : '公海'));
        return $this->result($r, $toUserId > 0 ? '指派成功' : '已移入公海');
    }

    /**
     * 增加共同所有人：POST { res_type, res_id, user_id }
     */
    public function addOwner()
    {
        if ($this->denyCompanyBound()) {
            return json(r_error('客户账号无权操作资源归属', 403));
        }
        $type = $this->resType();
        $id = (int)$this->request->post('res_id', 0);
        $userId = (int)$this->request->post('user_id', 0);
        if ($type === '' || $id <= 0 || $userId <= 0) {
            return json(r_error('参数错误', 400));
        }
        if (!$this->isAdmin() && !ResourceOwnerService::isOwner($type, $id, (int)$this->request->userId)) {
            return json(r_error('仅现所有人或管理员可增加共同所有人', 403));
        }
        if (ResourceOwnerService::isPool($type, $id)) {
            return json(r_error('公海资源请先认领再增加共有人', 400));
        }
        if (!ResourceOwnerService::addOwner($type, $id, $userId, 'assign', (int)$this->request->userId, false)) {
            return json(r_error('增加失败：账号不存在或已停用', 400));
        }
        ResourceOwnerService::log($type, $id, 0, $userId, (int)$this->request->userId, 'assign', false, '增加共同所有人');
        $this->log('资源归属', '增加共有人', "{$type} #{$id} → #{$userId}");
        return json(r_success(['owners' => ResourceOwnerService::owners($type, $id)], '已增加共同所有人'));
    }

    /**
     * 批量增加共同所有人：POST { res_type, res_ids[], user_ids[] }
     * 保留每条记录原所有人，只新增；公海资源由首个账号自动认领为主所有人。
     */
    public function addOwners()
    {
        if ($this->denyCompanyBound()) {
            return json(r_error('客户账号无权操作资源归属', 403));
        }
        $type = $this->resType();
        $ids = $this->ids();
        $userIds = array_values(array_unique(array_filter(array_map('intval', (array)$this->request->post('user_ids/a', [])))));
        if ($type === '' || !$ids || !$userIds) {
            return json(r_error('参数错误：请勾选记录并选择至少一个共同所有人', 400));
        }
        if (count($ids) > 500) {
            return json(r_error('单次最多批量操作 500 条记录', 400));
        }
        // 可见性白名单（非管理员只能操作自己有权访问的资源；公海按数据策略放行）
        $allowed = [];
        foreach ($ids as $id) {
            if ($this->canCore($type, $id)) {
                $allowed[] = $id;
            }
        }
        if (!$allowed) {
            return json(r_error('所选记录均无权操作', 403));
        }
        $r = ResourceOwnerService::addOwners($type, $ids, $userIds, (int)$this->request->userId, $allowed);
        $this->log('资源归属', '批量增加共有人', sprintf('%s×%d 条，新增归属 %d 个，账号 %s', $type, $r['ok'], $r['added'], implode(',', $userIds)));
        $msg = sprintf('已为 %d 条记录增加共同所有人（新增归属 %d 个）', $r['ok'], $r['added']);
        if (!empty($r['skipped'])) {
            $msg .= "，{$r['skipped']} 条无需变更";
        }
        if (!empty($r['fail'])) {
            $msg .= '，失败 ' . count($r['fail']) . ' 条：' . implode('；', array_slice($r['fail'], 0, 3));
        }
        // 前端响应拦截器成功只返回 data，故把汇总消息一并放进 data，便于页面精确提示
        $r['msg'] = $msg;
        return json(r_success($r, $msg));
    }

    /**
     * 移除共同所有人：POST { res_type, res_id, user_id }（admin 可移除任意，本人可退出）
     */
    public function removeOwner()
    {
        if ($this->denyCompanyBound()) {
            return json(r_error('客户账号无权操作资源归属', 403));
        }
        $type = $this->resType();
        $id = (int)$this->request->post('res_id', 0);
        $userId = (int)$this->request->post('user_id', (int)$this->request->userId);
        if ($type === '' || $id <= 0 || $userId <= 0) {
            return json(r_error('参数错误', 400));
        }
        if (!$this->isAdmin() && $userId !== (int)$this->request->userId) {
            return json(r_error('只能退出自己的共有关系', 403));
        }
        $remark = trim((string)$this->request->post('remark', '退出共有'));
        if (!ResourceOwnerService::removeOwner($type, $id, $userId, (int)$this->request->userId, $remark)) {
            return json(r_error('移除失败：该账号不是此资源所有人', 400));
        }
        $this->log('资源归属', '移除共有人', "{$type} #{$id} → #{$userId}");
        return json(r_success([
            'owners'  => ResourceOwnerService::owners($type, $id),
            'is_pool' => ResourceOwnerService::isPool($type, $id) ? 1 : 0,
        ], ResourceOwnerService::isPool($type, $id) ? '最后一位所有人已退出，资源已转入公海' : '已移除共同所有人'));
    }

    /**
     * 归属变更历史：GET { res_type, res_id?, page, limit }
     */
    public function transferLog()
    {
        $type = $this->resType();
        if ($type === '') {
            return json(r_error('未知资源类型', 400));
        }
        $recordId = (int)$this->request->param('res_id', 0);
        if ($recordId > 0 && !$this->canCore($type, $recordId)) {
            // 已不再拥有该资源的历史所有人仍可查看与自己相关的归属历史
            $uid = (int)$this->request->userId;
            $involved = $this->isAdmin() ? 1 : Db::name('cert_owner_transfer')
                ->where('module', $type)->where('record_id', $recordId)
                ->where(function ($w) use ($uid) {
                    $w->where('operator_id', $uid)->whereOr('from_user_id', $uid)->whereOr('to_user_id', $uid);
                })->count();
            if (!$involved) {
                return json(r_error('无权查看该资源归属历史', 403));
            }
        }
        [$page, $limit] = $this->pageParams();
        $q = Db::name('cert_owner_transfer')->where('module', $type);
        if ($recordId > 0) {
            $q->where('record_id', $recordId);
        }
        if (!$this->isAdmin()) {
            $uid = (int)$this->request->userId;
            $q->where(function ($w) use ($uid) {
                $w->where('operator_id', $uid)->whereOr('from_user_id', $uid)->whereOr('to_user_id', $uid);
            });
        }
        $total = $q->count();
        $rows = $q->order('id', 'desc')->page($page, $limit)->select()->toArray();
        $map = ResourceOwnerService::userMap();
        $actions = [
            'transfer' => '移交', 'assign' => '指派', 'claim' => '认领',
            'to_pool' => '移入公海', 'duplicate' => '查重共有', 'remove' => '退出共有',
        ];
        foreach ($rows as &$row) {
            $row['from_name'] = $map[(int)$row['from_user_id']] ?? '';
            $row['to_name']   = $map[(int)$row['to_user_id']] ?? '';
            $row['operator_name'] = $map[(int)$row['operator_id']] ?? '';
            $row['action_name'] = $actions[(string)($row['action'] ?? 'transfer')] ?? '移交';
            $row['to_pool'] = (int)($row['to_pool'] ?? 0);
        }
        unset($row);
        return json(r_success(['list' => $rows, 'total' => $total]));
    }

    private function result(array $r, string $successMsg)
    {
        $msg = $successMsg . ' ' . (int)$r['ok'] . ' 条';
        if (!empty($r['fail'])) {
            $msg .= '，' . count($r['fail']) . ' 条失败：' . implode('；', array_slice($r['fail'], 0, 3));
        }
        return json(r_success(['ok' => (int)$r['ok'], 'fail' => $r['fail'] ?? []], $msg));
    }
}
