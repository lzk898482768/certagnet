<?php
namespace app\service;

use think\facade\Db;

/**
 * 通用多附件服务（v2.35）
 *
 * 与「证书多附件」（CertAttachService / certificate_attach）同构，用于把
 * 「多附件 + 可命名 + 应用内预览」的能力扩展到 **人员档案** 与 **客户企业**。
 *
 * 存储策略（与证书一致，保持全仓库统一心智）：
 *   新建子表 staff_attach / company_attach（1:N）；
 *   父表 staff.attach_path / client_company.attach_path **保留**并写第一条（兼容列表/导出）。
 *
 * 读写双轨、只增不改、零丢失：
 *   读：子表有记录 → 返回子表；子表空 && 父表 attach_path 非空 → 合成**虚拟记录**，
 *       保证历史（单附件时代）数据仍可预览，不做数据迁移。
 *   写：DELETE 子表 → 逐条 INSERT → UPDATE 父表 attach_path = 第一条（全量覆盖，天然幂等）。
 *
 * ⚠️ 安全校验（扩展名白名单 + 真实 MIME + 大小）统一复用 CertAttachService，
 *    避免在多个模块各写一份白名单造成不一致（安全关键）。
 * ⚠️ 不写事务，事务边界由调用方控制。
 */
class AttachService
{
    /** 单个归属对象的附件数软上限 */
    public const MAX_PER_OWNER = 20;

    /**
     * module => [子表名, 归属列, 父表名]
     */
    public const MAP = [
        'staff'         => ['staff_attach',   'staff_id',   'staff'],
        'company'       => ['company_attach', 'company_id', 'client_company'],
        // v2.94：企业证书（安许 / 资质）多附件
        'company_cert'  => ['company_qualification_attach', 'qualification_id', 'company_qualification'],
    ];

    /** 支持的模块列表 */
    public static function modules(): array
    {
        return array_keys(self::MAP);
    }

    /**
     * 读某归属对象的全部附件（含历史虚拟记录兜底）
     *
     * @return array<int,array{id:int,attach_name:string,attach_path:string,url:string,file_ext:string,file_size:int,sort:int,is_virtual:int,previewable:int}>
     */
    public static function listBy(string $module, int $ownerId): array
    {
        $cfg = self::MAP[$module] ?? null;
        if (!$cfg || $ownerId <= 0) {
            return [];
        }
        [$table, $col, $parent] = $cfg;

        try {
            $list = Db::name($table)
                ->where($col, $ownerId)
                ->order('sort', 'asc')
                ->order('id', 'asc')
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            $list = []; // 子表缺失时降级走虚拟记录
        }

        if ($list) {
            return array_map(static function (array $r) {
                $r['id']          = (int)$r['id'];
                $r['attach_name'] = (string)$r['attach_name'];
                $r['attach_path'] = (string)$r['attach_path'];
                $r['url']         = CertAttachService::url((string)$r['attach_path']);
                $r['file_ext']    = (string)$r['file_ext'];
                $r['file_size']   = (int)$r['file_size'];
                $r['sort']        = (int)$r['sort'];
                $r['is_virtual']  = 0;
                $r['previewable'] = in_array(strtolower($r['file_ext']), CertAttachService::PREVIEW_EXT, true) ? 1 : 0;
                return $r;
            }, $list);
        }

        // 兜底：子表无记录但父表 attach_path 非空 → 合成虚拟记录
        try {
            $row = Db::name($parent)->where('id', $ownerId)->field('attach_path')->find();
        } catch (\Throwable $e) {
            $row = null;
        }
        $path = (string)($row['attach_path'] ?? '');
        if ($path === '') {
            return [];
        }
        return [self::virtualRecord($path)];
    }

    /**
     * 全量覆盖某归属对象的附件（DELETE → 逐条 INSERT → 同步父表 attach_path）
     *
     * ⚠️ 不开事务（由调用方控制）。调用方需先 validateAll()。
     *
     * @param array<int,array> $items 每项须含 attach_path；attach_name 可选
     * @return int 实际写入条数
     */
    public static function replace(string $module, int $ownerId, array $items, int $userId): int
    {
        $cfg = self::MAP[$module] ?? null;
        if (!$cfg || $ownerId <= 0) {
            return 0;
        }
        [$table, $col, $parent] = $cfg;

        $clean = [];
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $path = trim((string)($it['attach_path'] ?? ''));
            if ($path === '') {
                continue;
            }
            $ext = strtolower((string)($it['file_ext'] ?? ''));
            if ($ext === '') {
                $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
            }
            $name = trim((string)($it['attach_name'] ?? ''));
            if ($name === '') {
                $name = basename($path);
            }
            $clean[] = [
                'attach_name' => mb_substr($name, 0, 128),
                'attach_path' => mb_substr($path, 0, 255),
                'file_ext'    => substr($ext, 0, 16),
                'file_size'   => (int)($it['file_size'] ?? 0),
                'sort'        => count($clean),
            ];
        }
        if (count($clean) > self::MAX_PER_OWNER) {
            $clean = array_slice($clean, 0, self::MAX_PER_OWNER);
        }

        Db::name($table)->where($col, $ownerId)->delete();
        $now = date('Y-m-d H:i:s');
        foreach ($clean as $row) {
            Db::name($table)->insert([
                $col          => $ownerId,
                'attach_name' => $row['attach_name'],
                'attach_path' => $row['attach_path'],
                'file_ext'    => $row['file_ext'],
                'file_size'   => max(0, $row['file_size']),
                'sort'        => $row['sort'],
                'create_by'   => $userId,
                'create_time' => $now,
            ]);
        }

        // 派生态：父表 attach_path 永远等于第一条（兼容列表/导出）
        try {
            Db::name($parent)->where('id', $ownerId)->update([
                'attach_path' => $clean[0]['attach_path'] ?? '',
            ]);
        } catch (\Throwable $e) {
            // 父表无 attach_path 列时忽略（老库未自愈），不影响子表写入
        }

        return count($clean);
    }

    /**
     * 校验一组附件（数量 + 逐条类型/大小），复用证书附件白名单
     *
     * @return array{ok:bool,msg:string}
     */
    public static function validateAll(array $items): array
    {
        return CertAttachService::validateAll($items);
    }

    /**
     * 批量挂载附件到列表（列表用），并补 attach_count
     *
     * @param array $list 引用传入，每项需含 id
     */
    public static function attachList(string $module, array &$list): void
    {
        $cfg = self::MAP[$module] ?? null;
        if (!$cfg || !$list) {
            return;
        }
        [$table, $col] = $cfg;

        $ids = array_values(array_filter(array_map(static fn ($r) => (int)($r['id'] ?? 0), $list)));
        if (!$ids) {
            return;
        }

        try {
            $rows = Db::name($table)->whereIn($col, $ids)
                ->order('sort', 'asc')->order('id', 'asc')->select()->toArray();
        } catch (\Throwable $e) {
            $rows = [];
        }

        $map = [];
        foreach ($rows as $r) {
            $oid = (int)$r[$col];
            $r['id']          = (int)$r['id'];
            $r['attach_path'] = (string)$r['attach_path'];
            $r['url']         = CertAttachService::url($r['attach_path']);
            $r['file_size']   = (int)$r['file_size'];
            $r['previewable'] = in_array(strtolower((string)$r['file_ext']), CertAttachService::PREVIEW_EXT, true) ? 1 : 0;
            $map[$oid][] = $r;
        }

        foreach ($list as &$item) {
            $oid = (int)($item['id'] ?? 0);
            $item['attachments']  = $map[$oid] ?? [];
            $item['attach_count'] = count($item['attachments']);
        }
        unset($item);
    }

    /**
     * 合成一条「虚拟附件记录」（子表空 + 父表 attach_path 非空时兜底）
     */
    private static function virtualRecord(string $path): array
    {
        $ext  = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
        $name = basename($path);
        return [
            'id'          => 0,
            'attach_name' => $name === '' ? '历史附件' : $name,
            'attach_path' => $path,
            'url'         => CertAttachService::url($path),
            'file_ext'    => $ext,
            'file_size'   => 0,
            'sort'        => 0,
            'is_virtual'  => 1,
            'previewable' => in_array($ext, CertAttachService::PREVIEW_EXT, true) ? 1 : 0,
        ];
    }
}
