<?php
namespace app\controller\admin;

use app\model\SystemConfig as SystemConfigModel;
use app\service\WebDavService;
use think\facade\Cache;
use think\facade\Db;

/**
 * 系统基本信息设置（键值对存储）
 */
class SystemConfig extends AdminController
{
    /** 名称含敏感词但实际不是密钥的配置键（避免被误掩码） */
    protected const NON_SENSITIVE_KEYS = ['login_token_ttl_hours'];

    /** 敏感配置键：任何角色都只返回掩码，避免前端/日志泄露 */
    protected function isSensitiveKey(string $key): bool
    {
        if (in_array($key, self::NON_SENSITIVE_KEYS, true)) {
            return false;
        }
        return (bool)preg_match('/secret|password|passwd|pwd|token|private[_-]?key|access[_-]?key|app[_-]?key/i', $key);
    }

    protected function maskConfigList(array $list): array
    {
        foreach ($list as &$row) {
            if ($this->isSensitiveKey((string)($row['config_key'] ?? ''))) {
                $row['config_value'] = '******';
            }
        }
        unset($row);
        return $list;
    }

    /**
     * v2.46：WebDAV 附件存储「测试连接」（仅管理员）
     *
     * 表单未保存也允许直接测：非空 POST 值覆盖已保存配置；
     * 密码传掩码（******）或留空时，使用已保存的真实密码。
     */
    public function webdavTest()
    {
        $this->requireAdmin();
        $o = [
            'base'   => trim((string)$this->request->post('url', '')),
            'dir'    => (string)$this->request->post('dir', ''),
            'user'   => trim((string)$this->request->post('user', '')),
            'passwd' => (string)$this->request->post('passwd', ''),
            'verify' => trim((string)$this->request->post('verify', '')),
        ];
        $override = [];
        foreach ($o as $k => $v) {
            if ($v === '') {
                continue; // 未填写 → 用已保存值
            }
            if ($k === 'passwd' && $v === '******') {
                continue; // 掩码未改 → 用已保存密码
            }
            $override[$k] = $v;
        }
        $r = WebDavService::test($override);
        if (empty($r['ok'])) {
            return json(r_error($r['msg'] ?? '连接失败', 400, $r));
        }
        $this->log('系统设置', '测试', '附件 WebDAV 连接成功');
        return json(r_success($r, 'WebDAV 连接正常'));
    }

    /**
     * v2.99.48：S3 兼容对象存储「测试连接」（仅管理员）
     *
     * 表单未保存也允许直接测：非空 POST 值覆盖已保存配置；
     * AccessKey/SecretKey 传掩码（******）或留空时，使用已保存的真实值。
     */
    public function s3Test()
    {
        $this->requireAdmin();
        $o = [
            'endpoint'   => trim((string)$this->request->post('endpoint', '')),
            'region'     => trim((string)$this->request->post('region', '')),
            'bucket'     => trim((string)$this->request->post('bucket', '')),
            'access_key' => (string)$this->request->post('access_key', ''),
            'secret_key' => (string)$this->request->post('secret_key', ''),
            'prefix'     => (string)$this->request->post('prefix', ''),
            'path_style' => (string)$this->request->post('path_style', ''),
            'verify'     => (string)$this->request->post('verify', ''),
        ];
        $override = [];
        foreach ($o as $k => $v) {
            // 对象前缀允许显式清空（空值代表存到桶根），必须覆盖已保存值；
            // 其他字段留空表示「沿用已保存配置」。
            if ($v === '' && $k !== 'prefix') {
                continue; // 未填写 → 用已保存值
            }
            if (in_array($k, ['access_key', 'secret_key'], true) && $v === '******') {
                continue; // 掩码未改 → 用已保存密钥
            }
            $override[$k] = $v;
        }
        $r = \app\service\S3StorageService::test($override);
        if (empty($r['ok'])) {
            return json(r_error($r['msg'] ?? '连接失败', 400, $r));
        }
        $this->log('系统设置', '测试', '附件 S3 对象存储连接成功');
        return json(r_success($r, 'S3 连接正常'));
    }

    /**
     * v2.86：腾讯云 OCR「测试配置」（仅管理员）
     * 表单未保存也允许测：非空 POST 值覆盖已保存配置；掩码/留空用已保存值。
     */
    public function ocrTest()
    {
        $this->requireAdmin();
        $override = [
            'enable'     => '1',
            'secret_id'  => trim((string)$this->request->post('secret_id', '')),
            'secret_key' => (string)$this->request->post('secret_key', ''),
            'region'     => trim((string)$this->request->post('region', '')) ?: 'ap-guangzhou',
        ];
        if ($override['secret_id'] === '' || $override['secret_key'] === '' || $override['secret_key'] === '******') {
            return json(r_error('请填写 SecretId 与 SecretKey', 400));
        }
        $r = \app\service\ExamOcrService::testWith($override);
        if (empty($r['ok'])) {
            return json(r_error($r['msg'] ?? '测试失败', 400, $r));
        }
        $this->log('系统设置', '测试', '腾讯云 OCR 配置有效');
        return json(r_success($r, '腾讯云 OCR 配置有效'));
    }

    /**
     * v2.91：应急厅证书查询中继「测试连接」（仅管理员）
     * 表单未保存也允许直接测：非空 POST 值覆盖已保存配置；掩码/留空用已保存值。
     */
    public function relayTest()
    {
        $this->requireAdmin();
        $baseUrl = trim((string)$this->request->post('base_url', ''));
        $apiKey  = (string)$this->request->post('api_key', '');
        $timeout = (int)$this->request->post('timeout', 15);

        $override = ['timeout' => $timeout > 0 ? $timeout : 15];
        if ($baseUrl !== '') {
            $override['base_url'] = $baseUrl;
        }
        if ($apiKey !== '' && $apiKey !== '******') {
            $override['api_key'] = $apiKey;
        }

        $r = \app\service\MemPersonCollectService::test($override);
        if (empty($r['ok'])) {
            return json(r_error($r['msg'] ?? '中继连接失败', 400, $r));
        }
        $this->log('系统设置', '测试', '应急厅证书查询中继连接正常');
        return json(r_success($r, '中继连接正常'));
    }

    /**
     * v2.96：老库运行时补齐登录安全默认配置。
     * 仓库约定升级只做 git pull，不执行 init_data.sql，因此这里幂等补键。
     */
    protected function ensureSecurityDefaults(): void
    {
        $defaults = [
            'login_token_ttl_hours' => ['3', '登录超时（小时）', '登录 token 有效期，默认 3 小时，范围 1-720'],
            'totp_trust_days'       => ['3', '信任设备有效期（天）', '勾选「信任此设备」后免二次验证的有效期，默认 3 天，范围 1-365'],
            'mask_plain_roles'      => ['["admin"]', '可查看明文的角色', '脱敏关闭后允许查看完整敏感信息的角色编码（JSON 数组），超管始终可见'],
            'mask_matrix'           => ['{}', '字段级脱敏矩阵', '按角色配置每个敏感字段的显示方式（JSON：{角色: {字段: plain|mask|hide}}），缺省为脱敏'],
        ];
        foreach ($defaults as $key => [$value, $name, $remark]) {
            try {
                if (SystemConfigModel::where('config_key', $key)->find()) {
                    continue;
                }
                SystemConfigModel::create([
                    'config_key'   => $key,
                    'config_value' => $value,
                    'config_name'  => $name,
                    'config_group' => 'security',
                    'remark'       => $remark,
                ]);
            } catch (\Throwable $e) {
                // 并发插入唯一键冲突等场景静默忽略，读取时仍会走代码默认值
            }
        }
    }

    /** 读取全部配置（同时返回 list 与 map；敏感值掩码） */
    public function get()
    {
        $this->ensureSecurityDefaults();
        $list = $this->maskConfigList(
            SystemConfigModel::order('id', 'asc')->select()->toArray()
        );
        $map  = [];
        foreach ($list as $row) {
            $map[$row['config_key']] = $row['config_value'];
        }
        // v2.97：数据安全页需要角色多选，仅管理员返回（角色编码 + 名称）
        $roles = [];
        if ($this->isAdmin()) {
            $roles = \app\model\SysRole::order('id', 'asc')
                ->field('id,role_code,role_name,status')
                ->select()->toArray();
        }
        return json(r_success(['list' => $list, 'map' => $map, 'roles' => $roles, 'mask_fields' => \app\service\MaskService::FIELDS]));
    }

    /** 批量保存（接收 {key: value}，兼容 data / config 两种提交键名） */
    public function save()
    {
        $this->requireAdmin();

        $data = $this->request->post('data/a', []);
        if (empty($data) || !is_array($data)) {
            $data = $this->request->post('config/a', []);
        }
        // 兜底：整个 POST 体就是键值映射
        if (empty($data) || !is_array($data)) {
            $raw = $this->request->post();
            $skip = ['id', 'data', 'config', 's', 'timestamp'];
            $data = [];
            foreach ($raw as $k => $v) {
                if (!in_array((string)$k, $skip, true) && is_scalar($v)) {
                    $data[$k] = $v;
                }
            }
        }
        if (empty($data) || !is_array($data)) {
            return json(r_error('无有效配置数据', 400));
        }

        $saved = 0;
        // v2.96：认证安全参数白名单与范围校验（防止误填 0 / 负数导致永不过期或频繁掉线）
        $securityRanges = [
            'login_token_ttl_hours' => [1, 720, 3],
            'totp_trust_days'       => [1, 365, 3],
        ];
        foreach ($data as $key => $value) {
            $key = trim((string)$key);
            if ($key === '') {
                continue;
            }
            // 企微配置只能走「企微登录配置」页（含白名单、secret 掩码与角色安全校验），
            // 防止从通用键值接口绕过校验直接改 CorpID/Secret/默认角色。
            if (str_starts_with($key, 'wecom_')) {
                continue;
            }
            if (isset($securityRanges[$key])) {
                [$min, $max, $def] = $securityRanges[$key];
                $num = (int)$value;
                if ($num <= 0) {
                    $num = $def;
                }
                $num = max($min, min($max, $num));
                $value = (string)$num;
            }
            // v2.97：可查看明文的角色（只保留真实角色编码；超管始终保留）
            if ($key === 'mask_plain_roles') {
                $roles = is_array($value)
                    ? $value
                    : (json_decode((string)$value, true) ?: explode(',', (string)$value));
                $roles = array_values(array_unique(array_filter(array_map(
                    static fn ($v) => trim((string)$v),
                    (array)$roles
                ))));
                $valid = Db::name('sys_role')
                    ->whereIn('role_code', $roles ?: ['__none__'])
                    ->column('role_code');
                $roles = array_values(array_intersect($roles, array_map('strval', $valid)));
                if (!in_array('admin', $roles, true)) {
                    $roles[] = 'admin';
                }
                $value = json_encode($roles, JSON_UNESCAPED_UNICODE);
            }
            // v2.99.51：字段级脱敏矩阵（只保留真实角色 + 合法字段 + 三态值）
            if ($key === \app\service\MaskService::MATRIX_KEY) {
                $matrix = \app\service\MaskService::normalizeMatrix($value);
                if ($matrix) {
                    $validRoles = Db::name('sys_role')
                        ->whereIn('role_code', array_keys($matrix))
                        ->column('role_code');
                    $validRoles = array_map('strval', $validRoles);
                    $matrix = array_intersect_key($matrix, array_flip($validRoles));
                }
                $value = json_encode((object)$matrix, JSON_UNESCAPED_UNICODE);
            }
            $val = is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE);
            // 前端把掩码值原样回传时表示「不修改」，不能把 ****** 写进真正的密钥。
            if ($this->isSensitiveKey($key) && $val === '******') {
                continue;
            }
            $row = SystemConfigModel::where('config_key', $key)->find();
            if ($row) {
                $row->save(['config_value' => $val]);
            } else {
                SystemConfigModel::create([
                    'config_key'   => $key,
                    'config_value' => $val,
                    'config_name'  => $key,
                ]);
            }
            $saved++;
        }
        if ($saved === 0) {
            return json(r_error('无有效配置数据', 400));
        }
        // 清掉公开接口的缓存，保证登录页等处立即生效
        Cache::delete('sys_config_map');
        $this->log('系统设置', '更新', '基本信息');
        $map = [];
        $list = $this->maskConfigList(SystemConfigModel::select()->toArray());
        foreach ($list as $row) {
            $map[$row['config_key']] = $row['config_value'];
        }
        return json(r_success(['map' => $map], '保存成功'));
    }
}
