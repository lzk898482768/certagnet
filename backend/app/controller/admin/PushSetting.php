<?php
namespace app\controller\admin;

use app\service\PushSceneService;

/**
 * 推送规则设置（v2.99.40）
 *
 * 目前承载「申报进度推送范围」：
 *  - all：平台出现新环节即推送（默认，与升级前一致）
 *  - key：只在受理 / 办结 / 挂起 / 退件时推送
 *
 * 无论哪种范围，「环节没有更新」都不再推送（见 GovProgressService::changeEvents）。
 */
class PushSetting extends AdminController
{
    /** 读取推送规则（菜单 6 或 15 均可，只读） */
    public function gov()
    {
        return json(r_success([
            'scope'   => PushSceneService::govScope(),
            'options' => PushSceneService::govScopeOptions(),
            'scenes'  => PushSceneService::scenes(),
        ]));
    }

    /** 保存申报进度推送范围（功能码 push:edit） */
    public function govSave()
    {
        $scope = trim((string)$this->request->post('scope', ''));
        if (!PushSceneService::saveGovScope($scope)) {
            return json(r_error('推送范围参数不合法（all / key）', 400));
        }
        $this->log('推送规则', '更新', '申报进度推送范围=' . $scope);
        return json(r_success(['scope' => $scope], '已保存'));
    }
}
