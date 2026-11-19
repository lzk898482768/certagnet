<?php
namespace app\service;

use think\facade\Db;

/**
 * 学历报名进度流程引擎（v2.99）
 *
 * 状态流结构：[{ key, label }]，节点序号 1-based，
 * 工单 edu_signup.stage 即当前节点序号，节点时间存 stage_times JSON。
 * 流程按报名类型（电中/成考）在 edu_flow_config 配置，可在后台自定义增删改排序；
 * 工单建单时把当时流程写入 snapshot，后续配置变更不影响历史工单。
 */
class EduFlowService
{
    /** 取某报名类型的生效流程（落库配置 → 默认流程兜底） */
    public static function flowOfType(string $signupType): array
    {
        $row = Db::name('edu_flow_config')->where('signup_type', $signupType)->where('status', 1)->find();
        $flow = self::normalize($row['status_flow'] ?? null);
        if ($flow) {
            return $flow;
        }
        return self::defaultFlow($signupType);
    }

    /** 默认流程（配置缺失/被清空时兜底，与 EduSchemaService 种子一致） */
    public static function defaultFlow(string $signupType): array
    {
        if ($signupType === 'chengkao') {
            return [
                ['key' => 'pending',  'label' => '待报名'],
                ['key' => 'signed',   'label' => '已报名'],
                ['key' => 'to_exam',  'label' => '待考试'],
                ['key' => 'to_admit', 'label' => '待录取'],
                ['key' => 'admitted', 'label' => '已录取'],
            ];
        }
        // 电中（中专）
        return [
            ['key' => 'pending',   'label' => '待报名'],
            ['key' => 'signed',    'label' => '已报名'],
            ['key' => 'enrolled',  'label' => '已入籍'],
            ['key' => 'graduated', 'label' => '已毕业'],
        ];
    }

    /** 取工单生效流程：优先快照，缺失则按报名类型现取 */
    public static function flowOfSignup(array $signup): array
    {
        $snapshot = $signup['snapshot'] ?? null;
        if (is_string($snapshot) && $snapshot !== '') {
            $snap = json_decode($snapshot, true);
            if (is_array($snap) && !empty($snap['flow'])) {
                $flow = self::normalize($snap['flow']);
                if ($flow) {
                    return $flow;
                }
            }
        } elseif (is_array($snapshot) && !empty($snapshot['flow'])) {
            $flow = self::normalize($snapshot['flow']);
            if ($flow) {
                return $flow;
            }
        }
        return self::flowOfType((string)($signup['signup_type'] ?? ''));
    }

    /** 归一化状态流 */
    public static function normalize($flow): array
    {
        if (is_string($flow)) {
            $flow = json_decode($flow, true);
        }
        $out = [];
        foreach ((array)$flow as $n) {
            if (!is_array($n)) {
                continue;
            }
            $key = trim((string)($n['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $label = trim((string)($n['label'] ?? ''));
            $out[] = ['key' => $key, 'label' => $label !== '' ? $label : $key];
        }
        return $out;
    }

    /** 节点标签 */
    public static function label(array $flow, int $stage): string
    {
        return $flow[$stage - 1]['label'] ?? ('节点' . $stage);
    }

    /** 节点 key */
    public static function key(array $flow, int $stage): string
    {
        return $flow[$stage - 1]['key'] ?? ('stage' . $stage);
    }

    /** 是否允许从 from 流转到 to（只允许 +1 前进或 -1 退回，禁止跨步） */
    public static function canTransit(int $from, int $to, int $total): bool
    {
        if ($to < 1 || $to > $total) {
            return false;
        }
        return abs($to - $from) <= 1;
    }
}
