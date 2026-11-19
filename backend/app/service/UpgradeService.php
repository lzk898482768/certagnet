<?php
namespace app\service;

use think\facade\Config;

/**
 * 在线升级服务（v2.99.73）
 *
 * 能力：
 *  - 读取 Gitee 仓库最新版本标签，与当前版本比较；
 *  - 升级前自动备份：程序文件（zip，排除 runtime） + 数据库（mysqldump）；
 *  - 升级包来源：Gitee 在线下载 或 管理员本地上传 zip；
 *  - 应用升级：解压后替换 backend 目录文件（跳过 runtime/.env），并清理运行时缓存；
 *  - 备份恢复：程序文件 zip 回滚 / 数据库 sql 导入。
 *
 * 约定：
 *  - 数据库结构变更依赖系统运行时自愈（MenuCatalog/RoleCatalog/SchemaService），升级包不携带 upgrade SQL；
 *  - 所有备份与升级产物存放于 runtime/backup、runtime/upgrade，均不随升级包替换。
 */
class UpgradeService
{
    /** 当前系统版本（与 CHANGELOG 对齐） */
    public const CURRENT_VERSION = 'v2.99.73';

    /** 最近一次 zipDir 的失败原因（用于升级提示） */
    private static $zipError = '';

    /** Gitee Release 列表（tag => release），单次请求内缓存，避免跨版本汇总时 N 次请求 */
    private static $releaseIndex = null;

    /** 读取最近一次打包失败原因 */
    public static function lastZipError(): string
    {
        return self::$zipError;
    }

    /** Gitee 仓库默认配置（可通过 .env [UPGRADE] GITEE_OWNER / GITEE_REPO 覆盖） */
    private const GITEE_OWNER = 'kxunyun';
    private const GITEE_REPO = 'cert-expiry-manager';

    public static function owner(): string
    {
        return trim((string)env('upgrade.gitee_owner', self::GITEE_OWNER)) ?: self::GITEE_OWNER;
    }

    public static function repo(): string
    {
        return trim((string)env('upgrade.gitee_repo', self::GITEE_REPO)) ?: self::GITEE_REPO;
    }

    /**
     * Gitee 私人令牌（私有仓库必需）。
     * 在 backend/.env 中配置，该文件不随升级包/仓库分发：
     *   [UPGRADE]
     *   GITEE_TOKEN = xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
     */
    private static function giteeToken(): string
    {
        return trim((string)env('upgrade.gitee_token', ''));
    }

    public static function hasGiteeToken(): bool
    {
        return self::giteeToken() !== '';
    }

    /** backend 应用根目录 */
    public static function baseDir(): string
    {
        return root_path();
    }

    public static function runtimeDir(): string
    {
        return app()->getRuntimePath();
    }

    public static function backupDir(): string
    {
        return self::runtimeDir() . 'backup';
    }

    public static function upgradeDir(): string
    {
        return self::runtimeDir() . 'upgrade';
    }

    public static function historyFile(): string
    {
        return self::upgradeDir() . '/history.json';
    }

    /** 确保 runtime/backup、runtime/upgrade 目录存在 */
    public static function ensureDirs(): void
    {
        foreach ([self::backupDir(), self::upgradeDir()] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }
    }

    /**
     * 升级进度文件。
     * 升级请求耗时较长（下载 + 备份 + 替换），前端通过轮询 progress 接口
     * 读取该文件，展示当前阶段与百分比，避免页面看起来「卡住」。
     */
    public static function progressFile(): string
    {
        return self::upgradeDir() . '/progress.json';
    }

    /** 写入当前升级进度 */
    public static function setProgress(string $stage, int $percent, string $message, bool $running = true, bool $ok = true): void
    {
        self::ensureDirs();
        self::writeJson(self::progressFile(), [
            'stage'   => $stage,
            'percent' => max(0, min(100, $percent)),
            'message' => $message,
            'running' => $running,
            'ok'      => $ok,
            'time'    => date('Y-m-d H:i:s'),
            'version' => self::CURRENT_VERSION,
        ]);
    }

    /** 读取当前升级进度（无记录时视为空闲） */
    public static function progress(): array
    {
        $data = self::readJson(self::progressFile(), []);
        if (!is_array($data) || !$data) {
            return [
                'stage'   => 'idle',
                'percent' => 0,
                'message' => '',
                'running' => false,
                'ok'      => true,
                'time'    => '',
            ];
        }
        return $data;
    }

    /** 聚合信息：当前版本 / 最近一次检查结果 / 备份列表 / 升级记录 / 能力探测 */
    public static function info(): array
    {
        self::ensureDirs();
        $runner = self::commandRunner();
        return [
            'current_version' => self::CURRENT_VERSION,
            'gitee_owner'     => self::owner(),
            'gitee_repo'      => self::repo(),
            // 私有仓库必须配置访问令牌；仅暴露布尔值，不回传令牌本身
            'gitee_token'     => self::hasGiteeToken(),
            'last_check'      => self::readJson(self::upgradeDir() . '/last_check.json', []),
            'backups'         => self::listBackups(),
            'history'         => self::history(),
            'capabilities'    => [
                'zip'       => class_exists('ZipArchive'),
                'curl'      => function_exists('curl_init')
                    || filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN),
                // 命令执行：exec / proc_open / shell_exec 任一可用即可
                'exec'      => $runner !== '',
                'runner'    => $runner,
                'mysqldump' => $runner !== '' && self::findMysqldump() !== '',
                'mysql'     => $runner !== '' && self::findMysql() !== '',
            ],
        ];
    }

    // ---------------- 版本检测 ----------------

    /** 版本号比较：v2.99.1 vs v2.100.0 */
    public static function compareVersion(string $a, string $b): int
    {
        $na = preg_replace('/^v/i', '', trim($a));
        $nb = preg_replace('/^v/i', '', trim($b));
        return version_compare($na, $nb);
    }

    /**
     * 查询 Gitee 最新可用版本。
     *
     * v2.99.6：原实现只比对 tag，若发版只推代码未打 tag（或 tag 漏打），
     * 系统会一直提示「当前已是最新版本」，运维无从发现。现在按三步判断：
     *   1) 拉取全部 tag，取版本号最高者，并记下它的 commit sha / 时间；
     *   2) tag 未超前当前版本时，再取 main 分支最新提交；
     *   3) main 的提交既不是 tag 指向的那一个、时间也晚于 tag 时，判定
     *      「代码已更新但未发版」（branch_ahead），只提示不打升级标记，
     *      因为此时没有可下载的 tag。
     *
     * 注意：upgradeable 语义保持严格 —— 仅表示「存在比当前版本新的 tag」，
     * 否则前端会出现「可升级但点不了」的矛盾状态。
     */
    public static function checkGitee(): array
    {
        $token = self::giteeToken();
        $tagUrl = 'https://gitee.com/api/v5/repos/' . rawurlencode(self::owner())
            . '/' . rawurlencode(self::repo())
            . '/tags?per_page=100&sort=updated&direction=desc';
        if ($token !== '') {
            // 私有仓库必须携带 access_token，否则 Gitee 直接返回 404
            $tagUrl .= '&access_token=' . rawurlencode($token);
        }
        $http = self::httpGet($tagUrl, 12);
        if (!$http['ok']) {
            return ['ok' => false, 'msg' => '无法访问 Gitee：' . $http['msg']];
        }
        $code = (int)($http['code'] ?? 0);
        if ($code !== 200) {
            return ['ok' => false, 'msg' => self::giteeHttpError($code, $token !== ''), 'code' => $code];
        }
        $tags = json_decode($http['body'], true);
        if (!is_array($tags)) {
            return ['ok' => false, 'msg' => 'Gitee 返回数据格式异常'];
        }
        $latest = '';
        $latestSha = '';
        $latestTime = '';
        $validTags = [];
        foreach ($tags as $t) {
            $name = (string)($t['name'] ?? '');
            if ($name === '' || !preg_match('/^v?\d+\.\d+(\.\d+)?$/', $name)) {
                continue;
            }
            $validTags[] = $name;
            if ($latest === '' || self::compareVersion($name, $latest) > 0) {
                $latest = $name;
                $latestSha = (string)($t['commit']['sha'] ?? '');
                $latestTime = (string)($t['commit']['date'] ?? ($t['tagger']['date'] ?? ''));
            }
        }
        if ($latest === '') {
            return ['ok' => false, 'msg' => '仓库中未找到版本标签（形如 v2.99.0）'];
        }
        // 当前版本之前的那一个 tag，用于取「本版本改了什么」的增量提交
        $prevTag = '';
        foreach ($validTags as $name) {
            if (self::compareVersion($name, self::CURRENT_VERSION) < 0
                && ($prevTag === '' || self::compareVersion($name, $prevTag) > 0)) {
                $prevTag = $name;
            }
        }

        $result = [
            'ok'          => true,
            'latest'      => $latest,
            'upgradeable' => self::compareVersion($latest, self::CURRENT_VERSION) > 0,
        ];

        // 有新版本时顺带取该版本的更新说明，让升级页在「更新前」就能看到改了什么。
        // v2.99.58：跨版本升级时汇总「当前版本 → 最新版本」之间每个版本的更新内容，
        // 不再只显示最后一个 tag 的 Release 正文（旧实现会省略中间版本的更新）。
        if ($result['upgradeable']) {
            $notes = self::releaseNotesRange($latest, self::CURRENT_VERSION, $validTags);
            if (!empty($notes['ok'])) {
                $result['notes']        = $notes['body'];
                $result['notes_title']  = $notes['title'];
                $result['notes_time']   = $notes['time'];
                $result['notes_url']    = $notes['url'];
                $result['notes_source'] = $notes['source'];
                $result['notes_scope']  = 'update';
            }
        }

        // tag 未超过当前版本时，再看 main 分支是否已跑到 tag 前面（发版漏打 tag 的常见情况）
        if (!$result['upgradeable']) {
            $branch = self::checkGiteeBranch($token, $latestSha, $latestTime);
            if (!empty($branch['ok']) && !empty($branch['ahead'])) {
                $result['branch_ahead'] = true;
                $result['branch_commit'] = $branch['commit'];
                $result['branch_message'] = $branch['message'];
                $result['branch_time'] = $branch['time'];
                $result['msg'] = 'main 分支已有新提交（' . $branch['commit'] . ' ' . $branch['message']
                    . '），但尚未打版本标签；请联系维护者发布 tag 后再升级';
            }
        }

        // 已是最新（且没有未发版提交）时，展示「当前版本改了什么」，
        // 解决升级完成后回头看却不知道这一版包含哪些内容的问题。
        if (empty($result['upgradeable']) && empty($result['branch_ahead'])) {
            $notes = self::releaseNotes(self::CURRENT_VERSION, $prevTag);
            if (!empty($notes['ok'])) {
                $result['notes']        = $notes['body'];
                $result['notes_title']  = $notes['title'];
                $result['notes_time']   = $notes['time'];
                $result['notes_url']    = $notes['url'];
                $result['notes_source'] = $notes['source'];
                $result['notes_scope']  = 'current';
            }
        }
        return $result;
    }

    /**
     * 检查 main 分支最新提交是否比「最新 tag」更新。
     * 采用 Gitee 的 commits 接口，与 tag 的 commit sha / 时间比对；网络异常时静默跳过
     * （不影响 tag 结果，仍然返回「已是最新」）。
     *
     * @return array{ok:bool,ahead?:bool,commit?:string,message?:string,time?:string}
     */
    private static function checkGiteeBranch(string $token, string $tagSha = '', string $tagTime = ''): array
    {
        $url = 'https://gitee.com/api/v5/repos/' . rawurlencode(self::owner())
            . '/' . rawurlencode(self::repo())
            . '/commits?sha=main&per_page=1';
        if ($token !== '') {
            $url .= '&access_token=' . rawurlencode($token);
        }
        $http = self::httpGet($url, 10);
        if (!$http['ok'] || (int)($http['code'] ?? 0) !== 200) {
            return ['ok' => false];
        }
        $rows = json_decode($http['body'], true);
        if (!is_array($rows) || !isset($rows[0])) {
            return ['ok' => false];
        }
        $c = $rows[0];
        $sha = (string)($c['sha'] ?? '');
        $commit = substr($sha, 0, 8);
        $message = trim((string)($c['commit']['message'] ?? ''));
        $message = strtok($message, "\n") ?: '';
        $time = (string)($c['commit']['committer']['date'] ?? '');
        if ($commit === '') {
            return ['ok' => false];
        }
        return [
            'ok'      => true,
            'ahead'   => self::isBranchAhead($sha, $time, $message, $tagSha, $tagTime),
            'commit'  => $commit,
            'message' => mb_substr($message, 0, 60),
            'time'    => $time,
        ];
    }

    /**
     * 判断 main 分支最新提交是否代表「尚未发版的更新」。
     *
     * 判定顺序（任一命中即视为无更新，避免误报）：
     *   1) 提交与最新 tag 指向同一个 commit —— main 没有超出 tag 的内容；
     *   2) 提交时间不晚于 tag 时间 —— tag 已经包含该提交。
     *
     * 其余情况一律视为「有提交但没打 tag」。注意：**不再豁免 docs/chore 前缀**。
     * 产品要求「每次更新都必须能在线升级」，文档、脚本、构建产物同样属于一次交付；
     * 若继续放过 docs 提交，发版遗漏时页面会静默显示「已是最新」，运维无从发现。
     */
    private static function isBranchAhead(string $sha, string $time, string $message, string $tagSha = '', string $tagTime = ''): bool
    {
        if ($tagSha !== '' && $sha !== '' && strcasecmp($sha, $tagSha) === 0) {
            return false;
        }
        if ($time !== '' && $tagTime !== '') {
            $mt = strtotime($time);
            $tt = strtotime($tagTime);
            if ($mt !== false && $tt !== false && $mt <= $tt) {
                return false;
            }
        }
        return $message !== '';
    }


    /**
     * 读取指定版本的「更新内容」。
     *
     * 依次尝试三个来源（任一成功即返回，全部失败返回 ok=false，不影响升级本身）：
     *   1) Gitee Release 正文：维护者在 Gitee 上给 tag 建 Release 即可自定义更新日志；
     *   2) 两个 tag 之间的提交列表：没建 Release 时用 compare 接口按提交信息汇总；
     *   3) tag 指向提交的提交说明：最后兜底，适合只有一个提交的小版本。
     *
     * @param string $tag     目标版本（如 v2.99.7）
     * @param string $baseTag 当前版本，用于取增量提交
     * @return array{ok:bool,body:string,title:string,time:string,url:string,source:string}
     */
    public static function releaseNotes(string $tag, string $baseTag = ''): array
    {
        $empty = ['ok' => false, 'body' => '', 'title' => '', 'time' => '', 'url' => '', 'source' => ''];
        $tag = trim($tag);
        if ($tag === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $tag)) {
            return $empty;
        }
        $token = self::giteeToken();
        $api = 'https://gitee.com/api/v5/repos/' . rawurlencode(self::owner())
            . '/' . rawurlencode(self::repo());
        $withToken = static function (string $url) use ($token): string {
            return $token !== '' ? $url . (strpos($url, '?') === false ? '?' : '&') . 'access_token=' . rawurlencode($token) : $url;
        };
        $webBase = 'https://gitee.com/' . rawurlencode(self::owner()) . '/' . rawurlencode(self::repo());

        // 1) Release 正文（优先用一次拉取的列表索引；跨版本汇总时避免 N 次请求）
        $index = self::loadReleaseIndex();
        $rel = $index[$tag] ?? null;
        if (!is_array($rel)) {
            // 索引未覆盖（列表请求失败 / 超过分页上限）时回退单 tag 查询
            $http = self::httpGet($withToken($api . '/releases/tags/' . rawurlencode($tag)), 8);
            if ($http['ok'] && (int)$http['code'] === 200) {
                $one = json_decode($http['body'], true);
                if (is_array($one)) {
                    $rel = $one;
                }
            }
        }
        if (is_array($rel)) {
            $body = trim((string)($rel['body'] ?? ''));
            if ($body !== '') {
                $relUrl = trim((string)($rel['html_url'] ?? ''));
                return [
                    'ok'     => true,
                    'body'   => self::limitText($body),
                    'title'  => trim((string)($rel['name'] ?? '')) ?: $tag,
                    'time'   => (string)($rel['created_at'] ?? ''),
                    'url'    => $relUrl !== '' ? $relUrl : $webBase . '/releases/tag/' . $tag,
                    'source' => 'release',
                ];
            }
        }

        // 2) 两个 tag 之间的提交列表
        $baseTag = trim($baseTag);
        if ($baseTag !== '' && $baseTag !== $tag && preg_match('/^[A-Za-z0-9._-]+$/', $baseTag)) {
            $cmpUrl = $api . '/compare/' . rawurlencode($baseTag) . '...' . rawurlencode($tag);
            $http = self::httpGet($withToken($cmpUrl), 10);
            if ($http['ok'] && (int)$http['code'] === 200) {
                $cmp = json_decode($http['body'], true);
                $commits = is_array($cmp) ? ($cmp['commits'] ?? []) : [];
                if (is_array($commits) && $commits) {
                    $lines = [];
                    $total = count($commits);
                    foreach (array_slice($commits, 0, 30) as $c) {
                        $sha = substr((string)($c['sha'] ?? ''), 0, 7);
                        $msg = trim((string)($c['commit']['message'] ?? ''));
                        $msg = (string)(strtok($msg, "\n") ?: '');
                        if ($msg === '') {
                            continue;
                        }
                        $lines[] = '- ' . ($sha !== '' ? $sha . ' ' : '') . mb_substr($msg, 0, 100);
                    }
                    if ($lines) {
                        if ($total > 30) {
                            $lines[] = '… 另有 ' . ($total - 30) . ' 个提交';
                        }
                        return [
                            'ok'     => true,
                            'body'   => self::limitText(implode("\n", $lines)),
                            'title'  => $baseTag . ' → ' . $tag,
                            'time'   => '',
                            'url'    => $webBase . '/compare/' . $baseTag . '...' . $tag,
                            'source' => 'commits',
                        ];
                    }
                }
            }
        }

        // 3) tag 指向提交的提交说明
        $http = self::httpGet($withToken($api . '/commits/' . rawurlencode($tag)), 8);
        if ($http['ok'] && (int)$http['code'] === 200) {
            $c = json_decode($http['body'], true);
            $msg = is_array($c) ? trim((string)($c['commit']['message'] ?? '')) : '';
            if ($msg !== '') {
                return [
                    'ok'     => true,
                    'body'   => self::limitText($msg),
                    'title'  => $tag,
                    'time'   => (string)($c['commit']['committer']['date'] ?? ''),
                    'url'    => (string)($c['html_url'] ?? ''),
                    'source' => 'commit',
                ];
            }
        }

        return $empty;
    }

    /**
     * 一次性拉取仓库全部 Release（tag => release），进程内缓存。
     *
     * 汇总跨版本更新内容时如果逐个 tag 调 `/releases/tags/{tag}`，
     * 跨 10 个版本就是 10 次请求；这里改为一次列表请求。
     */
    private static function loadReleaseIndex(): array
    {
        if (self::$releaseIndex !== null) {
            return self::$releaseIndex;
        }
        self::$releaseIndex = [];
        $token = self::giteeToken();
        $api = 'https://gitee.com/api/v5/repos/' . rawurlencode(self::owner())
            . '/' . rawurlencode(self::repo());
        $url = $api . '/releases?per_page=100&page=1';
        if ($token !== '') {
            $url .= '&access_token=' . rawurlencode($token);
        }
        $http = self::httpGet($url, 12);
        if (!$http['ok'] || (int)($http['code'] ?? 0) !== 200) {
            return self::$releaseIndex;
        }
        $rows = json_decode($http['body'], true);
        if (!is_array($rows)) {
            return self::$releaseIndex;
        }
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $name = trim((string)($r['tag_name'] ?? ''));
            if ($name === '' || isset(self::$releaseIndex[$name])) {
                continue;
            }
            self::$releaseIndex[$name] = [
                'body'       => (string)($r['body'] ?? ''),
                'name'       => (string)($r['name'] ?? ''),
                'created_at' => (string)($r['created_at'] ?? ''),
                'html_url'   => (string)($r['html_url'] ?? ''),
            ];
        }
        return self::$releaseIndex;
    }

    /**
     * 汇总「基准版本 → 目标版本」之间所有版本的更新内容（v2.99.58）。
     *
     * 背景：跨版本升级（如 v2.99.50 → v2.99.57）时，旧实现只取最新 tag 的 Release
     * 正文，中间的 v2.99.51~56 更新内容被整段省略，用户无法判断这次升级包含什么。
     * 现在逐版本取正文（Release → compare 提交 → commit message 三级兜底），
     * 按版本号升序拼接、倒序展示（新版本在前，与 CHANGELOG / Release 页一致）。
     *
     * @param array $candidates 已知 tag 列表（可空；为空时用 Release 索引 + 起止 tag 推断）
     * @return array{ok:bool,body:string,title:string,time:string,url:string,source:string}
     */
    public static function releaseNotesRange(string $latest, string $fromTag, array $candidates = []): array
    {
        $empty = ['ok' => false, 'body' => '', 'title' => '', 'time' => '', 'url' => '', 'source' => ''];
        $latest  = trim($latest);
        $fromTag = trim($fromTag);
        if ($latest === '') {
            return $empty;
        }

        $versions = self::versionsBetween($fromTag, $latest, $candidates);
        if (!$versions) {
            return $empty;
        }
        // 只跨一个版本时保持原有行为（直接带出 Release 的 URL / 来源）
        if (count($versions) === 1) {
            return self::releaseNotes($versions[0], $fromTag);
        }

        $total = count($versions);
        $display = $versions;
        $hidden  = 0;
        // 一次跨太多版本时只展开最近 30 个，避免升级页被超长文本拖慢
        if ($total > 30) {
            $display = array_slice($versions, $total - 30);
            $hidden  = $total - 30;
        }

        $sections = [];
        $prev     = $fromTag;
        $time     = '';
        foreach ($display as $ver) {
            $n = self::releaseNotes($ver, $prev);
            $prev = $ver;
            if (empty($n['ok']) || trim((string)$n['body']) === '') {
                continue;
            }
            if ($time === '' || $ver === $latest) {
                $time = (string)$n['time'];
            }
            $title = trim((string)$n['title']);
            if ($title === '' || mb_strpos($title, $ver) === false) {
                $title = $ver . ($title !== '' ? ' ' . $title : '');
            }
            // release.mjs 自动生成的正文以「### 本次更新」开头，标题已含版本号，去掉重复小节名
            $body = trim((string)$n['body']);
            $body = preg_replace('/^#{1,6}\s*本次更新\s*\n+/u', '', $body) ?? $body;
            $sections[] = '### ' . $title . "\n" . $body;
        }
        if (!$sections) {
            return $empty;
        }

        // 新版本在前
        $sections = array_reverse($sections);
        $head = $fromTag !== ''
            ? "从 {$fromTag} 升级到 {$latest}，共 {$total} 个版本："
            : "目标版本 {$latest}，共 {$total} 个版本：";
        if ($hidden > 0) {
            $head .= "（仅展开最近 30 个版本，另有 {$hidden} 个更早版本未展示）";
        }

        $webBase = 'https://gitee.com/' . rawurlencode(self::owner()) . '/' . rawurlencode(self::repo());
        return [
            'ok'     => true,
            'body'   => self::limitText($head . "\n\n" . implode("\n\n", $sections), 16000),
            'title'  => $fromTag !== '' ? ($fromTag . ' → ' . $latest) : $latest,
            'time'   => $time,
            'url'    => $fromTag !== '' && $fromTag !== $latest
                ? $webBase . '/compare/' . $fromTag . '...' . $latest
                : $webBase . '/releases/tag/' . $latest,
            'source' => 'aggregate',
        ];
    }

    /**
     * 列出 (fromTag, toTag] 之间的版本号（升序）。
     */
    private static function versionsBetween(string $fromTag, string $toTag, array $candidates = []): array
    {
        $all = [];
        foreach ($candidates as $t) {
            $t = trim((string)$t);
            if ($t !== '') {
                $all[$t] = 1;
            }
        }
        foreach (array_keys(self::loadReleaseIndex()) as $t) {
            $all[$t] = 1;
        }
        if ($fromTag !== '') {
            $all[$fromTag] = 1;
        }
        $all[$toTag] = 1;

        $list = [];
        foreach (array_keys($all) as $t) {
            if (!preg_match('/^v?\d+\.\d+(\.\d+)?$/', $t)) {
                continue;
            }
            if ($fromTag !== '' && self::compareVersion($t, $fromTag) <= 0) {
                continue;
            }
            if (self::compareVersion($t, $toTag) > 0) {
                continue;
            }
            $list[] = $t;
        }
        usort($list, static fn ($a, $b) => self::compareVersion($a, $b));
        return $list;
    }

    /** 更新内容截断，避免超长 Release 正文把接口和前端页面拖慢 */
    private static function limitText(string $text, int $limit = 8000): string
    {
        $text = str_replace("\r\n", "\n", trim($text));
        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) . "\n…（已截断）" : $text;
    }


    /** 保存最近一次检查结果 */
    public static function saveLastCheck(array $result): void
    {
        self::ensureDirs();
        self::writeJson(self::upgradeDir() . '/last_check.json', array_merge($result, [
            'time'   => date('Y-m-d H:i:s'),
            'version' => self::CURRENT_VERSION,
        ]));
    }

    // ---------------- 备份 ----------------

    /** 升级前备份：程序文件 zip + 数据库 mysqldump */
    public static function createBackup(bool $withProgress = false): array
    {
        self::ensureDirs();
        self::raiseTimeLimit();
        $ts      = date('Ymd_His');
        $version = preg_replace('/\W+/', '', self::CURRENT_VERSION);
        $fileZip = self::backupDir() . '/files_' . $version . '_' . $ts . '.zip';
        $dbFile  = self::backupDir() . '/db_' . $version . '_' . $ts . '.sql';

        if ($withProgress) {
            self::setProgress('backup_files', 45, '正在备份程序文件…');
        }
        $zipOk = self::zipDir(
            self::baseDir(),
            $fileZip,
            self::backupExcludes(),
            static function (int $done, int $total) use ($withProgress): void {
                if (!$withProgress) {
                    return;
                }
                // 45% → 60%：备份阶段按文件数推进，避免大站点长时间停在「45%」看起来像卡死
                $pct = $total > 0 ? 45 + (int)floor($done / $total * 15) : 45;
                self::setProgress('backup_files', min(60, $pct), "正在备份程序文件（{$done}/{$total}）…");
            }
        );
        if ($withProgress) {
            self::setProgress('backup_db', 62, $zipOk ? '程序文件已备份，正在备份数据库…' : '程序文件备份失败，正在备份数据库…');
        }
        $dbOk  = self::dumpDatabase($dbFile);

        $msg = [];
        if ($zipOk) {
            $msg[] = '程序文件已备份';
        } else {
            $why = self::lastZipError();
            $msg[] = '程序文件打包失败' . ($why !== '' ? "（{$why}）" : '');
        }
        if ($dbOk) {
            $msg[] = '数据库已备份';
        } else {
            $msg[] = self::commandRunner() === ''
                ? '数据库备份失败（服务器已禁用 exec/shell_exec/proc_open，无法调用 mysqldump）'
                : '数据库备份失败（请检查 mysqldump 是否可用，必要时在 .env [UPGRADE] MYSQLDUMP 指定完整路径）';
        }

        self::appendHistory([
            'time'     => date('Y-m-d H:i:s'),
            'type'     => 'backup',
            'version'  => self::CURRENT_VERSION,
            'source'   => 'manual',
            'files'    => $zipOk ? basename($fileZip) : '',
            'db'       => $dbOk ? basename($dbFile) : '',
            'status'   => ($zipOk && $dbOk) ? 'ok' : 'error',
            'message'  => implode('；', $msg),
        ]);

        return [
            'ok'    => $zipOk && $dbOk,
            'msg'   => implode('；', $msg),
            'files' => $zipOk ? basename($fileZip) : '',
            'db'    => $dbOk ? basename($dbFile) : '',
        ];
    }

    /**
     * 备份需要排除的目录。
     *
     * 默认排除 runtime：里面有历史备份 zip、升级临时文件、日志与缓存，
     * 既不该进备份，也会让备份越滚越大。
     * 若站点附件/上传目录很大，可在 backend/.env 追加：
     *   [UPGRADE]
     *   BACKUP_EXCLUDE = public/uploads,public/storage
     */
    private static function backupExcludes(): array
    {
        $ex = [self::runtimeDir()];
        $extra = (string)env('upgrade.backup_exclude', '');
        foreach (preg_split('/[,;]+/', $extra) ?: [] as $p) {
            $p = trim(str_replace('\\', '/', $p), " \t\r\n/");
            if ($p !== '') {
                $ex[] = $p;
            }
        }
        return $ex;
    }

    /** 人类可读的字节数 */
    private static function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $n = (float)$bytes;
        while ($n >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }
        return ($i === 0 ? (string)(int)$n : number_format($n, 1)) . $units[$i];
    }

    /**
     * 程序文件打包为 zip（排除 runtime 等）
     *
     * @param callable|null $onProgress function(int $done, int $total)
     */
    public static function zipDir(string $sourceDir, string $targetZip, array $excludeDirs = [], ?callable $onProgress = null): bool
    {
        self::$zipError = '';
        if (!class_exists('ZipArchive')) {
            self::$zipError = '服务器未启用 ZipArchive 扩展';
            return false;
        }
        $sourceDir = rtrim(str_replace('\\', '/', $sourceDir), '/');
        $targetZip = str_replace('\\', '/', $targetZip);
        if (!is_dir($sourceDir)) {
            self::$zipError = '源目录不存在';
            return false;
        }

        // 日志/临时文件命名：files_xxx.zip → 同目录的 files_xxx.zip.*
        $targetRel = strpos($targetZip, $sourceDir . '/') === 0
            ? substr($targetZip, strlen($sourceDir) + 1)
            : '';

        // 排除目录统一归一化为「相对 sourceDir」的形式。
        // 历史问题：此前直接把绝对路径拼成 '/xxx/runtime/' 后与相对路径比较，
        // 永远不相等 → runtime 没被排除 → 备份里含历史备份 zip，甚至把正在写入的
        // 备份自己打包进去，压缩包越滚越大，最终 close() 失败，表现为
        // 「升级卡在 45%」或「程序文件打包失败」。删除旧备份后体积变小才偶然成功。
        $excludes = [];
        foreach ($excludeDirs as $d) {
            $p = rtrim(str_replace('\\', '/', (string)$d), '/');
            if ($p === '' || $p === $sourceDir) {
                continue;
            }
            if (strpos($p, $sourceDir . '/') === 0) {
                $p = substr($p, strlen($sourceDir) + 1);
            }
            $p = trim($p, '/');
            if ($p !== '') {
                $excludes[] = $p . '/';
            }
        }
        $isExcluded = static function (string $rel) use ($excludes, $targetRel): bool {
            if ($targetRel !== '' && ($rel === $targetRel || strpos($rel, $targetRel . '.') === 0)) {
                return true; // 备份文件自身及 .part/.tmp 等临时产物
            }
            foreach ($excludes as $ex) {
                if ($rel === rtrim($ex, '/') || strpos($rel, $ex) === 0) {
                    return true;
                }
            }
            return false;
        };

        $makeIterator = static function () use ($sourceDir, $isExcluded): \RecursiveIteratorIterator {
            $filter = new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $f) use ($sourceDir, $isExcluded): bool {
                    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($sourceDir) + 1));
                    return !$isExcluded($rel);
                }
            );
            return new \RecursiveIteratorIterator($filter);
        };

        // 先统计文件数与总大小：用于磁盘空间预检和进度百分比
        $total = 0;
        $bytes = 0;
        try {
            foreach ($makeIterator() as $f) {
                if ($f->isFile()) {
                    $total++;
                    $bytes += max(0, (int)$f->getSize());
                }
            }
        } catch (\Throwable $e) {
            self::$zipError = '无法读取待备份目录：' . $e->getMessage();
            return false;
        }
        $free = @disk_free_space(dirname($targetZip));
        if ($free !== false && $bytes > 0 && (float)$free < $bytes * 0.9) {
            self::$zipError = '磁盘空间不足（程序文件约 ' . self::humanSize($bytes)
                . '，剩余 ' . self::humanSize((int)$free)
                . '），请先在「在线升级 → 备份记录」删除历史备份，或清理磁盘后重试';
            return false;
        }

        $zip = new \ZipArchive();
        try {
            $opened = @$zip->open($targetZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true;
        } catch (\Throwable $e) {
            $opened = false;
        }
        if (!$opened) {
            self::$zipError = '无法创建备份文件（请检查 runtime/backup 目录写权限与磁盘空间）';
            return false;
        }

        $done = 0;
        $failed = [];
        try {
            foreach ($makeIterator() as $file) {
                /** @var \SplFileInfo $file */
                $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($sourceDir) + 1));
                if ($file->isDir()) {
                    $zip->addEmptyDir($rel);
                    continue;
                }
                if (!$file->isFile()) {
                    continue;
                }
                if (!$file->isReadable()) {
                    $failed[] = $rel;
                    $done++;
                    continue;
                }
                if (@$zip->addFile($file->getPathname(), $rel) === false) {
                    $failed[] = $rel;
                }
                $done++;
                if ($onProgress !== null && ($done % 100 === 0 || $done === $total)) {
                    $onProgress($done, $total);
                }
            }
        } catch (\Throwable $e) {
            try { @$zip->close(); } catch (\Throwable $e2) {}
            @unlink($targetZip);
            self::$zipError = '打包过程中出错：' . $e->getMessage();
            return false;
        }

        try {
            $closed = $zip->close();
        } catch (\Throwable $e) {
            $closed = false;
        }
        if (!$closed) {
            $status = method_exists($zip, 'getStatusString') ? (string)@$zip->getStatusString() : '';
            @unlink($targetZip);
            self::$zipError = '压缩包写入失败' . ($status !== '' ? '：' . $status : '（可能为磁盘空间不足或文件被占用）');
            return false;
        }
        if (!is_file($targetZip) || (int)@filesize($targetZip) <= 0) {
            @unlink($targetZip);
            self::$zipError = '压缩包为空（请检查 runtime/backup 目录写权限与磁盘空间）';
            return false;
        }
        if (!empty($failed)) {
            @unlink($targetZip);
            self::$zipError = '有 ' . count($failed) . ' 个文件无法读取（如 ' . implode('、', array_slice($failed, 0, 3))
                . '），请检查文件权限后重试';
            return false;
        }
        return true;
    }

    /** 数据库备份（mysqldump） */
    public static function dumpDatabase(string $targetFile): bool
    {
        if (self::commandRunner() === '') {
            return false;
        }
        $bin = self::findMysqldump();
        if ($bin === '') {
            return false;
        }
        $cfg = (array)Config::get('database.connections.mysql');
        $db  = (string)($cfg['database'] ?? '');
        if ($db === '') {
            return false;
        }
        // 密码经临时 option 文件传递，避免依赖 putenv / MYSQL_PWD（共享主机常禁用）
        self::ensureDirs();
        $cnf = self::upgradeDir() . '/mysql_' . bin2hex(random_bytes(4)) . '.cnf';
        @file_put_contents($cnf, "[client]\npassword=\"" . str_replace('"', '\\"', (string)($cfg['password'] ?? '')) . "\"\n");
        $cmd = escapeshellarg($bin)
            . ' --defaults-extra-file=' . escapeshellarg($cnf)
            . ' --default-character-set=utf8mb4'
            . ' -h' . escapeshellarg((string)($cfg['hostname'] ?? '127.0.0.1'))
            . ' -P' . escapeshellarg((string)($cfg['hostport'] ?? '3306'))
            . ' -u' . escapeshellarg((string)($cfg['username'] ?? 'root'))
            . ' ' . escapeshellarg($db)
            . ' > ' . escapeshellarg($targetFile) . ' 2>' . escapeshellarg($targetFile . '.err');
        $res = self::runShell($cmd);
        @unlink($cnf);
        $err = is_file($targetFile . '.err') ? trim((string)file_get_contents($targetFile . '.err')) : '';
        @unlink($targetFile . '.err');
        if ((int)$res['code'] !== 0
            || preg_match('/\bERROR\s+\d+/i', $err)
            || !is_file($targetFile)
            || (int)filesize($targetFile) <= 0) {
            @unlink($targetFile);
            return false;
        }
        return true;
    }

    // ---------------- 升级包获取 ----------------

    /** 从 Gitee 下载指定 tag 的 zip */
    public static function download(string $tag): array
    {
        self::ensureDirs();
        $tag = trim($tag);
        if ($tag === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $tag)) {
            return ['ok' => false, 'msg' => '版本标签格式不正确'];
        }
        // 有令牌走 API zipball（私有仓库必需）；无令牌走网页归档地址，
        // 保证公开仓库在未配置令牌时仍可正常下载（zipball 对匿名请求返回 401）。
        $token = self::giteeToken();
        if ($token !== '') {
            $url = 'https://gitee.com/api/v5/repos/' . rawurlencode(self::owner())
                . '/' . rawurlencode(self::repo())
                . '/zipball?ref=' . rawurlencode($tag)
                . '&access_token=' . rawurlencode($token);
        } else {
            $url = 'https://gitee.com/' . rawurlencode(self::owner()) . '/' . rawurlencode(self::repo())
                . '/repository/archive/' . rawurlencode($tag) . '.zip';
        }
        $http = self::httpGet($url, 180);
        if (!$http['ok']) {
            return ['ok' => false, 'msg' => '下载失败：' . $http['msg']];
        }
        $code = (int)($http['code'] ?? 0);
        if ($code !== 200) {
            return ['ok' => false, 'msg' => self::giteeHttpError($code, $token !== '')];
        }
        $tmp = self::upgradeDir() . '/gitee_' . preg_replace('/\W+/', '', $tag) . '.zip';
        if (@file_put_contents($tmp, $http['body']) === false) {
            return ['ok' => false, 'msg' => '写入升级包文件失败'];
        }
        $check = self::validateZip($tmp);
        if (!$check['ok']) {
            @unlink($tmp);
            return $check;
        }
        return ['ok' => true, 'path' => $tmp, 'size' => (int)strlen($http['body']), 'message' => $check['message']];
    }

    /** 接收上传的 zip 升级包，保存并校验 */
    public static function acceptUpload(string $tmpPath, string $originalName): array
    {
        self::ensureDirs();
        if (!is_file($tmpPath) || (int)filesize($tmpPath) <= 0) {
            return ['ok' => false, 'msg' => '未接收到有效的升级包文件'];
        }
        if (strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION)) !== 'zip') {
            return ['ok' => false, 'msg' => '仅支持 .zip 升级包'];
        }
        $target = self::upgradeDir() . '/upload_' . date('Ymd_His') . '_' . mt_rand(1000, 9999) . '.zip';
        if (!@move_uploaded_file($tmpPath, $target) && !@copy($tmpPath, $target)) {
            return ['ok' => false, 'msg' => '保存升级包失败，请检查 runtime 目录权限'];
        }
        $check = self::validateZip($target);
        if (!$check['ok']) {
            @unlink($target);
            return $check;
        }
        return ['ok' => true, 'path' => $target, 'size' => (int)filesize($target), 'message' => $check['message']];
    }

    /** 校验 zip：必须是有效压缩包且包含本系统 backend 目录标记 */
    private static function validateZip(string $path): array
    {
        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'msg' => '服务器未启用 ZipArchive 扩展'];
        }
        $zip = new \ZipArchive();
        try {
            if (@$zip->open($path) !== true) {
                return ['ok' => false, 'msg' => '升级包不是有效的 zip 文件'];
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'msg' => '升级包不是有效的 zip 文件'];
        }
        $hasBackend = false;
        $hasMarker  = false;
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = str_replace('\\', '/', (string)$zip->getNameIndex($i));
                if (preg_match('#(^|/)backend(/|$)#', $name)) {
                    $hasBackend = true;
                }
                if (preg_match('#(^|/)backend/think$#', $name)
                    || preg_match('#(^|/)backend/app/(BaseController|common)\.php$#', $name)) {
                    $hasMarker = true;
                }
                if ($hasBackend && $hasMarker) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            // 继续用已扫描到的结果判断
        }
        try { @$zip->close(); } catch (\Throwable $e) {}
        if (!$hasBackend) {
            return ['ok' => false, 'msg' => '升级包中未找到 backend 目录'];
        }
        if (!$hasMarker) {
            return ['ok' => false, 'msg' => '升级包缺少 backend/think 或应用标记文件，不是本系统升级包'];
        }
        return ['ok' => true, 'message' => '校验通过（包含 backend 目录）'];
    }

    // ---------------- 应用升级 ----------------

    /** 解压并替换 backend 目录文件（跳过 runtime 与 .env），返回替换文件数 */
    public static function apply(string $zipPath, string $source, string $tag = ''): array
    {
        self::raiseTimeLimit();
        if (!is_file($zipPath)) {
            return self::applyFail('升级包文件不存在');
        }
        if (!class_exists('ZipArchive')) {
            return self::applyFail('服务器未启用 ZipArchive 扩展');
        }
        // 写权限预检：提前暴露部署目录不可写的问题，避免备份完成后才发现无法替换文件
        $notWritable = self::preflightWriteCheck();
        if ($notWritable) {
            $msg = '目标文件/目录不可写，无法升级：' . implode('、', $notWritable)
                . '。请确保 PHP 运行用户对 backend 目录及其文件有写权限';
            self::appendUpgradeHistory($source, $zipPath, 'error', $msg);
            return self::applyFail($msg);
        }
        self::setProgress('extract', 70, '正在解压升级包…');
        $jobDir = self::upgradeDir() . '/apply_' . date('Ymd_His');
        if (!@mkdir($jobDir, 0755, true) && !is_dir($jobDir)) {
            return self::applyFail('无法创建工作目录（runtime 是否可写？）');
        }
        $zip = new \ZipArchive();
        try {
            $opened = @$zip->open($zipPath) === true;
        } catch (\Throwable $e) {
            $opened = false;
        }
        if (!$opened) {
            @self::removeDir($jobDir);
            return self::applyFail('无法解压升级包');
        }
        try {
            $extracted = $zip->extractTo($jobDir);
        } catch (\Throwable $e) {
            $extracted = false;
        }
        try { @$zip->close(); } catch (\Throwable $e) {}
        if (!$extracted) {
            @self::removeDir($jobDir);
            return self::applyFail('解压升级包失败，请检查 runtime 目录空间与权限');
        }

        $backendSrc = self::locateBackendDir($jobDir);
        if ($backendSrc === '' || !is_file($backendSrc . '/think')) {
            @self::removeDir($jobDir);
            return self::applyFail('未能在升级包中找到 backend 目录');
        }

        self::setProgress('copy', 75, '正在替换程序文件…');
        $copy = self::copyTreeDetailed($backendSrc, self::baseDir(), static function (int $done, int $total): void {
            $pct = $total > 0 ? 75 + (int)floor($done / $total * 15) : 85;
            self::setProgress('copy', min(90, $pct), "正在替换程序文件（{$done}/{$total}）…");
        });
        if ($copy['count'] <= 0 || $copy['failed']) {
            @self::removeDir($jobDir);
            $msg = $copy['count'] <= 0
                ? '没有可替换的文件，请检查 backend 目录写权限'
                : '部分文件替换失败（' . count($copy['failed']) . ' 个），升级已中止。请确保 PHP 运行用户对 backend 目录有写权限，示例：'
                    . implode('、', array_slice($copy['failed'], 0, 5));
            self::appendUpgradeHistory($source, $zipPath, 'error', $msg);
            return self::applyFail($msg);
        }

        // 关键文件落盘校验：避免 @copy 静默失败但历史记录却显示成功
        $verify = self::verifyAppliedFiles($backendSrc);
        if (!$verify['ok']) {
            @self::removeDir($jobDir);
            self::appendUpgradeHistory($source, $zipPath, 'error', $verify['msg']);
            return self::applyFail($verify['msg']);
        }
        @self::removeDir($jobDir);

        // 清理 OPcache 与运行时缓存，确保新代码立即生效（仅替换文件时 OPcache 可能仍缓存旧代码）
        self::resetOpcache();
        self::clearRuntimeCache();

        // v2.99.60：升级完成后自动回填存量身份证号明文（限时执行，失败不影响升级结果）。
        // 之前只有手工命令 php think idcard:encrypt，生产升级后没人执行，
        // 导致「数据库内现有的身份证号未被处理」。
        // v2.99.70：升级前先检查是否还有明文存量；没有就完全跳过，
        // 不再每次在线升级都扫一遍三张表、也不再写「加密 0 行」的历史。
        $backfill = self::backfillIdCardsIfNeeded();

        $toVersion = self::readVersionFromDir(self::baseDir());
        // 升级成功后把目标版本的更新内容一并写入历史，便于「升级后」回看本次改了什么。
        // v2.99.58：跨版本升级时同时汇总中间版本，避免历史记录只留下最后一版。
        $notes = [];
        if ($source === 'gitee') {
            $notes = self::releaseNotesRange($tag !== '' ? $tag : $toVersion, self::CURRENT_VERSION);
            if (empty($notes['ok'])) {
                $notes = [];
            }
        }
        self::appendUpgradeHistory(
            $source,
            $zipPath,
            'ok',
            "已替换 {$copy['count']} 个文件" . ($toVersion !== '' ? "，目标版本 {$toVersion}" : '')
                . ($backfill['msg'] !== '' ? '；' . $backfill['msg'] : ''),
            $toVersion,
            $notes
        );
        self::setProgress('done', 100, '升级完成' . ($toVersion !== '' ? "（{$toVersion}）" : ''), false, true);
        return [
            'ok'      => true,
            'count'   => $copy['count'],
            'version' => $toVersion,
            'msg'     => '升级完成，已替换 ' . $copy['count'] . ' 个文件'
                . ($toVersion !== '' ? '，当前版本 ' . $toVersion : '')
                . ($backfill['msg'] !== '' ? '；' . $backfill['msg'] : ''),
        ];
    }

    /** 记录升级失败进度并返回统一错误结构 */
    private static function applyFail(string $msg): array
    {
        self::setProgress('failed', 100, $msg, false, false);
        return ['ok' => false, 'msg' => $msg];
    }

    /**
     * 升级后按需回填存量身份证号（v2.99.70）
     *
     * 只有 `IdCardBackfillService::pendingSummary()` 明确还有明文行时才执行；
     * 存量早已全部加密的部署，升级不再重复扫描/回填，也不产生「加密 0 行」历史。
     * 日常新增数据走写入侧加密；历史遗留由运行时自愈与手工命令兜底。
     *
     * @return array{ok: bool, msg: string}
     */
    private static function backfillIdCardsIfNeeded(): array
    {
        $class = '\\app\\service\\IdCardBackfillService';
        if (!class_exists($class)) {
            return ['ok' => true, 'msg' => ''];
        }
        try {
            if (!$class::pendingSummary()) {
                return ['ok' => true, 'msg' => ''];
            }
        } catch (\Throwable $e) {
            // 检查失败不应打断升级：交给运行时自愈
            return ['ok' => true, 'msg' => ''];
        }
        return self::backfillIdCards();
    }

    /**
     * 升级后自动回填存量身份证号（v2.99.60）
     *
     * 只替换文件不回填数据，库里仍是明文，页面就成了「加密了但历史数据没处理」。
     * 这里在升级成功后直接跑一次限时回填，剩余行由运行时自愈继续推进。
     *
     * @param int $timeLimit 时间预算（秒），避免大库把升级请求拖到超时
     * @return array{ok: bool, msg: string}
     */
    private static function backfillIdCards(int $timeLimit = 30): array
    {
        $class = '\\app\\service\\IdCardBackfillService';
        try {
            if (!class_exists($class)) {
                return ['ok' => false, 'msg' => '身份证号回填：升级包缺少回填服务，已跳过'];
            }
            self::setProgress('backfill', 92, '正在迁移存量身份证号（SM4 加密）…');
            /** @var array $r */
            $r = $class::run(['timeLimit' => $timeLimit]);
            $t = $r['total'] ?? [];
            $encrypted = (int)($t['encrypted'] ?? 0);
            $hash      = (int)($t['hash'] ?? 0);
            $fail      = (int)($t['fail'] ?? 0);
            $msg = '身份证号回填：加密 ' . $encrypted . ' 行，补盲索引 ' . $hash . ' 行'
                . ($fail > 0 ? '，失败 ' . $fail . ' 行' : '')
                . (!empty($r['truncated']) ? '（已达时间上限，剩余行会在后续访问时自动继续）' : '');
            return ['ok' => $fail === 0, 'msg' => $msg];
        } catch (\Throwable $e) {
            return ['ok' => false, 'msg' => '身份证号回填失败：' . $e->getMessage()];
        }
    }

    /** 写入升级历史（成功 / 失败） */
    private static function appendUpgradeHistory(string $source, string $zipPath, string $status, string $message, string $toVersion = '', array $notes = []): void
    {
        self::appendHistory([
            'time'         => date('Y-m-d H:i:s'),
            'type'         => 'upgrade',
            'version'      => $toVersion !== '' ? $toVersion : self::CURRENT_VERSION,
            'from_version' => self::CURRENT_VERSION,
            'source'       => $source,
            'files'        => basename($zipPath),
            'db'           => '',
            'status'       => $status,
            'message'      => $message,
            'notes'        => (string)($notes['body'] ?? ''),
            'notes_title'  => (string)($notes['title'] ?? ''),
            'notes_url'    => (string)($notes['url'] ?? ''),
            'notes_source' => (string)($notes['source'] ?? ''),
        ]);
    }

    /**
     * 校验升级包中的关键文件是否已与解压源一致。
     * 只要有一个关键文件未替换成功，就说明升级并没有真正完成。
     */
    private static function verifyAppliedFiles(string $backendSrc): array
    {
        $dst = rtrim(str_replace('\\', '/', self::baseDir()), '/');
        $src = rtrim(str_replace('\\', '/', $backendSrc), '/');
        foreach (['app/service/UpgradeService.php', 'route/app.php', 'public/web/index.html'] as $rel) {
            if (!is_file($src . '/' . $rel)) {
                continue;
            }
            $target = $dst . '/' . $rel;
            if (!is_file($target)) {
                return ['ok' => false, 'msg' => "关键文件 {$rel} 缺失，升级未完成"];
            }
            $srcHash = @sha1_file($src . '/' . $rel);
            $dstHash = @sha1_file($target);
            if ($srcHash === false || $dstHash === false || $srcHash !== $dstHash) {
                return ['ok' => false, 'msg' => "关键文件 {$rel} 未能替换（文件可能被占用或权限不足）"];
            }
        }
        return ['ok' => true];
    }

    /** 从磁盘上的 UpgradeService.php 读取版本号（升级后当前进程仍持有旧常量） */
    private static function readVersionFromDir(string $dir): string
    {
        $file = rtrim(str_replace('\\', '/', $dir), '/') . '/app/service/UpgradeService.php';
        if (!is_file($file)) {
            return '';
        }
        $head = @file_get_contents($file, false, null, 0, 8192);
        if (is_string($head) && preg_match("/CURRENT_VERSION\s*=\s*'([^']+)'/", $head, $m)) {
            return $m[1];
        }
        return '';
    }

    /** 重置 OPcache，避免文件已替换但仍执行旧代码 */
    private static function resetOpcache(): void
    {
        $root = rtrim(str_replace('\\', '/', self::baseDir()), '/');
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($root . '/app/service/UpgradeService.php', true);
            @opcache_invalidate($root . '/route/app.php', true);
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        clearstatcache(true);
    }

    /**
     * 升级目标写权限预检。
     * 返回不可写的文件/目录（相对路径）；为空表示通过。
     */
    private static function preflightWriteCheck(): array
    {
        $root = rtrim(str_replace('\\', '/', self::baseDir()), '/');
        $checks = [
            'app/service/UpgradeService.php' => 'app/service/',
            'route/app.php'                  => 'route/',
            'public/web/index.html'          => 'public/web/',
        ];
        $problems = [];
        foreach ($checks as $file => $dir) {
            $path   = $root . '/' . $file;
            $parent = $root . '/' . $dir;
            if (is_file($path) && !is_writable($path)) {
                @chmod($path, 0644);
            }
            if (is_file($path) && !is_writable($path) && (!is_dir($parent) || !is_writable($parent))) {
                $problems[] = $file;
            }
            if (is_dir($parent) && !is_writable($parent)) {
                $problems[] = rtrim($dir, '/');
            }
        }
        return array_values(array_unique($problems));
    }

    /** 在解压目录中定位 backend 根目录（兼容 Gitee 外层目录与直接 backend 打包） */
    private static function locateBackendDir(string $root): string
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (is_dir($root . '/backend')) {
            return $root . '/backend';
        }
        foreach (glob($root . '/*') ?: [] as $d) {
            if (is_dir($d) && is_dir($d . '/backend')) {
                return str_replace('\\', '/', $d) . '/backend';
            }
            if (is_dir($d) && is_file($d . '/think')) {
                return str_replace('\\', '/', $d);
            }
        }
        return '';
    }

    /** 递归复制目录（跳过 runtime 顶层与 .env），返回成功复制文件数 */
    private static function copyTree(string $src, string $dst): int
    {
        $r = self::copyTreeDetailed($src, $dst);
        return $r['count'];
    }

    /**
     * 递归复制目录，记录失败文件，便于在升级失败时给出明确原因。
     * 目标文件不可写时先 chmod，再退化为「同目录临时文件 + rename」；
     * 复制成功后刷新 mtime，避免 OPcache 依据旧时间戳继续使用旧代码。
     */
    private static function copyTreeDetailed(string $src, string $dst, ?callable $onProgress = null): array
    {
        $count  = 0;
        $done   = 0;
        $failed = [];
        $src = rtrim(str_replace('\\', '/', $src), '/');
        $dst = rtrim(str_replace('\\', '/', $dst), '/');

        // 先统计待复制文件数，进度展示才有总数
        $total = 0;
        $pre = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($pre as $item) {
            $rel = str_replace('\\', '/', substr($item->getPathname(), strlen($src) + 1));
            if (strtok($rel, '/') === 'runtime' || $item->getFilename() === '.env') {
                continue;
            }
            if ($item->isFile()) {
                $total++;
            }
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            $rel = str_replace('\\', '/', substr($item->getPathname(), strlen($src) + 1));
            $first = strtok($rel, '/');
            if ($first === 'runtime') {
                continue;
            }
            if ($item->getFilename() === '.env') {
                continue;
            }
            $target = $dst . '/' . $rel;
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    @mkdir($target, 0755, true);
                }
                continue;
            }
            if (!is_dir(dirname($target))) {
                @mkdir(dirname($target), 0755, true);
            }
            $done++;
            if (self::replaceFile($item->getPathname(), $target)) {
                $count++;
            } else {
                $failed[] = $rel;
            }
            if ($onProgress !== null && ($done % 50 === 0 || $done === $total)) {
                $onProgress($done, $total);
            }
        }
        return ['count' => $count, 'failed' => $failed, 'total' => $total];
    }

    /** 覆盖单个文件，兼容只读文件与 Linux 下的文件属主差异 */
    private static function replaceFile(string $source, string $target): bool
    {
        if (!is_file($target)) {
            if (@copy($source, $target)) {
                @touch($target);
                return true;
            }
            return false;
        }
        if (!is_writable($target)) {
            @chmod($target, 0644);
        }
        if (@copy($source, $target)) {
            @touch($target);
            return true;
        }
        // 目录可写即可通过 rename 覆盖只读文件
        $tmp = dirname($target) . '/.upgrade_' . bin2hex(random_bytes(4)) . '.tmp';
        if (@copy($source, $tmp)) {
            if (@rename($tmp, $target)) {
                @touch($target);
                return true;
            }
            if (@unlink($target) && @rename($tmp, $target)) {
                @touch($target);
                return true;
            }
            @unlink($tmp);
        }
        return false;
    }

    /** 清理运行时缓存（cache/temp/session/log），保留目录本身 */
    public static function clearRuntimeCache(): void
    {
        foreach (['cache', 'temp', 'session', 'log'] as $sub) {
            $dir = self::runtimeDir() . $sub;
            if (!is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                if ($f->isDir()) {
                    @rmdir($f->getPathname());
                } else {
                    @unlink($f->getPathname());
                }
            }
        }
    }

    // ---------------- 备份恢复 ----------------

    /** 恢复备份：files=程序文件 zip 回滚；db=数据库 sql 导入 */
    public static function restore(string $backupFile, string $type): array
    {
        self::raiseTimeLimit();
        self::ensureDirs();
        $path = self::backupDir() . '/' . basename($backupFile);
        if (!is_file($path)) {
            return ['ok' => false, 'msg' => '备份文件不存在'];
        }
        if ($type === 'db') {
            $r = self::restoreDatabase($path);
            if ($r['ok']) {
                self::appendHistory([
                    'time'    => date('Y-m-d H:i:s'),
                    'type'    => 'restore',
                    'version' => self::CURRENT_VERSION,
                    'source'  => 'db',
                    'files'   => '',
                    'db'      => basename($path),
                    'status'  => 'ok',
                    'message' => '数据库已恢复',
                ]);
            }
            return $r;
        }
        if ($type !== 'files') {
            return ['ok' => false, 'msg' => '不支持的恢复类型'];
        }
        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'msg' => '服务器未启用 ZipArchive 扩展'];
        }
        $jobDir = self::upgradeDir() . '/restore_' . date('Ymd_His');
        if (!@mkdir($jobDir, 0755, true) && !is_dir($jobDir)) {
            return ['ok' => false, 'msg' => '无法创建工作目录'];
        }
        $zip = new \ZipArchive();
        try {
            $opened = @$zip->open($path) === true;
        } catch (\Throwable $e) {
            $opened = false;
        }
        if (!$opened) {
            @self::removeDir($jobDir);
            return ['ok' => false, 'msg' => '备份文件不是有效的 zip'];
        }
        try {
            $extracted = $zip->extractTo($jobDir);
        } catch (\Throwable $e) {
            $extracted = false;
        }
        try { @$zip->close(); } catch (\Throwable $e) {}
        if (!$extracted) {
            @self::removeDir($jobDir);
            return ['ok' => false, 'msg' => '解压备份文件失败，请检查 runtime 目录空间与权限'];
        }

        $copy = self::copyTreeDetailed($jobDir, self::baseDir());
        @self::removeDir($jobDir);
        if ($copy['count'] <= 0) {
            return ['ok' => false, 'msg' => '备份中没有可恢复的文件'];
        }
        if ($copy['failed']) {
            return [
                'ok'  => false,
                'msg' => '部分文件恢复失败（' . count($copy['failed']) . ' 个），请检查 backend 写权限，示例：'
                    . implode('、', array_slice($copy['failed'], 0, 5)),
            ];
        }
        $count = $copy['count'];
        self::resetOpcache();
        self::clearRuntimeCache();
        self::appendHistory([
            'time'    => date('Y-m-d H:i:s'),
            'type'    => 'restore',
            'version' => self::CURRENT_VERSION,
            'source'  => 'files',
            'files'   => basename($path),
            'db'      => '',
            'status'  => 'ok',
            'message' => "已恢复 {$count} 个文件",
        ]);
        return ['ok' => true, 'count' => $count, 'msg' => "程序文件已恢复 {$count} 个文件"];
    }

    /** 数据库导入（mysql 客户端） */
    private static function restoreDatabase(string $sqlFile): array
    {
        if (self::commandRunner() === '') {
            return ['ok' => false, 'msg' => '服务器已禁用 exec/shell_exec/proc_open，无法恢复数据库'];
        }
        $bin = self::findMysql();
        if ($bin === '') {
            return ['ok' => false, 'msg' => '未找到 mysql 客户端，无法恢复数据库'];
        }
        $cfg = (array)Config::get('database.connections.mysql');
        $db  = (string)($cfg['database'] ?? '');
        if ($db === '') {
            return ['ok' => false, 'msg' => '数据库配置缺失'];
        }
        // 密码经临时 option 文件传递，避免依赖 putenv / MYSQL_PWD
        self::ensureDirs();
        $cnf = self::upgradeDir() . '/mysql_' . bin2hex(random_bytes(4)) . '.cnf';
        @file_put_contents($cnf, "[client]\npassword=\"" . str_replace('"', '\\"', (string)($cfg['password'] ?? '')) . "\"\n");
        $cmd = escapeshellarg($bin)
            . ' --defaults-extra-file=' . escapeshellarg($cnf)
            . ' --default-character-set=utf8mb4'
            . ' -h' . escapeshellarg((string)($cfg['hostname'] ?? '127.0.0.1'))
            . ' -P' . escapeshellarg((string)($cfg['hostport'] ?? '3306'))
            . ' -u' . escapeshellarg((string)($cfg['username'] ?? 'root'))
            . ' ' . escapeshellarg($db)
            . ' < ' . escapeshellarg($sqlFile) . ' 2>&1';
        $res = self::runShell($cmd);
        @unlink($cnf);
        $detail = trim(implode("\n", array_slice($res['out'], -3)));
        if ((int)$res['code'] !== 0 || preg_match('/\bERROR\s+\d+/i', $detail)) {
            return ['ok' => false, 'msg' => '数据库恢复失败' . ($detail !== '' ? '：' . $detail : '')];
        }
        return ['ok' => true, 'msg' => '数据库已恢复'];
    }

    // ---------------- 历史记录与备份列表 ----------------

    public static function history(): array
    {
        $data = self::readJson(self::historyFile(), []);
        return is_array($data) ? array_reverse($data) : [];
    }

    public static function appendHistory(array $entry): void
    {
        self::ensureDirs();
        $data = self::readJson(self::historyFile(), []);
        if (!is_array($data)) {
            $data = [];
        }
        $data[] = $entry;
        $data = array_slice($data, -200);
        self::writeJson(self::historyFile(), $data);
    }

    public static function listBackups(): array
    {
        self::ensureDirs();
        $out = [];
        foreach (glob(self::backupDir() . '/files_*.zip') ?: [] as $f) {
            $out[] = [
                'type' => 'files',
                'file' => basename($f),
                'size' => (int)filesize($f),
                'time' => date('Y-m-d H:i:s', (int)filemtime($f)),
            ];
        }
        foreach (glob(self::backupDir() . '/db_*.sql') ?: [] as $f) {
            $out[] = [
                'type' => 'db',
                'file' => basename($f),
                'size' => (int)filesize($f),
                'time' => date('Y-m-d H:i:s', (int)filemtime($f)),
            ];
        }
        usort($out, static function (array $a, array $b): int {
            return strcmp((string)$b['file'], (string)$a['file']);
        });
        return $out;
    }

    /**
     * 删除指定备份文件。
     * 仅允许 backup 目录下的 files_*.zip / db_*.sql，避免通过 file 参数删除任意文件。
     */
    public static function deleteBackup(string $file): array
    {
        $file = basename(trim($file));
        if ($file === '' || !preg_match('/^(files|db)_[A-Za-z0-9._-]+\.(zip|sql)$/', $file)) {
            return ['ok' => false, 'msg' => '备份文件名不合法'];
        }
        self::ensureDirs();
        $path = self::backupDir() . '/' . $file;
        if (!is_file($path)) {
            return ['ok' => false, 'msg' => '备份文件不存在或已被删除'];
        }
        if (!@unlink($path)) {
            return ['ok' => false, 'msg' => '删除失败，请检查备份目录写入权限'];
        }
        return ['ok' => true, 'msg' => '备份已删除', 'file' => $file];
    }

    // ---------------- 内部工具 ----------------

    /**
     * Gitee 接口错误提示：私有仓库未配令牌时 Gitee 返回 404（而非 401），
     * 必须明确引导管理员配置令牌，否则很容易误判为仓库名写错。
     */
    private static function giteeHttpError(int $code, bool $hasToken): string
    {
        if ($code === 404) {
            return $hasToken
                ? 'Gitee 返回 HTTP 404：访问令牌无效/已过期，或该令牌无权读取此私有仓库，请检查 [UPGRADE] GITEE_TOKEN'
                : 'Gitee 返回 HTTP 404：仓库不存在或已设为私有。请在 backend/.env 的 [UPGRADE] GITEE_TOKEN 配置 Gitee 私人令牌（需 projects 读权限）后重试';
        }
        if ($code === 401 || $code === 403) {
            return $hasToken
                ? 'Gitee 返回 HTTP ' . $code . '：访问令牌权限不足，请确认 GITEE_TOKEN 有效且可读取该仓库'
                : 'Gitee 返回 HTTP ' . $code . '：仓库为私有或访问受限，请配置 [UPGRADE] GITEE_TOKEN';
        }
        return 'Gitee 返回异常（HTTP ' . ($code ?: '?') . '），请检查仓库名/网络';
    }

    private static function httpGet(string $url, int $timeout): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_USERAGENT      => 'CertAgent-Upgrade/' . self::CURRENT_VERSION,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);
            $body = curl_exec($ch);
            $err  = curl_error($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body === false) {
                return ['ok' => false, 'msg' => $err !== '' ? $err : 'HTTP ' . $code];
            }
            return ['ok' => true, 'body' => (string)$body, 'code' => $code];
        }
        $ctx = stream_context_create(['http' => [
            'timeout'       => $timeout,
            'ignore_errors' => true,
            'user_agent'    => 'CertAgent-Upgrade/' . self::CURRENT_VERSION,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            return ['ok' => false, 'msg' => 'file_get_contents 请求失败'];
        }
        $code = 200;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
                $code = (int)$m[1];
            }
        }
        return ['ok' => true, 'body' => $body, 'code' => $code];
    }

    private static function readJson(string $file, $default)
    {
        if (!is_file($file)) {
            return $default;
        }
        $data = json_decode((string)file_get_contents($file), true);
        return is_array($data) ? $data : $default;
    }

    private static function writeJson(string $file, array $data): void
    {
        @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            if ($f->isDir()) {
                @rmdir($f->getPathname());
            } else {
                @unlink($f->getPathname());
            }
        }
        @rmdir($dir);
    }

    private static function findMysqldump(): string
    {
        static $bin = null;
        if ($bin !== null) {
            return $bin;
        }
        return $bin = self::locateBinary('mysqldump', [
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
            '/www/server/mysql/bin/mysqldump',
            'D:/phpstudy_pro/Extensions/MySQL5.7.26/bin/mysqldump.exe',
            'C:/phpstudy_pro/Extensions/MySQL5.7.26/bin/mysqldump.exe',
        ]);
    }

    private static function findMysql(): string
    {
        static $bin = null;
        if ($bin !== null) {
            return $bin;
        }
        return $bin = self::locateBinary('mysql', [
            '/usr/bin/mysql',
            '/usr/local/bin/mysql',
            '/www/server/mysql/bin/mysql',
            'D:/phpstudy_pro/Extensions/MySQL5.7.26/bin/mysql.exe',
            'C:/phpstudy_pro/Extensions/MySQL5.7.26/bin/mysql.exe',
        ]);
    }

    /** 定位可执行文件：环境变量覆盖 > PATH 查找 > 常见绝对路径 */
    private static function locateBinary(string $name, array $fallbackPaths): string
    {
        $envKey = 'upgrade.' . $name;
        $envVal = trim((string)env($envKey, ''));
        if ($envVal !== '' && self::isExecutableFile($envVal)) {
            return $envVal;
        }
        $found = self::findInPath($name);
        if ($found !== '') {
            return $found;
        }
        foreach ($fallbackPaths as $p) {
            if (self::isExecutableFile($p)) {
                return $p;
            }
        }
        return '';
    }

    private static function findInPath(string $name): string
    {
        if (self::commandRunner() === '') {
            return '';
        }
        $cmd = PHP_OS_FAMILY === 'Windows'
            ? 'where ' . escapeshellarg($name) . ' 2>nul'
            : 'command -v ' . escapeshellarg($name) . ' 2>/dev/null';
        $res = self::runShell($cmd);
        foreach ($res['out'] as $line) {
            $path = trim((string)$line);
            if ($path !== '' && self::isExecutableFile($path)) {
                return $path;
            }
        }
        return '';
    }

    /** 放宽执行时间限制（部分主机禁用 set_time_limit） */
    private static function raiseTimeLimit(): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
    }

    /**
     * 可用的命令执行器：exec / proc_open / shell_exec。
     * 共享主机常把 exec 放进 disable_functions，此时 function_exists('exec')
     * 为 false，直接调用会抛 “Call to undefined function exec()”，必须提前探测。
     */
    private static function commandRunner(): string
    {
        foreach (['exec', 'proc_open', 'shell_exec'] as $fn) {
            if (function_exists($fn)) {
                return $fn;
            }
        }
        return '';
    }

    /**
     * 执行 shell 命令，返回 ['code' => int, 'out' => string[]]。
     * 三种执行器都兼容，命令内的重定向（> / < / 2>&1）由 shell 处理。
     * shell_exec 无法可靠拿到退出码时返回 code=0，调用方需结合输出/产物判断。
     */
    private static function runShell(string $cmd): array
    {
        // 1) exec：直接拿到退出码与输出
        if (function_exists('exec')) {
            $out = [];
            $code = -1;
            @exec($cmd, $out, $code);
            return ['code' => (int)$code, 'out' => is_array($out) ? $out : []];
        }
        // 2) proc_open：管道捕获输出，proc_close 拿退出码
        if (function_exists('proc_open')) {
            $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = @proc_open($cmd, $descriptors, $pipes);
            if (!is_resource($proc)) {
                return ['code' => -1, 'out' => []];
            }
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = (int)@proc_close($proc);
            $lines = [];
            foreach ([$stdout, $stderr] as $s) {
                if (is_string($s) && trim($s) !== '') {
                    foreach (preg_split('/\r?\n/', trim($s)) as $line) {
                        $lines[] = $line;
                    }
                }
            }
            return ['code' => $code, 'out' => $lines];
        }
        // 3) shell_exec：只有输出，无法可靠拿退出码
        if (function_exists('shell_exec')) {
            $raw = @shell_exec($cmd);
            $lines = [];
            if (is_string($raw) && trim($raw) !== '') {
                $lines = preg_split('/\r?\n/', trim($raw));
            }
            return ['code' => is_string($raw) ? 0 : -1, 'out' => $lines];
        }
        return ['code' => -1, 'out' => []];
    }

    private static function isExecutableFile(string $path): bool
    {
        $path = trim($path);
        return $path !== '' && is_file($path);
    }
}