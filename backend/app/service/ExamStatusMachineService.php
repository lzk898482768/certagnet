<?php
namespace app\service;

/**
 * 报名状态机引擎（配置驱动）
 *
 * 移植自 exam-reg StatusMachineService。
 * 状态流结构：[{ key, label }]，节点序号 1-based，
 * 工单 exam_signup.stage 即当前节点序号，节点时间存 stage_times JSON。
 * 生效状态流：业务组合覆盖优先，否则用业务动作默认。
 */
class ExamStatusMachineService
{
    /** 取生效状态流：业务组合覆盖优先，否则用动作默认 @return array<int,array{key:string,label:string}> */
    public static function flowOf(array $config, array $action): array
    {
        $flow = $config['status_flow'] ?? null;
        if (empty($flow)) {
            $flow = $action['status_flow'] ?? null;
        }
        if (is_string($flow)) {
            $flow = json_decode($flow, true);
        }
        return self::normalize($flow);
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
            $key = (string)($n['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $out[] = ['key' => $key, 'label' => (string)($n['label'] ?? $key)];
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
