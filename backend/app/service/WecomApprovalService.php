<?php
namespace app\service;

use app\model\WecomApproval;
use app\model\SysUser;

/**
 * 工作流审批服务（对接企微 OA 审批）
 *
 * 双通道：
 *  A) 企微审批：系统配置了 wecom_approval_template_id 且申请人绑定了企微 userid
 *     → 调 approval/applyevent 在企微侧创建审批单（审批人由企微模板内配置），
 *       企微审批人通过/驳回后用 syncStatus 拉回状态。
 *  B) 内部审批：无模板或申请人未绑企微 → 系统内直接审批，
 *       提交/审批结果都会通过「企微应用消息」通知对方（需配置企微应用）。
 *
 *  DEV 模式（CorpID 以 DEV_ 开头）：全流程模拟，不调企微接口。
 */
class WecomApprovalService
{
    /** 提交审批 */
    public static function submit(array $in): array
    {
        $title = trim((string)($in['title'] ?? ''));
        if ($title === '') {
            throw new \Exception('请填写审批标题');
        }
        $applyUserId = (int)($in['apply_user_id'] ?? 0);
        $applyUser = SysUser::find($applyUserId);
        if (!$applyUser) {
            throw new \Exception('申请人不存在');
        }

        $rec = new WecomApproval();
        $rec->biz_type       = (string)($in['biz_type'] ?? 'custom');
        $rec->biz_id         = (int)($in['biz_id'] ?? 0);
        $rec->title          = mb_substr($title, 0, 120);
        $rec->content        = (string)($in['content'] ?? '');
        $rec->apply_user_id  = $applyUser->id;
        $rec->apply_name     = $applyUser->real_name ?: $applyUser->username;
        $rec->approver_userid= trim((string)($in['approver_userid'] ?? ''));
        $rec->approver_name  = trim((string)($in['approver_name'] ?? ''));
        $rec->source         = 2; // 先按内部审批，下面若成功走企微则改 1
        $rec->status         = 0;
        $rec->apply_time     = date('Y-m-d H:i:s');

        $cfg = WecomClient::loadConfig();
        $templateId = trim((string)($cfg['wecom_approval_template_id'] ?? ''));
        $creatorWecom = (string)($applyUser->wecom_userid ?? '');

        if (!WecomClient::isDev($cfg) && $templateId !== '' && $creatorWecom !== '') {
            // A 通道：企微 OA 审批（失败自动降级为内部审批，失败原因记入 remark）
            try {
                $spNo = self::applyWecom($cfg, $templateId, $creatorWecom, $rec);
                if ($spNo) {
                    $rec->source = 1;
                    $rec->sp_no  = $spNo;
                }
            } catch (\Throwable $e) {
                $rec->remark = mb_substr('企微审批提交失败，已转内部审批：' . $e->getMessage(), 0, 240);
            }
        } elseif (WecomClient::isDev($cfg) && $templateId !== '' && $creatorWecom !== '') {
            // DEV 沙箱：模拟企微审批单号
            $rec->source = 1;
            $rec->sp_no  = 'DEV_SP_' . date('Ymd') . '_' . random_int(1000, 9999);
        }

        $rec->save();

        // 通知审批人（内部审批时用应用消息通知企微侧审批人）
        self::notify(
            $rec->approver_userid,
            "【审批提醒】{$rec->apply_name} 提交了「{$rec->title}」({$rec->biz_type_text})，请登录系统处理。"
        );
        return ['id' => $rec->id, 'source' => $rec->source, 'sp_no' => $rec->sp_no];
    }

    /** 内部审批：通过 */
    public static function approve(int $id, int $opUserId, string $remark = ''): void
    {
        $rec = self::mustFind($id);
        self::mustInternalAction($rec, $opUserId);
        $rec->status      = 1;
        $rec->finish_time = date('Y-m-d H:i:s');
        $rec->remark      = $remark ?: '同意';
        $rec->save();
        self::notify(
            self::applyWecomUserid($rec),
            "【审批结果】您提交的「{$rec->title}」已通过。{$rec->remark}"
        );
    }

    /** 内部审批：驳回 */
    public static function reject(int $id, int $opUserId, string $remark = ''): void
    {
        $rec = self::mustFind($id);
        self::mustInternalAction($rec, $opUserId);
        if ($remark === '') {
            throw new \Exception('驳回时请填写审批意见');
        }
        $rec->status      = 2;
        $rec->finish_time = date('Y-m-d H:i:s');
        $rec->remark      = $remark;
        $rec->save();
        self::notify(
            self::applyWecomUserid($rec),
            "【审批结果】您提交的「{$rec->title}」被驳回：{$remark}"
        );
    }

    /** 撤销（申请人本人或管理员） */
    public static function cancel(int $id, int $opUserId): void
    {
        $rec = self::mustFind($id);
        $isAdmin = (int)(SysUser::find($opUserId)->role_id ?? 0) === 1;
        if ((int)$rec->apply_user_id !== $opUserId && !$isAdmin) {
            throw new \Exception('仅申请人本人或管理员可撤销');
        }
        if ((int)$rec->status !== 0) {
            throw new \Exception('当前状态不可撤销');
        }
        // 企微侧的审批单无法通过 API 撤销（需在企微内手动撤销），这里仅同步本地状态
        $rec->status      = 3;
        $rec->finish_time = date('Y-m-d H:i:s');
        $rec->remark      = $rec->remark ?: '申请人撤销';
        $rec->save();
    }

    /**
     * 从企微同步审批状态（approval/getapprovalinfo）
     *  企微 sp_status：1审批中 2已通过 3已驳回 4已撤销 6通过后撤销 7已删除 10已支付
     */
    public static function syncWecomStatus(int $id): array
    {
        $rec = self::mustFind($id);
        if ((int)$rec->source !== 1 || $rec->sp_no === '') {
            throw new \Exception('该审批单不是企微审批或无单号');
        }
        if ((int)$rec->status !== 0) {
            return ['status' => (int)$rec->status, 'changed' => false]; // 已终态
        }
        $cfg = WecomClient::loadConfig();
        if (WecomClient::isDev($cfg)) {
            return ['status' => 0, 'changed' => false, 'msg' => 'DEV 模式：无真实企微审批单可同步'];
        }
        $token = WecomClient::getAccessToken($cfg);
        $resp = WecomClient::httpPost(
            WecomClient::WECOM_API . '/oa/getapprovalinfo?access_token=' . urlencode($token),
            ['sp_no_list' => [$rec->sp_no]]
        );
        if (($resp['errcode'] ?? -1) !== 0) {
            throw new \Exception('企微审批状态查询失败: ' . ($resp['errmsg'] ?? 'unknown'));
        }
        $spStatus = (int)($resp['sp_no_list'][0]['sp_status'] ?? 0);
        $map = [2 => 1, 3 => 2, 4 => 3, 6 => 3, 7 => 3];
        $changed = false;
        if (isset($map[$spStatus])) {
            $rec->status = $map[$spStatus];
            $rec->finish_time = date('Y-m-d H:i:s');
            $rec->save();
            $changed = true;
            $text = [1 => '已通过', 2 => '已驳回', 3 => '已撤销'][$rec->status];
            self::notify(
                self::applyWecomUserid($rec),
                "【审批结果】您提交的「{$rec->title}」{$text}。"
            );
        }
        return ['status' => (int)$rec->status, 'wecom_sp_status' => $spStatus, 'changed' => $changed];
    }

    // ------------------------------------------------------------
    // 内部
    // ------------------------------------------------------------

    /** 调企微 approval/applyevent，成功返回 sp_no */
    private static function applyWecom(array $cfg, string $templateId, string $creator, WecomApproval $rec): string
    {
        $token = WecomClient::getAccessToken($cfg);
        $body = [
            'creator_userid'       => $creator,
            'template_id'          => $templateId,
            'use_template_approver' => 1, // 审批人按企微模板内配置
            'summary_list'         => [
                ['text' => mb_substr("{$rec->title}｜{$rec->apply_name}｜{$rec->content}", 0, 200)],
            ],
        ];
        $resp = WecomClient::httpPost(
            WecomClient::WECOM_API . '/oa/applyevent?access_token=' . urlencode($token),
            $body
        );
        if (($resp['errcode'] ?? -1) !== 0) {
            throw new \Exception('企微审批提交失败: ' . ($resp['errmsg'] ?? 'unknown'));
        }
        return (string)($resp['sp_no'] ?? '');
    }

    /** 权限：内部审批仅管理员或审批人本人（通过其绑定企微 userid 匹配） */
    private static function mustInternalAction(WecomApproval $rec, int $opUserId): void
    {
        if ((int)$rec->source === 1) {
            throw new \Exception('企微侧审批单请在企业微信内审批，或用「同步状态」拉取结果');
        }
        if ((int)$rec->status !== 0) {
            throw new \Exception('该审批单已结束');
        }
        $u = SysUser::find($opUserId);
        if (!$u) throw new \Exception('操作人不存在');
        $isAdmin = (int)($u->role_id ?? 0) === 1;
        $isApprover = $rec->approver_userid !== '' && (string)($u->wecom_userid ?? '') === (string)$rec->approver_userid;
        if (!$isAdmin && !$isApprover) {
            throw new \Exception('仅管理员或指定审批人可审批');
        }
    }

    private static function mustFind(int $id): WecomApproval
    {
        $rec = WecomApproval::find($id);
        if (!$rec) throw new \Exception('审批单不存在');
        return $rec;
    }

    /** 申请人绑定企微 userid */
    private static function applyWecomUserid(WecomApproval $rec): string
    {
        $u = SysUser::find((int)$rec->apply_user_id);
        return (string)($u->wecom_userid ?? '');
    }

    /** 应用消息通知（无配置/DEV 时静默降级） */
    private static function notify(string $touser, string $content): void
    {
        if ($touser === '') return;
        try {
            WecomMessageService::send($touser, '', '', $content);
        } catch (\Throwable $e) {
            // 通知失败不影响审批主流程
        }
    }
}
