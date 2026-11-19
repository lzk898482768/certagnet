<?php
namespace app\service;

/**
 * 敏感文本加解密（v2.63）
 *
 * 统一承接政务网申报密码与账号密码库的加密需求，避免两套实现出现格式漂移。
 * - 算法：AES-256-GCM，随机 12 字节 IV，16 字节认证标签；
 * - 存储格式：`gcm$` + base64(iv + tag + cipher)，为 v2.62 既有格式，老数据可直接解密；
 * - 密钥：config/gov.php 的 password_key（环境变量 GOV_PASSWORD_KEY）。
 *
 * ⚠️ 更换 GOV_PASSWORD_KEY 会导致既有密文无法解密，必须重新录入。
 */
class SecretService
{
    private const PREFIX = 'gcm$';

    private static function key(): string
    {
        $secret = (string)config('gov.password_key', 'cert-agent-gov-progress-default-key-2026');
        return hash('sha256', $secret, true);
    }

    public static function encrypt(string $plain): string
    {
        $plain = trim($plain);
        if ($plain === '') {
            return '';
        }
        $iv  = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cipher === false) {
            throw new \RuntimeException('敏感信息加密失败');
        }
        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    /**
     * 解密；兼容历史明文（无 gcm$ 前缀原样返回），避免手工导入的数据无法使用。
     */
    public static function decrypt(string $enc): string
    {
        $enc = trim($enc);
        if ($enc === '') {
            return '';
        }
        if (!str_starts_with($enc, self::PREFIX)) {
            return $enc;
        }
        $raw = base64_decode(substr($enc, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) {
            return '';
        }
        $iv     = substr($raw, 0, 12);
        $tag    = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain  = openssl_decrypt($cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? '' : $plain;
    }

    /** 列表展示用掩码：短密码全掩，长密码保留首尾各 1 位 */
    public static function mask(string $plain): string
    {
        $plain = trim($plain);
        $len = mb_strlen($plain);
        if ($len === 0) {
            return '';
        }
        if ($len <= 2) {
            return str_repeat('*', $len);
        }
        if ($len <= 4) {
            return mb_substr($plain, 0, 1) . str_repeat('*', $len - 1);
        }
        return mb_substr($plain, 0, 1) . str_repeat('*', max(3, $len - 2)) . mb_substr($plain, -1);
    }
}
