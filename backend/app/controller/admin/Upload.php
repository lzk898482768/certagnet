<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use app\service\CertAttachService;

class Upload extends AdminController
{
    /**
     * 通用安全上传（证书扫描件等附件）
     *
     * ⚠️ P0 安全（原实现零校验，可上传 php/jsp/exe/svg/html 触发 RCE / 存储型 XSS）：
     *   1. 类型白名单（CertAttachService::ALLOW_EXT），非白名单一律拒绝；
     *   2. 真实扩展名优先（防 .png.php 伪装），扩展名不在白名单则拒；
     *   3. 落盘文件名**强制随机重命名**（UUID + 白名单内扩展名），不信任客户端文件名；
     *   4. 大小按白名单上限校验。
     *
     * 调用方（证书附件/人档/项目/合同等）无需改动，统一在此收口。
     */
    public function upload()
    {
        $file = $this->request->file('file');
        if (!$file) {
            return json(r_error('请选择文件', 400));
        }

        $origName = (string)$file->getOriginalName();
        $size     = (int)$file->getSize();

        // v2.32 L1 第二层：读取临时文件的真实 MIME（不信任客户端声明与扩展名）
        $realMime = '';
        try {
            $tmp = $file->getPathname();
            if ($tmp && function_exists('finfo_open')) {
                $fi = finfo_open(FILEINFO_MIME_TYPE);
                if ($fi) {
                    $realMime = (string)finfo_file($fi, $tmp);
                    finfo_close($fi);
                }
            }
        } catch (\Throwable $e) {
            $realMime = '';
        }

        // 先把原始扩展名 + 真实 MIME 交给白名单校验（真实扩展名优先，防双扩展名伪装）
        $raw = [
            'attach_path' => $origName,
            'file_size'   => $size,
            'file_mime'   => $realMime,
        ];
        $check = CertAttachService::validate($raw);
        if (!$check['ok']) {
            return json(r_error('上传被拒绝：' . $check['msg'], 400));
        }
        $ext = $check['ext']; // 白名单内的真实扩展名

        // 落盘：随机名 + 白名单扩展名（绝不保留客户端文件名与扩展名）
        $safeName = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;

        // v2.46 / v2.99.48：系统设置启用远端存储时统一转存；否则保持本机存储。
        // 优先级：S3（新）> WebDAV（历史）> 本机 uploads。三者互斥，由 attach_storage 决定。
        if (\app\service\S3StorageService::enabled()) {
            $tmp = $file->getPathname();
            $put = \app\service\S3StorageService::put($tmp, $safeName, $ext, $realMime);
            if (!$put['ok']) {
                return json(r_error('上传失败：' . $put['msg'], 400));
            }
            return json(r_success([
                'path'      => $put['path'], // s3:// 对象 key token
                'url'       => $put['url'],  // 带签名的本站下载接口
                'file_ext'  => $ext,
                'file_size' => $size,
                'name'      => $origName, // 仅用于回显「原始文件名」，不落地
            ], '上传成功'));
        }

        if (\app\service\WebDavService::enabled()) {
            $tmp = $file->getPathname();
            $put = \app\service\WebDavService::put($tmp, $safeName, $ext);
            if (!$put['ok']) {
                return json(r_error('上传失败：' . $put['msg'], 400));
            }
            return json(r_success([
                'path'      => $put['path'], // webdav:// 相对路径 token
                'url'       => $put['url'],  // 带签名的本站下载接口
                'file_ext'  => $ext,
                'file_size' => $size,
                'name'      => $origName, // 仅用于回显「原始文件名」，不落地
            ], '上传成功'));
        }

        $dir = 'uploads/' . date('Ym');
        $absDir = public_path() . $dir;
        if (!is_dir($absDir)) {
            @mkdir($absDir, 0755, true);
        }
        try {
            $moved = $file->move($absDir, $safeName);
        } catch (\Throwable $e) {
            return json(r_error('上传失败：' . $e->getMessage(), 400));
        }
        if (!$moved) {
            // UploadedFile 无 getError()（v2.32 修正：原调用会抛 undefined method）
            return json(r_error('上传失败，请重试', 400));
        }

        $path = '/' . $dir . '/' . $safeName;
        return json(r_success([
            'path'      => $path,
            'url'       => $path,
            'file_ext'  => $ext,
            'file_size' => $size,
            'name'      => $origName, // 仅用于回显「原始文件名」，不落地
        ], '上传成功'));
    }
}