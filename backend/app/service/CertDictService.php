<?php
namespace app\service;

use think\facade\Db;

/**
 * 证书「类型 / 专业」二级字典服务（v2.33）
 *
 * 设计要点：
 * - 单 type_code='cert_major'：一级（证书类型）parent_value=''，
 *   二级（证书专业）parent_value=一级的 dict_value。不再有独立的 cert_major_type。
 * - 用户可在字典管理页自主增删类型与专业，零发版即可扩展；
 *   且不会出现「父级改名 → 子级静默断链」的字符串硬编码问题（父级改名时
 *   只需同步其下二级的 parent_value，字典管理页可操作）。
 * - cascade() 一次返回全部，前端本地按 parent_value 过滤，避免级联下拉闪烁 + N+1。
 */
class CertDictService
{
    /** 证书字典码（二级字典：一级=证书类型，二级=证书专业） */
    public const MAJOR_CODE = 'cert_major';

    /**
     * 证书类型（一级）列表：parent_value=''
     *
     * @return array<int,array{id:int,dict_label:string,dict_value:string,sort:int}>
     */
    public static function types(): array
    {
        return self::dedupeTypes(self::fetchLevel1());
    }

    /**
     * 证书专业（二级）列表
     *
     * @param string $parentValue 父级（证书类型的 dict_value）；空=全部二级
     * @return array<int,array{id:int,dict_label:string,dict_value:string,parent_value:string,sort:int}>
     */
    public static function majors(string $parentValue = ''): array
    {
        $parentValue = trim($parentValue);
        if ($parentValue === '') {
            // 全部二级专业（parent_value 非空）；空串在此表示「不限」而非「一级」
            return self::dedupeMajors(self::fetchLevel2());
        }
        return self::dedupeMajors(self::fetch(self::MAJOR_CODE, $parentValue));
    }

    /**
     * 同名专业去重（v2.78）
     *
     * 历史数据里同一父级下可能存在「建筑工程」「建筑工程 」（空格/全角差异）等同名项，
     * 前端下拉会重复渲染；这里按 (parent_value + 规范化名称) 去重，保留 sort/id 最靠前的一条。
     * 只影响读取展示，不改库（避免误删业务仍在引用的字典项）。
     */
    private static function dedupeMajors(array $rows): array
    {
        $seen = [];
        $out  = [];
        foreach ($rows as $r) {
            $label = (string)($r['dict_label'] ?? '');
            $value = (string)($r['dict_value'] ?? '');
            $parent = (string)($r['parent_value'] ?? '');
            $key = $parent . '|' . self::normalize($label !== '' ? $label : $value);
            if ($key === '|' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $r;
        }
        return $out;
    }

    /** 类型列表去重（按 dict_value；同值多行只保留第一条） */
    private static function dedupeTypes(array $rows): array
    {
        $seen = [];
        $out  = [];
        foreach ($rows as $r) {
            $value = trim((string)($r['dict_value'] ?? ''));
            if ($value === '' || isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            $out[] = $r;
        }
        return $out;
    }

    /**
     * 专业名在字典中的归属类型集合（按规范化名称匹配 dict_value / dict_label）
     *
     * @return array<int,string>
     */
    public static function parentsOfMajor(string $name): array
    {
        $name = self::normalize($name);
        if ($name === '') {
            return [];
        }
        $parents = [];
        foreach (self::majors('') as $m) {
            $hit = self::normalize((string)($m['dict_value'] ?? '')) === $name
                || self::normalize((string)($m['dict_label'] ?? '')) === $name;
            if ($hit) {
                $parents[(string)($m['parent_value'] ?? '')] = true;
            }
        }
        unset($parents['']);
        return array_keys($parents);
    }

    /**
     * 校验并补全「证书类型 + 证书专业」组合（v2.78）
     *
     * 规则：
     *  - 专业为空 / 字典中不存在（存量自由文本）→ 原样通过；
     *  - 专业存在、类型为空且归属唯一 → 自动补全类型；
     *  - 专业存在、类型与归属不符 → 返回错误文案（由调用方拒绝保存）；
     *  - 跨类型同名专业（如「建筑工程」同属一建/二建）→ 只要所选类型在归属内即通过。
     *
     * @return array{type:string,name:string,error:string}
     */
    public static function normalizePair(string $type, string $name): array
    {
        $type = trim($type);
        $name = trim($name);
        if ($name === '') {
            return ['type' => $type, 'name' => '', 'error' => ''];
        }
        $parents = self::parentsOfMajor($name);
        if (!$parents) {
            // 字典外的自由文本：保留（存量数据 / 导入历史名称）
            return ['type' => $type, 'name' => $name, 'error' => ''];
        }
        if ($type === '') {
            return ['type' => count($parents) === 1 ? $parents[0] : '', 'name' => $name, 'error' => ''];
        }
        if (in_array($type, $parents, true)) {
            return ['type' => $type, 'name' => $name, 'error' => ''];
        }
        return [
            'type'  => $type,
            'name'  => $name,
            'error' => "证书专业「{$name}」不属于证书类型「{$type}」，请重新选择",
        ];
    }

    /** 字典项是否被业务引用（用于删除前的友好提示） */
    public static function majorUsage(string $name): int
    {
        $name = trim($name);
        if ($name === '') {
            return 0;
        }
        $count = 0;
        foreach (self::MAJOR_REF_TABLES as $t) {
            $count += (int)Db::name($t)->where('cert_name', $name)->count();
        }
        return $count;
    }

    /** 业务表中引用「证书类型」的列（类型改名时同步，避免筛选/匹配断链） */
    private const TYPE_REF_COLUMNS = [
        'certificate'      => ['cert_type', 'category'],
        'project_cert_req' => ['cert_type', 'cert_category'],
        'cert_task_item'   => ['cert_type', 'category'],
        'gov_progress'     => ['cert_type'],
        'policy_rule'      => ['cert_type', 'cert_category'],
    ];

    /** 业务表中引用「证书专业」的列（专业改名时同步） */
    private const MAJOR_REF_TABLES = [
        'certificate', 'project_cert_req', 'cert_task_item', 'gov_progress', 'policy_rule',
    ];

    /**
     * 类型改名：同步二级专业的 parent_value + 业务表中的 cert_type/category（v2.78）
     * 必须在事务内调用（由 DictData::save 负责）。
     */
    public static function renameTypeValue(string $old, string $new): void
    {
        $old = trim($old);
        $new = trim($new);
        if ($old === '' || $new === '' || $old === $new) {
            return;
        }
        Db::name('dict_data')
            ->where('type_code', self::MAJOR_CODE)
            ->where('parent_value', $old)
            ->update(['parent_value' => $new]);
        foreach (self::TYPE_REF_COLUMNS as $table => $cols) {
            foreach ($cols as $col) {
                Db::name($table)->where($col, $old)->update([$col => $new]);
            }
        }
        self::flushCache();
    }

    /**
     * 专业改归属类型：仅同步「引用该专业」的业务行，把 cert_type/category 指向新类型（v2.78）
     * 必须在事务内调用（由 DictData::save 负责）。
     */
    public static function moveMajorToType(string $name, string $newType): void
    {
        $name    = trim($name);
        $newType = trim($newType);
        if ($name === '' || $newType === '') {
            return;
        }
        foreach (self::TYPE_REF_COLUMNS as $table => $cols) {
            foreach ($cols as $col) {
                Db::name($table)->where('cert_name', $name)->update([$col => $newType]);
            }
        }
        self::flushCache();
    }

    /**
     * 专业改名：同步业务表中的 cert_name（v2.78）
     * 必须在事务内调用（由 DictData::save 负责）。
     */
    public static function renameMajorValue(string $old, string $new): void
    {
        $old = trim($old);
        $new = trim($new);
        if ($old === '' || $new === '' || $old === $new) {
            return;
        }
        foreach (self::MAJOR_REF_TABLES as $table) {
            Db::name($table)->where('cert_name', $old)->update(['cert_name' => $new]);
        }
        self::flushCache();
    }

    /**
     * 级联一次性返回：{types:[一级], majors:[全部二级]}
     * 前端一次加载后本地按 parent_value 过滤，无闪烁、无 N+1。
     *
     * v2.38 起：cascade() 经 RedisCache 缓存（TTL 30 分钟）。
     * 字典管理页增删改后由 Menu/Import 层主动 flushNs(NS_CERT_DICT) 抹掉缓存。
     *
     * @return array{types:array,majors:array}
     */
    public static function cascade(): array
    {
        return RedisCache::remember(
            RedisCache::NS_CERT_DICT,
            'cascade_all',
            RedisCache::TTL_LONG,
            fn() => [
                'types'  => self::types(),
                'majors' => self::majors(''),
            ]
        );
    }

    /**
     * 清空证书字典缓存（v2.38）
     *
     * 字典管理页对 cert_major 增删改后必须调用，否则 cascade() 会继续吐旧快照
     * （TTL 30 分钟）。quickCreate 内部也会调，保证「业务侧即时新建」立即可见。
     */
    public static function flushCache(): void
    {
        RedisCache::flushNs(RedisCache::NS_CERT_DICT);
    }

    /**
     * 快速新建字典项（业务录入的一部分，免字典管理权限）
     *
     * 去重口径：(type_code, parent_value, dict_label) —— 用 normalize 后的标签比较，
     * 避免「建筑工程 」与「建筑工程」重复建两条。
     *
     * @param string $label       显示名（即 dict_value，同值便于展示与匹配）
     * @param string $parentValue 上级值（证书专业必属某类型，即一级 dict_value）
     * @param int    $userId      操作人
     * @return array{id:int,dict_label:string,dict_value:string,parent_value:string,duplicated:bool,sort:int}
     */
    public static function quickCreate(string $label, string $parentValue, int $userId): array
    {
        $label = trim($label);
        if ($label === '') {
            throw new \InvalidArgumentException('名称不能为空');
        }
        if (mb_strlen($label) > 64) {
            throw new \InvalidArgumentException('名称过长（最多 64 字符）');
        }
        $parentValue = trim($parentValue);
        // 证书专业必属某个证书类型（parent_value = 当前类型）
        if ($parentValue === '') {
            throw new \InvalidArgumentException('请先选择证书类型');
        }
        // v2.78：父级必须是真实存在的类型，避免脏父级生成「不属于任何类型」的专业
        if (!self::isValidType($parentValue)) {
            throw new \InvalidArgumentException('证书类型不存在，请刷新页面后重试');
        }

        // 1) 同一父级下按 label 精确查（最常见命中路径）
        $exist = Db::name('dict_data')
            ->where('type_code', self::MAJOR_CODE)
            ->where('parent_value', $parentValue)
            ->where('dict_label', $label)
            ->find();
        if (!$exist) {
            // 2) 规范化比较兜底（去空格 / 全角括号转半角 / 全角空格）
            foreach (self::majors($parentValue) as $row) {
                if (self::normalize((string)$row['dict_label']) === self::normalize($label)
                    || self::normalize((string)($row['dict_value'] ?? '')) === self::normalize($label)) {
                    $exist = $row;
                    break;
                }
            }
        }
        if ($exist) {
            return [
                'id'           => (int)$exist['id'],
                'dict_label'   => (string)$exist['dict_label'],
                'dict_value'   => (string)$exist['dict_value'],
                'parent_value' => (string)($exist['parent_value'] ?? ''),
                'sort'         => (int)($exist['sort'] ?? 0),
                'duplicated'   => true,
                'conflict_scope' => 'same',
            ];
        }

        // 跨类型同名提示（一建/二建本就合法同名，此规则不得误伤）：
        // 同级名在**其他**类型下已存在 → 提示但允许继续。
        // 此处遍历全部二级（majors('')），不可遍历一级（fetchLevel1）。
        $foreignParents = [];
        foreach (self::majors('') as $row) {
            if ((string)($row['parent_value'] ?? '') === $parentValue) {
                continue;
            }
            if (self::normalize((string)$row['dict_label']) === self::normalize($label)
                || self::normalize((string)($row['dict_value'] ?? '')) === self::normalize($label)) {
                $foreignParents[] = (string)$row['parent_value'];
            }
        }
        $foreignParents = array_values(array_unique($foreignParents));

        // 3) 新增：sort 取同级最大值 +1（约定：必须显式给 sort，否则全排最前）
        $maxSort = (int)Db::name('dict_data')
            ->where('type_code', self::MAJOR_CODE)
            ->where('parent_value', $parentValue)
            ->max('sort');
        $sort = $maxSort + 1;

        $id = Db::name('dict_data')->insertGetId([
            'type_code'    => self::MAJOR_CODE,
            'dict_label'   => $label,
            'dict_value'   => $label,
            'parent_value' => $parentValue,
            'sort'         => $sort,
            'status'       => 1,
            'remark'       => 'quick_create', // 来源标记，字典管理页可据此筛选清理
            'create_time'  => date('Y-m-d H:i:s'),
        ]);
        self::flushCache(); // v2.38：新建后级联缓存立即失效

        return [
            'id'           => (int)$id,
            'dict_label'   => $label,
            'dict_value'   => $label,
            'parent_value' => $parentValue,
            'sort'         => $sort,
            'duplicated'   => false,
            'conflict_scope'   => $foreignParents ? 'cross' : '',
            'conflict_parents' => $foreignParents,
        ];
    }

    /**
     * 按 cert_type 反查其下专业（校验用；空则返回全部二级）
     *
     * @return array<int,array>
     */
    public static function majorsByType(string $certType): array
    {
        return self::majors(trim($certType));
    }

    /**
     * cert_type 是否为已启用的合法类型（软校验：不阻塞存量空值）
     */
    public static function isValidType(string $certType): bool
    {
        $certType = trim($certType);
        if ($certType === '') {
            return true; // 空 = 未分类，合法（存量兼容）
        }
        foreach (self::types() as $t) {
            if ((string)$t['dict_value'] === $certType) {
                return true;
            }
        }
        return false;
    }

    /**
     * 归一化字符串（去空格、全角括号转半角、全角空格转半角）
     * 与 MatchService 的规范化口径保持一致，供去重比较用。
     */
    public static function normalize(string $s): string
    {
        $s = str_replace('（', '(', $s);
        $s = str_replace('）', ')', $s);
        $s = str_replace('　', ' ', $s);
        // 去掉所有空白（含内部空格），使「建筑 工程」与「建筑工程」等价
        return (string)preg_replace('/\s+/u', '', trim($s));
    }

    /** 一级（证书类型，parent_value=''） */
    private static function fetchLevel1(): array
    {
        return self::fetch(self::MAJOR_CODE, '');
    }

    /** 全部二级（parent_value 非空） */
    private static function fetchLevel2(): array
    {
        try {
            return Db::name('dict_data')
                ->where('type_code', self::MAJOR_CODE)
                ->where('status', 1)
                ->where('parent_value', '<>', '')
                ->order('sort', 'asc')
                ->order('id', 'asc')
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            // 字典表或 parent_value 列缺失时降级为空，前端展示空下拉而非报错
            return [];
        }
    }

    /**
     * 查字典数据（精确匹配 type_code + parent_value；空串 = 一级）
     *
     * @param string $typeCode
     * @param string $parentValue 精确匹配；'' = 一级
     * @return array<int,array>
     */
    private static function fetch(string $typeCode, string $parentValue): array
    {
        try {
            return Db::name('dict_data')
                ->where('type_code', $typeCode)
                ->where('parent_value', $parentValue)
                ->where('status', 1)
                ->order('sort', 'asc')
                ->order('id', 'asc')
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            // 字典表或 parent_value 列缺失时降级为空，前端展示空下拉而非报错
            return [];
        }
    }
}
