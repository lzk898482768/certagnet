<?php
namespace app\controller\admin;

use app\service\CertDictService;
use app\service\CustomFieldService;
use app\service\ExcelService;
use app\service\PersonProfileService;
use think\facade\Db;

/**
 * 项目管理 —— 产品精髓「多维关联」
 *
 * 项目是用证需求的载体：它绑定客户企业、属地（region_code）与起止周期，
 * 其下的 project_cert_req 描述「这个项目要什么证、要几个人」，
 * 是智能匹配的输入源，也是属地政策评估的作用域。
 */
class Project extends AdminController
{
    /** 项目状态文本 */
    public const STATUS = [0 => '筹备中', 1 => '进行中', 2 => '已结束', 3 => '已暂停'];

    /** 项目社保要求文本 */
    public const SOCIAL = [0 => '不要求', 1 => '必须', 2 => '优先'];

    /** 状态标签：字典优先，常量兜底 */
    protected function statusLabels(): array
    {
        try {
            $rows = Db::name('dict_data')->where('type_code', 'project_status')
                ->where('status', 1)->order('sort', 'asc')->select()->toArray();
            if ($rows) {
                $out = [];
                foreach ($rows as $r) {
                    $out[(int)$r['dict_value']] = (string)$r['dict_label'];
                }
                if ($out) { return $out; }
            }
        } catch (\Throwable $e) {
            // 字典表异常时回退常量
        }
        return self::STATUS;
    }

    /** 社保要求标签：字典优先，常量兜底 */
    protected function socialLabels(): array
    {
        try {
            $rows = Db::name('dict_data')->where('type_code', 'project_require_social')
                ->where('status', 1)->order('sort', 'asc')->select()->toArray();
            if ($rows) {
                $out = [];
                foreach ($rows as $r) {
                    $out[(int)$r['dict_value']] = (string)$r['dict_label'];
                }
                if ($out) { return $out; }
            }
        } catch (\Throwable $e) {
            // 字典表异常时回退常量
        }
        return self::SOCIAL;
    }

    /**
     * 项目列表（含需求统计：需求项数 / 总需求人数 / 已匹配人数）
     */
    public function index()
    {
        [$page, $limit] = $this->pageParams();
        $name     = trim((string)$this->request->param('project_name', ''));
        $keyword  = trim((string)$this->request->param('keyword', ''));
        $clientId = (int)$this->request->param('client_id', 0);
        $status   = $this->request->param('status', '');

        $q = Db::name('project')->alias('p')
            ->leftJoin('client_company c', 'c.id = p.client_id')
            ->field('p.*, c.company_name')
            ->order('p.id', 'desc');

        // 数据权限：客户账号按企业边界；内部账号按创建人（v2.99.36）
        $q = $this->applyModuleScope($q, 'project', 'p.create_by', 'p.client_id');
        if ($clientId) {
            $q->where('p.client_id', $clientId);
        }
        if ($status !== '') {
            $q->where('p.status', $status);
        }
        if ($name) {
            $q->where('p.project_name', 'like', "%{$name}%");
        }
        if ($keyword) {
            $q->where(function ($w) use ($keyword) {
                $w->where('p.project_name', 'like', "%{$keyword}%")
                  ->whereOr('p.project_no', 'like', "%{$keyword}%")
                  ->whereOr('p.region_name', 'like', "%{$keyword}%");
            });
        }

        $total = (clone $q)->count();
        $list  = (clone $q)->page($page, $limit)->select()->toArray();

        // 需求统计（批量，避免 N+1）
        $ids = array_column($list, 'id');
        if ($ids) {
            $stats = Db::name('project_cert_req')->whereIn('project_id', $ids)
                ->field('project_id, COUNT(*) AS req_count, SUM(need_count) AS need_total, SUM(matched_count) AS matched_total')
                ->group('project_id')->select()->toArray();
            $map = [];
            foreach ($stats as $s) {
                $map[(int)$s['project_id']] = $s;
            }
            foreach ($list as &$p) {
                $s = $map[(int)$p['id']] ?? ['req_count' => 0, 'need_total' => 0, 'matched_total' => 0];
                $p['req_count']      = (int)$s['req_count'];
                $p['need_total']     = (int)$s['need_total'];
                $p['matched_total']  = (int)$s['matched_total'];
                $p['match_rate']     = (int)$s['need_total'] > 0
                    ? round((int)$s['matched_total'] * 100 / (int)$s['need_total'], 1) : 0;
                $p['status_text']    = $this->statusLabels()[(int)$p['status']] ?? '未知';
                $p['remain_days']    = \app\service\SecondmentService::remainDays($p['end_date'] ?? '');
            }
            unset($p);
        } else {
            foreach ($list as &$p) {
                $p += ['req_count' => 0, 'need_total' => 0, 'matched_total' => 0, 'match_rate' => 0,
                       'status_text' => $this->statusLabels()[(int)$p['status']] ?? '未知', 'remain_days' => null];
            }
            unset($p);
        }

        CustomFieldService::attachList('project', $list);
        return json(r_success(['list' => $list, 'total' => $total]));
    }

    /**
     * 项目详情：基本信息 + 需求明细 + 选用记录
     */
    public function detail()
    {
        $id = (int)$this->request->param('id', 0);
        $p  = Db::name('project')->alias('p')
            ->leftJoin('client_company c', 'c.id = p.client_id')
            ->field('p.*, c.company_name')
            ->where('p.id', $id)->find();
        if (!$p) {
            return json(r_error('项目不存在', 400));
        }
        $this->assertCompanyAccess((int)($p['client_id'] ?? 0));
        $this->assertModuleAccess('project', $id);
        $p['status_text'] = $this->statusLabels()[(int)$p['status']] ?? '未知';
        $p['remain_days'] = \app\service\SecondmentService::remainDays($p['end_date'] ?? '');

        // 需求明细（含匹配率）
        $reqs = Db::name('project_cert_req')->where('project_id', $id)
            ->order('id', 'asc')->select()->toArray();
        foreach ($reqs as &$r) {
            $r['need_count']    = (int)$r['need_count'];
            $r['matched_count'] = (int)$r['matched_count'];
            $r['remain_count']  = max(0, $r['need_count'] - $r['matched_count']);
            $r['match_rate']    = $r['need_count'] > 0
                ? round($r['matched_count'] * 100 / $r['need_count'], 1) : 0;
            $r['require_social_text'] = $this->socialLabels()[(int)$r['require_social']] ?? '不要求';
        }
        unset($r);
        $p['reqs'] = $reqs;

        // 选用记录（匹配留痕）
        // 注意：曾误加 leftJoin('client_company cc', 'cc.id = mr.project_id')，
        // 既传错 bind 参数（TP6.1 join 第三参为数组）又语义错误（project_id 不是企业ID），已移除
        $p['match_records'] = Db::name('match_record')->alias('mr')
            ->leftJoin('staff s', 's.id = mr.staff_id')
            ->leftJoin('certificate c', 'c.id = mr.cert_id')
            ->field('mr.*, s.staff_name, c.cert_name, c.cert_no')
            ->where('mr.project_id', $id)
            ->order('mr.id', 'desc')->limit(200)->select()->toArray();
        $user = $this->request->user ?? [];
        foreach ($p['match_records'] as &$mr) {
            $mr['cert_no'] = \app\service\MaskService::fieldValue('cert.cert_no', $mr['cert_no'] ?? '', $user);
            $mr['staff_name'] = \app\service\MaskService::fieldValue('staff.name', $mr['staff_name'] ?? '', $user);
            $detail = $mr['score_detail'] ?? '';
            $mr['score_detail'] = is_string($detail) ? (json_decode($detail, true) ?: []) : (array)$detail;
        }
        unset($mr);

        // 该项目名下已借调记录（在用到何时/在哪用）
        $p['secondments'] = Db::name('cert_secondment')->alias('s')
            ->leftJoin('certificate c', 'c.id = s.cert_id')
            ->leftJoin('staff st', 'st.id = s.staff_id')
            ->field('s.id,s.cert_id,s.staff_id,s.start_date,s.end_date,s.status,c.cert_name,c.cert_no,st.staff_name')
            ->whereIn('s.company_id', function ($sub) use ($p) {
                $sub->table('project')->where('id', $p['id'])->field('client_id');
            })
            ->order('s.id', 'desc')->limit(200)->select()->toArray();
        foreach ($p['secondments'] as &$sm) {
            $sm['cert_no'] = \app\service\MaskService::fieldValue('cert.cert_no', $sm['cert_no'] ?? '', $user);
            $sm['staff_name'] = \app\service\MaskService::fieldValue('staff.name', $sm['staff_name'] ?? '', $user);
            $sm['status_text']  = \app\service\SecondmentService::statusText((int)$sm['status']);
            $sm['remain_days']  = \app\service\SecondmentService::remainDays($sm['end_date'] ?? '');
        }
        unset($sm);

        // v2.30：配证任务并入项目模块——项目详情直接附带其名下任务（含明细、进度汇总与动态）
        $tasks = Db::name('cert_task')->where('project_id', $id)->order('id', 'desc')->select()->toArray();
        if ($tasks) {
            $tIds      = array_column($tasks, 'id');
            $taskItems = Db::name('cert_task_item')->whereIn('task_id', $tIds)->select()->toArray();
            $tiMap = [];
            foreach ($taskItems as $ti) {
                $tiMap[(int)$ti['task_id']][] = $ti;
            }
            // 任务动态（每个任务最近 50 条）
            $taskLogs = Db::name('cert_task_log')->whereIn('task_id', $tIds)
                ->order('id', 'desc')->select()->toArray();
            $tlMap = [];
            foreach ($taskLogs as $tl) {
                $tlMap[(int)$tl['task_id']][] = $tl;
            }
            foreach ($tasks as &$tk) {
                $its = $tiMap[(int)$tk['id']] ?? [];
                $tk['items']      = $its;
                $tk['item_count'] = count($its);
                $tk['need_total'] = array_sum(array_column($its, 'need_count'));
                $tk['done_total'] = array_sum(array_column($its, 'done_count'));
                $tk['company_name'] = $tk['company_id']
                    ? (Db::name('client_company')->where('id', (int)$tk['company_id'])->value('company_name') ?: '') : '';
                // 模型访问器等价格式化（Db 查询不经过 CertTask 模型）
                $tk['status_text'] = [0 => '草稿', 1 => '进行中', 2 => '已完成', 3 => '已关闭'][(int)$tk['status']] ?? '未知';
                $tk['visible_scope_text'] = ['all' => '全员可见', 'dept' => '指定部门', 'user' => '指定人员', 'self' => '仅发布人'][(string)$tk['visible_scope']] ?? '全员可见';
                $tk['logs'] = array_reverse($tlMap[(int)$tk['id']] ?? []);
            }
            unset($tk);
        }
        $p['tasks'] = $tasks;

        CustomFieldService::attachOne('project', $p);
        return json(r_success($p));
    }

    /**
     * 新增 / 编辑项目
     * reqs 为需求明细数组（可选，编辑时整体覆盖）
     */
    public function save()
    {
        $id   = (int)$this->request->post('id', 0);
        $name = trim((string)$this->request->post('project_name', ''));
        if ($name === '') {
            return json(r_error('项目名称必填', 400));
        }
        $clientId = (int)$this->request->post('client_id', 0);
        if ($clientId && !Db::name('client_company')->where('id', $clientId)->find()) {
            return json(r_error('客户企业不存在', 400));
        }
        if ($clientId > 0) {
            $this->assertCompanyAccess($clientId);
        }
        if ($id) {
            $oldProject = Db::name('project')->where('id', $id)->find();
            if (!$oldProject) {
                return json(r_error('项目不存在', 400));
            }
            $this->assertCompanyAccess((int)($oldProject['client_id'] ?? 0));
            // v2.99.36：内部账号仅能编辑本人创建的项目
            $this->assertModuleAccess('project', $id);
        }
        $start = trim((string)$this->request->post('start_date', '')) ?: null;
        $end   = trim((string)$this->request->post('end_date', '')) ?: null;
        if ($start && $end && $start > $end) {
            return json(r_error('项目开始日期不能晚于结束日期', 400));
        }

        // 自定义字段校验（与 Staff/Certificate 一致）
        $customErr = CustomFieldService::validate('project', $this->request->post('custom'));
        if ($customErr !== '') {
            return json(r_error($customErr, 400));
        }

        $data = [
            'project_no'   => trim((string)$this->request->post('project_no', '')),
            'project_name' => $name,
            'client_id'    => $clientId,
            'region_code'  => trim((string)$this->request->post('region_code', '')),
            'region_name'  => trim((string)$this->request->post('region_name', '')),
            'start_date'   => $start,
            'end_date'     => $end,
            'status'       => (int)$this->request->post('status', 0),
            'remark'       => trim((string)$this->request->post('remark', '')),
        ];
        if (!isset(self::STATUS[$data['status']])) {
            return json(r_error('项目状态不合法', 400));
        }

        // 需求明细（可选；传入则整体覆盖）
        $reqs = $this->request->post('reqs/a', null);
        $reqs = is_array($reqs) ? $reqs : null;
        $custom = $this->request->post('custom');
        // 闭包内不可用 $this，提前取出
        $userId = (int)($this->request->user['id'] ?? 0);

        // v2.78：证书类型/专业组合预校验（事务外，便于直接返回 400）
        if (is_array($reqs)) {
            foreach ($reqs as $k => $r) {
                $certName = trim((string)($r['cert_name'] ?? ''));
                if ($certName === '') {
                    continue;
                }
                $pair = CertDictService::normalizePair((string)($r['cert_type'] ?? ''), $certName);
                if ($pair['error'] !== '') {
                    return json(r_error('需求第' . ($k + 1) . '条：' . $pair['error'], 400));
                }
                $reqs[$k]['cert_type'] = $pair['type'];
                $reqs[$k]['cert_name'] = $pair['name'];
            }
        }

        Db::transaction(function () use (&$id, $data, $reqs, $userId, $custom) {
            if ($id) {
                Db::name('project')->where('id', $id)->update($data);
            } else {
                $data['create_by'] = $userId;
                $id = Db::name('project')->insertGetId($data);
            }

            if (is_array($reqs)) {
                Db::name('project_cert_req')->where('project_id', $id)->delete();
                foreach ($reqs as $r) {
                    $certName = trim((string)($r['cert_name'] ?? ''));
                    if ($certName === '') {
                        continue;
                    }
                    // v2.36 字典去重：需求行不再单独录入「证书类别」，
                    // 类别以证书类型为准同步（类型为空时保留前端传入的类别值以兼容旧调用方）。
                    $reqType = trim((string)($r['cert_type'] ?? ''));
                    $reqCat  = trim((string)($r['cert_category'] ?? ''));
                    if ($reqType !== '' && $reqCat === '') {
                        $reqCat = $reqType;
                    }
                    Db::name('project_cert_req')->insert([
                        'project_id'      => $id,
                        'cert_name'       => $certName,
                        'cert_category'   => $reqCat,
                        'cert_type'       => $reqType,
                        'need_count'      => max(1, (int)($r['need_count'] ?? 1)),
                        'matched_count'   => 0,
                        'require_social'  => in_array((int)($r['require_social'] ?? 0), [0, 1, 2], true) ? (int)($r['require_social'] ?? 0) : 0,
                        'min_expire_days' => max(0, (int)($r['min_expire_days'] ?? 0)),
                        'remark'          => trim((string)($r['remark'] ?? '')),
                    ]);
                }
            }
        });

        // 自定义字段值保存（与记录同事务外置，避免事务内异常吞掉）
        if (is_array($custom)) {
            CustomFieldService::saveValues('project', (int)$id, $custom);
        }

        $this->log('项目管理', $id ? '更新' : '新增', $name);
        return json(r_success(['id' => $id], $id ? '更新成功' : '新增成功'));
    }

    public function delete()
    {
        $id  = (int)$this->request->post('id', 0);
        $row = Db::name('project')->where('id', $id)->find();
        if (!$row) {
            return json(r_error('项目不存在', 400));
        }
        $this->assertCompanyAccess((int)($row['client_id'] ?? 0));
        $this->assertModuleAccess('project', $id);
        // v2.52：删除前快照进回收站（配证任务链 / 配证需求 / 匹配记录 / 自定义值随删，一并快照）
        $this->stashProjectGraph($id, $row);
        Db::transaction(function () use ($id) {
            Db::name('project_cert_req')->where('project_id', $id)->delete();
            Db::name('match_record')->where('project_id', $id)->delete();
            // v2.30：配证任务从属于项目，级联删除（含明细与动态）
            $taskIds = Db::name('cert_task')->where('project_id', $id)->column('id');
            if ($taskIds) {
                Db::name('cert_task_item')->whereIn('task_id', $taskIds)->delete();
                Db::name('cert_task_log')->whereIn('task_id', $taskIds)->delete();
                Db::name('cert_task')->whereIn('id', $taskIds)->delete();
            }
            Db::name('project')->where('id', $id)->delete();
        });
        CustomFieldService::deleteValues('project', $id);
        $this->log('项目管理', '删除', $row['project_name'] ?? "#{$id}");
        return json(r_success([], '已删除'));
    }

    public function batchDelete()
    {
        $ids = $this->request->post('ids/a', []);
        $ids = array_values(array_filter(array_map('intval', (array)$ids)));
        if (!$ids) {
            return json(r_error('请选择要删除的项目', 400));
        }
        $clientMap = Db::name('project')->whereIn('id', $ids)->column('client_id', 'id');
        foreach ($ids as $pid) {
            if (!isset($clientMap[$pid])
                || !$this->canAccessCompany((int)($clientMap[$pid] ?? 0))
                || !$this->canAccessModule('project', $pid)) {
                return json(r_error('存在无权删除的项目，请刷新后重试', 403));
            }
        }
        // v2.52：删除前快照进回收站（逐项目：配证任务链 / 需求 / 匹配记录 / 自定义值）
        foreach ($ids as $pid) {
            $rb = Db::name('project')->where('id', $pid)->find();
            if ($rb) {
                $this->stashProjectGraph($pid, $rb);
            }
        }
        Db::transaction(function () use ($ids) {
            Db::name('project_cert_req')->whereIn('project_id', $ids)->delete();
            Db::name('match_record')->whereIn('project_id', $ids)->delete();
            // v2.30：级联删除项目名下配证任务（含明细与动态）
            $taskIds = Db::name('cert_task')->whereIn('project_id', $ids)->column('id');
            if ($taskIds) {
                Db::name('cert_task_item')->whereIn('task_id', $taskIds)->delete();
                Db::name('cert_task_log')->whereIn('task_id', $taskIds)->delete();
                Db::name('cert_task')->whereIn('id', $taskIds)->delete();
            }
            Db::name('project')->whereIn('id', $ids)->delete();
        });
        foreach ($ids as $pid) {
            CustomFieldService::deleteValues('project', (int)$pid);
        }
        $this->log('项目管理', '删除', count($ids) . ' 个');
        return json(r_success([], '已删除 ' . count($ids) . ' 个'));
    }

    /** v2.52：项目整图快照进回收站（主行 + 需求/匹配/任务链 + 自定义值；失败不阻断删除） */
    private function stashProjectGraph(int $id, array $row): void
    {
        try {
            $taskIds = Db::name('cert_task')->where('project_id', $id)->column('id');
            $related = [
                ['table' => 'project_cert_req', 'rows' => \app\service\RecycleBinService::grab('project_cert_req', 'project_id', $id)],
                ['table' => 'match_record', 'rows' => \app\service\RecycleBinService::grab('match_record', 'project_id', $id)],
            ];
            if ($taskIds) {
                $related[] = ['table' => 'cert_task', 'rows' => Db::name('cert_task')->whereIn('id', $taskIds)->select()->toArray()];
                $related[] = ['table' => 'cert_task_item', 'rows' => Db::name('cert_task_item')->whereIn('task_id', $taskIds)->select()->toArray()];
                $related[] = ['table' => 'cert_task_log', 'rows' => Db::name('cert_task_log')->whereIn('task_id', $taskIds)->select()->toArray()];
            }
            $custom = \app\service\RecycleBinService::grabCustom('project', $id);
            if ($custom) {
                $related[] = $custom[0];
            }
            \app\service\RecycleBinService::stash(
                (int)$this->request->userId,
                'project',
                ((string)($row['project_name'] ?? '') ?: (string)($row['project_no'] ?? '')) ?: ("#" . $id),
                $row,
                $related
            );
        } catch (\Throwable $e) {
            // 快照失败不阻断删除
        }
    }

    /** 批量修改项目状态（如批量结束/暂停） */
    public function batchStatus()
    {
        $ids    = array_values(array_filter(array_map('intval', (array)$this->request->post('ids/a', []))));
        $status = (int)$this->request->post('status', -1);
        if (!$ids) {
            return json(r_error('请选择项目', 400));
        }
        if (!isset(self::STATUS[$status])) {
            return json(r_error('项目状态不合法', 400));
        }
        $clientMap = Db::name('project')->whereIn('id', $ids)->column('client_id', 'id');
        foreach ($ids as $pid) {
            if (!isset($clientMap[$pid])
                || !$this->canAccessCompany((int)($clientMap[$pid] ?? 0))
                || !$this->canAccessModule('project', $pid)) {
                return json(r_error('存在无权修改的项目，请刷新后重试', 403));
            }
        }
        Db::name('project')->whereIn('id', $ids)->update(['status' => $status]);
        $this->log('项目管理', '批量状态', count($ids) . ' 个 → ' . self::STATUS[$status]);
        return json(r_success([], '已更新 ' . count($ids) . ' 个项目状态'));
    }

    /** 下拉选项（项目选择器） */
    public function options()
    {
        $q = Db::name('project')->order('id', 'desc')->limit(200);
        $q = $this->applyModuleScope($q, 'project', 'create_by', 'client_id');
        $list = $q->select()->toArray();
        return json(r_success($list));
    }

    /** 下载导入模板（表头 + 一行示例，含启用中的自定义字段列） */
    public function template()
    {
        $path = ExcelService::template('project');
        return download_utf8($path, '项目导入模板.xlsx');
    }

    /** 导出项目清单（含需求统计与自定义字段） */
    public function export()
    {
        PersonProfileService::ensureTables();
        $q = Db::name('project')->alias('p')
            ->leftJoin('client_company c', 'c.id = p.client_id')
            ->field('p.*, c.company_name')
            ->order('p.id', 'desc');
        $q = $this->applyModuleScope($q, 'project', 'p.create_by', 'p.client_id');
        $rows = $q->select()->toArray();
        CustomFieldService::attachList('project', $rows);
        $ids = array_column($rows, 'id');
        $reqMap = [];
        if ($ids) {
            $reqs = Db::name('project_cert_req')->whereIn('project_id', $ids)->select()->toArray();
            foreach ($reqs as $r) {
                $reqMap[(int)$r['project_id']][] = $r;
            }
        }
        $statusLabels = $this->statusLabels();
        $formatted = [];
        foreach ($rows as $p) {
            $reqs = $reqMap[(int)$p['id']] ?? [];
            $reqText = [];
            foreach ($reqs as $r) {
                $reqText[] = $r['cert_name'] . '×' . (int)$r['need_count'];
            }
            $formatted[] = [
                'project_name' => $p['project_name'],
                'project_no'   => $p['project_no'] ?? '',
                'company_name' => $p['company_name'] ?? '',
                'region_name'  => $p['region_name'] ?? '',
                'region_code'  => $p['region_code'] ?? '',
                'start_date'   => $p['start_date'] ?? '',
                'end_date'     => $p['end_date'] ?? '',
                'status_text'  => $statusLabels[(int)$p['status']] ?? '',
                'reqs_text'    => implode(';', $reqText),
                'remark'       => $p['remark'] ?? '',
                'custom'       => $p['custom'] ?? [],
            ];
        }
        $path = ExcelService::export('project', $formatted);
        return download_utf8($path, '项目清单_' . date('Ymd') . '.xlsx');
    }

    /**
     * 项目统计：状态分布 + 需求满足率 + 属地分布
     */
    public function stat()
    {
        PersonProfileService::ensureTables();
        $projectQ = function () {
            return $this->applyModuleScope(Db::name('project'), 'project', 'create_by', 'client_id');
        };
        $reqQ = function () {
            $q = Db::name('project_cert_req')->alias('r')
                ->join('project p', 'p.id = r.project_id');
            return $this->applyModuleScope($q, 'project', 'p.create_by', 'p.client_id');
        };

        $statusStat = $projectQ()->field('status, COUNT(*) AS cnt')->group('status')->select()->toArray();
        foreach ($statusStat as &$s) {
            $s['status']      = (int)$s['status'];
            $s['status_text'] = $this->statusLabels()[$s['status']] ?? '未知';
            $s['cnt']         = (int)$s['cnt'];
        }
        unset($s);

        $need    = (int)$reqQ()->sum('r.need_count');
        $matched = (int)$reqQ()->sum('r.matched_count');

        $regionStat = $projectQ()
            ->where('region_name', '<>', '')
            ->field('region_name, COUNT(*) AS cnt')->group('region_name')
            ->order('cnt', 'desc')->limit(10)->select()->toArray();

        return json(r_success([
            'status_stat' => $statusStat,
            'region_stat' => $regionStat,
            'summary'     => [
                'project_total'   => (int)$projectQ()->count(),
                'req_total'       => (int)$reqQ()->count(),
                'need_total'      => $need,
                'matched_total'   => $matched,
                'match_rate'      => $need > 0 ? round($matched * 100 / $need, 1) : 0,
            ],
        ]));
    }
}