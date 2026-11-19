<?php
namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
use app\service\AttachMigrateService;
use app\service\S3StorageService;

/**
 * 存量附件迁移到 S3 对象存储（v2.99.48，v2.99.64 抽出服务）
 *
 * 把系统里所有「本机 uploads/」与「webdav://」历史附件搬到当前配置的 S3 桶，
 * 并把各表保存的路径 token 改写成 s3://<对象 key>。访问 URL 是读取时由
 * CertAttachService::url() 现算的，故只迁移存储路径列，不动 file_url 快照列。
 *
 * 安全设计：
 *   - 默认 dry-run，只统计不写库、不上传；加 --execute 才真正执行；
 *   - 每个文件上传成功后才回写路径；单文件失败只记录并继续，不中断整批；
 *   - 本机文件上传后默认保留（不删源），加 --delete-source 才删除本机源文件；
 *     WebDAV 源对象始终保留（远端删除风险高，由人工择期清理）；
 *   - 已是 s3:// 的记录自动跳过，可重复执行（幂等）；
 *   - 迁移前务必先在「系统设置 → 附件存储」保存并测试通过 S3 配置，并把当前存储切到 S3；
 *   - 历史 webdav:// 附件需要保留原 WebDAV 地址与账号：迁移时会忽略 attach_storage
 *     开关读取已保存的 WebDAV 配置（当前存储保持 S3 即可），无需切回 WebDAV。
 *
 * 用法：
 *   php think attach:migrate-s3                # 预检 + 干跑，列出将迁移的数量
 *   php think attach:migrate-s3 --execute      # 正式迁移
 *   php think attach:migrate-s3 --execute --delete-source   # 迁移后删本机源文件
 *   php think attach:migrate-s3 --execute --only=local      # 只迁本机 uploads（不迁 webdav）
 */
class AttachMigrateS3 extends Command
{
    protected function configure()
    {
        $this->setName('attach:migrate-s3')
            ->addOption('execute', null, Option::VALUE_NONE, '正式执行（缺省仅干跑统计，不写库不上传）')
            ->addOption('delete-source', null, Option::VALUE_NONE, '正式迁移后删除本机 uploads 源文件（WebDAV 源始终保留）')
            ->addOption('only', null, Option::VALUE_OPTIONAL, '只迁移指定来源：local 或 webdav', '')
            ->setDescription('把本机/WebDAV 存量附件迁移到 S3 对象存储并改写路径（默认干跑）');
    }

    protected function execute(Input $input, Output $output): int
    {
        $execute = (bool)$input->getOption('execute');
        $deleteSource = (bool)$input->getOption('delete-source');
        $only = strtolower(trim((string)$input->getOption('only')));
        if ($only !== '' && !in_array($only, ['local', 'webdav'], true)) {
            $output->writeln('<error>--only 仅支持 local 或 webdav</error>');
            return 1;
        }

        $cfg = S3StorageService::config();
        if (!$cfg) {
            $output->writeln('<error>S3 存储未启用或配置不完整：请先在「系统设置 → 附件存储」保存并测试通过 S3 配置。</error>');
            return 1;
        }
        $output->writeln('目标桶：' . $cfg['bucket'] . ' @ ' . $cfg['endpoint'] . '（' . ($cfg['path_style'] ? 'path-style' : 'virtual-host') . '）');
        $output->writeln('模式：' . ($execute ? '正式执行' : '干跑（dry-run，不写库不上传）'));
        if ($only !== '') {
            $output->writeln('仅迁移来源：' . $only);
        }
        $output->writeln('');

        $scan = AttachMigrateService::scan($only);
        foreach ($scan['skipped'] as $note) {
            $output->writeln('[skip] ' . $note . '，跳过');
        }
        $items = $scan['items'];
        $counts = $scan['counts'];
        $pendingAll = $counts['local'] + $counts['webdav'];
        $filteredOut = $only === '' ? 0 : max(0, $pendingAll - count($items));
        $output->writeln('待迁移：' . count($items) . '（本机 ' . $counts['local'] . ' / WebDAV ' . $counts['webdav'] . '）');
        if ($counts['s3'] > 0) {
            $output->writeln('已是 S3（自动跳过）：' . $counts['s3']);
        }
        if ($counts['webdav'] > 0 && !AttachMigrateService::webdavReady()) {
            $output->writeln('<comment>提示：存在 WebDAV 历史附件，但未保存 WebDAV 地址/账号，WebDAV 项将失败。请到「系统设置 → 附件存储 → WebDAV」填写原地址与账号（当前存储可保持 S3）。</comment>');
        }
        $output->writeln('');

        $totalOk = 0;
        $totalFail = 0;
        $failures = [];

        foreach ($items as $item) {
            $label = $item['table'] . '#' . $item['id'];
            if (!$execute) {
                continue;
            }
            $r = AttachMigrateService::migrateOne($item, $deleteSource);
            if ($r['ok']) {
                $totalOk++;
                $output->writeln('[ok] ' . $label . ' ' . $item['kind'] . ' -> ' . $r['token']);
            } else {
                $totalFail++;
                $failures[] = $label . '：' . $r['msg'];
                $output->writeln('<error>[fail] ' . $label . '：' . $r['msg'] . '</error>');
            }
        }

        $output->writeln('');
        $output->writeln('扫描记录：' . ($pendingAll + $counts['other']) . '，待迁移：' . count($items)
            . '，成功：' . $totalOk . '，跳过：' . ($counts['other'] + $filteredOut) . '，失败：' . $totalFail);
        if (!$execute) {
            $output->writeln('<info>干跑结束。确认无误后加 --execute 正式迁移。</info>');
            return 0;
        }
        if ($totalFail > 0) {
            $output->writeln('<comment>以下项目失败（可修正后重跑，已成功的会自动跳过）：</comment>');
            foreach (array_slice($failures, 0, 50) as $f) {
                $output->writeln('  - ' . $f);
            }
            return 1;
        }
        $output->writeln('<info>迁移完成。建议保留原存储一段时间确认无误后再清理源文件。</info>');
        return 0;
    }
}
