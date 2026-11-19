<?php
namespace app\controller\admin;

use app\service\SensitiveAccessService;

/**
 * 敏感信息明文访问审计（v2.99.47，国密合规 P1）
 *
 * 仅超级管理员可查：记录谁在何时、用何设备查看/复制了谁的哪条敏感明文，
 * 以及无权限试探的拒绝事件（granted=0）。审计表本身不落任何明文。
 */
class SensitiveAccess extends AdminController
{
    /** 分页列表：支持对象类型 / 字段 / 用途 / 是否放行 / 关键字 / 时间范围筛选 */
    public function index()
    {
        $this->requireAdmin();
        SensitiveAccessService::ensureTable();

        [$page, $limit] = $this->pageParams();
        $opt = [
            'page'       => $page,
            'limit'      => $limit,
            'owner_type' => trim((string)$this->request->param('owner_type', '')),
            'field'      => trim((string)$this->request->param('field', '')),
            'purpose'    => trim((string)$this->request->param('purpose', '')),
            'granted'    => $this->request->param('granted', ''),
            'keyword'    => trim((string)$this->request->param('keyword', '')),
            'start'      => $this->dayStart(trim((string)$this->request->param('start', ''))),
            'end'        => $this->dayEnd(trim((string)$this->request->param('end', ''))),
        ];
        $res = SensitiveAccessService::paginate($opt);
        // 附带字段/用途字典，供前端渲染筛选与中文标签
        $res['fields']   = SensitiveAccessService::ALLOWED_FIELDS;
        $res['purposes'] = SensitiveAccessService::ALLOWED_PURPOSE;
        return json(r_success($res));
    }

    private function dayStart(string $v): string
    {
        if ($v === '') {
            return '';
        }
        return strlen($v) <= 10 ? $v . ' 00:00:00' : $v;
    }

    private function dayEnd(string $v): string
    {
        if ($v === '') {
            return '';
        }
        return strlen($v) <= 10 ? $v . ' 23:59:59' : $v;
    }
}
