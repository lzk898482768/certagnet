<?php
namespace app\service;

use think\facade\Db;

/**
 * 客户企业资质 / 安许台账
 *
 * 数据来源：
 *  - hn_platform：河南省建筑市场监管公共服务平台公开页面采集（人工输入验证码，不绕过校验）
 *  - manual：人工补录 / 历史数据
 *
 * 结构自愈：老库 git pull 后无需手工执行 SQL，首次访问客户企业详情/采集接口时
 * 自动补齐 client_company 的法人/注册属地/注册资本列，并创建 company_qualification 表。
 */
class CompanyQualificationService
{
    /** 单次导入上限，避免异常请求拖垮数据库 */
    public const IMPORT_LIMIT = 100;

    /** 企业基础信息允许从平台补全的字段 => 文案 */
    public const BASE_FIELDS = [
        'legal_person'       => '法定代表人',
        'region_path'        => '注册属地',
        'registered_capital' => '注册资本',
        'address'            => '地址',
        'credit_code'        => '统一社会信用代码',
    ];

    /**
     * 老库结构自愈（幂等）
     */
    public static function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        ensure_column('client_company', 'legal_person', "varchar(64) NOT NULL DEFAULT '' COMMENT '法定代表人' AFTER `credit_code`");
        ensure_column('client_company', 'region_path', "varchar(128) NOT NULL DEFAULT '' COMMENT '注册属地（省/市）' AFTER `legal_person`");
        ensure_column('client_company', 'registered_capital', "varchar(64) NOT NULL DEFAULT '' COMMENT '注册资本' AFTER `region_path`");
        ensure_table('company_qualification', self::tableDdl());
        // v2.94：多附件（子表）+ 父表首附件派生列
        ensure_column('company_qualification', 'attach_path', "varchar(255) NOT NULL DEFAULT '' COMMENT '首附件路径（兼容列表/导出）' AFTER `remark`");
        ensure_table('company_qualification_attach', self::attachTableDdl());
    }

    /** 企业证书附件子表（1:N，与 certificate_attach / staff_attach 同构） */
    public static function attachTableDdl(): string
    {
        return "CREATE TABLE IF NOT EXISTS `company_qualification_attach` (
  `id`             int unsigned NOT NULL AUTO_INCREMENT,
  `qualification_id` int unsigned NOT NULL DEFAULT 0 COMMENT '企业证书ID company_qualification.id',
  `attach_name`    varchar(128) NOT NULL DEFAULT '' COMMENT '附件名称',
  `attach_path`    varchar(255) NOT NULL DEFAULT '' COMMENT '存储路径',
  `file_ext`       varchar(16)  NOT NULL DEFAULT '' COMMENT '扩展名',
  `file_size`      int unsigned NOT NULL DEFAULT 0 COMMENT '文件大小（字节）',
  `sort`           int NOT NULL DEFAULT 0 COMMENT '排序',
  `create_by`      int unsigned NOT NULL DEFAULT 0 COMMENT '上传人',
  `create_time`    datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_qualification` (`qualification_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='企业证书附件'";
    }

    public static function tableDdl(): string
    {
        return "CREATE TABLE IF NOT EXISTS `company_qualification` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `company_id` int unsigned NOT NULL DEFAULT 0 COMMENT '客户企业ID client_company.id',
  `q_type` varchar(20) NOT NULL DEFAULT 'qualification' COMMENT 'qualification 资质 / safety 安许',
  `cert_name` varchar(120) NOT NULL DEFAULT '' COMMENT '证书名称',
  `cert_level` varchar(50) NOT NULL DEFAULT '' COMMENT '等级',
  `cert_no` varchar(80) NOT NULL DEFAULT '' COMMENT '证书编号',
  `cert_scope` varchar(255) NOT NULL DEFAULT '' COMMENT '许可范围',
  `issue_org` varchar(150) NOT NULL DEFAULT '' COMMENT '发证机关',
  `issue_date` date DEFAULT NULL COMMENT '发证日期',
  `expire_date` date DEFAULT NULL COMMENT '有效期至',
  `source` varchar(30) NOT NULL DEFAULT '' COMMENT '来源：hn_platform 河南平台 / manual 手工',
  `source_url` varchar(500) NOT NULL DEFAULT '' COMMENT '来源页面',
  `fetched_at` datetime DEFAULT NULL COMMENT '采集时间',
  `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '1有效 0已删除',
  `create_by` int unsigned NOT NULL DEFAULT 0 COMMENT '创建人',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_company` (`company_id`,`status`),
  KEY `idx_company_type` (`company_id`,`q_type`),
  KEY `idx_expire` (`expire_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客户企业资质/安许台账'";
    }

    /**
     * 企业资质台账（有效记录）
     * @return array<int,array<string,mixed>>
     */
    public static function listByCompany(int $companyId): array
    {
        if ($companyId <= 0) {
            return [];
        }
        self::ensureSchema();
        $rows = Db::name('company_qualification')
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->order('q_type', 'asc')
            ->order('expire_date', 'desc')
            ->order('id', 'desc')
            ->select()->toArray();

        $today = strtotime(date('Y-m-d'));
        foreach ($rows as &$row) {
            $row['q_type_text'] = ($row['q_type'] ?? '') === 'safety' ? '安许' : '资质';
            $row['source_text'] = self::sourceText((string)($row['source'] ?? ''));
            $row['remain_days'] = !empty($row['expire_date']) ? remain_days($row['expire_date']) : null;
            $row['expired'] = !empty($row['expire_date'])
                && strtotime((string)$row['expire_date']) < $today;
        }
        unset($row);
        return $rows;
    }

    public static function sourceText(string $source): string
    {
        return match ($source) {
            'hn_platform' => '河南平台',
            ''            => '手工',
            default       => $source,
        };
    }

    /**
     * 标记采集结果中已入库的记录（企业 + 类型 + 证书编号 + 证书名称）
     */
    public static function markDuplicated(array $items, int $companyId): array
    {
        if ($companyId <= 0 || !$items) {
            foreach ($items as &$item) {
                $item['existing_id'] = 0;
                $item['duplicated'] = false;
            }
            unset($item);
            return $items;
        }

        self::ensureSchema();
        $existing = Db::name('company_qualification')
            ->where('company_id', $companyId)
            ->where('status', 1)
            ->field('id,q_type,cert_no,cert_name')
            ->select()->toArray();

        $keys = [];
        foreach ($existing as $row) {
            $keys[self::dupKey((string)($row['q_type'] ?? ''), (string)($row['cert_no'] ?? ''), (string)($row['cert_name'] ?? ''))] = (int)$row['id'];
        }
        foreach ($items as &$item) {
            $item['existing_id'] = $keys[self::dupKey(
                (string)($item['q_type'] ?? ''),
                (string)($item['cert_no'] ?? ''),
                (string)($item['cert_name'] ?? '')
            )] ?? 0;
            $item['duplicated'] = $item['existing_id'] > 0;
        }
        unset($item);
        return $items;
    }

    public static function dupKey(string $qType, string $certNo, string $certName): string
    {
        return $qType . '|' . strtoupper(trim($certNo)) . '|' . mb_strtolower(trim($certName));
    }

    /**
     * 计算「平台基础信息」补全方案（不写库，便于单测）
     *
     * 默认只补档案中的空字段；overwrite=true 时才会用平台值覆盖已有且不一致的字段。
     *
     * @param array<string,mixed> $company client_company 行
     * @param array<string,mixed> $base    平台返回的 company 结构
     * @return array{filled:array,overwritten:array,conflicts:array,unchanged:array,update:array<string,string>}
     */
    public static function basicPlan(array $company, array $base, bool $overwrite = false): array
    {
        $filled = [];
        $overwritten = [];
        $conflicts = [];
        $unchanged = [];
        $update = [];

        foreach (self::BASE_FIELDS as $field => $label) {
            $newVal = trim((string)($base[$field] ?? ''));
            if ($newVal === '') {
                continue;
            }
            if ($field === 'credit_code') {
                $newVal = strtoupper($newVal);
            }
            $oldVal = trim((string)($company[$field] ?? ''));
            $row = ['field' => $field, 'label' => $label];

            if ($oldVal === '') {
                $update[$field] = $newVal;
                $filled[] = $row + ['value' => $newVal];
            } elseif (mb_strtoupper($oldVal) === mb_strtoupper($newVal)) {
                $unchanged[] = $row + ['value' => $newVal];
            } elseif ($overwrite) {
                $update[$field] = $newVal;
                $overwritten[] = $row + ['current' => $oldVal, 'platform' => $newVal];
            } else {
                $conflicts[] = $row + ['current' => $oldVal, 'platform' => $newVal];
            }
        }

        return [
            'filled'      => $filled,
            'overwritten' => $overwritten,
            'conflicts'   => $conflicts,
            'unchanged'   => $unchanged,
            'update'      => $update,
        ];
    }

    /**
     * 一键从平台补全企业基础信息（写库）
     *
     * @param array<string,mixed> $base 平台返回的 company 结构
     * @return array{filled:array,overwritten:array,conflicts:array,unchanged:array,updated:bool}
     */
    public static function completeBasic(int $companyId, array $base, bool $overwrite = false): array
    {
        self::ensureSchema();
        $company = Db::name('client_company')->where('id', $companyId)->find();
        if (!$company) {
            throw new \RuntimeException('企业客户不存在');
        }

        $plan = self::basicPlan($company, $base, $overwrite);
        $updated = false;
        if ($plan['update']) {
            $plan['update']['update_time'] = date('Y-m-d H:i:s');
            Db::name('client_company')->where('id', $companyId)->update($plan['update']);
            $updated = true;
        }
        unset($plan['update']);
        $plan['updated'] = $updated;

        return $plan;
    }

    /**
     * 采集结果入库（按企业 + 类型 + 编号 + 名称判重，存在则更新）
     *
     * @return array{saved:int,updated:int,skipped:int,base_filled:array<int,string>,base_conflicts:array<int,string>}
     */
    public static function import(
        int $companyId,
        array $items,
        string $source = '',
        string $sourceUrl = '',
        bool $applyBase = false,
        array $base = [],
        int $userId = 0
    ): array {
        self::ensureSchema();
        $company = Db::name('client_company')->where('id', $companyId)->find();
        if (!$company) {
            throw new \RuntimeException('企业客户不存在');
        }
        if (!$items) {
            throw new \RuntimeException('请选择要导入的资质 / 安许记录');
        }

        $source = trim($source) !== '' ? trim($source) : CompanyPlatformService::SOURCE_KEY;
        $source = mb_substr($source, 0, 30);
        $sourceUrl = mb_substr(trim($sourceUrl), 0, 500);
        $now = date('Y-m-d H:i:s');

        $baseFilled = [];
        $baseConflicts = [];
        if ($applyBase && $base) {
            foreach (self::BASE_FIELDS as $field => $label) {
                $newVal = trim((string)($base[$field] ?? ''));
                if ($newVal === '') {
                    continue;
                }
                $oldVal = trim((string)($company[$field] ?? ''));
                if ($oldVal === '') {
                    $baseFilled[$field] = $newVal;
                } elseif (strtoupper($oldVal) !== strtoupper($newVal)) {
                    $baseConflicts[] = $label;
                }
            }
        }

        $saved = 0;
        $updated = 0;
        $skipped = 0;

        Db::startTrans();
        try {
            foreach (array_slice($items, 0, self::IMPORT_LIMIT) as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $certNo = mb_substr(trim((string)($it['cert_no'] ?? '')), 0, 80);
                $certName = mb_substr(trim((string)($it['cert_name'] ?? '')), 0, 120);
                if ($certName === '' && $certNo === '') {
                    $skipped++;
                    continue;
                }

                $qType = (string)($it['q_type'] ?? 'qualification') === 'safety' ? 'safety' : 'qualification';
                // 条目可自带来源（如国家能源局承装修试），未携带时沿用本次批量来源
                $itemSource = mb_substr(trim((string)($it['source'] ?? '')), 0, 30);
                $itemSourceUrl = mb_substr(trim((string)($it['source_url'] ?? '')), 0, 500);
                $data = [
                    'company_id'  => $companyId,
                    'q_type'      => $qType,
                    'cert_name'   => $certName,
                    'cert_level'  => mb_substr(trim((string)($it['cert_level'] ?? '')), 0, 50),
                    'cert_no'     => $certNo,
                    'cert_scope'  => mb_substr(trim((string)($it['cert_scope'] ?? '')), 0, 255),
                    'issue_org'   => mb_substr(trim((string)($it['issue_org'] ?? '')), 0, 150),
                    'issue_date'  => self::normDate($it['issue_date'] ?? null),
                    'expire_date' => self::normDate($it['expire_date'] ?? null),
                    'source'      => $itemSource !== '' ? $itemSource : $source,
                    'source_url'  => $itemSourceUrl !== '' ? $itemSourceUrl : $sourceUrl,
                    'fetched_at'  => $now,
                    'remark'      => mb_substr(trim((string)($it['remark'] ?? '')), 0, 255),
                    'status'      => 1,
                    'update_time' => $now,
                ];

                $existId = 0;
                if ($certNo !== '' || $certName !== '') {
                    $existId = (int)Db::name('company_qualification')
                        ->where('company_id', $companyId)->where('q_type', $qType)
                        ->where('cert_no', $certNo)->where('cert_name', $certName)
                        ->where('status', 1)->value('id');
                    // 历史数据中名称为空的同编号记录，视为同一条，避免重复
                    if (!$existId && $certNo !== '') {
                        $existId = (int)Db::name('company_qualification')
                            ->where('company_id', $companyId)->where('q_type', $qType)
                            ->where('cert_no', $certNo)->where('cert_name', '')
                            ->where('status', 1)->value('id');
                    }
                }

                if ($existId > 0) {
                    Db::name('company_qualification')->where('id', $existId)->update($data);
                    $updated++;
                } else {
                    $data['create_by'] = $userId;
                    $data['create_time'] = $now;
                    Db::name('company_qualification')->insert($data);
                    $saved++;
                }
            }

            if ($baseFilled) {
                Db::name('client_company')->where('id', $companyId)->update($baseFilled + ['update_time' => $now]);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        return [
            'saved'          => $saved,
            'updated'        => $updated,
            'skipped'        => $skipped,
            'base_filled'    => array_keys($baseFilled),
            'base_conflicts' => $baseConflicts,
        ];
    }

    /**
     * 单条企业证书详情（含所属企业名称）
     */
    public static function detail(int $id): ?array
    {
        self::ensureSchema();
        if ($id <= 0) {
            return null;
        }
        $row = Db::name('company_qualification')->where('id', $id)->where('status', 1)->find();
        if (!$row) {
            return null;
        }
        $company = Db::name('client_company')->where('id', (int)$row['company_id'])
            ->field('id,company_name,credit_code')->find();
        $row['company_name'] = (string)($company['company_name'] ?? '');
        $row['credit_code']  = (string)($company['credit_code'] ?? '');
        $row['q_type_text']  = ($row['q_type'] ?? '') === 'safety' ? '安许' : '资质';
        $row['source_text']  = self::sourceText((string)($row['source'] ?? ''));
        return $row;
    }

    /**
     * 新增 / 编辑一条企业证书（手工录入）
     *
     * 判重口径与采集导入一致：同一企业 + q_type + 证书编号 + 证书名称。
     *
     * @param array<string,mixed> $input
     * @param int $id 0=新增，>0=编辑
     * @return array{id:int,created:bool}
     */
    public static function save(array $input, int $userId, int $id = 0): array
    {
        self::ensureSchema();

        $companyId = (int)($input['company_id'] ?? 0);
        if ($companyId <= 0) {
            throw new \RuntimeException('请选择所属企业');
        }
        if (!Db::name('client_company')->where('id', $companyId)->find()) {
            throw new \RuntimeException('企业客户不存在');
        }

        $qType = (string)($input['q_type'] ?? 'qualification') === 'safety' ? 'safety' : 'qualification';
        $certName = mb_substr(trim((string)($input['cert_name'] ?? '')), 0, 120);
        $certNo   = mb_substr(trim((string)($input['cert_no'] ?? '')), 0, 80);
        if ($certName === '' && $certNo === '') {
            throw new \RuntimeException('证书名称和证书编号至少填写一项');
        }

        $issueDate  = self::normDate($input['issue_date'] ?? null);
        $expireDate = self::normDate($input['expire_date'] ?? null);
        if ($issueDate !== null && $expireDate !== null && strtotime($issueDate) > strtotime($expireDate)) {
            throw new \RuntimeException('发证日期不能晚于有效期至');
        }

        $data = [
            'company_id'  => $companyId,
            'q_type'      => $qType,
            'cert_name'   => $certName,
            'cert_level'  => mb_substr(trim((string)($input['cert_level'] ?? '')), 0, 50),
            'cert_no'     => $certNo,
            'cert_scope'  => mb_substr(trim((string)($input['cert_scope'] ?? '')), 0, 255),
            'issue_org'   => mb_substr(trim((string)($input['issue_org'] ?? '')), 0, 150),
            'issue_date'  => $issueDate,
            'expire_date' => $expireDate,
            'remark'      => mb_substr(trim((string)($input['remark'] ?? '')), 0, 255),
        ];

        $dup = Db::name('company_qualification')
            ->where('company_id', $companyId)
            ->where('q_type', $qType)
            ->where('cert_no', $certNo)
            ->where('cert_name', $certName)
            ->where('status', 1);
        if ($id > 0) {
            $dup->where('id', '<>', $id);
        }
        if ((int)$dup->value('id') > 0) {
            throw new \RuntimeException('该企业已存在相同类型、编号和名称的证书记录');
        }

        $now = date('Y-m-d H:i:s');
        if ($id > 0) {
            $exists = Db::name('company_qualification')->where('id', $id)->where('status', 1)->find();
            if (!$exists) {
                throw new \RuntimeException('记录不存在或已删除');
            }
            $data['update_time'] = $now;
            Db::name('company_qualification')->where('id', $id)->update($data);
            return ['id' => $id, 'created' => false];
        }

        $data += [
            'source'      => 'manual',
            'source_url'  => '',
            'status'      => 1,
            'create_by'   => $userId,
            'create_time' => $now,
            'update_time' => $now,
        ];
        $newId = (int)Db::name('company_qualification')->insertGetId($data);
        return ['id' => $newId, 'created' => true];
    }

    /**
     * Excel 导入企业证书（按所属企业名称匹配，不自动新建企业）
     *
     * @param array<int,array<string,mixed>> $rows ExcelService::import('company_cert') 的解析结果
     * @param array<int>|null $visibleCompanyIds null=不限制；数组=仅允许这些企业
     * @return array{saved:int,updated:int,skipped:int,failed:array,total:int}
     */
    public static function importRows(array $rows, int $userId, ?array $visibleCompanyIds = null): array
    {
        self::ensureSchema();

        $total   = count($rows);
        $skipped = 0;
        $failed  = [];
        $byCompany = [];
        $companyCache = [];

        foreach (array_values($rows) as $i => $row) {
            $line = $i + 2;
            if (!is_array($row)) {
                $skipped++;
                continue;
            }
            $companyName = trim((string)($row['company_name'] ?? ''));
            $creditCode  = strtoupper(trim((string)($row['credit_code'] ?? '')));
            $certName    = mb_substr(trim((string)($row['cert_name'] ?? '')), 0, 120);
            $certNo      = mb_substr(trim((string)($row['cert_no'] ?? '')), 0, 80);

            if ($companyName === '' && $creditCode === '') {
                $failed[] = ['line' => $line, 'reason' => '所属企业为空'];
                $skipped++;
                continue;
            }
            if ($certName === '' && $certNo === '') {
                $failed[] = ['line' => $line, 'reason' => '证书名称和证书编号至少填写一项'];
                $skipped++;
                continue;
            }

            $cacheKey = mb_strtolower($companyName) . '|' . $creditCode;
            if (!array_key_exists($cacheKey, $companyCache)) {
                $q = Db::name('client_company')->field('id');
                if ($companyName !== '') {
                    $q->where('company_name', $companyName);
                } else {
                    $q->where('credit_code', $creditCode);
                }
                $companyCache[$cacheKey] = (int)$q->value('id');
            }
            $companyId = (int)$companyCache[$cacheKey];
            if ($companyId <= 0) {
                $failed[] = ['line' => $line, 'reason' => '所属企业「' . ($companyName ?: $creditCode) . '」不存在，请先在客户企业建档'];
                $skipped++;
                continue;
            }
            if ($visibleCompanyIds !== null && !in_array($companyId, $visibleCompanyIds, true)) {
                $failed[] = ['line' => $line, 'reason' => '无权导入该企业数据'];
                $skipped++;
                continue;
            }

            $qTypeText = trim((string)($row['q_type_text'] ?? ''));
            $qType = mb_strpos($qTypeText, '安') !== false ? 'safety' : 'qualification';
            if (in_array((string)($row['q_type'] ?? ''), ['safety', 'qualification'], true)) {
                $qType = (string)$row['q_type'];
            }

            $byCompany[$companyId][] = [
                'q_type'      => $qType,
                'cert_name'   => $certName,
                'cert_level'  => mb_substr(trim((string)($row['cert_level'] ?? '')), 0, 50),
                'cert_no'     => $certNo,
                'cert_scope'  => mb_substr(trim((string)($row['cert_scope'] ?? '')), 0, 255),
                'issue_org'   => mb_substr(trim((string)($row['issue_org'] ?? '')), 0, 150),
                'issue_date'  => $row['issue_date'] ?? null,
                'expire_date' => $row['expire_date'] ?? null,
                'remark'      => mb_substr(trim((string)($row['remark'] ?? '')), 0, 255),
            ];
        }

        $saved = 0;
        $updated = 0;
        foreach ($byCompany as $companyId => $items) {
            foreach (array_chunk($items, self::IMPORT_LIMIT) as $chunk) {
                try {
                    $res = self::import($companyId, $chunk, 'manual', '', false, [], $userId);
                    $saved   += (int)($res['saved'] ?? 0);
                    $updated += (int)($res['updated'] ?? 0);
                    $skipped += (int)($res['skipped'] ?? 0);
                } catch (\Throwable $e) {
                    $failed[] = ['line' => 0, 'reason' => '企业#' . $companyId . ' 导入失败：' . $e->getMessage()];
                }
            }
        }

        return [
            'saved'   => $saved,
            'updated' => $updated,
            'skipped' => $skipped,
            'failed'  => $failed,
            'total'   => $total,
        ];
    }

    /**
     * 软删除一条台账记录
     * @return array<string,mixed>|null 被删除的记录
     */
    public static function delete(int $id): ?array
    {
        self::ensureSchema();
        $row = Db::name('company_qualification')->where('id', $id)->where('status', 1)->find();
        if (!$row) {
            return null;
        }
        Db::name('company_qualification')->where('id', $id)->update([
            'status'      => 0,
            'update_time' => date('Y-m-d H:i:s'),
        ]);
        return $row;
    }

    /** 统一日期格式，无法识别时返回 null（数据库 date 列可空） */
    public static function normDate($value): ?string
    {
        $value = trim((string)$value);
        if ($value === '' || $value === '—' || $value === '-') {
            return null;
        }
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/', $value, $m)) {
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }
        $ts = strtotime($value);
        return $ts ? date('Y-m-d', $ts) : null;
    }
}
