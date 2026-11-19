<?php
namespace app\service;

use app\model\SysUser;
use think\facade\Cache;
use think\facade\Db;

/**
 * 登录设备与会话管理（v2.96）
 *
 * 两类设备：
 *   1) 登录会话（sys_login_session）：每次登录签发 token 时登记一条，
 *      管理员 / 用户可查看在线设备并「下线」，下线后中间件立即拒绝该 token；
 *   2) 受信任设备（sys_trusted_device）：二次验证时用户勾选「信任此设备」后登记，
 *      信任期内同一设备登录可跳过动态验证码。
 *
 * 安全约定：
 *   - 数据库只保存令牌的 SHA-256 摘要，不落明文令牌；
 *   - 信任令牌同时绑定浏览器生成的 device_id，复制到其他浏览器无法使用；
 *   - 所有过期时间由服务端计算，前端不可篡改；
 *   - 表结构由 ensureTables() 自愈，兼容老库直接 git pull。
 */
class DeviceService
{
    public const VERSION = 'v2.96';

    /** 每账号最多保留的受信任设备数 */
    public const MAX_TRUSTED = 20;

    /** 会话最后活跃时间写库节流（秒） */
    private const TOUCH_INTERVAL = 300;

    protected static $ready = false;

    public static function ddlSessions(): string
    {
        return "CREATE TABLE IF NOT EXISTS `sys_login_session` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '用户ID',
  `token_hash` char(64) NOT NULL DEFAULT '' COMMENT '登录令牌 SHA-256（不存明文）',
  `device_id` varchar(64) NOT NULL DEFAULT '' COMMENT '浏览器设备标识',
  `device_name` varchar(100) NOT NULL DEFAULT '' COMMENT '设备名称（系统/浏览器）',
  `ip` varchar(64) NOT NULL DEFAULT '' COMMENT '登录IP',
  `user_agent` varchar(255) NOT NULL DEFAULT '' COMMENT 'User-Agent',
  `login_type` varchar(16) NOT NULL DEFAULT 'password' COMMENT '登录方式 password/wecom',
  `created_at` datetime DEFAULT NULL COMMENT '登录时间',
  `last_active_at` datetime DEFAULT NULL COMMENT '最后活跃时间',
  `expires_at` datetime DEFAULT NULL COMMENT '登录过期时间',
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '1在线 0已下线',
  `revoke_reason` varchar(100) NOT NULL DEFAULT '' COMMENT '下线原因',
  PRIMARY KEY (`id`),
  KEY `idx_user_status` (`user_id`,`status`),
  KEY `idx_token` (`token_hash`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='登录会话设备（v2.96）'";
    }

    public static function ddlTrusted(): string
    {
        return "CREATE TABLE IF NOT EXISTS `sys_trusted_device` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '用户ID',
  `token_hash` char(64) NOT NULL DEFAULT '' COMMENT '信任令牌 SHA-256（不存明文）',
  `device_id` varchar(64) NOT NULL DEFAULT '' COMMENT '浏览器设备标识',
  `device_name` varchar(100) NOT NULL DEFAULT '' COMMENT '设备名称（系统/浏览器）',
  `ip` varchar(64) NOT NULL DEFAULT '' COMMENT '信任时IP',
  `city` varchar(64) NOT NULL DEFAULT '' COMMENT '信任时归属城市（v2.99.50，用于异地登录判定）',
  `user_agent` varchar(255) NOT NULL DEFAULT '' COMMENT 'User-Agent',
  `created_at` datetime DEFAULT NULL COMMENT '信任时间',
  `last_used_at` datetime DEFAULT NULL COMMENT '最后使用时间',
  `expires_at` datetime DEFAULT NULL COMMENT '信任过期时间',
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '1有效 0已撤销',
  `revoke_reason` varchar(100) NOT NULL DEFAULT '' COMMENT '撤销原因',
  PRIMARY KEY (`id`),
  KEY `idx_user_status` (`user_id`,`status`),
  KEY `idx_token` (`token_hash`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='受信任设备（v2.96）'";
    }

    public static function ensureTables(): void
    {
        if (self::$ready) {
            return;
        }
        try {
            ensure_table('sys_login_session', self::ddlSessions());
            ensure_table('sys_trusted_device', self::ddlTrusted());
            // v2.99.50：受信任设备记录归属城市，用于异地登录判定
            ensure_column(
                'sys_trusted_device',
                'city',
                "varchar(64) NOT NULL DEFAULT '' COMMENT '信任时归属城市（v2.99.50）' AFTER `ip`"
            );
            self::$ready = true;
        } catch (\Throwable $e) {
            // 建表失败不阻断登录，下次请求重试
            self::$ready = false;
        }
    }

    /** 令牌摘要 */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** 信任期（秒）：后台可配置，默认 3 天 */
    public static function trustTtl(): int
    {
        $days = (int)sys_config('totp_trust_days', 3);
        if ($days < 1) {
            $days = 3;
        }
        if ($days > 365) {
            $days = 365;
        }
        return $days * 86400;
    }

    /** 信任期（天）：供前端展示 */
    public static function trustDays(): int
    {
        return (int)(self::trustTtl() / 86400);
    }

    /** 从 UA 粗略识别设备名称（仅用于展示，不作为安全依据） */
    public static function deviceName(string $ua): string
    {
        $ua = (string)$ua;
        if ($ua === '') {
            return '未知设备';
        }

        $os = '未知系统';
        if (preg_match('/Windows NT/i', $ua)) {
            $os = 'Windows';
        } elseif (preg_match('/iPhone|iPad|iPod/i', $ua)) {
            $os = 'iOS';
        } elseif (preg_match('/Android/i', $ua)) {
            $os = 'Android';
        } elseif (preg_match('/Mac OS X|Macintosh/i', $ua)) {
            $os = 'macOS';
        } elseif (preg_match('/Linux/i', $ua)) {
            $os = 'Linux';
        }

        $browser = '未知浏览器';
        if (preg_match('/wxwork/i', $ua)) {
            $browser = '企业微信';
        } elseif (preg_match('/MicroMessenger/i', $ua)) {
            $browser = '微信';
        } elseif (preg_match('/Edg\//i', $ua)) {
            $browser = 'Edge';
        } elseif (preg_match('/Chrome\//i', $ua)) {
            $browser = 'Chrome';
        } elseif (preg_match('/Firefox\//i', $ua)) {
            $browser = 'Firefox';
        } elseif (preg_match('/Safari\//i', $ua)) {
            $browser = 'Safari';
        } elseif (preg_match('/curl|wget|python|okhttp/i', $ua)) {
            $browser = '程序调用';
        }

        return mb_substr($os . ' / ' . $browser, 0, 100, 'UTF-8');
    }

    // ============================================================
    // 登录会话
    // ============================================================

    /**
     * 登记一次登录会话
     *
     * @param array $ctx device_id / ip / ua / login_type / ttl
     */
    public static function recordSession(int $userId, string $token, array $ctx = []): void
    {
        if ($userId <= 0 || $token === '') {
            return;
        }
        try {
            self::ensureTables();
            $now = time();
            $ttl = max(60, (int)($ctx['ttl'] ?? 3600));
            Db::name('sys_login_session')->insert([
                'user_id'        => $userId,
                'token_hash'     => self::hashToken($token),
                'device_id'      => mb_substr((string)($ctx['device_id'] ?? ''), 0, 64, 'UTF-8'),
                'device_name'    => self::deviceName((string)($ctx['ua'] ?? '')),
                'ip'             => mb_substr((string)($ctx['ip'] ?? ''), 0, 64, 'UTF-8'),
                'user_agent'     => mb_substr((string)($ctx['ua'] ?? ''), 0, 255, 'UTF-8'),
                'login_type'     => (string)($ctx['login_type'] ?? 'password') === 'wecom' ? 'wecom' : 'password',
                'created_at'     => date('Y-m-d H:i:s', $now),
                'last_active_at' => date('Y-m-d H:i:s', $now),
                'expires_at'     => date('Y-m-d H:i:s', $now + $ttl),
                'status'         => 1,
                'revoke_reason'  => '',
            ]);
            // 顺带清理 90 天前的历史会话，避免表无限增长
            if (random_int(1, 50) === 1) {
                Db::name('sys_login_session')->where('created_at', '<', date('Y-m-d H:i:s', $now - 90 * 86400))->delete();
            }
        } catch (\Throwable $e) {
            // 会话登记失败不阻断登录
        }
    }

    /**
     * 会话是否已被撤销 / 过期（中间件调用）
     * 老版本签发的 token 没有会话记录，返回 false 兼容。
     */
    public static function isSessionRevoked(string $token): bool
    {
        if ($token === '') {
            return false;
        }
        try {
            self::ensureTables();
            $row = Db::name('sys_login_session')
                ->where('token_hash', self::hashToken($token))
                ->field('id,status,expires_at,last_active_at')
                ->find();
            if (!$row) {
                return false;
            }
            if ((int)$row['status'] !== 1) {
                return true;
            }
            if (!empty($row['expires_at']) && strtotime((string)$row['expires_at']) < time()) {
                return true;
            }
            // 节流更新最后活跃时间
            $last = !empty($row['last_active_at']) ? strtotime((string)$row['last_active_at']) : 0;
            if (time() - $last >= self::TOUCH_INTERVAL) {
                Db::name('sys_login_session')->where('id', (int)$row['id'])
                    ->update(['last_active_at' => date('Y-m-d H:i:s')]);
            }
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function revokeSessionByToken(string $token, string $reason = '用户退出登录'): void
    {
        if ($token === '') {
            return;
        }
        try {
            self::ensureTables();
            Db::name('sys_login_session')
                ->where('token_hash', self::hashToken($token))
                ->where('status', 1)
                ->update([
                    'status'        => 0,
                    'revoke_reason' => mb_substr($reason, 0, 100, 'UTF-8'),
                ]);
        } catch (\Throwable $e) {
        }
    }

    /** 撤销单条会话（$userId > 0 时校验归属，防止越权下线他人） */
    public static function revokeSession(int $id, int $userId = 0, string $reason = '手动下线'): bool
    {
        if ($id <= 0) {
            return false;
        }
        self::ensureTables();
        $q = Db::name('sys_login_session')->where('id', $id);
        if ($userId > 0) {
            $q->where('user_id', $userId);
        }
        $n = $q->where('status', 1)->update([
            'status'        => 0,
            'revoke_reason' => mb_substr($reason, 0, 100, 'UTF-8'),
        ]);
        return $n > 0;
    }

    /** 撤销某用户全部会话；$exceptToken 用于「退出其他设备」时保留当前会话 */
    public static function revokeAllSessions(int $userId, string $reason = '全部下线', string $exceptToken = ''): int
    {
        if ($userId <= 0) {
            return 0;
        }
        self::ensureTables();
        $q = Db::name('sys_login_session')->where('user_id', $userId)->where('status', 1);
        if ($exceptToken !== '') {
            $q->where('token_hash', '<>', self::hashToken($exceptToken));
        }
        return $q->update([
            'status'        => 0,
            'revoke_reason' => mb_substr($reason, 0, 100, 'UTF-8'),
        ]);
    }

    /** 查询会话列表（附带是否当前会话） */
    public static function listSessions(int $userId, bool $onlyActive = true): array
    {
        self::ensureTables();
        $q = Db::name('sys_login_session')->where('user_id', $userId);
        if ($onlyActive) {
            $q->where('status', 1)->where('expires_at', '>', date('Y-m-d H:i:s'));
        }
        return $q->order('id', 'desc')->select()->toArray();
    }

    // ============================================================
    // 受信任设备
    // ============================================================

    /**
     * 签发受信任设备令牌
     *
     * @return array{token:string,ttl:int,expires_at:string}
     */
    public static function issueTrustedDevice(SysUser $user, array $ctx = []): array
    {
        self::ensureTables();
        $uid   = (int)$user->id;
        $token = bin2hex(random_bytes(32));
        $ttl   = self::trustTtl();
        $now   = time();
        $hash  = self::hashToken($token);

        // 超出上限时撤销最早的设备
        $active = Db::name('sys_trusted_device')
            ->where('user_id', $uid)->where('status', 1)
            ->where('expires_at', '>', date('Y-m-d H:i:s'))
            ->order('id', 'asc')->column('id');
        if (count($active) >= self::MAX_TRUSTED) {
            $drop = array_slice($active, 0, count($active) - self::MAX_TRUSTED + 1);
            Db::name('sys_trusted_device')->whereIn('id', $drop)->update([
                'status'        => 0,
                'revoke_reason' => '超出设备上限自动清理',
            ]);
        }

        Db::name('sys_trusted_device')->insert([
            'user_id'      => $uid,
            'token_hash'   => $hash,
            'device_id'    => mb_substr((string)($ctx['device_id'] ?? ''), 0, 64, 'UTF-8'),
            'device_name'  => self::deviceName((string)($ctx['ua'] ?? '')),
            'ip'           => mb_substr((string)($ctx['ip'] ?? ''), 0, 64, 'UTF-8'),
            'city'         => mb_substr(IpCityService::cityOf((string)($ctx['ip'] ?? '')), 0, 64, 'UTF-8'),
            'user_agent'   => mb_substr((string)($ctx['ua'] ?? ''), 0, 255, 'UTF-8'),
            'created_at'   => date('Y-m-d H:i:s', $now),
            'last_used_at' => date('Y-m-d H:i:s', $now),
            'expires_at'   => date('Y-m-d H:i:s', $now + $ttl),
            'status'       => 1,
            'revoke_reason'=> '',
        ]);

        // 顺带清理已过期 30 天以上的历史信任记录，避免表无限增长
        if (random_int(1, 50) === 1) {
            Db::name('sys_trusted_device')
                ->where('expires_at', '<', date('Y-m-d H:i:s', $now - 30 * 86400))
                ->delete();
        }

        return [
            'token'      => $token,
            'ttl'        => $ttl,
            'expires_at' => date('Y-m-d H:i:s', $now + $ttl),
        ];
    }

    /**
     * 校验受信任设备令牌
     * 若双方都有 device_id，则必须一致（防止令牌被复制到另一台浏览器）。
     *
     * v2.99.50：同时校验登录地归属城市。信任时记录的 city 与本次登录城市不一致
     * （跨市）即判为异地登录 → 撤销该账号全部信任设备、写入安全拦截日志并返回
     * false，调用方随之转入 TOTP 动态码验证（无法跳过二次鉴别）。
     *
     * @param string $ip 本次登录 IP（空则不进行归属地比对）
     * @param string $ua 本次登录 User-Agent（仅用于审计留痕）
     */
    public static function verifyTrustedToken(string $token, int $userId, string $deviceId = '', string $ip = '', string $ua = ''): bool
    {
        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token) || $userId <= 0) {
            return false;
        }
        try {
            self::ensureTables();
            $row = Db::name('sys_trusted_device')
                ->where('token_hash', self::hashToken($token))
                ->where('user_id', $userId)
                ->where('status', 1)
                ->find();
            if (!$row) {
                return false;
            }
            if (!empty($row['expires_at']) && strtotime((string)$row['expires_at']) < time()) {
                return false;
            }
            $boundId = (string)($row['device_id'] ?? '');
            // v2.96：绑定设备标识的令牌必须由同一浏览器携带 device_id 使用，
            // 缺少 device_id 不能绕过绑定校验（防止令牌被复制后直接调用接口）。
            if ($boundId !== '') {
                if ($deviceId === '' || !hash_equals($boundId, $deviceId)) {
                    return false;
                }
            }

            // v2.99.50：异地登录判定（跨市即视为异地）
            $trustCity = (string)($row['city'] ?? '');
            $nowCity   = IpCityService::cityOf($ip);
            if ($trustCity !== '' && $nowCity !== '' && $trustCity !== $nowCity) {
                self::revokeAllTrusted($userId, self::GEO_REVOKE_REASON);
                self::markGeoBlocked($userId, $trustCity, $nowCity, $ip);
                self::logGeoBlocked($userId, $trustCity, $nowCity, $ip, $ua);
                return false;
            }

            $upd = ['last_used_at' => date('Y-m-d H:i:s')];
            // 老版本建立的信任记录没有归属地：首次可用时用当次城市补基线，
            // 不打断登录；此后该设备即受异地校验保护。
            if ($trustCity === '' && $nowCity !== '') {
                $upd['city'] = $nowCity;
            }
            Db::name('sys_trusted_device')->where('id', (int)$row['id'])->update($upd);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** 异地登录撤销原因（写入 sys_trusted_device.revoke_reason） */
    public const GEO_REVOKE_REASON = '异地登录自动撤销';

    /** 异地拦截信息缓存键：供 TOTP challenge 向前端提示原因 */
    private const GEO_BLOCK_PREFIX = 'geo_block_uid_';

    /** 记录异地拦截上下文（10 分钟），由 TotpService::challenge 读取并下发给前端 */
    private static function markGeoBlocked(int $userId, string $from, string $to, string $ip): void
    {
        try {
            Cache::set(self::GEO_BLOCK_PREFIX . $userId, [
                'from' => $from,
                'to'   => $to,
                'ip'   => $ip,
                'at'   => time(),
            ], 600);
        } catch (\Throwable $e) {
            // 缓存不可用不影响拦截结果
        }
    }

    /** 读取并清除异地拦截上下文 */
    public static function pullGeoBlocked(int $userId): ?array
    {
        try {
            $v = Cache::get(self::GEO_BLOCK_PREFIX . $userId);
            if (!is_array($v)) {
                return null;
            }
            Cache::delete(self::GEO_BLOCK_PREFIX . $userId);
            if (time() - (int)($v['at'] ?? 0) > 600) {
                return null;
            }
            return $v;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** 写「安全拦截」登录日志（失败静默） */
    private static function logGeoBlocked(int $userId, string $from, string $to, string $ip, string $ua): void
    {
        try {
            $u = Db::name('sys_user')->alias('u')
                ->leftJoin('sys_role r', 'r.id = u.role_id')
                ->field('u.id,u.username,u.real_name,r.role_code')
                ->where('u.id', $userId)
                ->find();
            LoginLogService::record(
                (string)($u['username'] ?? ''),
                4,
                '异地登录（' . $from . ' → ' . $to . '），已撤销全部受信任设备，需重新动态验证',
                is_array($u) ? $u : ['id' => $userId],
                $ip,
                $ua,
                'password',
                '',
                ''
            );
        } catch (\Throwable $e) {
            // 审计失败不影响拦截
        }
    }

    public static function revokeTrusted(int $id, int $userId = 0, string $reason = '手动移除信任'): bool
    {
        if ($id <= 0) {
            return false;
        }
        self::ensureTables();
        $q = Db::name('sys_trusted_device')->where('id', $id);
        if ($userId > 0) {
            $q->where('user_id', $userId);
        }
        $n = $q->where('status', 1)->update([
            'status'        => 0,
            'revoke_reason' => mb_substr($reason, 0, 100, 'UTF-8'),
        ]);
        return $n > 0;
    }

    public static function revokeAllTrusted(int $userId, string $reason = '全部撤销信任'): int
    {
        if ($userId <= 0) {
            return 0;
        }
        self::ensureTables();
        return Db::name('sys_trusted_device')
            ->where('user_id', $userId)->where('status', 1)
            ->update([
                'status'        => 0,
                'revoke_reason' => mb_substr($reason, 0, 100, 'UTF-8'),
            ]);
    }

    public static function listTrusted(int $userId, bool $onlyActive = true): array
    {
        self::ensureTables();
        $q = Db::name('sys_trusted_device')->where('user_id', $userId);
        if ($onlyActive) {
            $q->where('status', 1)->where('expires_at', '>', date('Y-m-d H:i:s'));
        }
        return $q->order('id', 'desc')->select()->toArray();
    }
}
