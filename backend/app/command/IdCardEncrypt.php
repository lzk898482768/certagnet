<?php
namespace app\command;

use app\service\IdCardBackfillService;
use app\service\IdCardSchemaService;
use app\service\IdCardService;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;

/**
 * 存量身份证号加密回填（v2.99.59，v2.99.60 起逻辑抽到 IdCardBackfillService）
 *
 * 把 staff / exam_customer / edu_signup 三张表的明文身份证号转为 SM4 密文，
 * 并补齐盲索引 id_card_hash（HMAC-SM3）。命令幂等、可重入：
 *   - 已是 sm4$1$ 密文的行跳过（仅在 hash 缺失/不一致时补 hash）；
 *   - 空值统一把 hash 置 NULL（唯一索引下允许多个 NULL）；
 *   - 按主键区间分批，UPDATE 带原值做乐观校验，防并发覆盖。
 *
 * 用法：
 *   php think idcard:encrypt --dry-run              # 只统计，不写库
 *   php think idcard:encrypt                        # 正式回填（三张表）
 *   php think idcard:encrypt --table=staff          # 只处理一张表
 *   php think idcard:encrypt --batch=1000 --from-id=0
 *
 * 说明：
 *   - 在线升级完成后会自动回填一次，运行时也会按 10 分钟节流自动补齐，
 *     正常情况下无需手工执行本命令；它主要用于排障与大批量一次性处理；
 *   - 回填前建议先备份三张表（本命令不自动 dump，避免大库锁表）；
 *   - 回填完成后自动删除旧索引 uk_id_card / idx_idcard（见 IdCardSchemaService）；
 *   - 改代码/升级后需重启 PHP-FPM（opcache.validate_timestamps=0）。
 */
class IdCardEncrypt extends Command
{
    protected function configure()
    {
        $this->setName('idcard:encrypt')
            ->addOption('table', null, Option::VALUE_OPTIONAL, '指定表：staff / exam_customer / edu_signup / all', 'all')
            ->addOption('batch', null, Option::VALUE_OPTIONAL, '每批处理行数', 500)
            ->addOption('from-id', null, Option::VALUE_OPTIONAL, '从指定主键之后开始（断点续跑）', 0)
            ->addOption('dry-run', null, Option::VALUE_NONE, '只统计不写库')
            ->setDescription('把存量身份证号转为 SM4 密文并补盲索引（幂等，可重复执行）');
    }

    protected function execute(Input $input, Output $output): int
    {
        $tableOpt = strtolower(trim((string)$input->getOption('table')));
        $batch    = max(50, (int)$input->getOption('batch'));
        $fromId   = max(0, (int)$input->getOption('from-id'));
        $dryRun   = (bool)$input->getOption('dry-run');

        $targets = array_keys(IdCardSchemaService::TABLES);
        if ($tableOpt !== '' && $tableOpt !== 'all') {
            if (!in_array($tableOpt, $targets, true)) {
                $output->writeln('<error>--table 仅支持：' . implode(' / ', $targets) . ' / all</error>');
                return 1;
            }
            $targets = [$tableOpt];
        }

        $output->writeln('密钥来源：' . IdCardService::keySource()
            . '（建议在 backend/.env 显式配置 ID_CARD_SM4_KEY，更换密钥会导致历史密文无法解密）');
        $output->writeln('模式：' . ($dryRun ? '干跑（dry-run，不写库）' : '正式执行'));

        $r = IdCardBackfillService::run([
            'tables'  => $targets,
            'batch'   => $batch,
            'fromId'  => $fromId,
            'dryRun'  => $dryRun,
            'logger'  => static function (string $line, string $level = 'info') use ($output) {
                $output->writeln($level === 'error' ? '<error>' . $line . '</error>' : $line);
            },
        ]);

        $total = $r['total'];
        $output->writeln('');
        $output->writeln('扫描 ' . $total['scan'] . ' 行；加密 ' . $total['encrypted']
            . ' 行；补/修 hash ' . $total['hash'] . ' 行；清空 hash ' . $total['cleared']
            . ' 行；跳过 ' . $total['skip'] . ' 行；失败 ' . $total['fail'] . ' 行。');
        if ($dryRun) {
            $output->writeln('<comment>干跑结束，未写库。去掉 --dry-run 正式执行。</comment>');
        } else {
            $output->writeln('<info>回填完成。如已升级代码，请重启 PHP-FPM 后再验证页面。</info>');
        }
        return $total['fail'] > 0 ? 2 : 0;
    }
}
