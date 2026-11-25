<?php
namespace app\controller\admin;

use app\model\Certificate as CertificateModel;
use app\model\ClientCompany;
use app\model\Staff;
use app\model\CertSecondment;
use app\model\CertStatusLog;
use app\service\ExcelService;
use app\service\SecondmentService;
use app\service\CustomFieldService;
use app\service\CrmService;
use app\service\HnPersonCollectService;
use app\service\MemPersonCollectService;
use think\facade\Db;

class Certificate extends AdminController
{
    /** 社保状态文本 -> 值（导入时用） */
    public const SOCIAL_MAP = ['未知' => 0, '在缴' => 1, '停缴' => 2, '未缴纳' => 3];

    public function index()
    {
        [$page, $limit] = $this->pageParams();
        $companyId = (int)$this->request->param('company_id', 0);
        $staffName = $this->request->param('staff_name', '');
        $certName  = $this->request->param('cert_name', '');
        $status    = $this->request->param('cert_status', '');
        $category  = $this->request->param('category', '');
        $keyword   = trim((string)$this->request->param('keyword', '')); // 关键字：证书专业 / 类别
        // 快捷关键字：一键跨 企业名称 / 姓名 / 身份证号 / 手机号 / 证书编号 模糊查询
        $quickKw   = trim((string)$this->request->param('quick_kw', ''));
        $social    = $this->request->param('social_status', ''); // 社保状态
        $onlyFree  = (int)$this->request->param('only_free', 0); // 只看空闲（未被借调）证书
        $soonDays  = (int)$this->request->param('expire_soon', 0); // N 天内到期
        $urgent    = (int)$this->request->param('urgent', 0); // 只看紧急证书
        // v2.54：负责人筛选（owner_user_id 指定某人；mine=1 只看自己负责的）
        $ownerUserId = (int)$this->request->param('owner_user_id', 0);
        $mine        = (int)$this->request->param('mine', 0);
        // v2.99：表头排序（白名单字段，防止把请求参数直接拼进 SQL）
        $sortBy    = (string)$this->request->param('sort_by', '');
        $sortOrder = strtolower((string)$this->request->param('sort_order', '')) === 'desc' ? 'desc' : 'asc';
        $sortable  = ['expire_date' => true, 'cert_status' => true, 'cert_fee' => true];

        CrmService::ensureSchema();
        // v2.99.59：身份证号密文落库，兜底补齐 id_card_hash（老库运行时自愈）
        \app\service\IdCardSchemaService::ensure();
        $q = CertificateModel::with(['staff']);
        if (isset($sortable[$sortBy])) {
            // 长期有效（expire_date IS NULL）始终排在最后，不受升降序影响
            if ($sortBy === 'expire_date') {
                $q->orderRaw('expire_date IS NULL ASC, expire_date ' . $sortOrder);
            } else {
                $q->order($sortBy, $sortOrder);
            }
        } else {
            // v2.97：默认长期有效（expire_date IS NULL）排在最后，避免空值占列表首位
            $q->orderRaw('expire_date IS NULL ASC, expire_date ASC');
        }
        $q = $this->scopeCert($q);

        if ($mine) {
            $q = \app\service\ResourceOwnerService::scopeByOwner($q, 'certificate', (int)$this->request->userId, 'id');
        } elseif ($ownerUserId > 0) {
            $q = \app\service\ResourceOwnerService::scopeByOwner($q, 'certificate', $ownerUserId, 'id');
        }
        // v2.99.37：公海筛选（is_pool=1 公海 / 0 已分配；不传=全部）
        $isPool = $this->request->param('is_pool', '');
        if ($isPool !== '' && $isPool !== null) {
            $q->where('is_pool', (int)$isPool);
        }

        if ($companyId) {
            $certIds = SecondmentService::certIdsOf([$companyId], 1);
            $q->whereIn('id', $certIds ?: [0]);
        }
        if ($onlyFree) {
            $busyIds = Db::name('cert_secondment')->where('status', 1)->column('cert_id');
            $q->whereNotIn('id', $busyIds ?: [0]);
        }
        if ($staffName) {
            // 按持证人员姓名模糊筛选。
            // 注意：think-orm 2.x 没有 whereHas（Laravel 风格 API），调用它会被
            // __call 当成字段名 → "where express error:STAFF"。改用 EXISTS 子查询，
            // 由数据库完成关联过滤，比「先查 id 再 whereIn」少一次往返且不占用 IN 长度。
            // 表名取自连接配置，兼容自定义 PREFIX 的部署。
            $certTbl = Db::name('certificate')->getTable();
            $staffTbl = Db::name('staff')->getTable();
            $q->whereExists(function ($sub) use ($staffName, $certTbl, $staffTbl) {
                $sub->name('staff')
                    ->whereColumn($staffTbl . '.id', '=', $certTbl . '.staff_id')
                    ->where('staff_name', 'like', "%{$staffName}%");
            });
        }
        // v2.35：按持证人员精确筛选（左侧「人员姓名导航」）
        $staffId = (int)$this->request->param('staff_id', 0);
        if ($staffId) {
            $q->where('staff_id', $staffId);
        }
        if ($certName) {
            $q->where('cert_name', 'like', "%{$certName}%");
        }
        if ($status !== '') {
            $q->where('cert_status', $status);
        }
        if ($category) {
            // v2.36：类别维度已统一为证书类型，两列都认，避免老链接/旧筛选条件失配
            $q->where(function ($w) use ($category) {
                $w->where('category', $category)->whereOr('cert_type', $category);
            });
        }
        // v2.33：证书类型筛选（二级字典 cert_major 一级）
        $certType = trim((string)$this->request->param('cert_type', ''));
        if ($certType !== '') {
            $q->where(function ($w) use ($certType) {
                $w->where('cert_type', $certType)->whereOr('category', $certType);
            });
        }
        if ($keyword) {
            $q->where(function ($qq) use ($keyword) {
                $qq->where('cert_name', 'like', "%{$keyword}%")
                   ->whereOr('category', 'like', "%{$keyword}%")
                   ->whereOr('cert_type', 'like', "%{$keyword}%");
            });
        }
        // 快捷关键字：跨 5 个字段 OR 匹配（证书编号本表；姓名/身份证/手机号在 staff；企业名称经生效借调关联）
        if ($quickKw !== '') {
            // 转义 LIKE 通配符，避免用户输入 %/_ 被当成通配符
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $quickKw) . '%';
            // v2.99.59：身份证密文落库，仅完整号码可按盲索引精确命中
            $idHash = '';
            $idCardNorm = \app\service\IdCardService::normalize($quickKw);
            if (preg_match('/^\d{15}$|^\d{17}[\dX]$/', $idCardNorm)) {
                $idHash = \app\service\IdCardService::hash($idCardNorm);
            }
            $certTbl  = Db::name('certificate')->getTable();
            $staffTbl = Db::name('staff')->getTable();
            // 企业名称：先按名称模糊命中企业，再取这些企业生效中借调的证书
            $companyIds = ClientCompany::where('company_name', 'like', $like)->column('id');
            $companyCertIds = $companyIds
                ? Db::name('cert_secondment')->where('status', 1)->whereIn('company_id', $companyIds)->column('cert_id')
                : [];
            $q->where(function ($qq) use ($like, $certTbl, $staffTbl, $companyCertIds, $idHash, $idCardNorm) {
                // 证书编号（本表）
                $qq->whereOr($certTbl . '.cert_no', 'like', $like);
                // 姓名 / 手机号 / 身份证（staff 关联表，EXISTS 子查询内部 OR）
                $qq->whereOr(function ($w) use ($like, $certTbl, $staffTbl, $idHash, $idCardNorm) {
                    $w->whereExists(function ($sub) use ($like, $certTbl, $staffTbl, $idHash, $idCardNorm) {
                        $sub->name('staff')
                            ->whereColumn($staffTbl . '.id', '=', $certTbl . '.staff_id')
                            ->where(function ($sw) use ($like, $idHash, $idCardNorm) {
                                $sw->where('staff_name', 'like', $like)
                                   ->whereOr('phone', 'like', $like);
                                if ($idHash !== '') {
                                    $sw->whereOr('id_card_hash', $idHash);
                                }
                                // 兼容加密前的明文历史数据
                                if ($idCardNorm !== '') {
                                    $sw->whereOr('id_card', $idCardNorm);
                                }
                            });
                    });
                });
                // 企业名称（生效中借调关联的客户企业）
                if ($companyCertIds) {
                    $qq->whereOr($certTbl . '.id', 'in', array_map('intval', $companyCertIds));
                }
            });
        }
        if ($social !== '') {
            $q->where('social_status', $social);
        }
        if ($soonDays > 0) {
            $q->whereBetween('expire_date', [date('Y-m-d'), date('Y-m-d', strtotime("+{$soonDays} days"))]);
        }
        if ($urgent) {
            $q->where('priority', '紧急');
        }
        $total = $q->count();
        $list  = $q->page($page, $limit)->select()->toArray();
        SecondmentService::attach($list);
        // v2.54：负责人 / 创建人姓名 + 最近跟进时间
        CrmService::attachOwner($list, 'certificate');
        \app\service\ResourceOwnerService::attachOwners($list, 'certificate');
        $lastMap = CrmService::lastFollowMap('certificate', array_column($list, 'id'));
        foreach ($list as &$it) {
            $it['last_follow_time'] = $lastMap[(int)$it['id']] ?? '';
        }
        unset($it);
        // 顶层冗余人员字段：前端（PC / H5）直接取 staff_name，不必逐级取 staff.staff_name
        foreach ($list as &$it) {
            $it['staff_name']  = $it['staff']['staff_name'] ?? '';
            $it['staff_phone'] = $it['staff']['phone'] ?? '';
            // 盲索引只在服务端查重用，不能出口给前端
            if (isset($it['staff']) && is_array($it['staff'])) {
                unset($it['staff']['id_card_hash']);
            }
        }
        unset($it);
        CustomFieldService::attachList('certificate', $list);
        // 敏感字段脱敏（证书编号 / 顶层冗余的持证人电话），受 mask_sensitive 开关控制
        $list = \app\service\MaskService::certListFor($list, $this->request->user ?? []);
        foreach ($list as &$it) {
            $it['staff_phone'] = \app\service\MaskService::phone($it['staff_phone'] ?? '');
        }
        unset($it);
        return json(r_success(['list' => $list, 'total' => $total]));
    }

    /** 证书详情：基本信息 + 借调历史 + 续期记录 */
    public function detail()
    {
        $id   = (int)$this->request->param('id', 0);
        $cert = CertificateModel::with(['staff'])->find($id);
        if (!$cert) {
            return json(r_error('证书不存在', 400));
        }
        $this->assertCertAccess($id);
        CrmService::ensureSchema();
        $data = $cert->toArray();
        $tmp  = [$data];
        SecondmentService::attach($tmp);
        $data = $tmp[0];
        // v2.54：负责人 / 创建人姓名
        CrmService::attachOwner($data, 'certificate');
        // 客户企业账号只能看到本企业的借调历史，避免通过证书详情看到其他客户。
        $historyQ = CertSecondment::with(['company'])->where('cert_id', $id);
        $this->restrictSecondmentQuery($historyQ);
        $data['secondment_history'] = $historyQ->order('id', 'desc')->select()->toArray();
        // v2.56：详情「借调记录」Tab 直接展示状态/剩余天数
        foreach ($data['secondment_history'] as &$h) {
            $h['status_text'] = SecondmentService::statusText((int)($h['status'] ?? 0));
            $h['remain_days']  = SecondmentService::remainDays((string)($h['end_date'] ?? ''));
        }
        unset($h);
        $data['renewals'] = Db::name('cert_renewal')->where('cert_id', $id)
            ->order('id', 'desc')->select()->toArray();
        $data['register_tracks'] = Db::name('cert_register_track')->where('cert_id', $id)
            ->order('register_date', 'desc')->order('id', 'desc')->select()->toArray();
        // ★ v2.32 多附件：子表优先，历史只attach_path → 虚拟记录兜底（保证旧数据仍可预览）
        try {
            $data['attachments'] = \app\service\CertAttachService::listByCert($id);
        } catch (\Throwable $e) {
            $data['attachments'] = [];
        }
        // 顶层冗余人员字段（H5 通过推送详情链接直接打开时也要能显示人员）
        // v2.99.59：staff.id_card 密文落库，先解密再交给掩码/权限处理
        if (!empty($data['staff'])) {
            $data['staff'] = \app\service\IdCardService::decodeRow($data['staff']);
        }
        $data['staff_name']     = $data['staff']['staff_name'] ?? '';
        $data['staff_phone']    = $data['staff']['phone'] ?? '';
        $data['staff_id_card']  = $data['staff']['id_card'] ?? '';
        CustomFieldService::attachOne('certificate', $data);
        // 敏感字段脱敏（证书编号 / 身份证 / 手机号）——v2.99.51：按字段级矩阵逐字段处理
        $user = $this->request->user ?? [];
        $data = \app\service\MaskService::cert($data, $user);
        $data['staff_name']    = \app\service\MaskService::fieldValue('staff.name', $data['staff_name'] ?? '', $user);
        $data['staff_phone']   = \app\service\MaskService::fieldValue('staff.phone', $data['staff_phone'] ?? '', $user);
        $data['staff_id_card'] = \app\service\MaskService::fieldValue('staff.id_card', $data['staff_id_card'] ?? '', $user);
        if (!empty($data['staff'])) {
            $data['staff'] = \app\service\MaskService::staff($data['staff'], false, $user);
        }
        return json(r_success($data));
    }

    /**
     * 编辑用明文读取：列表/详情按脱敏策略返回打码值，但「编辑」需要回填真实证书编号。
     * 仅管理员可获取明文（与 Staff::read 一致）；非管理员维持脱敏输出，前端清空后由用户按需填写。
     * 返回结构兼容 detail（含 custom/attachments/负责人），供前端 openEdit 回填。
     */
    public function read()
    {
        $id   = (int)$this->request->param('id', 0);
        $cert = CertificateModel::with(['staff'])->find($id);
        if (!$cert) {
            return json(r_error('证书不存在', 400));
        }
        $this->assertCertAccess($id);
        CrmService::ensureSchema();
        $data = $cert->toArray();
        $tmp  = [$data];
        SecondmentService::attach($tmp);
        $data = $tmp[0];
        // v2.54：负责人 / 创建人姓名
        CrmService::attachOwner($data, 'certificate');
        // ★ v2.32 多附件：子表优先，历史只attach_path → 虚拟记录兜底（保证旧数据仍可预览）
        try {
            $data['attachments'] = \app\service\CertAttachService::listByCert($id);
        } catch (\Throwable $e) {
            $data['attachments'] = [];
        }
        // 顶层冗余人员字段（编辑回显持证人）
        // v2.99.59：staff.id_card 密文落库，先解密再交给掩码/权限处理
        if (!empty($data['staff'])) {
            $data['staff'] = \app\service\IdCardService::decodeRow($data['staff']);
        }
        $data['staff_name']     = $data['staff']['staff_name'] ?? '';
        $data['staff_phone']    = $data['staff']['phone'] ?? '';
        $data['staff_id_card']  = $data['staff']['id_card'] ?? '';
        CustomFieldService::attachOne('certificate', $data);
        // v2.99.51：编辑回填默认明文，但矩阵显式配置 mask/hide 时按配置处理
        $user = $this->request->user ?? [];
        if (!$this->isAdmin()) {
            $data = \app\service\MaskService::cert($data, $user);
            $data['staff_phone']   = \app\service\MaskService::fieldValue('staff.phone', $data['staff_phone'] ?? '', $user);
            $data['staff_id_card'] = \app\service\MaskService::fieldValue('staff.id_card', $data['staff_id_card'] ?? '', $user);
            if (!empty($data['staff'])) {
                $data['staff'] = \app\service\MaskService::staff($data['staff'], false, $user);
            }
        } else {
            $data = \app\service\MaskService::certEdit($data, $user);
            $data['staff_name']    = \app\service\MaskService::editValue('staff.name', $data['staff_name'] ?? '', $user);
            $data['staff_phone']   = \app\service\MaskService::editValue('staff.phone', $data['staff_phone'] ?? '', $user);
            $data['staff_id_card'] = \app\service\MaskService::editValue('staff.id_card', $data['staff_id_card'] ?? '', $user);
            if (!empty($data['staff'])) {
                $data['staff'] = \app\service\MaskService::staffEdit($data['staff'], $user);
            }
        }
        return json(r_success($data));
    }

    /**
     * v2.99.38：新增前查重预检（不写库）。命中只返回最小信息 + 命中字段，前端据此提示「关联并共有」。
     */
    public function dedupCheck()
    {
        $data = $this->request->post(['staff_id', 'cert_no', 'cert_name']);
        $dup = \app\service\DuplicateResolver::certificate($data);
        $item = null;
        if ($dup) {
            $item = \app\service\DuplicateResolver::brief('certificate', $dup['row']);
            $item['fields'] = $dup['fields'];
        }
        return json(r_success([
            'hit'  => $dup ? 1 : 0,
            'item' => $item,
        ]));
    }

    public function save()
    {
        // 轻量功能权限：新增/编辑分开校验（v2.14）
        $id = (int)$this->request->post('id', 0);
        if (!$this->can('cert', $id ? 'edit' : 'create')) {
            return json(r_error('暂无' . ($id ? '编辑' : '新增') . '证照权限，请联系管理员', 403));
        }
        CrmService::ensureSchema();
        if ($id) {
            $this->assertCertAccess($id);
        }
        $data = $this->request->post([
            'staff_id', 'category', 'cert_name', 'cert_no', 'cert_type',
            'issue_date', 'expire_date', 'issue_org', 'attach_path', 'enable_alert',
            'alert_rules', 'custom_alert_days', 'priority', 'register_start_date',
            'register_end_date', 'cert_fee', 'fee_remark', 'cert_status',
            'social_status', 'social_company', 'social_start_month',
        ]);
        // ★ v2.32 约定 C3：attach_path 一律由 attachments[0] 派生，禁止直接采信前端
        unset($data['attach_path']);
        $attachments = $this->request->post('attachments/a', []);
        if (!is_array($attachments)) {
            $attachments = [];
        }
        // 附件数量 + 类型/大小校验（后端为准，前端预校验仅为体验）
        $attCheck = \app\service\CertAttachService::validateAll($attachments);
        if (!$attCheck['ok']) {
            return json(r_error($attCheck['msg'], 422));
        }
        // v2.36 字典去重：证书类别(cert_category)与证书类型(cert_major 一级)语义重复，
        // 录入端已统一为「类型→专业」级联，类别不再单独录入。此处以类型为准自动同步，
        // 保证两列一致（筛选/匹配任一列都能命中）；类型为空时保留原类别值不动（存量兼容）。
        $certTypeVal = trim((string)($data['cert_type'] ?? ''));
        if ($certTypeVal !== '') {
            $data['category'] = $certTypeVal;
        }
        // v2.78：类型/专业组合校验。专业若已登记在字典中，必须归属所选类型；
        // 类型为空且专业归属唯一时自动补全，避免「专业挂错类型」的脏数据。
        // v2.99.79：建造师证书支持一本证书多个专业，逐项校验并统一为「、」连接。
        $pair = \app\service\CertDictService::normalizePairMulti($certTypeVal, (string)($data['cert_name'] ?? ''));
        if ($pair['error'] !== '') {
            return json(r_error($pair['error'], 400));
        }
        if ($pair['name'] !== '') {
            $data['cert_name'] = $pair['name'];
        }
        if ($pair['type'] !== '') {
            $data['cert_type'] = $pair['type'];
            $data['category']  = $pair['type'];
        }
        $data['social_status']      = (int)($data['social_status'] ?? 0);
        $data['social_company']     = (string)($data['social_company'] ?? '');
        $data['social_start_month'] = (string)($data['social_start_month'] ?? '');
        // v2.96：新增证书时可按前台确认强制保存（同人员同证书编号查重后由用户决定）
        $force = (int)$this->request->post('force', 0) === 1;
        // 脱敏回写防护：列表打码值（含 *）不得覆盖库内明文
        \app\service\MaskService::guardMaskedEcho($data, ['cert_no']);
        // 日期控件未选值会传 ''，MySQL 严格模式下 date 列不接受空串 -> 转 null
        $data = $this->emptyToNull($data, ['issue_date', 'expire_date', 'register_start_date', 'register_end_date']);
        // v2.97：证书有效期支持「长期」——勾选后到期日置空（NULL），与借调/企业资质口径一致
        $longTerm = (int)$this->request->post('long_term', 0) === 1;
        if ($longTerm) {
            $data['expire_date'] = null;
        }
        $required = ['staff_id', 'cert_name'];
        if (!$id) {
            $required[] = 'cert_no'; // 新增时证书编号必填；编辑时留空表示不修改
        }
        foreach ($required as $req) {
            if (empty($data[$req])) {
                return json(r_error('必填项缺失：' . $req, 400));
            }
        }
        if (!$longTerm && empty($data['expire_date'])) {
            return json(r_error('请选择到期日期，或勾选「长期有效」', 400));
        }
        // 防止把证书挂到无权访问的人员名下（新增、编辑都校验目标人员）。
        $this->assertStaffAccess((int)$data['staff_id']);
        // v2.96：新增时按「人员 + 证书编号」及「人员 + 证书专业」双重查重。
        // 同一人员重复录入会导致台账、到期提醒和借调数据重复；命中后由用户确认是否强制保存。
        if (!$id && !$force) {
            $certNo   = trim((string)($data['cert_no'] ?? ''));
            $certName = trim((string)($data['cert_name'] ?? ''));
            $dupByNo = CertificateModel::where('staff_id', (int)$data['staff_id'])
                ->where('cert_no', $certNo)
                ->order('id', 'desc')
                ->find();
            // v2.99.79：多专业证书按「专业集合」比较，顺序不同也视为重复
            $dupByName = null;
            if ($certName !== '') {
                $nameRows = CertificateModel::where('staff_id', (int)$data['staff_id'])
                    ->where('cert_name', '<>', '')
                    ->order('id', 'desc')
                    ->select();
                foreach ($nameRows as $nameRow) {
                    if (\app\service\CertDictService::sameNames((string)$nameRow['cert_name'], $certName)) {
                        $dupByName = $nameRow;
                        break;
                    }
                }
            }
            if ($dupByNo || $dupByName) {
                $dupCert = $dupByNo ?: $dupByName;
                $fields = [];
                if ($dupByNo) {
                    $fields[] = 'cert_no';
                }
                if ($dupByName) {
                    $fields[] = 'cert_name';
                }
                $reason = count($fields) > 1
                    ? '证书编号和证书专业均与该人员名下已有证书重复'
                    : ($dupByNo ? '证书编号与该人员名下已有证书重复' : '证书专业与该人员名下已有证书重复');
                return json(r_error($reason . '，请确认是否关联到该证书并共有', 409, [
                    'duplicate_cert' => [
                        'id'          => (int)$dupCert['id'],
                        'cert_name'   => (string)($dupCert['cert_name'] ?? ''),
                        'cert_type'   => (string)($dupCert['cert_type'] ?? ''),
                        'cert_no'     => \app\service\MaskService::certNo((string)($dupCert['cert_no'] ?? '')),
                        'expire_date' => (string)($dupCert['expire_date'] ?? ''),
                        'fields'      => $fields,
                    ],
                ]));
            }
        }
        // v2.99.38：用户确认后（force=1）命中存量 → 复用存量 + 原所有人/当前操作人共有，不再新建重复记录
        if (!$id && $force) {
            $dupInfo = \app\service\DuplicateResolver::certificate($data);
            if ($dupInfo) {
                $existId = (int)$dupInfo['row']['id'];
                $share = \app\service\DuplicateResolver::share('certificate', $existId, (int)$this->request->userId);
                $this->log('证照台账', '查重共有', "#{$existId} 命中存量，共有人数 {$share['owners']}");
                return json(r_success(['id' => $existId, 'duplicated' => true], '检测到已存在该证书，已自动关联为共同所有人'));
            }
        }
        if (is_array($data['alert_rules'] ?? null)) {
            $data['alert_rules'] = json_encode($data['alert_rules'], JSON_UNESCAPED_UNICODE);
        }
        // 自定义字段必填校验
        $customErr = CustomFieldService::validate('certificate', $this->request->post('custom'));
        if ($customErr !== '') {
            return json(r_error($customErr, 400));
        }
        if ($id) {
            // v2.54：负责人变更统一走「移交」功能，编辑表单不再允许指定负责人
            CertificateModel::where('id', $id)->update($data);
            $this->log('证照台账', '更新', "#{$id}");
        } else {
            $data['create_by'] = (int)$this->request->userId; // self 数据范围依据（v2.14）
            // v2.54：新建默认负责人 = 持证人员的负责人（证照与人员绑定）；人员未分配时归当前用户
            $staffOwner = (int)(Db::name('staff')->where('id', (int)$data['staff_id'])->value('owner_user_id') ?: 0);
            $data['owner_user_id'] = $staffOwner > 0 ? $staffOwner : (int)$this->request->userId;
            // v2.55.1 防重复提交：新增时若 5 分钟内已存在「同人员+同专业+同编号+同到期日」记录，
            // 视为重复提交（按钮连点/浏览器重放/代理重试），直接复用已有记录，避免生成两条重复信息。
            // 说明：仅作用于手动新增（导入走 ImportService 直插，不受影响，仍允许编号不唯一）。
            $dup = CertificateModel::where('staff_id', (int)$data['staff_id'])
                ->where('cert_name', trim((string)$data['cert_name']))
                ->where('cert_no', trim((string)$data['cert_no']));
            if (!empty($data['expire_date'])) {
                $dup->where('expire_date', (string)$data['expire_date']);
            } else {
                $dup->whereNull('expire_date');
            }
            $dup = $dup->where('create_time', '>=', date('Y-m-d H:i:s', time() - 300))
                ->order('id', 'desc')
                ->find();
            if ($dup) {
                // v2.99.38：并发/重复提交统一转「共有」，他人提交的同一证书也会把当前账号加为共同所有人
                \app\service\DuplicateResolver::share('certificate', (int)$dup->id, (int)$this->request->userId, '重复提交自动共有');
                $this->log('证照台账', '新增去重', "#{$dup->id} 短时间存在相同记录，转为共有");
                return json(r_success(['id' => (int)$dup->id, 'duplicated' => true], '检测到已存在该证书，已自动关联为共同所有人'));
            }
            $cert = CertificateModel::create($data);
            $id   = $cert->id;
            \app\service\ResourceOwnerService::addOwner('certificate', (int)$id, (int)$data['owner_user_id'], 'assign', (int)$this->request->userId, true);
            $this->log('证照台账', '新增', "#{$id} 负责人 #{$data['owner_user_id']}");
        }
        // ★ v2.32 附件子表全量覆盖（DELETE→INSERT→同步 attach_path=第一条）
        try {
            \app\service\CertAttachService::replace((int)$id, $attachments, (int)$this->request->userId);
        } catch (\Throwable $e) {
            // 附件子表若缺列等异常不阻断主保存
        }
        // 同步冗余人员到借调记录
        if ($id) {
            CertSecondment::where('cert_id', $id)->update(['staff_id' => (int)$data['staff_id']]);
            CustomFieldService::saveValues('certificate', (int)$id, $this->request->post('custom'));
        }
        return json(r_success(['id' => $id], '保存成功'));
    }

    /**
     * v2.50：重命名单个证书附件（只改显示名，不动文件与下载地址）。
     * attach_id=0 为历史虚拟附件（子表空 + 证书 attach_path），改名即物化进子表，
     * 此后证书附件走子表管理，与全量覆盖语义自然衔接。
     */
    public function attachRename()
    {
        if (!$this->can('cert', 'edit')) {
            return json(r_error('暂无编辑证照权限，请联系管理员', 403));
        }
        $id      = (int)$this->request->post('id', 0);
        $attachId = (int)$this->request->post('attach_id', 0);
        $name    = trim((string)$this->request->post('attach_name', ''));
        if ($id <= 0) {
            return json(r_error('参数错误', 400));
        }
        if ($name === '') {
            return json(r_error('附件名称不能为空', 400));
        }
        $name = mb_substr($name, 0, 128);
        $this->assertCertAccess($id);
        try {
            if ($attachId > 0) {
                $row = Db::name('certificate_attach')->where('id', $attachId)->where('cert_id', $id)->find();
                if (!$row) {
                    return json(r_error('附件不存在', 404));
                }
                Db::name('certificate_attach')->where('id', $attachId)->update(['attach_name' => $name]);
            } else {
                // 虚拟记录：物化进子表（ext/size 从路径推导），此后走子表
                $cert = Db::name('certificate')->where('id', $id)->field('attach_path')->find();
                $path = trim((string)($cert['attach_path'] ?? ''));
                if ($path === '') {
                    return json(r_error('附件不存在', 404));
                }
                $ext = strtolower((string)pathinfo(parse_url($path, PHP_URL_PATH) ?: $path, PATHINFO_EXTENSION));
                Db::name('certificate_attach')->insert([
                    'cert_id'     => $id,
                    'attach_name' => $name,
                    'attach_path' => $path,
                    'file_ext'    => substr($ext, 0, 16),
                    'file_size'   => 0,
                    'sort'        => 0,
                    'create_by'   => (int)$this->request->userId,
                    'create_time' => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Throwable $e) {
            return json(r_error('附件重命名失败：' . $e->getMessage(), 400));
        }
        $this->log('证照台账', '附件改名', "证书 #{$id} 附件 #{$attachId} → {$name}");
        return json(r_success([
            'attachments' => \app\service\CertAttachService::listByCert($id),
        ], '附件已重命名'));
    }

    public function delete()
    {
        if (!$this->can('cert', 'delete')) {
            return json(r_error('暂无删除证照权限，请联系管理员', 403));
        }
        $id = (int)$this->request->post('id', 0);
        if (!$id) {
            return json(r_error('参数错误', 400));
        }
        $r = $this->deleteOne($id);
        return $r['ok'] ? json(r_success([], '删除成功')) : json(r_error($r['msg'], 400));
    }

    protected function deletePerm(): ?array
    {
        return ['cert', 'delete'];
    }

    /** 单条删除核心逻辑（delete 与 batchDelete 共用） */
    protected function deleteOne(int $id): array
    {
        if (!$this->canAccessCert($id)) {
            return ['ok' => false, 'msg' => '无权删除该证书'];
        }
        // v2.52：删除前快照进回收站（借调 / 附件子表 / 自定义值随删，一并快照）
        try {
            $rb = CertificateModel::where('id', $id)->find();
            if ($rb) {
                \app\service\RecycleBinService::stash(
                    (int)$this->request->userId,
                    'certificate',
                    trim((string)$rb->cert_no . (($rb->cert_no && $rb->cert_name) ? ' / ' : '') . (string)$rb->cert_name) ?: ("#" . $id),
                    $rb->toArray(),
                    array_filter([
                        ['table' => 'cert_secondment', 'rows' => \app\service\RecycleBinService::grab('cert_secondment', 'cert_id', $id)],
                        ['table' => 'certificate_attach', 'rows' => \app\service\RecycleBinService::grab('certificate_attach', 'cert_id', $id)],
                        \app\service\RecycleBinService::grabCustom('certificate', $id),
                    ])
                );
            }
        } catch (\Throwable $e) {
            // 快照失败不阻断删除
        }
        // 借调记录一并清理，避免留下孤儿数据
        CertSecondment::where('cert_id', $id)->delete();
        // v2.54：级联清理跟进记录与移交历史
        CrmService::deleteByRecord('certificate', $id);
        // v2.32：附件子表随证书删除
        try {
            Db::name('certificate_attach')->where('cert_id', $id)->delete();
        } catch (\Throwable $e) {
            // 子表缺失忽略
        }
        CustomFieldService::deleteValues('certificate', $id);
        CertificateModel::destroy($id);
        $this->log('证照台账', '删除', "#{$id}");
        return ['ok' => true, 'msg' => '删除成功'];
    }

    // 状态变更（挂失/注销/恢复正常）留痕
    public function changeStatus()
    {
        $id     = (int)$this->request->post('id', 0);
        $status = (int)$this->request->post('cert_status', 1);
        $remark = $this->request->post('remark', '');
        $cert   = CertificateModel::find($id);
        if (!$cert) {
            return json(r_error('证书不存在', 400));
        }
        $old = $cert->cert_status;
        $cert->cert_status = $status;
        $cert->save();
        CertStatusLog::create([
            'cert_id'    => $id,
            'old_status' => $old,
            'new_status' => $status,
            'operator'   => $this->request->user['real_name'] ?? '',
            'remark'     => $remark,
        ]);
        $this->log('证书状态', '变更', "#{$id}:{$old}->{$status}");
        return json(r_success([], '操作成功'));
    }

    /**
     * v2.56：证书详情抽屉直接保存附件（只覆盖证书附件子表，不动证书其他字段）。
     * 与人员档案 attachSave 同构：文件已由统一上传入口做过白名单/大小校验，
     * 这里沿用 save() 同等的 validateAll 口径。
     */
    public function attachSave()
    {
        if (!$this->can('cert', 'edit')) {
            return json(r_error('暂无编辑证照权限，请联系管理员', 403));
        }
        $id = (int)$this->request->post('id', 0);
        if ($id <= 0 || !CertificateModel::find($id)) {
            return json(r_error('证书不存在', 400));
        }
        $this->assertCertAccess($id);
        $items = $this->request->post('attachments/a', []);
        if (!is_array($items)) {
            return json(r_error('附件数据格式错误', 400));
        }
        $attCheck = \app\service\CertAttachService::validateAll($items);
        if (!$attCheck['ok']) {
            return json(r_error($attCheck['msg'], 422));
        }
        try {
            $n = \app\service\CertAttachService::replace($id, $items, (int)$this->request->userId);
        } catch (\Throwable $e) {
            return json(r_error('附件保存失败：' . $e->getMessage(), 400));
        }
        $this->log('证照台账', '附件', "详情直接保存证书 #{$id} 附件，共 {$n} 个");
        return json(r_success([
            'count'       => $n,
            'attachments' => \app\service\CertAttachService::listByCert($id),
        ], '附件已保存'));
    }

    /** 下载导入模板（表头 + 一行示例，含启用中的自定义字段列） */
    public function template()
    {
        $path = ExcelService::template('certificate');
        return download_utf8($path, '证书导入模板.xlsx');
    }

    // Excel 导出（含当前借调企业与期限）
    public function export()
    {
        if (!$this->can('cert', 'export')) {
            return json(r_error('暂无导出权限，请联系管理员', 403));
        }
        // v2.99.49：含关联人员身份证/手机号，导出前强制 OTP 二次鉴别
        $this->requireExportTicket('certificate');
        $rows = $this->buildQuery()->with(['staff'])->select()->toArray();
        SecondmentService::attach($rows);
        $formatted = array_map(function ($r) {
            return [
                'cert_name'        => $r['cert_name'] ?? '',
                'cert_no'          => $r['cert_no'] ?? '',
                // v2.36：导出「证书类型」替代原「证书类别」（两者重复）；
                // 类型为空时回落类别值，保证 v2.36 之前的存量记录导出后仍可读。
                'cert_type'        => $r['cert_type'] ?: ($r['category'] ?? ''),
                'category'         => $r['category'] ?? '',
                'staff_name'       => $r['staff']['staff_name'] ?? '',
                'company_name'     => $r['company_name'] ?? '',
                'secondment_start' => $r['secondment_start'] ?? '',
                'secondment_end'   => $r['secondment_end'] ?? '',
                'issue_date'       => $r['issue_date'] ?? '',
                'expire_date'      => $r['expire_date'] ?: '长期',
                'cert_status_text' => $r['cert_status_text'] ?? '',
                'social_status_text' => $r['social_status_text'] ?? '未知',
                'priority'         => $r['priority'] ?? '',
                'cert_fee'         => $r['cert_fee'] ?? 0,
            ];
        }, $rows);
        $path = ExcelService::exportCertificate($formatted);
        return download_utf8($path, 'certificate_' . date('Ymd') . '.xlsx');
    }

    /**
     * v2.79：新增证书「一键采集」。
     *
     * 根据证书类型/专业自动选择查询源：
     *  - 河南省建管平台（注册类 tbregpersonlist、安管 slrylist、技工 techlist、建设厅特种工 tzzylist）
     *  - 应急管理部特种作业平台（v2.89，经 cert-relay 中继，见 MemPersonCollectService）
     * 平台要求图形验证码时返回验证码图片，由业务人员人工识别后提交（不识别、不绕过验证码）。
     *
     * 两种调用形态：
     *  - 首次采集：入参 staff_id*、cert_type*、cert_name
     *    → 直接尝试查询；需验证码时返回 need_captcha=true + captcha_id/image
     *  - 提交验证码：入参 captcha_id*、captcha_code*（会话已绑定人员/类型/来源）
     *    → 验证通过后返回 candidates[]（cert_type/cert_name/cert_no/issue_date/expire_date/issue_org/remark）
     */
    public function personCollect()
    {
        // v2.99：编辑证书时同样可用一键采集，故放宽为「新增或编辑」任一权限即可
        if (!$this->can('cert', 'create') && !$this->can('cert', 'edit')) {
            return json(r_error('暂无证照采集权限，请联系管理员', 403));
        }
        $staffId    = (int)$this->request->post('staff_id', 0);
        $certType   = trim((string)$this->request->post('cert_type', ''));
        $certName   = trim((string)$this->request->post('cert_name', ''));
        $captchaId  = trim((string)$this->request->post('captcha_id', ''));
        $captchaCode = trim((string)$this->request->post('captcha_code', ''));

        // 已有人工验证码：按会话归属选择查询源（河南平台 / 应急厅中继）
        if ($captchaId !== '') {
            if ($captchaCode === '') {
                return json(r_error('请输入图形验证码', 400));
            }
            $isMem = MemPersonCollectService::hasSession($captchaId);
            try {
                $result = $isMem
                    ? MemPersonCollectService::collect($captchaId, $captchaCode)
                    : HnPersonCollectService::collect($captchaId, $captchaCode);
            } catch (\Throwable $e) {
                return json(r_error($e->getMessage(), 400));
            }
            if (!empty($result['need_captcha'])) {
                return json(r_success($result, '平台仍要求验证码，请重新输入'));
            }
            $okMsg = $result['candidates'] ? '采集成功' : '平台未查询到该人员的证书';
            if (!empty($result['cached'])) {
                $okMsg .= '（中继缓存结果，默认 1 天）';
            }
            return json(r_success($result, $okMsg));
        }

        if ($staffId <= 0) {
            return json(r_error('请先选择持证人员', 400));
        }
        if ($certType === '') {
            return json(r_error('请先选择证书类型', 400));
        }
        $this->assertStaffAccess($staffId);

        // 后端从人员档案取身份证号明文（列表/详情均为脱敏值，不能用于平台匹配）
        $staff = Db::name('staff')->where('id', $staffId)->field('staff_name,id_card')->find();
        if (!$staff) {
            return json(r_error('持证人员不存在', 400));
        }
        // v2.99.59：库内为 SM4 密文，采集前必须解密为明文
        $idCard = \app\service\IdCardService::decode($staff['id_card'] ?? '');
        if ($idCard === '') {
            return json(r_error('该人员未登记身份证号，无法采集。请先在人员档案中补充身份证号', 400));
        }

        // v2.89：应急厅特种工走 cert-relay 中继（仍为人工验证码）
        if (MemPersonCollectService::supports($certType)) {
            try {
                $result = MemPersonCollectService::tryDirect(
                    $staffId,
                    $idCard,
                    trim((string)($staff['staff_name'] ?? '')),
                    $certType,
                    $certName
                );
            } catch (\Throwable $e) {
                return json(r_error($e->getMessage(), 400));
            }
            return json(r_success($result, '应急厅查询平台要求图形验证码，请人工输入后继续采集'));
        }

        $ep = HnPersonCollectService::resolveEndpoint($certType, $certName);
        if (isset($ep['error'])) {
            return json(r_error($ep['error'], 400));
        }

        try {
            $result = HnPersonCollectService::tryDirect(
                $ep['key'],
                $idCard,
                trim((string)($staff['staff_name'] ?? '')),
                $ep['extra'] ?? [],
                $staffId,
                $certType,
                $certName
            );
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage(), 400));
        }

        if (!empty($result['need_captcha'])) {
            return json(r_success($result, '平台要求图形验证码，请人工输入后继续采集'));
        }
        return json(r_success($result, $result['candidates'] ? '采集成功' : '平台未查询到该人员的证书'));
    }

    /**
     * v2.79：同一采集会话换一张验证码（识别错误 / 看不清）。
     * v2.89：兼容应急厅中继会话。
     *
     * 入参：captcha_id*
     */
    public function personCollectCaptchaRefresh()
    {
        $captchaId = trim((string)$this->request->post('captcha_id', ''));
        if ($captchaId === '') {
            return json(r_error('验证码会话已失效，请重新获取验证码', 400));
        }
        $isMem = MemPersonCollectService::hasSession($captchaId);
        try {
            $res = $isMem
                ? MemPersonCollectService::refreshCaptcha($captchaId)
                : HnPersonCollectService::refreshCaptcha($captchaId);
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage(), 400));
        }
        return json(r_success($res, '验证码已刷新'));
    }

    protected function buildQuery()
    {
        $companyId = (int)$this->request->param('company_id', 0);
        $q = $this->scopeCert(CertificateModel::orderRaw('expire_date IS NULL ASC, expire_date ASC'));
        if ($companyId) {
            $certIds = SecondmentService::certIdsOf([$companyId], 1);
            $q->whereIn('id', $certIds ?: [0]);
        }
        return $q;
    }
}
