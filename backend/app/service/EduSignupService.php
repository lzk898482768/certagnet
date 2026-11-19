<?php
namespace app\service;

use think\facade\Db;

/**
 * 学历报名工单核心服务（v2.99）
 *
 * 职责：单号生成、人员落地（关联 staff / 现场新增直接写 staff）、建单与编辑、
 *       进度状态流转（±1）、毕业自动归档 / 反归档、学费 → 费用台账联动、
 *       附件维护、软删与关联费用清理。
 *
 * 关键设计：
 *  - 快照 snapshot 存建单时状态流 flow，后续流程配置变更不影响历史工单；
 *  - 状态只能 ±1 节点流转，行锁 + 复核当前节点防并发跳级；
 *  - 学费三字段冗余在工单，权威账目在 fee 表（source_type='education'，is_auto=1）；
 *  - 仅维护 is_auto=1 的自动费用，人工登记的费用不受影响。
 */
class EduSignupService
{
    /** 费用来源标识（与 Fee::SOURCES 对齐） */
    public const FEE_SOURCE = 'education';

    /** 生成报名单号：XL + 日期 + 6 位随机 */
    public static function generateNo(): string
    {
        $prefix = 'XL' . date('Ymd');
        for ($i = 0; $i < 30; $i++) {
            $no = $prefix . str_pad((string)random_int(1, 999999), 6, '0', STR_PAD_LEFT);
            if (!Db::name('edu_signup')->where('signup_no', $no)->find()) {
                return $no;
            }
        }
        return $prefix . substr((string)microtime(true), -6) . random_int(10, 99);
    }

    /**
     * 落地人员：已选 staff 则复用；现场新增（无 staff_id 但有姓名）则直接写 staff 表。
     * 身份证号查重，避免重复建档。
     * @return int staff.id
     */
    public static function resolveStaff(array $p, int $userId): int
    {
        $staffId = (int)($p['staff_id'] ?? 0);
        if ($staffId > 0) {
            $exist = Db::name('staff')->where('id', $staffId)->find();
            if (!$exist) {
                throw new \RuntimeException('所选人员档案不存在');
            }
            return $staffId;
        }
        $name = trim((string)($p['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('请填写姓名或从人员档案选择');
        }
        $idCard = trim((string)($p['id_card'] ?? ''));
        if (strpos($idCard, '*') !== false) {
            $idCard = '';
        }
        // v2.99.59：身份证号查重走盲索引，已建档则直接复用
        if ($idCard !== '') {
            $idHash = \app\service\IdCardService::hashOrNull($idCard);
            if ($idHash !== null) {
                $dup = Db::name('staff')->where('id_card_hash', $idHash)->where('status', 1)->find();
                if (!$dup) {
                    $dup = Db::name('staff')->where('id_card', $idCard)->where('status', 1)->find();
                }
                if ($dup) {
                    return (int)$dup['id'];
                }
            }
        }
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
            'education'     => (string)($p['edu_level'] ?? ''),
            'edu_type'      => (string)($p['edu_nature'] ?? '') === '全日制' ? '全日制' : '非全日制',
            'remark'        => '由学历报名工单现场新增（' . ($p['signup_no'] ?? '') . '）',
            'status'        => 1,
            'create_by'     => $userId,
            'owner_user_id' => $userId,
            'create_time'   => $now,
            'update_time'   => $now,
        ]);
        return $staffId;
    }

    /**
     * 建单 / 编辑（统一入口）
     * @param array $p 前端表单
     * @param int $userId 操作人
     * @param int $owner 默认经办人（建单时未显式指定则取当前账号）
     */
    public static function save(array $p, int $userId, int $owner): array
    {
        $id = (int)($p['id'] ?? 0);
        $signupType = trim((string)($p['signup_type'] ?? ''));
        if ($signupType === '') {
            throw new \RuntimeException('请选择报名类型');
        }
        $name = trim((string)($p['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('请填写姓名');
        }

        $staffId = self::resolveStaff($p, $userId);
        $flow = EduFlowService::flowOfType($signupType);
        if (!$flow) {
            throw new \RuntimeException('该报名类型未配置进度流程');
        }

        // 第三方机构快照
        $agencyId = (int)($p['agency_id'] ?? 0);
        $agencyName = trim((string)($p['agency_name'] ?? ''));
        $agencyContact = trim((string)($p['agency_contact'] ?? ''));
        $agencyPhone = trim((string)($p['agency_phone'] ?? ''));
        if ($agencyId > 0) {
            $agency = Db::name('edu_agency')->where('id', $agencyId)->where('status', 1)->find();
            if ($agency) {
                $agencyName = $agency['name'];
                $agencyContact = $agency['contact'];
                $agencyPhone = $agency['phone'];
            }
        }

        $feeTotal = round((float)($p['fee_total'] ?? 0), 2);
        $feeReceived = round((float)($p['fee_received'] ?? 0), 2);
        $feeCollect = round((float)($p['fee_collect'] ?? 0), 2);
        if ($feeReceived > $feeTotal && $feeTotal > 0) {
            throw new \RuntimeException('已收学费不能大于总学费');
        }

        $rollStatus = (string)($p['roll_status'] ?? 'enrolled');
        if (!in_array($rollStatus, ['enrolled', 'graduated'], true)) {
            $rollStatus = 'enrolled';
        }

        $now = date('Y-m-d H:i:s');
        // v2.99.59：身份证密文落库 + 盲索引；含 * 是掩码回显，视为未填写
        $inIdCard = trim((string)($p['id_card'] ?? ''));
        if (strpos($inIdCard, '*') !== false) {
            $inIdCard = '';
        }
        $idCardData = ['id_card' => $inIdCard];
        \app\service\IdCardService::prepareWrite($idCardData);
        $data = [
            'signup_type'          => $signupType,
            'edu_level'            => mb_substr((string)($p['edu_level'] ?? ''), 0, 30),
            'edu_nature'           => mb_substr((string)($p['edu_nature'] ?? ''), 0, 30),
            'schooling'            => mb_substr((string)($p['schooling'] ?? ''), 0, 20),
            'staff_id'             => $staffId,
            'name'                 => mb_substr($name, 0, 50),
            'id_card'              => $idCardData['id_card'],
            'id_card_hash'         => $idCardData['id_card_hash'],
            'phone'                => mb_substr(trim((string)($p['phone'] ?? '')), 0, 20),
            'address'              => mb_substr(trim((string)($p['address'] ?? '')), 0, 200),
            'gender'               => (int)($p['gender'] ?? 0),
            'major'                => mb_substr(trim((string)($p['major'] ?? '')), 0, 120),
            'sign_time'            => self::dateOrNull($p['sign_time'] ?? null),
            'enroll_time'          => self::dateOrNull($p['enroll_time'] ?? null),
            'expect_graduate_time' => self::dateOrNull($p['expect_graduate_time'] ?? null),
            'graduate_time'        => self::dateOrNull($p['graduate_time'] ?? null),
            'agency_id'            => $agencyId,
            'agency_name'          => mb_substr($agencyName, 0, 150),
            'agency_contact'       => mb_substr($agencyContact, 0, 50),
            'agency_phone'         => mb_substr($agencyPhone, 0, 30),
            'owner_user_id'        => (int)($p['owner_user_id'] ?? 0) ?: $owner,
            'fee_total'            => $feeTotal,
            'fee_received'         => $feeReceived,
            'fee_collect'          => $feeCollect,
            'roll_status'          => $rollStatus,
            'remark'               => mb_substr(trim((string)($p['remark'] ?? '')), 0, 500),
            'update_time'          => $now,
        ];
        // 编辑时前端回传的是掩码（含 *）或空串：视为「不修改身份证号」，
        // 不能把空值写回，否则会把已加密的身份证号清空。
        if ($id > 0 && $inIdCard === '') {
            unset($data['id_card'], $data['id_card_hash']);
        }

        Db::startTrans();
        try {
            if ($id > 0) {
                $old = Db::name('edu_signup')->where('id', $id)->where('status', 1)->lock(true)->find();
                if (!$old) {
                    throw new \RuntimeException('报名单不存在或已删除');
                }
                // 归档单只读（反归档留痕后才可改）
                if ((int)$old['is_archived'] === 1) {
                    throw new \RuntimeException('该报名单已归档，请先反归档再编辑');
                }
                // 保留建单时的 stage / 快照 / 归档态，编辑表单不直接改进度
                $data['stage'] = (int)$old['stage'];
                Db::name('edu_signup')->where('id', $id)->update($data);
                $signupNo = (string)$old['signup_no'];
            } else {
                $signupNo = self::generateNo();
                $data['signup_no'] = $signupNo;
                $data['stage'] = 1;
                $data['stage_times'] = json_encode(
                    [EduFlowService::key($flow, 1) => date('Y-m-d H:i:s')],
                    JSON_UNESCAPED_UNICODE
                );
                $data['snapshot'] = json_encode([
                    'flow'       => $flow,
                    'signup_type' => $signupType,
                    'created_at' => $now,
                ], JSON_UNESCAPED_UNICODE);
                $data['is_archived'] = 0;
                $data['status'] = 1;
                $data['create_by'] = $userId;
                $data['create_time'] = $now;
                $id = (int)Db::name('edu_signup')->insertGetId($data);
                self::writeLog($id, 0, 1, 'transit', '创建报名单', $userId);
            }

            // 毕业自动归档 / 在籍自动解除归档标记
            self::applyArchive($id, $rollStatus, $userId, $data['graduate_time'] ?? null);

            // 学费 → 费用台账联动
            self::syncFee($id, [
                'staff_id'     => $staffId,
                'name'         => $data['name'],
                'fee_total'    => $feeTotal,
                'fee_received' => $feeReceived,
                'fee_collect'  => $feeCollect,
                'occur_date'   => $data['sign_time'] ?: date('Y-m-d'),
            ]);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        return ['id' => $id, 'signup_no' => $signupNo];
    }

    /**
     * 进度流转（±1）
     * @param int $to 目标节点序号
     */
    public static function advance(int $id, int $to, string $note, int $userId): array
    {
        Db::startTrans();
        try {
            $signup = Db::name('edu_signup')->where('id', $id)->where('status', 1)->lock(true)->find();
            if (!$signup) {
                throw new \RuntimeException('报名单不存在或已删除');
            }
            if ((int)$signup['is_archived'] === 1) {
                throw new \RuntimeException('已归档报名单不可流转，请先反归档');
            }
            $flow = EduFlowService::flowOfSignup($signup);
            $total = count($flow);
            $from = (int)$signup['stage'];
            if (!EduFlowService::canTransit($from, $to, $total)) {
                throw new \RuntimeException('只能前进或回退一个节点，禁止跨步流转');
            }
            $times = json_decode((string)($signup['stage_times'] ?? ''), true) ?: [];
            $times[EduFlowService::key($flow, $to)] = date('Y-m-d H:i:s');

            Db::name('edu_signup')->where('id', $id)->update([
                'stage'       => $to,
                'stage_times' => json_encode($times, JSON_UNESCAPED_UNICODE),
                'update_time' => date('Y-m-d H:i:s'),
            ]);
            self::writeLog($id, $from, $to, 'transit',
                $note !== '' ? $note : ('流转至「' . EduFlowService::label($flow, $to) . '」'), $userId);

            // 流转到末节点「已毕业」自动置毕业学籍并归档
            $lastKey = EduFlowService::key($flow, $total);
            if ($to === $total && $lastKey === 'graduated') {
                self::applyArchive($id, 'graduated', $userId, $signup['graduate_time'] ?? null, true);
            }
            Db::commit();
            return ['stage' => $to, 'label' => EduFlowService::label($flow, $to)];
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 归档 / 反归档（学籍状态驱动）。
     * - graduated → 归档（置 is_archived=1、补毕业时间）；
     * - enrolled  → 反归档（清归档标记与归档时间）。
     */
    public static function applyArchive(int $id, string $rollStatus, int $userId, $graduateDate = null, bool $fromAdvance = false): void
    {
        $now = date('Y-m-d H:i:s');
        if ($rollStatus === 'graduated') {
            // 学籍状态与归档态一并落库：流转到「已毕业」时 roll_status 也必须同步为 graduated
            $update = [
                'roll_status'  => 'graduated',
                'is_archived'  => 1,
                'archive_time' => $now,
                'update_time'  => $now,
            ];
            if (empty($graduateDate)) {
                $update['graduate_time'] = date('Y-m-d');
            }
            Db::name('edu_signup')->where('id', $id)->update($update);
            self::writeLog($id, 0, 0, 'archive', $fromAdvance ? '流转至已毕业，自动归档' : '学籍状态为已毕业，自动归档', $userId);
        } else {
            // 在籍：若此前已归档则反归档留痕，学籍状态一并回置为在籍
            $cur = Db::name('edu_signup')->where('id', $id)->find();
            if ($cur && (int)$cur['is_archived'] === 1) {
                Db::name('edu_signup')->where('id', $id)->update([
                    'is_archived' => 0, 'archive_time' => null,
                    'roll_status' => 'enrolled', 'update_time' => $now,
                ]);
                self::writeLog($id, 0, 0, 'unarchive', '学籍状态恢复在籍，反归档', $userId);
            }
        }
    }

    /** 显式反归档（归档单纠错入口，留痕） */
    public static function unarchive(int $id, int $userId): void
    {
        $signup = Db::name('edu_signup')->where('id', $id)->where('status', 1)->find();
        if (!$signup) {
            throw new \RuntimeException('报名单不存在');
        }
        Db::startTrans();
        try {
            Db::name('edu_signup')->where('id', $id)->update([
                'is_archived' => 0,
                'archive_time' => null,
                'roll_status'  => 'enrolled',
                'update_time'  => date('Y-m-d H:i:s'),
            ]);
            self::writeLog($id, 0, 0, 'unarchive', '手动反归档，学籍恢复在籍', $userId);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 学费 → 费用台账联动（仅维护 is_auto=1）
     *  - 总学费：income 应收一条，settle_amount=已收（部分结算），收满则 settle_status=1；
     *  - 代收学费：expense 应付一条（付给第三方报名机构）。
     * 金额为 0 时删除对应自动费用；人工登记费用不受影响。
     */
    public static function syncFee(int $signupId, array $fee): void
    {
        $staffId = (int)$fee['staff_id'];
        // v2.99.1：费用归属企业按人员档案在册企业回填，保证费用台账按企业数据范围可关联到学历报名费用
        $companyId = $staffId > 0
            ? (int)Db::name('staff_company_rel')->where('staff_id', $staffId)->order('id asc')->value('company_id')
            : 0;
        $name = (string)$fee['name'];
        $total = round((float)$fee['fee_total'], 2);
        $received = round((float)$fee['fee_received'], 2);
        $collect = round((float)$fee['fee_collect'], 2);
        $occurDate = $fee['occur_date'] ?: date('Y-m-d');
        if ($received > $total) {
            $received = $total;
        }

        // ① 总学费（应收）
        $income = Db::name('fee')
            ->where('source_type', self::FEE_SOURCE)->where('source_id', $signupId)
            ->where('is_auto', 1)->where('fee_type', 'income')->find();
        if ($total <= 0) {
            if ($income) {
                Db::name('fee')->where('id', $income['id'])->delete();
            }
        } else {
            $payload = [
                'company_id'    => $companyId,
                'staff_id'      => $staffId,
                'subject_type'  => 'staff',
                'source_type'   => self::FEE_SOURCE,
                'source_id'     => $signupId,
                'is_auto'       => 1,
                'fee_name'      => '学历报名学费·' . $name,
                'fee_type'      => 'income',
                'amount'        => $total,
                'settle_amount' => $received,
                'settle_status' => ($received > 0 && $received >= $total) ? 1 : 0,
                'settle_date'   => ($received > 0 && $received >= $total) ? date('Y-m-d') : null,
                'occur_date'    => $occurDate,
                'remark'        => '由学历报名工单自动生成（' . self::FEE_SOURCE . '#' . $signupId . '）',
                'update_time'   => date('Y-m-d H:i:s'),
            ];
            if ($income) {
                Db::name('fee')->where('id', $income['id'])->update($payload);
            } else {
                // v2.99.36：自动费用归属工单创建人，避免创建人数据范围下看不到
                $payload['create_by'] = (int)Db::name('edu_signup')->where('id', $signupId)->value('create_by');
                $payload['create_time'] = date('Y-m-d H:i:s');
                Db::name('fee')->insert($payload);
            }
        }

        // ② 代收学费（应付第三方机构）
        $expense = Db::name('fee')
            ->where('source_type', self::FEE_SOURCE)->where('source_id', $signupId)
            ->where('is_auto', 1)->where('fee_type', 'expense')->find();
        if ($collect <= 0) {
            if ($expense) {
                Db::name('fee')->where('id', $expense['id'])->delete();
            }
        } else {
            $payload = [
                'company_id'    => $companyId,
                'staff_id'      => $staffId,
                'subject_type'  => 'staff',
                'source_type'   => self::FEE_SOURCE,
                'source_id'     => $signupId,
                'is_auto'       => 1,
                'fee_name'      => '代收学历报名学费·' . $name,
                'fee_type'      => 'expense',
                'amount'        => $collect,
                'settle_amount' => 0,
                'settle_status' => 0,
                'occur_date'    => $occurDate,
                'remark'        => '应付第三方报名机构，由学历报名工单自动生成（' . self::FEE_SOURCE . '#' . $signupId . '）',
                'update_time'   => date('Y-m-d H:i:s'),
            ];
            if ($expense) {
                Db::name('fee')->where('id', $expense['id'])->update($payload);
            } else {
                // v2.99.36：自动费用归属工单创建人，避免创建人数据范围下看不到
                $payload['create_by'] = (int)Db::name('edu_signup')->where('id', $signupId)->value('create_by');
                $payload['create_time'] = date('Y-m-d H:i:s');
                Db::name('fee')->insert($payload);
            }
        }
    }

    /** 软删工单并清理其自动费用（人工费用保留） */
    public static function softDelete(int $id): string
    {
        $signup = Db::name('edu_signup')->where('id', $id)->find();
        if (!$signup) {
            throw new \RuntimeException('报名单不存在');
        }
        Db::startTrans();
        try {
            Db::name('edu_signup')->where('id', $id)->update([
                'status' => 0, 'update_time' => date('Y-m-d H:i:s'),
            ]);
            Db::name('fee')
                ->where('source_type', self::FEE_SOURCE)->where('source_id', $id)
                ->where('is_auto', 1)->delete();
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
        return (string)$signup['signup_no'];
    }

    /** 附件：登记一条（文件先经通用上传） */
    public static function attachSave(int $signupId, array $f, int $userId): int
    {
        $path = trim((string)($f['file_path'] ?? ''));
        if ($path === '') {
            throw new \RuntimeException('请先上传文件');
        }
        $sort = (int)Db::name('edu_signup_attach')
            ->where('signup_id', $signupId)->where('status', 1)->max('sort') + 1;
        $now = date('Y-m-d H:i:s');
        return (int)Db::name('edu_signup_attach')->insertGetId([
            'signup_id'   => $signupId,
            'attach_name' => mb_substr(trim((string)($f['attach_name'] ?? '')) ?: (string)($f['orig_name'] ?? '附件'), 0, 150),
            'file_path'   => $path,
            'file_url'    => (string)($f['file_url'] ?? $path),
            'file_ext'    => mb_substr(strtolower(ltrim((string)($f['file_ext'] ?? ''), '.')), 0, 20),
            'file_size'   => (int)($f['file_size'] ?? 0),
            'orig_name'   => mb_substr((string)($f['orig_name'] ?? ''), 0, 255),
            'sort'        => $sort,
            'status'      => 1,
            'uploaded_by' => $userId,
            'create_time' => $now,
        ]);
    }

    /** 附件：单条软删 */
    public static function attachDelete(int $attachId): void
    {
        Db::name('edu_signup_attach')->where('id', $attachId)->update([
            'status' => 0, 'update_time' => date('Y-m-d H:i:s'),
        ]);
    }

    /** 附件：改名 */
    public static function attachRename(int $attachId, string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            throw new \RuntimeException('附件名称不能为空');
        }
        Db::name('edu_signup_attach')->where('id', $attachId)->update([
            'attach_name' => mb_substr($name, 0, 150),
            'update_time' => date('Y-m-d H:i:s'),
        ]);
    }

    /** 流转日志 */
    protected static function writeLog(int $signupId, int $from, int $to, string $action, string $note, int $operator): void
    {
        Db::name('edu_signup_log')->insert([
            'signup_id'   => $signupId,
            'from_stage'  => $from,
            'to_stage'    => $to,
            'action'      => $action,
            'note'        => mb_substr($note, 0, 255),
            'operator'    => $operator,
            'create_time' => date('Y-m-d H:i:s'),
        ]);
    }

    /** 日期空串转 null（MySQL 严格模式不接受 ''） */
    protected static function dateOrNull($v): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = trim((string)$v);
        if ($v === '') {
            return null;
        }
        // 仅取日期部分（兼容 datetime 传入）
        return substr($v, 0, 10);
    }
}
