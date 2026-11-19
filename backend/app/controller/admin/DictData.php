<?php
namespace app\controller\admin;

use app\model\DictData as DictDataModel;
use app\service\CertDictService;
use think\facade\Db;

class DictData extends AdminController
{
    public function index()
    {
        $type = $this->request->param('type_code', '');
        $q = DictDataModel::order('sort', 'asc');
        if ($type) {
            $q->where('type_code', $type);
        }
        $list = $q->select()->toArray();
        return json(r_success($list));
    }

    // 按类型返回（前端下拉用）
    public function byType()
    {
        $type = $this->request->param('type_code', '');
        if (!$type) {
            return json(r_error('缺少类型', 400));
        }
        $list = DictDataModel::where('type_code', $type)->where('status', 1)->order('sort', 'asc')->select()->toArray();
        return json(r_success($list));
    }

    public function save()
    {
        $this->requireAdmin();
        $id = (int)$this->request->post('id', 0);
        $data = $this->request->post(['type_code', 'dict_label', 'dict_value', 'parent_value', 'sort', 'status', 'remark']);
        // parent_value 为空时归零，避免 NULL 破坏唯一性判断
        if (isset($data['parent_value'])) {
            $data['parent_value'] = trim((string)$data['parent_value']);
        }
        $data['dict_label'] = trim((string)($data['dict_label'] ?? ''));
        $data['dict_value'] = trim((string)($data['dict_value'] ?? ''));
        if (empty($data['type_code']) || $data['dict_label'] === '' || $data['dict_value'] === '') {
            return json(r_error('类型/名称/值必填', 400));
        }
        $typeCode = (string)$data['type_code'];
        $isCert   = $typeCode === CertDictService::MAJOR_CODE;
        $parent   = $isCert ? (string)($data['parent_value'] ?? '') : '';
        $old      = $id ? Db::name('dict_data')->where('id', $id)->find() : null;

        if ($isCert) {
            // 1) 专业必须挂在真实存在的证书类型下（一级自身 parent_value 为空）
            if ($parent !== '' && !CertDictService::isValidType($parent)) {
                return json(r_error('所属证书类型不存在，请刷新页面后重试', 400));
            }
            // 2) 同一层级去重：同名（规范化比较）不允许重复建，避免下拉出现重复项
            $siblings = Db::name('dict_data')
                ->where('type_code', CertDictService::MAJOR_CODE)
                ->where('parent_value', $parent)
                ->where('id', '<>', $id)
                ->field('id,dict_label')
                ->select()->toArray();
            foreach ($siblings as $row) {
                if (CertDictService::normalize((string)$row['dict_label']) === CertDictService::normalize($data['dict_label'])) {
                    $scope = $parent === '' ? '证书类型' : "「{$parent}」下的证书专业";
                    return json(r_error("{$scope}「{$row['dict_label']}」已存在，请勿重复添加", 400));
                }
            }
            // 3) 一级改名时，新值不能与其他类型撞车（会破坏二级归属）
            if ($parent === '' && $old && (string)$old['dict_value'] !== $data['dict_value']) {
                $conflict = Db::name('dict_data')
                    ->where('type_code', CertDictService::MAJOR_CODE)
                    ->where('parent_value', '')
                    ->where('dict_value', $data['dict_value'])
                    ->where('id', '<>', $id)
                    ->find();
                if ($conflict) {
                    return json(r_error("证书类型值「{$data['dict_value']}」已被其他类型占用", 400));
                }
            }
        }

        if ($id) {
            Db::transaction(function () use ($id, $data, $isCert, $old) {
                DictDataModel::where('id', $id)->update($data);
                if (!$isCert || !$old) {
                    return;
                }
                $oldParent = trim((string)($old['parent_value'] ?? ''));
                $newParent = trim((string)($data['parent_value'] ?? ''));
                $oldValue  = trim((string)($old['dict_value'] ?? ''));
                $newValue  = trim((string)($data['dict_value'] ?? ''));
                // 一级（类型）改名 → 同步其下专业的 parent_value 与业务表 cert_type/category
                if ($oldParent === '' && $oldValue !== '' && $oldValue !== $newValue) {
                    CertDictService::renameTypeValue($oldValue, $newValue);
                }
                // 二级（专业）改名 → 同步业务表 cert_name，避免历史证照显示旧名
                if ($oldParent !== '' && $oldValue !== '' && $oldValue !== $newValue) {
                    CertDictService::renameMajorValue($oldValue, $newValue);
                }
                // 专业改归属类型：仅把「引用该专业」的业务行同步到新类型
                if ($oldParent !== '' && $newParent !== '' && $oldParent !== $newParent) {
                    CertDictService::moveMajorToType($oldValue, $newParent);
                }
            });
            $this->log('字典项', '更新', "#{$id}");
        } else {
            DictDataModel::create($data);
            $this->log('字典项', '新增');
        }
        // v2.38：证书类型/专业字典变动后立即失效级联缓存
        if ($isCert) {
            CertDictService::flushCache();
        }
        return json(r_success([], '保存成功'));
    }

    public function delete()
    {
        $this->requireAdmin();
        $id = (int)$this->request->post('id', 0);
        // v2.38：先取 type_code，删后好判断要不要清证书字典缓存
        $row      = $id ? Db::name('dict_data')->where('id', $id)->find() : null;
        $typeCode = (string)($row['type_code'] ?? '');
        // v2.78：删除证书类型前必须先清空其下专业，避免专业变成「无归属」的孤儿
        if ($typeCode === CertDictService::MAJOR_CODE && $row && trim((string)($row['parent_value'] ?? '')) === '') {
            $children = (int)Db::name('dict_data')
                ->where('type_code', CertDictService::MAJOR_CODE)
                ->where('parent_value', (string)$row['dict_value'])
                ->count();
            if ($children > 0) {
                return json(r_error("该证书类型下还有 {$children} 个专业，请先移除或改归其他类型", 400));
            }
        }
        DictDataModel::destroy($id);
        if ($typeCode === CertDictService::MAJOR_CODE) {
            CertDictService::flushCache();
        }
        $this->log('字典项', '删除', "#{$id}");
        return json(r_success([], '删除成功'));
    }
}
