<?php
namespace app\service;

use app\model\SysUser;
use think\facade\Cache;

/**
 * 删除操作 OTP 闸门（v2.99.66，国密/等保合规）
 *
 * 合规依据：《数据安全法》第 27、29 条（重要数据全流程安全保护与风险监测）、
 *          等保2.0 三级「重要数据删除的二次鉴别」、GB/T 39786 基于密码技术的身份鉴别。
 *
 * 目标：任何人（含超级管理员、所有角色）删除业务数据或重要数据时，都必须
 *      通过腾讯身份验证器（TOTP）动态码二次认证，闸门不可被高权限绕过。
 *
 * 机制（与登录 / 导出 OTP 复用同一密钥，但使用独立的票据前缀与语义）：
 *   1. 前端发起删除（POST）→ 路由中间件拦截：
 *      - 未绑定 TOTP：返回 need_bind=true，前端引导绑定；
 *      - 已绑定但未带票：返回 need_delete_otp=true，前端弹动态码框；
 *   2. 前端调 verify(action, code)：校验动态码通过后签发一张**一次性、5 分钟、
 *      绑定用户 + 具体删除动作**的 ticket；
 *   3. 前端把 ticket 放回原请求（otp_ticket）重试，中间件 consume() 校验并立即销毁，
 *      通过后才放行到真正的删除控制器；
 *   4. 全程写敏感访问审计（purpose=delete），验证失败同样记 granted=0。
 *
 * 安全约定：
 *   - 动态码错误沿用账号级失败计数与 15 分钟锁定（与登录 TOTP 同口径）；
 *   - ticket 只存缓存不落库，过期 / 复用 / 跨用户 / 跨动作一律拒绝；
 *   - 本服务只做「是否允许执行删除」的二次鉴别，具体数据范围与业务校验仍由
 *     各控制器原有的 scope/can 权限与业务规则控制，本闸门不豁免任何权限。
 */
class DeleteOtpService
{
    /** ticket 有效期（秒） */
    public const TICKET_TTL = 300;

    /** 一次性删除凭证缓存前缀（与 export_otp_ticket_ 隔离，防止跨场景复用） */
    private const TICKET_PREFIX = 'delete_otp_ticket_';

    /** 动态码失败计数 / 锁定键（与 TotpService、ExportOtpService 共用同一账号级计数） */
    private const FAIL_PREFIX = 'totp_fail_uid_';
    private const LOCK_PREFIX = 'totp_lock_uid_';
    private const FAIL_MAX    = 10;
    private const FAIL_TTL    = 900;

    /**
     * 不叫 delete、但会永久丢弃重要数据的动作，一并纳入闸门。
     * 用完整路径显式列出，避免把 system/cache-clear 这类「清缓存」误伤。
     */
    private const EXTRA_ACTIONS = [
        'resource/remove-owner'  => '资源归属解除',
        'agent/key-revoke'       => '开放接口密钥吊销',
        'recycle-bin/clear'      => '回收站清空',
        'push-log/clear'         => '推送日志清空',
        'operation-log/clear'    => '操作日志清空',
        'login-log/clear'        => '登录日志清空',
    ];

    /** 删除动作所属模块的中文名（用于弹窗与审计，不含敏感数据） */
    private const MODULE_LABELS = [
        'company'          => '企业档案',
        'company-cert'     => '企业证照',
        'staff'            => '人员档案',
        'certificate'      => '证书台账',
        'secondment'       => '借调记录',
        'register-track'   => '报名跟踪',
        'crm'              => '客户',
        'push-channel'     => '推送渠道',
        'user'             => '用户账号',
        'role'             => '角色',
        'menu'             => '菜单',
        'msg-template'     => '消息模板',
        'fee'              => '费用记录',
        'wecom-contact'    => '企微通讯录',
        'dict-data'        => '字典数据',
        'dict-type'        => '字典类型',
        'contract'         => '合同',
        'custom-field'     => '自定义字段',
        'receiver'         => '收件人',
        'org-dept'         => '组织部门',
        'pay-account'      => '收款账户',
        'cert-task'        => '证照任务',
        'project'          => '项目',
        'policy-rule'      => '策略规则',
        'conflict'         => '冲突规则',
        'gov-progress'     => '政务进度',
        'credential-vault' => '账号密码库',
        'upgrade'          => '升级备份',
        'exam-cert'        => '考试证书',
        'exam-config'      => '考试配置',
        'exam-batch'       => '考试批次',
        'exam-signup'      => '考试报名',
        'edu-agency'       => '学历机构',
        'edu-signup'       => '学历报名',
        'agent'            => '开放接口',
    ];

    /** 删除动作后缀的中文描述（比统一叫「删除」更准确） */
    private const SUFFIX_LABELS = [
        'batch-delete'           => '批量删除',
        'delete-qualification'   => '资质删除',
        'rel-delete'             => '关联删除',
        'key-delete'             => '密钥删除',
        'follow-delete'          => '跟进记录删除',
        'material-delete'        => '材料删除',
        'attach-delete'          => '附件删除',
        'category-delete'        => '分类删除',
        'type-delete'            => '类型删除',
        'action-delete'          => '动作删除',
        'config-delete'          => '配置删除',
        'room-delete'            => '考场删除',
        'delete-backup'          => '备份删除',
        'exclusive-group-delete' => '互斥组删除',
    ];

    /** 组合后表述别扭的动作，直接给出完整中文标签（优先于模块+后缀拼接） */
    private const FULL_LABELS = [
        'upgrade/delete-backup' => '升级备份删除',
    ];

    /**
     * 归一化 pathinfo：与 AdminAuth 保持完全一致（去 api/、admin/、.html）。
     * 例：api/admin/staff/delete → staff/delete
     */
    public static function normalizePath(string $pathinfo): string
    {
        $path = strtolower(trim($pathinfo, '/'));
        $path = preg_replace('#^api/#', '', $path);
        $path = preg_replace('#^admin/#', '', $path);
        $path = preg_replace('#\.html$#', '', (string)$path);
        return trim((string)$path, '/');
    }

    /**
     * 是否为需要 OTP 的删除/破坏性动作。
     * 规则：末段包含 delete；或命中 EXTRA_ACTIONS 显式白名单。
     * 闸门自身的 verify 接口必须排除，否则永远无法完成验证。
     */
    public static function isProtectedAction(string $action): bool
    {
        $action = trim($action);
        if ($action === '' || strpos($action, '/') === false) {
            return false;
        }
        if (strpos($action, 'delete-otp/') === 0) {
            return false;
        }
        if (isset(self::EXTRA_ACTIONS[$action])) {
            return true;
        }
        $seg = substr($action, strrpos($action, '/') + 1);
        return strpos($seg, 'delete') !== false;
    }

    /** 校验并归一化删除动作；非法动作直接返回 400（防止伪造路径换票） */
    public static function normAction(string $action): string
    {
        $action = self::normalizePath($action);
        if (!self::isProtectedAction($action)) {
            throw new \think\exception\HttpResponseException(json(r_error('非法的删除操作', 400)));
        }
        return $action;
    }

    /** 删除动作的中文描述（弹窗 + 审计，不含任何业务数据） */
    public static function actionLabel(string $action): string
    {
        $action = trim($action);
        if (isset(self::FULL_LABELS[$action])) {
            return self::FULL_LABELS[$action];
        }
        if (isset(self::EXTRA_ACTIONS[$action])) {
            return self::EXTRA_ACTIONS[$action];
        }
        $pos  = strrpos($action, '/');
        $segs = $pos === false ? [$action] : [substr($action, 0, $pos), substr($action, $pos + 1)];
        $module = $segs[0];
        $suffix = $segs[1] ?? '';
        $moduleLabel = self::MODULE_LABELS[$module] ?? $module;
        $kind = self::SUFFIX_LABELS[$suffix] ?? '删除';
        return $moduleLabel . $kind;
    }

    /** 当前账号是否尚未绑定 TOTP（未绑定则不允许删除） */
    public static function needBind(int $userId): bool
    {
        $user = SysUser::find($userId);
        return !($user && TotpService::isBound($user));
    }

    /**
     * 校验动态码并签发一次性删除 ticket。
     *
     * @return array{ok:bool,msg:string,ticket:string,need_bind:bool}
     */
    public static function verify(int $userId, string $action, string $code): array
    {
        $action = self::normAction($action);
        $user = SysUser::find($userId);
        if (!$user || (int)$user->status !== 1) {
            return ['ok' => false, 'msg' => '账号不存在或已被禁用', 'ticket' => '', 'need_bind' => false];
        }
        if (!TotpService::isBound($user)) {
            self::audit($userId, $action, false, '未绑定动态验证码，拦截删除操作');
            return ['ok' => false, 'msg' => '账号尚未绑定动态验证码，请先绑定后再删除', 'ticket' => '', 'need_bind' => true];
        }

        if (Cache::get(self::LOCK_PREFIX . $userId)) {
            self::audit($userId, $action, false, '动态码失败次数过多，锁定中仍尝试删除');
            return ['ok' => false, 'msg' => '动态验证码错误次数过多，请 15 分钟后再试', 'ticket' => '', 'need_bind' => false];
        }

        $code = preg_replace('/\D/', '', (string)$code);
        if (strlen($code) !== TotpService::DIGITS) {
            return ['ok' => false, 'msg' => '请输入 6 位动态验证码', 'ticket' => '', 'need_bind' => false];
        }

        $secret = TotpService::decryptSecret((string)($user->totp_secret ?? ''));
        if ($secret === '') {
            // 密钥损坏：清空绑定，引导重新绑定，避免锁死
            $user->totp_secret = '';
            $user->totp_enabled = 0;
            $user->totp_bound_at = null;
            $user->save();
            self::audit($userId, $action, false, '动态验证码密钥失效，拦截删除操作');
            return ['ok' => false, 'msg' => '动态验证码密钥已失效，请重新绑定后再删除', 'ticket' => '', 'need_bind' => true];
        }

        if (!TotpService::verifyCode($secret, $code)) {
            $failKey = self::FAIL_PREFIX . $userId;
            $fails = (int)Cache::get($failKey) + 1;
            if ($fails >= self::FAIL_MAX) {
                Cache::set(self::LOCK_PREFIX . $userId, 1, self::FAIL_TTL);
                Cache::delete($failKey);
            } else {
                Cache::set($failKey, $fails, self::FAIL_TTL);
            }
            self::audit($userId, $action, false, "动态验证码错误（第 {$fails} 次）");
            return ['ok' => false, 'msg' => '动态验证码错误', 'ticket' => '', 'need_bind' => false];
        }

        // 通过：清失败计数，签发一次性 ticket（绑定用户 + 删除动作）
        Cache::delete(self::FAIL_PREFIX . $userId);
        $ticket = bin2hex(random_bytes(24));
        Cache::set(self::TICKET_PREFIX . $ticket, [
            'uid'    => $userId,
            'action' => $action,
            'issued' => time(),
        ], self::TICKET_TTL);

        self::audit($userId, $action, true, '删除二次验证通过，已签发一次性删除凭证');
        return ['ok' => true, 'msg' => '验证通过', 'ticket' => $ticket, 'need_bind' => false];
    }

    /**
     * 删除接口消费 ticket：校验归属 / 动作 / 有效期，通过后立即销毁（单次有效）。
     * 失败返回 false，调用方（中间件）应返回 428 要求重新验证。
     */
    public static function consume(int $userId, string $action, string $ticket): bool
    {
        $action = self::normAction($action);
        $ticket = trim($ticket);
        if ($ticket === '' || !preg_match('/^[a-f0-9]{32,128}$/', $ticket)) {
            self::audit($userId, $action, false, '删除凭证缺失或格式非法');
            return false;
        }
        $key = self::TICKET_PREFIX . $ticket;
        $payload = Cache::get($key);
        if (!is_array($payload)) {
            self::audit($userId, $action, false, '删除凭证不存在或已过期');
            return false;
        }
        // 一次性：无论后续校验是否通过，先销毁，防重放
        Cache::delete($key);

        if ((int)($payload['uid'] ?? 0) !== $userId || (string)($payload['action'] ?? '') !== $action) {
            self::audit($userId, $action, false, '删除凭证与账号/动作不匹配（疑似越权复用）');
            return false;
        }
        return true;
    }

    /** 记录一次删除闸门拒绝事件（供中间件在未绑定/缺票时调用） */
    public static function auditDenied(int $userId, string $action, string $remark): void
    {
        self::audit($userId, $action, false, $remark);
    }

    /**
     * 写敏感访问审计（复用 sensitive_access_log，purpose=delete）。
     * 审计失败不阻断主流程（SensitiveAccessService 内部已吞异常）。
     */
    private static function audit(int $userId, string $action, bool $granted, string $remark): void
    {
        try {
            SensitiveAccessService::recordDelete(
                $userId,
                $action,
                self::actionLabel($action),
                $granted,
                $remark
            );
        } catch (\Throwable $e) {
            // 审计不阻断删除鉴别
        }
    }
}
