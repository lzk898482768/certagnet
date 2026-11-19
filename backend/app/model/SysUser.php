<?php
namespace app\model;

use think\Model;

class SysUser extends Model
{
    protected $name = 'sys_user';
    protected $autoWriteTimestamp = true;
    protected $hidden = ['password', 'totp_secret'];

    public function setPasswordAttr($value): string
    {
        // 已经是 bcrypt 哈希则原样保存，否则使用 bcrypt
        if (str_starts_with($value, '$2y$') || str_starts_with($value, '$2a$')) {
            return $value;
        }
        return password_hash($value, PASSWORD_BCRYPT);
    }

    public function role()
    {
        return $this->belongsTo(SysRole::class, 'role_id');
    }

    public function companies()
    {
        return $this->belongsToMany(ClientCompany::class, 'user_company_rel', 'company_id', 'user_id');
    }

    public static function checkPassword($password, $hash): bool
    {
        // 兼容旧数据：md5 直接比较；新数据走 bcrypt
        if (str_starts_with((string)$hash, '$2y$') || str_starts_with((string)$hash, '$2a$')) {
            return password_verify($password, $hash);
        }
        return md5($password) === $hash;
    }

    /** 是否为需要升级的旧密码哈希（md5 / 弱 bcrypt cost） */
    public static function needsRehash(string $hash): bool
    {
        if ($hash === '') {
            return false;
        }
        if (!str_starts_with($hash, '$2y$') && !str_starts_with($hash, '$2a$')) {
            return true;
        }
        return password_needs_rehash($hash, PASSWORD_BCRYPT);
    }

    /**
     * 密码强度校验：8-32 位，至少含字母与数字
     */
    public static function validatePassword(string $password): bool
    {
        if (strlen($password) < 8 || strlen($password) > 32) {
            return false;
        }
        return preg_match('/[A-Za-z]/', $password) && preg_match('/\d/', $password);
    }
}
