<?php
namespace app\controller\admin;

use app\model\CertStatusLog as CertStatusLogModel;
use app\model\Certificate;
use app\service\CertStatusLogService;

class CertStatusLog extends AdminController
{
    public function index()
    {
        CertStatusLogService::ensureSchema();
        [$page, $limit] = $this->pageParams();
        $certId  = (int)$this->request->param('cert_id', 0);
        $bizType = (string)$this->request->param('biz_type', '');
        $q = CertStatusLogModel::with(['certificate'])->order('id', 'desc');
        // v2.76：按业务类型筛选（cert 证书状态 / secondment 借调状态）
        if (in_array($bizType, [CertStatusLogService::BIZ_CERT, CertStatusLogService::BIZ_SECONDMENT], true)) {
            $q->where('biz_type', $bizType);
        }
        if ($certId) {
            $this->assertCertAccess($certId);
            $q->where('cert_id', $certId);
        } elseif (!$this->isAdmin()) {
            $cq = Certificate::where('id', '>', 0);
            $this->scopeCert($cq);
            $q->whereIn('cert_id', $cq->column('id') ?: [0]);
        }
        $total = $q->count();
        $list  = $q->page($page, $limit)->select()->toArray();
        foreach ($list as &$item) {
            if (!empty($item['certificate']) && is_array($item['certificate'])) {
                $item['certificate'] = \app\service\MaskService::certFor($item['certificate'], $this->request->user ?? []);
            }
            $item['biz_type'] = $item['biz_type'] ?? CertStatusLogService::BIZ_CERT;
            $item['old_status_text'] = $item['biz_type'] === CertStatusLogService::BIZ_SECONDMENT
                ? CertStatusLogService::secondmentStatusText((int)$item['old_status'])
                : $this->certStatusText((int)$item['old_status']);
            $item['new_status_text'] = $item['biz_type'] === CertStatusLogService::BIZ_SECONDMENT
                ? CertStatusLogService::secondmentStatusText((int)$item['new_status'])
                : $this->certStatusText((int)$item['new_status']);
        }
        unset($item);
        return json(r_success(['list' => $list, 'total' => $total]));
    }

    private function certStatusText(int $status): string
    {
        return [1 => '正常', 0 => '已过期', 2 => '挂失', 3 => '注销'][$status] ?? '未知';
    }
}
