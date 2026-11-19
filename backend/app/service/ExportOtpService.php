<?php
namespace app\service;

use app\model\SysUser;
use think\facade\Cache;

/**
 * 敏感信息导出 OTP 闸门（v2.99.49，国密/等保合规）
 *
 * 合规依据：《个人信息保护法》第51条（对敏感个人信息处理采取严格保护并记录）、
 *          等保2.0 三级「重要数据导出的二次鉴别」、GB/T 39786 基于密码技术的身份鉴别。
 *
 * 机制（与登录 TOTP 复用同一密钥，但独立成闸，不混用登录 challenge）：
 *   1. 前端点「导出」→ 先调 prepare(module)：
 *      - 账号未绑定 TOTP：直接返回 need_bind=true 拦截，前端引导去绑定（**超管也不豁免**，
 *        否则闸门可被高权限绕过，不满足强制二次鉴别要求）；
 *      - 已绑定：返回 need_otp=true，前端弹 6 位动态码框；
 *   2. 前端调 verify(module, code)：用账号 TOTP 密钥校验动态码（允许 ±1 时间片时钟漂移），
 *      通过则签发一张**一次性、短期（5 分钟）、绑定用户+模块**的导出 ticket 写缓存；
 *   3. 前端带 ticket 调原导出接口，consume() 校验通过即销毁（单次有效），随后才真正导出；
 *   4. 全程写敏感访问审计（purpose=export），验证失败也记 granted=0，便于发现违规试探。
 *
 * 安全约定：
 *   - 动态码错误沿用账号级失败计数与 15 分钟锁定（与登录 TOTP 同口径，防止无限试码）；
 *   - ticket 用随机字节生成，服务端只存缓存不落库，过期/复用/跨用户/跨模块一律拒绝；
 *   - 闸门只做「是否允许导出」的鉴别，具体数据范围仍由各控制器原有 scope/can 权限控制。
 */
class ExportOtpService
{
    /** 受 OTP 闸门保护的导出模块白名单（module => 中文名/审计对象类型） */
    public const MODULES = [
        'staff'            => '人员档案',
        'credential_vault' => '账号密码库',
        'certificate'      => '证书台账',
    ];

    /** ticket 有效期（秒） */
    public const TICKET_TTL = 300;

    /** 单账号动态码连续失败锁定阈值 / 锁定时长（与 TotpService 保持一致） */
    private const FAIL_MAX = 10;
    private const FAIL_TTL = 900;

    private const TICKET_PREFIX = 'export_otp_ticket_';
    private const FAIL_PREFIX   = 'totp_fail_uid_';
    private const LOCK_PREFIX   = 'totp_lock_uid_';

    /**
     * 导出前预检：返回前端下一步动作
     *
     * @return array{need_otp:bool,need_bind:bool,module:string,module_label:string}
     */
    public static function prepare(int $userId, string $module): array
    {
        $module = self::normModule($module);
        $user = SysUser::find($userId);
        $bound = $user && TotpService::isBound($user);

        if (!$bound) {
            // 未绑定：记一条拒绝审计，明确拦截并引导绑定
            self::audit($userId, $module, false, '未绑定动态验证码，拦截敏感导出');
            return [
                'need_otp'      => false,
                'need_bind'     => true,
                'module'        => $module,
                'module_label'  => self::MODULES[$module],
            ];
        }

        return [
            'need_otp'      => true,
            'need_bind'     => false,
            'module'        => $module,
            'module_label'  => self::MODULES[$module],
        ];
    }

    /**
     * 校验动态码并签发一次性导出 ticket
     *
     * @return array{ok:bool,msg:string,ticket:string}
     */
    public static function verify(int $userId, string $module, string $code): array
    {
        $module = self::normModule($module);
        $user = SysUser::find($userId);
        if (!$user || (int)$user->status !== 1) {
            return ['ok' => false, 'msg' => '账号不存在或已被禁用', 'ticket' => ''];
        }
        if (!TotpService::isBound($user)) {
            self::audit($userId, $module, false, '未绑定动态验证码，拦截敏感导出');
            return ['ok' => false, 'msg' => '账号尚未绑定动态验证码，请先绑定后再导出', 'ticket' => ''];
        }

        if (Cache::get(self::LOCK_PREFIX . $userId)) {
            self::audit($userId, $module, false, '动态码失败次数过多，锁定中仍尝试导出');
            return ['ok' => false, 'msg' => '动态验证码错误次数过多，请 15 分钟后再试', 'ticket' => ''];
        }

        $code = preg_replace('/\D/', '', (string)$code);
        if (strlen($code) !== TotpService::DIGITS) {
            return ['ok' => false, 'msg' => '请输入 6 位动态验证码', 'ticket' => ''];
        }

        $secret = TotpService::decryptSecret((string)($user->totp_secret ?? ''));
        if ($secret === '') {
            // 密钥损坏：清空绑定，引导重新绑定，避免锁死
            $user->totp_secret = '';
            $user->totp_enabled = 0;
            $user->totp_bound_at = null;
            $user->save();
            self::audit($userId, $module, false, '动态验证码密钥失效，拦截导出');
            return ['ok' => false, 'msg' => '动态验证码密钥已失效，请重新绑定后再导出', 'ticket' => ''];
        }

        if (!TotpService::verifyCode($secret, $code)) {
            // 失败计数 + 阈值锁定
            $failKey = self::FAIL_PREFIX . $userId;
            $fails = (int)Cache::get($failKey) + 1;
            if ($fails >= self::FAIL_MAX) {
                Cache::set(self::LOCK_PREFIX . $userId, 1, self::FAIL_TTL);
                Cache::delete($failKey);
            } else {
                Cache::set($failKey, $fails, self::FAIL_TTL);
            }
            self::audit($userId, $module, false, "动态验证码错误（第 {$fails} 次）");
            return ['ok' => false, 'msg' => '动态验证码错误', 'ticket' => ''];
        }

        // 通过：清失败计数，签发一次性 ticket
        Cache::delete(self::FAIL_PREFIX . $userId);
        $ticket = bin2hex(random_bytes(24));
        Cache::set(self::TICKET_PREFIX . $ticket, [
            'uid'    => $userId,
            'module' => $module,
            'issued' => time(),
        ], self::TICKET_TTL);

        self::audit($userId, $module, true, '导出二次验证通过，已签发一次性导出凭证');
        return ['ok' => true, 'msg' => '验证通过', 'ticket' => $ticket];
    }

    /**
     * 导出接口消费 ticket：校验归属/模块/有效期，通过后立即销毁（单次有效）。
     * 通过返回 true；失败返回 false（调用方应返回 403，不产出文件）。
     */
    public static function consume(int $userId, string $module, string $ticket): bool
    {
        $module = self::normModule($module);
        $ticket = trim($ticket);
        if ($ticket === '' || !preg_match('/^[a-f0-9]{32,128}$/', $ticket)) {
            self::audit($userId, $module, false, '导出凭证缺失或格式非法');
            return false;
        }
        $key = self::TICKET_PREFIX . $ticket;
        $payload = Cache::get($key);
        if (!is_array($payload)) {
            self::audit($userId, $module, false, '导出凭证不存在或已过期');
            return false;
        }
        // 一次性：无论后续校验是否通过，先销毁，防重放
        Cache::delete($key);

        if ((int)($payload['uid'] ?? 0) !== $userId || (string)($payload['module'] ?? '') !== $module) {
            self::audit($userId, $module, false, '导出凭证与账号/模块不匹配（疑似跨模块或越权复用）');
            return false;
        }
        return true;
    }

    /** 模块白名单归一，非法模块直接抛 400 */
    public static function normModule(string $module): string
    {
        $module = trim($module);
        if (!isset(self::MODULES[$module])) {
            throw new \think\exception\HttpResponseException(json(r_error('非法的导出模块', 400)));
        }
        return $module;
    }

    /**
     * 写敏感访问审计（复用 sensitive_access_log，purpose=export）。
     * 审计失败不阻断主流程（SensitiveAccessService 内部已吞异常）。
     */
    private static function audit(int $userId, string $module, bool $granted, string $remark): void
    {
        try {
            // 复用敏感访问审计表；field 用导出模块专属标识，target 存结果说明，owner_id 记用户自身
            SensitiveAccessService::recordExport(
                $userId,
                $module,
                self::MODULES[$module] ?? $module,
                $granted,
                $remark
            );
        } catch (\Throwable $e) {
            // 审计不阻断导出鉴别
        }
    }
}
