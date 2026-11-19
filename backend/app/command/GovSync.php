<?php
namespace app\command;

use app\service\GovProgressService;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;

/**
 * 政务网申报进度同步（v2.62，v2.99.44 取消 20 小时间隔）
 *
 * 用法：
 *   php think gov:sync                      # 每次定时任务都同步（v2.99.44 起取消 20 小时限制）
 *   php think gov:sync --force              # 同上；保留该参数以兼容已有定时任务
 *   php think gov:sync --all                # 连同已办结记录一起刷新
 *   php think gov:sync --limit=50           # 本次最多同步 50 条
 *   php think gov:sync --id=12              # 只同步指定记录
 *
 * Docker scheduler / 宝塔计划任务每小时循环执行，命令内部不再做「未满 N 小时跳过」；
 * 若需降频，在 config/gov.php 的 daily_min_hours 设为大于 0 的小时数。
 */
class GovSync extends Command
{
    protected function configure()
    {
        $this->setName('gov:sync')
             ->addOption('force', null, Option::VALUE_NONE, '立即同步（当前默认已无间隔限制，保留以兼容旧定时任务）')
             ->addOption('all', null, Option::VALUE_NONE, '连同已办结记录一起刷新')
             ->addOption('limit', null, Option::VALUE_REQUIRED, '本次最多同步条数（0=不限）', '0')
             ->addOption('id', null, Option::VALUE_REQUIRED, '只同步指定记录 ID', '0')
             ->setDescription('同步河南政务服务网申报进度');
    }

    protected function execute(Input $input, Output $output)
    {
        $force = (bool)$input->getOption('force');
        $all   = (bool)$input->getOption('all');
        $limit = (int)$input->getOption('limit');
        $id    = (int)$input->getOption('id');

        GovProgressService::ensureTables();

        if ($id > 0) {
            $r = GovProgressService::syncRecord($id);
            $output->writeln(($r['ok'] ? '[OK] ' : '[FAIL] ') . ($r['msg'] ?? ''));
            return $r['ok'] ? 0 : 1;
        }

        if (!$force && !GovProgressService::shouldRunDaily()) {
            $output->writeln('[' . date('Y-m-d H:i:s') . '] 政务网进度：' . GovProgressService::lastRunAt()
                . ' 已同步，未满 ' . GovProgressService::dailyMinHours() . ' 小时，跳过');
            return 0;
        }
        $output->writeln('[' . date('Y-m-d H:i:s') . '] 开始同步政务网申报进度...');
        $summary = GovProgressService::syncPending([
            'limit'            => $limit,
            'include_finished' => $all,
        ]);

        foreach ($summary['items'] as $item) {
            if (empty($item['ok'])) {
                $output->writeln('  [FAIL] #' . ($item['id'] ?? '') . ' ' . ($item['msg'] ?? ''));
            }
        }
        $output->writeln(sprintf(
            '同步完成：共 %d 条，成功 %d 条，失败 %d 条',
            $summary['total'], $summary['ok'], $summary['fail']
        ));

        GovProgressService::markRun($summary, 'schedule');
        return $summary['fail'] > 0 ? 1 : 0;
    }
}
