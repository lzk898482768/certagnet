<?php
namespace app\service;

use think\facade\Db;

/**
 * 报名工单核心服务（v2.85，移植自 exam-reg OrderService）
 *
 * 负责：业务组合解析、规则上下文、考生落地（关联 staff/复用 client_company）、
 *       建单（正式/草稿）、重复校验、规则强校验、快照、状态流转、审核记录。
 * 不含财务收退款（第一期）。
 *
 * 关键设计：
 *  - 快照 snapshot 存下单时 rules/materials/flow/context/考生草稿，
 *    后续配置变更不影响历史工单；
 *  - 状态只能 ±1 节点流转，行锁 + 复核当前节点防并发跳级；
 *  - 服务端强制规则校验，「不符合条件仍办理」需显式确认并留痕 rule_override。
 */
class ExamSignupService
{
    /** 材料类规则字段：建单时材料可能尚未上传，交由审核环节判定 */
    private const MATERIAL_FIELDS = ['has_business_license', 'has_physical_exam'];

    /** 费用来源标识（与 Fee::SOURCES 对齐，v2.99.1 起联动费用台账） */
    public const FEE_SOURCE = 'exam';

    /** 生成报名单号：日期 + 6 位随机 */
    public static function generateNo(): string
    {
        $prefix = 'BM' . date('Ymd');
        for ($i = 0; $i < 30; $i++) {
            $no = $prefix . str_pad((string)random_int(1, 999999), 6, '0', STR_PAD_LEFT);
            if (!Db::name('exam_signup')->where('signup_no', $no)->find()) {
                return $no;
            }
        }
        return $prefix . substr((string)microtime(true), -6) . random_int(10, 99);
    }

    /** JSON 字段解析为数组 */
    public static function jsonList($v, bool $assocList = false): array
    {
        if (is_array($v)) {
            return $v;
        }
        if (is_string($v) && $v !== '') {
            $d = json_decode($v, true);
            if (is_array($d)) {
                return $d;
            }
        }
        return $assocList ? [] : [];
    }

    /**
     * 解析业务组合及其证书/动作/状态流/规则/材料
     * @return array{config:array,action:array,cert:array,flow:array,rules:array,materials:array}
     * @throws \RuntimeException
     */
    public static function resolveConfig(int $configId): array
    {
        $config = Db::name('exam_biz_config')->where('status', 1)->find($configId);
        if (!$config) {
            throw new \RuntimeException('业务组合不存在或已停用');
        }
        $c = $config;
        $c['rules']       = ExamRuleService::normalize($c['rules'] ?? []);
        $c['materials']   = ExamMaterialService::normalize($c['materials'] ?? []);
        $c['status_flow'] = ExamStatusMachineService::normalize($c['status_flow'] ?? null);

        $action = Db::name('exam_biz_action')->find($c['action_id']) ?: [];
        $action['status_flow'] = ExamStatusMachineService::normalize($action['status_flow'] ?? null);

        $cert = Db::name('exam_cert_type')->find($c['cert_type_id']) ?: [];

        $flow = ExamStatusMachineService::flowOf($c, $action);
        if (!$flow) {
            throw new \RuntimeException('该业务组合未配置状态流（请在「业务动作」中配置默认流转节点）');
        }

        return [
            'config'    => $c,
            'action'    => $action,
            'cert'      => $cert,
            'flow'      => $flow,
            'rules'     => $c['rules'],
            'materials' => $c['materials'],
        ];
    }

    /** 取工单生效状态流（优先快照，其次实时配置） */
    public static function flowOfSignup(array $signup): array
    {
        $snapshot = is_array($signup['snapshot'] ?? null)
            ? $signup['snapshot']
            : (json_decode((string)($signup['snapshot'] ?? ''), true) ?: []);
        $flow = $snapshot['flow'] ?? null;
        if (is_array($flow) && $flow) {
            return ExamStatusMachineService::normalize($flow);
        }
        try {
            return self::resolveConfig((int)($signup['biz_config_id'] ?? 0))['flow'];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 组装规则校验上下文
     * @param array $customer 考生数据
     * @param array $extra    work_years/cert_status/cert_expire_date/has_* 等
     * @param array $materialCodes 已上传材料编码
     */
    public static function buildContext(array $customer, array $extra = [], array $materialCodes = []): array
    {
        $age = 0;
        if (!empty($customer['birthday'])) {
            $age = ExamIdCardService::age((string)$customer['birthday']);
        } elseif (!empty($customer['id_card'])) {
            $age = ExamIdCardService::parse(\app\service\IdCardService::decode($customer['id_card']))['age'];
        }

        $hasLicense = array_key_exists('has_business_license', $extra)
            ? (bool)$extra['has_business_license']
            : in_array('business_license', $materialCodes, true);
        $hasExam = array_key_exists('has_physical_exam', $extra)
            ? (bool)$extra['has_physical_exam']
            : in_array('physical_exam', $materialCodes, true);

        $gradYears = !empty($customer['graduate_date'])
            ? ExamIdCardService::age((string)$customer['graduate_date']) : 0;

        // 证书状态/有效期权威取本系统证照台账（按 staff_id + 证书名称匹配最新有效证书）
        $certStatus = (string)($extra['cert_status'] ?? '');
        $certExpire = (string)($extra['cert_expire_date'] ?? '');
        $staffId = (int)($customer['staff_id'] ?? 0);
        $certTypeId = (int)($extra['cert_type_id'] ?? 0);
        if ($staffId > 0 && $certTypeId > 0) {
            $certName = (string)Db::name('exam_cert_type')->where('id', $certTypeId)->value('name');
            if ($certName !== '') {
                $ledger = Db::name('certificate')
                    ->where('staff_id', $staffId)
                    ->where('cert_name', $certName)
                    ->order('expire_date desc,id desc')->find();
                if ($ledger) {
                    $certExpire = (string)($ledger['expire_date'] ?? '');
                    if ($certStatus === '') {
                        $certStatus = remain_days($certExpire) < 0 ? 'expired' : 'valid';
                    }
                }
            }
        }

        return [
            'age'                  => $age,
            'gender'               => (int)($customer['gender'] ?? 0),
            'education'            => (string)($customer['education'] ?? ''),
            'edu_type'             => (string)($customer['edu_type'] ?? ''),
            'edu_cert_no'          => (string)($customer['edu_cert_no'] ?? ''),
            'graduate_date'        => (string)($customer['graduate_date'] ?? ''),
            'graduate_years'       => ($extra['graduate_years'] ?? '') !== '' ? (float)$extra['graduate_years'] : (float)$gradYears,
            'unit_name'            => (string)($customer['unit_name'] ?? ''),
            'city'                 => (string)($customer['city'] ?? ''),
            'work_years'           => (float)($extra['work_years'] ?? 0),
            'cert_status'          => $certStatus,
            'cert_expire_date'     => $certExpire,
            'has_business_license' => $hasLicense,
            'has_physical_exam'    => $hasExam,
        ];
    }

    /**
     * 考生落地：传 customer_id 复用；否则按身份证号「新增或复用」；可关联 staff。
     * 报名单位复用 client_company，绝不新建报名企业表。
     * @throws \RuntimeException
     */
    public static function resolveCustomer(array $input, int $userId, bool $draft = false): array
    {
        $c = (array)($input['customer'] ?? []);

        // 已指定考生：复用，且若带了资料字段则同步更新（编辑考生/建单时带出的新信息）
        if (!empty($input['customer_id'])) {
            $exist = Db::name('exam_customer')->where('status', 1)->find((int)$input['customer_id']);
            if (!$exist) {
                throw new \RuntimeException('考生不存在');
            }
            $patch = self::buildCustomerData($c, $userId);
            // 身份证不允许改成与他人重复
            $existPlainIdCard = \app\service\IdCardService::decode($exist['id_card'] ?? '');
            if ($patch['id_card'] !== '' && \app\service\IdCardService::normalize($patch['id_card']) !== $existPlainIdCard) {
                $info = ExamIdCardService::parse($patch['id_card']);
                if (!$info['ok']) {
                    throw new \RuntimeException('身份证号无效：' . $info['msg']);
                }
                $dup = Db::name('exam_customer')
                    ->where('id_card_hash', \app\service\IdCardService::hash($info['id_card']))
                    ->where('id', '<>', (int)$exist['id'])->find();
                if (!$dup) {
                    // 兼容未回填的历史明文行
                    $dup = Db::name('exam_customer')
                        ->where('id_card', $info['id_card'])
                        ->where('id', '<>', (int)$exist['id'])->find();
                }
                if ($dup) {
                    throw new \RuntimeException('该身份证号已被其他考生档案占用');
                }
                $patch['id_card']  = $info['id_card'];
                $patch['gender']   = $patch['gender'] ?: $info['gender'];
                $patch['birthday'] = $patch['birthday'] ?: $info['birthday'];
            }
            // v2.99.59：身份证密文 + 盲索引（与原值相同也必须重新加密，避免明文回写）
            if (array_key_exists('id_card', $patch)) {
                \app\service\IdCardService::prepareWrite($patch);
            }
            $patch = array_filter($patch, fn($v) => $v !== '' && $v !== null && $v !== 0, ARRAY_FILTER_USE_BOTH);
            if ($patch) {
                Db::name('exam_customer')->where('id', $exist['id'])
                    ->update($patch + ['update_time' => date('Y-m-d H:i:s')]);
            }
            return \app\service\IdCardService::decodeRow(Db::name('exam_customer')->find($exist['id']) ?: []);
        }

        $name   = trim((string)($c['name'] ?? ''));
        $idCard = strtoupper(trim((string)($c['id_card'] ?? '')));

        // 草稿：信息可不完整，先不建档，随快照暂存
        if ($draft && ($name === '' || $idCard === '' || !ExamIdCardService::parse($idCard)['ok'])) {
            return array_merge($c, ['id' => 0, '_draft_only' => 1]);
        }

        if ($name === '' || $idCard === '') {
            throw new \RuntimeException('请填写考生姓名与身份证号');
        }
        $info = ExamIdCardService::parse($idCard);
        if (!$info['ok']) {
            throw new \RuntimeException('身份证号无效：' . $info['msg']);
        }

        $data = self::buildCustomerData($c, $userId);
        $data['name']    = $name;
        $data['id_card'] = $info['id_card'];
        if (!$data['gender']) {
            $data['gender'] = $info['gender'];
        }
        if (!$data['birthday']) {
            $data['birthday'] = $info['birthday'];
        }
        // 报名单位：复用 client_company，带出单位名称
        if ($data['company_id'] > 0) {
            $comp = Db::name('client_company')->where('id', $data['company_id'])->find();
            if ($comp) {
                $data['unit_name'] = $data['unit_name'] !== '' ? $data['unit_name'] : (string)$comp['company_name'];
            } else {
                $data['company_id'] = 0;
            }
        }

        $exist = Db::name('exam_customer')
            ->where('id_card_hash', \app\service\IdCardService::hash($info['id_card']))
            ->lock(true)->find();
        if (!$exist) {
            // 兼容未回填的历史明文行，避免同一身份证重复建档
            $exist = Db::name('exam_customer')
                ->where('id_card', $info['id_card'])->lock(true)->find();
        }
        // v2.99.59：身份证密文 + 盲索引（写库前统一加工，$data 里已是明文）
        \app\service\IdCardService::prepareWrite($data);
        if ($exist) {
            Db::name('exam_customer')->where('id', $exist['id'])->update($data + [
                'status'      => 1,
                'update_time' => date('Y-m-d H:i:s'),
            ]);
            return \app\service\IdCardService::decodeRow(Db::name('exam_customer')->find($exist['id']) ?: []);
        }
        $data['create_time'] = date('Y-m-d H:i:s');
        $newId = Db::name('exam_customer')->insertGetId($data);
        $data['id'] = $newId;
        return \app\service\IdCardService::decodeRow($data);
    }

    /** 由前端入参构造考生档案字段（关联 staff 带出姓名/性别/生日/电话） */
    private static function buildCustomerData(array $c, int $userId): array
    {
        $staffId = (int)($c['staff_id'] ?? 0);
        $staff = $staffId > 0 ? Db::name('staff')->where('id', $staffId)->find() : null;
        $inIdCard = strtoupper(trim((string)($c['id_card'] ?? '')));

        $gender = (int)($c['gender'] ?? 0);
        if (!$gender && $staff) {
            // staff 无性别字段时仍为 0，由身份证解析兜底
            $gender = 0;
        }
        $phone = trim((string)($c['phone'] ?? ''));
        if ($phone === '' && $staff) {
            $phone = (string)$staff['phone'];
        }
        // v2.87：人员档案扩展字段（身份证/毕业证 OCR），前端未填时从关联 staff 带出
        $fromStaff = static function (string $key, $default = '') use ($staff, $c) {
            $v = trim((string)($c[$key] ?? ''));
            if ($v !== '' || !$staff) {
                return $v;
            }
            $sv = (string)($staff[$key] ?? '');
            return $sv !== '' ? $sv : $default;
        };

        return [
            'staff_id'      => $staff ? (int)$staff['id'] : 0,
            'name'          => trim((string)($c['name'] ?? '')) ?: ($staff ? (string)$staff['staff_name'] : ''),
            'gender'        => $gender ?: ($staff ? (int)($staff['gender'] ?? 0) : 0),
            'nation'        => (string)($c['nation'] ?? '') !== '' ? (string)$c['nation'] : (string)($staff['nation'] ?? ''),
            // v2.99.59：含 * 的值是掩码回显，视为未填写（防止把掩码写进库）
            'id_card'       => strpos($inIdCard, '*') !== false ? '' : $inIdCard,
            'birthday'      => self::normDate($c['birthday'] ?? null)
                ?: ($staff ? self::normDate($staff['birthday'] ?? null) : null),
            'phone'         => $phone,
            'education'     => $fromStaff('education'),
            'edu_type'      => $fromStaff('edu_type', '无') ?: '无',
            'edu_cert_no'   => $fromStaff('edu_cert_no'),
            'graduate_date' => self::normDate($c['graduate_date'] ?? null)
                ?: ($staff ? self::normDate($staff['graduate_date'] ?? null) : null),
            'graduate_school' => $fromStaff('graduate_school'),
            'company_id'    => (int)($c['company_id'] ?? 0),
            'unit_name'     => (string)($c['unit_name'] ?? ''),
            'city'          => (string)($c['city'] ?? ''),
            'address'       => (string)($c['address'] ?? '') !== '' ? (string)$c['address'] : (string)($staff['address'] ?? ''),
            'remark'        => (string)($c['remark'] ?? ''),
            'create_by'     => $userId,
        ];
    }

    /** 重复报名检测：同考生 + 同业务组合（+批次），未删除正式单 */
    public static function duplicateCheck(int $customerId, int $configId, int $batchId = 0): array
    {
        $q = Db::name('exam_signup')
            ->where('status', 1)->where('is_draft', 0)
            ->where('customer_id', $customerId)
            ->where('biz_config_id', $configId);
        if ($batchId > 0) {
            $q->where('batch_id', $batchId);
        }
        return $q->order('id desc')->limit(10)->select()->toArray();
    }

    /**
     * 报名费 → 费用台账联动（v2.99.1）
     * 正式报名单按工单报名费生成/更新一条自动应收费用（source_type='exam'，is_auto=1）；
     * 报名费为 0 或已删除时清理自动费用。草稿不生成费用。
     * @throws \RuntimeException
     */
    public static function syncFee(int $signupId): void
    {
        $signup = Db::name('exam_signup')->where('id', $signupId)->where('status', 1)->find();
        if (!$signup) {
            return;
        }
        $staffId  = (int)$signup['staff_id'];
        $fee      = round((float)($signup['fee'] ?? 0), 2);
        $occurDate = $signup['sign_time'] ?: date('Y-m-d');

        // 归属企业：报名单位优先，其次按人员档案在册企业回填
        $companyId = (int)$signup['company_id'];
        if ($companyId <= 0 && $staffId > 0) {
            $companyId = (int)Db::name('staff_company_rel')->where('staff_id', $staffId)->order('id asc')->value('company_id');
        }

        $income = Db::name('fee')
            ->where('source_type', self::FEE_SOURCE)->where('source_id', $signupId)
            ->where('is_auto', 1)->where('fee_type', 'income')->find();
        if ($fee <= 0) {
            if ($income) {
                Db::name('fee')->where('id', $income['id'])->delete();
            }
            return;
        }

        // 费用名称：考试报名费·考生姓名（无考生则用证书名兜底）
        $customerName = (string)Db::name('exam_customer')->where('id', (int)($signup['customer_id'] ?? 0))->value('name');
        $certName     = (int)($signup['cert_type_id'] ?? 0) > 0
            ? (string)Db::name('exam_cert_type')->where('id', (int)$signup['cert_type_id'])->value('name')
            : '';
        $feeName = '考试报名费·' . ($customerName !== '' ? $customerName : ($certName !== '' ? $certName : '#' . $signupId));

        $payload = [
            'company_id'    => $companyId,
            'staff_id'      => $staffId,
            'subject_type'  => $companyId > 0 ? 'company' : 'staff',
            'source_type'   => self::FEE_SOURCE,
            'source_id'     => $signupId,
            'is_auto'       => 1,
            'fee_name'      => $feeName,
            'fee_type'      => 'income',
            'amount'        => $fee,
            'settle_amount' => 0,
            'settle_status' => 0,
            'settle_date'   => null,
            'occur_date'    => $occurDate,
            'remark'        => '由考试报名工单自动生成（' . self::FEE_SOURCE . '#' . $signupId . '）',
            'update_time'   => date('Y-m-d H:i:s'),
        ];
        if ($income) {
            Db::name('fee')->where('id', $income['id'])->update($payload);
        } else {
            // v2.99.36：自动费用归属工单创建人
            $payload['create_by'] = (int)($signup['create_by'] ?? 0);
            $payload['create_time'] = date('Y-m-d H:i:s');
            Db::name('fee')->insert($payload);
        }
    }

    /**
     * 创建报名工单
     * @param array $input {customer_id|customer{}, biz_config_id, batch_id, fee, remark, extra{}, force, draft, ignore_rules}
     * @throws \RuntimeException
     */
    public static function create(array $input, int $userId, int $ownerUserId = 0): array
    {
        ExamSchemaService::ensureTables();
        $draft = !empty($input['draft']);

        Db::startTrans();
        try {
            $customer = self::resolveCustomer($input, $userId, $draft);
            if ((int)($customer['id'] ?? 0) > 0) {
                Db::name('exam_customer')->where('id', (int)$customer['id'])->lock(true)->find();
            }

            $rc     = self::resolveConfig((int)($input['biz_config_id'] ?? 0));
            $config = $rc['config'];
            if ((int)($config['is_open'] ?? 1) !== 1) {
                throw new \RuntimeException('该业务组合已停止接单');
            }

            $batchId = (int)($input['batch_id'] ?? 0);
            $batch   = $batchId > 0 ? Db::name('exam_batch')->where('status', 1)->find($batchId) : null;

            // 重复检测（草稿不拦，正式提交再校）
            if (!$draft) {
                $dups = self::duplicateCheck((int)($customer['id'] ?? 0), (int)$config['id'], $batchId);
                if ($dups && empty($input['force'])) {
                    throw new \RuntimeException('该考生已存在相同的报名单（单号 ' . $dups[0]['signup_no'] . '），如确需重复报名请勾选「允许重复」');
                }
            }

            $extra = (array)($input['extra'] ?? []);

            // 服务端强制规则校验（材料类字段建单时可缺，交审核判定）
            $ruleFails = [];
            if (!$draft) {
                $rules = [];
                foreach ($rc['rules'] as $r) {
                    $f = (string)($r['field'] ?? '');
                    if (in_array($f, self::MATERIAL_FIELDS, true) && !array_key_exists($f, $extra)) {
                        continue;
                    }
                    $rules[] = $r;
                }
                $ctxExtra = array_merge($extra, ['cert_type_id' => (int)($config['cert_type_id'] ?? 0)]);
                $res       = ExamRuleService::evaluate($rules, self::buildContext($customer, $ctxExtra));
                $ruleFails = array_values(array_filter($res['items'], fn($i) => empty($i['pass'])));
                if ($ruleFails && empty($input['ignore_rules'])) {
                    $tips = array_map(fn($i) => (string)$i['tip'], array_slice($ruleFails, 0, 3));
                    throw new \RuntimeException('不满足报名条件：' . implode('；', $tips) . '（如确需办理，请勾选「不符合条件仍需办理」）');
                }
            }

            // 费用：显式传入 > 组合费 > 证书默认费 > 批次费
            $fee = (isset($input['fee']) && $input['fee'] !== '' && $input['fee'] !== null)
                ? (float)$input['fee'] : (float)($config['fee'] ?? 0);
            if ($fee <= 0) {
                $fee = (float)($rc['cert']['default_fee'] ?? 0);
            }
            if ($fee <= 0 && $batch) {
                $fee = (float)$batch['fee'];
            }

            $snapshot = [
                'config_name'   => (string)($config['name'] ?? ''),
                'cert_name'     => (string)($rc['cert']['name'] ?? ''),
                'action_name'   => (string)($rc['action']['name'] ?? ''),
                'cert_code'     => (string)($rc['cert']['code'] ?? ''),
                'action_code'   => (string)($rc['action']['code'] ?? ''),
                'rules'         => $rc['rules'],
                'materials'     => $rc['materials'],
                'flow'          => $rc['flow'],
                'context'       => $extra,
                'fee'           => round($fee, 2),
                'at'            => date('Y-m-d H:i:s'),
                'rule_fails'    => array_map(fn($i) => ['field' => (string)$i['field'], 'tip' => (string)$i['tip']], $ruleFails),
                'rule_override' => !empty($input['ignore_rules']) ? 1 : 0,
            ];
            if ((int)($customer['id'] ?? 0) === 0) {
                // v2.99.59：草稿身份证密文暂存；掩码回显（含 *）视为未填
                $draftIdCard = trim((string)($customer['id_card'] ?? ''));
                if (strpos($draftIdCard, '*') !== false) {
                    $draftIdCard = '';
                }
                $snapshot['customer_draft'] = [
                    'staff_id'      => (int)($customer['staff_id'] ?? 0),
                    'name'          => (string)($customer['name'] ?? ''),
                    'gender'        => (int)($customer['gender'] ?? 0),
                    'id_card'       => \app\service\IdCardService::encode($draftIdCard),
                    'phone'         => (string)($customer['phone'] ?? ''),
                    'education'     => (string)($customer['education'] ?? ''),
                    'edu_type'      => (string)($customer['edu_type'] ?? ''),
                    'graduate_date' => (string)($customer['graduate_date'] ?? ''),
                    'company_id'    => (int)($customer['company_id'] ?? 0),
                    'unit_name'     => (string)($customer['unit_name'] ?? ''),
                    'city'          => (string)($customer['city'] ?? ''),
                    'remark'        => (string)($customer['remark'] ?? ''),
                ];
            }

            $flow       = $rc['flow'];
            $stageTimes = [$flow[0]['key'] => date('Y-m-d H:i:s')];

            $signupId = Db::name('exam_signup')->insertGetId([
                'signup_no'     => self::generateNo(),
                'customer_id'   => (int)($customer['id'] ?? 0),
                'staff_id'      => (int)($customer['staff_id'] ?? 0),
                'company_id'    => (int)($customer['company_id'] ?? 0),
                'biz_config_id' => (int)$config['id'],
                'batch_id'       => $batchId,
                'room_id'        => $batch ? (int)$batch['room_id'] : 0,
                'cert_type_id'   => (int)$config['cert_type_id'],
                'action_id'      => (int)$config['action_id'],
                'fee'            => round($fee, 2),
                'stage'          => 1,
                'stage_times'    => json_encode($stageTimes, JSON_UNESCAPED_UNICODE),
                'exception'      => 0,
                'reject_reason'  => '',
                'is_draft'       => $draft ? 1 : 0,
                'rule_override'  => !empty($input['ignore_rules']) ? 1 : 0,
                'snapshot'       => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
                'sign_time'      => $draft ? null : date('Y-m-d H:i:s'),
                'owner_user_id'  => $ownerUserId,
                'remark'         => (string)($input['remark'] ?? ''),
                'status'         => 1,
                'create_by'      => $userId,
                'create_time'    => date('Y-m-d H:i:s'),
            ]);

            $note = ($draft ? '暂存草稿：' : '创建报名单：') . $flow[0]['label'];
            if ($ruleFails) {
                $note .= '（不符合条件，已确认继续）';
            }
            self::addLog($signupId, 0, 1, $note, $userId);

            // v2.99.1：正式报名单联动费用台账（草稿不生成费用）
            if (!$draft) {
                self::syncFee($signupId);
            }

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        return Db::name('exam_signup')->find($signupId);
    }

    /** 草稿转正式：补建考生 + 重复校验 + 复核规则 */
    public static function submitDraft(int $signupId, int $userId, bool $ignoreRules = false): void
    {
        Db::startTrans();
        try {
            $signup = Db::name('exam_signup')->where('id', $signupId)->lock(true)->find();
            if (!$signup || (int)$signup['status'] !== 1) {
                throw new \RuntimeException('报名单不存在');
            }
            if ((int)$signup['is_draft'] !== 1) {
                throw new \RuntimeException('该报名单不是草稿，无需提交');
            }

            $customerId = (int)$signup['customer_id'];
            if ($customerId <= 0) {
                $snapshot = json_decode((string)$signup['snapshot'], true) ?: [];
                $draftCust = (array)($snapshot['customer_draft'] ?? []);
                // v2.99.59：草稿里的身份证号按密文存，取出时解密（兼容历史明文）
                $draftCust['id_card'] = \app\service\IdCardService::decode($draftCust['id_card'] ?? '');
                if (trim((string)($draftCust['id_card'] ?? '')) === '') {
                    throw new \RuntimeException('请先补充考生身份证号后再提交');
                }
                $cust = self::resolveCustomer(['customer' => $draftCust], $userId, false);
                $customerId = (int)($cust['id'] ?? 0);
                if ($customerId <= 0) {
                    throw new \RuntimeException('考生信息不完整，请补全后再提交');
                }
                Db::name('exam_signup')->where('id', $signupId)->update(['customer_id' => $customerId, 'staff_id' => (int)($cust['staff_id'] ?? 0)]);
                $signup['customer_id'] = $customerId;
            }

            Db::name('exam_customer')->where('id', $customerId)->lock(true)->find();
            $dups = self::duplicateCheck($customerId, (int)$signup['biz_config_id'], (int)$signup['batch_id']);
            if ($dups) {
                throw new \RuntimeException('该考生已存在相同的正式报名单（单号 ' . $dups[0]['signup_no'] . '），不能重复提交');
            }

            $snapshot = json_decode((string)$signup['snapshot'], true) ?: [];
            $rules = (array)($snapshot['rules'] ?? []);
            $ctx   = (array)($snapshot['context'] ?? []);
            $rules = array_values(array_filter($rules, function ($r) use ($ctx) {
                $f = (string)($r['field'] ?? '');
                return !(in_array($f, self::MATERIAL_FIELDS, true) && !array_key_exists($f, $ctx));
            }));
            if ($rules && !$ignoreRules) {
                $cust  = Db::name('exam_customer')->find($customerId) ?: [];
                $ctxExtra = array_merge($ctx, ['cert_type_id' => (int)$signup['cert_type_id']]);
                $res   = ExamRuleService::evaluate($rules, self::buildContext($cust, $ctxExtra));
                $fails = array_values(array_filter($res['items'], fn($i) => empty($i['pass'])));
                if ($fails) {
                    $tips = array_map(fn($i) => (string)$i['tip'], array_slice($fails, 0, 3));
                    throw new \RuntimeException('不满足报名条件：' . implode('；', $tips) . '（如确需办理，请勾选「不符合条件仍需办理」后重试）');
                }
            }

            Db::name('exam_signup')->where('id', $signupId)->update([
                'is_draft'      => 0,
                'rule_override' => $ignoreRules ? 1 : (int)$signup['rule_override'],
                'sign_time'     => date('Y-m-d H:i:s'),
                'update_time'   => date('Y-m-d H:i:s'),
            ]);
            self::addLog($signupId, (int)$signup['stage'], (int)$signup['stage'], '草稿转为正式报名单', $userId);

            // v2.99.1：草稿转正式后联动费用台账
            self::syncFee($signupId);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 状态流转（只能单步前进/退回），行锁防并发跳级
     * @throws \RuntimeException
     */
    public static function advance(int $signupId, int $toStage, string $note, int $userId): void
    {
        $signup = Db::name('exam_signup')->where('id', $signupId)->find();
        if (!$signup || (int)$signup['status'] !== 1) {
            throw new \RuntimeException('报名单不存在');
        }
        $flow  = self::flowOfSignup($signup);
        $total = count($flow);
        if ($total === 0) {
            throw new \RuntimeException('该报名单未配置状态流，无法流转');
        }
        $from = (int)$signup['stage'];
        if ($toStage === $from) {
            throw new \RuntimeException('报名单已处于该状态，无需重复操作');
        }
        if (!ExamStatusMachineService::canTransit($from, $toStage, $total)) {
            throw new \RuntimeException('不允许的状态流转：只能前进或退回一个节点');
        }

        $times = json_decode((string)$signup['stage_times'], true) ?: [];
        $times[ExamStatusMachineService::key($flow, $toStage)] = date('Y-m-d H:i:s');

        Db::startTrans();
        try {
            $fresh = Db::name('exam_signup')->where('id', $signupId)->lock(true)->find();
            if (!$fresh || (int)$fresh['stage'] !== $from) {
                throw new \RuntimeException('报名单状态已被他人变更，请刷新后重试');
            }
            Db::name('exam_signup')->where('id', $signupId)->update([
                'stage'         => $toStage,
                'stage_times'   => json_encode($times, JSON_UNESCAPED_UNICODE),
                'reject_reason' => $toStage > $from ? '' : (string)$note,
                'update_time'   => date('Y-m-d H:i:s'),
            ]);
            self::addLog($signupId, $from, $toStage,
                $note !== '' ? $note : ('流转到：' . ExamStatusMachineService::label($flow, $toStage)), $userId);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /** 审核：通过（前进一节点）/驳回（退回一节点并记原因） */
    public static function audit(int $signupId, bool $pass, string $reason, int $userId): void
    {
        $signup = Db::name('exam_signup')->where('id', $signupId)->find();
        if (!$signup || (int)$signup['status'] !== 1) {
            throw new \RuntimeException('报名单不存在');
        }
        $stage = (int)$signup['stage'];
        Db::startTrans();
        try {
            Db::name('exam_audit_record')->insertGetId([
                'signup_id' => $signupId,
                'action'    => $pass ? 'pass' : 'reject',
                'stage'     => $stage,
                'reason'    => $reason,
                'auditor'   => $userId,
                'create_time' => date('Y-m-d H:i:s'),
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
        if ($pass) {
            self::advance($signupId, $stage + 1, '审核通过' . ($reason !== '' ? '：' . $reason : ''), $userId);
        } else {
            if ($reason === '') {
                throw new \RuntimeException('驳回必须填写原因');
            }
            self::advance($signupId, max(1, $stage - 1), '审核驳回：' . $reason, $userId);
        }
    }

    /** 工单维度规则试算（规则取快照，上下文取考生档案 + 已上传材料） */
    public static function evaluateSignup(array $signup, array $extra = []): array
    {
        $snapshot = json_decode((string)($signup['snapshot'] ?? ''), true) ?: [];
        $rules    = (array)($snapshot['rules'] ?? []);
        $ctxExtra = (array)($snapshot['context'] ?? []);
        foreach ($extra as $k => $v) {
            $ctxExtra[$k] = $v;
        }
        $customer = (int)$signup['customer_id'] > 0
            ? (Db::name('exam_customer')->find($signup['customer_id']) ?: []) : [];
        $codes = Db::name('exam_signup_material')
            ->where('signup_id', (int)$signup['id'])->where('status', 1)->column('material_code');
        $ctxExtra['cert_type_id'] = (int)$signup['cert_type_id'];
        $ctx = self::buildContext($customer, $ctxExtra, $codes);
        return ExamRuleService::evaluate($rules, $ctx);
    }

    /** 材料完整性（必传项） */
    public static function materialStatus(int $signupId, array $materials = null): array
    {
        if ($materials === null) {
            $snapshot = json_decode((string)Db::name('exam_signup')->where('id', $signupId)->value('snapshot'), true) ?: [];
            $materials = ExamMaterialService::normalize($snapshot['materials'] ?? []);
        }
        $codes = Db::name('exam_signup_material')
            ->where('signup_id', $signupId)->where('status', 1)->column('material_code');
        $codes = array_values(array_unique($codes));
        return ExamMaterialService::checkRequired($materials, $codes);
    }

    /** 写流转日志 */
    protected static function addLog(int $signupId, int $from, int $to, string $note, int $userId): void
    {
        Db::name('exam_signup_log')->insert([
            'signup_id'  => $signupId,
            'from_stage' => $from,
            'to_stage'   => $to,
            'note'       => mb_substr($note, 0, 255),
            'operator'   => $userId,
            'create_time' => date('Y-m-d H:i:s'),
        ]);
    }

    /** 日期空值归一 */
    protected static function normDate($v): ?string
    {
        $v = trim((string)$v);
        if ($v === '') {
            return null;
        }
        $ts = strtotime($v);
        return $ts ? date('Y-m-d', $ts) : null;
    }
}
