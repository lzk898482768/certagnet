<?php
namespace app\controller\admin;

use app\model\Certificate;
use app\service\CrmService;
use think\facade\Db;

/**
 * CRM 能力接口（v2.54）
 *
 *  - 负责人移交（公司/证书，单条+批量，写移交历史）
 *  - 跟进记录（详情时间线 + 写跟进 + 删除）
 *  - 跟进台账汇总页（跨公司/证书分页）
 *
 * 数据权限复用 AdminController 的 assertCompanyAccess / assertCertAccess /
 * scope / scopeCert，与现有列表、删除口径完全一致。
 */
class Crm extends AdminController
{
    /** 可选负责人下拉（启用中的账号） */
    public function users()
    {
        CrmService::ensureSchema();
        return json(r_success(CrmService::users()));
    }

    /**
     * 负责人移交：POST { module, ids[], to_user_id, remark }
     */
    public function transfer()
    {
        $module   = (string)$this->request->post('module', '');
        $ids      = array_values(array_filter(array_map('intval', (array)$this->request->post('ids/a', []))));
        $toUserId = (int)$this->request->post('to_user_id', 0);
        $remark   = trim((string)$this->request->post('remark', ''));

        if (!isset(CrmService::MODULES[$module])) {
            return json(r_error('未知模块', 400));
        }
        if (!$ids) {
            return json(r_error('未选择要移交的数据', 400));
        }
        if ($toUserId < 0) {
            return json(r_error('目标参数错误', 400));
        }

        // 数据权限：仅放行当前账号可访问的记录
        $allowed = [];
        foreach ($ids as $id) {
            if ($module === 'company' && $this->canAccessCompany($id)) {
                $allowed[] = $id;
            } elseif ($module === 'certificate' && $this->canAccessCert($id)) {
                $allowed[] = $id;
            } elseif ($module === 'staff' && $this->canAccessStaff($id)) {
                $allowed[] = $id;
            }
        }
        CrmService::ensureSchema();
        $r = CrmService::transfer($module, $ids, $toUserId, $remark, $allowed, (int)$this->request->userId);

        $this->log(CrmService::MODULES[$module], '移交负责人', sprintf(
            '%s×%d 条 → %s%s',
            $module,
            $r['ok'],
            $toUserId > 0 ? '负责人 #' . $toUserId : '公海',
            $r['fail'] ? '，失败 ' . count($r['fail']) . ' 条' : ''
        ));

        $msg = '已移交 ' . $r['ok'] . ' 条';
        if ($r['fail']) {
            $msg .= '，' . count($r['fail']) . ' 条失败';
        }
        return json(r_success(['ok' => $r['ok'], 'fail' => $r['fail']], $msg));
    }

    /** 跟进记录列表：GET { module, record_id } */
    public function follows()
    {
        $module   = (string)$this->request->param('module', '');
        $recordId = (int)$this->request->param('record_id', 0);
        if (!isset(CrmService::MODULES[$module])) {
            return json(r_error('未知模块', 400));
        }
        if ($recordId <= 0) {
            return json(r_error('参数错误', 400));
        }
        if ($module === 'company') {
            $this->assertCompanyAccess($recordId);
        } elseif ($module === 'staff') {
            $this->assertStaffAccess($recordId);
        } else {
            $this->assertCertAccess($recordId);
        }
        CrmService::ensureSchema();
        // v2.57：跨证书聚合时按当前账号可访问证书过滤，避免企业账号看到其他企业证书的跟进。
        // 企业账号一次性取出可访问证书集合，避免逐证书调用 canAccessCert 造成 N+1。
        $certFilter = null;
        if (!$this->isAdmin()) {
            $companyIds = array_map('intval', $this->companyIds());
            if ($companyIds) {
                $allowed = array_map('intval', \app\service\SecondmentService::certIdsOf($companyIds, 1));
                $certFilter = function (int $cid) use ($allowed) {
                    return in_array($cid, $allowed, true);
                };
            } else {
                $certFilter = function (int $cid) {
                    return $this->canAccessCert($cid);
                };
            }
        }
        return json(r_success(CrmService::followList($module, $recordId, $certFilter)));
    }

    /** 写跟进：POST { module, record_id, follow_type, content, next_follow_date } */
    public function follow()
    {
        $module        = (string)$this->request->post('module', '');
        $recordId      = (int)$this->request->post('record_id', 0);
        $followType    = trim((string)$this->request->post('follow_type', '电话'));
        $content       = trim((string)$this->request->post('content', ''));
        $nextFollowDate = trim((string)$this->request->post('next_follow_date', ''));

        if (!isset(CrmService::MODULES[$module])) {
            return json(r_error('未知模块', 400));
        }
        if ($recordId <= 0) {
            return json(r_error('参数错误', 400));
        }
        if ($content === '') {
            return json(r_error('请填写跟进内容', 400));
        }
        if ($module === 'company') {
            $this->assertCompanyAccess($recordId);
        } elseif ($module === 'staff') {
            $this->assertStaffAccess($recordId);
        } else {
            $this->assertCertAccess($recordId);
        }
        $id = CrmService::addFollow((int)$this->request->userId, $module, $recordId, $followType, $content, $nextFollowDate);
        $this->log(CrmService::MODULES[$module], '写跟进', '#' . $recordId);
        return json(r_success(['id' => $id], '跟进已记录'));
    }

    /** 删除跟进记录：POST { id }，仅创建人或管理员 */
    public function followDelete()
    {
        $id = (int)$this->request->post('id', 0);
        if ($id <= 0) {
            return json(r_error('参数错误', 400));
        }
        CrmService::ensureSchema();
        if (!CrmService::deleteFollow((int)$this->request->userId, $this->isAdmin(), $id)) {
            return json(r_error('无权删除该跟进记录', 403));
        }
        return json(r_success([], '已删除'));
    }

    /**
     * 跟进台账汇总页：GET 分页
     * 参数 module / owner_user_id / keyword / start / end / follow_date / page / limit
     */
    public function ledger()
    {
        CrmService::ensureSchema();
        $scope = function (string $module) {
            if ($this->isAdmin()) {
                $table = $module === 'company' ? 'client_company' : ($module === 'staff' ? 'staff' : 'certificate');
                return array_map('intval', Db::name($table)->column('id'));
            }
            if ($module === 'company') {
                $q = Db::name('client_company')->field('id');
                $this->scopeCompany($q);
                return array_map('intval', $q->column('id'));
            }
            if ($module === 'staff') {
                $q = \app\model\Staff::field('id');
                $this->applyStaffScope($q);
                return array_map('intval', $q->column('id'));
            }
            $q = Certificate::field('id');
            $this->scopeCert($q);
            return array_map('intval', $q->column('id'));
        };
        return json(r_success(CrmService::ledger($this->request->param(), $scope)));
    }
}