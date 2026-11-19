<?php
namespace app\service;

use think\facade\Db;

/**
 * 考试报名模块建表自愈（v2.85）
 *
 * 移植自中介代报名系统 exam-reg 的「人员报名」核心：
 *   配置四表（证书分类 / 证书类型 / 业务动作 / 业务组合）
 *   + 考生（关联 staff）+ 报名工单（状态机 / 规则 / 材料快照）
 *   + 批次 / 考点 + 材料 / 审核记录 / 流转日志。
 *
 * 约定与本系统一致：
 *   - 表无表前缀；时间列 create_time / update_time；
 *   - 不提供 upgrade_*.sql，老库 git pull 后由 ensureTables() 幂等建表，
 *     全新安装以 database/init.sql 为权威 DDL（两者保持同构）。
 *
 * 财务收退款、介绍人/渠道/分组、工商业务等不在第一期范围，相关表不移植。
 */
class ExamSchemaService
{
    /** 进程内只建一次 */
    protected static bool $ensured = false;

    public static function ensureTables(): void
    {
        if (self::$ensured) {
            return;
        }
        self::$ensured = true;

        ensure_table('exam_cert_category', self::ddlCategory());
        ensure_table('exam_cert_type', self::ddlCertType());
        ensure_table('exam_biz_action', self::ddlBizAction());
        ensure_table('exam_biz_config', self::ddlBizConfig());
        ensure_table('exam_room', self::ddlRoom());
        ensure_table('exam_batch', self::ddlBatch());
        ensure_table('exam_customer', self::ddlCustomer());
        ensure_table('exam_signup', self::ddlSignup());
        ensure_table('exam_signup_material', self::ddlMaterial());
        ensure_table('exam_audit_record', self::ddlAudit());
        ensure_table('exam_signup_log', self::ddlLog());
        // v2.99.70：安管人员成绩查询结果（新取证 / 继续教育）
        ensure_table('exam_score_query', self::ddlScoreQuery());

        // v2.87 增量列：老库幂等补齐
        ensure_column('exam_customer', 'graduate_school',
            "varchar(120) NOT NULL DEFAULT '' COMMENT '毕业学校（毕业证OCR）'");

        self::seedDefaults();
    }

    /** 证书分类 */
    protected static function ddlCategory(): string
    {
        return "CREATE TABLE `exam_cert_category` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL COMMENT '分类名称（安管/特种/八大员/技工/其他）',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序',
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '1正常 0停用/删除',
  `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
  `create_by` int unsigned NOT NULL DEFAULT 0,
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='报名-证书分类'";
    }

    /** 证书类型 */
    protected static function ddlCertType(): string
    {
        return "CREATE TABLE `exam_cert_type` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL DEFAULT '' COMMENT '证书编码',
  `name` varchar(100) NOT NULL COMMENT '证书名称',
  `cate_id` int unsigned NOT NULL DEFAULT 0 COMMENT '证书分类 exam_cert_category.id',
  `category` varchar(30) NOT NULL DEFAULT '其他' COMMENT '分类名称快照',
  `valid_years` int NOT NULL DEFAULT 0 COMMENT '有效期（年），0=长期有效',
  `review_cycle_years` int NOT NULL DEFAULT 0 COMMENT '复审周期（年），0=无需复审',
  `need_exam` tinyint NOT NULL DEFAULT 0 COMMENT '是否需要考试',
  `need_physical` tinyint NOT NULL DEFAULT 0 COMMENT '是否需要体检',
  `min_age` int NOT NULL DEFAULT 0 COMMENT '最低年龄（含），0=不限',
  `max_age` int NOT NULL DEFAULT 0 COMMENT '最高年龄（含），0=不限',
  `education_req` varchar(30) NOT NULL DEFAULT '' COMMENT '学历要求：小学/初中/高中/中专/大专/本科',
  `default_fee` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '默认报名费',
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '1正常 0停用/删除',
  `remark` varchar(255) NOT NULL DEFAULT '',
  `create_by` int unsigned NOT NULL DEFAULT 0,
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cate` (`cate_id`),
  KEY `idx_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='报名-证书类型'";
    }

    /** 业务动作 */
    protected static function ddlBizAction(): string
    {
        return "CREATE TABLE `exam_biz_action` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL COMMENT '动作编码（唯一）',
  `name` varchar(100) NOT NULL COMMENT '动作名称',
  `category` varchar(30) NOT NULL DEFAULT '报名' COMMENT '大类：报名/继续教育/复审/变更补证',
  `status_flow` json DEFAULT NULL COMMENT '默认状态流 JSON：[{key,label}]',
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '1正常 0停用/删除',
  `remark` varchar(255) NOT NULL DEFAULT '',
  `create_by` int unsigned NOT NULL DEFAULT 0,
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='报名-业务动作'";
    }

    /** 业务组合：证书类型 × 业务动作 */
    protected static function ddlBizConfig(): string
    {
        return "CREATE TABLE `exam_biz_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `cert_type_id` int unsigned NOT NULL COMMENT '证书类型',
  `action_id` int unsigned NOT NULL COMMENT '业务动作',
  `name` varchar(150) NOT NULL DEFAULT '' COMMENT '业务名称（默认 证书+动作）',
  `rules` json DEFAULT NULL COMMENT '校验规则 JSON：[{field,op,value,tip}]',
  `materials` json DEFAULT NULL COMMENT '材料清单 JSON：[{code,name,required,multiple,ext,max_kb,type,tip,sort}]',
  `fee` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '报名费（覆盖证书默认费）',
  `status_flow` json DEFAULT NULL COMMENT '状态流覆盖，NULL 则继承动作默认',
  `is_open` tinyint NOT NULL DEFAULT 1 COMMENT '是否开放办理',
  `sort` int unsigned NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '1正常 0停用/删除',
  `remark` varchar(255) NOT NULL DEFAULT '',
  `create_by` int unsigned NOT NULL DEFAULT 0,
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cert_action` (`cert_type_id`,`action_id`),
  KEY `idx_action` (`action_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='报名-业务组合'";
    }

    /** 考点 */
    protected static function ddlRoom(): string
    {
        return "CREATE TABLE `exam_room` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL COMMENT '考点名称',
  `address` varchar(200) NOT NULL DEFAULT '' COMMENT '考点地址',
  `contact` varchar(50) NOT NULL DEFAULT '' COMMENT '联系人',
  `phone` varchar(20) NOT NULL DEFAULT '' COMMENT '联系电话',
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '1正常 0停用/删除',
  `create_by` int unsigned NOT NULL DEFAULT 0,
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='报名-考点'";
    }

    /** 报名批次 */
    protected static function ddlBatch(): string
    {
        return "CREATE TABLE `exam_batch` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL COMMENT '批次名称',
  `biz_config_id` int unsigned NOT NULL DEFAULT 0 COMMENT '业务组合（0=通用批次）',
  `room_id` int unsigned NOT NULL DEFAULT 0 COMMENT '考点',
  `sign_start` datetime DEFAULT NULL COMMENT '报名开始',
  `sign_end` datetime DEFAULT NULL COMMENT '报名截止',
  `exam_date` datetime DEFAULT NULL COMMENT '考试时间',
  `quota` int NOT NULL DEFAULT 0 COMMENT '人数上限，0=不限',
  `fee` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '批次费用',
  `sign_open` tinyint NOT NULL DEFAULT 1 COMMENT '报名是否开放',
  `is_current` tinyint NOT NULL DEFAULT 0 COMMENT '是否当前批次',
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '1正常 0停用/删除',
  `remark` varchar(255) NOT NULL DEFAULT '',
  `create_by` int unsigned NOT NULL DEFAULT 0,
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_config` (`biz_config_id`),
  KEY `idx_room` (`room_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='报名-批次'";
    }

    /**
     * 考生：报考专用档案。可关联现有 staff（报名优先选内部人员），
     * 也可为外部考生快速建档；不写回、不污染 staff。
     */
    protected static function ddlCustomer(): string
    {
        return "CREATE TABLE `exam_customer` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `staff_id` int unsigned NOT NULL DEFAULT 0 COMMENT '关联内部人员 staff.id，0=外部考生',
  `name` varchar(50) NOT NULL COMMENT '姓名',
  `gender` tinyint NOT NULL DEFAULT 1 COMMENT '1男 2女',
  `nation` varchar(30) NOT NULL DEFAULT '' COMMENT '民族',
  `id_card` varchar(255) NOT NULL DEFAULT '' COMMENT '身份证号（SM4 密文）',
  `id_card_hash` char(64) DEFAULT NULL COMMENT '身份证号盲索引（HMAC-SM3）',
  `birthday` date DEFAULT NULL COMMENT '出生日期',
  `phone` varchar(20) NOT NULL DEFAULT '' COMMENT '手机号',
  `education` varchar(30) NOT NULL DEFAULT '' COMMENT '学历',
  `edu_type` varchar(20) NOT NULL DEFAULT '无' COMMENT '学历性质：无/全日制/非全日制',
  `edu_cert_no` varchar(50) NOT NULL DEFAULT '' COMMENT '毕业证书编号（电子注册号）',
  `graduate_date` date DEFAULT NULL COMMENT '毕业日期',
  `graduate_school` varchar(120) NOT NULL DEFAULT '' COMMENT '毕业学校（v2.87 毕业证OCR）',
  `company_id` int unsigned NOT NULL DEFAULT 0 COMMENT '报名单位 client_company.id（复用现有企业）',
  `unit_name` varchar(150) NOT NULL DEFAULT '' COMMENT '单位名称快照',
  `city` varchar(50) NOT NULL DEFAULT '' COMMENT '所属地市',
  `address` varchar(200) NOT NULL DEFAULT '' COMMENT '通讯地址',
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '1正常 0停用/删除',
  `remark` varchar(255) NOT NULL DEFAULT '',
  `create_by` int unsigned NOT NULL DEFAULT 0,
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_id_card_hash` (`id_card_hash`),
  KEY `idx_staff` (`staff_id`),
  KEY `idx_phone` (`phone`),
  KEY `idx_name` (`name`),
  KEY `idx_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='报名-考生档案'";
    }

    /** 报名工单（核心） */
    protected static function ddlSignup(): string
    {
        return "CREATE TABLE `exam_signup` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `signup_no` varchar(32) NOT NULL COMMENT '报名单号（唯一）',
  `customer_id` int unsigned NOT NULL DEFAULT 0 COMMENT '考生 exam_customer.id（草稿可暂为0）',
  `staff_id` int unsigned NOT NULL DEFAULT 0 COMMENT '关联 staff.id 冗余，便于与证照体系联动',
  `company_id` int unsigned NOT NULL DEFAULT 0 COMMENT '报名单位 client_company.id',
  `biz_config_id` int unsigned NOT NULL DEFAULT 0 COMMENT '业务组合',
  `batch_id` int unsigned NOT NULL DEFAULT 0 COMMENT '批次',
  `room_id` int unsigned NOT NULL DEFAULT 0 COMMENT '考点冗余',
  `cert_type_id` int unsigned NOT NULL DEFAULT 0 COMMENT '证书类型冗余',
  `action_id` int unsigned NOT NULL DEFAULT 0 COMMENT '业务动作冗余',
  `fee` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '报名费（本期仅登记，不含收退款流程）',
  `stage` int NOT NULL DEFAULT 1 COMMENT '当前状态节点序号（1-based）',
  `stage_times` json DEFAULT NULL COMMENT '各节点时间 JSON：{key:datetime}',
  `exception` tinyint NOT NULL DEFAULT 0 COMMENT '是否异常挂起',
  `reject_reason` varchar(255) NOT NULL DEFAULT '' COMMENT '最近驳回原因',
  `is_draft` tinyint NOT NULL DEFAULT 0 COMMENT '1草稿 0正式',
  `rule_override` tinyint NOT NULL DEFAULT 0 COMMENT '是否不符合条件仍办理（留痕）',
  `snapshot` json DEFAULT NULL COMMENT '下单时规则/材料/状态流/考生草稿快照',
  `sign_time` datetime DEFAULT NULL COMMENT '正式提交时间',
  `owner_user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '负责人（数据权限）',
  `remark` varchar(255) NOT NULL DEFAULT '',
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '1正常 0删除（软删，重复校验排除）',
  `create_by` int unsigned NOT NULL DEFAULT 0,
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_signup_no` (`signup_no`),
  KEY `idx_customer` (`customer_id`),
  KEY `idx_staff` (`staff_id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_config` (`biz_config_id`),
  KEY `idx_batch` (`batch_id`),
  KEY `idx_stage` (`stage`),
  KEY `idx_owner` (`owner_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='报名-报名工单'";
    }

    /** 报名材料 */
    protected static function ddlMaterial(): string
    {
        return "CREATE TABLE `exam_signup_material` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `signup_id` int unsigned NOT NULL,
  `material_code` varchar(50) NOT NULL COMMENT '材料编码（对应清单）',
  `material_name` varchar(100) NOT NULL DEFAULT '' COMMENT '材料名称快照',
  `file_path` varchar(255) NOT NULL DEFAULT '' COMMENT '文件相对路径（复用通用上传）',
  `file_url` varchar(255) NOT NULL DEFAULT '' COMMENT '访问 URL（WebDAV 时为签名地址）',
  `file_ext` varchar(20) NOT NULL DEFAULT '',
  `file_size` int unsigned NOT NULL DEFAULT 0,
  `orig_name` varchar(255) NOT NULL DEFAULT '' COMMENT '原始文件名',
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '1正常 0删除',
  `uploaded_by` int unsigned NOT NULL DEFAULT 0,
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_signup` (`signup_id`),
  KEY `idx_signup_code` (`signup_id`,`material_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='报名-工单材料'";
    }

    /** 审核记录 */
    protected static function ddlAudit(): string
    {
        return "CREATE TABLE `exam_audit_record` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `signup_id` int unsigned NOT NULL,
  `action` varchar(20) NOT NULL DEFAULT 'pass' COMMENT 'pass通过 reject驳回',
  `stage` int NOT NULL DEFAULT 0 COMMENT '审核时节点',
  `reason` varchar(255) NOT NULL DEFAULT '' COMMENT '原因/意见',
  `auditor` int unsigned NOT NULL DEFAULT 0 COMMENT '审核人',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_signup` (`signup_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='报名-审核记录'";
    }

    /** 流转日志 */
    protected static function ddlLog(): string
    {
        return "CREATE TABLE `exam_signup_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `signup_id` int unsigned NOT NULL,
  `from_stage` int NOT NULL DEFAULT 0,
  `to_stage` int NOT NULL DEFAULT 0,
  `note` varchar(255) NOT NULL DEFAULT '',
  `operator` int unsigned NOT NULL DEFAULT 0,
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_signup` (`signup_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='报名-工单流转日志'";
    }

    /**
     * 成绩查询结果（v2.99.70）
     *
     * 一次查询是某人员 + 某查询类型（新取证 / 继续教育）的当前结果快照：
     * 重新查询会先清掉该人员该类型的旧行，再写入平台返回的全部成绩；
     * 未查询到时写入一行 status=3 作为记录，便于列表按三类状态统计。
     */
    protected static function ddlScoreQuery(): string
    {
        return "CREATE TABLE `exam_score_query` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `staff_id` int unsigned NOT NULL DEFAULT 0 COMMENT '人员档案 staff.id',
  `name` varchar(64) NOT NULL DEFAULT '' COMMENT '姓名快照',
  `id_card` varchar(512) NOT NULL DEFAULT '' COMMENT '身份证号（SM4 密文）',
  `id_card_hash` varchar(64) DEFAULT NULL COMMENT '身份证号盲索引',
  `company_id` int unsigned NOT NULL DEFAULT 0 COMMENT '匹配到的客户企业ID',
  `company_name` varchar(255) NOT NULL DEFAULT '' COMMENT '企业名称（平台原文）',
  `person_type` varchar(16) NOT NULL DEFAULT '' COMMENT '人员类别（A/B/C）',
  `exam_date` date DEFAULT NULL COMMENT '考试日期',
  `is_pass` tinyint NOT NULL DEFAULT 0 COMMENT '1合格 0不合格',
  `query_type` varchar(8) NOT NULL DEFAULT 'new' COMMENT 'new新取证 edu继续教育',
  `status` tinyint NOT NULL DEFAULT 3 COMMENT '1合格 2不合格 3未查询到成绩',
  `source` varchar(32) NOT NULL DEFAULT 'hnagks' COMMENT '数据来源',
  `raw_json` text COMMENT '平台原始行',
  `query_time` datetime DEFAULT NULL COMMENT '查询时间',
  `create_by` int unsigned NOT NULL DEFAULT 0,
  `dept_id` int unsigned NOT NULL DEFAULT 0,
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_staff_type` (`staff_id`,`query_type`),
  KEY `idx_company` (`company_id`),
  KEY `idx_status` (`status`),
  KEY `idx_hash` (`id_card_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='报名-成绩查询结果'";
    }

    /**
     * 默认基础数据（幂等）：一套开箱即用的证书分类、四个标准业务动作及其默认状态流。
     * 「新增证书业务只加数据不改码」：业务动作默认流可在后台调整，业务组合可覆盖。
     */
    protected static function seedDefaults(): void
    {
        try {
            // 证书分类
            $cats = ['安管类', '特种作业', '八大员', '技工类', '其他'];
            $sort = 0;
            foreach ($cats as $name) {
                $sort++;
                $exist = Db::name('exam_cert_category')->where('name', $name)->find();
                if (!$exist) {
                    Db::name('exam_cert_category')->insert(['name' => $name, 'sort' => $sort, 'status' => 1]);
                }
            }

            // 标准业务动作 + 默认状态流
            $flow = json_encode([
                ['key' => 'created',  'label' => '待提交'],
                ['key' => 'submitted','label' => '待审核'],
                ['key' => 'audited',  'label' => '已审核'],
                ['key' => 'signed',   'label' => '已报名'],
                ['key' => 'exam',     'label' => '已排考'],
                ['key' => 'issued',   'label' => '已完成'],
            ], JSON_UNESCAPED_UNICODE);
            $actions = [
                ['initial',  '初次报名',   '报名'],
                ['continue', '继续教育',   '继续教育'],
                ['review',   '复审延期',   '复审'],
                ['change',   '变更补证',   '变更补证'],
            ];
            $sort = 0;
            foreach ($actions as [$code, $name, $cat]) {
                $sort++;
                $exist = Db::name('exam_biz_action')->where('code', $code)->find();
                if (!$exist) {
                    Db::name('exam_biz_action')->insert([
                        'code' => $code, 'name' => $name, 'category' => $cat,
                        'status_flow' => $flow, 'sort' => $sort, 'status' => 1,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // 种子失败不阻断业务（下次自愈重试）
        }
    }
}
