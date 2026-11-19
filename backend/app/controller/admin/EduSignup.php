<?php
namespace app\controller\admin;

use app\service\DeptScopeService;
use app\service\EduFlowService;
use app\service\EduSchemaService;
use app\service\EduSignupService;
use think\facade\Db;

/**
 * 学历报名工单（v2.99）
 *
 *  - GET  /admin/edu-signup/meta          建单元数据（机构/人员/经办人）
 *  - GET  /admin/edu-signup/staff-options 人员档案检索（选择既有人员）
 *  - POST /admin/edu-signup/staff-save    现场新增人员（直接写 staff）
 *  - GET  /admin/edu-signup/detail        详情（工单/流程/附件/日志）
 *  - POST /admin/edu-signup/save          建单/编辑（含学费联动、毕业归档）
 *  - POST /admin/edu-signup/advance       进度流转（±1）
 *  - POST /admin/edu-signup/unarchive     反归档（留痕）
 *  - POST /admin/edu-signup/attach-save   上传登记附件
 *  - POST /admin/edu-signup/attach-rename 附件改名
 *  - POST /admin/edu-signup/attach-delete 附件单条软删
 *  - POST /admin/edu-signup/delete        删除工单（软删 + 清理自动费用）
 *  - GET  /admin/edu-signup               工单列表（数据权限）
 */
class EduSignup extends AdminController
{
    protected function boot(): void
    {
        EduSchemaService::ensureTables();
        \app\service\IdCardSchemaService::ensure();
    }

    /**
     * 工单数据权限（口径与考试报名一致）：
     *  - admin：不限；
     *  - company 客户侧账号：仅看本企业关联人员（staff_company_rel）的工单；
     *  - 内部办公账号：data_scope=all 不限；dept/dept_tree 换算到经办人 owner_user_id 所属部门；self 仅本人经办。
     */
    protected function scopeSignup($query, string $alias = 's')
    {
        if ($this->isAdmin()) {
            return $query;
        }
        if ($this->isCompanyBound()) {
            $compIds = array_values(array_filter(array_map('intval', $this->companyIds())));
            $staffIds = $compIds
                ? Db::name('staff_company_rel')->whereIn('company_id', $compIds)->column('staff_id')
                : [];
            $query->whereIn($alias . '.staff_id', $staffIds ?: [0]);
            return $query;
        }
        // 内部办公账号（v2.99.36）：按创建人 create_by
        return \app\service\DataScopeService::applyCreatorScope($query, $this->request->user ?? [], $alias . '.create_by');
    }

    public function index()
    {
        $this->boot();
        [$page, $limit] = $this->pageParams();

        $q = Db::name('edu_signup')->alias('s')->where('s.status', 1);
        $this->scopeSignup($q);

        // 归档筛选：默认只看在办（is_archived=0），archived=1 看已归档
        $archived = $this->request->has('is_archived') ? (int)$this->request->param('is_archived') : 0;
        $q->where('s.is_archived', $archived);

        $keyword = trim((string)$this->request->param('keyword', ''));
        if ($keyword !== '') {
            // v2.99.59：身份证密文落库，关键词只支持完整号码精确命中（盲索引）
            $idHash = $this->idCardHashOf($keyword);
            $q->where(function ($w) use ($keyword, $idHash) {
                $w->whereLike('s.signup_no', "%{$keyword}%")
                  ->whereOr('s.name', 'like', "%{$keyword}%")
                  ->whereOr('s.phone', 'like', "%{$keyword}%")
                  ->whereOr('s.major', 'like', "%{$keyword}%");
                if ($idHash !== '') {
                    $w->whereOr('s.id_card_hash', $idHash);
                }
            });
        }
        foreach (['signup_type' => 's.signup_type', 'roll_status' => 's.roll_status',
                  'agency_id' => 's.agency_id', 'owner_user_id' => 's.owner_user_id',
                  'edu_level' => 's.edu_level'] as $pk => $col) {
            $v = (string)$this->request->param($pk, '');
            if ($v !== '') {
                $q->where($col, $v);
            }
        }
        $stage = (int)$this->request->param('stage', 0);
        if ($stage > 0) {
            $q->where('s.stage', $stage);
        }

        $total = (clone $q)->count();
        $list = (clone $q)
            ->leftJoin('edu_agency a', 'a.id = s.agency_id')
            ->leftJoin('sys_user u', 'u.id = s.owner_user_id')
            ->leftJoin('staff st', 'st.id = s.staff_id')
            ->field('s.*,a.name agency_name_rel,u.real_name owner_name,u.username owner_username,
                     st.staff_name staff_link')
            ->order('s.id desc')->page($page, $limit)->select()->toArray();

        foreach ($list as &$r) {
            $r = $this->decorate($r);
        }
        unset($r);

        return json(r_success(['list' => $list, 'total' => $total]));
    }

    protected function decorate(array $r): array
    {
        $flow = EduFlowService::flowOfSignup($r);
        $stage = (int)$r['stage'];
        $r['stage_label'] = EduFlowService::label($flow, $stage);
        $r['stage_key']   = EduFlowService::key($flow, $stage);
        $r['stage_total'] = count($flow);
        $r['can_forward'] = $stage < count($flow);
        $r['can_back']    = $stage > 1;
        // v2.99.59：盲索引只在服务端查重用，不能出口给前端
        unset($r['id_card_hash']);
        // v2.99.59：身份证密文落库，出口只给掩码
        if (array_key_exists('id_card', $r)) {
            $masked = $this->maskIdCard((string)($r['id_card'] ?? ''));
            $r['id_card_mask'] = $masked;
            $r['id_card'] = $masked;
        }
        return $r;
    }

    public function detail()
    {
        $this->boot();
        $id = (int)$this->request->param('id', 0);
        $signup = Db::name('edu_signup')->where('id', $id)->where('status', 1)->find();
        if (!$signup) {
            return json(r_error('报名单不存在'));
        }
        if (!$this->isAdmin()) {
            $chk = Db::name('edu_signup')->alias('s')->where('s.id', $id);
            $this->scopeSignup($chk);
            if (!(clone $chk)->count()) {
                return json(r_error('无权查看该报名单', 403));
            }
        }

        $flow = EduFlowService::flowOfSignup($signup);
        $staff = (int)$signup['staff_id'] > 0
            ? Db::name('staff')->field('id,staff_name,id_card,phone,dept,position,address,gender,education,edu_type')->find($signup['staff_id'])
            : null;
        if ($staff) {
            $staff = \app\service\IdCardService::decodeRow($staff);
            $staff['id_card_mask'] = $this->maskIdCard((string)$staff['id_card']);
            $staff['id_card'] = $staff['id_card_mask'];
        }
        $agency = (int)$signup['agency_id'] > 0 ? Db::name('edu_agency')->find($signup['agency_id']) : null;
        $owner = (int)$signup['owner_user_id'] > 0
            ? Db::name('sys_user')->field('id,username,real_name,phone')->find($signup['owner_user_id'])
            : null;

        $attaches = Db::name('edu_signup_attach')
            ->where('signup_id', $id)->where('status', 1)->order('sort asc,id asc')->select()->toArray();
        $logs = Db::name('edu_signup_log')->where('signup_id', $id)->order('id asc')->select()->toArray();
        $opIds = array_values(array_unique(array_map(fn($l) => (int)$l['operator'], $logs)));
        $opNames = $opIds ? Db::name('sys_user')->whereIn('id', $opIds)->column('username', 'id') : [];
        foreach ($logs as &$l) {
            $l['operator_name'] = $opNames[$l['operator']] ?? ('#' . $l['operator']);
        }
        unset($l);

        $signup = $this->decorate($signup);
        return json(r_success([
            'signup'      => $signup,
            'flow'        => $flow,
            'stage_times' => json_decode((string)$signup['stage_times'], true) ?: [],
            'staff'       => $staff,
            'agency'      => $agency,
            'owner'       => $owner,
            'attaches'    => $attaches,
            'logs'        => $logs,
        ]));
    }

    /**
     * v2.99.61：学历报名详情「身份证」受控明文出口。
     *
     * 展示优先级与 detail() 一致：已建人员档案且登记了身份证 → 取 staff；
     * 否则取报名单自身 id_card。权限口径 staff:reveal，无权限 403 并写拒绝审计。
     *
     * 入参：id（报名单ID）、purpose（view/copy，默认 view）
     */
    public function reveal()
    {
        $this->boot();
        $id = (int)$this->request->param('id', 0);
        $purpose = trim((string)$this->request->param('purpose', 'view')) === 'copy' ? 'copy' : 'view';

        $signup = Db::name('edu_signup')->where('id', $id)->where('status', 1)->find();
        if (!$signup) {
            return json(r_error('报名单不存在', 404));
        }
        if (!$this->isAdmin()) {
            $chk = Db::name('edu_signup')->alias('s')->where('s.id', $id);
            $this->scopeSignup($chk);
            if (!(clone $chk)->count()) {
                return json(r_error('无权查看该报名单', 403));
            }
        }

        // 与详情页展示优先级保持一致：人员档案身份证优先，缺失时用报名单自填号码
        $ownerType = 'edu_signup';
        $ownerId   = $id;
        $target    = (string)($signup['name'] ?? '');
        $plain     = '';
        $staffId   = (int)$signup['staff_id'];
        if ($staffId > 0) {
            $staff = Db::name('staff')->field('id,staff_name,id_card')->find($staffId);
            if ($staff) {
                $staffPlain = \app\service\IdCardService::decode($staff['id_card'] ?? '');
                if ($staffPlain !== '') {
                    $ownerType = 'staff';
                    $ownerId   = $staffId;
                    $target    = (string)$staff['staff_name'];
                    $plain     = $staffPlain;
                }
            }
        }
        if ($plain === '') {
            $plain = \app\service\IdCardService::decode($signup['id_card'] ?? '');
        }

        $user = $this->request->user ?? [];
        if (!\app\service\IdCardService::canReveal($user)) {
            \app\service\SensitiveAccessService::record(
                $ownerType, $ownerId, 'id_card', $purpose, false,
                ['target' => $target . '（无明文权限）']
            );
            return json(r_error('当前账号无查看明文的权限，请联系管理员在「角色管理 → 人员档案 → 查看明文」中授权', 403, [
                'field'   => 'id_card',
                'granted' => 0,
            ]));
        }
        if ($plain === '') {
            return json(r_error('该报名单未登记身份证号，或密文无法解密，请联系管理员', 422));
        }

        \app\service\SensitiveAccessService::record(
            $ownerType, $ownerId, 'id_card', $purpose, true, ['target' => $target]
        );
        return json(r_success([
            'id'      => $id,
            'field'   => 'id_card',
            'value'   => $plain,
            'masked'  => false,
            'purpose' => $purpose,
        ]));
    }

    /** 建单下拉数据 */
    public function meta()
    {
        $this->boot();
        $agencies = Db::name('edu_agency')->where('status', 1)
            ->field('id,name,contact,phone,address')->order('sort asc,id desc')->limit(500)->select()->toArray();
        $staff = Db::name('staff')->where('status', 1)
            ->field('id,staff_name,id_card,phone,dept,position,gender,address')
            ->order('id desc')->limit(500)->select()->toArray();
        // v2.99.59：身份证密文落库，先解密再掩码，出口不返回明文
        \app\service\IdCardService::decodeRows($staff);
        foreach ($staff as &$s) {
            $s['id_card_mask'] = $this->maskIdCard((string)$s['id_card']);
            $s['id_card'] = $s['id_card_mask'];
        }
        unset($s);
        // 各报名类型当前生效流程（建单快照参考）
        $flows = [
            'dianzhong' => EduFlowService::flowOfType('dianzhong'),
            'chengkao'  => EduFlowService::flowOfType('chengkao'),
        ];
        return json(r_success(['agencies' => $agencies, 'staff' => $staff, 'flows' => $flows]));
    }

    /** 人员档案检索（工单表单选择既有人员） */
    public function staffOptions()
    {
        $this->boot();
        [$page, $limit] = $this->pageParams();
        $q = Db::name('staff')->where('status', 1);
        $keyword = trim((string)$this->request->param('keyword', ''));
        if ($keyword !== '') {
            $idHash = $this->idCardHashOf($keyword);
            $q->where(function ($w) use ($keyword, $idHash) {
                $w->whereLike('staff_name', "%{$keyword}%")
                  ->whereOr('phone', 'like', "%{$keyword}%");
                if ($idHash !== '') {
                    $w->whereOr('id_card_hash', $idHash);
                }
            });
        }
        $total = (clone $q)->count();
        $list = (clone $q)->field('id,staff_name,id_card,phone,dept,position,gender,address,education,edu_type')
            ->order('id desc')->page($page, $limit)->select()->toArray();
        \app\service\IdCardService::decodeRows($list);
        foreach ($list as &$s) {
            $s['id_card_mask'] = $this->maskIdCard((string)$s['id_card']);
            $s['id_card'] = $s['id_card_mask'];
        }
        unset($s);
        return json(r_success(['list' => $list, 'total' => $total]));
    }

    /** 现场新增人员（直接写 staff 人员档案，身份证查重） */
    public function staffSave()
    {
        $this->boot();
        if (!$this->can('edusignup', 'edit')) {
            return json(r_error('无新增人员权限', 403));
        }
        $p = $this->request->param();
        $name = trim((string)($p['staff_name'] ?? $p['name'] ?? ''));
        if ($name === '') {
            return json(r_error('请填写姓名'));
        }
        $idCard = trim((string)($p['id_card'] ?? ''));
        if ($idCard !== '' && strpos($idCard, '*') === false) {
            // v2.99.59：身份证密文落库，查重走盲索引
            $idHash = \app\service\IdCardService::hashOrNull($idCard);
            if ($idHash !== null) {
                $dup = Db::name('staff')->where('id_card_hash', $idHash)->where('status', 1)->find();
                if (!$dup) {
                    // 兼容加密前的老数据
                    $dup = Db::name('staff')->where('id_card', $idCard)->where('status', 1)->find();
                }
                if ($dup) {
                    return json(r_success(['id' => $dup['id'], 'reuse' => true], '该身份证已建档，已自动关联'));
                }
            }
        }
        $uid = (int)$this->request->userId;
        $now = date('Y-m-d H:i:s');
        $idCardData = ['id_card' => $idCard];
        \app\service\IdCardService::prepareWrite($idCardData);
        $staffId = (int)Db::name('staff')->insertGetId([
            'staff_name'    => mb_substr($name, 0, 64),
            'id_card'       => $idCardData['id_card'],
            'id_card_hash'  => $idCardData['id_card_hash'],
            'phone'         => mb_substr(trim((string)($p['phone'] ?? '')), 0, 32),
            'address'       => mb_substr(trim((string)($p['address'] ?? '')), 0, 200),
            'gender'        => (int)($p['gender'] ?? 0),
            'education'     => mb_substr((string)($p['education'] ?? ''), 0, 30),
            'edu_type'      => (string)($p['edu_type'] ?? '') === '全日制' ? '全日制' : '非全日制',
            'remark'        => '由学历报名模块现场新增',
            'status'        => 1,
            'create_by'     => $uid,
            'owner_user_id' => $uid,
            'create_time'   => $now,
            'update_time'   => $now,
        ]);
        $this->log('学历报名工单', '现场新增人员', $name);
        return json(r_success(['id' => $staffId], '人员已建档'));
    }

    public function save()
    {
        $this->boot();
        $id = (int)$this->request->param('id', 0);
        if (!$this->can('edusignup', $id > 0 ? 'edit' : 'create')) {
            return json(r_error('无保存权限', 403));
        }
        $p = $this->request->param();
        $owner = (int)($this->request->user['id'] ?? 0);
        try {
            $res = EduSignupService::save($p, (int)$this->request->userId, $owner);
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage()));
        }
        $this->log('学历报名工单', $id > 0 ? '编辑报名单' : '创建报名单', (string)$res['signup_no']);
        return json(r_success($res, '保存成功'));
    }

    public function advance()
    {
        $this->boot();
        if (!$this->can('edusignup', 'status')) {
            return json(r_error('无流转权限', 403));
        }
        $id = (int)$this->request->param('id', 0);
        $to = (int)$this->request->param('to_stage', 0);
        $note = mb_substr((string)$this->request->param('note', ''), 0, 255);
        try {
            $res = EduSignupService::advance($id, $to, $note, (int)$this->request->userId);
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage()));
        }
        $this->log('学历报名工单', '进度流转', "#{$id} -> {$res['label']}");
        return json(r_success($res, '流转成功'));
    }

    public function unarchive()
    {
        $this->boot();
        if (!$this->can('edusignup', 'status')) {
            return json(r_error('无反归档权限', 403));
        }
        $id = (int)$this->request->param('id', 0);
        try {
            EduSignupService::unarchive($id, (int)$this->request->userId);
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage()));
        }
        $this->log('学历报名工单', '反归档', "#{$id}");
        return json(r_success([], '已反归档，可继续编辑'));
    }

    public function attachSave()
    {
        $this->boot();
        if (!$this->can('edusignup', 'edit')) {
            return json(r_error('无上传权限', 403));
        }
        $signupId = (int)$this->request->param('signup_id', 0);
        $signup = Db::name('edu_signup')->where('id', $signupId)->where('status', 1)->find();
        if (!$signup) {
            return json(r_error('报名单不存在'));
        }
        if ((int)$signup['is_archived'] === 1) {
            return json(r_error('已归档报名单不可上传附件，请先反归档'));
        }
        try {
            $mid = EduSignupService::attachSave($signupId, $this->request->param(), (int)$this->request->userId);
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage()));
        }
        $this->log('学历报名工单', '上传附件', "#{$signupId}");
        return json(r_success(['id' => $mid], '上传成功'));
    }

    public function attachRename()
    {
        $this->boot();
        if (!$this->can('edusignup', 'edit')) {
            return json(r_error('无操作权限', 403));
        }
        try {
            EduSignupService::attachRename(
                (int)$this->request->param('id', 0),
                (string)$this->request->param('attach_name', '')
            );
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage()));
        }
        return json(r_success([], '已改名'));
    }

    public function attachDelete()
    {
        $this->boot();
        if (!$this->can('edusignup', 'edit')) {
            return json(r_error('无操作权限', 403));
        }
        EduSignupService::attachDelete((int)$this->request->param('id', 0));
        $this->log('学历报名工单', '删除附件', (string)$this->request->param('id', 0));
        return json(r_success([], '已删除'));
    }

    public function delete()
    {
        $this->boot();
        if (!$this->can('edusignup', 'delete')) {
            return json(r_error('无删除权限', 403));
        }
        $id = (int)$this->request->param('id', 0);
        try {
            $no = EduSignupService::softDelete($id);
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage()));
        }
        $this->log('学历报名工单', '删除报名单', $no);
        return json(r_success([], '删除成功'));
    }

    private function maskIdCard(string $id): string
    {
        // v2.99.59：入参可能是 SM4 密文，先解密再按统一规则掩码
        return \app\service\IdCardService::mask(\app\service\IdCardService::decode($id));
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
}
