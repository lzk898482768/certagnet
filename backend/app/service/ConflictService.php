<?php
namespace app\service;

use think\facade\Db;

/**
 * 证书冲突检测服务 —— 产品精髓「多维关联」
 *
 * 解决痛点：一人多证普遍，人工梳理「这张证现在被谁占着、这个人有没有分身乏术」
 * 极易出错。本服务把冲突判定固化为四类可执行规则：
 *
 *   ① 占用冲突 (occupy)   同一证书在同一时间区间已被其他企业借调
 *   ② 人员冲突 (person)   同一人在同一时间区间被多个项目/企业占用
 *   ③ 互斥冲突 (exclusive)互斥组内证书（如一级/二级建造师）被同一人同时占用
 *   ④ 政策冲突 (policy)   社保状态或属地政策不满足（由 PolicyRuleService 求值）
 *
 * 区间判定采用「闭区间重叠」：端点相接（甲今天归还、乙今天借出）不算冲突。
 */
class ConflictService
{
    public const T_OCCUPY    = 'occupy';    // 证书占用冲突
    public const T_PERSON    = 'person';    // 人员占用冲突
    public const T_EXCLUSIVE = 'exclusive'; // 证书互斥冲突
    public const T_POLICY    = 'policy';    // 社保/属地政策冲突

    public const TYPES = [
        self::T_OCCUPY    => '证书占用冲突',
        self::T_PERSON    => '人员时间冲突',
        self::T_EXCLUSIVE => '证书互斥冲突',
        self::T_POLICY    => '政策合规冲突',
    ];

    /**
     * v2.55：冲突规则默认配置（用户可在冲突中心「规则设置」中调整）。
     * level：0=提示 1=警告 2=阻断；status：1=启用 0=禁用。
     * 规则说明：
     * - occupy：同一证书在同一时间区间已被其他企业借调（默认阻断）；
     * - person：同一人员在相同区间内有多本证书分别借给不同企业（默认警告，
     *   符合「一人多证可同时被多家企业调用」的业务，但需二次确认）；
     * - exclusive：互斥组内证书被同一人同时占用（默认阻断）；
     * - policy：社保状态或属地政策不满足（默认阻断）。
     */
    public const RULE_DEFAULTS = [
        self::T_OCCUPY => [
            'name'   => '证书占用冲突',
            'desc'   => '同一证书在同一时间区间已被其他企业借调',
            'level'  => 2,
            'status' => 1,
        ],
        self::T_PERSON => [
            'name'   => '人员时间冲突',
            'desc'   => '同一人员在相同时间区间被多个企业同时借用（多本证书分别借出）',
            'level'  => 1,
            'status' => 1,
        ],
        self::T_EXCLUSIVE => [
            'name'   => '证书互斥冲突',
            'desc'   => '互斥组内的证书被同一人同时占用（互斥组可在下方逐条自定义）',
            'level'  => 2,
            'status' => 1,
        ],
        self::T_POLICY => [
            'name'   => '政策合规冲突',
            'desc'   => '社保状态或属地政策不满足',
            'level'  => 2,
            'status' => 1,
        ],
    ];

    /**
     * 单证书冲突检测（配证/借调前调用）
     *
     * @param int $certId 证书ID
     * @param array $opts ['start_date','end_date','project_id','staff_id']
     * @return array 冲突项数组
     */
    public static function checkCert(int $certId, array $opts = []): array
    {
        $conflicts = [];
        $cert = Db::name('certificate')->where('id', $certId)->find();
        if (!$cert) {
            return $conflicts;
        }

        $start = (string)($opts['start_date'] ?? date('Y-m-d'));
        $end   = (string)($opts['end_date'] ?? ($cert['expire_date'] ?: date('Y-m-d', strtotime('+1 year'))));
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }

        // ① 证书占用冲突：借调中(status=1)且区间重叠
        $busy = Db::name('cert_secondment')->alias('s')
            ->leftJoin('client_company c', 'c.id = s.company_id')
            ->field('s.id,s.start_date,s.end_date,s.company_id,c.company_name')
            ->where('s.cert_id', $certId)->where('s.status', 1)
            ->where(function ($w) use ($start, $end) {
                // 已借调区间 [s1,e1] 与目标 [start,end] 重叠：s1 <= end 且 e1 >= start
                $w->where(function ($a) use ($end) {
                    $a->where(function ($x) use ($end) { $x->whereNull('start_date')->whereOr('start_date', '<=', $end); });
                })->where(function ($b) use ($start) {
                    $b->where(function ($x) use ($start) { $x->whereNull('end_date')->whereOr('end_date', '>=', $start); });
                });
            })
            ->order('s.id', 'asc')->select()->toArray();

        foreach ($busy as $b) {
            $conflicts[] = [
                'type'   => self::T_OCCUPY,
                'level'  => 2,
                'title'  => '证书已被占用',
                'detail' => sprintf(
                    '该证书已借调至「%s」，占用区间 %s ~ %s',
                    $b['company_name'] ?: '未知企业',
                    $b['start_date'] ?: '不限',
                    $b['end_date'] ?: '长期'
                ),
                'ref_id' => (int)$b['id'],
                'range'  => ['start' => $b['start_date'], 'end' => $b['end_date']],
            ];
        }

        // ② 人员冲突（v2.55）：同一人员的其他证书正被其他企业借调。
        // 业务允许「一人多证同时被一家或多家企业调用」，因此默认警告级：
        // 提示「哪本证书已被哪家企业占用」并让用户确认是否继续借调。
        $staffId = (int)($opts['staff_id'] ?? $cert['staff_id']);
        if ($staffId > 0) {
            foreach (self::checkStaffInRange($staffId, $start, $end, $certId) as $pc) {
                $conflicts[] = $pc;
            }
        }

        // ③ 互斥冲突：同一人互斥组内其他证书正被占用
        foreach (self::exclusiveGroupsOf((string)$cert['cert_name']) as $g) {
            $members = $g['members'];
            $others = Db::name('certificate')
                ->where('staff_id', $cert['staff_id'])
                ->where('id', '<>', $certId)
                ->whereIn('cert_name', $members)
                ->where('cert_status', 1)
                ->select()->toArray();
            foreach ($others as $o) {
                $busyO = Db::name('cert_secondment')->alias('s')
                    ->leftJoin('client_company c', 'c.id = s.company_id')
                    ->field('s.id,c.company_name')
                    ->where('s.cert_id', $o['id'])->where('s.status', 1)->find();
                if ($busyO) {
                    $conflicts[] = [
                        'type'   => self::T_EXCLUSIVE,
                        'level'  => 2,
                        'title'  => '互斥证书冲突',
                        'detail' => sprintf(
                            '互斥组「%s」中，该人员的「%s」已被「%s」占用，同一人不可同时占用互斥证书',
                            $g['group_name'], $o['cert_name'], $busyO['company_name'] ?: '未知企业'
                        ),
                        'ref_id' => (int)$g['id'],
                    ];
                }
            }
        }

        // ④ 政策冲突：社保 + 属地政策
        $social   = PersonProfileService::resolveSocial($staffId, [$cert]);
        $projectId = (int)($opts['project_id'] ?? 0);
        foreach (PolicyRuleService::evaluateCert($cert, $projectId, $social) as $issue) {
            $conflicts[] = $issue + ['type' => self::T_POLICY];
        }

        // v2.55：按用户配置的冲突规则过滤/覆盖级别（禁用项不返回）
        return self::decorateConflicts($conflicts);
    }

    /**
     * 单人员在某区间的人员冲突（智能匹配与批量配证时调用）
     *
     * @param int $staffId 人员ID
     * @param string $start 起始日期
     * @param string $end   截止日期
     * @param int $excludeCertId 排除的证书ID（正在为该证书续期/续借时不算冲突）
     */
    public static function checkStaffInRange(int $staffId, string $start, string $end, int $excludeCertId = 0): array
    {
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }
        $q = Db::name('cert_secondment')->alias('s')
            ->leftJoin('certificate cert', 'cert.id = s.cert_id')
            ->leftJoin('client_company c', 'c.id = s.company_id')
            ->field('s.id,s.cert_id,s.start_date,s.end_date,cert.cert_name,c.company_name')
            ->where('s.staff_id', $staffId)->where('s.status', 1);
        if ($excludeCertId) {
            $q->where('s.cert_id', '<>', $excludeCertId);
        }
        $rows = $q->order('s.id', 'asc')->select()->toArray();

        $out = [];
        foreach ($rows as $r) {
            // 闭区间重叠判定
            $s1 = (string)($r['start_date'] ?: '');
            $e1 = (string)($r['end_date'] ?: '');
            $overlap = ($s1 === '' || $s1 <= $end) && ($e1 === '' || $e1 >= $start);
            if (!$overlap) {
                continue;
            }
            $out[] = [
                'type'   => self::T_PERSON,
                'level'  => 2,
                'title'  => '人员时间冲突',
                'detail' => sprintf(
                    '该人员的「%s」在 %s 占用至 %s',
                    $r['cert_name'] ?: '证书',
                    $r['company_name'] ?: '未知企业',
                    $e1 ?: '长期'
                ),
                'ref_id' => (int)$r['id'],
                'range'  => ['start' => $s1, 'end' => $e1],
            ];
        }
        return self::decorateConflicts($out);
    }

    /**
     * 人员全景冲突汇总（用于人员档案页展示）
     * @return array 冲突项数组
     */
    public static function checkStaff(int $staffId): array
    {
        $conflicts = [];

        // 名下每张有效证书的占用冲突
        $certs = Db::name('certificate')->where('staff_id', $staffId)->where('cert_status', 1)
            ->select()->toArray();
        foreach ($certs as $cert) {
            $busy = Db::name('cert_secondment')->alias('s')
                ->leftJoin('client_company c', 'c.id = s.company_id')
                ->field('s.id,s.start_date,s.end_date,c.company_name,cert.cert_name')
                ->join('certificate cert', 'cert.id = s.cert_id')
                ->where('s.cert_id', $cert['id'])->where('s.status', 1)->find();
            if ($busy) {
                $conflicts[] = [
                    'type'  => self::T_OCCUPY,
                    'level' => 0,   // 正常借出不算「冲突」，仅作占用提示
                    'title' => '证书正在使用中',
                    'detail' => sprintf(
                        '「%s」正借调至「%s」，%s ~ %s',
                        $cert['cert_name'], $busy['company_name'] ?: '未知企业',
                        $busy['start_date'] ?: '不限', $busy['end_date'] ?: '长期'
                    ),
                    'ref_id' => (int)$busy['id'],
                ];
            }
        }

        // 互斥冲突
        foreach ($certs as $cert) {
            foreach (self::exclusiveGroupsOf((string)$cert['cert_name']) as $g) {
                $others = Db::name('certificate')
                    ->where('staff_id', $staffId)->where('id', '<>', $cert['id'])
                    ->whereIn('cert_name', $g['members'])->where('cert_status', 1)->select()->toArray();
                foreach ($others as $o) {
                    $busyO = Db::name('cert_secondment')->alias('s')
                        ->leftJoin('client_company c', 'c.id = s.company_id')
                        ->field('s.id,c.company_name')
                        ->where('s.cert_id', $o['id'])->where('s.status', 1)->find();
                    if ($busyO) {
                        $conflicts[] = [
                            'type'   => self::T_EXCLUSIVE,
                            'level'  => 2,
                            'title'  => '互斥证书冲突',
                            'detail' => sprintf('互斥组「%s」：%s 已占用，同一人不可同时占用',
                                $g['group_name'], $o['cert_name']),
                            'ref_id' => (int)$g['id'],
                        ];
                    }
                }
            }
        }

        // 政策冲突（无项目作用域时只跑全国通用规则）
        $social = PersonProfileService::resolveSocial($staffId, $certs);
        foreach ($certs as $cert) {
            foreach (PolicyRuleService::evaluateCert($cert, 0, $social) as $issue) {
                $conflicts[] = $issue + ['type' => self::T_POLICY];
            }
        }

        // v2.55：按用户配置的冲突规则过滤/覆盖级别
        return self::decorateConflicts($conflicts);
    }

    /**
     * v2.55：读取冲突规则设置（system_config 存 JSON，进程内缓存）。
     * @return array key => ['name','desc','level','status']
     */
    public static function rules(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $saved = sys_config('conflict_rule_settings', '');
        $json  = [];
        if (is_string($saved) && $saved !== '') {
            $decoded = json_decode($saved, true);
            if (is_array($decoded)) {
                $json = $decoded;
            }
        }
        $out = [];
        foreach (self::RULE_DEFAULTS as $key => $def) {
            $conf  = is_array($json[$key] ?? null) ? $json[$key] : [];
            $level = (int)($conf['level'] ?? $def['level']);
            if (!in_array($level, [0, 1, 2], true)) {
                $level = (int)$def['level'];
            }
            $out[$key] = [
                'name'   => (string)($conf['name'] ?? $def['name']),
                'desc'   => (string)($conf['desc'] ?? $def['desc']),
                'level'  => $level,
                'status' => (int)($conf['status'] ?? $def['status']) === 1 ? 1 : 0,
            ];
        }
        $cache = $out;
        return $out;
    }

    /**
     * v2.55：保存冲突规则设置（仅管理员）。
     * @param array $rules key => ['level'=>int,'status'=>int]
     * @return array 保存后的规则全量
     */
    public static function saveRules(array $rules): array
    {
        $current = self::rules();
        foreach ($rules as $key => $conf) {
            if (!isset(self::RULE_DEFAULTS[$key]) || !is_array($conf)) {
                continue;
            }
            $level = (int)($conf['level'] ?? -1);
            if (in_array($level, [0, 1, 2], true)) {
                $current[$key]['level'] = $level;
            }
            $current[$key]['status'] = (int)($conf['status'] ?? 1) === 1 ? 1 : 0;
        }
        $row = \app\model\SystemConfig::where('config_key', 'conflict_rule_settings')->find();
        $payload = json_encode($current, JSON_UNESCAPED_UNICODE);
        if ($row) {
            $row->save(['config_value' => $payload]);
        } else {
            \app\model\SystemConfig::create([
                'config_key'   => 'conflict_rule_settings',
                'config_value' => $payload,
                'config_name'  => '冲突规则设置',
            ]);
        }
        \think\facade\Cache::delete('sys_config_map');
        return $current;
    }

    /**
     * v2.99.14：互斥组全量列表（含停用），供「冲突规则设置」逐条维护。
     * @return array<int,array{id:int,group_name:string,members:array<int,string>,remark:string,status:int}>
     */
    public static function exclusiveGroupsAll(): array
    {
        try {
            $rows = Db::name('cert_mutual_exclusive')->order('id', 'asc')->select()->toArray();
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'         => (int)$r['id'],
                'group_name' => (string)$r['group_name'],
                'members'    => array_values(array_filter(array_map('trim', explode(',', (string)$r['member_certs'])))),
                'remark'     => (string)($r['remark'] ?? ''),
                'status'     => (int)$r['status'] === 1 ? 1 : 0,
            ];
        }
        return $out;
    }

    /**
     * v2.99.14：互斥组可选证书专业名（字典专业 + 已录证书 + 现有组内成员）。
     * 客户可从中挑选，也可在前端直接输入字典外的名称。
     */
    public static function certNameOptions(): array
    {
        $names = [];
        try {
            $cascade = CertDictService::cascade();
            foreach (($cascade['majors'] ?? []) as $m) {
                foreach ([$m['dict_label'] ?? '', $m['dict_value'] ?? ''] as $n) {
                    $n = trim((string)$n);
                    if ($n !== '') { $names[$n] = 1; }
                }
            }
        } catch (\Throwable $e) {
            // 字典不可用时退化为「已录证书」列表
        }
        try {
            foreach (Db::name('certificate')->distinct(true)->column('cert_name') as $n) {
                $n = trim((string)$n);
                if ($n !== '') { $names[$n] = 1; }
            }
        } catch (\Throwable $e) {
            // 忽略
        }
        foreach (self::exclusiveGroupsAll() as $g) {
            foreach ($g['members'] as $n) {
                if ($n !== '') { $names[$n] = 1; }
            }
        }
        $out = array_keys($names);
        sort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    /**
     * v2.99.14：新增/保存互斥组（客户自定义，不再依赖系统内置规则）。
     *
     * @param array $data ['id'=>int,'group_name'=>string,'members'=>array|string,'remark'=>string,'status'=>int]
     * @return array 保存后的互斥组
     * @throws \InvalidArgumentException 校验不通过
     */
    public static function saveExclusiveGroup(array $data): array
    {
        $id      = (int)($data['id'] ?? 0);
        $name    = trim((string)($data['group_name'] ?? ''));
        $members = $data['members'] ?? [];
        if (is_string($members)) {
            $members = explode(',', $members);
        }
        if (!is_array($members)) {
            $members = [];
        }
        $members = array_values(array_unique(array_filter(
            array_map(fn($x) => trim((string)$x), $members),
            fn($x) => $x !== ''
        )));
        $remark = trim((string)($data['remark'] ?? ''));
        $status = (int)($data['status'] ?? 1) === 1 ? 1 : 0;

        if ($name === '') {
            throw new \InvalidArgumentException('请填写互斥组名称');
        }
        if (mb_strlen($name) > 64) {
            throw new \InvalidArgumentException('互斥组名称最多 64 个字符');
        }
        if (count($members) < 2) {
            throw new \InvalidArgumentException('互斥组至少需要 2 个证书专业');
        }
        $memberStr = implode(',', $members);
        if (mb_strlen($memberStr) > 500) {
            throw new \InvalidArgumentException('组内证书过多（合计最多 500 字符）');
        }
        if (mb_strlen($remark) > 255) {
            $remark = mb_substr($remark, 0, 255);
        }

        $dup = Db::name('cert_mutual_exclusive')->where('group_name', $name);
        if ($id > 0) {
            $dup->where('id', '<>', $id);
        }
        if ($dup->find()) {
            throw new \InvalidArgumentException('已存在同名互斥组：' . $name);
        }

        $payload = [
            'group_name'   => $name,
            'member_certs' => $memberStr,
            'remark'       => $remark,
            'status'       => $status,
        ];
        if ($id > 0) {
            if (!Db::name('cert_mutual_exclusive')->where('id', $id)->find()) {
                throw new \InvalidArgumentException('互斥组不存在或已被删除');
            }
            Db::name('cert_mutual_exclusive')->where('id', $id)->update($payload);
        } else {
            $id = (int)Db::name('cert_mutual_exclusive')->insertGetId($payload + [
                'create_time' => date('Y-m-d H:i:s'),
                'update_time' => date('Y-m-d H:i:s'),
            ]);
        }

        $saved = null;
        foreach (self::exclusiveGroupsAll() as $g) {
            if ($g['id'] === $id) { $saved = $g; break; }
        }
        return $saved ?: ['id' => $id] + $payload + ['members' => $members];
    }

    /**
     * v2.99.14：删除互斥组（仅删配置，不影响已有借调与证书数据）。
     */
    public static function deleteExclusiveGroup(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        return Db::name('cert_mutual_exclusive')->where('id', $id)->delete() > 0;
    }

    /**
     * v2.55：按规则设置过滤并覆盖冲突级别。
     * 被禁用的规则类型不输出；启用时级别以用户配置为准。
     */
    public static function decorateConflicts(array $items): array
    {
        $rules = self::rules();
        $out   = [];
        foreach ($items as $item) {
            $type = (string)($item['type'] ?? '');
            $rule = $rules[$type] ?? null;
            if (!$rule || !$rule['status']) {
                continue;
            }
            $item['level']     = (int)$rule['level'];
            $item['rule_key']   = $type;
            $item['rule_name']  = $rule['name'];
            $out[] = $item;
        }
        return $out;
    }

    /**
     * 全局冲突清单（冲突中心列表）
     * @param array $opts ['type'=>'','limit'=>100]
     */
    public static function listConflicts(array $opts = []): array
    {
        $limit = (int)($opts['limit'] ?? 100);
        $type  = (string)($opts['type'] ?? '');
        $items = [];
        $seen  = [];

        // ① 互斥冲突（最值得优先展示）
        $exGroups = self::allExclusiveGroups();
        if (!$type || $type === self::T_EXCLUSIVE) {
            foreach ($exGroups as $g) {
                if (!$g['members']) { continue; }
                $rows = Db::name('certificate')->alias('cert')
                    ->leftJoin('cert_secondment s', 's.cert_id = cert.id AND s.status = 1')
                    ->field('cert.id,cert.staff_id,cert.cert_name,cert.cert_no,s.id AS secondment_id,s.end_date')
                    ->whereIn('cert.cert_name', $g['members'])
                    ->where('cert.cert_status', 1)
                    ->where('s.id', '>', 0)
                    ->order('cert.staff_id', 'asc')->select()->toArray();
                // 同一人员组内占用数 >= 2 即互斥冲突
                $byStaff = [];
                foreach ($rows as $r) { $byStaff[(int)$r['staff_id']][] = $r; }
                foreach ($byStaff as $staffId => $list) {
                    if (count($list) < 2) { continue; }
                    $staff = Db::name('staff')->where('id', $staffId)->field('staff_name')->find();
                    $names = array_map(fn($x) => $x['cert_name'], $list);
                    // v2.99.14：同一证书可存在于多个互斥组，按「人员 + 证书集合」去重，避免重复计数
                    sort($names);
                    $seenKey = 'exclusive:' . $staffId . ':' . implode(',', $names);
                    if (isset($seen[$seenKey])) { continue; }
                    $seen[$seenKey] = 1;
                    $items[] = [
                        'type'   => self::T_EXCLUSIVE,
                        'type_text' => self::TYPES[self::T_EXCLUSIVE],
                        'level'  => 2,
                        'title'  => '互斥证书同时占用',
                        'detail' => sprintf('%s 同时占用互斥证书：%s',
                            $staff['staff_name'] ?? "人员#{$staffId}", implode('、', $names)),
                        'staff_id' => (int)$staffId,
                        'ref_id' => (int)$g['id'],
                    ];
                }
            }
        }

        // ② 同证书重叠占用（异常数据：同一证书多条 status=1 区间重叠）
        if (!$type || $type === self::T_OCCUPY) {
            $rows = Db::name('cert_secondment')->alias('s')
                ->leftJoin('certificate cert', 'cert.id = s.cert_id')
                ->leftJoin('staff st', 'st.id = s.staff_id')
                ->field('s.id,s.cert_id,s.start_date,s.end_date,cert.cert_name,cert.cert_no,st.staff_name')
                ->where('s.status', 1)->order('s.cert_id', 'asc')->select()->toArray();
            $byCert = [];
            foreach ($rows as $r) { $byCert[(int)$r['cert_id']][] = $r; }
            foreach ($byCert as $certId => $list) {
                $n = count($list);
                if ($n < 2) { continue; }
                // 两两判定重叠
                for ($i = 0; $i < $n; $i++) {
                    for ($j = $i + 1; $j < $n; $j++) {
                        $a = $list[$i]; $b = $list[$j];
                        $s1 = (string)($a['start_date'] ?: ''); $e1 = (string)($a['end_date'] ?: '');
                        $s2 = (string)($b['start_date'] ?: ''); $e2 = (string)($b['end_date'] ?: '');
                        if (($s1 === '' || $s1 <= $e2) && ($e1 === '' || $e1 >= $s2)) {
                            $key = "occupy:{$certId}:{$a['id']}:{$b['id']}";
                            if (isset($seen[$key])) { continue; }
                            $seen[$key] = 1;
                            $items[] = [
                                'type'   => self::T_OCCUPY,
                                'type_text' => self::TYPES[self::T_OCCUPY],
                                'level'  => 2,
                                'title'  => '证书重复占用',
                                'detail' => sprintf('%s 的「%s」存在 %d 条生效借调且区间重叠',
                                    $a['staff_name'] ?: '未知人员', $a['cert_name'], $n),
                                'staff_id' => (int)($a['staff_id'] ?? 0),
                                'ref_id' => (int)$certId,
                            ];
                        }
                    }
                }
            }
        }

        // 排序：阻断级优先
        usort($items, fn($x, $y) => ($y['level'] ?? 0) <=> ($x['level'] ?? 0));

        // v2.55：按用户配置的冲突规则过滤/覆盖级别（禁用项不返回）
        $items = self::decorateConflicts($items);

        return ['total' => count($items), 'items' => array_slice($items, 0, max(1, $limit))];
    }

    /**
     * 全量体检（冲突中心「立即体检」）
     *
     * 在 listConflicts（互斥 + 重复占用）基础上补齐两类全局扫描：
     *   ③ 人员时间冲突：同一人员多条生效借调区间重叠（逐人两两判定）
     *   ④ 政策预警：生效借调证书剩余有效期不满足政策下限
     *
     * @param int $limit 返回明细上限
     * @return array ['summary'=>[...], 'items'=>[...], 'total'=>int, 'scanned_at'=>string]
     */
    public static function scanAll(int $limit = 500): array
    {
        $items = [];

        // ①② 互斥冲突 + 同证书重复占用（复用全局清单逻辑）
        $base = self::listConflicts(['limit' => $limit]);
        foreach ($base['items'] as $it) {
            $items[] = $it;
        }

        // ③ 人员时间冲突：按人分组，两两闭区间重叠判定
        $rows = Db::name('cert_secondment')->alias('s')
            ->leftJoin('certificate cert', 'cert.id = s.cert_id')
            ->leftJoin('client_company c', 'c.id = s.company_id')
            ->leftJoin('staff st', 'st.id = s.staff_id')
            ->field('s.id,s.staff_id,s.cert_id,s.start_date,s.end_date,cert.cert_name,c.company_name,st.staff_name')
            ->where('s.status', 1)
            ->order('s.staff_id', 'asc')->order('s.id', 'asc')->select()->toArray();
        $byStaff = [];
        foreach ($rows as $r) {
            $byStaff[(int)$r['staff_id']][] = $r;
        }
        foreach ($byStaff as $staffId => $list) {
            $n = count($list);
            if ($n < 2) { continue; }
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $list[$i]; $b = $list[$j];
                    $s1 = (string)($a['start_date'] ?: ''); $e1 = (string)($a['end_date'] ?: '');
                    $s2 = (string)($b['start_date'] ?: ''); $e2 = (string)($b['end_date'] ?: '');
                    $overlap = ($s1 === '' || $s1 <= $e2) && ($e1 === '' || $e1 >= $s2);
                    if (!$overlap) { continue; }
                    $items[] = [
                        'type'      => self::T_PERSON,
                        'type_text' => self::TYPES[self::T_PERSON],
                        'level'     => 2,
                        'title'     => '人员时间冲突',
                        'detail'    => sprintf(
                            '%s 同时被占用：「%s」→ %s（%s ~ %s） 与 「%s」→ %s（%s ~ %s）',
                            $a['staff_name'] ?: "人员#{$staffId}",
                            $a['cert_name'] ?: '证书', $a['company_name'] ?: '未知企业', $s1 ?: '不限', $e1 ?: '长期',
                            $b['cert_name'] ?: '证书', $b['company_name'] ?: '未知企业', $s2 ?: '不限', $e2 ?: '长期'
                        ),
                        'staff_id'  => $staffId,
                        'ref_id'    => (int)$a['id'],
                    ];
                }
            }
        }

        // ④ 政策预警：生效借调证书的剩余有效期不满足政策下限
        try {
            $rules = PolicyRuleService::rules(0, '');
            $minDays = 0;
            foreach ($rules as $r) {
                if (($r['rule_type'] ?? '') === 'expiry_limit') {
                    $expr = PolicyRuleService::parseExpr((string)($r['rule_expr'] ?? ''));
                    $minDays = max($minDays, (int)($expr['min_expire_days'] ?? 0));
                }
            }
            if ($minDays > 0) {
                $deadline = date('Y-m-d', strtotime("+{$minDays} days"));
                $expiring = Db::name('cert_secondment')->alias('s')
                    ->leftJoin('certificate cert', 'cert.id = s.cert_id')
                    ->leftJoin('staff st', 'st.id = s.staff_id')
                    ->field('s.id,s.staff_id,cert.id AS cert_id,cert.cert_name,cert.expire_date,st.staff_name')
                    ->where('s.status', 1)
                    ->whereNotNull('cert.expire_date')
                    ->where('cert.expire_date', '<=', $deadline)
                    ->select()->toArray();
                foreach ($expiring as $e) {
                    $items[] = [
                        'type'      => self::T_POLICY,
                        'type_text' => self::TYPES[self::T_POLICY],
                        'level'     => 1,
                        'title'     => '占用证书即将到期',
                        'detail'    => sprintf(
                            '%s 正被占用的「%s」将于 %s 到期（政策要求剩余有效期 ≥ %d 天）',
                            $e['staff_name'] ?: '未知人员', $e['cert_name'], $e['expire_date'], $minDays
                        ),
                        'staff_id'  => (int)$e['staff_id'],
                        'ref_id'    => (int)$e['id'],
                    ];
                }
            }
        } catch (\Throwable $e) {
            // 政策表缺失时跳过该维度，不影响其余体检结果
        }

        // v2.55：按用户配置的冲突规则过滤/覆盖级别（禁用项不返回）
        $items = self::decorateConflicts($items);

        // 汇总
        $summary = [
            'total'     => count($items),
            'blocked'   => 0,
            'by_type'   => [],
            'by_level'  => [0 => 0, 1 => 0, 2 => 0],
        ];
        foreach ($items as $it) {
            $t = (string)($it['type'] ?? '');
            $l = (int)($it['level'] ?? 0);
            $summary['by_type'][$t] = ($summary['by_type'][$t] ?? 0) + 1;
            $summary['by_level'][$l] = ($summary['by_level'][$l] ?? 0) + 1;
            if ($l >= 2) { $summary['blocked']++; }
        }

        usort($items, fn($x, $y) => ($y['level'] ?? 0) <=> ($x['level'] ?? 0));

        return [
            'summary'    => $summary,
            'items'      => array_slice($items, 0, max(1, $limit)),
            'total'      => count($items),
            'scanned_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * v2.56：体检留痕持久化 —— 冲突中心「立即体检」结果写入 conflict_scan 表，
     * 刷新页面后仍可看到上次体检的冲突清单（此前仅存前端内存，一刷新即丢失）。
     */
    private static function ensureScanTable(): void
    {
        try {
            Db::execute("CREATE TABLE IF NOT EXISTS `conflict_scan` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `summary` text NULL COMMENT '体检汇总 JSON',
  `items` mediumtext NULL COMMENT '体检明细 JSON',
  `total` int NOT NULL DEFAULT 0 COMMENT '冲突总数',
  `scanned_at` datetime DEFAULT NULL COMMENT '体检时间',
  `create_by` int unsigned NOT NULL DEFAULT 0 COMMENT '触发体检的账号ID',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='冲突中心体检留痕（v2.56 持久化）'");
        } catch (\Throwable $e) {
            // 建表失败不阻断主流程
        }
    }

    /**
     * 保存一次体检结果（追加留痕，前端只读取最新一条）
     */
    public static function saveScan(array $data, int $userId): void
    {
        self::ensureScanTable();
        try {
            Db::name('conflict_scan')->insert([
                'summary'     => json_encode($data['summary'] ?? [], JSON_UNESCAPED_UNICODE),
                'items'       => json_encode($data['items'] ?? [], JSON_UNESCAPED_UNICODE),
                'total'       => (int)($data['total'] ?? 0),
                'scanned_at'  => (string)($data['scanned_at'] ?? date('Y-m-d H:i:s')),
                'create_by'   => $userId,
                'create_time' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // 留痕失败不阻断体检结果返回
        }
    }

    /**
     * 最近一次体检结果（无则返回 null）
     */
    public static function latestScan(): ?array
    {
        self::ensureScanTable();
        try {
            return Db::name('conflict_scan')->order('id', 'desc')->find() ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 取包含指定证书专业名的互斥组
     * @return array [['id'=>int,'group_name'=>string,'members'=>array]]
     */
    public static function exclusiveGroupsOf(string $certName): array
    {
        if ($certName === '') {
            return [];
        }
        $out = [];
        foreach (self::allExclusiveGroups() as $g) {
            if (in_array($certName, $g['members'], true)) {
                $out[] = $g;
            }
        }
        return $out;
    }

    /**
     * 全部启用的互斥组
     * @return array [['id'=>int,'group_name'=>string,'members'=>array]]
     */
    public static function allExclusiveGroups(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $out = [];
        try {
            $rows = Db::name('cert_mutual_exclusive')->where('status', 1)->select()->toArray();
            foreach ($rows as $r) {
                $members = array_values(array_filter(array_map('trim', explode(',', (string)$r['member_certs']))));
                if (!$members) { continue; }
                $out[] = [
                    'id'         => (int)$r['id'],
                    'group_name' => (string)$r['group_name'],
                    'members'    => $members,
                ];
            }
        } catch (\Throwable $e) {
            $out = [];
        }
        $cache = $out;
        return $out;
    }

    /** 冲突项 → 可读文本（消息模板与提醒用） */
    public static function summaryText(array $conflict): string
    {
        $t = self::TYPES[$conflict['type'] ?? ''] ?? '冲突';
        return "{$t}：" . ($conflict['detail'] ?? '');
    }
}