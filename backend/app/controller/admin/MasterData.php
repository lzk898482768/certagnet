<?php
namespace app\controller\admin;

use app\model\ClientCompany;
use app\model\Staff as StaffModel;
use app\service\CrmService;
use app\service\IdCardSchemaService;
use app\service\IdCardService;
use app\service\MaskService;
use app\service\ResourceOwnerService;
use think\facade\Db;

/**
 * 共享主数据（v2.99.35 权限三轴解耦 P1，v2.99.68 扩展受控写）
 *
 * 定位：跨模块「引用数据」选择源与现场建档通道，与业务菜单授权彻底解耦。
 *
 * 背景：政务网申报进度 / 账号密码库等模块的表单需要选择企业、人员，
 * 旧实现复用 company/options、staff/options，这两个接口挂在企业客户(菜单11)、
 * 人员档案(菜单12)守卫下且带数据范围过滤，导致只授权申报进度/账号库的角色
 * 下拉 403、保存时 canAccessCompany/canAccessStaff 再被 403。
 *
 * 成熟 RBAC 系统的通行做法：引用数据（reference data）不等于功能授权，
 * 任何登录用户在其被授权的业务表单内都应能选择主数据；行级敏感信息仍由
 * 脱敏服务按角色控制，本控制器只暴露选择器必需的最小字段。
 *
 * 安全边界：
 *  - 读：仅 GET、只读，在 PermissionMap::SHARED_READ_ACTIONS 登记，PermAuth 直接放行；
 *    不返回明文身份证 / 手机号（统一走 MaskService）；
 *  - 写（v2.99.68）：company-save / staff-save / ocr-idcard 登记在
 *    PermissionMap::SHARED_WRITE_ACTIONS，仅跳过「菜单级」守卫；控制器内部仍强制
 *    校验当前角色持有任一业务模块写权限码（requireAnyWritePerm），纯只读角色
 *    （viewer，perms 为空）一律 403。写接口只接受建档所需的最小字段白名单，
 *    复用与原生 company/staff 保存完全同口径的查重（DuplicateResolver，命中则复用
 *    存量并自动共有）、身份证密文 + 盲索引、资源所有人登记与操作日志。
 */
class MasterData extends AdminController
{
    /**
     * 企业选择源：id / 名称 / 简称（全量启用企业，不做数据范围过滤）
     * GET /admin/master/company-options
     *
     * v2.99.53：
     *  - 关键词改为服务端检索（配合前端 remote 搜索），避免企业数量超过单次上限后，
     *    新建的高 id 企业被截断、纯客户端 filterable 永远搜不到；
     *  - 排序由 id asc 改为 id desc，与人员选择源一致，新建企业优先可见；
     *  - 单次上限放大到 2000（仅返回 id/名称/简称三个轻量字段），并支持 keyword 精确收敛。
     */
    public function companyOptions()
    {
        $keyword = trim((string)$this->request->param('keyword', ''));
        $q = ClientCompany::where('status', 1)->field('id,company_name,short_name');
        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $q->where(function ($w) use ($like) {
                $w->whereLike('company_name', $like)
                  ->whereOr('short_name', 'like', $like);
            });
        }
        $list = $q->order('id', 'desc')->limit(2000)->select()->toArray();
        return json(r_success($list));
    }

    /**
     * 人员选择源：id / 姓名 / 脱敏联系方式 / 所属企业名（全量在职人员）
     * GET /admin/master/staff-options
     *
     * v2.99.53：
     *  - 关键词改为服务端多字段检索（姓名 / 电话 / 身份证），配合前端 remote 搜索，
     *    避免人员超过单次上限后新建人员被截断、客户端 filterable 搜不到；
     *  - 单次上限放大到 2000，排序保持 id desc（新人员优先）；
     *  - 脱敏、企业名富化、负责人附加均做异常兜底：任一增强环节失败不应让整个
     *    选择器接口 500，至少返回基础的 id/姓名，保证表单始终可选择人员。
     */
    public function staffOptions()
    {
        // v2.99.59：身份证号密文落库，兜底补齐 id_card_hash（老库运行时自愈）
        \app\service\IdCardSchemaService::ensure();
        $keyword = trim((string)$this->request->param('keyword', ''));
        $q = StaffModel::where('status', 1)->field('id,staff_name,phone,id_card,owner_user_id');
        if ($keyword !== '') {
            // v2.99.59：身份证密文落库，仅完整号码按盲索引精确命中
            $idCardNorm = \app\service\IdCardService::normalize($keyword);
            $idHash = preg_match('/^\d{15}$|^\d{17}[\dX]$/', $idCardNorm)
                ? \app\service\IdCardService::hash($idCardNorm) : '';
            $like = '%' . $keyword . '%';
            $q->where(function ($w) use ($like, $idHash, $idCardNorm) {
                $w->whereLike('staff_name', $like)
                  ->whereOr('phone', 'like', $like);
                if ($idHash !== '') {
                    $w->whereOr('id_card_hash', $idHash);
                }
                // 兼容加密前的明文历史数据
                if ($idCardNorm !== '') {
                    $w->whereOr('id_card', $idCardNorm);
                }
            });
        }
        $list = $q->order('id', 'desc')->limit(2000)->select()->toArray();
        // v2.99.59：出口前解密（身份证落库为 SM4 密文）
        \app\service\IdCardService::decodeRows($list);

        // 敏感字段脱敏与原 staff/options 同口径（按系统脱敏开关 + 角色）；
        // 脱敏异常不应阻断选择器，失败时退回基础字段。
        try {
            $list = MaskService::staffListFor($list, $this->request->user ?? []);
        } catch (\Throwable $e) {
            // 静默降级：保留原始 id/姓名，剔除可能的明文凭据字段
            foreach ($list as &$row) {
                unset($row['id_card'], $row['phone']);
            }
            unset($row);
        }

        // 附带所属企业名称，便于同名人员区分（与 Staff::options 保持一致的展示结构）
        if ($list) {
            try {
                $ids  = array_column($list, 'id');
                $rels = Db::table('staff_company_rel')->whereIn('staff_id', $ids)->select()->toArray();
                $cids = array_values(array_unique(array_column($rels, 'company_id')));
                $cmap = [];
                if ($cids) {
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
            } catch (\Throwable $e) {
                foreach ($list as &$s) {
                    $s['company_names'] = $s['company_names'] ?? [];
                }
                unset($s);
            }
        }
        try {
            CrmService::attachOwner($list, 'staff');
        } catch (\Throwable $e) {
            // 负责人信息为增强展示，失败不影响选择
        }
        return json(r_success($list));
    }

    // ==================================================================
    // 受控写：跨模块「快捷新建引用主数据」（v2.99.68）
    // ==================================================================

    /**
     * 共享写通道的功能级闸门：当前角色必须持有任一业务模块的写权限码。
     *
     * 菜单级守卫已在 PermAuth 跳过，这里补齐「默认拒绝」的最后一道关：
     *  - admin：permCodes 为 null，恒通过；
     *  - 业务角色（项目配证专员 / 证照管理员 / 财务等）：持有 *:create / *:edit 等写码即通过；
     *  - 只读观察员（viewer，perms 为空）：无任何写码 → false，调用方返回 403。
     *
     * 仅识别 PermissionCatalog 已登记的模块，避免把告警已读之类的杂项权限误判为写能力。
     */
    private function hasAnyWritePerm(): bool
    {
        if ($this->isAdmin()) {
            return true;
        }
        $codes = $this->request->permCodes;
        if ($codes === null) {
            return true; // 与 AdminController::can 同语义：null 仅超管
        }
        if (!is_array($codes) || !$codes) {
            return false;
        }
        $mods = array_keys(\app\service\PermissionCatalog::modules());
        foreach ($mods as $mod) {
            if (isset($codes[$mod . ':*'])) {
                return true;
            }
            foreach (['create', 'edit'] as $act) {
                if (isset($codes[$mod . ':' . $act])) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * 跨模块快捷新建客户企业
     * POST /admin/master/company-save
     *
     * 只接受 QuickCreate 建档所需的最小字段；查重口径与 Company::save 一致
     * （DuplicateResolver，命中则复用存量并把当前操作人加为共有人）。
     */
    public function companySave()
    {
        if (!$this->hasAnyWritePerm()) {
            return json(r_error('当前角色为只读账号，不能新建企业', 403));
        }
        $data = $this->request->post([
            'company_name', 'short_name', 'contact', 'contact_phone', 'credit_code', 'status',
        ]);
        $name = trim((string)($data['company_name'] ?? ''));
        if ($name === '') {
            return json(r_error('企业名称必填', 400));
        }
        $row = [
            'company_name'  => mb_substr($name, 0, 128),
            'short_name'    => mb_substr(trim((string)($data['short_name'] ?? '')), 0, 64),
            'contact'       => mb_substr(trim((string)($data['contact'] ?? '')), 0, 64),
            'contact_phone' => mb_substr(trim((string)($data['contact_phone'] ?? '')), 0, 32),
            'credit_code'   => strtoupper(trim((string)($data['credit_code'] ?? ''))),
            'legal_person'  => '',
            'region_path'   => '',
            'registered_capital' => '',
            'address'       => '',
            'industry'      => '',
            'company_scale' => '',
            'remark'        => '由业务模块快捷新建',
            'status'        => 1,
        ];

        // 查重：与原生建档一致，命中存量则复用 + 自动共有，不制造重复主记录
        $dup = \app\service\DuplicateResolver::company($row);
        if ($dup) {
            $share = \app\service\DuplicateResolver::share(
                'company', (int)$dup['id'], (int)$this->request->userId
            );
            $this->log('客户企业', '快捷新建查重共有', "#{$dup['id']} 共有人数 {$share['owners']}");
            return json(r_success([
                'id'         => (int)$dup['id'],
                'duplicated' => true,
            ], '检测到已存在相同企业，已自动关联为共同所有人'));
        }

        $uid = (int)$this->request->userId;
        $row['create_by']     = $uid;
        $row['owner_user_id'] = $uid;
        try {
            $model = ClientCompany::create($row);
        } catch (\Throwable $e) {
            // 唯一索引并发兜底：信用代码冲突时回查重，转为共有
            $dupMsg = \app\common\UniqueGuard::friendly($e, '统一社会信用代码已存在');
            if ($dupMsg !== '') {
                $dup = \app\service\DuplicateResolver::company($row);
                if ($dup) {
                    $share = \app\service\DuplicateResolver::share(
                        'company', (int)$dup['id'], $uid
                    );
                    $this->log('客户企业', '快捷新建查重共有', "#{$dup['id']} 唯一键冲突回查，共有人数 {$share['owners']}");
                    return json(r_success([
                        'id'         => (int)$dup['id'],
                        'duplicated' => true,
                    ], '检测到已存在相同企业，已自动关联为共同所有人'));
                }
                return json(r_error($dupMsg . '，请先核对是否已有该企业', 400));
            }
            throw $e;
        }
        $id = (int)$model->id;
        \app\service\Lookup::forgetCompany($row['company_name']);
        ResourceOwnerService::addOwner('company', $id, $uid, 'assign', $uid, true);
        $this->log('客户企业', '快捷新建', "#{$id} {$row['company_name']}");
        return json(r_success(['id' => $id], '保存成功'));
    }

    /**
     * 跨模块快捷新建持证人员
     * POST /admin/master/staff-save
     *
     * 身份证必填（v2.96 统一约束），走与 Staff::save 同口径的格式/校验位校验、
     * 密文 + 盲索引落库与 DuplicateResolver 查重（命中复用 + 自动共有）。
     */
    public function staffSave()
    {
        if (!$this->hasAnyWritePerm()) {
            return json(r_error('当前角色为只读账号，不能新建人员', 403));
        }
        IdCardSchemaService::ensure();
        \app\service\PersonProfileService::ensureStaffColumns();

        $name = trim((string)$this->request->post('staff_name', ''));
        if ($name === '') {
            return json(r_error('人员姓名必填', 400));
        }
        $idCard = trim((string)$this->request->post('id_card', ''));
        if ($idCard === '') {
            return json(r_error('身份证号必填', 400));
        }
        $idInfo = \app\service\ExamIdCardService::parse($idCard);
        if (empty($idInfo['ok'])) {
            return json(r_error('身份证号不正确：' . ($idInfo['msg'] ?? ''), 400));
        }
        $idCard = $idInfo['id_card'];

        $row = [
            'staff_name' => mb_substr($name, 0, 64),
            'id_card'    => $idCard,
            'phone'      => mb_substr(trim((string)$this->request->post('phone', '')), 0, 32),
            'gender'     => (int)$this->request->post('gender', 0),
            'nation'     => mb_substr(trim((string)$this->request->post('nation', '')), 0, 30),
            'address'    => mb_substr(trim((string)$this->request->post('address', '')), 0, 200),
            'dept'       => mb_substr(trim((string)$this->request->post('dept', '')), 0, 64),
            'status'     => 1,
            'remark'     => '由业务模块快捷新建',
        ];
        $birthday = trim((string)$this->request->post('birthday', ''));
        $row['birthday'] = $birthday === '' ? null : $birthday;

        // 查重：身份证盲索引优先，命中存量则复用 + 自动共有
        $dup = \app\service\DuplicateResolver::staff($row);
        if ($dup) {
            CrmService::ensureSchema();
            $share = \app\service\DuplicateResolver::share(
                'staff', (int)$dup['id'], (int)$this->request->userId
            );
            $this->log('持证人员', '快捷新建查重共有', "#{$dup['id']} 共有人数 {$share['owners']}");
            return json(r_success([
                'id'         => (int)$dup['id'],
                'duplicated' => true,
            ], '检测到已存在该人员，已自动关联为共同所有人'));
        }

        // 身份证密文 + 盲索引
        IdCardService::prepareWrite($row);
        $uid = (int)$this->request->userId;
        $row['create_by']     = $uid;
        $row['owner_user_id'] = $uid;
        CrmService::ensureSchema();
        $model = StaffModel::create($row);
        $id = (int)$model->id;
        ResourceOwnerService::addOwner('staff', $id, $uid, 'assign', $uid, true);
        \app\service\Lookup::forgetStaff($idCard, $row['staff_name']);
        $this->log('持证人员', '快捷新建', "#{$id} {$row['staff_name']}");
        return json(r_success(['id' => $id], '保存成功'));
    }

    /**
     * 快捷建档场景的身份证 OCR 识别
     * POST /admin/master/ocr-idcard
     *
     * QuickCreate 原来调 staff/ocr-idcard，受人员档案(12)菜单守卫，跨模块角色会被 403。
     * 这里提供同能力的共享入口，仍要求当前角色具备业务写权限；不返回任何库内数据，
     * 只识别上传图片，安全口径与 Staff::ocrIdcard 一致。
     */
    public function ocrIdcard()
    {
        if (!$this->hasAnyWritePerm()) {
            return json(r_error('当前角色为只读账号，不能使用该功能', 403));
        }
        $file = $this->request->file('file');
        $base64 = '';
        if ($file) {
            $allow = ['jpg', 'jpeg', 'png', 'webp', 'bmp'];
            $ext = strtolower((string)$file->getOriginalExtension());
            if ($ext === '') {
                $ext = strtolower(pathinfo((string)$file->getOriginalName(), PATHINFO_EXTENSION));
            }
            if (!in_array($ext, $allow, true) || (int)$file->getSize() > 7 * 1024 * 1024) {
                return json(r_error('图片格式或大小不支持', 400));
            }
            $base64 = base64_encode((string)file_get_contents($file->getPathname()));
        } else {
            $image = (string)$this->request->param('image', '');
            if ($image !== '') {
                $base64 = $image;
            }
        }
        if ($base64 === '') {
            return json(r_error('请上传或传入身份证图片', 400));
        }
        $r = \app\service\ExamOcrService::idCard($base64, 'FRONT');
        if (empty($r['ok'])) {
            return json(r_error($r['msg'] ?? '识别失败'));
        }
        $this->log('持证人员', '快捷新建OCR身份证识别', '');
        return json(r_success($r['data'] ?? [], '识别成功'));
    }
}
