<?php
namespace app\controller\admin;

use app\service\ImportService;
use app\service\ExcelService;
use app\service\GovProgressService;
use app\service\CredentialVaultService;

/**
 * 统一导入（预览 -> 确认入库）
 * 流程：前端上传文件调 preview 拿到逐行预览 -> 用户勾选确认 -> 调 commit 入库。
 * 与旧的三处直接 import 相比，避免了「人员/企业不存在整行被静默跳过」的隐患。
 */
class Import extends AdminController
{
    private const TYPES = ['certificate', 'staff', 'company', 'project', 'policy_rule', 'gov_progress', 'credential_vault'];

    /** 校验导入类型；非法返回 null（调用方据此返回 400） */
    private function checkType(): ?string
    {
        $type = $this->request->param('type', '');
        return in_array($type, self::TYPES, true) ? $type : null;
    }

    /** 预览：解析 Excel 并逐行判定存在性（不入库） */
    public function preview()
    {
        $type = $this->checkType();
        if ($type === null) {
            return json(r_error('未知的导入类型', 400));
        }
        $file = $this->request->file('file');
        if (!$file) {
            return json(r_error('请上传文件', 400));
        }
        // v2.53：扩展名白名单 + 大小上限
        $ext = strtolower((string)$file->getOriginalExtension());
        if (!in_array($ext, ['xlsx', 'xls'], true)) {
            return json(r_error('仅支持 .xlsx / .xls 格式的 Excel 文件', 400));
        }
        if ((int)$file->getSize() > 10 * 1024 * 1024) {
            return json(r_error('文件大小不能超过 10MB', 400));
        }
        $info = $file->move(app()->getRuntimePath() . 'upload/');
        if (!$info) {
            return json(r_error('文件上传失败', 400));
        }
        $autoCreate = (bool)$this->request->param('auto_create', 1);
        try {
            if ($type === 'gov_progress') {
                GovProgressService::ensureTables();
            }
            if ($type === 'credential_vault') {
                CredentialVaultService::ensureTables();
            }
            $rows = ImportService::preview($type, $info->getPathname(), $autoCreate);
        } catch (\Throwable $e) {
            @unlink($info->getPathname());
            return json(r_error('解析失败：' . $e->getMessage(), 400));
        }
        @unlink($info->getPathname());
        // v2.99.52：损坏/伪造的 Excel 可能被解析成 0 行，此前仍提示「解析完成」，
        // 用户以为可以导入。这里明确拒绝，避免空文件静默通过预览环节。
        if (!$rows) {
            return json(r_error('未解析到任何数据，请确认使用系统模板且已填写内容', 400));
        }
        return json(r_success([
            'type'        => $type,
            'rows'        => $rows,
            'auto_create' => $autoCreate,
        ], '解析完成，请确认预览'));
    }

    /** 提交：按预览结果入库（接收前端勾选的行，不再二次传文件） */
    public function commit()
    {
        $GLOBALS['__import_user_id'] = (int)$this->request->userId;
        \app\service\CrmService::ensureSchema(); // v2.54：导入新建人员/企业/证书默认负责人
        $type = $this->checkType();
        if ($type === null) {
            return json(r_error('未知的导入类型', 400));
        }
        // v2.69：导入权限统一走「模块:import」，与 PermAuth 的接口守卫同源
        $permMap = [
            'certificate'     => ['cert', 'import'],
            'staff'           => ['staff', 'import'],
            'company'         => ['company', 'import'],
            'project'         => ['project', 'import'],
            'policy_rule'     => ['policy', 'import'],
            'gov_progress'    => ['gov', 'import'],
            'credential_vault' => ['vault', 'import'],
        ];
        [$mod, $act] = $permMap[$type];
        if (!$this->can($mod, $act)) {
            $label = ['certificate' => '证照', 'staff' => '人员', 'company' => '企业', 'project' => '项目', 'policy_rule' => '政策规则', 'gov_progress' => '申报进度', 'credential_vault' => '账号密码'][$type] ?? $type;
            return json(r_error("暂无导入{$label}权限，请联系管理员", 403));
        }
        $rows = $this->request->post('rows/a', []);
        if (!is_array($rows) || !$rows) {
            return json(r_error('没有可导入的数据', 400));
        }
        $autoCreate = (bool)$this->request->param('auto_create', 1);
        if ($type === 'gov_progress') {
            GovProgressService::ensureTables();
        }
        if ($type === 'credential_vault') {
            CredentialVaultService::ensureTables();
        }
        $res = ImportService::commit($type, $rows, $autoCreate);
        $label = ['certificate' => '证照', 'staff' => '人员', 'company' => '企业', 'project' => '项目', 'policy_rule' => '政策规则', 'gov_progress' => '申报进度', 'credential_vault' => '账号密码'][$type] ?? $type;
        $this->log($label . '导入', '预览确认入库', "新增{$res['ok']}条/新建人员{$res['created_staff']}/新建企业{$res['created_company']}");
        $tip = "成功导入 {$res['ok']} 条";
        if ($res['created_staff'])   $tip .= "，自动建档人员 {$res['created_staff']} 人";
        if ($res['created_company']) $tip .= "，自动建档企业 {$res['created_company']} 家";
        return json(r_success($res, $tip));
    }

    /**
     * v2.55：导出导入失败名单（POST 收失败行数组，生成 Excel 下载）
     * 前端在 commit 返回 failed_rows 后调用，逐行标注失败原因，便于修正后重新导入。
     */
    public function failExport()
    {
        $type = $this->checkType();
        if ($type === null) {
            return json(r_error('未知的导入类型', 400));
        }
        $rows = $this->request->post('rows/a', []);
        if (!is_array($rows) || !$rows) {
            return json(r_error('没有可导出的失败记录', 400));
        }
        try {
            $path = ExcelService::exportImportFail($type, $rows);
        } catch (\Throwable $e) {
            return json(r_error('生成失败名单失败：' . $e->getMessage(), 400));
        }
        return download_utf8($path, '导入失败名单_' . date('YmdHis') . '.xlsx');
    }

    /**
     * v2.72：导出导入「识别记录」。
     *
     * 与失败名单不同：识别记录覆盖预览阶段每一行，标注识别结果
     * （新增/更新/跳过）与说明，便于整批核对与留存；密码列统一脱敏。
     * 权限与统一导入一致（模块:import），由 PermAuth 按 type 归属。
     */
    public function recognizeExport()
    {
        $type = $this->checkType();
        if ($type === null) {
            return json(r_error('未知的导入类型', 400));
        }
        $rows = $this->request->post('rows/a', []);
        if (!is_array($rows) || !$rows) {
            return json(r_error('没有可导出的识别记录', 400));
        }
        try {
            $path = ExcelService::exportImportRecognition($type, $rows);
        } catch (\Throwable $e) {
            return json(r_error('生成识别记录失败：' . $e->getMessage(), 400));
        }
        return download_utf8($path, '导入识别记录_' . date('YmdHis') . '.xlsx');
    }
}
