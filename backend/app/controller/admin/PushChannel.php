<?php
namespace app\controller\admin;

use app\model\PushChannel as PushChannelModel;
use app\model\PushMsgLog;
use app\service\PushService;

class PushChannel extends AdminController
{
    public function index()
    {
        $list = PushChannelModel::order('sort', 'asc')->select()->toArray();
        return json(r_success($list));
    }

    public function save()
    {
        $id = (int)$this->request->post('id', 0);
        $data = $this->request->post(['channel_type', 'channel_name', 'config_json', 'is_enable', 'sort', 'remark']);
        if (empty($data['channel_type']) || empty($data['channel_name'])) {
            return json(r_error('渠道类型和名称必填', 400));
        }
        // 枚举白名单校验，防止非法渠道类型入库
        $allowTypes = ['sms', 'email', 'wecom', 'wecom_app', 'mp', 'unipush', 'miniapp'];
        if (!in_array($data['channel_type'], $allowTypes, true)) {
            return json(r_error('不支持的渠道类型: ' . $data['channel_type'], 400));
        }
        if (is_array($data['config_json'] ?? null)) {
            $data['config_json'] = json_encode($data['config_json'], JSON_UNESCAPED_UNICODE);
        }
        if ($id) {
            PushChannelModel::where('id', $id)->update($data);
            $this->log('推送渠道', '更新', "#{$id}");
        } else {
            PushChannelModel::create($data);
            $this->log('推送渠道', '新增');
        }
        return json(r_success([], '保存成功'));
    }

    public function delete()
    {
        $id = (int)$this->request->post('id', 0);
        PushChannelModel::destroy($id);
        $this->log('推送渠道', '删除', "#{$id}");
        return json(r_success([], '删除成功'));
    }

    /**
     * 测试推送：用当前渠道配置立刻发一条消息，并把详细结果返回给前端
     *
     * post: id(渠道ID) target(手机号/邮箱/openid，企微群机器人可不填) content(可选)
     * 返回: status/msg/simulated/response/cost_ms/log_id
     * 说明：无论渠道是否启用都可以测试，方便先验证配置再启用。
     */
    public function test()
    {
        $id      = (int)$this->request->post('id', 0);
        $target  = trim((string)$this->request->post('target', ''));
        $content = trim((string)$this->request->post('content', ''));

        $channel = PushChannelModel::find($id);
        if (!$channel) {
            return json(r_error('推送渠道不存在', 400));
        }
        $type = $channel->channel_type;

        // 按渠道类型装配接收人
        $receiver = [];
        switch ($type) {
            case 'sms':
                $receiver['phone'] = $target;
                break;
            case 'email':
                $receiver['email'] = $target;
                break;
            case 'mp':
                $receiver['openid'] = $target;
                break;
            case 'unipush':
                $receiver['cid'] = $target;
                break;
            case 'miniapp':
                $receiver['openid'] = $target;
                break;
            case 'wecom':
                // 群机器人发送到群，不需要指定接收人
                break;
            case 'wecom_app':
                // 应用消息按企微 userid 精准推送
                $receiver['wecom'] = $target;
                break;
            default:
                return json(r_error('未知渠道类型: ' . $type, 400));
        }

        if ($type !== 'wecom' && $target === '') {
            $nameMap = [
                'sms' => '手机号', 'email' => '邮箱', 'mp' => 'openid',
                'wecom_app' => '企微账号(userid)', 'unipush' => 'cid',
                'miniapp' => 'openid（小程序绑定用户）',
            ];
            return json(r_error('请先填写接收' . ($nameMap[$type] ?? '目标'), 400));
        }

        if ($content === '') {
            $content = self::defaultTestContent($type);
        }
        $content = '[测试消息] ' . $content;

        $res = PushService::send($channel, $receiver, $content);

        // 记录日志（标记 is_test=1，便于在推送日志里区分）
        $logId = 0;
        try {
            $log = PushMsgLog::create([
                'company_id'     => 0,
                'cert_id'        => 0,
                'channel_id'     => $channel->id,
                'channel_type'   => $type,
                'receiver_phone' => $type === 'sms' ? $target : '',
                'receiver_email' => $type === 'email' ? $target : '',
                'receiver_wecom' => in_array($type, ['wecom', 'mp', 'wecom_app', 'unipush', 'miniapp'], true) ? $target : '',
                'msg_content'    => $content,
                'send_status'    => $res['status'],
                'err_msg'        => $res['status'] == 1 ? ($res['simulated'] ? '模拟发送：' . $res['msg'] : '') : ($res['msg'] ?? ''),
                'cost_ms'        => $res['cost_ms'] ?? 0,
                'is_test'        => 1,
            ]);
            $logId = (int)$log->id;
        } catch (\Throwable $e) {
            // 日志写入失败不影响测试结果返回
        }

        $this->log('推送渠道', '测试推送', "#{$id} " . ($res['status'] == 1 ? '成功' : '失败'));

        return json(r_success([
            'status'    => $res['status'],
            'msg'       => $res['msg'],
            'simulated' => $res['simulated'] ?? false,
            'response'  => $res['response'] ?? '',
            'cost_ms'   => $res['cost_ms'] ?? 0,
            'log_id'    => $logId,
            // 详情链接体检：未配置站点根地址 / 移动端H5地址时，定时任务（CLI）拿不到绝对地址
            'h5_base_url'       => h5_base_url(),
            'detail_url_sample' => h5_detail_url(1),
            'link_ok'           => (bool)preg_match('#^https?://#i', h5_base_url()),
            'link_tip'          => preg_match('#^https?://#i', h5_base_url())
                ? ''
                : '未配置「站点根地址」或「移动端H5地址」（系统管理 → 系统信息设置），真实预警消息将不含可点击的详情链接',
            'channel'   => [
                'id'           => $channel->id,
                'name'         => $channel->channel_name,
                'type'         => $type,
                'type_text'    => PushService::typeText($type),
                'enabled'      => (int)$channel->is_enable,
                'config_check' => PushService::checkConfig($type, is_array($channel->config_json) ? $channel->config_json : []),
            ],
        ], $res['status'] == 1 ? ($res['simulated'] ? '测试成功（模拟发送，未真实外发）' : '测试推送成功') : '测试推送失败'));
    }

    /** 默认测试内容（带 H5 详情链接示例，便于核对移动端可达性） */
    protected static function defaultTestContent(string $type): string
    {
        $url  = h5_detail_url(1);
        $text = "证书预警系统连通性测试"
            . "\n企业：示例建筑有限公司"
            . "\n人员：张三"
            . "\n证书：一级建造师（JZ20260001）"
            . "\n到期日：" . date('Y-m-d', strtotime('+30 days'))
            . "\n剩余：30 天";
        // 绝对地址缺失时明确提示，避免上线后才发现短信里的链接点不开
        $text .= $url !== ''
            ? "\n查看详情：" . $url
            : "\n⚠️ 未配置「站点根地址 / 移动端H5地址」，真实预警消息将不含详情链接";
        $text .= "\n发送时间：" . date('Y-m-d H:i:s');
        return $text;
    }
}
