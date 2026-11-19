<?php
namespace app\controller\admin;

use app\model\DictType as DictTypeModel;
use app\model\DictData;
use app\service\DictCatalog;

class DictType extends AdminController
{
    public function index()
    {
        // v2.99：字典管理页即自愈入口 —— 老库只 git pull 不跑 upgrade SQL，
        // 打开字典页时幂等补种新增模块字典（学历报名等），保证管理页可见可维护。
        try {
            DictCatalog::ensure();
        } catch (\Throwable $e) {
            // 字典表缺失等极端情况静默，列表按现状返回
        }
        $module = $this->request->param('module', '');
        $q = DictTypeModel::order('id', 'asc');
        if ($module) {
            $q->where('module', $module);
        }
        $list = $q->select()->toArray();
        return json(r_success($list));
    }

    public function save()
    {
        $this->requireAdmin();
        $id = (int)$this->request->post('id', 0);
        $data = $this->request->post(['type_code', 'type_name', 'module', 'remark', 'status']);
        if (empty($data['type_code']) || empty($data['type_name'])) {
            return json(r_error('类型编码和名称必填', 400));
        }
        $data['type_code'] = trim((string)$data['type_code']);

        // v2.99.1：type_code 存在唯一索引 uk_code，保存前查重，避免 1062 变 500
        $dupMsg = \app\common\UniqueGuard::check([
            'type_code' => [DictTypeModel::class, $data['type_code'], '字典编码'],
        ], $id);
        if ($dupMsg !== '') {
            return json(r_error($dupMsg, 400));
        }

        try {
            if ($id) {
                DictTypeModel::where('id', $id)->update($data);
                $this->log('字典类型', '更新', "#{$id}");
            } else {
                DictTypeModel::create($data);
                $this->log('字典类型', '新增');
            }
        } catch (\Throwable $e) {
            $dupMsg = \app\common\UniqueGuard::friendly($e, '字典编码已存在');
            if ($dupMsg !== '') {
                return json(r_error($dupMsg, 400));
            }
            throw $e;
        }
        return json(r_success([], '保存成功'));
    }

    public function delete()
    {
        $this->requireAdmin();
        $id = (int)$this->request->post('id', 0);
        $type = DictTypeModel::find($id);
        if ($type && DictData::where('type_code', $type->type_code)->find()) {
            return json(r_error('请先删除该类型下的字典项', 400));
        }
        DictTypeModel::destroy($id);
        $this->log('字典类型', '删除', "#{$id}");
        return json(r_success([], '删除成功'));
    }
}
