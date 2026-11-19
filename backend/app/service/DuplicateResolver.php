<?php
namespace app\service;

use think\facade\Db;

/**
 * 查重命中 → 复用存量 + 自动共有（v2.99.38 P4）
 *
 * 需求：新建时命中存量（即使当前账号对该存量没有数据权限），不新建重复主记录，
 * 而是复用存量并把「原所有人 + 当前操作人」置为共同所有人。
 *
 * 查重键（与设计文档 §4.1 一致）：
 *  - 企业：优先 credit_code；为空退化为 company_name + legal_person
 *  - 人员：优先 id_card；为空退化为 staff_name + phone
 *  - 证书：staff_id + cert_no；编号为空退化为 staff_id + cert_name + issue_org
 *          （控制器沿用「编号或专业任一命中」的既有交互，由 certificate() 返回字段清单）
 *
 * 说明：本服务只负责「查重 + 共有化 + 审计」，主记录的创建仍由各控制器完成，
 * 避免把三套差异较大的字段映射塞进一个通用方法。
 */
class DuplicateResolver
{
    /** 记录归属表（与 ResourceOwnerService 保持一致） */
    private const TABLES = [
        'company'     => 'client_company',
        'staff'       => 'staff',
        'certificate' => 'certificate',
    ];

    /**
     * 企业查重：命中返回存量行，否则 null。
     * @param array $input 至少包含 company_name；credit_code / legal_person 可选
     */
    public static function company(array $input, int $excludeId = 0): ?array
    {
        $code = strtoupper(trim((string)($input['credit_code'] ?? '')));
        $q = Db::name(self::TABLES['company']);
        if ($excludeId > 0) {
            $q->where('id', '<>', $excludeId);
        }
        if ($code !== '') {
            return $q->where('credit_code', $code)->order('id', 'asc')->find() ?: null;
        }
        $name = trim((string)($input['company_name'] ?? ''));
        if ($name === '') {
            return null;
        }
        $legal = trim((string)($input['legal_person'] ?? ''));
        if ($legal === '') {
            // 法定代表人为空时不退化到「仅名称」，避免同名不同企业误判
            return null;
        }
        return $q->where('company_name', $name)->where('legal_person', $legal)
            ->order('id', 'asc')->find() ?: null;
    }

    /**
     * 人员查重：命中返回存量行，否则 null。
     */
    public static function staff(array $input, int $excludeId = 0): ?array
    {
        // v2.99.59：身份证密文落库，查重走盲索引（HMAC-SM3），兼容未回填的历史明文
        $idCard = \app\service\IdCardService::normalize($input['id_card'] ?? '');
        $q = Db::name(self::TABLES['staff']);
        if ($excludeId > 0) {
            $q->where('id', '<>', $excludeId);
        }
        if ($idCard !== '') {
            $hash = \app\service\IdCardService::hash($idCard);
            if ($hash !== '') {
                $hit = (clone $q)->where('id_card_hash', $hash)->order('id', 'asc')->find();
                if ($hit) {
                    return $hit;
                }
            }
            return $q->where('id_card', $idCard)->order('id', 'asc')->find() ?: null;
        }
        $name = trim((string)($input['staff_name'] ?? ''));
        $phone = trim((string)($input['phone'] ?? ''));
        if ($name === '' || $phone === '') {
            // 姓名/电话不完整时不做退化匹配，宁可新建也不能错误合并
            return null;
        }
        return $q->where('staff_name', $name)->where('phone', $phone)
            ->order('id', 'asc')->find() ?: null;
    }

    /**
     * 证书查重：编号非空按编号，否则按「专业 + 发证机构」。
     * @return array{row:array,fields:string[]}|null
     */
    public static function certificate(array $input, int $excludeId = 0): ?array
    {
        $staffId = (int)($input['staff_id'] ?? 0);
        if ($staffId <= 0) {
            return null;
        }
        $certNo = trim((string)($input['cert_no'] ?? ''));
        $certName = trim((string)($input['cert_name'] ?? ''));
        if ($certNo === '' && $certName === '') {
            return null;
        }
        $base = function () use ($staffId, $excludeId) {
            $q = Db::name(self::TABLES['certificate'])->where('staff_id', $staffId);
            if ($excludeId > 0) {
                $q->where('id', '<>', $excludeId);
            }
            return $q;
        };

        $fields = [];
        $row = null;
        if ($certNo !== '') {
            $byNo = $base()->where('cert_no', $certNo)->order('id', 'desc')->find();
            if ($byNo) {
                $row = $byNo;
                $fields[] = 'cert_no';
            }
        }
        if ($certName !== '') {
            $byName = $base()->where('cert_name', $certName)->order('id', 'desc')->find();
            if ($byName) {
                if (!$row) {
                    $row = $byName;
                }
                $fields[] = 'cert_name';
            }
        }
        if (!$row) {
            return null;
        }
        return ['row' => $row, 'fields' => array_values(array_unique($fields))];
    }

    /**
     * 命中存量后的共有化：保留原所有人，追加当前操作人，并把公海资源落为私有。
     * 幂等：已是共有人时不会重复插入，但仍补一条审计便于追溯。
     *
     * @return array{ok:bool,created:bool,owners:int}
     */
    public static function share(string $type, int $existId, int $userId, string $remark = '查重命中自动共有'): array
    {
        ResourceOwnerService::ensure();
        $type = ResourceOwnerService::normalizeType($type);
        $table = ResourceOwnerService::table($type);
        if ($table === '' || $existId <= 0 || $userId <= 0) {
            return ['ok' => false, 'created' => false, 'owners' => 0];
        }
        return Db::transaction(function () use ($type, $table, $existId, $userId, $remark) {
            $row = Db::name($table)->where('id', $existId)->find();
            if (!$row) {
                return ['ok' => false, 'created' => false, 'owners' => 0];
            }
            $exists = Db::name('resource_owner')
                ->where('res_type', $type)->where('res_id', $existId)->where('user_id', $userId)->find();
            $created = false;
            if (!$exists) {
                $count = Db::name('resource_owner')->where('res_type', $type)->where('res_id', $existId)->count();
                Db::name('resource_owner')->insert([
                    'res_type'    => $type,
                    'res_id'      => $existId,
                    'user_id'     => $userId,
                    'is_primary'  => $count === 0 ? 1 : 0,
                    'source'      => 'duplicate',
                    'created_by'  => $userId,
                    'create_time' => date('Y-m-d H:i:s'),
                ]);
                $created = true;
            }
            // 已存在共有人或从公海被命中 → 统一回写 is_pool / owner_user_id
            ResourceOwnerService::syncFlags($type, $existId);
            ResourceOwnerService::log($type, $existId, 0, $userId, $userId, 'duplicate', false, $remark);
            $owners = Db::name('resource_owner')->where('res_type', $type)->where('res_id', $existId)->count();
            return ['ok' => true, 'created' => $created, 'owners' => (int)$owners];
        });
    }

    /**
     * 查重命中后返回给前端的最小信息（不回传敏感字段）。
     */
    public static function brief(string $type, array $row): array
    {
        $map = [
            'company'     => ['id' => 'id', 'title' => 'company_name', 'code' => 'credit_code'],
            'staff'       => ['id' => 'id', 'title' => 'staff_name', 'code' => 'id_card'],
            'certificate' => ['id' => 'id', 'title' => 'cert_name', 'code' => 'cert_no'],
        ];
        $cfg = $map[$type] ?? $map['company'];
        $code = (string)($row[$cfg['code']] ?? '');
        if ($type === 'staff') {
            $code = MaskService::idCard($code);
        } elseif ($type === 'certificate') {
            $code = MaskService::certNo($code);
        }
        return [
            'id'     => (int)($row[$cfg['id']] ?? 0),
            'title'  => (string)($row[$cfg['title']] ?? ''),
            'code'   => $code,
        ];
    }
}
