<?php
namespace app\controller\admin;

use app\service\EduSchemaService;
use think\facade\Db;

/**
 * 学历报名-第三方报名机构（v2.99）
 *
 *  - GET  /admin/edu-agency/options  启用机构下拉（工单表单用）
 *  - GET  /admin/edu-agency/detail   机构详情
 *  - POST /admin/edu-agency/save     新增/编辑（有 id=编辑）
 *  - POST /admin/edu-agency/delete   软删（被工单引用时拒绝）
 *  - GET  /admin/edu-agency          机构列表
 */
class EduAgency extends AdminController
{
    protected function boot(): void
    {
        EduSchemaService::ensureTables();
    }

    public function index()
    {
        $this->boot();
        [$page, $limit] = $this->pageParams();
        $q = Db::name('edu_agency')->where('status', 1);
        $keyword = trim((string)$this->request->param('keyword', ''));
        if ($keyword !== '') {
            $q->where(function ($w) use ($keyword) {
                $w->whereLike('name', "%{$keyword}%")
                  ->whereOr('contact', 'like', "%{$keyword}%")
                  ->whereOr('phone', 'like', "%{$keyword}%");
            });
        }
        $total = (clone $q)->count();
        $list = (clone $q)->order('sort asc,id desc')->page($page, $limit)->select()->toArray();
        foreach ($list as &$r) {
            $r['used_count'] = Db::name('edu_signup')
                ->where('agency_id', $r['id'])->where('status', 1)->count();
        }
        unset($r);
        return json(r_success(['list' => $list, 'total' => $total]));
    }

    /** 启用机构下拉（不分页，工单表单） */
    public function options()
    {
        $this->boot();
        $list = Db::name('edu_agency')->where('status', 1)
            ->field('id,name,contact,phone,address')
            ->order('sort asc,id desc')->limit(500)->select()->toArray();
        return json(r_success(['list' => $list]));
    }

    public function detail()
    {
        $this->boot();
        $id = (int)$this->request->param('id', 0);
        $row = Db::name('edu_agency')->where('id', $id)->where('status', 1)->find();
        if (!$row) {
            return json(r_error('机构不存在'));
        }
        return json(r_success($row));
    }

    public function save()
    {
        $this->boot();
        if (!$this->can('eduagency', 'edit')) {
            return json(r_error('无保存权限', 403));
        }
        $p = $this->request->param();
        $id = (int)($p['id'] ?? 0);
        $name = trim((string)($p['name'] ?? ''));
        if ($name === '') {
            return json(r_error('请填写机构名称'));
        }
        $dup = Db::name('edu_agency')->where('name', $name)->where('id', '<>', $id)->where('status', 1)->find();
        if ($dup) {
            return json(r_error('机构名称已存在'));
        }
        $data = [
            'name'    => mb_substr($name, 0, 150),
            'contact' => mb_substr(trim((string)($p['contact'] ?? '')), 0, 50),
            'phone'   => mb_substr(trim((string)($p['phone'] ?? '')), 0, 30),
            'address' => mb_substr(trim((string)($p['address'] ?? '')), 0, 200),
            'sort'    => (int)($p['sort'] ?? 0),
            'remark'  => mb_substr(trim((string)($p['remark'] ?? '')), 0, 255),
            'status'  => 1,
        ];
        if ($id > 0) {
            Db::name('edu_agency')->where('id', $id)->update($data + ['update_time' => date('Y-m-d H:i:s')]);
        } else {
            $data['create_by'] = (int)$this->request->userId;
            $data['create_time'] = date('Y-m-d H:i:s');
            $id = (int)Db::name('edu_agency')->insertGetId($data);
        }
        $this->log('学历报名机构', $id ? '编辑机构' : '新增机构', $name);
        return json(r_success(['id' => $id], '保存成功'));
    }

    public function delete()
    {
        $this->boot();
        if (!$this->can('eduagency', 'delete')) {
            return json(r_error('无删除权限', 403));
        }
        $id = (int)$this->request->param('id', 0);
        $used = Db::name('edu_signup')->where('agency_id', $id)->where('status', 1)->count();
        if ($used > 0) {
            return json(r_error('该机构已被 ' . $used . ' 条报名工单引用，不能删除（可停用）'));
        }
        $name = (string)Db::name('edu_agency')->where('id', $id)->value('name');
        Db::name('edu_agency')->where('id', $id)->update(['status' => 0, 'update_time' => date('Y-m-d H:i:s')]);
        $this->log('学历报名机构', '删除机构', $name);
        return json(r_success([], '删除成功'));
    }
}
