<?php
namespace app\service;

use app\service\crypto\HmacSm3;
use app\service\crypto\Sm4;

/**
 * 身份证号落库加密 / 出口脱敏 / 受控明文（v2.99.59）
 *
 * 落库格式：sm4$1$base64(iv + cipher)
 *   - SM4-CBC + 随机 IV + PKCS#7，每次加密结果都不同；
 *   - 旧明文数据仍可读（decode 原样返回），由回填命令逐步转密文。
 *
 * 盲索引：id_card_hash = HMAC-SM3(idxKey, 归一化号码)
 *   - 支撑「按完整身份证号等值查询 / 查重」，不能反推号码；
 *   - 空号码必须存 NULL（唯一索引下多个 NULL 允许，空串只允许一条）。
 *
 * 明文权限：staff:reveal
 *   - 列表 / 详情永远只返回掩码（不看 mask_sensitive 开关）；
 *   - 单条明文只走 Staff::reveal，且必须通过 canReveal()；
 *   - 导出是否含明文也只看 canReveal()，与全局脱敏开关无关。
 */
class IdCardService
{
    /** 密文前缀（含版本号，便于将来换算法） */
    public const PREFIX = 'sm4$1$';

    /** 敏感字段名 */
    public const FIELD = 'id_card';

    /** 盲索引列名 */
    public const HASH_FIELD = 'id_card_hash';

    /** 解密后的明文缓存（key=密文），批量解密时避免重复计算 */
    private static array $memo = [];

    private static ?string $secret = null;

    // ------------------------------------------------------------------
    // 密钥
    // ------------------------------------------------------------------

    /**
     * 主密钥：ID_CARD_SM4_KEY → GOV_PASSWORD_KEY → 内置默认串。
     *
     * 独立于政务密码的密钥可避免「换政务密钥导致身份证全部解不开」；
     * 但为了不强迫存量部署立刻加配置，保留到 GOV_PASSWORD_KEY 的回退。
     */
    public static function secret(): string
    {
        if (self::$secret !== null) {
            return self::$secret;
        }
        $k = trim((string)env('ID_CARD_SM4_KEY', ''));
        if ($k === '') {
            $k = trim((string)config('gov.password_key', ''));
        }
        if ($k === '') {
            $k = 'cert-agent-id-card-default-key-2026';
        }
        return self::$secret = $k;
    }

    /** 密钥来源：explicit（显式配置）/ gov（回退政务密钥）/ default（内置兜底） */
    public static function keySource(): string
    {
        if (trim((string)env('ID_CARD_SM4_KEY', '')) !== '') {
            return 'explicit';
        }
        return trim((string)config('gov.password_key', '')) !== '' ? 'gov' : 'default';
    }

    /** 加密密钥（16 字节） */
    private static function encKey(): string
    {
        return substr(hash('sha256', self::secret() . '|idcard|enc', true), 0, 16);
    }

    /** 盲索引密钥（32 字节，与加密密钥分离派生） */
    private static function idxKey(): string
    {
        return hash('sha256', self::secret() . '|idcard|idx', true);
    }

    // ------------------------------------------------------------------
    // 加解密
    // ------------------------------------------------------------------

    public static function isEncrypted($v): bool
    {
        return is_string($v) && strncmp($v, self::PREFIX, strlen(self::PREFIX)) === 0;
    }

    /** 归一化：去空白、全角转半角、末位 x 转大写 */
    public static function normalize($v): string
    {
        if (!is_string($v)) {
            $v = (string)$v;
        }
        $v = trim($v);
        if ($v === '') {
            return '';
        }
        // 常见全角字符（Excel / OCR 粘贴）
        $v = strtr($v, [
            '０' => '0', '１' => '1', '２' => '2', '３' => '3', '４' => '4',
            '５' => '5', '６' => '6', '７' => '7', '８' => '8', '９' => '9',
            'Ｘ' => 'X', 'ｘ' => 'x', '　' => ' ',
        ]);
        $v = preg_replace('/\s+/u', '', $v) ?? $v;
        return strtoupper($v);
    }

    /** 加密（空值返回空串；已是密文时原样返回，保证幂等） */
    public static function encode($plain): string
    {
        // 密文含大小写混合的 base64，必须先判密文再归一化，否则会破坏密文
        if (self::isEncrypted($plain)) {
            return trim((string)$plain);
        }
        $plain = self::normalize($plain);
        if ($plain === '') {
            return '';
        }
        $iv = random_bytes(16);
        $cipher = Sm4::encryptCbc($plain, self::encKey(), $iv);
        return self::PREFIX . base64_encode($iv . $cipher);
    }

    /**
     * 解密（兼容旧明文：非密文原样返回；解密失败返回空串并写日志，避免把密文透到页面）。
     */
    public static function decode($stored): string
    {
        $stored = is_string($stored) ? trim($stored) : '';
        if ($stored === '') {
            return '';
        }
        if (!self::isEncrypted($stored)) {
            return $stored;
        }
        if (array_key_exists($stored, self::$memo)) {
            return self::$memo[$stored];
        }
        try {
            return self::$memo[$stored] = self::decodeStrict($stored);
        } catch (\Throwable $e) {
            \think\facade\Log::warning('[IdCardService] 身份证解密失败：' . $e->getMessage());
            return self::$memo[$stored] = '';
        }
    }

    /** 严格解密：失败抛异常（reveal 需要明确报错，而不是静默返回空） */
    public static function decodeStrict(string $stored): string
    {
        $stored = trim($stored);
        if ($stored === '') {
            return '';
        }
        if (!self::isEncrypted($stored)) {
            return $stored;
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= 16 || (strlen($raw) - 16) % 16 !== 0) {
            throw new \RuntimeException('身份证密文格式非法');
        }
        return Sm4::decryptCbc(substr($raw, 16), self::encKey(), substr($raw, 0, 16));
    }

    // ------------------------------------------------------------------
    // 盲索引
    // ------------------------------------------------------------------

    /** 盲索引：HMAC-SM3(归一化号码)；空号码返回空串（写库时必须转 NULL） */
    public static function hash($plain): string
    {
        $plain = self::normalize($plain);
        if ($plain === '') {
            return '';
        }
        return HmacSm3::hex($plain, self::idxKey());
    }

    /** 写入用哈希值：空 → null（避免唯一索引下空串冲突） */
    public static function hashOrNull($plain): ?string
    {
        $h = self::hash($plain);
        return $h === '' ? null : $h;
    }

    // ------------------------------------------------------------------
    // 批量 & 组装
    // ------------------------------------------------------------------

    /**
     * 批量解密行内身份证号（原地替换，并移除盲索引列防止外泄）。
     *
     * @param array  $rows      数据行数组（引用）
     * @param string $field     明文字段名
     * @param string $hashField 盲索引字段名
     */
    public static function decodeRows(array &$rows, string $field = self::FIELD, string $hashField = self::HASH_FIELD): void
    {
        foreach ($rows as &$row) {
            if (!is_array($row)) {
                continue;
            }
            if (array_key_exists($field, $row)) {
                $row[$field] = self::decode($row[$field]);
            }
            unset($row[$hashField]);
        }
        unset($row);
    }

    /** 单行解密（返回副本） */
    public static function decodeRow(array $row, string $field = self::FIELD, string $hashField = self::HASH_FIELD): array
    {
        if (array_key_exists($field, $row)) {
            $row[$field] = self::decode($row[$field]);
        }
        unset($row[$hashField]);
        return $row;
    }

    /**
     * 写入前组装：把明文身份证号转成「密文 + 盲索引」。
     *
     * @param array  $data       待写数据（引用修改）
     * @param string $field      明文字段名
     * @param string $hashField  盲索引字段名
     */
    public static function prepareWrite(array &$data, string $field = self::FIELD, string $hashField = self::HASH_FIELD): void
    {
        if (!array_key_exists($field, $data)) {
            return;
        }
        $raw = is_string($data[$field]) ? trim($data[$field]) : (string)$data[$field];
        if ($raw === '') {
            $data[$field] = '';
            $data[$hashField] = null;
            return;
        }
        // 兼容「密文回写」场景：已是密文则保留，但盲索引按解出的明文重算
        if (self::isEncrypted($raw)) {
            $data[$field] = $raw;
            $data[$hashField] = self::hashOrNull(self::decode($raw));
            return;
        }
        $plain = self::normalize($raw);
        $data[$field] = self::encode($plain);
        $data[$hashField] = self::hashOrNull($plain);
    }

    // ------------------------------------------------------------------
    // 出口
    // ------------------------------------------------------------------

    /** 身份证掩码（转发 MaskService，保留前 6 后 4） */
    public static function mask($plain): string
    {
        return MaskService::idCard((string)$plain);
    }

    /**
     * 当前账号是否拥有身份证明文权限。
     *
     * 必须用 rolePerms(role_id)：admin 的 perms 为 null（不限制），
     * 而 $request->permCodes 对 admin 是空数组，直接判会误拒超管。
     */
    public static function canReveal($user = null): bool
    {
        $roleId = 0;
        if (is_object($user)) {
            $roleId = (int)($user->role_id ?? 0);
        } elseif (is_array($user)) {
            $roleId = (int)($user['role_id'] ?? 0);
        }
        $codes = PermissionCatalog::rolePerms($roleId);
        return PermissionCatalog::allows($codes, 'staff', 'reveal');
    }

    /** 导出是否可含明文：与单条查看同源，避免「能看不能导 / 能导不能看」错位 */
    public static function canExportPlain($user = null): bool
    {
        return self::canReveal($user);
    }

    /**
     * 导出取值：有明文权限输出明文，否则输出掩码。
     *
     * 只由账号权限决定，不看 mask_sensitive 开关。
     */
    public static function exportValue($stored, $user = null): string
    {
        $plain = self::decode($stored);
        if ($plain === '') {
            return '';
        }
        return self::canExportPlain($user) ? $plain : self::mask($plain);
    }

    /** 导出审计：每次导出写一条汇总（模块、行数、是否明文） */
    public static function auditExport($user, string $module = 'staff', string $label = '人员档案导出', int $rows = 0): void
    {
        $userId = 0;
        if (is_array($user)) {
            $userId = (int)($user['id'] ?? 0);
        } elseif (is_object($user)) {
            $userId = (int)($user->id ?? 0);
        }
        $plain = self::canExportPlain($user);
        SensitiveAccessService::recordExport(
            $userId,
            $module,
            $label,
            true,
            'plain=' . ($plain ? '1' : '0') . ' rows=' . $rows
        );
    }
}
