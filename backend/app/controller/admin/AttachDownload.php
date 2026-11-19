<?php
namespace app\controller\admin;

use app\service\AttachSignService;
use app\service\S3StorageService;
use app\service\WebDavService;

/**
 * 附件下载（v2.46 起，v2.99.48 扩展支持 S3）：
 * 为远端附件（WebDAV / S3）提供**带签名时效**的本站代理下载。
 *
 * 为什么放在免登录区：
 *   · 远端附件的访问地址由服务端生成 HMAC 签名（路径 + 时间戳 + 密钥），
 *     无需请求头 Authorization，可直接用于 <img> / <iframe> / <a target=_blank>；
 *   · 密钥只存 system_config，伪造 URL 无法通过校验；URL 带 1 小时时效；
 *   · 本接口只接受已签名的相对对象路径并做穿越过滤，本机文件不经过此接口。
 *
 * v2.99.48：
 *   · 同时代理 webdav:// 与 s3:// 两类远端对象；
 *   · 不依赖「当前启用的存储类型」——切换到 S3 后，历史 webdav:// 链接仍可下载，
 *     只要对应的存储配置仍然完整（保留可读，不强制迁移）。
 */
class AttachDownload
{
    /** 扩展名 → Content-Type（仅白名单类型，覆盖 CertAttachService 的上传白名单） */
    public const MIME = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'txt'  => 'text/plain; charset=utf-8',
        'zip'  => 'application/zip',
    ];

    public function download()
    {
        $req = request();
        $p   = (string)$req->param('p', '');
        $e   = (string)$req->param('e', '');
        $s   = (string)$req->param('sig', '');

        // 统一签名校验（WebDAV / S3 共用同一 HMAC 密钥），返回已过滤的相对路径
        $rel = AttachSignService::verify($p, $e, $s);
        if ($rel === null) {
            return $this->fail(403, '链接无效或已过期，请刷新页面后重试');
        }
        $ext = strtolower((string)pathinfo($rel, PATHINFO_EXTENSION));
        if (!isset(self::MIME[$ext])) {
            return $this->fail(400, '不支持的附件类型');
        }

        // 按当前启用的存储类型决定从哪里取流；两套配置都在时优先当前启用项，
        // 但即便当前已切到 S3，历史 WebDAV 链接仍回退到 WebDAV 配置读取。
        $storage = trim((string)sys_config('attach_storage', 'local'));
        $body = '';
        $sourceMime = '';

        if ($storage === 's3' && S3StorageService::config() !== null) {
            $r = S3StorageService::getObject($rel);
            if (empty($r['ok'])) {
                return $this->fail(502, '读取 S3 附件失败：' . ($r['msg'] ?? '未知错误'));
            }
            $body = (string)$r['body'];
            $sourceMime = (string)($r['mime'] ?? '');
        } elseif (WebDavService::config() !== null) {
            $cfg = WebDavService::config();
            $r = WebDavService::request($cfg, 'GET', $rel);
            if (empty($r['ok'])) {
                return $this->fail(502, '读取 WebDAV 附件失败：' . ($r['msg'] ?? '未知错误'));
            }
            $body = (string)$r['body'];
        } elseif (S3StorageService::config() !== null) {
            // 当前不是 S3 但 S3 配置仍在（例如回退到 local/webdav 后访问旧 s3:// 链接）
            $r = S3StorageService::getObject($rel);
            if (empty($r['ok'])) {
                return $this->fail(502, '读取 S3 附件失败：' . ($r['msg'] ?? '未知错误'));
            }
            $body = (string)$r['body'];
            $sourceMime = (string)($r['mime'] ?? '');
        } else {
            return $this->fail(404, '远端附件存储未配置或已停用');
        }

        $inline = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'txt'], true);
        $name = basename($rel);
        header('Content-Type: ' . self::MIME[$ext]);
        header('Content-Length: ' . strlen($body));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . rawurlencode($name) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=600');
        echo $body;
        exit;
    }

    private function fail(int $status, string $msg)
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        echo $msg;
        exit;
    }
}
