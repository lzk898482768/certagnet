<?php
namespace app\service;

use app\model\SystemConfig;
use app\model\SysUser;
use app\model\UserCompanyRel;
use think\facade\Cache;
use Throwable;

/**
 * 企业微信「智能机器人」回调接入（v2.83）
 *
 * 企业微信管理后台 → 应用管理 → 智能机器人 → API 模式 → 使用 URL 回调，
 * 需要填写 URL / Token / EncodingAESKey 三项。本服务负责：
 *   ① URL 验证：校验 msg_signature，解密 echostr 并原样返回明文；
 *   ② 接收消息：解密 JSON 回调体，按关键词意图查询本系统数据；
 *   ③ 被动回复：把结果组装成 markdown，加密后按协议返回。
 *
 * 与 /api/agent 的 API Key 体系互相独立：
 *   - 机器人没有 API Key，靠 Token + EncodingAESKey 校验来源；
 *   - 数据范围绑定「机器人账号」，只开放只读查询，不提供任何写操作。
 *
 * 加解密协议（企业微信官方）：AES-256-CBC + PKCS#7（块长 32），
 * key = Base64Decode(EncodingAESKey + '=')，iv = key 前 16 字节；
 * 明文 = random(16) + msg_len(4, 网络字节序) + msg + receiveid。
 * 智能机器人场景 receiveid 为空字符串。
 */
class WecomAibotService
{
    public const CFG_ENABLED = 'agent_wecom_enabled';
    public const CFG_TOKEN   = 'agent_wecom_token';
    public const CFG_AES     = 'agent_wecom_aes_key';
    public const CFG_OWNER   = 'agent_wecom_owner_user_id';
    public const CFG_WELCOME = 'agent_wecom_welcome';

    /** 单条回复的最大字节数（企业微信 stream 上限 20480） */
    private const MAX_REPLY_BYTES = 18000;

    /** 列表最多展示条数，避免聊天窗口刷屏 */
    private const MAX_ITEMS = 10;

    // ==================================================================
    // 配置
    // ==================================================================

    /** 读取机器人配置（未配置时返回空值，不抛异常） */
    public static function config(): array
    {
        return [
            'enabled'       => (string)sys_config(self::CFG_ENABLED, '0') === '1',
            'token'         => trim((string)sys_config(self::CFG_TOKEN, '')),
            'aes_key'       => trim((string)sys_config(self::CFG_AES, '')),
            'owner_user_id' => (int)sys_config(self::CFG_OWNER, 0),
            'welcome'       => (string)sys_config(self::CFG_WELCOME, ''),
        ];
    }

    /** 是否已具备接收回调的条件 */
    public static function ready(): bool
    {
        $c = self::config();
        return $c['token'] !== '' && self::validAesKey($c['aes_key']);
    }

    /**
     * 保存配置
     * @return array{ok:bool,msg:string}
     */
    public static function saveConfig(array $d): array
    {
        $token   = trim((string)($d['token'] ?? ''));
        $aesKey  = trim((string)($d['aes_key'] ?? ''));
        $owner   = (int)($d['owner_user_id'] ?? 0);
        $welcome = trim((string)($d['welcome'] ?? ''));
        $enabled = !empty($d['enabled']) ? '1' : '0';

        if ($token !== '' && !preg_match('/^[A-Za-z0-9]{1,64}$/', $token)) {
            return ['ok' => false, 'msg' => 'Token 只能是 1-64 位英文或数字'];
        }
        if ($aesKey !== '' && !self::validAesKey($aesKey)) {
            return ['ok' => false, 'msg' => 'EncodingAESKey 必须是 43 位英文或数字（企业微信随机生成的那一串）'];
        }
        if ($enabled === '1' && ($token === '' || $aesKey === '')) {
            return ['ok' => false, 'msg' => '启用前请先填写 Token 与 EncodingAESKey'];
        }
        if ($owner > 0) {
            $exists = SysUser::where('id', $owner)->where('status', 1)->find();
            if (!$exists) {
                return ['ok' => false, 'msg' => '绑定的账号不存在或已停用'];
            }
        }
        if (mb_strlen($welcome) > 500) {
            return ['ok' => false, 'msg' => '欢迎语不能超过 500 字'];
        }

        self::put(self::CFG_TOKEN, $token);
        self::put(self::CFG_AES, $aesKey);
        self::put(self::CFG_OWNER, (string)$owner);
        self::put(self::CFG_WELCOME, $welcome);
        self::put(self::CFG_ENABLED, $enabled);

        return ['ok' => true, 'msg' => '已保存'];
    }

    private static function put(string $key, string $value): void
    {
        $row = SystemConfig::where('config_key', $key)->find();
        if ($row) {
            $row->save(['config_value' => $value]);
            return;
        }
        SystemConfig::create([
            'config_key'   => $key,
            'config_value' => $value,
            'config_name'  => $key,
        ]);
    }

    /** 43 位 Base64 字符，解码后必须正好 32 字节 */
    public static function validAesKey(string $key): bool
    {
        if (!preg_match('/^[A-Za-z0-9+\/]{43}$/', $key)) {
            return false;
        }
        $raw = base64_decode($key . '=', true);
        return $raw !== false && strlen($raw) === 32;
    }

    /** 生成 32 位 Token（企业微信允许自定义，取字母数字避免转义问题） */
    public static function randomToken(): string
    {
        return substr(bin2hex(random_bytes(16)), 0, 32);
    }

    /** 生成合法的 43 位 EncodingAESKey */
    public static function randomAesKey(): string
    {
        return substr(rtrim(base64_encode(random_bytes(32)), '='), 0, 43);
    }

    /** 默认欢迎语（进入会话时展示） */
    public static function defaultWelcome(): string
    {
        return "你好，我是证照助手 👋\n\n可以直接问我：\n· 到期 7天 —— 查看即将到期的证照\n· 证书 一建 —— 按名称/编号搜证照\n· 人员 张三 —— 查人员档案\n· 企业 示例 —— 查企业客户\n· 概览 —— 数据总览\n\n回复「帮助」查看全部指令。";
    }

    // ==================================================================
    // 加解密
    // ==================================================================

    /** 企业微信消息签名：sha1(字典序拼接 token/timestamp/nonce/encrypt) */
    public static function signature(string $token, string $timestamp, string $nonce, string $encrypt): string
    {
        $arr = [$token, $timestamp, $nonce, $encrypt];
        sort($arr, SORT_STRING);
        return sha1(implode('', $arr));
    }

    /**
     * 解密回调密文，返回明文消息（失败返回空串）
     */
    public static function decrypt(string $encrypt, string $aesKey): string
    {
        if ($encrypt === '' || !self::validAesKey($aesKey)) {
            return '';
        }
        $key = base64_decode($aesKey . '=', true);
        $iv  = substr($key, 0, 16);
        // 注意：PHP 的 openssl_decrypt 在 RAW_DATA|ZERO_PADDING 组合下会返回错误数据，
        // 这里只传 OPENSSL_ZERO_PADDING（关闭填充校验，输出原始二进制），再手工去 PKCS#7。
        $raw = openssl_decrypt($encrypt, 'AES-256-CBC', $key, OPENSSL_ZERO_PADDING, $iv);
        if ($raw === false || strlen($raw) < 20) {
            return '';
        }

        // 去 PKCS#7 填充（块长 32）
        $pad = ord(substr($raw, -1));
        if ($pad < 1 || $pad > 32 || $pad > strlen($raw)) {
            return '';
        }
        $raw = substr($raw, 0, strlen($raw) - $pad);
        if (strlen($raw) < 20) {
            return '';
        }

        $len = unpack('N', substr($raw, 16, 4))[1] ?? 0;
        if ($len < 0 || $len > strlen($raw) - 20) {
            return '';
        }
        // 智能机器人 receiveid 为空，这里不校验，兼容企业主体差异
        return substr($raw, 20, $len);
    }

    /**
     * 加密回复明文
     */
    public static function encrypt(string $msg, string $aesKey): string
    {
        if (!self::validAesKey($aesKey)) {
            return '';
        }
        $key = base64_decode($aesKey . '=', true);
        $iv  = substr($key, 0, 16);

        $text = random_bytes(16) . pack('N', strlen($msg)) . $msg;
        $pad  = 32 - (strlen($text) % 32);
        $text .= str_repeat(chr($pad), $pad);

        $out = openssl_encrypt($text, 'AES-256-CBC', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
        return $out === false ? '' : base64_encode($out);
    }

    // ==================================================================
    // 回调处理
    // ==================================================================

    /**
     * URL 验证（GET）
     * @return array{ok:bool,plain:string,msg:string}
     */
    public static function verifyUrl(array $q): array
    {
        $cfg = self::config();
        if ($cfg['token'] === '' || !self::validAesKey($cfg['aes_key'])) {
            return ['ok' => false, 'plain' => '', 'msg' => '机器人未配置 Token / EncodingAESKey'];
        }

        $signature = (string)($q['msg_signature'] ?? '');
        $timestamp = (string)($q['timestamp'] ?? '');
        $nonce     = (string)($q['nonce'] ?? '');
        $echostr   = (string)($q['echostr'] ?? '');

        if ($signature === '' || $echostr === '') {
            return ['ok' => false, 'plain' => '', 'msg' => '缺少 msg_signature 或 echostr'];
        }
        $expect = self::signature($cfg['token'], $timestamp, $nonce, $echostr);
        if (!hash_equals($expect, $signature)) {
            return ['ok' => false, 'plain' => '', 'msg' => '签名校验失败'];
        }

        $plain = self::decrypt($echostr, $cfg['aes_key']);
        if ($plain === '') {
            return ['ok' => false, 'plain' => '', 'msg' => 'echostr 解密失败'];
        }
        return ['ok' => true, 'plain' => $plain, 'msg' => 'ok'];
    }

    /**
     * 处理消息回调（POST）
     *
     * @param array $q      URL query（msg_signature/timestamp/nonce）
     * @param string $body  原始请求体
     * @return array{status:int,body:string,msg:string}
     */
    public static function handleCallback(array $q, string $body): array
    {
        $cfg = self::config();
        if (!$cfg['enabled']) {
            return ['status' => 403, 'body' => 'robot disabled', 'msg' => '机器人未启用'];
        }
        if ($cfg['token'] === '' || !self::validAesKey($cfg['aes_key'])) {
            return ['status' => 403, 'body' => 'robot not configured', 'msg' => '机器人未配置'];
        }

        $payload = json_decode($body, true);
        $encrypt = is_array($payload) ? (string)($payload['encrypt'] ?? '') : '';
        if ($encrypt === '') {
            return ['status' => 400, 'body' => 'invalid body', 'msg' => '回调体缺少 encrypt'];
        }

        $signature = (string)($q['msg_signature'] ?? '');
        $timestamp = (string)($q['timestamp'] ?? '');
        $nonce     = (string)($q['nonce'] ?? '');
        $expect    = self::signature($cfg['token'], $timestamp, $nonce, $encrypt);
        if ($signature === '' || !hash_equals($expect, $signature)) {
            return ['status' => 403, 'body' => 'invalid signature', 'msg' => '签名校验失败'];
        }

        $plain = self::decrypt($encrypt, $cfg['aes_key']);
        if ($plain === '') {
            return ['status' => 400, 'body' => 'decrypt failed', 'msg' => '消息解密失败'];
        }

        $msg = json_decode($plain, true);
        if (!is_array($msg)) {
            return ['status' => 400, 'body' => 'invalid message', 'msg' => '消息不是合法 JSON'];
        }

        // 排重：企业微信可能因超时重复回调同一条消息
        $msgId = (string)($msg['msgid'] ?? '');
        if ($msgId !== '') {
            $ck = 'wca_msg_' . md5($msgId);
            try {
                if (Cache::get($ck)) {
                    return ['status' => 200, 'body' => '', 'msg' => 'duplicate'];
                }
                Cache::set($ck, 1, 300);
            } catch (Throwable $e) {
                // 缓存故障不影响回复
            }
        }

        $reply = self::buildReply($msg);

        // 无内容可回（事件、不支持的消息类型）：回空包，企业微信会展示默认提示
        if ($reply === null) {
            return ['status' => 200, 'body' => '', 'msg' => 'no reply'];
        }

        $encrypted = self::encrypt($reply, $cfg['aes_key']);
        if ($encrypted === '') {
            return ['status' => 500, 'body' => 'encrypt failed', 'msg' => '回复加密失败'];
        }
        $outNonce = $nonce !== '' ? $nonce : (string)random_int(100000000, 999999999);
        $outTs    = time();
        $outSign  = self::signature($cfg['token'], (string)$outTs, $outNonce, $encrypted);

        return [
            'status' => 200,
            'body'   => json_encode([
                'encrypt'      => $encrypted,
                // 官方文档字段名为 msgsignature，同时带上 msg_signature 兼容不同客户端
                'msgsignature' => $outSign,
                'msg_signature' => $outSign,
                'timestamp'    => $outTs,
                'nonce'        => $outNonce,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'msg' => 'ok',
        ];
    }

    /**
     * 根据解密后的消息生成回复明文 JSON（null = 不回复）
     */
    public static function buildReply(array $msg): ?string
    {
        $type = (string)($msg['msgtype'] ?? '');

        // 进入会话 → 欢迎语（仅这里支持纯文本消息）
        if ($type === 'event') {
            $eventType = (string)($msg['event']['eventtype'] ?? '');
            if (in_array($eventType, ['enter_chat', 'enter_session'], true)) {
                $welcome = self::config()['welcome'];
                if ($welcome === '') {
                    return null;
                }
                return json_encode([
                    'msgtype' => 'text',
                    'text'    => ['content' => $welcome],
                ], JSON_UNESCAPED_UNICODE);
            }
            return null;
        }

        $text = self::extractText($msg);
        if ($text === '') {
            return null;
        }

        $content = self::answer($text);
        $content = self::clamp($content);

        $streamId = (string)($msg['msgid'] ?? '');
        if ($streamId === '') {
            $streamId = 'stream_' . bin2hex(random_bytes(8));
        }

        return json_encode([
            'msgtype' => 'stream',
            'stream'  => [
                'id'      => $streamId,
                'finish'  => true,
                'content' => $content,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** 从文本 / 图文混排 / 引用消息中取出用户输入 */
    private static function extractText(array $msg): string
    {
        $type = (string)($msg['msgtype'] ?? '');
        if ($type === 'text') {
            return trim((string)($msg['text']['content'] ?? ''));
        }
        if ($type === 'mixed') {
            $parts = [];
            foreach ((array)($msg['mixed']['msg_item'] ?? []) as $item) {
                if (($item['msgtype'] ?? '') === 'text') {
                    $parts[] = (string)($item['text']['content'] ?? '');
                }
            }
            return trim(implode(' ', $parts));
        }
        if ($type === 'voice') {
            return trim((string)($msg['voice']['content'] ?? ''));
        }
        return '';
    }

    private static function clamp(string $text): string
    {
        if (strlen($text) <= self::MAX_REPLY_BYTES) {
            return $text;
        }
        return mb_strcut($text, 0, self::MAX_REPLY_BYTES, 'UTF-8') . "\n\n> 内容过长，已截断，请到 PC 端查看完整结果。";
    }

    // ==================================================================
    // 意图识别与查询
    // ==================================================================

    /**
     * 自然语言/关键词 → 系统数据
     *
     * 刻意做成确定性规则而非大模型：机器人回复必须可预期、可审计，
     * 也避免把企业数据发送给外部模型。
     */
    public static function answer(string $input): string
    {
        $q = trim($input);
        // 群聊里 @机器人 会带上 @昵称，去掉开头的 @xxx
        $q = preg_replace('/^@\S+\s*/u', '', $q) ?: $q;
        $q = trim($q);
        if ($q === '') {
            return self::helpText();
        }

        $ctx = self::context();
        if ($ctx === null) {
            return '机器人绑定的账号不可用，请联系管理员在「系统管理 → Agent 接入 → 企业微信机器人」中重新指定。';
        }

        try {
            if (self::match($q, ['帮助', 'help', '指令', '菜单', '功能', '怎么用', '用法', '?', '？'])) {
                return self::helpText();
            }
            if (self::match($q, ['概览', '总览', '统计', 'overview', '数据情况', '多少'])) {
                return self::formatOverview(AgentDataService::overview($ctx));
            }
            if (self::match($q, ['到期', '即将到期', '快到期', '续期', 'expire', 'expiring', '提醒'])) {
                $days = self::parseDays($q, 30);
                return self::formatExpiring(AgentDataService::expiring($ctx, [
                    'days'  => $days,
                    'page'  => 1,
                    'limit' => self::MAX_ITEMS,
                ]));
            }
            if (self::match($q, ['合同', 'contract'])) {
                $kw = self::cleanKeyword($q, ['合同', 'contract', '查询', '查', '搜索', '找', '看看', '列出', '一下']);
                return self::formatContracts(AgentDataService::contracts($ctx, [
                    'keyword' => $kw,
                    'page'    => 1,
                    'limit'   => self::MAX_ITEMS,
                ]));
            }
            if (self::match($q, ['人员', '员工', '持证', 'staff', '员工档案'])) {
                $kw = self::cleanKeyword($q, ['人员', '员工', '持证', 'staff', '档案', '查询', '查', '搜索', '找', '看看', '列出', '一下']);
                return self::formatStaff(AgentDataService::staffList($ctx, [
                    'keyword' => $kw,
                    'page'    => 1,
                    'limit'   => self::MAX_ITEMS,
                ]));
            }
            if (self::match($q, ['企业', '公司', '客户', 'company'])) {
                $kw = self::cleanKeyword($q, ['企业', '公司', '客户', 'company', '查询', '查', '搜索', '找', '看看', '列出', '一下']);
                return self::formatCompanies(AgentDataService::companies($ctx, [
                    'keyword' => $kw,
                    'page'    => 1,
                    'limit'   => self::MAX_ITEMS,
                ]));
            }

            // 默认按证书关键字检索（也覆盖「证书 / 证照 / 一建」等直接提问）
            $kw = self::cleanKeyword($q, ['证书', '证照', '资格证', '查询', '查', '搜索', '找', '看看', '列出', '一下']);
            return self::formatCertificates(AgentDataService::certificates($ctx, [
                'keyword' => $kw,
                'page'    => 1,
                'limit'   => self::MAX_ITEMS,
            ]));
        } catch (Throwable $e) {
            return '查询失败：' . $e->getMessage() . "\n\n可回复「帮助」查看支持的指令。";
        }
    }

    private static function match(string $q, array $words): bool
    {
        foreach ($words as $w) {
            if ($w !== '' && mb_stripos($q, $w) !== false) {
                return true;
            }
        }
        return false;
    }

    /** 解析「30天 / 7 天 / days=60」，默认 $default */
    private static function parseDays(string $q, int $default): int
    {
        if (preg_match('/(\d{1,3})\s*(天|日|days?)/iu', $q, $m)) {
            $d = (int)$m[1];
        } elseif (preg_match('/(\d{1,3})\s*个?\s*月/u', $q, $m)) {
            $d = (int)$m[1] * 30;
        } else {
            return $default;
        }
        return max(1, min($d, 365));
    }

    private static function cleanKeyword(string $q, array $remove): string
    {
        $kw = $q;
        foreach ($remove as $w) {
            $kw = str_ireplace($w, ' ', $kw);
        }
        $kw = preg_replace('/\d{1,3}\s*(天|日|days?)/iu', ' ', $kw) ?? $kw;
        $kw = preg_replace('/[，,。；;：:、\s]+/u', ' ', $kw) ?? $kw;
        return trim($kw);
    }

    /** 机器人绑定账号的权限上下文（与 API Key 中间件同口径） */
    public static function context(): ?array
    {
        $cfg = self::config();
        $userId = (int)$cfg['owner_user_id'];

        $user = $userId > 0
            ? SysUser::with(['role'])->find($userId)
            : SysUser::with(['role'])->where('role_id', 1)->where('status', 1)->order('id', 'asc')->find();

        if (!$user || (int)$user->status !== 1) {
            return null;
        }

        $payload = [
            'id'         => (int)$user->id,
            'username'   => (string)$user->username,
            'real_name'  => (string)$user->real_name,
            'role_id'    => (int)$user->role_id,
            'role_code'  => $user->role ? (string)$user->role->role_code : '',
            'dept_id'    => (int)$user->dept_id,
            'data_scope' => (string)($user->data_scope ?: 'self'),
        ];
        $isAdmin = $payload['role_code'] === 'admin';

        return [
            'user'        => $payload,
            'company_ids' => $isAdmin ? [] : UserCompanyRel::where('user_id', $payload['id'])->column('company_id'),
            'role_code'   => $payload['role_code'],
            'is_admin'    => $isAdmin,
        ];
    }

    // ==================================================================
    // 回复排版
    // ==================================================================

    private static function helpText(): string
    {
        return implode("\n", [
            '**🤖 证照助手**',
            '直接发关键词即可查询（数据范围跟随机器人绑定账号）：',
            '',
            '- `到期` / `到期 7天` — 即将到期的证照',
            '- `证书 一建` — 按名称 / 编号 / 专业搜证照',
            '- `人员 张三` — 人员档案',
            '- `企业 示例` — 企业客户与在借证照',
            '- `合同` / `合同 续签` — 服务合同',
            '- `概览` — 企业 / 人员 / 证照 / 合同 / 提醒总览',
            '',
            '> 只能查询，不能修改。新增、删除、状态变更请在 PC 端操作。',
        ]);
    }

    private static function formatOverview(array $d): string
    {
        return implode("\n", [
            '**📊 数据概览**',
            "- 企业客户：**{$d['companies']}**",
            "- 在职人员：**{$d['staff']}**",
            "- 证照总数：**{$d['certificates']}**",
            "- 执行中合同：**{$d['contracts_active']}**",
            "- 30 天内到期：**{$d['expiring_30d']}**",
            "- 未读提醒：**{$d['alerts_unread']}**",
            '',
            "> 统计时间：{$d['as_of']}",
        ]);
    }

    private static function formatExpiring(array $d): string
    {
        $list = (array)($d['list'] ?? []);
        $total = (int)($d['total'] ?? 0);
        $days = (int)($d['days'] ?? 30);
        if (!$list) {
            return "**⏰ {$days} 天内到期**\n\n没有即将到期的证照，状态良好。";
        }

        $lines = ["**⏰ {$days} 天内到期（共 {$total} 张，显示前 " . count($list) . "）**", ''];
        foreach ($list as $i => $item) {
            $name  = (string)($item['cert_name'] ?? '未命名证书');
            $staff = (string)($item['staff']['staff_name'] ?? '');
            $date  = (string)($item['expire_date'] ?? '');
            $left  = (int)($item['remain_days'] ?? 0);
            $comp  = (string)($item['company_name'] ?? '');
            $n = $i + 1;
            $lines[] = "{$n}. **{$name}**" . ($staff !== '' ? "（{$staff}）" : '');
            $meta = "   到期 {$date} ｜ 剩 {$left} 天";
            if ($comp !== '') {
                $meta .= " ｜ {$comp}";
            }
            $lines[] = $meta;
        }
        if ($total > count($list)) {
            $lines[] = '';
            $lines[] = "> 还有 " . ($total - count($list)) . " 张未展示，可在 PC 端「证照台账」筛选查看。";
        }
        return implode("\n", $lines);
    }

    private static function formatCertificates(array $d): string
    {
        $list = (array)($d['list'] ?? []);
        $total = (int)($d['total'] ?? 0);
        if (!$list) {
            return "没有匹配的证照。\n\n> 结果为空也可能是数据权限所致，可回复「帮助」换个关键词。";
        }
        $lines = ["**📄 证照查询（共 {$total} 张，显示前 " . count($list) . "）**", ''];
        foreach ($list as $i => $item) {
            $name  = (string)($item['cert_name'] ?? '未命名证书');
            $no    = (string)($item['cert_no'] ?? '');
            $staff = (string)($item['staff_name'] ?? ($item['staff']['staff_name'] ?? ''));
            $date  = (string)($item['expire_date'] ?? '');
            $left  = (int)($item['remain_days'] ?? 0);
            $st    = (string)($item['cert_status_text'] ?? '');
            $comp  = (string)($item['company_name'] ?? '');
            $n = $i + 1;
            $lines[] = "{$n}. **{$name}**" . ($staff !== '' ? "（{$staff}）" : '');
            $meta = '   ' . ($no !== '' ? "编号 {$no} ｜ " : '') . "状态 {$st} ｜ 到期 {$date}";
            if ($left >= 0) {
                $meta .= "（剩 {$left} 天）";
            }
            if ($comp !== '') {
                $meta .= " ｜ {$comp}";
            }
            $lines[] = $meta;
        }
        if ($total > count($list)) {
            $lines[] = '';
            $lines[] = "> 还有 " . ($total - count($list)) . " 张未展示。";
        }
        return implode("\n", $lines);
    }

    private static function formatStaff(array $d): string
    {
        $list = (array)($d['list'] ?? []);
        $total = (int)($d['total'] ?? 0);
        if (!$list) {
            return '没有匹配的人员。';
        }
        $lines = ["**👤 人员档案（共 {$total} 人，显示前 " . count($list) . "）**", ''];
        foreach ($list as $i => $item) {
            $name = (string)($item['staff_name'] ?? '');
            $dept = (string)($item['dept'] ?? '');
            $pos  = (string)($item['position'] ?? '');
            $phone = (string)($item['phone'] ?? '');
            $n = $i + 1;
            $meta = array_filter([$dept, $pos, $phone], fn ($v) => $v !== '');
            $lines[] = "{$n}. **{$name}**" . ($meta ? ' ｜ ' . implode(' ｜ ', $meta) : '');
        }
        if ($total > count($list)) {
            $lines[] = '';
            $lines[] = "> 还有 " . ($total - count($list)) . " 人未展示。";
        }
        return implode("\n", $lines);
    }

    private static function formatCompanies(array $d): string
    {
        $list = (array)($d['list'] ?? []);
        $total = (int)($d['total'] ?? 0);
        if (!$list) {
            return '没有匹配的企业。';
        }
        $lines = ["**🏢 企业客户（共 {$total} 家，显示前 " . count($list) . "）**", ''];
        foreach ($list as $i => $item) {
            $name = (string)($item['company_name'] ?? '');
            $sec  = (int)($item['sec_count'] ?? 0);
            $fee  = (string)($item['sec_fee'] ?? '');
            $n = $i + 1;
            $meta = "在借证照 {$sec} 张";
            if ($fee !== '' && (float)$fee > 0) {
                $meta .= " ｜ 借调费用 {$fee}";
            }
            $lines[] = "{$n}. **{$name}**";
            $lines[] = "   {$meta}";
        }
        if ($total > count($list)) {
            $lines[] = '';
            $lines[] = "> 还有 " . ($total - count($list)) . " 家未展示。";
        }
        return implode("\n", $lines);
    }

    private static function formatContracts(array $d): string
    {
        $list = (array)($d['list'] ?? []);
        $total = (int)($d['total'] ?? 0);
        if (!$list) {
            return '没有匹配的服务合同。';
        }
        $lines = ["**📑 服务合同（共 {$total} 份，显示前 " . count($list) . "）**", ''];
        foreach ($list as $i => $item) {
            $no    = (string)($item['contract_no'] ?? '');
            $title = (string)($item['title'] ?? '');
            $comp  = (string)($item['company']['company_name'] ?? '');
            $end   = (string)($item['end_date'] ?? '');
            $st    = (string)($item['status_text'] ?? '');
            $n = $i + 1;
            $lines[] = "{$n}. **" . ($title !== '' ? $title : $no) . '**' . ($comp !== '' ? "（{$comp}）" : '');
            $lines[] = '   ' . ($no !== '' ? "编号 {$no} ｜ " : '') . "状态 {$st} ｜ 到期 {$end}";
        }
        if ($total > count($list)) {
            $lines[] = '';
            $lines[] = "> 还有 " . ($total - count($list)) . " 份未展示。";
        }
        return implode("\n", $lines);
    }
}
