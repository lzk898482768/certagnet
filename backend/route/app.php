<?php
use think\facade\Route;

// ============================================================
// 说明：TP6 路由默认非完全匹配，父路径（如 staff）会抢占子路径
// （如 staff/options）。因此所有子路径规则必须注册在父路径之前。
// ============================================================

// ========== 站点根路径：按 UA 自动分流（生产直接访问域名即可用） ==========
// ★ 判断规则必须与 backend/public/index.html 的内联 JS 保持一致，两处同步修改！
// 规则：① 先判桌面 UA（PC 微信/企业微信内置浏览器的 UA 同样含 MicroMessenger，须先排除）
//      ② 再判移动特征
// 已知局限：iPadOS 13+ Safari 默认报桌面 UA（Macintosh，不含 iPad 字样），服务端无法
// 识别，会分流到 /web/ —— 由 index.html 的 JS 用 navigator.maxTouchPoints 兜底识别。
$clientHome = function () {
    $ua        = (string)request()->header('user-agent', '');
    $isDesktop = (bool)preg_match('/Windows NT|WindowsWechat|Macintosh/i', $ua);
    $isMobile  = (bool)preg_match('/Mobile|Android|iPhone|iPad|iPod|HarmonyOS|MicroMessenger|Windows Phone/i', $ua);
    $toMobile  = $isMobile && !$isDesktop;
    return redirect($toMobile ? '/mobile/' : '/web/', 302);
};
Route::get('/', $clientHome);
// 兼容不带结尾斜杠的访问（/web、/mobile），避免 404
Route::get('web', function () { return redirect('/web/', 301); });
Route::get('mobile', function () { return redirect('/mobile/', 301); });

// ========== 公开接口 ==========
Route::get('api/captcha', 'app\controller\api\Captcha@index');
Route::post('api/login', 'app\controller\api\Auth@login');
Route::get('api/system-info', 'app\controller\api\SystemInfo@index');
// v2.75：登录二次验证（TOTP，对接腾讯身份验证器）
Route::get('api/totp/challenge', 'app\controller\api\Auth@totpChallenge');
Route::post('api/totp/verify', 'app\controller\api\Auth@totpVerify');

// v2.46：WebDAV 附件的本站代理下载（HMAC 签名 + 1 小时时效，见 WebDavService / AttachDownload）
Route::get('api/attach/download', 'app\controller\admin\AttachDownload@download');

// ========== 企业微信登录（公开） ==========
Route::get('api/wecom/config', 'app\controller\api\Wecom@config');
// GET：企微 OAuth 浏览器回调（redirect_uri 指向本接口时企微直接带 code 跳回来）→ 302 回前端
Route::get('api/wecom/login', 'app\controller\api\Wecom@login');
// POST：前端登录页用 code 换 token（JSON）
Route::post('api/wecom/login', 'app\controller\api\Wecom@login');

// ========== v2.100 微信小程序（公开） ==========
// 小程序端首次登录 / 绑定：会话由 bind_ticket 承载，无需登录态
Route::get('api/miniapp/config', 'app\controller\api\MiniApp@config');
Route::post('api/miniapp/login', 'app\controller\api\MiniApp@login');
Route::post('api/miniapp/bind', 'app\controller\api\MiniApp@bind');

// ========== v2.99.24 移动端公开配置（H5 / App / 小程序共用） ==========
// 下发显示名称、功能导航、底部菜单、登录渠道开关、版本更新与推送配置
Route::get('api/mobile/config', 'app\controller\api\MobileConfig@config');

// ========== Agent 开放接口（独立 API Key 鉴权，不走登录态） ==========
// v2.82：外部 Agent（Claude Code / OpenCode / Dify / n8n 等）通过 sys_agent_key 调用。
// 子路径必须先于父路径注册（TP6 默认非完全匹配）。
Route::group('api/agent', function () {
    // v2.84：MCP 无状态端点（JSON-RPC over POST），供企微大模型自动发现并调用只读工具
    Route::post('mcp', 'app\controller\api\AgentMcp@endpoint');

    Route::get('ping', 'app\controller\api\Agent@ping');
    Route::get('capabilities', 'app\controller\api\Agent@capabilities');
    Route::get('skill', 'app\controller\api\Agent@skill');
    Route::get('openapi', 'app\controller\api\Agent@openapi');
    Route::get('overview', 'app\controller\api\Agent@overview');

    Route::get('certificates/detail', 'app\controller\api\Agent@certificateDetail');
    Route::get('certificates', 'app\controller\api\Agent@certificates');
    Route::post('certificates/status', 'app\controller\api\Agent@updateCertStatus');

    Route::get('staff/detail', 'app\controller\api\Agent@staffDetail');
    Route::get('staff', 'app\controller\api\Agent@staff');

    Route::get('companies/detail', 'app\controller\api\Agent@companyDetail');
    Route::get('companies', 'app\controller\api\Agent@companies');

    Route::get('contracts/detail', 'app\controller\api\Agent@contractDetail');
    Route::get('contracts', 'app\controller\api\Agent@contracts');

    Route::get('expiring', 'app\controller\api\Agent@expiring');

    Route::post('crm/follow', 'app\controller\api\Agent@addFollow');
})->middleware('agentauth');

// ========== 企业微信「智能机器人」URL 回调（v2.83） ==========
// 企业微信后台 → 应用管理 → 智能机器人 → API 模式 → 使用 URL 回调。
// 来源合法性由 msg_signature + EncodingAESKey 校验，不走登录态，也不走 API Key。
Route::get('api/wecom/aibot', 'app\controller\api\WecomAibot@verify');
Route::post('api/wecom/aibot', 'app\controller\api\WecomAibot@callback');

// 兼容企业微信后台已经填成 /api/agent 的情况：同一个回调入口。
// 必须注册在 agent 路由组之后，否则会抢占 api/agent/ping 等子路径。
Route::get('api/agent', 'app\controller\api\WecomAibot@verify');
Route::post('api/agent', 'app\controller\api\WecomAibot@callback');

// ========== 需鉴权 ==========
Route::group('api', function () {

    Route::post('logout', 'app\controller\api\Auth@logout');
    Route::get('profile/info', 'app\controller\api\Auth@info');
    Route::post('profile/change-password', 'app\controller\api\Auth@changePassword');
    // v2.96：我的登录设备 / 受信任设备（任何已登录用户）
    Route::get('profile/devices', 'app\controller\api\Auth@devices');
    Route::post('profile/devices/revoke', 'app\controller\api\Auth@revokeDevice');
    Route::post('profile/devices/revoke-all', 'app\controller\api\Auth@revokeAllDevices');

    // -------- 企业微信绑定管理（需鉴权） --------
    Route::post('wecom/bind', 'app\controller\api\Wecom@bind');
    Route::post('wecom/unbind', 'app\controller\api\Wecom@unbind');
    Route::get('wecom/status', 'app\controller\api\Wecom@status');

    // -------- v2.100 微信小程序绑定管理（需鉴权） --------
    Route::get('miniapp/status', 'app\controller\api\MiniApp@status');
    Route::post('miniapp/unbind', 'app\controller\api\MiniApp@unbind');
    // PC 端当前用户生成一次性绑定码
    Route::post('profile/miniapp/bind-code', 'app\controller\api\MiniApp@bindCode');

    // -------- 后台管理模块（管理员 / 操作员） --------
    Route::group('admin', function () {

        // 仪表盘
        Route::get('dashboard/stat', 'app\controller\admin\Dashboard@stat');

        // v2.82：Agent 接入密钥管理（仅管理员，控制器内 requireAdmin 双重拦截）
        Route::get('agent/wecom-gen', 'app\controller\admin\AgentKey@wecomGen');
        Route::get('agent/wecom', 'app\controller\admin\AgentKey@wecom');
        Route::post('agent/wecom-save', 'app\controller\admin\AgentKey@wecomSave');
        Route::get('agent/keys', 'app\controller\admin\AgentKey@index');
        Route::get('agent/catalog', 'app\controller\admin\AgentKey@catalog');
        Route::get('agent/skill', 'app\controller\admin\AgentKey@skill');
        Route::get('agent/openapi', 'app\controller\admin\AgentKey@openapi');
        Route::get('agent/owners', 'app\controller\admin\AgentKey@owners');
        Route::post('agent/key-save', 'app\controller\admin\AgentKey@save');
        Route::post('agent/key-revoke', 'app\controller\admin\AgentKey@revoke');
        Route::post('agent/key-delete', 'app\controller\admin\AgentKey@delete');

        // -------- 子路径优先注册（避免被父路径抢占） --------
        // v2.43：河南建筑市场监管平台采集（公开信息 + 安许 + 人工验证码资质明细）
        Route::get('company/qualifications', 'app\controller\admin\Company@qualifications');
        Route::post('company/fetch-qualification', 'app\controller\admin\Company@fetchQualification');
        Route::post('company/complete-basic', 'app\controller\admin\Company@completeBasic');
        Route::post('company/qualification-captcha', 'app\controller\admin\Company@qualificationCaptcha');
        Route::post('company/qualification-captcha-refresh', 'app\controller\admin\Company@refreshQualificationCaptcha');
        Route::post('company/fetch-qualification-detail', 'app\controller\admin\Company@fetchQualificationDetail');
        Route::post('company/collect-xkgs', 'app\controller\admin\Company@collectXkgs');
        Route::post('company/import-qualification', 'app\controller\admin\Company@importQualification');
        Route::post('company/delete-qualification', 'app\controller\admin\Company@deleteQualification');
        Route::post('company/status', 'app\controller\admin\Company@changeStatus');
        // v2.46：企业详情抽屉直接保存附件（只覆盖附件，不动其他字段）
        Route::post('company/attach-save', 'app\controller\admin\Company@attachSave');
        Route::post('company/batch-delete', 'app\controller\admin\Company@batchDelete');
        Route::post('company/delete', 'app\controller\admin\Company@delete');
        Route::post('company/save', 'app\controller\admin\Company@save');
        // v2.99.38：新增前查重预检（命中提示「关联为共同所有人」）
        Route::post('company/dedup-check', 'app\controller\admin\Company@dedupCheck');
        Route::get('company/options', 'app\controller\admin\Company@options');

        // v2.93：企业证书（安许 / 资质）集中台账（子路径优先于父路径）
        Route::get('company-cert/stat', 'app\controller\admin\CompanyCert@stat');
        Route::get('company-cert/export', 'app\controller\admin\CompanyCert@export');
        Route::post('company-cert/delete', 'app\controller\admin\CompanyCert@delete');
        // v2.94：增改查 / 导入 / 附件
        Route::get('company-cert/detail', 'app\controller\admin\CompanyCert@detail');
        Route::get('company-cert/template', 'app\controller\admin\CompanyCert@template');
        Route::post('company-cert/save', 'app\controller\admin\CompanyCert@save');
        Route::post('company-cert/import', 'app\controller\admin\CompanyCert@import');
        Route::post('company-cert/attach-save', 'app\controller\admin\CompanyCert@attachSave');
        // v2.94：表单内河南平台采集（安许免验证码 / 资质明细人工验证码）
        Route::post('company-cert/collect', 'app\controller\admin\CompanyCert@collect');
        Route::post('company-cert/collect-captcha', 'app\controller\admin\CompanyCert@collectCaptcha');
        Route::post('company-cert/collect-captcha-refresh', 'app\controller\admin\CompanyCert@collectCaptchaRefresh');
        Route::post('company-cert/collect-detail', 'app\controller\admin\CompanyCert@collectDetail');
        Route::post('company-cert/collect-xkgs', 'app\controller\admin\CompanyCert@collectXkgs');

        // 用户管理
        Route::post('user/unbind-wecom', 'app\controller\admin\User@unbindWecom');
        // v2.100：微信小程序绑定 / 解绑 / 绑定码
        Route::post('user/unbind-miniapp', 'app\controller\admin\User@unbindMiniApp');
        Route::post('user/miniapp-bind-code', 'app\controller\admin\User@miniappBindCode');
        // v2.100：小程序接入配置（后台）
        Route::get('miniapp/config', 'app\controller\admin\MiniApp@config');
        Route::post('miniapp/save', 'app\controller\admin\MiniApp@save');
        Route::post('miniapp/token', 'app\controller\admin\MiniApp@token');
        Route::post('miniapp/audit-mode', 'app\controller\admin\MiniApp@auditMode');
        // v2.99.24：移动端配置（显示名称 / 功能导航 / 底部菜单 / 版本更新 / 推送开关）
        Route::get('mobile-config', 'app\controller\admin\MobileConfig@get');
        Route::post('mobile-config/save', 'app\controller\admin\MobileConfig@save');
        // v2.99.26：移动端管理（合并小程序接入 + 移动端配置的统一入口，三端共用功能导航）
        Route::get('mobile-manage', 'app\controller\admin\MobileManage@get');
        Route::post('mobile-manage/save', 'app\controller\admin\MobileManage@save');
        Route::post('mobile-manage/token', 'app\controller\admin\MobileManage@token');
        Route::post('mobile-manage/audit-mode', 'app\controller\admin\MobileManage@auditMode');
        // v2.75：二次验证（腾讯身份验证器）管理
        Route::post('user/totp-force', 'app\controller\admin\User@totpForce');
        Route::post('user/totp-tip', 'app\controller\admin\User@totpTip');
        Route::post('user/totp-unbind', 'app\controller\admin\User@totpUnbind');

        Route::get('staff/options', 'app\controller\admin\Staff@options');
        Route::get('staff/profile', 'app\controller\admin\Staff@profile');
        Route::get('staff/utilization', 'app\controller\admin\Staff@utilization');
        Route::get('staff/idle-alert', 'app\controller\admin\Staff@idleAlert');
        Route::post('staff/rel-save', 'app\controller\admin\Staff@relSave');
        Route::post('staff/rel-delete', 'app\controller\admin\Staff@relDelete');
        Route::post('staff/batch-delete', 'app\controller\admin\Staff@batchDelete');
        Route::post('staff/delete', 'app\controller\admin\Staff@delete');
        Route::post('staff/save', 'app\controller\admin\Staff@save');
        // v2.99.38：新增前查重预检（命中提示「关联为共同所有人」）
        Route::post('staff/dedup-check', 'app\controller\admin\Staff@dedupCheck');
        // v2.79.2：重复人员检测 / 合并
        Route::get('staff/duplicates', 'app\controller\admin\Staff@duplicates');
        Route::post('staff/merge', 'app\controller\admin\Staff@merge');
// v2.47：人员全景档案直接保存附件（只覆盖附件子表，不动人员其他字段）
        Route::post('staff/attach-save', 'app\controller\admin\Staff@attachSave');

        // v2.87：人员档案身份证/毕业证 OCR
        Route::post('staff/ocr-idcard', 'app\controller\admin\Staff@ocrIdcard');
        Route::post('staff/ocr-diploma', 'app\controller\admin\Staff@ocrDiploma');

        Route::get('certificate/template', 'app\controller\admin\Certificate@template');
        Route::get('certificate/export', 'app\controller\admin\Certificate@export');
        // 统一导入（预览 -> 确认入库）
        Route::post('import/preview', 'app\controller\admin\Import@preview');
        Route::post('import/commit', 'app\controller\admin\Import@commit');
        // v2.55：导入失败名单导出（POST 收失败行数组，返回 Excel 下载）
        Route::post('import/fail-export', 'app\controller\admin\Import@failExport');
        // v2.72：导入识别记录导出（POST 收预览行数组，返回全量识别结果 Excel）
        Route::post('import/recognize-export', 'app\controller\admin\Import@recognizeExport');
        Route::get('certificate/detail', 'app\controller\admin\Certificate@detail');
        // v2.55.3：编辑用明文读取（管理员回填真实证书编号）
        Route::get('certificate/read', 'app\controller\admin\Certificate@read');
        Route::post('certificate/batch-delete', 'app\controller\admin\Certificate@batchDelete');
        Route::post('certificate/delete', 'app\controller\admin\Certificate@delete');
        Route::post('certificate/status', 'app\controller\admin\Certificate@changeStatus');
        Route::post('certificate/save', 'app\controller\admin\Certificate@save');
        // v2.99.38：新增前查重预检（命中提示「关联并共有」）
        Route::post('certificate/dedup-check', 'app\controller\admin\Certificate@dedupCheck');
        // v2.79：新增证书「一键采集」（河南建管平台人员证书）
        Route::post('certificate/person-collect', 'app\controller\admin\Certificate@personCollect');
        Route::post('certificate/person-collect-captcha-refresh', 'app\controller\admin\Certificate@personCollectCaptchaRefresh');
        // v2.50：重命名单个证书附件（详情页扫描件）
        Route::post('certificate/attach-rename', 'app\controller\admin\Certificate@attachRename');
        // v2.56：证书详情抽屉直接保存附件（直传附件）
        Route::post('certificate/attach-save', 'app\controller\admin\Certificate@attachSave');

        // v2.52：全局回收站（删除缓冲：恢复 / 彻底删除）
        Route::get('recycle-bin', 'app\controller\admin\RecycleBin@index');
        Route::post('recycle-bin/restore', 'app\controller\admin\RecycleBin@restore');
        Route::post('recycle-bin/purge', 'app\controller\admin\RecycleBin@purge');
        Route::post('recycle-bin/clear', 'app\controller\admin\RecycleBin@clear');

        // v2.54：CRM 能力（负责人移交 / 跟进记录 / 跟进台账）
        Route::get('crm/users', 'app\controller\admin\Crm@users');
        Route::post('crm/transfer', 'app\controller\admin\Crm@transfer');
        Route::get('crm/follows', 'app\controller\admin\Crm@follows');
        Route::post('crm/follow', 'app\controller\admin\Crm@follow');
        Route::post('crm/follow-delete', 'app\controller\admin\Crm@followDelete');
        Route::get('crm/ledger', 'app\controller\admin\Crm@ledger');

        // v2.99.37：资源归属 / 公海 / 共同所有人
        Route::get('resource/pool', 'app\controller\admin\Resource@pool');
        Route::get('resource/owners', 'app\controller\admin\Resource@owners');
        Route::get('resource/transfer-log', 'app\controller\admin\Resource@transferLog');
        Route::post('resource/to-pool', 'app\controller\admin\Resource@toPool');
        Route::post('resource/claim', 'app\controller\admin\Resource@claim');
        Route::post('resource/transfer', 'app\controller\admin\Resource@transfer');
        Route::post('resource/assign', 'app\controller\admin\Resource@assign');
        Route::post('resource/add-owner', 'app\controller\admin\Resource@addOwner');
        Route::post('resource/add-owners', 'app\controller\admin\Resource@addOwners');
        Route::post('resource/remove-owner', 'app\controller\admin\Resource@removeOwner');

        // 借调管理
        Route::get('secondment/free-certs', 'app\controller\admin\Secondment@freeCerts');
        Route::get('secondment/stat', 'app\controller\admin\Secondment@stat');
        Route::get('secondment/detail', 'app\controller\admin\Secondment@detail');
        Route::post('secondment/return', 'app\controller\admin\Secondment@returnBack');
        Route::post('secondment/batch-delete', 'app\controller\admin\Secondment@batchDelete');
        Route::post('secondment/delete', 'app\controller\admin\Secondment@delete');
        Route::post('secondment/save', 'app\controller\admin\Secondment@save');
        Route::post('secondment/renew', 'app\controller\admin\Secondment@renew');

        // 企业 / 人员：导入导出与模板
        Route::get('company/export', 'app\controller\admin\Company@export');
        Route::get('company/template', 'app\controller\admin\Company@template');
        Route::get('company/detail', 'app\controller\admin\Company@detail');
        Route::get('staff/export', 'app\controller\admin\Staff@export');
        Route::get('staff/template', 'app\controller\admin\Staff@template');
        Route::get('staff/read', 'app\controller\admin\Staff@read');
        Route::get('staff/detail', 'app\controller\admin\Staff@detail');
        // v2.99.47：受控明文出口（查看/复制身份证等，服务端解密并写敏感访问审计）
        Route::post('staff/reveal', 'app\controller\admin\Staff@reveal');

        Route::get('alert/unread-count', 'app\controller\admin\Alert@unreadCount');
        Route::post('alert/read-all', 'app\controller\admin\Alert@readAll');
        Route::post('alert/read', 'app\controller\admin\Alert@read');
        Route::post('alert/resend', 'app\controller\admin\Alert@resend');

        Route::get('report/summary', 'app\controller\admin\Report@summary');
        Route::get('report/overview', 'app\controller\admin\Report@overview');

        // 一键查询中心
        Route::get('search/detail', 'app\controller\admin\Search@detail');

        // 建造师注册轨迹
        Route::get('register-track/meta', 'app\controller\admin\RegisterTrack@meta');
        Route::post('register-track/save', 'app\controller\admin\RegisterTrack@save');
        Route::post('register-track/delete', 'app\controller\admin\RegisterTrack@delete');

        // 推送中心：测试推送 / 手动检测 / 推送体检
        Route::post('push-channel/test', 'app\controller\admin\PushChannel@test');
        Route::post('push-channel/save', 'app\controller\admin\PushChannel@save');
        Route::post('push-channel/delete', 'app\controller\admin\PushChannel@delete');
        Route::get('push/diagnose', 'app\controller\admin\Push@diagnose');
        Route::post('push/run-check', 'app\controller\admin\Push@runCheck');
        Route::post('push-log/clear', 'app\controller\admin\PushLog@clear');
        // v2.99.40：推送规则（申报进度推送范围）
        Route::get('push-setting/gov', 'app\controller\admin\PushSetting@gov');
        Route::post('push-setting/gov-save', 'app\controller\admin\PushSetting@govSave');

        Route::get('user/companies', 'app\controller\admin\User@companies');
        Route::get('user/depts', 'app\controller\admin\User@depts');
        Route::post('user/save', 'app\controller\admin\User@save');
        Route::post('user/delete', 'app\controller\admin\User@delete');

        Route::post('role/save', 'app\controller\admin\Role@save');
        Route::post('role/delete', 'app\controller\admin\Role@delete');
        Route::post('role/reset', 'app\controller\admin\Role@reset');
        Route::get('role/permissions', 'app\controller\admin\Role@permissions');

        Route::get('menu/list', 'app\controller\admin\Menu@list');
        Route::get('menu/diagnose', 'app\controller\admin\Menu@diagnose');
        Route::post('menu/reset-catalog', 'app\controller\admin\Menu@resetCatalog');
        Route::post('menu/save', 'app\controller\admin\Menu@save');
        Route::post('menu/delete', 'app\controller\admin\Menu@delete');

        Route::post('msg-template/save', 'app\controller\admin\MsgTemplate@save');
        Route::post('msg-template/delete', 'app\controller\admin\MsgTemplate@delete');

        Route::get('fee/meta', 'app\controller\admin\Fee@meta');
        Route::get('fee/by-source', 'app\controller\admin\Fee@bySource');
        Route::get('fee/stat', 'app\controller\admin\Fee@stat');
        Route::get('fee/receivable-payable', 'app\controller\admin\Fee@receivablePayable');
        Route::get('fee/summary', 'app\controller\admin\Fee@summary');
        Route::post('fee/settle', 'app\controller\admin\Fee@settle');
        Route::post('fee/save', 'app\controller\admin\Fee@save');
        Route::post('fee/batch-delete', 'app\controller\admin\Fee@batchDelete');
        Route::post('fee/delete', 'app\controller\admin\Fee@delete');
        Route::get('fee/export', 'app\controller\admin\Fee@export');

        Route::get('alert-config/list', 'app\controller\admin\AlertConfig@index');
        Route::post('alert-config/batch', 'app\controller\admin\AlertConfig@batch');
        Route::get('alert-config', 'app\controller\admin\AlertConfig@get')->completeMatch();
        Route::post('alert-config/save', 'app\controller\admin\AlertConfig@save');

        // 企业微信登录配置
        Route::get('wecom-config', 'app\controller\admin\WecomConfig@get')->completeMatch();
        Route::post('wecom-config/save', 'app\controller\admin\WecomConfig@save');
        Route::post('wecom-config/test', 'app\controller\admin\WecomConfig@test');

        // 企业微信通讯录同步
        Route::post('wecom-contact/sync', 'app\controller\admin\WecomContact@sync');
        Route::post('wecom-contact/update', 'app\controller\admin\WecomContact@update');
        Route::post('wecom-contact/delete', 'app\controller\admin\WecomContact@delete');
        Route::post('wecom-contact/bind-account', 'app\controller\admin\WecomContact@bindAccount');
        Route::get('wecom-contact/users', 'app\controller\admin\WecomContact@users');
        Route::get('wecom-contact/departments', 'app\controller\admin\WecomContact@departments');
        Route::post('wecom-contact/create-account', 'app\controller\admin\WecomContact@createAccount');

        // 工作流审批（对接企微审批）
        Route::get('approval/meta', 'app\controller\admin\Approval@meta');
        Route::get('approval/detail', 'app\controller\admin\Approval@detail');
        Route::post('approval/save', 'app\controller\admin\Approval@save');
        Route::post('approval/cancel', 'app\controller\admin\Approval@cancel');
        Route::post('approval/approve', 'app\controller\admin\Approval@approve');
        Route::post('approval/reject', 'app\controller\admin\Approval@reject');
        Route::post('approval/sync', 'app\controller\admin\Approval@syncStatus');

        Route::get('dict-data/by-type', 'app\controller\admin\DictData@byType');
        Route::post('dict-data/save', 'app\controller\admin\DictData@save');
        Route::post('dict-data/delete', 'app\controller\admin\DictData@delete');

        // v2.32 证书类型/专业两级字典（级联 + 快速新建）
        Route::get('cert-dict/cascade', 'app\controller\admin\CertDict@cascade');
        Route::get('cert-dict/majors', 'app\controller\admin\CertDict@majors');
        Route::post('cert-dict/quick-create', 'app\controller\admin\CertDict@quickCreate');

        Route::post('dict-type/save', 'app\controller\admin\DictType@save');
        Route::post('dict-type/delete', 'app\controller\admin\DictType@delete');

        Route::post('contract/save', 'app\controller\admin\Contract@save');
        Route::post('contract/batch-delete', 'app\controller\admin\Contract@batchDelete');
        Route::post('contract/delete', 'app\controller\admin\Contract@delete');
        Route::get('contract/detail', 'app\controller\admin\Contract@detail');

        // 自定义字段（子路径必须在父路径之前注册）
        Route::get('custom-field/meta', 'app\controller\admin\CustomField@meta');
        Route::get('custom-field/by-module', 'app\controller\admin\CustomField@byModule');
        Route::post('custom-field/save', 'app\controller\admin\CustomField@save');
        Route::post('custom-field/status', 'app\controller\admin\CustomField@status');
        Route::post('custom-field/delete', 'app\controller\admin\CustomField@delete');

        // 系统基本信息设置
        // v2.46：子路径注册在前（system-config 父路径之前），附件 WebDAV 测试连接
        Route::post('system-config/webdav-test', 'app\controller\admin\SystemConfig@webdavTest');
        // v2.99.48：附件 S3 对象存储测试连接
        Route::post('system-config/s3-test', 'app\controller\admin\SystemConfig@s3Test');
        // v2.99.64：附件 S3 一键迁移（子路径必须先于 system-config 父路径注册）
        Route::post('system-config/s3-migrate/preview', 'app\controller\admin\AttachMigrate@preview');
        Route::post('system-config/s3-migrate/start', 'app\controller\admin\AttachMigrate@start');
        Route::post('system-config/s3-migrate/run', 'app\controller\admin\AttachMigrate@run');
        Route::post('system-config/s3-migrate/cancel', 'app\controller\admin\AttachMigrate@cancel');
        Route::post('system-config/s3-migrate/status', 'app\controller\admin\AttachMigrate@status');
        // v2.99.49：敏感导出 OTP 闸门（预检 + 动态码换一次性 ticket）
        Route::post('export-otp/prepare', 'app\controller\admin\ExportOtp@prepare');
        Route::post('export-otp/verify', 'app\controller\admin\ExportOtp@verify');
        // v2.99.66：删除操作 OTP 闸门（动态码换一次性删除 ticket）
        Route::post('delete-otp/verify', 'app\controller\admin\DeleteOtp@verify');
        Route::post('system-config/ocr-test', 'app\controller\admin\SystemConfig@ocrTest');
        // v2.91：应急厅证书查询中继测试连接
        Route::post('system-config/relay-test', 'app\controller\admin\SystemConfig@relayTest');
        Route::get('system-config', 'app\controller\admin\SystemConfig@get');
        Route::post('system-config/save', 'app\controller\admin\SystemConfig@save');

        // 系统缓存清理（会清掉登录 token，所有用户需重新登录）
        Route::post('system/cache-clear', 'app\controller\admin\Cache@clear');

        Route::post('cert-renewal/save', 'app\controller\admin\CertRenewal@save');

        Route::post('operation-log/clear', 'app\controller\admin\OperationLog@clear');

        Route::post('receiver/save', 'app\controller\admin\Receiver@save');
        Route::post('receiver/batch-delete', 'app\controller\admin\Receiver@batchDelete');
        Route::post('receiver/delete', 'app\controller\admin\Receiver@delete');

        Route::post('upload', 'app\controller\admin\Upload@upload');

        // -------- 父路径（列表类）最后注册 --------
        // 客户企业
        Route::get('company', 'app\controller\admin\Company@index');

        // v2.93：企业证书集中台账
        Route::get('company-cert', 'app\controller\admin\CompanyCert@index');

        // 人员
        Route::get('staff', 'app\controller\admin\Staff@index');

        // 证照台账
        Route::get('certificate', 'app\controller\admin\Certificate@index');

        // 借调台账
        Route::get('secondment', 'app\controller\admin\Secondment@index');

        // 到期提醒
        Route::get('alert', 'app\controller\admin\Alert@index');

        // 推送渠道 / 日志 / 接收人
        Route::get('push-channel', 'app\controller\admin\PushChannel@index');
        Route::get('push-log', 'app\controller\admin\PushLog@index');
        Route::get('receiver', 'app\controller\admin\Receiver@index');

        // 账号 / 角色 / 菜单 / 模板
        Route::get('user', 'app\controller\admin\User@index');
        Route::get('role', 'app\controller\admin\Role@index');
        Route::get('menu', 'app\controller\admin\Menu@index');
        Route::get('msg-template', 'app\controller\admin\MsgTemplate@index');

        // 证书状态日志 / 费用 / 提醒配置
        Route::get('cert-status-log', 'app\controller\admin\CertStatusLog@index');
        Route::get('fee', 'app\controller\admin\Fee@index');

        // 一键查询中心 / 注册轨迹
        Route::get('search', 'app\controller\admin\Search@index');
        Route::get('register-track', 'app\controller\admin\RegisterTrack@index');

        // 组织架构 / 账号：供「可见范围」「接收人」等选择器使用
        Route::get('org-dept', 'app\controller\admin\OrgDepartment@index');
        Route::post('org-dept/save', 'app\controller\admin\OrgDepartment@save');
        Route::post('org-dept/delete', 'app\controller\admin\OrgDepartment@delete');
        // 组织架构选择器数据源（部门 + 账号，含企微绑定情况）
        Route::get('org/selector', 'app\controller\admin\Org@selector');

        // 工作流审批（列表父路径，务必在子路径之后注册；缺失会导致审批管理 404）
        Route::get('approval', 'app\controller\admin\Approval@index');

        // 收款账户（v2.16）
        Route::get('pay-account/options', 'app\controller\admin\PayAccount@options');
        Route::post('pay-account/save', 'app\controller\admin\PayAccount@save');
        Route::post('pay-account/batch-delete', 'app\controller\admin\PayAccount@batchDelete');
        Route::post('pay-account/delete', 'app\controller\admin\PayAccount@delete');
        Route::post('pay-account/set-default', 'app\controller\admin\PayAccount@setDefault');
        Route::get('pay-account', 'app\controller\admin\PayAccount@index')->completeMatch();

        // 配证任务（子路径必须注册在父路径之前，避免被 cert-task 抢占）
        Route::get('cert-task/detail', 'app\controller\admin\CertTask@detail');
        Route::get('cert-task', 'app\controller\admin\CertTask@index')->completeMatch();
        Route::post('cert-task/save', 'app\controller\admin\CertTask@save');
        Route::post('cert-task/batch-delete', 'app\controller\admin\CertTask@batchDelete');
        Route::post('cert-task/delete', 'app\controller\admin\CertTask@delete');
        Route::post('cert-task/publish', 'app\controller\admin\CertTask@publish');
        Route::post('cert-task/update-item', 'app\controller\admin\CertTask@updateItem');
        Route::post('cert-task/add-log', 'app\controller\admin\CertTask@addLog');

        // 项目管理（子路径必须注册在父路径之前）
        Route::get('project/options', 'app\controller\admin\Project@options');
        Route::get('project/stat', 'app\controller\admin\Project@stat');
        Route::get('project/detail', 'app\controller\admin\Project@detail');
        Route::get('project/template', 'app\controller\admin\Project@template');
        Route::get('project/export', 'app\controller\admin\Project@export');
        Route::post('project/save', 'app\controller\admin\Project@save');
        Route::post('project/batch-delete', 'app\controller\admin\Project@batchDelete');
        Route::post('project/batch-status', 'app\controller\admin\Project@batchStatus');
        Route::post('project/delete', 'app\controller\admin\Project@delete');
        Route::get('project', 'app\controller\admin\Project@index')->completeMatch();

        // 属地政策规则（子路径必须注册在父路径之前）
        Route::get('policy-rule/meta', 'app\controller\admin\PolicyRule@meta');
        Route::get('policy-rule/evaluate', 'app\controller\admin\PolicyRule@evaluate');
        Route::get('policy-rule/template', 'app\controller\admin\PolicyRule@template');
        Route::get('policy-rule/export', 'app\controller\admin\PolicyRule@export');
        Route::post('policy-rule/save', 'app\controller\admin\PolicyRule@save');
        Route::post('policy-rule/batch-delete', 'app\controller\admin\PolicyRule@batchDelete');
        Route::post('policy-rule/batch-status', 'app\controller\admin\PolicyRule@batchStatus');
        Route::post('policy-rule/delete', 'app\controller\admin\PolicyRule@delete');
        Route::get('policy-rule', 'app\controller\admin\PolicyRule@index')->completeMatch();

        // 智能匹配（子路径必须注册在父路径之前）
        Route::get('match/recommend', 'app\controller\admin\MatchCenter@recommend');
        Route::get('match/why', 'app\controller\admin\MatchCenter@why');
        Route::post('match/record', 'app\controller\admin\MatchCenter@record');
        Route::post('match/select', 'app\controller\admin\MatchCenter@select');
        Route::post('match/to-manual', 'app\controller\admin\MatchCenter@toManual');
        Route::post('match/give-up', 'app\controller\admin\MatchCenter@giveUp');
        Route::get('match', 'app\controller\admin\MatchCenter@index')->completeMatch();

        // 冲突检测（子路径必须注册在父路径之前）
        // v2.55：冲突规则设置（读取 / 保存，仅管理员）
        Route::get('conflict/rules', 'app\controller\admin\Conflict@rules');
        Route::post('conflict/rules-save', 'app\controller\admin\Conflict@rulesSave');
        // v2.99.14：证书互斥组逐条自定义（增删改）
        Route::get('conflict/exclusive-groups', 'app\controller\admin\Conflict@exclusiveGroups');
        Route::post('conflict/exclusive-group-save', 'app\controller\admin\Conflict@exclusiveGroupSave');
        Route::post('conflict/exclusive-group-delete', 'app\controller\admin\Conflict@exclusiveGroupDelete');
        Route::get('conflict/check-cert', 'app\controller\admin\Conflict@checkCert');
        Route::get('conflict/check-staff', 'app\controller\admin\Conflict@checkStaff');
        Route::get('conflict/list', 'app\controller\admin\Conflict@list');
        Route::get('conflict/scan', 'app\controller\admin\Conflict@scan');
        // v2.56：读取最近一次体检结果（刷新后恢复上次体检的冲突清单）
        Route::get('conflict/scan-result', 'app\controller\admin\Conflict@scanResult');
        Route::get('conflict', 'app\controller\admin\Conflict@index')->completeMatch();

        // v2.62：政务网申报进度（子路径必须注册在父路径之前）
        Route::get('gov-progress/meta', 'app\controller\admin\GovProgress@meta');
        Route::get('gov-progress/detail', 'app\controller\admin\GovProgress@detail');
        Route::get('gov-progress/export', 'app\controller\admin\GovProgress@export');
        Route::get('gov-progress/template', 'app\controller\admin\GovProgress@template');
        Route::get('gov-progress/runs', 'app\controller\admin\GovProgress@runs');
        Route::post('gov-progress/save', 'app\controller\admin\GovProgress@save');
        Route::post('gov-progress/delete', 'app\controller\admin\GovProgress@delete');
        Route::post('gov-progress/batch-delete', 'app\controller\admin\GovProgress@batchDelete');
        Route::post('gov-progress/archive', 'app\controller\admin\GovProgress@archive');
        Route::post('gov-progress/sync', 'app\controller\admin\GovProgress@sync');
        Route::post('gov-progress/sync-all', 'app\controller\admin\GovProgress@syncAll');
        Route::post('gov-progress/repair', 'app\controller\admin\GovProgress@repair');
        Route::get('gov-progress', 'app\controller\admin\GovProgress@index');

        // v2.63：企业 / 个人平台账号密码库（子路径必须注册在父路径之前）
        Route::get('credential-vault/meta', 'app\controller\admin\CredentialVault@meta');
        Route::get('credential-vault/detail', 'app\controller\admin\CredentialVault@detail');
        Route::get('credential-vault/reveal', 'app\controller\admin\CredentialVault@reveal');
        Route::get('credential-vault/export', 'app\controller\admin\CredentialVault@export');
        Route::get('credential-vault/template', 'app\controller\admin\CredentialVault@template');
        Route::post('credential-vault/save', 'app\controller\admin\CredentialVault@save');
        Route::post('credential-vault/quick-platform', 'app\controller\admin\CredentialVault@quickPlatform');
        Route::post('credential-vault/import', 'app\controller\admin\CredentialVault@import');
        Route::post('credential-vault/delete', 'app\controller\admin\CredentialVault@delete');
        Route::post('credential-vault/batch-delete', 'app\controller\admin\CredentialVault@batchDelete');
        Route::get('credential-vault', 'app\controller\admin\CredentialVault@index');

        // 字典
        Route::get('dict-type', 'app\controller\admin\DictType@index');
        Route::get('dict-data', 'app\controller\admin\DictData@index');

        // v2.99.35：共享只读主数据（跨模块引用选择源，不挂业务菜单守卫，见 MasterData）
        Route::get('master/company-options', 'app\controller\admin\MasterData@companyOptions');
        Route::get('master/staff-options', 'app\controller\admin\MasterData@staffOptions');
        // v2.99.68：跨模块快捷新建引用主数据（仅跳过菜单守卫，控制器内强制业务写权限校验）
        Route::post('master/company-save', 'app\controller\admin\MasterData@companySave');
        Route::post('master/staff-save', 'app\controller\admin\MasterData@staffSave');
        Route::post('master/ocr-idcard', 'app\controller\admin\MasterData@ocrIdcard');

        // 合同 / 续期 / 操作日志
        Route::get('contract', 'app\controller\admin\Contract@index');
        Route::get('custom-field', 'app\controller\admin\CustomField@index');
        Route::get('cert-renewal', 'app\controller\admin\CertRenewal@index');
        Route::get('operation-log', 'app\controller\admin\OperationLog@index');
        // v2.99.47：敏感信息明文访问审计（仅超管）
        Route::get('sensitive-access', 'app\controller\admin\SensitiveAccess@index');
        // v2.70：用户登录日志（子路径必须在父路径之前）
        Route::post('login-log/clear', 'app\controller\admin\LoginLog@clear');
        Route::get('login-log', 'app\controller\admin\LoginLog@index');

        // v2.96：登录设备管理（超级管理员）
        Route::get('login-device/users', 'app\controller\admin\LoginDevice@users');
        Route::post('login-device/revoke', 'app\controller\admin\LoginDevice@revoke');
        Route::post('login-device/revoke-all', 'app\controller\admin\LoginDevice@revokeAll');
        Route::get('login-device', 'app\controller\admin\LoginDevice@index');

        // ===== v2.85：考试报名模块（子路径必须注册在父路径之前） =====
        // 报名-证书分类 / 证书类型
        Route::get('exam-cert/categories', 'app\controller\admin\ExamCert@categories');
        Route::post('exam-cert/category-save', 'app\controller\admin\ExamCert@categorySave');
        Route::post('exam-cert/category-delete', 'app\controller\admin\ExamCert@categoryDelete');
        Route::get('exam-cert/type-options', 'app\controller\admin\ExamCert@typeOptions');
        Route::post('exam-cert/type-save', 'app\controller\admin\ExamCert@typeSave');
        Route::post('exam-cert/type-delete', 'app\controller\admin\ExamCert@typeDelete');
        Route::get('exam-cert/types', 'app\controller\admin\ExamCert@types');

        // 报名-业务动作 / 业务组合（配置中心）
        Route::get('exam-config/actions', 'app\controller\admin\ExamConfig@actions');
        Route::post('exam-config/action-save', 'app\controller\admin\ExamConfig@actionSave');
        Route::post('exam-config/action-delete', 'app\controller\admin\ExamConfig@actionDelete');
        Route::get('exam-config/config-detail', 'app\controller\admin\ExamConfig@configDetail');
        Route::post('exam-config/config-save', 'app\controller\admin\ExamConfig@configSave');
        Route::post('exam-config/config-delete', 'app\controller\admin\ExamConfig@configDelete');
        Route::post('exam-config/config-open', 'app\controller\admin\ExamConfig@configOpen');
        Route::post('exam-config/trial', 'app\controller\admin\ExamConfig@trial');
        Route::get('exam-config/configs', 'app\controller\admin\ExamConfig@configs');

        // 报名-考点 / 批次
        Route::get('exam-batch/room-options', 'app\controller\admin\ExamBatch@roomOptions');
        Route::post('exam-batch/room-save', 'app\controller\admin\ExamBatch@roomSave');
        Route::post('exam-batch/room-delete', 'app\controller\admin\ExamBatch@roomDelete');
        Route::get('exam-batch/rooms', 'app\controller\admin\ExamBatch@rooms');
        Route::get('exam-batch/options', 'app\controller\admin\ExamBatch@options');
        Route::get('exam-batch/detail', 'app\controller\admin\ExamBatch@detail');
        Route::post('exam-batch/save', 'app\controller\admin\ExamBatch@save');
        Route::post('exam-batch/delete', 'app\controller\admin\ExamBatch@delete');
        Route::post('exam-batch/current', 'app\controller\admin\ExamBatch@current');
        Route::get('exam-batch', 'app\controller\admin\ExamBatch@index');

        // 报名工单 / 考生 / 材料 / 审核 / 提醒
        Route::get('exam-signup/reminders', 'app\controller\admin\ExamSignup@reminders');
        Route::get('exam-signup/customers', 'app\controller\admin\ExamSignup@customers');
        Route::post('exam-signup/customer-save', 'app\controller\admin\ExamSignup@customerSave');
        Route::get('exam-signup/meta', 'app\controller\admin\ExamSignup@meta');
        Route::get('exam-signup/detail', 'app\controller\admin\ExamSignup@detail');
        // v2.99.61：考生身份证明文受控出口（staff:reveal，服务端留痕）
        Route::post('exam-signup/reveal', 'app\controller\admin\ExamSignup@reveal');
        Route::post('exam-signup/save', 'app\controller\admin\ExamSignup@save');
        Route::post('exam-signup/submit', 'app\controller\admin\ExamSignup@submit');
        Route::post('exam-signup/advance', 'app\controller\admin\ExamSignup@advance');
        Route::post('exam-signup/audit', 'app\controller\admin\ExamSignup@audit');
        Route::post('exam-signup/material-save', 'app\controller\admin\ExamSignup@materialSave');
        Route::post('exam-signup/material-delete', 'app\controller\admin\ExamSignup@materialDelete');
        Route::post('exam-signup/ocr-idcard', 'app\controller\admin\ExamSignup@ocrIdcard');
        Route::post('exam-signup/ocr-diploma', 'app\controller\admin\ExamSignup@ocrDiploma');
        Route::post('exam-signup/delete', 'app\controller\admin\ExamSignup@delete');
        Route::get('exam-signup', 'app\controller\admin\ExamSignup@index');

        // v2.99.70：安管人员成绩查询（新取证 / 继续教育）
        Route::get('exam-score/companies', 'app\controller\admin\ExamScore@companies');
        Route::post('exam-score/captcha', 'app\controller\admin\ExamScore@captcha');
        Route::post('exam-score/captcha-refresh', 'app\controller\admin\ExamScore@captchaRefresh');
        Route::post('exam-score/query', 'app\controller\admin\ExamScore@query');
        Route::post('exam-score/delete', 'app\controller\admin\ExamScore@delete');
        Route::get('exam-score', 'app\controller\admin\ExamScore@index');

        // ===== v2.99：学历报名模块（子路径必须注册在父路径之前） =====
        // 第三方报名机构
        Route::get('edu-agency/options', 'app\controller\admin\EduAgency@options');
        Route::get('edu-agency/detail', 'app\controller\admin\EduAgency@detail');
        Route::post('edu-agency/save', 'app\controller\admin\EduAgency@save');
        Route::post('edu-agency/delete', 'app\controller\admin\EduAgency@delete');
        Route::get('edu-agency', 'app\controller\admin\EduAgency@index');

        // 进度流程配置
        Route::get('edu-config/flows', 'app\controller\admin\EduConfig@flows');
        Route::get('edu-config/flow-detail', 'app\controller\admin\EduConfig@flowDetail');
        Route::post('edu-config/flow-save', 'app\controller\admin\EduConfig@flowSave');
        Route::post('edu-config/flow-reset', 'app\controller\admin\EduConfig@flowReset');

        // 报名工单 / 人员 / 附件 / 流转
        Route::get('edu-signup/meta', 'app\controller\admin\EduSignup@meta');
        Route::get('edu-signup/staff-options', 'app\controller\admin\EduSignup@staffOptions');
        Route::post('edu-signup/staff-save', 'app\controller\admin\EduSignup@staffSave');
        Route::get('edu-signup/detail', 'app\controller\admin\EduSignup@detail');
        // v2.99.61：报名单身份证明文受控出口（staff:reveal，服务端留痕）
        Route::post('edu-signup/reveal', 'app\controller\admin\EduSignup@reveal');
        Route::post('edu-signup/save', 'app\controller\admin\EduSignup@save');
        Route::post('edu-signup/advance', 'app\controller\admin\EduSignup@advance');
        Route::post('edu-signup/unarchive', 'app\controller\admin\EduSignup@unarchive');
        Route::post('edu-signup/attach-save', 'app\controller\admin\EduSignup@attachSave');
        Route::post('edu-signup/attach-rename', 'app\controller\admin\EduSignup@attachRename');
        Route::post('edu-signup/attach-delete', 'app\controller\admin\EduSignup@attachDelete');
        Route::post('edu-signup/delete', 'app\controller\admin\EduSignup@delete');
        Route::get('edu-signup', 'app\controller\admin\EduSignup@index');

        // ===== v2.99.1：在线升级（子路径必须注册在父路径之前） =====
        Route::get('upgrade/info', 'app\controller\admin\Upgrade@info');
        Route::get('upgrade/progress', 'app\controller\admin\Upgrade@progress');
        Route::post('upgrade/check', 'app\controller\admin\Upgrade@check');
        Route::post('upgrade/backup', 'app\controller\admin\Upgrade@backup');
        Route::post('upgrade/download', 'app\controller\admin\Upgrade@download');
        Route::post('upgrade/upload', 'app\controller\admin\Upgrade@upload');
        Route::post('upgrade/apply', 'app\controller\admin\Upgrade@apply');
        Route::post('upgrade/restore', 'app\controller\admin\Upgrade@restore');
        Route::post('upgrade/delete-backup', 'app\controller\admin\Upgrade@deleteBackup');
        Route::post('upgrade/batch-delete-backup', 'app\controller\admin\Upgrade@batchDeleteBackup');
    })->middleware(['datascope', 'adminauth', 'permauth', 'deleteotp']);

    // -------- 客户企业端（只读） --------
    Route::group('client', function () {
        Route::get('dashboard/stat', 'app\controller\client\Dashboard@stat');
        Route::get('certificate', 'app\controller\client\Certificate@index');
        Route::get('alert', 'app\controller\admin\Alert@index');
        Route::get('company', 'app\controller\admin\Company@index');
    })->middleware('datascope');

})->middleware('auth');
