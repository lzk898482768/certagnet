<?php
namespace app\controller\api;

use app\BaseController;
use app\service\WecomAibotService;
use think\facade\Log;

/**
 * 企业微信「智能机器人」URL 回调（v2.83）
 *
 * 企业微信管理后台 → 应用管理 → 智能机器人 → API 模式 → 使用 URL 回调：
 *   URL：https://你的域名/api/wecom/aibot
 *   Token / EncodingAESKey：在本系统「系统管理 → Agent 接入 → 企业微信机器人」生成并填写
 *
 * 该接口不走登录 token、也不走 API Key，来源合法性由 msg_signature 校验；
 * 数据范围由「机器人绑定账号」决定，且只开放只读查询。
 */
class WecomAibot extends BaseController
{
    /**
     * URL 验证：企业微信以 GET 请求带 msg_signature/timestamp/nonce/echostr，
     * 校验签名通过后必须原样返回 echostr 的明文（不能加引号、BOM、换行）。
     */
    public function verify()
    {
        $r = WecomAibotService::verifyUrl($this->request->get());

        if (!$r['ok']) {
            Log::warning('[wecom-aibot] URL 验证失败：' . $r['msg']);
            return response($r['msg'], 403, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        return response($r['plain'], 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * 消息回调：企业微信 POST JSON {"encrypt": "..."}，
     * 校验签名 → 解密 → 生成回复 → 加密 → 返回 {"encrypt":...}。
     */
    public function callback()
    {
        $body = (string)$this->request->getContent();
        $r    = WecomAibotService::handleCallback($this->request->get(), $body);

        if ($r['status'] !== 200) {
            Log::warning('[wecom-aibot] 回调处理失败：' . $r['msg']);
        }

        // 空包用于「事件/重复回调」，企业微信收到空响应不会展示任何消息
        return response($r['body'], $r['status'], ['Content-Type' => 'application/json; charset=utf-8']);
    }
}
