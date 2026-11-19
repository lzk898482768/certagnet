<?php
namespace app\controller\admin;

use app\model\OperationLog as OperationLogModel;
use app\service\OperationLogService;

class OperationLog extends AdminController
{
    /** 列表：支持关键字 / 模块 / 结果 / 时间范围筛选 */
    public function index()
    {
        $this->requireAdmin();
        // 老库自愈：确保 target/status/device_name 等审计扩展列存在
        OperationLogService::ensureColumns();

        [$page, $limit] = $this->pageParams();
        $module  = trim((string)$this->request->param('module', ''));
        $keyword = trim((string)$this->request->param('keyword', ''));
        $status  = (string)$this->request->param('status', '');
        $method  = strtoupper(trim((string)$this->request->param('method', '')));
        $start   = trim((string)$this->request->param('start', ''));
        $end     = trim((string)$this->request->param('end', ''));

        $q = OperationLogModel::order('id', 'desc');

        if ($module !== '') {
            $q->whereLike('module', '%' . $module . '%');
        }
        if ($method !== '') {
            $q->where('method', $method);
        }
        if ($status === '0' || $status === '1') {
            $q->where('status', (int)$status);
        }
        if ($start !== '') {
            $q->where('create_time', '>=', $this->dayStart($start));
        }
        if ($end !== '') {
            $q->where('create_time', '<=', $this->dayEnd($end));
        }
        if ($keyword !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $keyword) . '%';
            $q->whereRaw(
                '(username LIKE ? OR module LIKE ? OR action LIKE ? OR target LIKE ? OR url LIKE ? OR remark LIKE ? OR result_msg LIKE ?)',
                array_fill(0, 7, $like)
            );
        }

        $total = $q->count();
        $list  = $q->page($page, $limit)->select()->toArray();
        return json(r_success(['list' => $list, 'total' => $total]));
    }

    public function clear()
    {
        if (!$this->isAdmin()) {
            return json(r_error('仅管理员可清理', 403));
        }
        $count = (int)OperationLogModel::count();
        OperationLogModel::where('id', '>', 0)->delete();
        // 清空动作本身也要留痕（写入一条新的审计记录）
        $this->log('操作日志', '清空', "清空 {$count} 条历史日志");
        return json(r_success(['deleted' => $count], '已清空'));
    }

    /** 兼容 Y-m-d / Y-m-d H:i:s 两种入参 */
    private function dayStart(string $v): string
    {
        return strlen($v) <= 10 ? $v . ' 00:00:00' : $v;
    }

    private function dayEnd(string $v): string
    {
        return strlen($v) <= 10 ? $v . ' 23:59:59' : $v;
    }
}
