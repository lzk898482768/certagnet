<?php
namespace app\controller\admin;

use app\service\ExamSchemaService;
use think\facade\Db;

/**
 * 报名-批次 / 考点（v2.85）
 *
 *  - GET  /admin/exam-batch/rooms          考点列表
 *  - POST /admin/exam-batch/room-save       考点新增/编辑
 *  - POST /admin/exam-batch/room-delete     考点删除
 *  - GET  /admin/exam-batch/room-options    考点下拉
 *  - GET  /admin/exam-batch                 批次列表（父路径）
 *  - GET  /admin/exam-batch/detail          批次详情（含已报名人数）
 *  - GET  /admin/exam-batch/options         开放报名批次下拉
 *  - POST /admin/exam-batch/save            批次新增/编辑
 *  - POST /admin/exam-batch/delete          批次删除
 *  - POST /admin/exam-batch/current         设为当前批次
 */
class ExamBatch extends AdminController
{
    protected function boot(): void
    {
        ExamSchemaService::ensureTables();
    }

    // ---------------- 考点 ----------------

    public function rooms()
    {
        $this->boot();
        $list = Db::name('exam_room')->where('status', 1)->order('sort asc,id asc')->select()->toArray();
        return json(r_success(['list' => $list]));
    }

    public function roomOptions()
    {
        $this->boot();
        $list = Db::name('exam_room')->where('status', 1)
            ->field('id,name,address,contact,phone')->order('sort asc,id asc')->select()->toArray();
        return json(r_success(['list' => $list]));
    }

    public function roomSave()
    {
        $this->boot();
        if (!$this->can('exambatch', 'edit')) {
            return json(r_error('无保存权限', 403));
        }
        $p = $this->request->param();
        $id = (int)($p['id'] ?? 0);
        $name = trim((string)($p['name'] ?? ''));
        if ($name === '') {
            return json(r_error('请填写考点名称'));
        }
        $data = [
            'name'    => mb_substr($name, 0, 100),
            'address' => mb_substr((string)($p['address'] ?? ''), 0, 200),
            'contact' => mb_substr((string)($p['contact'] ?? ''), 0, 50),
            'phone'   => mb_substr((string)($p['phone'] ?? ''), 0, 20),
            'sort'    => (int)($p['sort'] ?? 0),
            'status'  => 1,
        ];
        if ($id > 0) {
            Db::name('exam_room')->where('id', $id)->update($data + ['update_time' => date('Y-m-d H:i:s')]);
        } else {
            $data['create_by'] = (int)$this->request->userId;
            $data['create_time'] = date('Y-m-d H:i:s');
            $id = (int)Db::name('exam_room')->insertGetId($data);
        }
        $this->log('报名批次', '保存考点', $data['name']);
        return json(r_success(['id' => $id], '保存成功'));
    }

    public function roomDelete()
    {
        $this->boot();
        if (!$this->can('exambatch', 'delete')) {
            return json(r_error('无删除权限', 403));
        }
        $id = (int)$this->request->param('id', 0);
        if (Db::name('exam_batch')->where('room_id', $id)->where('status', 1)->count() > 0) {
            return json(r_error('该考点已被批次使用，不能删除'));
        }
        Db::name('exam_room')->where('id', $id)->update(['status' => 0, 'update_time' => date('Y-m-d H:i:s')]);
        $this->log('报名批次', '删除考点', (string)$id);
        return json(r_success([], '删除成功'));
    }

    // ---------------- 批次 ----------------

    public function index()
    {
        $this->boot();
        [$page, $limit] = $this->pageParams();
        $q = Db::name('exam_batch')->alias('b')->where('b.status', 1);

        $keyword = trim((string)$this->request->param('keyword', ''));
        if ($keyword !== '') {
            $q->where('b.name', 'like', "%{$keyword}%");
        }
        $configId = (int)$this->request->param('biz_config_id', 0);
        if ($configId > 0) {
            $q->where('b.biz_config_id', $configId);
        }
        if ($this->request->has('sign_open')) {
            $so = $this->request->param('sign_open');
            if ($so !== '' && $so !== null) {
                $q->where('b.sign_open', (int)$so);
            }
        }

        $total = (clone $q)->count();
        $list = (clone $q)
            ->leftJoin('exam_room r', 'r.id = b.room_id')
            ->field('b.*,r.name room_name')
            ->order('b.is_current desc,b.id desc')->page($page, $limit)->select()->toArray();

        // 批次业务名 + 已报名人数
        $configNames = Db::name('exam_biz_config')->column('name', 'id');
        foreach ($list as &$row) {
            $row['config_name'] = $configNames[$row['biz_config_id']] ?? '';
            $row['signed_count'] = Db::name('exam_signup')
                ->where('batch_id', $row['id'])->where('status', 1)->where('is_draft', 0)->count();
            $row['remain_quota'] = $row['quota'] > 0 ? max(0, (int)$row['quota'] - (int)$row['signed_count']) : -1;
        }
        unset($row);
        return json(r_success(['list' => $list, 'total' => $total]));
    }

    public function detail()
    {
        $this->boot();
        $id = (int)$this->request->param('id', 0);
        $row = Db::name('exam_batch')->find($id);
        if (!$row || (int)$row['status'] !== 1) {
            return json(r_error('批次不存在'));
        }
        return json(r_success($row));
    }

    /** 当前开放报名的批次下拉（报名单用） */
    public function options()
    {
        $this->boot();
        $configId = (int)$this->request->param('biz_config_id', 0);
        $q = Db::name('exam_batch')->where('status', 1)->where('sign_open', 1);
        if ($configId > 0) {
            $q->where(function ($w) use ($configId) {
                $w->where('biz_config_id', $configId)->whereOr('biz_config_id', 0);
            });
        }
        $now = date('Y-m-d H:i:s');
        $list = $q->field('id,name,biz_config_id,room_id,sign_start,sign_end,exam_date,quota,fee')
            ->order('is_current desc,id desc')->limit(200)->select()->toArray();
        $configNames = Db::name('exam_biz_config')->column('name', 'id');
        foreach ($list as &$r) {
            $r['config_name'] = $configNames[$r['biz_config_id']] ?? '通用批次';
            $r['sign_active'] = (!$r['sign_start'] || $r['sign_start'] <= $now)
                && (!$r['sign_end'] || $r['sign_end'] >= $now);
        }
        unset($r);
        return json(r_success(['list' => $list]));
    }

    public function save()
    {
        $this->boot();
        if (!$this->can('exambatch', 'edit')) {
            return json(r_error('无保存权限', 403));
        }
        $p = $this->request->param();
        $id = (int)($p['id'] ?? 0);
        $name = trim((string)($p['name'] ?? ''));
        if ($name === '') {
            return json(r_error('请填写批次名称'));
        }
        $data = [
            'name'          => mb_substr($name, 0, 120),
            'biz_config_id' => (int)($p['biz_config_id'] ?? 0),
            'room_id'       => (int)($p['room_id'] ?? 0),
            'sign_start'    => $this->dtOrNull($p['sign_start'] ?? ''),
            'sign_end'      => $this->dtOrNull($p['sign_end'] ?? ''),
            'exam_date'     => $this->dtOrNull($p['exam_date'] ?? ''),
            'quota'         => max(0, (int)($p['quota'] ?? 0)),
            'fee'           => round((float)($p['fee'] ?? 0), 2),
            'sign_open'     => (int)($p['sign_open'] ?? 1) ? 1 : 0,
            'is_current'    => (int)($p['is_current'] ?? 0) ? 1 : 0,
            'sort'          => (int)($p['sort'] ?? 0),
            'remark'        => mb_substr((string)($p['remark'] ?? ''), 0, 255),
            'status'        => 1,
        ];
        if ($id > 0) {
            Db::name('exam_batch')->where('id', $id)->update($data + ['update_time' => date('Y-m-d H:i:s')]);
        } else {
            $data['create_by'] = (int)$this->request->userId;
            $data['create_time'] = date('Y-m-d H:i:s');
            $id = (int)Db::name('exam_batch')->insertGetId($data);
        }
        if ($data['is_current']) {
            Db::name('exam_batch')->where('id', '<>', $id)->update(['is_current' => 0]);
        }
        $this->log('报名批次', '保存批次', $data['name']);
        return json(r_success(['id' => $id], '保存成功'));
    }

    public function delete()
    {
        $this->boot();
        if (!$this->can('exambatch', 'delete')) {
            return json(r_error('无删除权限', 403));
        }
        $id = (int)$this->request->param('id', 0);
        if (Db::name('exam_signup')->where('batch_id', $id)->where('status', 1)->count() > 0) {
            return json(r_error('该批次已有报名单，不能删除（可关闭报名）'));
        }
        Db::name('exam_batch')->where('id', $id)->update(['status' => 0, 'update_time' => date('Y-m-d H:i:s')]);
        $this->log('报名批次', '删除批次', (string)$id);
        return json(r_success([], '删除成功'));
    }

    public function current()
    {
        $this->boot();
        if (!$this->can('exambatch', 'edit')) {
            return json(r_error('无操作权限', 403));
        }
        $id = (int)$this->request->param('id', 0);
        Db::name('exam_batch')->where('id', '<>', $id)->update(['is_current' => 0]);
        Db::name('exam_batch')->where('id', $id)->update(['is_current' => 1, 'update_time' => date('Y-m-d H:i:s')]);
        $this->log('报名批次', '设置当前批次', (string)$id);
        return json(r_success([], '操作成功'));
    }

    private function dtOrNull($v): ?string
    {
        $v = trim((string)$v);
        if ($v === '') {
            return null;
        }
        $ts = strtotime($v);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }
}
