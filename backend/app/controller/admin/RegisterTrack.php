<?php
namespace app\controller\admin;

use app\model\CertRegisterTrack;
use app\model\Certificate as CertificateModel;
use think\facade\Db;

/**
 * 建造师注册轨迹（挂在证书下）
 */
class RegisterTrack extends AdminController
{
    /** 某本证书的注册轨迹 */
    public function index()
    {
        $certId = (int)$this->request->param('cert_id', 0);
        if (!$certId) {
            return json(r_error('缺少证书ID', 400));
        }
        $this->assertCertAccess($certId);
        $list = CertRegisterTrack::where('cert_id', $certId)
            ->order('register_date', 'desc')
            ->order('id', 'desc')
            ->select()->toArray();
        return json(r_success(['list' => $list, 'total' => count($list)]));
    }

    /** 新增/编辑一条注册轨迹 */
    public function save()
    {
        $id     = (int)$this->request->post('id', 0);
        $certId = (int)$this->request->post('cert_id', 0);
        if (!$certId) {
            return json(r_error('缺少证书ID', 400));
        }
        $cert = CertificateModel::find($certId);
        if (!$cert) {
            return json(r_error('证书不存在', 400));
        }
        $this->assertCertAccess($certId);
        if ($id) {
            $oldTrack = CertRegisterTrack::find($id);
            if (!$oldTrack) {
                return json(r_error('注册轨迹不存在', 404));
            }
            $this->assertCertAccess((int)$oldTrack->cert_id);
        }
        $data = $this->request->post([
            'company_id', 'company_name', 'register_type', 'register_no',
            'register_date', 'valid_until', 'cancel_date', 'status', 'remark',
        ]);
        $data['cert_id']  = $certId;
        $data['staff_id'] = (int)$cert->staff_id;
        $data['status']   = isset($data['status']) && $data['status'] !== '' ? (int)$data['status'] : 1;
        foreach (['company_id', 'company_name', 'register_type', 'register_no', 'remark'] as $k) {
            $data[$k] = (string)($data[$k] ?? '');
        }
        foreach (['register_date', 'valid_until', 'cancel_date'] as $k) {
            if (empty($data[$k])) {
                $data[$k] = null;
            }
        }
        if (empty($data['register_type'])) {
            $data['register_type'] = '初始注册';
        }
        // 历史注册记录可有多条（初始/变更/延续/重新注册等），不做唯一限制

        if ($id) {
            CertRegisterTrack::where('id', $id)->update($data);
            $this->log('注册轨迹', '更新', "#{$id} 证书#{$certId}");
        } else {
            $t  = CertRegisterTrack::create($data);
            $id = (int)$t->id;
            $this->log('注册轨迹', '新增', "#{$id} 证书#{$certId}");
        }
        return json(r_success(['id' => $id], '保存成功'));
    }

    /** 删除一条注册轨迹 */
    public function delete()
    {
        $id = (int)$this->request->post('id', 0);
        if (!$id) {
            return json(r_error('缺少ID', 400));
        }
        $track = CertRegisterTrack::find($id);
        if (!$track) {
            return json(r_error('注册轨迹不存在', 404));
        }
        $this->assertCertAccess((int)$track->cert_id);
        // v2.52：删除前快照进回收站
        try {
            \app\service\RecycleBinService::stash(
                (int)$this->request->userId,
                'register_track',
                ((string)($track['register_type'] ?? '') ?: ("#" . $id)) . '（证书 #' . (int)$track['cert_id'] . '）',
                $track->toArray()
            );
        } catch (\Throwable $e) {
            // 快照失败不阻断删除
        }
        CertRegisterTrack::destroy($id);
        $this->log('注册轨迹', '删除', "#{$id}");
        return json(r_success([], '删除成功'));
    }

    /** 注册类型等选项 */
    public function meta()
    {
        $types = Db::name('dict_data')->where('type_code', 'register_type')
            ->where('status', 1)->order('sort', 'asc')->select()->toArray();
        return json(r_success([
            'register_types' => array_column($types, 'dict_value'),
        ]));
    }
}
