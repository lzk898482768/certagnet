<?php
namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
use app\service\CertExpireService;

/**
 * 证书到期检测定时任务
 * 用法：
 *   php think cert:checkexpire              # 正常检测并推送
 *   php think cert:checkexpire --dry-run    # 只体检，不写提醒/推送日志（排查用）
 *   php think cert:checkexpire --catch-up   # 补发此前漏跑的提醒节点（证书 + 借调，宽限 3 天）
 */
class CheckCertExpire extends Command
{
    protected function configure()
    {
        $this->setName('cert:checkexpire')
             ->addOption('dry-run', null, Option::VALUE_NONE, '只体检不写库')
             ->addOption('catch-up', null, Option::VALUE_NONE, '补发漏跑的提醒节点')
             ->setDescription('检测证书到期并触发多渠道提醒推送');
    }

    protected function execute(Input $input, Output $output)
    {
        $dryRun  = (bool)$input->getOption('dry-run');
        $catchUp = (bool)$input->getOption('catch-up');
        $output->writeln('[' . date('Y-m-d H:i:s') . '] 开始检测证书到期...' . ($dryRun ? '（体检模式）' : ''));
        try {
            $stats = CertExpireService::check($dryRun, $catchUp);
        } catch (\Throwable $e) {
            // 任务级兜底：即使整次检测异常也要留下明确日志，便于 crontab 邮件/监控发现
            \think\facade\Log::error('[cert-expire] 任务失败: ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $output->writeln('检测失败：' . $e->getMessage());
            return 1;
        }
        $output->writeln('检测完成：' . json_encode($stats, JSON_UNESCAPED_UNICODE));
        if (!empty($stats['errors'])) {
            $output->writeln('异常数据（已跳过，不影响其他记录）：');
            foreach ($stats['errors'] as $msg) {
                $output->writeln('  - ' . $msg);
            }
        }
        if (!empty($stats['skipped'])) {
            $output->writeln('脏数据（已跳过）：');
            foreach ($stats['skipped'] as $msg) {
                $output->writeln('  - ' . $msg);
            }
        }
        if (!empty($stats['items'])) {
            $output->writeln('命中明细：');
            foreach ($stats['items'] as $it) {
                $output->writeln(sprintf(
                    '  - 证书#%d %s 剩余%d天 节点%s => %s %s',
                    $it['cert_id'], $it['cert_name'], $it['remain'], $it['node'],
                    $it['result'] === 'hit' ? '已触发' : '被阻断',
                    $it['reason'] ?: ''
                ));
            }
        }
        if (!empty($stats['cert_expired'])) {
            $output->writeln('自动标记已过期证书：' . $stats['cert_expired'] . ' 张');
        }
        return ($stats['push_fail'] ?? 0) > 0 ? 1 : 0;
    }
}
