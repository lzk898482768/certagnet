<?php
namespace app\service;

use app\model\SysRole;
use app\model\SysUser;
use think\facade\Cache;
use think\facade\Config;

/**
 * 企业微信登录服务
 *
 * 关键点：
 *  1) 真实环境会调 https://qyapi.weixin.qq.com 三个接口
 *      - gettoken       corpid + corpsecret → access_token (缓存 7200s)
 *      - getuserinfo    code + access_token → userid
 *      - getuserdetail  userid + access_token → 手机号/头像/部门等
 *  2) 离线/沙箱环境：wecom_enabled=0 时不发起任何企微请求，
 *     通过 ENV.wecom_dev_userid / ENV.wecom_dev_mobile 模拟扫码结果，
 *     便于本地开发与单元测试。
 *  3) 首次扫码登录：按 wecom_auto_create 自动建本地账号，
 *     username 形如 wc_<userid>，密码随机 12 位（首次登录强制改密）。
 *  4) 返回结构与原生 AuthService::login 一致，前端无需改造。
 */
class WecomAuthService
{
    /** 企微接口 host */
    private const WECOM_API = 'https://qyapi.weixin.qq.com/cgi-bin';

    // ============================================================
    // 1) 主入口：扫码 code 换本地 token
    // ============================================================
    public static function loginByCode(string $code, string $ip = '', string $ua = '', string $deviceToken = '', string $deviceId = ''): array
    {
        $cfg = self::loadConfig();
        $wecomUserId = '';
        try {
            if (empty($cfg['wecom_enabled'])) {
                throw new \Exception('企业微信登录未启用');
            }

            // 1.1 拿 access_token
            $accessToken = self::getAccessToken($cfg);

            // 1.2 code 换 userid
            $wecomUserId = self::getUserIdByCode($accessToken, $code);
            if (empty($wecomUserId)) {
                throw new \Exception('企业微信授权失败：未获取到 userid');
            }

            // 1.3 userid 换详情
            $detail = self::getUserDetail($accessToken, $wecomUserId);

            $u = self::resolveUser($wecomUserId, $detail, $cfg);

            // v2.75：企微登录同样受二次验证约束（受信任设备可跳过）
            $challenge = TotpService::challenge($u, 'wecom', $ip, $ua, $deviceToken, $deviceId);
            if ($challenge !== null) {
                return $challenge;
            }

            $r = self::issueToken($u, [
                'device_id'  => $deviceId,
                'ip'         => $ip,
                'ua'         => $ua,
                'login_type' => 'wecom',
            ]);

            // v2.74：企微登录同样写入登录日志，管理员可在「登录日志」看到全部用户
            LoginLogService::record(
                (string)($r['user']['username'] ?? ''),
                1,
                '企业微信登录成功',
                $r['user'] ?? null,
                $ip,
                $ua,
                'wecom',
                $deviceId,
                DeviceService::deviceName($ua)
            );
            return $r;
        } catch (\Throwable $e) {
            LoginLogService::record('', 2, '企业微信登录失败：' . $e->getMessage(), null, $ip, $ua, 'wecom', $deviceId, DeviceService::deviceName($ua));
            throw $e;
        }
    }

    /**
     * 当前登录用户绑定企微（已登录账号 + 扫码 code）
     *  - 不允许一个 userid 同时绑多账号
     *  - 成功后只更新 wecom_* 字段并返回新 token
     */
    public static function bind(int $localUserId, string $code): array
    {
        $cfg = self::loadConfig();
        if (empty($cfg['wecom_enabled'])) {
            throw new \Exception('企业微信登录未启用');
        }
        $accessToken = self::getAccessToken($cfg);
        $userId = self::getUserIdByCode($accessToken, $code);
        if (empty($userId)) {
            throw new \Exception('企业微信授权失败');
        }
        $detail = self::getUserDetail($accessToken, $userId);

        // 检查该 userid 是否已绑其他账号
        $exists = SysUser::where('wecom_userid', $userId)
            ->where('id', '<>', $localUserId)
            ->find();
        if ($exists) {
            throw new \Exception('该企业微信账号已绑定其他用户');
        }

        $u = SysUser::find($localUserId);
        if (!$u) throw new \Exception('用户不存在');
        $u->wecom_userid   = $userId;
        $u->wecom_avatar   = $detail['avatar'] ?? null;
        $u->wecom_mobile   = $detail['mobile'] ?? null;
        $u->wecom_bound_at = date('Y-m-d H:i:s');
        $u->save();

        return self::issueToken($u);
    }

    /** 解绑（自己） */
    public static function unbind(int $localUserId): void
    {
        SysUser::where('id', $localUserId)->update([
            'wecom_userid'   => null,
            'wecom_avatar'   => null,
            'wecom_mobile'   => null,
            'wecom_bound_at' => null,
        ]);
    }

    /**
     * 解绑（管理员代操作）
     *  - 仅当当前用户为管理员时调用方应允许此方法（控制器侧把关）
     *  - 可选原因字段会写入 operation_log
     */
    public static function unbindByAdmin(int $localUserId, ?string $reason = null): void
    {
        $u = SysUser::find($localUserId);
        if (!$u) {
            throw new \Exception('用户不存在');
        }
        $u->wecom_userid   = null;
        $u->wecom_avatar   = null;
        $u->wecom_mobile   = null;
        $u->wecom_bound_at = null;
        $u->save();
    }

    /**
     * 企微自动建号/通讯录建号的默认角色。
     *
     * 安全底线：
     *  - 未配置时使用 viewer（只读观察员），不再兜底 role_id=1（管理员）；
     *  - 默认角色必须存在且启用；
     *  - 禁止自动建号直接获得 admin，防止拿到企微 code 即成为管理员。
     */
    public static function resolveAutoCreateRoleId(array $cfg): int
    {
        $roleId = (int)($cfg['wecom_default_role'] ?? 0);
        if ($roleId <= 0) {
            $roleId = (int)(SysRole::where('role_code', 'viewer')->where('status', 1)->value('id') ?: 0);
        }
        if ($roleId <= 0) {
            $roleId = (int)(SysRole::where('status', 1)->order('id', 'asc')->value('id') ?: 0);
        }
        $role = $roleId > 0 ? SysRole::find($roleId) : null;
        if (!$role || (int)$role->status !== 1) {
            throw new \Exception('企微自动建号默认角色未配置或已停用，请联系管理员在「企微登录配置」中设置');
        }
        if ($role->role_code === 'admin') {
            throw new \Exception('安全限制：企微自动建号不能默认授予管理员角色，请改为 viewer 或先创建本地账号后绑定');
        }
        return $roleId;
    }

    /**
     * 内部：找到/创建本地用户（v2.75 起只负责解析用户，令牌由调用方签发，
     * 便于插入二次验证）
     */
    private static function resolveUser(string $userId, array $detail, array $cfg): SysUser
    {
        $u = SysUser::where('wecom_userid', $userId)->find();
        $now = date('Y-m-d H:i:s');
        if (!$u) {
            if (empty($cfg['wecom_auto_create'])) {
                throw new \Exception('企微用户未在系统中开通，请联系管理员开启自动建账号或先创建本地账号');
            }
            $u = new SysUser();
            $u->username       = 'wc_' . substr(preg_replace('/[^a-zA-Z0-9_]/', '', $userId), 0, 32);
            $u->password       = self::randomPassword();
            $u->real_name      = $detail['name'] ?? $userId;
            $u->phone          = $detail['mobile'] ?? '';
            $u->role_id        = self::resolveAutoCreateRoleId($cfg);
            $u->status         = 1;
            $u->wecom_userid   = $userId;
            $u->wecom_avatar   = $detail['avatar'] ?? null;
            $u->wecom_mobile   = $detail['mobile'] ?? null;
            $u->wecom_bound_at = $now;
            $u->save();
        } else {
            // 已有绑定 → 同步最新企微信息
            $u->wecom_avatar = $detail['avatar'] ?? $u->wecom_avatar;
            $u->wecom_mobile = $detail['mobile'] ?? $u->wecom_mobile;
            $u->wecom_bound_at = $now;
            $u->save();
        }

        if ((int)$u->status !== 1) {
            throw new \Exception('账号已被禁用，请联系管理员');
        }

        return $u;
    }

    // ============================================================
    // 2) 企微 HTTP 调用（带缓存与离线降级）
    // ============================================================
    private static function getAccessToken(array $cfg): string
    {
        // 统一走 WecomClient：DEV 判定必须显式开启开发模式，且 TLS 校验强制开启。
        return WecomClient::getAccessToken($cfg);
    }

    private static function getUserIdByCode(string $accessToken, string $code): string
    {
        // 沙箱/离线模式：直接由 code 模拟 userid
        if ($accessToken === 'DEV_OFFLINE_TOKEN') {
            $map = [
                'dev_admin'  => 'dev_admin',
                'dev_user01' => 'dev_user01',
                'dev_user02' => 'dev_user02',
            ];
            return $map[$code] ?? $code;
        }

        $url = self::WECOM_API . '/auth/getuserinfo?access_token=' . $accessToken . '&code=' . urlencode($code);
        $resp = self::httpGet($url);
        if (($resp['errcode'] ?? -1) !== 0) {
            throw new \Exception('企微 code 换 userid 失败: ' . ($resp['errmsg'] ?? 'unknown'));
        }
        return $resp['userid'] ?? $resp['openid'] ?? '';
    }

    private static function getUserDetail(string $accessToken, string $userId): array
    {
        // 沙箱/离线模式：返回预置用户档案
        if ($accessToken === 'DEV_OFFLINE_TOKEN') {
            $map = [
                'dev_admin'  => ['userid' => 'dev_admin',  'name' => '开发管理员', 'mobile' => '13800000001', 'avatar' => ''],
                'dev_user01' => ['userid' => 'dev_user01', 'name' => '李四',       'mobile' => '13800000002', 'avatar' => ''],
                'dev_user02' => ['userid' => 'dev_user02', 'name' => '王五',       'mobile' => '13800000003', 'avatar' => ''],
            ];
            return $map[$userId] ?? ['userid' => $userId, 'name' => $userId, 'mobile' => null, 'avatar' => null];
        }

        $url = self::WECOM_API . '/user/get?access_token=' . $accessToken . '&userid=' . urlencode($userId);
        $resp = self::httpGet($url);
        if (($resp['errcode'] ?? -1) !== 0) {
            // 通讯录权限不足时不强抛，至少 userid 拿得到
            return ['userid' => $userId];
        }
        return [
            'userid' => $resp['userid'] ?? $userId,
            'name'   => $resp['name'] ?? $userId,
            'mobile' => $resp['mobile'] ?? null,
            'email'  => $resp['email'] ?? null,
            'avatar' => $resp['avatar'] ?? null,
            'dept'   => $resp['department'] ?? [],
        ];
    }

    private static function httpGet(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err) {
            throw new \Exception('企微接口网络错误: ' . $err);
        }
        $arr = json_decode((string)$raw, true);
        return is_array($arr) ? $arr : ['errcode' => -1, 'errmsg' => 'non-json: ' . substr($raw, 0, 200)];
    }

    // ============================================================
    // 3) 工具方法
    // ============================================================
    private static function issueToken(SysUser $u, array $ctx = []): array
    {
        $token = bin2hex(random_bytes(24));
        $role = $u->role;
        $payload = [
            'id'           => $u->id,
            'username'     => $u->username,
            'real_name'    => $u->real_name,
            'role_id'      => $u->role_id,
            'role_code'    => $role ? $role->role_code : '',
            'wecom_bound'  => !empty($u->wecom_userid),
        ];
        $ttl = AuthService::tokenTtl();
        Cache::set('token_' . $token, $payload, $ttl);
        // v2.99.1（INFO-02）：企微登录同样写入 HttpOnly Cookie，
        // 否则前端不再持有 localStorage 令牌后，回调落地页面将无法继续请求接口。
        \app\common\AuthCookie::queue($token, $ttl);
        DeviceService::recordSession((int)$u->id, $token, array_merge($ctx, ['ttl' => $ttl]));
        return ['token' => $token, 'user' => $payload, 'expires_in' => $ttl];
    }

    private static function loadConfig(): array
    {
        $rows = \app\model\SystemConfig::column('config_value', 'config_key');
        return [
            'wecom_enabled'      => ($rows['wecom_enabled'] ?? '0') === '1',
            'wecom_corp_id'      => $rows['wecom_corp_id']     ?? '',
            'wecom_agent_id'     => $rows['wecom_agent_id']    ?? '',
            'wecom_app_secret'   => $rows['wecom_app_secret']  ?? '',
            'wecom_redirect_uri' => $rows['wecom_redirect_uri'] ?? '',
            'wecom_auto_create'  => ($rows['wecom_auto_create'] ?? '0') === '1',
            'wecom_default_role' => $rows['wecom_default_role'] ?? '1',
        ];
    }

    private static function randomPassword(int $len = 12): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return password_hash($out, PASSWORD_BCRYPT);
    }
}
