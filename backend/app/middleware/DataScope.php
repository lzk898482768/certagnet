<?php
namespace app\middleware;

use app\model\UserCompanyRel;
use app\service\DataScopeSchemaService;
use app\service\DeptScopeService;
use think\exception\HttpResponseException;
use app\common\Response as R;

/**
 * 数据权限中间件
 *
 * 双维度解析（v2.14）：
 *  - 企业维度：admin 全部；其他账号按 user_company_rel 授权企业。
 *    兼容客户侧账号；内部办公账号可以不关联企业（不再直接 403）。
 *  - 部门维度：按 sys_user.data_scope（all/dept_tree/dept/self）解析，
 *    结果写入 $request->deptScope，供 DeptScopeService 注入查询。
 *
 * 结果写入：
 *  - $request->dataScopeAll(bool)  企业维度是否全部
 *  - $request->companyIds(array)   授权企业ID集合（空=未单独授权企业，v2.77 起
 *    由 AdminController::visibleCompanyIds() 按部门范围换算，不再直接视为无权限）
 *  - $request->deptScope(array)    {scope, dept_ids, level}
 */
class DataScope
{
    public function handle($request, \Closure $next)
    {
        // v2.99.36：数据权限基座表结构运行时自愈（进程内幂等，失败不阻断）
        DataScopeSchemaService::ensure();

        $user = $request->user;
        if ($user['role_code'] === 'admin') {
            $request->dataScopeAll = true;
            $request->companyIds    = [];
        } else {
            $ids = UserCompanyRel::where('user_id', $user['id'])->column('company_id');
            // 内部账号未关联企业不再 403：企业维度无权限（空集合），
            // 由部门维度接管证照/人员等列表的数据范围。
            $request->dataScopeAll = false;
            $request->companyIds    = $ids;
        }
        // 部门数据权限（admin 内部解析为 all）
        $request->deptScope = DeptScopeService::resolve(is_array($user) ? $user : []);
        return $next($request);
    }
}
