<?php
namespace app\controller\admin;

use app\service\ExamSchemaService;
use think\facade\Db;

/**
 * 报名-证书分类 / 证书类型（v2.85）
 *
 *  - GET  /admin/exam-cert/categories      分类列表
 *  - POST /admin/exam-cert/category-save   分类新增/编辑
 *  - POST /admin/exam-cert/category-delete 分类删除
 *  - GET  /admin/exam-cert/types           证书类型列表
 *  - GET  /admin/exam-cert/type-options    启用类型下拉
 *  - POST /admin/exam-cert/type-save       类型新增/编辑
 *  - POST /admin/exam-cert/type-delete     类型删除
 *
 * 配置驱动：这里维护「能报哪些证」，业务规则在业务组合中配置，代码不写死。
 */
class ExamCert extends AdminController
{
    /** 列表前确保表存在 */
    protected function boot(): void
    {
        ExamSchemaService::ensureTables();
    }

    // ---------------- 证书分类 ----------------

    public function categories()
    {
        $this->boot();
        $list = Db::name('exam_cert_category')
            ->where('status', 1)->order('sort asc,id asc')->select()->toArray();
        return json(r_success(['list' => $list]));
    }

    public function categorySave()
    {
        $this->boot();
        if (!$this->can('examcat', 'edit')) {
            return json(r_error('无保存权限', 403));
        }
        $p = $this->request->param();
        $id = (int)($p['id'] ?? 0);
        $name = trim((string)($p['name'] ?? ''));
        if ($name === '') {
            return json(r_error('请填写分类名称'));
        }
        $data = [
            'name'   => mb_substr($name, 0, 50),
            'sort'   => (int)($p['sort'] ?? 0),
            'remark' => mb_substr((string)($p['remark'] ?? ''), 0, 255),
            'status' => 1,
        ];
        $dup = Db::name('exam_cert_category')->where('name', $data['name'])->where('id', '<>', $id)->find();
        if ($dup) {
            return json(r_error('分类名称已存在'));
        }
        if ($id > 0) {
            Db::name('exam_cert_category')->where('id', $id)->update($data + ['update_time' => date('Y-m-d H:i:s')]);
        } else {
            $data['create_by'] = (int)$this->request->userId;
            $data['create_time'] = date('Y-m-d H:i:s');
            $id = (int)Db::name('exam_cert_category')->insertGetId($data);
        }
        $this->log('报名配置', '保存证书分类', $data['name']);
        return json(r_success(['id' => $id], '保存成功'));
    }

    public function categoryDelete()
    {
        $this->boot();
        if (!$this->can('examcat', 'delete')) {
            return json(r_error('无删除权限', 403));
        }
        $id = (int)$this->request->param('id', 0);
        if ($id <= 0) {
            return json(r_error('参数错误'));
        }
        if (Db::name('exam_cert_type')->where('cate_id', $id)->where('status', 1)->count() > 0) {
            return json(r_error('该分类下仍有证书类型，不能删除'));
        }
        Db::name('exam_cert_category')->where('id', $id)->update(['status' => 0, 'update_time' => date('Y-m-d H:i:s')]);
        $this->log('报名配置', '删除证书分类', (string)$id);
        return json(r_success([], '删除成功'));
    }

    // ---------------- 证书类型 ----------------

    public function types()
    {
        $this->boot();
        [$page, $limit] = $this->pageParams();
        $q = Db::name('exam_cert_type')->alias('t')->where('t.status', 1);

        $keyword = trim((string)$this->request->param('keyword', ''));
        if ($keyword !== '') {
            $q->where('t.name|t.code', 'like', "%{$keyword}%");
        }
        $cateId = (int)$this->request->param('cate_id', 0);
        if ($cateId > 0) {
            $q->where('t.cate_id', $cateId);
        }
        $total = (clone $q)->count();
        $list = (clone $q)->order('t.sort asc,t.id desc')->page($page, $limit)->select()->toArray();

        // 附带分类名与已配置的业务组合数
        $cateNames = Db::name('exam_cert_category')->column('name', 'id');
        foreach ($list as &$r) {
            $r['cate_name'] = $cateNames[$r['cate_id']] ?? $r['category'];
            $r['config_count'] = Db::name('exam_biz_config')
                ->where('cert_type_id', $r['id'])->where('status', 1)->count();
        }
        unset($r);
        return json(r_success(['list' => $list, 'total' => $total]));
    }

    /** 启用证书类型下拉（含分类分组） */
    public function typeOptions()
    {
        $this->boot();
        $rows = Db::name('exam_cert_type')->where('status', 1)
            ->field('id,name,cate_id,category,default_fee,min_age,max_age,education_req,valid_years')
            ->order('sort asc,id asc')->select()->toArray();
        return json(r_success(['list' => $rows]));
    }

    public function typeSave()
    {
        $this->boot();
        if (!$this->can('examcat', 'edit')) {
            return json(r_error('无保存权限', 403));
        }
        $p = $this->request->param();
        $id = (int)($p['id'] ?? 0);
        $name = trim((string)($p['name'] ?? ''));
        if ($name === '') {
            return json(r_error('请填写证书名称'));
        }
        $cateId = (int)($p['cate_id'] ?? 0);
        $category = (string)Db::name('exam_cert_category')->where('id', $cateId)->value('name');
        if ($category === '') {
            $category = '其他';
        }
        $data = [
            'code'              => mb_substr(trim((string)($p['code'] ?? '')), 0, 50),
            'name'              => mb_substr($name, 0, 100),
            'cate_id'           => $cateId,
            'category'          => $category,
            'valid_years'       => max(0, (int)($p['valid_years'] ?? 0)),
            'review_cycle_years'=> max(0, (int)($p['review_cycle_years'] ?? 0)),
            'need_exam'         => (int)($p['need_exam'] ?? 0) ? 1 : 0,
            'need_physical'     => (int)($p['need_physical'] ?? 0) ? 1 : 0,
            'min_age'           => max(0, (int)($p['min_age'] ?? 0)),
            'max_age'           => max(0, (int)($p['max_age'] ?? 0)),
            'education_req'     => mb_substr((string)($p['education_req'] ?? ''), 0, 30),
            'default_fee'       => round((float)($p['default_fee'] ?? 0), 2),
            'sort'              => (int)($p['sort'] ?? 0),
            'remark'            => mb_substr((string)($p['remark'] ?? ''), 0, 255),
            'status'            => 1,
        ];
        if ($id > 0) {
            Db::name('exam_cert_type')->where('id', $id)->update($data + ['update_time' => date('Y-m-d H:i:s')]);
        } else {
            $data['create_by'] = (int)$this->request->userId;
            $data['create_time'] = date('Y-m-d H:i:s');
            $id = (int)Db::name('exam_cert_type')->insertGetId($data);
        }
        $this->log('报名配置', '保存证书类型', $data['name']);
        return json(r_success(['id' => $id], '保存成功'));
    }

    public function typeDelete()
    {
        $this->boot();
        if (!$this->can('examcat', 'delete')) {
            return json(r_error('无删除权限', 403));
        }
        $id = (int)$this->request->param('id', 0);
        if ($id <= 0) {
            return json(r_error('参数错误'));
        }
        if (Db::name('exam_biz_config')->where('cert_type_id', $id)->where('status', 1)->count() > 0) {
            return json(r_error('该证书类型已配置业务组合，不能删除'));
        }
        Db::name('exam_cert_type')->where('id', $id)->update(['status' => 0, 'update_time' => date('Y-m-d H:i:s')]);
        $this->log('报名配置', '删除证书类型', (string)$id);
        return json(r_success([], '删除成功'));
    }
}
