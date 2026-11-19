<?php
namespace app\controller\admin;

use app\service\DeptScopeService;
use app\service\ExcelService;
use app\service\GovProgressService;
use think\facade\Db;

/**
 * 政务网申报进度（v2.62）
 *
 *  - GET  /admin/gov-progress             列表
 *  - GET  /admin/gov-progress/meta        业务类型 / 状态枚举
 *  - GET  /admin/gov-progress/detail      详情（含环节）
 *  - GET  /admin/gov-progress/export      导出
 *  - POST /admin/gov-progress/save        新增 / 编辑（保存后立即同步一次）
 *  - POST /admin/gov-progress/delete      删除
 *  - POST /admin/gov-progress/batch-delete批量删除
 *  - POST /admin/gov-progress/sync        立即同步（单条 / 多条）
 *  - POST /admin/gov-progress/sync-all    同步当前数据范围内的全部未办结申报
 *
 * 数据权限：admin 全量；其他岗位按部门数据范围（dept_tree/dept）或本人（self）。
 * 写入权限：admin / cert_manager / project_officer；viewer 只读。
 */
class GovProgress extends AdminController
{
    /**
     * v2.99.35：移除硬编码角色码白名单，访问权统一交给 RBAC——
     * 读由菜单 59（PermAuth 菜单级）控制，写由 gov:* 权限码（PermAuth 功能级）控制。
     * 保留空方法壳，避免控制器内大量调用点改动；后续阶段可直接删除。
     */
    protected function requireRead(): void
    {
        // 菜单级守卫已在 PermAuth 完成，这里不再做角色码限制
    }

    protected function requireWrite(): void
    {
        // 写操作权限码（gov:create/edit/sync/import 等）已在 PermAuth 校验
    }

    /** 数据范围注入（v2.99.36）：申报进度属「其他模块」，内部账号按创建人 create_by 过滤 */
    protected function scopeGov($query)
    {
        return \app\service\DataScopeService::applyCreatorScope($query, $this->request->user ?? [], 'create_by');
    }

    protected function canAccessGov(array $row): bool
    {
        $user = $this->request->user ?? [];
        if (($user['role_code'] ?? '') === 'admin') {
            return true;
        }
        // v2.99.36：其他模块按创建人；角色/用户策略放宽为 all 时不限制
        $policy = \app\service\DataScopeService::resolvePolicy($user, \app\service\DataScopeService::GROUP_OTHER);
        if ($policy['all']) {
            return true;
        }
        return (int)($row['create_by'] ?? 0) === (int)($user['id'] ?? 0);
    }

    protected function assertGovAccess(array $row): void
    {
        if (!$this->canAccessGov($row)) {
            throw new \think\exception\HttpResponseException(json(r_error('无权访问该申报记录', 403)));
        }
    }

    public function index()
    {
        $this->requireRead();
        GovProgressService::ensureTables();

        [$page, $limit] = $this->pageParams();
        $q = $this->scopeGov(Db::name('gov_progress'));
        $this->applyFilters($q);

        $total = (clone $q)->count();
        $list  = (clone $q)
            ->orderRaw($this->listOrder())
            ->page($page, $limit)->select()->toArray();
        GovProgressService::attachRelations($list);
        foreach ($list as &$row) {
            GovProgressService::decorate($row);
        }
        unset($row);

        return json(r_success([
            'list'     => $list,
            'total'    => $total,
            'stat'     => $this->stat(),
            'last_run' => GovProgressService::lastRunAt(),
        ]));
    }

    /**
     * v2.95：列表 / 导出统一排序。
     *
     * 已归档记录按办结时间倒序，最新办结的排在最上面；
     * 缺少办结时间的历史数据回退到归档时间 / 更新时间，避免排到最后。
     */
    protected function listOrder(): string
    {
        return 'is_archived asc, '
            . 'IF(is_archived = 1, COALESCE(finish_time, archive_time, update_time, create_time), NULL) desc, '
            . 'is_finished asc, is_returned desc, is_suspended desc, '
            . 'IF(promise_time IS NULL, 1, 0) asc, promise_time asc, id desc';
    }

    /** 统计卡片（不受筛选影响，仅受数据范围影响） */
    protected function stat(): array
    {
        $today = date('Y-m-d');
        $base = function () {
            return $this->scopeGov(Db::name('gov_progress'));
        };
        return [
            'total'       => (clone $base())->count(),
            'unfinished'  => (clone $base())->where('is_finished', 0)->where('is_archived', 0)->count(),
            // v2.73.2：办结与归档合并口径（两者有重叠，取并集）
            'closed'      => (clone $base())->where(function ($w) {
                $w->where('is_finished', 1)->whereOr('is_archived', 1);
            })->count(),
            'finished'    => (clone $base())->where('is_finished', 1)->count(),
            'archived'    => (clone $base())->where('is_archived', 1)->count(),
            'overdue'     => (clone $base())->where('is_finished', 0)->where('is_archived', 0)
                ->whereNotNull('promise_time')->where('promise_time', '<', $today)->count(),
            // v2.93：承诺时间 7 天内到期（未办结未归档），供首页风险卡 / 列表快捷筛选
            'soon'        => (clone $base())->where('is_finished', 0)->where('is_archived', 0)
                ->whereNotNull('promise_time')
                ->whereBetween('promise_time', [$today, date('Y-m-d', strtotime('+7 days'))])->count(),
            'sync_failed' => (clone $base())->where('is_archived', 0)->where('last_sync_status', 2)->count(),
            // v2.90：挂起中（平台 HANDLESTATE=挂起 / 存在挂起环节）
            // v2.90.5：兼容老数据——标记未回填但平台状态已为挂起的记录也计入
            'suspended'   => (clone $base())->where('is_finished', 0)->where('is_archived', 0)
                ->where(function ($w) {
                    $w->where('is_suspended', 1)->whereOr('handle_state', 'like', '%挂起%')
                      ->whereOr('handle_state', 'like', '%暂停%')
                      ->whereOr('handle_state', 'like', '%中止%');
                })->count(),
            // v2.99.40：退件/补正待处理（列表置顶并强提醒）
            'returned'    => (clone $base())->where('is_finished', 0)->where('is_archived', 0)
                ->where(function ($w) {
                    $w->where('is_returned', 1)
                      ->whereOr('current_node', 'like', '%退件%')
                      ->whereOr('current_node', 'like', '%退回%')
                      ->whereOr('current_node', 'like', '%补正%')
                      ->whereOr('handle_state', 'like', '%退件%')
                      ->whereOr('handle_state', 'like', '%补正%');
                })->count(),
        ];
    }

    protected function applyFilters($q): void
    {
        $keyword = trim((string)$this->request->param('keyword', ''));
        if ($keyword !== '') {
            // v2.63：关键字同时命中关联企业 / 人员名称（先解析 ID，避免多表 JOIN 影响分页）
            $companyIds = Db::name('client_company')->where('company_name', 'like', "%{$keyword}%")
                ->limit(500)->column('id');
            $staffIds = Db::name('staff')->where('staff_name', 'like', "%{$keyword}%")
                ->limit(500)->column('id');
            $q->where(function ($w) use ($keyword, $companyIds, $staffIds) {
                $w->where('project_no', 'like', "%{$keyword}%")
                  ->whereOr('project_name', 'like', "%{$keyword}%")
                  ->whereOr('apply_name', 'like', "%{$keyword}%")
                  ->whereOr('dept_name', 'like', "%{$keyword}%")
                  ->whereOr('cert_name', 'like', "%{$keyword}%")
                  ->whereOr('remark', 'like', "%{$keyword}%");
                if ($companyIds) {
                    $w->whereOr('company_id', 'in', array_map('intval', $companyIds));
                }
                if ($staffIds) {
                    $w->whereOr('staff_id', 'in', array_map('intval', $staffIds));
                }
            });
        }
        $companyId = (int)$this->request->param('company_id', 0);
        if ($companyId > 0) {
            $q->where('company_id', $companyId);
        }
        $staffId = (int)$this->request->param('staff_id', 0);
        if ($staffId > 0) {
            $q->where('staff_id', $staffId);
        }
        $certType = trim((string)$this->request->param('cert_type', ''));
        if ($certType !== '') {
            $q->where('cert_type', $certType);
        }
        $type = trim((string)$this->request->param('business_type', ''));
        if ($type !== '') {
            $q->where('business_type', $type);
        }
        $status = $this->request->param('status', '');
        if ($status !== '' && $status !== null) {
            $q->where('status', (int)$status);
        }
        $closed = $this->request->param('closed', '');
        if ($closed !== '' && $closed !== null) {
            // v2.73.2：已办结与已归档合并筛选（并集；=0 时取两者都未完成）
            if ((int)$closed === 1) {
                $q->where(function ($w) {
                    $w->where('is_finished', 1)->whereOr('is_archived', 1);
                });
            } else {
                $q->where('is_finished', 0)->where('is_archived', 0);
            }
        }
        $finished = $this->request->param('finished', '');
        if ($finished !== '' && $finished !== null) {
            $q->where('is_finished', (int)$finished);
        }
        $archived = $this->request->param('archived', '');
        if ($archived !== '' && $archived !== null) {
            $q->where('is_archived', (int)$archived);
        }
        // v2.90：挂起筛选（1=仅挂起，0=仅未挂起）
        // v2.90.5：1 时同时命中「标记已回填」和「平台状态含挂起」的老数据
        $suspended = $this->request->param('suspended', '');
        if ($suspended !== '' && $suspended !== null) {
            if ((int)$suspended === 1) {
                $q->where(function ($w) {
                    $w->where('is_suspended', 1)->whereOr('handle_state', 'like', '%挂起%')
                      ->whereOr('handle_state', 'like', '%暂停%')
                      ->whereOr('handle_state', 'like', '%中止%');
                });
            } else {
                $q->where('is_suspended', 0)
                  ->whereRaw("(handle_state = '' OR handle_state NOT REGEXP '挂起|暂停|中止')");
            }
        }
        $sync = $this->request->param('sync_status', '');
        if ($sync !== '' && $sync !== null) {
            $q->where('last_sync_status', (int)$sync);
        }
        // v2.99.40：退件筛选（1=仅退件/补正待处理，0=排除退件）
        $returned = $this->request->param('returned', '');
        if ($returned !== '' && $returned !== null) {
            if ((int)$returned === 1) {
                $q->where('is_finished', 0)->where(function ($w) {
                    $w->where('is_returned', 1)
                      ->whereOr('current_node', 'like', '%退件%')
                      ->whereOr('current_node', 'like', '%退回%')
                      ->whereOr('current_node', 'like', '%补正%')
                      ->whereOr('handle_state', 'like', '%退件%')
                      ->whereOr('handle_state', 'like', '%补正%');
                });
            } else {
                $q->where('is_returned', 0);
            }
        }
        $promise = trim((string)$this->request->param('promise_status', ''));
        if ($promise !== '') {
            $today = date('Y-m-d');
            if ($promise === 'overdue') {
                $q->where('is_finished', 0)->whereNotNull('promise_time')
                  ->where('promise_time', '<', $today);
            } elseif ($promise === 'soon') {
                $q->where('is_finished', 0)->whereNotNull('promise_time')
                  ->whereBetween('promise_time', [$today, date('Y-m-d', strtotime('+7 days'))]);
            } elseif ($promise === 'today') {
                $q->where('is_finished', 0)->where('promise_time', $today);
            }
        }
    }

    public function meta()
    {
        $this->requireRead();
        return json(r_success([
            'business_types'  => GovProgressService::businessTypes(),
            'dict_code'       => GovProgressService::BUSINESS_TYPE_DICT,
            'sync_states'     => GovProgressService::SYNC_STATES,
            'node_states'     => GovProgressService::NODE_STATES,
            'archive_sources' => GovProgressService::ARCHIVE_SOURCES,
            'last_run_at'     => GovProgressService::lastRunAt(),
            'daily_min_hours' => GovProgressService::dailyMinHours(),
            'schedule'        => GovProgressService::scheduleGuide(),
        ]));
    }

    public function detail()
    {
        $this->requireRead();
        $id = (int)$this->request->param('id', 0);
        if (!$id) {
            return json(r_error('缺少 id', 400));
        }
        $edit = (int)$this->request->param('edit', 0) === 1;
        if ($edit) {
            $this->requireWrite();
        }
        GovProgressService::ensureTables();
        $row = $this->scopeGov(Db::name('gov_progress'))->where('id', $id)->find();
        if (!$row) {
            return json(r_error('申报记录不存在或无权访问', 404));
        }
        // v2.64：历史数据只有 3 个环节时，打开详情自动从 raw_json 重解析并补全
        // v2.95：旧版合并逻辑产生重复环节（如两条「受理」）时也同样自动修复
        if (!empty($row['raw_json']) && ((int)($row['node_total'] ?? 0) <= 3 || GovProgressService::hasDuplicateNodes($id))) {
            $repair = GovProgressService::repairNodesFromRaw($id, false);
            if (!empty($repair['changed'])) {
                $row = $this->scopeGov(Db::name('gov_progress'))->where('id', $id)->find();
            }
        }
        $one = [$row];
        GovProgressService::attachRelations($one);
        $row = $one[0];
        // v2.70：详情页展示申报密码。编辑（gov:edit）与「查看密码」（gov:reveal）均返回明文，
        // 其余只读角色仍只拿到掩码，避免接口越权拿到明文。
        $canReveal = $this->can('gov', 'reveal');
        $plain = ($edit || $canReveal)
            ? GovProgressService::decryptPassword((string)($row['password_enc'] ?? ''))
            : '';
        GovProgressService::decorate($row, true);
        $row['can_reveal'] = $canReveal;
        if ($edit || $canReveal) {
            $row['password'] = $plain;
        }
        return json(r_success($row));
    }

    public function save()
    {
        $this->requireWrite();
        GovProgressService::ensureTables();

        $id           = (int)$this->request->post('id', 0);
        $projectNo    = trim((string)$this->request->post('project_no', ''));
        $password     = trim((string)$this->request->post('password', ''));
        $businessType = trim((string)$this->request->post('business_type', 'initial'));
        $companyId    = (int)$this->request->post('company_id', 0);
        $staffId      = (int)$this->request->post('staff_id', 0);
        $certType     = trim((string)$this->request->post('cert_type', ''));
        $certName     = trim((string)$this->request->post('cert_name', ''));
        $remark       = trim((string)$this->request->post('remark', ''));
        $status       = (int)$this->request->post('status', 1) === 0 ? 0 : 1;

        if ($projectNo === '') {
            return json(r_error('请填写申报号', 400));
        }
        if (!preg_match('/^[A-Za-z0-9_\-\/]{4,64}$/', $projectNo)) {
            return json(r_error('申报号格式不正确（4-64 位字母、数字、下划线、短横线）', 400));
        }
        if (!isset(GovProgressService::businessTypes()[$businessType])) {
            return json(r_error('不支持的业务类型（请在字典管理中维护 gov_business_type）', 400));
        }
        if ($password !== '' && (mb_strlen($password) > 64)) {
            return json(r_error('申报密码过长（最多 64 位）', 400));
        }
        if ($password === '******') {
            $password = '';
        }

        // v2.99.35：企业/人员仅作引用主数据，校验存在性即可，不再要求调用方具备
        // 企业客户/人员档案的数据权限（三轴解耦：申报进度的授权不依赖主数据菜单）。
        if ($companyId > 0) {
            $company = Db::name('client_company')->where('id', $companyId)->find();
            if (!$company) {
                return json(r_error('关联企业不存在', 400));
            }
        }
        if ($staffId > 0) {
            $staff = Db::name('staff')->where('id', $staffId)->find();
            if (!$staff) {
                return json(r_error('关联人员不存在', 400));
            }
        }

        $old = null;
        if ($id) {
            $old = $this->scopeGov(Db::name('gov_progress'))->where('id', $id)->find();
            if (!$old) {
                return json(r_error('申报记录不存在或无权访问', 404));
            }
        }

        $dup = Db::name('gov_progress')->where('project_no', $projectNo)
            ->where('id', '<>', $id)->find();
        if ($dup) {
            return json(r_error('该申报号已存在（#' . $dup['id'] . '）', 400));
        }

        // v2.78：证书类型/专业组合校验（专业已登记字典时必须归属所选类型）
        $pair = \app\service\CertDictService::normalizePair($certType, $certName);
        if ($pair['error'] !== '') {
            return json(r_error($pair['error'], 400));
        }
        $certType = $pair['type'];
        $certName = $pair['name'];

        $data = [
            'project_no'    => $projectNo,
            'business_type' => $businessType,
            'company_id'    => $companyId,
            'staff_id'      => $staffId,
            'cert_type'     => mb_substr($certType, 0, 64),
            'cert_name'     => mb_substr($certName, 0, 64),
            'remark'        => mb_substr($remark, 0, 255),
            'status'        => $status,
        ];
        // v2.95：人工保存人与保存时间（同步刷新不写这两个字段，避免保存时间被覆盖）
        $data['save_by']   = (int)$this->request->userId;
        $data['save_time'] = date('Y-m-d H:i:s');

        if ($id) {
            if ($password === '') {
                // 未填写新密码 = 保持原密码
            } else {
                $data['password_enc'] = GovProgressService::encryptPassword($password);
            }
            Db::name('gov_progress')->where('id', $id)->update($data);
            $this->log('政务网进度', '更新', "#{$id} {$projectNo}");
        } else {
            if ($password === '') {
                return json(r_error('请填写申报密码', 400));
            }
            $data['password_enc'] = GovProgressService::encryptPassword($password);
            $data['owner_id']     = (int)$this->request->userId;
            $data['create_by']    = (int)$this->request->userId;
            $data['dept_id']      = $this->primaryDeptId();
            $model = Db::name('gov_progress')->insertGetId($data);
            $id = (int)$model;
            $this->log('政务网进度', '新增', "#{$id} {$projectNo}");
        }

        // 保存后立即同步一次，用户无需再点「同步」；同步失败不影响保存结果
        $sync = GovProgressService::syncRecord($id);
        $pushNote = (!empty($sync['changed']) && !empty($sync['push']['pushed'])) ? '，进度变更已推送通知' : '';
        $msg = $sync['ok']
            ? '保存成功，已同步最新进度' . $pushNote
            : '保存成功，但同步失败：' . $sync['msg'];

        return json(r_success([
            'id'      => $id,
            'sync_ok' => !empty($sync['ok']),
            'sync'    => $sync,
        ], $msg));
    }

    protected function primaryDeptId(): int
    {
        $userId = (int)$this->request->userId;
        if ($userId <= 0) {
            return 0;
        }
        $ids = DeptScopeService::userDeptIds($userId);
        return (int)($ids[0] ?? 0);
    }

    public function delete()
    {
        $this->requireWrite();
        $id = (int)$this->request->post('id', 0);
        if (!$id) {
            return json(r_error('缺少 id', 400));
        }
        $r = $this->deleteOne($id);
        return $r['ok'] ? json(r_success([], '删除成功')) : json(r_error($r['msg'], 400));
    }

    protected function deleteOne(int $id): array
    {
        $row = Db::name('gov_progress')->where('id', $id)->find();
        if (!$row) {
            return ['ok' => false, 'msg' => '申报记录不存在'];
        }
        if (!$this->canAccessGov($row)) {
            return ['ok' => false, 'msg' => '无权删除该申报记录'];
        }
        try {
            \app\service\RecycleBinService::stash(
                (int)$this->request->userId,
                'gov_progress',
                ((string)($row['project_no'] ?? '') ?: ('#' . $id))
                    . ' ' . ((string)($row['project_name'] ?? '') ?: ''),
                $row,
                [['table' => 'gov_progress_node', 'rows' => \app\service\RecycleBinService::grab('gov_progress_node', 'progress_id', $id)]]
            );
        } catch (\Throwable $e) {
            // 快照失败不阻断删除
        }
        Db::transaction(function () use ($id) {
            Db::name('gov_progress_node')->where('progress_id', $id)->delete();
            Db::name('gov_progress')->where('id', $id)->delete();
        });
        $this->log('政务网进度', '删除', "#{$id} {$row['project_no']}");
        return ['ok' => true, 'msg' => '删除成功'];
    }

    /** 批量删除（逐条校验权限，失败项返回原因） */
    public function batchDelete()
    {
        $this->requireWrite();
        $ids = (array)$this->request->post('ids/a', []);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return json(r_error('请选择要删除的申报记录', 400));
        }

        $ok = 0;
        $fail = [];
        foreach ($ids as $id) {
            $r = $this->deleteOne($id);
            if (!empty($r['ok'])) {
                $ok++;
            } else {
                $fail[] = "#{$id} " . ($r['msg'] ?? '删除失败');
            }
        }

        $msg = $fail
            ? "已删除 {$ok} 条，" . count($fail) . ' 条失败'
            : "已删除 {$ok} 条";
        return json(r_success(['ok' => $ok, 'fail' => $fail], $msg));
    }

    /** 立即同步（单条或多条） */
    public function sync()
    {
        $this->requireWrite();
        $ids = (array)$this->request->post('ids/a', []);
        $single = (int)$this->request->post('id', 0);
        if ($single > 0) {
            $ids[] = $single;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return json(r_error('请选择要同步的申报记录', 400));
        }

        $allowed = $this->scopeGov(Db::name('gov_progress'))
            ->whereIn('id', $ids)->column('id');
        $allowed = array_map('intval', $allowed ?: []);
        if (!$allowed) {
            return json(r_error('无权同步所选申报记录', 403));
        }

        $summary = GovProgressService::syncMany($allowed);
        if (count($allowed) === 1) {
            $item = $summary['items'][0] ?? [];
            return !empty($item['ok'])
                ? json(r_success($item, $item['msg'] ?? '同步成功'))
                : json(r_error($item['msg'] ?? '同步失败', 400));
        }
        $msg = "同步完成：成功 {$summary['ok']} 条，失败 {$summary['fail']} 条";
        // v2.99.69：只统计真正推送成功的记录，避免「状态字段变了但环节没更新」被误报为已推送
        $pushedCount = count(array_filter($summary['items'] ?? [], fn($it) => !empty($it['push']['pushed'])));
        if ($pushedCount > 0) {
            $msg .= "，{$pushedCount} 条进度更新已推送通知";
        }
        return json(r_success($summary, $msg));
    }

    /** 同步当前范围内全部未办结申报 */
    public function syncAll()
    {
        $this->requireWrite();
        GovProgressService::ensureTables();

        $ids = $this->scopeGov(Db::name('gov_progress'))
            ->where('status', 1)->where('is_finished', 0)->where('is_archived', 0)
            ->order('id', 'asc')->limit(500)->column('id');
        $ids = array_map('intval', $ids ?: []);
        if (!$ids) {
            return json(r_success(['total' => 0, 'ok' => 0, 'fail' => 0, 'items' => []], '没有待同步的申报'));
        }
        $started = microtime(true);
        $summary = GovProgressService::syncMany($ids);
        $duration = (int)round((microtime(true) - $started) * 1000);
        GovProgressService::markRun($summary, 'manual', [
            'operator_id' => (int)$this->request->userId,
            'duration_ms' => $duration,
            'message'     => "手动同步未办结申报：成功 {$summary['ok']} 条，失败 {$summary['fail']} 条",
        ]);
        $msg = "同步完成：成功 {$summary['ok']} 条，失败 {$summary['fail']} 条";
        $pushedCount = count(array_filter($summary['items'] ?? [], fn($it) => !empty($it['push']['pushed'])));
        if ($pushedCount > 0) {
            $msg .= "，{$pushedCount} 条进度更新已推送通知";
        }
        return json(r_success($summary, $msg));
    }

    /**
     * v2.63：批量归档 / 取消归档
     *  - archived=1：归档并停止自动同步（办结记录由同步逻辑自动调用同等逻辑）
     *  - archived=0：恢复到在办列表并重新启用自动同步
     */
    public function archive()
    {
        $this->requireWrite();
        $ids = (array)$this->request->post('ids/a', []);
        $single = (int)$this->request->post('id', 0);
        if ($single > 0) {
            $ids[] = $single;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return json(r_error('请选择要操作的申报记录', 400));
        }
        $archived = (int)$this->request->post('archived', 1) === 0 ? 0 : 1;

        $allowed = $this->scopeGov(Db::name('gov_progress'))
            ->whereIn('id', $ids)->column('id');
        $allowed = array_map('intval', $allowed ?: []);
        if (!$allowed) {
            return json(r_error('无权操作所选申报记录', 403));
        }

        $now = date('Y-m-d H:i:s');
        $oldRows = Db::name('gov_progress')->whereIn('id', $allowed)->select()->toArray();
        $oldMap  = [];
        foreach ($oldRows as $r) {
            $oldMap[(int)$r['id']] = $r;
        }
        $data = $archived
            ? [
                'is_archived'    => 1,
                'archive_time'   => $now,
                'archive_source' => 'manual',
                'status'         => 0,
                'update_time'    => $now,
            ]
            : [
                'is_archived'    => 0,
                'archive_time'   => null,
                'archive_source' => '',
                'status'         => 1,
                'update_time'    => $now,
            ];
        Db::name('gov_progress')->whereIn('id', $allowed)->update($data);
        $this->log('政务网进度', $archived ? '归档' : '取消归档', implode(',', $allowed));

        // v2.73：归档 / 取消归档属于状态变更，按 gov_progress 模板推送消息通知
        $pushed = 0;
        foreach ($allowed as $id) {
            $new = Db::name('gov_progress')->where('id', $id)->find();
            $old = $oldMap[$id] ?? [];
            try {
                $res = GovProgressService::notifyProgressChanged($old, $new, ['action' => 'archive']);
                if (!empty($res['pushed'])) {
                    $pushed++;
                }
            } catch (\Throwable $e) {
                // 推送失败不影响归档结果
            }
        }

        $msg = $archived
            ? '已归档 ' . count($allowed) . ' 条，已停止自动同步'
            : '已取消归档 ' . count($allowed) . ' 条，已恢复自动同步';
        if ($pushed > 0) {
            $msg .= '，状态变更已推送通知';
        }
        return json(r_success(['ok' => count($allowed), 'ids' => $allowed], $msg));
    }

    /** v2.63：定时任务执行记录 */
    public function runs()
    {
        $this->requireRead();
        [$page, $limit] = $this->pageParams();
        $data = GovProgressService::runs($page, $limit);
        $data['schedule'] = GovProgressService::scheduleGuide();
        $data['last_run_at'] = GovProgressService::lastRunAt();
        return json(r_success($data));
    }

    /**
     * v2.64：从已保存的平台原始响应重解析环节，修复历史数据只显示 3 个环节的问题。
     * 不访问政务网，不会触发限流。
     */
    public function repair()
    {
        $this->requireWrite();
        $id = (int)$this->request->post('id', 0);
        if ($id > 0) {
            // 单条修复：先做数据范围校验，避免越权
            $row = $this->scopeGov(Db::name('gov_progress'))->where('id', $id)->find();
            if (!$row) {
                return json(r_error('申报记录不存在或无权访问', 404));
            }
            $one = GovProgressService::repairNodesFromRaw($id, false);
            if (empty($one['ok'])) {
                return json(r_error($one['msg'], 400));
            }
            $this->log('政务网进度', '修复环节', "#{$id} {$one['msg']}");
            return json(r_success([
                'scanned'     => 1,
                'repaired'    => !empty($one['changed']) ? 1 : 0,
                'nodes_added' => (int)$one['added'],
                'after'       => (int)$one['after'],
                'skipped'     => empty($one['changed']) ? 1 : 0,
                'failed'      => 0,
                'items'       => [['id' => $id, 'project_no' => (string)($row['project_no'] ?? ''), 'msg' => $one['msg']]],
            ], $one['msg']));
        }

        $limit = (int)$this->request->post('limit', 2000);
        $res = GovProgressService::repairAllNodes($limit);
        $this->log('政务网进度', '修复环节', "扫描{$res['scanned']}条/修复{$res['repaired']}条/补{$res['nodes_added']}个节点");
        $msg = $res['repaired'] > 0
            ? "修复完成：扫描 {$res['scanned']} 条，修复 {$res['repaired']} 条，新增 {$res['nodes_added']} 个环节"
            : "检查完成：扫描 {$res['scanned']} 条，环节均已完整（跳过 {$res['skipped']} 条）";
        return json(r_success($res, $msg));
    }

    /** 导出（最多 5000 条） */
    public function export()
    {
        $this->requireRead();
        GovProgressService::ensureTables();

        $q = $this->scopeGov(Db::name('gov_progress'));
        $this->applyFilters($q);
        $rows = $q->orderRaw($this->listOrder())
            ->limit(5000)->select()->toArray();
        GovProgressService::attachRelations($rows);

        $formatted = [];
        foreach ($rows as $r) {
            GovProgressService::decorate($r);
            $formatted[] = [
                'project_no'           => (string)($r['project_no'] ?? ''),
                'business_type_text'   => (string)($r['business_type_text'] ?? ''),
                'company_name'         => (string)($r['company_name'] ?? ''),
                'staff_name'           => (string)($r['staff_name'] ?? ''),
                'cert_type'            => (string)($r['cert_type'] ?? ''),
                'cert_name'            => (string)($r['cert_name'] ?? ''),
                'project_name'         => (string)($r['project_name'] ?? ''),
                'dept_name'            => (string)($r['dept_name'] ?? ''),
                'apply_name'           => (string)($r['apply_name'] ?? ''),
                'current_node'         => (string)($r['current_node'] ?? ''),
                'current_state_text'   => (string)($r['current_state_text'] ?? ''),
                'is_finished_text'     => (string)($r['is_finished_text'] ?? ''),
                'is_archived_text'     => (string)($r['is_archived_text'] ?? ''),
                'archive_time'         => (string)($r['archive_time'] ?? ''),
                'archive_source_text'  => (string)($r['archive_source_text'] ?? ''),
                'accept_time'          => (string)($r['accept_time'] ?? ''),
                'promise_time'         => (string)($r['promise_time'] ?? ''),
                'promise_status_text'  => (string)($r['promise_status_text'] ?? ''),
                'finish_time'          => (string)($r['finish_time'] ?? ''),
                'last_sync_time'       => (string)($r['last_sync_time'] ?? ''),
                'last_sync_status_text'=> (string)($r['last_sync_status_text'] ?? ''),
                'remark'               => (string)($r['remark'] ?? ''),
            ];
        }

        $path = ExcelService::export('gov_progress', $formatted);
        return download_utf8($path, '政务网申报进度_' . date('Ymd') . '.xlsx');
    }

    /**
     * v2.71：导入模板下载（申报号 / 申报密码 / 业务类型 / 关联信息）
     * 导入走统一入口：POST /admin/import/preview?type=gov_progress -> commit
     */
    public function template()
    {
        if (!$this->can('gov', 'import')) {
            return json(r_error('暂无导入申报进度权限，请联系管理员', 403));
        }
        GovProgressService::ensureTables();
        $path = ExcelService::template('gov_progress');
        return download_utf8($path, '政务网申报进度导入模板.xlsx');
    }
}
