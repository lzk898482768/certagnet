<?php
namespace app\service;

use think\facade\Db;

/**
 * 身份证号加密相关结构自愈（v2.99.59）
 *
 * 遵循项目约定：不写升级 SQL，运行时探测/补列/补索引；
 * 同时把同样的结构写进 backend/database/init.sql，保证全新安装一致。
 *
 * 三张表都会存身份证号：
 *   staff        人员档案
 *   exam_customer 考试考生
 *   edu_signup    学历报名
 *
 * 变更内容：
 *   1) id_card 扩到 varchar(255)（sm4$1$ + base64(iv+cipher) 约 60~110 字符）；
 *   2) 新增 id_card_hash char(64) NULL（HMAC-SM3 盲索引，空值必须为 NULL）；
 *   3) 补索引：exam_customer 用唯一索引（一人一档），其余用普通索引；
 *   4) 本表已无明文行时，删除旧的 uk_id_card / idx_idcard，避免误用密文列做查重。
 */
class IdCardSchemaService
{
    protected static bool $ensured = false;

    /** 干跑模式下禁止删除旧索引（避免只统计却改动结构） */
    public static bool $allowLegacyIndexDrop = true;

    /**
     * 需要处理的表：
     *   index  => 索引名
     *   unique => 是否唯一索引
     */
    public const TABLES = [
        'staff'         => ['index' => 'idx_id_card_hash', 'unique' => false],
        'exam_customer' => ['index' => 'uk_id_card_hash',  'unique' => true],
        'edu_signup'    => ['index' => 'idx_idcard_hash',  'unique' => false],
    ];

    /** 旧索引：表 => 索引名（本表回填完成后删除） */
    public const LEGACY_INDEX = [
        'exam_customer' => 'uk_id_card',
        'edu_signup'    => 'idx_idcard',
    ];

    public static function ensure(): void
    {
        if (self::$ensured) {
            return;
        }
        self::$ensured = true;

        foreach (self::TABLES as $table => $meta) {
            if (!self::tableExists($table)) {
                continue;
            }
            self::widen($table);
            ensure_column($table, IdCardService::HASH_FIELD,
                "char(64) DEFAULT NULL COMMENT '身份证号盲索引(HMAC-SM3)，空值为 NULL'");
            self::ensureHashIndex($table, $meta['index'], (bool)$meta['unique']);
            if (self::$allowLegacyIndexDrop) {
                self::dropLegacyIndexWhenBackfilled($table);
            }
        }

        // v2.99.60：存量明文身份证自动回填（10 分钟节流、限时小批量推进）。
        // 升级时若因超时只回填了一部分，后续访问会继续补齐，无需人工跑命令。
        // 任何异常都在服务内部吞掉，不影响业务请求。
        IdCardBackfillService::maybeAutoRun();
    }

    /** 表不存在时跳过（例如未启用考试 / 学历模块的老库） */
    public static function tableExists(string $table): bool
    {
        try {
            $rows = Db::query("SHOW TABLES LIKE '" . addslashes($table) . "'");
            return !empty($rows);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** 把 id_card 扩到 255，避免密文被截断 */
    public static function widen(string $table): bool
    {
        try {
            $cols = Db::query('SHOW COLUMNS FROM `' . $table . '`');
            foreach ($cols as $c) {
                if (strcasecmp((string)($c['Field'] ?? ''), IdCardService::FIELD) !== 0) {
                    continue;
                }
                $len = 0;
                if (preg_match('/varchar\((\d+)\)/i', (string)($c['Type'] ?? ''), $m)) {
                    $len = (int)$m[1];
                }
                if ($len >= 128) {
                    return true;
                }
                $null = strtoupper((string)($c['Null'] ?? 'YES')) === 'YES' ? 'NULL' : 'NOT NULL';
                $def  = $c['Default'] === null ? '' : " DEFAULT '" . addslashes((string)$c['Default']) . "'";
                Db::execute(
                    'ALTER TABLE `' . $table . '` MODIFY `' . IdCardService::FIELD . '` varchar(255) '
                    . $null . $def . " COMMENT '身份证号（SM4 密文）'"
                );
                refresh_table_schema($table);
                return true;
            }
        } catch (\Throwable $e) {
            // 自愈失败不阻断业务：写入密文会因长度不足报错，回填命令会给出明确提示
            \think\facade\Log::warning('[IdCardSchemaService] 扩展 ' . $table . '.id_card 失败：' . $e->getMessage());
        }
        return false;
    }

    /** 索引是否存在 */
    public static function indexExists(string $table, string $index): bool
    {
        try {
            foreach (Db::query('SHOW INDEX FROM `' . $table . '`') as $r) {
                if (strcasecmp((string)($r['Key_name'] ?? ''), $index) === 0) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            // 探测失败按不存在处理
        }
        return false;
    }

    /** 补 id_card_hash 索引（唯一索引失败时降级为普通索引，保证查询可用） */
    public static function ensureHashIndex(string $table, string $index, bool $unique = false): bool
    {
        if (self::indexExists($table, $index)) {
            return true;
        }
        try {
            Db::execute(
                'ALTER TABLE `' . $table . '` ADD ' . ($unique ? 'UNIQUE ' : '')
                . 'KEY `' . $index . '` (`' . IdCardService::HASH_FIELD . '`)'
            );
            return true;
        } catch (\Throwable $e) {
            \think\facade\Log::warning('[IdCardSchemaService] 为 ' . $table . ' 创建索引 ' . $index . ' 失败：' . $e->getMessage());
            if ($unique) {
                // 唯一索引失败（历史重复数据）时退回普通索引，至少保证等值查询性能
                return self::ensureHashIndex($table, 'idx_' . IdCardService::HASH_FIELD, false);
            }
            return false;
        }
    }

    /** 删除索引（不存在时直接返回） */
    public static function dropIndex(string $table, string $index): bool
    {
        if (!self::indexExists($table, $index)) {
            return true;
        }
        try {
            Db::execute('ALTER TABLE `' . $table . '` DROP INDEX `' . $index . '`');
            return true;
        } catch (\Throwable $e) {
            \think\facade\Log::warning('[IdCardSchemaService] 删除索引 ' . $table . '.' . $index . ' 失败：' . $e->getMessage());
            return false;
        }
    }

    /** 本表是否还有明文身份证（空值不算） */
    public static function hasPlaintext(string $table): bool
    {
        try {
            $n = Db::name($table)
                ->where(IdCardService::FIELD, '<>', '')
                ->where(IdCardService::FIELD, 'not like', IdCardService::PREFIX . '%')
                ->count();
            return $n > 0;
        } catch (\Throwable $e) {
            // 探测失败按「仍有明文」处理，保守不删旧索引
            return true;
        }
    }

    /**
     * 回填完成后删除旧索引：明文列不再被用于查重，旧唯一索引只会浪费写入性能。
     * 回填未完成时保留（避免查重弱化 + 唯一约束丢失）。
     */
    public static function dropLegacyIndexWhenBackfilled(string $table): bool
    {
        if (!isset(self::LEGACY_INDEX[$table])) {
            return true;
        }
        if (!self::tableExists($table) || self::hasPlaintext($table)) {
            return false;
        }
        return self::dropIndex($table, self::LEGACY_INDEX[$table]);
    }
}
