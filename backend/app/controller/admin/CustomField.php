<?php
namespace app\controller\admin;

use app\model\CustomField as CustomFieldModel;
use app\service\CustomFieldService;

/**
 * 自定义字段定义管理（各业务模块可扩展字段）
 */
class CustomField extends AdminController
{
    // 模块与类型枚举，供前端下拉使用
    public function meta()
    {
        return json(r_success([
            'modules' => CustomFieldService::MODULES,
            'types'   => CustomFieldService::TYPES,
        ]));
    }

    // 按模块取字段定义（业务表单渲染用）
    public function byModule()
    {
        $module = (string)$this->request->param('module', '');
        if ($module === '') {
            return json(r_error('缺少模块', 400));
        }
        return json(r_success(CustomFieldService::fieldsOf($module)));
    }

    public function index()
    {
        $module = (string)$this->request->param('module', '');
        $q = CustomFieldModel::order('sort', 'asc')->order('id', 'asc');
        if ($module !== '') {
            $q->where('module', $module);
        }
        $list = $q->select()->toArray();
        return json(r_success($list));
    }

    public function save()
    {
        $this->requireAdmin();
        $id = (int)$this->request->post('id', 0);
        $data = $this->request->post([
            'module', 'field_key', 'field_label', 'field_type', 'options',
            'default_value', 'required', 'sort', 'status', 'remark',
        ]);

        $module = (string)($data['module'] ?? '');
        $key    = trim((string)($data['field_key'] ?? ''));
        $label  = trim((string)($data['field_label'] ?? ''));

        if (!array_key_exists($module, CustomFieldService::MODULES)) {
            return json(r_error('模块不合法', 400));
        }
        if ($key === '') {
            return json(r_error('字段标识必填', 400));
        }
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
            return json(r_error('字段标识只能包含字母、数字、下划线，且不能以数字开头', 400));
        }
        if ($label === '') {
            return json(r_error('显示名称必填', 400));
        }
        $type = (string)($data['field_type'] ?? 'text');
        if (!array_key_exists($type, CustomFieldService::TYPES)) {
            return json(r_error('字段类型不合法', 400));
        }
        // 同模块内标识唯一
        $dup = CustomFieldModel::where('module', $module)->where('field_key', $key);
        if ($id) {
            $dup->where('id', '<>', $id);
        }
        if ($dup->count()) {
            return json(r_error('该模块下字段标识已存在', 400));
        }

        $data['field_key']   = $key;
        $data['field_label'] = $label;
        $data['required']    = (int)($data['required'] ?? 0) ? 1 : 0;
        $data['status']      = isset($data['status']) ? ((int)$data['status'] ? 1 : 0) : 1;
        $data['sort']        = (int)($data['sort'] ?? 0);

        if ($id) {
            CustomFieldModel::where('id', $id)->update($data);
            $this->log('自定义字段', '更新', "#{$id} {$label}");
        } else {
            $model = CustomFieldModel::create($data);
            $id    = (int)$model->id;
            $this->log('自定义字段', '新增', "{$module}.{$key}");
        }
        CustomFieldService::clearCache($module);
        return json(r_success(['id' => $id], '保存成功'));
    }

    /** 快捷启用 / 停用 */
    public function status()
    {
        $this->requireAdmin();
        $id     = (int)$this->request->post('id', 0);
        $status = (int)$this->request->post('status', 1) ? 1 : 0;
        if (!$id) {
            return json(r_error('缺少ID', 400));
        }
        $row = CustomFieldModel::find($id);
        if (!$row) {
            return json(r_error('字段不存在', 404));
        }
        $row->status = $status;
        $row->save();
        CustomFieldService::clearCache((string)$row->module);
        $this->log('自定义字段', $status ? '启用' : '停用', "#{$id} {$row->field_label}");
        return json(r_success(['id' => $id, 'status' => $status], $status ? '已启用' : '已停用'));
    }

    public function delete()
    {
        $this->requireAdmin();
        $id = (int)$this->request->post('id', 0);
        if (!$id) {
            return json(r_error('缺少ID', 400));
        }
        $row = CustomFieldModel::find($id);
        if (!$row) {
            return json(r_error('字段不存在', 404));
        }
        $module = $row->module;
        $row->delete();
        CustomFieldService::deleteFieldValues($id);
        CustomFieldService::clearCache($module);
        $this->log('自定义字段', '删除', "#{$id}");
        return json(r_success([], '删除成功'));
    }
}
