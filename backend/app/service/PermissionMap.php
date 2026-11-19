<?php
namespace app\service;

/**
 * 接口 → 菜单 / 功能权限映射（v2.69）
 *
 * 作用：PermAuth 中间件据此做**接口级默认拒绝**：
 *   ① 先判断当前角色是否被授权该控制器所属菜单（menu_ids）；
 *   ② 再对写操作判断功能权限码（sys_role.perms）。
 *
 * 约定：
 *   - 键统一用小写控制器名（$request->controller() 归一化后）；
 *   - 未登记的控制器对非 admin 一律拒绝（默认拒绝，需显式登记）；
 *   - GET 共享数据源（字典/自定义字段/组织选择器）登记在 SHARED_READ，
 *     避免业务页面因未授权系统菜单而读不到下拉数据。
 */
class PermissionMap
{
    /** 控制器 => 所属菜单ID */
    public const CONTROLLER_MENU = [
        'dashboard'       => 2,
        'report'          => 3,
        'search'          => 31,
        'registertrack'   => 31,
        'company'         => 11,
        'companycert'     => 68,
        'contract'        => 13,
        'crm'             => 56,
        'staff'           => 12,
        'certificate'     => 5,
        'certrenewal'     => 7,
        'project'         => 41,
        'certtask'        => 33,
        'matchcenter'     => 42,
        'conflict'        => 43,
        'policyrule'      => 44,
        'secondment'      => 26,
        'alert'           => 6,
        'certstatuslog'   => 8,
        'govprogress'     => 59,
        'credentialvault' => 60,
        'fee'             => 9,
        'payaccount'      => 54,
        'pushchannel'     => 15,
        'push'            => 15,
        'pushsetting'     => 15,
        'msgtemplate'     => 16,
        'receiver'        => 17,
        'pushlog'         => 18,
        'user'            => 21,
        'role'            => 22,
        'menu'            => 23,
        'dicttype'        => 24,
        'dictdata'        => 24,
        'certdict'        => 5,
        'customfield'     => 27,
        'systemconfig'    => 28,
        'wecomconfig'     => 35,
        'wecomcontact'    => 36,
        'operationlog'    => 25,
        'loginlog'        => 61,
        'logindevice'     => 69,
        'recyclebin'      => 55,
        'alertconfig'     => 20,
        'orgdepartment'   => 34,
        'org'             => 34,
        'approval'        => 37,
        'cache'           => 28,
        'upload'          => 0,
        // v2.82：Agent 接入密钥管理
        'agentkey'        => 62,
        // v2.85：考试报名
        'examsignup'      => 64,
        'exambatch'       => 65,
        'examconfig'      => 66,
        'examcert'        => 67,
        // v2.99.70：成绩查询
        'examscore'       => 79,
        // v2.99：学历报名
        'edusignup'       => 71,
        'eduagency'       => 72,
        'educonfig'       => 73,
        // v2.99.1：在线升级（默认仅超级管理员）
        'upgrade'         => 74,
        // v2.99.40：移动端 / 小程序配置页（原未登记，授权菜单后仍会 403）
        'miniapp'         => 75,
        'mobileconfig'    => 76,
        'mobilemanage'    => 77,
    ];

    /** 一个控制器可命中多个菜单（任一命中即通过） */
    public const ANY_MENU = [
        'import' => [11, 12, 5, 41, 44],
        // v2.99.37：资源归属/公海入口内嵌于企业/人员/证书/CRM 页面，任一菜单可达
        'resource' => [11, 12, 5, 56],
        // v2.99.39：推送体检/手动检测入口在「到期提醒」页(6)，渠道配置在推送中心(15)，
        // 两者任一菜单即可执行，功能权限码 push:run 仍严格校验
        'push' => [6, 15],
        // v2.99.40：推送规则（申报进度推送范围）同属推送中心，提醒中心/推送渠道任一菜单可达
        'pushsetting' => [6, 15],
    ];

    /** GET 共享数据源：非 admin 也放行（写操作仍按菜单/权限码校验） */
    public const SHARED_READ = [
        'dicttype', 'dictdata', 'certdict', 'customfield',
        'org', 'orgdepartment', 'alertconfig', 'upload',
    ];

    /**
     * GET 共享只读动作（控制器@动作，键均小写）：仅放行嵌入其它业务详情的
     * 联动只读查询，控制器的其余动作仍按所属菜单严格校验。
     *
     * 背景：证书借调 / 服务合同详情抽屉要展示关联费用（fee/by-source），
     * 但证照管理员等角色未被授权「费用台账」菜单(9)，会被菜单级守卫误拦。
     * 该动作内部仍走 scope() 数据权限，仅返回当前来源单据的费用，不扩大数据范围。
     */
    public const SHARED_READ_ACTIONS = [
        'fee@bysource',
        // v2.99.35：共享只读主数据选择源（企业/人员），供 gov/vault 等跨模块表单使用，
        // 仅 GET 且控制器内只返回脱敏后的选择器字段，不依赖企业(11)/人员(12)菜单授权。
        // 注意：控制器类 MasterData 归一化键为 masterdata（见 controllerKey）。
        'masterdata@companyoptions',
        'masterdata@staffoptions',
    ];

    /**
     * 共享写动作（控制器@动作，键均小写）：跨模块「快捷新建引用主数据」专用通道。
     *
     * 背景：账号密码库 / 申报进度 / 项目配证 / 借调等已授权表单内，常常需要现场新建
     * 被引用的企业或人员。旧实现直接调 company/save、staff/save，这两个动作挂在
     * 企业客户(11)/人员档案(12)菜单守卫下，导致只被授权本模块的角色（如项目配证专员）
     * 在自己的表单内快捷建档时被 403「当前角色无权访问该功能」拦截。
     *
     * 与 SHARED_READ_ACTIONS 的关键区别——写操作不能「登录即放行」，否则只读角色
     * （viewer，perms 为空）也能凭空造数据。因此这里只是跳过「菜单级」守卫，
     * 控制器内部仍强制要求当前角色拥有任一业务模块的写权限码（*:create / *:edit
     * 或模块通配 *:*），即「在已授权且可写的业务功能里，才能建引用主数据」。
     * 只读观察员等纯查看角色没有任何写权限码，仍会被控制器以 403 拒绝。
     */
    public const SHARED_WRITE_ACTIONS = [
        'masterdata@companysave',
        'masterdata@staffsave',
        'masterdata@ocridcard',
    ];

    /**
     * 完全放行（登录即可，例如附件上传）
     *
     * v2.99.66：OTP 闸门自身接口必须放行——
     *   - deleteotp：删除前动态码换一次性票据，票据只绑定当前账号与具体删除动作，
     *     真正的删除接口仍由 PermAuth 校验功能权限，因此放行本接口不会造成越权；
     *   - exportotp：同一性质（v2.99.49 起漏登记，导致非 admin 角色导出时被
     *     「该功能未开放给当前角色」误拦，本次一并修复）。
     */
    public const OPEN = ['upload', 'deleteotp', 'exportotp'];

    /**
     * 引用型只读动作（v2.99.40）
     *
     * 背景：业务页面在表单里经常引用其他模块的下拉选项（例如「项目配证」选客户企业、
     * 「申报进度」选人员）。旧规则按控制器菜单校验，导致只授予本模块菜单的角色
     * 打开页面就报「当前角色无权访问该功能」，而页面其实是正常的。
     *
     * 这些动作只返回选择器所需的 id/名称等最小字段，不含明细数据，登录即可读；
     * 真正的明细列表（index）与导出（export）仍严格按菜单/功能码校验。
     */
    public const SELECTOR_ACTIONS = [
        'options', 'option', 'select', 'selector', 'simple', 'brief', 'names',
        'suggest', 'lookup', 'picker', 'choices', 'template',
        // v2.99.40：共享只读主数据（企业 / 人员选择源）
        'companyoptions', 'staffoptions',
    ];

    /** 允许共享选择器的控制器（仅业务实体与字典，不含账号/角色/系统配置） */
    public const SELECTOR_CONTROLLERS = [
        'company', 'companycert', 'staff', 'certificate', 'project', 'certtask',
        'contract', 'secondment', 'govprogress', 'credentialvault', 'certrenewal',
        'matchcenter', 'conflict', 'policyrule', 'fee', 'payaccount',
        'receiver', 'pushchannel', 'msgtemplate', 'certdict', 'dictdata', 'dicttype',
        'masterdata', 'org', 'eduagency', 'exambatch',
    ];

    /** 控制器 => 权限模块码（与 PermissionCatalog 对应） */
    public const CONTROLLER_MOD = [
        'company'         => 'company',
        'companycert'     => 'companycert',
        'contract'        => 'contract',
        'crm'             => 'crm',
        'staff'           => 'staff',
        'certificate'     => 'cert',
        'certrenewal'     => 'renewal',
        'project'         => 'project',
        'certtask'        => 'task',
        'matchcenter'     => 'match',
        'conflict'        => 'conflict',
        'policyrule'      => 'policy',
        'secondment'      => 'secondment',
        'alert'           => 'alert',
        'govprogress'     => 'gov',
        'credentialvault' => 'vault',
        'fee'             => 'fee',
        'payaccount'      => 'pay',
        'pushchannel'     => 'push',
        'push'            => 'push',
        'pushsetting'     => 'push',
        'pushlog'         => 'pushlog',
        'msgtemplate'     => 'template',
        'receiver'        => 'receiver',
        'registertrack'   => 'register',
        'recyclebin'      => 'recycle',
        'certdict'        => 'cert',
        // v2.82：Agent 接入
        'agentkey'        => 'agent',
        // v2.85：考试报名
        'examcert'        => 'examcat',
        'examconfig'      => 'examconfig',
        'exambatch'       => 'exambatch',
        'examsignup'      => 'examsignup',
        // v2.99.70：成绩查询
        'examscore'       => 'examscore',
        // v2.99：学历报名
        'edusignup'       => 'edusignup',
        'eduagency'       => 'eduagency',
        'educonfig'        => 'educonfig',
        // v2.99.37：资源归属/公海（复用 CRM 移交权限码）
        'resource'        => 'crm',
    ];

    /** 非标准动作的显式权限码（键：控制器@动作，均小写） */
    public const OVERRIDES = [
        'alert@read'            => 'alert:read',
        'alert@readall'         => 'alert:read',
        'alert@resend'          => 'alert:resend',
        'certificate@attachsave'    => 'cert:edit',
        'certificate@attachrename'  => 'cert:edit',
        'company@attachsave'        => 'company:edit',
        'company@fetchqualification'      => 'company:qualification',
        'company@completebasic'           => 'company:qualification',
        'company@qualificationcaptcha'    => 'company:qualification',
        'company@refreshqualificationcaptcha' => 'company:qualification',
        'company@fetchqualificationdetail'=> 'company:qualification',
        'company@collectxkgs'             => 'company:qualification',
        'company@importqualification'     => 'company:qualification',
        'company@deletequalification'     => 'company:qualification',
        'company@changestatus'      => 'company:status',
        // v2.94：企业证书附件 / 模板 / 平台采集
        'companycert@attachsave'    => 'companycert:attach',
        'companycert@template'      => 'companycert:import',
        'companycert@collect'            => 'companycert:collect',
        'companycert@collectcaptcha'     => 'companycert:collect',
        'companycert@collectcaptcharefresh' => 'companycert:collect',
        'companycert@collectdetail'      => 'companycert:collect',
        'companycert@collectxkgs'        => 'companycert:collect',
        'certtask@publish'          => 'task:publish',
        'certtask@updateitem'       => 'task:edit',
        'certtask@addlog'           => 'task:edit',
        'conflict@scan'             => 'conflict:scan',
        'conflict@rulessave'        => 'conflict:rules',
        'crm@transfer'              => 'crm:transfer',
        'crm@follow'                => 'crm:follow',
        'crm@followdelete'          => 'crm:follow',
        // v2.99.37：资源归属/公海统一复用 CRM 移交权限码
        'resource@topool'           => 'crm:transfer',
        'resource@claim'            => 'crm:transfer',
        'resource@transfer'         => 'crm:transfer',
        'resource@assign'           => 'crm:transfer',
        'resource@addowner'         => 'crm:transfer',
        'resource@addowners'        => 'crm:transfer',
        'resource@removeowner'      => 'crm:transfer',
        'fee@settle'                => 'fee:settle',
        'govprogress@sync'          => 'gov:sync',
        'govprogress@syncall'       => 'gov:sync',
        'govprogress@repair'        => 'gov:sync',
        'govprogress@archive'       => 'gov:edit',
        // v2.71：导入模板属于导入能力（默认模板规则会误判为 export）
        'govprogress@template'      => 'gov:import',
        // v2.70：登录日志清空仅超级管理员（另有控制器 requireAdmin 双重拦截）
        'loginlog@clear'            => 'loginlog:clear',
        'matchcenter@record'        => 'match:run',
        'matchcenter@select'        => 'match:select',
        'matchcenter@tomanual'      => 'match:run',
        'matchcenter@giveup'        => 'match:giveup',
        'policyrule@batchstatus'    => 'policy:status',
        'project@batchstatus'       => 'project:status',
        'pushchannel@test'          => 'push:test',
        'push@runcheck'             => 'push:run',
        'pushlog@clear'             => 'pushlog:delete',
        'receiver@batchdelete'      => 'receiver:delete',
        'recyclebin@restore'        => 'recycle:restore',
        'recyclebin@purge'          => 'recycle:purge',
        'recyclebin@clear'          => 'recycle:purge',
        'registertrack@save'        => 'register:edit',
        'registertrack@delete'      => 'register:delete',
        'secondment@returnback'     => 'secondment:return',
        'secondment@renew'          => 'secondment:edit',
        'certdict@quickcreate'      => 'cert:create',
        // v2.99.38：新增前查重预检属于新增能力（命中即自动共有，仍需 create 权限）
        'company@dedupcheck'        => 'company:create',
        'staff@dedupcheck'          => 'staff:create',
        'certificate@dedupcheck'    => 'cert:create',
        'staff@relsave'             => 'staff:edit',
        'staff@reldelete'           => 'staff:edit',
        'staff@attachsave'          => 'staff:edit',
        'staff@reveal'              => 'staff:reveal', // v2.99.59 受控明文出口：无 staff:reveal 直接 403（导出是否含明文同此权限）
        'staff@ocridcard'           => 'staff:create', // v2.87 人员档案身份证OCR（新建场景）
        'staff@ocrdiploma'          => 'staff:create', // v2.87 人员档案毕业证OCR
        // v2.82：Agent 密钥的吊销与删除（新建/修改按 id 自动判定 create/edit）
        'agentkey@revoke'           => 'agent:revoke',
        'agentkey@delete'           => 'agent:delete',
        // v2.99.4：在线升级的检查/备份/下载/应用/恢复/删除备份统一使用 run 权限
        'upgrade@check'             => 'upgrade:run',
        'upgrade@backup'            => 'upgrade:run',
        'upgrade@download'          => 'upgrade:run',
        'upgrade@upload'            => 'upgrade:run',
        'upgrade@apply'             => 'upgrade:run',
        'upgrade@restore'           => 'upgrade:run',
        'upgrade@deletebackup'      => 'upgrade:run',

        // ===== v2.85 考试报名 =====
        // 证书类目：保存/删除按 id 已自动判定 create|edit / delete
        // 业务配置
        'examconfig@actionsave'     => 'examconfig:edit',
        'examconfig@actiondelete'   => 'examconfig:delete',
        'examconfig@configsave'     => 'examconfig:edit', // config id 也统一走 edit 权限
        'examconfig@configdelete'   => 'examconfig:delete',
        'examconfig@configopen'     => 'examconfig:status',
        'examconfig@trial'          => 'examconfig:edit',
        // 批次考点
        'exambatch@roomsave'        => 'exambatch:edit',
        'exambatch@roomdelete'      => 'exambatch:delete',
        'exambatch@current'         => 'exambatch:status',
        // 报名工单
        'examsignup@save'           => 'examsignup:create', // 建单（草稿/正式）统一 create
        'examsignup@submit'         => 'examsignup:edit',
        'examsignup@advance'        => 'examsignup:status',
        'examsignup@audit'          => 'examsignup:audit',
        'examsignup@materialsave'   => 'examsignup:edit',
        'examsignup@materialdelete' => 'examsignup:edit',
        'examsignup@customersave'   => 'examsignup:edit',
        'examsignup@ocridcard'      => 'examsignup:edit', // v2.86 身份证OCR识别
        'examsignup@ocrdiploma'     => 'examsignup:edit', // v2.87 毕业证OCR识别

        // ===== v2.99.70 成绩查询 =====
        // 取验证码 / 换图 / 提交查询都属于「查成绩」动作
        'examscore@captcha'         => 'examscore:query',
        'examscore@captcharefresh'  => 'examscore:query',
        'examscore@query'           => 'examscore:query',

        // ===== v2.99 学历报名 =====
        // 工单：save 按 id 自动判定 create|edit；流转/反归档走 status
        'edusignup@advance'        => 'edusignup:status',
        'edusignup@unarchive'      => 'edusignup:status',
        'edusignup@staffsave'      => 'edusignup:edit',
        'edusignup@attachsave'     => 'edusignup:edit',
        'edusignup@attachrename'   => 'edusignup:edit',
        'edusignup@attachdelete'   => 'edusignup:edit',
        // 流程配置：保存/恢复默认
        'educonfig@flowsave'       => 'educonfig:edit',
        'educonfig@flowreset'      => 'educonfig:edit',
    ];

    public static function controllerKey(string $controller): string
    {
        $name = basename(str_replace('\\', '/', $controller));
        return strtolower($name);
    }

    /** @return int[] */
    public static function menusFor(string $controller): array
    {
        $key = self::controllerKey($controller);
        if (isset(self::ANY_MENU[$key])) {
            return self::ANY_MENU[$key];
        }
        $id = self::CONTROLLER_MENU[$key] ?? 0;
        return $id > 0 ? [$id] : [];
    }

    public static function isOpen(string $controller): bool
    {
        return in_array(self::controllerKey($controller), self::OPEN, true);
    }

    public static function isSharedRead(string $controller): bool
    {
        return in_array(self::controllerKey($controller), self::SHARED_READ, true);
    }

    /**
     * 某控制器的指定只读动作是否为共享数据源（仅 GET 联动查询放行）。
     * 与 isSharedRead 区别：粒度到动作，不放开整个控制器。
     */
    public static function isSharedReadAction(string $controller, string $action): bool
    {
        $key = self::controllerKey($controller) . '@' . strtolower($action);
        if (in_array($key, self::SHARED_READ_ACTIONS, true)) {
            return true;
        }
        // v2.99.40：业务实体的选择器动作（只读、仅最小字段）跨模块放行
        return in_array(strtolower($action), self::SELECTOR_ACTIONS, true)
            && in_array(self::controllerKey($controller), self::SELECTOR_CONTROLLERS, true);
    }

    /**
     * 某控制器的指定写动作是否为「跨模块快捷新建引用主数据」共享通道。
     * 命中后仅跳过菜单级守卫；功能级写权限由控制器内部按「任一业务写权限码」校验，
     * 因此纯只读角色（无任何写权限码）仍会被拒绝，不会造成越权写入。
     */
    public static function isSharedWriteAction(string $controller, string $action): bool
    {
        $key = self::controllerKey($controller) . '@' . strtolower($action);
        return in_array($key, self::SHARED_WRITE_ACTIONS, true);
    }

    /**
     * 计算写操作需要的权限码
     * @return string|null null = 该控制器不做功能权限校验（仅菜单级）
     */
    public static function requiredPerm(string $controller, string $action, $request): ?string
    {
        $key  = self::controllerKey($controller);
        $act  = strtolower($action);
        $full = $key . '@' . $act;

        if (isset(self::OVERRIDES[$full])) {
            return self::OVERRIDES[$full];
        }

        // 统一导入：按 type 归属模块
        if ($key === 'import') {
            $type = strtolower((string)$request->param('type', 'cert'));
            if ($type === 'certificate') { $type = 'cert'; }
            if ($type === 'policy_rule') { $type = 'policy'; }
            if ($type === 'gov_progress') { $type = 'gov'; }
            if ($type === 'credential_vault') { $type = 'vault'; }
            $mod  = in_array($type, ['staff', 'company', 'project', 'policy', 'gov', 'vault'], true) ? $type : 'cert';
            $actCode = in_array($act, ['failexport'], true) ? 'export' : 'import';
            return $mod . ':' . $actCode;
        }

        $mod = self::CONTROLLER_MOD[$key] ?? '';
        if ($mod === '') {
            return null; // 系统管理类：由菜单权限 + requireAdmin 兜底
        }

        // 保存：有 id = 编辑，无 id = 新增
        if (in_array($act, ['save', 'store', 'create'], true)) {
            $id = (int)$request->param('id', 0);
            return $mod . ':' . ($id > 0 ? 'edit' : 'create');
        }
        if (in_array($act, ['delete', 'batchdelete'], true)) {
            return $mod . ':delete';
        }
        if (in_array($act, ['export', 'template', 'failexport'], true)) {
            return $mod . ':export';
        }
        if (in_array($act, ['status', 'changestatus', 'batchstatus'], true)) {
            return $mod . ':status';
        }
        if (in_array($act, ['edit', 'update'], true)) {
            return $mod . ':edit';
        }
        if (in_array($act, ['import', 'commit', 'preview'], true)) {
            return $mod . ':import';
        }

        // 未归类的写操作：默认要求 edit，避免「漏配即放行」
        return $mod . ':edit';
    }
}
