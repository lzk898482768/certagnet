<?php
namespace app\controller\admin;

use app\service\UpgradeService;

/**
 * 在线升级（v2.99.1）
 *
 *  - GET  /admin/upgrade/info       聚合信息（版本/最近检查/备份/历史/能力）
 *  - POST /admin/upgrade/check      检查 Gitee 最新版本
 *  - POST /admin/upgrade/backup     手动备份（程序文件 + 数据库）
 *  - POST /admin/upgrade/download   Gitee 在线升级（自动备份 → 下载 → 替换）
 *  - POST /admin/upgrade/upload     上传升级包 zip
 *  - POST /admin/upgrade/apply      应用已上传的升级包（自动备份 → 替换）
 *  - POST /admin/upgrade/restore    恢复备份（files=程序文件 / db=数据库）
 *  - POST /admin/upgrade/delete-backup 删除备份文件
 *
 * 安全：仅授予「在线升级」功能权限的角色可操作（默认仅超级管理员）。
 */
class Upgrade extends AdminController
{
    /**
     * 飞牛版关闭在线升级：所有接口返回 404，避免绕过菜单直接调用。
     */
    private function disabled()
    {
        if (UpgradeService::enabled()) {
            return null;
        }
        return json(r_error('飞牛版已关闭在线升级', 404));
    }

    protected function boot(): void
    {
        UpgradeService::ensureDirs();
    }

    public function info()
    {
        if ($blocked = $this->disabled()) {
            return $blocked;
        }
        $this->boot();
        if (!$this->can('upgrade', 'view')) {
            return json(r_error('无查看在线升级权限', 403));
        }
        return json(r_success(UpgradeService::info()));
    }

    public function check()
    {
        if ($blocked = $this->disabled()) {
            return $blocked;
        }
        $this->boot();
        if (!$this->can('upgrade', 'run')) {
            return json(r_error('无执行升级权限', 403));
        }
        $r = UpgradeService::checkGitee();
        UpgradeService::saveLastCheck($r);
        if (!$r['ok']) {
            return json(r_error($r['msg']));
        }
        // v2.99.6：main 分支已更新但未打 tag 时，把提示一并回传，避免前端只显示「已是最新」
        return json(r_success([
            'latest'         => $r['latest'],
            'upgradeable'    => $r['upgradeable'],
            'branch_ahead'   => $r['branch_ahead'] ?? false,
            'branch_commit'  => $r['branch_commit'] ?? '',
            'branch_message' => $r['branch_message'] ?? '',
            'branch_time'    => $r['branch_time'] ?? '',
            'notes'          => $r['notes'] ?? '',
            'notes_title'    => $r['notes_title'] ?? '',
            'notes_time'     => $r['notes_time'] ?? '',
            'notes_url'      => $r['notes_url'] ?? '',
            'notes_source'   => $r['notes_source'] ?? '',
            'notes_scope'    => $r['notes_scope'] ?? '',
            'msg'            => $r['msg'] ?? '',
        ]));
    }

    public function backup()
    {
        if ($blocked = $this->disabled()) {
            return $blocked;
        }
        $this->boot();
        if (!$this->can('upgrade', 'run')) {
            return json(r_error('无执行升级权限', 403));
        }
        $r = UpgradeService::createBackup();
        if ($r['ok']) {
            $this->log('在线升级', '手动备份', '程序文件：' . $r['files'] . '，数据库：' . $r['db']);
        }
        return json($r['ok'] ? r_success($r, '备份完成') : r_error($r['msg'], 500));
    }

    public function download()
    {
        if ($blocked = $this->disabled()) {
            return $blocked;
        }
        $this->boot();
        if (!$this->can('upgrade', 'run')) {
            return json(r_error('无执行升级权限', 403));
        }
        $tag = trim((string)$this->request->post('tag', ''));
        if ($tag === '') {
            return json(r_error('缺少版本标签'));
        }
        // 先下载并校验升级包，成功后再备份：避免网络/标签错误时产生无意义的备份
        try {
            UpgradeService::setProgress('download', 5, "正在下载升级包 {$tag}…");
            $dl = UpgradeService::download($tag);
            if (!$dl['ok']) {
                UpgradeService::setProgress('failed', 100, '下载升级包失败：' . $dl['msg'], false, false);
                return json(r_error('下载升级包失败：' . $dl['msg'], 500));
            }
            UpgradeService::setProgress('download', 40, '升级包下载并校验完成，准备升级前备份…');
            // 升级前强制备份（程序文件 + 数据库），任一失败中止
            $bak = UpgradeService::createBackup(true);
            if (!$bak['ok']) {
                @unlink($dl['path']);
                $msg = '升级前备份失败，已中止升级：' . $bak['msg'];
                UpgradeService::setProgress('failed', 100, $msg, false, false);
                return json(r_error($msg, 500));
            }
            $app = UpgradeService::apply($dl['path'], 'gitee', $tag);
            @unlink($dl['path']);
            if (!$app['ok']) {
                return json(r_error('应用升级失败：' . $app['msg'], 500));
            }
            $this->log('在线升级', '在线升级', 'Gitee ' . $tag . '，' . $app['msg']);
            return json(r_success($app, '升级成功，请刷新页面加载新版本'));
        } catch (\Throwable $e) {
            UpgradeService::setProgress('failed', 100, '升级出错：' . $e->getMessage(), false, false);
            return json(r_error('升级出错：' . $e->getMessage(), 500));
        }
    }

    /** 升级进度（前端轮询展示） */
    public function progress()
    {
        if ($blocked = $this->disabled()) {
            return $blocked;
        }
        $this->boot();
        if (!$this->can('upgrade', 'view')) {
            return json(r_error('无查看在线升级权限', 403));
        }
        return json(r_success(UpgradeService::progress()));
    }

    public function upload()
    {
        if ($blocked = $this->disabled()) {
            return $blocked;
        }
        $this->boot();
        if (!$this->can('upgrade', 'run')) {
            return json(r_error('无执行升级权限', 403));
        }
        $file = $this->request->file('file');
        if (!$file) {
            return json(r_error('请选择要上传的升级包', 400));
        }
        if ((int)$file->getSize() > 200 * 1024 * 1024) {
            return json(r_error('升级包不能超过 200MB', 400));
        }
        // 由服务完成落盘与校验（acceptUpload 内部 move_uploaded_file/copy + validateZip），
        // 避免先 move 再复制导致 runtime/upgrade 残留重复包。
        $r = UpgradeService::acceptUpload($file->getPathname(), $file->getOriginalName());
        if (!$r['ok']) {
            return json(r_error($r['msg'], 400));
        }
        return json(r_success([
            'path'    => $r['path'],
            'size'    => $r['size'],
            'message' => $r['message'] ?? '',
        ], '升级包已上传并通过校验'));
    }

    public function apply()
    {
        if ($blocked = $this->disabled()) {
            return $blocked;
        }
        $this->boot();
        if (!$this->can('upgrade', 'run')) {
            return json(r_error('无执行升级权限', 403));
        }
        $path = (string)$this->request->post('path', '');
        if ($path === '' || !is_file($path)) {
            return json(r_error('升级包不存在，请重新上传', 400));
        }
        UpgradeService::setProgress('start', 5, '准备应用升级包…');
        try {
            // 升级前强制备份
            $bak = UpgradeService::createBackup(true);
            if (!$bak['ok']) {
                $msg = '升级前备份失败，已中止升级：' . $bak['msg'];
                UpgradeService::setProgress('failed', 100, $msg, false, false);
                return json(r_error($msg, 500));
            }
            $app = UpgradeService::apply($path, 'upload');
            @unlink($path);
            if (!$app['ok']) {
                return json(r_error('应用升级失败：' . $app['msg'], 500));
            }
            $this->log('在线升级', '上传包升级', $app['msg']);
            return json(r_success($app, '升级成功，请刷新页面加载新版本'));
        } catch (\Throwable $e) {
            UpgradeService::setProgress('failed', 100, '升级出错：' . $e->getMessage(), false, false);
            return json(r_error('升级出错：' . $e->getMessage(), 500));
        }
    }

    public function restore()
    {
        if ($blocked = $this->disabled()) {
            return $blocked;
        }
        $this->boot();
        if (!$this->can('upgrade', 'run')) {
            return json(r_error('无执行升级权限', 403));
        }
        $file = (string)$this->request->post('file', '');
        $type = (string)$this->request->post('type', 'files');
        if ($file === '' || !in_array($type, ['files', 'db'], true)) {
            return json(r_error('参数错误'));
        }
        $r = UpgradeService::restore($file, $type);
        if (!$r['ok']) {
            return json(r_error($r['msg'], 500));
        }
        $this->log('在线升级', '恢复备份', ($type === 'db' ? '数据库' : '程序文件') . ' ' . $file);
        return json(r_success($r, '恢复完成'));
    }

    /** 删除备份文件（仅允许 runtime/backup 下的合法备份名） */
    public function deleteBackup()
    {
        if ($blocked = $this->disabled()) {
            return $blocked;
        }
        $this->boot();
        if (!$this->can('upgrade', 'run')) {
            return json(r_error('无执行升级权限', 403));
        }
        $file = (string)$this->request->post('file', '');
        $r = UpgradeService::deleteBackup($file);
        if (!$r['ok']) {
            return json(r_error($r['msg'], 400));
        }
        $this->log('在线升级', '删除备份', $r['file']);
        return json(r_success($r, '备份已删除'));
    }

    /**
     * v2.99.93：批量删除备份文件。
     * 入参 files 为备份文件名数组；同样受 deleteotp 中间件保护（动作名含 delete）。
     */
    public function batchDeleteBackup()
    {
        if ($blocked = $this->disabled()) {
            return $blocked;
        }
        $this->boot();
        if (!$this->can('upgrade', 'run')) {
            return json(r_error('无执行升级权限', 403));
        }
        $files = $this->request->post('files', []);
        if (!is_array($files)) {
            return json(r_error('参数 files 必须为数组', 400));
        }
        $r = UpgradeService::deleteBackups(array_map('strval', $files));
        if (empty($r['total'])) {
            return json(r_error($r['msg'], 400));
        }
        $this->log('在线升级', '批量删除备份', '删除 ' . $r['deleted'] . ' 个，失败 ' . $r['failed'] . ' 个');
        // 部分失败仍返回 200，由前端依据 failed/errors 提示；全部失败才报错
        if (!$r['ok'] && $r['deleted'] === 0) {
            return json(r_error($r['msg'], 400));
        }
        return json(r_success($r, $r['msg']));
    }
}