<?php
/**
 * 未授权提示页（独立于框架与业务系统，锁死态可渲染）。
 *
 * 由 FlowGate / SealTrace 在拦截页面请求时跳转进来：
 *   /license-gate.php?code=LICENSE_MISSING&back=%2Fweb%2F
 *
 * 本页只做两件事：
 *  - 用通俗中文说明「为什么被拦下」；
 *  - 提供「前往授权管理」与「已处理，刷新」两个快捷动作，不做任何写操作。
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

const MESSAGES = [
    'NOT_INSTALLED'     => ['尚未完成授权安装', '本系统还没有配置授权端公钥，第一次使用请先完成授权安装。'],
    'LICENSE_MISSING'   => ['尚未导入授权凭证', '公钥已就位，但还没有粘贴授权端下发的凭证。'],
    'LICENSE_MALFORMED' => ['授权凭证格式不正确', '凭证内容可能复制不完整（需包含 payload 与 signature 两部分）。'],
    'SIGNATURE_INVALID' => ['授权凭证校验未通过', '最常见原因是本系统公钥与授权端不一致，或该凭证不是由当前授权端签发。'],
    'LICENSE_READ_ERROR'=> ['授权文件读取异常', '授权目录或文件权限不足，请检查 runtime/vault 目录是否可读写。'],
    'DOMAIN_MISMATCH'   => ['当前域名不在授权范围内', '凭证绑定的域名与当前访问域名不一致，可改用授权域名访问，或联系软件提供方变更授权域名。'],
    'LICENSE_EXPIRED'   => ['授权已到期', '请联系软件提供方续期，续期后把新凭证粘贴到授权管理页即可，无需重装。'],
    'LICENSE_REVOKED'   => ['授权已被吊销', '授权端已吊销本系统的授权，请联系软件提供方恢复。'],
];

$code = isset($_GET['code']) ? trim((string)$_GET['code']) : 'LICENSE_MISSING';
if (!isset(MESSAGES[$code])) {
    $code = 'LICENSE_MISSING';
}

/** 只接受站内相对地址，避免被拼成外链跳板。 */
function safe_back(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '' || $raw[0] !== '/' || str_starts_with($raw, '//')) {
        return '/';
    }
    if (preg_match('/[\r\n]/', $raw)) {
        return '/';
    }
    return $raw;
}

$back = safe_back((string)($_GET['back'] ?? '/'));
[$title, $advice] = MESSAGES[$code];

$vaultDir       = dirname(__DIR__) . '/runtime/vault';
$verifierPath   = $vaultDir . '/verifier.pem';
$credentialPath = $vaultDir . '/credential.dat';

$hasKey = is_file($verifierPath);
$hasCred = is_file($credentialPath);

$host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
$host = (string)preg_replace('#:\d+$#', '', $host);

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= h($title) ?> - 授权提示</title>
<style>
  * { box-sizing: border-box; }
  body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px;
         font:14px/1.7 -apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,"PingFang SC","Microsoft YaHei",sans-serif;
         background:#f5f7fa; color:#303133; }
  .card { width:100%; max-width:560px; background:#fff; border-radius:12px; box-shadow:0 4px 24px rgba(0,0,0,.07); padding:32px 34px; }
  .icon { width:48px; height:48px; border-radius:50%; background:#fef0f0; color:#f56c6c; display:flex; align-items:center;
          justify-content:center; font-size:24px; margin-bottom:16px; }
  h1 { font-size:20px; margin:0 0 8px; }
  .desc { color:#606266; font-size:14px; margin-bottom:6px; }
  .tips { background:#f8f8f9; border:1px solid #ebeef5; border-radius:8px; padding:12px 14px; font-size:13px; color:#606266; margin:18px 0; }
  .tips b { color:#303133; }
  .tips div + div { margin-top:6px; }
  .actions { display:flex; gap:10px; flex-wrap:wrap; margin-top:20px; }
  a.btn { display:inline-block; text-decoration:none; border-radius:8px; padding:10px 20px; font-size:14px; }
  .btn-primary { background:#409eff; color:#fff; }
  .btn-primary:hover { background:#337ecc; }
  .btn-ghost { background:#fff; color:#606266; border:1px solid #dcdfe6; }
  .btn-ghost:hover { color:#409eff; border-color:#409eff; background:#ecf5ff; }
  .foot { margin-top:20px; font-size:12px; color:#909399; word-break:break-all; }
  code { background:#f4f4f5; padding:1px 5px; border-radius:4px; font-family:Consolas,Menlo,monospace; font-size:12px; }
</style>
</head>
<body>
<div class="card">
  <div class="icon">!</div>
  <h1><?= h($title) ?></h1>
  <div class="desc"><?= h($advice) ?></div>

  <div class="tips">
    <div><b>公钥文件：</b><?= $hasKey ? '已配置' : '尚未配置' ?>　<b>凭证文件：</b><?= $hasCred ? '已找到' : '未找到' ?></div>
    <div><b>当前访问域名：</b><code><?= h($host !== '' ? $host : '未知') ?></code></div>
    <div><b>状态码：</b><code><?= h($code) ?></code></div>
  </div>

  <div class="actions">
    <a class="btn btn-primary" href="/setup.php">前往授权管理</a>
    <a class="btn btn-ghost" href="<?= h($back) ?>">已处理，刷新</a>
  </div>

  <div class="foot">在授权管理页可粘贴公钥与凭证、查看公钥指纹；授权文件更换后立即生效，无需重启 PHP-FPM。</div>
</div>
</body>
</html>
