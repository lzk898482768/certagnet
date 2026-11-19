<?php
namespace app\controller\admin;

use app\model\AlertLog;

class Alert extends AdminController
{
    public function index()
    {
        [$page, $limit] = $this->pageParams();
        $companyId = (int)$this->request->param('company_id', 0);
        $isRead    = $this->request->param('is_read', '');
        $alertType = trim((string)$this->request->param('alert_type', ''));

        \app\service\CertExpireService::ensureAlertLogColsPublic();
        $q = AlertLog::with(['company', 'staff', 'certificate', 'companyQualification'])->order('id', 'desc');
        $q = $this->applyCompanyScope($q);
        if ($companyId) {
            $q->where('company_id', $companyId);
        }
        if ($isRead !== '') {
            $q->where('is_read', $isRead);
        }
        if ($alertType !== '') {
            $q->where('alert_type', $alertType);
        }
        $total = $q->count();
        $list  = $q->page($page, $limit)->select()->toArray();
        foreach ($list as &$item) {
            if (!empty($item['staff']) && is_array($item['staff'])) {
                $item['staff'] = \app\service\MaskService::staffFor($item['staff'], $this->request->user ?? []);
            }
            if (!empty($item['certificate']) && is_array($item['certificate'])) {
                $item['certificate'] = \app\service\MaskService::certFor($item['certificate'], $this->request->user ?? []);
            }
            // 企业证书提醒：关联方法名为驼峰（companyQualification），
            // 归一为蛇形 company_qualification，与其他关联键风格一致，前端统一取蛇形
            if (isset($item['companyQualification']) && !isset($item['company_qualification'])) {
                $item['company_qualification'] = $item['companyQualification'];
                unset($item['companyQualification']);
            }
            // 企业证书提醒：证书名称取关联的安许 / 资质记录，供前端类型化展示
            $cq = $item['company_qualification'] ?? null;
            if (($item['alert_type'] ?? '') === 'company_cert' && is_array($cq)) {
                $item['cert_name'] = $cq['cert_name'] ?? '';
            }
        }
        unset($item);
        return json(r_success(['list' => $list, 'total' => $total]));
    }

    public function read()
    {
        $id = (int)$this->request->post('id', 0);
        AlertLog::where('id', $id)->update(['is_read' => 1]);
        return json(r_success([], '已读'));
    }

    public function readAll()
    {
        $this->applyCompanyScope(AlertLog::where('is_read', 0))->update(['is_read' => 1]);
        return json(r_success([], '全部已读'));
    }

    public function unreadCount()
    {
        $count = $this->applyCompanyScope(AlertLog::where('is_read', 0))->count();
        return json(r_success(['count' => $count]));
    }

    /**
     * 提醒补发：对已有提醒记录按当前渠道配置重新推送
     * 支持单条（id）或批量（ids[]）。不新增站内提醒，只重新产生推送日志。
     */
    public function resend()
    {
        $ids = $this->request->post('ids/a', []);
        $id  = (int)$this->request->post('id', 0);
        if ($id) {
            $ids = [$id];
        }
        $ids = array_values(array_filter(array_map('intval', (array)$ids)));
        if (empty($ids)) {
            return json(r_error('请选择要补发的提醒', 400));
        }
        $results = [];
        $ok = 0;
        foreach ($ids as $lid) {
            $r = \app\service\CertExpireService::resendAlert($lid);
            if (!empty($r['ok'])) {
                $ok++;
            }
            $results[] = $r;
        }
        $this->log('到期提醒', '补发', count($ids) . ' 条（成功 ' . $ok . '）');
        return json(r_success(
            ['results' => $results, 'total' => count($ids), 'ok' => $ok],
            "补发完成：{$ok}/" . count($ids) . ' 成功'
        ));
    }
}
