<?php
namespace app\model;

use think\Model;

/**
 * 工作流审批单
 *
 *  - source: 1=企微审批（推送企微 OA 审批，状态随企微同步） 2=内部审批（系统内直接审批）
 *  - status: 0=审批中 1=通过 2=驳回 3=撤销
 */
class WecomApproval extends Model
{
    protected $name = 'wecom_approval';

    protected $append = ['biz_type_text', 'status_text', 'source_text'];

    /** 业务类型文案 */
    public const BIZ_TYPES = [
        'cert_renewal' => '证书续期',
        'secondment'   => '证书借调',
        'fee'          => '费用报销',
        'contract'     => '合同用印',
        'custom'       => '其他事项',
    ];

    public function getBizTypeTextAttr($v, $d)
    {
        return self::BIZ_TYPES[$d['biz_type']] ?? ($d['biz_type'] ?: '其他事项');
    }

    public function getStatusTextAttr($v, $d)
    {
        return [0 => '审批中', 1 => '已通过', 2 => '已驳回', 3 => '已撤销'][(int)($d['status'] ?? 0)] ?? '审批中';
    }

    public function getSourceTextAttr($v, $d)
    {
        return (int)($d['source'] ?? 1) === 1 ? '企微审批' : '内部审批';
    }
}
