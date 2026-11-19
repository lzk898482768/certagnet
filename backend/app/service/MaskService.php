<?php
namespace app\service;

/**
 * 敏感数据脱敏服务
 *
 * 产品硬性要求：身份证号、证书编号、社保号、手机号等敏感字段在接口返回时必须脱敏。
 * 规则：
 *   身份证号  保留前 6 后 4（如 3301**********1234）
 *   证书编号  保留前 4 后 4
 *   手机号    保留前 3 后 4
 *   银行卡号  保留前 4 后 4
 *   姓名      保留姓氏，名字以 * 代替（中文姓名按字符处理）
 *
 * 脱敏在「读取出口」统一执行，不改变库内明文数据。
 */
class MaskService
{
    /** 可查看明文的角色配置键（JSON 数组，存角色编码） */
    public const PLAIN_ROLES_KEY = 'mask_plain_roles';

    /** 默认可查看明文的角色：仅超级管理员，与旧行为一致 */
    public const DEFAULT_PLAIN_ROLES = ['admin'];

    /**
     * v2.99.51：字段级脱敏矩阵配置键。
     * 结构：{"角色编码": {"字段编码": "plain|mask|hide"}}，缺省字段按 mask 处理。
     * 仅在全局 mask_sensitive 开启时作为默认策略生效；关闭时回退旧 mask_plain_roles 规则。
     */
    public const MATRIX_KEY = 'mask_matrix';

    /** 三态：明文 / 脱敏 / 隐藏（返回空串） */
    public const STATE_PLAIN = 'plain';
    public const STATE_MASK  = 'mask';
    public const STATE_HIDE  = 'hide';
    public const STATES = [self::STATE_PLAIN, self::STATE_MASK, self::STATE_HIDE];

    /**
     * v2.99.59：无论全局脱敏开关、字段级矩阵怎么配，这些字段在列表/详情里永远掩码。
     *
     * 身份证号已改为密文落库，明文只允许通过 staff:reveal 受控出口发放；
     * 若这里允许配成 plain，关闭全局开关后列表就会直接返回明文，等于绕过权限。
     * 编辑回填（editValue）不受此限制，仍按矩阵/明文策略处理。
     */
    public const ALWAYS_MASK_FIELDS = ['staff.id_card', 'cert.id_card'];

    /**
     * 可配置的敏感字段目录（编码 => 展示信息）。
     * 编码用「域.字段」形式，前端配置页与后端解析共用这一份清单。
     */
    public const FIELDS = [
        'staff.name'            => ['label' => '人员姓名', 'group' => '人员档案'],
        'staff.id_card'         => ['label' => '身份证号', 'group' => '人员档案'],
        'staff.phone'           => ['label' => '手机号', 'group' => '人员档案'],
        'staff.email'           => ['label' => '邮箱', 'group' => '人员档案'],
        'cert.cert_no'          => ['label' => '证书编号', 'group' => '证照'],
        'cert.id_card'          => ['label' => '持证人身份证', 'group' => '证照'],
        'company.contact_phone' => ['label' => '企业联系电话', 'group' => '客户企业'],
    ];

    /**
     * 是否启用脱敏（系统配置开关 mask_sensitive，默认开启）
     */
    public static function enabled(): bool
    {
        $v = sys_config('mask_sensitive', '1');
        return !in_array((string)$v, ['0', 'false', 'off'], true);
    }

    /**
     * 允许查看明文的角色编码列表（v2.97 后台可配置）。
     * 兼容 JSON 数组与逗号分隔两种历史写法；未配置时回退默认值。
     */
    public static function plainRoles(): array
    {
        $raw = sys_config(self::PLAIN_ROLES_KEY, '');
        $list = [];
        if (is_array($raw)) {
            $list = $raw;
        } elseif (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $list = $decoded;
            } else {
                $list = explode(',', $raw);
            }
        }
        $list = array_values(array_unique(array_filter(array_map(
            static fn ($v) => trim((string)$v),
            $list
        ))));
        return $list ?: self::DEFAULT_PLAIN_ROLES;
    }

    /**
     * 当前用户是否可以看明文：脱敏关闭 + 角色在允许列表内。
     * 超级管理员始终放行，避免配置失误后连管理员也看不到明文。
     */
    public static function canViewPlain($user = null): bool
    {
        if (self::enabled()) {
            return false;
        }
        $role = $user['role_code'] ?? (is_object($user) ? ($user->role_code ?? '') : '');
        $role = trim((string)$role);
        if ($role === '') {
            return false;
        }
        if ($role === 'admin') {
            return true;
        }
        return in_array($role, self::plainRoles(), true);
    }

    /** 综合判断是否需要脱敏 */
    public static function shouldMask($user = null): bool
    {
        return !self::canViewPlain($user);
    }

    // ================= v2.99.51 字段级脱敏矩阵 =================

    /** 解析矩阵配置：只保留合法角色/字段/状态，其余静默丢弃 */
    public static function matrix(): array
    {
        $raw = sys_config(self::MATRIX_KEY, '');
        if (is_string($raw)) {
            $decoded = trim($raw) === '' ? [] : json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        return self::normalizeMatrix($raw);
    }

    /**
     * 规范化矩阵数据（保存与读取共用）：
     * 丢弃非法角色编码 / 未知字段 / 非法状态，返回 {role: {field: state}}。
     */
    public static function normalizeMatrix($raw): array
    {
        if (is_string($raw)) {
            $decoded = trim($raw) === '' ? [] : json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $role => $fields) {
            $role = trim((string)$role);
            if ($role === '' || !is_array($fields)) {
                continue;
            }
            foreach ($fields as $field => $state) {
                $field = trim((string)$field);
                if ($field === '' || !isset(self::FIELDS[$field])) {
                    continue;
                }
                $state = strtolower(trim((string)$state));
                if (!in_array($state, self::STATES, true)) {
                    continue;
                }
                $out[$role][$field] = $state;
            }
        }
        return $out;
    }

    /** 当前用户的角色编码（未登录 / 无角色返回空串） */
    public static function roleOf($user = null): string
    {
        if (is_object($user)) {
            $user = method_exists($user, 'toArray') ? $user->toArray() : (array)$user;
        }
        if (!is_array($user)) {
            return '';
        }
        $role = $user['role_code'] ?? '';
        if (is_array($role)) {
            $role = reset($role);
        }
        return trim((string)$role);
    }

    /** 矩阵中该角色对该字段的显式配置；未配置返回 null */
    public static function explicitState(string $field, $user = null): ?string
    {
        $role = self::roleOf($user);
        if ($role === '') {
            return null;
        }
        $m = self::matrix();
        return $m[$role][$field] ?? null;
    }

    /**
     * 解析某字段对某用户的最终状态。
     *
     * 优先级：
     *  1) 显式配置的 plain / hide 永远生效（合规收紧或放开都以管理员配置为准）；
     *  2) 全局脱敏关闭 → 走旧规则（mask_plain_roles 白名单内看明文，其余打码）；
     *  3) 全局脱敏开启 → 未配置字段默认 mask；
     *  4) 例外：姓名默认不脱敏（历史行为），仅导出等严格场景或显式配置才处理。
     */
    public static function stateFor(string $field, $user = null, bool $strict = false): string
    {
        // 身份证强制掩码：不看开关/矩阵，明文只能走 staff:reveal 受控出口
        if (in_array($field, self::ALWAYS_MASK_FIELDS, true)) {
            return self::STATE_MASK;
        }
        $explicit = self::explicitState($field, $user);
        if ($explicit !== null && $explicit !== self::STATE_MASK) {
            return $explicit;
        }
        if (!self::enabled()) {
            return self::canViewPlain($user) ? self::STATE_PLAIN : self::STATE_MASK;
        }
        if ($explicit === self::STATE_MASK) {
            return self::STATE_MASK;
        }
        if (!$strict && $field === 'staff.name') {
            return self::STATE_PLAIN;
        }
        return self::STATE_MASK;
    }

    /** 按字段编码套用对应的脱敏算法 */
    public static function maskField(string $field, $value): string
    {
        $v = (string)$value;
        return match ($field) {
            'staff.name'                     => self::name($v),
            'staff.id_card', 'cert.id_card'  => self::idCard($v),
            'staff.phone', 'company.contact_phone' => self::phone($v),
            'staff.email'                    => self::email($v),
            'cert.cert_no'                   => self::certNo($v),
            default                          => self::keepPublic($v, 2, 2),
        };
    }

    /** 按矩阵输出单个字段（plain 原样 / mask 打码 / hide 空串） */
    public static function fieldValue(string $field, $value, $user = null, bool $strict = false): string
    {
        $v = (string)$value;
        if ($v === '') {
            return '';
        }
        switch (self::stateFor($field, $user, $strict)) {
            case self::STATE_PLAIN:
                return $v;
            case self::STATE_HIDE:
                return '';
            default:
                return self::maskField($field, $v);
        }
    }

    /**
     * 编辑回填场景：默认返回明文，仅显式配置的 mask / hide 生效。
     * 供管理员编辑表单使用，避免矩阵收紧后仍无条件回填明文。
     */
    public static function editValue(string $field, $value, $user = null): string
    {
        $v = (string)$value;
        if ($v === '') {
            return '';
        }
        return match (self::explicitState($field, $user)) {
            self::STATE_HIDE => '',
            self::STATE_MASK => self::maskField($field, $v),
            default          => $v,
        };
    }

    /** 编辑回填：人员敏感字段（默认明文，尊重显式 mask / hide） */
    public static function staffEdit($staff, $user = null): array
    {
        $a = self::toArray($staff);
        foreach (['id_card' => 'staff.id_card', 'phone' => 'staff.phone', 'email' => 'staff.email'] as $key => $field) {
            if (array_key_exists($key, $a)) {
                $a[$key] = self::editValue($field, $a[$key] ?? '', $user);
            }
        }
        return $a;
    }

    /** 编辑回填：证书敏感字段（默认明文，尊重显式 mask / hide） */
    public static function certEdit($cert, $user = null): array
    {
        $a = self::toArray($cert);
        foreach (['cert_no' => 'cert.cert_no', 'id_card' => 'cert.id_card'] as $key => $field) {
            if (array_key_exists($key, $a)) {
                $a[$key] = self::editValue($field, $a[$key] ?? '', $user);
            }
        }
        if (!empty($a['staff']) && is_array($a['staff'])) {
            $a['staff'] = self::staffEdit($a['staff'], $user);
        }
        return $a;
    }

    /** 统一把模型对象转成数组 */
    protected static function toArray($row): array
    {
        if (is_array($row)) {
            return $row;
        }
        if (is_object($row) && method_exists($row, 'toArray')) {
            return $row->toArray();
        }
        return (array)$row;
    }

    /**
     * 脱敏值回写防护（v2.29 数据损坏修复）
     *
     * 背景：列表接口返回的身份证/证书编号等已打码，前端「编辑」若直接回传该值，
     *       会把「3301**********1234」写回数据库，造成敏感数据永久损坏。
     * 规则：含 * 的敏感字段值一律视为「未修改」，保存时跳过。
     *
     * @param array $data 待保存数据（引用修改）
     * @param array $fields 需要防护的字段名
     */
    public static function guardMaskedEcho(array &$data, array $fields): void
    {
        foreach ($fields as $f) {
            if (isset($data[$f]) && is_string($data[$f]) && strpos($data[$f], '*') !== false) {
                unset($data[$f]);
            }
        }
    }

    /**
     * 按矩阵批量脱敏人员列表
     * @param mixed $user 当前登录用户（含 role_code）
     */
    public static function staffListFor(array $list, $user = null, bool $strict = false): array
    {
        return self::staffList($list, $strict, $user);
    }

    /** 按矩阵批量脱敏证书列表 */
    public static function certListFor(array $list, $user = null): array
    {
        return self::certList($list, $user);
    }

    /** 按矩阵脱敏单个人员（兼容旧调用：不传 user 时按默认全量脱敏） */
    public static function staffFor($staff, $user = null, bool $strict = false): array
    {
        return self::staff($staff, $strict, $user);
    }

    /** 按矩阵脱敏单个证书 */
    public static function certFor($cert, $user = null): array
    {
        return self::cert($cert, $user);
    }

    /** 身份证号：保留前 6 后 4（入参可能是 SM4 密文，先解密再掩码） */
    public static function idCard($v): string
    {
        return self::keep(\app\service\IdCardService::decode((string)$v), 6, 4);
    }

    /**
     * v2.99.47：通用头尾保留掩码（公开入口，供操作日志等非标准字段复用）。
     * 值过短时退化为首字符 + 全打码。
     */
    public static function keepPublic($v, int $head = 2, int $tail = 2): string
    {
        return self::keep((string)$v, $head, $tail);
    }

    /** 证书编号：保留前 4 后 4 */
    public static function certNo($v): string
    {
        return self::keep((string)$v, 4, 4);
    }

    /** 手机号：保留前 3 后 4 */
    public static function phone($v): string
    {
        return self::keep((string)$v, 3, 4);
    }

    /** 银行卡号：保留前 4 后 4 */
    public static function bankNo($v): string
    {
        return self::keep((string)$v, 4, 4);
    }

    /** 社保号/社保编号：视为长编码，保留前 3 后 3 */
    public static function socialNo($v): string
    {
        return self::keep((string)$v, 3, 3);
    }

    /**
     * 姓名脱敏：张三 → 张*；欧阳明月 → 欧**（复姓/单名场景也安全）
     */
    public static function name($v): string
    {
        $s = trim((string)$v);
        if ($s === '') {
            return '';
        }
        $len = mb_strlen($s, 'UTF-8');
        if ($len === 1) {
            return $s;
        }
        return mb_substr($s, 0, 1, 'UTF-8') . str_repeat('*', min($len - 1, 3));
    }

    /**
     * 统一入口：保留前 $head 后 $tail 位，中间以 * 填充
     * 值过短（不足以保留头尾）时退化为「首字符 + 全部打码」
     *
     * v2.76 修复：必须按「字符」而不是「字节」截断。
     * 证书编号 / 身份证等字段虽然通常是 ASCII，但实际存在含中文、全角符号的脏数据，
     * 用 strlen/substr 按字节切分会把多字节 UTF-8 字符拦腰截断，
     * 生成非法 UTF-8 串，最终 json_encode 抛
     * 「Malformed UTF-8 characters, possibly incorrectly encoded」，整页报错。
     */
    protected static function keep(string $v, int $head, int $tail): string
    {
        $v = trim($v);
        if ($v === '') {
            return '';
        }
        // 非法 UTF-8 先做兜底转换，避免把坏字节继续透传到响应体
        if (!mb_check_encoding($v, 'UTF-8')) {
            $v = mb_convert_encoding($v, 'UTF-8', 'UTF-8');
        }
        $len = mb_strlen($v, 'UTF-8');
        if ($len <= $head + $tail) {
            return self::partial($v);
        }
        return mb_substr($v, 0, $head, 'UTF-8')
            . str_repeat('*', $len - $head - $tail)
            . mb_substr($v, $len - $tail, $tail, 'UTF-8');
    }

    /** 短值兜底：保留首字符，其余全部打码 */
    protected static function partial(string $v): string
    {
        if (!mb_check_encoding($v, 'UTF-8')) {
            $v = mb_convert_encoding($v, 'UTF-8', 'UTF-8');
        }
        $len = mb_strlen($v, 'UTF-8');
        if ($len <= 1) {
            return str_repeat('*', max(1, $len));
        }
        return mb_substr($v, 0, 1, 'UTF-8') . str_repeat('*', $len - 1);
    }

    /**
     * 人员列表脱敏（姓名默认不脱敏，身份证/电话/邮箱按矩阵处理）
     * 支持 toArray() 后的数组与模型对象
     * @param bool $strict 是否连姓名一起脱敏（导出给外部时使用）
     * @param mixed $user 当前登录用户，用于解析字段级矩阵；传 null 时按默认全量脱敏
     */
    public static function staff($staff, bool $strict = false, $user = null): array
    {
        $a = self::toArray($staff);
        if (array_key_exists('id_card', $a)) {
            $a['id_card'] = self::fieldValue('staff.id_card', $a['id_card'] ?? '', $user, $strict);
        }
        if (array_key_exists('phone', $a)) {
            $a['phone'] = self::fieldValue('staff.phone', $a['phone'] ?? '', $user, $strict);
        }
        if (array_key_exists('email', $a)) {
            $a['email'] = self::fieldValue('staff.email', $a['email'] ?? '', $user, $strict);
        }
        if (array_key_exists('staff_name', $a)) {
            $state = self::stateFor('staff.name', $user, $strict);
            if ($state === self::STATE_HIDE) {
                $a['staff_name'] = '';
            } elseif ($state === self::STATE_MASK) {
                $a['staff_name'] = self::name($a['staff_name'] ?? '');
            }
        }
        return $a;
    }

    /**
     * 证书脱敏（证书编号 / 持证人身份证按矩阵处理）
     */
    public static function cert($cert, $user = null): array
    {
        $a = self::toArray($cert);
        if (array_key_exists('cert_no', $a)) {
            $a['cert_no'] = self::fieldValue('cert.cert_no', $a['cert_no'] ?? '', $user);
        }
        // 持证人身份证（若查询中 join 带了）
        if (array_key_exists('id_card', $a)) {
            $a['id_card'] = self::fieldValue('cert.id_card', $a['id_card'] ?? '', $user);
        }
        // 证书列表 with(['staff']) 会把整条人员记录带出来，嵌套人员也要按矩阵处理。
        if (!empty($a['staff']) && is_array($a['staff'])) {
            $a['staff'] = self::staff($a['staff'], false, $user);
        }
        return $a;
    }

    /**
     * 批量人员脱敏
     */
    public static function staffList(array $list, bool $strict = false, $user = null): array
    {
        $out = [];
        foreach ($list as $item) {
            $out[] = self::staff($item, $strict, $user);
        }
        return $out;
    }

    /**
     * 批量证书脱敏
     */
    public static function certList(array $list, $user = null): array
    {
        $out = [];
        foreach ($list as $item) {
            $out[] = self::cert($item, $user);
        }
        return $out;
    }

    /** 邮箱脱敏：zhangsan@abc.com → z***@abc.com */
    public static function email($v): string
    {
        $s = trim((string)$v);
        if ($s === '' || strpos($s, '@') === false) {
            return $s;
        }
        [$name, $domain] = explode('@', $s, 2);
        $len = mb_strlen($name, 'UTF-8');
        if ($len <= 1) {
            return mb_substr($name, 0, 1, 'UTF-8') . '***@' . $domain;
        }
        return mb_substr($name, 0, 1, 'UTF-8') . str_repeat('*', min($len - 1, 3)) . '@' . $domain;
    }
}