<?php
namespace app\service;

use app\model\OperationLog;
use think\facade\Db;

/**
 * 操作审计日志
 *
 * v2.97：补齐审计字段（对象 / 结果 / 耗时 / 设备 / 控制器方法），
 * 并在全局中间件里对未显式记录日志的写请求做兜底采集。
 */
class OperationLogService
{
    /**
     * 日志中永远不落盘的字段（含常见变体）。
     *
     * 注意：这里按「整段字段名」匹配，避免把 login_token_ttl_hours 这类
     * 正常配置项误判成 token 而被替换成 ***。
     */
    private const SENSITIVE_RE = '/^(password|passwd|pwd|secret|token|authorization|captcha|passphrase|credential|api[_-]?key|access[_-]?key|private[_-]?key|secret[_-]?key|app[_-]?secret|client[_-]?secret)$|(^|_)(password|passwd|pwd|secret|token|api[_-]?key|access[_-]?key|private[_-]?key|secret[_-]?key)$/i';

    /**
     * v2.99.47：个人敏感信息（PII）字段，写操作日志 params 时一律掩码，
     * 避免审计日志本身成为身份证/手机号等的二次泄露源。
     * 独立于 SENSITIVE_RE（密钥/口令类直接 ***），PII 类保留头尾便于辨认。
     */
    private const PII_KEYS = [
        'id_card', 'idcard', 'id_card_no', 'cert_id',
        'phone', 'mobile', 'tel', 'contact_phone', 'receiver_phone', 'customer_phone',
        'bank_no', 'bank_card', 'account_no',
        'credit_code',
        'email', 'mail',
        'password_enc',
    ];

    /** 控制器名 => 模块中文名（自动兜底日志使用） */
    private const MODULE_MAP = [
        'certificate'     => '证照台账',
        'certrenewal'     => '证照续期',
        'certstatuslog'   => '证照状态',
        'certtask'        => '验证任务',
        'certdict'        => '证书专业',
        'company'         => '客户企业',
        'companycert'     => '企业证书',
        'staff'           => '人员管理',
        'secondment'      => '借调管理',
        'contract'        => '合同管理',
        'project'         => '项目管理',
        'matchcenter'     => '智能匹配',
        'conflict'        => '冲突中心',
        'govprogress'     => '政务网进度',
        'examconfig'      => '考试配置',
        'exambatch'       => '考试批次',
        'examsignup'      => '考试报名',
        'examcert'        => '考试证书',
        'fee'             => '费用收款',
        'payaccount'      => '收款账户',
        'push'            => '推送中心',
        'pushchannel'     => '推送渠道',
        'msgtemplate'     => '消息模板',
        'receiver'        => '接收人',
        'alert'           => '提醒中心',
        'alertconfig'     => '提醒规则',
        'policyrule'      => '属地政策',
        'credentialvault' => '账号密码库',
        'crm'             => '客户跟进',
        'registertrack'   => '注册跟踪',
        'user'            => '用户管理',
        'role'            => '角色权限',
        'menu'            => '菜单管理',
        'org'             => '组织架构',
        'orgdepartment'   => '组织架构',
        'dicttype'        => '字典管理',
        'dictdata'        => '字典管理',
        'customfield'     => '自定义字段',
        'systemconfig'    => '系统设置',
        'wecomconfig'     => '企微配置',
        'wecomcontact'    => '企微通讯录',
        'agentkey'        => 'Agent 接入',
        'upload'          => '文件上传',
        'attachdownload'  => '附件下载',
        'logindevice'     => '登录设备',
        'loginlog'        => '登录日志',
        'operationlog'    => '操作日志',
        'recyclebin'      => '回收站',
        'cache'           => '系统缓存',
        'approval'        => '审批中心',
        'import'          => '数据导入',
        'report'          => '统计报表',
        'dashboard'       => '工作台',
        'search'          => '查询中心',
    ];

    /** 方法名 => 动作中文名（自动兜底日志使用） */
    private const ACTION_MAP = [
        'save'                => '保存',
        'create'              => '新增',
        'update'              => '修改',
        'delete'              => '删除',
        'batchdelete'         => '批量删除',
        'import'              => '导入',
        'export'              => '导出',
        'template'            => '下载模板',
        'download'            => '下载',
        'reveal'              => '查看明文',
        'clear'               => '清空',
        'reset'               => '重置',
        'resetcatalog'        => '重置菜单',
        'revoke'              => '撤销',
        'revokeall'           => '批量撤销',
        'sync'                => '同步',
        'syncall'             => '全部同步',
        'syncstatus'          => '同步状态',
        'upload'              => '上传',
        'approve'             => '审核通过',
        'reject'              => '驳回',
        'test'                => '测试',
        'bind'                => '绑定',
        'unbind'              => '解绑',
        'unbindwecom'         => '解绑企微',
        'createaccount'       => '创建账号',
        'bindaccount'         => '绑定账号',
        'importqualification' => '导入资质',
        'deletequalification' => '删除资质',
        'updateitem'          => '更新明细',
        'apply'               => '应用',
    ];

    /** 视为失败结果的关键词（人工日志没有显式状态时用于推断） */
    private const FAIL_RE = '/失败|错误|拒绝|异常|无权|未通过|超时|不存在/';

    /** 已探测到的 operation_log 列（进程内缓存） */
    private static $columns = null;

    /** 当前请求是否已有人工调用 record()，避免中间件重复记录 */
    private static $manualLogged = false;

    /**
     * 从 pathinfo 反推控制器 / 动作。
     *
     * 本项目全部使用显式路由规则（Route::post('xxx', '控制器@方法')），
     * 不走默认的控制器自动解析，因此 $request->controller()/action() 恒为空。
     * 这里按 URL 段兜底解析，例如：
     *   api/admin/system-config/save => ['systemconfig', 'save']
     *   api/captcha                  => ['captcha', '']
     *
     * @return array{0:string,1:string} [小写控制器名, 小写动作名]
     */
    public static function parsePath($request): array
    {
        $path = trim((string)$request->pathinfo(), '/');
        $segments = $path === '' ? [] : array_values(array_filter(explode('/', $path), static fn ($s) => $s !== ''));
        // 去掉固定的 api 前缀与 admin 分组前缀
        if ($segments && strtolower($segments[0]) === 'api') {
            array_shift($segments);
        }
        if ($segments && strtolower($segments[0]) === 'admin') {
            array_shift($segments);
        }
        $action = '';
        $controller = '';
        if ($segments) {
            // 最后一段通常是动作；其余段拼回控制器（连字符统一移除，与 MODULE_MAP 键一致）
            $action = strtolower(str_replace('-', '', (string)array_pop($segments)));
            $controller = strtolower(str_replace('-', '', implode('_', $segments)));
        }
        // 只有一段时（如 api/captcha），它是控制器而非动作
        if ($controller === '' && $action !== '' && !in_array($action, self::ACTION_MAP, true)) {
            $controller = $action;
            $action = '';
        }
        // 公开登录入口路由为 api/login → api\Auth@login，归一化到 auth 以便跳过审计
        if ($controller === 'login') {
            $controller = 'auth';
        }
        return [$controller, $action];
    }

    /**
     * 写入一条人工审计日志（业务代码调用）
     *
     * @param array $extra 可覆盖字段：user / params / method / url / ip / route / http_status / code / duration_ms / result_msg / target
     */
    public static function record(string $module, string $action, string $remark = '', array $extra = []): void
    {
        self::$manualLogged = true;
        try {
            $req  = $extra['request'] ?? app('request');
            $user = $extra['user'] ?? ($req->user ?? []);
            $params = array_key_exists('params', $extra) ? $extra['params'] : $req->param();
            $ua = (string)($extra['user_agent'] ?? $req->header('user-agent', ''));

            $status = (int)($extra['status'] ?? (preg_match(self::FAIL_RE, $remark . $action) ? 0 : 1));
            $row = [
                'user_id'  => (int)($user['id'] ?? 0),
                'username' => (string)($user['real_name'] ?? ($user['username'] ?? '')),
                'module'   => $module,
                'action'   => $action,
                'method'   => (string)($extra['method'] ?? strtoupper($req->method())),
                'url'      => (string)($extra['url'] ?? $req->url()),
                'params'   => self::encodeParams($params),
                'ip'       => (string)($extra['ip'] ?? $req->ip()),
                'remark'   => $remark,
            ];

            $optional = [
                'target'      => (string)($extra['target'] ?? self::targetOf((array)$params)),
                'route'       => (string)($extra['route'] ?? self::routeOf($req)),
                'http_status' => (int)($extra['http_status'] ?? 200),
                'code'        => (int)($extra['code'] ?? 0),
                'duration_ms' => (int)($extra['duration_ms'] ?? 0),
                'user_agent'  => mb_substr($ua, 0, 255, 'UTF-8'),
                'device_name' => (string)($extra['device_name'] ?? DeviceService::deviceName($ua)),
                'result_msg'  => mb_substr((string)($extra['result_msg'] ?? ''), 0, 250, 'UTF-8'),
                'status'      => $status,
            ];
            self::attachOptional($row, $optional);

            $model = OperationLog::create($row);
            // 把日志 id 挂到当前请求，供审计中间件在响应结束后回写耗时 / 结果码
            if ($model && isset($model->id)) {
                try {
                    $req->__op_log_ids = array_merge((array)($req->__op_log_ids ?? []), [(int)$model->id]);
                } catch (\Throwable $e) {
                    // 部分请求对象不支持动态属性时忽略
                }
            }
        } catch (\Throwable $e) {
            // 审计失败不影响业务
        }
    }

    /**
     * 中间件在响应结束后回写人工日志的耗时 / HTTP 状态 / 业务码 / 结果。
     *
     * 业务代码在控制器内写日志时还拿不到这些信息（响应尚未返回），
     * 故由中间件统一补齐，保证人工日志与自动日志字段一致。
     */
    public static function finishRequest($request, array $meta): void
    {
        $ids = (array)($request->__op_log_ids ?? []);
        if (!$ids) {
            return;
        }
        self::ensureColumns();
        $update = array_intersect_key([
            'http_status' => (int)($meta['http_status'] ?? 0),
            'code'        => (int)($meta['code'] ?? 0),
            'duration_ms' => (int)($meta['duration_ms'] ?? 0),
            'result_msg'  => mb_substr((string)($meta['result_msg'] ?? ''), 0, 250, 'UTF-8'),
            'status'      => (int)($meta['status'] ?? 1),
        ], array_flip((array)self::$columns));
        if (!$update) {
            return;
        }
        try {
            OperationLog::whereIn('id', $ids)->update($update);
        } catch (\Throwable $e) {
            // 回写失败不影响业务
        }
    }

    /**
     * 中间件兜底：写请求没有人工日志时，按控制器/方法自动补一条。
     *
     * @param array $meta status / http_status / code / result_msg / duration_ms
     */
    public static function autoRecord($request, array $meta = []): void
    {
        if (self::$manualLogged) {
            return;
        }
        $user = $request->user ?? [];
        if (empty($user['id'])) {
            // 未登录请求（登录、验证码等）由登录日志覆盖，避免刷屏
            return;
        }
        try {
            [$module, $action] = self::describe($request);
            $params = $request->param();
            $ua = (string)$request->header('user-agent', '');
            $row = [
                'user_id'  => (int)$user['id'],
                'username' => (string)($user['real_name'] ?? ($user['username'] ?? '')),
                'module'   => $module,
                'action'   => $action,
                'method'   => strtoupper((string)$request->method()),
                'url'      => (string)$request->url(),
                'params'   => self::encodeParams($params),
                'ip'       => (string)$request->ip(),
                'remark'   => '自动采集（该接口未显式写审计日志）',
            ];
            $optional = [
                'target'      => self::targetOf((array)$params),
                'route'       => self::routeOf($request),
                'http_status' => (int)($meta['http_status'] ?? 200),
                'code'        => (int)($meta['code'] ?? 0),
                'duration_ms' => (int)($meta['duration_ms'] ?? 0),
                'user_agent'  => mb_substr($ua, 0, 255, 'UTF-8'),
                'device_name' => DeviceService::deviceName($ua),
                'result_msg'  => mb_substr((string)($meta['result_msg'] ?? ''), 0, 250, 'UTF-8'),
                'status'      => (int)($meta['status'] ?? 1),
            ];
            self::attachOptional($row, $optional);

            OperationLog::create($row);
        } catch (\Throwable $e) {
            // 审计失败不影响业务
        }
    }

    /**
     * 根据控制器 / 方法推导模块与动作。
     * 显式路由下 controller()/action() 为空，回退到 pathinfo 反推。
     */
    public static function describe($request): array
    {
        $controller = (string)$request->controller();
        $method     = (string)$request->action();
        if ($controller === '' && $method === '') {
            [$controller, $method] = self::parsePath($request);
        }
        $key    = strtolower($controller);
        $module = self::MODULE_MAP[$key] ?? ($controller !== '' ? $controller : '其他');
        $action = self::ACTION_MAP[strtolower($method)] ?? ($method !== '' ? $method : '请求');
        return [$module, $action];
    }

    /**
     * 取操作对象，如 #12、张三、豫建安B…
     */
    public static function targetOf(array $params): string
    {
        $ids = $params['ids'] ?? null;
        if (is_array($ids) && $ids) {
            return '共 ' . count($ids) . ' 条';
        }
        $id = $params['id'] ?? null;
        if (is_scalar($id) && (string)$id !== '' && (int)$id > 0) {
            return '#' . (int)$id;
        }
        foreach (['cert_no', 'company_name', 'name', 'title', 'username', 'staff_name', 'keyword'] as $k) {
            $v = $params[$k] ?? null;
            if (is_scalar($v) && trim((string)$v) !== '') {
                return mb_substr(trim((string)$v), 0, 80, 'UTF-8');
            }
        }
        return '';
    }

    /** 控制器@方法（显式路由下从 pathinfo 反推） */
    public static function routeOf($request): string
    {
        $c = (string)$request->controller();
        $a = (string)$request->action();
        if ($c === '' && $a === '') {
            [$c, $a] = self::parsePath($request);
        }
        return $c === '' ? '' : $c . '@' . $a;
    }

    /**
     * 结构自愈：确保审计扩展列存在（老库无需手工升级）。
     */
    public static function ensureColumns(): void
    {
        if (self::$columns !== null) {
            return;
        }
        $columns = [];
        try {
            $rows = Db::query('SHOW COLUMNS FROM `operation_log`');
            foreach ($rows as $r) {
                $columns[] = (string)($r['Field'] ?? $r['field'] ?? '');
            }
            $alter = [
                'target'      => "ALTER TABLE `operation_log` ADD COLUMN `target` varchar(200) NOT NULL DEFAULT '' COMMENT '操作对象' AFTER `action`",
                'route'       => "ALTER TABLE `operation_log` ADD COLUMN `route` varchar(160) NOT NULL DEFAULT '' COMMENT '控制器@方法' AFTER `url`",
                'http_status' => "ALTER TABLE `operation_log` ADD COLUMN `http_status` smallint unsigned NOT NULL DEFAULT 0 COMMENT 'HTTP状态码' AFTER `params`",
                'code'        => "ALTER TABLE `operation_log` ADD COLUMN `code` int NOT NULL DEFAULT 0 COMMENT '业务返回码' AFTER `http_status`",
                'duration_ms' => "ALTER TABLE `operation_log` ADD COLUMN `duration_ms` int unsigned NOT NULL DEFAULT 0 COMMENT '耗时(毫秒)' AFTER `code`",
                'user_agent'  => "ALTER TABLE `operation_log` ADD COLUMN `user_agent` varchar(255) NOT NULL DEFAULT '' COMMENT 'User-Agent' AFTER `ip`",
                'device_name' => "ALTER TABLE `operation_log` ADD COLUMN `device_name` varchar(100) NOT NULL DEFAULT '' COMMENT '设备名称' AFTER `user_agent`",
                'result_msg'  => "ALTER TABLE `operation_log` ADD COLUMN `result_msg` varchar(255) NOT NULL DEFAULT '' COMMENT '结果说明' AFTER `remark`",
                'status'      => "ALTER TABLE `operation_log` ADD COLUMN `status` tinyint NOT NULL DEFAULT 1 COMMENT '1成功 0失败' AFTER `result_msg`",
            ];
            foreach ($alter as $col => $sql) {
                if (in_array($col, $columns, true)) {
                    continue;
                }
                try {
                    Db::execute($sql);
                    $columns[] = $col;
                } catch (\Throwable $e) {
                    // 并发加列 / 权限不足时忽略，后续仍按已有列写入
                }
            }
        } catch (\Throwable $e) {
            // 表不存在等极端场景：保持空列集合，仅写基础字段
        }
        self::$columns = $columns;
    }

    /**
     * 递归脱敏：敏感键值替换为 ***，其余标量保留。
     */
    private static function redact($data, int $depth = 0)
    {
        if ($depth > 8) {
            return '…';
        }
        if (is_array($data)) {
            $out = [];
            foreach ($data as $k => $v) {
                if (is_string($k) && preg_match(self::SENSITIVE_RE, $k)) {
                    $out[$k] = '***';
                } elseif (is_string($k) && in_array(strtolower($k), self::PII_KEYS, true) && is_scalar($v) && (string)$v !== '') {
                    // v2.99.47：PII 保留头尾掩码，既能识别又不落完整明文
                    $out[$k] = self::maskValue((string)$v);
                } else {
                    $out[$k] = self::redact($v, $depth + 1);
                }
            }
            return $out;
        }
        if (is_object($data)) {
            return self::redact((array)$data, $depth + 1);
        }
        if (is_string($data) && mb_strlen($data, 'UTF-8') > 500) {
            return mb_substr($data, 0, 500, 'UTF-8') . '…';
        }
        return $data;
    }

    /**
     * v2.99.47：日志内 PII 值掩码。复用 MaskService 的头尾保留规则，
     * 避免在审计日志里落完整身份证/手机号/银行卡。
     */
    private static function maskValue(string $v): string
    {
        $v = trim($v);
        if ($v === '') {
            return '';
        }
        try {
            // 含掩码符或已是密文/长 token，直接整体打码避免泄露结构
            if (strpos($v, '*') !== false || strpos($v, 'gcm$') === 0 || strpos($v, 'sm4$') === 0) {
                return '***';
            }
            if (mb_strpos($v, '@') !== false) {
                return MaskService::email($v);
            }
            $len = mb_strlen($v, 'UTF-8');
            if ($len >= 15) {
                return MaskService::idCard($v); // 身份证/银行卡等长编码
            }
            if ($len === 11) {
                return MaskService::phone($v);  // 手机号
            }
            return MaskService::keepPublic($v, 2, 2); // 其余短编码兜底
        } catch (\Throwable $e) {
            return '***';
        }
    }

    /** 参数转 JSON（脱敏 + 截断） */
    private static function encodeParams($params): string
    {
        $json = json_encode(self::redact($params), JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return '';
        }
        if (mb_strlen($json, 'UTF-8') > 8000) {
            return mb_substr($json, 0, 8000, 'UTF-8') . '…(truncated)';
        }
        return $json;
    }

    /** 仅写入数据库实际存在的扩展列 */
    private static function attachOptional(array &$row, array $optional): void
    {
        self::ensureColumns();
        foreach ($optional as $k => $v) {
            if (in_array($k, self::$columns, true)) {
                $row[$k] = $v;
            }
        }
    }
}
