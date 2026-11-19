<?php
namespace app\service;

use think\facade\Db;

/**
 * 全局回收站服务（v2.52）
 *
 * 各业务模块删除时把「主行快照 + 随删除一并清理的关联子行」写入 recycle_bin；
 * 回收站页面可恢复（原 ID 原样回插，孤儿子行自动重连）或彻底清除快照。
 *
 * 设计要点：
 *   - 快照在删除动作**之前**落库，删除路径异常不阻断业务（stash 失败仅记日志）；
 *   - 恢复按 module 映射回插原表，同 ID 已被占用（如后来重建了同号记录）则拒绝恢复；
 *   - 不覆盖系统配置类数据（菜单/角色/账号/字典等），只覆盖业务数据模块。
 *
 * ⚠️ 自身不写事务：主行与子行的完整回插由回收站控制器统一包事务。
 */
class RecycleBinService
{
    /**
     * 支持的模块：module => [原表名, 中文名]
     */
    public const MODULES = [
        'certificate'            => ['certificate',            '证照台账'],
        'staff'                  => ['staff',                  '持证人员'],
        'company'                => ['client_company',         '客户企业'],
        'contract'               => ['contract',               '服务合同'],
        'fee'                    => ['fee',                    '费用明细'],
        'project'                => ['project',                '项目管理'],
        'secondment'             => ['cert_secondment',        '证书借调'],
        'cert_task'              => ['cert_task',              '配证任务'],
        'register_track'         => ['cert_register_track',    '注册轨迹'],
        'pay_account'            => ['pay_account',            '收款账户'],
        'receiver'               => ['company_receiver',       '企业接收人'],
        'msg_template'           => ['msg_template',           '消息模板'],
        'company_qualification'  => ['company_qualification',  '企业资质'],
        'gov_progress'           => ['gov_progress',           '政务网申报进度'],
        'credential_vault'       => ['credential_vault',       '平台账号密码库'],
    ];

    private static bool $tableChecked = false;

    /** 建 recycle_bin 表（首次写入时自愈，老库无需手工执行） */
    public static function ensureTable(): void
    {
        if (self::$tableChecked) {
            return;
        }
        Db::execute("CREATE TABLE IF NOT EXISTS `recycle_bin` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `module` varchar(32) NOT NULL COMMENT '模块 code（MODULES）',
            `origin_id` int unsigned NOT NULL DEFAULT 0 COMMENT '原表主键',
            `title` varchar(255) NOT NULL DEFAULT '' COMMENT '展示名（企业名/证书名等）',
            `data` mediumtext NOT NULL COMMENT '主行完整快照 JSON',
            `related` mediumtext NULL COMMENT '关联子行快照 JSON（原样回插）',
            `deleted_by` int unsigned NOT NULL DEFAULT 0,
            `deleted_at` datetime NOT NULL,
            `restored_at` datetime NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_module` (`module`, `origin_id`),
            KEY `idx_deleted_at` (`deleted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='全局回收站（v2.52）'");
        self::$tableChecked = true;
    }

    /**
     * 删除动作前调用：快照主行 + 已会被级联清理的关联子行。
     *
     * @param string $module  self::MODULES 键
     * @param string $title   展示名（如企业全称 / 证书编号）
     * @param array  $main    主行完整数据（原 id / create_time 一并保留）
     * @param array  $related [['table' => 表名, 'rows' => [行...]], ...] 删除路径会连带删掉的子表数据
     */
    public static function stash(int $userId, string $module, string $title, array $main, array $related = []): void
    {
        if (!isset(self::MODULES[$module]) || $main === []) {
            return;
        }
        self::ensureTable();
        Db::name('recycle_bin')->insert([
            'module'     => $module,
            'origin_id'  => (int)($main['id'] ?? 0),
            'title'      => mb_substr(trim($title), 0, 255),
            'data'       => json_encode($main, JSON_UNESCAPED_UNICODE),
            'related'    => $related ? json_encode($related, JSON_UNESCAPED_UNICODE) : null,
            'deleted_by' => $userId,
            'deleted_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 便捷：取一段随删除会被清理的子表行（related[].rows 用）
     */
    public static function grab(string $table, string $col, int $id): array
    {
        if ($id <= 0) {
            return [];
        }
        try {
            return Db::name($table)->where($col, $id)->select()->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 便捷：自定义字段值快照（cert_custom_value 按模块+记录）
     * @return array related 格式的单条目
     */
    public static function grabCustom(string $module, int $recordId): array
    {
        if ($recordId <= 0) {
            return [];
        }
        try {
            $rows = Db::name('cert_custom_value')->where('module', $module)->where('record_id', $recordId)->select()->toArray();
        } catch (\Throwable $e) {
            return [];
        }
        return $rows ? [['table' => 'cert_custom_value', 'rows' => $rows]] : [];
    }

    /** 恢复：主行原 id 回插 + 顺序回插关联子行；同 id 已占用则报错 */
    public static function restore(int $binId): array
    {
        self::ensureTable();
        $bin = Db::name('recycle_bin')->where('id', $binId)->find();
        if (!$bin) {
            return ['ok' => false, 'msg' => '回收记录不存在'];
        }
        if (!empty($bin['restored_at'])) {
            return ['ok' => false, 'msg' => '该记录已恢复过，请刷新列表'];
        }
        $module = (string)$bin['module'];
        $table = self::MODULES[$module][0] ?? '';
        if ($table === '') {
            return ['ok' => false, 'msg' => '未知模块，无法恢复'];
        }
        $main = json_decode((string)$bin['data'], true);
        if (!is_array($main) || empty($main['id'])) {
            return ['ok' => false, 'msg' => '快照数据损坏，无法恢复'];
        }
        $originId = (int)$main['id'];
        $originName = self::MODULES[$module][1];
        $exists = Db::name($table)->where('id', $originId)->count();
        if ($exists > 0) {
            return ['ok' => false, 'msg' => "{$originName}原表已存在 ID={$originId} 的记录（可能已重新录入），无法恢复"];
        }

        Db::startTrans();
        try {
            Db::name($table)->strict(false)->insert($main);
            $rel = json_decode((string)$bin['related'], true);
            if (is_array($rel)) {
                foreach ($rel as $r) {
                    $rtable = (string)($r['table'] ?? '');
                    $rows = is_array($r['rows'] ?? null) ? $r['rows'] : [];
                    if ($rtable === '' || !$rows) {
                        continue;
                    }
                    foreach ($rows as $row) {
                        if (!is_array($row) || !isset($row['id'])) {
                            continue;
                        }
                        // 子行 id 冲突（可能已重建）则跳过该子行，主数据不受影响
                        try {
                            Db::name($rtable)->strict(false)->insert($row);
                        } catch (\Throwable $e) {
                            // 忽略冲突子行
                        }
                    }
                }
            }
            Db::name('recycle_bin')->where('id', $binId)->update(['restored_at' => date('Y-m-d H:i:s')]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return ['ok' => false, 'msg' => '恢复失败：' . $e->getMessage()];
        }
        return ['ok' => true, 'msg' => '已恢复'];
    }

    /** 彻底清除一条快照 */
    public static function purge(int $binId): void
    {
        self::ensureTable();
        Db::name('recycle_bin')->where('id', $binId)->delete();
    }

    /** 清空回收站（快照即可删，不影响任何原表） */
    public static function clearAll(): void
    {
        self::ensureTable();
        Db::name('recycle_bin')->where('1=1')->delete();
    }
}
