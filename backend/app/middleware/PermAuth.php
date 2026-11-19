<?php
namespace app\middleware;

use app\model\SysRole;
use app\service\PermissionCatalog;
use app\service\PermissionMap;
use think\exception\HttpResponseException;

/**
 * 接口级权限守卫（v2.69）
 *
 * 目标：让「菜单里看不见」与「接口调不通」同源，堵住
 *   「前端隐藏了入口，但直接调接口仍然成功」的越权缺口。
 *
 * 规则（默认拒绝）：
 *   - admin：全部放行；
 *   - 控制器未登记 → 非 admin 一律 403；
 *   - 角色 menu_ids 不含该控制器所属菜单 → 403（空 menu_ids = 不限制菜单）；
 *   - 写操作再校验功能权限码（sys_role.perms），缺码 → 403；
 *   - GET 共享数据源（字典/自定义字段/组织选择器）放行，写操作仍校验。
 *
 * 结果写入 $request->permCodes（扁平权限码映射，null = 不限制），
 * 供 AdminController::can() 复用，避免每个请求重复查库。
 */
class PermAuth
{
    public function handle($request, \Closure $next)
    {
        $roleCode = (string)($request->roleCode ?? '');
        if ($roleCode === '') {
            throw new HttpResponseException(json(r_error('登录状态异常，请重新登录', 401)));
        }
        if ($roleCode === 'admin') {
            $request->permCodes = null; // 不限制
            return $next($request);
        }

        $role = SysRole::find((int)($request->role_id ?? 0));
        if (!$role || (int)$role->status !== 1) {
            throw new HttpResponseException(json(r_error('当前角色已停用，请联系管理员', 403)));
        }

        $permCodes = PermissionCatalog::normalize($role->perms);
        $request->permCodes = $permCodes;

        $controller = (string)$request->controller();
        $action     = (string)$request->action();
        // 路由中间件阶段 $request->controller()/action() 可能尚未解析，
        // 此时从当前路由规则（app\controller\admin\Xxx@yyy）兜底提取。
        if ($controller === '' || $action === '') {
            $rule  = $request->rule();
            $route = $rule ? $rule->getRoute() : '';
            if (is_string($route) && $route !== '') {
                $target = explode('@', $route);
                if ($controller === '') { $controller = $target[0] ?? ''; }
                if ($action === '')     { $action     = $target[1] ?? ''; }
            }
        }
        if (PermissionMap::isOpen($controller)) {
            return $next($request);
        }

        $method  = strtoupper((string)$request->method());
        $isRead  = in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);
        if ($isRead && (PermissionMap::isSharedRead($controller)
            || PermissionMap::isSharedReadAction($controller, $action))) {
            return $next($request);
        }

        // 跨模块「快捷新建引用主数据」共享写通道：仅跳过菜单级守卫。
        // 功能级写权限（须持有任一业务写权限码）在 MasterData 控制器内强制校验，
        // 纯只读角色没有任何写权限码，仍会被 403 拒绝。
        if (!$isRead && PermissionMap::isSharedWriteAction($controller, $action)) {
            return $next($request);
        }

        // ① 菜单级：空 menu_ids = 不限制菜单
        //    注意：SysRole 模型对 menu_ids 有访问器（返回数组），这里必须取原始值
        $rawMenuIds = $role->getData('menu_ids');
        $menuIds = array_values(array_filter(array_map('intval', explode(',', (string)$rawMenuIds))));
        $allowed = PermissionMap::menusFor($controller);
        if (!$allowed) {
            throw new HttpResponseException(json(r_error('该功能未开放给当前角色', 403)));
        }
        if ($menuIds && !array_intersect($allowed, $menuIds)) {
            throw new HttpResponseException(json(r_error('当前角色无权访问该功能', 403)));
        }

        // ② 功能权限：仅写操作
        if (!$isRead) {
            $code = PermissionMap::requiredPerm($controller, $action, $request);
            if ($code !== null && !PermissionCatalog::allows($permCodes, ...explode(':', $code))) {
                throw new HttpResponseException(json(r_error('当前角色无此操作权限', 403)));
            }
        }

        return $next($request);
    }
}
