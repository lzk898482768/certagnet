<?php
namespace app\controller\admin;

use app\model\WecomApproval;
use app\model\WecomUser;
use app\service\WecomApprovalService;

/**
 * 工作流审批（后台）
 *
 *  - GET  /admin/approval           审批单列表（status/biz_type/keyword）
 *  - GET  /admin/approval/meta      业务类型 + 可选审批人（企微在职成员）
 *  - GET  /admin/approval/detail    详情
 *  - POST /admin/approval/save      提交审批
 *  - POST /admin/approval/cancel    撤销
 *  - POST /admin/approval/approve   通过（内部审批）
 *  - POST /admin/approval/reject    驳回（内部审批）
 *  - POST /admin/approval/sync      从企微同步状态
 */
class Approval extends AdminController
{
    public function index()
    {
        [$page, $limit] = $this->pageParams();
        $q = WecomApproval::order('id', 'desc');
        $status = $this->request->param('status', '');
        if ($status !== '' && $status !== null) {
            $q->where('status', (int)$status);
        }
        $bizType = trim((string)$this->request->param('biz_type', ''));
        if ($bizType !== '') {
            $q->where('biz_type', $bizType);
        }
        $kw = trim((string)$this->request->param('keyword', ''));
        if ($kw !== '') {
            $q->where('title', 'like', "%{$kw}%");
        }
        // 数据范围：管理员看全部，其他人只看自己提交的 / 审批人是自己的
        if (!$this->isAdmin()) {
            $myWecom = (string)(\app\model\SysUser::find($this->request->userId)->wecom_userid ?? '');
            $q->where(function ($query) use ($myWecom) {
                $query->where('apply_user_id', $this->request->userId);
                if ($myWecom !== '') {
                    $query->whereOr('approver_userid', $myWecom);
                }
            });
        }
        $total = $q->count();
        $list  = $q->page($page, $limit)->select()->toArray();
        return json(r_success(['list' => $list, 'total' => $total]));
    }

    public function meta()
    {
        // 可选审批人：企微在职成员（优先有绑定账号的）
        $approvers = WecomUser::where('status', 1)
            ->order('id', 'asc')
            ->field('wecom_userid,name,position,bind_user_id')
            ->select()->toArray();
        return json(r_success([
            'biz_types' => WecomApproval::BIZ_TYPES,
            'approvers' => $approvers,
        ]));
    }

    public function detail()
    {
        $id = (int)$this->request->param('id', 0);
        $rec = WecomApproval::find($id);
        if (!$rec) return json(r_error('审批单不存在', 404));
        return json(r_success($rec->toArray()));
    }

    public function save()
    {
        try {
            $r = WecomApprovalService::submit(array_merge(
                $this->request->post(),
                ['apply_user_id' => $this->request->userId]
            ));
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage(), 400));
        }
        $this->log('工作流', '提交审批', "#{$r['id']}");
        $msg = (int)$r['source'] === 1
            ? '已提交企微审批（单号 ' . $r['sp_no'] . '）'
            : '已提交内部审批';
        return json(r_success($r, $msg));
    }

    public function cancel()
    {
        $id = (int)$this->request->post('id', 0);
        try {
            WecomApprovalService::cancel($id, $this->request->userId);
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage(), 400));
        }
        $this->log('工作流', '撤销审批', "#{$id}");
        return json(r_success([], '已撤销'));
    }

    public function approve()
    {
        $id = (int)$this->request->post('id', 0);
        $remark = trim((string)$this->request->post('remark', ''));
        try {
            WecomApprovalService::approve($id, $this->request->userId, $remark);
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage(), 400));
        }
        $this->log('工作流', '审批通过', "#{$id}");
        return json(r_success([], '已通过'));
    }

    public function reject()
    {
        $id = (int)$this->request->post('id', 0);
        $remark = trim((string)$this->request->post('remark', ''));
        try {
            WecomApprovalService::reject($id, $this->request->userId, $remark);
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage(), 400));
        }
        $this->log('工作流', '审批驳回', "#{$id}");
        return json(r_success([], '已驳回'));
    }

    public function syncStatus()
    {
        $id = (int)$this->request->post('id', 0);
        try {
            $r = WecomApprovalService::syncWecomStatus($id);
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage(), 400));
        }
        $this->log('工作流', '同步企微状态', "#{$id}");
        return json(r_success($r, !empty($r['changed']) ? '状态已更新' : '状态无变化'));
    }
}
