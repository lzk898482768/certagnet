<?php
namespace app\controller\admin;

use app\model\ClientCompany;
use app\model\CertSecondment;
use app\model\Contract;
use app\service\ExcelService;
use app\service\SecondmentService;
use app\service\CustomFieldService;
use app\service\CompanyPlatformService;
use app\service\CompanyQualificationService;
use app\service\CrmService;
use app\service\XkgsService;
use think\facade\Db;

class Company extends AdminController
{
    public function index()
    {
        [$page, $limit] = $this->pageParams();
        $name   = $this->request->param('company_name', '');
        $status = $this->request->param('status', '');
        $industry = $this->request->param('industry', '');
        // v2.54：负责人筛选（owner_user_id 指定某人；mine=1 只看自己负责的）
        $ownerUserId = (int)$this->request->param('owner_user_id', 0);
        $mine        = (int)$this->request->param('mine', 0);

        CrmService::ensureSchema();
        $q = ClientCompany::order('id', 'desc');
        // v2.67：企业表按主键 id 做数据范围过滤（有企业关联按授权企业，内部账号按负责人部门范围）
        $q = $this->scopeCompany($q);
        if ($name) {
            $q->where('company_name', 'like', "%{$name}%");
        }
        if ($industry) {
            $q->where('industry', $industry);
        }
        if ($status !== '') {
            $q->where('status', $status);
        }
        if ($mine) {
            $q = \app\service\ResourceOwnerService::scopeByOwner($q, 'company', (int)$this->request->userId, 'id');
        } elseif ($ownerUserId > 0) {
            $q = \app\service\ResourceOwnerService::scopeByOwner($q, 'company', $ownerUserId, 'id');
        }
        // v2.99.37：公海筛选（is_pool=1 公海 / 0 已分配；不传=全部）
        $isPool = $this->request->param('is_pool', '');
        if ($isPool !== '' && $isPool !== null) {
            $q->where('is_pool', (int)$isPool);
        }
        $total = $q->count();
        $list  = $q->page($page, $limit)->select()->toArray();
        \app\service\ResourceOwnerService::attachOwners($list, 'company');

        // 附加借调中的证书数量与费用
        if ($list) {
            $ids = array_column($list, 'id');
            $rows = Db::name('cert_secondment')
                ->field('company_id, count(*) as sec_count, sum(fee_amount) as sec_fee')
                ->whereIn('company_id', $ids)->where('status', 1)
                ->group('company_id')->select()->toArray();
            $map = [];
            foreach ($rows as $r) {
                $map[(int)$r['company_id']] = $r;
            }
            foreach ($list as &$item) {
                $s = $map[(int)$item['id']] ?? null;
                $item['sec_count'] = (int)($s['sec_count'] ?? 0);
                $item['sec_fee']   = (float)($s['sec_fee'] ?? 0);
            }
            unset($item);
            // v2.54：负责人 / 创建人姓名 + 最近跟进时间
            CrmService::attachOwner($list, 'company');
            $lastMap = CrmService::lastFollowMap('company', $ids);
            foreach ($list as &$item) {
                $item['last_follow_time'] = $lastMap[(int)$item['id']] ?? '';
            }
            unset($item);
        }
        CustomFieldService::attachList('company', $list);
        // v2.35：客户企业多附件（列表补 attachments + attach_count）
        \app\service\AttachService::attachList('company', $list);
        // v2.99.51：企业联系电话按字段级矩阵处理（详情页保留明文供编辑回填）
        $user = $this->request->user ?? [];
        foreach ($list as &$item) {
            $item['contact_phone'] = \app\service\MaskService::fieldValue('company.contact_phone', $item['contact_phone'] ?? '', $user);
        }
        unset($item);
        return json(r_success(['list' => $list, 'total' => $total]));
    }

    /** 企业详情：基本信息 + 借调中的证书 + 借调历史 + 合同 */
    public function detail()
    {
        $id = (int)$this->request->param('id', 0);
        $company = ClientCompany::find($id);
        if (!$company) {
            return json(r_error('企业不存在', 400));
        }
        $this->assertCompanyAccess($id);
        CompanyQualificationService::ensureSchema();
        CrmService::ensureSchema();
        $data = $company->toArray();
        CrmService::attachOwner($data, 'company');
        CustomFieldService::attachOne('company', $data);
        // v2.35：客户企业多附件
        $data['attachments'] = \app\service\AttachService::listBy('company', $id);
        // v2.43：企业资质 / 安许台账
        $data['qualifications'] = CompanyQualificationService::listByCompany($id);

        // 借调中的证书
        $sec = CertSecondment::with(['certificate', 'staff', 'contract'])
            ->where('company_id', $id)->where('status', 1)
            ->order('end_date', 'asc')->select()->toArray();
        $user = $this->request->user ?? [];
        foreach ($sec as &$s) {
            $s['remain_days'] = SecondmentService::remainDays($s['end_date'] ?? '');
            $s['status_text'] = SecondmentService::statusText((int)$s['status']);
            if (!empty($s['staff']) && is_array($s['staff'])) {
                $s['staff'] = \app\service\MaskService::staff($s['staff'], false, $user);
            }
            if (!empty($s['certificate']) && is_array($s['certificate'])) {
                $s['certificate'] = \app\service\MaskService::cert($s['certificate'], $user);
            }
        }
        unset($s);
        $data['secondments'] = $sec;

        // 借调历史（已归还 / 已到期）
        $history = CertSecondment::with(['certificate', 'staff'])
            ->where('company_id', $id)->where('status', '<>', 1)
            ->order('id', 'desc')->limit(50)->select()->toArray();
        foreach ($history as &$h) {
            if (!empty($h['staff']) && is_array($h['staff'])) {
                $h['staff'] = \app\service\MaskService::staff($h['staff'], false, $user);
            }
            if (!empty($h['certificate']) && is_array($h['certificate'])) {
                $h['certificate'] = \app\service\MaskService::cert($h['certificate'], $user);
            }
        }
        unset($h);
        $data['secondment_history'] = $history;

        // 通过借调间接持有的证书列表（供详情表格直接展示）
        $certRows = [];
        foreach ($sec as $s) {
            if (!empty($s['certificate'])) {
                $c = $s['certificate'];
                $c['company_name']     = $data['company_name'];
                $c['secondment_start'] = $s['start_date'] ?? '';
                $c['secondment_end']   = $s['end_date'] ?? '';
                $c['secondment_id']    = $s['id'];
                $c['secondment_fee']   = $s['fee_amount'] ?? 0;
                $c['staff_name']       = $s['staff']['staff_name'] ?? '';
                $certRows[] = $c;
            }
        }
        $data['certificates'] = $certRows;

        // 合同
        $data['contracts'] = Contract::where('company_id', $id)
            ->order('id', 'desc')->limit(50)->select()->toArray();

        return json(r_success($data));
    }

    /**
     * v2.99.38：新增前查重预检（不写库）。命中只返回最小信息，前端据此提示「将关联为共同所有人」。
     */
    public function dedupCheck()
    {
        $data = $this->request->post(['company_name', 'credit_code', 'legal_person']);
        if (isset($data['credit_code'])) {
            $data['credit_code'] = strtoupper(trim((string)$data['credit_code']));
        }
        $dup = \app\service\DuplicateResolver::company($data);
        return json(r_success([
            'hit'  => $dup ? 1 : 0,
            'item' => $dup ? \app\service\DuplicateResolver::brief('company', $dup) : null,
        ]));
    }

    public function save()
    {
        $id = (int)$this->request->post('id', 0);
        CompanyQualificationService::ensureSchema();
        CrmService::ensureSchema();
        $data = $this->request->post(['company_name', 'short_name', 'credit_code', 'legal_person', 'region_path', 'registered_capital', 'contact', 'contact_phone', 'address', 'industry', 'company_scale', 'remark', 'status']);
        if (empty($data['company_name'])) {
            return json(r_error('企业名称必填', 400));
        }
        $data['company_name'] = trim((string)$data['company_name']);
        // 统一社会信用代码统一转大写并去空格，避免大小写不同被当成两家企业
        if (isset($data['credit_code'])) {
            $data['credit_code'] = strtoupper(trim((string)$data['credit_code']));
        }

        // v2.99.1：信用代码查重（此前同码可重复建档，导致台账/统计/合同归属错乱）
        // v2.99.38：新建时的查重改为「复用存量 + 自动共有」，见下方 DuplicateResolver 分支
        if ($id) {
            $codeErr = \app\common\UniqueGuard::check([
                'credit_code' => [ClientCompany::class, $data['credit_code'] ?? '', '统一社会信用代码'],
            ], $id);
            if ($codeErr !== '') {
                return json(r_error($codeErr . '，请先核对是否已有该企业', 400));
            }
        }
        // v2.35：客户企业多附件
        $attachments = $this->request->post('attachments/a', []);
        if (!is_array($attachments)) {
            $attachments = [];
        }
        $attCheck = \app\service\AttachService::validateAll($attachments);
        if (!$attCheck['ok']) {
            return json(r_error($attCheck['msg'], 422));
        }
        $customErr = CustomFieldService::validate('company', $this->request->post('custom'));
        if ($customErr !== '') {
            return json(r_error($customErr, 400));
        }
        // v2.99.38：新建查重命中存量 → 复用存量 + 原所有人/当前操作人共有（不新建重复主记录）
        if (!$id) {
            $dup = \app\service\DuplicateResolver::company($data);
            if ($dup) {
                $share = \app\service\DuplicateResolver::share('company', (int)$dup['id'], (int)$this->request->userId);
                $this->log('客户企业', '查重共有', "#{$dup['id']} 命中存量，共有人数 {$share['owners']}");
                return json(r_success([
                    'id'         => (int)$dup['id'],
                    'duplicated' => true,
                ], '检测到已存在相同企业，已自动关联为共同所有人'));
            }
        }
        if ($id) {
            $this->assertCompanyAccess($id);
            $oldName = (string)(ClientCompany::where('id', $id)->value('company_name') ?: '');
            try {
                ClientCompany::where('id', $id)->update($data);
            } catch (\Throwable $e) {
                // 唯一索引兜底（并发写入时应用层查重可能没拦住）
                $dupMsg = \app\common\UniqueGuard::friendly($e, '统一社会信用代码已存在');
                if ($dupMsg !== '') {
                    return json(r_error($dupMsg . '，请先核对是否已有该企业', 400));
                }
                throw $e;
            }
            // v2.54：负责人变更统一走「移交」功能，编辑表单不再允许指定负责人
            // v2.38：企业写路径主动失效（改名需同时抹掉旧名与新名）
            \app\service\Lookup::forgetCompany($oldName ?: null);
            \app\service\Lookup::forgetCompany(trim((string)$data['company_name']) ?: null);
            $this->log('客户企业', '更新', "#{$id}");
        } else {
            // v2.54：新建默认当前用户为创建人 + 负责人（不允许在新增时指定他人，负责人变更走移交）
            $data['create_by'] = (int)$this->request->userId;
            $data['owner_user_id'] = (int)$this->request->userId;
            try {
                $model = ClientCompany::create($data);
            } catch (\Throwable $e) {
                $dupMsg = \app\common\UniqueGuard::friendly($e, '统一社会信用代码已存在');
                if ($dupMsg !== '') {
                    // 并发兜底：唯一键冲突说明他人刚建档，回查后转为共有
                    $dup = \app\service\DuplicateResolver::company($data);
                    if ($dup) {
                        $share = \app\service\DuplicateResolver::share('company', (int)$dup['id'], (int)$this->request->userId);
                        $this->log('客户企业', '查重共有', "#{$dup['id']} 唯一键冲突回查命中，共有人数 {$share['owners']}");
                        return json(r_success([
                            'id'         => (int)$dup['id'],
                            'duplicated' => true,
                        ], '检测到已存在相同企业，已自动关联为共同所有人'));
                    }
                    return json(r_error($dupMsg . '，请先核对是否已有该企业', 400));
                }
                throw $e;
            }
            $id    = (int)$model->id;
            \app\service\Lookup::forgetCompany(trim((string)$data['company_name']) ?: null);
            \app\service\ResourceOwnerService::addOwner('company', $id, (int)$this->request->userId, 'assign', (int)$this->request->userId, true);
            $this->log('客户企业', '新增', "#{$id} 负责人 #{$data['owner_user_id']}");
        }
        CustomFieldService::saveValues('company', (int)$id, $this->request->post('custom'));
        // v2.35：附件子表全量覆盖（DELETE→INSERT→同步 attach_path=第一条）
        try {
            \app\service\AttachService::replace('company', (int)$id, $attachments, (int)$this->request->userId);
        } catch (\Throwable $e) {
            // 附件子表若缺列等异常不阻断主保存
        }
        return json(r_success(['id' => $id], '保存成功'));
    }

    /**
     * v2.46：企业详情抽屉里直接保存附件（只覆盖附件子表，不动企业其他字段）。
     * 附件文件本身已在统一上传入口做过白名单/真实 MIME/大小校验，这里沿用 save() 同等口径。
     */
    public function attachSave()
    {
        $id = (int)$this->request->post('id', 0);
        if ($id <= 0) {
            return json(r_error('参数错误', 400));
        }
        if (!ClientCompany::find($id)) {
            return json(r_error('企业不存在', 400));
        }
        $this->assertCompanyAccess($id);
        $items = $this->request->post('attachments', []);
        if (!is_array($items)) {
            return json(r_error('附件数据格式错误', 400));
        }
        try {
            $n = \app\service\AttachService::replace('company', $id, $items, (int)$this->request->userId);
        } catch (\Throwable $e) {
            return json(r_error('附件保存失败：' . $e->getMessage(), 400));
        }
        $this->log('企业客户', '附件', "详情直接保存企业 #{$id} 附件，共 {$n} 个");
        return json(r_success([
            'count'       => $n,
            'attachments' => \app\service\AttachService::listBy('company', $id),
        ], '附件已保存'));
    }

    public function delete()
    {
        $id = (int)$this->request->post('id', 0);
        if (!$id) {
            return json(r_error('参数错误', 400));
        }
        $r = $this->deleteOne($id);
        return $r['ok'] ? json(r_success([], '删除成功')) : json(r_error($r['msg'], 400));
    }

    protected function deleteOne(int $id): array
    {
        if (!$this->canAccessCompany($id)) {
            return ['ok' => false, 'msg' => '无权删除该企业'];
        }
        $using = CertSecondment::where('company_id', $id)->where('status', 1)->count();
        if ($using) {
            return ['ok' => false, 'msg' => '该企业仍有 ' . $using . ' 本证书借调中，请先归还后再删除'];
        }
        CustomFieldService::deleteValues('company', $id);
        // v2.52：删除前快照进回收站（附件 / 资质台账 / 自定义值随删，一并快照）
        try {
            $rb = ClientCompany::where('id', $id)->find();
            if ($rb) {
                \app\service\RecycleBinService::stash(
                    (int)$this->request->userId,
                    'company',
                    (string)($rb['company_name'] ?? '') ?: ("#" . $id),
                    $rb->toArray(),
                    array_filter([
                        ['table' => 'company_attach', 'rows' => \app\service\RecycleBinService::grab('company_attach', 'company_id', $id)],
                        ['table' => 'company_qualification', 'rows' => \app\service\RecycleBinService::grab('company_qualification', 'company_id', $id)],
                        \app\service\RecycleBinService::grabCustom('company', $id),
                    ])
                );
            }
        } catch (\Throwable $e) {
            // 快照失败不阻断删除
        }
        $oldName = (string)(ClientCompany::where('id', $id)->value('company_name') ?: '');
        ClientCompany::destroy($id);
        \app\service\Lookup::forgetCompany($oldName ?: null);
        // v2.54：级联清理跟进记录与移交历史
        CrmService::deleteByRecord('company', $id);
        // v2.35：附件子表随企业删除
        try {
            Db::name('company_attach')->where('company_id', $id)->delete();
        } catch (\Throwable $e) {
            // 子表缺失忽略
        }
        // v2.43：资质台账随企业删除
        try {
            Db::name('company_qualification')->where('company_id', $id)->delete();
        } catch (\Throwable $e) {
            // 子表缺失忽略
        }
        $this->log('客户企业', '删除', "#{$id}");
        return ['ok' => true, 'msg' => '删除成功'];
    }

    public function changeStatus()
    {
        $id     = (int)$this->request->post('id', 0);
        $status = (int)$this->request->post('status', 1);
        if ($id <= 0) {
            return json(r_error('参数错误', 400));
        }
        $this->assertCompanyAccess($id);
        ClientCompany::where('id', $id)->update(['status' => $status]);
        return json(r_success([], '操作成功'));
    }

    // 可选项（下拉用）
    public function options()
    {
        $q = ClientCompany::where('status', 1)->field('id,company_name,short_name');
        // v2.67：与列表同口径（授权企业 / 内部账号按负责人部门范围）
        $this->scopeCompany($q);
        $list = $q->order('id', 'asc')->select()->toArray();
        return json(r_success($list));
    }

    // ---------- Excel 导入导出 ----------
    public function export()
    {
        $q = ClientCompany::order('id', 'desc');
        // v2.67：同 index()，企业表按主键 id 做数据范围过滤
        $q = $this->scopeCompany($q);
        $rows = $q->select()->toArray();
        $formatted = array_map(function ($r) {
            return [
                'company_name'  => $r['company_name'] ?? '',
                'short_name'    => $r['short_name'] ?? '',
                'credit_code'   => $r['credit_code'] ?? '',
                'legal_person'  => $r['legal_person'] ?? '',
                'region_path'   => $r['region_path'] ?? '',
                'registered_capital' => $r['registered_capital'] ?? '',
                'industry'      => $r['industry'] ?? '',
                'company_scale' => $r['company_scale'] ?? '',
                'contact'       => $r['contact'] ?? '',
                'contact_phone' => $r['contact_phone'] ?? '',
                'address'       => $r['address'] ?? '',
                'status_text'   => ($r['status'] ?? 1) == 1 ? '启用' : '停用',
                'remark'        => $r['remark'] ?? '',
            ];
        }, $rows);
        $path = ExcelService::exportCompany($formatted);
        return download_utf8($path, 'company_' . date('Ymd') . '.xlsx');
    }

    public function template()
    {
        $path = ExcelService::template('company');
        return download_utf8($path, '企业导入模板.xlsx');
    }

    // ------------------------------------------------------------
    // 河南建筑市场监管平台采集（v2.43）
    // 仅采集平台公开页面；资质明细由业务人员人工识别验证码后提交，
    // 不破解、不绕过平台验证码，并保留 2 秒最小请求间隔与 10 分钟缓存。
    // ------------------------------------------------------------

    /** 企业资质 / 安许台账（GET，客户企业账号仅可读自己企业） */
    public function qualifications()
    {
        $companyId = (int)$this->request->param('company_id', 0);
        if ($companyId <= 0) {
            return json(r_error('参数错误', 400));
        }
        $this->assertCompanyAccess($companyId);
        return json(r_success(CompanyQualificationService::listByCompany($companyId)));
    }

    /**
     * 采集企业基础信息 + 安许（公开详情页，无需验证码）
     *
     * 入参：company_id（已有企业，可选）、name / credit_code（未建档时按名称或信用代码查询）
     * 出参：company（基础信息）、items（可导入的安许）、warnings、qualification_url
     */
    public function fetchQualification()
    {
        $companyId = (int)$this->request->post('company_id', 0);
        $name = trim((string)$this->request->post('name', ''));
        $creditCode = strtoupper(trim((string)$this->request->post('credit_code', '')));

        if ($companyId > 0) {
            $this->assertCompanyAccess($companyId);
            $company = Db::name('client_company')->where('id', $companyId)->find();
            if (!$company) {
                return json(r_error('企业不存在', 400));
            }
            if ($name === '') {
                $name = (string)$company['company_name'];
            }
            if ($creditCode === '') {
                $creditCode = strtoupper((string)$company['credit_code']);
            }
        }
        if ($name === '' && $creditCode === '') {
            return json(r_error('请填写企业名称或统一社会信用代码', 400));
        }
        $this->assertCollectTarget($companyId, $name, $creditCode);

        try {
            $res = CompanyPlatformService::fetch($name, $creditCode, (int)$this->request->post('fresh', 0) === 1);
        } catch (\Throwable $e) {
            $this->log('客户企业', '河南平台采集失败', mb_substr($e->getMessage(), 0, 120));
            return json(r_error($e->getMessage(), 400));
        }

        // 已入库的同类记录标记出来，前端可勾选跳过
        $res['items'] = CompanyQualificationService::markDuplicated($res['items'] ?? [], $companyId);
        $this->log('客户企业', '河南平台采集', ($name ?: $creditCode) . '；可导入 ' . count($res['items']) . ' 条');
        return json(r_success($res, '采集完成'));
    }

    /**
     * 一键从河南平台补全企业基本信息（v2.58）
     *
     * 入参：company_id*、fresh（0/1，默认 1 拉取最新）、overwrite（0/1，默认 0 只补空）
     * 默认只补档案中的空字段；已有内容与平台不一致时返回 conflicts，由前端确认后再覆盖。
     */
    public function completeBasic()
    {
        $companyId = (int)$this->request->post('company_id', 0);
        if ($companyId <= 0) {
            return json(r_error('请先保存企业后再补全', 400));
        }
        $this->assertCompanyAccess($companyId);
        CompanyQualificationService::ensureSchema();
        $company = Db::name('client_company')->where('id', $companyId)->find();
        if (!$company) {
            return json(r_error('企业不存在', 400));
        }
        $name = trim((string)($company['company_name'] ?? ''));
        $creditCode = strtoupper(trim((string)($company['credit_code'] ?? '')));
        if ($name === '' && $creditCode === '') {
            return json(r_error('请先填写企业名称或统一社会信用代码', 400));
        }

        $fresh = (int)$this->request->post('fresh', 1) === 1;
        try {
            $res = CompanyPlatformService::fetch($name, $creditCode, $fresh);
        } catch (\Throwable $e) {
            $this->log('客户企业', '河南平台补全失败', "#{$companyId} " . mb_substr($e->getMessage(), 0, 120));
            return json(r_error($e->getMessage(), 400));
        }

        $base = is_array($res['company'] ?? null) ? $res['company'] : [];
        $platformName = trim((string)($base['name'] ?? ''));
        $platformCode = strtoupper(trim((string)($base['credit_code'] ?? '')));
        if ($platformName === '' && $platformCode === '') {
            return json(r_error('平台未查询到该企业的公开信息，请核对名称或信用代码', 400));
        }
        // 防止错配写入：信用代码都有值时必须一致；没有可比信用代码时名称必须一致
        if ($creditCode !== '' && $platformCode !== '') {
            if ($creditCode !== $platformCode) {
                $this->log('客户企业', '河南平台补全拒绝', "#{$companyId} 信用代码不一致：{$creditCode} / {$platformCode}");
                return json(r_error('平台返回的信用代码与当前企业不一致，已停止补全', 400));
            }
        } elseif ($platformName !== ''
            && self::normCompanyName($platformName) !== self::normCompanyName($name)) {
            return json(r_error('平台返回的企业名称与当前企业不一致，已停止补全', 400));
        }

        $overwrite = (int)$this->request->post('overwrite', 0) === 1;
        try {
            $plan = CompanyQualificationService::completeBasic($companyId, $base, $overwrite);
        } catch (\Throwable $e) {
            $this->log('客户企业', '河南平台补全失败', "#{$companyId} " . mb_substr($e->getMessage(), 0, 120));
            return json(r_error($e->getMessage(), 400));
        }

        $nFilled = count($plan['filled']);
        $nOverwritten = count($plan['overwritten']);
        $nConflicts = count($plan['conflicts']);
        if ($nFilled || $nOverwritten) {
            // 基础信息可能被名称检索缓存，写后主动失效
            \app\service\Lookup::forgetCompany($name ?: null);
            $this->log(
                '客户企业',
                '河南平台补全基本信息',
                "#{$companyId} 补全 {$nFilled} 项，覆盖 {$nOverwritten} 项，冲突 {$nConflicts} 项"
            );
        }

        if ($nOverwritten) {
            $msg = "已补全 {$nFilled} 项，并用平台值覆盖 {$nOverwritten} 项";
        } elseif ($nFilled) {
            $msg = "已从平台补全 {$nFilled} 项";
        } elseif ($nConflicts) {
            $msg = '平台信息与档案不一致，已保留现有内容';
        } else {
            $msg = '企业基本信息已是最新，无需补全';
        }

        return json(r_success([
            'filled'        => $plan['filled'],
            'overwritten'   => $plan['overwritten'],
            'conflicts'     => $plan['conflicts'],
            'unchanged'     => $plan['unchanged'],
            'updated'       => $plan['updated'],
            'platform_name' => $platformName,
            'name_mismatch' => $platformName !== '' && self::normCompanyName($platformName) !== self::normCompanyName($name),
            'items'         => $res['items'] ?? [],
            'warnings'      => $res['warnings'] ?? [],
            'source'        => $res['source'] ?? CompanyPlatformService::SOURCE,
            'source_url'    => $res['source_url'] ?? '',
            'fetched_at'    => $res['fetched_at'] ?? '',
            'cached'        => (bool)($res['cached'] ?? false),
        ], $msg));
    }

    /**
     * 获取平台图形验证码（人工识别，不绕过验证码）
     *
     * 入参：company_id、name、credit_code
     * 出参：captcha_id、image（data:image/jpeg;base64）、expires_in
     */
    public function qualificationCaptcha()
    {
        $companyId = (int)$this->request->post('company_id', 0);
        [$name, $creditCode] = $this->resolveCompanyNameCode();
        if ($name === '' && $creditCode === '') {
            return json(r_error('请填写企业名称或统一社会信用代码', 400));
        }
        $this->assertCollectTarget($companyId, $name, $creditCode);
        try {
            $res = CompanyPlatformService::captcha($creditCode, $name);
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage(), 400));
        }
        return json(r_success($res, '请识别图片中的验证码'));
    }

    /**
     * 同一会话刷新验证码（识别错误 / 看不清）
     *
     * 入参：captcha_id
     */
    public function refreshQualificationCaptcha()
    {
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

    /**
     * 人工输入验证码后采集企业资质明细
     *
     * 入参：company_id、name、credit_code、captcha_id、captcha_code
     * 出参：items（可导入的资质记录）、total、message
     */
    public function fetchQualificationDetail()
    {
        $companyId = (int)$this->request->post('company_id', 0);
        [$name, $creditCode] = $this->resolveCompanyNameCode();
        $captchaId = trim((string)$this->request->post('captcha_id', ''));
        $captchaCode = trim((string)$this->request->post('captcha_code', ''));
        if ($captchaId === '') {
            return json(r_error('验证码会话已失效，请重新获取验证码', 400));
        }
        if ($captchaCode === '') {
            return json(r_error('请输入图形验证码', 400));
        }
        $this->assertCollectTarget($companyId, $name, $creditCode);

        try {
            $res = CompanyPlatformService::fetchQualifications($captchaId, $captchaCode, $name, $creditCode);
        } catch (\Throwable $e) {
            $this->log('客户企业', '河南平台资质明细采集失败', mb_substr($e->getMessage(), 0, 120));
            return json(r_error($e->getMessage(), 400));
        }

        $res['items'] = CompanyQualificationService::markDuplicated($res['items'] ?? [], $companyId);
        $res['source'] = CompanyPlatformService::SOURCE;
        $res['source_key'] = CompanyPlatformService::SOURCE_KEY;
        $res['source_url'] = CompanyPlatformService::BASE_URL . '/companyTab/compQualificationInfo?corpcode='
            . rawurlencode($creditCode) . '&corpname=' . rawurlencode($name);

        $this->log('客户企业', '河南平台资质明细采集', ($name ?: $creditCode) . '；读取 ' . count($res['items']) . ' 条' . (!empty($res['cached']) ? '（缓存）' : ''));
        return json(r_success($res, $res['items'] ? '资质明细采集完成' : '未采集到资质明细'));
    }

    /**
     * 采集国家能源局「承装（修、试）电力设施许可证」
     *
     * 公开公示查询，无需登录 / 验证码；按当前客户企业档案名称查询，
     * 不接受前端传入企业名，避免把其他企业的证书导入当前企业。
     */
    public function collectXkgs()
    {
        $companyId = (int)$this->request->post('company_id', 0);
        if ($companyId <= 0) {
            return json(r_error('请先选择企业', 400));
        }
        $this->assertCompanyAccess($companyId);
        $company = Db::name('client_company')->where('id', $companyId)->find();
        if (!$company) {
            return json(r_error('企业不存在', 400));
        }
        $name = trim((string)($company['company_name'] ?? ''));
        if ($name === '') {
            return json(r_error('该企业未填写名称，无法查询承装修试许可证', 400));
        }

        try {
            $res = XkgsService::fetch($name, (int)$this->request->post('fresh', 0) === 1);
        } catch (\Throwable $e) {
            $this->log('客户企业', '承装修试许可证采集失败', mb_substr($e->getMessage(), 0, 120));
            return json(r_error($e->getMessage(), 400));
        }

        $items = XkgsService::toItems($res['records'] ?? []);
        $items = CompanyQualificationService::markDuplicated($items, $companyId);
        $this->log('客户企业', '承装修试许可证采集', ($name ?: ('#' . $companyId)) . '；读取 ' . count($items) . ' 条');
        return json(r_success([
            'items'      => $items,
            'total'      => count($items),
            'source'     => XkgsService::SOURCE,
            'source_key' => XkgsService::SOURCE_KEY,
            'source_url' => $res['source_url'] ?? '',
            'cached'     => (bool)($res['cached'] ?? false),
        ], $items ? '承装修试许可证采集完成' : '未查询到该企业的承装修试许可证'));
    }

    /**
     * 采集结果入库（按企业 + 类型 + 证书编号 + 证书名称判重）
     *
     * 入参：company_id*、items[]、source/source_url、apply_base、base
     */
    public function importQualification()
    {
        $companyId = (int)$this->request->post('company_id', 0);
        if ($companyId <= 0) {
            return json(r_error('请先保存企业后再导入', 400));
        }
        $this->assertCompanyAccess($companyId);

        $items = $this->request->post('items', []);
        if (is_string($items)) {
            $items = json_decode($items, true) ?: [];
        }
        if (!is_array($items) || !$items) {
            return json(r_error('请选择要导入的资质 / 安许记录', 400));
        }

        $base = $this->request->post('base', []);
        if (is_string($base)) {
            $base = json_decode($base, true) ?: [];
        }
        // 双保险：即使绕过前端，也不允许把其他企业的采集结果写入当前企业
        $this->assertCollectTarget(
            $companyId,
            (string)($base['name'] ?? ''),
            strtoupper(trim((string)($base['credit_code'] ?? '')))
        );

        try {
            $res = CompanyQualificationService::import(
                $companyId,
                $items,
                (string)$this->request->post('source', ''),
                (string)$this->request->post('source_url', ''),
                (int)$this->request->post('apply_base', 0) === 1,
                is_array($base) ? $base : [],
                (int)$this->request->userId
            );
        } catch (\Throwable $e) {
            $this->log('客户企业', '导入资质台账失败', mb_substr($e->getMessage(), 0, 120));
            return json(r_error($e->getMessage(), 400));
        }

        $this->log('客户企业', '导入资质台账', "新增 {$res['saved']} 条，更新 {$res['updated']} 条，跳过 {$res['skipped']} 条");
        return json(r_success($res, "导入完成：新增 {$res['saved']} 条，更新 {$res['updated']} 条"));
    }

    /** 删除一条企业资质台账（软删除） */
    public function deleteQualification()
    {
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
        // v2.52：删除前快照进回收站
        try {
            \app\service\RecycleBinService::stash(
                (int)$this->request->userId,
                'company_qualification',
                ((string)($row['cert_name'] ?? '') ?: ("#" . $id)),
                $row
            );
        } catch (\Throwable $e) {
            // 快照失败不阻断删除
        }
        CompanyQualificationService::delete($id);
        $this->log('客户企业', '删除资质台账', "#{$id} " . ($row['cert_name'] ?? ''));
        return json(r_success([], '已删除'));
    }

    /**
     * 采集对象与当前客户企业一致性校验
     *
     * 防止业务人员在采集弹窗里改成另一家企业后，把对方的资质 / 安许导入当前企业。
     * 未建档（company_id=0）时不做限制；已有企业时名称或信用代码只要有一项明确不一致就拒绝。
     */
    private function assertCollectTarget(int $companyId, string $name, string $creditCode): void
    {
        if ($companyId <= 0) {
            return;
        }
        $company = Db::name('client_company')->where('id', $companyId)->find();
        if (!$company) {
            throw new \think\exception\HttpResponseException(json(r_error('企业不存在', 400)));
        }

        $targetCode = strtoupper(trim((string)($company['credit_code'] ?? '')));
        $sourceCode = strtoupper(trim($creditCode));
        if ($sourceCode !== '' && $targetCode !== '' && $sourceCode !== $targetCode) {
            throw new \think\exception\HttpResponseException(
                json(r_error('采集结果的企业信用代码与当前客户企业不一致，请核对后再采集', 400))
            );
        }

        $targetName = self::normCompanyName((string)($company['company_name'] ?? ''));
        $sourceName = self::normCompanyName($name);
        if ($sourceName !== '' && $targetName !== '' && $sourceName !== $targetName) {
            throw new \think\exception\HttpResponseException(
                json(r_error('采集结果的企业名称与当前客户企业不一致，请核对后再采集', 400))
            );
        }
    }

    private static function normCompanyName(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', '', trim($name)) ?? '');
    }

    /** 采集入参：企业名称 / 信用代码（缺省时从企业档案补齐） */
    private function resolveCompanyNameCode(): array
    {
        $companyId = (int)$this->request->post('company_id', $this->request->param('company_id', 0));
        $name = trim((string)$this->request->post('name', $this->request->param('name', '')));
        $creditCode = strtoupper(trim((string)$this->request->post('credit_code', $this->request->param('credit_code', ''))));
        if ($companyId > 0) {
            $this->assertCompanyAccess($companyId);
            if ($name === '' || $creditCode === '') {
                $company = Db::name('client_company')->where('id', $companyId)->find();
                if ($company) {
                    if ($name === '') {
                        $name = (string)$company['company_name'];
                    }
                    if ($creditCode === '') {
                        $creditCode = strtoupper((string)$company['credit_code']);
                    }
                }
            }
        }
        return [$name, $creditCode];
    }
}
