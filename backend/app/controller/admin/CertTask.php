<?php
namespace app\controller\admin;

use app\model\CertTask as CertTaskModel;
use app\model\CertTaskItem;
use app\model\CertTaskLog;
use app\model\ClientCompany;
use app\service\CertDictService;
use app\service\TaskProgressService;
use think\facade\Db;

/**
 * 配证任务
 * 发布人发布任务：哪家企业需要哪些证书、各需要几个；可选择可见范围。
 * 参与人可实时更新每条明细的已配数量（进度）与备注，并添加任务动态。
 */
class CertTask extends AdminController
{
    /** 任务列表（支持状态/企业/项目/关键字筛选 + 可见范围） */
    public function index()
    {
        $q = CertTaskModel::order('id', 'desc');
        $status = $this->request->param('status', '');
        if ($status !== '') {
            $q->where('status', $status);
        }
        $companyId = (int)$this->request->param('company_id', 0);
        if ($companyId) {
            $q->where('company_id', $companyId);
        }
        // 按项目筛选（v2.30：配证任务并入项目模块后，项目详情内嵌任务列表）
        $projectId = (int)$this->request->param('project_id', 0);
        if ($projectId) {
            $q->where('project_id', $projectId);
        }
        $keyword = trim((string)$this->request->param('keyword', ''));
        if ($keyword !== '') {
            $q->where('title', 'like', "%{$keyword}%");
        }
        // 可见范围：非管理员仅看「全员可见」「自己发布的」「指定到自己/自己部门」的
        if (!$this->isAdmin()) {
            $uid = (int)$this->request->userId;
            // 部门匹配语义是「选中部门及其下属子部门可见」：
            // 把用户所在部门向上展开为祖先链，选中部门命中祖先链即视为可见
            $deptIds = \app\service\DeptScopeService::ancestorIds(
                \app\service\DeptScopeService::userDeptIds($uid)
            );
            $q->where(function ($w) use ($uid, $deptIds) {
                $w->where('visible_scope', 'all')->whereOr('publisher_id', $uid);
                // 指定人员：FIND_IN_SET 命中当前账号
                $w->whereOr(function ($x) use ($uid) {
                    $x->where('visible_scope', 'user')
                      ->whereRaw('FIND_IN_SET(?, visible_user_ids)', [(string)$uid]);
                });
                // 指定部门：命中当前账号所属部门的祖先链（即「选中部门及其子部门」）
                if ($deptIds) {
                    $w->whereOr(function ($x) use ($deptIds) {
                        $x->where('visible_scope', 'dept')
                          ->where(function ($y) use ($deptIds) {
                              foreach ($deptIds as $d) {
                                  $y->whereOrRaw('FIND_IN_SET(?, visible_dept_ids)', [(string)(int)$d]);
                              }
                          });
                    });
                }
            });
        }
        $list = $q->select()->toArray();
        // v2.32：支持分页（page/limit），兼容前端全量调用（不传时默认第 1 页 15 条）
        $total = count($list);
        [$page, $limit] = $this->pageParams();
        $list = array_slice($list, ($page - 1) * $limit, $limit);
        $ids  = array_column($list, 'id');
        $items = $ids ? CertTaskItem::whereIn('task_id', $ids)->select()->toArray() : [];
        $itemMap = [];
        foreach ($items as $it) {
            $itemMap[$it['task_id']][] = $it;
        }
        foreach ($list as &$t) {
            $its = $itemMap[$t['id']] ?? [];
            $t['item_count'] = count($its);
            $t['need_total']  = array_sum(array_column($its, 'need_count'));
            $t['done_total']  = array_sum(array_column($its, 'done_count'));
            $t['company_name'] = $t['company_id'] ? (ClientCompany::where('id', $t['company_id'])->value('company_name') ?: '') : '';
            $t['project_name'] = !empty($t['project_id']) ? (Db::name('project')->where('id', (int)$t['project_id'])->value('project_name') ?: '') : '';
        }
        unset($t);
        return json(r_success(['list' => $list, 'total' => $total, 'page' => $page, 'limit' => $limit]));
    }

    /** 任务详情（含明细 + 动态） */
    public function detail()
    {
        $id = (int)$this->request->param('id', 0);
        $task = CertTaskModel::find($id);
        if (!$task) {
            return json(r_error('任务不存在', 400));
        }
        $data = $task->toArray();
        $data['items'] = CertTaskItem::where('task_id', $id)->order('id', 'asc')->select()->toArray();
        $data['logs']  = CertTaskLog::where('task_id', $id)->order('id', 'desc')->select()->toArray();
        $data['company_name'] = $data['company_id'] ? (ClientCompany::where('id', $data['company_id'])->value('company_name') ?: '') : '';
        // 关联项目（v2.29）
        $data['project_name'] = !empty($data['project_id']) ? (Db::name('project')->where('id', (int)$data['project_id'])->value('project_name') ?: '') : '';
        // 可见范围回显：把逗号串转数组 + 名称（v2.16）
        $data['visible_dept_ids'] = array_values(array_filter(array_map('intval', explode(',', (string)($data['visible_dept_ids'] ?? '')))));
        $data['visible_user_ids'] = array_values(array_filter(array_map('intval', explode(',', (string)($data['visible_user_ids'] ?? '')))));
        if ($data['visible_dept_ids']) {
            $data['visible_dept_names'] = \app\model\OrgDepartment::whereIn('id', $data['visible_dept_ids'])
                ->column('name');
        } else {
            $data['visible_dept_names'] = [];
        }
        if ($data['visible_user_ids']) {
            $data['visible_user_names'] = \app\model\SysUser::whereIn('id', $data['visible_user_ids'])
                ->column('real_name');
        } else {
            $data['visible_user_names'] = [];
        }
        return json(r_success($data));
    }

    /** 新增/编辑任务（含明细） */
    public function save()
    {
        $id = (int)$this->request->post('id', 0);
        $title = trim((string)$this->request->post('title', ''));
        if ($title === '') {
            return json(r_error('请输入任务标题', 400));
        }
        $items = $this->request->post('items/a', []) ?: [];
        if (!is_array($items)) {
            $items = [];
        }
        foreach ($items as $k => $it) {
            if (empty($it['cert_name'])) {
                return json(r_error('第' . ($k + 1) . '条明细缺少证书专业', 400));
            }
            $items[$k]['need_count'] = max(1, (int)($it['need_count'] ?? 1));
            $items[$k]['done_count'] = min((int)($it['done_count'] ?? 0), $items[$k]['need_count']);
            // v2.36 字典去重：明细不再单独录入「证书类别」，类别以证书类型为准同步，
            // 保证 category 与 cert_type 两列一致（匹配与进度回写任一维度都能命中）。
            // v2.78：校验「类型 + 专业」归属，专业若已登记在字典中必须落在所选类型下。
            $pair = CertDictService::normalizePair((string)($it['cert_type'] ?? ''), (string)$it['cert_name']);
            if ($pair['error'] !== '') {
                return json(r_error('第' . ($k + 1) . '条明细：' . $pair['error'], 400));
            }
            $items[$k]['cert_type'] = $pair['type'];
            $items[$k]['cert_name'] = $pair['name'];
            $itType = trim((string)$pair['type']);
            if ($itType !== '') {
                $items[$k]['category'] = $itType;
            }
        }

        $data = [
            'title'         => $title,
            'company_id'    => (int)$this->request->post('company_id', 0),
            'project_id'    => (int)$this->request->post('project_id', 0),
            'description'   => trim((string)$this->request->post('description', '')),
            'status'        => (int)$this->request->post('status', 0),
            'visible_scope' => trim((string)$this->request->post('visible_scope', 'all')) ?: 'all',
            'deadline'      => trim((string)$this->request->post('deadline', '')) ?: null,
        ];
        // 项目关联有效性校验（v2.29：配证任务可挂到项目下，形成 项目→任务→配证 链路）
        // v2.30：配证任务并入项目模块后 project_id 为必填；对合并前的历史游离任务
        //       （project_id=0）按需求企业自动归属到唯一匹配的项目，避免数据变孤儿
        if (!$data['project_id'] && $data['company_id']) {
            $matchIds = Db::name('project')->where('client_id', $data['company_id'])->column('id');
            if (count($matchIds) === 1) {
                $data['project_id'] = (int)$matchIds[0];
            }
        }
        if ($data['project_id'] && !Db::name('project')->where('id', $data['project_id'])->find()) {
            return json(r_error('关联项目不存在', 400));
        }
        if ($data['status'] < 0 || $data['status'] > 3) {
            $data['status'] = 0;
        }
        // 可见范围：all 全员 / dept 指定部门 / user 指定人员 / self 仅发布人（v2.16）
        if (!in_array($data['visible_scope'], ['all', 'dept', 'user', 'self'], true)) {
            $data['visible_scope'] = 'all';
        }
        $toCsv = function ($v) {
            $arr = is_array($v) ? $v : explode(',', (string)$v);
            $arr = array_values(array_unique(array_filter(array_map('intval', $arr))));
            return $arr ? implode(',', $arr) : null;
        };
        $data['visible_dept_ids'] = $data['visible_scope'] === 'dept'
            ? $toCsv($this->request->post('visible_dept_ids/a', [])) : null;
        $data['visible_user_ids'] = $data['visible_scope'] === 'user'
            ? $toCsv($this->request->post('visible_user_ids/a', [])) : null;
        if ($data['visible_scope'] === 'dept' && empty($data['visible_dept_ids'])) {
            return json(r_error('选择「指定部门」时请至少选择一个部门', 400));
        }
        if ($data['visible_scope'] === 'user' && empty($data['visible_user_ids'])) {
            return json(r_error('选择「指定人员」时请至少选择一位人员', 400));
        }

        if ($id) {
            // ★ v2.32 T06：编辑分支是 delete + 重建明细，必须先继承来源需求关联，
            //   否则 source_req_id 被清零 → matched_count 回写静默失效。
            $affectedReqIds = $this->inheritSourceReqId($id, $items);
            CertTaskModel::where('id', $id)->update($data);
            CertTaskItem::where('task_id', $id)->delete();
            $this->log('配证任务', '更新', "#{$id} {$title}");
        } else {
            $data['publisher_id']   = (int)$this->request->userId;
            $data['publisher_name'] = $this->request->user['real_name'] ?? ($this->request->user['username'] ?? '');
            $m = CertTaskModel::create($data);
            $id = (int)$m->id;
            $this->log('配证任务', '新增', "#{$id} {$title}");
        }
        // 写入明细
        foreach ($items as $it) {
            $it['task_id'] = $id;
            unset($it['id']);
            CertTaskItem::create($it);
        }
        // v2.32：编辑后按剩余明细重算受影响需求进度（不残留虚高/虚低）
        if (!empty($affectedReqIds)) {
            try {
                TaskProgressService::recalcByReq($affectedReqIds);
            } catch (\Throwable $e) {
                // 重算失败不阻断保存
            }
        }
        // 动态记录
        CertTaskLog::create([
            'task_id'   => $id,
            'item_id'   => 0,
            'user_id'   => (int)$this->request->userId,
            'user_name' => $this->request->user['real_name'] ?? ($this->request->user['username'] ?? ''),
            'content'   => $id ? '更新了任务' : '创建了任务',
        ]);
        return json(r_success(['id' => $id], '保存成功'));
    }

    /** 发布（转为进行中） */
    public function publish()
    {
        $id = (int)$this->request->post('id', 0);
        $task = CertTaskModel::find($id);
        if (!$task) {
            return json(r_error('任务不存在', 400));
        }
        CertTaskModel::where('id', $id)->update(['status' => 1]);
        CertTaskLog::create([
            'task_id'   => $id,
            'item_id'   => 0,
            'user_id'   => (int)$this->request->userId,
            'user_name' => $this->request->user['real_name'] ?? ($this->request->user['username'] ?? ''),
            'content'   => '发布了任务，开始招募',
        ]);
        $this->log('配证任务', '发布', "#{$id}");
        return json(r_success([], '已发布'));
    }

    /** 删除任务 */
    public function delete()
    {
        $id = (int)$this->request->post('id', 0);
        if (!$id) {
            return json(r_error('缺少ID', 400));
        }
        $r = $this->deleteOne($id);
        return $r['ok'] ? json(r_success([], '删除成功')) : json(r_error($r['msg'], 400));
    }

    protected function deleteOne(int $id): array
    {
        // v2.52：删除前快照进回收站（明细与动态随删，一并快照）
        try {
            $rb = CertTaskModel::find($id);
            if ($rb) {
                \app\service\RecycleBinService::stash(
                    (int)$this->request->userId,
                    'cert_task',
                    ((string)($rb['title'] ?? '') ?: ("#" . $id)),
                    $rb->toArray(),
                    array_filter([
                        ['table' => 'cert_task_item', 'rows' => \app\service\RecycleBinService::grab('cert_task_item', 'task_id', $id)],
                        ['table' => 'cert_task_log', 'rows' => \app\service\RecycleBinService::grab('cert_task_log', 'task_id', $id)],
                    ])
                );
            }
        } catch (\Throwable $e) {
            // 快照失败不阻断删除
        }
        CertTaskModel::destroy($id);
        CertTaskItem::where('task_id', $id)->delete();
        CertTaskLog::where('task_id', $id)->delete();
        $this->log('配证任务', '删除', "#{$id}");
        return ['ok' => true, 'msg' => '删除成功'];
    }

    /** 参与人实时更新某条明细的进度/备注 */
    public function updateItem()
    {
        $itemId = (int)$this->request->post('id', 0);
        $item = CertTaskItem::find($itemId);
        if (!$item) {
            return json(r_error('明细不存在', 400));
        }
        $oldDone = (int)$item['done_count'];
        $done = (int)$this->request->post('done_count', -1);
        $newDone = $oldDone;
        if ($done >= 0) {
            $newDone = min($done, (int)$item['need_count']);
            $item->done_count = $newDone;
        }
        $remark = trim((string)$this->request->post('remark', ''));
        if ($remark !== '') {
            $item->remark = $remark;
        }
        $item->save();

        // ★ v2.32：唯一进度写入口 —— 绝对值重算，禁止 inc()/+1
        $prog = null;
        if ($newDone !== $oldDone && $done >= 0) {
            $logContent = $newDone >= $oldDone
                ? '人工配证回写进度：' . $item['cert_name'] . " 已配 {$oldDone}→{$newDone}"
                : '人工调整已配数：' . $item['cert_name'] . " {$oldDone} → {$newDone}，项目进度同步回退";
            try {
                $prog = TaskProgressService::syncReqProgress($itemId, $newDone, $logContent);
            } catch (\Throwable $e) {
                $prog = null;
            }
        }

        CertTaskLog::create([
            'task_id'   => (int)$item['task_id'],
            'item_id'   => $itemId,
            'user_id'   => (int)$this->request->userId,
            'user_name' => $this->request->user['real_name'] ?? ($this->request->user['username'] ?? ''),
            'content'   => '更新进度：' . ($item['cert_name']) . ' 已配 ' . $item->done_count . '/' . $item['need_count'],
        ]);
        if ($prog) {
            $this->log('配证任务', '调整进度',
                "明细#{$itemId} {$oldDone}→{$newDone}，项目需求#{$prog['req_id']} matched {$prog['old_matched']}→{$prog['new_matched']}");
        }
        // 若全部明细完成则自动标记任务完成（统一走 TaskProgressService）
        try {
            TaskProgressService::maybeCompleteTask((int)$item['task_id']);
        } catch (\Throwable $e) {
            // 忽略
        }
        return json(r_success([], '更新成功'));
    }

    /**
     * 编辑任务时继承来源需求关联（R3 防护 · v2.32 T06）
     *
     * CertTask::save() 编辑分支是 delete + 重建，若不继承 source_req_id，
     * 需求关联会静默丢失 → TaskProgressService 回写彻底失效（且无报错）。
     * 策略：delete 之前先取旧明细，按 cert_name + category + cert_type 三键
     *      匹配新提交的 item，继承其 source_req_id；对前端零侵入。
     *
     * @param array $newItems 引用传递，就地补入 source_req_id
     * @return array 受影响的需求 ID 列表
     */
    private function inheritSourceReqId(int $taskId, array &$newItems): array
    {
        $old = Db::name('cert_task_item')->where('task_id', $taskId)->select()->toArray();
        if (!$old) {
            return [];
        }
        // v2.36：由「三键精确匹配」改为「按专业名索引 + 类型维度宽松匹配」。
        // 原因——v2.36 之前建的明细类型是空的，改造后保存会带上类型，
        // 三键从「建筑工程||」变成「建筑工程|二级建造师|二级建造师」，精确匹配直接失配、
        // source_req_id 继承静默失效（无报错，最难排查）。
        // 宽松规则：专业名必须相同；类型维度「两方相等」或「任一方为空」即视为同一需求，
        // 这样既保留「一建建筑工程 vs 二建建筑工程」的区分，又兼容无类型的老数据。
        $byName = [];
        foreach ($old as $o) {
            if ((int)$o['source_req_id'] <= 0) {
                continue;
            }
            $byName[trim((string)$o['cert_name'])][] = [
                'type' => $this->itemTypeOf($o),
                'rid'  => (int)$o['source_req_id'],
            ];
        }
        $reqIds = [];
        foreach ($newItems as $i => $it) {
            if (!empty($it['source_req_id'])) {           // 前端若已传，优先采信
                $reqIds[] = (int)$it['source_req_id'];
                continue;
            }
            $name = trim((string)($it['cert_name'] ?? ''));
            $type = $this->itemTypeOf($it);
            $cands = $byName[$name] ?? [];
            // 第一轮：类型精确相等优先（区分一建/二建同名专业）
            $picked = -1;
            foreach ($cands as $k => $c) {
                if ($c['rid'] > 0 && $c['type'] !== '' && $c['type'] === $type) {
                    $picked = $k;
                    break;
                }
            }
            // 第二轮：放宽到「任一方类型为空」（兼容老数据无类型）
            if ($picked < 0) {
                foreach ($cands as $k => $c) {
                    if ($c['rid'] > 0 && ($c['type'] === '' || $type === '')) {
                        $picked = $k;
                        break;
                    }
                }
            }
            if ($picked >= 0) {
                $rid = $cands[$picked]['rid'];
                $byName[$name][$picked]['rid'] = 0; // 标记已用，避免多条同名需求抢同一来源
                $newItems[$i]['source_req_id'] = $rid;
                $reqIds[] = $rid;
            }
        }
        return array_values(array_unique($reqIds));
    }

    /** 明细的类型维度取值：cert_type 优先，为空回落类别（兼容 v2.36 之前的无类型数据） */
    private function itemTypeOf(array $it): string
    {
        $t = trim((string)($it['cert_type'] ?? ''));
        if ($t !== '') {
            return $t;
        }
        return trim((string)($it['category'] ?? $it['cert_category'] ?? ''));
    }

    /**
     * 批量删除任务（v2.32 补悬空路由：route/app.php 曾指向不存在的方法，前端点即 500）
     */
    public function batchDelete()
    {
        $ids = array_values(array_filter(array_map('intval',
            (array)$this->request->post('ids/a', []))));
        if (!$ids) {
            return json(r_error('请选择要删除的任务', 400));
        }
        // ★ delete 前先取关联需求，删除后重算，否则进度残留虚高
        $reqIds = Db::name('cert_task_item')->whereIn('task_id', $ids)
            ->where('source_req_id', '>', 0)->distinct(true)->column('source_req_id');
        Db::transaction(function () use ($ids) {
            Db::name('cert_task_item')->whereIn('task_id', $ids)->delete();
            Db::name('cert_task_log')->whereIn('task_id', $ids)->delete();
            Db::name('cert_task')->whereIn('id', $ids)->delete();
        });
        if ($reqIds) {
            try {
                TaskProgressService::recalcByReq(array_map('intval', $reqIds));
            } catch (\Throwable $e) {
                // 忽略
            }
        }
        $this->log('配证任务', '批量删除', implode(',', $ids));
        return json(r_success([], '删除成功'));
    }

    /** 添加任务动态/备注 */
    public function addLog()
    {
        $taskId = (int)$this->request->post('task_id', 0);
        $content = trim((string)$this->request->post('content', ''));
        if (!$taskId || $content === '') {
            return json(r_error('任务ID与备注内容必填', 400));
        }
        CertTaskLog::create([
            'task_id'   => $taskId,
            'item_id'   => (int)$this->request->post('item_id', 0),
            'user_id'   => (int)$this->request->userId,
            'user_name' => $this->request->user['real_name'] ?? ($this->request->user['username'] ?? ''),
            'content'   => $content,
        ]);
        return json(r_success([], '已记录'));
    }

    /** 明细全部完成后自动置为已完成 → 已由 TaskProgressService::maybeCompleteTask 统一负责 */
}
