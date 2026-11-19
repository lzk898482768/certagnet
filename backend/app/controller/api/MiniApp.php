<?php
namespace app\controller\api;

use app\service\MiniAppService;
use think\App;

/**
 * 微信小程序公开接口（v2.100）
 *
 *  - GET  /api/miniapp/config        公开：启用状态 + 功能菜单（不含 Secret / Token）
 *  - POST /api/miniapp/login         公开：{ code } → token 或 bind_ticket
 *  - POST /api/miniapp/bind          公开：{ ticket, username/password 或 bind_code } → token
 *  - GET  /api/miniapp/status        需鉴权：当前账号小程序绑定状态
 *  - POST /api/miniapp/unbind        需鉴权：解绑当前账号
 *  - POST /api/profile/miniapp/bind-code  需鉴权：生成 PC 端一次性绑定码
 */
class MiniApp
{
    protected $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function config()
    {
        try {
            return json(r_success(MiniAppService::publicConfig()));
        } catch (\Throwable $e) {
            // 引导接口不因配置读取失败而中断，避免小程序首屏白屏
            return json(r_success([
                'enabled'        => false,
                'login_enabled'  => false,
                'audit_mode'     => false,
                'token_required' => false,
                'menus'          => [],
            ]));
        }
    }

    public function login()
    {
        $req = $this->app->request;
        $code = (string)$req->param('code', '');
        try {
            $r = MiniAppService::loginByCode(
                $code,
                (string)$req->ip(),
                (string)$req->header('user-agent', ''),
                (string)$req->param('device_id', ''),
                (string)$req->param('device_token', '')
            );
            return json(r_success($r, !empty($r['need_bind']) ? '请先绑定系统账号' : '登录成功'));
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage() ?: '小程序登录失败'));
        }
    }

    public function bind()
    {
        $req = $this->app->request;
        $ticket = (string)$req->param('ticket', '');
        $bindCode = (string)$req->param('bind_code', '');
        try {
            if ($bindCode !== '') {
                $r = MiniAppService::bindByCode(
                    $ticket,
                    $bindCode,
                    (string)$req->ip(),
                    (string)$req->header('user-agent', ''),
                    (string)$req->param('device_id', '')
                );
            } else {
                $r = MiniAppService::bindByPassword(
                    $ticket,
                    (string)$req->param('username', ''),
                    (string)$req->param('password', ''),
                    (string)$req->ip(),
                    (string)$req->header('user-agent', ''),
                    (string)$req->param('device_id', ''),
                    (string)$req->param('device_token', '')
                );
            }
            return json(r_success($r, '绑定成功'));
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage() ?: '绑定失败'));
        }
    }

    public function status()
    {
        $user = $this->app->request->user ?? null;
        if (!$user) {
            return json(r_error('请先登录', 401));
        }
        return json(r_success(MiniAppService::bindingStatus((int)$user['id'])));
    }

    public function unbind()
    {
        $user = $this->app->request->user ?? null;
        if (!$user) {
            return json(r_error('请先登录', 401));
        }
        try {
            MiniAppService::unbind((int)$user['id']);
            return json(r_success([], '已解绑'));
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage() ?: '解绑失败'));
        }
    }

    /** PC 端当前登录用户生成一次性绑定码 */
    public function bindCode()
    {
        $user = $this->app->request->user ?? null;
        if (!$user) {
            return json(r_error('请先登录', 401));
        }
        try {
            return json(r_success(MiniAppService::createBindCode((int)$user['id'])));
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage() ?: '生成绑定码失败'));
        }
    }
}
