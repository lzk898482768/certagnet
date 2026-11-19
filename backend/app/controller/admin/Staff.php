<?php
namespace app\controller\admin;

use app\model\Staff as StaffModel;
use app\model\Certificate as CertificateModel;
use app\model\ClientCompany;
use app\model\CertSecondment;
use app\service\ExcelService;
use app\service\SecondmentService;
use app\service\CustomFieldService;
use app\service\CrmService; // v2.54：人员档案纳入 CRM（负责人/移交/跟进）
use think\facade\Db;

class Staff extends AdminController
{
    public function index()
    {
        [$page, $limit] = $this->pageParams();
        $name    = $this->request->param('staff_name', '');
        $phone   = trim((string)$this->request->param('phone', ''));
        $keyword = trim((string)$this->request->param('keyword', '')); // 移动端搜索框
        $status  = $this->request->param('status', '');
        $companyId = (int)$this->request->param('company_id', 0);
        // v2.54：负责人筛选（owner_user_id 指定某人；mine=1 只看自己负责的）
        $ownerUserId = (int)$this->request->param('owner_user_id', 0);
        $mine        = (int)$this->request->param('mine', 0);
        // v2.56：社保状态 / 借调状态筛选（社保以人为锚点，兼容历史证书冗余字段）
        $socialStatus = $this->request->param('social_status', '');
        $secStatus    = $this->request->param('secondment_status', '');

        CrmService::ensureSchema();
        \app\service\PersonProfileService::ensureStaffColumns();
        \app\service\IdCardSchemaService::ensure();
        $q = StaffModel::order('id', 'desc');
        $q = $this->applyStaffScope($q);
        if ($name) {
            $q->where('staff_name', 'like', "%{$name}%");
        }
        if ($phone) {
            $q->where('phone', 'like', "%{$phone}%");
        }
        // 移动端统一关键词：姓名 / 电话 / 身份证 任一命中
        // v2.99.59：身份证号已密文落库，只支持完整 18/15 位精确命中（按盲索引），
        // 片段搜索不再支持（见设计报告 §9）。
        if ($keyword) {
            $kwHash = self::idCardHashOf($keyword);
            $q->where(function ($qq) use ($keyword, $kwHash) {
                $qq->whereOr('staff_name', 'like', "%{$keyword}%")
                    ->whereOr('phone', 'like', "%{$keyword}%");
                if ($kwHash !== '') {
                    $qq->whereOr('id_card_hash', $kwHash);
                }
            });
        }
        if ($status !== '') {
            $q->where('status', $status);
        }
        if ($companyId) {
            // 借调了该人员证书的企业
            $staffIds = Db::name('cert_secondment')->where('company_id', $companyId)
                ->where('status', 1)->column('staff_id');
            $relIds = Db::table('staff_company_rel')->where('company_id', $companyId)->column('staff_id');
            $q->whereIn('id', array_unique(array_merge($staffIds ?: [], $relIds ?: [])) ?: [0]);
        }
        if ($mine) {
            $q = \app\service\ResourceOwnerService::scopeByOwner($q, 'staff', (int)$this->request->userId, 'id');
        } elseif ($ownerUserId > 0) {
            $q = \app\service\ResourceOwnerService::scopeByOwner($q, 'staff', $ownerUserId, 'id');
        }
        // v2.99.37：公海筛选（is_pool=1 公海 / 0 已分配；不传=全部）
        $isPool = $this->request->param('is_pool', '');
        if ($isPool !== '' && $isPool !== null) {
            $q->where('is_pool', (int)$isPool);
        }
        // v2.56：社保状态筛选（人档命中 或 名下证书冗余字段命中，兼容旧数据）
        if ($socialStatus !== '') {
            $q->where(function ($w) use ($socialStatus) {
                $w->where('social_status', $socialStatus)
                  ->whereOr(function ($x) use ($socialStatus) {
                      $x->whereExists(function ($sub) use ($socialStatus) {
                          $sub->name('certificate')
                              ->whereColumn('certificate.staff_id', 'staff.id')
                              ->where('social_status', $socialStatus);
                      });
                  });
            });
        }
        // v2.56：借调状态筛选（1=有证书正在借调中，0=未借调）
        if ($secStatus !== '') {
            $busyStaffIds = Db::name('cert_secondment')->where('status', 1)->column('staff_id');
            if ((int)$secStatus === 1) {
                $q->whereIn('id', $busyStaffIds ?: [0]);
            } else {
                $q->whereNotIn('id', $busyStaffIds ?: [0]);
            }
        }
        $total = $q->count();
        $list  = $q->page($page, $limit)->select()->toArray();
        self::decorate($list);
        \app\service\ResourceOwnerService::attachOwners($list, 'staff');
        CustomFieldService::attachList('staff', $list);
        // v2.35：人员多附件（列表补 attachments + attach_count）
        \app\service\AttachService::attachList('staff', $list);
        // v2.99.59：密文身份证先解密（并移除盲索引列），再统一走强制掩码
        \app\service\IdCardService::decodeRows($list);
        // 敏感字段脱敏（身份证永远掩码；电话/邮箱按脱敏矩阵）
        $list = \app\service\MaskService::staffListFor($list, $this->request->user ?? []);
        // v2.54：负责人 / 创建人姓名 + 最近跟进时间
        CrmService::attachOwner($list, 'staff');
        $lastMap = CrmService::lastFollowMap('staff', array_column($list, 'id'));
        foreach ($list as &$it) {
            $it['last_follow_time'] = $lastMap[(int)$it['id']] ?? '';
        }
        unset($it);
        return json(r_success(['list' => $list, 'total' => $total]));
    }

    /** 人员详情：基本信息 + 名下证书（含当前借调企业与期限）+ 借调历史 */
    public function detail()
    {
        $id = (int)$this->request->param('id', 0);
        $staff = StaffModel::find($id);
        if (!$staff) {
            return json(r_error('人员不存在', 400));
        }
        $this->assertStaffAccess($id);
        CrmService::ensureSchema();
        \app\service\PersonProfileService::ensureStaffColumns();
        $data = $staff->toArray();
        // v2.99.59：密文身份证解密（移除盲索引），随后统一掩码
        $data = \app\service\IdCardService::decodeRow($data);
        CrmService::attachOwner($data, 'staff');
        CustomFieldService::attachOne('staff', $data);
        // v2.35：人员多附件
        $data['attachments'] = \app\service\AttachService::listBy('staff', $id);

        // 名下证书 + 当前借调信息（客户账号只返回本企业借调中的证书）
        $certQ = CertificateModel::where('staff_id', $id);
        $this->scopeCert($certQ);
        $certs = $certQ->orderRaw('expire_date IS NULL ASC, expire_date ASC')->select()->toArray();
        SecondmentService::attach($certs);
        $data['certificates'] = $certs;

        // 借调历史（客户账号只返回本企业记录）
        $historyQ = CertSecondment::with(['company', 'certificate'])->where('staff_id', $id);
        $this->restrictSecondmentQuery($historyQ);
        $history = $historyQ->order('id', 'desc')->select()->toArray();
        foreach ($history as &$h) {
            $h['status_text'] = SecondmentService::statusText((int)$h['status']);
            $h['remain_days'] = SecondmentService::remainDays($h['end_date'] ?? '');
            if (!empty($h['certificate']) && is_array($h['certificate'])) {
                $h['certificate'] = \app\service\MaskService::cert($h['certificate'], $this->request->user ?? []);
            }
        }
        unset($h);
        $data['secondments'] = $history;

        // 服务企业（由借调关系推导 + 历史服务关系）
        $companyNameQ = Db::name('cert_secondment')->alias('s')
            ->join('client_company c', 'c.id = s.company_id')
            ->where('s.staff_id', $id)->where('s.status', 1);
        $this->applyCompanyScope($companyNameQ, 's.company_id');
        $companyNames = $companyNameQ->column('c.company_name');
        $data['company_names'] = implode('、', array_values(array_unique($companyNames)));

        // 敏感字段脱敏（本人档与名下证书一并处理）——v2.99.51：按字段级矩阵逐字段处理
        $user = $this->request->user ?? [];
        $data['certificates'] = \app\service\MaskService::certList($data['certificates'], $user);
        foreach (['id_card' => 'staff.id_card', 'phone' => 'staff.phone', 'email' => 'staff.email'] as $k => $field) {
            $data[$k] = \app\service\MaskService::fieldValue($field, $data[$k] ?? '', $user);
        }

        return json(r_success($data));
    }

    /**
     * 编辑用明文读取：列表/详情均按脱敏策略返回打码值，但「编辑」需要回填真实身份证/电话/邮箱。
     * 仅管理员可获取明文（与脱敏策略一致：非管理员始终走脱敏，避免敏感数据外泄）；
     * 返回结构与 detail 一致（含 custom 自定义字段），管理员时敏感字段为明文。
     * 前端 openEdit 拉取此接口填充表单，配合 save 端 guardMaskedEcho 双向防护回写。
     */
    public function read()
    {
        $id = (int)$this->request->param('id', 0);
        $staff = StaffModel::find($id);
        if (!$staff) {
            return json(r_error('人员不存在', 400));
        }
        $this->assertStaffAccess($id);
        CrmService::ensureSchema();
        \app\service\PersonProfileService::ensureStaffColumns();
        $data = $staff->toArray();
        // v2.99.59：密文身份证解密（移除盲索引）
        $data = \app\service\IdCardService::decodeRow($data);
        CrmService::attachOwner($data, 'staff');
        CustomFieldService::attachOne('staff', $data);
        // v2.35：人员多附件（编辑回填用）
        $data['attachments'] = \app\service\AttachService::listBy('staff', $id);
        // 保留解密后的明文副本，供管理员/有明文权限账号的编辑回填使用
        $plainRow = $data;
        // 默认按脱敏策略处理（身份证永远掩码；非管理员始终打码）
        $user = $this->request->user ?? [];
        $data = \app\service\MaskService::staff($data, false, $user);
        // 编辑回填真实敏感字段：身份证需 staff:reveal，电话/邮箱沿用管理员策略
        $canReveal = \app\service\IdCardService::canReveal($user);
        if ($this->isAdmin() || $canReveal) {
            foreach (['id_card' => 'staff.id_card', 'phone' => 'staff.phone', 'email' => 'staff.email'] as $f => $code) {
                if ($f === 'id_card' && !$canReveal) {
                    continue;
                }
                $plain = (string)($plainRow[$f] ?? '');
                $value = \app\service\MaskService::editValue($code, $plain, $user);
                $data[$f] = $value;
                // v2.99.47：编辑回填取明文属于敏感访问，留痕（purpose=edit）；被隐藏/脱敏时不记
                if ($plain !== '' && $value !== '' && $value === $plain) {
                    \app\service\SensitiveAccessService::record(
                        'staff', $id, $f, 'edit', true,
                        ['target' => (string)$staff->staff_name]
                    );
                }
            }
        }
        return json(r_success($data));
    }

    /**
     * v2.99.59：受控明文出口（查看 / 复制身份证、手机号、邮箱）。
     *
     * 合规要求：
     *  - 列表 / 详情接口永远只返回脱敏值（身份证强制掩码，不看全局开关）；
     *  - 明文只经由此出口发放，身份证必须 `staff:reveal`，**无权限直接 403**；
     *  - 每次调用写 sensitive_access_log，记录谁、何时、何设备、查看/复制了谁的哪个字段；
     *  - 无权访问同样写一条 granted=0 的拒绝事件，便于发现违规试探。
     *
     * 入参：id（人员ID）、field（id_card/phone/email）、purpose（view/copy，默认 view）
     */
    public function reveal()
    {
        $id = (int)$this->request->param('id', 0);
        $field = trim((string)$this->request->param('field', 'id_card'));
        $purpose = trim((string)$this->request->param('purpose', 'view')) === 'copy' ? 'copy' : 'view';

        $allowed = ['id_card', 'phone', 'email'];
        if ($id <= 0 || !in_array($field, $allowed, true)) {
            return json(r_error('参数错误', 400));
        }
        $staff = StaffModel::find($id);
        if (!$staff) {
            return json(r_error('人员不存在', 404));
        }
        $this->assertStaffAccess($id);

        $user  = $this->request->user ?? [];
        $code  = 'staff.' . $field;
        $target = (string)$staff->staff_name;
        // 身份证为密文落库，需要先解密；解密失败按「无可用明文」处理
        $plain = $field === 'id_card'
            ? \app\service\IdCardService::decode($staff->id_card ?? '')
            : (string)($staff->{$field} ?? '');

        // 身份证明文权限只看 staff:reveal；手机号/邮箱沿用脱敏矩阵配置
        $canPlain = $field === 'id_card'
            ? \app\service\IdCardService::canReveal($user)
            : (\app\service\MaskService::stateFor($code, $user) === \app\service\MaskService::STATE_PLAIN);

        if (!$canPlain) {
            // 无权限：记录拒绝事件并直接 403（前端据此提示申请权限，不再回退掩码）
            \app\service\SensitiveAccessService::record(
                'staff', $id, $field, $purpose, false,
                ['target' => $target . '（无明文权限）']
            );
            return json(r_error('当前账号无查看明文的权限，请联系管理员在「角色管理 → 人员档案 → 查看明文」中授权', 403, [
                'field'   => $field,
                'granted' => 0,
            ]));
        }

        if ($plain === '') {
            return json(r_error('该人员未登记' . ($field === 'id_card' ? '身份证号' : '相关信息') . '，或密文无法解密，请联系管理员', 422));
        }

        \app\service\SensitiveAccessService::record(
            'staff', $id, $field, $purpose, true, ['target' => $target]
        );
        return json(r_success([
            'id'      => $id,
            'field'   => $field,
            'value'   => $plain,
            'masked'  => false,
            'purpose' => $purpose,
        ]));
    }

    /**
     * v2.47：人员全景档案抽屉里直接保存附件（只覆盖附件子表，不动人员其他字段）。
     * 附件文件本身已在统一上传入口做过白名单/真实 MIME/大小校验，这里沿用 save() 同等口径。
     */
    public function attachSave()
    {
        $id = (int)$this->request->post('id', 0);
        if ($id <= 0 || !StaffModel::find($id)) {
            return json(r_error('人员不存在', 400));
        }
        $this->assertStaffAccess($id);
        $items = $this->request->post('attachments/a', []);
        if (!is_array($items)) {
            return json(r_error('附件数据格式错误', 400));
        }
        $attCheck = \app\service\AttachService::validateAll($items);
        if (!$attCheck['ok']) {
            return json(r_error($attCheck['msg'], 422));
        }
        try {
            $n = \app\service\AttachService::replace('staff', $id, $items, (int)$this->request->userId);
        } catch (\Throwable $e) {
            return json(r_error('附件保存失败：' . $e->getMessage(), 400));
        }
        try {
            \app\service\Lookup::forgetStaff(null, null);
        } catch (\Throwable $e) {
        }
        $this->log('持证人员', '附件', "详情直接保存人员 #{$id} 附件，共 {$n} 个");
        return json(r_success([
            'count'       => $n,
            'attachments' => \app\service\AttachService::listBy('staff', $id),
        ], '附件已保存'));
    }

    /**
     * 人员全景档案 —— 产品精髓「一人一档」
     * 以人员为锚点聚合：基本信息（脱敏）+ 社保 + 证书清单（含借调占用）+ 项目履历 + 合同费用 + 冲突
     */
    public function profile()
    {
        $id = (int)$this->request->param('id', 0);
        if (!$id) {
            return json(r_error('请选择人员', 400));
        }
        if (!\app\model\Staff::find($id)) {
            return json(r_error('人员不存在', 400));
        }
        $this->assertStaffAccess($id);
        // 非管理员按企业范围裁剪全景档案，避免通过人员档案看到其他企业的借调/合同/费用。
        $companyIds = $this->isAdmin() ? [] : $this->companyIds();
        $data = \app\service\PersonProfileService::profile($id, $companyIds, $this->request->user ?? []);
        if (!$data) {
            return json(r_error('人员不存在', 400));
        }
        return json(r_success($data));
    }

    /** 证件利用率统计（闲置率分析） */
    public function utilization()
    {
        $deptId = (int)$this->request->param('dept_id', 0);
        $days   = (int)$this->request->param('days', 180);
        $days   = $days > 0 ? min($days, 1095) : 180;
        $data   = \app\service\PersonProfileService::utilization($deptId, $days);
        return json(r_success($data));
    }

    /** 闲置预警：剩余有效期充足但长期未借调的证书 */
    public function idleAlert()
    {
        $days  = (int)$this->request->param('days', 180);
        $limit = (int)$this->request->param('limit', 50);
        $days  = $days > 0 ? min($days, 1095) : 180;
        $limit = $limit > 0 ? min($limit, 200) : 50;
        $data  = \app\service\PersonProfileService::idleAlerts($days, $limit);
        return json(r_success($data));
    }

    /**
     * v2.99.38：新增前查重预检（不写库）。命中只返回最小信息，前端据此提示「将关联为共同所有人」。
     */
    public function dedupCheck()
    {
        \app\service\IdCardSchemaService::ensure();
        $data = $this->request->post(['staff_name', 'id_card', 'phone']);
        $idCard = trim((string)($data['id_card'] ?? ''));
        if ($idCard !== '') {
            $info = \app\service\ExamIdCardService::parse($idCard);
            if ($info['ok']) {
                $data['id_card'] = $info['id_card'];
            }
        }
        $dup = \app\service\DuplicateResolver::staff($data);
        return json(r_success([
            'hit'  => $dup ? 1 : 0,
            'item' => $dup ? \app\service\DuplicateResolver::brief('staff', $dup) : null,
        ]));
    }

    public function save()
    {
        $id = (int)$this->request->post('id', 0);
        // v2.99.59：确保 id_card_hash 列存在（老库运行时自愈）
        \app\service\IdCardSchemaService::ensure();
        // 注意：attach_path 不再直接采信前端（v2.35 一律由 attachments[0] 派生）
        $data = $this->request->post(['staff_name', 'id_card', 'phone', 'email', 'dept', 'dept_id', 'position', 'remark', 'status']);
        // v2.87：人员档案扩展字段（身份证/毕业证 OCR 回填），与 exam_customer 对齐
        \app\service\PersonProfileService::ensureStaffColumns();
        $extra = $this->request->post(['gender', 'nation', 'birthday', 'address',
            'education', 'edu_type', 'edu_cert_no', 'graduate_date', 'graduate_school']);
        $data['gender'] = (int)($extra['gender'] ?? 0);
        $data['nation'] = trim((string)($extra['nation'] ?? ''));
        $data['address'] = trim((string)($extra['address'] ?? ''));
        $data['education'] = trim((string)($extra['education'] ?? ''));
        $data['edu_type'] = trim((string)($extra['edu_type'] ?? '')) ?: '无';
        $data['edu_cert_no'] = trim((string)($extra['edu_cert_no'] ?? ''));
        $data['graduate_school'] = trim((string)($extra['graduate_school'] ?? ''));
        // 空日期统一转 null（与报名模块约定一致）
        foreach (['birthday', 'graduate_date'] as $dk) {
            $dv = trim((string)($extra[$dk] ?? ''));
            $data[$dk] = $dv === '' ? null : $dv;
        }
        // v2.35：人员多附件
        $attachments = $this->request->post('attachments/a', []);
        if (!is_array($attachments)) {
            $attachments = [];
        }
        $attCheck = \app\service\AttachService::validateAll($attachments);
        if (!$attCheck['ok']) {
            return json(r_error($attCheck['msg'], 422));
        }
        // v2.29 社保上移人档：社保三字段 + 属地（与 certificate 冗余字段双读，见 PersonProfileService::resolveSocial）
        foreach (['social_status', 'social_company', 'social_start_month', 'region_code'] as $k) {
            $v = $this->request->post($k, null);
            if ($v !== null) {
                $data[$k] = $k === 'social_status' ? (int)$v : trim((string)$v);
            }
        }
        // 脱敏回写防护：列表打码值（含 *）不得覆盖库内明文；更新时身份证号留空同样视为不修改
        \app\service\MaskService::guardMaskedEcho($data, ['id_card', 'phone', 'email']);
        if ($id && isset($data['id_card']) && trim((string)$data['id_card']) === '') {
            unset($data['id_card']);
        }
        if (isset($data['dept_id'])) {
            $data['dept_id'] = (int)$data['dept_id'];
        }
        if (empty($data['staff_name'])) {
            return json(r_error('人员姓名必填', 400));
        }
        $customErr = CustomFieldService::validate('staff', $this->request->post('custom'));
        if ($customErr !== '') {
            return json(r_error($customErr, 400));
        }
        // v2.96：新增人员必须填写身份证号（人员档案 / 各快捷新增入口统一约束）。
        // 编辑时留空仍视为不修改，兼容列表脱敏回写。
        $idCard = trim((string)($data['id_card'] ?? ''));
        if (!$id && $idCard === '') {
            return json(r_error('身份证号必填', 400));
        }
        // v2.97：身份证号正确性校验（格式/校验位/出生日期），15 位旧号自动补全为 18 位
        if ($idCard !== '') {
            $idInfo = \app\service\ExamIdCardService::parse($idCard);
            if (!$idInfo['ok']) {
                return json(r_error('身份证号不正确：' . $idInfo['msg'], 400));
            }
            $idCard = $idInfo['id_card'];
            $data['id_card'] = $idCard;
        }
        // v2.79.2：编辑时重复身份证阻止（排除自身）
        // v2.99.38：新建时改为「复用存量 + 自动共有」，见下方 DuplicateResolver 分支
        if ($id && $idCard !== '') {
            // 走 DuplicateResolver：优先盲索引，未命中再按历史明文比对
            $dup = \app\service\DuplicateResolver::staff(['id_card' => $idCard], $id);
            if ($dup) {
                return json(r_error('身份证号已存在（人员：' . ($dup['staff_name'] ?? '') . '），请勿重复建档；如需合并请使用「重复检测」功能', 400, [
                    'duplicate' => [
                        'id'         => (int)$dup['id'],
                        'staff_name' => (string)($dup['staff_name'] ?? ''),
                        'id_card'    => \app\service\IdCardService::mask($idCard),
                    ],
                ]));
            }
        }
        // v2.99.38：新建查重命中存量 → 复用存量 + 原所有人/当前操作人共有（不新建重复主记录）。
        // 注意：即使当前账号对存量无数据权限也要能复用，这是需求指定的受控提权。
        if (!$id) {
            $dup = \app\service\DuplicateResolver::staff($data);
            if ($dup) {
                CrmService::ensureSchema();
                $share = \app\service\DuplicateResolver::share('staff', (int)$dup['id'], (int)$this->request->userId);
                $this->log('持证人员', '查重共有', "#{$dup['id']} 命中存量，共有人数 {$share['owners']}");
                return json(r_success([
                    'id'         => (int)$dup['id'],
                    'duplicated' => true,
                ], '检测到已存在该人员，已自动关联为共同所有人'));
            }
        }
        if ($id) {
            $this->assertStaffAccess($id);
            // v2.38：改档前先取旧键，改名/换身份证号后需同时抹掉旧键与新键，
            // 否则导入预览会按旧姓名/旧证件号命中缓存（“改完还是旧档”）
            $oldRow = StaffModel::where('id', $id)->find();
            // v2.99.59：身份证号密文 + 盲索引
            \app\service\IdCardService::prepareWrite($data);
            StaffModel::where('id', $id)->update($data);
            if ($oldRow) {
                \app\service\Lookup::forgetStaff(
                    \app\service\IdCardService::decode($oldRow['id_card'] ?? '') ?: null,
                    trim((string)($oldRow['staff_name'] ?? '')) ?: null
                );
            }
            $this->log('持证人员', '更新', "#{$id}");
        } else {
            // v2.99.59：身份证号密文 + 盲索引
            \app\service\IdCardService::prepareWrite($data);
            $data['create_by'] = (int)$this->request->userId; // self 数据范围依据（v2.14）
            CrmService::ensureSchema();
            // v2.54：新建默认当前用户为负责人（不允许在新增时指定他人，负责人变更走移交）
            $data['owner_user_id'] = (int)$this->request->userId;
            $model = StaffModel::create($data);
            $id    = (int)$model->id;
            \app\service\ResourceOwnerService::addOwner('staff', $id, (int)$this->request->userId, 'assign', (int)$this->request->userId, true);
            $this->log('持证人员', '新增');
        }
        CustomFieldService::saveValues('staff', (int)$id, $this->request->post('custom'));
        // v2.38：人员写路径主动失效查询缓存（避免导入预览命中旧值）
        // v2.99.59：传入明文（缓存键按盲索引派生），不能用 $data 里的密文
        \app\service\Lookup::forgetStaff(
            $idCard ?: null,
            trim((string)($data['staff_name'] ?? '')) ?: null
        );
        // v2.35：附件子表全量覆盖（DELETE→INSERT→同步 attach_path=第一条）
        try {
            \app\service\AttachService::replace('staff', (int)$id, $attachments, (int)$this->request->userId);
        } catch (\Throwable $e) {
            // 附件子表若缺列等异常不阻断主保存
        }
        return json(r_success(['id' => $id], '保存成功'));
    }

    /**
     * v2.79.2：重复人员检测 —— 按身份证号分组，返回存在多个人员档案的组。
     *
     * 查重范围全局（不做数据范围过滤之外的限制），但返回内容仍受当前账号数据范围裁剪；
     * 组内成员按 id 升序，附带人员列表同款装饰（证书数/企业/负责人）。
     */
    public function duplicates()
    {
        \app\service\IdCardSchemaService::ensure();
        // v2.99.59：按盲索引分组（身份证已密文落库，不能直接按 id_card 分组）
        $q = StaffModel::field('id_card_hash, COUNT(*) as cnt');
        $q = $this->applyStaffScope($q);
        $groups = $q->whereNotNull('id_card_hash')
            ->where('id_card_hash', '<>', '')
            ->group('id_card_hash')->having('cnt > 1')
            ->order('id_card_hash', 'asc')->select()->toArray();

        // 兼容未回填的历史明文行：hash 为空时仍按明文分组
        $legacyQ = StaffModel::field('id_card, COUNT(*) as cnt');
        $legacyQ = $this->applyStaffScope($legacyQ);
        $legacy = $legacyQ->whereNull('id_card_hash')->where('id_card', '<>', '')
            ->group('id_card')->having('cnt > 1')
            ->order('id_card', 'asc')->select()->toArray();

        $result = [];
        foreach ($groups as $g) {
            $hash = (string)$g['id_card_hash'];
            $mq = StaffModel::where('id_card_hash', $hash)->order('id', 'asc');
            $mq = $this->applyStaffScope($mq);
            $result[] = $this->buildDuplicateGroup($mq->select()->toArray());
        }
        foreach ($legacy as $g) {
            $mq = StaffModel::where('id_card', (string)$g['id_card'])->order('id', 'asc');
            $mq = $this->applyStaffScope($mq);
            $result[] = $this->buildDuplicateGroup($mq->select()->toArray());
        }

        $result = array_values(array_filter($result));
        return json(r_success(['groups' => $result]));
    }

    /**
     * 组装一个重复人员分组（解密 → 数据范围裁剪 → 装饰 → 强制掩码）。
     * 数据范围裁剪后不足 2 人时返回 null（当前账号下不构成重复）。
     */
    protected function buildDuplicateGroup(array $members): ?array
    {
        if (count($members) < 2) {
            return null;
        }
        \app\service\IdCardService::decodeRows($members);
        $plainIdCard = (string)($members[0]['id_card'] ?? '');
        self::decorate($members);
        CrmService::attachOwner($members, 'staff');
        $members = \app\service\MaskService::staffListFor($members, $this->request->user ?? []);
        return [
            'id_card' => \app\service\IdCardService::mask($plainIdCard),
            'count'   => count($members),
            'members' => $members,
        ];
    }

    /**
     * v2.79.2：合并重复人员 —— 把 remove_id 名下全部关联数据迁移到 keep_id 后删除 remove_id。
     *
     * 事务内迁移 certificate / cert_secondment / cert_register_track / staff_company_rel /
     * staff_attach / cert_custom_value(module=staff) / cert_follow_record / cert_owner_transfer /
     * credential_vault / gov_progress / alert_log / match_record；
     * 对唯一键冲突表（staff_company_rel、cert_custom_value、gov_progress、alert_log）逐行处理。
     */
    public function merge()
    {
        if (!$this->can('staff', 'delete')) {
            return json(r_error('暂无删除人员权限，无法执行合并', 403));
        }
        $keepId   = (int)$this->request->post('keep_id', 0);
        $removeId = (int)$this->request->post('remove_id', 0);
        if ($keepId <= 0 || $removeId <= 0 || $keepId === $removeId) {
            return json(r_error('请选择两个不同的人员进行合并', 400));
        }
        $keep   = StaffModel::find($keepId);
        $remove = StaffModel::find($removeId);
        if (!$keep || !$remove) {
            return json(r_error('人员不存在，请刷新后重试', 400));
        }
        $this->assertStaffAccess($keepId);
        $this->assertStaffAccess($removeId);
        $keepIdCard   = \app\service\IdCardService::decode($keep['id_card'] ?? '');
        $removeIdCard = \app\service\IdCardService::decode($remove['id_card'] ?? '');
        if ($keepIdCard === '' || $keepIdCard !== $removeIdCard) {
            return json(r_error('仅支持合并身份证号相同的重复人员', 400));
        }

        Db::startTrans();
        try {
            // 证书 / 借调 / 注册跟踪（均无唯一键冲突，直接改绑）
            Db::name('certificate')->where('staff_id', $removeId)->update(['staff_id' => $keepId]);
            Db::name('cert_secondment')->where('staff_id', $removeId)->update(['staff_id' => $keepId]);
            Db::name('cert_register_track')->where('staff_id', $removeId)->update(['staff_id' => $keepId]);

            // 企业服务关系（唯一键 staff_id + company_id）
            $rels = Db::name('staff_company_rel')->where('staff_id', $removeId)->select()->toArray();
            foreach ($rels as $rel) {
                $exists = Db::name('staff_company_rel')->where('staff_id', $keepId)->where('company_id', $rel['company_id'])->find();
                if ($exists) {
                    // 保留更早的入职/注册日，然后删除被合并方的重复关系
                    if (!empty($rel['entry_date'])
                        && (empty($exists['entry_date']) || $rel['entry_date'] < $exists['entry_date'])) {
                        Db::name('staff_company_rel')->where('id', $exists['id'])->update(['entry_date' => $rel['entry_date']]);
                    }
                    Db::name('staff_company_rel')->where('id', $rel['id'])->delete();
                } else {
                    Db::name('staff_company_rel')->where('id', $rel['id'])->update(['staff_id' => $keepId]);
                }
            }

            // 人员附件
            Db::name('staff_attach')->where('staff_id', $removeId)->update(['staff_id' => $keepId]);

            // 自定义字段值（module=staff，唯一键 module + record_id + field_id）
            $vals = Db::name('cert_custom_value')->where('module', 'staff')->where('record_id', $removeId)->select()->toArray();
            foreach ($vals as $v) {
                $exists = Db::name('cert_custom_value')->where('module', 'staff')->where('record_id', $keepId)->where('field_id', $v['field_id'])->find();
                if ($exists) {
                    // 保留方已有同字段值，丢弃被合并方的值（避免覆盖）
                    Db::name('cert_custom_value')->where('id', $v['id'])->delete();
                } else {
                    Db::name('cert_custom_value')->where('id', $v['id'])->update(['record_id' => $keepId]);
                }
            }

            // CRM 跟进 / 移交历史（module=staff）
            Db::name('cert_follow_record')->where('module', 'staff')->where('record_id', $removeId)->update(['record_id' => $keepId]);
            Db::name('cert_owner_transfer')->where('module', 'staff')->where('record_id', $removeId)->update(['record_id' => $keepId]);

            // 平台账号（无唯一键，直接改绑）
            Db::name('credential_vault')->where('staff_id', $removeId)->update(['staff_id' => $keepId]);

            // 政务进度（唯一键 project_no）
            $progress = Db::name('gov_progress')->where('staff_id', $removeId)->select()->toArray();
            foreach ($progress as $p) {
                $exists = Db::name('gov_progress')->where('project_no', $p['project_no'])->where('id', '<>', $p['id'])->find();
                if ($exists) {
                    Db::name('gov_progress')->where('id', $p['id'])->delete();
                } else {
                    Db::name('gov_progress')->where('id', $p['id'])->update(['staff_id' => $keepId]);
                }
            }

            // 预警（唯一键 cert_id + alert_node + alert_date：先删冲突再迁移）
            $dupAlerts = Db::name('alert_log')->alias('a')
                ->join('alert_log b', 'b.cert_id = a.cert_id AND b.alert_node = a.alert_node AND b.alert_date = a.alert_date AND b.staff_id = ' . $keepId)
                ->where('a.staff_id', $removeId)
                ->column('a.id');
            if ($dupAlerts) {
                Db::name('alert_log')->whereIn('id', $dupAlerts)->delete();
            }
            Db::name('alert_log')->where('staff_id', $removeId)->update(['staff_id' => $keepId]);

            // 智能匹配
            Db::name('match_record')->where('staff_id', $removeId)->update(['staff_id' => $keepId]);

            // 删除被合并人员
            StaffModel::destroy($removeId);

            // 失效查询缓存（合并后旧身份证/姓名键均失效）
            \app\service\Lookup::forgetStaff($removeIdCard ?: null, trim((string)($remove['staff_name'] ?? '')) ?: null);
            \app\service\Lookup::forgetStaff($keepIdCard ?: null, trim((string)($keep['staff_name'] ?? '')) ?: null);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return json(r_error('合并失败：' . $e->getMessage(), 400));
        }

        $this->log('持证人员', '合并', "#{$removeId} -> #{$keepId}（身份证 " . \app\service\IdCardService::mask($keepIdCard) . '）');
        return json(r_success(['keep_id' => $keepId, 'remove_id' => $removeId], '合并成功'));
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
        if (!$this->canAccessStaff($id)) {
            return ['ok' => false, 'msg' => '无权删除该人员档案'];
        }
        $busy = CertSecondment::where('staff_id', $id)->where('status', 1)->count();
        if ($busy) {
            return ['ok' => false, 'msg' => '该人员还有 ' . $busy . ' 本证书正在被借调，请先归还'];
        }
        // v2.38：删除前先取出键（删后就查不到了），用于精准失效查询缓存
        $old = StaffModel::where('id', $id)->find();
        // v2.52：删除前快照进回收站（关联关系 / 附件子表 / 自定义值随删，一并快照）
        try {
            if ($old) {
                \app\service\RecycleBinService::stash(
                    (int)$this->request->userId,
                    'staff',
                    (string)($old['staff_name'] ?? '') ?: ("#" . $id),
                    $old->toArray(),
                    array_filter([
                        ['table' => 'staff_company_rel', 'rows' => \app\service\RecycleBinService::grab('staff_company_rel', 'staff_id', $id)],
                        ['table' => 'staff_attach', 'rows' => \app\service\RecycleBinService::grab('staff_attach', 'staff_id', $id)],
                        \app\service\RecycleBinService::grabCustom('staff', $id),
                    ])
                );
            }
        } catch (\Throwable $e) {
            // 快照失败不阻断删除
        }
        CustomFieldService::deleteValues('staff', $id);
        StaffModel::destroy($id);
        // v2.54：级联清理该人员的跟进记录与移交历史
        CrmService::deleteByRecord('staff', $id);
        Db::table('staff_company_rel')->where('staff_id', $id)->delete();
        if ($old) {
            \app\service\Lookup::forgetStaff(
                \app\service\IdCardService::decode($old['id_card'] ?? '') ?: null,
                trim((string)($old['staff_name'] ?? '')) ?: null
            );
        }
        // v2.35：附件子表随人员删除
        try {
            Db::name('staff_attach')->where('staff_id', $id)->delete();
        } catch (\Throwable $e) {
            // 子表缺失忽略
        }
        $this->log('持证人员', '删除', "#{$id}");
        return ['ok' => true, 'msg' => '删除成功'];
    }

    // 人员-企业服务关系
    public function relSave()
    {
        $staffId   = (int)$this->request->post('staff_id', 0);
        $companyId = (int)$this->request->post('company_id', 0);
        $entryDate = $this->request->post('entry_date', null);
        if (!$staffId || !$companyId) {
            return json(r_error('参数错误', 400));
        }
        $this->assertStaffAccess($staffId);
        $this->assertCompanyAccess($companyId);
        $exists = Db::table('staff_company_rel')->where('staff_id', $staffId)->where('company_id', $companyId)->find();
        if ($exists) {
            Db::table('staff_company_rel')->where('id', $exists['id'])->update(['entry_date' => $entryDate]);
        } else {
            Db::table('staff_company_rel')->insert(['staff_id' => $staffId, 'company_id' => $companyId, 'entry_date' => $entryDate, 'create_time' => date('Y-m-d H:i:s')]);
        }
        return json(r_success([], '关联成功'));
    }

    public function relDelete()
    {
        $staffId   = (int)$this->request->post('staff_id', 0);
        $companyId = (int)$this->request->post('company_id', 0);
        if (!$staffId || !$companyId) {
            return json(r_error('参数错误', 400));
        }
        $this->assertStaffAccess($staffId);
        $this->assertCompanyAccess($companyId);
        Db::table('staff_company_rel')->where('staff_id', $staffId)->where('company_id', $companyId)->delete();
        return json(r_success([], '解除关联成功'));
    }

    // 可选项（人员下拉）
    public function options()
    {
        // 人员档案已去掉部门/岗位（统一由组织架构管理），下拉返回姓名/联系方式/负责人（v2.54 表单只读展示负责人）
        $q = StaffModel::where('status', 1)->field('id,staff_name,phone,id_card,owner_user_id');
        $q = $this->applyStaffScope($q);
        $list = $q->order('id', 'desc')->select()->toArray();
        // 下拉接口也遵循敏感字段脱敏规则（身份证永远掩码）
        \app\service\IdCardService::decodeRows($list);
        $list = \app\service\MaskService::staffListFor($list, $this->request->user ?? []);
        // v2.24 附带所属企业名称，便于费用等场景区分同名人员
        if ($list) {
            $ids = array_column($list, 'id');
            $rels = Db::table('staff_company_rel')->whereIn('staff_id', $ids)->select()->toArray();
            $cids = array_values(array_unique(array_column($rels, 'company_id')));
            $cmap = [];
            if ($cids) {
                // ★ 修复：column('id,company_name') 在 TP6 下返回 [id => [company_name]]（值为数组），
                // 后续 (string)$v 会抛 "Array to string conversion"。改用两参形式 [id => company_name 字符串]。
                foreach (Db::table('client_company')->whereIn('id', $cids)->column('company_name', 'id') as $k => $v) {
                    $cmap[(int)$k] = (string)$v;
                }
            }
            $smap = [];
            foreach ($rels as $r) {
                $sid = (int)$r['staff_id'];
                $cn  = $cmap[(int)$r['company_id']] ?? '';
                if ($cn !== '') {
                    $smap[$sid][] = $cn;
                }
            }
            foreach ($list as &$s) {
                $s['company_names'] = $smap[(int)$s['id']] ?? [];
            }
            unset($s);
        }
        // v2.54：附带负责人姓名（证书新建默认跟随持证人员负责人）
        CrmService::attachOwner($list, 'staff');
        return json(r_success($list));
    }

    // ---------- Excel 导入导出 ----------
    public function export()
    {
        // v2.99.49：敏感导出强制 OTP 二次鉴别（未绑定 TOTP 在前端 prepare 阶段即被拦截）
        $this->requireExportTicket('staff');
        \app\service\IdCardSchemaService::ensure();
        $q = StaffModel::order('id', 'desc');
        $q = $this->applyStaffScope($q);
        $rows = $q->select()->toArray();
        $tmp  = $rows;
        self::decorate($tmp);
        // v2.99.59：身份证是否输出明文由当前账号 staff:reveal 决定（不看全局脱敏开关）
        $user = $this->request->user ?? [];
        $plainExport = \app\service\IdCardService::canExportPlain($user);
        $headShot = [
            'staff_name'    => (string)($user['real_name'] ?? ($user['username'] ?? '')),
            'time'          => date('Y-m-d H:i:s'),
            'purpose'       => '人员档案导出（含完整身份证号，已留痕）',
        ];
        $formatted = array_map(function ($r) use ($user, $plainExport, $headShot) {
            $idCard = \app\service\IdCardService::decode($r['id_card'] ?? '');
            $row = [
                'staff_name'    => $r['staff_name'] ?? '',
                'id_card'       => $plainExport ? $idCard : \app\service\IdCardService::mask($idCard),
                'phone'         => $r['phone'] ?? '',
                'email'         => $r['email'] ?? '',
                'status_text'   => ($r['status'] ?? 1) == 1 ? '在职' : '离职',
                'company_names' => $r['company_names'] ?? '',
                'remark'        => $r['remark'] ?? '',
            ];
            if ($plainExport) {
                // 明文导出加「导出人 / 时间 / 用途」水印列，便于事后举证
                $row['export_by']      = $headShot['staff_name'];
                $row['export_time']    = $headShot['time'];
                $row['export_purpose'] = $headShot['purpose'];
            }
            return $row;
        }, $tmp);
        // 导出审计（模块级汇总一条，不逐行写）
        \app\service\IdCardService::auditExport($user, 'staff', '人员档案导出', count($formatted));
        $path = ExcelService::exportStaff($formatted, $plainExport ? [
            '导出人' => 'export_by',
            '导出时间' => 'export_time',
            '导出用途' => 'export_purpose',
        ] : []);
        return download_utf8($path, 'staff_' . date('Ymd') . '.xlsx');
    }

    public function template()
    {
        $path = ExcelService::template('staff');
        return download_utf8($path, '人员导入模板.xlsx');
    }

    /**
     * 关键词是完整身份证号时返回盲索引，否则空串。
     * 身份证已密文落库，片段搜索不再支持，只保留 18/15 位精确命中。
     */
    protected static function idCardHashOf(string $keyword): string
    {
        $id = \app\service\IdCardService::normalize($keyword);
        if (!preg_match('/^\d{15}$|^\d{17}[\dX]$/', $id)) {
            return '';
        }
        return \app\service\IdCardService::hash($id);
    }

    /**
     * 给人员列表补充：借调中的证书数、当前服务企业
     */
    protected static function decorate(array &$list): void
    {
        if (!$list) {
            return;
        }
        $ids = array_column($list, 'id');
        // 借调中的证书数
        $secRows = Db::name('cert_secondment')->alias('s')
            ->join('client_company c', 'c.id = s.company_id')
            ->whereIn('s.staff_id', $ids)->where('s.status', 1)
            ->field('s.staff_id, s.cert_id, c.company_name')
            ->select()->toArray();
        $companies = [];
        $certCount = [];
        foreach ($secRows as $r) {
            $sid = (int)$r['staff_id'];
            $companies[$sid][] = $r['company_name'];
            $certCount[$sid][] = $r['cert_id'];
        }
        // 证书总数
        $certRows = Db::name('certificate')->whereIn('staff_id', $ids)
            ->field('staff_id, count(*) as cnt')->group('staff_id')->select()->toArray();
        $totalMap = [];
        foreach ($certRows as $r) {
            $totalMap[(int)$r['staff_id']] = (int)$r['cnt'];
        }
        // 持有证书名称（与一键查询中心一致：取前 5 个，以 、 连接）
        $certNameRows = Db::name('certificate')->whereIn('staff_id', $ids)
            ->field('staff_id, cert_name')->select()->toArray();
        $certNameMap = [];
        foreach ($certNameRows as $r) {
            $certNameMap[(int)$r['staff_id']][] = $r['cert_name'];
        }
        // 归属企业（人档主服务企业，取 staff_company_rel 第一条）
        $ownerRows = Db::name('staff_company_rel')->alias('r')
            ->leftJoin('client_company c', 'c.id = r.company_id')
            ->whereIn('r.staff_id', $ids)
            ->field('r.staff_id, c.company_name')
            ->order('r.id', 'asc')->select()->toArray();
        $ownerMap = [];
        foreach ($ownerRows as $r) {
            $sid = (int)$r['staff_id'];
            if (!isset($ownerMap[$sid]) && !empty($r['company_name'])) {
                $ownerMap[$sid] = $r['company_name'];
            }
        }
        foreach ($list as &$item) {
            $sid = (int)$item['id'];
            $names = array_values(array_unique($companies[$sid] ?? []));
            $item['company_names']  = implode('、', $names);
            $item['owner_company']  = $ownerMap[$sid] ?? '';
            $item['cert_total']     = $totalMap[$sid] ?? 0;
            $item['cert_on_loan']   = count(array_unique($certCount[$sid] ?? []));
            $ownCerts = array_values(array_unique(array_filter($certNameMap[$sid] ?? [])));
            $item['cert_count']     = count($ownCerts);
            $item['cert_names']     = implode('、', array_slice($ownCerts, 0, 5));
        }
        unset($item);
    }

    // ---------- v2.87：身份证 / 毕业证 OCR（人员档案 + 快捷新建复用） ----------

    /**
     * 身份证 OCR 识别（腾讯云），回填姓名/身份证号/性别/生日/民族/住址。
     * 传图：multipart file / base64 image / 已上传 file_path。
     */
    public function ocrIdcard()
    {
        if (!$this->can('staff', 'create')) {
            return json(r_error('无使用权限', 403));
        }
        \app\service\PersonProfileService::ensureStaffColumns();
        $base64 = $this->readOcrImageBase64();
        if ($base64 === '') {
            return json(r_error('请上传或传入身份证图片', 400));
        }
        $r = \app\service\ExamOcrService::idCard($base64, 'FRONT');
        if (empty($r['ok'])) {
            return json(r_error($r['msg'] ?? '识别失败'));
        }
        $this->log('持证人员', 'OCR身份证识别', '新建人员');
        return json(r_success($r['data'] ?? [], '识别成功'));
    }

    /**
     * 毕业证 OCR 识别（腾讯云通用印刷体高精度 + 服务端解析），
     * 回填毕业学校 / 毕业证编号（电子注册号）/ 毕业日期。
     */
    public function ocrDiploma()
    {
        if (!$this->can('staff', 'create')) {
            return json(r_error('无使用权限', 403));
        }
        \app\service\PersonProfileService::ensureStaffColumns();
        $base64 = $this->readOcrImageBase64();
        if ($base64 === '') {
            return json(r_error('请上传或传入毕业证图片', 400));
        }
        $r = \app\service\ExamOcrService::diploma($base64);
        if (empty($r['ok'])) {
            return json(r_error($r['msg'] ?? '识别失败'));
        }
        $this->log('持证人员', 'OCR毕业证识别', '新建人员');
        return json(r_success($r['data'] ?? [], '识别成功'));
    }

    /** 读取 OCR 图片为 base64（file / image / file_path），失败返回空串 */
    private function readOcrImageBase64(): string
    {
        $file = $this->request->file('file');
        if ($file) {
            $allow = ['jpg', 'jpeg', 'png', 'webp', 'bmp'];
            $ext = strtolower((string)$file->getOriginalExtension());
            if ($ext === '') {
                $ext = strtolower(pathinfo((string)$file->getOriginalName(), PATHINFO_EXTENSION));
            }
            if (!in_array($ext, $allow, true) || (int)$file->getSize() > 7 * 1024 * 1024) {
                return '';
            }
            return base64_encode((string)file_get_contents($file->getPathname()));
        }
        $image = (string)$this->request->param('image', '');
        if ($image !== '') {
            return $image;
        }
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
