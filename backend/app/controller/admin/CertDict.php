<?php
namespace app\controller\admin;

use app\controller\admin\AdminController;
use app\service\CertDictService;

/**
 * 证书类型 / 证书专业 二级字典（v2.33：单 cert_major type_code，一级=类型，二级=专业）
 *
 * 供前端「证书专业」级联选择与快速新建使用。
 * 与字典管理页（DictData）并存：本控制器只暴露证书专业相关的读写，
 * 免字典管理权限，让业务录入可顺手新建专业。
 */
class CertDict extends AdminController
{
    /** 级联数据：一次返回 types + majors，前端本地过滤 */
    public function cascade()
    {
        try {
            return json(r_success(CertDictService::cascade()));
        } catch (\Throwable $e) {
            return json(r_success(['types' => [], 'majors' => []]));
        }
    }

    /** 按证书类型取专业（可选，级联数据已足够时前端可不用） */
    public function majors()
    {
        $type = trim((string)$this->request->param('cert_type', ''));
        return json(r_success(CertDictService::majors($type)));
    }

    /** 快速新建证书专业（同一父级下去重） */
    public function quickCreate()
    {
        $label  = trim((string)$this->request->post('label', ''));
        $parent = trim((string)$this->request->post('parent_value', ''));
        if ($label === '') {
            return json(r_error('请输入专业名称', 400));
        }
        try {
            $r = CertDictService::quickCreate($label, $parent, (int)$this->request->userId);
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage(), 400));
        }
        $this->log('证书专业', $r['duplicated'] ? '复用' : '新建', $label . ($parent !== '' ? "（{$parent}）" : ''));
        // PRD P0-15：跨类型同名 → 提示（不拒绝，因一建/二建本就合法同名）
        $msg = $r['duplicated']
            ? '该专业已存在，已为您选中'
            : (!empty($r['conflict_parents'])
                ? '已新增；提示：同名专业已在「' . implode('、', $r['conflict_parents']) . '」下存在'
                : '已新建专业');
        return json(r_success($r, $msg));
    }
}
