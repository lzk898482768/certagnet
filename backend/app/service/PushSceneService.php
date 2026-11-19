<?php
namespace app\service;

use think\facade\Db;

/**
 * 推送场景与规则（v2.99.40）
 *
 * 解决两类问题：
 *  1) 「按功能选择接收人」：接收人可以只接收某个功能/场景的通知（company_receiver.scenes），
 *     留空表示接收全部场景，保持老数据行为不变。
 *  2) 「申报进度推送规则」：管理员可选择按「全环节」推送，或只在
 *     受理 / 办结 / 挂起 / 退件 等关键节点推送；环节没有新增变化时不推送。
 *
 * 场景码与 msg_template.scene 保持一致，便于模板、接收人、推送规则三处使用同一套标识。
 */
class PushSceneService
{
    /** 配置键：申报进度推送范围（all=全环节 / key=仅关键节点） */
    public const CFG_GOV_SCOPE = 'gov_push_scope';

    public const GOV_SCOPE_ALL = 'all';
    public const GOV_SCOPE_KEY = 'key';

    /**
     * 场景字典：scene => 名称
     *
     * @return array<string,string>
     */
    public static function scenes(): array
    {
        return [
            'cert_expire'          => '证书到期',
            'secondment_approach'  => '借调临期',
            'secondment_expire'    => '借调到期',
            'secondment_renew'     => '借调续期',
            'company_cert_expire'  => '企业证书到期',
            'gov_progress'         => '申报进度',
        ];
    }

    /** 规整场景列表（接受数组 / 竖线或逗号分隔字符串），仅保留合法场景码 */
    public static function normalizeScenes($raw): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/[\s,;|]+/', $raw) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $valid = array_keys(self::scenes());
        $out = [];
        foreach ($raw as $s) {
            $s = trim((string)$s);
            if ($s !== '' && in_array($s, $valid, true) && !in_array($s, $out, true)) {
                $out[] = $s;
            }
        }
        return $out;
    }

    public static function scenesText($raw): string
    {
        $list = self::normalizeScenes($raw);
        if (!$list) {
            return '全部功能';
        }
        $map = self::scenes();
        return implode('、', array_map(fn($s) => $map[$s] ?? $s, $list));
    }

    /** 老库自愈：接收人「适用功能」列 */
    public static function ensureSchema(): void
    {
        ensure_column(
            'company_receiver',
            'scenes',
            "varchar(255) NOT NULL DEFAULT '' COMMENT '适用推送场景，逗号分隔；空=全部功能（v2.99.40）' AFTER `receiver_type`"
        );
    }

    /**
     * 接收人是否适用于某场景：未配置（空）视为全部场景
     *
     * @param array|object $receiver
     */
    public static function receiverMatchesScene($receiver, string $scene): bool
    {
        $raw = is_array($receiver) ? ($receiver['scenes'] ?? '') : ($receiver->scenes ?? '');
        $list = self::normalizeScenes($raw);
        return !$list || in_array($scene, $list, true);
    }

    /** 申报进度推送范围：all / key（默认 all，与升级前行为一致但不含「无环节变化」的推送） */
    public static function govScope(): string
    {
        $v = (string)sys_config(self::CFG_GOV_SCOPE, self::GOV_SCOPE_ALL);
        return in_array($v, [self::GOV_SCOPE_ALL, self::GOV_SCOPE_KEY], true) ? $v : self::GOV_SCOPE_ALL;
    }

    public static function govScopeOptions(): array
    {
        return [
            ['value' => self::GOV_SCOPE_ALL,  'label' => '全环节推送', 'desc' => '平台出现新环节就推送（受理、审批、办结等任何环节变化）'],
            ['value' => self::GOV_SCOPE_KEY,  'label' => '仅关键节点', 'desc' => '只在受理、办结、挂起、退件时推送，中间环节不打扰'],
        ];
    }

    public static function saveGovScope(string $scope): bool
    {
        if (!in_array($scope, [self::GOV_SCOPE_ALL, self::GOV_SCOPE_KEY], true)) {
            return false;
        }
        $row = Db::name('system_config')->where('config_key', self::CFG_GOV_SCOPE)->find();
        if ($row) {
            Db::name('system_config')->where('id', $row['id'])->update([
                'config_value' => $scope,
                'remark'       => '申报进度推送范围（all=全环节 / key=仅受理·办结·挂起·退件）',
            ]);
        } else {
            Db::name('system_config')->insert([
                'config_key'   => self::CFG_GOV_SCOPE,
                'config_value' => $scope,
                'remark'       => '申报进度推送范围（all=全环节 / key=仅受理·办结·挂起·退件）',
            ]);
        }
        return true;
    }
}
