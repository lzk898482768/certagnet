<?php
namespace app\service;

use app\model\Certificate as CertificateModel;
use app\model\ClientCompany;
use app\model\Staff;
use app\model\CertSecondment;
use think\facade\Db;

/**
 * 统一导入服务：解析 -> 预览 -> 提交 三段式
 *
 * 解决此前「证照导入时人员/企业不存在整行被静默跳过」的隐患：
 * - preview() 解析 Excel 后逐行判定存在性，返回可读的状态（新增/更新/将新建/跳过）；
 * - commit() 按预览结果入库，人员/企业不存在且开启自动创建时自动建档（仅证书导入需要，
 *   staff/company 导入本就「不存在即建」）。
 *
 * 前端流程：上传文件 -> preview 拿到预览 -> 用户勾选确认 -> commit 入库（传 JSON，不再二次传文件）。
 */
class ImportService
{
    /** 社保状态文本 -> 数值（与 Certificate 控制器保持一致） */
    public const SOCIAL_MAP = ['未知' => 0, '在缴' => 1, '停缴' => 2, '未缴纳' => 3];

    /** v2.37：证书状态文本 -> 数值（与 Certificate 模型 cert_status 映射一致） */
    public const CERT_STATUS_MAP = ['正常' => 1, '已过期' => 0, '挂失' => 2, '注销' => 3];

    /** 项目状态文本 -> 数值（与 Project::STATUS 保持一致） */
    public const PROJECT_STATUS_MAP = ['筹备中' => 0, '筹备' => 0, '进行中' => 1, '已结束' => 2, '已暂停' => 3];

    /** 解析 Excel 为关联数组列表（复用 ExcelService 的表头映射） */
    public static function parse(string $type, string $filePath): array
    {
        if ($type === 'certificate') return ExcelService::importCertificate($filePath);
        if ($type === 'staff')       return ExcelService::importStaff($filePath);
        if ($type === 'company')     return ExcelService::importCompany($filePath);
        if ($type === 'project')     return ExcelService::import('project', $filePath);
        if ($type === 'policy_rule') return ExcelService::import('policy_rule', $filePath);
        if ($type === 'gov_progress') return ExcelService::import('gov_progress', $filePath);
        if ($type === 'credential_vault') return ExcelService::import('credential_vault', $filePath);
        return [];
    }

    /**
     * 预览：解析 + 逐行判存在性
     * @param bool $autoCreate 是否允许对不存在的人员/企业自动建档（仅影响证书导入）
     */
    public static function preview(string $type, string $filePath, bool $autoCreate = true): array
    {
        // v2.99.59：身份证盲索引列兜底自愈（导入查重依赖 id_card_hash）
        \app\service\IdCardSchemaService::ensure();
        $rows = self::parse($type, $filePath);
        // v2.38：批量预热人员/企业缓存（一次 SQL 拉回整批），把逐行 find() 的 N+1 消掉。
        // 这是"导入预览卡顿"的根治手段；Redis 未命中时的负缓存同样写入，避免反复回表。
        self::prewarmFor($type, $rows);
        $out = [];
        // v2.71：申报进度文件内申报号去重（与库内已存在判定分开，提示可区分）
        $govSeen = [];
        // v2.72：账号密码库文件内去重（平台+账号类型+登录账号+企业+人员）
        $vaultSeen = [];
        // v2.55：证书编号允许不唯一——预览阶段不再做「库内已存在/文件内重复」查重。
        // 此举同时根治两个问题：① whereIn+field('cert_no')->toArray() 会触发 Certificate
        // 模型的 $append 访问器读取缺失的 cert_status 键，导致「Undefined array key
        // "cert_status"」解析报错；② 大文件逐条查库造成预览卡顿。
        foreach ($rows as $i => $row) {
            $item = self::previewRow($type, $row, $i + 2, $autoCreate);
            if ($type === 'gov_progress' && $item['status'] !== 'skip') {
                $no = mb_strtoupper(trim((string)($row['project_no'] ?? '')));
                if ($no !== '' && isset($govSeen[$no])) {
                    $item['status'] = 'skip';
                    $item['message'] = '文件内申报号重复（与第 ' . $govSeen[$no] . ' 行相同），已跳过';
                    $item['warnings'] = [];
                } else {
                    $govSeen[$no] = $i + 2;
                }
            }
            if ($type === 'credential_vault' && $item['status'] !== 'skip') {
                $sig = self::vaultDedupKey($row);
                if ($sig !== '' && isset($vaultSeen[$sig])) {
                    $item['status'] = 'skip';
                    $item['message'] = '文件内重复：与第 ' . $vaultSeen[$sig] . ' 行相同（平台+账号类型+登录账号+企业+人员），已跳过';
                    $item['warnings'] = [];
                } else {
                    $vaultSeen[$sig] = $i + 2;
                }
            }
            $out[] = $item;
        }
        return $out;
    }

    /** v2.72：账号密码库去重签名（平台+账号类型+登录账号+企业+人员） */
    protected static function vaultDedupKey(array $row): string
    {
        $platform = mb_strtoupper(trim((string)($row['platform'] ?? '')));
        $account  = trim((string)($row['account_name'] ?? ''));
        if ($platform === '' || $account === '') {
            return '';
        }
        $typeText = trim((string)($row['account_type_text'] ?? ''));
        $type     = mb_strpos($typeText, '个人') !== false ? 'personal' : 'company';
        $companyName = trim((string)($row['company_name'] ?? ''));
        $staffName   = trim((string)($row['staff_name'] ?? ''));
        $companyId = $companyName !== '' ? (int)(Lookup::companyByName($companyName)['id'] ?? 0) : 0;
        $staffId   = $staffName !== '' ? (int)(Lookup::staffByName($staffName)['id'] ?? 0) : 0;
        return implode('|', [$platform, $type, $account, $companyId, $staffId]);
    }

    /** 按导入类型收集键并批量预热 */
    protected static function prewarmFor(string $type, array $rows): void
    {
        Lookup::resetMemo();
        if (in_array($type, ['certificate', 'staff', 'gov_progress', 'credential_vault'], true)) {
            $idCards = [];
            $names   = [];
            foreach ($rows as $r) {
                $idCards[] = (string)($r['id_card'] ?? '');
                $names[]   = (string)($r['staff_name'] ?? '');
            }
            Lookup::prewarmStaff($idCards, $names);
        }
        if (in_array($type, ['certificate', 'company', 'project', 'gov_progress', 'credential_vault'], true)) {
            $comps = [];
            foreach ($rows as $r) {
                $comps[] = (string)($r['company_name'] ?? '');
            }
            Lookup::prewarmCompany($comps);
        }
    }

    protected static function previewRow(string $type, array $row, int $line, bool $autoCreate): array
    {
        $base = [
            'line'                => $line,
            'data'                => $row,
            'status'              => 'new',
            'message'             => '',
            'will_create_staff'   => false,
            'will_create_company' => false,
            'warnings'            => [],
        ];

        if ($type === 'certificate') {
            // 必填项（数据库 NOT NULL / 关联必需）为空 → 预览期即提示并跳过，避免入库时才报错
            $missing = [];
            if (!trim((string)($row['staff_name'] ?? ''))) $missing[] = '人员姓名';
            if (!trim((string)($row['cert_name'] ?? '')))  $missing[] = '证书专业';
            if (!trim((string)($row['cert_no'] ?? '')))    $missing[] = '证书编号';
            if (!trim((string)($row['expire_date'] ?? '')))$missing[] = '到期日期';
            if ($missing) {
                return array_merge($base, ['status' => 'skip', 'message' => '必填项为空：' . implode('、', $missing)]);
            }
            // 建议填写项为空 → 仅提示，不阻断导入
            $warn = [];
            $warn = array_merge($warn, self::qualityWarnings('certificate', $row));
            if (!trim((string)($row['id_card'] ?? '')))    $warn[] = '身份证号为空';
            if (!trim((string)($row['issue_date'] ?? ''))) $warn[] = '发证日期为空';
            // 状态/社保文本无法识别时提示（软校验：分别按 正常/未知 导入；预览页可改选）
            $stText = trim((string)($row['cert_status_text'] ?? ''));
            if ($stText !== '' && !isset(self::CERT_STATUS_MAP[$stText])) {
                $warn[] = "证书状态「{$stText}」无法识别，将按正常导入";
            }
            $socText = trim((string)($row['social_status_text'] ?? ''));
            if ($socText !== '' && !isset(self::SOCIAL_MAP[$socText])) {
                $warn[] = "社保状态「{$socText}」无法识别，将按未知导入";
            }
            // v2.37：证书类型/专业按两级字典软校验——非法值仅提示不阻断，
            // 用户在预览页可改选（含批量填充），确认后再入库。
            // 类型为空但专业可唯一归属某类型时先自动回填（预览即展示，入库同口径），
            // 仍为空才提示去预览页批量填充。
            if (trim((string)($row['cert_type'] ?? '')) === '') {
                $derived = self::deriveTypeByName(trim((string)($row['cert_name'] ?? '')));
                if ($derived !== '') {
                    $row['cert_type'] = $derived;
                    $base['data']['cert_type'] = $derived;
                }
            }
            foreach (self::checkCertDict($row) as $dictWarn) $warn[] = $dictWarn;
            if (trim((string)($row['cert_type'] ?? '')) === '') {
                $warn[] = '证书类型为空（可在预览页批量填充）';
            }

            $staffName = trim($row['staff_name']);
            $idCard    = trim((string)($row['id_card'] ?? ''));
            // 人员匹配：有身份证号优先按身份证号（精准），否则按姓名
            // v2.38：走 Lookup（请求内 memo → Redis → 单条回表），批量场景已在 prewarmFor 预热
            $staff = $idCard !== ''
                ? Lookup::staffByIdCard($idCard)
                : Lookup::staffByName($staffName);
            if (!$staff && $idCard !== '') {
                // 身份证号没匹配上时，再按姓名兜底一次（避免同姓同名漏档）
                $staff = Lookup::staffByName($staffName);
            }
            if (!$staff) {
                if (!$autoCreate) {
                    return array_merge($base, ['status' => 'skip', 'message' => "人员「{$staffName}」不存在，且未开启自动创建"]);
                }
                $base['will_create_staff'] = true;
            }
            $companyName = trim($row['company_name'] ?? '');
            $msg = '将新增证书';
            if ($companyName && !Lookup::companyByName($companyName)) {
                $base['will_create_company'] = true;
                $msg .= '；借调企业「' . $companyName . '」不存在，将自动建档';
            }
            $base['warnings'] = $warn;
            return array_merge($base, ['status' => 'new_cert', 'message' => $msg]);
        }

        if ($type === 'staff') {
            $name = trim($row['staff_name'] ?? '');
            if (!$name) {
                return array_merge($base, ['status' => 'skip', 'message' => '姓名为空']);
            }
            $warn = [];
            $warn = array_merge($warn, self::qualityWarnings('staff', $row));
            if (empty($row['id_card'])) $warn[] = '身份证号为空';
            if (empty($row['phone']))   $warn[] = '联系电话为空';
            $exists = !empty($row['id_card'])
                ? Lookup::staffByIdCard((string)$row['id_card'])
                : Lookup::staffByName($name);
            $base['warnings'] = $warn;
            return $exists
                ? array_merge($base, ['status' => 'update_staff', 'message' => '人员已存在，将更新'])
                : array_merge($base, ['status' => 'new_staff', 'message' => '将新增人员']);
        }

        if ($type === 'company') {
            $name = trim($row['company_name'] ?? '');
            if (!$name) {
                return array_merge($base, ['status' => 'skip', 'message' => '企业全称为空']);
            }
            $warn = [];
            $warn = array_merge($warn, self::qualityWarnings('company', $row));
            if (empty($row['credit_code']))   $warn[] = '统一社会信用代码为空';
            if (empty($row['contact_phone'])) $warn[] = '联系电话为空';
            $exists = Lookup::companyByName($name);
            $base['warnings'] = $warn;
            return $exists
                ? array_merge($base, ['status' => 'update_company', 'message' => '企业已存在，将更新'])
                : array_merge($base, ['status' => 'new_company', 'message' => '将新增企业']);
        }

        // project
        if ($type === 'project') {
            $name = trim($row['project_name'] ?? '');
            if (!$name) {
                return array_merge($base, ['status' => 'skip', 'message' => '项目名称为空']);
            }
            $warn = [];
            $statusText = trim((string)($row['status_text'] ?? ''));
            if ($statusText !== '' && !isset(self::PROJECT_STATUS_MAP[$statusText])) {
                $warn[] = "项目状态「{$statusText}」无法识别，将按筹备中导入";
            }
            if (trim((string)($row['reqs_text'] ?? '')) === '') {
                $warn[] = '证件需求为空（可后续在项目详情中补充）';
            }
            $companyName = trim((string)($row['company_name'] ?? ''));
            if ($companyName !== '' && !Lookup::companyByName($companyName)) {
                $warn[] = "客户企业「{$companyName}」不存在，将仅记录名称不关联";
            }
            $base['warnings'] = $warn;
            return array_merge($base, ['status' => 'new_project', 'message' => '将新增项目']);
        }

        // policy_rule
        if ($type === 'policy_rule') {
            $typeText = trim((string)($row['rule_type_text'] ?? ''));
            $typeKey  = array_search($typeText, PolicyRuleService::TYPES, true);
            if ($typeKey === false || $typeKey === null) {
                return array_merge($base, ['status' => 'skip', 'message' => "规则类型「{$typeText}」无法识别（应为：社保匹配/有效期下限/证书类型限制）"]);
            }
            if (trim((string)($row['rule_expr'] ?? '')) === '') {
                return array_merge($base, ['status' => 'skip', 'message' => '规则参数为空（如 min_expire_days=90）']);
            }
            $warn = [];
            $sevText = trim((string)($row['severity_text'] ?? ''));
            if ($sevText !== '' && !in_array($sevText, PolicyRuleService::SEVERITY, true)) {
                $warn[] = "违规级别「{$sevText}」无法识别，将按警告导入";
            }
            $base['warnings'] = $warn;
            return array_merge($base, ['status' => 'new_rule', 'message' => '将新增政策规则']);
        }

        // v2.71：政务网申报进度
        if ($type === 'gov_progress') {
            $projectNo = trim((string)($row['project_no'] ?? ''));
            $password  = trim((string)($row['password'] ?? ''));
            $missing   = [];
            if ($projectNo === '') $missing[] = '申报号';
            if ($password === '' || $password === '-' || $password === '—') $missing[] = '申报密码';
            if ($missing) {
                return array_merge($base, ['status' => 'skip', 'message' => '必填项为空：' . implode('、', $missing)]);
            }
            if (!preg_match('/^[A-Za-z0-9_\-\/]{4,64}$/', $projectNo)) {
                return array_merge($base, ['status' => 'skip', 'message' => '申报号格式不正确（4-64 位字母、数字、下划线、短横线）']);
            }
            if (mb_strlen($password) > 64) {
                return array_merge($base, ['status' => 'skip', 'message' => '申报密码过长（最多 64 位）']);
            }

            // 业务类型：支持填中文名或字典值，空值默认「初始注册」
            $types   = GovProgressService::businessTypes();
            $typeText = trim((string)($row['business_type_text'] ?? ''));
            $typeKey  = '';
            if ($typeText === '') {
                $typeKey = isset($types['initial']) ? 'initial' : (string)(array_key_first($types) ?: 'initial');
            } elseif (isset($types[$typeText])) {
                $typeKey = $typeText;
            } else {
                $found = array_search($typeText, $types, true);
                if ($found !== false) {
                    $typeKey = (string)$found;
                }
            }
            if ($typeKey === '') {
                return array_merge($base, ['status' => 'skip',
                    'message' => "业务类型「{$typeText}」无法识别（可选：" . implode(' / ', array_values($types)) . '）']);
            }
            $row['business_type'] = $typeKey;
            $base['data']['business_type'] = $typeKey;

            // 申报号唯一：已存在的记录不做覆盖，避免误改密码 / 归属
            if (Db::name('gov_progress')->where('project_no', $projectNo)->find()) {
                return array_merge($base, ['status' => 'skip', 'message' => '申报号已存在，已跳过（如需修改请编辑原记录）']);
            }

            $warn = self::checkCertDict($row);
            $companyName = trim((string)($row['company_name'] ?? ''));
            if ($companyName !== '' && !Lookup::companyByName($companyName)) {
                $warn[] = "关联企业「{$companyName}」不存在，将仅记录名称不关联";
            }
            $staffName = trim((string)($row['staff_name'] ?? ''));
            if ($staffName !== '' && !Lookup::staffByName($staffName)) {
                $warn[] = "关联人员「{$staffName}」不存在，将仅记录名称不关联";
            }
            $promise = trim((string)($row['promise_time'] ?? ''));
            if ($promise !== '' && strtotime($promise) === false) {
                $warn[] = "承诺时间「{$promise}」格式无法识别（建议 YYYY-MM-DD），将留空";
            }
            $base['warnings'] = array_values(array_unique($warn));
            return array_merge($base, ['status' => 'new_progress', 'message' => '将新增申报并加密保存密码']);
        }

        // v2.72：账号密码库
        if ($type === 'credential_vault') {
            $platform    = trim((string)($row['platform'] ?? ''));
            $accountName = trim((string)($row['account_name'] ?? ''));
            $password   = trim((string)($row['password'] ?? ''));
            $missing = [];
            if ($platform === '') $missing[] = '平台名称';
            if ($accountName === '') $missing[] = '登录账号';
            if ($password === '' || $password === '-' || $password === '—') $missing[] = '登录密码';
            if ($missing) {
                return array_merge($base, ['status' => 'skip', 'message' => '必填项为空：' . implode('、', $missing)]);
            }
            if (mb_strlen($platform) > 128) {
                return array_merge($base, ['status' => 'skip', 'message' => '平台名称过长（最多 128 位）']);
            }
            if (mb_strlen($accountName) > 255) {
                return array_merge($base, ['status' => 'skip', 'message' => '登录账号过长（最多 255 位）']);
            }
            if (mb_strlen($password) > 255) {
                return array_merge($base, ['status' => 'skip', 'message' => '登录密码过长（最多 255 位）']);
            }

            // 账号类型：支持「企业账号 / 个人账号」，无法识别时按企业账号
            $typeText = trim((string)($row['account_type_text'] ?? ''));
            $type     = mb_strpos($typeText, '个人') !== false ? 'personal' : 'company';
            $row['account_type'] = $type;
            $base['data']['account_type'] = $type;

            // 企业 / 人员存在性：指定但不存在 → 开启自动建档时随账号一并建档（关闭时跳过）
            $companyName = trim((string)($row['company_name'] ?? ''));
            $companyId   = 0;
            $willCreateCompany = false;
            if ($companyName !== '') {
                $company = Lookup::companyByName($companyName);
                if (!$company) {
                    if (!$autoCreate) {
                        return array_merge($base, ['status' => 'skip', 'message' => "所属企业「{$companyName}」不存在，且未开启自动创建"]);
                    }
                    $willCreateCompany = true;
                } else {
                    $companyId = (int)$company['id'];
                }
            }
            $staffName = trim((string)($row['staff_name'] ?? ''));
            $staffId   = 0;
            $willCreateStaff = false;
            if ($staffName !== '') {
                $staff = Lookup::staffByName($staffName);
                if (!$staff) {
                    if (!$autoCreate) {
                        return array_merge($base, ['status' => 'skip', 'message' => "关联人员「{$staffName}」不存在，且未开启自动创建"]);
                    }
                    $willCreateStaff = true;
                } else {
                    $staffId = (int)$staff['id'];
                }
            }
            $base['data']['company_id'] = $companyId;
            $base['data']['staff_id']   = $staffId;
            $base['will_create_company'] = $willCreateCompany;
            $base['will_create_staff']   = $willCreateStaff;

            // 软校验：网址格式仅提示，不阻断
            $warn = [];
            $platformUrl = trim((string)($row['platform_url'] ?? ''));
            if ($platformUrl !== '' && !preg_match('#^https?://#i', $platformUrl)) {
                $warn[] = "平台网址「{$platformUrl}」不是 http(s) 链接，将按文本保存";
            }

            // 已存在 → 更新（平台+账号类型+登录账号+企业+人员 去重）
            $exist = Db::name('credential_vault')
                ->where('platform', $platform)
                ->where('account_type', $type)
                ->where('account_name', $accountName)
                ->where('company_id', $companyId)
                ->where('staff_id', $staffId)
                ->find();
            $base['warnings'] = $warn;
            if ($exist) {
                return array_merge($base, ['status' => 'update_vault', 'message' => '账号已存在，将更新密码/网址/备注']);
            }
            $msg = '将新增账号（密码加密保存）';
            if ($willCreateCompany) $msg .= '；所属企业「' . $companyName . '」不存在，将自动建档';
            if ($willCreateStaff)   $msg .= '；关联人员「' . $staffName . '」不存在，将自动建档';
            return array_merge($base, ['status' => 'new_vault', 'message' => $msg]);
        }

        return array_merge($base, ['status' => 'skip', 'message' => '不支持的导入类型']);
    }

    /**
     * 导入数据质量软校验：日期/证件号/手机号/邮箱/信用代码格式问题只提示，不阻断导入。
     * 返回可读提示列表。
     */
    public static function qualityWarnings(string $type, array $row): array
    {
        $warn = [];
        $dateOk = function (string $v): bool {
            return $v === '' || strtotime($v) !== false;
        };
        $idCardOk = function (string $v): bool {
            return $v === '' || (bool)preg_match('/^(\d{15}|\d{17}[\dXx])$/', $v);
        };
        $phoneOk = function (string $v): bool {
            return $v === '' || (bool)preg_match('/^[0-9+\-\s()]{6,24}$/', $v);
        };

        if ($type === 'certificate') {
            $issue  = trim((string)($row['issue_date'] ?? ''));
            $expire = trim((string)($row['expire_date'] ?? ''));
            if (!$dateOk($issue))  $warn[] = "发证日期「{$issue}」格式无法识别（建议 YYYY-MM-DD）";
            if (!$dateOk($expire)) $warn[] = "到期日期「{$expire}」格式无法识别（建议 YYYY-MM-DD）";
            if ($dateOk($issue) && $dateOk($expire) && $issue !== '' && $expire !== ''
                && strtotime($issue) !== false && strtotime($expire) !== false
                && strtotime($issue) > strtotime($expire)) {
                $warn[] = '发证日期晚于到期日期，请核对';
            } elseif ($expire !== '' && strtotime($expire) !== false && strtotime($expire) < strtotime(date('Y-m-d'))) {
                $warn[] = '到期日期已过，导入后将自动标记为「已过期」';
            }
            if (!$idCardOk(trim((string)($row['id_card'] ?? '')))) {
                $warn[] = '身份证号格式不正确（应为 15 位或 18 位）';
            }
        }

        if ($type === 'staff') {
            if (!$idCardOk(trim((string)($row['id_card'] ?? '')))) {
                $warn[] = '身份证号格式不正确（应为 15 位或 18 位）';
            }
            $phone = trim((string)($row['phone'] ?? ''));
            if (!$phoneOk($phone)) {
                $warn[] = "联系电话「{$phone}」格式不正确";
            }
            $email = trim((string)($row['email'] ?? ''));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $warn[] = "邮箱「{$email}」格式不正确";
            }
        }

        if ($type === 'company') {
            $code = trim((string)($row['credit_code'] ?? ''));
            if ($code !== '' && !preg_match('/^[0-9A-Za-z]{18}$/', $code)) {
                $warn[] = '统一社会信用代码应为 18 位字母数字';
            }
            $phone = trim((string)($row['contact_phone'] ?? ''));
            if (!$phoneOk($phone)) {
                $warn[] = "联系电话「{$phone}」格式不正确";
            }
        }

        return $warn;
    }

    /**
     * v2.37：证书类型/专业字典软校验，返回提示文案列表（空数组 = 通过）
     *
     * 口径与 CertDictService 一致：一级=证书类型（parent_value=''），
     * 二级=证书专业（parent_value=类型的 dict_value）。
     * - 类型/专业任一为空 → 不校验（专业为必填，理论上到不了这里）；
     * - 字典两级皆空（未初始化）→ 跳过校验，避免存量库被误报；
     * - 专业必须落在「所选类型」的二级下；未选类型时退化为「是否任意二级」。
     * 旧模板的「证书类别」列经 headerAlias 已映射为 cert_type，一并参与校验。
     */
    public static function checkCertDict(array $row): array
    {
        $certType = trim((string)($row['cert_type'] ?? ''));
        $certName = trim((string)($row['cert_name'] ?? ''));
        if ($certType === '' && $certName === '') {
            return [];
        }
        $dict   = self::dictAll();
        $types  = $dict['types'];
        $majors = $dict['majors'];
        if (!$types && !$majors) {
            return []; // 字典未启用，不校验
        }
        $warn   = [];
        $typeOk = $certType === '' || self::inDict($types, $certType);
        if ($certType !== '' && !$typeOk) {
            $warn[] = "证书类型「{$certType}」不在字典中，请在预览页重新选择";
        }
        if ($certName !== '') {
            // 专业必须在所选类型的二级下；未选/类型非法时退化为「是否任意二级」
            $scope = ($certType !== '' && $typeOk)
                ? array_values(array_filter($majors, function ($m) use ($certType) {
                    return (string)($m['parent_value'] ?? '') === $certType;
                }))
                : $majors;
            // v2.99.79：多专业逐项校验，避免整串「建筑工程、市政公用工程」被当成一个专业
            foreach (CertDictService::splitNames($certName) as $one) {
                if (!self::inDict($scope, $one)) {
                    $warn[] = ($certType !== '' && $typeOk)
                        ? "证书专业「{$one}」不属于「{$certType}」类型，请在预览页重新选择"
                        : "证书专业「{$one}」不在字典中，请在预览页重新选择";
                }
            }
        }
        return $warn;
    }

    /**
     * v2.37：专业名唯一归属时反查类型（仅当全字典中该专业只出现在一个类型下才回填；
     * 跨类型同名——如「建筑工程」同属一建/二建——时不猜，交由用户在预览页选择）
     */
    public static function deriveTypeByName(string $certName): string
    {
        $names = CertDictService::splitNames($certName);
        if (!$names) {
            return '';
        }
        // v2.99.79：多专业时取所有专业归属类型的交集，只有唯一类型才回填
        $parents = null;
        foreach ($names as $name) {
            $one = [];
            foreach (self::dictAll()['majors'] as $m) {
                if ((string)($m['dict_value'] ?? '') === $name || (string)($m['dict_label'] ?? '') === $name) {
                    $one[(string)($m['parent_value'] ?? '')] = true;
                }
            }
            if (!$one) {
                return '';
            }
            $parents = $parents === null ? $one : array_intersect_key($parents, $one);
            if (!$parents) {
                return '';
            }
        }
        return count($parents) === 1 ? (string)array_key_first($parents) : '';
    }

    /** 单次导入内的字典快照缓存（v2.38：底层 cascade 已走 Redis，此处仅做请求内 memo） */
    protected static function dictAll(): array
    {
        static $cache = null;
        if ($cache === null) {
            $cache = CertDictService::cascade();
        }
        return $cache;
    }

    /** 字典项命中：dict_value 或 dict_label 相等（两者通常同值，兼容分别维护的历史数据） */
    protected static function inDict(array $items, string $value): bool
    {
        foreach ($items as $it) {
            if ((string)($it['dict_value'] ?? '') === $value || (string)($it['dict_label'] ?? '') === $value) {
                return true;
            }
        }
        return false;
    }

    /**
     * 提交：按预览结果入库
     * @param array $rows 来自 preview 的数组（含 line/data/status）
     */
    public static function commit(string $type, array $rows, bool $autoCreate): array
    {
        // v2.99.59：身份证盲索引列兜底自愈（导入查重依赖 id_card_hash）
        \app\service\IdCardSchemaService::ensure();
        // v2.38：提交前重置请求内 memo（避免与 preview 阶段的旧值串味），
        // 并批量预热——前端可能只传回被勾选的行，键集合与 preview 不同。
        Lookup::resetMemo();
        self::prewarmFor($type, array_map(fn($it) => $it['data'] ?? [], $rows));

        $ok = 0;
        $createdStaff = 0;
        $createdCompany = 0;
        $errors = [];
        // v2.55：失败名单——逐行记录失败明细（Excel行号/原始数据/原因），
        // 前端弹窗展示并支持一键导出 Excel（替换此前仅提示前 3 条错误字符串的体验）。
        $failedRows = [];
        // v2.53：自定义字段必填校验（与编辑表单同口径；policy_rule 无自定义字段）
        $customModule = in_array($type, ['certificate', 'staff', 'company', 'project'], true) ? $type : '';
        foreach ($rows as $item) {
            $status = $item['status'] ?? '';
            $row    = $item['data'] ?? [];
            $line   = $item['line'] ?? '?';
            if ($status === 'skip') {
                $msg = '跳过（' . ($item['message'] ?? '') . '）';
                $errors[] = "第{$line}行：{$msg}";
                $failedRows[] = ['line' => $line, 'data' => $row, 'reason' => $msg];
                continue;
            }
            try {
                if ($customModule !== '') {
                    $cfErr = CustomFieldService::validate($customModule, $row['custom'] ?? []);
                    if ($cfErr !== '') {
                        throw new \Exception($cfErr);
                    }
                }
                // v2.55：证书编号允许不唯一——移除「本批重复即跳过」的硬校验。
                if ($type === 'certificate') {
                    $r = self::commitCertificate($row, $autoCreate);
                    if (!empty($r['staff_created']))   $createdStaff++;
                    if (!empty($r['company_created'])) $createdCompany++;
                    $ok++;
                } elseif ($type === 'staff') {
                    self::commitStaff($row);
                    $ok++;
                } elseif ($type === 'company') {
                    self::commitCompany($row);
                    $ok++;
                } elseif ($type === 'project') {
                    self::commitProject($row);
                    $ok++;
                } elseif ($type === 'policy_rule') {
                    self::commitPolicyRule($row);
                    $ok++;
                } elseif ($type === 'gov_progress') {
                    self::commitGovProgress($row);
                    $ok++;
                } elseif ($type === 'credential_vault') {
                    $r = self::commitCredentialVault($row, $autoCreate);
                    if (!empty($r['staff_created']))   $createdStaff++;
                    if (!empty($r['company_created'])) $createdCompany++;
                    $ok++;
                }
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                $errors[] = "第{$line}行失败：{$msg}";
                $failedRows[] = ['line' => $line, 'data' => $row, 'reason' => $msg];
            }
        }
        return [
            'ok'              => $ok,
            'created_staff'   => $createdStaff,
            'created_company' => $createdCompany,
            'errors'          => $errors,
            'failed_rows'     => $failedRows,
        ];
    }

    protected static function commitCertificate(array $row, bool $autoCreate): array
    {
        $staffName = trim($row['staff_name'] ?? '');
        $idCard    = trim((string)($row['id_card'] ?? ''));
        // 兜底校验：即便预览被绕过，必填项为空也给出可读错误，而非数据库报错
        // （口径与 previewRow 一致：人员姓名/证书专业/证书编号/到期日期）
        if ($staffName === '' || trim((string)($row['cert_name'] ?? '')) === ''
            || trim((string)($row['cert_no'] ?? '')) === '' || trim((string)($row['expire_date'] ?? '')) === '') {
            throw new \Exception('必填项为空（人员姓名/证书专业/证书编号/到期日期）');
        }
        // v2.55：日期规范化——Excel 中常见的 "-"、"0"、"/" 等占位符/非法日期，
        // 直接入库会触发 SQLSTATE[22007]（用户实测第 29/30/31 行即此场景）。
        // 必填日期规范化后仍为空 → 抛可读异常，进入失败名单；选填日期回退到兜底值。
        $expireDate = self::normDate($row['expire_date'] ?? '', '');
        if ($expireDate === '') {
            throw new \Exception('到期日期「' . trim((string)($row['expire_date'] ?? '')) . '」格式不正确，请使用 YYYY-MM-DD');
        }
        $issueDate = self::normDate($row['issue_date'] ?? '', '');
        // 人员匹配：有身份证号优先按身份证号（精准），否则按姓名；身份证号未命中再按姓名兜底
        // v2.38：走 Lookup（请求内 memo → Redis → 单条回表），消除提交链路 N+1
        $staff = $idCard !== ''
            ? Lookup::staffByIdCard($idCard)
            : Lookup::staffByName($staffName);
        if (!$staff && $idCard !== '') {
            $staff = Lookup::staffByName($staffName);
        }
        $staffCreated = false;
        $staffId      = $staff ? (int)$staff['id'] : 0;
        if (!$staff) {
            if (!$autoCreate) {
                throw new \Exception("人员「{$staffName}」不存在且未开启自动创建");
            }
            // v2.79.2：自动建档前防重——直接查库兜底（规避 Lookup 负缓存/并发），
            // 身份证号已存在时阻止重复建档并给出已有人员信息。
            if ($idCard !== '') {
                $dup = Db::name('staff')
                    ->where('id_card_hash', \app\service\IdCardService::hash($idCard))->find();
                if (!$dup) {
                    // 兼容未回填的历史明文行
                    $dup = Db::name('staff')->where('id_card', $idCard)->find();
                }
                if ($dup) {
                    throw new \Exception("自动建档失败：身份证号已存在（人员「" . ($dup['staff_name'] ?? '') . "」ID {$dup['id']}），请核对导入数据或使用「重复检测」合并");
                }
            }
            // 自动建档：姓名 + 身份证号（模板已支持身份证号列，导入即补齐关键身份信息）
            // v2.54：自动建档人员默认当前导入用户为创建人 + 负责人
            $importUserId = (int)($GLOBALS['__import_user_id'] ?? 0);
            $staffData = array_filter([
                'staff_name'     => $staffName,
                'id_card'        => $idCard,
                'create_by'      => $importUserId,
                'owner_user_id'  => $importUserId,
            ], function ($v) { return $v !== ''; });
            // v2.99.59：身份证密文 + 盲索引
            \app\service\IdCardService::prepareWrite($staffData);
            $staffId = (int)Staff::create($staffData)->id;
            $staffCreated = true;
            // 新建后清掉负缓存，避免后续同批/同请求继续判"不存在"
            Lookup::forgetStaff($idCard ?: null, $staffName);
        } elseif ($idCard !== '' && empty($staff['id_card'])) {
            // 已存在人员但档案缺身份证号：导入时顺手补全（不覆盖已有值）
            $patch = ['id_card' => $idCard];
            \app\service\IdCardService::prepareWrite($patch);
            Staff::where('id', $staffId)->update($patch);
            Lookup::forgetStaff($idCard, $staffName ?: null);
        }

        // v2.37：证书类型必须落库（此前漏写导致导入证书的类型维度为空，台账筛选/智能匹配失效）。
        // 类型为空时先按专业唯一归属反查回填；与录入端口径一致：类型非空时 category 同步为类型，
        // 类型为空（字典未初始化/跨类型同名）才回退到旧列 category，保证两列不打架。
        $certType = trim((string)($row['cert_type'] ?? ''));
        $certNameVal = CertDictService::joinNames(CertDictService::splitNames((string)($row['cert_name'] ?? '')));
        if ($certNameVal === '') {
            $certNameVal = trim((string)($row['cert_name'] ?? ''));
        }
        if ($certType === '') {
            $certType = self::deriveTypeByName($certNameVal);
        }
        $category = $certType !== '' ? $certType : trim((string)($row['category'] ?? ''));
        // v2.54：证书负责人默认跟随持证人员（证照与人员绑定）；人员未分配时归当前导入用户
        $staffOwner    = (int)(Db::name('staff')->where('id', $staffId)->value('owner_user_id') ?: 0);
        $importUserId  = (int)($GLOBALS['__import_user_id'] ?? 0);

        // v2.55：日期规范化——Excel 中常见的 "-"、"0"、"/" 等占位符/非法日期，
        // 直接入库会触发 SQLSTATE[22007]（用户实测第 29/30/31 行即此场景）。
        // 必填日期规范化后仍为空 → 抛可读异常，进入失败名单；选填日期回退到兜底值。
        $expireDate = self::normDate($row['expire_date'] ?? '', '');
        if ($expireDate === '') {
            throw new \Exception('到期日期「' . trim((string)($row['expire_date'] ?? '')) . '」格式不正确，请使用 YYYY-MM-DD');
        }
        $issueDate = self::normDate($row['issue_date'] ?? '', '');

        $cert = CertificateModel::create([
            'staff_id'      => $staffId,
            'cert_type'     => $certType,
            'category'      => $category,
            'cert_name'     => $certNameVal,
            'cert_no'       => $row['cert_no'],
            'issue_date'    => $issueDate ?: null,
            'expire_date'   => $expireDate,
            'priority'      => $row['priority'] ?? '普通',
            'cert_fee'      => $row['cert_fee'] ?? 0,
            // v2.37：证书状态按预览页可编辑的文本映射入库（无法识别/为空 → 正常）
            'cert_status'   => self::CERT_STATUS_MAP[trim((string)($row['cert_status_text'] ?? ''))] ?? 1,
            'social_status' => self::SOCIAL_MAP[trim((string)($row['social_status_text'] ?? ''))] ?? 0,
            'create_by'     => $importUserId, // self 数据范围依据（v2.14）
            'owner_user_id' => $staffOwner > 0 ? $staffOwner : $importUserId,
        ]);
        if (!empty($row['custom'])) {
            CustomFieldService::saveValues('certificate', (int)$cert->id, $row['custom']);
        }

        $companyCreated = false;
        if (!empty($row['company_name'])) {
            $company = Lookup::companyByName(trim($row['company_name']));
            if (!$company) {
                if ($autoCreate) {
                    $companyId = (int)ClientCompany::create(['company_name' => trim($row['company_name'])])->id;
                    Lookup::forgetCompany(trim($row['company_name']));
                    $company = ['id' => $companyId];
                    $companyCreated = true;
                } else {
                    $company = null;
                }
            }
            if ($company) {
                CertSecondment::create([
                    'cert_id'    => $cert->id,
                    'staff_id'   => $staffId,
                    'company_id' => (int)$company['id'],
                    // v2.55：借调起止同样做日期规范化——非法占位符（如 "-"）回退到
                    // 发证日期/今天（开始）与到期日（结束），避免 SQLSTATE[22007] 导致整行失败。
                    'start_date' => self::normDate($row['secondment_start'] ?? '', $issueDate !== '' ? $issueDate : date('Y-m-d')),
                    'end_date'   => self::normDate($row['secondment_end'] ?? '', $expireDate),
                    'fee_amount' => $row['cert_fee'] ?? 0,
                    'fee_period' => '一次性',
                    'status'     => 1,
                    'remark'     => '导入自动生成',
                ]);
            }
        }
        return ['staff_created' => $staffCreated, 'company_created' => $companyCreated];
    }

    /**
     * v2.55：日期字段规范化——把 Excel 中的非法占位符/非法日期转为兜底值。
     * 支持 "-"、"0"、"/" 等占位符及 strtotime 无法解析的文本。
     */
    protected static function normDate($value, string $fallback = ''): string
    {
        $v = trim((string)($value ?? ''));
        if ($v === '' || $v === '-' || $v === '0' || $v === '/' || strtotime($v) === false) {
            return $fallback;
        }
        return $v;
    }

    protected static function commitStaff(array $row): void
    {
        $data = [
            'staff_name' => trim($row['staff_name'] ?? ''),
            'id_card'    => $row['id_card'] ?? '',
            'phone'      => $row['phone'] ?? '',
            'email'      => $row['email'] ?? '',
            'status'     => ($row['status_text'] ?? '') === '离职' ? 0 : 1,
            'remark'     => $row['remark'] ?? '',
        ];
        $plainIdCard = \app\service\IdCardService::normalize($data['id_card']);
        $exists = $plainIdCard !== ''
            ? Lookup::staffByIdCard($plainIdCard)
            : Lookup::staffByName($data['staff_name']);
        if ($exists) {
            $id = (int)$exists['id'];
        } else {
            // v2.54：导入新建人员默认当前导入用户为创建人 + 负责人
            $importUserId = (int)($GLOBALS['__import_user_id'] ?? 0);
            $data['create_by']     = $importUserId;
            $data['owner_user_id'] = $importUserId;
            // v2.99.59：身份证密文 + 盲索引
            \app\service\IdCardService::prepareWrite($data);
            $id = (int)Staff::create($data)->id;
        }
        // 写路径主动失效：新键可能此前被记为"不存在"
        Lookup::forgetStaff($plainIdCard ?: null, $data['staff_name'] ?: null);
        if (!empty($row['custom'])) {
            CustomFieldService::saveValues('staff', $id, $row['custom']);
        }
    }

    protected static function commitCompany(array $row): void
    {
        $name = trim($row['company_name'] ?? '');
        $data = [
            'company_name'  => $name,
            'short_name'    => $row['short_name'] ?? '',
            'credit_code'   => $row['credit_code'] ?? '',
            'industry'      => $row['industry'] ?? '',
            'company_scale' => $row['company_scale'] ?? '',
            'contact'       => $row['contact'] ?? '',
            'contact_phone' => $row['contact_phone'] ?? '',
            'address'       => $row['address'] ?? '',
            'status'        => ($row['status_text'] ?? '') === '停用' ? 0 : 1,
            'remark'        => $row['remark'] ?? '',
        ];
        $exists = Lookup::companyByName($name);
        if ($exists) {
            $id = (int)$exists['id'];
        } else {
            // v2.54：导入新建企业默认当前导入用户为创建人 + 负责人
            $importUserId = (int)($GLOBALS['__import_user_id'] ?? 0);
            $data['create_by']     = $importUserId;
            $data['owner_user_id'] = $importUserId;
            $id = (int)ClientCompany::create($data)->id;
        }
        Lookup::forgetCompany($name ?: null);
        if (!empty($row['custom'])) {
            CustomFieldService::saveValues('company', $id, $row['custom']);
        }
    }

    /**
     * 项目导入入库（含证件需求文本解析）
     * 证件需求格式：「二级建造师×2;安全员C证×1」，分隔符支持 ; ；,
     */
    protected static function commitProject(array $row): void
    {
        $name = trim($row['project_name'] ?? '');
        if ($name === '') {
            throw new \Exception('项目名称为空');
        }
        $companyName = trim((string)($row['company_name'] ?? ''));
        $companyRow  = $companyName !== '' ? Lookup::companyByName($companyName) : null;
        $clientId    = $companyRow ? (int)$companyRow['id'] : 0;
        $statusText  = trim((string)($row['status_text'] ?? ''));
        $status      = self::PROJECT_STATUS_MAP[$statusText] ?? 0;

        $projectId = (int)Db::name('project')->insertGetId([
            'project_no'   => trim((string)($row['project_no'] ?? '')),
            'project_name' => $name,
            'client_id'    => $clientId,
            'region_code'  => trim((string)($row['region_code'] ?? '')),
            'region_name'  => trim((string)($row['region_name'] ?? '')),
            'start_date'   => self::normDate($row['start_date'] ?? '', '') ?: null,
            'end_date'     => self::normDate($row['end_date'] ?? '', '') ?: null,
            'status'       => $status,
            'remark'       => trim((string)($row['remark'] ?? '')),
            'create_by'    => (int)($GLOBALS['__import_user_id'] ?? 0),
        ]);

        foreach (self::parseReqsText((string)($row['reqs_text'] ?? '')) as $req) {
            Db::name('project_cert_req')->insert([
                'project_id'      => $projectId,
                'cert_name'       => $req['cert_name'],
                'need_count'      => $req['need_count'],
                'matched_count'   => 0,
                'require_social'  => 0,
                'min_expire_days' => 0,
            ]);
        }
        if (!empty($row['custom'])) {
            CustomFieldService::saveValues('project', $projectId, $row['custom']);
        }
    }

    /**
     * 解析证件需求文本：「二级建造师×2;安全员C证×1」→ [['cert_name'=>..,'need_count'=>..]]
     */
    public static function parseReqsText(string $text): array
    {
        $out = [];
        if (trim($text) === '') {
            return $out;
        }
        $parts = preg_split('/[;；,，]/u', $text);
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            $count = 1;
            if (preg_match('/^(.*?)[×xX\*:：\s]+(\d+)$/u', $p, $m)) {
                $p     = trim($m[1]);
                $count = max(1, (int)$m[2]);
            }
            if ($p !== '') {
                $out[] = ['cert_name' => $p, 'need_count' => $count];
            }
        }
        return $out;
    }

    /** 政策规则导入入库（类型/级别按文本映射，参数原样存储） */
    protected static function commitPolicyRule(array $row): void
    {
        $typeText = trim((string)($row['rule_type_text'] ?? ''));
        $typeKey  = array_search($typeText, PolicyRuleService::TYPES, true);
        if ($typeKey === false || $typeKey === null) {
            throw new \Exception("规则类型「{$typeText}」无法识别");
        }
        $expr = trim((string)($row['rule_expr'] ?? ''));
        if ($expr === '') {
            throw new \Exception('规则参数为空');
        }
        $sevText  = trim((string)($row['severity_text'] ?? ''));
        $severity = 1;
        if ($sevText !== '') {
            $sevKey = array_search($sevText, PolicyRuleService::SEVERITY, true);
            if ($sevKey !== false && $sevKey !== null) {
                $severity = (int)$sevKey;
            }
        }
        Db::name('policy_rule')->insert([
            'region_code'   => trim((string)($row['region_code'] ?? '')),
            'region_name'   => trim((string)($row['region_name'] ?? '')),
            'rule_type'     => (string)$typeKey,
            // v2.36：导入列「适用类别」经 headerAlias 已解析为 cert_type；
            // 类别列同步为类型，保证规则命中逻辑（两列都认）在任一列上都能生效。
            'cert_type'     => trim((string)($row['cert_type'] ?? '')),
            'cert_category' => trim((string)($row['cert_type'] ?? '')),
            'cert_name'     => trim((string)($row['cert_name'] ?? '')),
            'rule_expr'     => $expr,
            'severity'      => $severity,
            'remark'        => trim((string)($row['remark'] ?? '')),
            'status'        => 1,
        ]);
    }

    /**
     * v2.71：政务网申报进度导入入库
     *
     * 只做新增：申报号唯一，已存在的记录在预览阶段已跳过，避免误覆盖密码 / 归属。
     * 归属人 = 当前导入用户，归属部门 = 其主部门；导入后不自动同步，由用户按需触发。
     */
    protected static function commitGovProgress(array $row): void
    {
        $projectNo = trim((string)($row['project_no'] ?? ''));
        $password  = trim((string)($row['password'] ?? ''));
        if ($projectNo === '' || $password === '' || $password === '-' || $password === '—') {
            throw new \Exception('申报号与申报密码不能为空');
        }
        if (!preg_match('/^[A-Za-z0-9_\-\/]{4,64}$/', $projectNo)) {
            throw new \Exception('申报号格式不正确（4-64 位字母、数字、下划线、短横线）');
        }
        if (mb_strlen($password) > 64) {
            throw new \Exception('申报密码过长（最多 64 位）');
        }
        if (Db::name('gov_progress')->where('project_no', $projectNo)->find()) {
            throw new \Exception('申报号已存在');
        }

        $types    = GovProgressService::businessTypes();
        $typeKey  = trim((string)($row['business_type'] ?? ''));
        if ($typeKey === '') {
            $typeText = trim((string)($row['business_type_text'] ?? ''));
            if ($typeText !== '') {
                if (isset($types[$typeText])) {
                    $typeKey = $typeText;
                } else {
                    $found = array_search($typeText, $types, true);
                    if ($found !== false) {
                        $typeKey = (string)$found;
                    }
                }
            }
        }
        if ($typeKey === '' || !isset($types[$typeKey])) {
            throw new \Exception('业务类型无法识别');
        }

        $companyId = 0;
        $companyName = trim((string)($row['company_name'] ?? ''));
        if ($companyName !== '') {
            $company = Lookup::companyByName($companyName);
            if ($company) {
                $companyId = (int)$company['id'];
            }
        }
        $staffId = 0;
        $staffName = trim((string)($row['staff_name'] ?? ''));
        if ($staffName !== '') {
            $staff = Lookup::staffByName($staffName);
            if ($staff) {
                $staffId = (int)$staff['id'];
            }
        }

        $certType = trim((string)($row['cert_type'] ?? ''));
        $certName = trim((string)($row['cert_name'] ?? ''));
        if ($certType === '' && $certName !== '') {
            $certType = self::deriveTypeByName($certName);
        }

        $userId = (int)($GLOBALS['__import_user_id'] ?? 0);
        $deptIds = $userId > 0 ? DeptScopeService::userDeptIds($userId) : [];

        Db::name('gov_progress')->insert([
            'project_no'    => $projectNo,
            'password_enc'  => GovProgressService::encryptPassword($password),
            'business_type' => $typeKey,
            'company_id'    => $companyId,
            'staff_id'      => $staffId,
            'cert_type'     => mb_substr($certType, 0, 64),
            'cert_name'     => mb_substr($certName, 0, 64),
            'project_name'  => mb_substr(trim((string)($row['project_name'] ?? '')), 0, 255),
            'dept_name'     => mb_substr(trim((string)($row['dept_name'] ?? '')), 0, 128),
            'apply_name'    => mb_substr(trim((string)($row['apply_name'] ?? '')), 0, 128),
            'promise_time'  => self::normDate($row['promise_time'] ?? '', '') ?: null,
            'remark'        => mb_substr(trim((string)($row['remark'] ?? '')), 0, 255),
            'owner_id'      => $userId,
            'dept_id'       => (int)($deptIds[0] ?? 0),
            'create_by'     => $userId,
            // v2.95：导入属于人工保存行为，记录保存人 / 保存时间
            'save_by'       => $userId,
            'save_time'     => date('Y-m-d H:i:s'),
            'status'        => 1,
        ]);
    }

    /**
     * v2.72：账号密码库导入入库（单行）
     *
     * 去重口径与 CredentialVaultService::importRows 一致：
     * 「平台 + 账号类型 + 登录账号 + 企业 + 人员」，存在则更新密码/网址/备注，
     * 不存在则新增；平台自动登记到字典 gov_platform。密码 AES-256-GCM 加密。
     * 企业 / 人员指定但不存在时：开启自动建档则一并创建（默认当前导入用户为
     * 创建人 + 负责人），并返回建档标记供前端提示；关闭则抛出可读异常进失败名单。
     *
     * @return array ['staff_created'=>bool, 'company_created'=>bool]
     */
    protected static function commitCredentialVault(array $row, bool $autoCreate): array
    {
        $platform    = trim((string)($row['platform'] ?? ''));
        $accountName = trim((string)($row['account_name'] ?? ''));
        $password    = trim((string)($row['password'] ?? ''));
        if ($platform === '' || $accountName === '' || $password === '' || $password === '-' || $password === '—') {
            throw new \Exception('平台名称 / 登录账号 / 登录密码不能为空');
        }
        if (mb_strlen($platform) > 128) {
            throw new \Exception('平台名称过长（最多 128 位）');
        }
        if (mb_strlen($accountName) > 255) {
            throw new \Exception('登录账号过长（最多 255 位）');
        }
        if (mb_strlen($password) > 255) {
            throw new \Exception('登录密码过长（最多 255 位）');
        }

        $type = (string)($row['account_type'] ?? '');
        if (!in_array($type, ['company', 'personal'], true)) {
            $typeText = trim((string)($row['account_type_text'] ?? ''));
            $type     = mb_strpos($typeText, '个人') !== false ? 'personal' : 'company';
        }

        $userId         = (int)($GLOBALS['__import_user_id'] ?? 0);
        $companyId      = (int)($row['company_id'] ?? 0);
        $staffId        = (int)($row['staff_id'] ?? 0);
        $companyCreated = false;
        $staffCreated   = false;
        $companyName    = trim((string)($row['company_name'] ?? ''));
        if ($companyName !== '') {
            $company = Lookup::companyByName($companyName);
            if (!$company) {
                if (!$autoCreate) {
                    throw new \Exception("所属企业「{$companyName}」不存在且未开启自动创建");
                }
                $companyId = (int)ClientCompany::create(['company_name' => $companyName])->id;
                $companyCreated = true;
                Lookup::forgetCompany($companyName);
            } else {
                $companyId = (int)$company['id'];
            }
        }
        $staffName = trim((string)($row['staff_name'] ?? ''));
        if ($staffName !== '') {
            $staff = Lookup::staffByName($staffName);
            if (!$staff) {
                if (!$autoCreate) {
                    throw new \Exception("关联人员「{$staffName}」不存在且未开启自动创建");
                }
                // v2.79.2：自动建档前防重——若模板扩展了身份证列，仍按身份证号查重兜底。
                $rowIdCard = trim((string)($row['id_card'] ?? ''));
                if ($rowIdCard !== '') {
                    $dup = Db::name('staff')
                        ->where('id_card_hash', \app\service\IdCardService::hash($rowIdCard))->find();
                    if (!$dup) {
                        // 兼容未回填的历史明文行
                        $dup = Db::name('staff')->where('id_card', $rowIdCard)->find();
                    }
                    if ($dup) {
                        throw new \Exception("关联人员建档失败：身份证号已存在（人员「" . ($dup['staff_name'] ?? '') . "」ID {$dup['id']}），请核对导入数据或使用「重复检测」合并");
                    }
                }
                // 自动建档：模板无身份证号列，仅姓名；默认当前导入用户为创建人 + 负责人
                $staffId = (int)Staff::create(array_filter([
                    'staff_name'    => $staffName,
                    'create_by'     => $userId,
                    'owner_user_id' => $userId,
                ], function ($v) { return $v !== ''; }))->id;
                $staffCreated = true;
                Lookup::forgetStaff(null, $staffName);
            } else {
                $staffId = (int)$staff['id'];
            }
        }

        $platformUrl = mb_substr(trim((string)($row['platform_url'] ?? '')), 0, 500);
        $remark      = mb_substr(trim((string)($row['remark'] ?? '')), 0, 500);
        $passwordEnc = SecretService::encrypt($password);
        $deptIds    = $userId > 0 ? DeptScopeService::userDeptIds($userId) : [];

        // 平台登记到字典（已存在则跳过）
        CredentialVaultService::registerPlatform($platform, $platformUrl);

        $exist = Db::name('credential_vault')
            ->where('platform', $platform)
            ->where('account_type', $type)
            ->where('account_name', $accountName)
            ->where('company_id', $companyId)
            ->where('staff_id', $staffId)
            ->find();
        if ($exist) {
            Db::name('credential_vault')->where('id', $exist['id'])->update([
                'platform_url' => $platformUrl,
                'password_enc' => $passwordEnc,
                'remark'       => $remark,
                'status'       => 1,
                'update_time'  => date('Y-m-d H:i:s'),
            ]);
            return ['staff_created' => $staffCreated, 'company_created' => $companyCreated];
        }
        Db::name('credential_vault')->insert([
            'platform'     => mb_substr($platform, 0, 128),
            'platform_url' => $platformUrl,
            'account_type' => $type,
            'company_id'   => $companyId,
            'staff_id'     => $staffId,
            'account_name' => mb_substr($accountName, 0, 255),
            'password_enc' => $passwordEnc,
            'remark'       => $remark,
            'owner_id'     => $userId,
            'dept_id'      => (int)($deptIds[0] ?? 0),
            'create_by'    => $userId,
            'status'       => 1,
            'create_time'  => date('Y-m-d H:i:s'),
            'update_time'  => date('Y-m-d H:i:s'),
        ]);
        return ['staff_created' => $staffCreated, 'company_created' => $companyCreated];
    }
}
