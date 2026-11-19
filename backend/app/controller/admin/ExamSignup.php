<?php
namespace app\controller\admin;

use app\service\DeptScopeService;
use app\service\ExamMaterialService;
use app\service\ExamSchemaService;
use app\service\ExamSignupService;
use app\service\ExamStatusMachineService;
use think\facade\Db;

/**
 * 报名工单（v2.85，移植自 exam-reg 报名订单核心，去除财务）
 *
 *  - GET  /admin/exam-signup                  工单列表（数据权限）
 *  - GET  /admin/exam-signup/detail           详情（工单/考生/材料/审核/日志/状态流）
 *  - GET  /admin/exam-signup/meta             建单元数据（业务组合/批次/考生/单位/材料/规则/流）
 *  - GET  /admin/exam-signup/customers        考生档案列表/检索
 *  - POST /admin/exam-signup/customer-save    新增/编辑考生（可关联 staff）
 *  - POST /admin/exam-signup/save             建单（正式/草稿，force/ignore_rules）
 *  - POST /admin/exam-signup/submit           草稿转正式
 *  - POST /admin/exam-signup/advance          状态流转（±1）
 *  - POST /admin/exam-signup/audit            审核通过/驳回
 *  - POST /admin/exam-signup/material-save    上传/登记材料
 *  - POST /admin/exam-signup/material-delete  删除材料
 *  - POST /admin/exam-signup/delete           删除工单（软删）
 *  - GET  /admin/exam-signup/reminders        报名提醒（截止/考试临近）
 *  - POST /admin/exam-signup/ocr-idcard       身份证 OCR 识别（腾讯云，v2.86）
 */
class ExamSignup extends AdminController
{
    protected function boot(): void
    {
        ExamSchemaService::ensureTables();
        \app\service\IdCardSchemaService::ensure();
    }

    /**
     * 工单数据权限（口径与本系统 DeptScopeService 一致）：
     *  - admin：不限；
     *  - company 客户侧账号：按 user_company_rel 关联单位过滤 company_id，
     *    未关联任何单位则恒空集（[0]），绝不回退部门范围；
     *  - 内部办公账号：data_scope=all 不限；dept/dept_tree 换算到
     *    「负责人 owner_user_id 所属部门在范围内」（sys_user.dept_id）；
     *    self 仅本人负责的工单。
     */
    protected function scopeSignup($query, string $alias = 's')
    {
        if ($this->isAdmin()) {
            return $query;
        }
        // 客户侧账号：只看被授权单位的工单
        if ($this->isCompanyBound()) {
            $ids = array_values(array_filter(array_map('intval', $this->companyIds())));
            $query->whereIn($alias . '.company_id', $ids ?: [0]);
            return $query;
        }
        // 内部办公账号（v2.99.36）：按创建人 create_by
        return \app\service\DataScopeService::applyCreatorScope($query, $this->request->user ?? [], $alias . '.create_by');
    }

    public function index()
    {
        $this->boot();
        [$page, $limit] = $this->pageParams();

        $q = Db::name('exam_signup')->alias('s')->where('s.status', 1);
        $this->scopeSignup($q);

        $keyword = trim((string)$this->request->param('keyword', ''));
        $joinedCustomer = false;
        if ($keyword !== '') {
            // v2.99.52：关键词检索会先 join 考生表，列表查询不能再 join 一次，否则
            // MySQL 报 "Not unique table/alias: 'cu'"（列表带关键词直接 500）
            $joinedCustomer = true;
            $idHash = $this->idCardHashOf($keyword);
            $q->leftJoin('exam_customer cu', 'cu.id = s.customer_id')
              ->where(function ($w) use ($keyword, $idHash) {
                  $w->where('s.signup_no', 'like', "%{$keyword}%")
                    ->whereOr('cu.name', 'like', "%{$keyword}%")
                    ->whereOr('cu.phone', 'like', "%{$keyword}%");
                  if ($idHash !== '') {
                      $w->whereOr('cu.id_card_hash', $idHash);
                  }
              });
        }
        foreach (['biz_config_id' => 's.biz_config_id', 'batch_id' => 's.batch_id',
                  'cert_type_id' => 's.cert_type_id', 'action_id' => 's.action_id',
                  'stage' => 's.stage', 'company_id' => 's.company_id'] as $pk => $col) {
            $v = (int)$this->request->param($pk, 0);
            if ($v > 0) {
                $q->where($col, $v);
            }
        }
        if ($this->request->has('is_draft')) {
            $q->where('s.is_draft', (int)$this->request->param('is_draft'));
        }
        if ($this->request->has('exception')) {
            $q->where('s.exception', (int)$this->request->param('exception'));
        }

        $total = (clone $q)->count();
        $listQ = (clone $q);
        if (!$joinedCustomer) {
            $listQ->leftJoin('exam_customer cu', 'cu.id = s.customer_id');
        }
        $list = $listQ
            ->leftJoin('client_company cc', 'cc.id = s.company_id')
            ->leftJoin('exam_biz_config bc', 'bc.id = s.biz_config_id')
            ->leftJoin('exam_batch bb', 'bb.id = s.batch_id')
            ->field('s.*,cu.name customer_name,cu.phone customer_phone,cu.id_card customer_id_card,
                     cc.company_name,bc.name config_name,bb.name batch_name')
            ->order('s.id desc')->page($page, $limit)->select()->toArray();

        foreach ($list as &$r) {
            $r = $this->decorate($r);
        }
        unset($r);

        return json(r_success(['list' => $list, 'total' => $total]));
    }

    /** 列表项附加状态标签/材料完成度 */
    protected function decorate(array $r): array
    {
        $flow = ExamSignupService::flowOfSignup($r);
        $stage = (int)$r['stage'];
        $r['stage_label'] = ExamStatusMachineService::label($flow, $stage);
        $r['stage_key']   = ExamStatusMachineService::key($flow, $stage);
        $r['stage_total'] = count($flow);
        $r['can_forward'] = $stage < count($flow);
        $r['can_back']    = $stage > 1;
        $mat = ExamSignupService::materialStatus((int)$r['id']);
        $r['material_ready'] = $mat['pass'];
        $r['material_missing'] = $mat['missing_names'];
        // v2.99.59：列表里的考生身份证号为密文，统一转掩码
        if (isset($r['customer_id_card'])) {
            $r['customer_id_card'] = $this->maskIdCard((string)$r['customer_id_card']);
        }
        return $r;
    }

    public function detail()
    {
        $this->boot();
        $id = (int)$this->request->param('id', 0);
        $signup = Db::name('exam_signup')->where('id', $id)->where('status', 1)->find();
        if (!$signup) {
            return json(r_error('报名单不存在'));
        }
        if (!$this->isAdmin()) {
            // 对象级数据权限（与列表口径一致）
            $chk = Db::name('exam_signup')->alias('s')->where('s.id', $id);
            $this->scopeSignup($chk);
            if (!(clone $chk)->count()) {
                return json(r_error('无权查看该报名单', 403));
            }
        }

        $customer = (int)$signup['customer_id'] > 0
            ? Db::name('exam_customer')->find($signup['customer_id']) : null;
        if ($customer) {
            // v2.99.59：身份证密文 → 解密 → 只返回掩码（明文走 staff:reveal 受控出口）
            $customer = \app\service\IdCardService::decodeRow($customer);
            $customer['id_card_mask'] = array_key_exists('id_card', $customer)
                ? \app\service\IdCardService::mask($customer['id_card']) : '';
            $customer['id_card'] = $customer['id_card_mask'];
            $staff = (int)$customer['staff_id'] > 0
                ? Db::name('staff')->field('id,staff_name,dept,position,phone')->find($customer['staff_id']) : null;
            $customer['staff'] = $staff ?: null;
        }
        $company = (int)$signup['company_id'] > 0
            ? Db::name('client_company')->field('id,company_name,short_name,contact,contact_phone')->find($signup['company_id']) : null;
        $config = Db::name('exam_biz_config')->find($signup['biz_config_id']);
        $batch  = (int)$signup['batch_id'] > 0 ? Db::name('exam_batch')->find($signup['batch_id']) : null;
        $room   = (int)$signup['room_id'] > 0 ? Db::name('exam_room')->find($signup['room_id']) : null;

        $snapshot = json_decode((string)$signup['snapshot'], true) ?: [];
        $flow = ExamSignupService::flowOfSignup($signup);
        $materials = Db::name('exam_signup_material')
            ->where('signup_id', $id)->where('status', 1)->order('sort asc,id asc')->select()->toArray();
        $audits = Db::name('exam_audit_record')->where('signup_id', $id)->order('id desc')->select()->toArray();
        $auditorIds = array_values(array_unique(array_map(fn($a) => (int)$a['auditor'], $audits)));
        $userNames = $auditorIds ? Db::name('sys_user')->whereIn('id', $auditorIds)->column('username', 'id') : [];
        foreach ($audits as &$a) {
            $a['auditor_name'] = $userNames[$a['auditor']] ?? ('#' . $a['auditor']);
        }
        unset($a);
        $logs = Db::name('exam_signup_log')->where('signup_id', $id)->order('id asc')->select()->toArray();
        $opIds = array_values(array_unique(array_map(fn($l) => (int)$l['operator'], $logs)));
        $opNames = $opIds ? Db::name('sys_user')->whereIn('id', $opIds)->column('username', 'id') : [];
        foreach ($logs as &$l) {
            $l['operator_name'] = $opNames[$l['operator']] ?? ('#' . $l['operator']);
        }
        unset($l);

        // 规则实时试算（按快照规则）
        $ruleResult = ExamSignupService::evaluateSignup($signup);
        $materialCheck = ExamSignupService::materialStatus(
            $id, ExamMaterialService::normalize($snapshot['materials'] ?? [])
        );

        $signup = $this->decorate($signup);
        // v2.99.59：草稿快照中的身份证号为密文，出口统一转掩码
        if (!empty($snapshot['customer_draft']['id_card'])) {
            $snapshot['customer_draft']['id_card'] = \app\service\IdCardService::mask(
                \app\service\IdCardService::decode($snapshot['customer_draft']['id_card'])
            );
        }
        return json(r_success([
            'signup'         => $signup,
            'customer'       => $customer,
            'company'        => $company,
            'config'         => $config,
            'batch'          => $batch,
            'room'           => $room,
            'flow'           => $flow,
            'stage_times'    => json_decode((string)$signup['stage_times'], true) ?: [],
            'materials_def'  => ExamMaterialService::normalize($snapshot['materials'] ?? []),
            'materials'      => $materials,
            'material_check' => $materialCheck,
            'audits'         => $audits,
            'logs'           => $logs,
            'rule_result'    => $ruleResult,
            'snapshot'       => $snapshot,
        ]));
    }

    /**
     * v2.99.61：报名详情「考生身份证」受控明文出口。
     *
     * 与人员档案同一权限口径（staff:reveal）：无权限直接 403，并写 granted=0 的拒绝审计；
     * 成功查看/复制写 sensitive_access_log（owner_type=exam_customer，owner_id=考生档案ID）。
     *
     * 入参：id（报名单ID）、purpose（view/copy，默认 view）
     */
    public function reveal()
    {
        $this->boot();
        $id = (int)$this->request->param('id', 0);
        $purpose = trim((string)$this->request->param('purpose', 'view')) === 'copy' ? 'copy' : 'view';

        $signup = Db::name('exam_signup')->where('id', $id)->where('status', 1)->find();
        if (!$signup) {
            return json(r_error('报名单不存在', 404));
        }
        if (!$this->isAdmin()) {
            $chk = Db::name('exam_signup')->alias('s')->where('s.id', $id);
            $this->scopeSignup($chk);
            if (!(clone $chk)->count()) {
                return json(r_error('无权查看该报名单', 403));
            }
        }
        $customerId = (int)$signup['customer_id'];
        $customer = $customerId > 0 ? Db::name('exam_customer')->find($customerId) : null;
        if (!$customer) {
            return json(r_error('该报名单未关联考生档案', 404));
        }
        $target = (string)($customer['name'] ?? '');
        $plain  = \app\service\IdCardService::decode($customer['id_card'] ?? '');

        $user = $this->request->user ?? [];
        if (!\app\service\IdCardService::canReveal($user)) {
            \app\service\SensitiveAccessService::record(
                'exam_customer', $customerId, 'id_card', $purpose, false,
                ['target' => $target . '（无明文权限）']
            );
            return json(r_error('当前账号无查看明文的权限，请联系管理员在「角色管理 → 人员档案 → 查看明文」中授权', 403, [
                'field'   => 'id_card',
                'granted' => 0,
            ]));
        }
        if ($plain === '') {
            return json(r_error('该考生未登记身份证号，或密文无法解密，请联系管理员', 422));
        }

        \app\service\SensitiveAccessService::record(
            'exam_customer', $customerId, 'id_card', $purpose, true, ['target' => $target]
        );
        return json(r_success([
            'id'      => $id,
            'field'   => 'id_card',
            'value'   => $plain,
            'masked'  => false,
            'purpose' => $purpose,
        ]));
    }

    /** 建单/详情所需的下拉与定义 */
    public function meta()
    {
        $this->boot();
        $configId = (int)$this->request->param('biz_config_id', 0);

        $configs = Db::name('exam_biz_config')->alias('bc')
            ->where('bc.status', 1)->where('bc.is_open', 1)
            ->leftJoin('exam_cert_type ct', 'ct.id = bc.cert_type_id')
            ->leftJoin('exam_biz_action ba', 'ba.id = bc.action_id')
            ->field('bc.id,bc.cert_type_id,bc.action_id,bc.name,bc.fee,ct.name cert_name,ba.name action_name,ba.code action_code')
            ->order('bc.sort asc,bc.id desc')->select()->toArray();

        $staffList = Db::name('staff')->where('status', 1)
            ->field('id,staff_name,id_card,phone,dept')->order('id desc')->limit(500)->select()->toArray();
        // v2.99.59：人员下拉里的身份证号按密文存储，出口转掩码（避免把明文带到前端）
        \app\service\IdCardService::decodeRows($staffList);
        $staffList = \app\service\MaskService::staffListFor($staffList, $this->request->user ?? []);

        $out = [
            'configs'      => $configs,
            'customers'    => $this->customerOptions(),
            'staff'        => $staffList,
            'companies'    => Db::name('client_company')->where('status', 1)
                                ->field('id,company_name,short_name,credit_code')->order('id desc')->limit(500)->select()->toArray(),
            'education'    => array_keys(\app\service\ExamRuleService::EDU_RANK),
            'edu_types'    => \app\service\ExamRuleService::EDU_TYPES,
            'field_labels' => \app\service\ExamRuleService::FIELD_LABEL,
        ];

        if ($configId > 0) {
            $rc = ExamSignupService::resolveConfig($configId);
            $out['config_detail'] = [
                'rules'     => $rc['rules'],
                'materials' => $rc['materials'],
                'flow'      => $rc['flow'],
                'fee'       => (float)$rc['config']['fee'],
            ];
            $out['batches'] = Db::name('exam_batch')->where('status', 1)->where('sign_open', 1)
                ->where(function ($w) use ($configId) {
                    $w->where('biz_config_id', $configId)->whereOr('biz_config_id', 0);
                })->field('id,name,room_id,sign_start,sign_end,exam_date,quota,fee')
                ->order('is_current desc,id desc')->select()->toArray();
        }

        return json(r_success($out));
    }

    private function customerOptions(): array
    {
        $rows = Db::name('exam_customer')->where('status', 1)
            ->field('id,staff_id,name,gender,nation,phone,id_card,education,edu_type,edu_cert_no,graduate_date,graduate_school,company_id,unit_name,city,birthday,address')
            ->order('id desc')->limit(500)->select()->toArray();
        // v2.99.59：身份证号出口只给掩码
        \app\service\IdCardService::decodeRows($rows);
        foreach ($rows as &$r) {
            $plain = (string)($r['id_card'] ?? '');
            $r['id_card_mask'] = $plain === '' ? '' : \app\service\IdCardService::mask($plain);
            $r['id_card'] = $r['id_card_mask'];
        }
        unset($r);
        return $rows;
    }

    public function customers()
    {
        $this->boot();
        [$page, $limit] = $this->pageParams();
        $q = Db::name('exam_customer')->alias('cu')->where('cu.status', 1);
        $keyword = trim((string)$this->request->param('keyword', ''));
        if ($keyword !== '') {
            // v2.99.59：身份证密文落库，关键词只支持完整 18/15 位精确命中（盲索引）
            $idHash = $this->idCardHashOf($keyword);
            $q->where(function ($w) use ($keyword, $idHash) {
                $w->whereLike('cu.name', "%{$keyword}%")
                  ->whereOr('cu.phone', 'like', "%{$keyword}%");
                if ($idHash !== '') {
                    $w->whereOr('cu.id_card_hash', $idHash);
                }
            });
        }
        $total = (clone $q)->count();
        $list = (clone $q)
            ->leftJoin('client_company cc', 'cc.id = cu.company_id')
            ->leftJoin('staff st', 'st.id = cu.staff_id')
            ->field('cu.*,cc.company_name,st.staff_name staff_link')
            ->order('cu.id desc')->page($page, $limit)->select()->toArray();
        \app\service\IdCardService::decodeRows($list);
        foreach ($list as &$r) {
            $plain = (string)($r['id_card'] ?? '');
            $r['id_card_mask'] = $plain === '' ? '' : \app\service\IdCardService::mask($plain);
            $r['id_card'] = $r['id_card_mask'];
            $r['signup_count'] = Db::name('exam_signup')
                ->where('customer_id', $r['id'])->where('status', 1)->where('is_draft', 0)->count();
        }
        unset($r);
        return json(r_success(['list' => $list, 'total' => $total]));
    }

    public function customerSave()
    {
        $this->boot();
        if (!$this->can('examsignup', 'edit')) {
            return json(r_error('无保存权限', 403));
        }
        $uid = (int)$this->request->userId;
        $customer = $this->request->param('customer/a', []);
        if (!$customer) {
            // 兼容扁平提交
            $customer = $this->request->only(['staff_id','name','gender','nation','id_card','phone',
                'education','edu_type','edu_cert_no','graduate_date','company_id','unit_name',
                'city','address','remark'], 'param');
        }
        try {
            $customer = ExamSignupService::resolveCustomer(
                ['customer_id' => (int)$this->request->param('id', 0), 'customer' => $customer],
                $uid, false
            );
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage()));
        }
        $this->log('报名工单', '保存考生', (string)($customer['name'] ?? ''));
        return json(r_success(['id' => $customer['id'] ?? 0], '保存成功'));
    }

    public function save()
    {
        $this->boot();
        $perm = (int)$this->request->param('draft', 0) ? 'edit' : 'create';
        if (!$this->can('examsignup', $perm)) {
            return json(r_error('无建单权限', 403));
        }
        $p = $this->request->param();
        // 客户侧账号只能为本单位报名
        if (!$this->isAdmin() && $this->roleCode() === 'company') {
            $compIds = array_values(array_filter(array_map('intval', $this->companyIds())));
            $custCompany = (int)($p['customer']['company_id'] ?? ($p['company_id'] ?? 0));
            if (!in_array($custCompany, $compIds, true)) {
                return json(r_error('只能为本单位人员报名', 403));
            }
        }
        $owner = (int)($this->request->user['id'] ?? 0);
        try {
            $signup = ExamSignupService::create($p, (int)$this->request->userId, $owner);
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage()));
        }
        $this->log('报名工单', '创建报名单', (string)$signup['signup_no']);
        return json(r_success(['id' => $signup['id'], 'signup_no' => $signup['signup_no']], '报名成功'));
    }

    public function submit()
    {
        $this->boot();
        if (!$this->can('examsignup', 'edit')) {
            return json(r_error('无操作权限', 403));
        }
        $id = (int)$this->request->param('id', 0);
        $ignore = (int)$this->request->param('ignore_rules', 0) ? true : false;
        try {
            ExamSignupService::submitDraft($id, (int)$this->request->userId, $ignore);
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage()));
        }
        $this->log('报名工单', '提交报名单', (string)$id);
        return json(r_success([], '已转为正式报名单'));
    }

    public function advance()
    {
        $this->boot();
        if (!$this->can('examsignup', 'status')) {
            return json(r_error('无流转权限', 403));
        }
        $id = (int)$this->request->param('id', 0);
        $to = (int)$this->request->param('to_stage', 0);
        $note = mb_substr((string)$this->request->param('note', ''), 0, 255);
        try {
            ExamSignupService::advance($id, $to, $note, (int)$this->request->userId);
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage()));
        }
        $this->log('报名工单', '状态流转', "#{$id} -> {$to}");
        return json(r_success([], '流转成功'));
    }

    public function audit()
    {
        $this->boot();
        if (!$this->can('examsignup', 'audit')) {
            return json(r_error('无审核权限', 403));
        }
        $id = (int)$this->request->param('id', 0);
        $pass = (int)$this->request->param('pass', 0) ? true : false;
        $reason = mb_substr((string)$this->request->param('reason', ''), 0, 255);
        try {
            ExamSignupService::audit($id, $pass, $reason, (int)$this->request->userId);
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage()));
        }
        $this->log('报名工单', $pass ? '审核通过' : '审核驳回', "#{$id}");
        return json(r_success([], $pass ? '已通过' : '已驳回'));
    }

    public function materialSave()
    {
        $this->boot();
        if (!$this->can('examsignup', 'edit')) {
            return json(r_error('无上传权限', 403));
        }
        $id = (int)$this->request->param('signup_id', 0);
        $signup = Db::name('exam_signup')->where('id', $id)->where('status', 1)->find();
        if (!$signup) {
            return json(r_error('报名单不存在'));
        }
        $code = trim((string)$this->request->param('material_code', ''));
        if ($code === '') {
            return json(r_error('缺少材料编码'));
        }
        $snapshot = json_decode((string)$signup['snapshot'], true) ?: [];
        $defs = ExamMaterialService::normalize($snapshot['materials'] ?? []);
        $def = ExamMaterialService::find($defs, $code);
        if (!$def) {
            return json(r_error('该材料项不在清单内'));
        }
        // 扩展名与大小校验
        $path = (string)$this->request->param('file_path', '');
        $ext  = strtolower(ltrim((string)$this->request->param('file_ext', ''), '.'));
        if ($path === '' || $ext === '') {
            return json(r_error('请先上传文件'));
        }
        if (!in_array($ext, $def['ext'], true)) {
            return json(r_error('材料格式不符，允许：' . implode('/', $def['ext'])));
        }
        $size = (int)$this->request->param('file_size', 0);
        if ($def['max_kb'] > 0 && $size > $def['max_kb'] * 1024) {
            return json(r_error('材料超过 ' . $def['max_kb'] . 'KB 限制'));
        }

        // 非 multiple：重传即替换（删旧）
        if (empty($def['multiple'])) {
            Db::name('exam_signup_material')
                ->where('signup_id', $id)->where('material_code', $code)
                ->update(['status' => 0, 'update_time' => date('Y-m-d H:i:s')]);
        }
        $sort = (int)Db::name('exam_signup_material')
            ->where('signup_id', $id)->where('status', 1)->max('sort') + 1;
        $mid = Db::name('exam_signup_material')->insertGetId([
            'signup_id'     => $id,
            'material_code' => $code,
            'material_name' => $def['name'],
            'file_path'     => $path,
            'file_url'      => (string)$this->request->param('file_url', $path),
            'file_ext'      => $ext,
            'file_size'     => $size,
            'orig_name'     => mb_substr((string)$this->request->param('orig_name', ''), 0, 255),
            'sort'          => $sort,
            'status'        => 1,
            'uploaded_by'   => (int)$this->request->userId,
            'create_time'   => date('Y-m-d H:i:s'),
        ]);
        $this->log('报名工单', '上传材料', $def['name']);
        return json(r_success(['id' => $mid], '上传成功'));
    }

    public function materialDelete()
    {
        $this->boot();
        if (!$this->can('examsignup', 'edit')) {
            return json(r_error('无操作权限', 403));
        }
        $id = (int)$this->request->param('id', 0);
        Db::name('exam_signup_material')->where('id', $id)->update([
            'status' => 0, 'update_time' => date('Y-m-d H:i:s'),
        ]);
        return json(r_success([], '已删除'));
    }

    public function delete()
    {
        $this->boot();
        if (!$this->can('examsignup', 'delete')) {
            return json(r_error('无删除权限', 403));
        }
        $id = (int)$this->request->param('id', 0);
        $no = (string)Db::name('exam_signup')->where('id', $id)->value('signup_no');
        // v2.99.1：软删工单并清理其自动费用（人工费用保留）
        Db::startTrans();
        try {
            Db::name('exam_signup')->where('id', $id)->update([
                'status' => 0, 'update_time' => date('Y-m-d H:i:s'),
            ]);
            Db::name('fee')
                ->where('source_type', ExamSignupService::FEE_SOURCE)->where('source_id', $id)
                ->where('is_auto', 1)->delete();
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
        $this->log('报名工单', '删除报名单', $no);
        return json(r_success([], '删除成功'));
    }

    /**
     * 报名提醒（轻量、只读）：
     *  - 报名截止临近（默认 7 天内）且报名开放；
     *  - 考试时间临近（默认 7 天内）。
     * 复用本系统提醒中心展示，不新增推送通道；写操作走现有推送体系。
     */
    public function reminders()
    {
        $this->boot();
        $days = max(1, min(60, (int)$this->request->param('days', 7)));
        $now = date('Y-m-d H:i:s');
        $deadline = date('Y-m-d H:i:s', strtotime("+{$days} day"));

        $q = Db::name('exam_batch')->alias('b')
            ->where('b.status', 1)
            ->where(function ($w) use ($now, $deadline) {
                $w->where(function ($w1) use ($now, $deadline) {
                    $w1->where('b.sign_open', 1)->whereNotNull('b.sign_end')
                       ->whereBetween('b.sign_end', [$now, $deadline]);
                })->whereOr(function ($w2) use ($now, $deadline) {
                    $w2->whereNotNull('b.exam_date')->whereBetween('b.exam_date', [$now, $deadline]);
                });
            });
        // 数据权限：仅统计与当前用户相关批次（按工单负责人/单位）
        if (!$this->isAdmin()) {
            $visibleConfig = Db::name('exam_signup')->alias('s')->where('s.status', 1);
            $this->scopeSignup($visibleConfig);
            $batchIds = (clone $visibleConfig)->column('distinct s.batch_id');
            $q->whereIn('b.id', $batchIds ?: [0]);
        }
        $batches = $q->leftJoin('exam_room r', 'r.id = b.room_id')
            ->field('b.id,b.name,b.sign_start,b.sign_end,b.exam_date,b.quota,r.name room_name')
            ->order('b.sign_end asc,b.exam_date asc')->limit(200)->select()->toArray();

        $items = [];
        foreach ($batches as $b) {
            if ($b['sign_end'] && $b['sign_end'] >= $now && $b['sign_end'] <= $deadline) {
                $items[] = [
                    'type' => 'sign_end', 'batch_id' => $b['id'], 'batch_name' => $b['name'],
                    'at' => $b['sign_end'], 'remain_days' => remain_days($b['sign_end']),
                    'tip' => '报名截止', 'room_name' => $b['room_name'],
                ];
            }
            if ($b['exam_date'] && $b['exam_date'] >= $now && $b['exam_date'] <= $deadline) {
                $items[] = [
                    'type' => 'exam_date', 'batch_id' => $b['id'], 'batch_name' => $b['name'],
                    'at' => $b['exam_date'], 'remain_days' => remain_days($b['exam_date']),
                    'tip' => '考试时间', 'room_name' => $b['room_name'],
                ];
            }
        }
        usort($items, fn($a, $b2) => strcmp($a['at'], $b2['at']));
        return json(r_success(['list' => $items, 'days' => $days, 'total' => count($items)]));
    }

    private function maskIdCard(string $id): string
    {
        // v2.99.59：入参可能是 SM4 密文，先解密再按统一规则掩码
        $id = \app\service\IdCardService::decode($id);
        return \app\service\IdCardService::mask($id);
    }

    /** 关键词是完整身份证号时返回盲索引，否则空串（密文后不支持片段搜索） */
    private function idCardHashOf(string $keyword): string
    {
        $id = \app\service\IdCardService::normalize($keyword);
        if (!preg_match('/^\d{15}$|^\d{17}[\dX]$/', $id)) {
            return '';
        }
        return \app\service\IdCardService::hash($id);
    }

    /**
     * 身份证 OCR 识别（v2.86，腾讯云）
     * 支持三种传图方式（优先级）：
     *   1. multipart 文件字段 file；
     *   2. base64 字段 image（data:image 前缀可带可不带）；
     *   3. 已上传相对路径 file_path（如 /uploads/202610/xxx.jpg）。
     * 参数 side：FRONT 人像面（默认，回填姓名等）/ BACK 国徽面（签发机关/有效期）。
     */
    public function ocrIdcard()
    {
        $this->boot();
        if (!$this->can('examsignup', 'edit')) {
            return json(r_error('无使用权限', 403));
        }
        $side = strtoupper((string)$this->request->param('side', 'FRONT')) === 'BACK' ? 'BACK' : 'FRONT';

        $base64 = $this->readOcrImageBase64();
        if ($base64 === '') {
            return json(r_error('请上传或传入身份证图片', 400));
        }

        $r = \app\service\ExamOcrService::idCard($base64, $side);
        if (empty($r['ok'])) {
            return json(r_error($r['msg'] ?? '识别失败'));
        }
        $this->log('报名工单', 'OCR身份证识别', $side === 'FRONT' ? '人像面' : '国徽面');
        return json(r_success($r['data'] ?? [], '识别成功'));
    }

    /**
     * 毕业证 OCR 识别（v2.87，腾讯云通用印刷体高精度 + 服务端解析）
     * 传图方式与身份证端点一致：file / image(base64) / file_path。
     * 返回：school 毕业学校、cert_no 毕业证编号（电子注册号）、graduate_date 毕业日期。
     */
    public function ocrDiploma()
    {
        $this->boot();
        if (!$this->can('examsignup', 'edit')) {
            return json(r_error('无使用权限', 403));
        }
        $base64 = $this->readOcrImageBase64();
        if ($base64 === '') {
            return json(r_error('请上传或传入毕业证图片', 400));
        }
        $r = \app\service\ExamOcrService::diploma($base64);
        if (empty($r['ok'])) {
            return json(r_error($r['msg'] ?? '识别失败'));
        }
        $this->log('报名工单', 'OCR毕业证识别', '毕业学校/编号/日期');
        return json(r_success($r['data'] ?? [], '识别成功'));
    }

    /**
     * 读取 OCR 图片为 base64（支持 multipart file / base64 image / 已上传 file_path）
     * 失败或无图返回空串。
     */
    private function readOcrImageBase64(): string
    {
        // 1. 直接上传文件
        $file = $this->request->file('file');
        if ($file) {
            $allow = ['jpg', 'jpeg', 'png', 'webp', 'bmp'];
            $ext = strtolower((string)$file->getOriginalExtension());
            // getOriginalExtension 可能为空，从原名再取一次
            if ($ext === '') {
                $ext = strtolower(pathinfo((string)$file->getOriginalName(), PATHINFO_EXTENSION));
            }
            if (!in_array($ext, $allow, true)) {
                return '';
            }
            if ((int)$file->getSize() > 7 * 1024 * 1024) {
                return '';
            }
            return base64_encode((string)file_get_contents($file->getPathname()));
        }
        // 2. base64 直传
        $image = (string)$this->request->param('image', '');
        if ($image !== '') {
            return $image;
        }
        // 3. 已上传相对路径（限本地 public 目录，防穿越）
        $rel = ltrim((string)$this->request->param('file_path', ''), '/');
        if ($rel !== '' && !str_contains($rel, '..')) {
            $abs = public_path() . $rel;
            if (is_file($abs)) {
                return base64_encode((string)file_get_contents($abs));
            }
        }
        return '';
    }
}
