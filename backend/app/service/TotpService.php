<?php
namespace app\service;

use app\model\SysUser;
use think\facade\Cache;

/**
 * 登录二次验证（TOTP，v2.75）
 *
 * 对接腾讯身份验证器 / 任意标准 TOTP 客户端：
 *   - 标准 RFC 6238：HMAC-SHA1、6 位、30 秒、Base32 密钥；
 *   - 密钥使用 SecretService（AES-256-GCM）加密后存 sys_user.totp_secret；
 *   - 登录分两步：密码 / 企微校验通过后返回一次性 challenge，
 *     前端凭 challenge + 动态验证码换取正式 token；
 *   - 管理员可对账号设置 totp_force（强制绑定），并可在账号丢失时解绑。
 *
 * 安全约定：
 *   - challenge 5 分钟有效，最多尝试 5 次；
 *   - 同一账号 15 分钟内累计 10 次验证码错误则临时锁定二次验证；
 *   - 密钥绝不出现在列表接口（SysUser::$hidden）。
 */
class TotpService
{
    public const VERSION = 'v2.76';

    public const PERIOD = 30;
    public const DIGITS = 6;

    /** challenge 缓存键前缀 */
    private const CHALLENGE_PREFIX = 'totp_challenge_';
    /** challenge 有效期（秒） */
    public const CHALLENGE_TTL = 300;
    /** 单个 challenge 最多尝试次数 */
    public const MAX_ATTEMPTS = 5;
    /** 同一账号失败锁定阈值 / 锁定时长 */
    private const FAIL_MAX = 10;
    private const FAIL_TTL = 900;

    /** 受信任设备：信任期由后台「数据安全」设置，默认 3 天（v2.96 改为数据库存储） */

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** 进程内列自愈只做一次 */
    protected static $columnsReady = false;

    /**
     * 结构自愈：老库自动补 totp_* 列（幂等）
     */
    public static function ensureColumns(): void
    {
        if (self::$columnsReady) {
            return;
        }
        try {
            ensure_column(
                'sys_user',
                'totp_secret',
                "varchar(255) NOT NULL DEFAULT '' COMMENT 'TOTP 密钥（AES-256-GCM 加密）' AFTER `wecom_bound_at`"
            );
            ensure_column(
                'sys_user',
                'totp_enabled',
                "tinyint NOT NULL DEFAULT 0 COMMENT '是否已绑定腾讯身份验证器: 1是 0否' AFTER `totp_secret`"
            );
            ensure_column(
                'sys_user',
                'totp_force',
                "tinyint NOT NULL DEFAULT 0 COMMENT '管理员强制开启二次验证: 1是 0否' AFTER `totp_enabled`"
            );
            ensure_column(
                'sys_user',
                'totp_bound_at',
                "datetime DEFAULT NULL COMMENT '二次验证绑定时间' AFTER `totp_force`"
            );
            ensure_column(
                'sys_user',
                'totp_tip',
                "varchar(255) NOT NULL DEFAULT '' COMMENT '二次验证绑定提示语（登录绑定弹窗展示）' AFTER `totp_bound_at`"
            );
        } catch (\Throwable $e) {
            // 结构自愈失败不阻断登录，下次请求重试
        }
        self::$columnsReady = true;
    }

    /** 是否已绑定（密钥 + 开关同时满足） */
    public static function isBound(SysUser $user): bool
    {
        return (int)($user->totp_enabled ?? 0) === 1
            && (string)($user->totp_secret ?? '') !== '';
    }

    /** 是否被管理员强制开启 */
    public static function isForced(SysUser $user): bool
    {
        return (int)($user->totp_force ?? 0) === 1;
    }

    /**
     * 登录第一阶段：判断是否需要二次验证。
     *
     * @param string $deviceToken 浏览器携带的受信任设备令牌（信任期内可跳过）
     * @param string $deviceId    浏览器设备标识（与信任令牌绑定，防止令牌被复制）
     * @return array|null null = 无需二次验证，可直接签发 token；
     *                    array = challenge 描述（含 need_totp / need_totp_bind）
     */
    public static function challenge(SysUser $user, string $loginType, string $ip = '', string $ua = '', string $deviceToken = '', string $deviceId = ''): ?array
    {
        self::ensureColumns();

        $bound  = self::isBound($user);
        $forced = self::isForced($user);
        if (!$bound && !$forced) {
            return null;
        }

        // 同一台设备已验证过：受信任令牌有效时直接放行（仍需账号已绑定）
        $geo = null;
        if ($bound && $deviceToken !== '') {
            // v2.99.50：一并校验登录地归属城市，跨市视为异地并撤销全部信任设备
            if (self::verifyDeviceToken($deviceToken, (int)$user->id, $deviceId, $ip, $ua)) {
                return null;
            }
            $geo = DeviceService::pullGeoBlocked((int)$user->id);
        }

        $challenge = bin2hex(random_bytes(18));
        $account   = (string)$user->username;
        $issuer    = self::issuer();
        $tip       = self::tip($user);
        $base = [
            'uid'        => (int)$user->id,
            'mode'       => $bound ? 'verify' : 'bind',
            'login_type' => $loginType,
            'ip'         => $ip,
            'ua'         => $ua,
            'device_id'  => $deviceId,
            'attempts'   => 0,
            'account'    => $account,
            'tip'        => $tip,
            'geo'        => $geo,
        ];

        if ($bound) {
            Cache::set(self::CHALLENGE_PREFIX . $challenge, $base, self::CHALLENGE_TTL);
            $res = [
                'need_totp'   => true,
                'challenge'   => $challenge,
                'account'     => $account,
                'issuer'      => $issuer,
                'digits'      => self::DIGITS,
                'period'      => self::PERIOD,
                'tip'         => $tip,
            ];
            return $res + self::geoPayload($geo);
        }

        // 强制开启但尚未绑定：下发待确认密钥，验证通过后才落库
        $secret = self::generateSecret();
        $base['secret'] = $secret;
        Cache::set(self::CHALLENGE_PREFIX . $challenge, $base, self::CHALLENGE_TTL);

        return [
            'need_totp'      => true,
            'need_totp_bind' => true,
            'challenge'      => $challenge,
            'account'        => $account,
            'secret'         => $secret,
            'issuer'         => $issuer,
            'otpauth'        => self::otpauthUri($secret, $account, $issuer),
            'digits'         => self::DIGITS,
            'period'         => self::PERIOD,
            'tip'            => $tip,
        ];
    }

    /**
     * 读取 challenge 描述（企微 OAuth 回调 302 回前端后，凭 challenge 拉取）
     *
     * 若同时携带有效的受信任设备令牌，则直接完成登录并返回 token
     * （企微 OAuth 回调无法在跳转前提交 localStorage 里的设备令牌）。
     *
     * @return array|null null = challenge 不存在或已过期
     */
    public static function challengeInfo(string $challenge, string $deviceToken = '', string $ip = '', string $ua = '', string $deviceId = ''): ?array
    {
        $p = self::loadChallenge($challenge);
        if (!$p) {
            return null;
        }

        $uid = (int)($p['uid'] ?? 0);
        $geo = is_array($p['geo'] ?? null) ? $p['geo'] : null;
        $trusted = false;
        if (($p['mode'] ?? '') !== 'bind' && $deviceToken !== '') {
            $trusted = self::verifyDeviceToken(
                $deviceToken,
                $uid,
                $deviceId,
                $ip !== '' ? $ip : (string)($p['ip'] ?? ''),
                $ua !== '' ? $ua : (string)($p['ua'] ?? '')
            );
            if (!$trusted) {
                $geo = DeviceService::pullGeoBlocked($uid) ?: $geo;
            }
        }
        if ($trusted) {
            // 设备已受信任：不必再输动态验证码
            $user = SysUser::find($uid);
            if ($user && (int)$user->status === 1) {
                Cache::delete(self::CHALLENGE_PREFIX . $challenge);
                $result = AuthService::issueToken($user, [
                    'device_id'  => $deviceId !== '' ? $deviceId : (string)($p['device_id'] ?? ''),
                    'ip'         => $ip !== '' ? $ip : (string)($p['ip'] ?? ''),
                    'ua'         => $ua !== '' ? $ua : (string)($p['ua'] ?? ''),
                    'login_type' => (string)($p['login_type'] ?? 'password'),
                ]);
                LoginLogService::record(
                    (string)$user->username,
                    1,
                    '登录成功（受信任设备）',
                    $result['user'] ?? null,
                    $ip !== '' ? $ip : (string)($p['ip'] ?? ''),
                    $ua !== '' ? $ua : (string)($p['ua'] ?? ''),
                    (string)($p['login_type'] ?? 'password'),
                    $deviceId !== '' ? $deviceId : (string)($p['device_id'] ?? ''),
                    DeviceService::deviceName($ua !== '' ? $ua : (string)($p['ua'] ?? ''))
                );
                $result['need_totp'] = false;
                return $result;
            }
        }

        $info = [
            'need_totp'   => true,
            'challenge'   => $challenge,
            'account'     => (string)($p['account'] ?? ''),
            'issuer'      => self::issuer(),
            'digits'      => self::DIGITS,
            'period'      => self::PERIOD,
            'tip'         => (string)($p['tip'] ?? ''),
        ];
        $info += self::geoPayload(is_array($p['geo'] ?? null) ? $p['geo'] : $geo);
        if (($p['mode'] ?? '') === 'bind') {
            $secret = (string)($p['secret'] ?? '');
            $info['need_totp_bind'] = true;
            $info['secret']         = $secret;
            $info['otpauth']        = self::otpauthUri($secret, (string)($p['account'] ?? ''), self::issuer());
        }
        return $info;
    }

    /**
     * 异地拦截提示字段（v2.99.50）：仅在受信任设备因跨市被撤销时下发，
     * 供登录页提示「检测到异地登录，已撤销信任设备，请重新动态验证」。
     */
    private static function geoPayload(?array $geo): array
    {
        if (!is_array($geo) || (string)($geo['to'] ?? '') === '') {
            return [];
        }
        $from = (string)($geo['from'] ?? '');
        $to   = (string)($geo['to'] ?? '');
        return [
            'geo_blocked' => true,
            'geo_from'    => $from,
            'geo_to'      => $to,
        ];
    }

    /**
     * 登录第二阶段：校验动态验证码并签发 token。
     *
     * @param bool   $trustDevice 用户是否勾选「信任此设备」（未勾选则不签发长期令牌）
     * @return array{token:string,user:array}
     * @throws \Exception
     */
    public static function verify(string $challenge, string $code, string $ip = '', string $ua = '', bool $trustDevice = false, string $deviceId = ''): array
    {
        self::ensureColumns();

        $key = self::CHALLENGE_PREFIX . $challenge;
        $p   = self::loadChallenge($challenge);
        if (!$p) {
            throw new \Exception('验证会话已过期，请重新登录');
        }

        $uid      = (int)($p['uid'] ?? 0);
        $mode     = (string)($p['mode'] ?? 'verify');
        $loginType = (string)($p['login_type'] ?? 'password');
        $logIp    = $ip !== '' ? $ip : (string)($p['ip'] ?? '');
        $logUa    = $ua !== '' ? $ua : (string)($p['ua'] ?? '');
        if ($deviceId === '') {
            $deviceId = (string)($p['device_id'] ?? '');
        }

        $lockKey = 'totp_lock_uid_' . $uid;
        if (Cache::get($lockKey)) {
            throw new \Exception('动态验证码错误次数过多，请 15 分钟后再试');
        }

        $user = SysUser::find($uid);
        if (!$user) {
            throw new \Exception('账号不存在，请重新登录');
        }
        if ((int)$user->status !== 1) {
            throw new \Exception('账号已被禁用，请联系管理员');
        }

        $code = preg_replace('/\D/', '', (string)$code);
        if (strlen($code) !== self::DIGITS) {
            throw new \Exception('请输入 6 位动态验证码');
        }

        if ($mode === 'bind') {
            $secret = (string)($p['secret'] ?? '');
        } else {
            $secret = self::decryptSecret((string)($user->totp_secret ?? ''));
            if ($secret === '') {
                // 密钥损坏 / 加密密钥变更：清空绑定状态，下次登录重新绑定
                $user->totp_secret  = '';
                $user->totp_enabled = 0;
                $user->totp_bound_at = null;
                $user->save();
                throw new \Exception('二次验证密钥已失效，请重新登录后重新绑定');
            }
        }

        if (!self::verifyCode($secret, $code)) {
            $p['attempts'] = (int)($p['attempts'] ?? 0) + 1;
            if ($p['attempts'] >= self::MAX_ATTEMPTS) {
                Cache::delete($key);
            } else {
                Cache::set($key, $p, self::CHALLENGE_TTL);
            }

            // 账号级失败累计，防止反复重新登录无限试码
            $failKey = 'totp_fail_uid_' . $uid;
            $fails   = (int)Cache::get($failKey) + 1;
            if ($fails >= self::FAIL_MAX) {
                Cache::set($lockKey, 1, self::FAIL_TTL);
                Cache::delete($failKey);
            } else {
                Cache::set($failKey, $fails, self::FAIL_TTL);
            }

            LoginLogService::record(
                (string)$user->username,
                2,
                '动态验证码错误',
                AuthService::userInfo($user),
                $logIp,
                $logUa,
                $loginType,
                $deviceId,
                DeviceService::deviceName($logUa)
            );
            throw new \Exception('动态验证码错误，请重新输入');
        }

        // 校验通过：绑定模式落库密钥，验证模式无需改动
        if ($mode === 'bind') {
            $user->totp_secret   = self::encryptSecret($secret);
            $user->totp_enabled  = 1;
            $user->totp_bound_at = date('Y-m-d H:i:s');
            $user->save();
        }

        Cache::delete($key);
        Cache::delete('totp_fail_uid_' . $uid);

        $result = AuthService::issueToken($user, [
            'device_id'  => $deviceId,
            'ip'         => $logIp,
            'ua'         => $logUa,
            'login_type' => $loginType,
        ]);
        // v2.96：只有用户勾选「信任此设备」且携带设备标识才签发长期令牌；
        // 缺少 device_id 时不签发，避免产生无法绑定浏览器的信任令牌。
        if ($trustDevice && $deviceId !== '') {
            $device = self::issueDeviceToken($user, $logIp, $logUa, $deviceId);
            $result['device_token']      = $device['token'];
            $result['device_expires_in'] = $device['ttl'];
            $result['device_expires_at'] = $device['expires_at'];
        }
        LoginLogService::record(
            (string)$user->username,
            1,
            $mode === 'bind' ? '登录成功（已绑定腾讯身份验证器）' : '登录成功',
            $result['user'] ?? null,
            $logIp,
            $logUa,
            $loginType,
            $deviceId,
            DeviceService::deviceName($logUa)
        );
        return $result;
    }

    /**
     * 管理员解绑：清空密钥与绑定状态（保留 totp_force 策略）
     */
    public static function unbindByAdmin(int $userId): void
    {
        self::ensureColumns();
        $user = SysUser::find($userId);
        if (!$user) {
            throw new \Exception('用户不存在');
        }
        $user->totp_secret   = '';
        $user->totp_enabled  = 0;
        $user->totp_bound_at = null;
        $user->save();

        Cache::delete('totp_lock_uid_' . $userId);
        Cache::delete('totp_fail_uid_' . $userId);
        // 解绑即撤销该账号所有受信任设备，防止旧设备继续跳过二次验证
        self::revokeDevices($userId);
    }

    /**
     * 管理员设置是否强制开启二次验证
     */
    public static function setForce(int $userId, bool $force): void
    {
        self::ensureColumns();
        $user = SysUser::find($userId);
        if (!$user) {
            throw new \Exception('用户不存在');
        }
        $user->totp_force = $force ? 1 : 0;
        $user->save();
    }

    /**
     * 绑定提示语：展示在登录「绑定腾讯身份验证器」弹窗，告诉用户该找谁/怎么绑
     */
    public static function tip(SysUser $user): string
    {
        self::ensureColumns();
        return trim((string)($user->totp_tip ?? ''));
    }

    /** 管理员设置某账号的绑定提示语（空字符串表示清除，使用默认文案） */
    public static function setTip(int $userId, string $tip): void
    {
        self::ensureColumns();
        $user = SysUser::find($userId);
        if (!$user) {
            throw new \Exception('用户不存在');
        }
        $user->totp_tip = mb_substr(trim($tip), 0, 100, 'UTF-8');
        $user->save();
    }

    // ============================================================
    // 密钥管理
    // ============================================================

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function encryptSecret(string $secret): string
    {
        return SecretService::encrypt($secret);
    }

    public static function decryptSecret(string $enc): string
    {
        if ($enc === '') {
            return '';
        }
        try {
            return SecretService::decrypt($enc);
        } catch (\Throwable $e) {
            return '';
        }
    }

    public static function issuer(): string
    {
        $name = trim((string)sys_config('sys_name', ''));
        return $name !== '' ? $name : '证照到期管理系统';
    }

    /**
     * otpauth://totp/ISSUER:ACCOUNT?secret=...&issuer=...&algorithm=SHA1&digits=6&period=30
     * 腾讯身份验证器 / Google Authenticator 等标准客户端可直接扫码或手动输入密钥。
     */
    public static function otpauthUri(string $secret, string $account, string $issuer = ''): string
    {
        $issuer  = $issuer !== '' ? $issuer : self::issuer();
        $label   = rawurlencode($issuer) . ':' . rawurlencode($account);
        $query   = http_build_query([
            'secret'    => $secret,
            'issuer'    => $issuer,
            'algorithm' => 'SHA1',
            'digits'    => self::DIGITS,
            'period'    => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
        return 'otpauth://totp/' . $label . '?' . $query;
    }

    /**
     * 生成当前时间对应的 6 位动态验证码（服务端测试 / 排障用）
     */
    public static function code(string $secret, ?int $timeSlice = null): string
    {
        $key   = self::base32Decode($secret);
        $slice = $timeSlice ?? (int)floor(time() / self::PERIOD);
        $hash  = hash_hmac('sha1', pack('N*', 0) . pack('N*', $slice), $key, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $part   = unpack('N', substr($hash, $offset, 4));
        $value  = (int)$part[1] & 0x7FFFFFFF;
        return str_pad((string)($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * 校验动态验证码（允许前后各 1 个时间片，容忍客户端时钟偏差）
     */
    public static function verifyCode(string $secret, string $code, int $window = 1): bool
    {
        if ($secret === '' || !preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $slice = (int)floor(time() / self::PERIOD);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::code($secret, $slice + $i), $code)) {
                return true;
            }
        }
        return false;
    }

    public static function base32Encode(string $data): string
    {
        if ($data === '') {
            return '';
        }
        $bits = '';
        $len  = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $out  .= self::ALPHABET[bindec($chunk)];
        }
        return $out;
    }

    public static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $b32));
        if ($b32 === '') {
            return '';
        }
        $bits = '';
        $len  = strlen($b32);
        for ($i = 0; $i < $len; $i++) {
            $v = strpos(self::ALPHABET, $b32[$i]);
            if ($v === false) {
                continue;
            }
            $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) < 8) {
                break;
            }
            $out .= chr(bindec($byte));
        }
        return $out;
    }

    private static function loadChallenge(string $challenge): ?array
    {
        if ($challenge === '' || !preg_match('/^[a-f0-9]{20,64}$/', $challenge)) {
            return null;
        }
        $p = Cache::get(self::CHALLENGE_PREFIX . $challenge);
        return is_array($p) ? $p : null;
    }

    // ============================================================
    // 受信任设备（信任期由后台设置，默认 3 天；v2.96 改为数据库存储）
    // ============================================================

    /** 签发受信任设备令牌（仅在用户勾选「信任此设备」时调用） */
    public static function issueDeviceToken(SysUser $user, string $ip = '', string $ua = '', string $deviceId = ''): array
    {
        return DeviceService::issueTrustedDevice($user, [
            'ip'        => $ip,
            'ua'        => $ua,
            'device_id' => $deviceId,
        ]);
    }

    /** 校验设备令牌是否属于该账号（并校验 device_id 绑定与登录地城市） */
    public static function verifyDeviceToken(string $token, int $userId, string $deviceId = '', string $ip = '', string $ua = ''): bool
    {
        return DeviceService::verifyTrustedToken($token, $userId, $deviceId, $ip, $ua);
    }

    /** 撤销某账号全部受信任设备（解绑 / 改密时调用） */
    public static function revokeDevices(int $userId, string $reason = '账号安全策略'): void
    {
        DeviceService::revokeAllTrusted($userId, $reason);
    }
}
