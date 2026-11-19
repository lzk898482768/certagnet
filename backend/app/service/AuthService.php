<?php
namespace app\service;

use app\model\SysUser;
use think\facade\Cache;

/**
 * 登录与 Token 管理
 */
class AuthService
{
    public static function login(string $username, string $password, string $captchaKey = '', string $captchaCode = '', string $ip = '', string $ua = '', string $deviceToken = '', string $deviceId = ''): array
    {
        $cfg = \think\facade\Config::get('security');
        // v2.75：老库自动补 totp_* 列（必须在查询用户前执行）
        TotpService::ensureColumns();
        // v2.96：登录设备 / 会话表自愈
        DeviceService::ensureTables();

        // ---------- 1. 防暴力破解：IP / 账号+IP 组合锁定（v2.97） ----------
        // 不再按「账号」单独硬锁：否则攻击者故意输错即可把他人账号锁死（DoS）。
        // 现在按 IP 与「账号+IP」两个维度锁定；跨 IP 的账号爆破只做递增延迟与日志。
        $ipKey    = 'login_lock_ip_' . md5($ip ?: 'unknown');
        $pairKey  = 'login_lock_pair_' . md5($username . '|' . ($ip ?: 'unknown'));
        $acctKey  = 'login_fail_acct_' . md5($username);
        $ipLeft   = (int)Cache::get($ipKey);
        $pairLeft = (int)Cache::get($pairKey);
        if ($ipLeft > 0 || $pairLeft > 0) {
            $left = max($ipLeft, $pairLeft);
            $min  = max(1, (int)ceil($left / 60));
            $msg  = '账号已被临时锁定，请 ' . $min . ' 分钟后再试';
            LoginLogService::record($username, 3, $msg, null, $ip, $ua, 'password', $deviceId, DeviceService::deviceName($ua));
            throw new \Exception($msg);
        }

        // ---------- 2. 图形验证码 ----------
        if (!empty($cfg['captcha_enabled'])) {
            if (!$captchaKey || !$captchaCode) {
                $msg = '请输入图形验证码';
                LoginLogService::record($username, 2, $msg, null, $ip, $ua, 'password', $deviceId, DeviceService::deviceName($ua));
                throw new \Exception($msg);
            }
            $cached = Cache::get('captcha_' . $captchaKey);
            Cache::delete('captcha_' . $captchaKey);
            if (!$cached || strtolower($cached) !== strtolower($captchaCode)) {
                self::recordFail($ipKey, $pairKey, $acctKey, (int)($cfg['login_lock_seconds'] ?? 900));
                $msg = '图形验证码错误';
                LoginLogService::record($username, 2, $msg, null, $ip, $ua, 'password', $deviceId, DeviceService::deviceName($ua));
                throw new \Exception($msg);
            }
        }

        // ---------- 3. 账号校验 ----------
        $user = SysUser::where('username', $username)->find();
        if (!$user) {
            self::recordFail($ipKey, $pairKey, $acctKey, (int)($cfg['login_lock_seconds'] ?? 900));
            $msg = '账号或密码错误';
            LoginLogService::record($username, 2, $msg, null, $ip, $ua, 'password', $deviceId, DeviceService::deviceName($ua));
            throw new \Exception($msg);
        }
        $userInfo = self::userInfo($user);
        if ((int)$user->status !== 1) {
            $msg = '账号已被禁用，请联系管理员';
            LoginLogService::record($username, 2, $msg, $userInfo, $ip, $ua, 'password', $deviceId, DeviceService::deviceName($ua));
            throw new \Exception($msg);
        }
        if (!SysUser::checkPassword($password, $user->password)) {
            self::recordFail($ipKey, $pairKey, $acctKey, (int)($cfg['login_lock_seconds'] ?? 900));
            $msg = '账号或密码错误';
            LoginLogService::record($username, 2, $msg, $userInfo, $ip, $ua, 'password', $deviceId, DeviceService::deviceName($ua));
            throw new \Exception($msg);
        }

        // v2.96 安全加固：旧库 md5 密码在用户成功登录后自动升级为 bcrypt
        if (SysUser::needsRehash((string)$user->password)) {
            $user->password = $password;
            $user->save();
        }

        // ---------- 4. 二次验证（v2.75）----------
        // 密码已校验通过，清除各维度失败计数；动态验证码另有一套失败锁定。
        Cache::delete($ipKey);
        Cache::delete($ipKey . '_fail');
        Cache::delete($pairKey);
        Cache::delete($pairKey . '_fail');
        Cache::delete($acctKey);

        // 已绑定且设备受信任 → 直接放行；未绑定但被强制 → 返回绑定 challenge。
        $challenge = TotpService::challenge($user, 'password', $ip, $ua, $deviceToken, $deviceId);
        if ($challenge !== null) {
            return $challenge;
        }

        // ---------- 5. 成功：签发令牌 ----------
        $res = self::issueToken($user, [
            'device_id'  => $deviceId,
            'ip'         => $ip,
            'ua'         => $ua,
            'login_type' => 'password',
        ]);
        LoginLogService::record($username, 1, '登录成功', $res['user'], $ip, $ua, 'password', $deviceId, DeviceService::deviceName($ua));
        return $res;
    }

    /** 登录 token 有效期（秒）：后台可配置，默认 3 小时 */
    public static function tokenTtl(): int
    {
        $hours = (int)sys_config('login_token_ttl_hours', 3);
        if ($hours < 1) {
            $hours = 3;
        }
        if ($hours > 720) {
            $hours = 720;
        }
        return $hours * 3600;
    }

    /**
     * 签发登录令牌（供密码登录 / 企微登录 / 二次验证完成时复用）
     *
     * @param array $ctx device_id / ip / ua / login_type
     */
    public static function issueToken(SysUser $user, array $ctx = []): array
    {
        $token   = bin2hex(random_bytes(24));
        $role    = $user->role;
        $payload = [
            'id'        => $user->id,
            'username'  => $user->username,
            'real_name' => $user->real_name,
            'role_id'   => $user->role_id,
            'role_code' => $role ? $role->role_code : '',
        ];
        $ttl = self::tokenTtl();
        Cache::set('token_' . $token, $payload, $ttl);

        // v2.99.1（INFO-02）：同时写入 HttpOnly Cookie，前端不再把令牌存 localStorage。
        \app\common\AuthCookie::queue($token, $ttl);

        // v2.96：登记登录会话，支持用户 / 超管查看与主动下线
        DeviceService::recordSession((int)$user->id, $token, array_merge($ctx, ['ttl' => $ttl]));

        return ['token' => $token, 'user' => $payload, 'expires_in' => $ttl];
    }

    /** 登录日志用的用户摘要（失败时也尽量带上姓名 / 角色） */
    public static function userInfo($user): array
    {
        try {
            $role = $user->role;
            return [
                'id'        => (int)$user->id,
                'username'  => (string)$user->username,
                'real_name' => (string)$user->real_name,
                'role_code' => $role ? (string)$role->role_code : '',
            ];
        } catch (\Throwable $e) {
            return ['id' => (int)($user->id ?? 0), 'username' => (string)($user->username ?? '')];
        }
    }

    /**
     * 记录一次登录失败（v2.97 防 DoS 改造）
     *
     * 硬锁（满 login_max_fail 次后拒绝 15 分钟）只落在：
     *   - IP 维度：同一来源批量试不同账号会被封；
     *   - 账号+IP 维度：同一来源持续试同一账号会被封。
     * 纯账号维度不再硬锁，只做递增延迟（有界、跨 IP），
     * 避免攻击者故意输错把他人账号整体锁死。
     */
    private static function recordFail(string $ipKey, string $pairKey, string $acctKey, int $lockSeconds): void
    {
        $max = (int)(\think\facade\Config::get('security.login_max_fail') ?? 5);
        $lockSeconds = $lockSeconds ?: 900;

        // v2.99.1（INFO-01）：IP 维度阈值放大到 4 倍。
        // 办公网 / NAT 共享出口下，个别人反复输错即会把整个出口 IP 锁死，
        // 攻击者也能借此让全公司“连坐”。真正需要硬锁的是「账号+IP」维度；
        // IP 维度只在明显异常（如批量试不同账号）时兜底。
        $ipMax = max($max + 3, $max * 4);

        foreach ([$ipKey => $ipMax, $pairKey => $max] as $k => $limit) {
            $n = (int)Cache::get($k . '_fail') + 1;
            if ($n >= $limit) {
                Cache::set($k, $lockSeconds, $lockSeconds);
                Cache::delete($k . '_fail');
            } else {
                Cache::set($k . '_fail', $n, $lockSeconds);
            }
        }

        // 账号维度：仅累计失败次数（24 小时窗口），供跨 IP 分布式爆破的延迟与告警使用，
        // 不直接拒绝登录——所以不存在「锁死他人账号」的 DoS。
        $acctN = (int)Cache::get($acctKey) + 1;
        Cache::set($acctKey, $acctN, 86400);

        // 有界递增延迟：按该账号历史失败次数指数退避，封顶 2 秒，避免显著拖垮正常用户。
        $delayMs = min(2000, (int)(50 * pow(2, min($acctN, 6))));
        usleep($delayMs * 1000);
    }

    public static function logout(?string $token): void
    {
        // v2.99.1（INFO-02）：无论是否有有效令牌，都清除浏览器端 HttpOnly Cookie
        \app\common\AuthCookie::clear();
        if ($token) {
            Cache::delete('token_' . $token);
            // v2.96：同步标记会话离线
            DeviceService::revokeSessionByToken($token, '用户退出登录');
        }
    }

    public static function getUser(string $token): ?array
    {
        return Cache::get('token_' . $token);
    }
}
