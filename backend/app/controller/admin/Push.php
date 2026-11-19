<?php
namespace app\controller\admin;

use app\service\CertExpireService;

/**
 * 推送中心：手动检测 / 推送体检 / 测试推送的统一入口
 *
 * 背景：到期提醒依赖服务器定时任务（php think cert:checkexpire）。
 * 定时任务未配置、渠道未配置、接收人为空、节点未命中都会导致「推送毫无动静」，
 * 且旧版在阻断环节全程静默，无法定位。这里把「体检」和「手动检测」做成接口。
 */
class Push extends AdminController
{
    /**
     * 推送体检（只读，不写任何数据）
     * 返回：渠道、接收人、企业提醒配置、证书命中情况、干跑结果、阻断原因清单
     */
    public function diagnose()
    {
        try {
            $data = CertExpireService::diagnose();
        } catch (\Throwable $e) {
            return json(r_error('体检失败: ' . $e->getMessage(), 500));
        }
        return json(r_success($data));
    }

    /**
     * 立即执行一次到期检测（等同于手动跑一次定时任务）
     * post: dry_run=1 只体检不写库；catch_up=1 补发此前漏跑的提醒节点
     */
    public function runCheck()
    {
        $dryRun  = (int)$this->request->post('dry_run', 0) === 1;
        $catchUp = (int)$this->request->post('catch_up', 0) === 1;
        try {
            $stats = CertExpireService::check($dryRun, $catchUp);
        } catch (\Throwable $e) {
            return json(r_error('检测失败: ' . $e->getMessage(), 500));
        }
        if (!$dryRun) {
            $this->log('到期提醒', '手动检测', '补发=' . ($catchUp ? '是' : '否'));
        }
        $msg = $dryRun
            ? '体检完成'
            : "检测完成：新增提醒 {$stats['alert_count']} 条，推送 {$stats['push_count']} 条，失败 {$stats['push_fail']} 条";
        return json(r_success($stats, $msg));
    }
}
