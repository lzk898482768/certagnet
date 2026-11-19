<?php
namespace app\service;

use think\facade\Db;

/**
 * 用户登录日志（v2.70）
 *
 * 记录每一次登录尝试：成功 / 失败 / 因连续失败被临时锁定。
 * 设计约定：
 *   - 登录日志写入失败**绝不影响登录主流程**（静默 try/catch）；
 *   - 表结构中文注释与 init.sql 保持一致，老库由 ensure_table 运行时自愈；
 *   - 仅记录账号、角色、IP、UA 与结果，不记录密码 / 验证码。
 */
class LoginLogService
{
    /** 日志结构版本：改动字段时 +1（仅用于注释追溯） */
    public const VERSION = 'v2.70';

    /** 状态：1 成功 / 2 失败 / 3 锁定 / 4 安全拦截（v2.99.50：异地登录撤销信任） */
    public const STATUSES = [
        1 => '登录成功',
        2 => '登录失败',
        3 => '账号锁定',
        4 => '安全拦截',
    ];

    /** 登录方式：password 账号密码 / wecom 企业微信 */
    public const LOGIN_TYPES = [
        'password' => '账号密码',
        'wecom'    => '企业微信',
        'miniapp'  => '微信小程序',
    ];

    /** 进程内只建表/探测一次 */
    protected static $ready = false;

    public static function ddl(): string
    {
        return "CREATE TABLE IF NOT EXISTS `login_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '用户ID（未匹配到账号时为0）',
  `username` varchar(64) NOT NULL DEFAULT '' COMMENT '登录账号',
  `real_name` varchar(64) NOT NULL DEFAULT '' COMMENT '姓名',
  `role_code` varchar(32) NOT NULL DEFAULT '' COMMENT '角色编码',
  `login_type` varchar(16) NOT NULL DEFAULT 'password' COMMENT '登录方式: password账号密码 / wecom企业微信',
  `ip` varchar(64) NOT NULL DEFAULT '' COMMENT '客户端IP',
  `user_agent` varchar(255) NOT NULL DEFAULT '' COMMENT 'User-Agent',
  `device_id`  varchar(64) NOT NULL DEFAULT '' COMMENT '设备标识（v2.96）',
  `device_name` varchar(100) NOT NULL DEFAULT '' COMMENT '设备名称（v2.96）',
  `login_city` varchar(64) NOT NULL DEFAULT '' COMMENT '登录地归属城市（v2.99.50）',
  `status` tinyint NOT NULL DEFAULT 2 COMMENT '1成功 2失败 3锁定 4安全拦截',
  `message` varchar(255) NOT NULL DEFAULT '' COMMENT '结果说明',
  `login_time` datetime DEFAULT NULL COMMENT '登录时间',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_login_time` (`login_time`),
  KEY `idx_username` (`username`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户登录日志（v2.70）'";
    }

    public static function ensureTables(): void
    {
        if (self::$ready) {
            return;
        }
        self::$ready = true;
        try {
            ensure_table('login_log', self::ddl());
            // v2.74：区分账号密码 / 企业微信登录（企微登录同样写入日志）
            ensure_column(
                'login_log',
                'login_type',
                "varchar(16) NOT NULL DEFAULT 'password' COMMENT '登录方式: password账号密码 / wecom企业微信' AFTER `role_code`"
            );
            // v2.96：记录设备标识与设备名称，便于安全审计
            ensure_column(
                'login_log',
                'device_id',
                "varchar(64) NOT NULL DEFAULT '' COMMENT '设备标识（v2.96）' AFTER `user_agent`"
            );
            ensure_column(
                'login_log',
                'device_name',
                "varchar(100) NOT NULL DEFAULT '' COMMENT '设备名称（v2.96）' AFTER `device_id`"
            );
            // v2.99.50：记录登录归属地城市，便于异地登录审计与举证
            ensure_column(
                'login_log',
                'login_city',
                "varchar(64) NOT NULL DEFAULT '' COMMENT '登录地归属城市（v2.99.50）' AFTER `device_name`"
            );
        } catch (\Throwable $e) {
            // 建表失败不阻断登录，下次请求会重试
            self::$ready = false;
        }
    }

    /**
     * 写入一条登录日志（失败静默，不影响登录）
     *
     * @param string     $username 登录账号
     * @param int        $status   1 成功 / 2 失败 / 3 锁定
     * @param string     $message  结果说明（与返回给用户的提示保持一致）
     * @param array|null $user     已匹配到的用户信息（id / username / real_name / role_code）
     * @param string     $ip       客户端 IP
     * @param string     $ua       User-Agent
     * @param string     $type     登录方式 password / wecom（v2.74）
     * @param string     $deviceId 设备标识（v2.96）
     * @param string     $deviceName 设备名称（v2.96）
     */
    public static function record(
        string $username,
        int $status,
        string $message = '',
        ?array $user = null,
        string $ip = '',
        string $ua = '',
        string $type = 'password',
        string $deviceId = '',
        string $deviceName = ''
    ): void {
        try {
            self::ensureTables();
            if (!isset(self::STATUSES[$status])) {
                $status = 2;
            }
            if (!isset(self::LOGIN_TYPES[$type])) {
                $type = 'password';
            }
            $now  = date('Y-m-d H:i:s');
            $user = is_array($user) ? $user : [];
            Db::name('login_log')->insert([
                'user_id'     => (int)($user['id'] ?? 0),
                'username'    => mb_substr($username !== '' ? $username : (string)($user['username'] ?? ''), 0, 64, 'UTF-8'),
                'real_name'   => mb_substr((string)($user['real_name'] ?? ''), 0, 64, 'UTF-8'),
                'role_code'   => mb_substr((string)($user['role_code'] ?? ''), 0, 32, 'UTF-8'),
                'login_type'  => $type,
                'ip'          => mb_substr($ip, 0, 64, 'UTF-8'),
                'user_agent'  => mb_substr($ua, 0, 255, 'UTF-8'),
                'device_id'   => mb_substr($deviceId, 0, 64, 'UTF-8'),
                'device_name' => mb_substr($deviceName !== '' ? $deviceName : DeviceService::deviceName($ua), 0, 100, 'UTF-8'),
                'login_city'  => mb_substr(IpCityService::cityName($ip), 0, 64, 'UTF-8'),
                'status'      => $status,
                'message'     => mb_substr($message, 0, 255, 'UTF-8'),
                'login_time'  => $now,
                'create_time' => $now,
            ]);
        } catch (\Throwable $e) {
            // 登录日志失败不阻断登录
        }
    }
}
