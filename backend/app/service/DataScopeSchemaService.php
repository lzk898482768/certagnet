<?php
namespace app\service;

use think\facade\Db;

/**
 * 数据权限基座表结构自愈（v2.99.36 P2）
 *
 * 运行时幂等建表/加列，不写升级 SQL；同步维护 backend/database/init.sql。
 *  - sys_role 加 data_policy（JSON，角色级数据策略，NULL=内置默认）
 *  - 新建 sys_user_data_scope（用户级数据范围覆盖）
 *  - 给缺创建人列的业务表补 create_by：contract / fee / cert_secondment
 *  - 历史 create_by=0 数据回填，避免切到「创建人」范围后旧数据全员不可见
 */
class DataScopeSchemaService
{
    private static bool $checked = false;

    public static function ensure(): void
    {
        if (self::$checked) {
            return;
        }
        self::$checked = true;

        try {
            // 1) 角色数据策略列
            self::ensureColumn('sys_role', 'data_policy',
                "ALTER TABLE `sys_role` ADD COLUMN `data_policy` text NULL COMMENT '数据权限策略JSON（v2.99.36）：core/other 两组，NULL=内置默认' AFTER `perms`");

            // 2) 用户级数据范围覆盖表
            Db::execute("CREATE TABLE IF NOT EXISTS `sys_user_data_scope` (
                `id`            int unsigned NOT NULL AUTO_INCREMENT,
                `user_id`       int unsigned NOT NULL COMMENT '系统账号ID',
                `scope_group`   varchar(20)  NOT NULL COMMENT 'core 核心资源 | other 其他模块',
                `scope`         varchar(20)  NOT NULL COMMENT 'all | pool | self（core）；all | self（other）',
                `include_pool`  tinyint      NOT NULL DEFAULT 1 COMMENT 'core 组是否含公海',
                `update_by`     int unsigned NOT NULL DEFAULT 0,
                `update_time`   datetime     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_user_group` (`user_id`,`scope_group`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户级数据范围覆盖（缺省回退角色策略，v2.99.36）'");

            // 3) 业务表补 create_by
            self::ensureColumn('contract', 'create_by',
                "ALTER TABLE `contract` ADD COLUMN `create_by` int unsigned NOT NULL DEFAULT 0 COMMENT '创建人账号ID（v2.99.36 数据权限）' AFTER `id`");
            self::ensureColumn('fee', 'create_by',
                "ALTER TABLE `fee` ADD COLUMN `create_by` int unsigned NOT NULL DEFAULT 0 COMMENT '创建人账号ID（v2.99.36 数据权限）' AFTER `id`");
            self::ensureColumn('cert_secondment', 'create_by',
                "ALTER TABLE `cert_secondment` ADD COLUMN `create_by` int unsigned NOT NULL DEFAULT 0 COMMENT '创建人账号ID（v2.99.36 数据权限）' AFTER `id`");
            self::ensureColumn('gov_progress', 'create_by',
                "ALTER TABLE `gov_progress` ADD COLUMN `create_by` int unsigned NOT NULL DEFAULT 0 COMMENT '创建人账号ID（v2.99.36 数据权限）' AFTER `owner_id`");

            // 4) 历史数据回填（幂等：只处理 create_by=0）
            self::backfill();

            // 5) P3 资源归属（多所有人）与公海
            self::ensureResourceOwner();
        } catch (\Throwable $e) {
            // 自愈失败不阻断主流程，下一轮重试
            self::$checked = false;
        }
    }

    /**
     * P3：资源归属表、三主表公海标记、归属变更审计扩展。
     * 全部幂等，老库首次调用自动补齐，无需手工执行升级 SQL。
     */
    private static function ensureResourceOwner(): void
    {
        Db::execute("CREATE TABLE IF NOT EXISTS `resource_owner` (
            `id`          int unsigned NOT NULL AUTO_INCREMENT,
            `res_type`    varchar(20)  NOT NULL COMMENT 'staff | company | certificate',
            `res_id`      int unsigned NOT NULL,
            `user_id`     int unsigned NOT NULL COMMENT '所有人账号ID',
            `is_primary`  tinyint      NOT NULL DEFAULT 0 COMMENT '1=主所有人（兼容 owner_user_id 展示）',
            `source`      varchar(20)  NOT NULL DEFAULT 'assign' COMMENT 'assign指派/transfer移交/duplicate查重共有/claim公海认领',
            `created_by`  int unsigned NOT NULL DEFAULT 0,
            `create_time` datetime     DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_res_user` (`res_type`,`res_id`,`user_id`),
            KEY `idx_user` (`user_id`),
            KEY `idx_res` (`res_type`,`res_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='核心资源共同所有人（多对多，v2.99.37）'");

        $tables = ['staff', 'client_company', 'certificate'];
        foreach ($tables as $table) {
            self::ensureColumn($table, 'is_pool',
                "ALTER TABLE `{$table}` ADD COLUMN `is_pool` tinyint NOT NULL DEFAULT 0 COMMENT '1=公海（无所有人，可认领）' AFTER `owner_user_id`");
            self::ensureIndex($table, 'idx_pool', "ALTER TABLE `{$table}` ADD KEY `idx_pool` (`is_pool`)");
        }

        self::ensureColumn('cert_owner_transfer', 'action',
            "ALTER TABLE `cert_owner_transfer` ADD COLUMN `action` varchar(20) NOT NULL DEFAULT 'transfer' COMMENT 'transfer移交/to_pool移入公海/claim认领/assign指派/duplicate查重共有' AFTER `module`");
        self::ensureColumn('cert_owner_transfer', 'to_pool',
            "ALTER TABLE `cert_owner_transfer` ADD COLUMN `to_pool` tinyint NOT NULL DEFAULT 0 COMMENT '1=目标为公海' AFTER `action`");

        self::backfillResourceOwners();
    }

    /** 幂等建索引（SHOW INDEX 已存在则跳过） */
    private static function ensureIndex(string $table, string $index, string $ddl): void
    {
        try {
            $exists = Db::query("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$index}'");
            if (!$exists) {
                Db::execute($ddl);
            }
        } catch (\Throwable $e) {
            // 索引异常忽略，下一轮自愈
        }
    }

    /**
     * 归属回填：owner_user_id>0 生成主所有人行；无归属行的资源置公海。
     * 全程幂等，重复执行不会产生重复归属。
     */
    private static function backfillResourceOwners(): void
    {
        $map = [
            'staff'       => 'staff',
            'company'     => 'client_company',
            'certificate' => 'certificate',
        ];
        foreach ($map as $type => $table) {
            try {
                if (Db::name($table)->where('owner_user_id', '>', 0)->count() > 0) {
                    // INSERT IGNORE + uk_res_user 天然幂等，避免重复归属
                    Db::execute(
                        "INSERT IGNORE INTO `resource_owner`
                            (`res_type`,`res_id`,`user_id`,`is_primary`,`source`,`created_by`)
                         SELECT ?, t.id, t.owner_user_id, 1, 'assign', 0
                         FROM `{$table}` t WHERE t.owner_user_id > 0",
                        [$type]
                    );
                }
                // 无任何归属 → 公海；有归属 → 非公海
                Db::execute("UPDATE `{$table}` t
                    LEFT JOIN `resource_owner` ro ON ro.res_type = ? AND ro.res_id = t.id
                    SET t.is_pool = CASE WHEN ro.id IS NULL THEN 1 ELSE 0 END", [$type]);
            } catch (\Throwable $e) {
                // 单表异常不影响其它表，下一轮重试
            }
        }
    }

    private static function ensureColumn(string $table, string $column, string $ddl): void
    {
        try {
            $exists = Db::query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
            if (!$exists) {
                Db::execute($ddl);
            }
        } catch (\Throwable $e) {
            // 表/列异常忽略，下一轮自愈
        }
    }

    /**
     * 历史 create_by=0 回填。
     * 优先级：关联主体（企业/人员/证书）的负责人 owner_user_id；仍无法推导的归首个启用管理员。
     * 这样切换到创建人范围后，旧数据至少对原负责人/管理员可见，不会变成全员不可见的孤儿。
     */
    private static function backfill(): void
    {
        $adminId = (int)(Db::name('sys_user')->alias('u')
            ->join('sys_role r', 'r.id = u.role_id')
            ->where('u.status', 1)->where('r.role_code', 'admin')
            ->order('u.id', 'asc')->value('u.id') ?: 0);

        // 合同：按企业负责人
        if (Db::name('contract')->where('create_by', 0)->count() > 0) {
            Db::execute("UPDATE `contract` c
                JOIN `client_company` cc ON cc.id = c.company_id AND cc.owner_user_id > 0
                SET c.create_by = cc.owner_user_id WHERE c.create_by = 0");
            if ($adminId > 0) {
                Db::execute("UPDATE `contract` SET `create_by` = {$adminId} WHERE `create_by` = 0");
            }
        }

        // 费用：企业费用按企业负责人，人员费用按人员负责人
        if (Db::name('fee')->where('create_by', 0)->count() > 0) {
            Db::execute("UPDATE `fee` f
                JOIN `client_company` cc ON cc.id = f.company_id AND cc.owner_user_id > 0
                SET f.create_by = cc.owner_user_id
                WHERE f.create_by = 0 AND f.subject_type = 'company'");
            Db::execute("UPDATE `fee` f
                JOIN `staff` st ON st.id = f.staff_id AND st.owner_user_id > 0
                SET f.create_by = st.owner_user_id
                WHERE f.create_by = 0 AND f.subject_type = 'staff'");
            if ($adminId > 0) {
                Db::execute("UPDATE `fee` SET `create_by` = {$adminId} WHERE `create_by` = 0");
            }
        }

        // 借调：按持证人员负责人
        if (Db::name('cert_secondment')->where('create_by', 0)->count() > 0) {
            Db::execute("UPDATE `cert_secondment` s
                JOIN `staff` st ON st.id = s.staff_id AND st.owner_user_id > 0
                SET s.create_by = st.owner_user_id WHERE s.create_by = 0");
            if ($adminId > 0) {
                Db::execute("UPDATE `cert_secondment` SET `create_by` = {$adminId} WHERE `create_by` = 0");
            }
        }

        // 申报进度：既有 owner_id 直接回填
        try {
            if (Db::name('gov_progress')->where('create_by', 0)->count() > 0) {
                Db::execute("UPDATE `gov_progress` SET `create_by` = `owner_id` WHERE `create_by` = 0 AND `owner_id` > 0");
                if ($adminId > 0) {
                    Db::execute("UPDATE `gov_progress` SET `create_by` = {$adminId} WHERE `create_by` = 0");
                }
            }
        } catch (\Throwable $e) {
            // 老库缺列时跳过
        }
    }
}
