<?php
namespace app\controller\admin;

use app\service\AttachMigrateService;
use app\service\S3StorageService;
use think\facade\Cache;

/**
 * 附件 S3 一键迁移（v2.99.64）
 *
 * 前端分步调用：preview 扫描 → start 建任务 → run 循环处理小批次 → cancel 取消。
 * 任务清单与进度存 Redis（2 小时），同一时间只允许一个迁移任务；迁移逻辑
 * 复用 AttachMigrateService，与 CLI `attach:migrate-s3` 完全一致。
 */
class AttachMigrate extends AdminController
{
    private const JOB_PREFIX = 'attach_migrate_job:';
    private const ACTIVE_KEY = 'attach_migrate_active';
    private const LAST_KEY   = 'attach_migrate_last';
    private const JOB_TTL    = 7200;
    private const MAX_ITEMS  = 20000;

    /** 扫描待迁移数量（不写库、不上传） */
    public function preview()
    {
        $this->requireAdmin();
        if (!S3StorageService::enabled()) {
            return json(r_error('请先保存并启用 S3 存储（当前存储需切换为 S3）', 400));
        }
        $scan = AttachMigrateService::scan();
        return json(r_success([
            'counts'       => $scan['counts'],
            'pending'      => count($scan['items']),
            'local'        => $scan['counts']['local'],
            'webdav'       => $scan['counts']['webdav'],
            'other'        => $scan['counts']['other'],
            's3'           => $scan['counts']['s3'],
            'webdav_ready' => AttachMigrateService::webdavReady(),
            'skipped'      => $scan['skipped'],
            'active_job'   => $this->activeJob(),
        ], 'ok'));
    }

    /** 创建任务；已有进行中的任务则直接返回它（可刷新页面继续） */
    public function start()
    {
        $this->requireAdmin();
        if (!S3StorageService::enabled()) {
            return json(r_error('请先保存并启用 S3 存储（当前存储需切换为 S3）', 400));
        }
        $only = strtolower(trim((string)$this->request->post('only', '')));
        if ($only !== '' && !in_array($only, ['local', 'webdav'], true)) {
            return json(r_error('迁移范围仅支持 local / webdav', 400));
        }
        $deleteSource = (string)$this->request->post('delete_source', '0') === '1';

        $active = $this->activeJob();
        if ($active) {
            return json(r_success(['job' => $this->publicJob($active), 'resumed' => true], '已有迁移任务进行中'));
        }

        $scan = AttachMigrateService::scan($only);
        $items = $scan['items'];
        if (empty($items)) {
            $label = $only === 'local' ? '本机' : ($only === 'webdav' ? 'WebDAV' : '');
            return json(r_error('没有待迁移的' . $label . '附件', 400));
        }
        if (count($items) > self::MAX_ITEMS) {
            return json(r_error('待迁移附件超过 ' . self::MAX_ITEMS . ' 条，请使用命令行 php think attach:migrate-s3 --execute 分批处理', 400));
        }
        // 只有本次范围包含 WebDAV 时才要求 WebDAV 配置；“仅本机”可直接迁移。
        $webdavPending = $only === 'local' ? 0 : ($only === 'webdav' ? count($items) : (int)$scan['counts']['webdav']);
        if ($webdavPending > 0 && !AttachMigrateService::webdavReady()) {
            return json(r_error(
                '检测到 ' . $webdavPending . ' 条 WebDAV 历史附件，但未保存 WebDAV 地址/账号。请在下方「历史 WebDAV 源」填写原地址与账号并保存，或把迁移范围改为「仅本机」。',
                400,
                ['webdav_ready' => false, 'webdav' => $webdavPending]
            ));
        }

        $job = [
            'id'            => bin2hex(random_bytes(8)),
            'status'        => 'running',
            'only'          => $only,
            'delete_source' => $deleteSource,
            'total'         => count($items),
            'offset'        => 0,
            'ok'            => 0,
            'fail'          => 0,
            'failures'      => [],
            'current'       => '',
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
            'finished_at'   => '',
            'created_by'    => (int)($this->request->userId ?? 0),
            'items'         => $items,
        ];
        $this->saveJob($job);
        Cache::set(self::ACTIVE_KEY, $job['id'], self::JOB_TTL);
        $this->log('系统设置', '迁移', '开始 S3 一键迁移：' . $job['total'] . ' 条');
        return json(r_success(['job' => $this->publicJob($job), 'resumed' => false], '迁移任务已创建'));
    }

    /** 处理一个小批次，返回最新进度 */
    public function run()
    {
        $this->requireAdmin();
        $job = $this->loadJob((string)$this->request->post('job_id', ''));
        if (!$job) {
            return json(r_error('迁移任务不存在或已过期，请重新扫描', 400));
        }
        if (in_array($job['status'], ['done', 'cancelled', 'failed'], true)) {
            Cache::set(self::LAST_KEY, $job['id'], self::JOB_TTL);
            return json(r_success(['job' => $this->publicJob($job)], 'ok'));
        }
        if ($job['status'] === 'cancelling') {
            $job = $this->finish($job, 'cancelled');
            $this->log('系统设置', '迁移', '已取消 S3 一键迁移（成功 ' . $job['ok'] . '，失败 ' . $job['fail'] . '）');
            return json(r_success(['job' => $this->publicJob($job)], '已取消'));
        }

        $started = microtime(true);
        $processed = 0;
        $items = $job['items'];
        while ($job['offset'] < $job['total']
            && $processed < AttachMigrateService::BATCH_SIZE
            && (microtime(true) - $started) < AttachMigrateService::BATCH_SECONDS) {
            $item = $items[$job['offset']];
            $job['current'] = $item['table'] . '#' . $item['id'];
            $r = AttachMigrateService::migrateOne($item, (bool)$job['delete_source']);
            $job['offset']++;
            $processed++;
            if ($r['ok']) {
                $job['ok']++;
            } else {
                $job['fail']++;
                if (count($job['failures']) < 100) {
                    $job['failures'][] = $item['table'] . '#' . $item['id'] . '：' . $r['msg'];
                }
            }
            $job['updated_at'] = date('Y-m-d H:i:s');
            $this->saveJob($job);
        }

        if ($job['offset'] >= $job['total']) {
            $job = $this->finish($job, 'done');
            $this->log('系统设置', '迁移', 'S3 一键迁移完成：成功 ' . $job['ok'] . '，失败 ' . $job['fail']);
        }
        return json(r_success(['job' => $this->publicJob($job)], 'ok'));
    }

    /** 取消任务（当前批次处理完后停止） */
    public function cancel()
    {
        $this->requireAdmin();
        $job = $this->loadJob((string)$this->request->post('job_id', ''));
        if (!$job) {
            return json(r_error('迁移任务不存在或已过期', 400));
        }
        if (in_array($job['status'], ['done', 'cancelled', 'failed'], true)) {
            return json(r_success(['job' => $this->publicJob($job)], 'ok'));
        }
        $job['status'] = 'cancelling';
        $job['updated_at'] = date('Y-m-d H:i:s');
        $this->saveJob($job);
        $this->log('系统设置', '迁移', '取消 S3 一键迁移');
        return json(r_success(['job' => $this->publicJob($job)], '正在取消'));
    }

    /** 查询当前进行中（或最近一次）的任务；页面刷新后恢复进度 */
    public function status()
    {
        $this->requireAdmin();
        $job = $this->activeJob() ?: $this->lastJob();
        return json(r_success(['job' => $job ? $this->publicJob($job) : null], 'ok'));
    }

    /** 收尾：标记终态、清理活动标记、记录最近任务 */
    private function finish(array $job, string $status): array
    {
        $job['status'] = $status;
        $job['finished_at'] = date('Y-m-d H:i:s');
        $job['current'] = '';
        $this->saveJob($job);
        Cache::delete(self::ACTIVE_KEY);
        Cache::set(self::LAST_KEY, $job['id'], self::JOB_TTL);
        return $job;
    }

    private function saveJob(array $job): void
    {
        Cache::set(self::JOB_PREFIX . $job['id'], $job, self::JOB_TTL);
    }

    private function loadJob(string $id): ?array
    {
        $id = preg_replace('/[^a-f0-9]/i', '', $id);
        if ($id === '') {
            return null;
        }
        $job = Cache::get(self::JOB_PREFIX . $id);
        return is_array($job) ? $job : null;
    }

    private function activeJob(): ?array
    {
        $id = (string)Cache::get(self::ACTIVE_KEY, '');
        if ($id === '') {
            return null;
        }
        $job = $this->loadJob($id);
        if (!$job) {
            Cache::delete(self::ACTIVE_KEY);
            return null;
        }
        if (in_array($job['status'], ['done', 'cancelled', 'failed'], true)) {
            Cache::delete(self::ACTIVE_KEY);
            Cache::set(self::LAST_KEY, $job['id'], self::JOB_TTL);
            return null;
        }
        return $job;
    }

    private function lastJob(): ?array
    {
        $id = (string)Cache::get(self::LAST_KEY, '');
        return $id === '' ? null : $this->loadJob($id);
    }

    /** 返回给前端的进度（去掉大清单） */
    private function publicJob(array $job): array
    {
        unset($job['items']);
        $job['processed'] = (int)$job['offset'];
        $job['percent'] = $job['total'] > 0 ? (int)floor($job['offset'] * 100 / $job['total']) : 0;
        $job['remaining'] = max(0, (int)$job['total'] - (int)$job['offset']);
        $job['failure_count'] = (int)$job['fail'];
        return $job;
    }
}
