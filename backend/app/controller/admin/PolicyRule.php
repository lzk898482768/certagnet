<?php
namespace app\controller\admin;

use app\service\ExcelService;
use app\service\PersonProfileService;
use app\service\PolicyRuleService;
use think\facade\Db;

/**
 * 属地政策规则配置（管理员）
 *
 * 提供规则的新增/编辑/删除与「试算」能力：
 * 管理员配好规则后，可对任意证书 + 项目即时试算，验证规则是否符合预期。
 */
class PolicyRule extends AdminController
{
    public function index()
    {
        [$page, $limit] = $this->pageParams();
        PersonProfileService::ensureTables();
        // v2.36：政策规则的「适用证书类型」列（老库 git pull 后由入口自愈补齐）
        ensure_column('policy_rule', 'cert_type', "varchar(64) NOT NULL DEFAULT '' COMMENT '适用证书类型(dict: cert_major 一级)，空=全部' AFTER `cert_category`");

        $regionCode = trim((string)$this->request->param('region_code', ''));
        $ruleType   = trim((string)$this->request->param('rule_type', ''));
        $status     = $this->request->param('status', '');
        $keyword    = trim((string)$this->request->param('keyword', ''));

        $q = Db::name('policy_rule')->order('region_code', 'asc')->order('id', 'asc');
        if ($regionCode !== '') {
            $q->where('region_code', $regionCode);
        }
        if ($ruleType !== '') {
            $q->where('rule_type', $ruleType);
        }
        if ($status !== '') {
            $q->where('status', $status);
        }
        if ($keyword !== '') {
            $q->where(function ($w) use ($keyword) {
                $w->where('region_name', 'like', "%{$keyword}%")
                  ->whereOr('cert_name', 'like', "%{$keyword}%")
                  ->whereOr('remark', 'like', "%{$keyword}%");
            });
        }

        $total = (clone $q)->count();
        $list  = (clone $q)->page($page, $limit)->select()->toArray();
        $this->decorateRules($list);

        // 属地列表（供筛选与地区树）
        $regions = Db::name('policy_rule')
            ->field('region_code,region_name,COUNT(*) AS cnt')
            ->group('region_code,region_name')->order('cnt', 'desc')->select()->toArray();

        return json(r_success(['list' => $list, 'total' => $total, 'regions' => $regions]));
    }

    /** 补充规则展示字段（类型/级别/参数可读化 + 解析后的参数） */
    protected function decorateRules(array &$list): void
    {
        $typeLabels = $this->dictLabels('policy_rule_type', PolicyRuleService::TYPES);
        $sevLabels  = $this->dictLabels('policy_severity', PolicyRuleService::SEVERITY);
        foreach ($list as &$r) {
            $r['type_text']        = $typeLabels[$r['rule_type']] ?? $r['rule_type'];
            $r['severity_text']    = $sevLabels[(int)$r['severity']] ?? '未知';
            $r['params']           = PolicyRuleService::parseExpr((string)($r['rule_expr'] ?? ''));
            $r['region_text']      = (string)($r['region_name'] ?: '') ?: '全国通用';
            // v2.36：适用类别已统一为证书类型，展示取类型优先、类别回落
            $r['cert_type']        = (string)($r['cert_type'] ?? ($r['cert_category'] ?? ''));
            $r['scope_text']       = $this->scopeText((string)($r['cert_type'] ?? ''), (string)($r['cert_name'] ?? ''));
        }
        unset($r);
    }

    /**
     * 字典标签优先，缺失时回退内置常量
     * @param array $defaults [value => label]
     * @return array [value => label]
     */
    protected function dictLabels(string $typeCode, array $defaults): array
    {
        try {
            $rows = Db::name('dict_data')
                ->where('type_code', $typeCode)->where('status', 1)
                ->order('sort', 'asc')->select()->toArray();
            if ($rows) {
                $out = [];
                foreach ($rows as $r) {
                    $out[(string)$r['dict_value']] = (string)$r['dict_label'];
                }
                return $out ?: $defaults;
            }
        } catch (\Throwable $e) {
            // 字典表异常时静默回退
        }
        return $defaults;
    }

    protected function scopeText(string $category, string $certName): string
    {
        $parts = [];
        if ($category !== '') { $parts[] = '类别:' . $category; }
        if ($certName !== '') { $parts[] = '专业:' . $certName; }
        return $parts ? implode(' / ', $parts) : '全部证书';
    }

    /** 元数据：规则类型、参数定义、级别选项（字典标签优先，常量兜底） */
    public function meta()
    {
        return json(r_success([
            'types'    => $this->dictLabels('policy_rule_type', PolicyRuleService::TYPES),
            'params'   => PolicyRuleService::PARAMS,
            'severity' => $this->dictLabels('policy_severity', PolicyRuleService::SEVERITY),
        ]));
    }

    /**
     * 试算：对指定证书 + 项目评估政策合规性
     * 前端用于「配证前先看看这个证书在这个项目合不合规」
     */
    public function evaluate()
    {
        $certId    = (int)$this->request->param('cert_id', 0);
        $projectId = (int)$this->request->param('project_id', 0);
        if (!$certId) {
            return json(r_error('请选择证书', 400));
        }
        $cert = Db::name('certificate')->where('id', $certId)->find();
        if (!$cert) {
            return json(r_error('证书不存在', 400));
        }
        // 社保取人档优先（v2.27 社保已上移）
        $social = PersonProfileService::resolveSocial((int)$cert['staff_id'], [$cert]);

        $issues = PolicyRuleService::evaluateCert($cert, $projectId, $social);
        $region = PolicyRuleService::regionOf($projectId);

        return json(r_success([
            'pass'    => PolicyRuleService::passes($issues),
            'issues'  => $issues,
            'region'  => $region,
            'social'  => $social,
        ]));
    }

    public function save()
    {
        $id       = (int)$this->request->post('id', 0);
        $ruleType = trim((string)$this->request->post('rule_type', ''));
        if (!isset(PolicyRuleService::TYPES[$ruleType])) {
            return json(r_error('规则类型不合法', 400));
        }
        $severity = (int)$this->request->post('severity', 1);
        if (!isset(PolicyRuleService::SEVERITY[$severity])) {
            $severity = 1;
        }

        // 参数：前端按类型传 params 对象，服务端按该类型的白名单键过滤后组装
        $params = $this->request->post('params', []);
        $params = is_array($params) ? $params : [];
        $allow  = array_column(PolicyRuleService::PARAMS[$ruleType], 'key');
        $clean  = [];
        foreach ($allow as $key) {
            if (array_key_exists($key, $params)) {
                $clean[$key] = is_scalar($params[$key]) ? trim((string)$params[$key]) : '';
            }
        }
        $expr = PolicyRuleService::buildExpr($clean);

        $regionCode = trim((string)$this->request->post('region_code', ''));
        // v2.78：证书类型/专业组合校验（专业已登记字典时必须归属所选类型）
        $ruleTypeVal = trim((string)$this->request->post('cert_type', '')) ?: trim((string)$this->request->post('cert_category', ''));
        $pair = \app\service\CertDictService::normalizePair($ruleTypeVal, trim((string)$this->request->post('cert_name', '')));
        if ($pair['error'] !== '') {
            return json(r_error($pair['error'], 400));
        }
        $data = [
            'region_code'   => $regionCode,
            'region_name'   => trim((string)$this->request->post('region_name', '')),
            'rule_type'     => $ruleType,
            // v2.36：适用证书类别已统一为证书类型，两列同步（兼容旧调用方只传 cert_category）
            'cert_category' => $pair['type'] ?: trim((string)$this->request->post('cert_category', '')),
            'cert_type'     => $pair['type'],
            'cert_name'     => $pair['name'],
            'rule_expr'     => $expr,
            'severity'      => $severity,
            'remark'        => trim((string)$this->request->post('remark', '')),
            'status'        => (int)$this->request->post('status', 1) === 1 ? 1 : 0,
        ];

        // 带参规则不能为空（否则规则无意义）
        if ($expr === '') {
            return json(r_error('请配置规则参数', 400));
        }

        if ($id) {
            Db::name('policy_rule')->where('id', $id)->update($data);
            $this->log('政策规则', '更新', "#{$id} " . $data['region_name']);
            return json(r_success(['id' => $id], '更新成功'));
        }
        $newId = Db::name('policy_rule')->insertGetId($data);
        $this->log('政策规则', '新增', "{$data['region_name']} " . PolicyRuleService::TYPES[$ruleType]);
        return json(r_success(['id' => $newId], '新增成功'));
    }

    public function delete()
    {
        $id  = (int)$this->request->post('id', 0);
        $row = Db::name('policy_rule')->where('id', $id)->find();
        if (!$row) {
            return json(r_error('规则不存在', 400));
        }
        Db::name('policy_rule')->where('id', $id)->delete();
        $this->log('政策规则', '删除', "#{$id} " . ($row['region_name'] ?: '全国通用'));
        return json(r_success([], '已删除'));
    }

    /** 批量删除 */
    public function batchDelete()
    {
        $ids = array_values(array_filter(array_map('intval', (array)$this->request->post('ids/a', []))));
        if (!$ids) {
            return json(r_error('请选择规则', 400));
        }
        Db::name('policy_rule')->whereIn('id', $ids)->delete();
        $this->log('政策规则', '批量删除', count($ids) . ' 条');
        return json(r_success([], '已删除 ' . count($ids) . ' 条'));
    }

    /** 批量启停 */
    public function batchStatus()
    {
        $ids    = array_values(array_filter(array_map('intval', (array)$this->request->post('ids/a', []))));
        $status = (int)$this->request->post('status', -1);
        if (!$ids) {
            return json(r_error('请选择规则', 400));
        }
        if (!in_array($status, [0, 1], true)) {
            return json(r_error('状态不合法', 400));
        }
        Db::name('policy_rule')->whereIn('id', $ids)->update(['status' => $status]);
        $this->log('政策规则', '批量启停', count($ids) . ' 条 → ' . ($status ? '启用' : '停用'));
        return json(r_success([], '已' . ($status ? '启用' : '停用') . ' ' . count($ids) . ' 条'));
    }

    /** 下载导入模板（表头 + 一行示例） */
    public function template()
    {
        $path = ExcelService::template('policy_rule');
        return download_utf8($path, '政策规则导入模板.xlsx');
    }

    /** 导出政策规则清单 */
    public function export()
    {
        PersonProfileService::ensureTables();
        $rows = Db::name('policy_rule')->order('region_code', 'asc')->order('id', 'asc')->select()->toArray();
        $typeLabels = $this->dictLabels('policy_rule_type', PolicyRuleService::TYPES);
        $sevLabels  = $this->dictLabels('policy_severity', PolicyRuleService::SEVERITY);
        $formatted  = [];
        foreach ($rows as $r) {
            $formatted[] = [
                'region_name'     => $r['region_name'] ?? '',
                'region_code'     => $r['region_code'] ?? '',
                'rule_type_text'  => $typeLabels[$r['rule_type']] ?? $r['rule_type'],
                // v2.36：导出列改为「适用类型」，老数据无 cert_type 时用类别回落
                'cert_type'       => $r['cert_type'] ?: ($r['cert_category'] ?? ''),
                'cert_name'       => $r['cert_name'] ?? '',
                'rule_expr'       => $r['rule_expr'] ?? '',
                'severity_text'   => $sevLabels[(int)$r['severity']] ?? '',
                'remark'          => $r['remark'] ?? '',
            ];
        }
        $path = ExcelService::export('policy_rule', $formatted);
        return download_utf8($path, '政策规则_' . date('Ymd') . '.xlsx');
    }
}