<?php
namespace app\service;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use think\facade\Db;
use app\service\CustomFieldService;

/**
 * Excel 导入导出（证书 / 人员 / 企业）
 * 表头中文名 -> 字段名的映射同时用于导出与导入解析，保证「导出的模板能直接导入」
 */
class ExcelService
{
    /** 各类型的表头定义：[中文表头 => 字段] */
    protected static $headers = [
        'certificate' => [
            '人员姓名'   => 'staff_name',
            '身份证号'   => 'id_card',
            // v2.36：证书类别(cert_category)与证书类型(cert_major 一级)语义重复，
            // 全系统已统一为「证书类型 → 证书专业」两级。导出/模板只保留「证书类型」，
            // 旧模板的「证书类别」列由 $headerAlias 兼容映射到 cert_type。
            '证书类型'   => 'cert_type',
            '证书专业'   => 'cert_name',
            '证书编号'   => 'cert_no',
            '发证日期'   => 'issue_date',
            '到期日期'   => 'expire_date',
            '证书状态'   => 'cert_status_text',
            '社保状态'   => 'social_status_text',
            '优先级'     => 'priority',
            '费用'       => 'cert_fee',
            '借调企业'   => 'company_name',
            '借调开始'   => 'secondment_start',
            '借调到期'   => 'secondment_end',
        ],
        'staff' => [
            '姓名'       => 'staff_name',
            '身份证号'   => 'id_card',
            '电话'       => 'phone',
            '邮箱'       => 'email',
            '状态'       => 'status_text',
            '关联企业'   => 'company_names',
            '备注'       => 'remark',
        ],
        'company' => [
            '企业全称'   => 'company_name',
            '简称'       => 'short_name',
            '统一社会信用代码' => 'credit_code',
            '法定代表人' => 'legal_person',
            '注册属地'   => 'region_path',
            '注册资本'   => 'registered_capital',
            '所属行业'   => 'industry',
            '企业规模'   => 'company_scale',
            '联系人'     => 'contact',
            '联系电话'   => 'contact_phone',
            '地址'       => 'address',
            '状态'       => 'status_text',
            '备注'       => 'remark',
        ],
        'fee' => [
            '单号'         => 'id',
            '客户企业'     => 'company_name',
            '费用项目'     => 'fee_name',
            '关联来源'     => 'source_label',
            '收付方向'     => 'direction_text',
            '收支类型'     => 'fee_type_text',
            '金额(元)'     => 'amount',
            '已收/付(元)'  => 'settle_amount',
            '未结清(元)'   => 'unsettled_amount',
            '发生日期'     => 'occur_date',
            '计划收付'     => 'plan_date',
            '结算状态'     => 'settle_text',
            '收款账户'     => 'pay_account_name',
            '票据号'       => 'invoice_no',
            '经办人'       => 'handler',
            '备注'         => 'remark',
        ],
        'project' => [
            '项目名称'   => 'project_name',
            '项目编号'   => 'project_no',
            '客户企业'   => 'company_name',
            '项目属地'   => 'region_name',
            '属地编码'   => 'region_code',
            '开始日期'   => 'start_date',
            '结束日期'   => 'end_date',
            '项目状态'   => 'status_text',
            '证件需求'   => 'reqs_text',
            '备注'       => 'remark',
        ],
        'policy_rule' => [
            '适用属地'   => 'region_name',
            '属地编码'   => 'region_code',
            '规则类型'   => 'rule_type_text',
            // v2.36：适用类别已统一为证书类型（旧表头由 $headerAlias 兼容）
            '适用类型'   => 'cert_type',
            '适用专业'   => 'cert_name',
            '规则参数'   => 'rule_expr',
            '违规级别'   => 'severity_text',
            '备注'       => 'remark',
        ],
        // v2.62：政务网申报进度导出（v2.63 增加企业 / 人员 / 证书字段）
        'gov_progress' => [
            '申报号'     => 'project_no',
            '业务类型'   => 'business_type_text',
            '关联企业'   => 'company_name',
            '关联人员'   => 'staff_name',
            '证书类型'   => 'cert_type',
            '证书专业'   => 'cert_name',
            '事项名称'   => 'project_name',
            '办理部门'   => 'dept_name',
            '申请人'     => 'apply_name',
            '当前环节'   => 'current_node',
            '环节状态'   => 'current_state_text',
            '办结状态'   => 'is_finished_text',
            '归档状态'   => 'is_archived_text',
            '归档时间'   => 'archive_time',
            '归档方式'   => 'archive_source_text',
            '受理时间'   => 'accept_time',
            '承诺时间'   => 'promise_time',
            '承诺剩余'   => 'promise_status_text',
            '实际办结'   => 'finish_time',
            '最近同步'   => 'last_sync_time',
            '同步状态'   => 'last_sync_status_text',
            '备注'       => 'remark',
        ],
        // v2.63：账号密码库导入导出（导出含明文密码，仅限有查看权限的岗位）
        'credential_vault' => [
            '平台名称'   => 'platform',
            '平台网址'   => 'platform_url',
            '账号类型'   => 'account_type_text',
            '所属企业'   => 'company_name',
            '关联人员'   => 'staff_name',
            '登录账号'   => 'account_name',
            '登录密码'   => 'password',
            '备注'       => 'remark',
        ],
        // v2.93：企业证书（安许 / 资质）集中台账导出
        'company_cert' => [
            '所属企业'   => 'company_name',
            '证书类型'   => 'q_type_text',
            '证书名称'   => 'cert_name',
            '等级'       => 'cert_level',
            '证书编号'   => 'cert_no',
            '许可范围'   => 'cert_scope',
            '发证机关'   => 'issue_org',
            '发证日期'   => 'issue_date',
            '有效期至'   => 'expire_date',
            '到期状态'   => 'status_text',
            '来源'       => 'source_text',
            '备注'       => 'remark',
        ],
    ];

    /**
     * v2.71：导入专用表头（模块导入列与导出列不一致时使用）
     *
     * 目前仅「申报进度」：导出含平台计算列（当前环节 / 办结状态 / 同步状态等）且出于安全
     * 不导出申报密码；导入只接收核心字段 + 申报密码。template()/import()/失败名单均走这里。
     */
    protected static $importHeaders = [
        // v2.94：企业证书导入（不接收平台计算的「到期状态 / 来源」列）
        'company_cert' => [
            '所属企业'       => 'company_name',
            '统一社会信用代码' => 'credit_code',
            '证书类型'       => 'q_type_text',
            '证书名称'       => 'cert_name',
            '等级'           => 'cert_level',
            '证书编号'       => 'cert_no',
            '许可范围'       => 'cert_scope',
            '发证机关'       => 'issue_org',
            '发证日期'       => 'issue_date',
            '有效期至'       => 'expire_date',
            '备注'           => 'remark',
        ],
        'gov_progress' => [
            '申报号'     => 'project_no',
            '申报密码'   => 'password',
            '业务类型'   => 'business_type_text',
            '关联企业'   => 'company_name',
            '关联人员'   => 'staff_name',
            '证书类型'   => 'cert_type',
            '证书专业'   => 'cert_name',
            '事项名称'   => 'project_name',
            '办理部门'   => 'dept_name',
            '申请人'     => 'apply_name',
            '承诺时间'   => 'promise_time',
            '备注'       => 'remark',
        ],
    ];

    /**
     * 取表头映射：$forImport=true 时优先使用导入专用表头
     * @return array [中文表头 => 字段]
     */
    public static function headers(string $type, bool $forImport = false): array
    {
        if ($forImport && isset(self::$importHeaders[$type])) {
            return self::$importHeaders[$type];
        }
        return self::$headers[$type] ?? [];
    }

    /**
     * 旧表头兼容别名：[旧表头 => 新表头]
     * 「证书名称」于 v2.16 更名为「证书专业」，历史导出的模板仍需可直接导入。
     */
    protected static $headerAlias = [
        'certificate' => [
            '证书名称' => '证书专业',
            // v2.36：旧模板的「证书类别」列映射到「证书类型」（两者语义重复，统一为类型）
            '证书类别' => '证书类型',
        ],
        'policy_rule' => [
            // v2.36：政策规则「适用类别」→「适用类型」
            '适用类别' => '适用类型',
        ],
    ];

    /**
     * 需要以「文本」格式写入的字段：身份证号、电话等均属于长数字，
     * 若当成数值写入，Excel 会显示成科学计数法、且丢失前导零；用户照模板手填时
     * 列格式设为文本可避免其输入再次被转成数字。
     */
    protected static $textFields = ['id_card', 'phone', 'project_no', 'account_name', 'password'];

    /**
     * 模板示例行（v2.53 起与导入解析联动：导入时整行完全匹配示例值的行视为示例，自动跳过）
     * 顺序与 $headers[$type] 的列顺序一致；custom 列不参与匹配。
     */
    protected static $samples = [
        // v2.36：第 3 列已由「证书类别」改为「证书类型」，示例值同步为 类型=二级建造师 / 专业=建筑工程
        'certificate' => ['张三', '110101199001011234', '二级建造师', '建筑工程', 'JSZ-2024-0001', '2024-01-10', '2027-01-09', '正常', '在缴', '重要', 12000, '示例建设有限公司', '2024-01-10', '2027-01-09'],
        'staff'       => ['张三', '110101199001011234', '13900000001', 'a@b.com', '在职', '示例建设有限公司', ''],
        'company'     => ['示例建设有限公司', '示例建设', '91330100MA2XXXXX1A', '王五', '浙江省/杭州市', '1000万元', '建筑工程', '中型', '王五', '13900000003', '杭州市', '启用', ''],
        'project'     => ['示例建设有限公司2026年度资质项目', 'XM-2026-001', '示例建设有限公司', '杭州市', '330100', '2026-01-01', '2026-12-31', '进行中', '二级建造师×2;安全员C证×1', '示例数据，可删除'],
        'policy_rule' => ['杭州市', '330100', '有效期下限', '', '', 'min_expire_days=90', '警告', '示例规则，可删除'],
        'credential_vault' => ['示例政务平台', 'https://example.com', '企业账号', '示例建设有限公司', '', 'demo_account', 'Demo@123456', '示例数据，可删除'],
        // v2.71：申报进度导入示例（列顺序与 $importHeaders['gov_progress'] 一致）
        'gov_progress' => ['HNZW20260001', 'Demo@123456', '初始注册', '示例建设有限公司', '张三', '二级建造师', '建筑工程', '二级建造师初始注册', '住房和城乡建设厅', '张三', '2026-12-31', '示例数据，可删除'],
        // v2.94：企业证书导入示例（列顺序与 $importHeaders['company_cert'] 一致）
        'company_cert' => ['示例建设有限公司', '91330100MA2XXXXX1A', '安许', '安全生产许可证', '', '（豫）JZ安许证字[2024]000001', '建筑施工', '河南省住房和城乡建设厅', '2024-01-10', '2027-01-09', '示例数据，可删除'],
    ];

    /** 以文本类型写入单元格，并把该单元格格式锁为文本（@） */
    protected static function setTextCell(Spreadsheet $sp, string $coord, $value): void
    {
        $sheet = $sp->getActiveSheet();
        $sheet->setCellValueExplicit($coord, (string)$value, DataType::TYPE_STRING);
        $sheet->getStyle($coord)->getNumberFormat()->setFormatCode('@');
    }

    /**
     * CSV/Excel 公式注入防护（OWASP）。
     *
     * 用户可控文本以 = + - @ 或制表符/回车开头时，Excel 会当成公式执行，
     * 攻击者可写入 =HYPERLINK(...) / DDE 等内容，管理员打开导出文件即中招。
     * 这里给字符串前置单引号（Excel 中不显示），并保持数字/布尔类型不变。
     */
    protected static function safeCellValue($value)
    {
        if (is_string($value) && $value !== '' && preg_match('/^[=+\-@\t\r]/', $value)) {
            return "'" . $value;
        }
        return $value;
    }

    /**
     * 该模块启用的自定义字段：[显示名称 => field_key]
     * 用于导出/模板追加列，以及导入时识别自定义列
     */
    protected static function customMap(string $type): array
    {
        $fields = CustomFieldService::fieldsOf($type);
        $out = [];
        foreach ($fields as $f) {
            $out[$f['field_label']] = $f['field_key'];
        }
        return $out;
    }

    public static function exportCertificate(array $rows): string
    {
        return self::export('certificate', $rows);
    }

    public static function exportStaff(array $rows, array $extraCols = []): string
    {
        return self::export('staff', $rows, $extraCols);
    }

    public static function exportCompany(array $rows): string
    {
        return self::export('company', $rows);
    }

    public static function exportFee(array $rows): string
    {
        return self::export('fee', $rows);
    }

    /** 通用导出（内置字段 + 自定义字段 + 可选附加列） */
    public static function export(string $type, array $rows, array $extraCols = []): string
    {
        $map   = self::$headers[$type] ?? [];
        $cmap  = self::customMap($type);
        $sp    = new Spreadsheet();
        $sheet = $sp->getActiveSheet();
        $col   = 1;
        $fieldByCol = [];
        foreach (array_keys($map) as $h) {
            $field = $map[$h];
            $sheet->setCellValue(self::col($col) . '1', $h);
            $sheet->getColumnDimension(self::col($col))->setWidth(18);
            $fieldByCol[$col] = $field;
            $col++;
        }
        // 自定义字段追加在末尾
        foreach (array_keys($cmap) as $h) {
            $field = $cmap[$h];
            $sheet->setCellValue(self::col($col) . '1', $h);
            $sheet->getColumnDimension(self::col($col))->setWidth(18);
            $fieldByCol[$col] = $field;
            $col++;
        }
        // v2.99.59：附加列（明文导出水印：导出人 / 时间 / 用途）
        foreach (array_keys($extraCols) as $h) {
            $sheet->setCellValue(self::col($col) . '1', $h);
            $sheet->getColumnDimension(self::col($col))->setWidth(22);
            $col++;
        }
        $sheet->getStyle('1:1')->getFont()->setBold(true);
        // 文本字段整列锁定为文本格式，防止导出值/用户手填值变成科学计数法
        foreach ($fieldByCol as $c => $field) {
            if (in_array($field, self::$textFields, true)) {
                $sheet->getStyle(self::col($c) . ':' . self::col($c))->getNumberFormat()->setFormatCode('@');
            }
        }

        $r = 2;
        foreach ($rows as $row) {
            $c = 1;
            foreach ($map as $field) {
                $v = $row[$field] ?? '';
                // 证书导出的人员身份证号：行内没有则取关联 staff（与详情口径一致）
                if ($v === '' && $field === 'id_card') {
                    $v = $row['staff']['id_card'] ?? '';
                }
                if (is_array($v)) {
                    $v = implode('、', $v);
                }
                $coord = self::col($c) . $r;
                if (in_array($field, self::$textFields, true)) {
                    self::setTextCell($sp, $coord, $v);
                } else {
                    $sheet->setCellValue($coord, self::safeCellValue($v));
                }
                $c++;
            }
            $custom = $row['custom'] ?? [];
            foreach ($cmap as $key) {
                $v = $custom[$key] ?? '';
                if (is_bool($v)) {
                    $v = $v ? '是' : '否';
                } elseif (is_array($v)) {
                    $v = implode('、', $v);
                }
                $sheet->setCellValue(self::col($c) . $r, self::safeCellValue($v));
                $c++;
            }
            // 附加列取值（水印）
            foreach ($extraCols as $field) {
                $sheet->setCellValue(self::col($c) . $r, self::safeCellValue($row[$field] ?? ''));
                $c++;
            }
            $r++;
        }
        return self::save($sp, $type);
    }

    /** 列序号(1起) 转 Excel 列标：1->A, 27->AA */
    protected static function col(int $index): string
    {
        $s = '';
        while ($index > 0) {
            $rem = ($index - 1) % 26;
            $s    = chr(65 + $rem) . $s;
            $index = intdiv($index - 1, 26);
        }
        return $s;
    }

    /** 生成空模板（只有表头 + 一行示例，含自定义字段列） */
    public static function template(string $type): string
    {
        $map   = self::headers($type, true);
        $cmap  = self::customMap($type);
        $sp    = new Spreadsheet();
        $sheet = $sp->getActiveSheet();
        $col   = 1;
        $fieldByCol = [];
        foreach (array_keys($map) as $h) {
            $field = $map[$h];
            $sheet->setCellValue(self::col($col) . '1', $h);
            $sheet->getColumnDimension(self::col($col))->setWidth(18);
            $fieldByCol[$col] = $field;
            $col++;
        }
        foreach (array_keys($cmap) as $h) {
            $field = $cmap[$h];
            $sheet->setCellValue(self::col($col) . '1', $h);
            $sheet->getColumnDimension(self::col($col))->setWidth(18);
            $fieldByCol[$col] = $field;
            $col++;
        }
        $sheet->getStyle('1:1')->getFont()->setBold(true);
        // 文本字段整列锁定为文本格式，避免用户照模板手填时身份证号/电话被转成科学计数法或丢前导零
        foreach ($fieldByCol as $c => $field) {
            if (in_array($field, self::$textFields, true)) {
                $sheet->getStyle(self::col($c) . ':' . self::col($c))->getNumberFormat()->setFormatCode('@');
            }
        }
        $samples = self::$samples;
        if (!empty($samples[$type])) {
            $c = 1;
            foreach ($samples[$type] as $v) {
                $coord = self::col($c) . '2';
                if (isset($fieldByCol[$c]) && in_array($fieldByCol[$c], self::$textFields, true)) {
                    self::setTextCell($sp, $coord, $v);
                } else {
                    $sheet->setCellValue($coord, self::safeCellValue($v));
                }
                $c++;
            }
        }
        // v2.37：下拉框字段生成真实 Excel 下拉（数据验证），选项带出字典/枚举值
        self::applyTemplateDropdowns($sp, $type, $fieldByCol);
        return self::save($sp, $type . '_template');
    }

    /**
     * v2.37：为导入模板生成真实 Excel 下拉框（数据验证）
     *
     * 设计：
     * - 选项集中写入隐藏「选项」sheet，主表 DV 用范围引用（规避内联列表 255 字符上限）；
     * - 证书类型/专业取自 cert_major 两级字典（管理员在字典管理页维护，下载模板即最新）；
     * - 证书专业列直接平铺**字典全部专业**（同名专业跨类型只留一项，按所属类型分组排序）：
     *   曾用 OFFSET/MATCH/COUNTIF 做「选类型→只列该类型专业」级联，但该公式在部分
     *   Excel/WPS 的 DV 中不可靠（实测只列出一项），故改为最通用的范围引用；
     *   专业与类型的匹配由导入预览校验 + 预览页改选保证（软校验，不阻断）；
     * - 软校验：showErrorMessage=false，不拦截手工输入与粘贴，导入预览环节再校验。
     *
     * @param array $fieldByCol [列序号 => 字段名]
     */
    protected static function applyTemplateDropdowns(Spreadsheet $sp, string $type, array $fieldByCol): void
    {
        $colOf = array_flip($fieldByCol); // 字段 => 列序号
        if (!$colOf) {
            return;
        }
        $plain = self::templateOptions($type); // [字段 => [标题, 选项]]

        // 证书类型/专业两级字典（字典未初始化时对应列不生成下拉）
        $dict = ['types' => [], 'majors' => []];
        if ($type === 'certificate') {
            try {
                $dict = CertDictService::cascade();
            } catch (\Throwable $e) {
                // 字典表异常时退化为「仅枚举列有下拉」
            }
        }
        $typeLabels = [];
        foreach (($dict['types'] ?? []) as $t) {
            $label = trim((string)($t['dict_label'] ?? ''));
            if ($label !== '') {
                $typeLabels[$label] = true;
            }
        }
        $typeLabels = array_keys($typeLabels);
        // 全部专业（去重）：同名专业跨类型合法（如「建筑工程」同属一建/二建），下拉只列一项
        $majorLabels = [];
        foreach (($dict['majors'] ?? []) as $m) {
            $label = trim((string)($m['dict_label'] ?? ''));
            if ($label !== '') {
                $majorLabels[$label] = true;
            }
        }
        $majorLabels = array_keys($majorLabels);

        $hasPlain = false;
        foreach ($plain as $items) {
            if (!empty($items[1])) { $hasPlain = true; break; }
        }
        if (!$hasPlain && !$typeLabels && !$majorLabels) {
            return; // 没有任何可下选的选项，不建隐藏 sheet
        }

        // 隐藏「选项」sheet：行1标题、行2起数据，逐列写入
        $opt    = $sp->createSheet();
        $opt->setTitle('选项');
        $col    = 1;
        $ranges = []; // 字段 => 范围（公式1）
        $writeCol = function (string $title, array $items) use ($opt, &$col): string {
            $letter = self::col($col++);
            $opt->setCellValue($letter . '1', $title);
            foreach ($items as $i => $v) {
                $opt->setCellValueExplicit($letter . (2 + $i), (string)$v, DataType::TYPE_STRING);
            }
            $opt->getColumnDimension($letter)->setWidth(18);

            return '选项!$' . $letter . '$2:$' . $letter . '$' . (1 + count($items));
        };

        foreach ($plain as $field => $spec) {
            if (empty($spec[1]) || !isset($colOf[$field])) {
                continue;
            }
            $ranges[$field] = $writeCol($spec[0], $spec[1]);
        }
        if ($type === 'certificate' && $typeLabels && isset($colOf['cert_type'])) {
            $ranges['cert_type'] = $writeCol('证书类型', $typeLabels);
        }

        if ($type === 'certificate' && $majorLabels && isset($colOf['cert_name'])) {
            $ranges['cert_name'] = $writeCol('证书专业', $majorLabels);
        }
        $opt->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
        $sp->setActiveSheetIndex(0); // 打开模板时落在主表，而非隐藏选项 sheet

        // 主表写数据验证：覆盖表头以下 2..1000 行（含示例行），一个范围一条 DV
        $main = $sp->getActiveSheet();
        foreach ($ranges as $field => $formula) {
            if (!isset($colOf[$field])) {
                continue;
            }
            $letter = self::col($colOf[$field]);
            self::addListValidation($main, $letter . '2:' . $letter . '1000', $formula);
        }
    }

    /**
     * v2.37：模板下拉选项来源（非字典列）
     * 证书类型/证书专业不走这里——由 applyTemplateDropdowns 从 cert_major 字典动态生成。
     *
     * @return array [字段 => [中文标题, 选项数组]]
     */
    protected static function templateOptions(string $type): array
    {
        return [
            'certificate' => [
                'cert_status_text'   => ['证书状态', ['正常', '已过期', '挂失', '注销']],
                'social_status_text' => ['社保状态', ['在缴', '停缴', '未缴纳']],
                'priority'           => ['优先级', self::dictLabels('priority', ['普通', '重要', '紧急'])],
            ],
            'staff'       => ['status_text' => ['状态', ['在职', '离职']]],
            'company'     => ['status_text' => ['状态', ['启用', '停用']]],
            'project'     => ['status_text' => ['项目状态', ['筹备中', '进行中', '已结束', '已暂停']]],
            'policy_rule' => [
                'rule_type_text' => ['规则类型', self::dictLabels('policy_rule_type', array_values(PolicyRuleService::TYPES))],
                'severity_text'  => ['违规级别', self::dictLabels('policy_severity', array_values(PolicyRuleService::SEVERITY))],
            ],
            'credential_vault' => [
                'account_type_text' => ['账号类型', ['企业账号', '个人账号']],
            ],
            // v2.71：申报进度业务类型下拉（来自字典 gov_business_type，缺失回退内置类型）
            'gov_progress' => [
                'business_type_text' => ['业务类型', array_values(GovProgressService::businessTypes())],
            ],
            // v2.94：企业证书类型下拉
            'company_cert' => [
                'q_type_text' => ['证书类型', ['安许', '资质']],
            ],
        ][$type] ?? [];
    }

    /**
     * v2.37：读取通用字典标签列表（模板下拉用；表缺失/无数据返回兜底值）
     */
    protected static function dictLabels(string $typeCode, array $fallback = []): array
    {
        try {
            $labels = Db::name('dict_data')
                ->where('type_code', $typeCode)
                ->where('status', 1)
                ->order('sort', 'asc')
                ->order('id', 'asc')
                ->column('dict_label');
            $out = [];
            foreach ($labels as $l) {
                $l = trim((string)$l);
                if ($l !== '') {
                    $out[$l] = true; // 去重保序
                }
            }
            if ($out) {
                return array_keys($out);
            }
        } catch (\Throwable $e) {
            // 字典表异常时走兜底
        }
        return $fallback;
    }

    /**
     * v2.37：追加一个列表型数据验证
     * 注意 showDropDown 的 OOXML 语义与属性名相反（true=显示下拉箭头），必须显式置 true。
     */
    protected static function addListValidation(Worksheet $sheet, string $range, string $formula): void
    {
        $dv = new DataValidation();
        $dv->setType(DataValidation::TYPE_LIST);
        $dv->setAllowBlank(true);
        $dv->setShowDropDown(true);        // 显示单元格右侧下拉箭头
        $dv->setShowInputMessage(true);
        $dv->setPromptTitle('请选择');
        $dv->setPrompt('点击下拉箭头选择，也可直接输入（导入时会校验）');
        $dv->setShowErrorMessage(false);   // 软校验：不拦截手工输入/粘贴
        $dv->setErrorStyle(DataValidation::STYLE_WARNING);
        $dv->setFormula1($formula);
        $sheet->setDataValidation($range, $dv);
    }

    /**
     * 解析导入的 Excel，返回关联数组列表
     */
    public static function importCertificate(string $filePath): array
    {
        return self::import('certificate', $filePath);
    }

    public static function importStaff(string $filePath): array
    {
        return self::import('staff', $filePath);
    }

    public static function importCompany(string $filePath): array
    {
        return self::import('company', $filePath);
    }

    public static function import(string $type, string $filePath): array
    {
        $sp    = IOFactory::load($filePath);
        $sheet = $sp->getActiveSheet();
        $data  = $sheet->toArray();
        if (count($data) < 2) {
            return [];
        }
        $map     = self::headers($type, true);
        // 旧表头兼容：把历史别名解析为新表头后再查映射
        $alias   = self::$headerAlias[$type] ?? [];
        $cmap    = self::customMap($type);
        $headers = array_shift($data);
        // 每列解析为 ['f', 字段名] 或 ['c', 自定义field_key] 或 null
        $keys = [];
        foreach ($headers as $h) {
            $h = trim((string)$h);
            if (isset($alias[$h])) {
                $h = $alias[$h];
            }
            if ($h === '') {
                $keys[] = null;
            } elseif (isset($map[$h])) {
                $keys[] = ['f', $map[$h]];
            } elseif (isset($cmap[$h])) {
                $keys[] = ['c', $cmap[$h]];
            } else {
                $keys[] = null;
            }
        }
        $result = [];
        foreach ($data as $line) {
            $item = [];
            foreach ($keys as $idx => $spec) {
                if (!$spec) {
                    continue;
                }
                $v = is_scalar($line[$idx] ?? null) ? trim((string)$line[$idx]) : ($line[$idx] ?? '');
                if ($spec[0] === 'f') {
                    $item[$spec[1]] = $v;
                } else {
                    $item['custom'][$spec[1]] = $v;
                }
            }
            // 过滤全空行
            $filled = array_filter($item, function ($v) {
                if (is_array($v)) {
                    return (bool)array_filter($v, function ($x) { return $x !== '' && $x !== null; });
                }
                return $v !== '' && $v !== null;
            });
            if ($filled && !self::isSampleRow($type, $item)) {
                $result[] = $item;
            }
        }
        return $result;
    }

    /**
     * v2.53：判断解析行是否为模板示例行（整行与 $samples[$type] 逐列完全一致）。
     * 防止用户下载模板后未删除示例行直接导入，把示例数据写入生产库。
     */
    protected static function isSampleRow(string $type, array $item): bool
    {
        $sample = self::$samples[$type] ?? [];
        if (!$sample) {
            return false;
        }
        $fieldByCol = self::headers($type, true);
        $i = 0;
        foreach ($fieldByCol as $field) {
            if (!array_key_exists($i, $sample)) {
                break;
            }
            $sampleVal = trim((string)$sample[$i]);
            $itemVal   = trim((string)($item[$field] ?? ''));
            if ($sampleVal !== $itemVal) {
                return false;
            }
            $i++;
        }
        return true;
    }

    /**
     * v2.55：生成导入失败名单 Excel。
     *
     * 表头 = 「Excel行号」 + 模板表头列 + 「导入失败原因」，逐行还原失败记录的
     * 关键字段值并标注失败原因，方便用户对照原文件修正后重新导入。
     * 替换此前仅在前端提示前 3 条错误字符串的体验。
     *
     * @param string $type 导入类型（certificate/staff/company/project/policy_rule）
     * @param array $failedRows [['line'=>int,'data'=>array,'reason'=>string], ...]
     * @return string 生成的 xlsx 文件路径
     */
    public static function exportImportFail(string $type, array $failedRows): string
    {
        $map  = self::headers($type, true);
        $sp   = new Spreadsheet();
        $sheet = $sp->getActiveSheet();

        // 第一列：原始 Excel 行号
        $lineCol = 1;
        $sheet->setCellValue(self::col($lineCol) . '1', 'Excel行号');

        // 中间列：模板表头
        $fieldByCol = [];
        $col = 2;
        foreach ($map as $label => $field) {
            $sheet->setCellValue(self::col($col) . '1', $label);
            $fieldByCol[$col] = $field;
            $col++;
        }

        // 最后一列：失败原因
        $reasonCol = $col;
        $sheet->setCellValue(self::col($reasonCol) . '1', '导入失败原因');
        $sheet->getStyle('1:1')->getFont()->setBold(true);

        $r = 2;
        foreach ($failedRows as $item) {
            $data = $item['data'] ?? [];
            $sheet->setCellValueExplicit(self::col($lineCol) . $r, (string)($item['line'] ?? ''), DataType::TYPE_STRING);
            $c = 2;
            foreach ($map as $label => $field) {
                $v = $data[$field] ?? '';
                if (is_array($v)) {
                    $v = '';
                }
                // v2.71：失败名单不回写明文密码
                if ($field === 'password') {
                    $v = ((string)$v) === '' ? '' : '******';
                }
                $coord = self::col($c) . $r;
                $sheet->setCellValue($coord, self::safeCellValue($v));
                if (in_array($field, self::$textFields, true)) {
                    $sheet->getStyle($coord)->getNumberFormat()->setFormatCode('@');
                }
                $c++;
            }
            $sheet->setCellValue(self::col($reasonCol) . $r, self::safeCellValue((string)($item['reason'] ?? '')));
            $r++;
        }

        $sheet->getColumnDimension(self::col($lineCol))->setWidth(12);
        foreach ($fieldByCol as $c => $field) {
            $sheet->getColumnDimension(self::col($c))->setWidth(18);
        }
        $sheet->getColumnDimension(self::col($reasonCol))->setWidth(40);
        // 表头冻结，方便大名单核对
        $sheet->freezePane('A2');

        return self::save($sp, $type . '_fail');
    }

    /**
     * v2.72：导出导入「识别记录」Excel。
     *
     * 与失败名单不同：识别记录覆盖预览阶段的每一行，标注识别结果
     * （新增/更新/跳过）与说明，便于用户核对整批导入的判定并留存。
     * 密码列同样脱敏为 ******，防止明文外泄。
     *
     * @param string $type 导入类型
     * @param array $rows 预览结果 [['line'=>int,'data'=>array,'status'=>string,'message'=>string,'warnings'=>array], ...]
     * @return string 生成的 xlsx 文件路径
     */
    public static function exportImportRecognition(string $type, array $rows): string
    {
        $map   = self::headers($type, true);
        $sp    = new Spreadsheet();
        $sheet = $sp->getActiveSheet();

        // 识别状态 → 中文
        $statusText = [
            'new'            => '新增', 'update' => '更新',
            'new_cert'       => '新增证书', 'new_staff' => '新增人员', 'new_company' => '新增企业',
            'update_staff'   => '更新人员', 'update_company' => '更新企业',
            'new_project'    => '新增项目', 'new_rule' => '新增规则', 'new_progress' => '新增申报',
            'new_vault'      => '新增账号', 'update_vault' => '更新账号',
            'skip'           => '跳过',
        ];

        // 前三列：Excel行号 / 识别结果 / 识别说明
        $lineCol   = 1;
        $statusCol = 2;
        $msgCol    = 3;
        $sheet->setCellValue(self::col($lineCol) . '1', 'Excel行号');
        $sheet->setCellValue(self::col($statusCol) . '1', '识别结果');
        $sheet->setCellValue(self::col($msgCol) . '1', '识别说明');

        // 之后：模板表头列
        $fieldByCol = [];
        $col = 4;
        foreach ($map as $label => $field) {
            $sheet->setCellValue(self::col($col) . '1', $label);
            $fieldByCol[$col] = $field;
            $col++;
        }
        $sheet->getStyle('1:1')->getFont()->setBold(true);

        $r = 2;
        foreach ($rows as $item) {
            $data = $item['data'] ?? [];
            $sheet->setCellValueExplicit(self::col($lineCol) . $r, (string)($item['line'] ?? ''), DataType::TYPE_STRING);
            $status = (string)($item['status'] ?? '');
            $sheet->setCellValue(self::col($statusCol) . $r, $statusText[$status] ?? $status);

            $msg      = (string)($item['message'] ?? '');
            $warnings = $item['warnings'] ?? [];
            if (is_array($warnings) && $warnings) {
                $msg = trim($msg . ($msg !== '' ? '；' : '') . implode('；', $warnings));
            }
            $sheet->setCellValue(self::col($msgCol) . $r, self::safeCellValue($msg));

            $c = 4;
            foreach ($map as $label => $field) {
                $v = $data[$field] ?? '';
                if (is_array($v)) {
                    $v = '';
                }
                // 密码列统一脱敏，识别记录不回写明文
                if ($field === 'password') {
                    $v = ((string)$v) === '' ? '' : '******';
                }
                $coord = self::col($c) . $r;
                $sheet->setCellValue($coord, self::safeCellValue($v));
                if (in_array($field, self::$textFields, true)) {
                    $sheet->getStyle($coord)->getNumberFormat()->setFormatCode('@');
                }
                $c++;
            }
            $r++;
        }

        $sheet->getColumnDimension(self::col($lineCol))->setWidth(12);
        $sheet->getColumnDimension(self::col($statusCol))->setWidth(12);
        $sheet->getColumnDimension(self::col($msgCol))->setWidth(48);
        foreach ($fieldByCol as $c => $field) {
            $sheet->getColumnDimension(self::col($c))->setWidth(18);
        }
        $sheet->freezePane('A2');

        return self::save($sp, $type . '_recognize');
    }

    protected static function save(Spreadsheet $sp, string $type): string
    {
        $dir = app()->getRuntimePath() . 'export/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $path   = $dir . $type . '_' . date('YmdHis') . '.xlsx';
        $writer = new Xlsx($sp);
        $writer->save($path);
        return $path;
    }
}
