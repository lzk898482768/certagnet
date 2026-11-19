<?php
namespace app\service;

use think\facade\Db;

/**
 * CRM 能力（v2.54）
 *
 *  - 创建人 / 负责人字段自愈：client_company 补 create_by、owner_user_id；
 *    certificate 补 owner_user_id（存量按 create_by 回填一次）。
 *  - 负责人移交：cert_owner_transfer 记录原负责人→新负责人→操作人→备注→时间。
 *  - 跟进台账：cert_follow_record 记录对客户企业 / 证照的跟进（方式、内容、下次跟进时间）。
 *
 * 表结构均走「首次使用自愈」模式（老库无需手工 SQL），与 RecycleBinService 一致。
 */
class CrmService
{
    public const MODULES = [
        'company'     => '客户企业',
        'certificate' => '证照台账',
        'staff'       => '人员档案',
    ];

    public const FOLLOW_TYPES = ['电话', '微信', '上门拜访', '邮件', '短信', '会议', '其他'];

    private static bool $schemaChecked = false;

    public static function ensureSchema(): void
    {
        if (self::$schemaChecked) {
            return;
        }
        // 1) 客户企业：创建人 + 负责人
        self::ensureColumn('client_company', 'create_by', "ALTER TABLE `client_company` ADD COLUMN `create_by` int unsigned NOT NULL DEFAULT 0 COMMENT '创建人系统账号ID（v2.54 CRM）' AFTER `id`");
        self::ensureColumn('client_company', 'owner_user_id', "ALTER TABLE `client_company` ADD COLUMN `owner_user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '负责人系统账号ID，0=未分配（v2.54 CRM）' AFTER `create_by`");
        // 2) 证照：负责人（create_by 已存在）
        self::ensureColumn('certificate', 'owner_user_id', "ALTER TABLE `certificate` ADD COLUMN `owner_user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '负责人系统账号ID，0=未分配（v2.54 CRM）' AFTER `create_by`");
        // v2.97：证书到期日允许为空，NULL 表示长期有效（与 company_qualification 口径一致）
        self::ensureColumnNullable('certificate', 'expire_date', "ALTER TABLE `certificate` MODIFY COLUMN `expire_date` date DEFAULT NULL COMMENT '证书到期日期（NULL=长期有效）'");
        // 3) 人员档案：负责人（证照与人员绑定，人员同样纳入 CRM 负责人体系）
        self::ensureColumn('staff', 'owner_user_id', "ALTER TABLE `staff` ADD COLUMN `owner_user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '负责人系统账号ID，0=未分配（v2.54 CRM）' AFTER `create_by`");
        // 4) 存量回填：负责人 = 创建人（仅当 owner 全为 0 且不在公海时执行，幂等）
        //    v2.99.37：owner=0 且 is_pool=0 的历史数据按创建人补齐；
        //    显式公海（is_pool=1）以及无创建人的数据保持"无所有人"，不再强塞管理员。
        try {
            $n1 = (int)Db::name('client_company')->where('owner_user_id', 0)->where('create_by', '>', 0)->count();
            if ($n1 > 0) {
                Db::execute('UPDATE `client_company` SET `owner_user_id` = `create_by` WHERE `owner_user_id` = 0 AND `create_by` > 0 AND `is_pool` = 0');
            }
            $n2 = (int)Db::name('certificate')->where('owner_user_id', 0)->where('create_by', '>', 0)->count();
            if ($n2 > 0) {
                Db::execute('UPDATE `certificate` SET `owner_user_id` = `create_by` WHERE `owner_user_id` = 0 AND `create_by` > 0 AND `is_pool` = 0');
            }
            $n3 = (int)Db::name('staff')->where('owner_user_id', 0)->where('create_by', '>', 0)->count();
            if ($n3 > 0) {
                Db::execute('UPDATE `staff` SET `owner_user_id` = `create_by` WHERE `owner_user_id` = 0 AND `create_by` > 0 AND `is_pool` = 0');
            }
        } catch (\Throwable $e) {
            // 列尚未加上时忽略，下一轮自愈
        }
        // 5) 移交历史表
        Db::execute("CREATE TABLE IF NOT EXISTS `cert_owner_transfer` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `module` varchar(20) NOT NULL COMMENT 'company|certificate|staff',
            `record_id` int unsigned NOT NULL DEFAULT 0 COMMENT '业务主表ID',
            `title` varchar(255) NOT NULL DEFAULT '' COMMENT '主体标题（企业名/证书编号/人员姓名）',
            `from_user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '原负责人',
            `to_user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '新负责人',
            `operator_id` int unsigned NOT NULL DEFAULT 0 COMMENT '操作人',
            `remark` varchar(500) NOT NULL DEFAULT '' COMMENT '移交备注',
            `create_time` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_module_record` (`module`, `record_id`),
            KEY `idx_to_user` (`to_user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='负责人移交历史（v2.54 CRM）'");
        // 6) 跟进记录表
        Db::execute("CREATE TABLE IF NOT EXISTS `cert_follow_record` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `module` varchar(20) NOT NULL COMMENT 'company|certificate|staff',
            `record_id` int unsigned NOT NULL DEFAULT 0 COMMENT '业务主表ID',
            `follow_type` varchar(32) NOT NULL DEFAULT '' COMMENT '跟进方式：电话/微信/上门拜访/邮件/短信/会议/其他',
            `content` text NULL COMMENT '跟进内容',
            `next_follow_date` date DEFAULT NULL COMMENT '下次跟进日期',
            `create_by` int unsigned NOT NULL DEFAULT 0 COMMENT '跟进人',
            `create_time` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_module_record` (`module`, `record_id`),
            KEY `idx_next_date` (`next_follow_date`),
            KEY `idx_create_by` (`create_by`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='跟进台账（v2.54 CRM）'");
        // 7) v2.99.1：客户企业统一社会信用代码唯一约束自愈
        self::ensureCreditCodeUnique();
        self::$schemaChecked = true;
    }

    /**
     * v2.99.1（BUG-04）：统一社会信用代码唯一约束自愈。
     *
     * 采用「生成列 + 唯一索引」方案：
     *   credit_code_uniq = NULLIF(credit_code, '')  STORED
     *   UNIQUE KEY uk_credit_code (credit_code_uniq)
     * 这样空字符串会被 NULLIF 变 NULL，多个 NULL 允许共存（大量未填信用代码的
     * 企业不受影响），非空值则强制唯一。MySQL 5.7.6+ 支持 STORED 生成列与唯一索引。
     *
     * 加索引前先清理历史重复的非空信用代码：保留 id 最小的一条，其余置空，
     * 避免迁移因脏数据失败；清理动作幂等，重复执行无副作用。
     */
    public static function ensureCreditCodeUnique(): void
    {
        try {
            // 已有该生成列则视为已迁移
            $col = Db::query("SHOW COLUMNS FROM `client_company` LIKE 'credit_code_uniq'");
            if (!$col) {
                // 1) 清理非空重复：保留 id 最小的一条，其余置 ''
                Db::execute(
                    "UPDATE `client_company` c
                       JOIN (
                         SELECT `credit_code`, MIN(`id`) AS keep_id
                           FROM `client_company`
                          WHERE `credit_code` <> ''
                       GROUP BY `credit_code`
                         HAVING COUNT(*) > 1
                       ) d ON c.`credit_code` = d.`credit_code` AND c.`id` <> d.keep_id
                        SET c.`credit_code` = ''"
                );
                // 2) 加生成列 + 唯一索引
                Db::execute(
                    "ALTER TABLE `client_company`
                       ADD COLUMN `credit_code_uniq` varchar(64)
                         GENERATED ALWAYS AS (NULLIF(`credit_code`, '')) STORED
                         COMMENT '统一社会信用代码唯一键（空值转 NULL）',
                       ADD UNIQUE KEY `uk_credit_code` (`credit_code_uniq`)"
                );
            }
        } catch (\Throwable $e) {
            // 老库异常不阻断主流程（应用层已做查重兜底）
        }
    }

    private static function ensureColumn(string $table, string $column, string $ddl): void
    {
        try {
            $fields = Db::query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
            if (!$fields) {
                Db::execute($ddl);
            }
        } catch (\Throwable $e) {
            // 表缺失等情况忽略
        }
    }

    /** 列存在且 NOT NULL 时执行 DDL（用于幂等放宽约束） */
    private static function ensureColumnNullable(string $table, string $column, string $ddl): void
    {
        try {
            $fields = Db::query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
            if ($fields && strtoupper((string)($fields[0]['Null'] ?? '')) === 'NO') {
                Db::execute($ddl);
            }
        } catch (\Throwable $e) {
            // 表缺失等情况忽略，下一轮自愈
        }
    }

    /** 可选负责人下拉（启用中的账号） */
    public static function users(): array
    {
        return Db::name('sys_user')
            ->where('status', 1)
            ->field('id,username,real_name,role_id')
            ->order('id', 'asc')
            ->select()->toArray();
    }

    /** id => 展示名（姓名优先，其次登录名） */
    public static function userMap(): array
    {
        $map = [];
        foreach (self::users() as $u) {
            $map[(int)$u['id']] = ($u['real_name'] !== '' ? $u['real_name'] : $u['username']);
        }
        return $map;
    }

    /** 给行附加 owner_name / create_name（列表或单行均可，自动识别） */
    public static function attachOwner(array &$list, string $module): void
    {
        if (!$list) {
            return;
        }
        // 兼容单条记录（详情/编辑：关联数组）与记录列表（index：二维数组）
        $single = isset($list['id']) || isset($list['owner_user_id']) || isset($list['create_by']);
        if ($single) {
            $list = [$list];
        }
        $map = self::userMap();
        foreach ($list as &$row) {
            $oid = (int)($row['owner_user_id'] ?? 0);
            $cid = (int)($row['create_by'] ?? 0);
            $row['owner_name']   = $oid ? ($map[$oid] ?? '') : '';
            $row['create_name']   = $cid ? ($map[$cid] ?? '') : '';
            $row['create_by_id'] = $cid;
        }
        unset($row);
        if ($single) {
            $list = $list[0];
        }
    }

    /** 主体标题 */
    public static function titleOf(string $module, int $recordId): string
    {
        if ($module === 'company') {
            return (string)(Db::name('client_company')->where('id', $recordId)->value('company_name') ?: '');
        }
        if ($module === 'staff') {
            return (string)(Db::name('staff')->where('id', $recordId)->value('staff_name') ?: '');
        }
        if ($module === 'certificate') {
            $no = (string)(Db::name('certificate')->where('id', $recordId)->value('cert_no') ?: '');
            $name = (string)(Db::name('certificate')->where('id', $recordId)->value('cert_name') ?: '');
            return $no !== '' ? $no : ($name ?: ('#' . $recordId));
        }
        return '';
    }

    /**
     * 负责人移交：批量更新 owner_user_id + 写历史。
     *
     * @param array $allowedIds 已通过数据权限校验的记录 id 集合
     * @param int   $operatorId 当前操作人
     * @return array ['ok'=>int, 'fail'=>string[]]
     */
    public static function transfer(string $module, array $ids, int $toUserId, string $remark, array $allowedIds, int $operatorId): array
    {
        if (!isset(self::MODULES[$module])) {
            return ['ok' => 0, 'fail' => ['未知模块']];
        }
        // v2.99.37：归属变更统一走 ResourceOwnerService（支持多人共有 / 公海）
        $operator = Db::name('sys_user')->alias('u')
            ->join('sys_role r', 'r.id = u.role_id')
            ->where('u.id', $operatorId)
            ->field('r.role_code')
            ->find();
        $isAdmin = ($operator['role_code'] ?? '') === 'admin';
        return ResourceOwnerService::transfer(
            $module,
            $ids,
            $toUserId,
            $operatorId,
            $isAdmin,
            $remark,
            $allowedIds
        );
    }

    /**
     * 证书展示标签（v2.57）
     * 「证书类型 + 专业」组合，如「二级建造师 建筑工程」；两者相同时自动去重。
     * 兼容只有 category（旧版类别）的历史数据。
     */
    public static function certLabel(array $cert): string
    {
        $type = trim((string)($cert['cert_type'] ?? ''));
        $name = trim((string)($cert['cert_name'] ?? ''));
        if ($type === '') {
            $type = trim((string)($cert['category'] ?? ''));
        }
        if ($name === '' || $name === $type) {
            $label = $name !== '' ? $name : $type;
        } else {
            $label = $type !== '' ? ($type . ' ' . $name) : $name;
        }
        if ($label === '') {
            $label = '证书#' . (int)($cert['id'] ?? 0);
        }
        return $label;
    }

    /**
     * 跟进记录列表（v2.57 以「人」为锚点）
     *
     * - staff：人员本人的跟进 + 名下所有证书的跟进；
     * - certificate：自动归属到持证人，返回该人员全部跟进（当前证书的跟进 is_focus=1），
     *   这样打开「市政公用工程」证书详情时，也能看到同一人员「二级建造师 建筑工程」下的跟进，
     *   并用 source_label 左标签标明来源证书；
     * - company：仅本企业的跟进（保持原状）。
     *
     * 每行统一附加：
     *   source_type=staff|certificate、source_label（人员档案/证书标签）、
     *   cert_id、cert_name、cert_no、cert_label、is_focus、staff_id、staff_name
     */
    public static function followList(string $module, int $recordId, ?callable $certFilter = null): array
    {
        self::ensureSchema();
        $rows = [];
        $staffId = 0;
        $focusCertId = 0;

        if ($module === 'staff') {
            $staffId = $recordId;
        } elseif ($module === 'certificate') {
            $focusCertId = $recordId;
            $staffId = (int)(Db::name('certificate')->where('id', $recordId)->value('staff_id') ?: 0);
        }

        if ($staffId > 0) {
            // 以人为锚点：人员档案跟进 + 名下全部证书跟进
            $certIds = array_map('intval', Db::name('certificate')->where('staff_id', $staffId)->column('id') ?: []);
            // 数据权限：非管理员仅聚合其可访问证书的跟进，避免跨企业泄露
            if ($certFilter !== null) {
                $certIds = array_values(array_filter($certIds, $certFilter));
            }
            $rows = Db::name('cert_follow_record')
                ->where(function ($w) use ($staffId, $certIds) {
                    $w->where(function ($x) use ($staffId) {
                        $x->where('module', 'staff')->where('record_id', $staffId);
                    })->whereOr(function ($x) use ($certIds) {
                        $x->where('module', 'certificate')->whereIn('record_id', $certIds ?: [0]);
                    });
                })
                ->order('id', 'desc')->select()->toArray();
        } else {
            // 企业 / 无持证人的证书：仅本记录
            $rows = Db::name('cert_follow_record')
                ->where('module', $module)->where('record_id', $recordId)
                ->order('id', 'desc')
                ->select()->toArray();
        }

        $map = self::userMap();
        $staffName = $staffId > 0
            ? (string)(Db::name('staff')->where('id', $staffId)->value('staff_name') ?: '')
            : '';
        $certCache = [];
        foreach ($rows as &$r) {
            $r['create_name'] = $map[(int)$r['create_by']] ?? '';
            $isCert = (string)($r['module'] ?? '') === 'certificate';
            $isCompany = (string)($r['module'] ?? '') === 'company';
            $r['source_type']   = $isCert ? 'certificate' : ($isCompany ? 'company' : 'staff');
            $r['source_label']  = $isCompany ? '企业客户' : '人员档案';
            $r['cert_id']       = 0;
            $r['cert_name']     = '';
            $r['cert_no']       = '';
            $r['cert_label']    = '';
            $r['is_focus']      = 0;
            $r['staff_id']      = $staffId;
            $r['staff_name']    = $staffName;
            if ($isCert) {
                $cid = (int)$r['record_id'];
                if (!array_key_exists($cid, $certCache)) {
                    $certCache[$cid] = Db::name('certificate')->where('id', $cid)
                        ->field('id,cert_type,category,cert_name,cert_no')->find() ?: null;
                }
                $ci = $certCache[$cid];
                if ($ci) {
                    $r['cert_id']    = $cid;
                    $r['cert_name']  = (string)($ci['cert_name'] ?? '');
                    $r['cert_no']    = (string)($ci['cert_no'] ?? '');
                    $r['cert_label'] = self::certLabel($ci);
                    $r['source_label'] = $r['cert_label'];
                }
                $r['is_focus'] = ($focusCertId > 0 && $cid === $focusCertId) ? 1 : 0;
            }
        }
        unset($r);
        return $rows;
    }

    public static function addFollow(int $userId, string $module, int $recordId, string $followType, string $content, string $nextFollowDate): int
    {
        self::ensureSchema();
        return (int)Db::name('cert_follow_record')->insertGetId([
            'module'            => $module,
            'record_id'         => $recordId,
            'follow_type'       => mb_substr(trim($followType), 0, 32),
            'content'           => trim($content),
            'next_follow_date'  => $nextFollowDate !== '' ? $nextFollowDate : null,
            'create_by'         => $userId,
            'create_time'       => date('Y-m-d H:i:s'),
        ]);
    }

    /** 删除跟进记录：仅创建人或管理员 */
    public static function deleteFollow(int $userId, bool $isAdmin, int $id): bool
    {
        $row = Db::name('cert_follow_record')->where('id', $id)->find();
        if (!$row) {
            return false;
        }
        if (!$isAdmin && (int)$row['create_by'] !== $userId) {
            return false;
        }
        Db::name('cert_follow_record')->where('id', $id)->delete();
        return true;
    }

    /**
     * 跟进台账分页（跨公司/人员/证书）。
     *
     * v2.57 完善：
     *  - 主体以「个人/企业」呈现，证书跟进主体 = 持证人，并左标签标注来源证书；
     *  - 支持 subject_type（person/company）、staff_id（按人聚合其全部证书跟进）、cert_id 过滤；
     *  - 返回 stats（今日待跟进 / 超期未跟进 / 待跟进总数），供台账顶部指标卡使用。
     *
     * @param callable $scope fn(string $module): int[] 返回当前用户可访问的 record_id 集合
     */
    public static function ledger(array $params, callable $scope): array
    {
        $page  = max(1, (int)($params['page'] ?? 1));
        $limit = min(100, max(1, (int)($params['limit'] ?? 15)));

        // 数据权限范围只计算一次，分页查询与统计复用
        $scopeIds = [
            'company'     => array_map('intval', $scope('company')),
            'certificate' => array_map('intval', $scope('certificate')),
            'staff'       => array_map('intval', $scope('staff')),
        ];

        $q = self::ledgerQuery($params, $scopeIds, true);
        $total = (int)$q->count();
        $list  = $q->order('f.id', 'desc')->page($page, $limit)->select()->toArray();

        $map = self::userMap();
        $titleCache = [];
        $certInfoCache = [];
        foreach ($list as &$r) {
            $r['create_name'] = $map[(int)$r['create_by']] ?? '';
            $r['module_text'] = self::MODULES[$r['module']] ?? $r['module'];
            $r['cert_label']  = '';
            $r['cert_no']     = '';
            $r['cert_id']     = 0;
            $r['staff_id']    = 0;
            // v2.56/v2.57：跟进主体应为「个人或企业」
            //  - company/staff：主体即企业名/人员姓名；
            //  - certificate：主体为该证书的持证人（个人），并附 cert_label 标注来源证书。
            if ($r['module'] === 'certificate') {
                $cid = (int)$r['record_id'];
                if (!array_key_exists($cid, $certInfoCache)) {
                    $certInfoCache[$cid] = Db::name('certificate')->alias('c')
                        ->leftJoin('staff s', 's.id = c.staff_id')
                        ->where('c.id', $cid)
                        ->field('c.id,c.staff_id,c.cert_type,c.category,c.cert_name,c.cert_no,s.staff_name')
                        ->find() ?: null;
                }
                $info = $certInfoCache[$cid];
                $r['cert_id']      = $cid;
                $r['title']        = (string)($info['staff_name'] ?? '');
                $r['staff_id']     = (int)($info['staff_id'] ?? 0);
                $r['subject_type'] = '个人';
                if ($info) {
                    $r['cert_label'] = self::certLabel($info);
                    $r['cert_no']    = (string)($info['cert_no'] ?? '');
                }
                if ($r['title'] === '') {
                    $r['title'] = '人员#' . $cid;
                }
            } else {
                $key = $r['module'] . ':' . $r['record_id'];
                if (!array_key_exists($key, $titleCache)) {
                    $titleCache[$key] = self::titleOf($r['module'], (int)$r['record_id']);
                }
                $r['title']        = $titleCache[$key];
                $r['subject_type'] = $r['module'] === 'company' ? '企业' : '个人';
                if ($r['module'] === 'staff') {
                    $r['staff_id'] = (int)$r['record_id'];
                }
            }
        }
        unset($r);

        // 统计（忽略日期筛选，保留模块/人员/企业等主体筛选）
        $baseParams = array_merge($params, ['follow_date' => '', 'start' => '', 'end' => '']);
        $today = date('Y-m-d');
        $stats = [
            'today'   => (int)self::ledgerQuery($baseParams, $scopeIds, false)
                ->where('f.next_follow_date', $today)->count(),
            'overdue' => (int)self::ledgerQuery($baseParams, $scopeIds, false)
                ->whereNotNull('f.next_follow_date')->where('f.next_follow_date', '<', $today)->count(),
            'pending' => (int)self::ledgerQuery($baseParams, $scopeIds, false)
                ->whereNotNull('f.next_follow_date')->count(),
        ];
        return ['list' => $list, 'total' => $total, 'stats' => $stats];
    }

    /**
     * 构造跟进台账查询（v2.57）
     * @param array $scopeIds ['company'=>[], 'certificate'=>[], 'staff'=>[]]
     */
    private static function ledgerQuery(array $params, array $scopeIds, bool $withDate): \think\db\Query
    {
        $module  = trim((string)($params['module'] ?? ''));
        $ownerId = (int)($params['owner_user_id'] ?? 0);
        $keyword = trim((string)($params['keyword'] ?? ''));
        $staffId = (int)($params['staff_id'] ?? 0);
        $certId  = (int)($params['cert_id'] ?? 0);
        $subject = trim((string)($params['subject_type'] ?? ''));
        $start   = trim((string)($params['start'] ?? ''));
        $end     = trim((string)($params['end'] ?? ''));
        $date    = trim((string)($params['follow_date'] ?? ''));

        $q = Db::name('cert_follow_record')->alias('f');
        if ($module !== '' && isset(self::MODULES[$module])) {
            $q->where('f.module', $module);
        }
        if ($ownerId > 0) {
            $q->where('f.create_by', $ownerId);
        }
        if ($withDate) {
            if ($date !== '') {
                $q->where('f.next_follow_date', $date);
            } else {
                if ($start !== '') {
                    $q->where('f.create_time', '>=', $start . ' 00:00:00');
                }
                if ($end !== '') {
                    $q->where('f.create_time', '<=', $end . ' 23:59:59');
                }
            }
        }
        // v2.57：超期未跟进快捷筛选（下次跟进日期早于今天）
        if (!empty($params['overdue'])) {
            $q->whereNotNull('f.next_follow_date')->where('f.next_follow_date', '<', date('Y-m-d'));
        }
        // v2.57：按人员过滤 —— 人员档案跟进 + 该人员名下全部证书跟进
        if ($staffId > 0) {
            $cids = array_map('intval', Db::name('certificate')->where('staff_id', $staffId)->column('id') ?: []);
            $q->where(function ($w) use ($staffId, $cids) {
                $w->where(function ($x) use ($staffId) {
                    $x->where('f.module', 'staff')->where('f.record_id', $staffId);
                })->whereOr(function ($x) use ($cids) {
                    $x->where('f.module', 'certificate')->whereIn('f.record_id', $cids ?: [0]);
                });
            });
        }
        if ($certId > 0) {
            $q->where('f.module', 'certificate')->where('f.record_id', $certId);
        }
        if ($subject === 'company') {
            $q->where('f.module', 'company');
        } elseif ($subject === 'person') {
            $q->whereIn('f.module', ['staff', 'certificate']);
        }

        $companyIds = $scopeIds['company'] ?? [];
        $certIds    = $scopeIds['certificate'] ?? [];
        $staffIds   = $scopeIds['staff'] ?? [];
        $q->where(function ($w) use ($companyIds, $certIds, $staffIds) {
            $w->where(function ($x) use ($companyIds) {
                $x->where('f.module', 'company')->whereIn('f.record_id', $companyIds ?: [0]);
            })->whereOr(function ($x) use ($certIds) {
                $x->where('f.module', 'certificate')->whereIn('f.record_id', $certIds ?: [0]);
            })->whereOr(function ($x) use ($staffIds) {
                $x->where('f.module', 'staff')->whereIn('f.record_id', $staffIds ?: [0]);
            });
        });
        if ($keyword !== '') {
            $q->where('f.content', 'like', "%{$keyword}%");
        }
        return $q;
    }

    /** 最近跟进时间映射：record_id => 最近跟进时间 */
    public static function lastFollowMap(string $module, array $recordIds): array
    {
        if (!$recordIds) {
            return [];
        }
        $rows = Db::name('cert_follow_record')
            ->field('record_id, MAX(create_time) as last_time')
            ->where('module', $module)->whereIn('record_id', $recordIds)
            ->group('record_id')->select()->toArray();
        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r['record_id']] = $r['last_time'];
        }
        return $map;
    }

    /** 删除主记录时级联清理跟进与移交历史 */
    public static function deleteByRecord(string $module, int $recordId): void
    {
        try {
            Db::name('cert_follow_record')->where('module', $module)->where('record_id', $recordId)->delete();
            Db::name('cert_owner_transfer')->where('module', $module)->where('record_id', $recordId)->delete();
        } catch (\Throwable $e) {
            // 表尚未创建时忽略
        }
    }
}