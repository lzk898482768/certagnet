<?php
namespace app\service;

use think\facade\Db;

/**
 * 核心资源共享/公海归属服务（v2.99.37 P3）
 *
 * 唯一写入口：所有对 staff / client_company / certificate 归属的变更都必须经过本服务，
 * 由本服务在事务内保证三者一致：
 *   ① resource_owner 归属行
 *   ② 三主表 is_pool 公海标记
 *   ③ 三主表 owner_user_id 冗余（主所有人，兼容旧列表/导出）
 *
 * 语义：
 *  - 公海 = 没有任何所有人（resource_owner 无行，is_pool=1，owner_user_id=0）；
 *  - 共有 = 同一资源多条 resource_owner（is_primary=1 的那条回写 owner_user_id）；
 *  - 最后一个所有人退出 → 自动转公海，避免出现「无人负责但也不在公海」的孤儿数据。
 */
class ResourceOwnerService
{
    /** 业务模块 => 主表 */
    public const TABLES = [
        'staff'       => 'staff',
        'company'     => 'client_company',
        'certificate' => 'certificate',
    ];

    /** 归属变更动作 */
    public const ACTIONS = ['assign', 'transfer', 'claim', 'to_pool', 'duplicate', 'remove'];

    private static bool $ready = false;

    /* ============================ 基础 ============================ */

    public static function ensure(): void
    {
        if (self::$ready) {
            return;
        }
        DataScopeSchemaService::ensure();
        self::$ready = true;
    }

    public static function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        if ($type === 'client_company' || $type === 'clientcompany') {
            return 'company';
        }
        if ($type === 'cert') {
            return 'certificate';
        }
        return isset(self::TABLES[$type]) ? $type : '';
    }

    public static function table(string $type): string
    {
        return self::TABLES[self::normalizeType($type)] ?? '';
    }

    /** 账号 id => 展示名（含停用账号，历史归属仍需展示） */
    public static function userMap(): array
    {
        $map = [];
        try {
            foreach (Db::name('sys_user')->field('id,username,real_name')->select()->toArray() as $u) {
                $name = trim((string)($u['real_name'] ?? ''));
                $map[(int)$u['id']] = $name !== '' ? $name : (string)$u['username'];
            }
        } catch (\Throwable $e) {
            // 忽略
        }
        return $map;
    }

    /** 某资源的全部所有人（含展示名，主所有人排最前） */
    public static function owners(string $type, int $resId): array
    {
        self::ensure();
        $type = self::normalizeType($type);
        if ($type === '' || $resId <= 0) {
            return [];
        }
        $rows = Db::name('resource_owner')
            ->where('res_type', $type)
            ->where('res_id', $resId)
            ->order('is_primary', 'desc')
            ->order('id', 'asc')
            ->select()->toArray();
        if (!$rows) {
            return [];
        }
        $map = self::userMap();
        foreach ($rows as &$row) {
            $uid = (int)$row['user_id'];
            $row['user_id']   = $uid;
            $row['res_id']    = (int)$row['res_id'];
            $row['is_primary'] = (int)$row['is_primary'];
            $row['user_name'] = $map[$uid] ?? ('已删除账号#' . $uid);
        }
        unset($row);
        return $rows;
    }

    /** @return int[] */
    public static function ownerIds(string $type, int $resId): array
    {
        self::ensure();
        $type = self::normalizeType($type);
        if ($type === '' || $resId <= 0) {
            return [];
        }
        return array_map('intval', Db::name('resource_owner')
            ->where('res_type', $type)->where('res_id', $resId)->column('user_id'));
    }

    public static function isPool(string $type, int $resId): bool
    {
        self::ensure();
        $table = self::table($type);
        if ($table === '' || $resId <= 0) {
            return false;
        }
        return (int)Db::name($table)->where('id', $resId)->value('is_pool') === 1;
    }

    /**
     * 给列表/单行附加归属信息：owner_list / owner_names / owner_count / is_pool
     */
    public static function attachOwners(array &$list, string $type): void
    {
        self::ensure();
        $type = self::normalizeType($type);
        if ($type === '' || !$list) {
            return;
        }
        $single = isset($list['id']) || isset($list['owner_user_id']);
        if ($single) {
            $list = [$list];
        }
        $ids = [];
        foreach ($list as $row) {
            if (isset($row['id'])) {
                $ids[] = (int)$row['id'];
            }
        }
        $grouped = [];
        if ($ids) {
            $rows = Db::name('resource_owner')
                ->where('res_type', $type)
                ->whereIn('res_id', array_values(array_unique($ids)))
                ->order('is_primary', 'desc')
                ->order('id', 'asc')
                ->select()->toArray();
            $map = self::userMap();
            foreach ($rows as $row) {
                $rid = (int)$row['res_id'];
                $grouped[$rid][] = [
                    'user_id'    => (int)$row['user_id'],
                    'user_name'  => $map[(int)$row['user_id']] ?? ('已删除账号#' . (int)$row['user_id']),
                    'is_primary' => (int)$row['is_primary'],
                    'source'     => (string)$row['source'],
                ];
            }
        }
        foreach ($list as &$row) {
            $rid = (int)($row['id'] ?? 0);
            $owners = $grouped[$rid] ?? [];
            $row['owner_list']  = $owners;
            $row['owner_count'] = count($owners);
            $row['owner_names'] = implode('、', array_column($owners, 'user_name'));
            if (!isset($row['is_pool'])) {
                $row['is_pool'] = $owners ? 0 : 1;
            }
            $row['is_pool'] = (int)$row['is_pool'];
        }
        unset($row);
        if ($single) {
            $list = $list[0];
        }
    }

    /* ============================ 可见性 ============================ */

    /**
     * 可见资源 id 集合；null = 不限。
     * @return int[]|null
     */
    public static function visibleCoreIds(string $type, array $user): ?array
    {
        self::ensure();
        $type = self::normalizeType($type);
        if ($type === '' || ($user['role_code'] ?? '') === 'admin') {
            return null;
        }
        $policy = DataScopeService::resolvePolicy($user, DataScopeService::GROUP_CORE);
        if (!empty($policy['all'])) {
            return null;
        }
        $uid = (int)($user['id'] ?? 0);
        $owned = $uid > 0
            ? array_map('intval', Db::name('resource_owner')->where('res_type', $type)->where('user_id', $uid)->column('res_id'))
            : [];
        $includePool = $policy['scope'] === 'pool' || !empty($policy['include_pool']);
        if (!$includePool) {
            return $owned;
        }
        $poolIds = array_map('intval', Db::name(self::table($type))->where('is_pool', 1)->column('id'));
        return array_values(array_unique(array_merge($poolIds, $owned)));
    }

    /** 筛选「某人是所有人」的资源（mine / owner_user_id 条件，支持多人共有） */
    public static function scopeByOwner($query, string $type, int $userId, string $pk = 'id')
    {
        self::ensure();
        $type = self::normalizeType($type);
        if ($type === '' || $userId <= 0) {
            return $query->where($pk, 0);
        }
        $roTable = Db::name('resource_owner')->getTable();
        return $query->whereRaw(
            "`{$pk}` IN (SELECT `res_id` FROM `{$roTable}` WHERE `res_type` = ? AND `user_id` = ?)",
            [$type, $userId]
        );
    }

    /** 列表注入：all 不限；pool = 公海 OR 本人共有；self = 仅本人共有 */
    public static function applyScope($query, array $user, string $type, string $pk = 'id')
    {
        self::ensure();
        $type = self::normalizeType($type);
        if ($type === '' || ($user['role_code'] ?? '') === 'admin') {
            return $query;
        }
        $policy = DataScopeService::resolvePolicy($user, DataScopeService::GROUP_CORE);
        if (!empty($policy['all'])) {
            return $query;
        }
        $uid = (int)($user['id'] ?? 0);
        $owned = $uid > 0
            ? array_map('intval', Db::name('resource_owner')->where('res_type', $type)->where('user_id', $uid)->column('res_id'))
            : [];
        $includePool = $policy['scope'] === 'pool' || !empty($policy['include_pool']);

        return $query->where(function ($w) use ($includePool, $owned, $pk) {
            if ($includePool) {
                $w->where('is_pool', 1);
            }
            if ($owned) {
                $w->whereOr($pk, 'in', $owned);
            }
            if (!$includePool && !$owned) {
                $w->where($pk, 0);
            }
        });
    }

    /** 对象级可见：admin/all 直通；否则本人在归属表，或（允许公海时）目标在公海 */
    public static function canAccess(string $type, int $resId, array $user): bool
    {
        self::ensure();
        $type = self::normalizeType($type);
        if ($type === '' || $resId <= 0) {
            return false;
        }
        if (($user['role_code'] ?? '') === 'admin') {
            return true;
        }
        $policy = DataScopeService::resolvePolicy($user, DataScopeService::GROUP_CORE);
        if (!empty($policy['all'])) {
            return true;
        }
        $uid = (int)($user['id'] ?? 0);
        if ($uid > 0 && Db::name('resource_owner')->where('res_type', $type)->where('res_id', $resId)->where('user_id', $uid)->count() > 0) {
            return true;
        }
        $includePool = $policy['scope'] === 'pool' || !empty($policy['include_pool']);
        return $includePool && self::isPool($type, $resId);
    }

    /** 是否现所有人（不含公海认领权） */
    public static function isOwner(string $type, int $resId, int $userId): bool
    {
        self::ensure();
        $type = self::normalizeType($type);
        return $type !== '' && $resId > 0 && $userId > 0
            && Db::name('resource_owner')->where('res_type', $type)->where('res_id', $resId)->where('user_id', $userId)->count() > 0;
    }

    /* ============================ 写入操作 ============================ */

    /**
     * 增加共同所有人（不清除原所有人）。首个所有人自动成为主所有人。
     */
    public static function addOwner(string $type, int $resId, int $userId, string $source = 'assign', int $operatorId = 0, bool $makePrimary = false): bool
    {
        self::ensure();
        $type = self::normalizeType($type);
        $table = self::table($type);
        if ($table === '' || $resId <= 0 || $userId <= 0) {
            return false;
        }
        if (!Db::name($table)->where('id', $resId)->find()) {
            return false;
        }
        if (!Db::name('sys_user')->where('id', $userId)->where('status', 1)->find()) {
            return false;
        }
        return (bool)Db::transaction(function () use ($type, $resId, $userId, $source, $operatorId, $makePrimary) {
            $exists = Db::name('resource_owner')
                ->where('res_type', $type)->where('res_id', $resId)->where('user_id', $userId)->find();
            $count = Db::name('resource_owner')->where('res_type', $type)->where('res_id', $resId)->count();
            $primary = $makePrimary || $count === 0;
            if ($primary) {
                Db::name('resource_owner')->where('res_type', $type)->where('res_id', $resId)->update(['is_primary' => 0]);
            }
            if ($exists) {
                if ($primary) {
                    Db::name('resource_owner')->where('id', (int)$exists['id'])->update(['is_primary' => 1]);
                }
            } else {
                Db::name('resource_owner')->insert([
                    'res_type'    => $type,
                    'res_id'      => $resId,
                    'user_id'     => $userId,
                    'is_primary'  => $primary ? 1 : 0,
                    'source'      => in_array($source, self::ACTIONS, true) ? $source : 'assign',
                    'created_by'  => $operatorId,
                    'create_time' => date('Y-m-d H:i:s'),
                ]);
            }
            self::syncFlags($type, $resId);
            return true;
        });
    }

    /**
     * 批量增加共同所有人（保留每条记录原有所有人，只新增，不清空）。
     *
     * 规则：
     *  - 对每个资源，按 $userIds 顺序逐个 addOwner：
     *    · 已是该资源所有人的账号自动跳过（不计成功也不计失败）；
     *    · 公海资源（无任何所有人）：第一个新增账号自动成为主所有人，资源离开公海；
     *    · 已有所有人的资源：新增账号均为共有人，不改变原主所有人。
     *  - 单条资源在一个事务内处理，单条失败不影响其他资源，失败原因汇总返回。
     *
     * @param int[]   $ids        资源 id 集合
     * @param int[]   $userIds    要新增为共同所有人的账号（顺序即主所有人优先级）
     * @param int     $operatorId 操作人
     * @param int[]|null $allowedIds 已通过可见性校验的 id 白名单；null 表示不限制（admin）
     * @return array{ok:int,added:int,skipped:int,fail:string[]}
     */
    public static function addOwners(string $type, array $ids, array $userIds, int $operatorId, ?array $allowedIds = null): array
    {
        self::ensure();
        $type = self::normalizeType($type);
        $table = self::table($type);
        if ($table === '') {
            return ['ok' => 0, 'added' => 0, 'skipped' => 0, 'fail' => ['未知资源类型']];
        }
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), fn($v) => $v > 0)));
        if (!$userIds) {
            return ['ok' => 0, 'added' => 0, 'skipped' => 0, 'fail' => ['请选择至少一个共同所有人']];
        }
        // 仅保留启用中的账号
        $validUsers = Db::name('sys_user')->whereIn('id', $userIds)->where('status', 1)->column('id');
        $validUsers = array_map('intval', $validUsers);
        $userIds = array_values(array_filter($userIds, fn($u) => in_array($u, $validUsers, true)));
        if (!$userIds) {
            return ['ok' => 0, 'added' => 0, 'skipped' => 0, 'fail' => ['所选账号不存在或已停用']];
        }

        $allowed = $allowedIds === null ? null : array_map('intval', $allowedIds);
        $ok = 0;
        $added = 0;
        $skipped = 0;
        $fail = [];

        foreach ($ids as $idv) {
            $id = (int)$idv;
            if ($id <= 0) {
                continue;
            }
            if ($allowed !== null && !in_array($id, $allowed, true)) {
                $fail[] = "#{$id}：无权操作该记录";
                continue;
            }
            if (!Db::name($table)->where('id', $id)->find()) {
                $fail[] = "#{$id}：记录不存在";
                continue;
            }
            try {
                $resAdded = 0;
                Db::transaction(function () use ($type, $id, $userIds, $operatorId, &$resAdded) {
                    $existing = self::ownerIds($type, $id);
                    $wasPool = count($existing) === 0;
                    foreach ($userIds as $idx => $uid) {
                        if (in_array($uid, $existing, true)) {
                            continue; // 已是所有人，幂等跳过
                        }
                        // 公海资源的第一个新增账号设为主所有人；其余均为共有人
                        $makePrimary = $wasPool && $idx === 0;
                        $done = self::addOwner($type, $id, $uid, 'assign', $operatorId, $makePrimary);
                        if ($done) {
                            $resAdded++;
                            $existing[] = $uid;
                            self::log($type, $id, 0, $uid, $operatorId, 'assign', false, '批量增加共同所有人');
                        }
                    }
                });
                if ($resAdded > 0) {
                    $ok++;
                    $added += $resAdded;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $fail[] = "#{$id}：增加共有人失败（" . $e->getMessage() . '）';
            }
        }
        return ['ok' => $ok, 'added' => $added, 'skipped' => $skipped, 'fail' => $fail];
    }

    /**
     * 退出共有；最后一个所有人退出 → 自动转公海。
     */
    public static function removeOwner(string $type, int $resId, int $userId, int $operatorId = 0, string $remark = ''): bool
    {
        self::ensure();
        $type = self::normalizeType($type);
        if ($type === '' || $resId <= 0 || $userId <= 0) {
            return false;
        }
        return (bool)Db::transaction(function () use ($type, $resId, $userId, $operatorId, $remark) {
            $row = Db::name('resource_owner')
                ->where('res_type', $type)->where('res_id', $resId)->where('user_id', $userId)->find();
            if (!$row) {
                return false;
            }
            $wasPrimary = (int)$row['is_primary'] === 1;
            Db::name('resource_owner')->where('id', (int)$row['id'])->delete();
            if ($wasPrimary) {
                $next = Db::name('resource_owner')->where('res_type', $type)->where('res_id', $resId)->order('id', 'asc')->find();
                if ($next) {
                    Db::name('resource_owner')->where('id', (int)$next['id'])->update(['is_primary' => 1]);
                }
            }
            self::syncFlags($type, $resId);
            $left = Db::name('resource_owner')->where('res_type', $type)->where('res_id', $resId)->count();
            self::log($type, $resId, $userId, 0, $operatorId, $left === 0 ? 'to_pool' : 'remove', $left === 0, $remark);
            return true;
        });
    }

    /** 资源移入公海（清空全部所有人） */
    public static function toPool(string $type, int $resId, int $operatorId, string $remark = ''): bool
    {
        self::ensure();
        $type = self::normalizeType($type);
        $table = self::table($type);
        if ($table === '' || $resId <= 0 || !Db::name($table)->where('id', $resId)->find()) {
            return false;
        }
        return (bool)Db::transaction(function () use ($type, $resId, $operatorId, $remark, $table) {
            $from = (int)(Db::name($table)->where('id', $resId)->value('owner_user_id') ?: 0);
            Db::name('resource_owner')->where('res_type', $type)->where('res_id', $resId)->delete();
            Db::name($table)->where('id', $resId)->update(['is_pool' => 1, 'owner_user_id' => 0]);
            self::log($type, $resId, $from, 0, $operatorId, 'to_pool', true, $remark);
            return true;
        });
    }

    /** 公海认领：仅公海资源可认领，认领人成为主所有人 */
    public static function claim(string $type, int $resId, int $userId, int $operatorId = 0): array
    {
        self::ensure();
        $type = self::normalizeType($type);
        $table = self::table($type);
        if ($table === '' || $resId <= 0) {
            return ['ok' => false, 'msg' => '参数错误'];
        }
        if (!Db::name($table)->where('id', $resId)->find()) {
            return ['ok' => false, 'msg' => '资源不存在'];
        }
        if (!self::isPool($type, $resId)) {
            return ['ok' => false, 'msg' => '该资源已被认领，请刷新列表'];
        }
        if (!Db::name('sys_user')->where('id', $userId)->where('status', 1)->find()) {
            return ['ok' => false, 'msg' => '账号不可用'];
        }
        $ok = Db::transaction(function () use ($type, $resId, $userId, $operatorId) {
            Db::name('resource_owner')->where('res_type', $type)->where('res_id', $resId)->delete();
            Db::name('resource_owner')->insert([
                'res_type'    => $type,
                'res_id'      => $resId,
                'user_id'     => $userId,
                'is_primary'  => 1,
                'source'      => 'claim',
                'created_by'  => $operatorId ?: $userId,
                'create_time' => date('Y-m-d H:i:s'),
            ]);
            self::syncFlags($type, $resId);
            self::log($type, $resId, 0, $userId, $operatorId ?: $userId, 'claim', false, '公海认领');
            return true;
        });
        return $ok ? ['ok' => true, 'msg' => '已认领'] : ['ok' => false, 'msg' => '认领失败'];
    }

    /**
     * 批量移交。
     *  - admin：强制替换全部所有人（目标 0 = 公海）；
     *  - 非 admin：只交接「自己」的份额，保留其他共有人（目标 0 = 自己退出，最后一人自动转公海）。
     *
     * @param array|null $allowedIds 已通过可见性校验的 id 集合；null 表示不限制（admin）
     * @return array{ok:int,fail:string[]}
     */
    public static function transfer(string $type, array $ids, int $toUserId, int $operatorId, bool $isAdmin, string $remark = '', ?array $allowedIds = null, string $action = 'transfer'): array
    {
        self::ensure();
        $type = self::normalizeType($type);
        $table = self::table($type);
        if ($table === '') {
            return ['ok' => 0, 'fail' => ['未知资源类型']];
        }
        if ($toUserId > 0 && !Db::name('sys_user')->where('id', $toUserId)->where('status', 1)->find()) {
            return ['ok' => 0, 'fail' => ['目标账号不存在或已停用']];
        }
        $allowed = $allowedIds === null ? null : array_map('intval', $allowedIds);
        $ok = 0;
        $fail = [];
        foreach ($ids as $idv) {
            $id = (int)$idv;
            if ($id <= 0) {
                continue;
            }
            if ($allowed !== null && !in_array($id, $allowed, true)) {
                $fail[] = "#{$id}：无权操作该记录";
                continue;
            }
            $row = Db::name($table)->where('id', $id)->find();
            if (!$row) {
                $fail[] = "#{$id}：记录不存在";
                continue;
            }
            if (!$isAdmin && $toUserId > 0 && !self::isOwner($type, $id, $operatorId)) {
                $fail[] = "#{$id}：您不是该记录所有人，无法移交";
                continue;
            }
            if (!$isAdmin && $toUserId <= 0 && !self::isOwner($type, $id, $operatorId)) {
                $fail[] = "#{$id}：您不是该记录所有人，无法移入公海";
                continue;
            }
            if (!$isAdmin && $toUserId === $operatorId) {
                $fail[] = "#{$id}：目标已是您本人";
                continue;
            }
            try {
                Db::transaction(function () use ($type, $table, $id, $toUserId, $operatorId, $isAdmin, $remark, $action) {
                    $from = (int)(Db::name($table)->where('id', $id)->value('owner_user_id') ?: 0);
                    if ($toUserId <= 0) {
                        if ($isAdmin) {
                            // 管理员：强制清空全部所有人 → 公海
                            Db::name('resource_owner')->where('res_type', $type)->where('res_id', $id)->delete();
                            Db::name($table)->where('id', $id)->update(['is_pool' => 1, 'owner_user_id' => 0]);
                            self::log($type, $id, $from, 0, $operatorId, 'to_pool', true, $remark);
                        } else {
                            // 普通所有人：只退出自己的份额，保留其他共有人；最后一人退出才转公海
                            Db::name('resource_owner')->where('res_type', $type)->where('res_id', $id)
                                ->where('user_id', $operatorId)->delete();
                            self::syncFlags($type, $id);
                            $left = Db::name('resource_owner')->where('res_type', $type)->where('res_id', $id)->count();
                            self::log($type, $id, $operatorId, 0, $operatorId, $left === 0 ? 'to_pool' : 'remove', $left === 0, $remark);
                        }
                        return;
                    }
                    if ($isAdmin) {
                        // 管理员强制移交：替换全部共有人
                        Db::name('resource_owner')->where('res_type', $type)->where('res_id', $id)->delete();
                    } else {
                        // 普通所有人：只交接自己的份额
                        Db::name('resource_owner')->where('res_type', $type)->where('res_id', $id)->where('user_id', $operatorId)->delete();
                    }
                    $existingOwner = Db::name('resource_owner')
                        ->where('res_type', $type)->where('res_id', $id)->where('user_id', $toUserId)->find();
                    if ($existingOwner) {
                        Db::name('resource_owner')->where('id', (int)$existingOwner['id'])->update(['is_primary' => 1]);
                    } else {
                        Db::name('resource_owner')->insert([
                            'res_type'    => $type,
                            'res_id'      => $id,
                            'user_id'     => $toUserId,
                            'is_primary'  => 1,
                            'source'      => $isAdmin ? 'assign' : 'transfer',
                            'created_by'  => $operatorId,
                            'create_time' => date('Y-m-d H:i:s'),
                        ]);
                    }
                    // 旧主所有人可能已删除，确保只剩一个主所有人
                    Db::name('resource_owner')->where('res_type', $type)->where('res_id', $id)
                        ->where('user_id', '<>', $toUserId)->update(['is_primary' => 0]);
                    self::syncFlags($type, $id);
                    self::log($type, $id, $from, $toUserId, $operatorId, $isAdmin ? 'assign' : ($action ?: 'transfer'), false, $remark);
                });
                $ok++;
            } catch (\Throwable $e) {
                $fail[] = "#{$id}：移交失败（" . $e->getMessage() . '）';
            }
        }
        return ['ok' => $ok, 'fail' => $fail];
    }

    /**
     * 统一维护 resource_owner ↔ is_pool ↔ owner_user_id 一致性。
     */
    public static function syncFlags(string $type, int $resId): void
    {
        $type = self::normalizeType($type);
        $table = self::table($type);
        if ($table === '' || $resId <= 0) {
            return;
        }
        $rows = Db::name('resource_owner')
            ->where('res_type', $type)->where('res_id', $resId)
            ->order('is_primary', 'desc')->order('id', 'asc')
            ->select()->toArray();
        if (!$rows) {
            Db::name($table)->where('id', $resId)->update(['is_pool' => 1, 'owner_user_id' => 0]);
            return;
        }
        $primary = null;
        foreach ($rows as $row) {
            if ((int)$row['is_primary'] === 1) {
                $primary = $row;
                break;
            }
        }
        if (!$primary) {
            $primary = $rows[0];
            Db::name('resource_owner')->where('id', (int)$primary['id'])->update(['is_primary' => 1]);
        }
        Db::name($table)->where('id', $resId)->update([
            'is_pool'       => 0,
            'owner_user_id' => (int)$primary['user_id'],
        ]);
    }

    /** 审计：归属变更统一写 cert_owner_transfer */
    public static function log(string $type, int $resId, int $fromUserId, int $toUserId, int $operatorId, string $action, bool $toPool, string $remark = ''): void
    {
        try {
            $title = CrmService::titleOf($type, $resId);
            Db::name('cert_owner_transfer')->insert([
                'module'       => $type,
                'action'       => in_array($action, self::ACTIONS, true) ? $action : 'transfer',
                'to_pool'      => $toPool ? 1 : 0,
                'record_id'    => $resId,
                'title'        => mb_substr(trim($title), 0, 255),
                'from_user_id' => $fromUserId,
                'to_user_id'   => $toUserId,
                'operator_id'  => $operatorId,
                'remark'       => mb_substr(trim($remark), 0, 500),
                'create_time'  => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // 审计失败不阻断主流程
        }
    }
}
