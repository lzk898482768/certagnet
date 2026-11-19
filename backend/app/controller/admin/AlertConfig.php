<?php
namespace app\controller\admin;

use app\model\CompanyAlertConfig;
use app\model\ClientCompany;
use think\facade\Db;

/**
 * 提醒规则设置
 *
 * 两套配置：
 *  - 全局默认规则（is_global=1，company_id=0）：所有「未单独配置」的企业都用它
 *  - 企业规则：针对单个企业，优先级高于全局
 * 另提供批量配置：一次给多家企业套用同一套规则（或恢复为「跟随全局」）。
 */
class AlertConfig extends AdminController
{
    /** 默认提醒节点（既无企业配置也无全局配置时的兜底） */
    protected const DEFAULT_NODES = ['1month', '7day', '3day'];

    /** 允许的提醒节点 / 提醒方式（防止脏值写入后永不命中） */
    protected const NODE_WHITELIST = ['1year', '6month', '3month', '2month', '1month', '15day', '7day', '3day', '1day'];
    protected const WAY_WHITELIST  = ['site', 'sms', 'email', 'wecom', 'wecom_app', 'mp'];

    /**
     * 校验提醒规则：节点/方式不能保存为空（空规则等于永不提醒），且只接受白名单值
     * @return string|null 错误信息；null=通过
     */
    protected function validateRule(array $data): ?string
    {
        foreach (['alert_nodes' => '提醒节点', 'alert_ways' => '提醒方式'] as $field => $label) {
            if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
                continue; // 未提交该字段视为不修改
            }
            $list = is_array($data[$field]) ? $data[$field] : json_decode((string)$data[$field], true);
            if (!is_array($list) || empty($list)) {
                return $label . '不能为空：请至少选择一项，否则到期提醒永远不会触发；如需停用请逐张证书关闭「到期提醒」';
            }
            $whitelist = $field === 'alert_nodes' ? self::NODE_WHITELIST : self::WAY_WHITELIST;
            $invalid   = array_diff(array_map('strval', $list), $whitelist);
            if (!empty($invalid)) {
                return $label . '包含不支持的取值：' . implode('、', $invalid);
            }
        }
        return null;
    }

    /** 确保 is_global 列存在（老库自愈） */
    protected function ensureGlobal(): bool
    {
        return $this->ensureColumn(
            'company_alert_config', 'is_global',
            "tinyint NOT NULL DEFAULT 0 COMMENT '1=全局默认规则（适用于未单独配置的企业）' AFTER `company_id`"
        );
    }

    /** 查询单套配置：company_id=0 表示取全局默认 */
    public function get()
    {
        $this->ensureGlobal();
        $companyId = (int)$this->request->param('company_id', 0);
        $own = $companyId ? CompanyAlertConfig::where('company_id', $companyId)->find() : null;
        $cfg = $own ?: CompanyAlertConfig::where('is_global', 1)->find();
        if (!$cfg) {
            $cfg = [
                'company_id'       => $companyId,
                'is_global'        => $companyId ? 0 : 1,
                'alert_nodes'      => self::DEFAULT_NODES,
                'alert_ways'       => [],
                'default_priority' => '普通',
                'is_default'       => true,   // 既无企业配置也无全局配置
                'scope'            => 'none',
            ];
        }
        $arr = self::decodeCfg($cfg);
        $arr['is_default'] = !$own && !CompanyAlertConfig::where('is_global', 1)->find();
        // 明确告知前端这份配置来自哪里，避免「回退到全局」被误判为「该企业已单独配置」
        $arr['scope'] = $own ? 'company' : ($companyId ? 'global' : 'global');
        $arr['is_own'] = $own ? 1 : 0;
        $arr['global'] = $this->globalCfg();
        return json(r_success($arr));
    }

    /** 配置总览：全局 1 条 + 各企业 1 条，便于看出哪些企业已单独配置 */
    public function index()
    {
        $hasGlobal = $this->ensureGlobal();
        $q = CompanyAlertConfig::with(['company']);
        if ($hasGlobal) {
            $q->orderRaw('is_global DESC, id ASC');
        } else {
            $q->order('id', 'asc');
        }
        $q = $this->applyCompanyScope($q);
        $list = $q->select()->toArray();
        foreach ($list as &$r) {
            $r['is_global']    = (int)($r['is_global'] ?? 0);
            $r['company_name'] = $r['is_global'] ? '全局默认' : ($r['company']['company_name'] ?? '');
            $r['scope_text']   = $r['is_global'] ? '全局' : '企业';
        }
        unset($r);
        $companiesQ = ClientCompany::field('id,company_name');
        $companiesQ = $this->scopeCompany($companiesQ, 'id');
        $companies = $companiesQ->select()->toArray();
        $setIds    = [];
        foreach ($list as $r) {
            if (!$r['is_global']) {
                $setIds[] = (int)$r['company_id'];
            }
        }
        return json(r_success([
            'list'       => $list,
            'companies'  => $companies,
            'configured' => $setIds,
        ]));
    }

    /**
     * 保存配置
     * company_id=0（或 is_global=1）→ 保存全局默认规则
     */
    public function save()
    {
        $this->ensureGlobal();
        $companyId = (int)$this->request->post('company_id', 0);
        $isGlobal  = (int)$this->request->post('is_global', 0) === 1 || $companyId === 0;
        if ($isGlobal && !$this->isAdmin()) {
            return json(r_error('仅管理员可修改全局默认提醒规则', 403));
        }
        if (!$isGlobal && !$this->canAccessCompany($companyId)) {
            return json(r_error('无权修改该企业的提醒规则', 403));
        }
        $data      = $this->request->post(['alert_nodes', 'alert_ways', 'default_priority']);
        if ($err = $this->validateRule($data)) {
            return json(r_error($err, 400));
        }
        if (is_array($data['alert_nodes'] ?? null)) {
            $data['alert_nodes'] = json_encode($data['alert_nodes'], JSON_UNESCAPED_UNICODE);
        }
        if (is_array($data['alert_ways'] ?? null)) {
            $data['alert_ways'] = json_encode($data['alert_ways'], JSON_UNESCAPED_UNICODE);
        }
        if ($isGlobal) {
            $data['is_global']  = 1;
            $data['company_id'] = 0;
            $cfg = CompanyAlertConfig::where('is_global', 1)->find();
            if ($cfg) {
                CompanyAlertConfig::where('id', $cfg->id)->update($data);
            } else {
                CompanyAlertConfig::create($data);
            }
            $this->log('提醒规则', '保存', '全局默认');
            return json(r_success([], '全局默认规则已保存'));
        }
        $cfg = CompanyAlertConfig::where('company_id', $companyId)->find();
        if ($cfg) {
            CompanyAlertConfig::where('id', $cfg->id)->update($data);
        } else {
            $data['company_id'] = $companyId;
            CompanyAlertConfig::create($data);
        }
        $this->log('提醒规则', '保存', "企业#{$companyId}");
        return json(r_success([], '保存成功'));
    }

    /**
     * 批量配置：一次给多家企业套用同一套规则
     * mode=global  → 删除这些企业的独立配置，改为跟随全局默认
     * mode=custom  → 按传入的 alert_nodes / alert_ways / default_priority 逐家写入
     */
    public function batch()
    {
        $this->ensureGlobal();
        $ids  = $this->request->post('company_ids/a', []);
        $mode = $this->request->post('mode', 'custom');
        $ids  = array_values(array_filter(array_map('intval', (array)$ids)));
        if (empty($ids)) {
            return json(r_error('请至少选择一家企业', 400));
        }
        foreach ($ids as $cid) {
            if (!$this->canAccessCompany((int)$cid)) {
                return json(r_error('无权配置所选企业中的部分企业', 403));
            }
        }
        if ($mode === 'global') {
            CompanyAlertConfig::where('is_global', 0)->whereIn('company_id', $ids)->delete();
            $this->log('提醒规则', '批量恢复全局', count($ids) . ' 家企业');
            return json(r_success(['count' => count($ids)], '已恢复为跟随全局默认规则'));
        }
        $data = $this->request->post(['alert_nodes', 'alert_ways', 'default_priority']);
        if ($err = $this->validateRule($data)) {
            return json(r_error($err, 400));
        }
        if (is_array($data['alert_nodes'] ?? null)) {
            $data['alert_nodes'] = json_encode($data['alert_nodes'], JSON_UNESCAPED_UNICODE);
        }
        if (is_array($data['alert_ways'] ?? null)) {
            $data['alert_ways'] = json_encode($data['alert_ways'], JSON_UNESCAPED_UNICODE);
        }
        $count = 0;
        foreach ($ids as $cid) {
            $cfg = CompanyAlertConfig::where('company_id', $cid)->find();
            if ($cfg) {
                CompanyAlertConfig::where('id', $cfg->id)->update($data);
            } else {
                CompanyAlertConfig::create($data + ['company_id' => $cid]);
            }
            $count++;
        }
        $this->log('提醒规则', '批量配置', "{$count} 家企业");
        return json(r_success(['count' => $count], "已为 {$count} 家企业套用该规则"));
    }

    /** 取某企业的配置（企业优先，其次全局） */
    protected function findCfg(int $companyId)
    {
        $cfg = null;
        if ($companyId) {
            $cfg = CompanyAlertConfig::where('company_id', $companyId)->find();
        }
        if (!$cfg) {
            $cfg = CompanyAlertConfig::where('is_global', 1)->find();
        }
        return $cfg ? self::decodeCfg($cfg) : null;
    }

    protected function globalCfg(): ?array
    {
        $cfg = CompanyAlertConfig::where('is_global', 1)->find();
        return $cfg ? self::decodeCfg($cfg) : null;
    }

    /**
     * 把配置行（数组或模型）统一成数组，并把 alert_nodes / alert_ways
     * 的 JSON 字符串解码成数组，方便前端直接渲染勾选项。
     */
    protected static function decodeCfg($cfg): array
    {
        $arr = is_array($cfg) ? $cfg : $cfg->toArray();
        foreach (['alert_nodes', 'alert_ways'] as $f) {
            if (!isset($arr[$f]) || $arr[$f] === '' || $arr[$f] === null) {
                $arr[$f] = [];
            } elseif (is_string($arr[$f])) {
                $dec = json_decode($arr[$f], true);
                $arr[$f] = is_array($dec) ? $dec : [];
            }
        }
        return $arr;
    }
}
