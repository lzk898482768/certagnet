<?php
namespace app\service;

use think\facade\Db;

/**
 * 学历报名模块建表自愈（v2.99）
 *
 * 与考试报名模块同构：
 *   - 表无表前缀；时间列 create_time / update_time；
 *   - 不提供 upgrade_*.sql，老库 git pull 后由 ensureTables() 幂等建表，
 *     全新安装以 database/init.sql 为权威 DDL（两者保持同构）。
 *
 * 表清单：
 *   edu_agency        第三方报名机构
 *   edu_flow_config   按报名类型的可自定义进度流程
 *   edu_signup        学历报名工单（核心，状态机 / 快照 / 学费冗余 / 归档）
 *   edu_signup_attach 工单附件（支持单条软删）
 *   edu_signup_log    工单流转日志
 */
class EduSchemaService
{
    /** 进程内只建一次 */
    protected static bool $ensured = false;

    public static function ensureTables(): void
    {
        if (self::$ensured) {
            return;
        }
        self::$ensured = true;

        ensure_table('edu_agency', self::ddlAgency());
        ensure_table('edu_flow_config', self::ddlFlowConfig());
        ensure_table('edu_signup', self::ddlSignup());
        ensure_table('edu_signup_attach', self::ddlAttach());
        ensure_table('edu_signup_log', self::ddlLog());

        // 老库 fee 表可能停留在 v2.24 之前结构（缺人员归属列），学费联动写入前幂等补齐
        ensure_column('fee', 'staff_id',
            "int unsigned NOT NULL DEFAULT 0 COMMENT '持证人员ID（subject_type=staff 时有效）' AFTER `company_id`");
        ensure_column('fee', 'subject_type',
            "varchar(16) NOT NULL DEFAULT 'company' COMMENT '费用归属对象类型：company客户企业 / staff持证人员' AFTER `staff_id`");
        ensure_column('fee', 'payer_name',
            "varchar(128) NOT NULL DEFAULT '' COMMENT '付款方名称' AFTER `subject_type`");

        self::seedDefaults();
    }

    /** 第三方报名机构 */
    protected static function ddlAgency(): string
    {
        return "CREATE TABLE `edu_agency` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL COMMENT '机构名称',
  `contact` varchar(50) NOT NULL DEFAULT '' COMMENT '联系人',
  `phone` varchar(30) NOT NULL DEFAULT '' COMMENT '联系电话',
  `address` varchar(200) NOT NULL DEFAULT '' COMMENT '机构地址',
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '1正常 0停用/删除',
  `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
  `create_by` int unsigned NOT NULL DEFAULT 0,
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='学历报名-第三方报名机构'";
    }

    /** 进度流程配置（按报名类型，一套一条；可自定义节点） */
    protected static function ddlFlowConfig(): string
    {
        return "CREATE TABLE `edu_flow_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `signup_type` varchar(30) NOT NULL COMMENT '报名类型 dict: edu_signup_type（电中/成考）',
  `flow_name` varchar(100) NOT NULL DEFAULT '' COMMENT '流程名称',
  `status_flow` json DEFAULT NULL COMMENT '状态流 JSON：[{key,label}]',
  `update_by` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '1正常 0停用',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_type` (`signup_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='学历报名-进度流程配置'";
    }

    /** 学历报名工单（核心） */
    protected static function ddlSignup(): string
    {
        return "CREATE TABLE `edu_signup` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `signup_no` varchar(32) NOT NULL COMMENT '报名单号（唯一，XL前缀）',
  `signup_type` varchar(30) NOT NULL DEFAULT '' COMMENT '报名类型 dict: edu_signup_type',
  `edu_level` varchar(30) NOT NULL DEFAULT '' COMMENT '学历类型 dict: edu_level',
  `edu_nature` varchar(30) NOT NULL DEFAULT '' COMMENT '学历性质 dict: edu_nature',
  `schooling` varchar(20) NOT NULL DEFAULT '' COMMENT '学制 dict: edu_schooling',
  `staff_id` int unsigned NOT NULL DEFAULT 0 COMMENT '关联人员档案 staff.id，0=工单内现场新增未建档',
  `name` varchar(50) NOT NULL COMMENT '姓名',
  `id_card` varchar(255) NOT NULL DEFAULT '' COMMENT '身份证号（SM4 密文）',
  `id_card_hash` char(64) DEFAULT NULL COMMENT '身份证号盲索引（HMAC-SM3）',
  `phone` varchar(20) NOT NULL DEFAULT '' COMMENT '电话',
  `address` varchar(200) NOT NULL DEFAULT '' COMMENT '地址',
  `gender` tinyint NOT NULL DEFAULT 0 COMMENT '性别 0未知 1男 2女',
  `major` varchar(120) NOT NULL DEFAULT '' COMMENT '报名专业',
  `sign_time` date DEFAULT NULL COMMENT '报名时间',
  `enroll_time` date DEFAULT NULL COMMENT '入学时间',
  `expect_graduate_time` date DEFAULT NULL COMMENT '预计毕业时间',
  `graduate_time` date DEFAULT NULL COMMENT '毕业时间',
  `agency_id` int unsigned NOT NULL DEFAULT 0 COMMENT '第三方报名机构 edu_agency.id',
  `agency_name` varchar(150) NOT NULL DEFAULT '' COMMENT '机构名称快照',
  `agency_contact` varchar(50) NOT NULL DEFAULT '' COMMENT '机构联系人快照',
  `agency_phone` varchar(30) NOT NULL DEFAULT '' COMMENT '机构联系电话快照',
  `owner_user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '经办人 sys_user.id（数据权限）',
  `fee_total` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '总学费（冗余，权威在费用台账 income 应收）',
  `fee_received` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '已收学费（冗余，权威在 income.settle_amount）',
  `fee_collect` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '代收学费（冗余，权威在费用台账 expense 应付机构）',
  `roll_status` varchar(20) NOT NULL DEFAULT 'enrolled' COMMENT '学籍状态 dict: edu_roll_status：enrolled在籍 graduated已毕业',
  `stage` int NOT NULL DEFAULT 1 COMMENT '当前进度节点序号（1-based）',
  `stage_times` json DEFAULT NULL COMMENT '各节点时间 JSON：{key:datetime}',
  `snapshot` json DEFAULT NULL COMMENT '建单时状态流/机构/表单快照',
  `is_archived` tinyint NOT NULL DEFAULT 0 COMMENT '是否已归档（毕业自动归档）：1是 0否',
  `archive_time` datetime DEFAULT NULL COMMENT '归档时间',
  `remark` varchar(500) NOT NULL DEFAULT '' COMMENT '备注',
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '1正常 0删除（软删）',
  `create_by` int unsigned NOT NULL DEFAULT 0,
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_signup_no` (`signup_no`),
  KEY `idx_staff` (`staff_id`),
  KEY `idx_agency` (`agency_id`),
  KEY `idx_owner` (`owner_user_id`),
  KEY `idx_type` (`signup_type`),
  KEY `idx_stage` (`stage`),
  KEY `idx_roll` (`roll_status`),
  KEY `idx_archived` (`is_archived`),
  KEY `idx_name` (`name`),
  KEY `idx_idcard_hash` (`id_card_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='学历报名-报名工单'";
    }

    /** 工单附件（支持单条软删，对齐 exam_signup_material） */
    protected static function ddlAttach(): string
    {
        return "CREATE TABLE `edu_signup_attach` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `signup_id` int unsigned NOT NULL COMMENT '学历报名工单 edu_signup.id',
  `attach_name` varchar(150) NOT NULL DEFAULT '' COMMENT '附件名称（可编辑）',
  `file_path` varchar(255) NOT NULL DEFAULT '' COMMENT '文件相对路径（复用通用上传）',
  `file_url` varchar(255) NOT NULL DEFAULT '' COMMENT '访问 URL',
  `file_ext` varchar(20) NOT NULL DEFAULT '',
  `file_size` int unsigned NOT NULL DEFAULT 0,
  `orig_name` varchar(255) NOT NULL DEFAULT '' COMMENT '原始文件名',
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '1正常 0删除',
  `uploaded_by` int unsigned NOT NULL DEFAULT 0,
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_signup` (`signup_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='学历报名-工单附件'";
    }

    /** 工单流转日志 */
    protected static function ddlLog(): string
    {
        return "CREATE TABLE `edu_signup_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `signup_id` int unsigned NOT NULL,
  `from_stage` int NOT NULL DEFAULT 0,
  `to_stage` int NOT NULL DEFAULT 0,
  `action` varchar(20) NOT NULL DEFAULT 'transit' COMMENT 'transit流转/archive归档/unarchive反归档',
  `note` varchar(255) NOT NULL DEFAULT '',
  `operator` int unsigned NOT NULL DEFAULT 0,
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_signup` (`signup_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='学历报名-工单流转日志'";
    }

    /**
     * 默认流程种子（幂等）：电中/成考各一套默认进度。
     * 仅在该报名类型尚无配置时插入，管理员后续自定义不会被覆盖。
     */
    protected static function seedDefaults(): void
    {
        try {
            $defaults = [
                'dianzhong' => ['中专（电中）报名流程', [
                    ['key' => 'pending',   'label' => '待报名'],
                    ['key' => 'signed',    'label' => '已报名'],
                    ['key' => 'enrolled',  'label' => '已入籍'],
                    ['key' => 'graduated', 'label' => '已毕业'],
                ]],
                'chengkao' => ['成人高考报名流程', [
                    ['key' => 'pending',   'label' => '待报名'],
                    ['key' => 'signed',    'label' => '已报名'],
                    ['key' => 'to_exam',   'label' => '待考试'],
                    ['key' => 'to_admit',  'label' => '待录取'],
                    ['key' => 'admitted',  'label' => '已录取'],
                ]],
            ];
            foreach ($defaults as $type => [$flowName, $flow]) {
                $exist = Db::name('edu_flow_config')->where('signup_type', $type)->find();
                if (!$exist) {
                    Db::name('edu_flow_config')->insert([
                        'signup_type' => $type,
                        'flow_name'   => $flowName,
                        'status_flow' => json_encode($flow, JSON_UNESCAPED_UNICODE),
                        'status'      => 1,
                        'create_time' => date('Y-m-d H:i:s'),
                        'update_time' => date('Y-m-d H:i:s'),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // 种子失败不阻断业务（下次自愈重试）
        }
    }
}
