<?php
namespace app\service;

use think\facade\Db;

/**
 * 企业 / 个人平台账号密码库（v2.63）
 *
 * 场景：业务人员需要频繁登录各地政务网、住建平台、税务/社保平台，
 * 账号散落在个人微信与 Excel 中，交接困难。本服务集中托管平台、账号、密码、
 * 关联企业/人员与备注，支持快速检索、复制、导入导出。
 *
 * 安全约定：
 * - 密码使用 SecretService（AES-256-GCM）加密存储；
 * - 列表只返回掩码，明文仅在「查看 / 复制密码」接口按条解密并写入操作日志；
 * - 导出文件含明文密码，仅开放给具备本模块查看权限的岗位。
 */
class CredentialVaultService
{
    /** 账号主体类型 */
    public const ACCOUNT_TYPES = [
        'company'  => '企业账号',
        'personal' => '个人账号',
    ];

    public static function ensureTables(): void
    {
        ensure_table('credential_vault', self::ddl());
        try {
            DictCatalog::ensureGovPlatforms();
        } catch (\Throwable $e) {
            // 平台字典补种失败不阻断账号密码库主流程
        }
    }

    /**
     * 平台下拉选项：字典平台 + 已有账号中的自定义平台合并。
     *
     * @return array<int,array{name:string,url:string,source:string}>
     */
    public static function platformOptions(): array
    {
        $options = [];
        try {
            $rows = Db::name('dict_data')
                ->where('type_code', 'gov_platform')
                ->where('status', 1)
                ->order('sort', 'asc')->order('id', 'asc')
                ->field('dict_label,dict_value,remark')
                ->select()->toArray();
            foreach ($rows as $r) {
                $name = trim((string)($r['dict_label'] ?? '')) ?: trim((string)($r['dict_value'] ?? ''));
                if ($name === '' || isset($options[$name])) {
                    continue;
                }
                $options[$name] = [
                    'name'   => $name,
                    'url'    => trim((string)($r['remark'] ?? '')),
                    'source' => 'dict',
                ];
            }
        } catch (\Throwable $e) {
            // 字典表缺失时回退已有账号
        }

        try {
            $rows = Db::name('credential_vault')
                ->where('platform', '<>', '')
                ->field('platform,platform_url')
                ->order('id', 'desc')
                ->select()->toArray();
            foreach ($rows as $r) {
                $name = trim((string)($r['platform'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $url = trim((string)($r['platform_url'] ?? ''));
                if (!isset($options[$name])) {
                    $options[$name] = ['name' => $name, 'url' => $url, 'source' => 'account'];
                } elseif ($options[$name]['url'] === '' && $url !== '') {
                    $options[$name]['url'] = $url;
                }
            }
        } catch (\Throwable $e) {
            // 账号表缺失时忽略
        }

        return array_values($options);
    }

    /**
     * 快捷新增平台：写入字典 gov_platform，返回标准选项。
     *
     * @return array{name:string,url:string,source:string,created:bool}
     */
    public static function addPlatform(string $name, string $url = ''): array
    {
        $name = trim($name);
        $url  = trim($url);
        if ($name === '') {
            throw new \InvalidArgumentException('请填写平台名称');
        }
        if (mb_strlen($name) > 128) {
            throw new \InvalidArgumentException('平台名称最多 128 个字符');
        }
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            throw new \InvalidArgumentException('平台网址需以 http:// 或 https:// 开头');
        }
        if (mb_strlen($url) > 255) {
            throw new \InvalidArgumentException('平台网址过长（最多 255 个字符）');
        }

        self::ensureTables();

        try {
            $existType = Db::name('dict_type')->where('type_code', 'gov_platform')->find();
            if (!$existType) {
                Db::name('dict_type')->insert([
                    'type_code' => 'gov_platform',
                    'type_name' => '政务网平台',
                    'module'    => 'gov',
                    'remark'    => '账号密码库平台下拉；字典项的「备注」填写平台网址',
                    'status'    => 1,
                ]);
            }

            $exist = Db::name('dict_data')
                ->where('type_code', 'gov_platform')
                ->where('parent_value', '')
                ->where(function ($q) use ($name) {
                    $q->where('dict_value', $name)->whereOr('dict_label', $name);
                })
                ->find();
            if ($exist) {
                $data = [];
                if ($url !== '' && trim((string)($exist['remark'] ?? '')) === '') {
                    $data['remark'] = $url;
                }
                if ((int)($exist['status'] ?? 1) !== 1) {
                    $data['status'] = 1;
                }
                if ($data) {
                    Db::name('dict_data')->where('id', (int)$exist['id'])->update($data);
                }
                return [
                    'name'    => (string)($exist['dict_label'] ?? $name),
                    'url'     => $data['remark'] ?? trim((string)($exist['remark'] ?? '')),
                    'source'  => 'dict',
                    'created' => false,
                ];
            }

            $maxSort = (int)Db::name('dict_data')->where('type_code', 'gov_platform')->max('sort');
            Db::name('dict_data')->insert([
                'type_code'    => 'gov_platform',
                'dict_label'   => $name,
                'dict_value'   => $name,
                'parent_value' => '',
                'sort'         => $maxSort + 1,
                'status'       => 1,
                'remark'       => $url,
            ]);
        } catch (\Throwable $e) {
            if ($e instanceof \InvalidArgumentException) {
                throw $e;
            }
            throw new \RuntimeException('平台字典保存失败：' . $e->getMessage());
        }

        return ['name' => $name, 'url' => $url, 'source' => 'dict', 'created' => true];
    }

    /** 导入时静默登记平台，保证导入后下拉框也能选到（统一导入服务也会调用） */
    public static function registerPlatform(string $name, string $url): void
    {
        static $done = [];
        $key = $name . "\0" . $url;
        if (isset($done[$key])) {
            return;
        }
        $done[$key] = true;
        try {
            self::addPlatform($name, $url);
        } catch (\Throwable $e) {
            // 平台登记失败不影响账号导入
        }
    }

    public static function ddl(): string
    {
        return "CREATE TABLE IF NOT EXISTS `credential_vault` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='企业/个人平台账号密码库（v2.63）'";
    }

    /**
     * 列表展示字段：掩码 + 类型文本 + 企业/人员名称
     *
     * @param array<int,array> $rows 传引用
     */
    public static function decorateList(array &$rows): void
    {
        if (!$rows) {
            return;
        }
        $companyIds = [];
        $staffIds = [];
        foreach ($rows as $r) {
            $cid = (int)($r['company_id'] ?? 0);
            $sid = (int)($r['staff_id'] ?? 0);
            if ($cid > 0) {
                $companyIds[$cid] = $cid;
            }
            if ($sid > 0) {
                $staffIds[$sid] = $sid;
            }
        }
        $companyMap = [];
        $staffMap = [];
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
        } catch (\Throwable $e) {
            // 关联表异常时保持原样
        }

        foreach ($rows as &$row) {
            $type = (string)($row['account_type'] ?? 'company');
            $row['account_type_text'] = self::ACCOUNT_TYPES[$type] ?? '企业账号';
            $plain = SecretService::decrypt((string)($row['password_enc'] ?? ''));
            $row['password_mask'] = SecretService::mask($plain);
            $row['has_password'] = trim((string)($row['password_enc'] ?? '')) !== '';
            unset($row['password_enc']);

            $company = $companyMap[(int)($row['company_id'] ?? 0)] ?? null;
            $staff = $staffMap[(int)($row['staff_id'] ?? 0)] ?? null;
            $row['company_name'] = (string)($company['company_name'] ?? '');
            $row['company_short_name'] = (string)($company['short_name'] ?? '');
            $row['staff_name'] = (string)($staff['staff_name'] ?? '');
            $row['staff_phone'] = (string)($staff['phone'] ?? '');
        }
        unset($row);
    }

    /**
     * 导出数据：密码必须解密为明文，供用户备份 / 迁移。
     *
     * @param array<int,array> $rows 原始行（含 password_enc）
     * @return array<int,array>
     */
    public static function exportRows(array $rows): array
    {
        if (!$rows) {
            return [];
        }
        $companyIds = [];
        $staffIds = [];
        foreach ($rows as $r) {
            if ((int)($r['company_id'] ?? 0) > 0) {
                $companyIds[(int)$r['company_id']] = (int)$r['company_id'];
            }
            if ((int)($r['staff_id'] ?? 0) > 0) {
                $staffIds[(int)$r['staff_id']] = (int)$r['staff_id'];
            }
        }
        $companyMap = $companyIds
            ? Db::name('client_company')->whereIn('id', array_values($companyIds))->column('company_name', 'id')
            : [];
        $staffMap = $staffIds
            ? Db::name('staff')->whereIn('id', array_values($staffIds))->column('staff_name', 'id')
            : [];

        $out = [];
        foreach ($rows as $r) {
            $type = (string)($r['account_type'] ?? 'company');
            $out[] = [
                'platform'          => (string)($r['platform'] ?? ''),
                'platform_url'      => (string)($r['platform_url'] ?? ''),
                'account_type_text' => self::ACCOUNT_TYPES[$type] ?? '企业账号',
                'company_name'      => (string)($companyMap[(int)($r['company_id'] ?? 0)] ?? ''),
                'staff_name'        => (string)($staffMap[(int)($r['staff_id'] ?? 0)] ?? ''),
                'account_name'      => (string)($r['account_name'] ?? ''),
                'password'          => SecretService::decrypt((string)($r['password_enc'] ?? '')),
                'remark'            => (string)($r['remark'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * 导入：按「平台 + 账号类型 + 登录账号 + 企业 + 人员」去重，存在则更新密码/网址/备注。
     *
     * @param array<int,array> $rows ExcelService::import('credential_vault') 的结果
     * @return array{inserted:int,updated:int,skipped:int,errors:array<int,array{line:int,reason:string}>}
     */
    public static function importRows(array $rows, int $userId, int $deptId = 0): array
    {
        self::ensureTables();
        $res = ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
        $companyCache = [];
        $staffCache = [];

        foreach ($rows as $i => $row) {
            $line = $i + 2;
            $platform = trim((string)($row['platform'] ?? ''));
            $accountName = trim((string)($row['account_name'] ?? ''));
            $password = trim((string)($row['password'] ?? ''));
            if ($platform === '') {
                $res['skipped']++;
                $res['errors'][] = ['line' => $line, 'reason' => '平台名称为空'];
                continue;
            }
            if ($accountName === '') {
                $res['skipped']++;
                $res['errors'][] = ['line' => $line, 'reason' => '登录账号为空'];
                continue;
            }
            if ($password === '') {
                $res['skipped']++;
                $res['errors'][] = ['line' => $line, 'reason' => '登录密码为空'];
                continue;
            }

            $typeText = trim((string)($row['account_type_text'] ?? ''));
            $type = 'company';
            if (mb_strpos($typeText, '个人') !== false) {
                $type = 'personal';
            }

            $companyId = 0;
            $companyName = trim((string)($row['company_name'] ?? ''));
            if ($companyName !== '') {
                if (!array_key_exists($companyName, $companyCache)) {
                    $companyCache[$companyName] = (int)(Db::name('client_company')
                        ->where('company_name', $companyName)->value('id') ?: 0);
                }
                $companyId = (int)$companyCache[$companyName];
                if ($companyId <= 0) {
                    $res['skipped']++;
                    $res['errors'][] = ['line' => $line, 'reason' => "企业「{$companyName}」不存在"];
                    continue;
                }
            }

            $staffId = 0;
            $staffName = trim((string)($row['staff_name'] ?? ''));
            if ($staffName !== '') {
                if (!array_key_exists($staffName, $staffCache)) {
                    $staffCache[$staffName] = (int)(Db::name('staff')
                        ->where('staff_name', $staffName)->order('id', 'asc')->value('id') ?: 0);
                }
                $staffId = (int)$staffCache[$staffName];
                if ($staffId <= 0) {
                    $res['skipped']++;
                    $res['errors'][] = ['line' => $line, 'reason' => "人员「{$staffName}」不存在"];
                    continue;
                }
            }

            $platformUrl = trim((string)($row['platform_url'] ?? ''));
            $remark = mb_substr(trim((string)($row['remark'] ?? '')), 0, 500);
            $passwordEnc = SecretService::encrypt($password);
            self::registerPlatform($platform, mb_substr($platformUrl, 0, 255));

            $exist = Db::name('credential_vault')
                ->where('platform', $platform)
                ->where('account_type', $type)
                ->where('account_name', $accountName)
                ->where('company_id', $companyId)
                ->where('staff_id', $staffId)
                ->find();
            if ($exist) {
                Db::name('credential_vault')->where('id', $exist['id'])->update([
                    'platform_url' => mb_substr($platformUrl, 0, 500),
                    'password_enc' => $passwordEnc,
                    'remark'       => $remark,
                    'status'       => 1,
                    'update_time'  => date('Y-m-d H:i:s'),
                ]);
                $res['updated']++;
            } else {
                Db::name('credential_vault')->insert([
                    'platform'     => mb_substr($platform, 0, 128),
                    'platform_url' => mb_substr($platformUrl, 0, 500),
                    'account_type' => $type,
                    'company_id'   => $companyId,
                    'staff_id'     => $staffId,
                    'account_name' => mb_substr($accountName, 0, 255),
                    'password_enc' => $passwordEnc,
                    'remark'       => $remark,
                    'owner_id'     => $userId,
                    'dept_id'      => $deptId,
                    'create_by'    => $userId,
                    'status'       => 1,
                    'create_time'  => date('Y-m-d H:i:s'),
                    'update_time'  => date('Y-m-d H:i:s'),
                ]);
                $res['inserted']++;
            }
        }
        return $res;
    }
}
