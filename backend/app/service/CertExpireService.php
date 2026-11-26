<?php
namespace app\service;

use app\model\AlertLog;
use app\model\Certificate;
use app\model\CertStatusLog;
use app\model\CompanyAlertConfig;
use app\model\CompanyReceiver;
use app\model\MsgTemplate;
use app\model\PushChannel;
use app\model\PushMsgLog;
use think\facade\Db;

/**
 * 证书到期检测与推送核心服务
 * - 每日定时执行（command: cert:checkexpire）
 * - 命中提醒节点 -> 写站内提醒日志 + 多通道推送（去重）
 * - 支持 dryRun（只体检不写库）与 catchUp（补发漏跑的提醒节点）
 */
class CertExpireService
{
    /**
     * v2.38：请求内配置快照缓存
     *
     * 推送遍历「证书 × 节点 × 渠道」时，消息模板与企业规则会被反复读取，
     * 是典型的 N+1（一次定时任务可能触发上千次重复查询）。此处按 key 缓存到
     * 进程内（CLI 单进程天然安全；Web 触发 resend 也只在单请求内有效）。
     * 长驻进程若需跨任务刷新，可在任务开头调用 self::resetConfigMemo()。
     */
    protected static array $cfgMemo = [];

    public static function resetConfigMemo(): void
    {
        self::$cfgMemo = [];
    }

    /** 消息模板（场景 × 渠道）——带请求内 memo */
    protected static function tplOf(string $scene, string $type)
    {
        $k = 'tpl:' . $scene . ':' . $type;
        if (!array_key_exists($k, self::$cfgMemo)) {
            self::$cfgMemo[$k] = MsgTemplate::where('scene', $scene)->where('template_type', $type)->find();
        }
        return self::$cfgMemo[$k];
    }

    protected static $nodeMap = [
        '1year' => 365, '6month' => 180, '3month' => 90, '2month' => 60,
        '1month' => 30, '15day' => 15, '7day' => 7, '3day' => 3, '1day' => 1,
    ];

    /** 补发宽限天数：节点刚过去不超过该天数才补发，避免一次性堆积大量历史节点提醒 */
    const CATCH_UP_GRACE_DAYS = 3;

    /**
     * @param bool $dryRun   true=只体检，不写提醒日志/推送日志
     * @param bool $catchUp  true=补发「刚过去（3天内）且从未提醒过」的节点，
     *                       解决定时任务漏跑导致「推送没触发」的问题
     */
    public static function check(bool $dryRun = false, bool $catchUp = false): array
    {
        $today = date('Y-m-d');
        $stats = [
            'cert_count' => 0, 'alert_count' => 0, 'push_count' => 0, 'push_fail' => 0,
            'push_simulated' => 0,
            'hit_count' => 0, 'block_count' => 0, 'items' => [], 'secondment_alert' => 0,
            'secondment_expired' => 0, 'company_cert_alert' => 0,
            'errors' => [], 'skipped' => [],
            'cert_expired' => 0,
        ];
        self::ensureAlertLogCols();
        self::ensurePushLogCols();
        self::resetConfigMemo(); // v2.38：每次任务开头清请求内配置快照（长驻进程防串味）
        if (!$dryRun) {
            self::ensureMsgTemplates();
            // 先把已过到期日的「正常」证书自动置为「已过期」，并写状态变更日志
            $stats['cert_expired'] = self::expireOutdatedCertificates($today, $stats);
        }

        $certs = Certificate::where('cert_status', 1)
            ->where('enable_alert', 1)
            ->where('expire_date', '>=', $today)
            ->select();

        foreach ($certs as $cert) {
            $stats['cert_count']++;
            // 单条证书异常隔离：一条脏数据不能让整次定时任务 500
            try {
                $remain = remain_days($cert->expire_date);
                if ($remain < 0) {
                    continue;
                }
                $nodes = self::resolveNodes($cert, SecondmentService::companyIdOf($cert->id));
                foreach ($nodes as $node) {
                    $days = self::nodeToDays($node);
                    if ($days === null || $days < 0) {
                        continue;
                    }
                    $matched   = ($remain === $days);
                    $needCatch = false;
                    if (!$matched && $catchUp) {
                        // 节点刚过去（宽限 3 天）且此前从没提醒过 -> 判定为定时任务漏跑，补发一次
                        $passed = $days - $remain;
                        if ($passed > 0 && $passed <= self::CATCH_UP_GRACE_DAYS) {
                            $needCatch = AlertLog::where('cert_id', $cert->id)
                                ->where('alert_node', $node)->count() === 0;
                        }
                    }
                    if (!$matched && !$needCatch) {
                        continue;
                    }
                    $dup = AlertLog::where('cert_id', $cert->id)
                        ->where('alert_node', $node)
                        ->where('alert_date', $today)
                        ->find();
                    if ($dup) {
                        continue;
                    }
                    $item = [
                        'cert_id'    => $cert->id,
                        'cert_name'  => $cert->cert_name,
                        'expire_date'=> $cert->expire_date,
                        'remain'     => $remain,
                        'node'       => $node,
                        'node_days'  => $days,
                        'mode'       => $matched ? 'exact' : 'catch_up',
                        'result'     => 'hit',
                        'reason'     => '',
                        'channels'   => [],
                    ];
                    self::fire($cert, $node, $remain, $today, $stats, $dryRun, $item);
                    $stats['alert_count']++;
                    $stats['items'][] = $item;
                }
            } catch (\Throwable $e) {
                self::recordError($stats, 'cert#' . $cert->id, $e);
            }
        }
        // 借调临期提醒：按企业配置的节点，在 end_date 前 N 天触发多渠道推送
        $stats['secondment_alert'] = self::checkSecondmentExpiry($today, $dryRun, $stats, $catchUp);
        // 借调到期检测：到期未归还的自动标记为「已到期」并推送
        $stats['secondment_expired'] = self::checkSecondment($today, $dryRun, $stats);
        // 企业证书（安许 / 资质）临期提醒：与借调临期同构，按企业节点多渠道推送
        $stats['company_cert_alert'] = self::checkCompanyQualificationExpiry($today, $dryRun, $stats, $catchUp);

        // 统计命中 / 被阻断（同时供体检与正式检测使用）
        foreach ($stats['items'] as $it) {
            if ($it['result'] === 'hit') {
                $stats['hit_count']++;
            } else {
                $stats['block_count']++;
            }
        }
        self::recordLastRun($stats, $dryRun);
        return $stats;
    }

    /**
     * 记录最近一次真实执行结果，供推送体检判断「定时任务是否真的在跑」。
     * 写入失败不影响主流程（runtime 目录不可写时只缺少监控信息）。
     */
    protected static function recordLastRun(array $stats, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }
        $data = [
            'finished_at'        => date('Y-m-d H:i:s'),
            'cert_count'         => $stats['cert_count'] ?? 0,
            'alert_count'        => $stats['alert_count'] ?? 0,
            'hit_count'          => $stats['hit_count'] ?? 0,
            'block_count'        => $stats['block_count'] ?? 0,
            'push_count'         => $stats['push_count'] ?? 0,
            'push_fail'          => $stats['push_fail'] ?? 0,
            'push_simulated'     => $stats['push_simulated'] ?? 0,
            'secondment_alert'   => $stats['secondment_alert'] ?? 0,
            'secondment_expired' => $stats['secondment_expired'] ?? 0,
            'company_cert_alert' => $stats['company_cert_alert'] ?? 0,
            'cert_expired'       => $stats['cert_expired'] ?? 0,
            'error_count'        => count($stats['errors'] ?? []),
            'skipped_count'      => count($stats['skipped'] ?? []),
        ];
        try {
            @file_put_contents(
                self::lastRunFile(),
                json_encode($data, JSON_UNESCAPED_UNICODE)
            );
        } catch (\Throwable $e) {
            // 监控信息写入失败不影响业务
        }
    }

    /**
     * 最近一次真实执行结果的落盘路径（runtime 目录，便于体检读取）。
     */
    protected static function lastRunFile(): string
    {
        return runtime_path() . 'cert_last_run.json';
    }

    /**
     * 推断 PHP CLI 可执行文件路径。
     * 体检接口运行在 PHP-FPM 下，PHP_BINARY 是 php-fpm，不能用；PHP_BINDIR 指向编译时的 bin 目录，
     * 宝塔通常就是 /www/server/php/82/bin，可直接拼出 php。取不到时回退到 php/php.exe。
     */
    protected static function phpCliPath(): string
    {
        $binary = defined('PHP_BINARY') ? (string)PHP_BINARY : '';
        $base   = strtolower(basename($binary));
        $exe    = DIRECTORY_SEPARATOR === '\\' ? 'php.exe' : 'php';

        // CLI 环境直接可用
        if ($binary !== '' && ($base === 'php' || $base === 'php.exe')) {
            return $binary;
        }
        // FPM：/www/server/php/82/sbin/php-fpm -> /www/server/php/82/bin/php
        if ($binary !== '' && str_contains($base, 'php-fpm')) {
            $candidate = dirname(dirname($binary)) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'php';
            if (@is_file($candidate)) {
                return $candidate;
            }
        }
        // PHP_BINDIR 指向编译时的 bin 目录（部分 Windows 集成环境是 C:\php，需校验存在性）
        $bin = defined('PHP_BINDIR') ? (string)PHP_BINDIR : '';
        if ($bin !== '') {
            $candidate = rtrim($bin, '/\\') . DIRECTORY_SEPARATOR . $exe;
            if (@is_file($candidate)) {
                return $candidate;
            }
        }
        return $exe;
    }

    /**
     * 证书自动过期：到期日早于今天的「正常」证书统一置为「已过期」，并写状态变更日志。
     * 仅正常状态(1)会被处理，挂失(2)/注销(3)保持原状。
     */
    public static function expireOutdatedCertificates(string $today = '', array &$stats = []): int
    {
        $today = $today ?: date('Y-m-d');
        $rows  = Certificate::where('cert_status', 1)
            ->whereNotNull('expire_date')
            ->where('expire_date', '<', $today)
            ->field('id,cert_name,expire_date')
            ->select();
        $count = 0;
        foreach ($rows as $cert) {
            try {
                Certificate::where('id', $cert->id)->update(['cert_status' => 0]);
                CertStatusLog::create([
                    'cert_id'    => $cert->id,
                    'old_status' => 1,
                    'new_status' => 0,
                    'operator'   => 'system',
                    'remark'     => '到期日已过（' . $cert->expire_date . '），自动标记为已过期',
                ]);
                $count++;
            } catch (\Throwable $e) {
                self::recordError($stats, 'cert-expire#' . $cert->id, $e);
            }
        }
        if ($count > 0) {
            try {
                \app\controller\admin\Dashboard::forgetDashboard();
            } catch (\Throwable $ignore) {
            }
        }
        return $count;
    }

    /**
     * 借调到期检测：把已过 end_date 且仍为「借调中」的记录置为「已到期」并推送提醒
     */
    public static function checkSecondment(string $today = '', bool $dryRun = false, array &$stats = []): int
    {
        $today = $today ?: date('Y-m-d');
        $rows  = SecondmentService::expiredActives($today);
        foreach ($rows as $row) {
            // 脏数据防御：结束日期早于开始日期的记录不参与到期处理（清理数据前也可安全运行）
            if (!empty($row['start_date']) && !empty($row['end_date']) && $row['end_date'] < $row['start_date']) {
                self::recordSkip($stats, 'secondment#' . $row['id'], '结束日期早于开始日期');
                continue;
            }
            try {
                $certName   = $row['certificate']['cert_name'] ?? '';
                $companyName= $row['company']['company_name'] ?? '';
                $content    = self::buildSecondmentContent('sms', $row, 0, 'secondment_expire');
                $dup = AlertLog::where('cert_id', $row['cert_id'])
                    ->where('alert_node', 'secondment_expire')
                    ->where('alert_date', $today)->find();
                if ($dup) {
                    continue;
                }
                if (!$dryRun) {
                    \app\model\CertSecondment::where('id', $row['id'])->update(['status' => 2]);
                    // v2.76：定时任务把借调置为已到期，也要在状态变更日志留痕
                    \app\service\CertStatusLogService::secondment(
                        (int)$row['id'],
                        (int)$row['cert_id'],
                        (int)($row['status'] ?? 1),
                        2,
                        'system',
                        '借调到期（结束日期 ' . ($row['end_date'] ?? '-') . '），自动标记为已到期'
                    );
                    AlertLog::create([
                        'company_id'    => $row['company_id'],
                        'cert_id'       => $row['cert_id'],
                        'staff_id'      => (int)($row['staff_id'] ?? 0),
                        'alert_day'     => 0,
                        'alert_node'    => 'secondment_expire',
                        'alert_date'    => $today,
                        'alert_content' => $content,
                        'alert_type'    => 'secondment',
                        'secondment_id' => (int)$row['id'],
                    ]);
                }
                $item = [
                    'cert_id'   => $row['cert_id'],
                    'cert_name' => $certName,
                    'remain'    => 0,
                    'node'      => 'secondment_expire',
                    'mode'      => 'exact',
                    'result'    => 'hit',
                    'reason'    => '',
                    'channels'  => [],
                ];
                $item['company'] = $companyName;
                $staff     = $row['staff'] ? (object)$row['staff'] : null;
                $contentFn = function (string $type) use ($row) {
                    return self::buildSecondmentContent($type, $row, 0, 'secondment_expire');
                };
                self::deliver((int)$row['company_id'], $contentFn, $staff, $row['cert_id'], (int)$row['id'], 'secondment_expire', $today, $dryRun, $stats, $item, 'secondment');
                $stats['alert_count']++;
                $stats['items'][] = $item;
            } catch (\Throwable $e) {
                self::recordError($stats, 'secondment#' . $row['id'], $e);
            }
        }
        return count($rows);
    }

    /**
     * 借调临期提醒：扫描「借调中」且 end_date 尚未到来的记录，
     * 按企业配置的提醒节点（剩余天数）触发多渠道推送。
     */
    public static function checkSecondmentExpiry(string $today = '', bool $dryRun = false, array &$stats = [], bool $catchUp = false): int
    {
        $today = $today ?: date('Y-m-d');
        $rows  = \app\model\CertSecondment::with(['certificate', 'company', 'staff'])
            ->where('status', 1)
            ->whereNotNull('end_date')
            ->where('end_date', '>=', $today)
            ->select()->toArray();
        $count = 0;
        foreach ($rows as $row) {
            try {
                $cert = $row['certificate'] ?? null;
                if (!$cert) {
                    continue;
                }
                $companyId   = (int)$row['company_id'];
                $companyName = $row['company']['company_name'] ?? '';
                $remain      = SecondmentService::remainDays($row['end_date']);
                if ($remain === null || $remain < 0) {
                    continue;
                }
                $nodes = self::secondmentNodes($companyId);
                foreach ($nodes as $node) {
                    $days = self::nodeToDays($node);
                    if ($days === null || $days < 0) {
                        continue;
                    }
                    $nodeKey = 'secondment_' . $node;
                    $matched = ($remain === $days);
                    $needCatch = false;
                    if (!$matched && $catchUp) {
                        // 节点刚过去（宽限 3 天）且该借调此前从没提醒过 -> 判定为定时任务漏跑，补发一次
                        $passed = $days - $remain;
                        if ($passed > 0 && $passed <= self::CATCH_UP_GRACE_DAYS) {
                            $needCatch = AlertLog::where('secondment_id', (int)$row['id'])
                                ->where('alert_node', $nodeKey)->count() === 0;
                        }
                    }
                    if (!$matched && !$needCatch) {
                        continue;
                    }
                    // 同一天同一借调同一节点只提醒一次；按 secondment_id 去重，避免同一证书换借调后串号
                    $dup = AlertLog::where('secondment_id', (int)$row['id'])
                        ->where('alert_node', $nodeKey)
                        ->where('alert_date', $today)
                        ->find();
                    if ($dup) {
                        continue;
                    }
                    $item = [
                        'cert_id'   => $cert['id'],
                        'cert_name' => $cert['cert_name'],
                        'end_date'  => $row['end_date'],
                        'remain'    => $remain,
                        'node'      => $nodeKey,
                        'mode'      => $matched ? 'exact' : 'catch_up',
                        'result'    => 'hit',
                        'reason'    => '',
                        'channels'  => [],
                    ];
                    $item['company'] = $companyName;
                    $content = self::buildSecondmentContent('sms', $row, $remain, 'secondment_approach');
                    if (!$dryRun) {
                        AlertLog::create([
                            'company_id'    => $companyId,
                            'cert_id'       => $cert['id'],
                            'staff_id'      => (int)($row['staff']['id'] ?? 0),
                            'alert_day'     => $remain,
                            'alert_node'    => $nodeKey,
                            'alert_date'    => $today,
                            'alert_content' => $content,
                            'alert_type'    => 'secondment',
                            'secondment_id' => (int)$row['id'],
                        ]);
                    }
                    $staff     = $row['staff'] ? (object)$row['staff'] : null;
                    $contentFn = function (string $type) use ($row, $remain) {
                        return self::buildSecondmentContent($type, $row, $remain, 'secondment_approach');
                    };
                    self::deliver($companyId, $contentFn, $staff, $cert['id'], (int)$row['id'], $nodeKey, $today, $dryRun, $stats, $item, 'secondment');
                    $stats['alert_count']++;
                    $stats['items'][] = $item;
                    $count++;
                }
            } catch (\Throwable $e) {
                self::recordError($stats, 'secondment#' . $row['id'], $e);
            }
        }
        return $count;
    }

    /** 借调提醒节点：复用企业配置里的 alert_nodes（与证书一致，按 end_date 前 N 天触发） */
    protected static function secondmentNodes(int $companyId): array
    {
        $cfg = self::alertConfigOf($companyId);
        if ($cfg && !empty($cfg->alert_nodes)) {
            return array_values(array_unique($cfg->alert_nodes));
        }
        return ['1month', '7day', '3day'];
    }

    /**
     * 企业证书（安许 / 资质）临期提醒：
     * 扫描 company_qualification 有效记录，按企业配置节点在 expire_date 前 N 天推送。
     * 与借调临期同构：站内 alert_log（alert_type=company_cert）+ 多渠道推送，
     * 去重以 company_qualification_id 为准（cert_id 恒为 0，避免多家企业撞唯一键）。
     */
    public static function checkCompanyQualificationExpiry(string $today = '', bool $dryRun = false, array &$stats = [], bool $catchUp = false): int
    {
        $today = $today ?: date('Y-m-d');
        CompanyQualificationService::ensureSchema();
        $rows  = Db::name('company_qualification')
            ->where('status', 1)
            ->whereNotNull('expire_date')
            ->where('expire_date', '>=', $today)
            ->select()->toArray();
        $count = 0;
        foreach ($rows as $row) {
            try {
                $companyId = (int)$row['company_id'];
                if ($companyId <= 0) {
                    continue;
                }
                $remain = remain_days((string)$row['expire_date']);
                if ($remain === null || $remain < 0) {
                    continue;
                }
                $nodes = self::secondmentNodes($companyId);
                foreach ($nodes as $node) {
                    $days = self::nodeToDays($node);
                    if ($days === null || $days < 0) {
                        continue;
                    }
                    $nodeKey = 'companycert_' . $node;
                    $matched = ($remain === $days);
                    $needCatch = false;
                    if (!$matched && $catchUp) {
                        $passed = $days - $remain;
                        if ($passed > 0 && $passed <= self::CATCH_UP_GRACE_DAYS) {
                            $needCatch = AlertLog::where('company_qualification_id', (int)$row['id'])
                                ->where('alert_node', $nodeKey)->count() === 0;
                        }
                    }
                    if (!$matched && !$needCatch) {
                        continue;
                    }
                    $dup = AlertLog::where('company_qualification_id', (int)$row['id'])
                        ->where('alert_node', $nodeKey)
                        ->where('alert_date', $today)
                        ->find();
                    if ($dup) {
                        continue;
                    }
                    $companyName = (string)(Db::name('client_company')->where('id', $companyId)->value('company_name') ?? '');
                    $content = self::buildCompanyCertContent('sms', $row, $companyName, $remain);
                    $item = [
                        'cert_id'   => 0,
                        'cert_name' => $row['cert_name'],
                        'expire_date' => $row['expire_date'],
                        'remain'    => $remain,
                        'node'      => $nodeKey,
                        'node_days' => $days,
                        'mode'      => $matched ? 'exact' : 'catch_up',
                        'result'    => 'hit',
                        'reason'    => '',
                        'channels'  => [],
                    ];
                    $item['company'] = $companyName;
                    if (!$dryRun) {
                        AlertLog::create([
                            'company_id'               => $companyId,
                            'cert_id'                  => 0,
                            'staff_id'                 => 0,
                            'alert_day'                => $remain,
                            'alert_node'               => $nodeKey,
                            'alert_date'               => $today,
                            'alert_content'            => $content,
                            'alert_type'               => 'company_cert',
                            'secondment_id'            => 0,
                            'company_qualification_id' => (int)$row['id'],
                        ]);
                    }
                    $contentFn = function (string $type) use ($row, $companyName, $remain) {
                        return self::buildCompanyCertContent($type, $row, $companyName, $remain);
                    };
                    // 企业证书无持证人，staff 传 null；第二业务 ID 即 company_qualification_id
                    self::deliver($companyId, $contentFn, null, 0, (int)$row['id'], $nodeKey, $today, $dryRun, $stats, $item, 'company_cert');
                    $stats['alert_count']++;
                    $stats['items'][] = $item;
                    $count++;
                }
            } catch (\Throwable $e) {
                self::recordError($stats, 'company-qual#' . $row['id'], $e);
            }
        }
        return $count;
    }

    /** 企业证书提醒文案（场景 company_cert_expire，变量：企业名/证书名/编号/到期日/剩余天数） */
    protected static function buildCompanyCertContent(string $type, array $row, string $companyName, int $remain): string
    {
        $tpl = self::tplOf('company_cert_expire', $type);
        $data = [
            'company_name' => $companyName,
            'staff_name'   => '',
            'cert_name'    => (string)($row['cert_name'] ?? ''),
            'cert_no'      => (string)($row['cert_no'] ?? ''),
            'expire_date'  => (string)($row['expire_date'] ?? ''),
            'remain_days'  => $remain,
            'detail_url'   => '',
        ];
        if ($tpl) {
            return MsgTemplate::render($tpl->content, $data);
        }
        $kind = ($row['q_type'] ?? '') === 'safety' ? '安全生产许可证' : '资质证书';
        return "【企业证书到期预警】{$companyName} 的{$kind}「" . $row['cert_name'] . "」将于 "
            . $row['expire_date'] . " 到期（剩余 {$remain} 天），请及时办理延续。";
    }

    /** 借调提醒文案（支持消息模板 {{变量}}，变量与证书一致，并额外给出 end_date） */
    protected static function buildSecondmentContent(string $type, array $row, int $remain, string $scene = 'secondment_approach'): string
    {
        $companyName = $row['company']['company_name'] ?? '';
        $staffName   = $row['staff']['staff_name'] ?? '';
        $certName    = $row['certificate']['cert_name'] ?? '';
        $certId      = (int)($row['certificate']['id'] ?? ($row['cert_id'] ?? 0));
        $tpl = self::tplOf($scene, $type); // v2.38：请求内 memo，避免逐条重复查模板
        $detailUrl = h5_detail_url($certId);
        if ($detailUrl !== '' && !preg_match('#^https?://#i', $detailUrl)) {
            $detailUrl = '';
        }
        $data = [
            'company_name' => $companyName,
            'staff_name'   => $staffName,
            'cert_name'    => $certName,
            'cert_no'      => $row['certificate']['cert_no'] ?? '',
            'expire_date'  => $row['end_date'],
            'remain_days'  => $remain,
            'detail_url'   => $detailUrl,
        ];
        if ($tpl) {
            $content = MsgTemplate::render($tpl->content, $data);
            if ($detailUrl === '' && str_contains((string)$tpl->content, '{{detail_url}}')) {
                $content = strip_empty_detail_link($content);
            }
            return $content;
        }
        $text = "【借调到期预警】{$companyName}：{$staffName} 借用的 {$certName} 将于 {$row['end_date']} 到期（剩余 {$remain} 天），请及时归还或续借。";
        if ($detailUrl !== '') {
            $text .= "\n查看详情：" . $detailUrl;
        }
        return $text;
    }

    /** 对外暴露的 alert_log 结构自愈（列表查询前确保新列 / 去重索引就绪） */
    public static function ensureAlertLogColsPublic(): void
    {
        self::ensureAlertLogCols();
    }

    /** 自愈 push_msg_log 业务归属列（v2.93：区分证书/借调/企业证书推送留痕） */
    protected static function ensurePushLogCols(): void
    {
        ensure_column('push_msg_log', 'biz_type', "varchar(20) NOT NULL DEFAULT 'cert' COMMENT '业务类型: cert证书 / secondment借调 / company_cert企业证书' AFTER `cert_id`");
        ensure_column('push_msg_log', 'biz_id', "int unsigned NOT NULL DEFAULT 0 COMMENT '第二业务ID：cert_secondment.id / company_qualification.id' AFTER `biz_type`");
    }

    /** 自愈 alert_log 新列（老库可能缺 alert_type / secondment_id / company_qualification_id） */
    protected static function ensureAlertLogCols(): void
    {
        ensure_column('alert_log', 'alert_type', "varchar(16) NOT NULL DEFAULT 'cert' COMMENT '提醒类型: cert证书 / secondment借调 / company_cert企业证书' AFTER `cert_id`");
        ensure_column('alert_log', 'secondment_id', "int unsigned NOT NULL DEFAULT 0 COMMENT '关联借调记录ID（alert_type=secondment 时有效）' AFTER `staff_id`");
        // v2.93：企业证书提醒 cert_id 恒为 0，旧唯一键 (cert_id,alert_node,alert_date)
        // 会让多家企业同节点同日互相撞键。纳入 secondment_id / company_qualification_id 后重建。
        ensure_column('alert_log', 'company_qualification_id', "int unsigned NOT NULL DEFAULT 0 COMMENT '关联企业证书ID（alert_type=company_cert 时有效）' AFTER `secondment_id`");
        self::rebuildAlertDedupIndex();
    }

    /**
     * 重建去重唯一键：旧键 (cert_id,alert_node,alert_date) 无法区分
     * cert_id=0 的借调到期/企业证书提醒，扩展为四类业务 ID 共同去重。
     * 仅当旧键存在时执行一次，重复执行无副作用。
     */
    protected static function rebuildAlertDedupIndex(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        try {
            $row = Db::query("SHOW INDEX FROM `alert_log` WHERE Key_name = 'uk_dedup'");
            $cols = [];
            foreach ($row ?: [] as $r) {
                $cols[(int)($r['Seq_in_index'] ?? 0)] = $r['Column_name'] ?? '';
            }
            ksort($cols);
            $cols = array_values(array_filter($cols));
            $need = ['cert_id', 'alert_node', 'alert_date', 'secondment_id', 'company_qualification_id'];
            if ($cols === $need) {
                $done = true;
                return;
            }
            if ($cols) {
                // 旧结构（通常是 cert_id,alert_node,alert_date）→ 删除后按新结构重建
                Db::execute("ALTER TABLE `alert_log` DROP INDEX `uk_dedup`");
            }
            Db::execute("ALTER TABLE `alert_log` ADD UNIQUE KEY `uk_dedup` "
                . "(`cert_id`,`alert_node`,`alert_date`,`secondment_id`,`company_qualification_id`)");
        } catch (\Throwable $e) {
            // 索引/列未就绪等异常忽略，不阻断提醒主流程（下一轮自愈会重试）
        }
        $done = true;
    }

    /** 老库自愈：补齐 msg_template 的 scene 列，并为各场景 × 渠道生成默认模板 */
    public static function ensureMsgTemplates(): void
    {
        ensure_column('msg_template', 'scene',
            "varchar(32) NOT NULL DEFAULT 'cert_expire' COMMENT '提醒场景: cert_expire/secondment_approach/secondment_expire/secondment_renew/gov_progress' AFTER `id`");
        // 旧数据未带 scene 的，统一归到证书到期场景
        MsgTemplate::whereNull('scene')->whereOr('scene', '')->update(['scene' => 'cert_expire']);

        $defaults = [
            'cert_expire' => [
                'sms'        => ['证书到期短信', '【证书预警】{{company_name}}：{{staff_name}}的{{cert_name}}({{cert_no}})剩余{{remain_days}}天到期，请及时处理。'],
                'email'      => ['证书到期邮件', '尊敬的{{company_name}}：持证人员{{staff_name}}的{{cert_name}}(编号{{cert_no}})将于{{expire_date}}到期，剩余{{remain_days}}天，请尽快办理续期。'],
                'wecom'      => ['企微群通知', '🚨 证书到期预警：{{company_name}} - {{staff_name}} - {{cert_name}}({{cert_no}})，剩余{{remain_days}}天到期。'],
                'wecom_app'  => ['企业微信应用消息', '🚨 证书到期预警：{{company_name}} - {{staff_name}} - {{cert_name}}({{cert_no}})，剩余{{remain_days}}天到期。'],
                'mp'         => ['公众号模板消息', '您关注的证书{{cert_name}}({{cert_no}})剩余{{remain_days}}天到期，请及时处理。'],
            ],
            'secondment_approach' => [
                'sms'        => ['借调到期短信', '【借调到期提醒】{{company_name}}：{{staff_name}}借用的{{cert_name}}({{cert_no}})将于{{expire_date}}到期（剩余{{remain_days}}天），请及时处理归还或续借。'],
                'email'      => ['借调到期邮件', '尊敬的{{company_name}}：您借调的{{staff_name}}持有的{{cert_name}}(编号{{cert_no}})将于{{expire_date}}到期，剩余{{remain_days}}天，请提前安排归还或续借。'],
                'wecom'      => ['借调临期群通知', '🚨 借调到期提醒：{{company_name}} - {{staff_name}} - {{cert_name}}({{cert_no}})，剩余{{remain_days}}天到期，请安排归还或续借。'],
                'wecom_app'  => ['借调临期应用消息', '🚨 借调到期提醒：{{company_name}} - {{staff_name}} - {{cert_name}}({{cert_no}})，剩余{{remain_days}}天到期，请安排归还或续借。'],
                'mp'         => ['借调临期公众号', '您关注的借调证书{{cert_name}}({{cert_no}})剩余{{remain_days}}天到期，请及时处理归还或续借。'],
            ],
            'secondment_expire' => [
                'sms'        => ['借调已到期短信', '【借调已到期】{{company_name}}：{{staff_name}}借用的{{cert_name}}({{cert_no}})已于{{expire_date}}到期，请尽快归还或办理续借。'],
                'email'      => ['借调已到期邮件', '尊敬的{{company_name}}：您借调的{{staff_name}}持有的{{cert_name}}(编号{{cert_no}})已于{{expire_date}}到期，请尽快归还或办理续借。'],
                'wecom'      => ['借调到期群通知', '🚨 借调已到期：{{company_name}} - {{staff_name}} - {{cert_name}}({{cert_no}})，已于{{expire_date}}到期，请归还或续借。'],
                'wecom_app'  => ['借调到期应用消息', '🚨 借调已到期：{{company_name}} - {{staff_name}} - {{cert_name}}({{cert_no}})，已于{{expire_date}}到期，请归还或续借。'],
                'mp'         => ['借调到期公众号', '您关注的借调证书{{cert_name}}({{cert_no}})已于{{expire_date}}到期，请尽快归还或续借。'],
            ],
            'secondment_renew' => [
                'sms'        => ['借调续期短信', '【借调续期成功】{{company_name}}：{{staff_name}}借用的{{cert_name}}({{cert_no}})借期已延长至{{expire_date}}，请知悉。'],
                'email'      => ['借调续期邮件', '尊敬的{{company_name}}：您借调的{{staff_name}}持有的{{cert_name}}(编号{{cert_no}})借期已延长至{{expire_date}}，特此通知。'],
                'wecom'      => ['借调续期群通知', '✅ 借调续期通知：{{company_name}} - {{staff_name}} - {{cert_name}}({{cert_no}})，借期已延长至{{expire_date}}。'],
                'wecom_app'  => ['借调续期应用消息', '✅ 借调续期通知：{{company_name}} - {{staff_name}} - {{cert_name}}({{cert_no}})，借期已延长至{{expire_date}}。'],
                'mp'         => ['借调续期公众号', '您关注的借调证书{{cert_name}}({{cert_no}})借期已延长至{{expire_date}}。'],
            ],
            // v2.93：企业证书（安许 / 资质）到期提醒（场景 company_cert_expire）
            'company_cert_expire' => [
                'sms'        => ['企业证书到期短信', '【企业证书预警】{{company_name}}：{{cert_name}}({{cert_no}})将于{{expire_date}}到期，剩余{{remain_days}}天，请及时办理延续。'],
                'email'      => ['企业证书到期邮件', '尊敬的{{company_name}}：贵司{{cert_name}}(编号{{cert_no}})将于{{expire_date}}到期，剩余{{remain_days}}天，请尽快安排办理延续手续。'],
                'wecom'      => ['企业证书到期群通知', '🚨 企业证书到期预警：{{company_name}} - {{cert_name}}({{cert_no}})，剩余{{remain_days}}天到期，请及时办理延续。'],
                'wecom_app'  => ['企业证书到期应用消息', '🚨 企业证书到期预警：{{company_name}} - {{cert_name}}({{cert_no}})，剩余{{remain_days}}天到期，请及时办理延续。'],
                'mp'         => ['企业证书到期公众号', '贵司证书{{cert_name}}({{cert_no}})剩余{{remain_days}}天到期，请及时办理延续。'],
            ],
            // v2.73：申报进度更新推送（场景 gov_progress）
            // v2.81.3：默认模板带出 H5 详情链接（{{detail_url}} 由推送时渲染）
            // v2.99.93：模板带最近一次办理意见（{{current_opinion}}，政务网原文，为空时渲染为空）
            'gov_progress' => [
                'sms'        => ['申报进度短信', '【申报进度更新】{{company_name}}：{{project_name}}(申报号{{project_no}})当前环节「{{current_node}}」，进度{{progress_percent}}%。{{current_opinion}}' . "\n\n查看详情：{{detail_url}}"],
                'email'      => ['申报进度邮件', '尊敬的{{company_name}}：您关注的申报事项{{project_name}}(申报号{{project_no}})进度已更新。当前环节：{{current_node}}，总进度{{progress_percent}}%；{{is_finished_text}}。办理意见：{{current_opinion}}' . "\n\n查看详情：{{detail_url}}"],
                'wecom'      => ['申报进度群通知', '📢 申报进度更新：{{company_name}} - {{project_name}}({{project_no}}) 当前环节「{{current_node}}」，进度{{progress_percent}}%。{{current_opinion}}' . "\n\n[查看详情]({{detail_url}})"],
                'wecom_app'  => ['申报进度应用消息', '📢 申报进度更新：{{company_name}} - {{project_name}}({{project_no}}) 当前环节「{{current_node}}」，进度{{progress_percent}}%。{{current_opinion}}' . "\n\n查看详情：{{detail_url}}"],
                'mp'         => ['申报进度公众号', '您关注的申报{{project_name}}({{project_no}})进度更新为「{{current_node}}」，总进度{{progress_percent}}%。{{current_opinion}}' . "\n\n查看详情：{{detail_url}}"],
            ],
        ];
        foreach ($defaults as $scene => $channels) {
            foreach ($channels as $type => $cfg) {
                if (MsgTemplate::where('scene', $scene)->where('template_type', $type)->find()) {
                    continue;
                }
                MsgTemplate::create([
                    'scene'         => $scene,
                    'template_type' => $type,
                    'template_name' => $cfg[0],
                    'content'       => $cfg[1],
                ]);
            }
        }
    }

    /**
     * 提醒补发：对一条已有的 alert_log 记录，按当前渠道配置重新推送其 alert_content。
     * 用于「推送没发出去 / 想手动再发一次」的场景；不新增站内提醒记录，只产生 push_msg_log。
     */
    public static function resendAlert(int $logId): array
    {
        $log = AlertLog::find($logId);
        if (!$log) {
            return ['ok' => false, 'log_id' => $logId, 'msg' => '提醒记录不存在'];
        }
        $companyId = (int)$log->company_id;
        $staff     = $log->staff;
        $stats     = ['push_count' => 0, 'push_fail' => 0];
        $item      = ['cert_id' => $log->cert_id, 'node' => $log->alert_node, 'result' => 'hit', 'reason' => '', 'channels' => []];
        $contentFn = function (string $type) use ($log) { return $log->alert_content; };
        $alertType = (string)($log->alert_type ?? 'cert');
        if ($alertType === 'company_cert') {
            // 企业证书提醒：cert_id=0，第二 ID 为 company_qualification.id
            self::deliver($companyId, $contentFn, $staff, 0, (int)($log->company_qualification_id ?? 0), $log->alert_node, $log->alert_date ?: date('Y-m-d'), false, $stats, $item, 'company_cert');
        } else {
            // cert 证书提醒：biz_type=cert；secondment 借调提醒：biz_type=secondment
            self::deliver($companyId, $contentFn, $staff, $log->cert_id, (int)$log->secondment_id, $log->alert_node, $log->alert_date ?: date('Y-m-d'), false, $stats, $item, $alertType === 'secondment' ? 'secondment' : 'cert');
        }
        $blocked = ($item['result'] ?? 'hit') === 'blocked';
        return [
            'ok'          => !$blocked && ($stats['push_fail'] ?? 0) === 0,
            'log_id'      => $logId,
            'push_count'  => $stats['push_count'],
            'push_fail'   => $stats['push_fail'],
            'blocked'     => $blocked,
            'reason'      => $item['reason'] ?? '',
            'channels'    => $item['channels'] ?? [],
        ];
    }

    /**
     * 借调续借通知推送：借调续期后，按「借调续借(secondment_renew)」场景模板向已启用渠道推送
     * @return array ['ok','push_count','push_fail','blocked','reason','channels']
     */
    public static function secondmentRenewPush(int $secondmentId): array
    {
        $row = \app\model\CertSecondment::with(['certificate', 'company', 'staff'])->find($secondmentId);
        if (!$row) {
            return ['ok' => false, 'push_count' => 0, 'push_fail' => 0, 'blocked' => false, 'reason' => '借调记录不存在', 'channels' => []];
        }
        $companyId = (int)$row->company_id;
        $staff     = $row->staff ? (object)$row->staff : null;
        $stats     = ['push_count' => 0, 'push_fail' => 0];
        $item      = ['cert_id' => $row->cert_id, 'node' => 'secondment_renew', 'result' => 'hit', 'reason' => '', 'channels' => []];
        $contentFn = function (string $type) use ($row) {
            return self::buildSecondmentContent($type, $row->toArray(), 0, 'secondment_renew');
        };
        self::deliver($companyId, $contentFn, $staff, $row->cert_id, $secondmentId, 'secondment_renew', date('Y-m-d'), false, $stats, $item, 'secondment');
        $blocked = ($item['result'] ?? 'hit') === 'blocked';
        return [
            'ok'         => !$blocked && ($stats['push_fail'] ?? 0) === 0,
            'push_count' => $stats['push_count'],
            'push_fail'  => $stats['push_fail'],
            'blocked'    => $blocked,
            'reason'     => $item['reason'] ?? '',
            'channels'   => $item['channels'] ?? [],
        ];
    }

    /**
     * 单条数据异常记录：写入 stats 并落日志，保证定时任务继续处理后续数据
     */
    protected static function recordError(array &$stats, string $scope, \Throwable $e): void
    {
        $msg = $scope . ': ' . $e->getMessage();
        $stats['errors'][] = $msg;
        try {
            \think\facade\Log::warning('[cert-expire] ' . $msg . ' @ ' . $e->getFile() . ':' . $e->getLine());
        } catch (\Throwable $ignore) {
        }
    }

    /** 脏数据跳过记录（不视为异常，但会在体检结果里提示） */
    protected static function recordSkip(array &$stats, string $scope, string $reason): void
    {
        $stats['skipped'][] = $scope . ': ' . $reason;
    }

    protected static function nodeToDays($node): ?int
    {
        if (isset(self::$nodeMap[$node])) {
            return self::$nodeMap[$node];
        }
        if (preg_match('/^(\d+)day$/', $node, $m)) {
            return (int)$m[1];
        }
        if (preg_match('/^(\d+)month$/', $node, $m)) {
            return (int)$m[1] * 30;
        }
        if (preg_match('/^(\d+)year$/', $node, $m)) {
            return (int)$m[1] * 365;
        }
        return null;
    }

    /**
     * 取生效的提醒配置：企业规则优先，其次全局默认规则
     */
    protected static function alertConfigOf(int $companyId)
    {
        // v2.38：请求内 memo（每家企业规则在一次任务里只查一次；
        // 未单独配置时会回落到全局规则，同样缓存，避免逐证书重复查询）
        $k = 'alertcfg:' . $companyId;
        if (array_key_exists($k, self::$cfgMemo)) {
            return self::$cfgMemo[$k];
        }
        $cfg = null;
        if ($companyId) {
            $cfg = CompanyAlertConfig::where('company_id', $companyId)->find();
        }
        if (!$cfg && ensure_column(
            'company_alert_config', 'is_global',
            "tinyint NOT NULL DEFAULT 0 COMMENT '1=全局默认规则（适用于未单独配置的企业）' AFTER `company_id`"
        )) {
            $cfg = CompanyAlertConfig::where('is_global', 1)->find();
        }
        return self::$cfgMemo[$k] = $cfg;
    }

    protected static function resolveNodes($cert, int $companyId = 0): array
    {
        $cfg   = self::alertConfigOf($companyId);
        // 配置存在但节点为空时同样回退默认节点，避免「保存了空规则 = 永不提醒」
        $nodes = ($cfg && !empty($cfg->alert_nodes)) ? $cfg->alert_nodes : ['1month', '7day', '3day'];
        if (!empty($cert->alert_rules)) {
            $nodes = $cert->alert_rules;
        }
        if (!empty($cert->custom_alert_days)) {
            foreach (explode(',', $cert->custom_alert_days) as $d) {
                $d = trim($d);
                if (is_numeric($d)) {
                    $nodes[] = (int)$d . 'day';
                }
            }
        }
        return array_values(array_unique($nodes));
    }

    /**
     * 触发一次证书到期提醒：站内日志 + 多渠道推送
     * $item 为引用，用于体检模式收集「为什么没推出去」
     */
    protected static function fire($cert, $node, $remain, $today, &$stats, bool $dryRun = false, ?array &$item = null): void
    {
        $staff     = $cert->staff;
        $companyId = SecondmentService::companyIdOf($cert->id);
        $company   = $companyId ? \app\model\ClientCompany::find($companyId) : null;
        $companyName = $company->company_name ?? '未借调';

        if (!$dryRun) {
            AlertLog::create([
                'company_id'    => $companyId,
                'cert_id'       => $cert->id,
                'staff_id'      => $cert->staff_id,
                'alert_day'     => $remain,
                'alert_node'    => $node,
                'alert_date'    => $today,
                'alert_content' => "{$companyName} 的 {$staff->staff_name} 持有 {$cert->cert_name} 将于 {$cert->expire_date} 到期，剩余 {$remain} 天。",
                'alert_type'    => 'cert',
                'secondment_id' => 0,
            ]);
        }
        if ($item !== null) {
            $item['company']     = $companyName;
            $item['company_id']  = $companyId;
            $item['staff_name']  = $staff->staff_name ?? '';
        }
        $contentFn = function (string $type) use ($cert, $staff, $company, $remain) {
            return self::buildContent($type, $cert, $staff, $company, $remain);
        };
        self::deliver($companyId, $contentFn, $staff, $cert->id, 0, $node, $today, $dryRun, $stats, $item);
    }

    /**
     * 统一的渠道推送（证书 / 借调共用）
     * $contentFn: callable(string $channelType): string | 或已生成的 string（如借调到期固定文案）
     * 流程：取企业提醒方式 → 查已启用渠道 → 解析接收人 → 逐渠道逐接收人推送并写 push_msg_log
     */
    protected static function deliver(int $companyId, $contentFn, $staff, int $certId, int $secondmentId, string $node, string $today, bool $dryRun, array &$stats, ?array &$item = null, string $bizType = 'cert'): void
    {
        // v2.93：业务类型决定第二业务 ID 的含义并写入 push_msg_log：
        //  cert          → 普通证照到期（$secondmentId 应为 0）
        //  secondment    → $secondmentId 是 cert_secondment.id（借调临期/到期/续借）
        //  company_cert  → $secondmentId 是 company_qualification.id（企业安许/资质），cert_id 恒为 0
        // 不改变渠道/接收人解析逻辑。
        if ($item !== null) {
            $item['biz_type'] = $bizType;
        }
        self::ensurePushLogCols();
        if (!$companyId) {
            if ($item !== null) {
                $item['result'] = 'blocked';
                $item['reason'] = '当前无归属企业，只写站内提醒，不做渠道推送';
            }
            return;
        }
        $cfg  = self::alertConfigOf($companyId);
        $ways = $cfg ? $cfg->alert_ways : [];
        if ($item !== null) {
            $item['ways']      = $ways;
            $item['cfg_scope'] = $cfg ? ((int)($cfg->is_global ?? 0) ? '全局默认' : '企业规则') : '无';
        }
        if (empty($ways)) {
            if ($item !== null) {
                $item['result'] = 'blocked';
                $item['reason'] = '未配置提醒方式（系统管理 → 提醒规则设置：先配「全局默认」，或给该企业单独配置）';
            }
            return;
        }

        // 「企微」方式同时覆盖「企微群机器人(wecom)」与「企微应用消息(wecom_app)」两类渠道：
        // 用户在提醒方式里勾选「企微」时，只要在推送渠道里启用了任意一种企微渠道都能正常推送，
        // 避免「提醒方式=企微、启用的是企微应用消息」时匹配不上而报「没有对应的已启用推送渠道」。
        $types = [];
        foreach ($ways as $w) {
            $types[] = $w;
            if ($w === 'wecom') {
                $types[] = 'wecom_app';
            }
        }
        $types    = array_values(array_unique($types));
        $channels = PushChannel::whereIn('channel_type', $types)
            ->where('is_enable', 1)->select();
        if ($channels->isEmpty()) {
            $reason = '企业已配置提醒方式[' . implode(',', $ways) . ']，但没有对应的「已启用」推送渠道';
            if ($item !== null) {
                $item['result'] = 'blocked';
                $item['reason'] = $reason;
            }
            self::logBlock($companyId, $certId, is_string($contentFn) ? $contentFn : '', $reason, $dryRun);
            return;
        }

        foreach ($channels as $channel) {
            $msgContent = is_callable($contentFn) ? $contentFn($channel->channel_type) : $contentFn;
            $receivers  = self::resolveReceivers($companyId, $channel->channel_type, $staff, self::sceneOf($bizType, $node));
            if (empty($receivers)) {
                $reason = '渠道「' . $channel->channel_name . '」无可用接收人（请在「接收人管理」为该企业配置手机号/邮箱/企微，且保持启用）';
                if ($item !== null) {
                    $item['result']  = 'blocked';
                    $item['reason']  = $reason;
                    $item['channels'][] = ['name' => $channel->channel_name, 'type' => $channel->channel_type, 'status' => 2, 'msg' => $reason];
                }
                self::logBlock($companyId, $certId, $msgContent, $reason, $dryRun, $channel->id, $channel->channel_type);
                continue;
            }
            foreach ($receivers as $rcv) {
                if ($dryRun) {
                    if ($item !== null) {
                        $item['channels'][] = [
                            'name'   => $channel->channel_name,
                            'type'   => $channel->channel_type,
                            'status' => 0,
                            'msg'    => '体检模式：未真实发送（接收人 ' . self::rcvText($rcv) . '）',
                        ];
                    }
                    continue;
                }
                $log = PushMsgLog::create([
                    'company_id'      => $companyId,
                    'cert_id'         => $certId,
                    'biz_type'        => $bizType,
                    'biz_id'          => $secondmentId,
                    'channel_id'      => $channel->id,
                    'channel_type'    => $channel->channel_type,
                    'receiver_phone'  => $rcv['phone'] ?? '',
                    'receiver_email'  => $rcv['email'] ?? '',
                    'receiver_wecom'  => $rcv['wecom'] ?? '',
                    'msg_content'     => $msgContent,
                    'send_status'     => 0,
                    'is_test'         => 0,
                ]);
                $res = PushService::send($channel, $rcv, $msgContent);
                $log->send_status = $res['status'];
                $log->err_msg     = $res['status'] == 1 ? ($res['simulated'] ? '模拟发送：' . $res['msg'] : '') : ($res['msg'] ?? '');
                $log->cost_ms     = $res['cost_ms'] ?? 0;
                $log->save();
                $stats['push_count']++;
                if ($res['status'] != 1) {
                    $stats['push_fail']++;
                } elseif (!empty($res['simulated'])) {
                    $stats['push_simulated']++;
                }
                if ($item !== null) {
                    $item['channels'][] = [
                        'name'     => $channel->channel_name,
                        'type'     => $channel->channel_type,
                        'status'   => $res['status'],
                        'simulated'=> $res['simulated'] ?? false,
                        'msg'      => $res['msg'] ?? '',
                    ];
                }
            }
        }
    }

    /** 记录一条「被阻断」的推送失败日志，方便在推送日志里看到原因（体检模式不写） */
    protected static function logBlock(int $companyId, int $certId, string $content, string $reason, bool $dryRun, int $channelId = 0, string $channelType = ''): void
    {
        if ($dryRun) {
            return;
        }
        PushMsgLog::create([
            'company_id'    => $companyId,
            'cert_id'       => $certId,
            'channel_id'    => $channelId,
            'channel_type'  => $channelType,
            'msg_content'   => $content,
            'send_status'   => 2,
            'err_msg'       => $reason,
            'is_test'       => 0,
        ]);
    }

    protected static function rcvText(array $rcv): string
    {
        return $rcv['phone'] ?? $rcv['email'] ?? $rcv['wecom'] ?? $rcv['openid'] ?? '';
    }

    /**
     * 拆分「企微 userid / openid」多选值
     * 接收人管理改为组织架构多选后统一存为竖线分隔，这里兼容竖线/逗号/分号/空格等历史写法
     * @return array<string> 去重后的非空账号列表
     */
    protected static function splitAccounts($raw): array
    {
        $raw = (string)$raw;
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/[|,;，；\s]+/u', $raw) ?: [];
        $out   = [];
        foreach ($parts as $p) {
            $p = trim((string)$p);
            if ($p !== '' && !in_array($p, $out, true)) {
                $out[] = $p;
            }
        }
        return $out;
    }

    protected static function buildContent($type, $cert, $staff, $company, $remain, $scene = 'cert_expire'): string
    {
        $tpl = self::tplOf($scene, $type); // v2.38：请求内 memo，避免逐条重复查模板
        // H5 端详情链接（消息模板里用 {{detail_url}} 引用）
        // 注意：定时任务是 CLI 运行，若未配置「移动端H5地址」可能拿不到绝对地址，
        // 此时宁可不给链接，也不要在短信/企微里塞一个点不开的相对路径。
        $detailUrl = h5_detail_url((int)$cert->id);
        // 兜底：拿不到绝对地址（CLI 且未配置「移动端H5地址」）时不给链接，
        // 避免在短信/企微里塞一个点不开的相对路径
        if ($detailUrl !== '' && !preg_match('#^https?://#i', $detailUrl)) {
            $detailUrl = '';
        }
        $data = [
            'company_name' => $company->company_name ?? '',
            'staff_name'   => $staff->staff_name ?? '',
            'cert_name'    => $cert->cert_name,
            'cert_no'      => $cert->cert_no,
            'expire_date'  => $cert->expire_date,
            'remain_days'  => $remain,
            'detail_url'   => $detailUrl,
        ];
        if ($tpl) {
            $content = MsgTemplate::render($tpl->content, $data);
            // 模板里引用了 {{detail_url}} 但地址为空 → 去掉可能残留的「查看详情：」空行
            if ($detailUrl === '' && str_contains((string)$tpl->content, '{{detail_url}}')) {
                $content = strip_empty_detail_link($content);
            }
            return $content;
        }
        $text = "【证书预警】" . ($company->company_name ?? '未借调') . "：{$staff->staff_name}的{$cert->cert_name}剩余{$remain}天到期。";
        if ($detailUrl !== '') {
            $text .= "\n查看详情：" . $detailUrl;
        }
        return $text;
    }

    /**
     * 推送场景：用于「接收人适用功能」过滤（v2.99.40）
     */
    public static function sceneOf(string $bizType, string $node): string
    {
        if ($bizType === 'company_cert') {
            return 'company_cert_expire';
        }
        if ($bizType === 'secondment') {
            if (mb_strpos($node, 'renew') !== false) {
                return 'secondment_renew';
            }
            if (mb_strpos($node, 'expire') !== false) {
                return 'secondment_expire';
            }
            return 'secondment_approach';
        }
        return 'cert_expire';
    }

    /**
     * 解析某次推送的接收人
     * - 企微（群机器人）发到群里，不需要接收人 -> 固定一条
     * - 其余渠道：企业接收人 + 全局接收人（is_global=1 接收所有证书通知），按联系方式去重
     * - v2.99.40：接收人可限定「适用功能」，未配置场景的接收人按全部功能处理
     */
    protected static function resolveReceivers($companyId, $type, $staff, string $scene = 'cert_expire'): array
    {
        // 企微群机器人：消息直接进群，无需指定接收人
        if ($type === 'wecom') {
            return [['wecom' => '']];
        }

        // 老库可能缺 is_global 列，先自愈；补不上就退化为只按企业匹配
        $hasGlobal = ensure_column(
            'company_receiver', 'is_global',
            "tinyint NOT NULL DEFAULT 0 COMMENT '1=全局接收人（接收所有证书通知）' AFTER `company_id`"
        );
        // v2.99.40：接收人「适用功能」列自愈
        PushSceneService::ensureSchema();

        // 企微应用消息：按「企微 userid」精准推送
        if ($type === 'wecom_app') {
            $list = [];
            $seen = [];
            $push = function (string $userid) use (&$list, &$seen) {
                $userid = trim($userid);
                if ($userid === '' || isset($seen[$userid])) {
                    return;
                }
                $seen[$userid]    = true;
                $list[]           = ['wecom' => $userid];
            };
            // 1) 企业接收人 / 全局接收人中填写了企微账号(userid)者
            //    接收人管理改为组织架构多选后，receiver_wecom 可能是竖线分隔的多个 userid，需拆分
            $rcvs = CompanyReceiver::where('is_enable', 1)
                ->where(function ($q) use ($companyId, $hasGlobal) {
                    if ($hasGlobal) {
                        $q->where('company_id', $companyId)->whereOr('is_global', 1);
                    } else {
                        $q->where('company_id', $companyId);
                    }
                })
                ->select()
                ->filter(fn($r) => PushSceneService::receiverMatchesScene($r, $scene));
            foreach ($rcvs as $r) {
                foreach (self::splitAccounts($r->receiver_wecom) as $uid) {
                    $push($uid);
                }
            }
            // 2) 绑定到该企业的系统账号（user_company_rel）且已绑定企微者
            $userIds = \think\facade\Db::name('user_company_rel')->where('company_id', $companyId)->column('user_id');
            if ($userIds) {
                $users = SysUser::whereIn('id', $userIds)
                    ->where('status', 1)
                    ->whereNotNull('wecom_userid')
                    ->where('wecom_userid', '<>', '')
                    ->select();
                foreach ($users as $u) {
                    $push($u->wecom_userid);
                }
            }
            return $list;
        }

        $list = [];
        $seen = [];
        $push = function ($key, array $item) use (&$list, &$seen) {
            if ($key === '' || isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $list[]     = $item;
        };

        $keyOf = function ($r) use ($type): string {
            if ($type == 'sms') {
                return trim((string)$r->receiver_phone);
            }
            if ($type == 'email') {
                return trim((string)$r->receiver_email);
            }
            // 公众号复用企微字段存放 openid（组织架构多选后可能是竖线分隔的多个）
            return trim((string)$r->receiver_wecom);
        };

        $receivers = CompanyReceiver::where('is_enable', 1)
            ->where(function ($q) use ($companyId, $hasGlobal) {
                if ($hasGlobal) {
                    $q->where('company_id', $companyId)->whereOr('is_global', 1);
                } else {
                    $q->where('company_id', $companyId);
                }
            })
            ->select()
            ->filter(fn($r) => PushSceneService::receiverMatchesScene($r, $scene));
        foreach ($receivers as $r) {
            // mp 渠道的 openid 与 wecom_app 的 userid 一样支持多选（竖线分隔），逐个拆分
            $keys = ($type == 'mp') ? self::splitAccounts($r->receiver_wecom) : [$keyOf($r)];
            foreach ($keys as $key) {
                $key = trim((string)$key);
                if ($key === '') {
                    continue;
                }
                if ($type == 'sms') {
                    $push($key, ['phone' => $key]);
                } elseif ($type == 'email') {
                    $push($key, ['email' => $key]);
                } elseif ($type == 'mp') {
                    $push($key, ['openid' => $key]);
                }
            }
        }

        // 持证人本人
        if ($staff) {
            if ($type == 'sms' && $staff->phone) {
                $push(trim((string)$staff->phone), ['phone' => trim((string)$staff->phone)]);
            }
            if ($type == 'email' && $staff->email) {
                $push(trim((string)$staff->email), ['email' => trim((string)$staff->email)]);
            }
        }
        return $list;
    }

    /**
     * 推送链路体检：不动数据库，回答「为什么推送没有触发」
     */
    public static function diagnose(): array
    {
        $today = date('Y-m-d');
        $backendDir = dirname(__DIR__, 2);
        $phpCli     = self::phpCliPath();
        $cronLog    = $backendDir . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'cron.log';
        $out   = [
            'today'      => $today,
            'channels'   => ['total' => 0, 'enabled' => 0, 'list' => []],
            'receivers'  => ['total' => 0, 'enabled' => 0, 'by_company' => []],
            'configs'    => [],
            'certs'      => ['total' => 0, 'alert_open' => 0, 'scan' => 0],
            'hits'       => [],
            'blockers'   => [],
            // 宝塔计划任务的 PATH 通常不含 PHP 目录，必须给出绝对路径；同时带 cd/日志/补发，复制即可用
            'cron_hint'  => 'cd ' . $backendDir . ' && ' . $phpCli . ' think cert:checkexpire --catch-up >> ' . $cronLog . ' 2>&1',
        ];

        // 1. 渠道
        $channels = PushChannel::order('sort')->select();
        foreach ($channels as $ch) {
            $cfg    = is_array($ch->config_json) ? $ch->config_json : [];
            $check  = PushService::checkConfig($ch->channel_type, $cfg);
            $out['channels']['total']++;
            if ($ch->is_enable == 1) {
                $out['channels']['enabled']++;
            }
            $out['channels']['list'][] = [
                'id'         => $ch->id,
                'name'       => $ch->channel_name,
                'type'       => $ch->channel_type,
                'type_text'  => PushService::typeText($ch->channel_type),
                'enabled'    => (int)$ch->is_enable,
                'configured' => $check['configured'],
                'missing'    => $check['missing'],
            ];
        }
        if ($out['channels']['total'] === 0) {
            $out['blockers'][] = '尚未配置任何推送渠道：到期提醒不会外发（推送中心 → 推送渠道）';
        } elseif ($out['channels']['enabled'] === 0) {
            $out['blockers'][] = '所有推送渠道均为「停用」状态，请至少启用一个渠道';
        }
        $enabledUnconfigured = array_values(array_filter($out['channels']['list'], function ($c) {
            return $c['enabled'] === 1 && empty($c['configured']);
        }));
        if (!empty($enabledUnconfigured)) {
            $out['blockers'][] = '已启用但配置不完整的渠道：'
                . implode('、', array_map(fn($c) => $c['name'] . '（缺' . implode('/', $c['missing']) . '）', $enabledUnconfigured))
                . '；这些渠道会显示「模拟发送」，不会真正送达';
        }

        // 2. 接收人
        $rcv = CompanyReceiver::select();
        foreach ($rcv as $r) {
            $out['receivers']['total']++;
            if ($r->is_enable == 1) {
                $out['receivers']['enabled']++;
            }
            $cid = (int)$r->company_id;
            $out['receivers']['by_company'][$cid] = ($out['receivers']['by_company'][$cid] ?? 0) + 1;
        }
        if ($out['receivers']['enabled'] === 0) {
            $out['blockers'][] = '没有启用状态的接收人：即使渠道正常也没有发送目标（推送中心 → 接收人管理）';
        }

        // 3. 企业提醒配置
        $cfgs = CompanyAlertConfig::select();
        foreach ($cfgs as $c) {
            $company = \app\model\ClientCompany::find($c->company_id);
            $out['configs'][] = [
                'company_id'   => $c->company_id,
                'company_name' => $company->company_name ?? ('企业#' . $c->company_id),
                'nodes'        => $c->alert_nodes ?: [],
                'ways'         => $c->alert_ways ?: [],
            ];
        }
        if (empty($out['configs'])) {
            $out['blockers'][] = '没有任何企业配置提醒规则（系统管理 → 提醒规则设置），默认仅按 30/7/3 天节点提醒且不推送渠道';
        }

        // 4. 证书扫描：把「差多少天才触发」讲清楚
        $certs = Certificate::where('cert_status', 1)->select();
        $out['certs']['total'] = count($certs);
        // 真实执行时会自动置为「已过期」的数量（体检模式不写库，这里提前暴露）
        $out['certs']['would_expire'] = Certificate::where('cert_status', 1)
            ->whereNotNull('expire_date')->where('expire_date', '<', $today)->count();
        $scan = 0;
        foreach ($certs as $cert) {
            if ((int)$cert->enable_alert !== 1) {
                continue;
            }
            $out['certs']['alert_open']++;
            if (empty($cert->expire_date) || $cert->expire_date < $today) {
                continue;
            }
            $scan++;
            $remain      = remain_days($cert->expire_date);
            $companyId   = SecondmentService::companyIdOf($cert->id);
            $nodes       = self::resolveNodes($cert, $companyId);
            $nodeDetail  = [];
            $nearest     = null;
            foreach ($nodes as $node) {
                $days = self::nodeToDays($node);
                if ($days === null) {
                    continue;
                }
                $diff = $remain - $days; // 0=今天触发，>0 未到，<0 已过
                $nodeDetail[] = ['node' => $node, 'days' => $days, 'diff' => $diff];
                if ($nearest === null || abs($diff) < abs($nearest['diff'])) {
                    $nearest = ['node' => $node, 'days' => $days, 'diff' => $diff];
                }
            }
            $out['hits'][] = [
                'cert_id'     => $cert->id,
                'cert_name'   => $cert->cert_name,
                'expire_date' => $cert->expire_date,
                'remain'      => $remain,
                'company_id'  => $companyId,
                'company'     => $companyId ? (\app\model\ClientCompany::find($companyId)?->company_name ?? '') : '未借调',
                'nodes'       => $nodeDetail,
                'nearest'     => $nearest,
            ];
        }
        $out['certs']['scan'] = $scan;
        if ($scan === 0) {
            $out['blockers'][] = '没有「已开启提醒且未到期」的证书，检测无对象可扫';
        }

        // 4b. 借调临期扫描：把「差多少天触发借调到期提醒」讲清楚
        $secs = \app\model\CertSecondment::with(['certificate', 'company', 'staff'])
            ->where('status', 1)
            ->whereNotNull('end_date')
            ->where('end_date', '>=', $today)
            ->select()->toArray();
        $secHits = [];
        foreach ($secs as $row) {
            if (empty($row['end_date']) || $row['end_date'] < $today) {
                continue;
            }
            $remain     = SecondmentService::remainDays($row['end_date']);
            $companyId  = (int)$row['company_id'];
            $nodes      = self::secondmentNodes($companyId);
            $nodeDetail = [];
            $nearest    = null;
            foreach ($nodes as $node) {
                $days = self::nodeToDays($node);
                if ($days === null) {
                    continue;
                }
                $diff = $remain - $days;
                $nodeDetail[] = ['node' => 'secondment_' . $node, 'days' => $days, 'diff' => $diff];
                if ($nearest === null || abs($diff) < abs($nearest['diff'])) {
                    $nearest = ['node' => 'secondment_' . $node, 'days' => $days, 'diff' => $diff];
                }
            }
            $secHits[] = [
                'secondment_id' => $row['id'],
                'cert_name'     => $row['certificate']['cert_name'] ?? '',
                'end_date'      => $row['end_date'],
                'remain'        => $remain,
                'company'       => $companyId ? ($row['company']['company_name'] ?? '') : '未借调',
                'nodes'         => $nodeDetail,
                'nearest'       => $nearest,
            ];
        }
        $out['secondment_scan']  = count($secs);
        $out['secondment_hits']  = $secHits;
        if (count($secs) === 0) {
            $out['blockers'][] = '没有「借调中且未到期」的借调记录，借调到期提醒无对象可扫';
        }

        // 4c. 企业证书（安许 / 资质）临期扫描
        $companyCertHits = [];
        $companyCertScan = 0;
        try {
            \app\service\CompanyQualificationService::ensureSchema();
            $ccRows = Db::name('company_qualification')
                ->where('status', 1)
                ->whereNotNull('expire_date')
                ->where('expire_date', '>=', $today)
                ->select()->toArray();
            $companyCertScan = count($ccRows);
            $ccCompanyCache = [];
            foreach ($ccRows as $row) {
                $remain    = remain_days((string)$row['expire_date']);
                $companyId = (int)$row['company_id'];
                if (!array_key_exists($companyId, $ccCompanyCache)) {
                    $ccCompanyCache[$companyId] = (string)(\app\model\ClientCompany::where('id', $companyId)->value('company_name') ?? '');
                }
                $nodes      = self::secondmentNodes($companyId);
                $nodeDetail = [];
                $nearest    = null;
                foreach ($nodes as $node) {
                    $days = self::nodeToDays($node);
                    if ($days === null) {
                        continue;
                    }
                    $diff = $remain - $days;
                    $nodeDetail[] = ['node' => 'companycert_' . $node, 'days' => $days, 'diff' => $diff];
                    if ($nearest === null || abs($diff) < abs($nearest['diff'])) {
                        $nearest = ['node' => 'companycert_' . $node, 'days' => $days, 'diff' => $diff];
                    }
                }
                $companyCertHits[] = [
                    'company_qualification_id' => (int)$row['id'],
                    'q_type'      => (string)($row['q_type'] ?? ''),
                    'cert_name'   => (string)($row['cert_name'] ?? ''),
                    'cert_no'     => (string)($row['cert_no'] ?? ''),
                    'expire_date' => (string)($row['expire_date'] ?? ''),
                    'remain'      => $remain,
                    'company_id'  => $companyId,
                    'company'     => $ccCompanyCache[$companyId],
                    'nodes'       => $nodeDetail,
                    'nearest'     => $nearest,
                ];
            }
        } catch (\Throwable $e) {
            // 表尚未就绪时忽略体检该段
        }
        $out['company_cert_scan'] = $companyCertScan;
        $out['company_cert_hits'] = $companyCertHits;

        // 5. 今天实际会命中什么（干跑一次；开启 3 天内漏跑补发，便于发现定时任务停摆）
        $dry = self::check(true, true);
        $out['dry_run'] = [
            'alert_count' => $dry['alert_count'],
            'hit_count'   => $dry['hit_count'] ?? 0,
            'block_count' => $dry['block_count'] ?? 0,
            'items'       => $dry['items'] ?? [],
            'errors'      => $dry['errors'] ?? [],
            'skipped'     => $dry['skipped'] ?? [],
            'secondment_alert'   => $dry['secondment_alert'] ?? 0,
            'secondment_expired' => $dry['secondment_expired'] ?? 0,
            'company_cert_alert' => $dry['company_cert_alert'] ?? 0,
            'push_simulated'     => $dry['push_simulated'] ?? 0,
        ];
        if (!empty($dry['errors'])) {
            $out['blockers'][] = '检测过程中有 ' . count($dry['errors']) . ' 条数据异常，已跳过（详见 dry_run.errors）';
        }
        if (!empty($dry['skipped'])) {
            $out['blockers'][] = '有 ' . count($dry['skipped']) . ' 条借调数据日期不合法（结束日期早于开始日期），已跳过（详见 dry_run.skipped）';
        }
        if (($dry['alert_count'] ?? 0) === 0) {
            $out['blockers'][] = '今天没有任何证书 / 借调 / 企业证书命中提醒节点（含 3 天内漏跑补发；也可能定时任务未执行）';
        }

        // 6. 上一次真实执行记录（由 check() 写入 runtime/cert_last_run.json）
        $lastFile = self::lastRunFile();
        $last     = is_file($lastFile) ? json_decode((string)@file_get_contents($lastFile), true) : null;
        $out['last_run'] = is_array($last) ? $last : null;
        if (is_array($last) && !empty($last['finished_at'])) {
            $hours = (time() - (int)strtotime($last['finished_at'])) / 3600;
            if ($hours > 36) {
                $out['blockers'][] = '定时任务已超过 ' . floor($hours) . ' 小时未执行（上次 ' . $last['finished_at'] . '），请检查 crontab / scheduler';
            }
        } else {
            $out['blockers'][] = '尚未记录到定时任务执行（部署后请先手动执行一次检测，或确认 crontab 已配置）';
        }
        return $out;
    }
}
