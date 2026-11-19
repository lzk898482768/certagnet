<?php
namespace app\service;

use app\model\SysUser;
use think\facade\Cache;
use think\facade\Db;

/**
 * 微信小程序接入（v2.100）
 *
 * 能力：
 *  1) 后台可配置 AppID / Secret / 接入 Token，以及小程序端可见的功能菜单；
 *  2) 小程序请求携带 X-Client: miniapp + X-Miniapp-Token，由 MiniAppAuth 中间件校验；
 *  3) wx.login 的 code 在服务端调用 jscode2session 换 openid，已绑定账号直接签 token；
 *  4) 未绑定时返回一次性 bind_ticket，需账号密码校验或 PC 端一次性绑定码完成绑定；
 *  5) 绑定关系存 sys_miniapp_binding，按 (appid, openid) 与 (appid, user_id) 唯一。
 *
 * 安全约定：
 *  - Secret 只存服务端，任何接口都不回显；
 *  - 接入 Token 只允许超级管理员查看/重置；
 *  - bind_ticket 10 分钟有效，绑定成功后立即作废；
 *  - PC 绑定码 10 分钟有效、一次性使用；
 *  - 密码绑定失败按 ticket+IP 限次，避免被当成撞库入口。
 */
class MiniAppService
{
    public const VERSION = 'v2.100';

    public const TABLE = 'sys_miniapp_binding';

    /** 绑定票据 / 绑定码有效期（秒） */
    public const TICKET_TTL = 600;
    public const BIND_CODE_TTL = 600;

    /** 密码绑定失败上限 */
    public const MAX_BIND_FAIL = 8;

    protected static $ready = false;

    /**
     * 小程序功能目录（v2.99.26 起与 MobileConfigService::navCatalog 统一）。
     * 保留 group/desc/sensitive 字段以兼容后台小程序卡片与审核模式渲染。
     */
    public static function catalog(): array
    {
        $groupName = ['common' => '核心业务', 'manage' => '进阶功能'];
        $out = [];
        foreach (MobileConfigService::navCatalog() as $item) {
            $out[] = [
                'id'        => $item['id'],
                'label'     => $item['label'],
                'group'     => $groupName[$item['group']] ?? '核心业务',
                'sensitive' => !empty($item['sensitive']),
                'desc'      => $item['label'] . '（统一功能导航）',
            ];
        }
        return $out;
    }

    /** 审核模式默认隐藏的敏感模块（v2.99.26 起与 MobileConfigService 统一目录口径一致） */
    public static function sensitiveIds(): array
    {
        return MobileConfigService::sensitiveNavIds();
    }

    public static function catalogIds(): array
    {
        return array_map(static fn ($x) => $x['id'], self::catalog());
    }

    // ============================================================
    // 配置读写
    // ============================================================

    public static function settings(): array
    {
        try {
            $rows = \app\model\SystemConfig::column('config_value', 'config_key');
        } catch (\Throwable $e) {
            $rows = [];
        }
        return [
            'enabled'       => (string)($rows['miniapp_enabled'] ?? '0') === '1',
            'login_enabled' => (string)($rows['miniapp_login_enabled'] ?? '1') === '1',
            'audit_mode'    => (string)($rows['miniapp_audit_mode'] ?? '0') === '1',
            'appid'         => trim((string)($rows['miniapp_appid'] ?? '')),
            'secret'        => trim((string)($rows['miniapp_secret'] ?? '')),
            'token'         => trim((string)($rows['miniapp_token'] ?? '')),
        ];
    }

    /**
     * 实际生效的小程序菜单（v2.99.26 起以统一功能导航 mobile_navs 为唯一数据源）。
     *
     * - 显隐取 MobileConfigService::effectiveNavs()（三端同一份配置）；
     * - 审核模式优先级最高：即使统一导航里为 true，也强制隐藏敏感模块；
     * - 返回保持 {id: bool} 结构，兼容小程序端 /miniapp/config 与历史调用。
     */
    public static function effectiveMenus(): array
    {
        $auditMode = self::settings()['audit_mode'];
        $menus = [];
        foreach (MobileConfigService::effectiveNavs() as $id => $v) {
            $menus[$id] = !empty($v['enabled']);
        }
        if ($auditMode) {
            foreach (self::sensitiveIds() as $id) {
                if (array_key_exists($id, $menus)) {
                    $menus[$id] = false;
                }
            }
        }
        return $menus;
    }

    /** 管理端配置（包含完整 Token，仅超级管理员可访问） */
    public static function adminConfig(): array
    {
        $cfg = self::settings();
        return [
            'enabled'       => $cfg['enabled'],
            'login_enabled' => $cfg['login_enabled'],
            'audit_mode'    => $cfg['audit_mode'],
            'appid'         => $cfg['appid'],
            'secret_set'    => $cfg['secret'] !== '',
            'token'         => $cfg['token'],
            'token_set'     => $cfg['token'] !== '',
            'menus'         => self::effectiveMenus(),
            'catalog'       => self::catalog(),
            'sensitive_ids' => self::sensitiveIds(),
            'bound_count'   => self::boundCount(),
        ];
    }

    /** 小程序端公开配置（不含 Secret，不含接入 Token 本身） */
    public static function publicConfig(): array
    {
        $cfg = self::settings();
        return [
            'enabled'        => $cfg['enabled'],
            'login_enabled'  => $cfg['login_enabled'],
            'audit_mode'     => $cfg['audit_mode'],
            'token_required' => $cfg['token'] !== '',
            'menus'          => self::effectiveMenus(),
        ];
    }

    /**
     * 保存配置。
     * $data 允许键：enabled / login_enabled / audit_mode / appid / secret / menus
     */
    public static function save(array $data): array
    {
        $cfg = self::settings();
        $rows = [];
        $navUpdated = false;

        foreach (['enabled', 'login_enabled', 'audit_mode'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $rows['miniapp_' . $flag] = $data[$flag] ? '1' : '0';
            }
        }
        if (array_key_exists('appid', $data)) {
            $appid = trim((string)$data['appid']);
            if ($appid !== '' && !preg_match('/^[A-Za-z0-9_\-]{6,64}$/', $appid)) {
                throw new \Exception('AppID 格式不正确（应为微信公众平台的小程序 AppID）');
            }
            $rows['miniapp_appid'] = $appid;
        }
        if (array_key_exists('secret', $data) && trim((string)$data['secret']) !== '') {
            $secret = trim((string)$data['secret']);
            if (strlen($secret) < 16) {
                throw new \Exception('AppSecret 长度不正确，请从微信公众平台复制完整的 AppSecret');
            }
            $rows['miniapp_secret'] = $secret;
        }
        if (array_key_exists('menus', $data) && is_array($data['menus'])) {
            // v2.99.26：小程序菜单显隐并入统一功能导航 mobile_navs（三端共用一份），
            // 不再单独写 miniapp_menus；审核模式的强制隐藏在 effectiveMenus() 读取时叠加。
            $valid = self::catalogIds();
            $visibility = [];
            foreach ($data['menus'] as $id => $enabled) {
                $id = (string)$id;
                if (in_array($id, $valid, true)) {
                    $visibility[$id] = (bool)$enabled;
                }
            }
            if ($visibility) {
                MobileConfigService::updateNavVisibility($visibility);
                $navUpdated = true;
            }
        }

        // 首次保存即自动生成接入 Token（避免启用后小程序无法对接）
        if (($rows['miniapp_enabled'] ?? ($cfg['enabled'] ? '1' : '0')) === '1'
            && $cfg['token'] === '' && !array_key_exists('miniapp_token', $rows)) {
            $rows['miniapp_token'] = self::randomToken();
        }

        if (!$rows && !$navUpdated) {
            throw new \Exception('没有需要保存的内容');
        }

        foreach ($rows as $key => $value) {
            $row = \app\model\SystemConfig::where('config_key', $key)->find();
            if ($row) {
                $row->save(['config_value' => (string)$value]);
            } else {
                \app\model\SystemConfig::create([
                    'config_key'   => $key,
                    'config_value' => (string)$value,
                    'config_group' => 'miniapp',
                    'config_name'  => $key,
                ]);
            }
        }
        Cache::delete('sys_config_map');
        return self::adminConfig();
    }

    public static function randomToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** 重新生成接入 Token（旧 Token 立即失效） */
    public static function regenerateToken(): array
    {
        $token = self::randomToken();
        $row = \app\model\SystemConfig::where('config_key', 'miniapp_token')->find();
        if ($row) {
            $row->save(['config_value' => $token]);
        } else {
            \app\model\SystemConfig::create([
                'config_key'   => 'miniapp_token',
                'config_value' => $token,
                'config_group' => 'miniapp',
                'config_name'  => '小程序接入 Token',
            ]);
        }
        Cache::delete('sys_config_map');
        return ['token' => $token];
    }

    /** 校验小程序接入 Token（服务端未配置 Token 时放行，兼容首次接入） */
    public static function verifyToken(string $given): bool
    {
        $expected = self::settings()['token'];
        if ($expected === '') {
            return true;
        }
        return $given !== '' && hash_equals($expected, $given);
    }

    // ============================================================
    // 绑定表
    // ============================================================

    public static function ddl(): string
    {
        return "CREATE TABLE IF NOT EXISTS `" . self::TABLE . "` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '系统用户ID',
  `appid` varchar(64) NOT NULL DEFAULT '' COMMENT '小程序 AppID',
  `openid` varchar(64) NOT NULL DEFAULT '' COMMENT '微信 openid',
  `unionid` varchar(64) NOT NULL DEFAULT '' COMMENT '微信 unionid（可选）',
  `nickname` varchar(100) NOT NULL DEFAULT '' COMMENT '微信昵称（可选）',
  `created_at` datetime DEFAULT NULL COMMENT '绑定时间',
  `last_login_at` datetime DEFAULT NULL COMMENT '最后登录时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_app_openid` (`appid`,`openid`),
  UNIQUE KEY `uk_app_user` (`appid`,`user_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='微信小程序账号绑定（v2.100）'";
    }

    public static function ensureTable(): void
    {
        if (self::$ready) {
            return;
        }
        try {
            ensure_table(self::TABLE, self::ddl());
            // v2.99.25：ensure_table 见表已存在即跳过，不会补列。老库若由早期 DDL 建表、
            // 缺少后续新增列（如 nickname），bindUser 写入会触发 1054 Unknown column。
            // 这里按 TotpService::ensureColumns 的既有约定做列级自愈（幂等，失败静默重试）。
            ensure_column(
                self::TABLE,
                'unionid',
                "varchar(64) NOT NULL DEFAULT '' COMMENT '微信 unionid（可选）' AFTER `openid`"
            );
            ensure_column(
                self::TABLE,
                'nickname',
                "varchar(100) NOT NULL DEFAULT '' COMMENT '微信昵称（可选）' AFTER `unionid`"
            );
            ensure_column(
                self::TABLE,
                'last_login_at',
                "datetime DEFAULT NULL COMMENT '最后登录时间' AFTER `created_at`"
            );
            self::$ready = true;
        } catch (\Throwable $e) {
            self::$ready = false;
        }
    }

    public static function bindingStatus(int $userId): array
    {
        self::ensureTable();
        $row = Db::name(self::TABLE)->where('user_id', $userId)->order('id', 'desc')->find();
        if (!$row) {
            return ['bound' => false, 'openid' => '', 'appid' => '', 'bound_at' => null, 'last_login_at' => null];
        }
        return [
            'bound'         => true,
            'openid'        => self::maskOpenid((string)$row['openid']),
            'appid'         => (string)$row['appid'],
            'bound_at'      => $row['created_at'] ?? null,
            'last_login_at' => $row['last_login_at'] ?? null,
        ];
    }

    /** 批量取绑定状态（后台账号列表） */
    public static function bindingMap(array $userIds): array
    {
        self::ensureTable();
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (!$userIds) {
            return [];
        }
        $out = [];
        foreach (array_chunk($userIds, 500) as $ids) {
            $rows = Db::name(self::TABLE)->whereIn('user_id', $ids)->order('id', 'desc')->select()->toArray();
            foreach ($rows as $row) {
                $uid = (int)$row['user_id'];
                if (isset($out[$uid])) {
                    continue;
                }
                $out[$uid] = [
                    'bound'         => true,
                    'openid'        => self::maskOpenid((string)$row['openid']),
                    'appid'         => (string)$row['appid'],
                    'bound_at'      => $row['created_at'] ?? null,
                    'last_login_at' => $row['last_login_at'] ?? null,
                ];
            }
        }
        return $out;
    }

    public static function boundCount(): int
    {
        try {
            self::ensureTable();
            return (int)Db::name(self::TABLE)->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public static function unbind(int $userId): void
    {
        self::ensureTable();
        Db::name(self::TABLE)->where('user_id', $userId)->delete();
    }

    /** 生成 PC 端一次性绑定码（供小程序登录页输入） */
    public static function createBindCode(int $userId): array
    {
        self::ensureTable();
        $user = SysUser::find($userId);
        if (!$user) {
            throw new \Exception('账号不存在');
        }
        if ((int)$user->status !== 1) {
            throw new \Exception('账号已禁用，无法绑定');
        }
        // 避免 6 位码冲突：最多重试 20 次
        $code = '';
        for ($i = 0; $i < 20; $i++) {
            $candidate = (string)random_int(100000, 999999);
            if (!Cache::get('miniapp_bind_code_' . $candidate)) {
                $code = $candidate;
                break;
            }
        }
        if ($code === '') {
            throw new \Exception('生成绑定码失败，请稍后重试');
        }
        Cache::set('miniapp_bind_code_' . $code, [
            'user_id' => $userId,
            'created' => time(),
        ], self::BIND_CODE_TTL);
        return [
            'code'       => $code,
            'expires_in' => self::BIND_CODE_TTL,
            'username'   => (string)$user->username,
            'real_name'  => (string)$user->real_name,
        ];
    }

    // ============================================================
    // 小程序登录 / 绑定
    // ============================================================

    public static function isDev(array $cfg = null): bool
    {
        $cfg = $cfg ?: self::settings();
        $appid = strtoupper($cfg['appid']);
        return $appid === '' || str_starts_with($appid, 'DEV_');
    }

    /** code 换 openid（真实环境调微信接口；DEV_ 开头走本地沙箱） */
    public static function jscode2session(array $cfg, string $code): array
    {
        $code = trim($code);
        if ($code === '') {
            throw new \Exception('缺少微信登录 code');
        }
        if (self::isDev($cfg)) {
            $safe = preg_replace('/[^A-Za-z0-9_\-]/', '', $code);
            return [
                'openid'      => 'dev_' . ($safe !== '' ? $safe : substr(sha1($code), 0, 16)),
                'unionid'     => '',
                'session_key' => '',
                'dev'         => true,
            ];
        }
        if ($cfg['appid'] === '' || $cfg['secret'] === '') {
            throw new \Exception('小程序 AppID / AppSecret 未配置');
        }
        $url = 'https://api.weixin.qq.com/sns/jscode2session?appid=' . urlencode($cfg['appid'])
            . '&secret=' . urlencode($cfg['secret'])
            . '&js_code=' . urlencode($code)
            . '&grant_type=authorization_code';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = (string)curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === '') {
            throw new \Exception('微信登录接口请求失败' . ($err ? '：' . $err : ''));
        }
        $j = json_decode($raw, true);
        if (!is_array($j)) {
            throw new \Exception('微信登录接口返回异常');
        }
        if (!empty($j['errcode'])) {
            $map = [
                40029 => '微信登录 code 无效或已过期，请重试',
                45011 => '微信登录过于频繁，请稍后再试',
                40163 => '微信登录 code 已被使用，请重试',
            ];
            throw new \Exception($map[(int)$j['errcode']] ?? ('微信登录失败：' . ($j['errmsg'] ?? ('错误码 ' . $j['errcode']))));
        }
        if (empty($j['openid'])) {
            throw new \Exception('微信登录失败：未获取到 openid');
        }
        return [
            'openid'      => (string)$j['openid'],
            'unionid'     => (string)($j['unionid'] ?? ''),
            'session_key' => (string)($j['session_key'] ?? ''),
            'dev'         => false,
        ];
    }

    /**
     * 小程序一键登录：已绑定直接签 token；未绑定返回 bind_ticket。
     */
    public static function loginByCode(string $code, string $ip = '', string $ua = '', string $deviceId = '', string $deviceToken = ''): array
    {
        self::ensureTable();
        $cfg = self::settings();
        if (!$cfg['enabled']) {
            throw new \Exception('小程序接入未启用，请联系管理员在后台开启');
        }
        if (!$cfg['login_enabled']) {
            throw new \Exception('小程序登录未启用，请使用账号密码登录');
        }
        $sess = self::jscode2session($cfg, $code);
        $appid = $cfg['appid'] !== '' ? $cfg['appid'] : 'dev';

        $binding = Db::name(self::TABLE)->where('appid', $appid)->where('openid', $sess['openid'])->find();
        if ($binding) {
            $user = SysUser::find((int)$binding['user_id']);
            if (!$user) {
                Db::name(self::TABLE)->where('id', $binding['id'])->delete();
            } else {
                if ((int)$user->status !== 1) {
                    throw new \Exception('该微信绑定的账号已被禁用，请联系管理员');
                }
                Db::name(self::TABLE)->where('id', $binding['id'])->update([
                    'unionid'       => $sess['unionid'] !== '' ? $sess['unionid'] : (string)($binding['unionid'] ?? ''),
                    'last_login_at' => date('Y-m-d H:i:s'),
                ]);
                $challenge = TotpService::challenge($user, 'miniapp', $ip, $ua, $deviceToken, $deviceId);
                if ($challenge !== null) {
                    return array_merge($challenge, ['need_bind' => false]);
                }
                $r = self::issueToken($user, $ip, $ua, $deviceId);
                LoginLogService::record(
                    (string)$user->username,
                    1,
                    '微信小程序登录成功',
                    $r['user'] ?? null,
                    $ip,
                    $ua,
                    'miniapp',
                    $deviceId,
                    DeviceService::deviceName($ua)
                );
                return array_merge($r, ['need_bind' => false]);
            }
        }

        // 未绑定：签发 10 分钟一次性绑定票据
        $ticket = bin2hex(random_bytes(16));
        Cache::set('miniapp_bind_ticket_' . $ticket, [
            'appid'       => $appid,
            'openid'      => $sess['openid'],
            'unionid'     => $sess['unionid'],
            'session_key' => $sess['session_key'],
            'dev'         => (bool)$sess['dev'],
            'created'     => time(),
        ], self::TICKET_TTL);

        return [
            'need_bind'    => true,
            'ticket'       => $ticket,
            'expires_in'   => self::TICKET_TTL,
            'appid'        => $appid,
            'dev'          => (bool)$sess['dev'],
            'login_methods' => ['password', 'bind_code'],
        ];
    }

    /** 凭账号密码完成绑定 */
    public static function bindByPassword(string $ticket, string $username, string $password, string $ip = '', string $ua = '', string $deviceId = '', string $deviceToken = ''): array
    {
        $data = self::loadTicket($ticket);
        $username = trim($username);
        if ($username === '' || $password === '') {
            throw new \Exception('请输入账号和密码');
        }

        $failKey = 'miniapp_bind_fail_' . md5($ticket . '|' . $ip);
        $fails = (int)Cache::get($failKey, 0);
        if ($fails >= self::MAX_BIND_FAIL) {
            throw new \Exception('密码错误次数过多，请 10 分钟后重试，或在电脑端生成绑定码');
        }

        TotpService::ensureColumns();
        $user = SysUser::where('username', $username)->find();
        if (!$user || !password_verify($password, (string)$user->password)) {
            Cache::set($failKey, $fails + 1, self::TICKET_TTL);
            LoginLogService::record($username, 2, '小程序绑定失败：账号或密码错误', null, $ip, $ua, 'miniapp', $deviceId, DeviceService::deviceName($ua));
            throw new \Exception('账号或密码错误');
        }
        if ((int)$user->status !== 1) {
            throw new \Exception('账号已被禁用，无法绑定');
        }

        self::bindUser($data, $user);
        Cache::delete($failKey);

        $challenge = TotpService::challenge($user, 'miniapp', $ip, $ua, $deviceToken, $deviceId);
        if ($challenge !== null) {
            Cache::delete('miniapp_bind_ticket_' . $ticket);
            return array_merge($challenge, ['need_bind' => false]);
        }

        $r = self::issueToken($user, $ip, $ua, $deviceId);
        Cache::delete('miniapp_bind_ticket_' . $ticket);
        LoginLogService::record((string)$user->username, 1, '微信小程序绑定并登录成功', $r['user'] ?? null, $ip, $ua, 'miniapp', $deviceId, DeviceService::deviceName($ua));
        return array_merge($r, ['need_bind' => false]);
    }

    /** 凭 PC 端一次性绑定码完成绑定 */
    public static function bindByCode(string $ticket, string $code, string $ip = '', string $ua = '', string $deviceId = ''): array
    {
        $data = self::loadTicket($ticket);
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== 6) {
            throw new \Exception('请输入 6 位绑定码');
        }
        $hit = Cache::get('miniapp_bind_code_' . $code);
        if (!$hit || empty($hit['user_id'])) {
            throw new \Exception('绑定码无效或已过期，请在电脑端重新生成');
        }
        $user = SysUser::find((int)$hit['user_id']);
        if (!$user) {
            Cache::delete('miniapp_bind_code_' . $code);
            throw new \Exception('绑定码对应的账号不存在');
        }
        if ((int)$user->status !== 1) {
            throw new \Exception('账号已被禁用，无法绑定');
        }
        self::bindUser($data, $user);
        Cache::delete('miniapp_bind_code_' . $code);
        Cache::delete('miniapp_bind_ticket_' . $ticket);

        $r = self::issueToken($user, $ip, $ua, $deviceId);
        LoginLogService::record((string)$user->username, 1, '微信小程序绑定码绑定并登录成功', $r['user'] ?? null, $ip, $ua, 'miniapp', $deviceId, DeviceService::deviceName($ua));
        return array_merge($r, ['need_bind' => false]);
    }

    /** 读取并校验绑定票据 */
    private static function loadTicket(string $ticket): array
    {
        self::ensureTable();
        $ticket = trim($ticket);
        if ($ticket === '') {
            throw new \Exception('绑定会话已失效，请重新点击微信一键登录');
        }
        $data = Cache::get('miniapp_bind_ticket_' . $ticket);
        if (!is_array($data) || empty($data['openid'])) {
            throw new \Exception('绑定会话已失效，请重新点击微信一键登录');
        }
        return $data;
    }

    /** 写入绑定关系（唯一性校验） */
    private static function bindUser(array $ticketData, SysUser $user): void
    {
        self::ensureTable();
        $appid  = (string)($ticketData['appid'] ?? 'dev');
        $openid = (string)($ticketData['openid'] ?? '');
        if ($openid === '') {
            throw new \Exception('微信身份信息缺失，请重新登录');
        }

        $otherOpenid = Db::name(self::TABLE)
            ->where('appid', $appid)
            ->where('openid', $openid)
            ->where('user_id', '<>', (int)$user->id)
            ->find();
        if ($otherOpenid) {
            throw new \Exception('该微信已绑定其他系统账号，请先在原账号解绑');
        }

        $otherUser = Db::name(self::TABLE)
            ->where('appid', $appid)
            ->where('user_id', (int)$user->id)
            ->where('openid', '<>', $openid)
            ->find();
        if ($otherUser) {
            throw new \Exception('该账号已绑定其他微信，请先在电脑端解绑小程序');
        }

        $now = date('Y-m-d H:i:s');
        $row = Db::name(self::TABLE)->where('appid', $appid)->where('openid', $openid)->find();
        if ($row) {
            Db::name(self::TABLE)->where('id', $row['id'])->update([
                'user_id'       => (int)$user->id,
                'unionid'       => (string)($ticketData['unionid'] ?? ''),
                'last_login_at' => $now,
            ]);
        } else {
            Db::name(self::TABLE)->insert([
                'user_id'       => (int)$user->id,
                'appid'         => $appid,
                'openid'        => $openid,
                'unionid'       => (string)($ticketData['unionid'] ?? ''),
                'nickname'      => '',
                'created_at'    => $now,
                'last_login_at' => $now,
            ]);
        }
    }

    /** 复用系统令牌签发逻辑 */
    private static function issueToken(SysUser $user, string $ip, string $ua, string $deviceId): array
    {
        return AuthService::issueToken($user, [
            'device_id'  => $deviceId,
            'ip'         => $ip,
            'ua'         => $ua,
            'login_type' => 'miniapp',
        ]);
    }

    private static function maskOpenid(string $openid): string
    {
        if ($openid === '') {
            return '';
        }
        if (strlen($openid) <= 10) {
            return substr($openid, 0, 3) . '****';
        }
        return substr($openid, 0, 6) . '****' . substr($openid, -4);
    }
}
