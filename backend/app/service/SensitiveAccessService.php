<?php
namespace app\service;

use think\facade\Db;

/**
 * 敏感信息访问审计（v2.99.47，国密合规 P1）
 *
 * 合规依据：《个人信息保护法》第51条（采取加密、去标识化等安全技术措施并记录处理情况）、
 *          等保2.0 三级安全审计、GB/T 39786 密码应用要求。
 *
 * 定位：与 operation_log（操作行为审计）分离，专门记录「谁在什么时候查看/复制了
 *      谁的哪一条敏感明文（身份证/手机号/银行卡等）」，便于事后追溯与导出举证。
 *
 * 设计：
 *  - 表结构运行时自愈（ensure_table / ensure_column），不写升级 SQL，老库免手工改造；
 *  - 审计写入失败绝不阻断业务（try/catch 吞异常）；
 *  - 日志本身不落明文，只存字段名与对象标识，避免审计表成为新的泄露源。
 */
class SensitiveAccessService
{
    public const TABLE = 'sensitive_access_log';

    /** 允许审计的敏感字段白名单（防止前端传入任意字段名） */
    public const ALLOWED_FIELDS = [
        'id_card'    => '身份证号',
        'phone'      => '手机号',
        'email'      => '邮箱',
        'bank_no'    => '银行账号',
        'credit_code'=> '统一社会信用代码',
        'cert_no'    => '证书编号',
    ];

    /** 允许的访问用途（v2.99.49 新增 export；v2.99.66 新增 delete） */
    public const ALLOWED_PURPOSE = [
        'view'   => '查看明文',
        'copy'   => '复制明文',
        'edit'   => '编辑回填',
        'export' => '敏感导出',
        'delete' => '删除二次验证',
    ];

    /** 导出审计的字段标识（模块级操作，非单一字段；写入 field 列以便复用现有筛选） */
    public const EXPORT_FIELD = 'sensitive_export';

    /** 删除闸门审计的字段标识（v2.99.66） */
    public const DELETE_FIELD = 'delete_otp';

    /**
     * 表结构自愈（幂等）。
     */
    public static function ensureTable(): void
    {
        $ddl = "CREATE TABLE IF NOT EXISTS `" . self::TABLE . "` (
          `id` bigint unsigned NOT NULL AUTO_INCREMENT,
          `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '访问者 sys_user.id',
          `username` varchar(64) NOT NULL DEFAULT '' COMMENT '访问者姓名/账号快照',
          `role_code` varchar(64) NOT NULL DEFAULT '' COMMENT '访问者角色快照',
          `owner_type` varchar(32) NOT NULL DEFAULT '' COMMENT '对象类型：staff/exam_customer/edu_signup 等',
          `owner_id` int unsigned NOT NULL DEFAULT 0 COMMENT '对象主键',
          `target` varchar(120) NOT NULL DEFAULT '' COMMENT '对象可读标识（如人员姓名，不含敏感值）',
          `field` varchar(40) NOT NULL DEFAULT '' COMMENT '敏感字段名（英文键）',
          `field_label` varchar(40) NOT NULL DEFAULT '' COMMENT '敏感字段中文名',
          `purpose` varchar(16) NOT NULL DEFAULT 'view' COMMENT 'view查看 copy复制 edit编辑回填 export导出 delete删除二次验证',
          `granted` tinyint NOT NULL DEFAULT 1 COMMENT '1放行 0拒绝（无明文权限仍尝试访问）',
          `ip` varchar(45) NOT NULL DEFAULT '' COMMENT '来源 IP',
          `user_agent` varchar(255) NOT NULL DEFAULT '' COMMENT 'User-Agent',
          `device_name` varchar(100) NOT NULL DEFAULT '' COMMENT '设备名称',
          `create_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '访问时间',
          PRIMARY KEY (`id`),
          KEY `idx_user` (`user_id`),
          KEY `idx_owner` (`owner_type`,`owner_id`),
          KEY `idx_field` (`field`),
          KEY `idx_time` (`create_time`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='敏感信息明文访问审计'";
        ensure_table(self::TABLE, $ddl);
    }

    /**
     * 记录一次敏感明文访问。
     *
     * @param string $ownerType 对象类型（staff / exam_customer / edu_signup ...）
     * @param int    $ownerId   对象主键
     * @param string $field     敏感字段键（须在白名单内）
     * @param string $purpose   view / copy / edit
     * @param bool   $granted   本次是否实际放行返回了明文
     * @param array  $extra     可选：target(对象标识) / user(覆盖当前登录用户)
     */
    public static function record(
        string $ownerType,
        int $ownerId,
        string $field,
        string $purpose = 'view',
        bool $granted = true,
        array $extra = []
    ): void {
        try {
            self::ensureTable();
            $field = trim($field);
            if (!isset(self::ALLOWED_FIELDS[$field])) {
                return; // 非白名单字段不记，防止污染审计表
            }
            $req = app('request');
            $user = $extra['user'] ?? ($req->user ?? []);
            $ua   = (string)($extra['user_agent'] ?? $req->header('user-agent', ''));
            $row = [
                'user_id'     => (int)($user['id'] ?? 0),
                'username'    => mb_substr((string)($user['real_name'] ?? ($user['username'] ?? '')), 0, 64, 'UTF-8'),
                'role_code'   => mb_substr((string)($user['role_code'] ?? ''), 0, 64, 'UTF-8'),
                'owner_type'  => mb_substr($ownerType, 0, 32, 'UTF-8'),
                'owner_id'    => $ownerId,
                'target'      => mb_substr((string)($extra['target'] ?? ''), 0, 120, 'UTF-8'),
                'field'       => $field,
                'field_label' => self::ALLOWED_FIELDS[$field],
                'purpose'     => isset(self::ALLOWED_PURPOSE[$purpose]) ? $purpose : 'view',
                'granted'     => $granted ? 1 : 0,
                'ip'          => (string)($extra['ip'] ?? $req->ip()),
                'user_agent'  => mb_substr($ua, 0, 255, 'UTF-8'),
                'device_name' => mb_substr(DeviceService::deviceName($ua), 0, 100, 'UTF-8'),
            ];
            Db::name(self::TABLE)->insert($row);
        } catch (\Throwable $e) {
            // 审计失败不阻断业务
        }
    }

    /**
     * 记录一次敏感信息导出的二次鉴别（OTP 闸门）事件（v2.99.49）。
     *
     * 与 record() 的区别：导出是「模块级」动作而非对单条对象某字段的查看，
     * 故不复用 ALLOWED_FIELDS 单字段白名单，而是：
     *   - owner_type 记导出模块（staff / credential_vault / certificate）；
     *   - field 固定为 sensitive_export，field_label 为「敏感导出」；
     *   - purpose 固定为 export；target 记模块中文名；
     *   - granted 表示 OTP 闸门是否放行（0 = 未绑定/验证码错误/凭证失效等拦截）。
     * 不改表结构，与 P1 审计同表共存，老库靠 ensureTable() 自愈。
     *
     * @param int    $userId  执行导出的账号 sys_user.id
     * @param string $module  导出模块（ExportOtpService::MODULES 的键）
     * @param string $label   模块中文名（写入 target，不含任何敏感值）
     * @param bool   $granted 闸门是否放行
     * @param string $remark  结果说明（写入 target 附加，便于排查；勿放明文）
     */
    public static function recordExport(
        int $userId,
        string $module,
        string $label,
        bool $granted,
        string $remark = ''
    ): void {
        self::recordGateEvent($userId, $module, self::EXPORT_FIELD, '敏感导出', 'export', $label, $granted, $remark);
    }

    /**
     * 记录一次删除操作的二次鉴别（OTP 闸门）事件（v2.99.66）。
     *
     * 与导出口径一致：owner_type 记具体删除动作（如 staff/delete），
     * field 固定 delete_otp，purpose=delete；target 记动作中文名 + 结果说明，
     * granted=0 表示未绑定 / 动态码错误 / 凭证失效等拦截。
     *
     * @param int    $userId  执行删除的账号 sys_user.id
     * @param string $action  归一化后的删除动作（如 staff/delete）
     * @param string $label   动作中文名（写入 target，不含任何业务数据）
     * @param bool   $granted 闸门是否放行
     * @param string $remark  结果说明（写入 target 附加，便于排查；勿放明文）
     */
    public static function recordDelete(
        int $userId,
        string $action,
        string $label,
        bool $granted,
        string $remark = ''
    ): void {
        self::recordGateEvent($userId, $action, self::DELETE_FIELD, '删除二次验证', 'delete', $label, $granted, $remark);
    }

    /** 闸门事件的统一落库实现（导出 / 删除共用，避免两份插入逻辑漂移） */
    private static function recordGateEvent(
        int $userId,
        string $ownerType,
        string $field,
        string $fieldLabel,
        string $purpose,
        string $label,
        bool $granted,
        string $remark
    ): void {
        try {
            self::ensureTable();
            $ownerType = mb_substr(trim($ownerType), 0, 32, 'UTF-8');
            if ($ownerType === '') {
                return;
            }
            $req = app('request');
            // 按显式 userId 回查账号快照（sys_user 无 role_code 列，需关联 sys_role）
            $user = Db::name('sys_user')->alias('u')
                ->leftJoin('sys_role r', 'r.id = u.role_id')
                ->field('u.id,u.username,u.real_name,r.role_code')
                ->where('u.id', $userId)
                ->find();
            $ua   = (string)$req->header('user-agent', '');
            $target = $label !== '' ? $label : $ownerType;
            if ($remark !== '') {
                $target = mb_substr($target . '｜' . $remark, 0, 120, 'UTF-8');
            }
            $row = [
                'user_id'     => $userId,
                'username'    => mb_substr((string)($user['real_name'] ?? ($user['username'] ?? '')), 0, 64, 'UTF-8'),
                'role_code'   => mb_substr((string)($user['role_code'] ?? ''), 0, 64, 'UTF-8'),
                'owner_type'  => $ownerType,
                'owner_id'    => 0,
                'target'      => $target,
                'field'       => $field,
                'field_label' => $fieldLabel,
                'purpose'     => $purpose,
                'granted'     => $granted ? 1 : 0,
                'ip'          => (string)$req->ip(),
                'user_agent'  => mb_substr($ua, 0, 255, 'UTF-8'),
                'device_name' => mb_substr(DeviceService::deviceName($ua), 0, 100, 'UTF-8'),
            ];
            Db::name(self::TABLE)->insert($row);
        } catch (\Throwable $e) {
            // 审计失败不阻断业务
        }
    }

    /**
     * 审计日志分页查询（管理端）。
     *
     * @return array{list:array,total:int}
     */
    public static function paginate(array $opt = []): array
    {
        self::ensureTable();
        $page  = max(1, (int)($opt['page'] ?? 1));
        $limit = min(100, max(1, (int)($opt['limit'] ?? 15)));
        $q = Db::name(self::TABLE);

        if (!empty($opt['user_id'])) {
            $q->where('user_id', (int)$opt['user_id']);
        }
        if (!empty($opt['owner_type'])) {
            $q->where('owner_type', trim((string)$opt['owner_type']));
        }
        if (!empty($opt['field'])) {
            $q->where('field', trim((string)$opt['field']));
        }
        if (!empty($opt['purpose'])) {
            $q->where('purpose', trim((string)$opt['purpose']));
        }
        if (isset($opt['granted']) && $opt['granted'] !== '' && $opt['granted'] !== null) {
            $q->where('granted', (int)$opt['granted']);
        }
        if (!empty($opt['keyword'])) {
            $kw = '%' . str_replace(['%', '_'], ['\%', '\_'], trim((string)$opt['keyword'])) . '%';
            $q->where(function ($w) use ($kw) {
                // 注意：whereOr 必须显式传 'like'，单参数两参形式会被解析成 = 导致查不到
                $w->whereLike('username', $kw)->whereOr('target', 'like', $kw);
            });
        }
        if (!empty($opt['start'])) {
            $q->where('create_time', '>=', trim((string)$opt['start']));
        }
        if (!empty($opt['end'])) {
            $q->where('create_time', '<=', trim((string)$opt['end']));
        }

        $total = (clone $q)->count();
        $list = $q->order('id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();
        return ['list' => $list, 'total' => $total];
    }
}
