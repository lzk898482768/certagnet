<?php
namespace app\service;

use app\model\CertStatusLog as CertStatusLogModel;

/**
 * 状态变更日志（v2.76）
 *
 * 背景：`cert_status_log` 原只记录「证书状态」变化（0 过期 / 1 正常 / 2 挂失 / 3 注销），
 * 借调记录的「借调中 → 已到期 → 续借恢复借调中」同样属于业务状态变更，
 * 但此前只写了 operation_log（操作日志），在「状态变更日志」页面看不到，
 * 用户续借后回溯不到状态变化。
 *
 * v2.76 起：
 *   - 新增 biz_type（cert 证书 / secondment 借调）与 secondment_id 两列；
 *   - 证书状态变化写 biz_type=cert，保持原有 semantics；
 *   - 借调状态变化写 biz_type=secondment，old/new_status 使用借调状态码
 *     （0 已归还 / 1 借调中 / 2 已到期）；
 *   - 两类记录共用一张表、一个列表页，按业务类型筛选。
 */
class CertStatusLogService
{
    public const BIZ_CERT = 'cert';
    public const BIZ_SECONDMENT = 'secondment';

    /** 进程内结构自愈只做一次 */
    protected static $schemaReady = false;

    /** 借调状态文本 */
    public static function secondmentStatusText(int $status): string
    {
        return [0 => '已归还', 1 => '借调中', 2 => '已到期'][$status] ?? '未知';
    }

    /**
     * 结构自愈：老库补 biz_type / secondment_id（幂等）
     */
    public static function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }
        try {
            ensure_column(
                'cert_status_log',
                'biz_type',
                "varchar(16) NOT NULL DEFAULT 'cert' COMMENT '业务类型: cert证书 / secondment借调' AFTER `cert_id`"
            );
            ensure_column(
                'cert_status_log',
                'secondment_id',
                "int unsigned NOT NULL DEFAULT 0 COMMENT '关联借调记录ID（biz_type=secondment 时有效）' AFTER `biz_type`"
            );
        } catch (\Throwable $e) {
            // 自愈失败不阻断业务写入，下次请求重试
        }
        self::$schemaReady = true;
    }

    /**
     * 写一条借调状态变更日志
     *
     * @param int    $secondmentId 借调记录 ID
     * @param int    $certId       关联证书 ID（非 0 才能被证书维度的数据权限覆盖）
     * @param int    $oldStatus    变更前借调状态
     * @param int    $newStatus    变更后借调状态
     * @param string $operator     操作人
     * @param string $remark       变更说明（原到期日 → 新到期日、归还原因等）
     */
    public static function secondment(
        int $secondmentId,
        int $certId,
        int $oldStatus,
        int $newStatus,
        string $operator = '',
        string $remark = ''
    ): void {
        self::ensureSchema();
        try {
            CertStatusLogModel::create([
                'cert_id'       => $certId,
                'biz_type'      => self::BIZ_SECONDMENT,
                'secondment_id' => $secondmentId,
                'old_status'    => $oldStatus,
                'new_status'    => $newStatus,
                'operator'      => $operator,
                'remark'        => $remark,
            ]);
        } catch (\Throwable $e) {
            // 日志失败不影响主流程（借调/续借本身已成功）
        }
    }

    /**
     * 写一条证书状态变更日志（保留旧调用口径，供后续统一迁移使用）
     */
    public static function cert(int $certId, int $oldStatus, int $newStatus, string $operator = '', string $remark = ''): void
    {
        self::ensureSchema();
        CertStatusLogModel::create([
            'cert_id'    => $certId,
            'biz_type'   => self::BIZ_CERT,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'operator'   => $operator,
            'remark'     => $remark,
        ]);
    }
}
