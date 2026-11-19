<?php
namespace app\controller\admin;

use app\model\CertRenewal as CertRenewalModel;
use app\model\Certificate;

class CertRenewal extends AdminController
{
    public function index()
    {
        [$page, $limit] = $this->pageParams();
        $certId = (int)$this->request->param('cert_id', 0);
        $q = CertRenewalModel::with(['certificate.staff'])->order('id', 'desc');
        if ($certId) {
            $this->assertCertAccess($certId);
            $q->where('cert_id', $certId);
        } elseif (!$this->isAdmin()) {
            // 不带证书 ID 的列表也必须按数据范围过滤，避免枚举全部续期记录。
            $cq = Certificate::where('id', '>', 0);
            $this->scopeCert($cq);
            $q->whereIn('cert_id', $cq->column('id') ?: [0]);
        }
        $total = $q->count();
        $list  = $q->page($page, $limit)->select()->toArray();
        foreach ($list as &$item) {
            if (!empty($item['certificate']) && is_array($item['certificate'])) {
                $item['certificate'] = \app\service\MaskService::certFor($item['certificate'], $this->request->user ?? []);
                // v2.78：续期列表直接展示持证人员，便于区分同名/同类证书
                $item['staff_name'] = $item['certificate']['staff']['staff_name']
                    ?? ($item['certificate']['staff_name'] ?? '');
            } else {
                $item['staff_name'] = '';
            }
        }
        unset($item);
        return json(r_success(['list' => $list, 'total' => $total]));
    }

    // 新增续期：记录历史并回写证书新到期日
    public function save()
    {
        $certId          = (int)$this->request->post('cert_id', 0);
        $newExpireDate   = $this->request->post('new_expire_date', '');
        $oldExpireDate   = $this->request->post('old_expire_date', '');
        $renewalDate     = $this->request->post('renewal_date', date('Y-m-d'));
        $operator        = $this->request->post('operator', $this->request->user['real_name'] ?? '');
        $remark          = $this->request->post('remark', '');

        $cert = Certificate::find($certId);
        if (!$cert) {
            return json(r_error('证书不存在', 400));
        }
        $this->assertCertAccess($certId);
        CertRenewalModel::create([
            'cert_id'          => $certId,
            'old_expire_date'  => $oldExpireDate ?: $cert->expire_date,
            'new_expire_date'  => $newExpireDate,
            'renewal_date'     => $renewalDate,
            'operator'         => $operator,
            'remark'           => $remark,
        ]);
        $oldStatus = (int)$cert->cert_status;
        $oldExpire = (string)$cert->expire_date;
        $cert->expire_date = $newExpireDate;
        $cert->cert_status = 1; // 续期后恢复为正常
        $cert->save();
        // v2.76：续期把「已过期」恢复为「正常」属于证书状态变更，补写状态变更日志
        if ($oldStatus !== 1) {
            \app\service\CertStatusLogService::cert(
                $certId,
                $oldStatus,
                1,
                (string)($this->request->user['real_name'] ?? ''),
                '证书延续：到期日 ' . ($oldExpire ?: '-') . ' → ' . $newExpireDate
            );
        }
        $this->log('证书延续', '新增', "证书#{$certId}");
        return json(r_success([], '延续成功'));
    }
}
