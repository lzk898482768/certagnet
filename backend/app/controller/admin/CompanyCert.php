<?php
namespace app\controller\admin;

use app\service\CompanyQualificationService;
use app\service\CompanyPlatformService;
use app\service\ExcelService;
use app\service\XkgsService;
use think\facade\Db;

/**
 * 企业证书（安许 / 资质）集中台账
 *
 * 复用 company_qualification 表与既有河南平台采集链路（企业客户页），
 * 本控制器只提供「跨企业聚合视图」：到期状态集中展示、导出、删除。
 * 数据范围与企业客户列表一致（scopeCompany → visibleCompanyIds）。
 */
class CompanyCert extends AdminController
{
    /**
     * 跨企业聚合列表
     * 查询参数：q_type / company_id / keyword（证书名/编号）/ expire_status
     *  expire_status: soon 30天内到期 / expired 已过期 / normal 正常
     */
    public function index()
    {
        [$page, $limit] = $this->pageParams();
        CompanyQualificationService::ensureSchema();

        [$filters, $today, $soonDate] = [$this->filters(), date('Y-m-d'), date('Y-m-d', strtotime('+30 days'))];

        $q = $this->buildQuery($filters);
        $total = (clone $q)->count();        $rows = (clone $q)->orderRaw("IF(expire_date IS NULL OR expire_date = '', 1, 0) asc, expire_date asc, id desc")
            ->page($page, $limit)->select()->toArray();

        $companyIds = array_values(array_unique(array_map(fn($r) => (int)$r['company_id'], $rows)));
        $companyMap = [];
        if ($companyIds) {
            foreach (Db::name('client_company')->whereIn('id', $companyIds)->field('id,company_name,credit_code')->select()->toArray() as $c) {
                $companyMap[(int)$c['id']] = $c;
            }
        }

        $todayTs = strtotime($today);
        $list = [];
        foreach ($rows as $row) {
            $company = $companyMap[(int)$row['company_id']] ?? null;
            $remain  = !empty($row['expire_date']) ? remain_days((string)$row['expire_date']) : null;
            $list[] = [
                'id'                => (int)$row['id'],
                'company_id'        => (int)$row['company_id'],
                'company_name'      => $company['company_name'] ?? ('企业#' . $row['company_id']),
                'credit_code'       => $company['credit_code'] ?? '',
                'q_type'            => (string)$row['q_type'],
                'q_type_text'       => ($row['q_type'] ?? '') === 'safety' ? '安许' : '资质',
                'cert_name'         => (string)$row['cert_name'],
                'cert_level'        => (string)$row['cert_level'],
                'cert_no'           => (string)$row['cert_no'],
                'cert_scope'        => (string)$row['cert_scope'],
                'issue_org'         => (string)$row['issue_org'],
                'issue_date'        => $row['issue_date'],
                'expire_date'       => $row['expire_date'],
                'remain_days'       => $remain,
                'expired'           => !empty($row['expire_date']) && strtotime((string)$row['expire_date']) < $todayTs,
                'source'            => (string)$row['source'],
                'source_text'       => CompanyQualificationService::sourceText((string)($row['source'] ?? '')),
                'source_url'        => (string)$row['source_url'],
                'fetched_at'        => $row['fetched_at'],
                'remark'            => (string)$row['remark'],
                'update_time'       => $row['update_time'],
            ];
        }

        // v2.94：列表补附件数（详情弹窗再按需展开）
        \app\service\AttachService::attachList('company_cert', $list);

        return json(r_success([
            'list'  => $list,
            'total' => $total,
            'stat'  => $this->statData($this->visibleCompanyIdsForQuery()),
        ]));
    }

    /** 风险计数（首页卡片 / 列表页头部共用；受数据范围限制） */
    public function stat()
    {
        CompanyQualificationService::ensureSchema();
        return json(r_success($this->statData($this->visibleCompanyIdsForQuery())));
    }

    /**
     * 统计数据（供 Dashboard 内部调用）
     * @param array|null $visibleCompanyIds null=不限制（admin）；[]=空集；传入数组=限定这些企业
     */
    public static function statData(?array $visibleCompanyIds = null): array
    {
        CompanyQualificationService::ensureSchema();
        $today    = date('Y-m-d');
        $soonDate = date('Y-m-d', strtotime('+30 days'));
        $base = function () use ($visibleCompanyIds) {
            $q = Db::name('company_qualification')->where('status', 1);
            if ($visibleCompanyIds !== null) {
                $q->whereIn('company_id', $visibleCompanyIds ?: [0]);
            }
            return $q;
        };
        return [
            'total'         => (clone $base())->count(),
            'safety_total'  => (clone $base())->where('q_type', 'safety')->count(),
            'soon'          => (clone $base())->whereNotNull('expire_date')
                ->whereBetween('expire_date', [$today, $soonDate])->count(),
            'expired'       => (clone $base())->whereNotNull('expire_date')
                ->where('expire_date', '<', $today)->count(),
        ];
    }

    /** 导出（最多 5000 条，沿用当前筛选与数据范围） */
    public function export()
    {
        if (!$this->can('companycert', 'export')) {
            return json(r_error('暂无导出权限，请联系管理员', 403));
        }
        CompanyQualificationService::ensureSchema();
        $filters = $this->filters();
        $rows = $this->buildQuery($filters)
            ->orderRaw("IF(expire_date IS NULL OR expire_date = '', 1, 0) asc, expire_date asc, id desc")
            ->limit(5000)->select()->toArray();

        $companyIds = array_values(array_unique(array_map(fn($r) => (int)$r['company_id'], $rows)));
        $companyMap = [];
        if ($companyIds) {
            foreach (Db::name('client_company')->whereIn('id', $companyIds)->field('id,company_name')->select()->toArray() as $c) {
                $companyMap[(int)$c['id']] = $c['company_name'];
            }
        }
        $todayTs = strtotime(date('Y-m-d'));
        $formatted = [];
        foreach ($rows as $r) {
            $expire = (string)($r['expire_date'] ?? '');
            $statusText = '长期';
            if ($expire !== '') {
                if (strtotime($expire) < $todayTs) {
                    $statusText = '已过期';
                } else {
                    $statusText = '剩余' . remain_days($expire) . '天';
                }
            }
            $formatted[] = [
                'company_name' => $companyMap[(int)$r['company_id']] ?? ('企业#' . $r['company_id']),
                'q_type_text'  => ($r['q_type'] ?? '') === 'safety' ? '安许' : '资质',
                'cert_name'    => (string)$r['cert_name'],
                'cert_level'   => (string)$r['cert_level'],
                'cert_no'      => (string)$r['cert_no'],
                'cert_scope'   => (string)$r['cert_scope'],
                'issue_org'    => (string)$r['issue_org'],
                'issue_date'   => (string)($r['issue_date'] ?? ''),
                'expire_date'  => $expire,
                'status_text'  => $statusText,
                'source_text'  => CompanyQualificationService::sourceText((string)($r['source'] ?? '')),
                'remark'       => (string)$r['remark'],
            ];
        }
        $path = ExcelService::export('company_cert', $formatted);
        $this->log('企业证书', '导出', count($formatted) . ' 条');
        return download_utf8($path, '企业证书台账_' . date('Ymd') . '.xlsx');
    }

    /**
     * 删除一条企业证书（软删除，回收站立快照）。
     * 复用 CompanyQualificationService::delete，删除前做企业数据权限校验。
     */
    public function delete()
    {
        if (!$this->can('companycert', 'delete')) {
            return json(r_error('暂无删除权限，请联系管理员', 403));
        }
        $id = (int)$this->request->post('id', 0);
        if ($id <= 0) {
            return json(r_error('参数错误', 400));
        }
        CompanyQualificationService::ensureSchema();
        $row = Db::name('company_qualification')->where('id', $id)->where('status', 1)->find();
        if (!$row) {
            return json(r_error('记录不存在或已删除', 400));
        }
        $this->assertCompanyAccess((int)$row['company_id']);
        try {
            \app\service\RecycleBinService::stash(
                (int)$this->request->userId,
                'company_qualification',
                ((string)($row['cert_name'] ?? '') ?: ('#' . $id)),
                $row
            );
        } catch (\Throwable $e) {
            // 快照失败不阻断删除
        }
        CompanyQualificationService::delete($id);
        \app\controller\admin\Dashboard::forgetDashboard();
        $this->log('企业证书', '删除', "#{$id} " . ($row['cert_name'] ?? ''));
        return json(r_success([], '已删除'));
    }

    /**
     * 单条详情（含附件），供编辑弹窗回填
     */
    public function detail()
    {
        $id = (int)$this->request->param('id', 0);
        if ($id <= 0) {
            return json(r_error('参数错误', 400));
        }
        $row = CompanyQualificationService::detail($id);
        if (!$row) {
            return json(r_error('记录不存在或已删除', 400));
        }
        $this->assertCompanyAccess((int)$row['company_id']);
        $row['attachments']  = \app\service\AttachService::listBy('company_cert', $id);
        $row['attach_count'] = count($row['attachments']);
        return json(r_success($row));
    }

    /**
     * 新增 / 编辑企业证书（含附件全量覆盖）
     *
     * 入参：id（0=新增）、company_id*、q_type、cert_name/cert_no（至少一项）、
     *       cert_level/cert_scope/issue_org/issue_date/expire_date/remark、attachments[]
     */
    public function save()
    {
        $id = (int)$this->request->post('id', 0);
        if ($id > 0) {
            if (!$this->can('companycert', 'edit')) {
                return json(r_error('暂无编辑权限，请联系管理员', 403));
            }
            $exists = Db::name('company_qualification')->where('id', $id)->where('status', 1)->find();
            if (!$exists) {
                return json(r_error('记录不存在或已删除', 400));
            }
            $this->assertCompanyAccess((int)$exists['company_id']);
        } elseif (!$this->can('companycert', 'create')) {
            return json(r_error('暂无新增权限，请联系管理员', 403));
        }

        $input = [
            'company_id'  => (int)$this->request->post('company_id', 0),
            'q_type'      => (string)$this->request->post('q_type', 'qualification'),
            'cert_name'   => (string)$this->request->post('cert_name', ''),
            'cert_level'  => (string)$this->request->post('cert_level', ''),
            'cert_no'     => (string)$this->request->post('cert_no', ''),
            'cert_scope'  => (string)$this->request->post('cert_scope', ''),
            'issue_org'   => (string)$this->request->post('issue_org', ''),
            'issue_date'  => (string)$this->request->post('issue_date', ''),
            'expire_date' => (string)$this->request->post('expire_date', ''),
            'remark'      => (string)$this->request->post('remark', ''),
        ];
        $this->assertCompanyAccess($input['company_id']);

        $attachments = $this->request->post('attachments', null);
        if (is_string($attachments)) {
            $attachments = json_decode($attachments, true) ?: [];
        }
        if ($attachments !== null) {
            if (!is_array($attachments)) {
                return json(r_error('附件数据格式错误', 400));
            }
            $attCheck = \app\service\AttachService::validateAll($attachments);
            if (!$attCheck['ok']) {
                return json(r_error($attCheck['msg'], 422));
            }
        }

        try {
            $res = CompanyQualificationService::save($input, (int)$this->request->userId, $id);
        } catch (\RuntimeException $e) {
            return json(r_error($e->getMessage(), 400));
        } catch (\Throwable $e) {
            return json(r_error('保存失败：' . $e->getMessage(), 500));
        }

        if ($attachments !== null) {
            try {
                \app\service\AttachService::replace('company_cert', (int)$res['id'], $attachments, (int)$this->request->userId);
            } catch (\Throwable $e) {
                // 附件子表异常不阻断主保存（与人员/企业一致）
            }
        }

        \app\controller\admin\Dashboard::forgetDashboard();
        $this->log('企业证书', $id > 0 ? '编辑' : '新增', '#' . $res['id'] . ' ' . $input['cert_name']);
        return json(r_success([
            'id'          => (int)$res['id'],
            'created'     => (bool)$res['created'],
            'attachments' => \app\service\AttachService::listBy('company_cert', (int)$res['id']),
        ], '保存成功'));
    }

    /** 下载导入模板 */
    public function template()
    {
        $path = ExcelService::template('company_cert');
        return download_utf8($path, '企业证书导入模板.xlsx');
    }

    /**
     * Excel 导入企业证书（按所属企业名称匹配，不自动新建企业）
     */
    public function import()
    {
        if (!$this->can('companycert', 'import')) {
            return json(r_error('暂无导入权限，请联系管理员', 403));
        }
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
            $rows = ExcelService::import('company_cert', $path);
        } catch (\Throwable $e) {
            @unlink($path);
            return json(r_error('解析失败：' . $e->getMessage(), 400));
        }
        @unlink($path);
        if (!$rows) {
            return json(r_error('文件中没有可导入的数据', 400));
        }

        $res = CompanyQualificationService::importRows(
            $rows,
            (int)$this->request->userId,
            $this->visibleCompanyIds()
        );
        \app\controller\admin\Dashboard::forgetDashboard();
        $this->log('企业证书', '导入', "新增{$res['saved']}条/更新{$res['updated']}条/跳过{$res['skipped']}条");
        $msg = "导入完成：新增 {$res['saved']} 条，更新 {$res['updated']} 条，跳过 {$res['skipped']} 条";
        return json(r_success($res, $msg));
    }

    /** 独立保存附件（不动证书主体字段） */
    public function attachSave()
    {
        if (!$this->can('companycert', 'attach')) {
            return json(r_error('暂无附件操作权限，请联系管理员', 403));
        }
        $id = (int)$this->request->post('id', 0);
        if ($id <= 0) {
            return json(r_error('参数错误', 400));
        }
        $row = Db::name('company_qualification')->where('id', $id)->where('status', 1)->find();
        if (!$row) {
            return json(r_error('记录不存在或已删除', 400));
        }
        $this->assertCompanyAccess((int)$row['company_id']);

        $items = $this->request->post('attachments', []);
        if (is_string($items)) {
            $items = json_decode($items, true) ?: [];
        }
        if (!is_array($items)) {
            return json(r_error('附件数据格式错误', 400));
        }
        $attCheck = \app\service\AttachService::validateAll($items);
        if (!$attCheck['ok']) {
            return json(r_error($attCheck['msg'], 422));
        }
        try {
            $n = \app\service\AttachService::replace('company_cert', $id, $items, (int)$this->request->userId);
        } catch (\Throwable $e) {
            return json(r_error('附件保存失败：' . $e->getMessage(), 400));
        }
        $this->log('企业证书', '附件', "#{$id} 保存附件 {$n} 个");
        return json(r_success([
            'count'       => $n,
            'attachments' => \app\service\AttachService::listBy('company_cert', $id),
        ], '附件已保存'));
    }

    // ------------------------------------------------------------
    // v2.94：新增 / 编辑弹窗内「河南平台采集」
    // 复用 CompanyPlatformService；安许为公开页免验证码，资质明细必须人工识别验证码，
    // 不破解、不绕过平台校验与限流。
    // ------------------------------------------------------------

    /** 采集对象（自动从企业档案补齐名称 / 信用代码，避免前端篡改） */
    private function collectCompany(): array
    {
        if (!$this->can('companycert', 'create') && !$this->can('companycert', 'edit')) {
            throw new \think\exception\HttpResponseException(
                json(r_error('暂无采集权限，请联系管理员', 403))
            );
        }
        $companyId = (int)$this->request->post('company_id', 0);
        if ($companyId <= 0) {
            throw new \think\exception\HttpResponseException(json(r_error('请先选择所属企业', 400)));
        }
        $this->assertCompanyAccess($companyId);
        $company = Db::name('client_company')->where('id', $companyId)->find();
        if (!$company) {
            throw new \think\exception\HttpResponseException(json(r_error('企业不存在', 400)));
        }
        return $company;
    }

    /** 采集企业基础信息 + 安许（公开详情页，无需验证码） */
    public function collect()
    {
        $company = $this->collectCompany();
        $name = trim((string)($company['company_name'] ?? ''));
        $code = strtoupper(trim((string)($company['credit_code'] ?? '')));
        if ($name === '' && $code === '') {
            return json(r_error('该企业未填写名称或统一社会信用代码，无法采集', 400));
        }
        try {
            $res = CompanyPlatformService::fetch($name, $code, (int)$this->request->post('fresh', 0) === 1);
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage(), 400));
        }
        $res['items'] = CompanyQualificationService::markDuplicated($res['items'] ?? [], (int)$company['id']);
        $this->log('企业证书', '平台采集', "#{$company['id']} {$name}；可导入 " . count($res['items']) . ' 条');
        return json(r_success($res, '采集完成'));
    }

    /** 获取平台图形验证码（人工识别） */
    public function collectCaptcha()
    {
        $company = $this->collectCompany();
        $name = trim((string)($company['company_name'] ?? ''));
        $code = strtoupper(trim((string)($company['credit_code'] ?? '')));
        try {
            $res = CompanyPlatformService::captcha($code, $name);
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage(), 400));
        }
        return json(r_success($res, '请识别图片中的验证码'));
    }

    /** 同一会话刷新验证码 */
    public function collectCaptchaRefresh()
    {
        $this->collectCompany();
        $captchaId = trim((string)$this->request->post('captcha_id', ''));
        if ($captchaId === '') {
            return json(r_error('验证码会话已失效，请重新获取验证码', 400));
        }
        try {
            $res = CompanyPlatformService::refreshCaptcha($captchaId);
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage(), 400));
        }
        return json(r_success($res, '验证码已刷新'));
    }

    /** 人工输入验证码后采集资质明细 */
    public function collectDetail()
    {
        $company = $this->collectCompany();
        $captchaId   = trim((string)$this->request->post('captcha_id', ''));
        $captchaCode = trim((string)$this->request->post('captcha_code', ''));
        if ($captchaId === '') {
            return json(r_error('验证码会话已失效，请重新获取验证码', 400));
        }
        if ($captchaCode === '') {
            return json(r_error('请输入图形验证码', 400));
        }
        $name = trim((string)($company['company_name'] ?? ''));
        $code = strtoupper(trim((string)($company['credit_code'] ?? '')));
        try {
            $res = CompanyPlatformService::fetchQualifications($captchaId, $captchaCode, $name, $code);
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage(), 400));
        }
        $res['items'] = CompanyQualificationService::markDuplicated($res['items'] ?? [], (int)$company['id']);
        $this->log('企业证书', '平台资质明细采集', "#{$company['id']} {$name}；读取 " . count($res['items']) . ' 条');
        return json(r_success($res, $res['items'] ? '资质明细采集完成' : '未采集到资质明细'));
    }

    /**
     * 采集国家能源局「承装（修、试）电力设施许可证」
     *
     * 与河南平台采集共用同一个回填弹窗；按企业全称查询公开行政许可公示。
     */
    public function collectXkgs()
    {
        $company = $this->collectCompany();
        $name = trim((string)($company['company_name'] ?? ''));
        if ($name === '') {
            return json(r_error('该企业未填写名称，无法查询承装修试许可证', 400));
        }
        try {
            $res = XkgsService::fetch($name, (int)$this->request->post('fresh', 0) === 1);
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage(), 400));
        }
        $items = XkgsService::toItems($res['records'] ?? []);
        $items = CompanyQualificationService::markDuplicated($items, (int)$company['id']);
        $this->log('企业证书', '承装修试许可证采集', "#{$company['id']} {$name}；读取 " . count($items) . ' 条');
        return json(r_success([
            'items'      => $items,
            'total'      => count($items),
            'source'     => XkgsService::SOURCE,
            'source_key' => XkgsService::SOURCE_KEY,
            'source_url' => $res['source_url'] ?? '',
            'cached'     => (bool)($res['cached'] ?? false),
        ], $items ? '承装修试许可证采集完成' : '未查询到该企业的承装修试许可证'));
    }

    /** 收集筛选条件 */
    protected function filters(): array
    {
        return [
            'q_type'        => trim((string)$this->request->param('q_type', '')),
            'company_id'    => (int)$this->request->param('company_id', 0),
            'keyword'       => trim((string)$this->request->param('keyword', '')),
            'expire_status' => trim((string)$this->request->param('expire_status', '')),
        ];
    }

    /**
     * 当前账号可见的客户企业主键集合：
     *  - admin：null（不限制，不能直接传进 whereIn）
     *  - 客户账号：本企业
     *  - 内部账号：按部门数据范围换算
     */
    protected function visibleCompanyIdsForQuery(): ?array
    {
        if ($this->isAdmin()) {
            return null;
        }
        $companyQ = $this->scopeCompany(Db::name('client_company')->field('id'));
        return array_map('intval', $companyQ->column('id')) ?: [];
    }

    /** 带数据范围与筛选的基础查询 */
    protected function buildQuery(array $filters)
    {
        $today    = date('Y-m-d');
        $soonDate = date('Y-m-d', strtotime('+30 days'));

        // 数据范围：企业主键维度（客户账号限本企业，内部账号按部门范围换算）
        $visibleIds = $this->visibleCompanyIdsForQuery();

        $q = Db::name('company_qualification')->where('status', 1);
        if ($visibleIds !== null) {
            $q->whereIn('company_id', $visibleIds ?: [0]);
        }

        if ($filters['q_type'] === 'safety' || $filters['q_type'] === 'qualification') {
            $q->where('q_type', $filters['q_type']);
        }
        if ($filters['company_id'] > 0) {
            $q->where('company_id', $filters['company_id']);
        }
        if ($filters['keyword'] !== '') {
            $kw = $filters['keyword'];
            $q->where(function ($w) use ($kw) {
                $w->whereLike('cert_name', "%{$kw}%")
                  ->whereOr('cert_no', 'like', "%{$kw}%");
            });
        }
        if ($filters['expire_status'] !== '') {
            switch ($filters['expire_status']) {
                case 'soon':
                    $q->whereNotNull('expire_date')->whereBetween('expire_date', [$today, $soonDate]);
                    break;
                case 'expired':
                    $q->whereNotNull('expire_date')->where('expire_date', '<', $today);
                    break;
                case 'normal':
                    $q->where(function ($w) use ($today, $soonDate) {
                        $w->whereNull('expire_date')->whereOr('expire_date', '>', $soonDate);
                    });
                    break;
                case 'long':
                    $q->where(function ($w) {
                        $w->whereNull('expire_date')->whereOr('expire_date', '');
                    });
                    break;
            }
        }
        return $q;
    }
}
