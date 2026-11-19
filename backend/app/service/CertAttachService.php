<?php
namespace app\service;

use think\facade\Db;

/**
 * 证书附件服务（v2.32，多附件 / 可命名 / 可预览）
 *
 * 存储策略（D5）：新建 certificate_attach 子表（1:N），
 *   certificate.attach_path **保留**并写第一条（兼容导出/列表/H5 老消费点）。
 *
 * 读写双轨、只增不改、零丢失（预勘察 §2.1）：
 *   读：子表有记录 → 返回子表；子表空 && attach_path 非空 → 合成**虚拟记录**，
 *       保证历史附件仍可预览（不做数据迁移，避免双源不一致）。
 *   写：DELETE 子表 → 逐条 INSERT → UPDATE certificate.attach_path = attachments[0]
 *       （全量覆盖，天然幂等）
 *
 * ⚠️ 约定 C3：前端**不得**直接提交 attach_path，后端一律从 attachments[0] 派生并覆盖。
 * ⚠️ 约定 C13：文件类型必须走白名单，拒绝 php/jsp/exe/sh/py/html/svg/xml 等。
 * ⚠️ 数量：前端不设 limit（满足「不限数量」诉求），后端软上限 MAX_PER_CERT 防详情页被拖死。
 *
 * 本类**不写事务**，事务边界由调用方（Certificate::save）控制。
 */
class CertAttachService
{
    /** 单证书附件数软上限（前端不限，后端兜底） */
    public const MAX_PER_CERT = 20;

    /** 图片大小上限（字节） */
    public const MAX_IMAGE_SIZE = 5 * 1024 * 1024;   // 5MB

    /** PDF / 其他文档大小上限（字节） */
    public const MAX_DOC_SIZE = 10 * 1024 * 1024;     // 10MB

    /** 可在应用内预览的类型 */
    public const PREVIEW_EXT = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

    /** 允许上传的类型白名单 */
    public const ALLOW_EXT = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'zip'];

    /**
     * 扩展名 → 允许的真实 MIME 白名单（v2.32 L1 第二层：内容真伪校验）
     * 用于拦截「内容为 PHP/HTML 但扩展名伪装成 .jpg」的上传。
     * 缺省（未识别）时按「扩展名白名单已过」放行，避免误伤合法文件。
     */
    public const ALLOW_MIME = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'webp' => ['image/webp'],
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword', 'application/CDFV2', 'application/x-ole-storage'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls'  => ['application/vnd.ms-excel', 'application/CDFV2', 'application/x-ole-storage'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'txt'  => ['text/plain'],
        'zip'  => ['application/zip', 'application/x-zip-compressed'],
    ];

    /** 前端 accept 属性串（与 ALLOW_EXT 保持一致） */
    public const ACCEPT_STR = '.jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip';

    /**
     * 读某证书的全部附件（含历史虚拟记录兜底）
     *
     * @return array<int,array{id:int,attach_name:string,attach_path:string,file_ext:string,file_size:int,sort:int,is_virtual:int}>
     */
    public static function listByCert(int $certId): array
    {
        if ($certId <= 0) {
            return [];
        }
        try {
            $list = Db::name('certificate_attach')
                ->where('cert_id', $certId)
                ->order('sort', 'asc')
                ->order('id', 'asc')
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            $list = []; // 子表缺失时降级走虚拟记录
        }

        if ($list) {
            return array_map(static function (array $r) {
                $r['id']           = (int)$r['id'];
                $r['attach_name']  = (string)$r['attach_name'];
                $r['attach_path']  = (string)$r['attach_path'];
                $r['file_ext']     = (string)$r['file_ext'];
                $r['file_size']    = (int)$r['file_size'];
                $r['sort']         = (int)$r['sort'];
                $r['is_virtual']   = 0;
                $r['previewable']  = in_array(strtolower($r['file_ext']), self::PREVIEW_EXT, true) ? 1 : 0;
                return $r;
            }, $list);
        }

        // 兜底：子表无记录但 certificate.attach_path 非空 → 合成虚拟记录
        try {
            $cert = Db::name('certificate')
                ->where('id', $certId)
                ->field('id,attach_path')
                ->find();
        } catch (\Throwable $e) {
            $cert = null;
        }
        $path = (string)($cert['attach_path'] ?? '');
        if ($path === '') {
            return [];
        }
        return [self::virtualRecord($path)];
    }

    /**
     * 全量覆盖某证书的附件（DELETE → 逐条 INSERT → 同步 attach_path）
     *
     * ⚠️ 不开事务（由调用方控制）。调用方需先 validateAll()。
     *
     * @param array<int,array> $items 每项须含 attach_path；attach_name 可选
     * @param int              $userId
     * @return int 实际写入条数
     */
    public static function replace(int $certId, array $items, int $userId): int
    {
        if ($certId <= 0) {
            return 0;
        }
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
        if (count($clean) > self::MAX_PER_CERT) {
            $clean = array_slice($clean, 0, self::MAX_PER_CERT);
        }

        Db::name('certificate_attach')->where('cert_id', $certId)->delete();
        $now = date('Y-m-d H:i:s');
        foreach ($clean as $row) {
            Db::name('certificate_attach')->insert([
                'cert_id'     => $certId,
                'attach_name' => $row['attach_name'],
                'attach_path' => $row['attach_path'],
                'file_ext'    => $row['file_ext'],
                'file_size'   => max(0, $row['file_size']),
                'sort'        => $row['sort'],
                'create_by'   => $userId,
                'create_time' => $now,
            ]);
        }

        // 派生态：attach_path 永远等于第一条（兼容导出/列表/H5）
        Db::name('certificate')->where('id', $certId)->update([
            'attach_path' => $clean[0]['attach_path'] ?? '',
        ]);

        return count($clean);
    }

    /**
     * 校验一组附件（数量 + 逐条类型/大小）
     *
     * @param array<int,array> $items
     * @return array{ok:bool,msg:string}
     */
    public static function validateAll(array $items): array
    {
        $n = 0;
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $path = trim((string)($it['attach_path'] ?? ''));
            if ($path !== '') {
                $n++;
            }
        }
        if ($n === 0) {
            return ['ok' => true, 'msg' => ''];
        }
        if ($n > self::MAX_PER_CERT) {
            return ['ok' => false, 'msg' => "附件数量超出上限（最多 " . self::MAX_PER_CERT . " 个，当前 {$n} 个）"];
        }
        foreach ($items as $idx => $it) {
            if (!is_array($it)) {
                continue;
            }
            $path = trim((string)($it['attach_path'] ?? ''));
            if ($path === '') {
                continue;
            }
            $file = [
                'attach_path' => $path,
                'file_ext'    => $it['file_ext'] ?? '',
                'file_size'   => (int)($it['file_size'] ?? 0),
            ];
            $r = self::validate($file);
            if (!$r['ok']) {
                $name = trim((string)($it['attach_name'] ?? '')) ?: ('第' . ((int)$idx + 1) . '个附件');
                return ['ok' => false, 'msg' => "{$name}：" . $r['msg']];
            }
        }
        return ['ok' => true, 'msg' => ''];
    }

    /**
     * 校验单个文件：扩展名白名单 + 大小上限
     *
     * @param array $file 至少含 attach_path（或 file_ext）；file_size 可选（缺省不校验大小）
     * @return array{ok:bool,msg:string,ext:string}
     */
    public static function validate(array $file): array
    {
        $path = (string)($file['attach_path'] ?? $file['path'] ?? '');
        $ext  = strtolower(trim((string)($file['file_ext'] ?? '')));
        if ($ext === '') {
            $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
        }
        // 双保险：路径里可能带查询串/伪造双扩展名
        $pathExt = strtolower((string)pathinfo(parse_url($path, PHP_URL_PATH) ?: $path, PATHINFO_EXTENSION));
        if ($pathExt !== '' && $pathExt !== $ext) {
            // 以路径真实扩展名为准，防止「.png.php」绕过
            $ext = $pathExt;
        }

        if ($ext === '') {
            return ['ok' => false, 'msg' => '无法识别文件类型，已拒绝', 'ext' => ''];
        }
        if (!in_array($ext, self::ALLOW_EXT, true)) {
            return [
                'ok'   => false,
                'msg'  => "不支持的文件类型 .{$ext}（仅允许 " . implode('/', self::ALLOW_EXT) . '）',
                'ext'  => $ext,
            ];
        }

        $size = (int)($file['file_size'] ?? 0);
        if ($size > 0) {
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) && $size > self::MAX_IMAGE_SIZE) {
                return ['ok' => false, 'msg' => '图片大小不能超过 5MB', 'ext' => $ext];
            }
            if ($size > self::MAX_DOC_SIZE) {
                return ['ok' => false, 'msg' => '文件大小不能超过 10MB', 'ext' => $ext];
            }
        }

        // v2.32 L1 第二层：真实 MIME 校验（防「内容为 PHP/HTML 但扩展名伪装 .jpg」）
        $mime = strtolower(trim((string)($file['file_mime'] ?? $file['mime'] ?? '')));
        if ($mime !== '' && isset(self::ALLOW_MIME[$ext])) {
            // 归一化常见别名
            $mime = preg_replace('/;.*$/', '', $mime);
            $allowed = self::ALLOW_MIME[$ext];
            $okMime = in_array($mime, $allowed, true);
            // text/plain 的 txt 特例；octet-stream 无法判别时按扩展名放行（避免误伤）
            if (!$okMime && $mime === 'application/octet-stream') {
                $okMime = true;
            }
            if (!$okMime) {
                return [
                    'ok'  => false,
                    'msg' => "文件内容与扩展名不符（检测到 {$mime}，期望 " . implode('/', $allowed) . '），已拒绝',
                    'ext' => $ext,
                ];
            }
        }

        return ['ok' => true, 'msg' => '', 'ext' => $ext];
    }

    /**
     * 由路径推导附件的完整访问 URL
     */
    public static function url(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        // v2.46：WebDAV 附件 → 带签名的本站下载接口（服务器代理取流，凭据不出站）
        if (stripos($path, WebDavService::PREFIX) === 0) {
            return WebDavService::signedDownloadUrl($path);
        }
        // v2.99.48：S3 附件 → 同样走带签名的本站代理下载（AccessKey 不出服务器，桶无需公网读）
        if (stripos($path, S3StorageService::PREFIX) === 0) {
            return S3StorageService::signedDownloadUrl($path);
        }
        if (preg_match('#^(https?:)?//#i', $path)) {
            return $path;
        }
        $path = ltrim($path, '/');
        if (strpos($path, 'uploads/') === 0) {
            return '/' . $path;
        }
        return '/uploads/' . $path;
    }

    /**
     * 合成一条「虚拟附件记录」（子表空 + attach_path 非空时兜底）
     *
     * @return array{id:int,attach_name:string,attach_path:string,file_ext:string,file_size:int,sort:int,is_virtual:int,previewable:int}
     */
    private static function virtualRecord(string $path): array
    {
        $ext  = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
        $name = basename($path);
        return [
            'id'          => 0,
            'attach_name' => $name === '' ? '历史附件' : $name,
            'attach_path' => $path,
            'url'         => self::url($path),
            'file_ext'    => $ext,
            'file_size'   => 0,
            'sort'        => 0,
            'is_virtual'  => 1,
            'previewable' => in_array($ext, self::PREVIEW_EXT, true) ? 1 : 0,
        ];
    }
}