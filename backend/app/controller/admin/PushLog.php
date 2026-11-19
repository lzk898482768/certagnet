<?php
namespace app\controller\admin;

use app\model\PushMsgLog;

class PushLog extends AdminController
{
    public function index()
    {
        [$page, $limit] = $this->pageParams();
        $companyId = (int)$this->request->param('company_id', 0);
        $status    = $this->request->param('send_status', '');
        // 来源：''全部 / '0'正式 / '1'测试推送
        $isTest    = $this->request->param('is_test', '');

        $q = PushMsgLog::with(['channel', 'certificate', 'company'])->order('id', 'desc');
        $q = $this->applyCompanyScope($q);
        if ($companyId) {
            $q->where('company_id', $companyId);
        }
        if ($status !== '') {
            $q->where('send_status', $status);
        }
        if ($isTest !== '') {
            $q->where('is_test', (int)$isTest);
        }
        $total = $q->count();
        $list  = $q->page($page, $limit)
            ->select()
            ->append(['send_status_text', 'is_test_text', 'channel_type_text', 'is_simulated'])
            ->toArray();
        return json(r_success(['list' => $list, 'total' => $total]));
    }

    /** 清空推送日志（保留最近 N 天可选） */
    public function clear()
    {
        $days = (int)$this->request->post('days', 0);
        $q    = PushMsgLog::where('id', '>', 0);
        $q    = $this->applyCompanyScope($q);
        if ($days > 0) {
            $q->where('create_time', '<', date('Y-m-d H:i:s', strtotime("-{$days} days")));
        }
        $n = $q->delete();
        $this->log('推送日志', '清空', "删除 {$n} 条");
        return json(r_success([], "已清空 {$n} 条日志"));
    }
}
