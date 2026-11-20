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
        // v2.99：预载证书持证人 + 借调记录（含借调人员/企业），让列表直接显示人员姓名、借调企业与借调时间
        $q = CertStatusLogModel::with(['certificate.staff', 'secondment.staff', 'secondment.company'])->order('id', 'desc');
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
        $user  = $this->request->user ?? [];
        foreach ($list as &$item) {
            if (!empty($item['certificate']) && is_array($item['certificate'])) {
                $item['certificate'] = \app\service\MaskService::certFor($item['certificate'], $user);
            }
            $item['biz_type'] = $item['biz_type'] ?? CertStatusLogService::BIZ_CERT;
            $item['old_status_text'] = $item['biz_type'] === CertStatusLogService::BIZ_SECONDMENT
                ? CertStatusLogService::secondmentStatusText((int)$item['old_status'])
                : $this->certStatusText((int)$item['old_status']);
            $item['new_status_text'] = $item['biz_type'] === CertStatusLogService::BIZ_SECONDMENT
                ? CertStatusLogService::secondmentStatusText((int)$item['new_status'])
                : $this->certStatusText((int)$item['new_status']);

            // v2.99：借调状态日志补「人员姓名 / 借调企业 / 借调起止时间」快照，前端无需再只显示 #ID。
            // 借调记录可能已被删除，secondment 为空时回退到证书持证人，保证人员姓名仍可见。
            if ($item['biz_type'] === CertStatusLogService::BIZ_SECONDMENT) {
                $sec = $item['secondment'] ?? null;
                $staffName = '';
                if (is_array($sec) && !empty($sec['staff']) && is_array($sec['staff'])) {
                    $sec['staff'] = \app\service\MaskService::staff($sec['staff'], false, $user);
                    $staffName = $sec['staff']['staff_name'] ?? '';
                } elseif (!empty($item['certificate']['staff']) && is_array($item['certificate']['staff'])) {
                    $staffName = $item['certificate']['staff']['staff_name'] ?? '';
                }
                $item['staff_name']     = $staffName;
                $item['company_name']   = (is_array($sec) && !empty($sec['company']) && is_array($sec['company']))
                    ? ($sec['company']['company_name'] ?? '')
                    : '';
                $item['second_start']   = is_array($sec) ? ($sec['start_date'] ?? '') : '';
                $item['second_end']     = is_array($sec) ? ($sec['end_date'] ?? '') : '';
                $item['secondment']     = is_array($sec) ? $sec : null;
            }
        }
        unset($item);
        return json(r_success(['list' => $list, 'total' => $total]));
    }

    private function certStatusText(int $status): string
    {
        return [1 => '正常', 0 => '已过期', 2 => '挂失', 3 => '注销'][$status] ?? '未知';
    }
}
