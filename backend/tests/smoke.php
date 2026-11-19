<?php
/**
 * 轻量冒烟测试（无需 PHPUnit / 数据库）
 *
 * 运行：cd backend && php tests/smoke.php
 * 退出码：0 全部通过；1 存在失败
 *
 * 覆盖：提醒节点解析、默认节点回退、导入质量校验、已到期/即将到期查询口径。
 * 数据库相关断言只生成 SQL 并比对语义，不连接数据库，便于 CI 直接执行。
 */

require __DIR__ . '/../vendor/autoload.php';

$app = new think\App();
$app->initialize();

$fail = 0;
$pass = 0;

function ok(string $name, bool $cond, string $detail = ''): void
{
    global $fail, $pass;
    if ($cond) {
        $pass++;
        echo "  ok   {$name}\n";
    } else {
        $fail++;
        echo "  FAIL {$name}" . ($detail !== '' ? " -> {$detail}" : '') . "\n";
    }
}

function invokeStatic(string $class, string $method, array $args = [])
{
    $m = new ReflectionMethod($class, $method);
    $m->setAccessible(true);
    return $m->invokeArgs(null, $args);
}

echo "[1] 提醒节点解析\n";
ok('7day => 7', invokeStatic(app\service\CertExpireService::class, 'nodeToDays', ['7day']) === 7);
ok('1month => 30', invokeStatic(app\service\CertExpireService::class, 'nodeToDays', ['1month']) === 30);
ok('1year => 365', invokeStatic(app\service\CertExpireService::class, 'nodeToDays', ['1year']) === 365);
ok('非法节点 => null', invokeStatic(app\service\CertExpireService::class, 'nodeToDays', ['nope']) === null);

echo "[2] 空提醒节点回退默认值\n";
$fakeCert = new class {
    public $alert_rules = null;
};
// 直接注入“企业规则存在但节点为空”的快照，避免依赖本机数据库是否配置了全局规则
$prop = new ReflectionProperty(app\service\CertExpireService::class, 'cfgMemo');
$prop->setAccessible(true);
$prop->setValue(null, ['alertcfg:999999' => (object)['alert_nodes' => []]]);
$nodes = invokeStatic(app\service\CertExpireService::class, 'resolveNodes', [$fakeCert, 999999]);
$prop->setValue(null, []);
ok('配置存在但节点为空时使用默认节点', $nodes === ['1month', '7day', '3day'], json_encode($nodes));

echo "[3] 导入质量校验\n";
$w = app\service\ImportService::qualityWarnings('staff', [
    'id_card' => '123', 'phone' => 'abc', 'email' => 'not-an-email',
]);
ok('身份证格式告警', count(array_filter($w, fn($x) => str_contains($x, '身份证号'))) === 1);
ok('手机号格式告警', count(array_filter($w, fn($x) => str_contains($x, '联系电话'))) === 1);
ok('邮箱格式告警', count(array_filter($w, fn($x) => str_contains($x, '邮箱'))) === 1);

$w2 = app\service\ImportService::qualityWarnings('certificate', [
    'issue_date' => '2025-01-01', 'expire_date' => '2024-01-01', 'id_card' => '110101199001011234',
]);
ok('发证晚于到期告警', count(array_filter($w2, fn($x) => str_contains($x, '晚于到期'))) === 1);
ok('合法身份证不告警', count(array_filter($w2, fn($x) => str_contains($x, '身份证号'))) === 0);

echo "[4] 借调已到期 / 即将到期口径\n";
$q1 = think\facade\Db::name('cert_secondment');
app\service\SecondmentService::scopeExpired($q1, '2026-10-21');
$sql1 = $q1->fetchSql(true)->select();
ok('已到期包含 status=2', str_contains($sql1, '`status` = 2'));
ok('已到期包含借调中且结束日期已过', str_contains($sql1, "`status` = 1") && str_contains($sql1, "`end_date` < '2026-10-21'"));

$q2 = think\facade\Db::name('cert_secondment');
app\service\SecondmentService::scopeExpiring($q2, 30, '2026-10-21');
$sql2 = $q2->fetchSql(true)->select();
ok('即将到期包含逾期未处理', str_contains($sql2, "`end_date` <= '2026-11-20'"));
ok('即将到期仅限借调中', str_contains($sql2, '`status` = 1'));

echo "[5] 河南平台采集解析 / 资质台账判重\n";
$fixture = <<<'HTML'
<div class="hdhd"><h2>荣电科技股份有限公司</h2></div>
<table class="tableBox">
  <tr><td>统一社会信用代码</td><td>91410581684632999h</td><td>企业法定代表人</td><td>张三</td></tr>
  <tr><td>企业注册属地</td><td>河南省-安阳市</td><td>企业经营地址</td><td>河南省林州市迎宾大道19号</td></tr>
  <tr><td>注册资本</td><td>5000万元</td><td>证书状态</td><td>有效</td></tr>
  <tr><td>安全生产许可证：</td><td>（豫）JZ安许证字[2018]001171</td><td>发证日期</td><td>2024/11/12</td></tr>
  <tr><td>安全生产许可证有效日期</td><td>2027-11-12</td><td></td><td></td></tr>
</table>
HTML;
$parsed = invokeStatic(app\service\CompanyPlatformService::class, 'parseDetail', [$fixture]);
ok('企业名称解析', ($parsed['company']['name'] ?? '') === '荣电科技股份有限公司');
ok('信用代码转大写', ($parsed['company']['credit_code'] ?? '') === '91410581684632999H');
ok('法定代表人解析', ($parsed['company']['legal_person'] ?? '') === '张三');
ok('注册属地 - 转 /', ($parsed['company']['region_path'] ?? '') === '河南省/安阳市');
ok('经营地址解析', ($parsed['company']['address'] ?? '') === '河南省林州市迎宾大道19号');
ok('注册资本解析', ($parsed['company']['registered_capital'] ?? '') === '5000万元');
ok('安许识别为 safety', ($parsed['items'][0]['q_type'] ?? '') === 'safety');
ok('安许发证日期归一化', ($parsed['items'][0]['issue_date'] ?? '') === '2024-11-12');
ok('安许有效期归一化', ($parsed['items'][0]['expire_date'] ?? '') === '2027-11-12');
ok('资质明细提示存在', count(array_filter($parsed['warnings'] ?? [], fn($x) => str_contains($x, '企业资质资格'))) === 1);

ok('平台日期斜杠归一化', invokeStatic(app\service\CompanyPlatformService::class, 'normDate', ['2024/1/2']) === '2024-01-02');
ok('平台日期占位符转空', invokeStatic(app\service\CompanyPlatformService::class, 'normDate', ['—']) === '');

ok('台账判重忽略编号大小写', app\service\CompanyQualificationService::dupKey('qualification', 'd241a45551', '建筑装修装饰工程专业承包贰级')
    === app\service\CompanyQualificationService::dupKey('qualification', 'D241A45551', ' 建筑装修装饰工程专业承包贰级 '));
ok('台账判重区分类型', app\service\CompanyQualificationService::dupKey('safety', 'X1', '证书') !== app\service\CompanyQualificationService::dupKey('qualification', 'X1', '证书'));
ok('台账日期归一化', app\service\CompanyQualificationService::normDate('2024/1/2') === '2024-01-02');
ok('台账空日期为 null', app\service\CompanyQualificationService::normDate('—') === null);

echo "[5.1] 平台基础信息补全方案\n";
$planCompany = [
    'legal_person' => '', 'region_path' => '河南省/安阳市', 'registered_capital' => '',
    'address' => '旧地址', 'credit_code' => '',
];
$planBase = [
    'legal_person' => '张三', 'region_path' => '河南省/安阳市', 'registered_capital' => '5000万元',
    'address' => '新地址', 'credit_code' => '91410581684632999h',
];
$plan1 = app\service\CompanyQualificationService::basicPlan($planCompany, $planBase, false);
ok('补全默认只填空字段', array_column($plan1['filled'], 'field') === ['legal_person', 'registered_capital', 'credit_code'], json_encode($plan1['filled'], JSON_UNESCAPED_UNICODE));
ok('补全默认保留冲突字段', count($plan1['conflicts']) === 1 && $plan1['conflicts'][0]['field'] === 'address');
ok('补全相同值不计变更', count($plan1['unchanged']) === 1 && $plan1['unchanged'][0]['field'] === 'region_path');
ok('补全信用代码转大写', ($plan1['update']['credit_code'] ?? '') === '91410581684632999H');
$plan2 = app\service\CompanyQualificationService::basicPlan($planCompany, $planBase, true);
ok('覆盖模式写入冲突字段', count($plan2['overwritten']) === 1 && ($plan2['update']['address'] ?? '') === '新地址' && !$plan2['conflicts']);

echo "[6] 附件 WebDAV（v2.46）\n";
ok('BaseURL 补尾斜杠', app\service\WebDavService::normBase('https://dav.example.com/dav') === 'https://dav.example.com/dav/');
ok('BaseURL 非 http(s) 拒绝', app\service\WebDavService::normBase('ftp://dav.example.com') === '');
ok('BaseURL 空串拒绝', app\service\WebDavService::normBase('  ') === '');
ok('相对路径拒绝穿越', app\service\WebDavService::sanitizeRel('a/../../etc/passwd') === 'a/etc/passwd');
ok('相对路径折叠空段', app\service\WebDavService::sanitizeRel('/a//b\\\\c/') === 'a/b/c');
ok('相对路径空串', app\service\WebDavService::sanitizeRel('   ') === '');
$b64 = app\service\WebDavService::b64url('webdav-中文-öl/');
ok('b64url roundtrip', base64_decode(strtr($b64, '-_', '+/')) === 'webdav-中文-öl/');
ok('b64url URL 安全', strpbrk($b64, '/+') === false);
ok('路径 token 前缀唯一', app\service\WebDavService::PREFIX === 'webdav://');
// v2.46 回归：签名下载参数必须用 sig —— 框架把 GET 参数 s 当 pathinfo 兼容变量，带 ?s= 会 404
$du = app\service\WebDavService::signedDownloadUrl('webdav://d/f.png');
ok('签名链接用 sig 且无裸 s 参数', str_contains($du, '&sig=') && !preg_match('/[?&]s=/', $du));
ok('签名链接指向免登录下载接口', str_starts_with($du, '/api/attach/download?p='));

echo "[7] 导入日期规范化 / 冲突规则配置（v2.55）\n";
ok('非法占位符 - 回退', invokeStatic(app\service\ImportService::class, 'normDate', ['-', '2024-01-01']) === '2024-01-01');
ok('空串回退', invokeStatic(app\service\ImportService::class, 'normDate', ['', '2024-01-01']) === '2024-01-01');
ok('零占位回退', invokeStatic(app\service\ImportService::class, 'normDate', ['0', '2024-01-01']) === '2024-01-01');
ok('合法日期保留', invokeStatic(app\service\ImportService::class, 'normDate', ['2024-01-02', 'fb']) === '2024-01-02');

$rules = app\service\ConflictService::rules();
ok('规则默认四类', count($rules) === 4);
ok('占用冲突默认阻断', ($rules['occupy']['level'] ?? -1) === 2 && ($rules['occupy']['status'] ?? 0) === 1);
ok('人员冲突默认警告', ($rules['person']['level'] ?? -1) === 1 && ($rules['person']['status'] ?? 0) === 1);
ok('互斥冲突默认阻断', ($rules['exclusive']['level'] ?? -1) === 2 && ($rules['exclusive']['status'] ?? 0) === 1);
ok('政策冲突默认阻断', ($rules['policy']['level'] ?? -1) === 2 && ($rules['policy']['status'] ?? 0) === 1);

$decorated = app\service\ConflictService::decorateConflicts([
    ['type' => 'occupy', 'title' => 't', 'level' => 2],
    ['type' => 'policy', 'title' => 'p', 'level' => 1],
]);
ok('装饰保留启用项', count($decorated) === 2 && ($decorated[0]['level'] ?? -1) === 2);
$decoratedDisabled = app\service\ConflictService::decorateConflicts([
    ['type' => 'unknown', 'title' => 'x', 'level' => 2],
]);
ok('未知规则不输出', count($decoratedDisabled) === 0);

echo "[8] 附件 S3 存储 / 共享签名（v2.99.48）\n";
// 共享签名工具
ok('AttachSign b64url 往返', base64_decode(strtr(app\service\AttachSignService::b64url('s3-中文/öl'), '-_', '+/')) === 's3-中文/öl');
ok('AttachSign 穿越过滤', app\service\AttachSignService::sanitizeRel('a/../../etc/passwd') === 'a/etc/passwd');
ok('AttachSign 折叠空段/反斜杠', app\service\AttachSignService::sanitizeRel('/a//b\\\\c/') === 'a/b/c');
// S3 纯函数
ok('S3 PREFIX 固定', app\service\S3StorageService::PREFIX === 's3://');
ok('S3 endpoint 去尾斜杠', app\service\S3StorageService::normEndpoint('https://s3.example.com/') === 'https://s3.example.com');
ok('S3 endpoint 非 http(s) 拒绝', app\service\S3StorageService::normEndpoint('ftp://x') === '');
ok('S3 key 拒绝穿越', app\service\S3StorageService::sanitizeKey('../bucket/x.png') === 'bucket/x.png');
ok('S3 key 去前导斜杠', app\service\S3StorageService::sanitizeKey('/a/b/c.pdf') === 'a/b/c.pdf');
// URL 构造：path-style 与 virtual-host
$buildUrl = invokeStatic(app\service\S3StorageService::class, 'buildUrl', [[
    'endpoint' => 'https://s3.example.com', 'region' => 'us-east-1', 'bucket' => 'bkt',
    'access_key' => 'ak', 'secret_key' => 'sk', 'prefix' => '', 'path_style' => true, 'verify' => true,
], 'dir/f.png']);
ok('S3 path-style URL', $buildUrl[0] === 'https://s3.example.com/bkt/dir/f.png', $buildUrl[0]);
ok('S3 path-style host', $buildUrl[1] === 's3.example.com');
ok('S3 path-style 签名路径含 bucket', ($buildUrl[2] ?? '') === '/bkt/dir/f.png', $buildUrl[2] ?? '');
$buildUrl2 = invokeStatic(app\service\S3StorageService::class, 'buildUrl', [[
    'endpoint' => 'https://s3.example.com', 'region' => 'us-east-1', 'bucket' => 'bkt',
    'access_key' => 'ak', 'secret_key' => 'sk', 'prefix' => '', 'path_style' => false, 'verify' => true,
], 'dir/f.png']);
ok('S3 virtual-host URL', $buildUrl2[0] === 'https://bkt.s3.example.com/dir/f.png', $buildUrl2[0]);
ok('S3 virtual-host host', $buildUrl2[1] === 'bkt.s3.example.com');
ok('S3 virtual-host 签名路径不含 bucket', ($buildUrl2[2] ?? '') === '/dir/f.png', $buildUrl2[2] ?? '');
// 带 endpoint 子路径（反向代理 / MinIO 子路径）
$buildUrl3 = invokeStatic(app\service\S3StorageService::class, 'buildUrl', [[
    'endpoint' => 'http://minio.local:9000/store', 'region' => 'us-east-1', 'bucket' => 'bkt',
    'access_key' => 'ak', 'secret_key' => 'sk', 'prefix' => '', 'path_style' => true, 'verify' => true,
], 'd/f.txt']);
ok('S3 path-style 含子路径', $buildUrl3[0] === 'http://minio.local:9000/store/bkt/d/f.txt', $buildUrl3[0]);
ok('S3 path-style 子路径签名路径一致', ($buildUrl3[2] ?? '') === '/store/bkt/d/f.txt', $buildUrl3[2] ?? '');
// SigV4 签名密钥派生：确定性（同输入同输出）、随密钥/日期/区域变化
$sk1 = app\service\S3StorageService::signingKey('SKTEST', '20261115', 'cn-north-1');
$sk2 = app\service\S3StorageService::signingKey('SKTEST', '20261115', 'cn-north-1');
ok('SigV4 派生确定性', $sk1 === $sk2 && strlen($sk1) === 32);
ok('SigV4 随日期变化', app\service\S3StorageService::signingKey('SKTEST', '20261116', 'cn-north-1') !== $sk1);
ok('SigV4 随区域变化', app\service\S3StorageService::signingKey('SKTEST', '20261115', 'us-east-1') !== $sk1);
ok('SigV4 随密钥变化', app\service\S3StorageService::signingKey('OTHER', '20261115', 'cn-north-1') !== $sk1);
// 手工对照一条已知 RFC 风格 HMAC 链路，确保派生顺序正确
$expect = hash_hmac('sha256', 'aws4_request',
    hash_hmac('sha256', 's3',
        hash_hmac('sha256', 'cn-north-1',
            hash_hmac('sha256', '20261115', 'AWS4SKTEST', true), true), true), true);
ok('SigV4 派生链路正确', $sk1 === $expect);

echo "[9] 敏感导出 OTP 闸门（v2.99.49）\n";
ok('闸门模块白名单为三项', array_keys(app\service\ExportOtpService::MODULES) === ['staff', 'credential_vault', 'certificate']);
ok('ticket 有效期 5 分钟', app\service\ExportOtpService::TICKET_TTL === 300);
ok('合法模块归一', app\service\ExportOtpService::normModule(' credential_vault ') === 'credential_vault');
$badModuleThrew = false;
try { app\service\ExportOtpService::normModule('fee'); } catch (\Throwable $e) { $badModuleThrew = true; }
ok('非法模块抛 400 阻断', $badModuleThrew);
ok('审计用途含 export', isset(app\service\SensitiveAccessService::ALLOWED_PURPOSE['export']));
ok('导出审计字段标识固定', app\service\SensitiveAccessService::EXPORT_FIELD === 'sensitive_export');
ok('P1 原有用途未被破坏', isset(app\service\SensitiveAccessService::ALLOWED_PURPOSE['view'], app\service\SensitiveAccessService::ALLOWED_PURPOSE['copy'], app\service\SensitiveAccessService::ALLOWED_PURPOSE['edit']));

echo "[10] 异地登录检测（v2.99.50）\n";
$ipc = app\service\IpCityService::class;
ok('内网/回环 IP 判为未知', $ipc::normIp('127.0.0.1') === '' && $ipc::normIp('10.0.0.1') === '' && $ipc::normIp('192.168.1.9') === '');
ok('公网 IP 去端口保留地址', $ipc::normIp(' 114.114.114.114:8080 ') === '114.114.114.114');
ok('IPv4-mapped IPv6 归一为 IPv4', $ipc::normIp('::ffff:114.114.114.114') === '114.114.114.114');
ok('纯 IPv6 地址判为未知', $ipc::normIp('2409:8a00:1234::1') === '');
ok('私网边界 172.16/12 判定正确', $ipc::isPrivate('172.16.0.1') === true && $ipc::isPrivate('172.15.0.1') === false);
ok('随包 ip2region 库可加载', $ipc::available() === true);
$geo = $ipc::lookup('114.114.114.114');
ok('真实库解析国家/城市', $geo['country'] === '中国' && $geo['city'] === '南京市', $geo['text']);
ok('城市基线为 省|市', $ipc::cityOf('114.114.114.114') === '江苏省|南京市', $ipc::cityOf('114.114.114.114'));
ok('直辖市基线归一为单段', $ipc::cityOf('1.2.4.8') === '北京市', $ipc::cityOf('1.2.4.8'));
ok('内网 IP 无城市基线', $ipc::cityOf('192.168.1.9') === '');
ok('异地撤销原因固定', app\service\DeviceService::GEO_REVOKE_REASON !== '');
ok('登录日志含安全拦截状态', (app\service\LoginLogService::STATUSES[4] ?? '') === '安全拦截');

echo "[11] 字段级脱敏矩阵（v2.99.51）\n";
$ms = app\service\MaskService::class;
ok('矩阵字段目录含 7 个敏感字段', count($ms::FIELDS) === 7);
ok('三态取值固定', $ms::STATES === ['plain', 'mask', 'hide']);
// 规范化：非法角色/字段/状态被丢弃，合法配置保留
$norm = $ms::normalizeMatrix([
    'admin'  => ['staff.id_card' => 'plain', 'staff.phone' => 'hide', 'unknown.field' => 'plain', 'staff.email' => 'bogus'],
    'sales'  => ['cert.cert_no' => 'plain'],
    ''       => ['staff.phone' => 'plain'],
    'legal'  => 'not-an-array',
]);
ok('规范化保留合法配置', ($norm['admin']['staff.id_card'] ?? '') === 'plain' && ($norm['admin']['staff.phone'] ?? '') === 'hide');
ok('规范化丢弃未知字段/非法状态', !isset($norm['admin']['unknown.field']) && !isset($norm['admin']['staff.email']));
ok('规范化丢弃空角色与非数组值', !isset($norm['']) && !isset($norm['legal']));
ok('规范化支持 JSON 字符串输入', ($ms::normalizeMatrix('{"sales":{"cert.cert_no":"hide"}}')['sales']['cert.cert_no'] ?? '') === 'hide');
// 未配置矩阵时的默认策略：身份证/手机/邮箱/证书编号默认脱敏，姓名默认明文
$guest = ['role_code' => 'sales'];
ok('默认字段脱敏', $ms::stateFor('staff.id_card', $guest) === 'mask' && $ms::stateFor('cert.cert_no', $guest) === 'mask');
ok('姓名默认不脱敏', $ms::stateFor('staff.name', $guest) === 'plain');
ok('严格导出场景姓名脱敏', $ms::stateFor('staff.name', $guest, true) === 'mask');
// 字段值输出
ok('身份证脱敏保留前 6 后 4', $ms::fieldValue('staff.id_card', '330101199001011234', $guest) === '330101********1234');
ok('手机号脱敏保留前 3 后 4', $ms::fieldValue('staff.phone', '13800138000', $guest) === '138****8000');
ok('邮箱脱敏保留首字母与域名', $ms::fieldValue('staff.email', 'zhangsan@abc.com', $guest) === 'z***@abc.com');
ok('证书编号脱敏保留前 4 后 4', $ms::fieldValue('cert.cert_no', 'ABCD12345678WXYZ', $guest) === 'ABCD********WXYZ');
ok('空值不产生掩码', $ms::fieldValue('staff.phone', '', $guest) === '');
// 脱敏幂等：已打码值再次处理不破坏长度
ok('脱敏幂等', $ms::fieldValue('staff.id_card', $ms::fieldValue('staff.id_card', '330101199001011234', $guest), $guest) === '330101********1234');
// 人员/证书批量入口保留旧行为（不传 user => 全量脱敏）
$staffRow = $ms::staff(['staff_name' => '张三', 'id_card' => '330101199001011234', 'phone' => '13800138000', 'email' => 'zhangsan@abc.com']);
ok('staff() 默认脱敏且姓名保留', $staffRow['id_card'] === '330101********1234' && $staffRow['staff_name'] === '张三');
$certRow = $ms::cert(['cert_no' => 'ABCD12345678WXYZ', 'staff' => ['id_card' => '330101199001011234']]);
ok('cert() 含嵌套人员脱敏', $certRow['cert_no'] === 'ABCD********WXYZ' && $certRow['staff']['id_card'] === '330101********1234');
ok('编辑回填默认明文', $ms::editValue('staff.id_card', '330101199001011234', ['role_code' => 'admin']) === '330101199001011234');
ok('矩阵配置键固定', $ms::MATRIX_KEY === 'mask_matrix');

echo "[12] 删除操作 OTP 闸门（v2.99.66）\n";
$dotp = app\service\DeleteOtpService::class;
ok('删除票据有效期 5 分钟', $dotp::TICKET_TTL === 300);
ok('pathinfo 归一（去 api/admin 前缀）', $dotp::normalizePath('api/admin/staff/delete') === 'staff/delete');
ok('普通删除动作受保护', $dotp::isProtectedAction('staff/batch-delete') === true);
ok('非 delete 写操作不受保护', $dotp::isProtectedAction('staff/save') === false);
ok('清缓存不受保护（避免误伤）', $dotp::isProtectedAction('system/cache-clear') === false);
ok('reset 类动作不受保护', $dotp::isProtectedAction('role/reset') === false && $dotp::isProtectedAction('menu/reset-catalog') === false);
ok('remove-owner 显式纳入', $dotp::isProtectedAction('resource/remove-owner') === true);
ok('key-revoke 显式纳入', $dotp::isProtectedAction('agent/key-revoke') === true);
ok('日志清空显式纳入', $dotp::isProtectedAction('login-log/clear') === true);
ok('闸门自身 verify 不拦截（否则无法换票）', $dotp::isProtectedAction('delete-otp/verify') === false);
ok('动作中文标签-普通删除', $dotp::actionLabel('staff/delete') === '人员档案删除');
ok('动作中文标签-批量删除', $dotp::actionLabel('company/batch-delete') === '企业档案批量删除');
ok('动作中文标签-完整覆盖', $dotp::actionLabel('upgrade/delete-backup') === '升级备份删除');
ok('审计用途含 delete', isset(app\service\SensitiveAccessService::ALLOWED_PURPOSE['delete']));
ok('删除审计字段标识固定', app\service\SensitiveAccessService::DELETE_FIELD === 'delete_otp');
$badActionThrew = false;
try { $dotp::normAction('staff/save'); } catch (\Throwable $e) { $badActionThrew = true; }
ok('非法删除动作换票抛 400 阻断', $badActionThrew);
$normAction = $dotp::normAction('api/admin/crm/follow-delete');
ok('normAction 归一后保留合法动作', $normAction === 'crm/follow-delete' && $dotp::isProtectedAction($normAction));

echo "[13] 申报进度推送判定（v2.99.69）\n";
$gv = app\service\GovProgressService::class;
$hasEvent = fn(array $ev, string $name) => count(array_filter($ev, fn($e) => ($e['event'] ?? '') === $name)) > 0;
$base = [
    'current_node' => '审查', 'current_state' => 2, 'node_done' => 1, 'node_total' => 4,
    'handle_state' => '办理中', 'progress_percent' => 25,
    'is_finished' => 0, 'is_suspended' => 0, 'is_returned' => 0, 'suspend_reason' => '',
];
$jitter = $base; $jitter['current_node'] = '审查（临时）';
ok('环节清单未变时 current_node 文本抖动不触发推送', $gv::changeEvents($base, $jitter, false) === []);
$advance = $base; $advance['node_done'] = 2;
ok('已完成环节数增加视为环节推进', $hasEvent($gv::changeEvents($base, $advance, true), 'node'));
$moved = $base; $moved['current_node'] = '决定';
ok('环节名称变化产生环节事件', $hasEvent($gv::changeEvents($base, $moved, true), 'node'));
$fin = $base; $fin['is_finished'] = 1; $fin['current_node'] = '已办结';
ok('环节清单未变但办结仍推送', $hasEvent($gv::changeEvents($base, $fin, false), 'finished'));
$sus = $base; $sus['is_suspended'] = 1;
ok('环节清单未变但新挂起仍推送', $hasEvent($gv::changeEvents($base, $sus, false), 'suspended'));
$ret = $base; $ret['is_returned'] = 1;
ok('环节清单未变但退件仍推送', $hasEvent($gv::changeEvents($base, $ret, false), 'returned'));

$nodesA = [
    ['node_name' => '受理', 'state' => 1, 'step' => 1, 'start_time' => '2026-11-01 09:00:00', 'end_time' => '2026-11-01 10:00:00'],
    ['node_name' => '审查', 'state' => 2, 'step' => 2, 'start_time' => '2026-11-01 10:00:00', 'end_time' => null],
];
$nodesB = array_reverse($nodesA);
ok('环节指纹忽略平台返回顺序', $gv::nodesFingerprint($nodesA) === $gv::nodesFingerprint($nodesB));
$nodesC = $nodesA; $nodesC[1]['state'] = 1; $nodesC[1]['end_time'] = '2026-11-02 10:00:00';
ok('环节指纹识别办理状态推进', $gv::nodesFingerprint($nodesA) !== $gv::nodesFingerprint($nodesC));
$nodesD = $nodesA; $nodesD[0]['node_name'] = " 受理\u{3000}";
ok('环节指纹忽略空白差异', $gv::nodesFingerprint($nodesA) === $gv::nodesFingerprint($nodesD));
ok('长环节名按落库 64 字截断比对', $gv::nodesFingerprint([['node_name' => str_repeat('长', 80), 'state' => 2]])
    === $gv::nodesFingerprint([['node_name' => str_repeat('长', 64), 'state' => 2]]));

echo "\n结果：{$pass} 通过，{$fail} 失败\n";
exit($fail > 0 ? 1 : 0);
