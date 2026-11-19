<?php
namespace app\service;

use think\facade\Db;

/**
 * 存量附件迁移到 S3（v2.99.64）
 *
 * 把本机 uploads/ 与历史 webdav:// 附件搬到当前启用的 S3 桶，并把各表保存的
 * 路径 token 改写成 s3://<对象 key>。CLI（attach:migrate-s3）与后台
 * 「系统设置 → 附件存储 → 一键迁移」共用本服务，保证两条路径行为一致。
 *
 * 安全/行为约束：
 *   - 默认只扫描，不写库不上传（由调用方决定是否执行）；
 *   - 单条失败不中断整批，已成功的下次自动跳过（幂等）；
 *   - 本机源文件默认保留，显式开启后才在成功后删除；
 *   - WebDAV 源对象始终保留，避免不可逆的远端删除；
 *   - 历史 webdav:// 读取使用 WebDavService::storedConfig()，不要求当前存储是
 *     webdav —— 迁移时当前存储通常已经是 s3。
 */
class AttachMigrateService
{
    /** 单个批次最多处理条数（前端一键迁移用） */
    public const BATCH_SIZE = 5;

    /** 单个批次最长耗时（秒），避免 HTTP 请求超时 */
    public const BATCH_SECONDS = 20;

    /**
     * 需要扫描的 [表名 => [路径列...]]
     */
    public static function targets(): array
    {
        return [
            'client_company'               => ['attach_path'],
            'staff'                        => ['attach_path'],
            'certificate'                  => ['attach_path'],
            'contract'                     => ['attach_path'],
            'company_qualification'        => ['attach_path'],
            'certificate_attach'           => ['attach_path'],
            'staff_attach'                 => ['attach_path'],
            'company_attach'               => ['attach_path'],
            'company_qualification_attach' => ['attach_path'],
            'exam_signup_material'         => ['file_path'],
            'edu_signup_attach'            => ['file_path'],
            'edu_signup'                   => ['attach_path'],
        ];
    }

    /**
     * 扫描待迁移附件
     *
     * @param string $only ''=全部 / local / webdav
     * @return array{
     *   items: array<int,array{table:string,col:string,id:int,path:string,kind:string}>,
     *   counts: array{local:int,webdav:int,other:int,s3:int},
     *   skipped: array<int,string>
     * }
     */
    public static function scan(string $only = ''): array
    {
        $only = strtolower(trim($only));
        $items = [];
        $counts = ['local' => 0, 'webdav' => 0, 'other' => 0, 's3' => 0];
        $skipped = [];

        foreach (self::targets() as $table => $columns) {
            if (!self::tableExists($table)) {
                $skipped[] = '表 ' . $table . ' 不存在';
                continue;
            }
            foreach ($columns as $col) {
                if (!self::columnExists($table, $col)) {
                    continue;
                }
                // 已迁移的只统计数量，不读取全部行
                $counts['s3'] += (int)Db::name($table)
                    ->where($col, '<>', '')
                    ->whereLike($col, S3StorageService::PREFIX . '%')
                    ->count();

                $rows = Db::name($table)
                    ->where($col, '<>', '')
                    ->whereNotLike($col, S3StorageService::PREFIX . '%')
                    ->field('id,' . $col)
                    ->select()->toArray();
                foreach ($rows as $row) {
                    $path = (string)($row[$col] ?? '');
                    $kind = self::classify($path);
                    if (!isset($counts[$kind])) {
                        $counts[$kind] = 0;
                    }
                    $counts[$kind]++;
                    if ($kind !== 'local' && $kind !== 'webdav') {
                        continue;
                    }
                    if ($only !== '' && $kind !== $only) {
                        continue;
                    }
                    $items[] = [
                        'table' => $table,
                        'col'   => $col,
                        'id'    => (int)$row['id'],
                        'path'  => $path,
                        'kind'  => $kind,
                    ];
                }
            }
        }

        return ['items' => $items, 'counts' => $counts, 'skipped' => $skipped];
    }

    /** 判定路径来源：local 本机 / webdav 远端 / s3 已是目标 / other 外链等 */
    public static function classify(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return 'other';
        }
        if (stripos($path, S3StorageService::PREFIX) === 0) {
            return 's3';
        }
        if (stripos($path, WebDavService::PREFIX) === 0) {
            return 'webdav';
        }
        if (preg_match('#^https?://#i', $path)) {
            return 'other'; // 外部直链，不归本系统存储管
        }
        return 'local';
    }

    /**
     * 迁移单条附件，成功时同步回写数据库路径列
     *
     * @param array $item {table,col,id,path,kind}
     * @return array{ok:bool,token:string,msg:string}
     */
    public static function migrateOne(array $item, bool $deleteSource = false): array
    {
        $table = (string)($item['table'] ?? '');
        $col   = (string)($item['col'] ?? '');
        $id    = (int)($item['id'] ?? 0);
        $path  = (string)($item['path'] ?? '');
        $kind  = (string)($item['kind'] ?? self::classify($path));

        if ($table === '' || $col === '' || $id <= 0 || $path === '') {
            return ['ok' => false, 'token' => '', 'msg' => '迁移项参数不完整'];
        }

        try {
            $r = $kind === 'local'
                ? self::migrateLocal($path)
                : ($kind === 'webdav'
                    ? self::migrateWebdav($path)
                    : ['ok' => false, 'token' => '', 'msg' => '不支持的附件来源：' . $kind]);
            if (!$r['ok']) {
                return $r;
            }
            Db::name($table)->where('id', $id)->update([$col => $r['token']]);
            // 路径回写成功后才删除本机源文件，避免库还没改就丢源（WebDAV 源始终保留）。
            if ($deleteSource && $kind === 'local' && !empty($r['abs'])) {
                @unlink($r['abs']);
            }
            unset($r['abs']);
            return $r;
        } catch (\Throwable $e) {
            return ['ok' => false, 'token' => '', 'msg' => '迁移异常：' . $e->getMessage()];
        }
    }

    /** 本机 uploads/ 文件 → S3 */
    private static function migrateLocal(string $path): array
    {
        $rel = ltrim(str_replace('\\', '/', $path), '/');
        // 兼容 /uploads/x 与 uploads/x 两种历史写法
        $rel = preg_replace('#^/?uploads/#', '', $rel);
        $abs = public_path() . 'uploads' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (!is_file($abs)) {
            return ['ok' => false, 'token' => '', 'msg' => '本机文件不存在：' . $abs];
        }
        $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
        $safeName = self::safeName($ext);
        $put = S3StorageService::put($abs, $safeName, $ext);
        if (empty($put['ok'])) {
            return ['ok' => false, 'token' => '', 'msg' => $put['msg']];
        }
        return ['ok' => true, 'token' => $put['path'], 'msg' => 'ok', 'abs' => $abs];
    }

    /** WebDAV 历史对象 → 读回流 → S3（源对象始终保留） */
    private static function migrateWebdav(string $path): array
    {
        $cfg = WebDavService::storedConfig();
        if (!$cfg) {
            return ['ok' => false, 'token' => '', 'msg' => 'WebDAV 未配置：请到「系统设置 → 附件存储 → WebDAV」填写原 WebDAV 地址与账号（当前存储可保持 S3），保存后重跑'];
        }
        $rel = substr($path, strlen(WebDavService::PREFIX));
        $get = WebDavService::request($cfg, 'GET', $rel);
        if (empty($get['ok'])) {
            return ['ok' => false, 'token' => '', 'msg' => '读取 WebDAV 失败：' . ($get['msg'] ?? '未知')];
        }
        $tmp = tempnam(runtime_path(), 'mig_');
        if ($tmp === false) {
            return ['ok' => false, 'token' => '', 'msg' => '无法创建临时文件'];
        }
        try {
            file_put_contents($tmp, (string)$get['body']);
            $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
            $put = S3StorageService::put($tmp, self::safeName($ext), $ext);
        } finally {
            @unlink($tmp);
        }
        if (empty($put['ok'])) {
            return ['ok' => false, 'token' => '', 'msg' => $put['msg']];
        }
        // WebDAV 源对象刻意保留，避免不可逆的远端删除
        return ['ok' => true, 'token' => $put['path'], 'msg' => 'ok'];
    }

    /** 与上传统一命名：时间 + 随机 + 白名单扩展名 */
    private static function safeName(string $ext): string
    {
        return date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . ($ext !== '' ? '.' . $ext : '');
    }

    /** 当前是否已保存可用的 WebDAV 地址/账号（不要求当前存储是 webdav） */
    public static function webdavReady(): bool
    {
        return WebDavService::storedConfig() !== null;
    }

    public static function tableExists(string $table): bool
    {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }
        try {
            $real = Db::name($table)->getTable();
            $db = (string)config('database.connections.mysql.database');
            $row = Db::query(
                'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1',
                [$db, $real]
            );
            return $cache[$table] = !empty($row);
        } catch (\Throwable $e) {
            return $cache[$table] = false;
        }
    }

    public static function columnExists(string $table, string $col): bool
    {
        static $cache = [];
        $key = $table . '.' . $col;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        try {
            $real = Db::name($table)->getTable();
            $db = (string)config('database.connections.mysql.database');
            $rows = Db::query(
                'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
                [$db, $real, $col]
            );
            return $cache[$key] = !empty($rows);
        } catch (\Throwable $e) {
            return $cache[$key] = false;
        }
    }
}
