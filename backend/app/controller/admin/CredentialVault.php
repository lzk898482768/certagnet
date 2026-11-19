<?php
namespace app\controller\admin;

use app\service\CredentialVaultService;
use app\service\DeptScopeService;
use app\service\ExcelService;
use app\service\SecretService;
use think\facade\Db;

/**
 * 企业 / 个人平台账号密码库（v2.63）
 *
 *  - GET  /admin/credential-vault             列表
 *  - GET  /admin/credential-vault/meta        类型 / 平台下拉
 *  - GET  /admin/credential-vault/detail      编辑详情（含明文密码）
 *  - GET  /admin/credential-vault/reveal      查看 / 复制单条密码（写操作日志）
 *  - GET  /admin/credential-vault/export      导出（含明文，谨慎）
 *  - GET  /admin/credential-vault/template    导入模板
 *  - POST /admin/credential-vault/save        新增 / 编辑
 *  - POST /admin/credential-vault/import      Excel 导入
 *  - POST /admin/credential-vault/delete      删除
 *  - POST /admin/credential-vault/batch-delete批量删除
 *
 * 权限与「政务网进度」一致：admin / cert_manager / project_officer 可读写，
 * viewer 只读；company 与 finance 不开放。
 */
class CredentialVault extends AdminController
{
    /**
     * v2.99.35：移除硬编码角色码白名单，访问权统一交给 RBAC——
     * 读由菜单 60（PermAuth 菜单级）控制，写由 vault:* 权限码（PermAuth 功能级）控制。
     * 保留空方法壳，避免控制器内大量调用点改动；后续阶段可直接删除。
     */
    protected function requireRead(): void
    {
        // 菜单级守卫已在 PermAuth 完成，这里不再做角色码限制
    }

    protected function requireWrite(): void
    {
        // 写操作权限码（vault:create/edit/import/reveal 等）已在 PermAuth 校验
    }

    public function index()
    {
        $this->requireRead();
        CredentialVaultService::ensureTables();

        [$page, $limit] = $this->pageParams();
        $q = Db::name('credential_vault')->alias('v');
        $this->scopeVault($q);
        $this->applyFilters($q);
        $total = (clone $q)->count();
        $list = (clone $q)->field('v.*')->order('v.platform', 'asc')->order('v.id', 'desc')
            ->page($page, $limit)->select()->toArray();
        CredentialVaultService::decorateList($list);

        $base = function () {
            return $this->applyModuleScope(Db::name('credential_vault'), 'credential_vault');
        };
        return json(r_success([
            'list'  => $list,
            'total' => $total,
            'stat'  => [
                'total'    => (clone $base())->count(),
                'company'  => (clone $base())->where('account_type', 'company')->count(),
                'personal' => (clone $base())->where('account_type', 'personal')->count(),
                'platform' => (int)(clone $base())->group('platform')->count(),
            ],
        ]));
    }

    /**
     * 数据范围（v2.99.36）：账号库属「其他模块」——
     * 客户账号按授权企业边界，内部办公账号按创建人 create_by。
     */
    protected function scopeVault($query, string $alias = 'v')
    {
        return $this->applyModuleScope($query, 'credential_vault', $alias . '.create_by', $alias . '.company_id');
    }

    protected function applyFilters($q): void
    {
        $keyword = trim((string)$this->request->param('keyword', ''));
        if ($keyword !== '') {
            // v2.73.1：关键字同时匹配平台/网址/账号/备注，以及关联企业名称、关联人员姓名
            $q->leftJoin('client_company c', 'c.id = v.company_id')
              ->leftJoin('staff st', 'st.id = v.staff_id')
              ->where(function ($w) use ($keyword) {
                  $w->where('v.platform', 'like', "%{$keyword}%")
                    ->whereOr('v.platform_url', 'like', "%{$keyword}%")
                    ->whereOr('v.account_name', 'like', "%{$keyword}%")
                    ->whereOr('v.remark', 'like', "%{$keyword}%")
                    ->whereOr('c.company_name', 'like', "%{$keyword}%")
                    ->whereOr('st.staff_name', 'like', "%{$keyword}%");
              });
        }
        $platform = trim((string)$this->request->param('platform', ''));
        if ($platform !== '') {
            $q->where('v.platform', $platform);
        }
        $type = trim((string)$this->request->param('account_type', ''));
        if ($type !== '') {
            $q->where('v.account_type', $type);
        }
        $companyId = (int)$this->request->param('company_id', 0);
        if ($companyId > 0) {
            $q->where('v.company_id', $companyId);
        }
        $staffId = (int)$this->request->param('staff_id', 0);
        if ($staffId > 0) {
            $q->where('v.staff_id', $staffId);
        }
    }

    public function meta()
    {
        $this->requireRead();
        CredentialVaultService::ensureTables();
        $platformOptions = CredentialVaultService::platformOptions();
        return json(r_success([
            'account_types'    => CredentialVaultService::ACCOUNT_TYPES,
            // 兼容旧前端：保留平台名称数组
            'platforms'        => array_values(array_map(static function ($x) {
                return $x['name'];
            }, $platformOptions)),
            // v2.64：平台下拉选项（含预设网址）
            'platform_options' => $platformOptions,
        ]));
    }

    /** v2.64：账号密码库快捷新增平台（写入字典 gov_platform） */
    public function quickPlatform()
    {
        $this->requireWrite();
        $name = trim((string)$this->request->post('name', ''));
        $url  = trim((string)$this->request->post('url', ''));
        try {
            $option = CredentialVaultService::addPlatform($name, $url);
        } catch (\InvalidArgumentException $e) {
            return json(r_error($e->getMessage(), 400));
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage(), 500));
        }
        $this->log('账号密码库', '新增平台', $option['name'] . ($option['url'] !== '' ? ' ' . $option['url'] : ''));
        $msg = !empty($option['created']) ? '平台已新增并加入下拉框' : '平台已存在，已加入下拉框';
        return json(r_success($option, $msg));
    }

    public function detail()
    {
        $this->requireWrite();
        CredentialVaultService::ensureTables();
        $id = (int)$this->request->param('id', 0);
        if (!$id) {
            return json(r_error('缺少 id', 400));
        }
        $row = Db::name('credential_vault')->where('id', $id)->find();
        if (!$row) {
            return json(r_error('账号记录不存在', 404));
        }
        $this->assertModuleAccess('credential_vault', $id);
        $row['account_type_text'] = CredentialVaultService::ACCOUNT_TYPES[(string)$row['account_type']] ?? '企业账号';
        $row['password'] = SecretService::decrypt((string)($row['password_enc'] ?? ''));
        unset($row['password_enc']);
        return json(r_success($row));
    }

    public function reveal()
    {
        $this->requireRead();
        CredentialVaultService::ensureTables();
        $id = (int)$this->request->param('id', 0);
        if (!$id) {
            return json(r_error('缺少 id', 400));
        }
        $row = Db::name('credential_vault')->where('id', $id)->find();
        if (!$row) {
            return json(r_error('账号记录不存在', 404));
        }
        $this->assertModuleAccess('credential_vault', $id);
        $password = SecretService::decrypt((string)($row['password_enc'] ?? ''));
        if ($password === '') {
            return json(r_error('该记录未保存密码或密码解密失败', 400));
        }
        $this->log('账号密码库', '查看密码', "#{$id} {$row['platform']} / {$row['account_name']}");
        return json(r_success(['id' => $id, 'password' => $password], 'ok'));
    }

    public function save()
    {
        $this->requireWrite();
        CredentialVaultService::ensureTables();

        $id = (int)$this->request->post('id', 0);
        $platform = trim((string)$this->request->post('platform', ''));
        $platformUrl = trim((string)$this->request->post('platform_url', ''));
        $accountType = trim((string)$this->request->post('account_type', 'company'));
        $companyId = (int)$this->request->post('company_id', 0);
        $staffId = (int)$this->request->post('staff_id', 0);
        $accountName = trim((string)$this->request->post('account_name', ''));
        $password = trim((string)$this->request->post('password', ''));
        $remark = trim((string)$this->request->post('remark', ''));
        $status = (int)$this->request->post('status', 1) === 0 ? 0 : 1;

        if ($platform === '') {
            return json(r_error('请填写平台名称', 400));
        }
        if ($accountName === '') {
            return json(r_error('请填写登录账号', 400));
        }
        if (!isset(CredentialVaultService::ACCOUNT_TYPES[$accountType])) {
            return json(r_error('账号类型不正确', 400));
        }
        if ($password !== '' && mb_strlen($password) > 255) {
            return json(r_error('密码过长（最多 255 位）', 400));
        }
        if ($platformUrl !== '' && !preg_match('#^https?://#i', $platformUrl)) {
            return json(r_error('平台网址需以 http:// 或 https:// 开头', 400));
        }
        // v2.99.35：企业/人员仅作引用主数据，校验存在性即可，不再要求调用方具备
        // 企业客户/人员档案的数据权限（三轴解耦：账号库的授权不依赖主数据菜单）。
        if ($companyId > 0) {
            $company = Db::name('client_company')->where('id', $companyId)->find();
            if (!$company) {
                return json(r_error('关联企业不存在', 400));
            }
        }
        if ($staffId > 0) {
            $staff = Db::name('staff')->where('id', $staffId)->find();
            if (!$staff) {
                return json(r_error('关联人员不存在', 400));
            }
        }

        $old = null;
        if ($id) {
            $old = Db::name('credential_vault')->where('id', $id)->find();
            if (!$old) {
                return json(r_error('账号记录不存在', 404));
            }
            // v2.99.36：内部账号仅能编辑本人创建的账号记录
            $this->assertModuleAccess('credential_vault', $id);
        }

        $dup = Db::name('credential_vault')
            ->where('platform', $platform)
            ->where('account_type', $accountType)
            ->where('account_name', $accountName)
            ->where('company_id', $companyId)
            ->where('staff_id', $staffId)
            ->where('id', '<>', $id)
            ->find();
        if ($dup) {
            return json(r_error('该平台下已存在相同账号（#' . $dup['id'] . '），请直接编辑', 400));
        }

        $data = [
            'platform'     => mb_substr($platform, 0, 128),
            'platform_url' => mb_substr($platformUrl, 0, 500),
            'account_type' => $accountType,
            'company_id'   => $companyId,
            'staff_id'     => $staffId,
            'account_name' => mb_substr($accountName, 0, 255),
            'remark'       => mb_substr($remark, 0, 500),
            'status'       => $status,
        ];

        if ($id) {
            if ($password !== '') {
                $data['password_enc'] = SecretService::encrypt($password);
            }
            Db::name('credential_vault')->where('id', $id)->update($data);
            $this->log('账号密码库', '更新', "#{$id} {$platform} / {$accountName}");
            return json(r_success(['id' => $id], '保存成功'));
        }

        if ($password === '') {
            return json(r_error('请填写登录密码', 400));
        }
        $data['password_enc'] = SecretService::encrypt($password);
        $data['owner_id'] = (int)$this->request->userId;
        $data['create_by'] = (int)$this->request->userId;
        $data['dept_id'] = $this->primaryDeptId();
        $data['create_time'] = date('Y-m-d H:i:s');
        $data['update_time'] = date('Y-m-d H:i:s');
        $newId = (int)Db::name('credential_vault')->insertGetId($data);
        $this->log('账号密码库', '新增', "#{$newId} {$platform} / {$accountName}");
        return json(r_success(['id' => $newId], '保存成功'));
    }

    protected function primaryDeptId(): int
    {
        $userId = (int)$this->request->userId;
        if ($userId <= 0) {
            return 0;
        }
        $ids = DeptScopeService::userDeptIds($userId);
        return (int)($ids[0] ?? 0);
    }

    public function delete()
    {
        $this->requireWrite();
        $id = (int)$this->request->post('id', 0);
        if (!$id) {
            return json(r_error('缺少 id', 400));
        }
        $r = $this->deleteOne($id);
        return $r['ok'] ? json(r_success([], '删除成功')) : json(r_error($r['msg'], 400));
    }

    protected function deleteOne(int $id): array
    {
        $row = Db::name('credential_vault')->where('id', $id)->find();
        if (!$row) {
            return ['ok' => false, 'msg' => '账号记录不存在'];
        }
        if (!$this->canAccessModule('credential_vault', $id)) {
            return ['ok' => false, 'msg' => '无权删除该账号记录'];
        }
        try {
            \app\service\RecycleBinService::stash(
                (int)$this->request->userId,
                'credential_vault',
                ((string)($row['platform'] ?? '') ?: '账号') . ' / ' . (string)($row['account_name'] ?? ''),
                $row
            );
        } catch (\Throwable $e) {
            // 快照失败不阻断删除
        }
        Db::name('credential_vault')->where('id', $id)->delete();
        $this->log('账号密码库', '删除', "#{$id} {$row['platform']} / {$row['account_name']}");
        return ['ok' => true, 'msg' => '删除成功'];
    }

    public function batchDelete()
    {
        $this->requireWrite();
        $ids = (array)$this->request->post('ids/a', []);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return json(r_error('请选择要删除的账号', 400));
        }
        $ok = 0;
        $fail = [];
        foreach ($ids as $id) {
            $r = $this->deleteOne($id);
            if (!empty($r['ok'])) {
                $ok++;
            } else {
                $fail[] = "#{$id} " . ($r['msg'] ?? '删除失败');
            }
        }
        $msg = $fail ? "已删除 {$ok} 条，" . count($fail) . ' 条失败' : "已删除 {$ok} 条";
        return json(r_success(['ok' => $ok, 'fail' => $fail], $msg));
    }

    /** 导出含明文密码，仅限本模块查看权限；操作日志留痕 */
    public function export()
    {
        $this->requireRead();
        // v2.99.49：含明文密码，导出前强制 OTP 二次鉴别
        $this->requireExportTicket('credential_vault');
        CredentialVaultService::ensureTables();

        $q = Db::name('credential_vault')->alias('v');
        $this->scopeVault($q);
        $this->applyFilters($q);
        $rows = $q->field('v.*')->order('v.platform', 'asc')->order('v.id', 'desc')->limit(5000)->select()->toArray();
        $formatted = CredentialVaultService::exportRows($rows);
        $path = ExcelService::export('credential_vault', $formatted);
        $this->log('账号密码库', '导出', '导出 ' . count($formatted) . ' 条（含明文密码）');
        return download_utf8($path, '平台账号密码库_' . date('Ymd') . '.xlsx');
    }

    public function template()
    {
        $this->requireRead();
        $path = ExcelService::template('credential_vault');
        return download_utf8($path, '平台账号密码库导入模板.xlsx');
    }

    public function import()
    {
        $this->requireWrite();
        CredentialVaultService::ensureTables();

        $file = $this->request->file('file');
        if (!$file) {
            return json(r_error('请上传文件', 400));
        }
        $ext = strtolower((string)$file->getOriginalExtension());
        if (!in_array($ext, ['xlsx', 'xls'], true)) {
            return json(r_error('仅支持 .xlsx / .xls 格式的 Excel 文件', 400));
        }
        if ((int)$file->getSize() > 10 * 1024 * 1024) {
            return json(r_error('文件大小不能超过 10MB', 400));
        }
        $info = $file->move(app()->getRuntimePath() . 'upload/');
        if (!$info) {
            return json(r_error('文件上传失败', 400));
        }
        $path = $info->getPathname();
        try {
            $rows = ExcelService::import('credential_vault', $path);
        } catch (\Throwable $e) {
            @unlink($path);
            return json(r_error('解析失败：' . $e->getMessage(), 400));
        }
        @unlink($path);
        if (!$rows) {
            return json(r_error('文件中没有可导入的数据', 400));
        }
        $res = CredentialVaultService::importRows($rows, (int)$this->request->userId, $this->primaryDeptId());
        $this->log('账号密码库', '导入', "新增{$res['inserted']}条/更新{$res['updated']}条/跳过{$res['skipped']}条");
        $msg = "导入完成：新增 {$res['inserted']} 条，更新 {$res['updated']} 条，跳过 {$res['skipped']} 条";
        return json(r_success($res, $msg));
    }
}
