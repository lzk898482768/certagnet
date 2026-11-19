<?php
namespace app\service;

use app\model\CompanyReceiver;
use app\model\MsgTemplate;
use app\model\PushChannel;
use app\model\PushMsgLog;
use think\facade\Db;

/**
 * 河南政务服务网「申报进度」服务（v2.63）
 *
 * 业务背景：
 *   证照注册 / 转注 / 变更 / 延期等事项在河南政务服务网提交后，平台会返回申报号与密码。
 *   本服务按「申报号 + 密码」调用平台公开查询接口，解析 FLOWXML 环节进度并落库，
 *   供 PC 端列表、详情与每日定时任务共用。
 *
 * 关键约定：
 *   - 进度真源是 result.BaseInfo.FLOWXML，逐节点读取
 *     NODENAME / STEP / STATE / STARTTIME / ENDTIME / OPINION / NAME；
 *   - STATE：1 已完成，2 办理中，3 未开始；
 *   - 环节名称包含「签发」且该节点已完成（STATE=1 或 ENDTIME 非空）→ 事项办结；
 *     全节点完成或平台 HANDLESTATE 含「办结」也视为办结；
 *   - 承诺时间取 BaseInfo.PROMISEETIME，受理时间取 ACCEPTTIME（缺失回退 RECEIVETIME）；
 *   - 申报密码使用 AES-256-GCM 加密存储，列表只返回掩码；
 *   - v2.63：业务类型改由字典 gov_business_type 维护（缺失时回退 BUSINESS_TYPES），
 *     并增加企业 / 人员 / 证书类型 / 证书专业关联；同步执行记录落 gov_sync_log；
 *   - v2.63：FLOWXML 中的全部 NODE 均按平台原始顺序落库，包含「签发」等所有环节，
 *     不以 STEP 去重、不因状态未知而丢弃；环节唯一键改为 (progress_id, sort)；
 *   - v2.63：检测到办理完成（签发完成 / 全节点完成 / 平台状态办结）时自动归档，
 *     停止每日自动同步，但保留全部数据，可在「已归档」中查看或手动取消归档。
 */
class GovProgressService
{
    /** 业务类型字典码（v2.63：管理员可在「字典管理」中维护） */
    public const BUSINESS_TYPE_DICT = 'gov_business_type';

    /** 业务类型兜底值：value => 中文名（字典缺失或为空时使用） */
    public const BUSINESS_TYPES = [
        'initial'  => '初始注册',
        'transfer' => '转注',
        'change'   => '变更',
        'renew'    => '延期',
        'reissue'  => '重新注册',
        'cancel'   => '注销',
        'other'    => '其他',
    ];

    /** 环节状态：1 已完成 / 2 办理中 / 3 未开始 */
    public const NODE_STATES = [
        1 => '已完成',
        2 => '办理中',
        3 => '未开始',
    ];

    /** 同步状态：0 未同步 / 1 成功 / 2 失败 */
    public const SYNC_STATES = [
        0 => '未同步',
        1 => '同步成功',
        2 => '同步失败',
    ];

    /** 归档来源：auto=办结自动归档 / manual=人工归档 */
    public const ARCHIVE_SOURCES = [
        'auto'   => '办结自动归档',
        'manual' => '手动归档',
    ];

    /** 平台公开查询接口 */
    public const DEFAULT_API_URL = 'https://www.hnzwfw.gov.cn/hnzwfw/apasinfo/taskOffGetApasInfoDetailByProjidAndProjpwd';

    /** 表结构自愈（老库 git pull 后首次访问自动建表 / 补列） */
    public static function ensureTables(): void
    {
        ensure_table('gov_progress', self::progressDdl());
        // v2.63：企业 / 人员 / 证书字段。老库只 git pull，不执行升级脚本，故运行时补列。
        ensure_column('gov_progress', 'company_id', "int unsigned NOT NULL DEFAULT 0 COMMENT '关联客户企业ID' AFTER `business_type`");
        ensure_column('gov_progress', 'staff_id', "int unsigned NOT NULL DEFAULT 0 COMMENT '关联人员ID' AFTER `company_id`");
        ensure_column('gov_progress', 'cert_type', "varchar(64) NOT NULL DEFAULT '' COMMENT '证书类型（cert_major 一级）' AFTER `staff_id`");
        ensure_column('gov_progress', 'cert_name', "varchar(64) NOT NULL DEFAULT '' COMMENT '证书专业（cert_major 二级）' AFTER `cert_type`");
        // v2.63：办结自动归档。老库补列后由 backfillArchive() 一次性回填历史已办结数据。
        ensure_column('gov_progress', 'is_archived', "tinyint NOT NULL DEFAULT 0 COMMENT '1=已归档（办结自动归档）' AFTER `is_finished`");
        ensure_column('gov_progress', 'archive_time', "datetime DEFAULT NULL COMMENT '归档时间' AFTER `is_archived`");
        ensure_column('gov_progress', 'archive_source', "varchar(16) NOT NULL DEFAULT '' COMMENT 'auto=办结自动归档 manual=手动归档' AFTER `archive_time`");
        // v2.95：人工保存人与保存时间（同步刷新不写入，详情页展示）
        ensure_column('gov_progress', 'save_by', "int unsigned NOT NULL DEFAULT 0 COMMENT '最近人工保存人账号ID（同步刷新不写入）' AFTER `create_by`");
        ensure_column('gov_progress', 'save_time', "datetime DEFAULT NULL COMMENT '最近人工保存时间（同步刷新不写入）' AFTER `save_by`");
        // v2.90：挂起状态与挂起意见。全部取自政务网原文（HANDLESTATE / Nodes.Node.OPINION / FLOWXML SPECIALS），
        // 平台未返回意见时留空，不做任何推断或补写。
        ensure_column('gov_progress', 'is_suspended', "tinyint NOT NULL DEFAULT 0 COMMENT '1=平台挂起' AFTER `is_finished`");
        ensure_column('gov_progress', 'suspend_reason', "varchar(2000) NOT NULL DEFAULT '' COMMENT '挂起意见（政务网原文）' AFTER `is_suspended`");
        ensure_column('gov_progress', 'suspend_time', "datetime DEFAULT NULL COMMENT '挂起开始时间（政务网原文）' AFTER `suspend_reason`");
        ensure_column('gov_progress', 'suspend_notified', "tinyint NOT NULL DEFAULT 0 COMMENT '1=本次挂起已发送通知' AFTER `suspend_time`");
        // v2.99.40：退件/补正状态（平台原文关键词），列表置顶并强提醒，避免被淹没
        ensure_column('gov_progress', 'is_returned', "tinyint NOT NULL DEFAULT 0 COMMENT '1=退件/补正待处理' AFTER `suspend_notified`");
        ensure_column('gov_progress', 'return_reason', "varchar(2000) NOT NULL DEFAULT '' COMMENT '退件意见（政务网原文）' AFTER `is_returned`");
        ensure_column('gov_progress', 'return_time', "datetime DEFAULT NULL COMMENT '退件时间（政务网原文）' AFTER `return_reason`");
        ensure_table('gov_progress_node', self::nodeDdl());
        // v2.70：环节审批人。老库只 git pull，运行时补列。
        ensure_column('gov_progress_node', 'approver_name', "varchar(64) NOT NULL DEFAULT '' COMMENT '审批人' AFTER `handler_name`");
        // v2.90：政务网 result.Nodes.Node 的挂起环节字段（原文落库，不做二次加工）
        ensure_column('gov_progress_node', 'opinion_type', "varchar(64) NOT NULL DEFAULT '' COMMENT '意见类型 OPINION_TYPE' AFTER `opinion`");
        ensure_column('gov_progress_node', 'action', "varchar(32) NOT NULL DEFAULT '' COMMENT '操作类型 ACTION' AFTER `opinion_type`");
        ensure_column('gov_progress_node', 'is_suspended', "tinyint NOT NULL DEFAULT 0 COMMENT '1=挂起环节' AFTER `action`");
        ensure_column('gov_progress_node', 'special_name', "varchar(64) NOT NULL DEFAULT '' COMMENT '特别程序名称 SPECIALNAME' AFTER `is_suspended`");
        ensure_column('gov_progress_node', 'special_reason', "varchar(2000) NOT NULL DEFAULT '' COMMENT '特别程序原因 SPECIALRESON（原文）' AFTER `special_name`");
        ensure_column('gov_progress_node', 'special_start_time', "datetime DEFAULT NULL COMMENT '特别程序开始时间' AFTER `special_reason`");
        ensure_column('gov_progress_node', 'special_end_time', "datetime DEFAULT NULL COMMENT '特别程序结束时间' AFTER `special_start_time`");
        // v2.62 的 uk_progress_step 会在平台返回重复 STEP 时覆盖环节（曾导致「签发」丢失），
        // v2.63 改为 (progress_id, sort) 唯一，保证 FLOWXML 每个 NODE 都保留。
        self::ensureNodeIndexes();
        ensure_table('gov_sync_log', self::syncLogDdl());
        // 业务类型字典：老库由运行时补种，保证「字典管理」里立即可维护
        try {
            DictCatalog::ensureGovBusinessTypes();
        } catch (\Throwable $e) {
            // 字典补种失败不阻断申报进度主流程
        }
        self::backfillArchive();
    }

    /**
     * v2.63：把旧库的历史已办结记录补成「已归档」并停止自动同步。
     * 只执行一次，之后由同步逻辑实时维护，不影响用户手动取消归档。
     */
    private static function backfillArchive(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;
        try {
            if (sys_config('gov_archive_backfill_v263', '') === '1') {
                return;
            }
            Db::execute(
                "UPDATE `gov_progress` SET `is_archived`=1, `archive_time`=COALESCE(`finish_time`, `last_sync_time`, `update_time`, NOW()), `archive_source`='auto', `status`=0 "
                . "WHERE `is_finished`=1 AND `is_archived`=0"
            );
            $row = Db::name('system_config')->where('config_key', 'gov_archive_backfill_v263')->find();
            if ($row) {
                Db::name('system_config')->where('id', $row['id'])->update(['config_value' => '1']);
            } else {
                Db::name('system_config')->insert([
                    'config_key'   => 'gov_archive_backfill_v263',
                    'config_value' => '1',
                    'remark'       => 'v2.63 已办结申报自动归档历史数据回填标记',
                ]);
            }
        } catch (\Throwable $e) {
            // 回填失败不阻断主流程，下次进入模块会重试
        }
    }

    /**
     * v2.63：环节表索引迁移。老库的 uk_progress_step 必须移除，否则重复 STEP 会被覆盖。
     */
    private static function ensureNodeIndexes(): void
    {
        try {
            $indexes = Db::query('SHOW INDEX FROM `gov_progress_node`');
            $names = [];
            foreach ($indexes as $idx) {
                $names[(string)($idx['Key_name'] ?? '')] = true;
            }
            if (isset($names['uk_progress_step'])) {
                Db::execute('ALTER TABLE `gov_progress_node` DROP INDEX `uk_progress_step`');
            }
            if (!isset($names['uk_progress_sort'])) {
                Db::execute('ALTER TABLE `gov_progress_node` ADD UNIQUE KEY `uk_progress_sort` (`progress_id`, `sort`)');
            }
            if (!isset($names['idx_progress_step'])) {
                Db::execute('ALTER TABLE `gov_progress_node` ADD KEY `idx_progress_step` (`progress_id`, `step`)');
            }
        } catch (\Throwable $e) {
            // 索引迁移失败不阻断同步；insert 阶段仍会按 sort 写入
        }
    }

    public static function progressDdl(): string
    {
        return "CREATE TABLE IF NOT EXISTS `gov_progress` (
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
  `is_suspended` tinyint NOT NULL DEFAULT 0 COMMENT '1=平台挂起（政务网 HANDLESTATE / Nodes.Node）',
  `suspend_reason` varchar(2000) NOT NULL DEFAULT '' COMMENT '挂起意见（政务网原文）',
  `suspend_time` datetime DEFAULT NULL COMMENT '挂起开始时间（政务网原文）',
  `suspend_notified` tinyint NOT NULL DEFAULT 0 COMMENT '1=本次挂起已发送通知',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='政务网申报进度（v2.63）'";
    }

    /** v2.63：定时任务执行记录（每次 gov:sync / 手动全量同步落一条） */
    public static function syncLogDdl(): string
    {
        return "CREATE TABLE IF NOT EXISTS `gov_sync_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `run_at` datetime NOT NULL COMMENT '执行时间',
  `trigger_type` varchar(20) NOT NULL DEFAULT 'schedule' COMMENT 'schedule=定时任务 manual=手动',
  `total` int NOT NULL DEFAULT 0 COMMENT '本次处理条数',
  `ok` int NOT NULL DEFAULT 0 COMMENT '成功条数',
  `fail` int NOT NULL DEFAULT 0 COMMENT '失败条数',
  `duration_ms` int NOT NULL DEFAULT 0 COMMENT '耗时毫秒',
  `operator_id` int unsigned NOT NULL DEFAULT 0 COMMENT '手动触发人账号ID',
  `message` varchar(500) NOT NULL DEFAULT '' COMMENT '执行摘要',
  `detail` mediumtext COMMENT '失败明细 JSON',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_run_at` (`run_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='政务网同步执行记录（v2.63）'";
    }


    public static function nodeDdl(): string
    {
        return "CREATE TABLE IF NOT EXISTS `gov_progress_node` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `progress_id` int unsigned NOT NULL DEFAULT 0 COMMENT 'gov_progress.id',
  `step` int NOT NULL DEFAULT 0 COMMENT '环节序号 STEP',
  `node_name` varchar(64) NOT NULL DEFAULT '' COMMENT '环节名称 NODENAME',
  `state` tinyint NOT NULL DEFAULT 0 COMMENT '1已完成 2办理中 3未开始',
  `start_time` datetime DEFAULT NULL COMMENT '开始时间 STARTTIME',
  `end_time` datetime DEFAULT NULL COMMENT '结束时间 ENDTIME',
  `stay_time` varchar(16) NOT NULL DEFAULT '' COMMENT '停留时长 STAYTIME',
  `opinion` varchar(2000) NOT NULL DEFAULT '' COMMENT '办理意见 OPINION',
  `opinion_type` varchar(64) NOT NULL DEFAULT '' COMMENT '意见类型 OPINION_TYPE',
  `action` varchar(32) NOT NULL DEFAULT '' COMMENT '操作类型 ACTION',
  `is_suspended` tinyint NOT NULL DEFAULT 0 COMMENT '1=挂起环节（名称/意见类型含挂起）',
  `special_name` varchar(64) NOT NULL DEFAULT '' COMMENT '特别程序名称 SPECIALNAME',
  `special_reason` varchar(2000) NOT NULL DEFAULT '' COMMENT '特别程序原因 SPECIALRESON（原文）',
  `special_start_time` datetime DEFAULT NULL COMMENT '特别程序开始时间',
  `special_end_time` datetime DEFAULT NULL COMMENT '特别程序结束时间',
  `handler_name` varchar(64) NOT NULL DEFAULT '' COMMENT '经办人 NAME',
  `approver_name` varchar(64) NOT NULL DEFAULT '' COMMENT '审批人 APPROVER',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_progress_sort` (`progress_id`, `sort`),
  KEY `idx_progress` (`progress_id`),
  KEY `idx_progress_step` (`progress_id`, `step`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='政务网申报环节进度（v2.63，完整保留平台节点）'";
    }

    // ============================================================
    // 密码加解密（v2.63 统一委托 SecretService，兼容既有 gcm$ 数据）
    // ============================================================

    public static function encryptPassword(string $plain): string
    {
        return SecretService::encrypt($plain);
    }

    /**
     * 解密。兼容历史明文（非 gcm$ 前缀原样返回），避免手工导入的数据无法同步。
     */
    public static function decryptPassword(string $enc): string
    {
        return SecretService::decrypt($enc);
    }

    public static function maskPassword(string $plain): string
    {
        return SecretService::mask($plain);
    }

    // ============================================================
    // 业务类型字典
    // ============================================================

    /**
     * 业务类型列表：优先读字典 gov_business_type，缺失/为空时回退常量。
     *
     * @return array<string,string> value => 中文名
     */
    public static function businessTypes(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        try {
            $rows = Db::name('dict_data')
                ->where('type_code', self::BUSINESS_TYPE_DICT)
                ->where('status', 1)
                ->order('sort', 'asc')
                ->order('id', 'asc')
                ->field('dict_label,dict_value')
                ->select()
                ->toArray();
            $map = [];
            foreach ($rows as $r) {
                $value = trim((string)($r['dict_value'] ?? ''));
                $label = trim((string)($r['dict_label'] ?? ''));
                if ($value !== '' && $label !== '') {
                    $map[$value] = $label;
                }
            }
            if ($map) {
                return $cache = $map;
            }
        } catch (\Throwable $e) {
            // 字典表异常时回退内置常量
        }
        return $cache = self::BUSINESS_TYPES;
    }

    public static function businessTypeText(string $value): string
    {
        $map = self::businessTypes();
        return $map[$value] ?? ($value !== '' ? $value : '其他');
    }


    // ============================================================
    // 政务网查询与解析
    // ============================================================

    /**
     * 查询平台并返回 BaseInfo / 原始响应
     *
     * @return array{base:array,result:array,raw:array}
     * @throws \RuntimeException 平台不可用、申报号或密码错误等
     */
    public static function fetch(string $projectNo, string $password): array
    {
        $projectNo = trim($projectNo);
        $password  = trim($password);
        if ($projectNo === '') {
            throw new \RuntimeException('申报号不能为空');
        }
        if ($password === '') {
            throw new \RuntimeException('申报密码不能为空');
        }

        $url = (string)config('gov.api_url', self::DEFAULT_API_URL);
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query([
            'projId'   => $projectNo,
            'password' => $password,
        ]);

        $body = self::httpGet($url);

        $json = json_decode($body, true);
        if (!is_array($json)) {
            $preview = trim(preg_replace('/\s+/u', ' ', strip_tags($body)) ?? '');
            throw new \RuntimeException('政务网返回格式异常：' . mb_substr($preview, 0, 120));
        }
        $code = (string)($json['code'] ?? '');
        if ($code !== '1000') {
            $msg = trim((string)($json['msg'] ?? '未知错误'));
            throw new \RuntimeException('政务网查询失败：' . ($msg !== '' ? $msg : '未知错误') . ($code !== '' ? "（code {$code}）" : ''));
        }
        $result = $json['result'] ?? null;
        if (!is_array($result)) {
            throw new \RuntimeException('政务网返回缺少 result 数据');
        }
        $base = $result['BaseInfo'] ?? null;
        if (!is_array($base) || !$base) {
            throw new \RuntimeException('未找到申报信息，请核对申报号与密码');
        }
        return ['base' => $base, 'result' => $result, 'raw' => $json];
    }

    private static function httpGet(string $url): string
    {
        $caFile = rtrim((string)app()->getRootPath(), '/\\') . DIRECTORY_SEPARATOR
            . 'extend' . DIRECTORY_SEPARATOR . 'cert' . DIRECTORY_SEPARATOR . 'cacert.pem';

        $ch = curl_init();
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => (int)config('gov.timeout', 25),
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json, text/plain, */*',
                'Accept-Language: zh-CN,zh;q=0.9',
                'Referer: https://www.hnzwfw.gov.cn/',
            ],
        ];
        if (is_file($caFile)) {
            $opts[CURLOPT_CAINFO] = $caFile;
        }
        curl_setopt_array($ch, $opts);
        $body   = curl_exec($ch);
        $err    = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException('政务网请求失败：' . ($err !== '' ? $err : '网络异常'));
        }
        if ($status >= 400) {
            throw new \RuntimeException("政务网请求失败：HTTP {$status}");
        }
        return (string)$body;
    }

    /**
     * 解析 FLOWXML 为环节数组；无 FLOWXML 时回退 result.Nodes.Node 摘要。
     *
     * v2.63 关键约束：平台返回多少个 NODE 就保留多少个，包含「签发」等全部节点。
     *  - 不按 STEP 去重，STEP 重复时用 sort（XML 原始顺序）作为唯一键；
     *  - 不因 STATE 缺失/未知而丢弃节点，状态按起止时间推断；
     *  - 保持 FLOWXML 中的原始顺序（平台流程顺序），不重新排序。
     *
     * @return array<int,array{step:int,sort:int,node_name:string,state:int,start_time:?string,end_time:?string,stay_time:string,opinion:string,handler_name:string,approver_name:string}>
     */
    public static function parseNodes(array $base, array $result = []): array
    {
        $flow = self::extractFlowPayload($base, $result);
        $out = $flow !== null ? self::parseFlowPayload($flow) : [];

        // v2.90：result.Nodes.Node 是平台另返回的「办理动作」清单，包含不在 FLOWXML
        // 节点里的环节（如「挂起（现场勘查）」及其挂起意见）。FLOWXML 有解析结果时
        // 也必须合并，否则挂起环节会被丢掉。
        $summary = self::parseNodesSummary($result);
        if ($summary) {
            $out = self::mergeSummaryNodes($out, $summary);
        }

        // 回退：两处都没解析到才视为无环节
        // 重新编号，保证 sort 连续且等于平台原始顺序
        foreach ($out as $i => &$n) {
            $n['sort'] = $i + 1;
        }
        unset($n);
        return $out;
    }

    /**
     * v2.90：把 Nodes.Node 摘要合并进 FLOWXML 节点列表。
     * 只做去重与排序，节点名称 / 意见 / 时间全部保留平台原文。
     */
    private static function mergeSummaryNodes(array $baseNodes, array $summary): array
    {
        $out = $baseNodes;
        foreach ($summary as $s) {
            $dup = false;
            foreach ($out as $n) {
                if (self::isSameSummaryNode($n, $s)) {
                    $dup = true;
                    break;
                }
            }
            if ($dup) {
                continue;
            }
            // 与 FLOWXML 特别程序同一开始时间的，插到该节点之后，保持时间先后
            $pos = null;
            foreach ($out as $i => $n) {
                if (!empty($n['special_start_time']) && !empty($s['start_time'])
                    && (string)$n['special_start_time'] === (string)$s['start_time']) {
                    $pos = $i;
                    break;
                }
            }
            if ($pos === null) {
                $out[] = $s;
            } else {
                array_splice($out, $pos + 1, 0, [$s]);
            }
        }
        return $out;
    }

    /**
     * 判断 Nodes.Node 摘要与 FLOWXML 节点是否描述同一条办理动作。
     *
     * 平台两处返回的时间口径并不一致：例如「受理」在 FLOWXML 里用环节进入时间，
     * 在 Nodes.Node 里用收件时间，名称相同但起止时间不同。旧实现只比较
     * 「名称 + 开始时间 + 意见」，会把同一条受理重复追加到流程末尾。
     * 这里改为按 STEP / 时间段重叠判断，同时保留摘要独有的挂起、特别程序节点。
     */
    private static function isSameSummaryNode(array $base, array $sum): bool
    {
        $name = (string)($base['node_name'] ?? '');
        if ($name === '' || $name !== (string)($sum['node_name'] ?? '')) {
            return false;
        }

        $bStep = (int)($base['step'] ?? 0);
        $sStep = (int)($sum['step'] ?? 0);
        if ($bStep > 0 && $sStep > 0 && $bStep === $sStep) {
            return true;
        }

        // 摘要里的挂起 / 特别程序若带来了 FLOWXML 没有的信息，按独立节点保留
        $sumSpecial = (int)($sum['is_suspended'] ?? 0) === 1 || (string)($sum['special_name'] ?? '') !== '';
        $baseSpecial = (int)($base['is_suspended'] ?? 0) === 1 || (string)($base['special_name'] ?? '') !== '';
        if ($sumSpecial && !$baseSpecial) {
            return false;
        }

        return self::nodeRangeOverlap($base, $sum);
    }

    /** 两个环节的起止时间段是否重叠（平台两处时间可能相差 1~2 秒，留容差） */
    private static function nodeRangeOverlap(array $a, array $b): bool
    {
        $ra = self::nodeRange($a);
        $rb = self::nodeRange($b);
        if ($ra === null || $rb === null) {
            return false;
        }
        $tolerance = 120;
        return $ra[0] <= $rb[1] + $tolerance && $rb[0] <= $ra[1] + $tolerance;
    }

    /** 环节时间区间；只有一端时间时按单点处理 */
    private static function nodeRange(array $n): ?array
    {
        $start = !empty($n['start_time']) ? strtotime((string)$n['start_time']) : null;
        $end = !empty($n['end_time']) ? strtotime((string)$n['end_time']) : null;
        if ($start === null && $end === null) {
            return null;
        }
        $start = $start ?? $end;
        $end = $end ?? $start;
        return [(int)$start, (int)$end];
    }

    /**
     * 从 BaseInfo 或 result 任意层级提取 FLOWXML。
     * 兼容平台字段名大小写变化、以及 FLOWXML 意外放在 result 根节点的情况。
     */
    private static function extractFlowPayload(array $base, array $result): mixed
    {
        foreach (['FLOWXML', 'FLOW_XML', 'FLOWXMLS', 'flowXml', 'flowxml'] as $key) {
            if (array_key_exists($key, $base) && $base[$key] !== null && $base[$key] !== '') {
                return $base[$key];
            }
        }
        $found = self::searchKeyRecursive($result, '/^flow_?xmls?$/i', 0);
        return $found;
    }

    /** 递归按 key 正则查找（返回第一个非空值） */
    private static function searchKeyRecursive(mixed $data, string $pattern, int $depth): mixed
    {
        if ($depth > 6 || !is_array($data)) {
            return null;
        }
        foreach ($data as $k => $v) {
            if (is_string($k) && preg_match($pattern, $k) && !is_array($v) && $v !== null && trim((string)$v) !== '') {
                return $v;
            }
            if (is_array($v)) {
                $found = self::searchKeyRecursive($v, $pattern, $depth + 1);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    /**
     * 解析 FLOWXML：支持 XML 字符串、JSON 字符串、已解码数组。
     *
     * @return array<int,array>
     */
    private static function parseFlowPayload(mixed $flow): array
    {
        if (is_array($flow)) {
            $list = self::findNodeArrays($flow);
            return $list ? self::nodesFromArrays($list) : [];
        }

        $text = trim((string)$flow);
        if ($text === '') {
            return [];
        }

        // 少数网关会把 XML 再 JSON 编码一次
        $first = $text[0];
        if ($first === '{' || $first === '[') {
            $decoded = json_decode($text, true);
            if (is_array($decoded)) {
                $list = self::findNodeArrays($decoded);
                if ($list) {
                    return self::nodesFromArrays($list);
                }
                // 有些网关把整个对象再 JSON 编码一次：{"FLOWXML":"<FLOW>..."}
                $inner = self::extractFlowPayload($decoded, $decoded);
                if ($inner !== null && $inner !== $text) {
                    return self::parseFlowPayload($inner);
                }
            }
        }

        // HTML 实体转义 / BOM
        if (str_contains($text, '&lt;')) {
            $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
        $text = preg_replace('/^\xEF\xBB\xBF/u', '', $text) ?? $text;

        $out = [];
        $xml = self::loadXml($text);
        if ($xml !== false) {
            // 关键：local-name 大小写不敏感，兼容 <NODE> / <Node> / <node>
            $xpath = "//*[translate(local-name(), 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ')='NODE']";
            $els = $xml->xpath($xpath);
            if (is_array($els)) {
                foreach ($els as $n) {
                    if (!$n instanceof \SimpleXMLElement) {
                        continue;
                    }
                    $fields = [
                        'NODENAME'     => self::xmlText($n, ['NODENAME', 'NODE_NAME', 'STEPNAME', 'STEP_NAME']),
                        'NAME'         => self::xmlText($n, ['NAME', 'HANDLERNAME', 'HANDLER_NAME']),
                        'APPROVER'     => self::xmlText($n, [
                            'APPROVER', 'APPROVERNAME', 'APPROVER_NAME', 'APPROVE_NAME',
                            'APPROVEUSER', 'APPROVE_USER', 'APPROVEPERSON', 'APPROVE_PERSON',
                            'SPNAME', 'SP_NAME', 'SPUSER', 'SP_USER',
                            'AUDITUSER', 'AUDITNAME', 'AUDIT_NAME', 'AUDITOR',
                            'CHECKER', 'CHECKNAME', 'CHECK_NAME',
                            'DEALUSER', 'DEALNAME', 'DEAL_NAME',
                            'BLR', 'BLNAME', 'HANDLENAME', 'HANDLE_NAME',
                        ]),
                        'STEP'         => self::xmlText($n, ['STEP', 'NODESTEP', 'NODE_STEP']),
                        'STATE'        => self::xmlText($n, ['STATE', 'NODESTATE', 'NODE_STATE']),
                        'STARTTIME'    => self::xmlText($n, ['STARTTIME', 'START_TIME', 'BEGINTIME', 'BEGIN_TIME']),
                        'ENDTIME'      => self::xmlText($n, ['ENDTIME', 'END_TIME', 'FINISHTIME', 'FINISH_TIME']),
                        'STAYTIME'     => self::xmlText($n, ['STAYTIME', 'STAY_TIME']),
                        'OPINION'      => self::xmlText($n, ['OPINION', 'OPINIONS']),
                    ];
                    // v2.90：FLOWXML 里的 SPECIALS/SPECIAL（现场勘查等特别程序），原文取出
                    $special = self::xmlSpecials($n);
                    if ($special !== null) {
                        $fields['SPECIALNAME']        = $special['name'];
                        $fields['SPECIALREASON']      = $special['reason'];
                        $fields['SPECIALSTARTTIME']   = $special['start'];
                        $fields['SPECIALENDTIME']     = $special['end'];
                    }
                    $node = self::nodeFromFields($fields);
                    if ($node !== null) {
                        $out[] = $node;
                    }
                }
            }
        }

        // simplexml 失败（非法字符 / 未转义 &）或节点结构异常时，用正则兜底
        if (!$out) {
            $out = self::parseXmlNodeBlocks($text);
        }
        return $out;
    }

    /** 容错 XML 解析：simplexml 失败时按 <NODE>...</NODE> 分块提取 */
    private static function parseXmlNodeBlocks(string $xml): array
    {
        if (!preg_match_all('/<([A-Za-z0-9_:.\-]*NODE)\b[^>]*>(.*?)<\/\1\s*>/is', $xml, $matches, PREG_SET_ORDER)) {
            return [];
        }
        $out = [];
        foreach ($matches as $m) {
            $block = (string)($m[2] ?? '');
            $fields = [];
            if (preg_match_all('/<([A-Za-z0-9_:.\-]+)\b[^>]*>(.*?)<\/\1\s*>/is', $block, $fm, PREG_SET_ORDER)) {
                foreach ($fm as $f) {
                    $tag = strtoupper((string)preg_replace('/^.*:/', '', (string)$f[1]));
                    $value = (string)($f[2] ?? '');
                    $value = preg_replace('/<!\[CDATA\[(.*?)\]\]>/is', '$1', $value) ?? $value;
                    $fields[$tag] = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                }
            }
            // v2.90：嵌套的 SPECIALS/SPECIAL 会被外层正则整块吃掉，这里单独取原文字段
            foreach ([
                'SPECIALNAME'       => 'SPECIALNAME',
                'SPECIALRESON'      => 'SPECIALREASON',
                'SPECIALREASON'     => 'SPECIALREASON',
                'SPECIALSTARTTIME'  => 'SPECIALSTARTTIME',
                'SPECIALENDTIME'    => 'SPECIALENDTIME',
            ] as $tag => $key) {
                if (preg_match('/<' . $tag . '\b[^>]*>(.*?)<\/' . $tag . '\s*>/is', $block, $tm)) {
                    $value = preg_replace('/<!\[CDATA\[(.*?)\]\]>/is', '$1', (string)$tm[1]) ?? (string)$tm[1];
                    if (trim($value) !== '') {
                        $fields[$key] = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                    }
                }
            }
            // 兼容 <NODE NODENAME="..." STATE="..."/> 属性写法
            if (!$fields && preg_match_all('/([A-Za-z0-9_:\-]+)\s*=\s*"([^"]*)"/', (string)($m[0] ?? ''), $am, PREG_SET_ORDER)) {
                foreach ($am as $a) {
                    $fields[strtoupper((string)preg_replace('/^.*:/', '', (string)$a[1]))] = trim(html_entity_decode((string)$a[2], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                }
            }
            $node = self::nodeFromFields($fields);
            if ($node !== null) {
                $out[] = $node;
            }
        }
        return $out;
    }

    /** 回退：解析 result.Nodes.Node */
    /**
     * 解析 result.Nodes.Node（平台办理动作清单）。
     *
     * v2.90：平台实际返回形态为 {"Nodes":{"Node":{...单对象...}}}，
     * 旧实现用 findNodeArrays 只认数组列表，导致「挂起（现场勘查）」整条丢失。
     */
    private static function parseNodesSummary(array $result): array
    {
        $nodes = null;
        foreach ($result as $k => $v) {
            if (is_string($k) && strcasecmp($k, 'Nodes') === 0) {
                $nodes = $v;
                break;
            }
        }
        if ($nodes === null) {
            return [];
        }

        $raw = $nodes;
        if (is_array($raw)) {
            foreach ($raw as $k => $v) {
                if (is_string($k) && in_array(strtoupper($k), ['NODE', 'ITEM', 'ROW'], true) && is_array($v)) {
                    $raw = $v;
                    break;
                }
            }
        }

        $rows = [];
        if (is_array($raw)) {
            if (self::isList($raw)) {
                foreach ($raw as $r) {
                    if (is_array($r)) {
                        $rows[] = $r;
                    }
                }
            } else {
                $rows = [$raw];
            }
        }
        if (!$rows) {
            $found = self::findNodeArrays($nodes);
            if ($found) {
                $rows = $found;
            }
        }
        if (!$rows) {
            return [];
        }
        return self::nodesFromArrays($rows);
    }

    /** 递归查找节点数组列表 */
    private static function findNodeArrays(mixed $data, int $depth = 0): array
    {
        if ($depth > 6 || !is_array($data)) {
            return [];
        }
        if (self::isList($data)) {
            $out = [];
            foreach ($data as $item) {
                if (is_array($item) && self::looksLikeNode($item)) {
                    $out[] = $item;
                }
            }
            if ($out) {
                return $out;
            }
        }
        foreach ($data as $k => $v) {
            if (!is_array($v)) {
                continue;
            }
            if (is_string($k) && in_array(strtoupper($k), ['NODE', 'NODES', 'NODELIST', 'NODE_LIST', 'FLOW', 'STEPS', 'STEPLIST'], true)) {
                $found = self::findNodeArrays($v, $depth + 1);
                if ($found) {
                    return $found;
                }
            }
        }
        foreach ($data as $v) {
            if (is_array($v)) {
                $found = self::findNodeArrays($v, $depth + 1);
                if ($found) {
                    return $found;
                }
            }
        }
        return [];
    }

    private static function isList(array $data): bool
    {
        if (function_exists('array_is_list')) {
            return array_is_list($data);
        }
        return array_keys($data) === range(0, count($data) - 1);
    }

    private static function looksLikeNode(array $row): bool
    {
        foreach ($row as $k => $v) {
            if (is_array($v)) {
                continue;
            }
            $key = strtoupper((string)$k);
            if (in_array($key, ['NODENAME', 'NODE_NAME', 'STEP', 'STATE', 'STARTTIME', 'START_TIME', 'ENDTIME', 'END_TIME', 'NODESTATE'], true)) {
                return true;
            }
        }
        return false;
    }

    private static function nodesFromArrays(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $fields = [];
            foreach ($row as $k => $v) {
                if (!is_array($v) && !is_object($v)) {
                    $fields[strtoupper((string)$k)] = trim((string)$v);
                }
            }
            $node = self::nodeFromFields($fields);
            if ($node !== null) {
                $out[] = $node;
            }
        }
        return $out;
    }

    /**
     * 统一节点字段：名称 / STEP / 状态 / 起止时间 / 意见 / 经办人
     *
     * @param array<string,string> $fields 已统一为大写 key
     */
    private static function nodeFromFields(array $fields): ?array
    {
        $pick = static function (array $keys) use ($fields): string {
            foreach ($keys as $key) {
                if (isset($fields[$key]) && trim((string)$fields[$key]) !== '') {
                    return trim((string)$fields[$key]);
                }
            }
            return '';
        };

        $name = $pick(['NODENAME', 'NODE_NAME', 'STEPNAME', 'STEP_NAME', 'NODE_TITLE']);
        $handler = $pick(['NAME', 'HANDLERNAME', 'HANDLER_NAME', 'OPERATOR', 'USERNAME']);
        // v2.70：审批人。各地市 FLOWXML 字段名不统一，只认显式的审批人别名字段；
        // 平台若仅返回 NAME / HANDLERNAME，则留给 handler，前端按
        // approver_name || handler_name 兜底展示，避免把环节名误当审批人。
        $approver = $pick([
            'APPROVER', 'APPROVERNAME', 'APPROVER_NAME', 'APPROVE_NAME',
            'APPROVEUSER', 'APPROVE_USER', 'APPROVEPERSON', 'APPROVE_PERSON',
            'SPNAME', 'SP_NAME', 'SPUSER', 'SP_USER',
            'AUDITUSER', 'AUDITNAME', 'AUDIT_NAME', 'AUDITOR',
            'CHECKER', 'CHECKNAME', 'CHECK_NAME',
            'DEALUSER', 'DEALNAME', 'DEAL_NAME',
            'BLR', 'BLNAME', 'HANDLENAME', 'HANDLE_NAME',
        ]);
        if ($name === '' && $handler !== '') {
            // 个别平台用 NAME 表示环节名，此时不再重复作为经办人
            $name = $handler;
            $handler = '';
        }

        $step = (int)$pick(['STEP', 'NODESTEP', 'NODE_STEP', 'SORT']);
        $state = (int)$pick(['STATE', 'NODESTATE', 'NODE_STATE']);
        $start = self::normalizeDateTime($pick(['STARTTIME', 'START_TIME', 'BEGINTIME', 'BEGIN_TIME']));
        $end = self::normalizeDateTime($pick(['ENDTIME', 'END_TIME', 'FINISHTIME', 'FINISH_TIME']));
        if ($state < 1 || $state > 3) {
            $state = $end !== null ? 1 : ($start !== null ? 2 : 3);
        }

        // v2.90：意见类型 / 操作类型 / 特别程序，全部为政务网原文字段
        $opinionType   = self::cleanText($pick(['OPINION_TYPE', 'OPINIONTYPE']));
        $action        = self::cleanText($pick(['ACTION', 'OPERATION']));
        $specialName   = self::cleanText($pick(['SPECIALNAME', 'SPECIAL_NAME']));
        $specialReason = self::cleanText($pick(['SPECIALRESON', 'SPECIALREASON', 'SPECIAL_REASON', 'SPECIAL_RESON']));
        $isSuspended   = preg_match('/挂起|暂停|中止/u', $name . $opinionType) === 1 ? 1 : 0;

        if ($name === '' && $step === 0 && $start === null && $end === null) {
            return null;
        }

        return [
            'step'         => $step,
            'sort'         => 0,
            'node_name'    => $name,
            'state'        => $state,
            'start_time'   => $start,
            'end_time'     => $end,
            'stay_time'    => self::cleanText($pick(['STAYTIME', 'STAY_TIME'])),
            'opinion'      => self::cleanText($pick(['OPINION', 'OPINIONS'])),
            'opinion_type' => $opinionType,
            'action'       => $action,
            'is_suspended' => $isSuspended,
            'special_name' => $specialName,
            'special_reason'      => $specialReason,
            'special_start_time'  => self::normalizeDateTime($pick(['SPECIALSTARTTIME', 'SPECIAL_START_TIME'])),
            'special_end_time'    => self::normalizeDateTime($pick(['SPECIALENDTIME', 'SPECIAL_END_TIME'])),
            'handler_name' => self::cleanText($handler),
            'approver_name' => self::cleanText($approver),
        ];
    }

    /** simplexml 容错加载（先严格，再 RECOVER） */
    private static function loadXml(string $text): \SimpleXMLElement|false
    {
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($text, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET | LIBXML_COMPACT);
        if ($xml === false && defined('LIBXML_RECOVER')) {
            $xml = simplexml_load_string($text, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET | LIBXML_COMPACT | LIBXML_RECOVER);
        }
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $xml;
    }

    /**
     * 读取节点子元素文本（大小写不敏感，兼容默认命名空间）。
     *
     * @param array<int,string> $names
     */
    private static function xmlText(\SimpleXMLElement $node, array $names): string
    {
        $wanted = array_map('strtoupper', $names);
        // 先按直接子元素遍历（对默认命名空间最可靠）
        foreach ($node->children() as $key => $child) {
            if (in_array(strtoupper((string)$key), $wanted, true)) {
                return trim((string)$child);
            }
        }
        // 再按 local-name 查找（兼容前缀命名空间）
        foreach ($names as $name) {
            foreach ([$name, strtolower($name), ucfirst(strtolower($name))] as $variant) {
                $found = $node->xpath('./*[local-name()="' . $variant . '"]');
                if (is_array($found) && isset($found[0])) {
                    return trim((string)$found[0]);
                }
            }
        }
        return '';
    }

    /**
     * v2.90：读取节点下的 SPECIALS/SPECIAL（特别程序，如「现场勘查」）。
     * 只取平台原文字段：SPECIALNAME / SPECIALRESON / SPECIALSTARTTIME / SPECIALENDTIME。
     *
     * @return array{name:string,reason:string,start:?string,end:?string}|null
     */
    private static function xmlSpecials(\SimpleXMLElement $node): ?array
    {
        $els = $node->xpath('.//*[translate(local-name(), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="special"]');
        if (!is_array($els) || !$els) {
            return null;
        }
        $names = [];
        $reasons = [];
        $start = null;
        $end = null;
        foreach ($els as $el) {
            if (!$el instanceof \SimpleXMLElement) {
                continue;
            }
            $name   = trim(self::xmlText($el, ['SPECIALNAME', 'SPECIAL_NAME']));
            $reason = trim(self::xmlText($el, ['SPECIALRESON', 'SPECIALREASON', 'SPECIAL_REASON', 'SPECIAL_RESON']));
            $s = self::normalizeDateTime(self::xmlText($el, ['SPECIALSTARTTIME', 'SPECIAL_START_TIME']));
            $e = self::normalizeDateTime(self::xmlText($el, ['SPECIALENDTIME', 'SPECIAL_END_TIME']));
            if ($name !== '') {
                $names[$name] = true;
            }
            if ($reason !== '') {
                $reasons[$reason] = true;
            }
            if ($start === null && $s !== null) {
                $start = $s;
            }
            if ($e !== null && ($end === null || $e > $end)) {
                $end = $e;
            }
        }
        if (!$names && !$reasons) {
            return null;
        }
        return [
            'name'   => implode('、', array_keys($names)),
            'reason' => implode("\n", array_keys($reasons)),
            'start'  => $start,
            'end'    => $end,
        ];
    }

    /**
     * 将平台 BaseInfo + 环节解析为系统字段
     */
    public static function analyze(array $base, array $nodes): array
    {
        $handle = self::cleanText((string)($base['HANDLESTATE'] ?? ''));
        $signNode = null;
        $suspendedNode = null;
        $allDone = true;
        $realTotal = 0;
        $done = 0;
        $maxEnd = null;
        foreach ($nodes as $n) {
            // v2.90：挂起环节是平台办理动作，不计入办理进度分母
            if ((int)($n['is_suspended'] ?? 0) === 1) {
                if ($suspendedNode === null) {
                    $suspendedNode = $n;
                }
                continue;
            }
            $realTotal++;
            if ((int)$n['state'] === 1) {
                $done++;
            } else {
                $allDone = false;
            }
            if (!empty($n['end_time']) && ($maxEnd === null || $n['end_time'] > $maxEnd)) {
                $maxEnd = $n['end_time'];
            }
            if ($signNode === null && mb_strpos((string)$n['node_name'], '签发') !== false) {
                $signNode = $n;
            }
        }
        if ($realTotal === 0) {
            $allDone = false;
        }

        $signFinished = $signNode
            && ((int)$signNode['state'] === 1 || !empty($signNode['end_time']));
        $finished = $signFinished || $allDone || mb_strpos($handle, '办结') !== false;

        // v2.90：挂起判定与挂起意见。只使用政务网返回的原文：
        //   HANDLESTATE 含「挂起/暂停/中止」、Nodes.Node 中的挂起环节、FLOWXML 未结束的特别程序。
        // 平台给出明确状态时以平台当前状态为准，避免历史挂起环节在恢复办理后仍被判为挂起；
        // 仅在平台未返回 HANDLESTATE 时，才用挂起环节 / 特别程序兜底。
        // 平台没给意见时保持空字符串，不做任何推断或补写。
        $handleSuspended = preg_match('/挂起|暂停|中止/u', $handle) === 1;
        $specialActive = false;
        $specialReasons = [];
        $specialStart = null;
        foreach ($nodes as $n) {
            $spName   = trim((string)($n['special_name'] ?? ''));
            $spReason = trim((string)($n['special_reason'] ?? ''));
            if ($spName === '' && $spReason === '') {
                continue;
            }
            if (empty($n['special_end_time'])) {
                $specialActive = true;
            }
            if ($spReason !== '') {
                $specialReasons[] = $spReason;
            }
            if ($specialStart === null && !empty($n['special_start_time'])) {
                $specialStart = (string)$n['special_start_time'];
            }
        }
        $nodeSuspended = $suspendedNode !== null || $specialActive;
        $isSuspended = (!$finished && ($handleSuspended || ($handle === '' && $nodeSuspended))) ? 1 : 0;

        $suspendReason = '';
        $suspendTime = null;
        if ($isSuspended) {
            $parts = [];
            if ($suspendedNode !== null) {
                $op = trim((string)($suspendedNode['opinion'] ?? ''));
                if ($op !== '') {
                    $parts[] = $op;
                }
            }
            foreach ($specialReasons as $r) {
                if (!in_array($r, $parts, true)) {
                    $parts[] = $r;
                }
            }
            $suspendReason = implode("\n", $parts);
            if ($suspendedNode !== null && !empty($suspendedNode['start_time'])) {
                $suspendTime = (string)$suspendedNode['start_time'];
            } elseif ($specialStart !== null) {
                $suspendTime = $specialStart;
            }
        }

        $current = null;
        foreach ($nodes as $n) {
            if ((int)$n['state'] === 2) {
                $current = $n;
                break;
            }
        }
        if ($current === null) {
            foreach ($nodes as $n) {
                if ((int)$n['state'] === 3) {
                    $current = $n;
                    break;
                }
            }
        }
        if ($current === null && $nodes) {
            $current = end($nodes) ?: null;
        }

        // v2.99.40：退件/补正判定。只认政务网原文（HANDLESTATE / 当前环节名称 / 环节意见），
        // 不做关键词之外的推断；已办结事项不再判为退件。
        $returnText = $handle;
        if ($current) {
            $returnText .= ' ' . (string)($current['node_name'] ?? '') . ' ' . (string)($current['opinion'] ?? '');
        }
        $isReturned = (!$finished && preg_match('/退件|退回|补正|不予受理|驳回|退办|材料不齐/u', $returnText) === 1) ? 1 : 0;
        $returnReason = '';
        $returnTime   = null;
        if ($isReturned) {
            $opinion = trim((string)($current['opinion'] ?? ''));
            $returnReason = $opinion !== '' ? $opinion : $handle;
            if ($current && !empty($current['start_time'])) {
                $returnTime = (string)$current['start_time'];
            }
        }

        $finishTime = null;
        if ($finished) {
            if ($signNode && !empty($signNode['end_time'])) {
                $finishTime = $signNode['end_time'];
            } elseif ($maxEnd !== null) {
                $finishTime = $maxEnd;
            } else {
                $finishTime = date('Y-m-d H:i:s');
            }
        }

        $total = $realTotal;
        $percent = $total > 0 ? (int)round($done / $total * 100) : 0;
        if ($finished) {
            $percent = 100;
        }

        $projectName = self::cleanText((string)($base['PROJECTNAME'] ?? ''));
        if ($projectName === '') {
            $projectName = self::cleanText((string)($base['SERVICENAME'] ?? ''));
        }
        $acceptTime = self::normalizeDateTime((string)($base['ACCEPTTIME'] ?? ''));
        if ($acceptTime === null) {
            $acceptTime = self::normalizeDateTime((string)($base['RECEIVETIME'] ?? ''));
        }

        return [
            'project_name'     => mb_substr($projectName, 0, 255),
            'service_name'     => mb_substr(self::cleanText((string)($base['SERVICENAME'] ?? '')), 0, 255),
            'dept_name'        => mb_substr(self::cleanText((string)($base['DEPTNAME'] ?? '')), 0, 128),
            'apply_name'       => mb_substr(self::cleanText((string)($base['APPLYNAME'] ?? '')), 0, 128),
            'accept_time'      => $acceptTime,
            'promise_time'     => self::normalizeDate((string)($base['PROMISEETIME'] ?? '')),
            'finish_time'      => $finishTime,
            'handle_state'     => mb_substr($handle, 0, 32),
            'is_finished'      => $finished ? 1 : 0,
            // v2.90：挂起状态 / 挂起意见 / 挂起时间（政务网原文，未返回时为空）
            'is_suspended'     => $isSuspended,
            'suspend_reason'   => mb_substr($suspendReason, 0, 2000),
            'suspend_time'     => $suspendTime,
            // v2.99.40：退件/补正（政务网原文）
            'is_returned'      => $isReturned,
            'return_reason'    => mb_substr($returnReason, 0, 2000),
            'return_time'      => $returnTime,
            'current_node'     => $current ? mb_substr((string)$current['node_name'], 0, 64) : ($finished ? '已办结' : ''),
            'current_state'    => $current ? (int)$current['state'] : ($finished ? 1 : 0),
            'node_total'       => $total,
            'node_done'        => $done,
            'progress_percent' => $percent,
        ];
    }

    // ============================================================
    // 申报进度变更推送（v2.73）
    // ============================================================

    /**
     * 申报进度更新推送：状态有变化时，按「申报进度(gov_progress)」消息模板，
     * 通过已启用推送渠道推送给企业接收人 / 全局接收人 / 关联人员，并写 push_msg_log。
     *
     * 复用既有推送系统：PushChannel + CompanyReceiver + MsgTemplate::render + PushService::send。
     * 仅当 current_node / current_state / is_finished / is_archived / progress_percent 等
     * 状态字段发生变化时推送，避免每次同步轰炸。
     *
     * @param array $before 更新前记录
     * @param array $after  更新后记录
     * @param array $ctx    上下文（action=sync/save/archive/repair 等）
     * @return array{pushed:bool,channels:int,count:int,fail:int,reason:string}
     */
    public static function notifyProgressChanged(array $before, array $after, array $ctx = []): array
    {
        $changes = self::diffProgress($before, $after);
        // v2.99.40：先判定「是否有新的环节/关键事件」。
        // 仅进度百分比、平台状态文字等字段变化（环节没有更新）不再推送，
        // 避免手动同步 / 定时同步每次都发通知。
        // v2.99.69：同步场景下带上环节清单指纹比对结果，清单完全没变时
        // 一律不因 current_node 文本抖动而推送。
        $nodesChanged = array_key_exists('nodes_changed', $ctx) ? (bool)$ctx['nodes_changed'] : null;
        $events = self::changeEvents($before, $after, $nodesChanged);
        // v2.90.2：升级前已挂起、但没有发出挂起通知的记录，允许强制补发一次
        $forceSuspend = !empty($ctx['force_suspend']) && (int)($after['is_suspended'] ?? 0) === 1;
        if ($forceSuspend && !in_array('suspended', array_column($events, 'event'), true)) {
            $events[] = ['event' => 'suspended', 'label' => '挂起', 'key' => true];
            $changes['is_suspended'] = $changes['is_suspended']
                ?? ['label' => '挂起状态', 'old' => '0', 'new' => '1'];
        }
        if (empty($events)) {
            return [
                'pushed' => false, 'channels' => 0, 'count' => 0, 'fail' => 0,
                'reason' => '环节未更新，不推送', 'skipped' => true, 'events' => [],
            ];
        }
        // 推送范围：全环节 / 仅关键节点（受理·办结·挂起·退件）
        $scope = PushSceneService::govScope();
        if ($scope === PushSceneService::GOV_SCOPE_KEY) {
            $keyEvents = array_values(array_filter($events, fn($e) => !empty($e['key'])));
            if (empty($keyEvents)) {
                return [
                    'pushed' => false, 'channels' => 0, 'count' => 0, 'fail' => 0,
                    'reason' => '推送规则为「仅关键节点」，本次环节变化不在受理/办结/挂起/退件范围',
                    'skipped' => true, 'events' => $events,
                ];
            }
            $events = $keyEvents;
        }
        $companyId = (int)($after['company_id'] ?? $before['company_id'] ?? 0);
        if ($companyId <= 0) {
            return ['pushed' => false, 'channels' => 0, 'count' => 0, 'fail' => 0, 'reason' => '未关联企业，不推送'];
        }
        try {
            // 老库自愈：确保 gov_progress 场景默认模板已种入
            \app\service\CertExpireService::ensureMsgTemplates();
        } catch (\Throwable $e) {
            // 模板自愈失败不阻断推送（回退默认文案）
        }

        $channels = PushChannel::where('is_enable', 1)->order('sort', 'asc')->order('id', 'asc')->select();
        if ($channels->isEmpty()) {
            return ['pushed' => false, 'channels' => 0, 'count' => 0, 'fail' => 0, 'reason' => '无已启用推送渠道'];
        }

        $company = Db::name('client_company')->where('id', $companyId)->find();
        $staff   = null;
        $staffId = (int)($after['staff_id'] ?? $before['staff_id'] ?? 0);
        if ($staffId > 0) {
            $staff = Db::name('staff')->where('id', $staffId)->find();
        }
        $data = self::pushTemplateData($before, $after, $company, $staff, $changes);

        $channelsUsed = 0;
        $pushed = 0;
        $fail   = 0;
        foreach ($channels as $channel) {
            $tpl = MsgTemplate::where('scene', 'gov_progress')
                ->where('template_type', $channel->channel_type)
                ->find();
            $content = $tpl
                ? MsgTemplate::render($tpl->content, $data)
                : self::defaultGovContent($channel->channel_type, $data);
            // 模板引用了 {{detail_url}} 但拿不到生产域名 → 去掉残留的「查看详情」空行，
            // 避免推送出「查看详情：」或「[查看详情]()」这种点不开的内容
            if ($tpl && ($data['detail_url'] ?? '') === ''
                && str_contains((string)$tpl->content, '{{detail_url}}')) {
                $content = strip_empty_detail_link($content);
            }
            // v2.90.2：老库已存在的自定义模板通常不含挂起变量，这里统一追加挂起/恢复提醒，
            // 保证挂起状态一定会通知到接收人（内容仍为政务网原文）。
            $content = self::appendSuspendNotice($content, $data, $changes);
            $receivers = self::resolveGovReceivers($companyId, $channel->channel_type, $staff, 'gov_progress');
            if (empty($receivers)) {
                continue;
            }
            $channelsUsed++;
            foreach ($receivers as $rcv) {
                $log = PushMsgLog::create([
                    'company_id'      => $companyId,
                    'cert_id'         => 0,
                    'channel_id'      => $channel->id,
                    'channel_type'    => $channel->channel_type,
                    'receiver_phone'  => $rcv['phone'] ?? '',
                    'receiver_email'  => $rcv['email'] ?? '',
                    'receiver_wecom'  => $rcv['wecom'] ?? '',
                    'msg_content'     => $content,
                    'send_status'     => 0,
                    'is_test'         => 0,
                ]);
                $res = PushService::send($channel, $rcv, $content);
                $log->send_status = $res['status'];
                $log->err_msg     = $res['status'] == 1 ? ($res['simulated'] ? '模拟发送：' . $res['msg'] : '') : ($res['msg'] ?? '');
                $log->cost_ms     = $res['cost_ms'] ?? 0;
                $log->save();
                if ($res['status'] == 1) {
                    $pushed++;
                } else {
                    $fail++;
                }
            }
        }

        return [
            'pushed'   => $pushed > 0,
            'channels' => $channelsUsed,
            'count'    => $pushed,
            'fail'     => $fail,
            'reason'   => $pushed > 0 ? '' : ($fail > 0 ? '推送失败' : '无可用接收人'),
        ];
    }

    /**
     * 对比更新前后的环节/关键事件（v2.99.40）
     *
     * 与 diffProgress() 的区别：本方法只关心「是否出现了新的环节或关键事件」，
     * 纯进度百分比 / 平台状态文字变化不算事件，因此不会触发推送。
     *
     * key=true 表示关键节点（受理 / 办结 / 挂起 / 退件），供「仅关键节点」规则使用。
     *
     * v2.99.69：$nodesChanged 为本次同步的「环节清单指纹是否变化」。
     * 政务网返回的 current_node 会因当前环节选择顺序、临时状态字段出现/消失而抖动，
     * 光比文本会在没有任何推进时误判成「环节更新」并推送通知。清单指纹未变化时
     * 只认关键事件（挂起/恢复/办结/退件）与已完成环节数增加，文本变化一律忽略。
     *
     * @param bool|null $nodesChanged null=未提供（归档/修复等场景，保持原口径）
     * @return array<int,array{event:string,label:string,key:bool}>
     */
    public static function changeEvents(array $before, array $after, ?bool $nodesChanged = null): array
    {
        $events = [];

        $nodeBefore = self::normalizeNodeName((string)($before['current_node'] ?? ''));
        $nodeAfter  = self::normalizeNodeName((string)($after['current_node'] ?? ''));
        $handle     = trim((string)($after['handle_state'] ?? ''));

        // 已完成环节数增加：即使当前环节名称恰好相同（多轮同名环节），也算环节推进
        $nodeAdvanced = (int)($after['node_done'] ?? 0) > (int)($before['node_done'] ?? 0);

        $susBefore = (int)($before['is_suspended'] ?? 0) === 1;
        $susAfter  = (int)($after['is_suspended'] ?? 0) === 1;
        $finBefore = (int)($before['is_finished'] ?? 0) === 1;
        $finAfter  = (int)($after['is_finished'] ?? 0) === 1;
        $retBefore = (int)($before['is_returned'] ?? 0) === 1;
        $retAfter  = (int)($after['is_returned'] ?? 0) === 1;

        if (!$susBefore && $susAfter) {
            $events[] = ['event' => 'suspended', 'label' => '挂起', 'key' => true];
        } elseif ($susBefore && !$susAfter) {
            $events[] = ['event' => 'resumed', 'label' => '恢复办理', 'key' => true];
        } elseif ($susAfter && $susBefore
            && trim((string)($before['suspend_reason'] ?? '')) !== trim((string)($after['suspend_reason'] ?? ''))) {
            $events[] = ['event' => 'suspend_note', 'label' => '挂起意见更新', 'key' => true];
        }

        if (!$finBefore && $finAfter) {
            $events[] = ['event' => 'finished', 'label' => '办结', 'key' => true];
        }

        if (!$retBefore && $retAfter) {
            $events[] = ['event' => 'returned', 'label' => '退件/补正', 'key' => true];
        }

        // 环节名称变化：先识别关键节点，剩余情况归为普通环节更新。
        // v2.99.69：环节清单指纹明确未变化且完成数没增加时，current_node 的文本差异
        // 属于平台抖动，不产生任何事件（手动同步无更新 → 不推送）。
        $nodeEvent = false;
        if ($nodeAfter !== '' && $nodeAfter !== $nodeBefore
            && ($nodesChanged !== false || $nodeAdvanced)) {
            $keyMatched = false;
            if (preg_match('/受理|收件|接件/u', $nodeAfter) === 1) {
                $events[] = ['event' => 'accepted', 'label' => '受理', 'key' => true];
                $keyMatched = true;
            }
            if (preg_match('/退件|退回|补正|不予受理|驳回|退办/u', $nodeAfter . ' ' . $handle) === 1) {
                $events[] = ['event' => 'returned', 'label' => '退件/补正', 'key' => true];
                $keyMatched = true;
            }
            if (!$keyMatched) {
                $events[] = ['event' => 'node', 'label' => '环节更新：' . $nodeAfter, 'key' => false];
            }
            $nodeEvent = true;
        }

        // 环节推进但当前环节名称没变（例如多轮同名审核）：按普通环节更新推送
        if (!$nodeEvent && $nodeAdvanced) {
            $events[] = [
                'event' => 'node',
                'label' => $nodeAfter !== '' ? '环节推进：' . $nodeAfter : '环节推进',
                'key'   => false,
            ];
        }

        // 去重（同一事件可能出现多次）
        $seen = [];
        $out  = [];
        foreach ($events as $e) {
            if (isset($seen[$e['event']])) {
                continue;
            }
            $seen[$e['event']] = true;
            $out[] = $e;
        }
        return $out;
    }

    /**
     * 环节清单指纹（v2.99.69）
     *
     * 用于判断两次同步之间「环节是否真的发生变化」：只取环节身份与办理状态
     * （名称 / 状态 / 步骤 / 起止时间 / 挂起与特别程序标记），不取经办人、
     * 停留时长、意见等易变信息，也不取返回顺序。清单完全相同即视为无环节更新。
     *
     * @param array<int,array> $nodes 可以是 parseNodes() 的结果，也可以是 gov_progress_node 的行
     */
    public static function nodesFingerprint(array $nodes): string
    {
        $lines = [];
        foreach ($nodes as $n) {
            if (!is_array($n)) {
                continue;
            }
            $lines[] = implode('|', [
                // 与 gov_progress_node.node_name 落库长度保持一致，避免长名称被截断后每次同步都判为变化
                mb_substr(self::normalizeNodeName((string)($n['node_name'] ?? '')), 0, 64),
                (int)($n['state'] ?? 0),
                (int)($n['step'] ?? 0),
                (string)($n['start_time'] ?? ''),
                (string)($n['end_time'] ?? ''),
                (int)($n['is_suspended'] ?? 0),
                (string)($n['special_name'] ?? ''),
                (string)($n['special_start_time'] ?? ''),
                (string)($n['special_end_time'] ?? ''),
            ]);
        }
        sort($lines, SORT_STRING);
        return md5(implode("\n", $lines));
    }

    /** 环节名称归一：只消除空白差异（全角空格 / 连续空白 / BOM），保留平台原文 */
    private static function normalizeNodeName(string $name): string
    {
        $name = preg_replace('/^\xEF\xBB\xBF/u', '', $name) ?? $name;
        $name = str_replace(["\xC2\xA0", "\u{3000}"], ' ', $name);
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        return trim($name);
    }

    /**
     * 对比申报进度更新前后的状态字段，返回实际变化清单。
     *
     * @return array<string,array{label:string,old:string,new:string}>
     */
    protected static function diffProgress(array $before, array $after): array
    {
        $fields = [
            'current_node'     => '当前环节',
            'current_state'    => '环节状态',
            'handle_state'     => '平台状态',
            'is_suspended'     => '挂起状态',
            'suspend_reason'   => '挂起意见',
            'progress_percent' => '总进度',
            'is_finished'      => '是否办结',
            'is_archived'      => '是否归档',
        ];
        $changes = [];
        foreach ($fields as $key => $label) {
            $old = trim((string)($before[$key] ?? ''));
            $new = trim((string)($after[$key] ?? ''));
            if ($old !== $new) {
                $changes[$key] = ['label' => $label, 'old' => $old, 'new' => $new];
            }
        }
        return $changes;
    }

    /**
     * 组装 gov_progress 模板变量（{{key}} 渲染）
     */
    protected static function pushTemplateData(array $before, array $after, ?array $company, ?array $staff, array $changes): array
    {
        $finished = (int)($after['is_finished'] ?? 0) === 1;
        $suspended = (int)($after['is_suspended'] ?? 0) === 1;
        $nodeChange = $changes['current_node'] ?? null;
        // H5 端详情链接（消息模板里用 {{detail_url}} 引用）
        // 定时任务为 CLI 运行，拿不到生产域名时宁可不给链接，也不推送 localhost
        $progressId = (int)($after['id'] ?? $before['id'] ?? 0);
        $detailUrl  = $progressId > 0 ? h5_detail_url($progressId, 'pages/gov-progress/detail') : '';
        if ($detailUrl !== '' && !preg_match('#^https?://#i', $detailUrl)) {
            $detailUrl = '';
        }
        return [
            'company_name'      => $company['company_name'] ?? '',
            'staff_name'        => $staff['staff_name'] ?? '',
            'project_no'        => (string)($after['project_no'] ?? ''),
            'project_name'      => (string)($after['project_name'] ?? ''),
            'business_type'     => (string)($after['business_type'] ?? ''),
            'business_type_text'=> self::businessTypeText((string)($after['business_type'] ?? '')),
            'current_node'      => (string)($after['current_node'] ?? ''),
            'prev_node'         => $nodeChange ? (string)($nodeChange['old']) : '',
            'current_state'     => (string)($after['current_state'] ?? ''),
            'current_state_text'=> self::NODE_STATES[(int)($after['current_state'] ?? 0)] ?? '',
            'progress_percent'  => (string)(int)($after['progress_percent'] ?? 0),
            'is_finished'       => $finished ? '1' : '0',
            'is_finished_text'  => $finished ? '已办结' : '办理中',
            'handle_state'      => (string)($after['handle_state'] ?? ''),
            'is_suspended'      => $suspended ? '1' : '0',
            'is_suspended_text' => $suspended ? '已挂起' : '正常',
            'suspend_reason'    => (string)($after['suspend_reason'] ?? ''),
            'suspend_time'      => (string)($after['suspend_time'] ?? ''),
            'finish_time'       => (string)($after['finish_time'] ?? ''),
            'promise_time'      => (string)($after['promise_time'] ?? ''),
            'archive_time'      => (string)($after['archive_time'] ?? ''),
            'remark'            => (string)($after['remark'] ?? ''),
            'detail_url'        => $detailUrl,
        ];
    }

    /**
     * v2.90.2：去掉意见末尾的句号/空白，避免拼接提醒时出现「。。」。
     */
    private static function trimReasonEnd(string $reason): string
    {
        $reason = trim($reason);
        return trim((string)preg_replace('/[。．.；;\s]+$/u', '', $reason));
    }

    /**
     * v2.90.2：向已渲染内容追加挂起 / 恢复办理提醒。
     *
     * 老库中已有的「申报进度」模板只引用了 {{current_node}}，不会因为新增挂起字段
     * 自动变化；这里在发送前统一补一行提醒，确保挂起状态一定通知到接收人。
     * 挂起意见只使用政务网原文，未返回时只提示挂起、不补写原因。
     *
     * @param array<string,string> $data
     * @param array<string,array{label:string,old:string,new:string}> $changes
     */
    private static function appendSuspendNotice(string $content, array $data, array $changes): string
    {
        $suspended = (string)($data['is_suspended'] ?? '0') === '1';
        if ($suspended) {
            $reason = self::trimReasonEnd((string)($data['suspend_reason'] ?? ''));
            $hasSuspend = mb_strpos($content, '挂起') !== false;
            $hasReason  = $reason === '' || mb_strpos($content, $reason) !== false;
            if (!$hasSuspend || !$hasReason) {
                $line = '【挂起提醒】政务网已挂起该申报'
                    . ($reason !== '' ? '，挂起意见：' . $reason : '')
                    . '。';
                $content = rtrim($content) . "\n" . $line;
            }
            return $content;
        }

        // 从挂起恢复为正常办理时也提示一次，避免只看到旧通知。
        $old = (string)($changes['is_suspended']['old'] ?? '');
        if ($old === '1' && mb_strpos($content, '恢复办理') === false) {
            $content = rtrim($content) . "\n【恢复办理】政务网已恢复该申报办理。";
        }
        return $content;
    }

    /**
     * gov_progress 默认推送文案（模板缺失时兜底）
     */
    protected static function defaultGovContent(string $type, array $data): string
    {
        $subject = "{$data['company_name']} - {$data['project_name']}（申报号 {$data['project_no']}）";
        $node    = $data['current_node'] !== '' ? $data['current_node'] : $data['is_finished_text'];
        $link    = (string)($data['detail_url'] ?? '');
        if ($link !== '' && !preg_match('#^https?://#i', $link)) {
            $link = '';
        }
        $tail = $link !== '' ? "\n查看详情：" . $link : '';
        // v2.90：挂起提醒（挂起意见为政务网原文，未返回则不追加）
        $susp = '';
        if ((string)($data['is_suspended'] ?? '0') === '1') {
            $reason = self::trimReasonEnd((string)($data['suspend_reason'] ?? ''));
            $susp = '【挂起提醒】平台已挂起该申报' . ($reason !== '' ? '，挂起意见：' . $reason : '') . '。';
        }
        if ($type === 'sms') {
            return "【申报进度更新】{$data['company_name']}：{$data['project_name']}(申报号{$data['project_no']})当前环节「{$node}」，进度{$data['progress_percent']}%。" . $susp . $tail;
        }
        if ($type === 'email') {
            return "尊敬的{$data['company_name']}：您关注的申报事项{$data['project_name']}(申报号{$data['project_no']})进度已更新。当前环节：{$node}，总进度{$data['progress_percent']}%；{$data['is_finished_text']}。" . $susp . $tail;
        }
        if ($type === 'mp') {
            return "您关注的申报{$data['project_name']}({$data['project_no']})进度更新为「{$node}」，总进度{$data['progress_percent']}%。" . $susp . $tail;
        }
        return "📢 申报进度更新：{$subject} 当前环节「{$node}」，进度{$data['progress_percent']}%。" . $susp . $tail;
    }

    /**
     * 解析申报进度推送的接收人
     * - 企微群机器人：消息直接进群，无需接收人
     * - 企微应用消息 / 公众号：按 receiver_wecom 拆分（竖线分隔多选）
     * - 短信 / 邮件：企业接收人 + 全局接收人 + 关联人员本人
     */
    protected static function resolveGovReceivers(int $companyId, string $type, ?array $staff, string $scene = 'gov_progress'): array
    {
        if ($type === 'wecom') {
            return [['wecom' => '']];
        }

        PushSceneService::ensureSchema();
        $rcvs = CompanyReceiver::where('is_enable', 1)
            ->where(function ($q) use ($companyId) {
                $q->where('company_id', $companyId)->whereOr('is_global', 1);
            })
            ->select()
            ->filter(fn($r) => PushSceneService::receiverMatchesScene($r, $scene));

        // 企微应用消息：按 userid 精准推送
        if ($type === 'wecom_app') {
            $list = [];
            $seen = [];
            foreach ($rcvs as $r) {
                foreach (self::splitAccounts((string)$r->receiver_wecom) as $uid) {
                    if ($uid === '' || isset($seen[$uid])) {
                        continue;
                    }
                    $seen[$uid] = true;
                    $list[] = ['wecom' => $uid];
                }
            }
            return $list;
        }

        $list = [];
        $seen = [];
        $push = function (string $key, array $item) use (&$list, &$seen) {
            if ($key === '' || isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $list[] = $item;
        };

        foreach ($rcvs as $r) {
            if ($type === 'sms' && trim((string)$r->receiver_phone) !== '') {
                $push(trim((string)$r->receiver_phone), ['phone' => trim((string)$r->receiver_phone)]);
            } elseif ($type === 'email' && trim((string)$r->receiver_email) !== '') {
                $push(trim((string)$r->receiver_email), ['email' => trim((string)$r->receiver_email)]);
            } elseif ($type === 'mp') {
                foreach (self::splitAccounts((string)$r->receiver_wecom) as $openid) {
                    $openid = trim($openid);
                    if ($openid === '') {
                        continue;
                    }
                    $push($openid, ['openid' => $openid]);
                }
            }
        }

        // 关联人员本人
        if ($staff) {
            if ($type === 'sms' && !empty($staff['phone'])) {
                $push(trim((string)$staff['phone']), ['phone' => trim((string)$staff['phone'])]);
            }
            if ($type === 'email' && !empty($staff['email'])) {
                $push(trim((string)$staff['email']), ['email' => trim((string)$staff['email'])]);
            }
        }
        return $list;
    }

    /** 拆分竖线分隔的企微 userid / openid 多选值 */
    protected static function splitAccounts(string $s): array
    {
        return array_values(array_filter(array_map('trim', explode('|', $s)), fn($v) => $v !== ''));
    }

    // ============================================================
    // 同步
    // ============================================================

    /**
     * 同步单条申报
     *
     * @return array{ok:bool,msg:string,id:int,finished?:int,current_node?:string}
     */
    public static function syncRecord(int $id, ?int $operatorId = null): array
    {
        self::ensureTables();
        $row = Db::name('gov_progress')->where('id', $id)->find();
        if (!$row) {
            return ['ok' => false, 'msg' => '申报记录不存在', 'id' => $id];
        }

        try {
            $password = self::decryptPassword((string)($row['password_enc'] ?? ''));
            if ($password === '') {
                throw new \RuntimeException('未设置申报密码或密码解密失败，请重新填写');
            }
            $data = self::fetch((string)$row['project_no'], $password);
            $nodes = self::parseNodes($data['base'], $data['result']);
            $info  = self::analyze($data['base'], $nodes);

            $raw = json_encode($data['raw'], JSON_UNESCAPED_UNICODE);
            if ($raw === false) {
                $raw = '';
            }
            // mediumtext 足够存放；超长时宁可不存，也不写入被截断的非法 JSON
            $info['raw_json']        = ($raw !== '' && strlen($raw) <= 2000000) ? $raw : '';
            $info['last_sync_time']  = date('Y-m-d H:i:s');
            $info['last_sync_status'] = 1;
            $info['last_sync_error'] = '';

            // v2.63：办结即自动归档并停止每日同步；数据完整保留，可在「已归档」查看。
            $wasArchived = (int)($row['is_archived'] ?? 0) === 1;
            if ((int)$info['is_finished'] === 1) {
                $info['is_archived'] = 1;
                if (!$wasArchived || empty($row['archive_time'])) {
                    $info['archive_time'] = $info['finish_time'] ?: date('Y-m-d H:i:s');
                }
                $info['archive_source'] = $wasArchived
                    ? ((string)($row['archive_source'] ?? '') ?: 'auto')
                    : 'auto';
                $info['status'] = 0;
            } elseif ($wasArchived && (string)($row['archive_source'] ?? '') === 'auto') {
                // 平台回退为办理中（如补正/撤回），自动恢复为在办并重新纳入每日同步
                $info['is_archived']    = 0;
                $info['archive_time']   = null;
                $info['archive_source'] = '';
                $info['status']         = 1;
            }

            // v2.90.2：挂起通知标记。本次新挂起（含升级前已挂起但从未通知）时为 0，
            // 推送成功后置 1；恢复办理时清零，下次挂起重新通知。
            $wasSuspended    = (int)($row['is_suspended'] ?? 0) === 1;
            $suspendNotified = (int)($row['suspend_notified'] ?? 0);
            $info['suspend_notified'] = (int)$info['is_suspended'] === 1
                ? ($wasSuspended ? $suspendNotified : 0)
                : 0;

            $after = array_merge($row, $info);
            $changes = self::diffProgress($row, $after);
            $forceSuspendPush = (int)$info['is_suspended'] === 1 && (int)$info['suspend_notified'] === 0;

            // v2.99.69：记录同步前已落库的环节清单，与本次解析结果做指纹比对。
            // 指纹相同 = 平台没有任何环节推进，后续推送判定不再被 current_node 文本抖动影响。
            $nodesBefore = [];
            try {
                $nodesBefore = Db::name('gov_progress_node')->where('progress_id', $id)->select()->toArray();
            } catch (\Throwable $e) {
                $nodesBefore = [];
            }
            $nodesChanged = self::nodesFingerprint($nodesBefore) !== self::nodesFingerprint($nodes);

            Db::transaction(function () use ($id, $info, $nodes) {
                Db::name('gov_progress')->where('id', $id)->update($info);
                self::replaceNodes($id, $nodes);
            });

            $msg = '同步成功';
            if ((int)$info['is_finished'] === 1) {
                $msg = $wasArchived ? '同步成功，事项已办结（已归档）' : '同步成功，事项已办结并自动归档';
            }
            // v2.73：状态有变化时推送消息（复用推送系统 + gov_progress 消息模板）
            $push = ['pushed' => false, 'channels' => 0, 'count' => 0, 'fail' => 0, 'reason' => ''];
            if ($changes || $forceSuspendPush) {
                try {
                    $push = self::notifyProgressChanged($row, $after, [
                        'action'        => 'sync',
                        'operator_id'   => $operatorId,
                        'force_suspend' => $forceSuspendPush,
                        'nodes_changed' => $nodesChanged,
                    ]);
                    if (!empty($push['pushed'])) {
                        $msg .= $forceSuspendPush && empty($changes['is_suspended'])
                            ? '，挂起提醒已推送通知'
                            : '，进度变更已推送通知';
                        if ($forceSuspendPush) {
                            Db::name('gov_progress')->where('id', $id)->update(['suspend_notified' => 1]);
                        }
                    } elseif (!empty($push['skipped'])) {
                        // v2.99.40：环节未更新 / 未命中关键节点时不推送，只在返回结果里说明原因
                        $msg .= '，' . ($push['reason'] ?? '未触发推送');
                    }
                } catch (\Throwable $e) {
                    // 推送失败不影响同步结果
                }
            } else {
                // v2.99.69：平台数据与库内完全一致，明确告知未推送，避免用户以为漏发通知
                $msg .= '，未检测到环节更新，未推送通知';
            }
            return [
                'ok'           => true,
                'id'           => $id,
                'msg'          => $msg,
                'finished'     => $info['is_finished'],
                'archived'     => (int)($info['is_archived'] ?? ($wasArchived ? 1 : 0)),
                'current_node' => $info['current_node'],
                'changed'      => !empty($changes),
                'push'         => $push,
            ];
        } catch (\Throwable $e) {
            $msg = mb_substr(trim($e->getMessage()), 0, 500);
            try {
                Db::name('gov_progress')->where('id', $id)->update([
                    'last_sync_time'   => date('Y-m-d H:i:s'),
                    'last_sync_status' => 2,
                    'last_sync_error'  => $msg,
                ]);
            } catch (\Throwable $ignore) {
            }
            return ['ok' => false, 'id' => $id, 'msg' => $msg];
        }
    }

    /**
     * 覆盖写入某条申报的环节（sort = 平台原始顺序）
     *
     * @param array<int,array> $nodes
     */
    private static function replaceNodes(int $id, array $nodes): void
    {
        Db::name('gov_progress_node')->where('progress_id', $id)->delete();
        if (!$nodes) {
            return;
        }
        $now  = date('Y-m-d H:i:s');
        $rows = [];
        $i = 0;
        foreach ($nodes as $n) {
            $i++;
            // sort 使用平台 XML 原始顺序，step 仅保留平台原值，重复也不覆盖
            $sort = (int)($n['sort'] ?? 0);
            $rows[] = [
                'progress_id'  => $id,
                'step'         => (int)($n['step'] ?? 0),
                'node_name'    => mb_substr((string)$n['node_name'], 0, 64),
                'state'        => (int)$n['state'],
                'start_time'   => $n['start_time'],
                'end_time'     => $n['end_time'],
                'stay_time'    => mb_substr((string)$n['stay_time'], 0, 16),
                'opinion'      => mb_substr((string)$n['opinion'], 0, 2000),
                'opinion_type' => mb_substr((string)($n['opinion_type'] ?? ''), 0, 64),
                'action'       => mb_substr((string)($n['action'] ?? ''), 0, 32),
                'is_suspended' => (int)($n['is_suspended'] ?? 0),
                'special_name' => mb_substr((string)($n['special_name'] ?? ''), 0, 64),
                'special_reason' => mb_substr((string)($n['special_reason'] ?? ''), 0, 2000),
                'special_start_time' => $n['special_start_time'] ?? null,
                'special_end_time'   => $n['special_end_time'] ?? null,
                'handler_name' => mb_substr((string)$n['handler_name'], 0, 64),
                'approver_name' => mb_substr((string)($n['approver_name'] ?? ''), 0, 64),
                'sort'         => $sort > 0 ? $sort : $i,
                'create_time'  => $now,
                'update_time'  => $now,
            ];
        }
        Db::name('gov_progress_node')->insertAll($rows);
    }

    /**
     * v2.90：环节内容指纹，用于判断重新解析后是否需要覆盖（数量相同但内容变了也要更新）。
     */
    private static function nodesDigest(array $rows): string
    {
        $sig = [];
        foreach ($rows as $r) {
            $sig[] = implode('|', [
                (string)($r['sort'] ?? ''),
                (string)($r['node_name'] ?? ''),
                (string)($r['state'] ?? ''),
                (string)($r['start_time'] ?? ''),
                (string)($r['end_time'] ?? ''),
                (string)($r['opinion'] ?? ''),
                (string)($r['opinion_type'] ?? ''),
                (string)($r['action'] ?? ''),
                (string)($r['is_suspended'] ?? 0),
                (string)($r['special_name'] ?? ''),
                (string)($r['special_reason'] ?? ''),
                (string)($r['special_start_time'] ?? ''),
                (string)($r['special_end_time'] ?? ''),
            ]);
        }
        return md5(implode("\n", $sig));
    }

    /**
     * v2.95：检测历史环节是否因旧版合并逻辑出现重复。
     *
     * 旧版按「名称 + 开始时间 + 意见」判重，平台两处时间口径不同时会把
     * 同一条「受理」重复追加到流程末尾。这里用同一套判重规则做只读检测，
     * 供详情页自动从 raw_json 修复。
     */
    public static function hasDuplicateNodes(int $id): bool
    {
        try {
            $rows = Db::name('gov_progress_node')->where('progress_id', $id)
                ->field('node_name,step,state,start_time,end_time,opinion,is_suspended,special_name')
                ->order('sort', 'asc')->order('id', 'asc')->select()->toArray();
        } catch (\Throwable $e) {
            return false;
        }
        $count = count($rows);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if (self::isLikelyDuplicateNode($rows[$i], $rows[$j])) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * 历史重复环节判断：同名且 STEP 相同；任一方没有 STEP 时按时间段重叠判断。
     * 不同 STEP 的同名环节可能是平台真实的多轮流程，不视为重复。
     */
    private static function isLikelyDuplicateNode(array $a, array $b): bool
    {
        $name = (string)($a['node_name'] ?? '');
        if ($name === '' || $name !== (string)($b['node_name'] ?? '')) {
            return false;
        }
        $aStep = (int)($a['step'] ?? 0);
        $bStep = (int)($b['step'] ?? 0);
        if ($aStep > 0 && $bStep > 0) {
            return $aStep === $bStep;
        }
        return self::nodeRangeOverlap($a, $b);
    }

    /**
     * v2.64：用已保存的 raw_json 重新解析环节，修复历史数据「只显示 3 个环节」。
     *
     * 说明：不重新请求政务网（老接口可能限流 / 失败），只从上次原始响应重算；
     *       解析出的节点数多于现有节点或 $withState=true 时才写库。
     *
     * @return array{ok:bool,id:int,msg:string,before:int,after:int,added:int,changed:bool}
     */
    public static function repairNodesFromRaw(int $id, bool $withState = false): array
    {
        $row = Db::name('gov_progress')->where('id', $id)->find();
        if (!$row) {
            return ['ok' => false, 'id' => $id, 'msg' => '申报记录不存在', 'before' => 0, 'after' => 0, 'added' => 0, 'changed' => false];
        }
        $raw = (string)($row['raw_json'] ?? '');
        if (trim($raw) === '') {
            return ['ok' => false, 'id' => $id, 'msg' => '未保存平台原始响应，请点击「同步」重新获取', 'before' => 0, 'after' => 0, 'added' => 0, 'changed' => false];
        }
        $json = json_decode($raw, true);
        $result = is_array($json) && is_array($json['result'] ?? null) ? $json['result'] : [];
        if ($result) {
            $base  = is_array($result['BaseInfo'] ?? null) ? $result['BaseInfo'] : [];
            $nodes = self::parseNodes($base, $result);
        } else {
            // 历史记录可能因旧版截断导致 JSON 不完整：尽量从原文抢救 FLOWXML
            $flow = self::recoverJsonString($raw, 'FLOWXML');
            if ($flow === null || trim($flow) === '') {
                return ['ok' => false, 'id' => $id, 'msg' => '原始响应不是有效 JSON 且未找到 FLOWXML，请点击「同步」重新获取', 'before' => 0, 'after' => 0, 'added' => 0, 'changed' => false];
            }
            $base  = ['HANDLESTATE' => (string)(self::recoverJsonString($raw, 'HANDLESTATE') ?? '')];
            $nodes = self::parseNodes(['FLOWXML' => $flow]);
        }
        if (!$nodes) {
            return ['ok' => false, 'id' => $id, 'msg' => '原始响应中未解析到环节，请点击「同步」重新获取', 'before' => 0, 'after' => 0, 'added' => 0, 'changed' => false];
        }

        $existing = Db::name('gov_progress_node')->where('progress_id', $id)
            ->order('sort', 'asc')->order('id', 'asc')->select()->toArray();
        $before = count($existing);
        $after  = count($nodes);
        // v2.90：环节数量相同但名称/状态/意见有变化时也要更新（平台每次同步都可能调整）
        $changed = $after !== $before || $withState
            || self::nodesDigest($existing) !== self::nodesDigest($nodes);
        if (!$changed) {
            return ['ok' => true, 'id' => $id, 'msg' => '环节已完整，无需修复', 'before' => $before, 'after' => $before, 'added' => 0, 'changed' => false];
        }

        $info = self::analyze($base, $nodes);
        // v2.90.2：修复后按原挂起通知状态维护标记，避免重复推送或漏推
        $repairSuspended = (int)$info['is_suspended'] === 1;
        $data = [
            'current_node'     => $info['current_node'],
            'current_state'    => $info['current_state'],
            'handle_state'     => $info['handle_state'],
            'is_suspended'     => $info['is_suspended'],
            'suspend_reason'   => $info['suspend_reason'],
            'suspend_time'     => $info['suspend_time'],
            'suspend_notified' => $repairSuspended
                ? ((int)($row['is_suspended'] ?? 0) === 1 ? (int)($row['suspend_notified'] ?? 0) : 0)
                : 0,
            'node_total'       => $info['node_total'],
            'node_done'        => $info['node_done'],
            'progress_percent' => $info['progress_percent'],
            'update_time'      => date('Y-m-d H:i:s'),
        ];

        if ($withState) {
            $data['is_finished'] = $info['is_finished'];
            $data['finish_time'] = $info['finish_time'];
            // 修复后若已办结且未归档，与同步逻辑保持一致：自动归档并停止每日同步
            if ((int)$info['is_finished'] === 1 && (int)($row['is_archived'] ?? 0) !== 1) {
                $data['is_archived']    = 1;
                $data['archive_time']   = $info['finish_time'] ?: date('Y-m-d H:i:s');
                $data['archive_source'] = 'auto';
                $data['status']         = 0;
            }
        }

        $afterRow = array_merge($row, $data);
            $stateChanges = self::diffProgress($row, $afterRow);

            Db::transaction(function () use ($id, $data, $nodes) {
                Db::name('gov_progress')->where('id', $id)->update($data);
                self::replaceNodes($id, $nodes);
            });

            // v2.73：修复导致状态变化时推送消息（如环节推进 / 办结）
            if ($stateChanges) {
                try {
                    $push = self::notifyProgressChanged($row, $afterRow, ['action' => 'repair']);
                    if (!empty($push['pushed']) && $repairSuspended) {
                        Db::name('gov_progress')->where('id', $id)->update(['suspend_notified' => 1]);
                    }
                } catch (\Throwable $e) {
                    // 推送失败不影响修复结果
                }
            }

            return [
                'ok'            => true,
                'id'            => $id,
                'msg'           => "环节已修复：{$before} → {$after} 个",
                'before'        => $before,
                'after'         => $after,
                'added'         => max(0, $after - $before),
                'changed'       => true,
                'state_changed' => !empty($stateChanges),
            ];
    }

    /**
     * 从未必完整的 JSON 原文中提取某个字符串字段（用于抢救被截断的历史响应）
     */
    private static function recoverJsonString(string $raw, string $key): ?string
    {
        $pattern = '/"' . preg_quote($key, '/') . '"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s';
        if (!preg_match($pattern, $raw, $m)) {
            return null;
        }
        $decoded = json_decode('"' . $m[1] . '"', true);
        return is_string($decoded) ? $decoded : $m[1];
    }

    /**
     * v2.64：批量修复历史环节数据（从 raw_json 重解析，不访问政务网）。
     *
     * @return array{scanned:int,repaired:int,nodes_added:int,skipped:int,failed:int,items:array}
     */
    public static function repairAllNodes(int $limit = 2000): array
    {
        self::ensureTables();
        $limit = max(1, min(5000, $limit));
        $rows = Db::name('gov_progress')
            ->where('raw_json', '<>', '')
            ->field('id,is_archived,project_no')
            ->order('id', 'asc')->limit($limit)->select()->toArray();

        $res = ['scanned' => count($rows), 'repaired' => 0, 'nodes_added' => 0, 'skipped' => 0, 'failed' => 0, 'items' => []];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            // 未归档记录同时修复办结状态；已归档只补环节，避免误改归档
            $r = self::repairNodesFromRaw($id, (int)($row['is_archived'] ?? 0) !== 1);
            if (empty($r['ok'])) {
                $res['failed']++;
                if (count($res['items']) < 20) {
                    $res['items'][] = ['id' => $id, 'project_no' => (string)($row['project_no'] ?? ''), 'msg' => $r['msg']];
                }
                continue;
            }
            if (!empty($r['changed'])) {
                $res['repaired']++;
                $res['nodes_added'] += (int)$r['added'];
                if (count($res['items']) < 20) {
                    $res['items'][] = ['id' => $id, 'project_no' => (string)($row['project_no'] ?? ''), 'msg' => $r['msg']];
                }
            } else {
                $res['skipped']++;
            }
        }
        return $res;
    }

    /**
     * 批量同步（逐条失败不影响其他记录）
     *
     * @return array{total:int,ok:int,fail:int,items:array<int,array>}
     */
    public static function syncMany(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $summary = ['total' => count($ids), 'ok' => 0, 'fail' => 0, 'items' => []];
        if (!$ids) {
            return $summary;
        }
        $delay = max(0, (int)config('gov.request_delay_ms', 300));
        foreach ($ids as $i => $id) {
            if ($i > 0 && $delay > 0) {
                usleep($delay * 1000);
            }
            $r = self::syncRecord($id);
            $summary['items'][] = $r;
            if (!empty($r['ok'])) {
                $summary['ok']++;
            } else {
                $summary['fail']++;
            }
        }
        return $summary;
    }

    /**
     * 每日同步：只处理启用且未办结的申报
     *
     * @param array{limit?:int,include_finished?:bool} $opt
     */
    public static function syncPending(array $opt = []): array
    {
        self::ensureTables();
        $q = Db::name('gov_progress')->where('status', 1)->where('is_archived', 0);
        if (empty($opt['include_finished'])) {
            $q->where('is_finished', 0);
        }
        $limit = (int)($opt['limit'] ?? 0);
        if ($limit > 0) {
            $q->limit($limit);
        }
        $ids = $q->order('id', 'asc')->column('id');
        return self::syncMany($ids ?: []);
    }

    // ============================================================
    // 每日运行标记 / 展示字段
    // ============================================================

    /**
     * 定时同步最小间隔（小时）。
     *
     * v2.99.44：取消固定的 20 小时限制。默认返回 0 = 每次定时任务都执行；
     * 如确需降频，可在 config/gov.php 设置 daily_min_hours（或 .env 的 gov.daily_min_hours）为大于 0 的小时数。
     */
    public static function dailyMinHours(): int
    {
        $hours = (int)config('gov.daily_min_hours', 0);
        return $hours > 0 ? $hours : 0;
    }

    /**
     * 定时任务是否应当执行。
     *
     * 间隔为 0（默认）时永远执行，等同于去掉「未满 N 小时跳过」的限制。
     */
    public static function shouldRunDaily(int $minHours = 0): bool
    {
        $minHours = $minHours > 0 ? $minHours : self::dailyMinHours();
        if ($minHours <= 0) {
            return true;
        }
        $last = trim((string)sys_config('gov_sync_last_at', ''));
        if ($last === '') {
            return true;
        }
        $ts = strtotime($last);
        if ($ts === false) {
            return true;
        }
        return (time() - $ts) >= $minHours * 3600;
    }

    public static function lastRunAt(): string
    {
        $last = (string)sys_config('gov_sync_last_at', '');
        if ($last !== '') {
            return $last;
        }
        try {
            $last = (string)Db::name('gov_sync_log')->order('id', 'desc')->value('run_at');
        } catch (\Throwable $e) {
            $last = '';
        }
        return $last;
    }

    /**
     * 记录运行结果（system_config 供页面展示，runtime JSON 供服务器排障，gov_sync_log 供执行记录查询）
     *
     * @param array{total?:int,ok?:int,fail?:int,items?:array} $summary
     * @param string $trigger schedule=定时任务 manual=手动
     * @param array{duration_ms?:int,operator_id?:int,message?:string} $meta
     */
    public static function markRun(array $summary, string $trigger = 'schedule', array $meta = []): void
    {
        $now = date('Y-m-d H:i:s');
        $row = Db::name('system_config')->where('config_key', 'gov_sync_last_at')->find();
        if ($row) {
            Db::name('system_config')->where('id', $row['id'])->update(['config_value' => $now]);
        } else {
            Db::name('system_config')->insert([
                'config_key'   => 'gov_sync_last_at',
                'config_value' => $now,
                'remark'       => '政务网申报进度最近一次定时同步时间',
            ]);
        }

        $failed = array_values(array_filter((array)($summary['items'] ?? []), static function ($x) {
            return empty($x['ok']);
        }));
        $payload = [
            'ran_at'  => $now,
            'trigger' => $trigger,
            'total'   => (int)($summary['total'] ?? 0),
            'ok'      => (int)($summary['ok'] ?? 0),
            'fail'    => (int)($summary['fail'] ?? 0),
            'items'   => $failed,
        ];

        // 执行记录表：失败明细只保留 id / msg，避免日志膨胀
        try {
            ensure_table('gov_sync_log', self::syncLogDdl());
            $detail = [];
            foreach ($failed as $f) {
                $detail[] = [
                    'id'  => (int)($f['id'] ?? 0),
                    'msg' => mb_substr((string)($f['msg'] ?? ''), 0, 200),
                ];
            }
            Db::name('gov_sync_log')->insert([
                'run_at'      => $now,
                'trigger_type'=> $trigger === 'manual' ? 'manual' : 'schedule',
                'total'       => (int)($summary['total'] ?? 0),
                'ok'          => (int)($summary['ok'] ?? 0),
                'fail'        => (int)($summary['fail'] ?? 0),
                'duration_ms' => max(0, (int)($meta['duration_ms'] ?? 0)),
                'operator_id' => max(0, (int)($meta['operator_id'] ?? 0)),
                'message'     => mb_substr((string)($meta['message'] ?? ''), 0, 500),
                'detail'      => $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null,
                'create_time' => $now,
            ]);
            // 只保留最近 2000 条，避免长期运行无限增长
            $minId = (int)Db::name('gov_sync_log')->order('id', 'desc')->limit(1)->value('id') - 2000;
            if ($minId > 0) {
                Db::name('gov_sync_log')->where('id', '<=', $minId)->delete();
            }
        } catch (\Throwable $e) {
            // 执行记录写失败不影响同步结果
        }

        try {
            @file_put_contents(
                runtime_path() . 'gov_last_run.json',
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            );
        } catch (\Throwable $e) {
            // 运行摘要写失败不影响业务
        }
    }

    /**
     * 执行记录分页
     *
     * @return array{list:array,total:int,stat:array}
     */
    public static function runs(int $page = 1, int $limit = 20): array
    {
        ensure_table('gov_sync_log', self::syncLogDdl());
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));
        $q = Db::name('gov_sync_log');
        $total = (clone $q)->count();
        $list = (clone $q)->order('id', 'desc')->page($page, $limit)->select()->toArray();
        foreach ($list as &$row) {
            $row['trigger_text'] = ($row['trigger_type'] ?? '') === 'manual' ? '手动触发' : '定时任务';
            $row['status_text'] = ((int)($row['fail'] ?? 0) > 0) ? '部分失败' : '成功';
            $row['detail_items'] = [];
            if (!empty($row['detail'])) {
                $decoded = json_decode((string)$row['detail'], true);
                $row['detail_items'] = is_array($decoded) ? $decoded : [];
            }
            unset($row['detail'], $row['create_time']);
        }
        unset($row);

        $stat = [
            'total'   => $total,
            'success' => (int)Db::name('gov_sync_log')->where('fail', 0)->count(),
            'failed'  => (int)Db::name('gov_sync_log')->where('fail', '>', 0)->count(),
        ];
        return ['list' => $list, 'total' => $total, 'stat' => $stat];
    }

    /**
     * 检测 php.ini / php.d 中重复加载（或已内置仍显式加载）的扩展
     *
     * 这类配置会让 PHP CLI 启动时输出
     *   PHP Warning:  Module "fileinfo" is already loaded in Unknown on line 0
     * 计划任务日志因此显示为「有输出 / 报错」，但任务本身仍会正常执行。
     *
     * @return array<int,string> 有问题的扩展名
     */
    protected static function duplicatedPhpExtensions(): array
    {
        $files = [];
        $loaded = php_ini_loaded_file();
        if ($loaded) {
            $files[] = $loaded;
        }
        $scanned = php_ini_scanned_files();
        if ($scanned) {
            foreach (explode(',', $scanned) as $f) {
                $f = trim($f);
                if ($f !== '') {
                    $files[] = $f;
                }
            }
        }
        if (!$files) {
            return [];
        }

        $extDir = rtrim((string)ini_get('extension_dir'), '/\\');
        $counts = [];
        $builtin = [];
        foreach ($files as $file) {
            if (!is_readable($file)) {
                continue;
            }
            $lines = @file($file, FILE_IGNORE_NEW_LINES);
            if (!$lines) {
                continue;
            }
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === ';' || $line[0] === '#') {
                    continue;
                }
                if (!preg_match('/^extension\s*=\s*"?(?:php_)?([a-z0-9_]+)(?:\.so|\.dll)?"?\s*$/i', $line, $m)) {
                    continue;
                }
                $name = strtolower($m[1]);
                $counts[$name] = ($counts[$name] ?? 0) + 1;
                // 扩展目录下找不到同名 so/dll（Windows 为 php_xxx.dll），说明该扩展已编译进 PHP，
                // 再写 extension= 必定触发 already loaded 警告
                if ($extDir !== ''
                    && !is_file($extDir . DIRECTORY_SEPARATOR . $name . '.' . PHP_SHLIB_SUFFIX)
                    && !is_file($extDir . DIRECTORY_SEPARATOR . 'php_' . $name . '.' . PHP_SHLIB_SUFFIX)) {
                    $builtin[$name] = true;
                }
            }
        }

        $issues = [];
        foreach ($counts as $name => $n) {
            if ($n > 1 || isset($builtin[$name])) {
                $issues[] = $name;
            }
        }
        return $issues;
    }

    /**
     * 宝塔面板定时任务配置建议（按当前部署路径/ PHP 可执行文件生成）
     *
     * @return array<string,mixed>
     */
    public static function scheduleGuide(): array
    {
        $root = rtrim((string)app()->getRootPath(), '/\\');

        // PHP_BINARY 在 php-fpm 环境下会指向 php-fpm，不能直接用于 Shell 定时任务
        $php = trim((string)config('gov.php_binary', ''));
        if ($php === '') {
            $php = defined('PHP_BINARY') && PHP_BINARY !== '' ? (string)PHP_BINARY : 'php';
            if (preg_match('#/sbin/php-fpm#i', $php)) {
                $php = preg_replace('#/sbin/php-fpm.*$#i', '/bin/php', $php) ?: 'php';
            } elseif (preg_match('/(fpm|cgi)/i', basename($php))) {
                $php = 'php';
            }
        }

        $dailyMin = self::dailyMinHours();
        // v2.99.44：默认间隔为 0，定时任务每次执行都会同步，不再有「未满 20 小时跳过」。
        $syncCmd  = 'cd ' . $root . ' && ' . $php . ' think gov:sync';
        $forceCmd = 'cd ' . $root . ' && ' . $php . ' think gov:sync --force';
        $catchCmd = 'cd ' . $root . ' && ' . $php . ' think cert:checkexpire --catch-up';
        $lastRun  = self::lastRunAt();

        $notes = [
            $dailyMin > 0
                ? '当前设置了最小间隔 ' . $dailyMin . ' 小时：同一间隔内重复执行会直接跳过。如需每次都同步，把 config/gov.php 的 daily_min_hours 改为 0。'
                : '已取消同步间隔限制：计划任务每次执行都会同步未办结申报（仍只对「环节/状态有变化」的记录推送通知）。',
            '已办结并自动归档的申报不会再参与定时同步，属正常现象。',
            '若服务器不能访问政务网，请在宝塔任务日志中查看 curl 报错，并检查服务器出口网络。',
        ];

        // v2.73.2：PHP 扩展重复加载会让计划任务日志出现 already loaded 警告
        $badExt = self::duplicatedPhpExtensions();
        if ($badExt) {
            $extText = implode('、', $badExt);
            $notes[] = '检测到 PHP 启动时会输出「Module "' . ($badExt[0] ?? '') . '" is already loaded」类警告：'
                . $extText . ' 被重复加载或已内置。该警告**不影响同步结果**（任务仍会成功执行），'
                . '但会让计划任务日志显示成异常。处理：编辑 PHP 的 php.ini，把这些扩展的 extension= 行只保留一处，'
                . '其余注释掉；若宝塔「PHP 设置 → 配置文件」与「php.d」目录下重复写了同名 extension，同样注释掉，然后重启 PHP。';
        }
        $notes[] = '若计划任务日志出现「Module "fileinfo" is already loaded in Unknown on line 0」，'
            . '属于 PHP 配置重复加载扩展，不是程序错误，按上一条处理后即不再出现。';

        return [
            'backend_path'    => $root,
            'php_binary'      => $php,
            'sync_command'    => $syncCmd,
            'force_command'   => $forceCmd,
            'catch_command'   => $catchCmd,
            'cron_command'    => $syncCmd . ' >> ' . $root . '/runtime/gov_sync_cron.log 2>&1',
            'daily_min_hours' => $dailyMin,
            'last_run_at'     => $lastRun,
            'configured'      => $lastRun !== '',
            'frequency'       => '每 1 小时（推荐）或每天 08:00',
            'php_ini'         => php_ini_loaded_file() ?: '',
            'php_ext_issues'  => $badExt,
            'recommend'       => '推荐：宝塔面板 → 计划任务 → 添加任务，任务类型「Shell 脚本」，执行周期「每 1 小时」，'
                . '脚本内容使用下方「定时同步命令」。'
                . ($dailyMin > 0
                    ? '当前设置了最小间隔 ' . $dailyMin . ' 小时，同一间隔内重复执行会自动跳过，不会重复抓取。'
                    : '已取消同步间隔限制，每次执行都会拉取最新进度；只有环节或状态确实变化时才会推送通知。'),
            'steps'           => [
                '登录宝塔面板，点击左侧菜单「计划任务」，再点右上角「添加任务」。',
                '任务类型选择「Shell 脚本」；任务名称填写「政务网申报进度同步」；执行周期选择「每 1 小时」（或「每天」08:00）。',
                '脚本内容粘贴下方「定时同步命令」，保存任务。命令中的后端目录和 PHP 路径已按当前部署自动生成，直接复制即可。',
                '在任务列表点击「执行」运行一次；再回到本系统，点本页「刷新执行记录」，能看到一条「定时任务」记录即配置成功。',
                '如执行提示 php 不存在：到宝塔「软件商店 → PHP → 设置」查看 PHP 安装目录，把命令中的 PHP 路径改为类似 /www/server/php/82/bin/php，'
                    . '或在 backend/.env 增加 GOV_PHP_BINARY=/www/server/php/82/bin/php。',
                '如日志出现「Module "fileinfo" is already loaded」等警告：这是 PHP 重复加载扩展，'
                    . '到宝塔「软件商店 → PHP → 设置 → 配置文件」把重复的 extension=fileinfo / extension=mbstring 注释掉（只保留一处），'
                    . '保存后重启 PHP；若该扩展已内置，直接注释即可。',
                '建议再添加一条「证书到期提醒」任务（每天 08:30），使用下方「证书到期提醒命令」，避免到期提醒漏发。',
            ],
            'verify' => [
                '保存任务后，在宝塔「计划任务」中点击该任务的「执行」。',
                '等待 10 秒左右，回到本系统打开「定时任务与执行记录 → 执行记录」。',
                '出现「触发方式=定时任务」的新记录即表示配置成功；若失败，记录里会显示失败原因。',
                '配置成功后，列表顶部的「最近同步」会显示最近一次执行时间。',
            ],
            'notes' => $notes,
        ];
    }


    /**
     * 列表/详情补充展示字段
     */
    public static function decorate(array &$row, bool $withNodes = false): void
    {
        $type = (string)($row['business_type'] ?? '');
        $row['business_type_text'] = self::businessTypeText($type);

        $state = (int)($row['current_state'] ?? 0);
        $row['current_state_text'] = self::NODE_STATES[$state] ?? '';

        $sync = (int)($row['last_sync_status'] ?? 0);
        $row['last_sync_status_text'] = self::SYNC_STATES[$sync] ?? '未同步';
        $row['status_text'] = ((int)($row['status'] ?? 0) === 1) ? '启用' : '暂停';
        $row['is_finished_text'] = ((int)($row['is_finished'] ?? 0) === 1) ? '已办结' : '办理中';

        // v2.90：挂起状态（政务网 HANDLESTATE / 挂起环节）；意见原文透出，未返回则为空
        $row['handle_state'] = trim((string)($row['handle_state'] ?? ''));
        $suspended = (int)($row['is_suspended'] ?? 0) === 1;
        // v2.90.5：老数据（v2.90 之前同步）只有 handle_state 没有 is_suspended，
        // 这里按平台状态兜底，避免数据库标记未回填时列表仍显示为正常办理。
        // 平台状态为空时保留原标记（挂起环节 / 特别程序兜底的结果）。
        if ($row['handle_state'] !== '') {
            $suspended = preg_match('/挂起|暂停|中止/u', $row['handle_state']) === 1;
        }
        if ((int)($row['is_finished'] ?? 0) === 1 || (int)($row['is_archived'] ?? 0) === 1) {
            $suspended = false;
        }
        $row['is_suspended']      = $suspended ? 1 : 0;
        $row['is_suspended_text'] = $suspended ? '已挂起' : '';
        $row['suspend_reason']    = (string)($row['suspend_reason'] ?? '');
        $row['suspend_time']      = $row['suspend_time'] ?? null;

        $archived = (int)($row['is_archived'] ?? 0) === 1;
        $row['is_archived_text'] = $archived ? '已归档' : '未归档';
        $row['archive_source_text'] = self::ARCHIVE_SOURCES[(string)($row['archive_source'] ?? '')] ?? '';

        // v2.99.40：退件/补正标记（列表置顶 + 强提醒）。以入库字段为准，
        // 同时兼容升级前的老数据（字段未回填但平台状态/当前环节已含退件关键词）。
        $returned = (int)($row['is_returned'] ?? 0) === 1;
        if (!$returned) {
            $hay = (string)($row['handle_state'] ?? '') . ' ' . (string)($row['current_node'] ?? '');
            $returned = preg_match('/退件|退回|补正|不予受理|驳回|退办|材料不齐/u', $hay) === 1;
        }
        if ((int)($row['is_finished'] ?? 0) === 1 || $archived) {
            $returned = false;
        }
        $row['is_returned']   = $returned ? 1 : 0;
        $row['return_reason'] = (string)($row['return_reason'] ?? '');
        $row['return_time']   = $row['return_time'] ?? null;

        $row['password_mask'] = self::maskPassword(self::decryptPassword((string)($row['password_enc'] ?? '')));
        $row['has_password']  = trim((string)($row['password_enc'] ?? '')) !== '';
        unset($row['password_enc'], $row['raw_json']);

        $row['promise_days_left'] = null;
        $row['promise_status']    = '';
        $row['promise_status_text'] = '';
        if (!$archived && !empty($row['promise_time']) && (int)($row['is_finished'] ?? 0) === 0) {
            $today = new \DateTimeImmutable(date('Y-m-d'));
            $promise = \DateTimeImmutable::createFromFormat('Y-m-d', (string)$row['promise_time']);
            if ($promise) {
                $days = (int)$today->diff($promise->setTime(0, 0))->format('%r%a');
                $row['promise_days_left'] = $days;
                if ($days < 0) {
                    $row['promise_status'] = 'overdue';
                    $row['promise_status_text'] = '已逾期 ' . abs($days) . ' 天';
                } elseif ($days === 0) {
                    $row['promise_status'] = 'today';
                    $row['promise_status_text'] = '今天到期';
                } elseif ($days <= 3) {
                    $row['promise_status'] = 'soon';
                    $row['promise_status_text'] = '剩 ' . $days . ' 天';
                } else {
                    $row['promise_status'] = 'normal';
                    $row['promise_status_text'] = '剩 ' . $days . ' 天';
                }
            }
        }

        if ($withNodes) {
            $nodes = Db::name('gov_progress_node')
                ->where('progress_id', (int)$row['id'])
                ->order('sort', 'asc')->order('id', 'asc')
                ->select()->toArray();
            foreach ($nodes as &$n) {
                $n['state_text'] = self::NODE_STATES[(int)($n['state'] ?? 0)] ?? '';
            }
            unset($n);
            $row['nodes'] = $nodes;
        }
    }

    /**
     * v2.63：批量补齐企业 / 人员名称，避免逐行查库（列表 N+1）。
     *
     * @param array<int,array> $rows 传引用
     */
    public static function attachRelations(array &$rows): void
    {
        if (!$rows) {
            return;
        }
        $companyIds = [];
        $staffIds = [];
        $userIds = [];
        foreach ($rows as $r) {
            $cid = (int)($r['company_id'] ?? 0);
            $sid = (int)($r['staff_id'] ?? 0);
            // v2.95：保存人；老数据没有 save_by 时回退到创建人
            $uid = (int)($r['save_by'] ?? 0) ?: (int)($r['create_by'] ?? 0);
            if ($cid > 0) {
                $companyIds[$cid] = $cid;
            }
            if ($sid > 0) {
                $staffIds[$sid] = $sid;
            }
            if ($uid > 0) {
                $userIds[$uid] = $uid;
            }
        }
        $companyMap = [];
        $staffMap = [];
        $userMap = [];
        try {
            if ($companyIds) {
                foreach (Db::name('client_company')->whereIn('id', array_values($companyIds))
                    ->field('id,company_name,short_name')->select()->toArray() as $c) {
                    $companyMap[(int)$c['id']] = $c;
                }
            }
            if ($staffIds) {
                foreach (Db::name('staff')->whereIn('id', array_values($staffIds))
                    ->field('id,staff_name,phone')->select()->toArray() as $s) {
                    $staffMap[(int)$s['id']] = $s;
                }
            }
            if ($userIds) {
                foreach (Db::name('sys_user')->whereIn('id', array_values($userIds))
                    ->field('id,username,real_name')->select()->toArray() as $u) {
                    $userMap[(int)$u['id']] = $u;
                }
            }
        } catch (\Throwable $e) {
            // 关联表异常时保持原样，不影响申报主数据展示
        }
        foreach ($rows as &$r) {
            $cid = (int)($r['company_id'] ?? 0);
            $sid = (int)($r['staff_id'] ?? 0);
            $company = $companyMap[$cid] ?? null;
            $staff = $staffMap[$sid] ?? null;
            $r['company_name'] = (string)($company['company_name'] ?? '');
            $r['company_short_name'] = (string)($company['short_name'] ?? '');
            $r['staff_name'] = (string)($staff['staff_name'] ?? '');
            $r['staff_phone'] = (string)($staff['phone'] ?? '');

            // v2.95：保存人 / 保存时间。老数据回退展示创建人与创建时间。
            $uid = (int)($r['save_by'] ?? 0) ?: (int)($r['create_by'] ?? 0);
            $user = $userMap[$uid] ?? null;
            $r['save_by_name'] = $user
                ? ((string)($user['real_name'] ?? '') !== '' ? (string)$user['real_name'] : (string)($user['username'] ?? ''))
                : '';
            if (empty($r['save_time'])) {
                $r['save_time'] = $r['create_time'] ?? null;
            }
        }
        unset($r);
    }

    // ============================================================
    // 工具方法
    // ============================================================

    private static function cleanText(string $s): string
    {
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = strip_tags($s);
        $s = str_replace(["\xc2\xa0", '&nbsp;'], ' ', $s);
        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }

    public static function normalizeDateTime(string $s): ?string
    {
        $s = trim($s);
        if ($s === '' || $s === '0000-00-00 00:00:00') {
            return null;
        }
        $ts = strtotime($s);
        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }

    public static function normalizeDate(string $s): ?string
    {
        $dt = self::normalizeDateTime($s);
        return $dt === null ? null : substr($dt, 0, 10);
    }
}
