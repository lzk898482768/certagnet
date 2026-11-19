<?php
namespace app\controller\admin;

use app\model\OrgDepartment;
use app\model\SysUser;
use think\facade\Db;

/**
 * 组织架构选择器数据源（v2.16）
 *
 *  GET /admin/org/selector
 *  返回「部门（扁平，前端构树）」+「启用中的系统账号（含所属部门与企微绑定情况）」，
 *  统一供以下场景使用：
 *    - 配证任务「可见范围」选择部门 / 人员
 *    - 推送中心「企微接收人」从组织架构选择
 */
class Org extends AdminController
{
    public function selector()
    {
        $onlyWecom = (int)$this->request->param('only_wecom_bound', 0) === 1;

        // 1) 部门（扁平列表，含 parent_id 与账号数）
        $departments = OrgDepartment::order('parent_id', 'asc')
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->field('id,parent_id,name,wecom_dept_id,sort')
            ->select()->toArray();

        // 2) 账号（启用中）
        $uQuery = SysUser::where('status', 1);
        if ($onlyWecom) {
            $uQuery->whereNotNull('wecom_userid')->where('wecom_userid', '<>', '');
        }
        $users = $uQuery->field('id,username,real_name,phone,dept_id,wecom_userid')
            ->order('id', 'asc')->select()->toArray();

        // 多部门归属
        $uids = array_column($users, 'id');
        $relMap = [];
        if ($uids) {
            foreach (Db::name('user_dept_rel')->whereIn('user_id', $uids)->select()->toArray() as $r) {
                $relMap[(int)$r['user_id']][] = (int)$r['dept_id'];
            }
        }

        $deptNames = [];
        foreach ($departments as $d) {
            $deptNames[(int)$d['id']] = $d['name'];
        }

        $out = [];
        foreach ($users as $u) {
            $did = (int)$u['dept_id'];
            $deptIds = $relMap[(int)$u['id']] ?? [];
            if ($did > 0 && !in_array($did, $deptIds, true)) {
                array_unshift($deptIds, $did);
            }
            $out[] = [
                'id'           => (int)$u['id'],
                'username'     => $u['username'],
                'real_name'    => $u['real_name'],
                'label'        => ($u['real_name'] ?: $u['username']) . ($deptNames[$did] ?? '' ? '（' . $deptNames[$did] . '）' : ''),
                'phone'        => $u['phone'] ?? '',
                'dept_id'      => $did,
                'dept_name'    => $deptNames[$did] ?? '',
                'dept_ids'     => array_map('intval', $deptIds),
                'wecom_userid' => (string)($u['wecom_userid'] ?? ''),
                'wecom_bound'  => !empty($u['wecom_userid']),
            ];
        }

        return json(r_success([
            'departments' => $departments,
            'users'       => $out,
        ]));
    }
}
