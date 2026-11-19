<?php
namespace app\service;

/**
 * 报名材料清单引擎（配置驱动）
 *
 * 移植自 exam-reg MaterialService，去除 ImageService 依赖，
 * 扩展名白名单对齐本系统通用上传（CertAttachService::ALLOW_EXT）。
 *
 * 材料项结构：[{ code, name, required, multiple, ext[], max_kb, type, tip, sort }]
 *  - type：id_front/id_back/edu/photo/file —— 仅用于默认扩展名与前端展示
 *  - multiple：是否允许同一材料项多份（默认 false＝重传即替换）
 *  - ext：允许扩展名（留空按 type 取默认）
 *  - max_kb：大小上限 KB（0＝不限制，后端上传仍有全局上限）
 */
class ExamMaterialService
{
    public const DEFAULT_CATEGORY = '其他';

    /** 本系统通用上传白名单（与 CertAttachService::ALLOW_EXT 保持一致） */
    public const ALLOW_EXT = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'zip'];

    /** 按类型给出默认允许扩展名 */
    public const DEFAULT_EXT = [
        'id_front' => ['jpg', 'jpeg', 'png', 'webp'],
        'id_back'  => ['jpg', 'jpeg', 'png', 'webp'],
        'edu'      => ['jpg', 'jpeg', 'png', 'webp', 'pdf'],
        'photo'    => ['jpg', 'jpeg', 'png', 'webp'],
        'file'     => ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'zip'],
    ];

    /** 归一化材料清单，保证结构完整 */
    public static function normalize($materials): array
    {
        if (is_string($materials)) {
            $materials = json_decode($materials, true) ?: [];
        }
        $materials = (array)$materials;
        if ($materials && !array_is_list($materials)) {
            $materials = [$materials];
        }
        $out = [];
        $i   = 0;
        foreach ($materials as $m) {
            if (!is_array($m)) {
                continue;
            }
            $i++;
            $type = self::normalizeType((string)($m['type'] ?? 'file'));
            $ext  = array_values(array_filter(array_map(
                fn($e) => self::safeExt((string)$e),
                (array)($m['ext'] ?? [])
            )));
            $out[] = [
                'code'     => (string)($m['code'] ?? ('item' . $i)),
                'name'     => (string)($m['name'] ?? '材料' . $i),
                'category' => trim((string)($m['category'] ?? '')) ?: self::DEFAULT_CATEGORY,
                'required' => (bool)($m['required'] ?? false),
                'multiple' => (bool)($m['multiple'] ?? false),
                'ext'      => $ext ?: (self::DEFAULT_EXT[$type] ?? self::DEFAULT_EXT['file']),
                'max_kb'   => max(0, (int)($m['max_kb'] ?? 0)),
                'type'     => $type,
                'tip'      => (string)($m['tip'] ?? ''),
                'sort'     => (int)($m['sort'] ?? $i),
            ];
        }
        usort($out, fn($a, $b) => $a['sort'] <=> $b['sort']);
        return $out;
    }

    /**
     * 校验已上传材料是否满足必传项
     * @return array{pass:bool,missing:array,missing_names:array}
     */
    public static function checkRequired(array $materials, array $uploaded): array
    {
        $missing = [];
        $names   = [];
        foreach ($materials as $m) {
            if (!empty($m['required']) && !in_array($m['code'], $uploaded, true)) {
                $missing[] = $m['code'];
                $names[]   = $m['name'];
            }
        }
        return ['pass' => empty($missing), 'missing' => $missing, 'missing_names' => $names];
    }

    /** 按编码取材料定义 */
    public static function find(array $materials, string $code): ?array
    {
        foreach ($materials as $m) {
            if ($m['code'] === $code) {
                return $m;
            }
        }
        return null;
    }

    /** 类型白名单归一化 */
    public static function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        return in_array($type, ['id_front', 'id_back', 'edu', 'photo', 'file'], true) ? $type : 'file';
    }

    /** 扩展名白名单归一化（小写、去点、限本系统白名单） */
    public static function safeExt(string $ext): string
    {
        $ext = strtolower(ltrim(trim($ext), '.'));
        return in_array($ext, self::ALLOW_EXT, true) ? $ext : '';
    }
}
