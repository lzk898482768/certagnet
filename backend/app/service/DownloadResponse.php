<?php
namespace app\service;

use think\response\File;

/**
 * v2.56：带 RFC 5987 UTF-8 文件名的下载响应。
 *
 * TP6 原生 File 响应（download() helper）直接把中文文件名写入
 * Content-Disposition: attachment; filename="证书导入模板.xlsx"，
 * 非 ASCII 字节未做百分号编码，前端 fetch 解析 header 后拿到的文件名乱码。
 *
 * 这里在输出阶段覆盖为标准头：
 *   Content-Disposition: attachment; filename="<ASCII 兜底>"; filename*=UTF-8''<rawurlencode>
 * 前端 request.js 的 filenameFromHeader 已优先解析 filename*=UTF-8''，两者配合后中文文件名正常。
 */
class DownloadResponse extends File
{
    /**
     * v2.99.54：扩展名 → Content-Type 兜底表。
     *
     * TP6 原生 File::getMimeType() 直接调用 finfo_open()，而部分生产环境
     * （宝塔默认 PHP / 精简编译）未启用 fileinfo 扩展，会抛出
     * 「Call to undefined function think\response\finfo_open()」，
     * 导致所有 Excel 导出/模板下载 500。这里优先按扩展名给类型，
     * 仅在不认识扩展名且 fileinfo 可用时才回退探测。
     */
    private const MIME_BY_EXT = [
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'xls'  => 'application/vnd.ms-excel',
        'csv'  => 'text/csv; charset=utf-8',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'doc'  => 'application/msword',
        'pdf'  => 'application/pdf',
        'zip'  => 'application/zip',
        'txt'  => 'text/plain; charset=utf-8',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
    ];

    public function output($data)
    {
        $name = (string)$this->name;
        if ($name === '') {
            $name = (string)pathinfo((string)$this->data, PATHINFO_BASENAME);
        }
        // ASCII 兜底名（去非 ASCII），保证旧客户端/下载器也能拿到合法文件名
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $name);
        $ascii = ($ascii === null || $ascii === '') ? 'download' : $ascii;

        // 让父类用 ASCII 名生成基础响应（清缓冲 / Content-Length 等逻辑不变）
        $this->name = $ascii;
        $content = parent::output($data);

        // 覆盖为 RFC 5987：filename* 携带 UTF-8 百分号编码的原始文件名
        $this->header['Content-Disposition'] = 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name);

        return $content;
    }

    /**
     * 覆盖父类实现：不依赖 fileinfo 扩展。
     * 先按扩展名给 Content-Type；未知扩展名且 fileinfo 可用时才探测，
     * 否则降级为 application/octet-stream（浏览器仍会按文件名下载）。
     */
    protected function getMimeType(string $filename): string
    {
        if (!empty($this->mimeType)) {
            return $this->mimeType;
        }

        $ext = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext !== '' && isset(self::MIME_BY_EXT[$ext])) {
            return self::MIME_BY_EXT[$ext];
        }

        if (is_file($filename) && function_exists('finfo_open')) {
            try {
                $fi = finfo_open(FILEINFO_MIME_TYPE);
                if ($fi !== false) {
                    $mime = (string)finfo_file($fi, $filename);
                    if (function_exists('finfo_close')) {
                        finfo_close($fi);
                    }
                    if ($mime !== '') {
                        return $mime;
                    }
                }
            } catch (\Throwable $e) {
                // 扩展缺失 / 文件不可读：落到二进制兜底，不阻断下载
            }
        }

        return 'application/octet-stream';
    }
}