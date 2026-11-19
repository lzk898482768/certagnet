<?php
namespace app\controller\api;

use think\App;
use app\service\WecomAuthService;
use app\model\SysUser;
use think\facade\Cache;

/**
 * 企业微信登录 API
 *
 *  - GET  /api/wecom/config        公开：返回 corp_id/agent_id/redirect_uri/enabled（无 secret）
 *  - POST /api/wecom/login         公开：{ code } → 颁发 token
 *  - POST /api/wecom/bind          需鉴权：{ code } → 当前用户绑定企微
 *  - POST /api/wecom/unbind        需鉴权：解绑
 *  - GET  /api/wecom/status        需鉴权：当前账号企微绑定状态
 */
class Wecom
{
    protected $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** 公开：企微登录配置（不返回 secret） */
    public function config()
    {
        $rows = \app\model\SystemConfig::column('config_value', 'config_key');
        $cfg = [
            'wecom_enabled' => ($rows['wecom_enabled'] ?? '0') === '1' ? '1' : '0',
            'wecom_corp_id' => $rows['wecom_corp_id'] ?? '',
            'wecom_agent_id' => $rows['wecom_agent_id'] ?? '',
        ];
        // dev=离线沙箱（前端显示沙箱账号）；prod=已对接真实企微（前端走 OAuth）
        $mode = \app\service\WecomClient::isDev($cfg) ? 'dev' : 'prod';
        return json([
            'code' => 0,
            'data' => [
                'enabled'       => ($rows['wecom_enabled'] ?? '0') === '1',
                'mode'          => $mode,
                'corp_id'       => $rows['wecom_corp_id']      ?? '',
                'agent_id'      => $rows['wecom_agent_id']     ?? '',
                'redirect_uri'  => $rows['wecom_redirect_uri'] ?? '',
                'qr_scene'      => 'wechat_scan_login',
            ],
        ]);
    }

    /**
     * 公开：code 换 token
     *  - POST（XHR）：返回 JSON，前端登录页拿到 token 后自行进入系统
     *  - GET（企微 OAuth 浏览器回调，redirect_uri 指向本接口时触发）：
     *    处理后 302 回前端登录页，token / 错误信息走 URL 参数，前端直链登录
     */
    public function login()
    {
        $code = $this->app->request->param('code', '');
        $isBrowserCallback = $this->app->request->isGet();

        if (!$code) {
            $msg = '缺少 code';
            return $isBrowserCallback
                ? $this->redirectToSpa('', $msg)
                : json(['code' => 400, 'msg' => $msg]);
        }
        try {
            $r = WecomAuthService::loginByCode(
                $code,
                (string)$this->app->request->ip(),
                (string)$this->app->request->header('user-agent', ''),
                (string)$this->app->request->param('device_token', ''),
                (string)$this->app->request->param('device_id', '')
            );
            if ($isBrowserCallback) {
                // v2.75：需要二次验证时把 challenge 带回登录页，由前端继续校验
                return $this->redirectToSpa($r['token'] ?? '', '', (string)($r['challenge'] ?? ''));
            }
            return json(['code' => 0, 'msg' => '登录成功', 'data' => $r]);
        } catch (\Throwable $e) {
            $msg = $e->getMessage() ?: '企业微信登录失败';
            if ($isBrowserCallback) {
                return $this->redirectToSpa('', $msg);
            }
            return json(['code' => 1, 'msg' => $msg]);
        }
    }

    /**
     * OAuth 浏览器回调：携 token / 错误信息 302 回前端登录页
     * 按 UA 分流：企微移动端 → /mobile/，其余（含企微 PC 版）→ /web/
     */
    private function redirectToSpa(string $token, string $error, string $challenge = '')
    {
        $ua = (string)$this->app->request->header('user-agent', '');
        $isMobile = (bool)preg_match(
            '/Mobile|Android|iPhone|iPad|iPod|HarmonyOS|MicroMessenger|wxwork.*(iPhone|Android)|Windows Phone/i',
            $ua
        );
        $query = [];
        if ($token !== '') $query['token'] = $token;
        if ($error !== '') $query['wecom_error'] = $error;
        if ($challenge !== '') $query['totp_challenge'] = $challenge;
        $url = ($isMobile ? '/mobile/' : '/web/') . ($query ? '?' . http_build_query($query) : '');
        return redirect($url, 302);
    }

    /** 需鉴权：当前用户绑定企微 */
    public function bind()
    {
        // 只接受鉴权中间件解析出的登录态，不再接受 ?token= 查询参数
        // （查询参数会进入 Nginx/浏览器历史/Referer，容易泄露）。
        $user = $this->app->request->user ?? null;
        if (!$user) return json(['code' => 401, 'msg' => '请先登录']);

        $code = $this->app->request->param('code', '');
        if (!$code) return json(['code' => 400, 'msg' => '缺少 code']);

        try {
            $r = WecomAuthService::bind($user['id'], $code);
            return json(['code' => 0, 'msg' => '绑定成功', 'data' => $r]);
        } catch (\Throwable $e) {
            return json(['code' => 1, 'msg' => $e->getMessage()]);
        }
    }

    /** 需鉴权：解绑 */
    public function unbind()
    {
        $user = $this->app->request->user ?? null;
        if (!$user) return json(['code' => 401, 'msg' => '请先登录']);
        WecomAuthService::unbind($user['id']);
        return json(['code' => 0, 'msg' => '解绑成功']);
    }

    /** 需鉴权：当前账号企微状态 */
    public function status()
    {
        $user = $this->app->request->user ?? null;
        if (!$user) return json(['code' => 401, 'msg' => '请先登录']);
        $u = SysUser::find($user['id']);
        return json([
            'code' => 0,
            'data' => [
                'bound'      => !empty($u->wecom_userid),
                'wecom_userid' => $u->wecom_userid,
                'avatar'     => $u->wecom_avatar,
                'mobile'     => $u->wecom_mobile,
                'bound_at'   => $u->wecom_bound_at,
            ],
        ]);
    }

}
