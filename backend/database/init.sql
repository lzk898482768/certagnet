-- ============================================================
-- 证照到期管理系统（中介版）v2 完整建库脚本
-- MySQL 8.0 / utf8mb4
-- 含原 15 张表 + v2 新增表（字典/合同/续期/操作日志/借调/费用/收款账户…）
-- ============================================================
-- 用法：先建库并选中，再导入本文件
--   mysql -u root -p -e "CREATE DATABASE cert_agent DEFAULT CHARSET utf8mb4"
--   mysql -u root -p cert_agent < init.sql
-- （也可直接使用 public/install.php 可视化安装向导）
--
-- 本文件为唯一安装入口，已合并历次升级（v2.1 ~ v2.43）的全部结构变更，
-- 无需再执行任何 upgrade_*.sql / migrate_*.php。
--
-- 【费用模块约定】
--   fee.pay_account_id  收款账户ID（结算方式，默认 0 = 未指定）
--   fee.pay_method      结算方式字典，取值同 pay_account.account_type：
--                       bank_public 对公银行账户 / bank_private 对私银行账户 /
--                       wechat 微信 / alipay 支付宝 / cash 现金 / other 其他
SET NAMES utf8mb4;

-- 1. 客户企业表 ---------------------------------------------------------
CREATE TABLE `client_company` (
  `id`            int unsigned NOT NULL AUTO_INCREMENT COMMENT '客户企业ID',
  `create_by`     int unsigned NOT NULL DEFAULT 0 COMMENT '创建人系统账号ID（v2.54 CRM）',
  `owner_user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '负责人系统账号ID，0=未分配（v2.54 CRM）',
  `is_pool`       tinyint NOT NULL DEFAULT 0 COMMENT '1=公海（无所有人，全员可认领，v2.99.37）',
  `company_name`  varchar(128)  NOT NULL COMMENT '企业全称',
  `short_name`    varchar(64)   DEFAULT '' COMMENT '企业简称',
  `credit_code`   varchar(64)   DEFAULT '' COMMENT '统一社会信用代码',
  `legal_person`  varchar(64)   NOT NULL DEFAULT '' COMMENT '法定代表人（v2.43，河南平台采集/手工维护）',
  `region_path`   varchar(128)  NOT NULL DEFAULT '' COMMENT '注册属地（v2.43，平台格式：省/市）',
  `registered_capital` varchar(64) NOT NULL DEFAULT '' COMMENT '注册资本（v2.43）',
  `contact`       varchar(64)   DEFAULT '' COMMENT '对接联系人',
  `contact_phone` varchar(32)   DEFAULT '' COMMENT '企业联系电话',
  `address`       varchar(255)  DEFAULT '' COMMENT '企业地址',
  `industry`      varchar(64)   DEFAULT '' COMMENT '所属行业（关联 dict: industry）',
  `company_scale` varchar(32)   DEFAULT '' COMMENT '企业规模（关联 dict: company_scale）',
  `attach_path`   varchar(255)  DEFAULT '' COMMENT '附件路径（v2.35 多附件派生第一条，兼容列表/导出）',
  `remark`        text COMMENT '备注',
  `status`        tinyint NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `create_time`   datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time`   datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `credit_code_uniq` varchar(64) GENERATED ALWAYS AS (NULLIF(`credit_code`, '')) STORED COMMENT '统一社会信用代码唯一键（空值转 NULL，v2.99.1）',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_credit_code` (`credit_code_uniq`),
  KEY `idx_pool` (`is_pool`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='中介-客户企业';

-- 2. 持证人员档案表 -----------------------------------------------------
CREATE TABLE `staff` (
  `id`          int unsigned NOT NULL AUTO_INCREMENT,
  `staff_name`  varchar(64)  NOT NULL COMMENT '人员姓名',
  `id_card`     varchar(255) DEFAULT '' COMMENT '身份证号（SM4 密文，sm4$1$...）',
  `id_card_hash` char(64) DEFAULT NULL COMMENT '身份证号盲索引（HMAC-SM3，等值查询用）',
  `phone`       varchar(32)  DEFAULT '' COMMENT '联系电话',
  `email`       varchar(128) DEFAULT '' COMMENT '邮箱（推送用）',
  `dept`        varchar(64)  DEFAULT '' COMMENT '所属部门（关联 dict: dept）',
  `dept_id`     int unsigned NOT NULL DEFAULT 0 COMMENT '内部跟进部门 org_department.id，0=未分配（v2.14 数据权限）',
  `position`    varchar(64)  DEFAULT '' COMMENT '岗位',
  `social_status` tinyint NOT NULL DEFAULT 0 COMMENT '社保状态: 0未知 1在缴 2停缴 3未缴纳（v2.27 一人一档：社保上移至人档；certificate 同名字段为冗余兼容，读写以本表为准）',
  `social_company` varchar(128) NOT NULL DEFAULT '' COMMENT '社保缴纳单位',
  `social_start_month` varchar(7) NOT NULL DEFAULT '' COMMENT '社保起始月份 YYYY-MM',
  `region_code` varchar(32) DEFAULT '' COMMENT '属地区划编码（项目属地政策匹配用）',
  `gender` tinyint NOT NULL DEFAULT 0 COMMENT '性别 0未知 1男 2女（v2.87）',
  `nation` varchar(30) NOT NULL DEFAULT '' COMMENT '民族（v2.87）',
  `birthday` date DEFAULT NULL COMMENT '出生日期（v2.87）',
  `address` varchar(200) NOT NULL DEFAULT '' COMMENT '身份证住址/通讯地址（v2.87）',
  `education` varchar(30) NOT NULL DEFAULT '' COMMENT '学历（v2.87）',
  `edu_type` varchar(20) NOT NULL DEFAULT '无' COMMENT '学历性质：无/全日制/非全日制（v2.87）',
  `edu_cert_no` varchar(50) NOT NULL DEFAULT '' COMMENT '毕业证书编号（电子注册号）（v2.87）',
  `graduate_date` date DEFAULT NULL COMMENT '毕业日期（v2.87）',
  `graduate_school` varchar(120) NOT NULL DEFAULT '' COMMENT '毕业学校（v2.87）',
  `remark`      text COMMENT '备注',
  `attach_path` varchar(500) DEFAULT '' COMMENT '附件（扫描件/资料）路径',
  `status`      tinyint NOT NULL DEFAULT 1 COMMENT '1在职有效 0离职',
  `create_by`   int unsigned NOT NULL DEFAULT 0 COMMENT '创建人系统账号ID（v2.14 self 数据范围）',
  `owner_user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '负责人系统账号ID，0=未分配（v2.54 CRM）',
  `is_pool`     tinyint NOT NULL DEFAULT 0 COMMENT '1=公海（无所有人，v2.99.37）',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_name` (`staff_name`),
  KEY `idx_social` (`social_status`),
  KEY `idx_pool` (`is_pool`),
  KEY `idx_id_card_hash` (`id_card_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='持证人员档案';

-- 3. 人员-客户企业关联表（多对多）--------------------------------------
CREATE TABLE `staff_company_rel` (
  `id`          int unsigned NOT NULL AUTO_INCREMENT,
  `staff_id`    int unsigned NOT NULL COMMENT '人员ID',
  `company_id`  int unsigned NOT NULL COMMENT '客户企业ID',
  `entry_date`  date DEFAULT NULL COMMENT '入职/注册开始日期',
  `exit_date`   date DEFAULT NULL COMMENT '注销/离场日期',
  `remark`      text,
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_staff_company` (`staff_id`,`company_id`),
  KEY `idx_staff`   (`staff_id`),
  KEY `idx_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='人员与客户企业关联表';

-- 4. 证照主表 -----------------------------------------------------------
-- 说明：证书不再固定归属企业（人员不固定企业）。
--       证书当前被哪家企业使用，由 cert_secondment（借调记录）决定。
CREATE TABLE `certificate` (
  `id`                  int unsigned NOT NULL AUTO_INCREMENT,
  `staff_id`            int unsigned NOT NULL COMMENT '持证人员ID',
  `create_by`           int unsigned NOT NULL DEFAULT 0 COMMENT '创建人系统账号ID（v2.14 self 数据范围）',
  `owner_user_id`     int unsigned NOT NULL DEFAULT 0 COMMENT '负责人系统账号ID，0=未分配（v2.54 CRM）',
  `is_pool`             tinyint NOT NULL DEFAULT 0 COMMENT '1=公海（无所有人，v2.99.37）',
  `category`            varchar(64)   DEFAULT '' COMMENT '证书类别(dict: cert_category)',
  `cert_name`           varchar(128)  NOT NULL COMMENT '证照名称：二级建造师/安全员C证',
  `cert_no`             varchar(128)  NOT NULL COMMENT '证书编号',
  `cert_type`           varchar(64)   DEFAULT '' COMMENT '证书类型(dict: cert_major 一级)；为空=未分类',
  `issue_date`          date DEFAULT NULL COMMENT '发证日期',
  `expire_date`         date DEFAULT NULL COMMENT '证书到期日期（NULL=长期有效）',
  `issue_org`           varchar(128)  DEFAULT '' COMMENT '发证机关',
  `attach_path`         varchar(255)  DEFAULT '' COMMENT '证书扫描件上传路径',
  `enable_alert`        tinyint NOT NULL DEFAULT 1 COMMENT '是否开启到期提醒 1是0否',
  `alert_rules`         text COMMENT '提醒节点json ["1year","6month","3month"]',
  `custom_alert_days`   varchar(128)  DEFAULT '' COMMENT '自定义提前天数，逗号分隔，如 90,30,7',
  `priority`            varchar(16)   DEFAULT '普通' COMMENT '优先级：普通/重要/紧急',
  `register_start_date` date DEFAULT NULL COMMENT '注册起始日期',
  `register_end_date`   date DEFAULT NULL COMMENT '注册到期日期',
  `cert_fee`            decimal(12,2) DEFAULT 0.00 COMMENT '证书费用（年度/挂靠费）',
  `fee_remark`          varchar(255)  DEFAULT '' COMMENT '费用备注',
  `cert_status`         tinyint NOT NULL DEFAULT 1 COMMENT '证书状态:1正常 0过期 2挂失 3注销',
  `social_status`       tinyint NOT NULL DEFAULT 0 COMMENT '社保状态: 0未知 1在缴 2停缴 3未缴纳',
  `social_company`      varchar(128) NOT NULL DEFAULT '' COMMENT '社保缴纳单位',
  `social_start_month`  varchar(7) NOT NULL DEFAULT '' COMMENT '社保起始月份 YYYY-MM',
  `create_time`         datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time`         datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_staff`   (`staff_id`),
  KEY `idx_expire`  (`expire_date`),
  KEY `idx_status`  (`cert_status`),
  KEY `idx_social`  (`social_status`),
  KEY `idx_pool`    (`is_pool`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='证照信息表';

-- 4c. 负责人移交历史（v2.54 CRM）----------------------------------------
CREATE TABLE `cert_owner_transfer` (
  `id`            int unsigned NOT NULL AUTO_INCREMENT,
  `module`        varchar(20)  NOT NULL COMMENT 'company|staff|certificate',
  `action`        varchar(20)  NOT NULL DEFAULT 'transfer' COMMENT 'transfer移交/to_pool移入公海/claim认领/assign指派/duplicate查重共有/remove退出共有',
  `to_pool`       tinyint      NOT NULL DEFAULT 0 COMMENT '1=目标为公海',
  `record_id`     int unsigned NOT NULL DEFAULT 0 COMMENT '业务主表ID',
  `title`         varchar(255) NOT NULL DEFAULT '' COMMENT '主体标题（企业名/证书编号）',
  `from_user_id`  int unsigned NOT NULL DEFAULT 0 COMMENT '原负责人',
  `to_user_id`    int unsigned NOT NULL DEFAULT 0 COMMENT '新负责人',
  `operator_id`   int unsigned NOT NULL DEFAULT 0 COMMENT '操作人',
  `remark`        varchar(500) NOT NULL DEFAULT '' COMMENT '移交备注',
  `create_time`   datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_module_record` (`module`, `record_id`),
  KEY `idx_to_user` (`to_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='负责人移交历史（v2.54 CRM）';

-- 4c-2. 核心资源共同所有人（v2.99.37 P3）------------------------------
CREATE TABLE `resource_owner` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='核心资源共同所有人（多对多，v2.99.37）';

-- 4d. 跟进台账（v2.54 CRM）--------------------------------------------
CREATE TABLE `cert_follow_record` (
  `id`               int unsigned NOT NULL AUTO_INCREMENT,
  `module`           varchar(20)  NOT NULL COMMENT 'company|certificate',
  `record_id`        int unsigned NOT NULL DEFAULT 0 COMMENT '业务主表ID',
  `follow_type`      varchar(32)  NOT NULL DEFAULT '' COMMENT '跟进方式：电话/微信/上门拜访/邮件/短信/会议/其他',
  `content`          text NULL COMMENT '跟进内容',
  `next_follow_date` date DEFAULT NULL COMMENT '下次跟进日期',
  `create_by`        int unsigned NOT NULL DEFAULT 0 COMMENT '跟进人',
  `create_time`      datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_module_record` (`module`, `record_id`),
  KEY `idx_next_date` (`next_follow_date`),
  KEY `idx_create_by` (`create_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='跟进台账（v2.54 CRM）';

-- 2b. 建造师注册轨迹（v2.6）-------------------------------------------
CREATE TABLE `cert_register_track` (
  `id`            int unsigned NOT NULL AUTO_INCREMENT,
  `cert_id`       int unsigned NOT NULL DEFAULT 0 COMMENT '证书ID',
  `staff_id`      int unsigned NOT NULL DEFAULT 0 COMMENT '持证人员ID(冗余)',
  `company_id`    int unsigned NOT NULL DEFAULT 0 COMMENT '注册单位(企业)ID',
  `company_name`  varchar(128) NOT NULL DEFAULT '' COMMENT '注册单位名称(可手工填写)',
  `register_type` varchar(32)  NOT NULL DEFAULT '初始注册' COMMENT '初始/变更/延续/增项/重新注册',
  `register_no`   varchar(64)  NOT NULL DEFAULT '' COMMENT '注册编号/注册证书号',
  `register_date` date DEFAULT NULL COMMENT '注册日期',
  `valid_until`   date DEFAULT NULL COMMENT '注册有效期至',
  `cancel_date`   date DEFAULT NULL COMMENT '注销/转出日期',
  `status`        tinyint NOT NULL DEFAULT 1 COMMENT '1有效 0已注销',
  `remark`        varchar(255) DEFAULT '' COMMENT '备注',
  `create_time`   datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time`   datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cert` (`cert_id`),
  KEY `idx_staff` (`staff_id`),
  KEY `idx_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='建造师注册轨迹';

-- 4b. 证书借调记录表（v2.2 新增：企业借调人员证书，带期限/合同/费用）----
CREATE TABLE `cert_secondment` (
  `id`           int unsigned NOT NULL AUTO_INCREMENT,
  `cert_id`      int unsigned NOT NULL COMMENT '证书ID',
  `staff_id`     int unsigned NOT NULL DEFAULT 0 COMMENT '持证人员ID（冗余，便于查询）',
  `company_id`   int unsigned NOT NULL COMMENT '借入/使用企业ID',
  `direction`    tinyint NOT NULL DEFAULT 1 COMMENT '方向 1=借出 2=借入',
  `contract_id`  int unsigned NOT NULL DEFAULT 0 COMMENT '关联服务合同ID（0=未生成合同）',
  `start_date`   date DEFAULT NULL COMMENT '借调开始日期',
  `end_date`     date DEFAULT NULL COMMENT '借调到期日期（期限）',
  `fee_amount`   decimal(12,2) DEFAULT 0.00 COMMENT '借调费用金额',
  `fee_period`   varchar(16) DEFAULT '一次性' COMMENT '计费周期：一次性/月/季/年',
  `fee_status`   tinyint NOT NULL DEFAULT 0 COMMENT '费用结算状态 0未结 1已结',
  `status`       tinyint NOT NULL DEFAULT 1 COMMENT '状态 1借调中 0已归还 2已到期',
  `remark`       text COMMENT '备注',
  `create_by`    int unsigned NOT NULL DEFAULT 0 COMMENT '创建人账号ID（v2.99.36 数据权限）',
  `create_time`  datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time`  datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cert`    (`cert_id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_staff`   (`staff_id`),
  KEY `idx_status`  (`status`),
  KEY `idx_end`     (`end_date`),
  KEY `idx_active`  (`cert_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='证书借调记录（企业借调人员证书）';

-- 5. 企业独立全局提醒配置 ----------------------------------------------
CREATE TABLE `company_alert_config` (
  `id`              int unsigned NOT NULL AUTO_INCREMENT,
  `company_id`      int unsigned NOT NULL DEFAULT 0 COMMENT '客户企业ID；全局默认规则为 0',
  `is_global`       tinyint NOT NULL DEFAULT 0 COMMENT '1=全局默认规则（适用于未单独配置的企业）',
  `alert_nodes`     text COMMENT '默认提醒节点json',
  `alert_ways`      text COMMENT '提醒方式json',
  `default_priority` varchar(16) DEFAULT '普通' COMMENT '默认优先级',
  `create_time`     datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time`     datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_company` (`company_id`),
  KEY `idx_global`   (`is_global`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='提醒配置（含全局默认规则）';

-- 6. 到期提醒日志 -------------------------------------------------------
CREATE TABLE `alert_log` (
  `id`           int unsigned NOT NULL AUTO_INCREMENT,
  `company_id`   int unsigned NOT NULL COMMENT '客户企业ID',
  `cert_id`      int unsigned NOT NULL COMMENT '证书id',
  `alert_type`   varchar(16) NOT NULL DEFAULT 'cert' COMMENT '提醒类型: cert证书 / secondment借调 / company_cert企业证书',
  `staff_id`     int unsigned NOT NULL COMMENT '持证人员',
  `secondment_id` int unsigned NOT NULL DEFAULT 0 COMMENT '关联借调记录ID（alert_type=secondment 时有效）',
  `company_qualification_id` int unsigned NOT NULL DEFAULT 0 COMMENT '关联企业证书ID（alert_type=company_cert 时有效）',
  `alert_day`    int NOT NULL COMMENT '本次触发时剩余天数',
  `alert_node`   varchar(32) DEFAULT '' COMMENT '触发节点标识 如 30day / secondment_30day / companycert_30day / secondment_expire',
  `alert_date`   date DEFAULT NULL COMMENT '触发日期（用于去重）',
  `alert_content` text,
  `is_read`      tinyint NOT NULL DEFAULT 0 COMMENT '0未读 1已读',
  `create_time`  datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_cert`    (`cert_id`),
  KEY `idx_secondment` (`secondment_id`),
  KEY `idx_company_qualification` (`company_qualification_id`),
  UNIQUE KEY `uk_dedup` (`cert_id`,`alert_node`,`alert_date`,`secondment_id`,`company_qualification_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='到期提醒日志';

-- 7. 系统用户账号表 -----------------------------------------------------
CREATE TABLE `sys_user` (
  `id`          int unsigned NOT NULL AUTO_INCREMENT,
  `username`    varchar(64)  NOT NULL COMMENT '登录账号',
  `password`    varchar(128) NOT NULL COMMENT '密码（md5加密存储）',
  `real_name`   varchar(64)  DEFAULT '' COMMENT '用户姓名',
  `phone`       varchar(32)  DEFAULT '',
  `role_id`     int unsigned NOT NULL COMMENT '角色ID',
  `dept_id`     int unsigned NOT NULL DEFAULT 0 COMMENT '所属内部部门 org_department.id，0=未分配（v2.13）',
  `data_scope`  varchar(20) NOT NULL DEFAULT 'self' COMMENT '【已废弃 v2.99.36】旧部门四级范围 all/dept_tree/dept/self，保留列兼容老数据，鉴权改用 sys_role.data_policy + sys_user_data_scope',
  `status`      tinyint NOT NULL DEFAULT 1 COMMENT '1启用 0禁用',
  `wecom_userid`   varchar(64)  DEFAULT NULL COMMENT '企微 userid（v2.11 企微登录绑定）',
  `wecom_avatar`   varchar(255) DEFAULT NULL COMMENT '企微头像URL',
  `wecom_mobile`   varchar(20)  DEFAULT NULL COMMENT '企微通讯录手机号',
  `wecom_bound_at` datetime     DEFAULT NULL COMMENT '企微绑定时间',
  `totp_secret`    varchar(255) NOT NULL DEFAULT '' COMMENT 'TOTP 密钥（AES-256-GCM 加密，v2.75）',
  `totp_enabled`   tinyint      NOT NULL DEFAULT 0 COMMENT '是否已绑定腾讯身份验证器: 1是 0否（v2.75）',
  `totp_force`     tinyint      NOT NULL DEFAULT 0 COMMENT '管理员强制开启二次验证: 1是 0否（v2.75）',
  `totp_bound_at`  datetime     DEFAULT NULL COMMENT '二次验证绑定时间（v2.75）',
  `totp_tip`       varchar(255) NOT NULL DEFAULT '' COMMENT '二次验证绑定提示语（登录绑定弹窗展示，v2.76）',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  UNIQUE KEY `uk_wecom_userid` (`wecom_userid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统登录账号';

-- 8. 用户-客户企业权限关联 ---------------------------------------------
CREATE TABLE `user_company_rel` (
  `id`         int unsigned NOT NULL AUTO_INCREMENT,
  `user_id`    int unsigned NOT NULL,
  `company_id` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_company` (`user_id`,`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='账号可访问的客户企业';

-- 2c. 账号-部门多对多（v2.14 部门数据权限，企微一人多部门）----
CREATE TABLE `user_dept_rel` (
  `id`         int unsigned NOT NULL AUTO_INCREMENT,
  `user_id`    int unsigned NOT NULL COMMENT '系统账号ID',
  `dept_id`    int unsigned NOT NULL COMMENT '部门ID org_department.id',
  `is_primary` tinyint NOT NULL DEFAULT 0 COMMENT '1=主部门',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_dept` (`user_id`,`dept_id`),
  KEY `idx_dept` (`dept_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='账号-部门多对多（企微一人多部门）';

-- 2d. 用户级数据范围覆盖（v2.99.36 权限三轴解耦 P2）------------------
-- 缺省回退角色级 sys_role.data_policy，再回退内置默认（core=all / other=self）
CREATE TABLE `sys_user_data_scope` (
  `id`            int unsigned NOT NULL AUTO_INCREMENT,
  `user_id`       int unsigned NOT NULL COMMENT '系统账号ID',
  `scope_group`   varchar(20)  NOT NULL COMMENT 'core 核心资源 | other 其他模块',
  `scope`         varchar(20)  NOT NULL COMMENT 'all | pool | self（core）；all | self（other）',
  `include_pool`  tinyint      NOT NULL DEFAULT 1 COMMENT 'core 组是否含公海',
  `update_by`     int unsigned NOT NULL DEFAULT 0,
  `update_time`   datetime     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_group` (`user_id`,`scope_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户级数据范围覆盖（缺省回退角色策略，v2.99.36）';

-- 9. 角色表 -------------------------------------------------------------
CREATE TABLE `sys_role` (
  `id`          int unsigned NOT NULL AUTO_INCREMENT,
  `role_name`   varchar(64)  NOT NULL,
  `role_code`   varchar(32)  DEFAULT '' COMMENT '角色编码：admin/company/cert_manager/project_officer/finance/viewer',
  `menu_ids`    text COMMENT '菜单权限ID集合（空=不限制，仅 admin）',
  `perms`       text NULL COMMENT '功能权限码 JSON，如 {"cert:create":1,"cert:edit":1}；NULL=不限制（仅 admin），空对象=无写权限（v2.69）',
  `data_policy` text NULL COMMENT '数据权限策略JSON（v2.99.36）：{"core":{"scope":"all","include_pool":1},"other":{"scope":"self"}}；NULL=内置默认（core=all / other=self）',
  `remark`      text,
  `status`      tinyint DEFAULT 1,
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='角色';

-- 10. 系统菜单表 --------------------------------------------------------
CREATE TABLE `sys_menu` (
  `id`         int unsigned NOT NULL AUTO_INCREMENT,
  `parent_id`  int unsigned DEFAULT 0 COMMENT '父菜单ID',
  `menu_name`  varchar(64)  NOT NULL,
  `icon`       varchar(64)  DEFAULT '' COMMENT '图标',
  `path`       varchar(128) DEFAULT '',
  `component`  varchar(128) DEFAULT '',
  `sort`       int DEFAULT 0,
  `status`     tinyint DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统菜单';

-- 11. 推送渠道配置表 ----------------------------------------------------
CREATE TABLE `push_channel` (
  `id`           int unsigned NOT NULL AUTO_INCREMENT,
  `channel_type` varchar(32) NOT NULL COMMENT '渠道类型:sms/email/wecom(群机器人)/wecom_app(应用消息)/mp',
  `channel_name` varchar(64) NOT NULL COMMENT '渠道名称',
  `config_json`  text NOT NULL COMMENT '渠道配置参数json',
  `is_enable`    tinyint NOT NULL DEFAULT 1 COMMENT '是否启用',
  `sort`         int DEFAULT 0,
  `remark`       varchar(255) DEFAULT '',
  `create_time`  datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time`  datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_type` (`channel_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='多消息推送渠道配置';

-- 12. 推送发送日志 ------------------------------------------------------
CREATE TABLE `push_msg_log` (
  `id`             int unsigned NOT NULL AUTO_INCREMENT,
  `company_id`     int unsigned NOT NULL COMMENT '所属客户企业',
  `cert_id`        int unsigned NOT NULL COMMENT '证照ID',
  `biz_type`       varchar(20)  NOT NULL DEFAULT 'secondment' COMMENT '业务类型: secondment借调/证书 / company_cert企业证书',
  `biz_id`         int unsigned NOT NULL DEFAULT 0 COMMENT '第二业务ID：cert_secondment.id 或 company_qualification.id',
  `channel_id`     int unsigned NOT NULL COMMENT '推送渠道id',
  `receiver_phone` varchar(32)  DEFAULT '' COMMENT '接收手机号',
  `receiver_email` varchar(128) DEFAULT '' COMMENT '接收邮箱',
  `receiver_wecom` varchar(128) DEFAULT '' COMMENT '企微接收人',
  `msg_content`    text NOT NULL COMMENT '消息内容',
  `send_status`    tinyint NOT NULL DEFAULT 0 COMMENT '0待发送 1成功 2失败',
  `err_msg`        text COMMENT '失败原因',
  `channel_type`   varchar(16)  DEFAULT '' COMMENT '渠道类型冗余（sms/email/wecom/mp，便于日志筛选）',
  `is_test`        tinyint NOT NULL DEFAULT 0 COMMENT '1=测试推送（与正式推送区分）',
  `cost_ms`        int unsigned NOT NULL DEFAULT 0 COMMENT '发送耗时（毫秒）',
  `create_time`    datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cert`    (`cert_id`),
  KEY `idx_channel` (`channel_id`),
  KEY `idx_test`    (`is_test`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='推送发送日志';

-- 13. 企业接收人配置 ----------------------------------------------------
CREATE TABLE `company_receiver` (
  `id`             int unsigned NOT NULL AUTO_INCREMENT,
  `company_id`     int unsigned NOT NULL DEFAULT 0 COMMENT '所属企业ID；全局接收人为 0',
  `is_global`      tinyint NOT NULL DEFAULT 0 COMMENT '1=全局接收人（接收所有证书通知，不局限于某一企业）',
  `receiver_name`  varchar(64) NOT NULL,
  `receiver_phone` varchar(32)  DEFAULT '',
  `receiver_email` varchar(128) DEFAULT '',
  `receiver_wecom` varchar(500) DEFAULT '' COMMENT '企微userid(竖线分隔多个)/公众号openid（v2.16 支持组织架构多选）',
  `receiver_type`  varchar(32) NOT NULL COMMENT 'agent中介管理员 / client客户对接人',
  `scenes`         varchar(255) NOT NULL DEFAULT '' COMMENT '适用推送场景，逗号分隔；空=全部功能（v2.99.40）',
  `is_enable`      tinyint DEFAULT 1,
  `create_time`    datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_global`  (`is_global`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='消息接收人（含全局接收人）';

-- 14. 证书状态变更日志 --------------------------------------------------
CREATE TABLE `cert_status_log` (
  `id`          int unsigned NOT NULL AUTO_INCREMENT,
  `cert_id`     int unsigned NOT NULL,
  `biz_type`    varchar(16) NOT NULL DEFAULT 'cert' COMMENT '业务类型: cert证书 / secondment借调',
  `secondment_id` int unsigned NOT NULL DEFAULT 0 COMMENT '关联借调记录ID（biz_type=secondment 时有效）',
  `old_status`  tinyint NOT NULL COMMENT '原状态',
  `new_status`  tinyint NOT NULL COMMENT '新状态',
  `operator`    varchar(64) DEFAULT '' COMMENT '操作人',
  `remark`      text COMMENT '变更原因',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cert` (`cert_id`),
  KEY `idx_secondment` (`secondment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='证书/借调状态变更日志';

-- 15. 消息模板表 --------------------------------------------------------
CREATE TABLE `msg_template` (
  `id`            int unsigned NOT NULL AUTO_INCREMENT,
  `scene`         varchar(32) NOT NULL DEFAULT 'cert_expire' COMMENT '提醒场景：cert_expire证书到期 / secondment_approach借调临期 / secondment_expire借调到期 / secondment_renew借调续借',
  `template_type` varchar(32) NOT NULL COMMENT '类型：sms/email/wecom/wecom_app/mp',
  `template_name` varchar(64) NOT NULL,
  `content`       text NOT NULL COMMENT '模板内容，支持{{变量}}',
  `status`        tinyint DEFAULT 1,
  `create_time`   datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time`   datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_scene_type` (`scene`,`template_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='推送消息模板（按场景×渠道组织）';

-- 16. 字典类型表（v2 新增）--------------------------------------------
CREATE TABLE `dict_type` (
  `id`          int unsigned NOT NULL AUTO_INCREMENT,
  `type_code`   varchar(64)  NOT NULL COMMENT '类型编码: cert_category/dept/...',
  `type_name`   varchar(64)  NOT NULL COMMENT '类型名称',
  `module`      varchar(32)  DEFAULT '' COMMENT '所属模块: cert/staff/company/contract/system',
  `remark`      varchar(255) DEFAULT '',
  `status`      tinyint DEFAULT 1,
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`type_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='字典类型';

-- 17. 字典数据表（v2 新增）--------------------------------------------
CREATE TABLE `dict_data` (
  `id`          int unsigned NOT NULL AUTO_INCREMENT,
  `type_code`   varchar(64)  NOT NULL COMMENT '关联 dict_type.type_code',
  `dict_label`  varchar(128) NOT NULL COMMENT '显示名称',
  `dict_value`  varchar(128) NOT NULL COMMENT '存储值',
  `parent_value` varchar(64)  NOT NULL DEFAULT '' COMMENT '上级字典值(cert_major 二级存一级 dict_value)',
  `sort`        int DEFAULT 0,
  `status`      tinyint DEFAULT 1,
  `remark`      varchar(255) DEFAULT '',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_type` (`type_code`),
  KEY `idx_type_parent` (`type_code`,`parent_value`,`status`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='字典数据';

-- 18. 服务合同表（v2 新增）--------------------------------------------
CREATE TABLE `contract` (
  `id`          int unsigned NOT NULL AUTO_INCREMENT,
  `create_by`   int unsigned NOT NULL DEFAULT 0 COMMENT '创建人账号ID（v2.99.36 数据权限）',
  `company_id`  int unsigned NOT NULL COMMENT '客户企业ID',
  `secondment_id` int unsigned NOT NULL DEFAULT 0 COMMENT '关联借调记录ID（0=非借调合同）',
  `contract_no` varchar(64)  DEFAULT '' COMMENT '合同编号',
  `title`       varchar(128) DEFAULT '' COMMENT '合同名称',
  `contract_type` varchar(64) DEFAULT '' COMMENT '合同类型（关联 dict: contract_type）',
  `party_a`     varchar(128) DEFAULT '' COMMENT '甲方（中介）',
  `party_b`     varchar(128) DEFAULT '' COMMENT '乙方（客户）',
  `amount`      decimal(12,2) DEFAULT 0.00 COMMENT '合同金额',
  `sign_date`   date DEFAULT NULL COMMENT '签订日期',
  `start_date`  date DEFAULT NULL COMMENT '生效日期',
  `end_date`    date DEFAULT NULL COMMENT '到期日期',
  `status`      tinyint DEFAULT 1 COMMENT '1执行中 0到期/终止',
  `remark`      text,
  `attach_path` varchar(500) DEFAULT '' COMMENT '合同附件路径',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='中介-客户服务合同';

-- 19. 证书续期记录表（v2 新增）----------------------------------------
CREATE TABLE `cert_renewal` (
  `id`               int unsigned NOT NULL AUTO_INCREMENT,
  `cert_id`          int unsigned NOT NULL COMMENT '证书ID',
  `old_expire_date`  date DEFAULT NULL COMMENT '原到期日',
  `new_expire_date`  date DEFAULT NULL COMMENT '新到期日',
  `renewal_date`     date DEFAULT NULL COMMENT '续期办理日期',
  `operator`         varchar(64) DEFAULT '' COMMENT '办理人',
  `remark`           text,
  `create_time`      datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cert` (`cert_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='证书续期记录';

-- 20. 操作日志表（v2 新增）--------------------------------------------
CREATE TABLE `operation_log` (
  `id`         int unsigned NOT NULL AUTO_INCREMENT,
  `user_id`    int unsigned DEFAULT 0 COMMENT '操作人ID',
  `username`   varchar(64)  DEFAULT '' COMMENT '操作人',
  `module`     varchar(64)  DEFAULT '' COMMENT '模块',
  `action`     varchar(64)  DEFAULT '' COMMENT '动作',
  `method`     varchar(16)  DEFAULT '' COMMENT 'HTTP方法',
  `url`        varchar(255) DEFAULT '' COMMENT '请求URL',
  `params`     text COMMENT '请求参数(JSON)',
  `ip`         varchar(64)  DEFAULT '' COMMENT 'IP',
  `remark`     varchar(255) DEFAULT '',
  `target`      varchar(200) DEFAULT '' COMMENT '操作对象（v2.97）',
  `route`       varchar(160) DEFAULT '' COMMENT '控制器@方法（v2.97）',
  `http_status` smallint unsigned DEFAULT 0 COMMENT 'HTTP状态码（v2.97）',
  `code`        int DEFAULT 0 COMMENT '业务返回码（v2.97）',
  `duration_ms` int unsigned DEFAULT 0 COMMENT '耗时毫秒（v2.97）',
  `user_agent`  varchar(255) DEFAULT '' COMMENT 'User-Agent（v2.97）',
  `device_name` varchar(100) DEFAULT '' COMMENT '设备名称（v2.97）',
  `result_msg`  varchar(255) DEFAULT '' COMMENT '结果说明（v2.97）',
  `status`      tinyint DEFAULT 1 COMMENT '1成功 0失败（v2.97）',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_module` (`module`),
  KEY `idx_create_time` (`create_time`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='操作审计日志';

-- 20.1 用户登录日志（v2.70）-------------------------------------------
CREATE TABLE `login_log` (
  `id`         int unsigned NOT NULL AUTO_INCREMENT,
  `user_id`    int unsigned NOT NULL DEFAULT 0 COMMENT '用户ID（未匹配到账号时为0）',
  `username`   varchar(64) NOT NULL DEFAULT '' COMMENT '登录账号',
  `real_name`  varchar(64) NOT NULL DEFAULT '' COMMENT '姓名',
  `role_code`  varchar(32) NOT NULL DEFAULT '' COMMENT '角色编码',
  `login_type` varchar(16) NOT NULL DEFAULT 'password' COMMENT '登录方式: password账号密码 / wecom企业微信',
  `ip`         varchar(64) NOT NULL DEFAULT '' COMMENT '客户端IP',
  `user_agent` varchar(255) NOT NULL DEFAULT '' COMMENT 'User-Agent',
  `device_id`  varchar(64) NOT NULL DEFAULT '' COMMENT '设备标识（v2.96）',
  `device_name` varchar(100) NOT NULL DEFAULT '' COMMENT '设备名称（v2.96）',
  `login_city` varchar(64) NOT NULL DEFAULT '' COMMENT '登录地归属城市（v2.99.50）',
  `status`     tinyint NOT NULL DEFAULT 2 COMMENT '1成功 2失败 3锁定 4安全拦截',
  `message`    varchar(255) NOT NULL DEFAULT '' COMMENT '结果说明',
  `login_time` datetime DEFAULT NULL COMMENT '登录时间',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_login_time` (`login_time`),
  KEY `idx_username` (`username`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户登录日志（v2.70）';

-- 20.2 登录会话设备（v2.96）--------------------------------------------
CREATE TABLE `sys_login_session` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='登录会话设备（v2.96）';

-- 20.3 受信任设备（v2.96）------------------------------------------------
CREATE TABLE `sys_trusted_device` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '用户ID',
  `token_hash` char(64) NOT NULL DEFAULT '' COMMENT '信任令牌 SHA-256（不存明文）',
  `device_id` varchar(64) NOT NULL DEFAULT '' COMMENT '浏览器设备标识',
  `device_name` varchar(100) NOT NULL DEFAULT '' COMMENT '设备名称（系统/浏览器）',
  `ip` varchar(64) NOT NULL DEFAULT '' COMMENT '信任时IP',
  `city` varchar(64) NOT NULL DEFAULT '' COMMENT '信任时归属城市（v2.99.50）',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='受信任设备（v2.96）';

-- 自定义字段定义：为各业务模块扩展字段（无需改动主表结构）
CREATE TABLE `cert_custom_field` (
  `id`           int unsigned NOT NULL AUTO_INCREMENT,
  `module`       varchar(32)  NOT NULL COMMENT '模块: certificate/staff/company/contract/secondment',
  `field_key`    varchar(64)  NOT NULL COMMENT '字段标识（英文，同模块内唯一）',
  `field_label`  varchar(64)  NOT NULL COMMENT '显示名称',
  `field_type`   varchar(16)  DEFAULT 'text' COMMENT '类型: text/textarea/number/date/datetime/select/radio/checkbox/switch',
  `options`      varchar(500) DEFAULT '' COMMENT '下拉选项，英文逗号分隔',
  `default_value` varchar(255) DEFAULT '' COMMENT '默认值',
  `required`     tinyint DEFAULT 0 COMMENT '是否必填 1是',
  `sort`         int DEFAULT 0 COMMENT '排序（小在前）',
  `status`       tinyint DEFAULT 1 COMMENT '1启用 0停用',
  `remark`       varchar(255) DEFAULT '' COMMENT '备注/帮助提示',
  `create_time`  datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time`  datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_module_key` (`module`,`field_key`),
  KEY `idx_module` (`module`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='自定义字段定义';

-- 自定义字段值（EAV）：按模块+记录ID存放，避免改动业务主表
CREATE TABLE `cert_custom_value` (
  `id`        int unsigned NOT NULL AUTO_INCREMENT,
  `module`    varchar(32) NOT NULL COMMENT '模块',
  `record_id` int unsigned NOT NULL COMMENT '业务记录ID',
  `field_id`  int unsigned NOT NULL COMMENT '字段定义ID',
  `field_key` varchar(64) NOT NULL COMMENT '冗余字段标识，便于导出',
  `value`     text COMMENT '字段值',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rec_field` (`module`,`record_id`,`field_id`),
  KEY `idx_module_rec` (`module`,`record_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='自定义字段值';

-- 证书附件（多附件，v2.32 新增）----------------------------------------
-- 一张证书可挂多个附件；certificate.attach_path 保留并写第一条（兼容导出/列表/H5）
CREATE TABLE `certificate_attach` (
  `id`          int unsigned NOT NULL AUTO_INCREMENT,
  `cert_id`     int unsigned NOT NULL COMMENT '证书ID certificate.id',
  `attach_name` varchar(128) NOT NULL DEFAULT '' COMMENT '附件名称(用户填写)',
  `attach_path` varchar(255) NOT NULL COMMENT '文件路径',
  `file_ext`    varchar(16)  NOT NULL DEFAULT '' COMMENT '扩展名(判预览)',
  `file_size`   int unsigned NOT NULL DEFAULT 0 COMMENT '字节',
  `sort`        int NOT NULL DEFAULT 0 COMMENT '排序',
  `create_by`   int unsigned NOT NULL DEFAULT 0 COMMENT '上传人',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cert` (`cert_id`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='证书附件（多附件，可命名）';

-- 人员附件（多附件，v2.35 新增）----------------------------------------
-- 一名人员可挂多个附件；staff.attach_path 保留并写第一条（兼容列表/导出）
CREATE TABLE `staff_attach` (
  `id`          int unsigned NOT NULL AUTO_INCREMENT,
  `staff_id`    int unsigned NOT NULL COMMENT '人员ID staff.id',
  `attach_name` varchar(128) NOT NULL DEFAULT '' COMMENT '附件名称(用户填写)',
  `attach_path` varchar(255) NOT NULL COMMENT '文件路径',
  `file_ext`    varchar(16)  NOT NULL DEFAULT '' COMMENT '扩展名(判预览)',
  `file_size`   int unsigned NOT NULL DEFAULT 0 COMMENT '字节',
  `sort`        int NOT NULL DEFAULT 0 COMMENT '排序',
  `create_by`   int unsigned NOT NULL DEFAULT 0 COMMENT '上传人',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_staff` (`staff_id`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='人员附件（多附件，可命名）';

-- 客户企业附件（多附件，v2.35 新增）------------------------------------
-- 一家企业可挂多个附件；client_company.attach_path 保留并写第一条（兼容列表/导出）
CREATE TABLE `company_attach` (
  `id`          int unsigned NOT NULL AUTO_INCREMENT,
  `company_id`  int unsigned NOT NULL COMMENT '客户企业ID client_company.id',
  `attach_name` varchar(128) NOT NULL DEFAULT '' COMMENT '附件名称(用户填写)',
  `attach_path` varchar(255) NOT NULL COMMENT '文件路径',
  `file_ext`    varchar(16)  NOT NULL DEFAULT '' COMMENT '扩展名(判预览)',
  `file_size`   int unsigned NOT NULL DEFAULT 0 COMMENT '字节',
  `sort`        int NOT NULL DEFAULT 0 COMMENT '排序',
  `create_by`   int unsigned NOT NULL DEFAULT 0 COMMENT '上传人',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客户企业附件（多附件，可命名）';

-- 客户企业资质 / 安许台账（v2.43 新增）--------------------------------
-- 来源：河南省建筑市场监管公共服务平台公开页面采集（hn_platform）或手工（manual）。
-- 判重：同一企业 + q_type + 证书编号 + 证书名称；安许为 q_type=safety。
CREATE TABLE `company_qualification` (
  `id`          int unsigned NOT NULL AUTO_INCREMENT,
  `company_id`  int unsigned NOT NULL DEFAULT 0 COMMENT '客户企业ID client_company.id',
  `q_type`      varchar(20)  NOT NULL DEFAULT 'qualification' COMMENT 'qualification 资质 / safety 安许',
  `cert_name`   varchar(120) NOT NULL DEFAULT '' COMMENT '证书名称',
  `cert_level`  varchar(50)  NOT NULL DEFAULT '' COMMENT '等级',
  `cert_no`     varchar(80)  NOT NULL DEFAULT '' COMMENT '证书编号',
  `cert_scope`  varchar(255) NOT NULL DEFAULT '' COMMENT '许可范围',
  `issue_org`   varchar(150) NOT NULL DEFAULT '' COMMENT '发证机关',
  `issue_date`  date DEFAULT NULL COMMENT '发证日期',
  `expire_date` date DEFAULT NULL COMMENT '有效期至',
  `source`      varchar(30)  NOT NULL DEFAULT '' COMMENT '来源：hn_platform 河南平台 / manual 手工',
  `source_url`  varchar(500) NOT NULL DEFAULT '' COMMENT '来源页面',
  `fetched_at`  datetime DEFAULT NULL COMMENT '采集时间',
  `remark`      varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
  `attach_path` varchar(255) NOT NULL DEFAULT '' COMMENT '首附件路径（兼容列表/导出）',
  `status`      tinyint NOT NULL DEFAULT 1 COMMENT '1有效 0已删除',
  `create_by`   int unsigned NOT NULL DEFAULT 0 COMMENT '创建人',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`,`status`),
  KEY `idx_company_type` (`company_id`,`q_type`),
  KEY `idx_expire` (`expire_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客户企业资质/安许台账';

-- 企业证书附件子表（v2.94，与 certificate_attach / staff_attach 同构）
CREATE TABLE `company_qualification_attach` (
  `id`              int unsigned NOT NULL AUTO_INCREMENT,
  `qualification_id` int unsigned NOT NULL DEFAULT 0 COMMENT '企业证书ID company_qualification.id',
  `attach_name`     varchar(128) NOT NULL DEFAULT '' COMMENT '附件名称',
  `attach_path`     varchar(255) NOT NULL DEFAULT '' COMMENT '存储路径',
  `file_ext`        varchar(16)  NOT NULL DEFAULT '' COMMENT '扩展名',
  `file_size`       int unsigned NOT NULL DEFAULT 0 COMMENT '文件大小（字节）',
  `sort`            int NOT NULL DEFAULT 0 COMMENT '排序',
  `create_by`       int unsigned NOT NULL DEFAULT 0 COMMENT '上传人',
  `create_time`     datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_qualification` (`qualification_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='企业证书附件';

-- 5b. 费用台账（独立模块，v2.4 新增）-----------------------------------
CREATE TABLE `fee` (
  `id`           int unsigned NOT NULL AUTO_INCREMENT,
  `create_by`    int unsigned NOT NULL DEFAULT 0 COMMENT '创建人账号ID（v2.99.36 数据权限）',
  `company_id`   int unsigned NOT NULL DEFAULT 0 COMMENT '客户企业ID（v2.24：仅当 subject_type=company 时有效）',
  `staff_id`     int unsigned NOT NULL DEFAULT 0 COMMENT '持证人员ID（v2.24：仅当 subject_type=staff 时有效）',
  `subject_type` varchar(16)  NOT NULL DEFAULT 'company' COMMENT '费用归属对象类型 v2.24：company客户企业 / staff持证人员',
  `payer_name`   varchar(128) NOT NULL DEFAULT '' COMMENT '付款方名称 v2.24：付款单付款方（本公司），留空则取系统信息设置的运营主体',
  `source_type`  varchar(16)  NOT NULL DEFAULT '' COMMENT '来源: secondment/contract/other',
  `source_id`    int unsigned NOT NULL DEFAULT 0 COMMENT '关联记录ID',
  `is_auto`      tinyint NOT NULL DEFAULT 0 COMMENT '1=由借调等业务自动生成（只维护这类记录，人工登记的费用不受影响）',
  `fee_name`     varchar(128) NOT NULL DEFAULT '' COMMENT '费用项目/名称',
  `fee_type`     varchar(16)  NOT NULL DEFAULT 'income' COMMENT 'income收入(应收) / expense支出(应付)',
  `amount`       decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '金额',
  `settle_amount`decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '已结算金额（支持部分结算，v2.8）',
  `pay_method`   varchar(32)  NOT NULL DEFAULT '' COMMENT '结算方式（v2.8，兼容保留）',
  `pay_account_id` int unsigned NOT NULL DEFAULT 0 COMMENT '收款账户ID（结算方式，v2.18）',
  `invoice_no`   varchar(64)  NOT NULL DEFAULT '' COMMENT '票据号/发票号（v2.8）',
  `handler`      varchar(32)  NOT NULL DEFAULT '' COMMENT '经办人（v2.8）',
  `occur_date`   date DEFAULT NULL COMMENT '发生日期',
  `plan_date`    date DEFAULT NULL COMMENT '计划收/付日期（v2.8）',
  `settle_status`tinyint NOT NULL DEFAULT 0 COMMENT '0未结算 1已结算',
  `settle_date`  date DEFAULT NULL COMMENT '结算日期',
  `remark`       text COMMENT '备注',
  `create_time`  datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time`  datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_staff` (`staff_id`),
  KEY `idx_subject` (`subject_type`,`company_id`,`staff_id`),
  KEY `idx_source` (`source_type`,`source_id`),
  KEY `idx_settle` (`settle_status`),
  KEY `pay_account_id` (`pay_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='费用台账（独立模块）';

-- 5c. 系统基本信息配置（v2.4 新增）-------------------------------------
CREATE TABLE `system_config` (
  `id`           int unsigned NOT NULL AUTO_INCREMENT,
  `config_key`   varchar(64)  NOT NULL COMMENT '配置键',
  `config_value` text COMMENT '配置值',
  `config_name`  varchar(64)  NOT NULL DEFAULT '' COMMENT '配置名称',
  `config_group` varchar(32)  NOT NULL DEFAULT 'base' COMMENT '分组',
  `remark`       varchar(255) DEFAULT '' COMMENT '说明',
  `create_time`  datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time`  datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_key` (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统基本信息配置';

-- 5d. 内部组织架构（v2.7 新增）------------------------------------------
CREATE TABLE `org_department` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` int unsigned NOT NULL DEFAULT 0 COMMENT '上级部门ID,0为顶级',
  `wecom_dept_id` int DEFAULT NULL COMMENT '企微部门id（v2.12 企微通讯录同步，source=1 时唯一）',
  `wecom_order` int NOT NULL DEFAULT 0 COMMENT '企微部门排序',
  `source` tinyint NOT NULL DEFAULT 0 COMMENT '0=本地维护 1=企微同步',
  `wecom_raw` text COMMENT '企微原始JSON（调试用）',
  `name` varchar(64) NOT NULL DEFAULT '' COMMENT '部门名称',
  `leader` varchar(32) NOT NULL DEFAULT '' COMMENT '负责人',
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `remark` varchar(255) DEFAULT '' COMMENT '备注',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_parent` (`parent_id`),
  UNIQUE KEY `uk_wecom_dept` (`wecom_dept_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='内部组织架构';

-- 5e. 配证任务（v2.7 新增）----------------------------------------------
CREATE TABLE `cert_task` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `publisher_id` int unsigned NOT NULL DEFAULT 0 COMMENT '发布人用户ID',
  `publisher_name` varchar(64) NOT NULL DEFAULT '' COMMENT '发布人姓名',
  `company_id` int unsigned NOT NULL DEFAULT 0 COMMENT '需求企业ID',
  `project_id` int unsigned NOT NULL DEFAULT 0 COMMENT '关联项目ID（v2.27，0=不关联；打通项目需求与配证任务）',
  `title` varchar(128) NOT NULL DEFAULT '' COMMENT '任务标题',
  `description` text COMMENT '任务说明',
  `status` tinyint NOT NULL DEFAULT 0 COMMENT '0草稿 1进行中 2已完成 3已关闭',
  `visible_scope` varchar(64) NOT NULL DEFAULT 'all' COMMENT '可见范围: all全员 / dept指定部门 / user指定人员 / self仅发布人',
  `visible_dept_ids` text COMMENT '可见部门ID(逗号分隔，含子部门)（v2.16）',
  `visible_user_ids` text COMMENT '可见账号ID(逗号分隔)（v2.16）',
  `deadline` date DEFAULT NULL COMMENT '截止日期',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_publisher` (`publisher_id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='配证任务';

CREATE TABLE `cert_task_item` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `cert_name` varchar(128) NOT NULL DEFAULT '' COMMENT '证书专业',
  `category` varchar(64) NOT NULL DEFAULT '' COMMENT '证书类别',
  `cert_type` varchar(64) NOT NULL DEFAULT '' COMMENT '证书类型(dict: cert_major 一级)，转人工时从 project_cert_req 带入',
  `need_count` int NOT NULL DEFAULT 1 COMMENT '需要数量',
  `done_count` int NOT NULL DEFAULT 0 COMMENT '已配数量（项目配证进度的唯一事实源）',
  `remark` varchar(255) DEFAULT '' COMMENT '备注',
  `source_req_id` int unsigned NOT NULL DEFAULT 0 COMMENT '来源项目需求ID project_cert_req.id（转人工配证时写入，用于回写进度）',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_task` (`task_id`),
  KEY `idx_source_req` (`source_req_id`),
  KEY `idx_source` (`task_id`,`source_req_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='配证任务明细';

CREATE TABLE `cert_task_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `task_id` int unsigned NOT NULL DEFAULT 0,
  `item_id` int unsigned NOT NULL DEFAULT 0 COMMENT '0=任务级',
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `user_name` varchar(64) NOT NULL DEFAULT '',
  `content` text COMMENT '进度/备注内容',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_task` (`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='配证任务动态';

-- 5f. 企微通讯录成员镜像（v2.12 新增）-------------------------------------
-- 与 sys_user 解耦：先同步企微成员，再按需「创建系统账号」绑定 bind_user_id
CREATE TABLE `wecom_user` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `wecom_userid` varchar(64) NOT NULL COMMENT '企微 userid（唯一）',
  `name` varchar(64) DEFAULT '' COMMENT '姓名',
  `mobile` varchar(32) DEFAULT '' COMMENT '手机号',
  `email` varchar(128) DEFAULT '' COMMENT '邮箱',
  `avatar` varchar(255) DEFAULT '' COMMENT '头像',
  `position` varchar(128) DEFAULT '' COMMENT '职位',
  `gender` tinyint DEFAULT 0 COMMENT '0未知 1男 2女',
  `dept_ids` text COMMENT '所属企微部门id json数组',
  `is_leader` text COMMENT '是否部门负责人 json数组',
  `status` tinyint DEFAULT 1 COMMENT '1在职 0离职',
  `bind_user_id` int unsigned DEFAULT NULL COMMENT '关联 sys_user.id（已创建系统账号）',
  `last_sync` datetime DEFAULT NULL COMMENT '最近同步时间',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_wecom_userid` (`wecom_userid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='企微通讯录成员镜像';

-- 32. 工作流审批单（v2.13，对接企微 OA 审批 + 内部审批） -----------------
CREATE TABLE `wecom_approval` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `biz_type` varchar(32) NOT NULL DEFAULT 'custom' COMMENT '业务类型:cert_renewal/secondment/fee/contract/custom',
  `biz_id` int unsigned NOT NULL DEFAULT 0 COMMENT '关联业务ID',
  `title` varchar(128) NOT NULL DEFAULT '' COMMENT '审批标题',
  `content` text COMMENT '审批说明',
  `apply_user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '申请人系统账号ID',
  `apply_name` varchar(64) NOT NULL DEFAULT '' COMMENT '申请人姓名',
  `approver_userid` varchar(64) NOT NULL DEFAULT '' COMMENT '审批人企微userid',
  `approver_name` varchar(64) NOT NULL DEFAULT '' COMMENT '审批人姓名',
  `source` tinyint NOT NULL DEFAULT 2 COMMENT '1=企微审批 2=内部审批',
  `status` tinyint NOT NULL DEFAULT 0 COMMENT '0=审批中 1=通过 2=驳回 3=撤销',
  `sp_no` varchar(64) NOT NULL DEFAULT '' COMMENT '企微审批单号',
  `apply_time` datetime DEFAULT NULL COMMENT '提交时间',
  `finish_time` datetime DEFAULT NULL COMMENT '完结时间',
  `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '审批意见',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_apply` (`apply_user_id`),
  KEY `idx_sp_no` (`sp_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工作流审批单';

-- 33. 收款账户（v2.16，多账户可增删改查，支持默认账户） --------------------
CREATE TABLE `pay_account` (
  `id`           int unsigned NOT NULL AUTO_INCREMENT,
  `account_name` varchar(128) NOT NULL DEFAULT '' COMMENT '账户名称/户名',
  `bank_name`    varchar(128) NOT NULL DEFAULT '' COMMENT '开户银行（银行账户填写）',
  `branch_name`  varchar(128) NOT NULL DEFAULT '' COMMENT '开户支行',
  `account_no`   varchar(128) NOT NULL DEFAULT '' COMMENT '账号：银行账号/微信号/支付宝账号/现金编号',
  `account_type` varchar(16)  NOT NULL DEFAULT 'bank_public' COMMENT 'bank_public对公 bank_private对私 wechat微信 alipay支付宝 cash现金 other其他',
  `initial_balance` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '初始余额（v2.18）',
  `currency`     varchar(16)  NOT NULL DEFAULT 'CNY' COMMENT '币种',
  `contact`      varchar(64)  NOT NULL DEFAULT '' COMMENT '联系人',
  `phone`        varchar(32)  NOT NULL DEFAULT '' COMMENT '联系电话',
  `is_default`   tinyint      NOT NULL DEFAULT 0 COMMENT '1=默认收款账户',
  `status`       tinyint      NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `sort`         int          NOT NULL DEFAULT 0 COMMENT '排序（越小越前）',
  `remark`       varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
  `create_by`    int unsigned NOT NULL DEFAULT 0 COMMENT '创建人账号ID',
  `create_time`  datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time`  datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='收款账户（v2.16 创建，v2.18 扩展支付渠道与初始余额）';

-- ========== v2.27 产品四大精髓对齐新增（一人一档 / 多维关联 / 全景可视 / 智能匹配） ==========

-- 34. 项目主体（属地政策与用证需求的载体） ---------------------------------
CREATE TABLE `project` (
  `id`            int unsigned NOT NULL AUTO_INCREMENT,
  `project_no`    varchar(64)  NOT NULL DEFAULT '' COMMENT '项目编号',
  `project_name`  varchar(128) NOT NULL COMMENT '项目名称',
  `client_id`     int unsigned NOT NULL DEFAULT 0 COMMENT '所属客户企业 client_company.id',
  `region_code`   varchar(32)  NOT NULL DEFAULT '' COMMENT '项目属地(区划编码，用于政策匹配)',
  `region_name`   varchar(64)  NOT NULL DEFAULT '' COMMENT '项目属地名称',
  `start_date`    date DEFAULT NULL COMMENT '项目开始日期',
  `end_date`      date DEFAULT NULL COMMENT '项目结束日期',
  `status`        tinyint NOT NULL DEFAULT 0 COMMENT '状态 0筹备 1进行中 2已结束 3已暂停',
  `remark`        text COMMENT '备注',
  `create_by`     int unsigned NOT NULL DEFAULT 0 COMMENT '创建人账号ID',
  `create_time`   datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time`   datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_client` (`client_id`),
  KEY `idx_region` (`region_code`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='项目主体（属地政策与用证需求的载体）';

-- 35. 项目证件需求（智能匹配输入） ----------------------------------------
CREATE TABLE `project_cert_req` (
  `id`            int unsigned NOT NULL AUTO_INCREMENT,
  `project_id`    int unsigned NOT NULL DEFAULT 0,
  `cert_name`     varchar(128) NOT NULL DEFAULT '' COMMENT '需求证书专业',
  `cert_category` varchar(64)  NOT NULL DEFAULT '' COMMENT '证书类别(兼容维度，不迁移)',
  `cert_type`     varchar(64)  NOT NULL DEFAULT '' COMMENT '证书类型(dict: cert_major 一级)，专业级联的父级',
  `need_count`    int NOT NULL DEFAULT 1 COMMENT '需要人数',
  `matched_count` int NOT NULL DEFAULT 0 COMMENT '已匹配人数（由TaskProgressService绝对值重算，禁止+1记账）',
  `require_social` tinyint NOT NULL DEFAULT 0 COMMENT '是否要求社保在缴: 0不要求 1必须 2优先',
  `min_expire_days` int NOT NULL DEFAULT 0 COMMENT '证书剩余有效期下限(天)，防临期',
  `remark`        varchar(255) DEFAULT '',
  `create_time`   datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time`   datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_project` (`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='项目证件需求（智能匹配输入）';

-- 36. 属地政策规则（可配置，支持不同地市） --------------------------------
CREATE TABLE `policy_rule` (
  `id`            int unsigned NOT NULL AUTO_INCREMENT,
  `region_code`   varchar(32)  NOT NULL DEFAULT '' COMMENT '适用属地编码，空=全国通用',
  `region_name`   varchar(64)  NOT NULL DEFAULT '' COMMENT '属地名称',
  `rule_type`     varchar(32)  NOT NULL COMMENT '规则类型: social_match社保匹配/expiry_limit有效期下限/cert_limit证书类型限制',
  `cert_category` varchar(64)  NOT NULL DEFAULT '' COMMENT '适用证书类别(兼容列，v2.36 起与 cert_type 同步)，空=全部',
  `cert_type`     varchar(64)  NOT NULL DEFAULT '' COMMENT '适用证书类型(dict: cert_major 一级)，空=全部',
  `cert_name`     varchar(128) NOT NULL DEFAULT '' COMMENT '适用证书专业，空=全部',
  `rule_expr`     varchar(255) NOT NULL DEFAULT '' COMMENT '规则参数(键值对)，如 min_expire_days=90;require_social=1',
  `severity`      tinyint NOT NULL DEFAULT 1 COMMENT '违规级别 0提示 1警告 2阻断',
  `remark`        varchar(255) DEFAULT '',
  `status`        tinyint NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `create_time`   datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time`   datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_region` (`region_code`),
  KEY `idx_type`   (`rule_type`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='属地政策规则（可配置，支持不同地市）';

-- 37. 证书互斥组（同类证书不可同时占用同一人） ------------------------------
CREATE TABLE `cert_mutual_exclusive` (
  `id`           int unsigned NOT NULL AUTO_INCREMENT,
  `group_name`   varchar(64)  NOT NULL COMMENT '互斥组名称',
  `member_certs` varchar(500) NOT NULL DEFAULT '' COMMENT '组内证书专业，逗号分隔(如: 一级建造师,二级建造师)',
  `remark`       varchar(255) DEFAULT '',
  `status`       tinyint NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `create_time`  datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time`  datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='证书互斥组（同类证书不可同时占用同一人）';

-- 38. 智能匹配与选用留痕 ---------------------------------------------------
CREATE TABLE `match_record` (
  `id`           int unsigned NOT NULL AUTO_INCREMENT,
  `project_id`   int unsigned NOT NULL DEFAULT 0 COMMENT '项目ID',
  `req_id`       int unsigned NOT NULL DEFAULT 0 COMMENT '需求ID project_cert_req.id',
  `staff_id`     int unsigned NOT NULL DEFAULT 0 COMMENT '推荐人员',
  `cert_id`      int unsigned NOT NULL DEFAULT 0 COMMENT '选用证书',
  `score`        decimal(6,2) NOT NULL DEFAULT 0.00 COMMENT '匹配得分',
  `score_detail` text COMMENT '得分明细(JSON)：各维度加分',
  `status`       tinyint NOT NULL DEFAULT 0 COMMENT '0待确认 1已选用 2已放弃',
  `secondment_id` int unsigned NOT NULL DEFAULT 0 COMMENT '选用后生成的借调ID',
  `create_by`    int unsigned NOT NULL DEFAULT 0,
  `create_time`  datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_project` (`project_id`),
  KEY `idx_staff`   (`staff_id`),
  KEY `idx_status`  (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='智能匹配与选用留痕';

-- ========== v2.62 政务网申报进度 ==========
-- 说明：老库由 GovProgressService::ensureTables() 自动建表；此处保证新库初始化即有。
-- 39. 申报主表 -------------------------------------------------------------
CREATE TABLE `gov_progress` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `project_no` varchar(64) NOT NULL DEFAULT '' COMMENT '申报号 projId',
  `password_enc` varchar(512) NOT NULL DEFAULT '' COMMENT '申报密码（AES-256-GCM 加密）',
  `business_type` varchar(20) NOT NULL DEFAULT 'initial' COMMENT '业务类型（字典 gov_business_type）',
  `company_id` int unsigned NOT NULL DEFAULT 0 COMMENT '关联客户企业ID',
  `staff_id` int unsigned NOT NULL DEFAULT 0 COMMENT '关联人员ID',
  `cert_type` varchar(64) NOT NULL DEFAULT '' COMMENT '证书类型（cert_major 一级）',
  `cert_name` varchar(64) NOT NULL DEFAULT '' COMMENT '证书专业（cert_major 二级）',
  `project_name` varchar(255) NOT NULL DEFAULT '' COMMENT '事项名称 PROJECTNAME',
  `service_name` varchar(255) NOT NULL DEFAULT '' COMMENT '服务名称 SERVICENAME',
  `dept_name` varchar(128) NOT NULL DEFAULT '' COMMENT '办理部门 DEPTNAME',
  `apply_name` varchar(128) NOT NULL DEFAULT '' COMMENT '申请人 APPLYNAME',
  `accept_time` datetime DEFAULT NULL COMMENT '受理时间 ACCEPTTIME',
  `promise_time` date DEFAULT NULL COMMENT '承诺办结时间 PROMISEETIME',
  `finish_time` datetime DEFAULT NULL COMMENT '实际办结时间',
  `handle_state` varchar(32) NOT NULL DEFAULT '' COMMENT '平台办理状态 HANDLESTATE',
  `is_finished` tinyint NOT NULL DEFAULT 0 COMMENT '1=已办结（签发完成）',
  `is_suspended` tinyint NOT NULL DEFAULT 0 COMMENT '1=平台挂起（v2.90）',
  `suspend_reason` varchar(2000) NOT NULL DEFAULT '' COMMENT '挂起意见（政务网原文）',
  `suspend_time` datetime DEFAULT NULL COMMENT '挂起开始时间',
  `suspend_notified` tinyint NOT NULL DEFAULT 0 COMMENT '1=本次挂起已发送通知',
  `is_returned` tinyint NOT NULL DEFAULT 0 COMMENT '1=退件/补正待处理（v2.99.40）',
  `return_reason` varchar(2000) NOT NULL DEFAULT '' COMMENT '退件意见（政务网原文）',
  `return_time` datetime DEFAULT NULL COMMENT '退件时间',
  `current_opinion` varchar(2000) NOT NULL DEFAULT '' COMMENT '最近一次办理意见（政务网原文，推送透出）（v2.99.93）',
  `is_archived` tinyint NOT NULL DEFAULT 0 COMMENT '1=已归档（办结自动归档）',
  `archive_time` datetime DEFAULT NULL COMMENT '归档时间',
  `archive_source` varchar(16) NOT NULL DEFAULT '' COMMENT 'auto=办结自动归档 manual=手动归档',
  `current_node` varchar(64) NOT NULL DEFAULT '' COMMENT '当前环节',
  `current_state` tinyint NOT NULL DEFAULT 0 COMMENT '当前环节状态 1已完成 2办理中 3未开始',
  `node_total` int NOT NULL DEFAULT 0 COMMENT '环节总数',
  `node_done` int NOT NULL DEFAULT 0 COMMENT '已完成环节数',
  `progress_percent` tinyint unsigned NOT NULL DEFAULT 0 COMMENT '完成百分比',
  `last_sync_time` datetime DEFAULT NULL COMMENT '最近同步时间',
  `last_sync_status` tinyint NOT NULL DEFAULT 0 COMMENT '0未同步 1成功 2失败',
  `last_sync_error` varchar(500) NOT NULL DEFAULT '' COMMENT '最近同步失败原因',
  `raw_json` mediumtext COMMENT '平台最近一次返回（排障用）',
  `owner_id` int unsigned NOT NULL DEFAULT 0 COMMENT '负责人账号ID',
  `dept_id` int unsigned NOT NULL DEFAULT 0 COMMENT '归属部门ID',
  `create_by` int unsigned NOT NULL DEFAULT 0 COMMENT '创建人账号ID',
  `save_by` int unsigned NOT NULL DEFAULT 0 COMMENT '最近人工保存人账号ID（同步刷新不写入）',
  `save_time` datetime DEFAULT NULL COMMENT '最近人工保存时间（同步刷新不写入）',
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '1启用自动同步 0暂停',
  `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_project_no` (`project_no`),
  KEY `idx_finished` (`is_finished`),
  KEY `idx_archived` (`is_archived`),
  KEY `idx_status` (`status`),
  KEY `idx_promise` (`promise_time`),
  KEY `idx_owner` (`owner_id`),
  KEY `idx_dept` (`dept_id`),
  KEY `idx_company` (`company_id`),
  KEY `idx_staff` (`staff_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='政务网申报进度（v2.63）';

-- 40. 申报环节进度 ---------------------------------------------------------
CREATE TABLE `gov_progress_node` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `progress_id` int unsigned NOT NULL DEFAULT 0 COMMENT 'gov_progress.id',
  `step` int NOT NULL DEFAULT 0 COMMENT '环节序号 STEP',
  `node_name` varchar(64) NOT NULL DEFAULT '' COMMENT '环节名称 NODENAME',
  `state` tinyint NOT NULL DEFAULT 0 COMMENT '1已完成 2办理中 3未开始',
  `start_time` datetime DEFAULT NULL COMMENT '开始时间 STARTTIME',
  `end_time` datetime DEFAULT NULL COMMENT '结束时间 ENDTIME',
  `stay_time` varchar(16) NOT NULL DEFAULT '' COMMENT '停留时长 STAYTIME',
  `opinion` varchar(2000) NOT NULL DEFAULT '' COMMENT '办理意见 OPINION',
  `handler_name` varchar(64) NOT NULL DEFAULT '' COMMENT '经办人 NAME',
  `approver_name` varchar(64) NOT NULL DEFAULT '' COMMENT '审批人 APPROVER',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_progress_sort` (`progress_id`, `sort`),
  KEY `idx_progress` (`progress_id`),
  KEY `idx_progress_step` (`progress_id`, `step`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='政务网申报环节进度（v2.63，完整保留平台节点）';

-- 41. 政务网同步执行记录 ---------------------------------------------------
CREATE TABLE `gov_sync_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `run_at` datetime NOT NULL COMMENT '执行时间',
  `trigger_type` varchar(16) NOT NULL DEFAULT 'schedule' COMMENT 'schedule=定时任务 manual=手动',
  `total` int NOT NULL DEFAULT 0 COMMENT '本次同步总数',
  `ok` int NOT NULL DEFAULT 0 COMMENT '成功数',
  `fail` int NOT NULL DEFAULT 0 COMMENT '失败数',
  `duration_ms` int unsigned NOT NULL DEFAULT 0 COMMENT '耗时（毫秒）',
  `operator_id` int unsigned NOT NULL DEFAULT 0 COMMENT '操作人（手动同步）',
  `message` varchar(500) NOT NULL DEFAULT '' COMMENT '执行摘要',
  `detail` text NULL COMMENT '失败明细 JSON',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_run_at` (`run_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='政务网同步执行记录（v2.63）';

-- 42. 企业 / 个人平台账号密码库 -------------------------------------------
CREATE TABLE `credential_vault` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `platform` varchar(128) NOT NULL DEFAULT '' COMMENT '平台名称，如河南政务服务网',
  `platform_url` varchar(500) NOT NULL DEFAULT '' COMMENT '平台网址',
  `account_type` varchar(20) NOT NULL DEFAULT 'company' COMMENT 'company=企业账号 personal=个人账号',
  `company_id` int unsigned NOT NULL DEFAULT 0 COMMENT '关联客户企业ID',
  `staff_id` int unsigned NOT NULL DEFAULT 0 COMMENT '关联人员ID',
  `account_name` varchar(255) NOT NULL DEFAULT '' COMMENT '登录账号',
  `password_enc` varchar(512) NOT NULL DEFAULT '' COMMENT '登录密码（AES-256-GCM 加密）',
  `remark` varchar(500) NOT NULL DEFAULT '' COMMENT '备注（用途、密保提示等）',
  `owner_id` int unsigned NOT NULL DEFAULT 0 COMMENT '负责人账号ID',
  `dept_id` int unsigned NOT NULL DEFAULT 0 COMMENT '归属部门ID',
  `create_by` int unsigned NOT NULL DEFAULT 0 COMMENT '创建人账号ID',
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_platform` (`platform`),
  KEY `idx_type` (`account_type`),
  KEY `idx_company` (`company_id`),
  KEY `idx_staff` (`staff_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='企业/个人平台账号密码库（v2.63）';

-- 43. Agent 接入密钥（v2.82） -------------------------------------------------
-- 独立 API Key：只存 sha256 哈希，明文仅创建时返回一次；绑定内部账号决定数据范围。
-- 老库无需手工执行：AgentApiService::ensureSchema() 运行时幂等自建同结构表。
CREATE TABLE `sys_agent_key` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL DEFAULT '' COMMENT '密钥名称/用途',
  `key_prefix` varchar(16) NOT NULL DEFAULT '' COMMENT '明文前缀，便于识别',
  `key_hash` char(64) NOT NULL DEFAULT '' COMMENT 'sha256(完整密钥)',
  `scopes` varchar(64) NOT NULL DEFAULT 'read' COMMENT 'read / read,write',
  `owner_user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '绑定账号，决定数据范围',
  `status` tinyint(4) NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `expires_at` datetime NULL DEFAULT NULL COMMENT '过期时间，NULL=永久',
  `last_used_at` datetime NULL DEFAULT NULL,
  `last_used_ip` varchar(64) NOT NULL DEFAULT '',
  `call_count` int unsigned NOT NULL DEFAULT 0,
  `remark` varchar(255) NOT NULL DEFAULT '',
  `create_by` int unsigned NOT NULL DEFAULT 0,
  `create_time` datetime NULL DEFAULT NULL,
  `update_time` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_key_hash` (`key_hash`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Agent 接入密钥（v2.82）';

-- ========================================================================
-- v2.85 考试报名模块（移植中介代报名系统「人员报名」核心）
--   老库无需手工执行：ExamSchemaService::ensureTables() 运行时幂等自建同结构表；
--   本段仅为全新安装的权威 DDL，两者保持同构。
-- ========================================================================

-- 报名-证书分类
CREATE TABLE `exam_cert_category` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='报名-证书分类';

-- 报名-证书类型
CREATE TABLE `exam_cert_type` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='报名-证书类型';

-- 报名-业务动作
CREATE TABLE `exam_biz_action` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='报名-业务动作';

-- 报名-业务组合：证书类型 × 业务动作
CREATE TABLE `exam_biz_config` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='报名-业务组合';

-- 报名-考点
CREATE TABLE `exam_room` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='报名-考点';

-- 报名-批次
CREATE TABLE `exam_batch` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='报名-批次';

-- 报名-考生档案（关联 staff，不污染人员档案）
CREATE TABLE `exam_customer` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='报名-考生档案';

-- 报名-报名工单（核心）
CREATE TABLE `exam_signup` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='报名-报名工单';

-- 报名-工单材料
CREATE TABLE `exam_signup_material` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='报名-工单材料';

-- 报名-审核记录
CREATE TABLE `exam_audit_record` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `signup_id` int unsigned NOT NULL,
  `action` varchar(20) NOT NULL DEFAULT 'pass' COMMENT 'pass通过 reject驳回',
  `stage` int NOT NULL DEFAULT 0 COMMENT '审核时节点',
  `reason` varchar(255) NOT NULL DEFAULT '' COMMENT '原因/意见',
  `auditor` int unsigned NOT NULL DEFAULT 0 COMMENT '审核人',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_signup` (`signup_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='报名-审核记录';

-- 报名-工单流转日志
CREATE TABLE `exam_signup_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `signup_id` int unsigned NOT NULL,
  `from_stage` int NOT NULL DEFAULT 0,
  `to_stage` int NOT NULL DEFAULT 0,
  `note` varchar(255) NOT NULL DEFAULT '',
  `operator` int unsigned NOT NULL DEFAULT 0,
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_signup` (`signup_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='报名-工单流转日志';

-- 报名-成绩查询结果（v2.99.70，与 ExamSchemaService::ddlScoreQuery 同构）
CREATE TABLE `exam_score_query` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='报名-成绩查询结果';

-- ============================================================================
-- v2.99 学历报名模块（5 表，与 EduSchemaService::ensureTables 同构）
-- ============================================================================

-- 学历报名-第三方报名机构
CREATE TABLE `edu_agency` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='学历报名-第三方报名机构';

-- 学历报名-进度流程配置（按报名类型，一套一条，可自定义节点）
CREATE TABLE `edu_flow_config` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='学历报名-进度流程配置';

-- 学历报名-报名工单（核心）
CREATE TABLE `edu_signup` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `signup_no` varchar(32) NOT NULL COMMENT '报名单号（唯一，XL前缀）',
  `signup_type` varchar(30) NOT NULL DEFAULT '' COMMENT '报名类型 dict: edu_signup_type',
  `edu_level` varchar(30) NOT NULL DEFAULT '' COMMENT '学历类型 dict: edu_level',
  `edu_nature` varchar(30) NOT NULL DEFAULT '' COMMENT '学历性质 dict: edu_nature',
  `schooling` varchar(20) NOT NULL DEFAULT '' COMMENT '学制 dict: edu_schooling',
  `staff_id` int unsigned NOT NULL DEFAULT 0 COMMENT '关联人员档案 staff.id，0=未建档',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='学历报名-报名工单';

-- 学历报名-工单附件（支持单条软删，对齐 exam_signup_material）
CREATE TABLE `edu_signup_attach` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='学历报名-工单附件';

-- 学历报名-工单流转日志
CREATE TABLE `edu_signup_log` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='学历报名-工单流转日志';

-- 微信小程序账号绑定（v2.100）
-- 同一 AppID 下 openid 与 user_id 各自唯一；解绑即删除记录。
CREATE TABLE IF NOT EXISTS `sys_miniapp_binding` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='微信小程序账号绑定（v2.100）';

-- 全局回收站（v2.52）：删除时快照主行与关联子行，支持还原；首次写入时运行时自愈建表
CREATE TABLE IF NOT EXISTS `recycle_bin` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `module` varchar(32) NOT NULL COMMENT '模块 code（MODULES）',
  `origin_id` int unsigned NOT NULL DEFAULT 0 COMMENT '原表主键',
  `title` varchar(255) NOT NULL DEFAULT '' COMMENT '展示名（企业名/证书名等）',
  `data` mediumtext NOT NULL COMMENT '主行完整快照 JSON',
  `related` mediumtext NULL COMMENT '关联子行快照 JSON（原样回插）',
  `deleted_by` int unsigned NOT NULL DEFAULT 0,
  `deleted_at` datetime NOT NULL,
  `restored_at` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_module` (`module`, `origin_id`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='全局回收站（v2.52）';

-- 冲突中心体检留痕（v2.56 持久化）：保存最近一次「立即体检」的汇总与明细
CREATE TABLE IF NOT EXISTS `conflict_scan` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `summary` text NULL COMMENT '体检汇总 JSON',
  `items` mediumtext NULL COMMENT '体检明细 JSON',
  `total` int NOT NULL DEFAULT 0 COMMENT '冲突总数',
  `scanned_at` datetime DEFAULT NULL COMMENT '体检时间',
  `create_by` int unsigned NOT NULL DEFAULT 0 COMMENT '触发体检的账号ID',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='冲突中心体检留痕（v2.56 持久化）';

-- 敏感信息明文访问审计（v2.99.47 国密合规 P1）：谁在何时查看/复制了谁的哪条敏感明文，本身不落明文
CREATE TABLE IF NOT EXISTS `sensitive_access_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '访问者 sys_user.id',
  `username` varchar(64) NOT NULL DEFAULT '' COMMENT '访问者姓名/账号快照',
  `role_code` varchar(64) NOT NULL DEFAULT '' COMMENT '访问者角色快照',
  `owner_type` varchar(32) NOT NULL DEFAULT '' COMMENT '对象类型：staff/exam_customer/edu_signup 等',
  `owner_id` int unsigned NOT NULL DEFAULT 0 COMMENT '对象主键',
  `target` varchar(120) NOT NULL DEFAULT '' COMMENT '对象可读标识（如人员姓名，不含敏感值）',
  `field` varchar(40) NOT NULL DEFAULT '' COMMENT '敏感字段名（英文键）',
  `field_label` varchar(40) NOT NULL DEFAULT '' COMMENT '敏感字段中文名',
  `purpose` varchar(16) NOT NULL DEFAULT 'view' COMMENT 'view查看 copy复制 edit编辑回填 export敏感导出 delete删除二次验证',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='敏感信息明文访问审计';

