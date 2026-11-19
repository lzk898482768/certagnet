<?php
namespace app\service;

use think\facade\Db;

/**
 * 字典目录自愈 —— 保证 v2.27/v2.28 新增模块的字典在老库中自动就位
 *
 * 背景：仓库约定不建 upgrade_*.sql，老库升级只 git pull。
 *       新模块（项目/匹配/冲突/政策）的枚举若只写在 init_data.sql，
 *       老库的字典管理页与前端下拉将永远缺项。
 *
 * 策略：与 MenuCatalog 同思路 —— 内置蓝图，运行时幂等补种。
 *       仅在缺失时插入，不覆盖管理员已修改的标签/排序/停用状态。
 */
class DictCatalog
{
    /** 蓝图版本：新增字典时 +1 */
    public const VERSION = 'v2.65';

    /** 字典类型蓝图 [type_code, type_name, module, remark] */
    public const TYPES = [
        ['project_status', '项目状态', 'project', '筹备/进行中/已结束/已暂停'],
        ['project_require_social', '项目社保要求', 'project', '不要求/必须/优先'],
        ['policy_rule_type', '政策规则类型', 'policy', '社保匹配/有效期下限/证书类型限制'],
        ['policy_severity', '政策违规级别', 'policy', '提示/警告/阻断'],
        ['conflict_type', '冲突类型', 'conflict', '占用/人员/互斥/政策'],
        ['match_status', '匹配状态', 'match', '待确认/已选用/已放弃'],
        // v2.33：证书「类型/专业」合并为单一 cert_major 二级字典（一级=类型，二级=专业），
        // 取消独立的 cert_major_type，避免父级改名导致子级静默断链的字符串硬编码。
        // v2.36：type_name 由「证书专业」改为「证书类型/专业」—— 该字典承载两级，
        // 旧名让字典管理页看不出「类型→专业」层级，是「新增类型后专业上级找不到」的诱因之一。
        ['cert_major', '证书类型/专业', 'cert', '二级字典：一级=证书类型(parent_value=空)，二级=证书专业(parent_value=一级dict_value)'],
        // v2.63：政务网申报业务类型（申报进度模块使用，管理员可增删改排序）
        ['gov_business_type', '政务网业务类型', 'gov', '初始注册/转注/变更/延期/重新注册/注销/其他'],
        // v2.64：政务网平台字典（账号密码库平台下拉；dict_data.remark 存平台网址）
        ['gov_platform', '政务网平台', 'gov', '账号密码库平台下拉；字典项的「备注」填写平台网址，选择平台时自动带出'],
        // v2.65：学历报名模块字典
        ['edu_signup_type', '学历报名类型', 'edu', '电中/成考'],
        ['edu_level', '学历类型', 'edu', '中专/高起专/专升本/高起本'],
        ['edu_nature', '学历性质', 'edu', '函授/全日制'],
        ['edu_schooling', '学制', 'edu', '1年/2年/2.5年'],
        ['edu_roll_status', '学籍状态', 'edu', '在籍/已毕业'],
    ];

    /** v2.63：政务网申报业务类型默认值 [type_code, label, value, sort] */
    public const GOV_BUSINESS_TYPES = [
        ['gov_business_type', '初始注册', 'initial', 1],
        ['gov_business_type', '转注', 'transfer', 2],
        ['gov_business_type', '变更', 'change', 3],
        ['gov_business_type', '延期', 'renew', 4],
        ['gov_business_type', '重新注册', 'reissue', 5],
        ['gov_business_type', '注销', 'cancel', 6],
        ['gov_business_type', '其他', 'other', 7],
    ];

    /**
     * v2.64：政务网平台默认值 [label, url, sort]
     * URL 写入 dict_data.remark；管理员可在「字典管理 → 政务网平台」继续增删改。
     */
    public const GOV_PLATFORMS = [
        ['河南政务服务网', 'https://www.hnzwfw.gov.cn/', 1],
        ['河南省住房和城乡建设厅', 'https://hnjs.henan.gov.cn/', 2],
        ['全国建筑市场监管公共服务平台', 'https://jzsc.mohurd.gov.cn/', 3],
    ];

    /**
     * 字典数据蓝图 [type_code, dict_label, dict_value, parent_value, sort]
     *
     * ⚠️ 兼容说明：v2.29 及之前的条目是四元组 [type,label,value,sort]，
     *    v2.32 起扩展为五元组 [type,label,value,parent,sort]。
     *    ensure() 用可变参数解构兼容两种长度 —— 四元组时第 4 位仍是 sort，
     *    需按下标判定，不可直接取 $row[3] 当 parent。
     */
    public const DATA = [
        // 项目状态
        ['project_status', '筹备', '0', 1],
        ['project_status', '进行中', '1', 2],
        ['project_status', '已结束', '2', 3],
        ['project_status', '已暂停', '3', 4],
        // 项目社保要求
        ['project_require_social', '不要求', '0', 1],
        ['project_require_social', '必须', '1', 2],
        ['project_require_social', '优先', '2', 3],
        // 政策规则类型（与 PolicyRuleService::TYPES 对齐）
        ['policy_rule_type', '社保匹配', 'social_match', 1],
        ['policy_rule_type', '有效期下限', 'expiry_limit', 2],
        ['policy_rule_type', '证书类型限制', 'cert_limit', 3],
        // 政策违规级别（与 PolicyRuleService::SEVERITY 对齐）
        ['policy_severity', '提示', '0', 1],
        ['policy_severity', '警告', '1', 2],
        ['policy_severity', '阻断', '2', 3],
        // 冲突类型（与 ConflictService::TYPES 对齐）
        ['conflict_type', '证书占用冲突', 'occupy', 1],
        ['conflict_type', '人员时间冲突', 'person', 2],
        ['conflict_type', '证书互斥冲突', 'exclusive', 3],
        ['conflict_type', '政策合规冲突', 'policy', 4],
        // 匹配状态（与 match_record.status 对齐）
        ['match_status', '待确认', '0', 1],
        ['match_status', '已选用', '1', 2],
        ['match_status', '已放弃', '2', 3],

        // ===== v2.33 证书类型（cert_major 一级，parent_value=''，5 条）=====
        // 一级建造师与二级建造师是两个**独立类型**，专业清单各自独立
        ['cert_major', '一级建造师', '一级建造师', '', 1],
        ['cert_major', '二级建造师', '二级建造师', '', 2],
        ['cert_major', '安管人员', '安管人员', '', 3],
        ['cert_major', '建设厅特种工', '建设厅特种工', '', 4],
        ['cert_major', '应急厅特种工', '应急厅特种工', '', 5],

        // ===== v2.32 证书专业（cert_major，57 条）=====
        // 【一级建造师 14 条】依据《注册建造师管理规定》14 个专业
        ['cert_major', '建筑工程', '建筑工程', '一级建造师', 1],
        ['cert_major', '市政公用工程', '市政公用工程', '一级建造师', 2],
        ['cert_major', '机电工程', '机电工程', '一级建造师', 3],
        ['cert_major', '公路工程', '公路工程', '一级建造师', 4],
        ['cert_major', '水利水电工程', '水利水电工程', '一级建造师', 5],
        ['cert_major', '港口与航道工程', '港口与航道工程', '一级建造师', 6],
        ['cert_major', '通信工程', '通信工程', '一级建造师', 7],
        ['cert_major', '电力工程', '电力工程', '一级建造师', 8],
        ['cert_major', '矿山工程', '矿山工程', '一级建造师', 9],
        ['cert_major', '石油工程', '石油工程', '一级建造师', 10],
        ['cert_major', '铁路工程', '铁路工程', '一级建造师', 11],
        ['cert_major', '民航机场工程', '民航机场工程', '一级建造师', 12],
        ['cert_major', '海洋工程', '海洋工程', '一级建造师', 13],
        ['cert_major', '园林工程', '园林工程', '一级建造师', 14],
        // 【二级建造师 10 条】为一级 14 专业的子集（无铁路/民航/海洋/园林）
        ['cert_major', '建筑工程', '建筑工程', '二级建造师', 1],
        ['cert_major', '市政公用工程', '市政公用工程', '二级建造师', 2],
        ['cert_major', '机电工程', '机电工程', '二级建造师', 3],
        ['cert_major', '公路工程', '公路工程', '二级建造师', 4],
        ['cert_major', '水利水电工程', '水利水电工程', '二级建造师', 5],
        ['cert_major', '港口与航道工程', '港口与航道工程', '二级建造师', 6],
        ['cert_major', '通信工程', '通信工程', '二级建造师', 7],
        ['cert_major', '电力工程', '电力工程', '二级建造师', 8],
        ['cert_major', '矿山工程', '矿山工程', '二级建造师', 9],
        ['cert_major', '石油工程', '石油工程', '二级建造师', 10],
        // 【安管人员 5 条】建筑施工企业安全生产管理人员考核合格证书（A/B/C）
        ['cert_major', 'A', 'A', '安管人员', 1],
        ['cert_major', 'B', 'B', '安管人员', 2],
        ['cert_major', 'C1', 'C1', '安管人员', 3],
        ['cert_major', 'C2', 'C2', '安管人员', 4],
        ['cert_major', 'C3', 'C3', '安管人员', 5],
        // 【建设厅特种工 14 条】建筑施工特种作业操作资格
        ['cert_major', '建筑电工', '建筑电工', '建设厅特种工', 1],
        ['cert_major', '建筑焊工', '建筑焊工', '建设厅特种工', 2],
        ['cert_major', '建筑起重信号司索工', '建筑起重信号司索工', '建设厅特种工', 3],
        ['cert_major', '建筑塔式起重机司机', '建筑塔式起重机司机', '建设厅特种工', 4],
        ['cert_major', '建筑施工升降机司机', '建筑施工升降机司机', '建设厅特种工', 5],
        ['cert_major', '塔式起重机安装拆卸工', '塔式起重机安装拆卸工', '建设厅特种工', 6],
        ['cert_major', '施工升降机安装拆卸工', '施工升降机安装拆卸工', '建设厅特种工', 7],
        ['cert_major', '建筑脚手架工', '建筑脚手架工', '建设厅特种工', 8],
        ['cert_major', '模板工', '模板工', '建设厅特种工', 9],
        ['cert_major', '钢筋工', '钢筋工', '建设厅特种工', 10],
        ['cert_major', '混凝土工', '混凝土工', '建设厅特种工', 11],
        ['cert_major', '砌筑工', '砌筑工', '建设厅特种工', 12],
        ['cert_major', '抹灰工', '抹灰工', '建设厅特种工', 13],
        ['cert_major', '建筑装饰装修工', '建筑装饰装修工', '建设厅特种工', 14],
        // 【应急厅特种工 14 条】特种作业操作证（应急管理部口径）
        ['cert_major', '低压电工作业', '低压电工作业', '应急厅特种工', 1],
        ['cert_major', '高压电工作业', '高压电工作业', '应急厅特种工', 2],
        ['cert_major', '熔化焊接与热切割作业', '熔化焊接与热切割作业', '应急厅特种工', 3],
        ['cert_major', '钎焊作业', '钎焊作业', '应急厅特种工', 4],
        ['cert_major', '一级高处作业', '一级高处作业', '应急厅特种工', 5],
        ['cert_major', '二级高处作业', '二级高处作业', '应急厅特种工', 6],
        ['cert_major', '制冷与空调设备安装修理作业', '制冷与空调设备安装修理作业', '应急厅特种工', 7],
        ['cert_major', '起重机械司机', '起重机械司机', '应急厅特种工', 8],
        ['cert_major', '起重机械司索信号工', '起重机械司索信号工', '应急厅特种工', 9],
        ['cert_major', '模板脚手架作业', '模板脚手架作业', '应急厅特种工', 10],
        ['cert_major', '叉车作业', '叉车作业', '应急厅特种工', 11],
        ['cert_major', '危险化学品安全作业', '危险化学品安全作业', '应急厅特种工', 12],
        ['cert_major', '烟花爆竹安全作业', '烟花爆竹安全作业', '应急厅特种工', 13],
        ['cert_major', '金属非金属矿山作业', '金属非金属矿山作业', '应急厅特种工', 14],

        // ===== v2.65 学历报名字典（均为一级，parent_value=''） =====
        // 报名类型：电中 / 成考
        ['edu_signup_type', '电中', 'dianzhong', '', 1],
        ['edu_signup_type', '成考', 'chengkao', '', 2],
        // 学历类型
        ['edu_level', '中专', '中专', '', 1],
        ['edu_level', '高起专', '高起专', '', 2],
        ['edu_level', '专升本', '专升本', '', 3],
        ['edu_level', '高起本', '高起本', '', 4],
        // 学历性质
        ['edu_nature', '函授', '函授', '', 1],
        ['edu_nature', '全日制', '全日制', '', 2],
        // 学制
        ['edu_schooling', '1年', '1年', '', 1],
        ['edu_schooling', '2年', '2年', '', 2],
        ['edu_schooling', '2.5年', '2.5年', '', 3],
        // 学籍状态
        ['edu_roll_status', '在籍', 'enrolled', '', 1],
        ['edu_roll_status', '已毕业', 'graduated', '', 2],
    ];

    /**
     * v2.63：只补种「政务网业务类型」字典。
     * GovProgressService::ensureTables() 直接调用，保证仅访问申报进度页时字典也可维护。
     */
    public static function ensureGovBusinessTypes(): void
    {
        try {
            ensure_column('dict_data', 'parent_value', "varchar(64) NOT NULL DEFAULT '' COMMENT '上级字典值' AFTER `dict_value`");

            $existType = Db::name('dict_type')->where('type_code', 'gov_business_type')->find();
            if (!$existType) {
                Db::name('dict_type')->insert([
                    'type_code' => 'gov_business_type',
                    'type_name' => '政务网业务类型',
                    'module'    => 'gov',
                    'remark'    => '初始注册/转注/变更/延期/重新注册/注销/其他',
                    'status'    => 1,
                ]);
            }
            foreach (self::GOV_BUSINESS_TYPES as [$code, $label, $value, $sort]) {
                $exist = Db::name('dict_data')
                    ->where('type_code', $code)
                    ->where('parent_value', '')
                    ->where('dict_value', $value)
                    ->find();
                if (!$exist) {
                    Db::name('dict_data')->insert([
                        'type_code'    => $code,
                        'dict_label'   => $label,
                        'dict_value'   => $value,
                        'parent_value' => '',
                        'sort'         => $sort,
                        'status'       => 1,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // 字典表缺失时静默降级，业务类型回退内置常量
        }
    }

    /**
     * v2.64：只补种「政务网平台」字典（账号密码库平台下拉数据源）。
     * 平台网址保存在 dict_data.remark，便于字典管理页直接维护。
     */
    public static function ensureGovPlatforms(): void
    {
        try {
            ensure_column('dict_data', 'parent_value', "varchar(64) NOT NULL DEFAULT '' COMMENT '上级字典值' AFTER `dict_value`");
            ensure_column('dict_data', 'remark', "varchar(255) DEFAULT '' COMMENT '备注/平台网址' AFTER `status`");

            $existType = Db::name('dict_type')->where('type_code', 'gov_platform')->find();
            if (!$existType) {
                Db::name('dict_type')->insert([
                    'type_code' => 'gov_platform',
                    'type_name' => '政务网平台',
                    'module'    => 'gov',
                    'remark'    => '账号密码库平台下拉；字典项的「备注」填写平台网址，选择平台时自动带出',
                    'status'    => 1,
                ]);
            }

            foreach (self::GOV_PLATFORMS as [$label, $url, $sort]) {
                $exist = Db::name('dict_data')
                    ->where('type_code', 'gov_platform')
                    ->where('parent_value', '')
                    ->where('dict_value', $label)
                    ->find();
                if (!$exist) {
                    Db::name('dict_data')->insert([
                        'type_code'    => 'gov_platform',
                        'dict_label'   => $label,
                        'dict_value'   => $label,
                        'parent_value' => '',
                        'sort'         => $sort,
                        'status'       => 1,
                        'remark'       => $url,
                    ]);
                } elseif ($url !== '' && trim((string)($exist['remark'] ?? '')) === '') {
                    // 老库已有同名平台但没网址时，补上预设链接
                    Db::name('dict_data')->where('id', (int)$exist['id'])->update(['remark' => $url]);
                }
            }
        } catch (\Throwable $e) {
            // 字典表缺失时静默降级，平台下拉回退已有账号中的平台
        }
    }

    /**
     * 幂等补种字典（在 PersonProfileService::ensureTables 入口调用）
     * 只在缺失时插入；管理员改过的标签/排序/停用一律不动。
     *
     * ⚠️ dict_data 无任何唯一键（仅 idx_type 普通索引），因此**严禁 ON DUPLICATE KEY**，
     *    必须「按 type_code + parent_value + dict_value 查后插」。
     */
    public static function ensure(): void
    {
        try {
            // R8 缓解：ensure() 被多处入口调用，先自愈父列再补种子，
            // 保证「只进证书页/字典页不碰匹配」的老库也能补出 parent_value。
            ensure_column('dict_data', 'parent_value', "varchar(64) NOT NULL DEFAULT '' COMMENT '上级字典值(cert_major 二级存一级 dict_value)' AFTER `dict_value`");

            foreach (self::TYPES as [$code, $name, $module, $remark]) {
                $exist = Db::name('dict_type')->where('type_code', $code)->find();
                if (!$exist) {
                    Db::name('dict_type')->insert([
                        'type_code' => $code,
                        'type_name' => $name,
                        'module'    => $module,
                        'remark'    => $remark,
                        'status'    => 1,
                    ]);
                }
            }
            // v2.36 架构字段纠正：type_name 属架构（决定字典管理页的层级可读性），
            // 老库 v2.32/33 建的 cert_major 名称是「证书专业」，需纠正为「证书类型/专业」。
            // 与 MenuCatalog 同口径：只纠正架构字段，status 留给管理员手动停用。
            $major = Db::name('dict_type')->where('type_code', 'cert_major')->find();
            if ($major && (string)$major['type_name'] !== '证书类型/专业') {
                Db::name('dict_type')->where('type_code', 'cert_major')
                    ->update(['type_name' => '证书类型/专业']);
            }

            // v2.63：政务网业务类型字典（独立方法，GovProgressService 也会直接调用）
            self::ensureGovBusinessTypes();

            // v2.64：政务网平台字典（账号密码库下拉）
            self::ensureGovPlatforms();


            foreach (self::DATA as $row) {
                // 兼容四元组 [type,label,value,sort] 与五元组 [type,label,value,parent,sort]
                if (count($row) >= 5) {
                    [$code, $label, $value, $parent, $sort] = $row;
                } else {
                    [$code, $label, $value, $sort] = $row;
                    $parent = '';
                }
                $exist = Db::name('dict_data')
                    ->where('type_code', $code)
                    ->where('parent_value', $parent)
                    ->where('dict_value', $value)
                    ->find();
                if (!$exist) {
                    Db::name('dict_data')->insert([
                        'type_code'    => $code,
                        'dict_label'   => $label,
                        'dict_value'   => $value,
                        'parent_value' => $parent,
                        'sort'         => $sort,
                        'status'       => 1,
                    ]);
                }
            }

            // v2.33 迁移：取消独立的 cert_major_type，并入 cert_major 一级。
            // 上面的 DATA 循环已确保 5 条一级 cert_major（parent_value=''）就位，
            // 此处可安全清理旧 type_code，避免字典管理页出现孤儿分组。
            // 二级 cert_major 的 parent_value 仍指向类型 dict_value（字符串不变），引用不受影响。
            if (Db::name('dict_data')->where('type_code', 'cert_major_type')->find()) {
                Db::name('dict_data')->where('type_code', 'cert_major_type')->delete();
            }
            Db::name('dict_type')->where('type_code', 'cert_major_type')->delete();

            // v2.36 退役：证书类别(cert_category)与 cert_major 一级（证书类型）语义重复，
            // 系统内所有调用点已改用证书类型/专业（字典调用方在 v2.36 重构中全部移除该 type）。
            // 清理类型 + 数据，避免字典管理页出现与「证书类型/专业」重复的僵尸分组。
            // 仅删字典本身；业务表中的 cert_category 兼容列（policy_rule/project_cert_req 等）保留不动。
            if (Db::name('dict_data')->where('type_code', 'cert_category')->find()) {
                Db::name('dict_data')->where('type_code', 'cert_category')->delete();
            }
            Db::name('dict_type')->where('type_code', 'cert_category')->delete();
        } catch (\Throwable $e) {
            // 字典表缺失（极老库）或缺 parent_value 列时不阻断主流程
        }
    }
}
