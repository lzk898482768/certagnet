<?php
namespace app\controller\admin;

use think\facade\Db;
use app\service\RecycleBinService;

/**
 * 全局回收站（v2.52）：删除缓冲的查看 / 恢复 / 彻底删除。
 * 仅管理员可访问（快照含满表数据，恢复属高危管理动作）。
 */
class RecycleBin extends AdminController
{
    public function index()
    {
        $this->requireAdmin();
        // v2.99.2：老库/新装库可能从未发生过删除，recycle_bin 表尚未创建。
        // 列表接口也必须先自愈建表，否则首次点开回收站会因「表不存在」直接 500，
        // 生产环境（APP_DEBUG=false）被统一显示为「系统错误」。
        RecycleBinService::ensureTable();
        $page   = max(1, (int)$this->request->param('page', 1));
        $limit  = min(100, max(1, (int)$this->request->param('limit', 15)));
        $module = trim((string)$this->request->param('module', ''));
        $q      = Db::name('recycle_bin')->order('id', 'desc');
        if ($module !== '' && isset(RecycleBinService::MODULES[$module])) {
            $q->where('module', $module);
        }
        $total = $q->count();
        $rows  = $q->page($page, $limit)->select()->toArray();
        foreach ($rows as &$r) {
            [$t, $label] = RecycleBinService::MODULES[$r['module']] ?? [$r['module'], $r['module']];
            $r['table']        = $t;
            $r['module_label'] = $label;
            $r['related_cnt']  = 0;
            $rel = json_decode((string)$r['related'], true);
            if (is_array($rel)) {
                foreach ($rel as $x) {
                    $r['related_cnt'] += is_array($x['rows'] ?? null) ? count($x['rows']) : 0;
                }
            }
            $r['deleted_by_name'] = (string)(Db::name('sys_user')->where('id', (int)$r['deleted_by'])->value('real_name') ?: '');
            $r['restored'] = !empty($r['restored_at']);
        }
        unset($r);
        return json(r_success(['list' => $rows, 'total' => $total, 'modules' => RecycleBinService::MODULES]));
    }

    public function restore()
    {
        $this->requireAdmin();
        $id = (int)$this->request->post('id', 0);
        if ($id <= 0) {
            return json(r_error('参数错误', 400));
        }
        $r = RecycleBinService::restore($id);
        $this->log('回收站', $r['ok'] ? '恢复' : '恢复失败', "#{$id}");
        return $r['ok'] ? json(r_success([], $r['msg'])) : json(r_error($r['msg'], 400));
    }

    public function purge()
    {
        $this->requireAdmin();
        $id = (int)$this->request->post('id', 0);
        if ($id <= 0) {
            return json(r_error('参数错误', 400));
        }
        RecycleBinService::purge($id);
        $this->log('回收站', '彻底删除', "#{$id}");
        return json(r_success([], '已彻底删除（快照清除，不可恢复）'));
    }

    public function clear()
    {
        $this->requireAdmin();
        RecycleBinService::clearAll();
        $this->log('回收站', '清空', '全部快照');
        return json(r_success([], '回收站已清空'));
    }
}
