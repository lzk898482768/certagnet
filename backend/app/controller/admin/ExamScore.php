<?php
namespace app\controller\admin;

use app\service\DataScopeService;
use app\service\ExamScoreService;
use think\facade\Db;

/**
 * 安管人员成绩查询（v2.99.70）
 *
 *  - GET  /admin/exam-score                  查询结果列表（合格 / 不合格 / 未查询到成绩）
 *  - GET  /admin/exam-score/companies        结果中出现的企业（按企业筛选）
 *  - POST /admin/exam-score/captcha          开始查询：返回验证码图片
 *  - POST /admin/exam-score/captcha-refresh  换一张验证码
 *  - POST /admin/exam-score/query            提交人工验证码并保存成绩
 *  - POST /admin/exam-score/delete           删除一条查询记录
 */
class ExamScore extends AdminController
{
    protected function boot(): void
    {
        ExamScoreService::ensureTables();
    }

    /** 数据权限：与申报进度一致（管理员全部；客户账号按企业；内部账号按创建人） */
    protected function scopeQuery($query)
    {
        if ($this->isAdmin()) {
            return $query;
        }
        if ($this->isCompanyBound()) {
            $ids = array_values(array_filter(array_map('intval', $this->companyIds())));
            $query->whereIn('company_id', $ids ?: [0]);
            return $query;
        }
        return DataScopeService::applyCreatorScope($query, $this->request->user ?? [], 'create_by');
    }

    public function index()
    {
        $this->boot();
        [$page, $limit] = $this->pageParams();

        $base = Db::name('exam_score_query');
        $this->scopeQuery($base);

        $keyword = trim((string)$this->request->param('keyword', ''));
        if ($keyword !== '') {
            $base->where(function ($w) use ($keyword) {
                $w->whereLike('name', '%' . $keyword . '%')
                  ->whereOr('company_name', 'like', '%' . $keyword . '%');
            });
        }
        $staffId = (int)$this->request->param('staff_id', 0);
        if ($staffId > 0) {
            $base->where('staff_id', $staffId);
        }
        $company = trim((string)$this->request->param('company', ''));
        if ($company !== '') {
            $base->where('company_name', $company);
        }
        $type = trim((string)$this->request->param('query_type', ''));
        if ($type !== '' && isset(ExamScoreService::QUERY_TYPES[$type])) {
            $base->where('query_type', $type);
        }

        // 三类状态统计：忽略 status 筛选本身，便于页签显示数量
        $stats = ['total' => 0, 'passed' => 0, 'failed' => 0, 'not_found' => 0];
        $statRows = (clone $base)->field('status, COUNT(*) AS c')->group('status')->select()->toArray();
        foreach ($statRows as $r) {
            $n = (int)$r['c'];
            $stats['total'] += $n;
            $s = (int)$r['status'];
            if ($s === 1) { $stats['passed'] = $n; }
            elseif ($s === 2) { $stats['failed'] = $n; }
            elseif ($s === 3) { $stats['not_found'] = $n; }
        }

        $status = (int)$this->request->param('status', 0);
        if ($status > 0) {
            $base->where('status', $status);
        }

        $total = (clone $base)->count();
        $list = (clone $base)
            ->order('query_time', 'desc')->order('id', 'desc')
            ->page($page, $limit)->select()->toArray();

        foreach ($list as &$r) {
            $r['person_type_label'] = ExamScoreService::personTypeLabel((string)($r['person_type'] ?? ''));
            $r['status_label'] = ExamScoreService::STATUSES[(int)($r['status'] ?? 3)] ?? '未查询到成绩';
            $r['query_type_label'] = ExamScoreService::QUERY_TYPES[(string)($r['query_type'] ?? '')] ?? '';
            unset($r['raw_json'], $r['id_card'], $r['id_card_hash']);
        }
        unset($r);

        return json(r_success([
            'list'  => $list,
            'total' => $total,
            'stats' => $stats,
            'types' => ExamScoreService::QUERY_TYPES,
            'statuses' => ExamScoreService::STATUSES,
        ]));
    }

    /** 结果中出现过的企业（下拉筛选） */
    public function companies()
    {
        $this->boot();
        $q = Db::name('exam_score_query')->where('company_name', '<>', '');
        $this->scopeQuery($q);
        $rows = $q->field('company_name, COUNT(*) AS total')
            ->group('company_name')
            ->order('total', 'desc')
            ->limit(500)
            ->select()->toArray();
        return json(r_success($rows));
    }

    /** 开始查询：打开平台会话并返回验证码 */
    public function captcha()
    {
        $this->boot();
        $staffId = (int)$this->request->post('staff_id', 0);
        $type = trim((string)$this->request->post('query_type', 'new'));
        $deptId = (int)($this->request->user['dept_id'] ?? 0);
        try {
            $data = ExamScoreService::startCaptcha($staffId, $type, (int)$this->request->userId, $deptId);
        } catch (\Throwable $e) {
            return json(r_error($e->getMessage(), 400));
        }
        return json(r_success($data, '请输入图片中的计算结果'));
    }

    public function captchaRefresh()
    {
        $this->boot();
        $captchaId = trim((string)$this->request->post('captcha_id', ''));
        if ($captchaId === '') {
            return json(r_error('验证码会话不存在，请重新发起查询', 400));
        }
        try {
            $data = ExamScoreService::refreshCaptcha($captchaId);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            // 会话已过期：告知前端按「验证码失效」处理，直接重新发起一次查询
            $invalid = str_contains($msg, '验证码已过期') || str_contains($msg, '验证码会话不存在');
            return json(r_error($msg, 400, $invalid ? ['captcha_invalid' => true] : []));
        }
        return json(r_success($data));
    }

    /** 提交验证码并查询，成功后自动保存 */
    public function query()
    {
        $this->boot();
        $captchaId = trim((string)$this->request->post('captcha_id', ''));
        $code = trim((string)$this->request->post('code', ''));
        if ($captchaId === '') {
            return json(r_error('验证码会话不存在，请重新发起查询', 400));
        }
        try {
            $res = ExamScoreService::query($captchaId, $code);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            // 验证码会话过期（Cache 过期 / 服务重启）同样按「验证码失效」返回，
            // 前端会自动换取一张新图，用户不必关掉弹窗重来。
            $invalid = str_contains($msg, '验证码已过期') || str_contains($msg, '验证码会话不存在');
            return json(r_error($msg, 400, $invalid ? ['captcha_invalid' => true] : []));
        }
        if (empty($res['ok'])) {
            return json(r_error($res['message'] ?? '验证码错误，请重新输入', 400, [
                'captcha_invalid' => !empty($res['captcha_invalid']),
            ]));
        }
        $this->log('成绩查询', '查询', ($res['items'][0]['name'] ?? '') . '：' . ($res['message'] ?? ''));
        return json(r_success($res, $res['message'] ?? '查询完成'));
    }

    public function delete()
    {
        $this->boot();
        $id = (int)$this->request->post('id', 0);
        if ($id <= 0) {
            return json(r_error('请选择要删除的记录', 400));
        }
        $row = $this->scopeQuery(Db::name('exam_score_query'))->where('id', $id)->find();
        if (!$row) {
            return json(r_error('记录不存在或无权访问', 404));
        }
        Db::name('exam_score_query')->where('id', $id)->delete();
        $this->log('成绩查询', '删除', "#{$id} " . ($row['name'] ?? ''));
        return json(r_success(['id' => $id], '已删除'));
    }
}
