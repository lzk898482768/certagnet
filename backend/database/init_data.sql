-- ============================================================
-- 初始化数据（角色/菜单/管理员/模板/字典/示例数据）
-- 用法：mysql -u root -p cert_agent < init_data.sql
-- ============================================================
-- 说明：必须显式声明 utf8mb4，否则消息模板里的 emoji（🚨）会因 4 字节字符导入失败
-- 本文件为唯一初始化入口，与 init.sql 配套使用。
SET NAMES utf8mb4;

-- 1. 角色（v2.69：单角色岗位分工；operator 已从蓝图移除）
INSERT INTO `sys_role` (`id`,`role_name`,`role_code`,`menu_ids`,`remark`) VALUES
(1,'超级管理员','admin','','系统超级管理员，拥有全部功能与数据权限'),
(2,'企业查看者','company','1,2,3,31,57,11,56,50,12,5,51,26,6','客户企业账号，仅查看本企业相关数据（只读）');

-- 2. 系统菜单
-- 菜单顺序说明（v2.8）：顶级排序为 一键查询中心(1) > 配证任务(2) > 工作台(3) >
--   证照管理(4) > 档案管理(5) > 推送中心(6) > 系统管理(7)
INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`) VALUES
(1,0,'工作台','Odometer','','',3,1),
(2,1,'首页总览','HomeFilled','/dashboard','dashboard/index',1,1),
(3,1,'统计报表','DataAnalysis','/report','report/index',2,1),
(4,0,'证照管理','Postcard','','',4,1),
(5,4,'证照台账','Tickets','/certificate','certificate/index',1,1),
(26,4,'证书借调','Switch','/secondment','secondment/index',2,1),
(6,14,'提醒中心','AlarmClock','/alert','alert/index',1,1),
(7,4,'续期记录','Refresh','/cert-renewal','certRenewal/index',4,1),
(8,4,'状态变更日志','Document','/cert-status-log','certStatusLog/index',5,1),
(29,0,'费用管理','Money','','',6,1),
(9,29,'费用台账','Money','/fee','fee/index',1,1),
(10,0,'档案管理','FolderOpened','','',5,1),
(11,10,'企业客户','OfficeBuilding','/company','company/index',1,1),
(12,10,'持证人员档案','User','/staff','staff/index',2,1),
(13,10,'服务合同管理','Files','/contract','contract/index',3,1),
(14,0,'推送中心','Bell','','',7,1),
(15,14,'推送渠道管理','Connection','/push-channel','pushChannel/index',1,1),
(16,14,'消息模板管理','ChatDotRound','/msg-template','msgTemplate/index',2,1),
(17,14,'企业接收人','Avatar','/receiver','receiver/index',3,1),
(18,14,'推送消息日志','List','/push-log','pushLog/index',4,1),
(19,0,'系统管理','Setting','','',8,1),
-- 系统管理下：组织架构(1) > 菜单管理(2) > 字典管理(3) > 自定义字段(4) >
--             提醒规则设置(5) > 系统信息设置(6) > 操作日志(7)
-- 组织架构为目录，下挂 部门管理 / 账号权限管理 / 角色管理（账号、角色已并入组织架构）
(20,19,'提醒规则设置','Timer','/alert-config','alertConfig/index',5,1),
(21,32,'账号权限管理','Key','/user','user/index',2,1),
(22,32,'角色管理','UserFilled','/role','role/index',3,1),
(23,19,'菜单管理','Menu','/menu','menu/index',2,1),
(24,19,'字典管理','Collection','/dict','dict/index',3,1),
(25,19,'操作日志','Memo','/operation-log','operationLog/index',7,1),
(27,19,'自定义字段','EditPen','/custom-field','custom-field/index',4,1),
(28,19,'系统信息设置','Setting','/system-info','system-info/index',6,1),
(31,0,'一键查询中心','Search','/search-center','search-center/index',1,1),
(32,19,'组织架构','Share','','',1,1),
(33,0,'配证任务','Tickets','/cert-task','cert-task/index',2,1),
(34,32,'部门管理','OfficeBuilding','/org-dept','org-dept/index',1,1);

-- 2b. v2.7 菜单：组织架构 / 配证任务
-- v2.8：组织架构升级为目录（不再直接指向页面），下设 部门管理 / 账号权限管理 / 角色管理
INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`)
VALUES (32,19,'组织架构','Share','','',1,1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), menu_name=VALUES(menu_name),
  icon=VALUES(icon), path=VALUES(path), component=VALUES(component), sort=VALUES(sort);

INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`)
VALUES (34,32,'部门管理','OfficeBuilding','/org-dept','org-dept/index',1,1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), menu_name=VALUES(menu_name),
  icon=VALUES(icon), path=VALUES(path), component=VALUES(component), sort=VALUES(sort);

INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`)
VALUES (33,0,'配证任务','Tickets','/cert-task','cert-task/index',2,1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), menu_name=VALUES(menu_name),
  icon=VALUES(icon), path=VALUES(path), component=VALUES(component), sort=VALUES(sort);

-- 3. 中介管理员账号（密码：admin123，md5）
INSERT INTO `sys_user` (`username`,`password`,`real_name`,`role_id`,`status`) VALUES
('admin', MD5('admin123'), '系统管理员', 1, 1);

-- 4. 默认消息模板（按「提醒场景 × 渠道」组织，scene 维度自 v2.17 起）
INSERT INTO `msg_template` (`scene`,`template_type`,`template_name`,`content`) VALUES
-- 证书到期（cert_expire）
('cert_expire','sms','证书到期短信','【证书预警】{{company_name}}：{{staff_name}}的{{cert_name}}({{cert_no}})剩余{{remain_days}}天到期，请及时处理。'),
('cert_expire','email','证书到期邮件','尊敬的{{company_name}}：持证人员{{staff_name}}的{{cert_name}}(编号{{cert_no}})将于{{expire_date}}到期，剩余{{remain_days}}天，请尽快办理续期。'),
('cert_expire','wecom','企微群通知','🚨 证书到期预警：{{company_name}} - {{staff_name}} - {{cert_name}}({{cert_no}})，剩余{{remain_days}}天到期。'),
('cert_expire','wecom_app','企业微信应用消息','🚨 证书到期预警：{{company_name}} - {{staff_name}} - {{cert_name}}({{cert_no}})，剩余{{remain_days}}天到期。'),
('cert_expire','mp','公众号模板消息','您关注的证书{{cert_name}}({{cert_no}})剩余{{remain_days}}天到期，请及时处理。'),
-- 借调临期（secondment_approach）
('secondment_approach','sms','借调到期短信','【借调到期提醒】{{company_name}}：{{staff_name}}借用的{{cert_name}}({{cert_no}})将于{{expire_date}}到期（剩余{{remain_days}}天），请及时处理归还或续借。'),
('secondment_approach','email','借调到期邮件','尊敬的{{company_name}}：您借调的{{staff_name}}持有的{{cert_name}}(编号{{cert_no}})将于{{expire_date}}到期，剩余{{remain_days}}天，请提前安排归还或续借。'),
('secondment_approach','wecom','借调临期群通知','🚨 借调到期提醒：{{company_name}} - {{staff_name}} - {{cert_name}}({{cert_no}})，剩余{{remain_days}}天到期，请安排归还或续借。'),
('secondment_approach','wecom_app','借调临期应用消息','🚨 借调到期提醒：{{company_name}} - {{staff_name}} - {{cert_name}}({{cert_no}})，剩余{{remain_days}}天到期，请安排归还或续借。'),
('secondment_approach','mp','借调临期公众号','您关注的借调证书{{cert_name}}({{cert_no}})剩余{{remain_days}}天到期，请及时处理归还或续借。'),
-- 借调到期（secondment_expire）
('secondment_expire','sms','借调已到期短信','【借调已到期】{{company_name}}：{{staff_name}}借用的{{cert_name}}({{cert_no}})已于{{expire_date}}到期，请尽快归还或办理续借。'),
('secondment_expire','email','借调已到期邮件','尊敬的{{company_name}}：您借调的{{staff_name}}持有的{{cert_name}}(编号{{cert_no}})已于{{expire_date}}到期，请尽快归还或办理续借。'),
('secondment_expire','wecom','借调到期群通知','🚨 借调已到期：{{company_name}} - {{staff_name}} - {{cert_name}}({{cert_no}})，已于{{expire_date}}到期，请归还或续借。'),
('secondment_expire','wecom_app','借调到期应用消息','🚨 借调已到期：{{company_name}} - {{staff_name}} - {{cert_name}}({{cert_no}})，已于{{expire_date}}到期，请归还或续借。'),
('secondment_expire','mp','借调到期公众号','您关注的借调证书{{cert_name}}({{cert_no}})已于{{expire_date}}到期，请尽快归还或续借。'),
-- 借调续借（secondment_renew）
('secondment_renew','sms','借调续期短信','【借调续期成功】{{company_name}}：{{staff_name}}借用的{{cert_name}}({{cert_no}})借期已延长至{{expire_date}}，请知悉。'),
('secondment_renew','email','借调续期邮件','尊敬的{{company_name}}：您借调的{{staff_name}}持有的{{cert_name}}(编号{{cert_no}})借期已延长至{{expire_date}}，特此通知。'),
('secondment_renew','wecom','借调续期群通知','✅ 借调续期通知：{{company_name}} - {{staff_name}} - {{cert_name}}({{cert_no}})，借期已延长至{{expire_date}}。'),
('secondment_renew','wecom_app','借调续期应用消息','✅ 借调续期通知：{{company_name}} - {{staff_name}} - {{cert_name}}({{cert_no}})，借期已延长至{{expire_date}}。'),
('secondment_renew','mp','借调续期公众号','您关注的借调证书{{cert_name}}({{cert_no}})借期已延长至{{expire_date}}。');

-- 5. 字典类型
-- v2.36：证书类别(cert_category)已退役——与 cert_major 二级字典一级（证书类型）语义重复，
-- 系统内调用点已全部改用证书类型/专业，老库由 DictCatalog::ensure() 自动清理该字典。
INSERT INTO `dict_type` (`type_code`,`type_name`,`module`,`remark`) VALUES
('priority','优先级','cert','证书紧急程度'),
('fee_type','费用类型','cert','新办/续期/复审等'),
('dept','部门','staff','人员所属部门'),
('position','岗位','staff','人员岗位'),
('industry','所属行业','company','客户企业所属行业'),
('company_scale','企业规模','company','客户企业规模'),
('contract_type','合同类型','contract','服务合同类型');

-- 6. 字典数据
INSERT INTO `dict_data` (`type_code`,`dict_label`,`dict_value`,`sort`) VALUES
('dept','工程部','工程部',1),
('dept','安全部','安全部',2),
('dept','经营部','经营部',3),
('priority','普通','普通',1),
('priority','重要','重要',2),
('priority','紧急','紧急',3),
('fee_type','新办','新办',1),
('fee_type','续期','续期',2),
('fee_type','复审','复审',3),
('fee_type','转注','转注',4),
('fee_type','其他','其他',5),
('position','项目经理','项目经理',1),
('position','技术负责人','技术负责人',2),
('position','安全员','安全员',3),
('position','施工员','施工员',4),
('position','质量员','质量员',5),
('position','材料员','材料员',6),
('position','资料员','资料员',7),
('industry','建筑工程','建筑工程',1),
('industry','市政工程','市政工程',2),
('industry','装饰装修','装饰装修',3),
('industry','机电安装','机电安装',4),
('industry','房地产开发','房地产开发',5),
('industry','其他','其他',6),
('company_scale','大型','大型',1),
('company_scale','中型','中型',2),
('company_scale','小型','小型',3),
('contract_type','证书服务','证书服务',1),
('contract_type','资质代办','资质代办',2),
('contract_type','继续教育','继续教育',3),
('contract_type','咨询服务','咨询服务',4),
('contract_type','其他','其他',5);

-- 7. 示例客户企业 + 人员 + 证书（便于演示）
INSERT INTO `client_company` (`company_name`,`short_name`,`credit_code`,`contact`,`contact_phone`,`status`) VALUES
('示例建设有限公司','示例建设','91110000XXXXXXXX01','王经理','13800000001',1),
('宏远建筑工程公司','宏远建筑','91110000XXXXXXXX02','李总','13800000002',1);

INSERT INTO `staff` (`staff_name`,`id_card`,`phone`,`email`,`dept`,`position`,`status`) VALUES
('张三','110101199001011234','13900000001','zhangsan@example.com','工程部','项目经理',1),
('李四','110101199002022345','13900000002','lisi@example.com','安全部','安全员',1);

INSERT INTO `staff_company_rel` (`staff_id`,`company_id`,`entry_date`) VALUES
(1,1,'2024-01-01'),(2,1,'2024-01-01'),(1,2,'2024-03-01');

-- 证书只属于人员，不再写死归属企业
INSERT INTO `certificate` (`staff_id`,`category`,`cert_name`,`cert_no`,`issue_date`,`expire_date`,`issue_org`,`priority`,`cert_status`) VALUES
(1,'二级建造师','二级建造师','JSZ-2024-0001','2024-01-10','2026-12-31','省住建厅','重要',1),
(2,'安全员C证','安全员C证','AQC-2024-0002','2024-02-10','2026-11-15','省住建厅','普通',1),
(1,'一级建造师','一级建造师','JSZ-2023-0003','2023-05-10','2025-05-09','住建部','紧急',1);

-- 借调记录：企业借调人员证书（带期限、费用）
INSERT INTO `cert_secondment` (`cert_id`,`staff_id`,`company_id`,`start_date`,`end_date`,`fee_amount`,`fee_period`,`status`,`remark`) VALUES
(1,1,1,'2024-01-10','2025-12-31',12000.00,'年',1,'示例建设借调张三二级建造师'),
(2,2,1,'2024-02-10','2025-11-15',6000.00,'年',1,'示例建设借调李四安全员C证'),
(3,1,2,'2023-05-10','2025-05-09',30000.00,'一次性',1,'宏远建设借调张三一级建造师');

INSERT INTO `company_alert_config` (`company_id`,`alert_nodes`,`alert_ways`,`default_priority`) VALUES
(1,'["1year","6month","3month","1month","15day","7day","3day","1day"]','["site","sms","wecom","email","mp"]','普通'),
(2,'["3month","1month","15day","7day","3day"]','["site","wecom"]','普通');

INSERT INTO `user_company_rel` (`user_id`,`company_id`) VALUES (1,1),(1,2);

-- 6b. 系统基本信息配置默认值（v2.4）
INSERT IGNORE INTO `system_config` (`config_key`,`config_value`,`config_name`,`config_group`,`remark`) VALUES
('sys_name','证照到期管理系统','系统名称','base','系统标题/浏览器标题'),
('sys_short','证照管理','系统简称','base','侧边栏/登录页简称'),
('sys_logo','',           '系统Logo','base','Logo图片地址，留空用文字'),
('sys_company','',        '运营主体','base','中介/猎头公司名称'),
('sys_contact','',         '联系方式','base','客服电话/微信'),
('sys_copyright','Copyright © 2026','版权信息','base','页脚版权'),
('sys_bei_an','',         '备案号','base','ICP备案号');

-- 7. 系统信息设置菜单（系统管理 parent_id=19）
INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`)
VALUES (28,19,'系统信息设置','Setting','/system-info','system-info/index',6,1)
ON DUPLICATE KEY UPDATE menu_name=VALUES(menu_name), path=VALUES(path), component=VALUES(component), sort=VALUES(sort);

-- 7b. 一键查询中心：v2.8 升级为顶级菜单并置顶（原挂在工作台下）
INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`)
VALUES (31,0,'一键查询中心','Search','/search-center','search-center/index',1,1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), menu_name=VALUES(menu_name),
  icon=VALUES(icon), path=VALUES(path), component=VALUES(component), sort=VALUES(sort);

-- 7c. 社保状态 / 注册类型字典（v2.6）
INSERT IGNORE INTO `dict_type` (`type_code`,`type_name`,`status`,`remark`) VALUES
('social_status','社保状态',1,'证书社保缴纳状态'),
('register_type','注册类型',1,'建造师注册轨迹类型');

INSERT IGNORE INTO `dict_data` (`type_code`,`dict_label`,`dict_value`,`sort`,`status`) VALUES
('social_status','未知','0',1,1),
('social_status','在缴','1',2,1),
('social_status','停缴','2',3,1),
('social_status','未缴纳','3',4,1),
('register_type','初始注册','初始注册',1,1),
('register_type','变更注册','变更注册',2,1),
('register_type','延续注册','延续注册',3,1),
('register_type','增项注册','增项注册',4,1),
('register_type','重新注册','重新注册',5,1);

-- 8. v2.12 企微模块：菜单（35 企微登录配置 / 36 企微通讯录）--------------
-- 企微登录配置：挂在「系统管理」(19) 下，排序 7
INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`)
VALUES (35,19,'企微登录配置','Connection','/wecom-config','wecom-config/index',7,1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), menu_name=VALUES(menu_name),
  icon=VALUES(icon), path=VALUES(path), component=VALUES(component), sort=VALUES(sort);

-- 企微通讯录：挂在「组织架构」(32) 下，排序 2
INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`)
VALUES (36,32,'企微通讯录','Share','/wecom-org','wecom-org/index',2,1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), menu_name=VALUES(menu_name),
  icon=VALUES(icon), path=VALUES(path), component=VALUES(component), sort=VALUES(sort);

-- v2.13 审批管理：挂在「组织架构」(32) 下，排序 3
INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`)
VALUES (37,32,'审批管理','DocumentChecked','/approval','approval/index',3,1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), menu_name=VALUES(menu_name),
  icon=VALUES(icon), path=VALUES(path), component=VALUES(component), sort=VALUES(sort);

-- 8b. v2.12 默认「企微应用消息」推送渠道（默认禁用）
-- 复用「企微登录配置」里的 CorpID / AgentID / Secret，按企微 userid 精准推送
-- 说明：push_channel.channel_type 无唯一键，故用派生表 + NOT EXISTS 防重复
INSERT INTO `push_channel` (`channel_type`,`channel_name`,`config_json`,`is_enable`,`sort`,`remark`)
SELECT * FROM (SELECT
  'wecom_app' AS channel_type,'企业微信应用消息' AS channel_name,'{}' AS config_json,0 AS is_enable,5 AS sort,
  '复用系统「企微登录配置」的 CorpID/AgentID/Secret，按企微 userid 精准推送' AS remark
) AS tmp
WHERE NOT EXISTS (SELECT 1 FROM `push_channel` WHERE `channel_type`='wecom_app');

-- 9. v2.16 收款账户菜单：挂在「费用管理」(29) 下，排序 3
--    v2.44 起与 54 重复（54 为权威实现，且挂回权威分组 52），新库直接播种为停用
INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`)
VALUES (38,29,'收款账户','CreditCard','/pay-account','pay-account/index',3,0)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), menu_name=VALUES(menu_name),
  icon=VALUES(icon), path=VALUES(path), component=VALUES(component), sort=VALUES(sort);

-- ========== v2.27 产品对齐：属地政策规则 + 证书互斥组（种子数据） ==========
-- 说明：以下为通用示例规则，管理员可在「政策规则」页按属地自由增删改。
INSERT INTO `policy_rule` (`region_code`,`region_name`,`rule_type`,`cert_category`,`cert_name`,`rule_expr`,`severity`,`remark`,`status`) VALUES
('','全国通用','expiry_limit','','','min_expire_days=90',1,'通用：证书剩余有效期不足 90 天时提示，避免临期配证',1),
('','全国通用','social_match','','','require_social=1',0,'通用：项目明确要求社保在缴时不满足则提示',1);

INSERT INTO `cert_mutual_exclusive` (`group_name`,`member_certs`,`remark`,`status`) VALUES
('建造师等级互斥','一级建造师,二级建造师','同一人员不可同时以一级/二级建造师身份占用同一家企业',1);

-- 脱敏开关（1=开启脱敏，身份证/证书编号/电话在接口返回时打码）
INSERT INTO `system_config` (`config_key`,`config_value`,`remark`) VALUES
('mask_sensitive','1','敏感数据脱敏开关：1开启（身份证/证书编号/手机号打码），0关闭（仅管理员可见明文）');

-- v2.96：登录安全默认值（后台「系统信息设置 → 数据安全」可修改）
INSERT IGNORE INTO `system_config` (`config_key`,`config_value`,`config_name`,`config_group`,`remark`) VALUES
('login_token_ttl_hours','3','登录超时（小时）','security','登录 token 有效期，默认 3 小时，范围 1-720'),
('totp_trust_days','3','信任设备有效期（天）','security','勾选「信任此设备」后免二次验证的有效期，默认 3 天，范围 1-365');

-- v2.97：脱敏关闭后允许查看明文的角色（JSON 数组；超级管理员始终可见）
INSERT IGNORE INTO `system_config` (`config_key`,`config_value`,`config_name`,`config_group`,`remark`) VALUES
('mask_plain_roles','["admin"]','可查看明文的角色','security','脱敏关闭后允许查看完整敏感信息的角色编码，超管始终可见');

-- ========== v2.27 产品对齐：新增菜单（项目管理 / 智能匹配 / 冲突中心 / 属地政策） ==========
INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`)
VALUES (40,0,'项目与配证','Briefcase','','',5,1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), menu_name=VALUES(menu_name),
  icon=VALUES(icon), path=VALUES(path), component=VALUES(component), sort=VALUES(sort);

INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`)
VALUES (41,40,'项目管理','FolderOpened','/project','project/index',1,1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), menu_name=VALUES(menu_name),
  icon=VALUES(icon), path=VALUES(path), component=VALUES(component), sort=VALUES(sort);

INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`)
VALUES (42,40,'智能匹配','Aim','/match','match/index',2,1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), menu_name=VALUES(menu_name),
  icon=VALUES(icon), path=VALUES(path), component=VALUES(component), sort=VALUES(sort);

INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`)
VALUES (43,40,'冲突中心','WarningFilled','/conflict','conflict/index',3,1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), menu_name=VALUES(menu_name),
  icon=VALUES(icon), path=VALUES(path), component=VALUES(component), sort=VALUES(sort);

INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`)
VALUES (44,40,'属地政策规则','SetUp','/policy-rule','policy-rule/index',4,1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), menu_name=VALUES(menu_name),
  icon=VALUES(icon), path=VALUES(path), component=VALUES(component), sort=VALUES(sort);

-- 说明：sys_role.perms 为 NULL 表示不限制功能，管理员角色默认可见全部新增菜单；
--       若为非 NULL 的角色需授权，可在此追加 INSERT INTO sys_role_menu ...

-- ========== v2.28 菜单信息架构重构（以人为锚点组织，6 大分组） ==========
-- 设计原则：
--   01 工作台：全局视角（含一键查询）
--   02 人员与证书：一人一档 + 证照资产（人 → 证 → 企业/合同）
--   03 项目与配证：需求 → 匹配 → 冲突 → 政策（智能匹配主战场）
--   04 借调与提醒：证书实际占用与到期生命周期
--   05 费用与收款：商业化闭环
--   06 推送中心：通知渠道与系统治理
-- 说明：全部使用 ON DUPLICATE KEY UPDATE，老库执行后同样生效（幂等）。

-- 01 工作台（原有 1，扩容为含查询入口）
INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`) VALUES
(1,0,'工作台','Odometer','','',1,1)
ON DUPLICATE KEY UPDATE menu_name=VALUES(menu_name), icon=VALUES(icon), sort=VALUES(sort);

-- 02 人员与证书
INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`) VALUES
(50,0,'人员与证书','UserFilled','','',2,1)
ON DUPLICATE KEY UPDATE menu_name=VALUES(menu_name), icon=VALUES(icon), sort=VALUES(sort);

-- 03 项目与配证（v2.27 的 40 组升级并扩容）
INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`) VALUES
(40,0,'项目与配证','Briefcase','','',3,1)
ON DUPLICATE KEY UPDATE menu_name=VALUES(menu_name), icon=VALUES(icon), sort=VALUES(sort);

-- 04 借调与提醒
INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`) VALUES
(51,0,'借调与提醒','Switch','','',4,1)
ON DUPLICATE KEY UPDATE menu_name=VALUES(menu_name), icon=VALUES(icon), sort=VALUES(sort);

-- 05 费用与收款
INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`) VALUES
(52,0,'费用与收款','Money','','',5,1)
ON DUPLICATE KEY UPDATE menu_name=VALUES(menu_name), icon=VALUES(icon), sort=VALUES(sort);

-- 06 推送中心
INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`) VALUES
(14,0,'推送中心','Setting','','',6,1)
ON DUPLICATE KEY UPDATE menu_name=VALUES(menu_name), icon=VALUES(icon), sort=VALUES(sort);

-- ---------- 01 工作台 ----------
INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`) VALUES
(2,1,'首页总览','HomeFilled','/dashboard','dashboard/index',1,1),
(3,1,'统计报表','DataAnalysis','/report','report/index',2,1),
(31,1,'一键查询中心','Search','/search-center','search-center/index',3,1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), menu_name=VALUES(menu_name), icon=VALUES(icon),
  path=VALUES(path), component=VALUES(component), sort=VALUES(sort);

-- ---------- 02 人员与证书 ----------
-- 人员全景档案（v2.27 页面正式挂菜单，不再只能从列表进入）
INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`) VALUES
(12,50,'持证人员档案','User','/staff','staff/index',1,1),
(5,50,'证照台账','Tickets','/certificate','certificate/index',3,1),
(11,50,'企业客户','OfficeBuilding','/company','company/index',4,1),
(13,50,'服务合同管理','Files','/contract','contract/index',5,1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), menu_name=VALUES(menu_name), icon=VALUES(icon),
  path=VALUES(path), component=VALUES(component), sort=VALUES(sort);

-- ---------- 03 项目与配证 ----------
INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`) VALUES
(41,40,'项目管理','FolderOpened','/project','project/index',1,1),
(33,40,'配证任务','Tickets','/cert-task','cert-task/index',2,1),
(42,40,'智能匹配','Aim','/match','match/index',3,1),
(43,40,'冲突中心','WarningFilled','/conflict','conflict/index',4,1),
(44,40,'属地政策规则','SetUp','/policy-rule','policy-rule/index',5,1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), menu_name=VALUES(menu_name), icon=VALUES(icon),
  path=VALUES(path), component=VALUES(component), sort=VALUES(sort);

-- ---------- 04 借调与提醒 ----------
INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`) VALUES
(26,51,'证书借调','Switch','/secondment','secondment/index',1,1),
(6,14,'提醒中心','AlarmClock','/alert','alert/index',1,1),
(7,51,'续期记录','Refresh','/cert-renewal','certRenewal/index',3,1),
(8,51,'状态变更日志','Document','/cert-status-log','certStatusLog/index',3,1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), menu_name=VALUES(menu_name), icon=VALUES(icon),
  path=VALUES(path), component=VALUES(component), sort=VALUES(sort);

-- ---------- 05 费用与收款 ----------
INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`) VALUES
(9,52,'费用台账','Money','/fee','fee/index',1,1),
(30,52,'费用统计','Histogram','/fee-stat','fee/index',2,1),
(54,52,'收款账户','Wallet','/pay-account','payAccount/index',3,1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), menu_name=VALUES(menu_name), icon=VALUES(icon),
  path=VALUES(path), component=VALUES(component), sort=VALUES(sort);

-- ---------- 06 推送中心 ----------
-- v2.73：「到期提醒中心」更名「提醒中心」并移入推送中心；「推送与系统」更名「推送中心」
INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`) VALUES
(6,14,'提醒中心','AlarmClock','/alert','alert/index',1,1),
(15,14,'推送渠道管理','Connection','/push-channel','pushChannel/index',2,1),
(16,14,'消息模板管理','ChatDotRound','/msg-template','msgTemplate/index',3,1),
(17,14,'企业接收人','Avatar','/receiver','receiver/index',4,1),
(18,14,'推送消息日志','List','/push-log','pushLog/index',5,1),
(20,14,'提醒规则设置','Timer','/alert-config','alertConfig/index',6,1),
(19,14,'系统管理','Setting','','',7,1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), menu_name=VALUES(menu_name), icon=VALUES(icon),
  path=VALUES(path), component=VALUES(component), sort=VALUES(sort);

-- 系统管理子项（原挂在 19 下，归属不变）
INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`) VALUES
(32,19,'组织架构','Share','','',1,1),
(34,32,'部门管理','OfficeBuilding','/org-dept','org-dept/index',1,1),
(21,32,'账号权限管理','Key','/user','user/index',2,1),
(22,32,'角色管理','UserFilled','/role','role/index',3,1),
(23,19,'菜单管理','Menu','/menu','menu/index',2,1),
(24,19,'字典管理','Collection','/dict','dict/index',3,1),
(27,19,'自定义字段','EditPen','/custom-field','custom-field/index',4,1),
(28,19,'系统信息设置','Setting','/system-info','system-info/index',5,1),
(25,19,'操作日志','Memo','/operation-log','operationLog/index',8,1),
(61,19,'登录日志','Tickets','/login-log','loginLog/index',9,1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), menu_name=VALUES(menu_name), icon=VALUES(icon),
  path=VALUES(path), component=VALUES(component), sort=VALUES(sort);

-- 停用已被合并的旧顶级分组（其子项已在上面重新挂载到新分组下）
UPDATE `sys_menu` SET `status` = 0 WHERE `id` IN (4,10,29);

-- ========== v2.29 新增模块字典（项目/匹配/冲突/政策） ==========
INSERT INTO `dict_type` (`type_code`,`type_name`,`module`,`remark`) VALUES
('project_status','项目状态','project','筹备/进行中/已结束/已暂停'),
('project_require_social','项目社保要求','project','不要求/必须/优先'),
('policy_rule_type','政策规则类型','policy','社保匹配/有效期下限/证书类型限制'),
('policy_severity','政策违规级别','policy','提示/警告/阻断'),
('conflict_type','冲突类型','conflict','占用/人员/互斥/政策'),
('match_status','匹配状态','match','待确认/已选用/已放弃')
ON DUPLICATE KEY UPDATE type_name=VALUES(type_name), module=VALUES(module), remark=VALUES(remark);

INSERT INTO `dict_data` (`type_code`,`dict_label`,`dict_value`,`sort`) VALUES
('project_status','筹备','0',1),('project_status','进行中','1',2),
('project_status','已结束','2',3),('project_status','已暂停','3',4),
('project_require_social','不要求','0',1),('project_require_social','必须','1',2),
('project_require_social','优先','2',3),
('policy_rule_type','社保匹配','social_match',1),('policy_rule_type','有效期下限','expiry_limit',2),
('policy_rule_type','证书类型限制','cert_limit',3),
('policy_severity','提示','0',1),('policy_severity','警告','1',2),('policy_severity','阻断','2',3),
('conflict_type','证书占用冲突','occupy',1),('conflict_type','人员时间冲突','person',2),
('conflict_type','证书互斥冲突','exclusive',3),('conflict_type','政策合规冲突','policy',4),
('match_status','待确认','0',1),('match_status','已选用','1',2),('match_status','已放弃','2',3);

-- ========== v2.29 项目模块自定义字段示例（管理员可在「自定义字段」页增删） ==========
INSERT INTO `cert_custom_field` (`module`,`field_key`,`field_label`,`field_type`,`options`,`required`,`sort`,`status`,`remark`) VALUES
('project','contract_no','项目合同号','text','',0,1,1,'甲方合同编号，便于对账'),
('project','settle_cycle','结算周期','select','月结,季结,年结,一次性',0,2,1,'费用结算周期'),
('project','need_invoice','是否开票','switch','',0,3,1,'是否需要开具发票')
ON DUPLICATE KEY UPDATE field_label=VALUES(field_label), field_type=VALUES(field_type),
  options=VALUES(options), sort=VALUES(sort), status=VALUES(status), remark=VALUES(remark);

-- ========== v2.30 重复模块合并：菜单与角色组重构 ==========
-- 合并原则（OA 内部系统形态）：
--   ① 「持证人员档案」与「人员全景档案」合并为「人员档案」（全景改为档案抽屉）
--   ② 「项目管理」与「配证任务」合并为「项目配证」（任务并入项目详情）
--   ③ 「费用统计」本就是「费用台账」内的 tab，取消独立菜单
-- 注意：此处与 MenuCatalog 蓝图保持一致；老库由 MenuCatalog::ensureSchema() 运行时自愈。

-- ① 更名与重排序（子路径顺延）
INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`) VALUES
(12,50,'人员档案','User','/staff','staff/index',1,1),
(5,50,'证照台账','Tickets','/certificate','certificate/index',2,1),
(11,50,'企业客户','OfficeBuilding','/company','company/index',3,1),
(13,50,'服务合同管理','Files','/contract','contract/index',4,1),
(41,40,'项目配证','FolderOpened','/project','project/index',1,1),
-- v2.34：33 配证任务紧邻项目配证（用户诉求「配证任务在项目配证下方」）
(33,40,'配证任务','Tickets','/cert-task','cert-task/index',2,1),
(42,40,'智能匹配','Aim','/match','match/index',3,1),
(43,40,'冲突中心','WarningFilled','/conflict','conflict/index',4,1),
(44,40,'属地政策规则','SetUp','/policy-rule','policy-rule/index',5,1),
(7,50,'证书延续','Refresh','/cert-renewal','certRenewal/index',3,1),
(9,52,'费用台账','Money','/fee','fee/index',1,1),
(54,52,'收款账户','Wallet','/pay-account','payAccount/index',2,1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), menu_name=VALUES(menu_name), icon=VALUES(icon),
  path=VALUES(path), component=VALUES(component), sort=VALUES(sort);

-- ② 停用因合并而退役的菜单（功能已并入其他模块；数据保留便于回滚）
-- v2.32：33 配证任务已恢复，从停用清单移除
UPDATE `sys_menu` SET `status` = 0 WHERE `id` IN (53,30);

-- ③ 角色组重构（v2.69：OA 岗位分工 + 单角色；admin = 不限制）
INSERT INTO `sys_role` (`id`,`role_code`,`role_name`,`menu_ids`,`remark`) VALUES
(1,'admin','超级管理员','','系统超级管理员，拥有全部功能与数据权限'),
(4,'cert_manager','证照管理员','1,2,3,31,57,11,68,13,56,50,12,5,7,14,6,51,26,8,40,41,33,42,43,44,58,59,60,63,64,65,66,67,19,62','负责人员档案、证照台账、证书延续、借调、提醒、客户跟进、项目配证、政务网进度、考试报名与 Agent 接入（只读）'),
(5,'project_officer','项目配证专员','1,2,3,31,40,41,33,42,43,44,58,59,60','负责项目配证、智能匹配、冲突处置与属地政策'),
(6,'finance','财务专员','1,2,3,57,13,52,9,54','负责费用台账、收款账户与服务合同'),
(2,'company','企业查看者','1,2,3,31,57,11,56,50,12,5,14,51,26,6','客户企业账号，仅查看本企业相关数据（只读）'),
(7,'viewer','只读观察员','1,2,3,31,57,11,13,56,50,12,5,7,14,40,41,33,42,43,44,51,26,6,8,58,59,60,52,9,54','全业务只读查看，含提醒中心；不含推送渠道/模板/接收人/日志管理')
ON DUPLICATE KEY UPDATE role_name=VALUES(role_name), menu_ids=VALUES(menu_ids), remark=VALUES(remark);

-- v2.69：operator 已从角色蓝图移除；无账号在用则删除，有账号则仅停用
DELETE FROM `sys_role` WHERE `role_code` = 'operator'
  AND `id` NOT IN (SELECT `role_id` FROM `sys_user`);
UPDATE `sys_role` SET `status` = 0,
  `remark` = '已从角色蓝图移除（仍有账号在用，请先调整账号角色）'
WHERE `role_code` = 'operator';

-- ========== v2.52 全模块回收站：菜单项 ==========
-- 回收站为管理员专用删除缓冲（快照 / 恢复 / 彻底删除），仅挂在「系统管理」分组下；
-- 非 admin 岗位角色 menu_ids 不包含 55，天然不可见（与 MenuCatalog 蓝图一致）。
INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`)
VALUES (55,19,'回收站','Delete','/recycle-bin','recycleBin/index',10,1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), menu_name=VALUES(menu_name),
  icon=VALUES(icon), path=VALUES(path), component=VALUES(component), sort=VALUES(sort);

-- ========== v2.54 CRM：跟进台账菜单 ==========
-- v2.69：跟进台账归入「客户与跟进」分组（57），与蓝图一致。
INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`)
VALUES (57,0,'客户与跟进','OfficeBuilding','','',2,1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), menu_name=VALUES(menu_name),
  icon=VALUES(icon), path=VALUES(path), component=VALUES(component), sort=VALUES(sort);

INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`)
VALUES (56,57,'跟进台账','Notebook','/follow-ledger','followLedger/index',3,1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), menu_name=VALUES(menu_name),
  icon=VALUES(icon), path=VALUES(path), component=VALUES(component), sort=VALUES(sort);

-- v2.69：角色授权以 v2.30/v2.69 角色块为唯一真源，不再在此追加菜单，
-- 避免「初始化脚本追加」与「蓝图对齐」两套口径互相覆盖。
-- （v2.54 跟进台账 56 / v2.62-63 政务网 58/59/60 均已包含在上方角色 menu_ids 中）

-- ========== v2.62 政务网申报进度 ==========
-- 顶级分组「政务网进度」(58) + 子菜单「申报进度」(59)；老库由 MenuCatalog 运行时自愈。
INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`)
VALUES (58,0,'政务网进度','Promotion','','',9,1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), menu_name=VALUES(menu_name),
  icon=VALUES(icon), path=VALUES(path), component=VALUES(component), sort=VALUES(sort);

INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`)
VALUES (59,58,'申报进度','DocumentChecked','/gov-progress','gov-progress/index',1,1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), menu_name=VALUES(menu_name),
  icon=VALUES(icon), path=VALUES(path), component=VALUES(component), sort=VALUES(sort);

-- v2.69：58/59 授权已并入上方角色 menu_ids，不再单独追加。

-- ========== v2.63 政务网进度增强 + 账号密码库 ==========
-- 菜单顺序：政务网进度前移到「费用与收款」之前；费用/推送/系统顺延。
INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`)
VALUES (58,0,'政务网进度','Promotion','','',6,1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), menu_name=VALUES(menu_name),
  icon=VALUES(icon), path=VALUES(path), component=VALUES(component), sort=VALUES(sort);

INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`)
VALUES (59,58,'申报进度','DocumentChecked','/gov-progress','gov-progress/index',1,1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), menu_name=VALUES(menu_name),
  icon=VALUES(icon), path=VALUES(path), component=VALUES(component), sort=VALUES(sort);

INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`)
VALUES (60,58,'账号密码库','Key','/credential-vault','credential-vault/index',2,1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), menu_name=VALUES(menu_name),
  icon=VALUES(icon), path=VALUES(path), component=VALUES(component), sort=VALUES(sort);

UPDATE `sys_menu` SET `sort` = 7 WHERE `id` = 52;
UPDATE `sys_menu` SET `sort` = 8 WHERE `id` = 14;
UPDATE `sys_menu` SET `sort` = 9 WHERE `id` = 19;

-- ========== v2.74 菜单顺序调整：借调与提醒上移到项目与配证之前 ==========
-- 老库由 MenuCatalog::ensureSchema() 按蓝图自动纠正，这里仅同步初始化种子。
UPDATE `sys_menu` SET `sort` = 4 WHERE `id` = 51;
UPDATE `sys_menu` SET `sort` = 5 WHERE `id` = 40;

-- v2.69：账号密码库（60）授权已并入上方角色 menu_ids，不再单独追加。

-- ========== v2.82 Agent 接入（API Key 管理） ==========
-- 默认仅超级管理员可见（admin 的 menu_ids 为空 = 不限制），其他角色需手工授权。
-- 密钥表 sys_agent_key 由 AgentApiService::ensureSchema() 运行时幂等自建。
INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`)
VALUES (62,19,'Agent 接入','MagicStick','/agent','agent/index',11,1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), menu_name=VALUES(menu_name),
  icon=VALUES(icon), path=VALUES(path), component=VALUES(component), sort=VALUES(sort);

-- 政务网申报业务类型字典（管理员可在「字典管理」中继续增删改排序）
INSERT INTO `dict_type` (`type_code`,`type_name`,`module`,`remark`) VALUES
('gov_business_type','政务网业务类型','gov','初始注册/转注/变更/延期/重新注册/注销/其他')
ON DUPLICATE KEY UPDATE type_name=VALUES(type_name), module=VALUES(module), remark=VALUES(remark);

INSERT INTO `dict_data` (`type_code`,`dict_label`,`dict_value`,`sort`,`status`) VALUES
('gov_business_type','初始注册','initial',1,1),
('gov_business_type','转注','transfer',2,1),
('gov_business_type','变更','change',3,1),
('gov_business_type','延期','renew',4,1),
('gov_business_type','重新注册','reissue',5,1),
('gov_business_type','注销','cancel',6,1),
('gov_business_type','其他','other',7,1)
ON DUPLICATE KEY UPDATE dict_label=VALUES(dict_label), sort=VALUES(sort), status=VALUES(status);

-- v2.64：账号密码库平台字典（remark 存平台网址，选择平台时自动带出）
INSERT INTO `dict_type` (`type_code`,`type_name`,`module`,`remark`) VALUES
('gov_platform','政务网平台','gov','账号密码库平台下拉；字典项的「备注」填写平台网址')
ON DUPLICATE KEY UPDATE type_name=VALUES(type_name), module=VALUES(module), remark=VALUES(remark);

INSERT INTO `dict_data` (`type_code`,`dict_label`,`dict_value`,`sort`,`status`,`remark`) VALUES
('gov_platform','河南政务服务网','河南政务服务网',1,1,'https://www.hnzwfw.gov.cn/'),
('gov_platform','河南省住房和城乡建设厅','河南省住房和城乡建设厅',2,1,'https://hnjs.henan.gov.cn/'),
('gov_platform','全国建筑市场监管公共服务平台','全国建筑市场监管公共服务平台',3,1,'https://jzsc.mohurd.gov.cn/')
ON DUPLICATE KEY UPDATE dict_label=VALUES(dict_label), remark=VALUES(remark), sort=VALUES(sort), status=VALUES(status);

-- ========== v2.99.26 移动端管理（合并原小程序接入 75 / 移动端配置 76） ==========
-- 挂在「系统管理」(19) 下、企微登录配置 (35, sort 7) 正下方，默认仅超级管理员可见
-- （admin 的 menu_ids 为空 = 不限制）。老库由 MenuCatalog::ensureSchema() 按蓝图运行时自愈。
-- 77 为合并后的唯一入口；75/76 停用隐藏但保留数据，避免历史角色授权成为孤儿。
INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`)
VALUES (77,19,'移动端管理','Cellphone','/mobile-manage','mobileManage/index',8,1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), menu_name=VALUES(menu_name),
  icon=VALUES(icon), path=VALUES(path), component=VALUES(component), sort=VALUES(sort);

INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`)
VALUES
  (75,19,'小程序接入','Cellphone','/miniapp','miniapp/index',14,0),
  (76,19,'移动端配置','Phone','/mobile-config','mobileConfig/index',15,0)
ON DUPLICATE KEY UPDATE status=VALUES(status);

-- ========== v2.93 企业证书（安许 / 资质）集中台账 ==========
-- 菜单挂在「客户与跟进」(57) 下，紧邻企业客户 (11)；服务合同/跟进台账 sort 顺延。
-- 老库由 MenuCatalog::ensureSchema() 运行时自愈 + grantCompanyCertMenuOnce 一次性授权。
INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`)
VALUES (68,57,'企业证书','Medal','/company-cert','companyCert/index',2,1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), menu_name=VALUES(menu_name),
  icon=VALUES(icon), path=VALUES(path), component=VALUES(component), sort=VALUES(sort);
UPDATE `sys_menu` SET `sort` = 3 WHERE `id` = 13;
UPDATE `sys_menu` SET `sort` = 4 WHERE `id` = 56;

-- alert_log 扩展企业证书去重维度（老库由 CertExpireService::ensureAlertLogCols 运行时自愈）
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alert_log' AND COLUMN_NAME = 'company_qualification_id');
SET @sql := IF(@col = 0,
  'ALTER TABLE `alert_log` ADD COLUMN `company_qualification_id` int unsigned NOT NULL DEFAULT 0 COMMENT ''关联企业证书ID（alert_type=company_cert 时有效）'' AFTER `secondment_id`',
  'SELECT 1 INTO @install_dummy');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @uk := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alert_log' AND INDEX_NAME = 'uk_dedup'
    AND COLUMN_NAME = 'company_qualification_id');
SET @sql := IF(@uk = 0,
  'ALTER TABLE `alert_log` DROP INDEX `uk_dedup`, ADD UNIQUE KEY `uk_dedup` (`cert_id`,`alert_node`,`alert_date`,`secondment_id`,`company_qualification_id`)',
  'SELECT 1 INTO @install_dummy');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- push_msg_log 增加业务归属列（老库由 CertExpireService::ensurePushLogCols 运行时自愈）
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'push_msg_log' AND COLUMN_NAME = 'biz_type');
SET @sql := IF(@col = 0,
  'ALTER TABLE `push_msg_log` ADD COLUMN `biz_type` varchar(20) NOT NULL DEFAULT ''cert'' COMMENT ''业务类型: cert证书 / secondment借调 / company_cert企业证书'' AFTER `cert_id`',
  'SELECT 1 INTO @install_dummy');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'push_msg_log' AND COLUMN_NAME = 'biz_id');
SET @sql := IF(@col = 0,
  'ALTER TABLE `push_msg_log` ADD COLUMN `biz_id` int unsigned NOT NULL DEFAULT 0 COMMENT ''第二业务ID：cert_secondment.id / company_qualification.id'' AFTER `biz_type`',
  'SELECT 1 INTO @install_dummy');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ========== v2.94 企业证书增改删查 / 导入 / 附件 ==========
-- 父表补首附件列（老库由 CompanyQualificationService::ensureSchema 运行时自愈）
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'company_qualification' AND COLUMN_NAME = 'attach_path');
SET @sql := IF(@col = 0,
  'ALTER TABLE `company_qualification` ADD COLUMN `attach_path` varchar(255) NOT NULL DEFAULT '''' COMMENT ''首附件路径（兼容列表/导出）'' AFTER `remark`',
  'SELECT 1 INTO @install_dummy');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 附件子表（与 certificate_attach / staff_attach 同构）
CREATE TABLE IF NOT EXISTS `company_qualification_attach` (
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

-- ========== v2.99 学历报名模块 ==========
-- 顶级分组「学历报名」(70) + 报名工单(71)/报名机构(72)/流程配置(73)。
-- 老库由 MenuCatalog::ensureSchema() 按蓝图运行时自愈，角色由 RoleCatalog 版本对齐。
INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`)
VALUES (70,0,'学历报名','School','','',8,1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), menu_name=VALUES(menu_name),
  icon=VALUES(icon), path=VALUES(path), component=VALUES(component), sort=VALUES(sort);
INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`)
VALUES (71,70,'报名工单','Tickets','/edu-signup','eduSignup/index',1,1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), menu_name=VALUES(menu_name),
  icon=VALUES(icon), path=VALUES(path), component=VALUES(component), sort=VALUES(sort);
INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`)
VALUES (72,70,'报名机构','OfficeBuilding','/edu-agency','eduAgency/index',2,1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), menu_name=VALUES(menu_name),
  icon=VALUES(icon), path=VALUES(path), component=VALUES(component), sort=VALUES(sort);
INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`)
VALUES (73,70,'流程配置','SetUp','/edu-config','eduConfig/index',3,1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), menu_name=VALUES(menu_name),
  icon=VALUES(icon), path=VALUES(path), component=VALUES(component), sort=VALUES(sort);
-- 后续顶级分组排序顺延：费用与收款 9 / 推送中心 10 / 系统管理 11
UPDATE `sys_menu` SET `sort` = 9 WHERE `id` = 52;
UPDATE `sys_menu` SET `sort` = 10 WHERE `id` = 14;
UPDATE `sys_menu` SET `sort` = 11 WHERE `id` = 19;

-- 字典类型（dict_type.type_code 有唯一键，可 ON DUPLICATE）
INSERT INTO `dict_type` (`type_code`,`type_name`,`module`,`remark`) VALUES
 ('edu_signup_type','学历报名类型','edu','电中/成考'),
 ('edu_level','学历类型','edu','中专/高起专/专升本/高起本'),
 ('edu_nature','学历性质','edu','函授/全日制'),
 ('edu_schooling','学制','edu','1年/2年/2.5年'),
 ('edu_roll_status','学籍状态','edu','在籍/已毕业')
ON DUPLICATE KEY UPDATE type_name=VALUES(type_name), module=VALUES(module), remark=VALUES(remark);

-- 字典数据（dict_data 无唯一键，按 type_code+dict_value 查后插，避免重复）
INSERT INTO `dict_data` (`type_code`,`dict_label`,`dict_value`,`parent_value`,`sort`,`status`)
SELECT * FROM (SELECT 'edu_signup_type' AS a,'电中' AS b,'dianzhong' AS c,'' AS d,1 AS e,1 AS f) t
WHERE NOT EXISTS (SELECT 1 FROM `dict_data` WHERE type_code='edu_signup_type' AND parent_value='' AND dict_value='dianzhong');
INSERT INTO `dict_data` (`type_code`,`dict_label`,`dict_value`,`parent_value`,`sort`,`status`)
SELECT * FROM (SELECT 'edu_signup_type' AS type_code,'成考' AS dict_label,'chengkao' AS dict_value,'' AS parent_value,2 AS sort,1 AS status) t
WHERE NOT EXISTS (SELECT 1 FROM `dict_data` WHERE type_code='edu_signup_type' AND parent_value='' AND dict_value='chengkao');
INSERT INTO `dict_data` (`type_code`,`dict_label`,`dict_value`,`parent_value`,`sort`,`status`)
SELECT * FROM (SELECT 'edu_level' AS type_code,'中专' AS dict_label,'中专' AS dict_value,'' AS parent_value,1 AS sort,1 AS status) t
WHERE NOT EXISTS (SELECT 1 FROM `dict_data` WHERE type_code='edu_level' AND parent_value='' AND dict_value='中专');
INSERT INTO `dict_data` (`type_code`,`dict_label`,`dict_value`,`parent_value`,`sort`,`status`)
SELECT * FROM (SELECT 'edu_level' AS type_code,'高起专' AS dict_label,'高起专' AS dict_value,'' AS parent_value,2 AS sort,1 AS status) t
WHERE NOT EXISTS (SELECT 1 FROM `dict_data` WHERE type_code='edu_level' AND parent_value='' AND dict_value='高起专');
INSERT INTO `dict_data` (`type_code`,`dict_label`,`dict_value`,`parent_value`,`sort`,`status`)
SELECT * FROM (SELECT 'edu_level' AS type_code,'专升本' AS dict_label,'专升本' AS dict_value,'' AS parent_value,3 AS sort,1 AS status) t
WHERE NOT EXISTS (SELECT 1 FROM `dict_data` WHERE type_code='edu_level' AND parent_value='' AND dict_value='专升本');
INSERT INTO `dict_data` (`type_code`,`dict_label`,`dict_value`,`parent_value`,`sort`,`status`)
SELECT * FROM (SELECT 'edu_level' AS type_code,'高起本' AS dict_label,'高起本' AS dict_value,'' AS parent_value,4 AS sort,1 AS status) t
WHERE NOT EXISTS (SELECT 1 FROM `dict_data` WHERE type_code='edu_level' AND parent_value='' AND dict_value='高起本');
INSERT INTO `dict_data` (`type_code`,`dict_label`,`dict_value`,`parent_value`,`sort`,`status`)
SELECT * FROM (SELECT 'edu_nature' AS type_code,'函授' AS dict_label,'函授' AS dict_value,'' AS parent_value,1 AS sort,1 AS status) t
WHERE NOT EXISTS (SELECT 1 FROM `dict_data` WHERE type_code='edu_nature' AND parent_value='' AND dict_value='函授');
INSERT INTO `dict_data` (`type_code`,`dict_label`,`dict_value`,`parent_value`,`sort`,`status`)
SELECT * FROM (SELECT 'edu_nature' AS type_code,'全日制' AS dict_label,'全日制' AS dict_value,'' AS parent_value,2 AS sort,1 AS status) t
WHERE NOT EXISTS (SELECT 1 FROM `dict_data` WHERE type_code='edu_nature' AND parent_value='' AND dict_value='全日制');
INSERT INTO `dict_data` (`type_code`,`dict_label`,`dict_value`,`parent_value`,`sort`,`status`)
SELECT * FROM (SELECT 'edu_schooling' AS type_code,'1年' AS dict_label,'1年' AS dict_value,'' AS parent_value,1 AS sort,1 AS status) t
WHERE NOT EXISTS (SELECT 1 FROM `dict_data` WHERE type_code='edu_schooling' AND parent_value='' AND dict_value='1年');
INSERT INTO `dict_data` (`type_code`,`dict_label`,`dict_value`,`parent_value`,`sort`,`status`)
SELECT * FROM (SELECT 'edu_schooling' AS type_code,'2年' AS dict_label,'2年' AS dict_value,'' AS parent_value,2 AS sort,1 AS status) t
WHERE NOT EXISTS (SELECT 1 FROM `dict_data` WHERE type_code='edu_schooling' AND parent_value='' AND dict_value='2年');
INSERT INTO `dict_data` (`type_code`,`dict_label`,`dict_value`,`parent_value`,`sort`,`status`)
SELECT * FROM (SELECT 'edu_schooling' AS type_code,'2.5年' AS dict_label,'2.5年' AS dict_value,'' AS parent_value,3 AS sort,1 AS status) t
WHERE NOT EXISTS (SELECT 1 FROM `dict_data` WHERE type_code='edu_schooling' AND parent_value='' AND dict_value='2.5年');
INSERT INTO `dict_data` (`type_code`,`dict_label`,`dict_value`,`parent_value`,`sort`,`status`)
SELECT * FROM (SELECT 'edu_roll_status' AS type_code,'在籍' AS dict_label,'enrolled' AS dict_value,'' AS parent_value,1 AS sort,1 AS status) t
WHERE NOT EXISTS (SELECT 1 FROM `dict_data` WHERE type_code='edu_roll_status' AND parent_value='' AND dict_value='enrolled');
INSERT INTO `dict_data` (`type_code`,`dict_label`,`dict_value`,`parent_value`,`sort`,`status`)
SELECT * FROM (SELECT 'edu_roll_status' AS type_code,'已毕业' AS dict_label,'graduated' AS dict_value,'' AS parent_value,2 AS sort,1 AS status) t
WHERE NOT EXISTS (SELECT 1 FROM `dict_data` WHERE type_code='edu_roll_status' AND parent_value='' AND dict_value='graduated');

-- 角色菜单授权：证照管理员(4) 整组 70/71/72/73；企业查看者(2) 仅分组+工单 70/71。
-- 老库实际以 RoleCatalog::ensure() 版本门控整体对齐为准，此处仅保证全新安装种子一致。
UPDATE `sys_role` SET `menu_ids` = CONCAT(`menu_ids`, ',70,71,72,73')
WHERE `role_code` = 'cert_manager' AND FIND_IN_SET(70, `menu_ids`) = 0;
UPDATE `sys_role` SET `menu_ids` = CONCAT(`menu_ids`, ',70,71')
WHERE `role_code` = 'company' AND FIND_IN_SET(70, `menu_ids`) = 0;
-- v2.99.47 国密合规 P1：敏感信息明文访问审计菜单（仅超级管理员可见，admin 菜单为空=不限制，无需给其他角色授权）
INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`)
VALUES (78,19,'敏感访问审计','Lock','/sensitive-access','sensitiveAccess/index',11,1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), menu_name=VALUES(menu_name), icon=VALUES(icon),
  path=VALUES(path), component=VALUES(component), sort=VALUES(sort);

-- ========== v2.99.70 考试报名：安管人员成绩查询 ==========
INSERT INTO `sys_menu` (`id`,`parent_id`,`menu_name`,`icon`,`path`,`component`,`sort`,`status`)
VALUES (79,63,'成绩查询','Trophy','/exam-score','examScore/index',5,1)
ON DUPLICATE KEY UPDATE parent_id=VALUES(parent_id), menu_name=VALUES(menu_name), icon=VALUES(icon),
  path=VALUES(path), component=VALUES(component), sort=VALUES(sort);
-- 授权：证照管理员、项目配证专员（老库 runtime 由 RoleCatalog 版本门控对齐）
UPDATE `sys_role` SET `menu_ids` = CONCAT(`menu_ids`, ',79')
WHERE `role_code` IN ('cert_manager','project_officer') AND FIND_IN_SET(79, `menu_ids`) = 0;

