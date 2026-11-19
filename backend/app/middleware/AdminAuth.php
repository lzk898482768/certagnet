<?php
namespace app\middleware;

use think\exception\HttpResponseException;
use app\common\Response as R;

/**
 * 后台接口角色边界（v2.40）
 *
 * /api/admin/* 以内部办公账号为主。客户企业账号（role_code=company）：
 *  - 一律拒绝 POST/PUT/DELETE 等写操作，杜绝客户账号调用后台写接口；
 *  - 仅放行明确列出的只读 GET 接口，且这些接口必须已经在控制器内
 *    按企业/部门数据范围过滤，并完成对象级权限校验；
 *  - 用户、角色、菜单、系统配置、企微、推送、操作日志、冲突中心、
 *    智能匹配、配证任务等内部运营接口不对客户开放。
 *
 * 此前 admin 路由组仅有 auth，客户账号可直接调用后台写接口，属于越权高危问题。
 * 更细的功能权限仍由 AdminController::can() 与各控制器的
 * requireAdmin()/requireAnyRole() 负责。
 */
class AdminAuth
{
    /**
     * 客户企业账号可读的接口白名单（相对 /api/admin/ 的路径）。
     * 新增白名单项前必须确认：列表按数据范围过滤、详情校对象权限、无敏感字段。
     */
    private const COMPANY_READ = [
        'dashboard/stat',
        'company',
        'company/options',
        'company/detail',
        'company/qualifications',
        // v2.93：企业证书集中台账（只读，数据范围由 CompanyCert::buildQuery 限制本企业）
        'company-cert',
        'company-cert/stat',
        'company-cert/detail',
        'staff',
        'staff/options',
        'staff/detail',
        'staff/profile',
        'staff/read',
        'certificate',
        'certificate/detail',
        'secondment',
        'secondment/detail',
        'secondment/free-certs',
        'secondment/stat',
        'contract',
        'contract/detail',
        'alert',
        'alert/unread-count',
        'fee',
        'fee/meta',
        'fee/by-source',
        'fee/stat',
        'fee/summary',
        'fee/receivable-payable',
        'report/summary',
        'report/overview',
        'search',
        'search/detail',
        'register-track',
        'register-track/meta',
        'cert-renewal',
        'cert-status-log',
        'dict-data/by-type',
        'cert-dict/cascade',
        'cert-dict/majors',
        'custom-field/meta',
        'custom-field/by-module',
        'project',
        'project/options',
        'project/stat',
        'project/detail',
        // v2.99：学历报名 —— 客户企业账号只读接口（数据范围在控制器内过滤）
        'edu-signup',
        'edu-signup/detail',
        'edu-signup/meta',
        'edu-signup/staff-options',
        'edu-agency/options',
    ];

    /**
     * 客户企业账号可写接口白名单（v2.99）
     * 默认客户账号只读；学历报名按产品决策对 company 开放「建单/编辑/传附件」，
     * 因此单独放行这些写接口，功能级权限仍由 PermAuth 按权限码二次校验
     * （company 已授予 edusignup:create/edit）。
     */
    private const COMPANY_WRITE = [
        'edu-signup/save',
        'edu-signup/staff-save',
        'edu-signup/attach-save',
        'edu-signup/attach-rename',
        'edu-signup/attach-delete',
        // v2.99.66：删除 OTP 闸门自身接口。客户账号若要删除（如学历报名附件），
        // 必须先在此用本人动态码换取一次性票据，否则永远无法完成删除；
        // 该接口只校验当前登录账号自己的 TOTP，不涉及任何业务数据访问。
        'delete-otp/verify',
        // v2.99.68：跨模块快捷新建引用主数据。客户账号在其可写业务表单（学历报名等）
        // 内现场建企业/人员时走共享通道；MasterData 控制器仍强制校验业务写权限码，
        // 且只接受建档最小字段、查重命中转为共有，不扩大数据边界。
        'master/company-save',
        'master/staff-save',
        'master/ocr-idcard',
    ];

    public function handle($request, \Closure $next)
    {
        $role = (string)($request->roleCode ?? '');
        if ($role === '') {
            throw new HttpResponseException(json(R::error('登录状态异常，请重新登录', 403)));
        }
        if ($role !== 'company') {
            return $next($request);
        }

        $method = strtoupper((string)$request->method());

        $path = strtolower(trim((string)$request->pathinfo(), '/'));
        $path = preg_replace('#^api/#', '', $path);
        $path = preg_replace('#^admin/#', '', $path);
        $path = preg_replace('#\.html$#', '', $path);

        // v2.99：学历报名对 company 放行建单/编辑类写接口（功能级权限仍由 PermAuth 校验）
        if ($method !== 'GET') {
            if (in_array($path, self::COMPANY_WRITE, true)) {
                return $next($request);
            }
            throw new HttpResponseException(json(R::error('客户账号仅可查看数据，不能执行此操作', 403)));
        }

        if (!in_array($path, self::COMPANY_READ, true)) {
            throw new HttpResponseException(json(R::error('客户账号无权访问该功能', 403)));
        }

        return $next($request);
    }
}
