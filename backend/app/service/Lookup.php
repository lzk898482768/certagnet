<?php
namespace app\service;

use app\model\Staff;
use app\model\ClientCompany;

/**
 * 人员/企业查询门面（v2.38：批量预热 + Redis 缓存，消除导入 N+1）
 *
 * 背景：
 *   导入预览与提交原先按行 `Staff::where()->find()` / `ClientCompany::where()->find()`，
 *   100 行 Excel 就是 200+ 次库往返——这是"导入预览卡顿"的根因。
 *
 * 三层结构（就近优先）：
 *   1. 请求内 memo（static 数组）：同一请求内同键只算一次，零 IO；
 *   2. Redis 缓存：跨请求命中，含**负缓存**（未命中的键也记一笔，避免反复回表）；
 *   3. 数据库（批量 WHERE IN）：仅在缓存未命中时发生，且一次拉一整批。
 *
 * 一致性：
 *   写路径（人员/企业 新增、改档、删除、导入落库）必须调用 forget*()，
 *   否则会出现"刚改完、预览还是老值"。TTL 只作为遗漏时的兜底。
 */
class Lookup
{
    /** 负缓存标记：Redis 里存这个数组表示"该键确实查不到" */
    private const MISS = ['__miss__' => 1];

    /** 请求内 memo：key => row|null */
    private static array $memoId      = [];
    private static array $memoName    = [];
    private static array $memoCompany = [];

    /** 每次批量任务开始前重置（避免长驻进程内串味） */
    public static function resetMemo(): void
    {
        self::$memoId      = [];
        self::$memoName    = [];
        self::$memoCompany = [];
    }

    // ------------------------------------------------------------------
    // 批量预热
    // ------------------------------------------------------------------

    /**
     * 批量预热人员：一次 SQL 拉回本批全部人员（按身份证号 + 按姓名各一次）
     *
     * @param string[] $idCards
     * @param string[] $names
     */
    public static function prewarmStaff(array $idCards, array $names): void
    {
        $idCards = self::uniq($idCards);
        $names   = self::uniq($names);

        // ---- 身份证号维度 ----
        $missId = [];
        foreach ($idCards as $ic) {
            if (array_key_exists($ic, self::$memoId)) continue;
            $r = RedisCache::get(RedisCache::NS_IMPORT, self::kId($ic));
            if ($r === self::MISS)          { self::$memoId[$ic] = null; continue; }
            if (is_array($r))               { self::$memoId[$ic] = $r;   continue; }
            $missId[] = $ic;
        }
        if ($missId) {
            $found  = [];
            $hashOf = [];
            foreach ($missId as $ic) {
                $h = \app\service\IdCardService::hash($ic);
                if ($h !== '') {
                    $hashOf[$ic] = $h;
                }
            }
            // 盲索引查询（密文落库后唯一可用的等值匹配）
            if ($hashOf) {
                try {
                    $byHash = Staff::whereIn('id_card_hash', array_values($hashOf))->select()->toArray();
                    foreach ($byHash as $r) {
                        $h = (string)($r['id_card_hash'] ?? '');
                        if ($h !== '' && !isset($found[$h])) {
                            $found[$h] = $r;
                        }
                    }
                } catch (\Throwable $e) {
                    // 列尚未自愈时退化为明文查询
                }
            }
            // 兼容未回填的历史明文行
            $legacy = [];
            foreach ($missId as $ic) {
                $h = $hashOf[$ic] ?? '';
                if ($h === '' || !isset($found[$h])) {
                    $legacy[] = $ic;
                }
            }
            if ($legacy) {
                foreach (Staff::whereIn('id_card', $legacy)->select()->toArray() as $r) {
                    $h = \app\service\IdCardService::hash($r['id_card'] ?? '');
                    if ($h !== '') {
                        $found[$h] = $r;
                    }
                }
            }
            $put = [];
            foreach ($missId as $ic) {
                $h = $hashOf[$ic] ?? '';
                $v = $h !== '' ? ($found[$h] ?? null) : null;
                self::$memoId[$ic] = $v;
                $put[self::kId($ic)] = $v === null ? self::MISS : $v;
            }
            RedisCache::mput(RedisCache::NS_IMPORT, $put, RedisCache::TTL_DEFAULT);
        }

        // ---- 姓名维度 ----
        $missName = [];
        foreach ($names as $n) {
            if (array_key_exists($n, self::$memoName)) continue;
            $r = RedisCache::get(RedisCache::NS_IMPORT, self::kName($n));
            if ($r === self::MISS) { self::$memoName[$n] = null; continue; }
            if (is_array($r))      { self::$memoName[$n] = $r;   continue; }
            $missName[] = $n;
        }
        if ($missName) {
            $found = [];
            foreach (Staff::whereIn('staff_name', $missName)->select()->toArray() as $r) {
                // 同名取第一条（与原 find() 语义一致）
                $nm = (string)($r['staff_name'] ?? '');
                if (!isset($found[$nm])) $found[$nm] = $r;
            }
            $put = [];
            foreach ($missName as $n) {
                $v = $found[$n] ?? null;
                self::$memoName[$n] = $v;
                $put[self::kName($n)] = $v === null ? self::MISS : $v;
            }
            RedisCache::mput(RedisCache::NS_IMPORT, $put, RedisCache::TTL_DEFAULT);
        }
    }

    /** 批量预热企业 */
    public static function prewarmCompany(array $names): void
    {
        $names  = self::uniq($names);
        $missed = [];
        foreach ($names as $n) {
            if (array_key_exists($n, self::$memoCompany)) continue;
            $r = RedisCache::get(RedisCache::NS_IMPORT, self::kCompany($n));
            if ($r === self::MISS) { self::$memoCompany[$n] = null; continue; }
            if (is_array($r))      { self::$memoCompany[$n] = $r;   continue; }
            $missed[] = $n;
        }
        if ($missed) {
            $found = [];
            foreach (ClientCompany::whereIn('company_name', $missed)->select()->toArray() as $r) {
                $cn = (string)($r['company_name'] ?? '');
                if (!isset($found[$cn])) $found[$cn] = $r;
            }
            $put = [];
            foreach ($missed as $n) {
                $v = $found[$n] ?? null;
                self::$memoCompany[$n] = $v;
                $put[self::kCompany($n)] = $v === null ? self::MISS : $v;
            }
            RedisCache::mput(RedisCache::NS_IMPORT, $put, RedisCache::TTL_DEFAULT);
        }
    }

    // ------------------------------------------------------------------
    // 单条查询（命中 memo/Redis，未命中才回表并回填）
    // ------------------------------------------------------------------

    public static function staffByIdCard(string $idCard): ?array
    {
        $idCard = trim($idCard);
        if ($idCard === '') return null;
        if (array_key_exists($idCard, self::$memoId)) return self::$memoId[$idCard];

        $r = RedisCache::get(RedisCache::NS_IMPORT, self::kId($idCard));
        if ($r === self::MISS) return self::$memoId[$idCard] = null;
        if (is_array($r))      return self::$memoId[$idCard] = $r;

        $row = null;
        // v2.99.59：优先盲索引等值匹配，未命中再按历史明文兼容
        $hash = \app\service\IdCardService::hash($idCard);
        if ($hash !== '') {
            try {
                $row = Staff::where('id_card_hash', $hash)->find();
            } catch (\Throwable $e) {
                $row = null;
            }
        }
        if (!$row) {
            $row = Staff::where('id_card', \app\service\IdCardService::normalize($idCard))->find();
        }
        $v   = $row ? $row->toArray() : null;
        self::$memoId[$idCard] = $v;
        RedisCache::put(RedisCache::NS_IMPORT, self::kId($idCard), $v === null ? self::MISS : $v, RedisCache::TTL_DEFAULT);
        return $v;
    }

    public static function staffByName(string $name): ?array
    {
        $name = trim($name);
        if ($name === '') return null;
        if (array_key_exists($name, self::$memoName)) return self::$memoName[$name];

        $r = RedisCache::get(RedisCache::NS_IMPORT, self::kName($name));
        if ($r === self::MISS) return self::$memoName[$name] = null;
        if (is_array($r))      return self::$memoName[$name] = $r;

        $row = Staff::where('staff_name', $name)->find();
        $v   = $row ? $row->toArray() : null;
        self::$memoName[$name] = $v;
        RedisCache::put(RedisCache::NS_IMPORT, self::kName($name), $v === null ? self::MISS : $v, RedisCache::TTL_DEFAULT);
        return $v;
    }

    public static function companyByName(string $name): ?array
    {
        $name = trim($name);
        if ($name === '') return null;
        if (array_key_exists($name, self::$memoCompany)) return self::$memoCompany[$name];

        $r = RedisCache::get(RedisCache::NS_IMPORT, self::kCompany($name));
        if ($r === self::MISS) return self::$memoCompany[$name] = null;
        if (is_array($r))      return self::$memoCompany[$name] = $r;

        $row = ClientCompany::where('company_name', $name)->find();
        $v   = $row ? $row->toArray() : null;
        self::$memoCompany[$name] = $v;
        RedisCache::put(RedisCache::NS_IMPORT, self::kCompany($name), $v === null ? self::MISS : $v, RedisCache::TTL_DEFAULT);
        return $v;
    }

    // ------------------------------------------------------------------
    // 主动失效（写路径调用）
    // ------------------------------------------------------------------

    public static function forgetStaff(?string $idCard = null, ?string $name = null): void
    {
        if ($idCard !== null && $idCard !== '') {
            unset(self::$memoId[trim($idCard)]);
            RedisCache::forget(RedisCache::NS_IMPORT, self::kId(trim($idCard)));
        }
        if ($name !== null && $name !== '') {
            unset(self::$memoName[trim($name)]);
            RedisCache::forget(RedisCache::NS_IMPORT, self::kName(trim($name)));
        }
    }

    public static function forgetCompany(?string $name = null): void
    {
        if ($name !== null && $name !== '') {
            unset(self::$memoCompany[trim($name)]);
            RedisCache::forget(RedisCache::NS_IMPORT, self::kCompany(trim($name)));
        }
    }

    /** 全清（导入落库后批量刷新 / 数据修复脚本） */
    public static function flushAll(): void
    {
        self::resetMemo();
        RedisCache::flushNs(RedisCache::NS_IMPORT);
    }

    // ------------------------------------------------------------------

    private static function kId(string $v): string
    {
        // v2.99.59：缓存键改用盲索引，避免明文身份证号出现在 Redis key 里
        $h = \app\service\IdCardService::hash($v);
        return 'by_id_' . ($h !== '' ? $h : $v);
    }
    private static function kName(string $v): string     { return 'by_name_' . $v; }
    private static function kCompany(string $v): string  { return 'c_by_' . $v; }

    /** 去空 + 去重 + 归一为字符串列表 */
    private static function uniq(array $list): array
    {
        $out = [];
        foreach ($list as $v) {
            $v = trim((string)$v);
            if ($v !== '') $out[$v] = true;
        }
        return array_keys($out);
    }
}
