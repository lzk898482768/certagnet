<?php
namespace app\controller\admin;

use app\service\ConflictService;
use app\service\PersonProfileService;

/**
 * 冲突中心
 *
 * 把「谁有证、在哪用、用到何时、有无冲突」中的最后一环可视化：
 * 列出全局互斥冲突与重复占用冲突，支持按类型筛选与跳转排查。
 */
class Conflict extends AdminController
{
    /** 冲突类型 + 级别元数据 */
    public function index()
    {
        $data = ConflictService::listConflicts(['limit' => 200]);
        return json(r_success([
            'types'   => ConflictService::TYPES,
            'total'   => $data['total'],
            'items'   => $data['items'],
        ]));
    }

    /** 冲突清单（支持分页与类型筛选，供冲突中心页使用） */
    public function list()
    {
        [$page, $limit] = $this->pageParams();
        $type = trim((string)$this->request->param('type', ''));
        if ($type !== '' && !isset(ConflictService::TYPES[$type])) {
            return json(r_error('冲突类型不合法', 400));
        }
        $data = ConflictService::listConflicts(['limit' => 1000, 'type' => $type]);
        $items = $data['items'];
        $total = count($items);
        $slice = array_slice($items, ($page - 1) * $limit, $limit);

        return json(r_success(['list' => $slice, 'total' => $total]));
    }

    /**
     * 全量体检（冲突中心「立即体检」）
     * 输出 summary（按类型/级别汇总）+ items（明细）+ scanned_at；
     * v2.56：结果持久化到 conflict_scan，刷新后仍可查看最近一次体检。
     */
    public function scan()
    {
        PersonProfileService::ensureTables();
        $data = ConflictService::scanAll(500);
        ConflictService::saveScan($data, (int)$this->request->userId);
        $this->log('冲突中心', '体检', "共 {$data['total']} 项，阻断 {$data['summary']['blocked']} 项");
        return json(r_success($data));
    }

    /**
     * v2.56：读取最近一次体检结果（刷新后恢复上次体检的冲突清单）
     */
    public function scanResult()
    {
        $row = ConflictService::latestScan();
        if (!$row) {
            return json(r_success([
                'summary'    => null,
                'items'      => [],
                'total'      => 0,
                'scanned_at' => '',
            ]));
        }
        return json(r_success([
            'summary'    => json_decode((string)($row['summary'] ?? '{}'), true) ?: [],
            'items'      => json_decode((string)($row['items'] ?? '[]'), true) ?: [],
            'total'      => (int)$row['total'],
            'scanned_at' => (string)($row['scanned_at'] ?? ''),
        ]));
    }

    /**
     * 单证书冲突检测
     */
    public function checkCert()
    {
        $certId    = (int)$this->request->param('cert_id', 0);
        $startDate = trim((string)$this->request->param('start_date', ''));
        $endDate   = trim((string)$this->request->param('end_date', ''));
        $projectId = (int)$this->request->param('project_id', 0);

        if (!$certId) {
            return json(r_error('请选择证书', 400));
        }
        PersonProfileService::ensureTables();

        $conflicts = ConflictService::checkCert($certId, [
            'start_date' => $startDate,
            'end_date'   => $endDate,
            'project_id' => $projectId,
        ]);

        return json(r_success([
            'items'     => $conflicts,
            'total'     => count($conflicts),
            'blocked'   => self::hasBlocked($conflicts),
        ]));
    }

    /**
     * 单人员冲突检测（不限区间，汇总该人员所有冲突）
     */
    public function checkStaff()
    {
        $staffId = (int)$this->request->param('staff_id', 0);
        if (!$staffId) {
            return json(r_error('请选择人员', 400));
        }
        PersonProfileService::ensureTables();

        $conflicts = ConflictService::checkStaff($staffId);
        return json(r_success([
            'items'   => $conflicts,
            'total'   => count($conflicts),
            'blocked' => self::hasBlocked($conflicts),
        ]));
    }

    /**
     * v2.55：冲突规则设置读取
     */
    public function rules()
    {
        return json(r_success([
            'rules' => ConflictService::rules(),
        ]));
    }

    /**
     * v2.55：保存冲突规则设置（仅管理员）
     */
    public function rulesSave()
    {
        $this->requireAdmin();
        $rules = $this->request->post('rules/a', []);
        if (!is_array($rules) || !$rules) {
            return json(r_error('无有效规则数据', 400));
        }
        $saved = ConflictService::saveRules($rules);
        $this->log('冲突中心', '更新', '冲突规则设置');
        return json(r_success(['rules' => $saved], '冲突规则已保存'));
    }

    /**
     * v2.99.14：互斥组列表（含停用）+ 可选证书专业名
     */
    public function exclusiveGroups()
    {
        return json(r_success([
            'groups'     => ConflictService::exclusiveGroupsAll(),
            'cert_names' => ConflictService::certNameOptions(),
        ]));
    }

    /**
     * v2.99.14：新增/保存互斥组（仅管理员）
     */
    public function exclusiveGroupSave()
    {
        $this->requireAdmin();
        $data = [
            'id'         => (int)$this->request->post('id', 0),
            'group_name' => (string)$this->request->post('group_name', ''),
            'members'    => $this->request->post('members', []),
            'remark'     => (string)$this->request->post('remark', ''),
            'status'     => (int)$this->request->post('status', 1),
        ];
        try {
            $group = ConflictService::saveExclusiveGroup($data);
        } catch (\InvalidArgumentException $e) {
            return json(r_error($e->getMessage(), 400));
        }
        $this->log('冲突中心', '更新', '互斥组：' . ($group['group_name'] ?? ''));
        return json(r_success(['group' => $group], '互斥组已保存'));
    }

    /**
     * v2.99.14：删除互斥组（仅管理员）
     */
    public function exclusiveGroupDelete()
    {
        $this->requireAdmin();
        $id = (int)$this->request->post('id', 0);
        if (!$id) {
            return json(r_error('请选择要删除的互斥组', 400));
        }
        if (!ConflictService::deleteExclusiveGroup($id)) {
            return json(r_error('互斥组不存在或已被删除', 400));
        }
        $this->log('冲突中心', '删除', '互斥组#' . $id);
        return json(r_success([], '互斥组已删除'));
    }

    /** 是否存在阻断级冲突 */
    protected static function hasBlocked(array $conflicts): bool
    {
        foreach ($conflicts as $c) {
            if ((int)($c['level'] ?? 0) >= 2) {
                return true;
            }
        }
        return false;
    }
}