<?php
namespace app\service;

use app\model\Staff;
use app\model\Certificate;
use app\model\CertSecondment;
use think\facade\Db;

/**
 * 人员全景聚合服务 —— 产品精髓「一人一档」
 *
 * 以 staff.id 为唯一锚点，聚合名下：
 *   基本信息（脱敏）+ 社保 + 证书清单（含借调占用）+ 项目履历 + 合同费用 + 冲突清单 + 统计
 *
 * 设计要点：
 *   1. 不推翻现有 staff/certificate 结构，仅扩展 staff 的社保字段；
 *   2. certificate 上的 social_* 为历史冗余，读取时以人档优先、人档为空回退证书（source 标记来源）；
 *   3. 老库缺列/缺表由 ensureSchema() 运行时幂等自愈，无需手动升级脚本。
 */
class PersonProfileService
{
    /** 冲突检测服务的引用（延迟解析，避免服务间循环加载） */
    protected static function conflictService(): string
    {
        return \app\service\ConflictService::class;
    }

    /**
     * 老库自愈：补齐 staff 的社保/属地列，以及全景聚合依赖的新表
     * 幂等：已存在则跳过（MySQL 5.7 无 ADD COLUMN IF NOT EXISTS，故先查列再补）
     */
    public static function ensureSchema(): void
    {
        // ① staff 扩展列
        self::ensureStaffColumns();

        // ② 全景聚合依赖的新表（幂等建表）
        self::ensureTables();
    }

    /**
     * 仅自愈 staff 扩展列（v2.87：人员档案/OCR 依赖）。
     * Staff 控制器列表/保存/详情调用，避免触发全景聚合的建表。
     */
    public static function ensureStaffColumns(): void
    {
        // 社保/属地（v2.29）
        ensure_column('staff', 'social_status', "tinyint NOT NULL DEFAULT 0 COMMENT '社保状态: 0未知 1在缴 2停缴 3未缴纳' AFTER `position`");
        ensure_column('staff', 'social_company', "varchar(128) NOT NULL DEFAULT '' COMMENT '社保缴纳单位' AFTER `social_status`");
        ensure_column('staff', 'social_start_month', "varchar(7) NOT NULL DEFAULT '' COMMENT '社保起始月份 YYYY-MM' AFTER `social_company`");
        ensure_column('staff', 'region_code', "varchar(32) DEFAULT '' COMMENT '属地区划编码（项目属地政策匹配用）' AFTER `social_start_month`");

        // v2.87：人员档案扩展（对齐报名考生档案，支持身份证/毕业证 OCR 回填）
        ensure_column('staff', 'gender', "tinyint NOT NULL DEFAULT 0 COMMENT '性别 0未知 1男 2女' AFTER `staff_name`");
        ensure_column('staff', 'nation', "varchar(30) NOT NULL DEFAULT '' COMMENT '民族' AFTER `gender`");
        ensure_column('staff', 'birthday', "date DEFAULT NULL COMMENT '出生日期' AFTER `id_card`");
        ensure_column('staff', 'address', "varchar(200) NOT NULL DEFAULT '' COMMENT '身份证住址/通讯地址' AFTER `region_code`");
        ensure_column('staff', 'education', "varchar(30) NOT NULL DEFAULT '' COMMENT '学历' AFTER `position`");
        ensure_column('staff', 'edu_type', "varchar(20) NOT NULL DEFAULT '无' COMMENT '学历性质：无/全日制/非全日制' AFTER `education`");
        ensure_column('staff', 'edu_cert_no', "varchar(50) NOT NULL DEFAULT '' COMMENT '毕业证书编号（电子注册号）' AFTER `edu_type`");
        ensure_column('staff', 'graduate_date', "date DEFAULT NULL COMMENT '毕业日期' AFTER `edu_cert_no`");
        ensure_column('staff', 'graduate_school', "varchar(120) NOT NULL DEFAULT '' COMMENT '毕业学校' AFTER `graduate_date`");
    }

    /** 幂等建表：项目 / 需求 / 政策规则 / 互斥组 / 匹配留痕 */
    public static function ensureTables(): void
    {
        // 字典自愈（新模块枚举）：与建表同级，老库 git pull 后自动就位
        DictCatalog::ensure();
        $ddls = [
            'project' => "CREATE TABLE IF NOT EXISTS `project` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `project_no` varchar(64) NOT NULL DEFAULT '' COMMENT '项目编号',
  `project_name` varchar(128) NOT NULL COMMENT '项目名称',
  `client_id` int unsigned NOT NULL DEFAULT 0 COMMENT '所属客户企业 client_company.id',
  `region_code` varchar(32) NOT NULL DEFAULT '' COMMENT '项目属地(区划编码，用于政策匹配)',
  `region_name` varchar(64) NOT NULL DEFAULT '' COMMENT '项目属地名称',
  `start_date` date DEFAULT NULL COMMENT '项目开始日期',
  `end_date` date DEFAULT NULL COMMENT '项目结束日期',
  `status` tinyint NOT NULL DEFAULT 0 COMMENT '状态 0筹备 1进行中 2已结束 3已暂停',
  `remark` text COMMENT '备注',
  `create_by` int unsigned NOT NULL DEFAULT 0 COMMENT '创建人账号ID',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_client` (`client_id`),
  KEY `idx_region` (`region_code`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='项目主体（属地政策与用证需求的载体）'",

            'project_cert_req' => "CREATE TABLE IF NOT EXISTS `project_cert_req` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `project_id` int unsigned NOT NULL DEFAULT 0,
  `cert_name` varchar(128) NOT NULL DEFAULT '' COMMENT '需求证书专业',
  `cert_category` varchar(64) NOT NULL DEFAULT '' COMMENT '证书类别(兼容维度，不迁移)',
  `cert_type` varchar(64) NOT NULL DEFAULT '' COMMENT '证书类型(dict: cert_major 一级)，专业级联的父级',
  `need_count` int NOT NULL DEFAULT 1 COMMENT '需要人数',
  `matched_count` int NOT NULL DEFAULT 0 COMMENT '已匹配人数（由TaskProgressService绝对值重算）',
  `require_social` tinyint NOT NULL DEFAULT 0 COMMENT '是否要求社保在缴: 0不要求 1必须 2优先',
  `min_expire_days` int NOT NULL DEFAULT 0 COMMENT '证书剩余有效期下限(天)，防临期',
  `remark` varchar(255) DEFAULT '',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_project` (`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='项目证件需求（智能匹配输入）'",

            'policy_rule' => "CREATE TABLE IF NOT EXISTS `policy_rule` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `region_code` varchar(32) NOT NULL DEFAULT '' COMMENT '适用属地编码，空=全国通用',
  `region_name` varchar(64) NOT NULL DEFAULT '' COMMENT '属地名称',
  `rule_type` varchar(32) NOT NULL COMMENT '规则类型: social_match/expiry_limit/cert_limit',
  `cert_category` varchar(64) NOT NULL DEFAULT '' COMMENT '适用证书类别，空=全部',
  `cert_name` varchar(128) NOT NULL DEFAULT '' COMMENT '适用证书专业，空=全部',
  `rule_expr` varchar(255) NOT NULL DEFAULT '' COMMENT '规则参数(键值对)，如 min_expire_days=90;require_social=1',
  `severity` tinyint NOT NULL DEFAULT 1 COMMENT '违规级别 0提示 1警告 2阻断',
  `remark` varchar(255) DEFAULT '',
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_region` (`region_code`),
  KEY `idx_type` (`rule_type`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='属地政策规则（可配置，支持不同地市）'",

            'cert_mutual_exclusive' => "CREATE TABLE IF NOT EXISTS `cert_mutual_exclusive` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `group_name` varchar(64) NOT NULL COMMENT '互斥组名称',
  `member_certs` varchar(500) NOT NULL DEFAULT '' COMMENT '组内证书专业，逗号分隔(如: 一级建造师,二级建造师)',
  `remark` varchar(255) DEFAULT '',
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='证书互斥组（同类证书不可同时占用同一人）'",

            'match_record' => "CREATE TABLE IF NOT EXISTS `match_record` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `project_id` int unsigned NOT NULL DEFAULT 0 COMMENT '项目ID',
  `req_id` int unsigned NOT NULL DEFAULT 0 COMMENT '需求ID project_cert_req.id',
  `staff_id` int unsigned NOT NULL DEFAULT 0 COMMENT '推荐人员',
  `cert_id` int unsigned NOT NULL DEFAULT 0 COMMENT '选用证书',
  `score` decimal(6,2) NOT NULL DEFAULT 0.00 COMMENT '匹配得分',
  `score_detail` text COMMENT '得分明细(JSON)：各维度加分/扣分',
  `status` tinyint NOT NULL DEFAULT 0 COMMENT '0待确认 1已选用 2已放弃',
  `secondment_id` int unsigned NOT NULL DEFAULT 0 COMMENT '选用后生成的借调ID',
  `create_by` int unsigned NOT NULL DEFAULT 0,
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_project` (`project_id`),
  KEY `idx_staff` (`staff_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='智能匹配与选用留痕'",

            // v2.32：证书多附件子表（1:N）
            'certificate_attach' => "CREATE TABLE IF NOT EXISTS `certificate_attach` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `cert_id` int unsigned NOT NULL COMMENT '证书ID certificate.id',
  `attach_name` varchar(128) NOT NULL DEFAULT '' COMMENT '附件名称(用户填写)',
  `attach_path` varchar(255) NOT NULL COMMENT '文件路径',
  `file_ext` varchar(16) NOT NULL DEFAULT '' COMMENT '扩展名(判预览)',
  `file_size` int unsigned NOT NULL DEFAULT 0 COMMENT '字节',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序',
  `create_by` int unsigned NOT NULL DEFAULT 0 COMMENT '上传人',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cert` (`cert_id`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='证书附件（多附件，可命名）'",

            // v2.35：人员 / 客户企业多附件子表（与 certificate_attach 同构）
            'staff_attach' => "CREATE TABLE IF NOT EXISTS `staff_attach` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `staff_id` int unsigned NOT NULL COMMENT '人员ID staff.id',
  `attach_name` varchar(128) NOT NULL DEFAULT '' COMMENT '附件名称(用户填写)',
  `attach_path` varchar(255) NOT NULL COMMENT '文件路径',
  `file_ext` varchar(16) NOT NULL DEFAULT '' COMMENT '扩展名(判预览)',
  `file_size` int unsigned NOT NULL DEFAULT 0 COMMENT '字节',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序',
  `create_by` int unsigned NOT NULL DEFAULT 0 COMMENT '上传人',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_staff` (`staff_id`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='人员附件（多附件，可命名）'",

            'company_attach' => "CREATE TABLE IF NOT EXISTS `company_attach` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `company_id` int unsigned NOT NULL COMMENT '客户企业ID client_company.id',
  `attach_name` varchar(128) NOT NULL DEFAULT '' COMMENT '附件名称(用户填写)',
  `attach_path` varchar(255) NOT NULL COMMENT '文件路径',
  `file_ext` varchar(16) NOT NULL DEFAULT '' COMMENT '扩展名(判预览)',
  `file_size` int unsigned NOT NULL DEFAULT 0 COMMENT '字节',
  `sort` int NOT NULL DEFAULT 0 COMMENT '排序',
  `create_by` int unsigned NOT NULL DEFAULT 0 COMMENT '上传人',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客户企业附件（多附件，可命名）'",
        ];

        foreach ($ddls as $table => $ddl) {
            try {
                if (Db::query("SHOW TABLES LIKE '{$table}'")) {
                    continue;
                }
                Db::execute($ddl);
            } catch (\Throwable $e) {
                // 建表失败不阻断主流程，交由日志排查
            }
        }

        // ③ cert_task 关联项目
        ensure_column('cert_task', 'project_id', "int unsigned NOT NULL DEFAULT 0 COMMENT '关联项目ID（0=不关联）' AFTER `company_id`");
        // ④ cert_task_item 来源需求（转人工配证回写进度）
        ensure_column('cert_task_item', 'source_req_id', "int unsigned NOT NULL DEFAULT 0 COMMENT '来源项目需求ID project_cert_req.id（转人工配证时写入）' AFTER `remark`");
        ensure_column('cert_task_item', 'cert_type', "varchar(64) NOT NULL DEFAULT '' COMMENT '证书类型(dict: cert_major 一级)' AFTER `category`");
        // ⑤ v2.32：两级字典父列 + 项目需求的证书类型 + 多附件子表
        ensure_column('dict_data', 'parent_value', "varchar(64) NOT NULL DEFAULT '' COMMENT '上级字典值(cert_major 二级存一级 dict_value)' AFTER `dict_value`");
        ensure_column('project_cert_req', 'cert_type', "varchar(64) NOT NULL DEFAULT '' COMMENT '证书类型(dict: cert_major 一级)' AFTER `cert_category`");
        // v2.36：certificate.cert_type 此前只写在 init.sql、无自愈 —— 老库 git pull 后
        // 拿不到该列，台账「证书类型」筛选与保存会直接 1054。此处补齐（幂等，新库无副作用）。
        ensure_column('certificate', 'cert_type', "varchar(64) DEFAULT '' COMMENT '证书类型(dict: cert_major 一级)；为空=未分类' AFTER `cert_name`");
        // v2.36：政策规则的适用证书类型列（与 certificate.cert_type 同源；
        // 放在此处而非 PolicyRule 控制器，避免「先跑匹配/导入再打开规则页」时缺列）
        ensure_column('policy_rule', 'cert_type', "varchar(64) NOT NULL DEFAULT '' COMMENT '适用证书类型(dict: cert_major 一级)，空=全部' AFTER `cert_category`");
        ensure_table('certificate_attach', $ddls['certificate_attach']);
        // v2.35：人员 / 客户企业多附件（子表 + 父表附件路径列）
        ensure_table('staff_attach', $ddls['staff_attach']);
        ensure_table('company_attach', $ddls['company_attach']);
        ensure_column('client_company', 'attach_path', "varchar(255) DEFAULT '' COMMENT '附件路径（v2.35 多附件派生第一条）' AFTER `company_scale`");
        // 转人工去重走 (task_id, source_req_id) 复合索引；字典级联走 (type_code,parent_value,status,sort)
        ensure_index('cert_task_item', 'idx_source', '(`task_id`,`source_req_id`)');
        ensure_index('dict_data', 'idx_type_parent', '(`type_code`,`parent_value`,`status`,`sort`)');
    }

    /**
     * 社保信息解析：人档优先，为空回退证书（向后兼容老数据）
     * @return array{status:int,company:string,start_month:string,source:string}
     *         source: staff=人档 / certificate=证书冗余（待迁移）/ none=无数据
     */
    public static function resolveSocial(int $staffId, array $certs = []): array
    {
        $none = ['status' => 0, 'company' => '', 'start_month' => '', 'source' => 'none'];

        $row = Db::name('staff')->where('id', $staffId)
            ->field('social_status,social_company,social_start_month')->find();
        if ($row && (int)($row['social_status'] ?? 0) > 0) {
            return [
                'status'      => (int)$row['social_status'],
                'company'     => (string)($row['social_company'] ?? ''),
                'start_month' => (string)($row['social_start_month'] ?? ''),
                'source'      => 'staff',
            ];
        }

        // 人档为空 → 回退任一证书上的社保冗余字段
        foreach ($certs as $c) {
            if ((int)($c['social_status'] ?? 0) > 0) {
                return [
                    'status'      => (int)$c['social_status'],
                    'company'     => (string)($c['social_company'] ?? ''),
                    'start_month' => (string)($c['social_start_month'] ?? ''),
                    'source'      => 'certificate',
                ];
            }
        }
        return $none;
    }

    /** 社保状态文本 */
    public static function socialText(int $status): string
    {
        return [0 => '未知', 1 => '在缴', 2 => '停缴', 3 => '未缴纳'][$status] ?? '未知';
    }

    /**
     * 人员全景聚合主入口
     * @param int $staffId 人员ID
     * @param array $companyIds 非空时只返回这些企业范围内的证书/借调/合同/费用
     * @param mixed $user 当前登录用户（含 role_code），用于脱敏开关判断
     */
    public static function profile(int $staffId, array $companyIds = [], $user = null): array
    {
        self::ensureSchema();

        $staff = Staff::find($staffId);
        if (!$staff) {
            return [];
        }
        $base = MaskService::staffFor($staff->toArray(), $user);

        // 名下证书 + 当前借调占用；带企业范围时只取当前借调给这些企业的证书
        $certQ = Certificate::where('staff_id', $staffId);
        if ($companyIds) {
            $certIds = SecondmentService::certIdsOf($companyIds, 1) ?: [0];
            $certQ->whereIn('id', $certIds);
        }
        $certs = $certQ->orderRaw('expire_date IS NULL ASC, expire_date ASC')->select()->toArray();
        SecondmentService::attach($certs);
        foreach ($certs as &$c) {
            $c['remain_days'] = remain_days($c['expire_date'] ?? '');
        }
        unset($c);

        $social      = self::resolveSocial($staffId, $certs);
        $social['text'] = self::socialText((int)$social['status']);

        // 项目履历：借调记录 → 所属项目（按借出企业反查项目）
        $projects = self::attachProjects($staffId, $companyIds, $user);

        // 合同与费用
        $contractFee = self::attachContractFee($staffId, $companyIds);

        // v2.47：人员档案「调用证书附件」——名下证书各自附件（含可直用签名链接）
        $certAtts = [];
        foreach ($certs as $c) {
            $atts = CertAttachService::listByCert((int)$c['id']);
            if (!$atts) {
                continue;
            }
            foreach ($atts as &$a) {
                $a['url'] = CertAttachService::url((string)$a['attach_path']);
            }
            unset($a);
            $certAtts[] = [
                'cert_id'     => (int)$c['id'],
                'cert_name'   => (string)($c['cert_name'] ?? ''),
                'cert_no'     => (string)($c['cert_no'] ?? ''),
                'expire_date' => (string)($c['expire_date'] ?? ''),
                'attachments' => array_values($atts),
            ];
        }

        // 冲突清单（若冲突服务已就绪）。带企业范围时不做全局冲突检测，避免泄露其他企业数据。
        $conflicts = [];
        if (!$companyIds) {
            try {
                $cls = self::conflictService();
                if (class_exists($cls)) {
                    $conflicts = $cls::checkStaff($staffId);
                }
            } catch (\Throwable $e) {
                $conflicts = [];
            }
        }

        // 证书附件清单中的证书编号同样受脱敏开关控制
        foreach ($certAtts as &$ca) {
            $ca['cert_no'] = MaskService::fieldValue('cert.cert_no', (string)($ca['cert_no'] ?? ''), $user);
        }
        unset($ca);

        return [
            'staff'        => $base,
            'social'       => $social,
            'certificates' => MaskService::certListFor($certs, $user),
            'cert_attachments' => $certAtts,
            'projects'     => $projects,
            'contracts'    => $contractFee['contracts'],
            'fee_summary'  => $contractFee['summary'],
            'conflicts'    => $conflicts,
            'attachments'  => AttachService::listBy('staff', $staffId),
            'stats'        => self::stat($certs, $projects, $conflicts),
        ];
    }

    /**
     * 项目履历：按借调记录推导（借出企业 = 项目客户企业）
     * 项目表缺失时降级为仅返回借调记录，不报错
     */
    public static function attachProjects(int $staffId, array $companyIds = [], $user = null): array
    {
        $q = Db::name('cert_secondment')->alias('s')
            ->field('s.id,s.cert_id,s.company_id,s.start_date,s.end_date,s.status,c.cert_name,c.expire_date,cc.company_name')
            ->join('certificate c', 'c.id = s.cert_id', 'LEFT')
            ->join('client_company cc', 'cc.id = s.company_id', 'LEFT')
            ->where('s.staff_id', $staffId);
        if ($companyIds) {
            $q->whereIn('s.company_id', array_map('intval', $companyIds));
        }
        $rows = $q->order('s.id', 'desc')->select()->toArray();

        // 项目表存在时补充项目名称与属地
        $hasProject = false;
        try {
            $hasProject = (bool)Db::query("SHOW TABLES LIKE 'project'");
        } catch (\Throwable $e) {
            $hasProject = false;
        }

        foreach ($rows as &$r) {
            $r['status_text'] = SecondmentService::statusText((int)$r['status']);
            $r['remain_days'] = SecondmentService::remainDays($r['end_date'] ?? '');
            $r['project_name'] = '';
            $r['region_name']  = '';
            if ($hasProject && !empty($r['company_id'])) {
                $p = Db::name('project')->where('client_id', $r['company_id'])
                    ->order('id', 'desc')->find();
                if ($p) {
                    $r['project_name'] = (string)$p['project_name'];
                    $r['region_name']  = (string)$p['region_name'];
                }
            }
        }
        unset($r);

        return MaskService::certListFor($rows, $user);
    }

    /**
     * 合同与费用汇总（按借调记录关联的合同）
     */
    public static function attachContractFee(int $staffId, array $companyIds = []): array
    {
        $scope = function () use ($staffId, $companyIds) {
            $q = Db::name('cert_secondment')->alias('s')->where('s.staff_id', $staffId);
            if ($companyIds) {
                $q->whereIn('s.company_id', array_map('intval', $companyIds));
            }
            return $q;
        };

        $contracts = $scope()
            ->where('s.contract_id', '>', 0)
            ->field('DISTINCT s.contract_id')
            ->column('s.contract_id');

        $list = [];
        if ($contracts) {
            $list = Db::name('contract')->whereIn('id', $contracts)
                ->order('id', 'desc')->select()->toArray();
        }

        // 费用汇总：借调费用 + 合同关联费用
        $feeTotal   = (float)$scope()->sum('s.fee_amount');
        $feeUnpaid  = (float)$scope()->where('s.fee_status', 0)->sum('s.fee_amount');

        return [
            'contracts' => $list,
            'summary'   => [
                'contract_count' => count($list),
                'fee_total'      => round($feeTotal, 2),
                'fee_unpaid'     => round($feeUnpaid, 2),
                'fee_received'   => round($feeTotal - $feeUnpaid, 2),
            ],
        ];
    }

    /**
     * 全景统计（证书维度 + 项目维度 + 冲突）
     */
    public static function stat(array $certs, array $projects, array $conflicts = []): array
    {
        $total = count($certs);
        $used  = 0;
        $soon  = 0;   // 90 天内到期
        $expired = 0;
        foreach ($certs as $c) {
            if ((int)($c['secondment_status'] ?? 0) === 1) {
                $used++;
            }
            $rd = (int)($c['remain_days'] ?? 0);
            if ($rd < 0) {
                $expired++;
            } elseif ($rd <= 90) {
                $soon++;
            }
        }

        $blockConflicts = 0;
        foreach ($conflicts as $cf) {
            if ((int)($cf['level'] ?? 0) >= 2) {
                $blockConflicts++;
            }
        }

        return [
            'cert_total'      => $total,
            'cert_in_use'     => $used,
            'cert_idle'       => max(0, $total - $used),
            'use_rate'        => $total > 0 ? round($used * 100 / $total, 1) : 0,
            'cert_expiring'   => $soon,
            'cert_expired'    => $expired,
            'project_count'   => count($projects),
            'conflict_total'  => count($conflicts),
            'conflict_block'  => $blockConflicts,
        ];
    }

    /**
     * 证件利用率统计（组织维度）
     * @param int $deptId 0=全部
     * @return array{summary:array,items:array}
     */
    public static function utilization(int $deptId = 0, int $days = 180): array
    {
        self::ensureSchema();

        $q = Db::name('certificate')->alias('c')
            ->join('staff s', 's.id = c.staff_id', 'LEFT')
            ->where('c.cert_status', 1);
        if ($deptId) {
            $q->where('s.dept_id', $deptId);
        }
        $total = (clone $q)->count();
        $inUse = 0;
        $rows  = (clone $q)->field('c.id,c.cert_name,c.expire_date,s.staff_name,s.dept_id,s.dept')->select()->toArray();

        $usedIds = Db::name('cert_secondment')->where('status', 1)->column('cert_id');
        $usedSet = array_flip(array_map('intval', $usedIds ?: []));

        $items = [];
        $idleLong = 0;
        foreach ($rows as $r) {
            $isUsed = isset($usedSet[(int)$r['id']]);
            if ($isUsed) {
                $inUse++;
            }
            $remain = remain_days($r['expire_date'] ?? '');
            // 闲置超过阈值：未借调且剩余有效期已过半（按 days 参数折算）
            $idleThreshold = $days;
            $isIdleLong = !$isUsed && $remain !== null && $remain > $idleThreshold;
            if ($isIdleLong) {
                $idleLong++;
            }
            $items[] = [
                'cert_id'     => (int)$r['id'],
                'cert_name'   => (string)$r['cert_name'],
                'staff_name'  => (string)($r['staff_name'] ?? ''),
                'dept'        => (string)($r['dept'] ?? ''),
                'expire_date' => (string)($r['expire_date'] ?? ''),
                'remain_days' => $remain,
                'in_use'      => $isUsed,
                'idle_long'   => $isIdleLong,
            ];
        }

        return [
            'summary' => [
                'cert_total'    => $total,
                'cert_in_use'   => $inUse,
                'cert_idle'     => max(0, $total - $inUse),
                'use_rate'      => $total > 0 ? round($inUse * 100 / $total, 1) : 0,
                'idle_long'     => $idleLong,
                'idle_rate'     => $total > 0 ? round($idleLong * 100 / $total, 1) : 0,
            ],
            'items' => $items,
        ];
    }

    /**
     * 闲置预警：剩余有效期充足但长期未借调的证书
     */
    public static function idleAlerts(int $days = 180, int $limit = 50): array
    {
        $r = self::utilization(0, $days);
        $items = array_values(array_filter($r['items'], function ($i) {
            return !empty($i['idle_long']);
        }));
        usort($items, function ($a, $b) {
            return ($b['remain_days'] ?? 0) <=> ($a['remain_days'] ?? 0);
        });
        return ['summary' => $r['summary'], 'items' => array_slice($items, 0, $limit)];
    }
}