<?php
namespace app\controller\admin;

use app\model\OrgDepartment as OrgDepartmentModel;
use app\model\SysUser;
use think\facade\Db;

/**
 * 内部组织架构（树形部门）
 */
class OrgDepartment extends AdminController
{
    /** 部门列表（前端构建树），附带各部门账号数 */
    public function index()
    {
        $list = OrgDepartmentModel::order('parent_id', 'asc')
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()->toArray();
        // 各部门系统账号数（按 dept_id 归属）
        $counts = SysUser::field('dept_id, COUNT(*) AS cnt')
            ->group('dept_id')
            ->select()->toArray();
        $map = [];
        foreach ($counts as $c) {
            $map[(int)$c['dept_id']] = (int)$c['cnt'];
        }
        foreach ($list as &$d) {
            $d['user_count'] = $map[(int)$d['id']] ?? 0;
        }
        unset($d);
        return json(r_success(['list' => $list, 'total' => count($list)]));
    }

    /** 新增/编辑部门 */
    public function save()
    {
        $this->requireAdmin();
        $id       = (int)$this->request->post('id', 0);
        $parentId = (int)$this->request->post('parent_id', 0);
        $name     = trim((string)$this->request->post('name', ''));
        if ($name === '') {
            return json(r_error('请输入部门名称', 400));
        }
        if ($id && $parentId === $id) {
            return json(r_error('上级部门不能选择自己', 400));
        }
        if ($id && $parentId > 0) {
            // 防止把部门挂到自己的子孙下（简化：仅检查直接子）
            $child = OrgDepartmentModel::where('parent_id', $id)->find();
            if ($child && (int)$child['id'] === $parentId) {
                return json(r_error('不能挂到自己的下级部门', 400));
            }
        }
        $data = [
            'parent_id' => $parentId,
            'name'      => $name,
            'leader'    => trim((string)$this->request->post('leader', '')),
            'sort'      => (int)$this->request->post('sort', 0),
            'status'    => (int)$this->request->post('status', 1),
            'remark'    => trim((string)$this->request->post('remark', '')),
        ];
        if ($id) {
            OrgDepartmentModel::where('id', $id)->update($data);
            $this->log('组织架构', '更新', "#{$id} {$name}");
        } else {
            $m = OrgDepartmentModel::create($data);
            $id = (int)$m->id;
            $this->log('组织架构', '新增', "#{$id} {$name}");
        }
        return json(r_success(['id' => $id], '保存成功'));
    }

    /** 删除部门（有下级时禁止） */
    public function delete()
    {
        $this->requireAdmin();
        $id = (int)$this->request->post('id', 0);
        if (!$id) {
            return json(r_error('缺少ID', 400));
        }
        if (OrgDepartmentModel::where('parent_id', $id)->count() > 0) {
            return json(r_error('请先删除下级部门', 400));
        }
        OrgDepartmentModel::destroy($id);
        $this->log('组织架构', '删除', "#{$id}");
        return json(r_success([], '删除成功'));
    }
}
