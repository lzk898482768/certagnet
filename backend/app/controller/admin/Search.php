<?php
namespace app\controller\admin;

use app\model\Certificate as CertificateModel;
use app\model\ClientCompany;
use app\model\Contract;
use app\model\Staff;
use app\service\CustomFieldService;
use app\service\SecondmentService;
use think\facade\Db;

/**
 * 一键查询中心
 * v2.81：结果区由「人员 + 企业」改为「证书 + 企业」，查询逻辑同步改为按证书检索。
 * 支持：关键字（证书名称/编号/类型/专业/发证机关/持证人/企业名/信用代码…）模糊或精确匹配 +
 * 多条件组合筛选（= / 包含 / 不包含），并可查看证书/人员/企业完整详情。
 */
class Search extends AdminController
{
    // 人员维度字段（保留：企业关联人员、旧版 H5 type=staff 兼容）
    private const STAFF_FIELDS = [
        'staff_name'   => ['col' => 'staff.staff_name',   'entity' => 'staff'],
        'phone'        => ['col' => 'staff.phone',        'entity' => 'staff'],
        'id_card'      => ['col' => 'staff.id_card',      'entity' => 'staff'],
        'dept'         => ['col' => 'staff.dept',         'entity' => 'staff'],
        'position'     => ['col' => 'staff.position',     'entity' => 'staff'],
        'email'        => ['col' => 'staff.email',        'entity' => 'staff'],
        'status'       => ['col' => 'staff.status',       'entity' => 'staff'],
        'cert_name'    => ['col' => 'certificate.cert_name',    'entity' => 'cert'],
        // v2.36：证书类别已统一为证书类型；category 条件保留以兼容旧链接/历史筛选
        'cert_type'    => ['col' => 'certificate.cert_type',    'entity' => 'cert'],
        'category'     => ['col' => 'certificate.category',     'entity' => 'cert'],
        'social_status'=> ['col' => 'staff.social_status',      'entity' => 'staff'],
    ];

    private const COMPANY_FIELDS = [
        'company_name'  => ['col' => 'client_company.company_name', 'entity' => 'company'],
        'short_name'    => ['col' => 'client_company.short_name',  'entity' => 'company'],
        'credit_code'   => ['col' => 'client_company.credit_code', 'entity' => 'company'],
        'contact'       => ['col' => 'client_company.contact',     'entity' => 'company'],
        'contact_phone' => ['col' => 'client_company.contact_phone','entity' => 'company'],
        'industry'      => ['col' => 'client_company.industry',    'entity' => 'company'],
        'company_scale' => ['col' => 'client_company.company_scale','entity' => 'company'],
        'address'       => ['col' => 'client_company.address',     'entity' => 'company'],
        'status'        => ['col' => 'client_company.status',      'entity' => 'company'],
    ];

    // 证书维度字段 -> 实际列；staff_name 通过 EXISTS 子查询过滤持证人，
    // cert_company 通过「借调记录 -> 企业」反查证书（v2.81.1）
    private const CERT_FIELDS = [
        'cert_name'     => ['col' => 'certificate.cert_name',     'entity' => 'cert'],
        'cert_no'       => ['col' => 'certificate.cert_no',       'entity' => 'cert'],
        'cert_type'     => ['col' => 'certificate.cert_type',     'entity' => 'cert'],
        // v2.99.92：「证书专业」筛选实际应匹配存专业的 cert_name 列（多专业以「、」连接）；
        // category 列历史上存的是证书类型名而非专业，故改用专用实体 cert_major 做多专业感知匹配。
        'category'      => ['col' => 'certificate.cert_name',     'entity' => 'cert_major'],
        'issue_org'     => ['col' => 'certificate.issue_org',     'entity' => 'cert'],
        'cert_status'   => ['col' => 'certificate.cert_status',   'entity' => 'cert'],
        'social_status' => ['col' => 'certificate.social_status', 'entity' => 'cert'],
        'priority'      => ['col' => 'certificate.priority',      'entity' => 'cert'],
        'staff_name'    => ['col' => 'staff.staff_name',          'entity' => 'staff_exists'],
        // v2.99.61：一键查询中心支持按持证人身份证号（盲索引精确匹配）查证书
        'staff_id_card' => ['col' => 'staff.id_card_hash',        'entity' => 'staff_idcard'],
        'cert_company'  => ['col' => 'client_company.company_name','entity' => 'company_exists'],
    ];

    /** 组合查询：GET /api/admin/search */
    public function index()
    {
        $type    = $this->request->param('type', 'all'); // all=cert+company|cert|company|staff(旧版兼容)
        $keyword = trim((string)$this->request->param('keyword', ''));
        $match   = $this->request->param('match', 'fuzzy'); // fuzzy|exact
        // v2.35：多条件匹配关系 and=同时具备（默认） / or=任意均可
        $logic   = $this->request->param('logic', 'and');
        if (!in_array($logic, ['and', 'or'], true)) {
            $logic = 'and';
        }
        // v2.80：证书 / 企业各自分页，每页最多 15 条（前端竖向排列两个结果区）
        $pageSize = (int)$this->request->param('page_size', 15);
        $pageSize = $pageSize > 0 && $pageSize <= 100 ? $pageSize : 15;
        $certPage    = max(1, (int)$this->request->param('cert_page', 1));
        $companyPage = max(1, (int)$this->request->param('company_page', 1));
        // 旧版 H5 人员查询兼容
        $staffPage   = max(1, (int)$this->request->param('staff_page', 1));
        // 兼容旧版 limit（H5 等未传分页参数时仍按 limit 取首页）
        $limit   = (int)$this->request->param('limit', 0);
        // 旧版 H5 缓存的特征：type=all + limit，但没有 cert_page 标记。
        // 这类客户端仍按「人员 + 企业」渲染，继续走人员分支，避免升级瞬间结果区变空。
        $legacyStaffAll = ($type === 'all' && $limit > 0 && !$this->request->has('cert_page'));
        // v2.99.59：身份证号密文落库，兜底补齐 id_card_hash（老库运行时自愈）
        \app\service\IdCardSchemaService::ensure();

        // 兼容条件构造器：前端以 JSON 字符串传入 conditions
        $conditions = [];
        $condRaw = $this->request->param('conditions', '');
        if ($condRaw) {
            $dec = json_decode($condRaw, true);
            if (is_array($dec)) {
                $conditions = $dec;
            }
        }

        $result = [
            'cert'    => [],
            'company' => [],
            'keyword' => $keyword,
            'match'   => $match,
            'logic'   => $logic,
        ];

        $certRes = ['list' => [], 'total' => 0];
        $staffRes = ['list' => [], 'total' => 0];
        $companyRes = ['list' => [], 'total' => 0];
        if ($type === 'all' || $type === 'cert') {
            if ($legacyStaffAll) {
                // 旧版 H5：type=all 仍返回人员
                $staffRes = $this->searchStaff($keyword, $match, $conditions, $logic, $staffPage, $pageSize, $limit);
                $result['staff'] = $staffRes['list'];
            } else {
                $certRes = $this->searchCert($keyword, $match, $conditions, $logic, $certPage, $pageSize, $limit);
                $result['cert'] = $certRes['list'];
            }
        }
        // 旧版 H5 仍可能传 type=staff，保留人员检索分支
        if ($type === 'staff') {
            $staffRes = $this->searchStaff($keyword, $match, $conditions, $logic, $staffPage, $pageSize, $limit);
            $result['staff'] = $staffRes['list'];
        }
        if ($type === 'all' || $type === 'company') {
            $companyRes = $this->searchCompany($keyword, $match, $conditions, $logic, $companyPage, $pageSize, $limit);
            $result['company'] = $companyRes['list'];
        }
        $result['cert_total']    = $certRes['total'];
        $result['company_total'] = $companyRes['total'];
        $result['cert_page']     = $certPage;
        $result['company_page']  = $companyPage;
        // 旧字段兼容（旧版 H5 读取 staff_total / staff_page）
        $result['staff']         = $result['staff'] ?? [];
        $result['staff_total']   = $staffRes['total'];
        $result['staff_page']    = $staffPage;
        $result['page_size']     = $pageSize;

        return json(r_success($result));
    }

    /** 详情：GET /api/admin/search/detail?type=staff|company&id= */
    public function detail()
    {
        $type = $this->request->param('type', 'staff');
        $id   = (int)$this->request->param('id', 0);
        if (!$id) {
            return json(r_error('缺少ID', 400));
        }
        return $type === 'company' ? $this->companyDetail($id) : $this->staffDetail($id);
    }

    // ---------------- 条件应用 ----------------

    private function applyOp($q, string $col, string $op, string $val)
    {
        if ($op === 'eq') {
            $q->where($col, $val);
        } elseif ($op === 'not_like') {
            $q->where($col, 'not like', "%{$val}%");
        } else { // like / 包含
            $q->where($col, 'like', "%{$val}%");
        }
    }

    /**
     * 把单条条件应用到查询
     */
    private function applyOne($q, array $meta, string $op, string $val)
    {
        // v2.99.59：身份证密文落库，身份证条件改为盲索引精确匹配（兼容明文历史数据）
        if (($meta['col'] ?? '') === 'staff.id_card') {
            $norm = \app\service\IdCardService::normalize($val);
            $hash = \app\service\IdCardService::hash($norm);
            $q->where(function ($w) use ($hash, $norm) {
                if ($hash !== '') {
                    $w->whereOr('staff.id_card_hash', $hash);
                }
                $w->whereOr('staff.id_card', $norm);
            });
            return;
        }
        if ($meta['entity'] === 'cert') {
            // 证书维度 -> EXISTS 子查询（仅人员）
            $q->whereExists(function ($sub) use ($meta, $op, $val) {
                $sub->table('certificate')->whereColumn('certificate.staff_id', 'staff.id');
                $this->applyOp($sub, $meta['col'], $op, $val);
            });
        } else {
            $this->applyOp($q, $meta['col'], $op, $val);
        }
    }

    /**
     * 把条件数组应用到查询
     *
     * @param string $entity staff|company
     * @param string $logic  and=同时具备（默认，逐条 AND） / or=任意均可（整体 OR）
     */
    private function applyConditions($q, array $conditions, string $entity, string $logic = 'and')
    {
        $map = $entity === 'company' ? self::COMPANY_FIELDS : self::STAFF_FIELDS;

        // 先收集有效条件（字段合法 + 值非空）
        $valid = [];
        foreach ($conditions as $c) {
            if (!is_array($c)) {
                continue;
            }
            $field = $c['field'] ?? '';
            $val   = isset($c['value']) ? trim((string)$c['value']) : '';
            if (!isset($map[$field]) || $val === '') {
                continue;
            }
            $valid[] = [
                'meta' => $map[$field],
                'op'   => $c['op'] ?? 'like',
                'val'  => $val,
            ];
        }
        if (!$valid) {
            return;
        }

        // 任意均可：整体包一层 OR（>1 条才有意义）
        if ($logic === 'or' && count($valid) > 1) {
            $q->where(function ($w) use ($valid) {
                foreach ($valid as $one) {
                    $w->whereOr(function ($ww) use ($one) {
                        $this->applyOne($ww, $one['meta'], $one['op'], $one['val']);
                    });
                }
            });
            return;
        }

        // 同时具备：逐条 AND
        foreach ($valid as $one) {
            $this->applyOne($q, $one['meta'], $one['op'], $one['val']);
        }
    }

    // ---------------- 证书 ----------------

    /**
     * 按企业维度（名称 / 简称 / 信用代码）反查「借调过该企业的证书」ID。
     *
     * 不限制借调状态：已归还 / 已到期的历史借调同样属于这家企业的证书，
     * 否则用户按企业名搜索时看不到已到期、仍需跟进的证书。
     */
    private function certIdsByCompany(string $value, string $op = 'like'): array
    {
        if ($value === '') {
            return [];
        }
        $rows = Db::name('cert_secondment')->alias('s')
            ->join('client_company c', 'c.id = s.company_id')
            ->where(function ($w) use ($value, $op) {
                $this->applyOp($w, 'c.company_name', $op, $value);
                if ($op !== 'eq') {
                    // 模糊匹配时同时匹配简称与统一社会信用代码
                    $w->whereOr(function ($ww) use ($value, $op) {
                        $this->applyOp($ww, 'c.short_name', $op, $value);
                    });
                    $w->whereOr(function ($ww) use ($value, $op) {
                        $this->applyOp($ww, 'c.credit_code', $op, $value);
                    });
                }
            })
            ->column('s.cert_id');
        return array_values(array_unique(array_map('intval', $rows ?: [])));
    }

    /**
     * 把条件数组应用到证书查询（certificate 主表）
     * staff_name 通过 EXISTS 子查询过滤，避免 join staff 造成 create_by 等列名歧义。
     */
    private function applyCertConditions($q, array $conditions, string $logic = 'and')
    {
        $valid = [];
        foreach ($conditions as $c) {
            if (!is_array($c)) {
                continue;
            }
            $field = $c['field'] ?? '';
            $val   = isset($c['value']) ? trim((string)$c['value']) : '';
            if (!isset(self::CERT_FIELDS[$field]) || $val === '') {
                continue;
            }
            $valid[] = [
                'meta' => self::CERT_FIELDS[$field],
                'op'   => $c['op'] ?? 'like',
                'val'  => $val,
            ];
        }
        if (!$valid) {
            return;
        }

        $apply = function ($qq, array $one) {
            $meta = $one['meta'];
            if (($meta['entity'] ?? '') === 'staff_exists') {
                $certTbl  = Db::name('certificate')->getTable();
                $staffTbl = Db::name('staff')->getTable();
                $qq->whereExists(function ($sub) use ($one, $certTbl, $staffTbl) {
                    $sub->name('staff')->whereColumn($staffTbl . '.id', '=', $certTbl . '.staff_id');
                    $this->applyOp($sub, 'staff_name', $one['op'], $one['val']);
                });
            } elseif (($meta['entity'] ?? '') === 'staff_idcard') {
                // v2.99.61：身份证密文落库，只能按完整号码走盲索引精确匹配（兼容明文历史行）
                $certTbl  = Db::name('certificate')->getTable();
                $staffTbl = Db::name('staff')->getTable();
                $norm = \app\service\IdCardService::normalize($one['val']);
                if (!preg_match('/^\d{15}$|^\d{17}[\dX]$/', $norm)) {
                    // 非完整身份证号：密文库里无法做包含匹配，直接返回空集，避免误命中
                    $qq->whereRaw('1 = 0');
                    return;
                }
                $hash = \app\service\IdCardService::hash($norm);
                $qq->whereExists(function ($sub) use ($hash, $norm, $certTbl, $staffTbl) {
                    $sub->name('staff')->whereColumn($staffTbl . '.id', '=', $certTbl . '.staff_id')
                        ->where(function ($w) use ($hash, $norm) {
                            $w->where('id_card_hash', $hash)->whereOr('id_card', $norm);
                        });
                });
            } elseif (($meta['entity'] ?? '') === 'company_exists') {
                // 当前/历史借调企业 -> 反查证书
                $certIds = $this->certIdsByCompany($one['val'], $one['op']);
                $qq->whereIn('certificate.id', $certIds ?: [0]);
            } elseif (($meta['entity'] ?? '') === 'cert_major') {
                // v2.99.92：「证书专业」筛选匹配 cert_name（多专业以「、」连接，逐专业完整名命中），
                // 并 OR 兼容历史把类型/专业写进 category 列的存量数据，避免老数据筛选断链。
                $val = trim((string)$one['val']);
                $qq->where(function ($w) use ($val, $one) {
                    $nameExpr = "CONCAT('、', certificate.cert_name, '、')";
                    $catExpr  = "CONCAT('、', certificate.category, '、')";
                    if ($one['op'] === 'eq') {
                        // 等于：任一专业完整名相等（顿号边界），避免子串误判
                        $w->whereRaw("{$nameExpr} LIKE ?", ['%、' . $val . '、%'])
                          ->whereOrRaw("{$catExpr} LIKE ?", ['%、' . $val . '、%']);
                    } elseif ($one['op'] === 'not_like') {
                        // 不包含：两列都不含该专业
                        $w->whereRaw("{$nameExpr} NOT LIKE ?", ['%、' . $val . '、%'])
                          ->whereRaw("{$catExpr} NOT LIKE ?", ['%、' . $val . '、%']);
                    } else {
                        // 包含：子串匹配即可（cert_name 或历史 category 任一命中）
                        $w->where('certificate.cert_name', 'like', "%{$val}%")
                          ->whereOr('certificate.category', 'like', "%{$val}%");
                    }
                });
            } else {
                $this->applyOp($qq, $meta['col'], $one['op'], $one['val']);
            }
        };

        if ($logic === 'or' && count($valid) > 1) {
            $q->where(function ($w) use ($valid, $apply) {
                foreach ($valid as $one) {
                    $w->whereOr(function ($ww) use ($one, $apply) {
                        $apply($ww, $one);
                    });
                }
            });
            return;
        }

        foreach ($valid as $one) {
            $apply($q, $one);
        }
    }

    private function searchCert(string $keyword, string $match, array $conditions, string $logic = 'and', int $page = 1, int $pageSize = 15, int $legacyLimit = 0): array
    {
        $q = CertificateModel::orderRaw('expire_date IS NULL ASC, expire_date ASC');
        // 证书级数据权限（与证书台账同口径）
        $q = $this->scopeCert($q);

        $certTbl  = Db::name('certificate')->getTable();
        $staffTbl = Db::name('staff')->getTable();

        if ($keyword !== '') {
            $fields = ['cert_name', 'cert_no', 'cert_type', 'category', 'issue_org'];
            // v2.81.1：关键字同时匹配借调企业（名称 / 简称 / 信用代码），
            // 保证按企业名搜索时能带出该企业名下（含历史借调）的证书。
            $companyCertIds = $this->certIdsByCompany($keyword, $match === 'exact' ? 'eq' : 'like');
            // v2.99.61：关键字为完整身份证号时，按持证人盲索引精确匹配其名下证书
            $kwIdNorm = \app\service\IdCardService::normalize($keyword);
            $kwIdHash = preg_match('/^\d{15}$|^\d{17}[\dX]$/', $kwIdNorm)
                ? \app\service\IdCardService::hash($kwIdNorm) : '';
            $q->where(function ($w) use ($fields, $keyword, $match, $certTbl, $staffTbl, $companyCertIds, $kwIdHash, $kwIdNorm) {
                foreach ($fields as $f) {
                    if ($match === 'exact') {
                        $w->whereOr($certTbl . '.' . $f, $keyword);
                    } else {
                        $w->whereOr($certTbl . '.' . $f, 'like', "%{$keyword}%");
                    }
                }
                // 持证人姓名
                $w->whereOr(function ($ww) use ($keyword, $match, $certTbl, $staffTbl) {
                    $ww->whereExists(function ($sub) use ($keyword, $match, $certTbl, $staffTbl) {
                        $sub->name('staff')->whereColumn($staffTbl . '.id', '=', $certTbl . '.staff_id');
                        if ($match === 'exact') {
                            $sub->where('staff_name', $keyword);
                        } else {
                            $sub->where('staff_name', 'like', "%{$keyword}%");
                        }
                    });
                });
                // 持证人身份证号（仅完整号码可命中：密文库 + 盲索引）
                if ($kwIdHash !== '') {
                    $w->whereOr(function ($ww) use ($kwIdHash, $kwIdNorm, $certTbl, $staffTbl) {
                        $ww->whereExists(function ($sub) use ($kwIdHash, $kwIdNorm, $certTbl, $staffTbl) {
                            $sub->name('staff')->whereColumn($staffTbl . '.id', '=', $certTbl . '.staff_id')
                                ->where(function ($q2) use ($kwIdHash, $kwIdNorm) {
                                    $q2->where('id_card_hash', $kwIdHash)->whereOr('id_card', $kwIdNorm);
                                });
                        });
                    });
                }
                // 借调企业
                if ($companyCertIds) {
                    $w->whereOr($certTbl . '.id', 'in', $companyCertIds);
                }
            });
        }

        // 兼容旧版扁平参数
        $certName   = trim((string)$this->request->param('cert_name', ''));
        $certType   = trim((string)$this->request->param('cert_type', ''));
        $certStatus = $this->request->param('cert_status', '');
        $social     = $this->request->param('social_status', '');
        $staffName  = trim((string)$this->request->param('staff_name', ''));
        if ($certName) {
            $q->where('certificate.cert_name', 'like', "%{$certName}%");
        }
        if ($certType !== '') {
            $q->where(function ($w) use ($certType) {
                $w->where('certificate.cert_type', $certType)->whereOr('certificate.category', $certType);
            });
        }
        if ($certStatus !== '') {
            $q->where('certificate.cert_status', $certStatus);
        }
        if ($social !== '') {
            $q->where('certificate.social_status', $social);
        }
        if ($staffName) {
            $q->whereExists(function ($sub) use ($staffName, $certTbl, $staffTbl) {
                $sub->name('staff')->whereColumn($staffTbl . '.id', '=', $certTbl . '.staff_id')
                    ->where('staff_name', 'like', "%{$staffName}%");
            });
        }
        // 兼容旧版扁平参数：按借调企业过滤
        $companyName = trim((string)$this->request->param('company_name', ''));
        if ($companyName !== '') {
            $ids = $this->certIdsByCompany($companyName, 'like');
            $q->whereIn($certTbl . '.id', $ids ?: [0]);
        }

        // 条件构造器
        $this->applyCertConditions($q, $conditions, $logic);

        $total = (clone $q)->count();
        if ($legacyLimit > 0) {
            $list = $q->limit($legacyLimit)->select()->toArray();
        } else {
            $list = $q->page($page, $pageSize)->select()->toArray();
        }

        // 当前借调企业 / 借调状态
        SecondmentService::attach($list);

        // 无在借记录的证书：带出最近一次借调企业，
        // 用于解释「按企业名搜索命中的证书」当前已归还 / 已到期。
        $idleIds = [];
        foreach ($list as $row) {
            if (empty($row['company_name'])) {
                $idleIds[] = (int)($row['id'] ?? 0);
            }
        }
        $lastSecMap = [];
        if ($idleIds) {
            $secRows = Db::name('cert_secondment')->alias('s')
                ->join('client_company c', 'c.id = s.company_id', 'LEFT')
                ->whereIn('s.cert_id', $idleIds)
                ->field('s.cert_id, s.status, s.start_date, s.end_date, c.company_name')
                ->order('s.id', 'desc')
                ->select()->toArray();
            foreach ($secRows as $r) {
                $cid = (int)$r['cert_id'];
                if (!isset($lastSecMap[$cid])) {
                    $lastSecMap[$cid] = $r; // 只保留最新一条
                }
            }
        }

        // 批量带出持证人信息，避免 N+1
        $staffIds = array_values(array_unique(array_filter(array_map('intval', array_column($list, 'staff_id')))));
        $staffMap = [];
        if ($staffIds) {
            foreach (Db::name('staff')->whereIn('id', $staffIds)
                ->field('id, staff_name, phone, dept, position, social_status, status')
                ->select()->toArray() as $s) {
                $staffMap[(int)$s['id']] = $s;
            }
        }
        foreach ($list as &$row) {
            $s = $staffMap[(int)($row['staff_id'] ?? 0)] ?? [];
            $row['staff_name']     = $s['staff_name'] ?? '';
            $row['staff_phone']    = $s['phone'] ?? '';
            $row['staff_dept']     = $s['dept'] ?? '';
            $row['staff_position'] = $s['position'] ?? '';
            $row['staff_status']   = $s['status'] ?? 0;
            $row['social_status_text'] = self::socialText((int)($row['social_status'] ?? 0));
            $row['remain_days']    = SecondmentService::remainDays((string)($row['expire_date'] ?? ''));
            // 最近一次借调（仅当前无在借记录时有值）
            $last = $lastSecMap[(int)($row['id'] ?? 0)] ?? null;
            $row['last_company_name'] = $last['company_name'] ?? '';
            $row['last_secondment_status_text'] = $last ? SecondmentService::statusText((int)($last['status'] ?? 0)) : '';
            $row['last_secondment_end_date'] = $last['end_date'] ?? '';
        }
        unset($row);

        // 敏感字段脱敏（证书编号 / 持证人电话），受 mask_sensitive 开关控制
        $list = \app\service\MaskService::certListFor($list, $this->request->user ?? []);
        foreach ($list as &$row) {
            $row['staff_phone'] = \app\service\MaskService::phone($row['staff_phone'] ?? '');
        }
        unset($row);
        return ['list' => $list, 'total' => $total];
    }

    // ---------------- 人员 ----------------

    private function searchStaff(string $keyword, string $match, array $conditions, string $logic = 'and', int $page = 1, int $pageSize = 15, int $legacyLimit = 0): array
    {
        $q = Staff::order('id', 'desc');
        // 一键查询中心同样受数据权限约束，避免通过搜索枚举全量人员。
        $q = $this->applyStaffScope($q);
        if ($keyword !== '') {
            // v2.99.59：身份证密文落库，身份证仅完整号码按盲索引精确命中
            $idCardNorm = \app\service\IdCardService::normalize($keyword);
            $idHash = preg_match('/^\d{15}$|^\d{17}[\dX]$/', $idCardNorm)
                ? \app\service\IdCardService::hash($idCardNorm) : '';
            $fields = ['staff_name', 'phone', 'dept', 'position', 'email'];
            $q->where(function ($w) use ($fields, $keyword, $match, $idHash, $idCardNorm) {
                foreach ($fields as $f) {
                    if ($match === 'exact') {
                        $w->whereOr('staff.' . $f, $keyword);
                    } else {
                        $w->whereOr('staff.' . $f, 'like', "%{$keyword}%");
                    }
                }
                if ($idHash !== '') {
                    $w->whereOr('staff.id_card_hash', $idHash);
                }
                // 兼容加密前的明文历史数据
                if ($idCardNorm !== '') {
                    if ($match === 'exact') {
                        $w->whereOr('staff.id_card', $idCardNorm);
                    } else {
                        $w->whereOr('staff.id_card', 'like', "%{$idCardNorm}%");
                    }
                }
            });
        }

        // 兼容旧版扁平参数（H5 仍使用）
        $certName = trim((string)$this->request->param('cert_name', ''));
        $category = trim((string)$this->request->param('category', ''));
        $social   = $this->request->param('social_status', '');
        $status   = $this->request->param('staff_status', '');
        if ($status !== '') {
            $q->where('staff.status', $status);
        }
        if ($certName || $category || $social !== '') {
            $q->whereExists(function ($sub) use ($certName, $category, $social, $match) {
                $sub->table('certificate')->whereColumn('certificate.staff_id', 'staff.id');
                if ($certName) {
                    if ($match === 'exact') {
                        // v2.99.79：多专业证书按拆分后的完整专业名命中，避免子串误判
                        $names = \app\service\CertDictService::splitNames($certName) ?: [$certName];
                        $sub->where(function ($w2) use ($names) {
                            foreach ($names as $i => $one) {
                                if ($i === 0) {
                                    $w2->whereRaw("CONCAT('、', cert_name, '、') LIKE ?", ['%、' . $one . '、%']);
                                } else {
                                    $w2->whereOrRaw("CONCAT('、', cert_name, '、') LIKE ?", ['%、' . $one . '、%']);
                                }
                            }
                        });
                    } else {
                        $sub->where('cert_name', 'like', "%{$certName}%");
                    }
                }
                if ($category) {
                    $sub->where('category', $category);
                }
                if ($social !== '') {
                    $sub->where('social_status', $social);
                }
            });
        }

        // 条件构造器
        $this->applyConditions($q, $conditions, 'staff', $logic);

        $total = (clone $q)->count();
        if ($legacyLimit > 0) {
            $list = $q->limit($legacyLimit)->select()->toArray();
        } else {
            $list = $q->page($page, $pageSize)->select()->toArray();
        }
        $ids  = array_column($list, 'id');
        $certs = [];
        if ($ids) {
            $certQ = CertificateModel::whereIn('staff_id', $ids);
            $this->scopeCert($certQ);
            $certs = $certQ->select()->toArray();
        }
        $certMap = [];
        foreach ($certs as $c) {
            $certMap[$c['staff_id']][] = $c;
        }
        foreach ($list as &$row) {
            $own = $certMap[$row['id']] ?? [];
            SecondmentService::attach($own);
            $row['cert_count']  = count($own);
            $row['cert_names']  = implode('、', array_slice(array_column($own, 'cert_name'), 0, 5));
            $row['certificates'] = array_map(function ($c) {
                return [
                    'id'           => $c['id'],
                    'cert_name'    => $c['cert_name'] ?? '',
                    'cert_no'      => $c['cert_no'] ?? '',
                    'category'     => $c['category'] ?? '',
                    'expire_date'  => $c['expire_date'] ?? '',
                    'cert_status'  => $c['cert_status'] ?? 1,
                    'social_status' => $c['social_status'] ?? 0,
                    'social_status_text' => self::socialText((int)($c['social_status'] ?? 0)),
                    'company_name' => $c['company_name'] ?? '',
                ];
            }, $own);
            $row['company_names'] = implode('、', array_values(array_unique(array_filter(array_column($own, 'company_name')))));
        }
        unset($row);
        // v2.99.59：密文身份证先解密并移除盲索引列，再统一掩码
        \app\service\IdCardService::decodeRows($list);
        // 敏感字段脱敏（受 mask_sensitive 开关控制）
        $list = \app\service\MaskService::staffListFor($list, $this->request->user ?? []);
        foreach ($list as &$row) {
            $row['certificates'] = \app\service\MaskService::certListFor($row['certificates'] ?? [], $this->request->user ?? []);
        }
        unset($row);
        return ['list' => $list, 'total' => $total];
    }

    private function staffDetail(int $id)
    {
        $staff = Staff::find($id);
        if (!$staff) {
            return json(r_error('人员不存在', 400));
        }
        $this->assertStaffAccess($id);
        $data = $staff->toArray();
        $data['owner_name'] = $this->ownerName((int)($data['owner_user_id'] ?? 0));

        $certQ = CertificateModel::where('staff_id', $id);
        $this->scopeCert($certQ);
        $certs = $certQ->select()->toArray();
        SecondmentService::attach($certs);
        foreach ($certs as &$c) {
            $c['social_status_text'] = self::socialText((int)($c['social_status'] ?? 0));
            $c['tracks'] = Db::name('cert_register_track')->where('cert_id', $c['id'])
                ->order('register_date', 'desc')->select()->toArray();
        }
        unset($c);
        CustomFieldService::attachList('certificate', $certs);
        $data['certificates'] = $certs;

        $secQ = Db::name('cert_secondment')->alias('sec')
            ->leftJoin('certificate c', 'c.id = sec.cert_id')
            ->leftJoin('client_company co', 'co.id = sec.company_id')
            ->where('sec.staff_id', $id)
            ->field('sec.*, c.cert_name, c.cert_no, c.expire_date, co.company_name');
        $this->restrictSecondmentQuery($secQ, 'sec.company_id');
        $data['secondments'] = $secQ->order('sec.id', 'desc')->select()->toArray();

        $companyQ = Db::name('staff_company_rel')->alias('r')
            ->join('client_company c', 'c.id = r.company_id')
            ->where('r.staff_id', $id)
            ->field('c.id, c.company_name, c.short_name, c.industry');
        $this->applyCompanyScope($companyQ, 'r.company_id');
        $data['companies'] = $companyQ->select()->toArray();

        $feeQ = Db::name('fee')->where('source_type', 'staff')->where('source_id', $id);
        $feeQ = $this->applyModuleScope($feeQ, 'fee');
        $data['fees'] = $feeQ->order('id', 'desc')->select()->toArray();

        CustomFieldService::attachOne('staff', $data);
        // v2.99.59：密文身份证先解密并移除盲索引列，再统一掩码
        $data = \app\service\IdCardService::decodeRow($data);
        // 敏感字段脱敏（本人档、名下证书与借调记录一并处理；v2.99.51 按字段级矩阵）
        $user = $this->request->user ?? [];
        $data = \app\service\MaskService::staff($data, false, $user);
        $data['certificates'] = \app\service\MaskService::certList($data['certificates'] ?? [], $user);
        $data['secondments']  = \app\service\MaskService::certList($data['secondments'] ?? [], $user);
        return json(r_success($data));
    }

    // ---------------- 企业 ----------------

    private function searchCompany(string $keyword, string $match, array $conditions, string $logic = 'and', int $page = 1, int $pageSize = 15, int $legacyLimit = 0): array
    {
        $q = ClientCompany::order('id', 'desc');
        $q = $this->scopeCompany($q, 'id');
        if ($keyword !== '') {
            $fields = ['company_name', 'short_name', 'credit_code', 'contact', 'contact_phone', 'industry', 'address'];
            $q->where(function ($w) use ($fields, $keyword, $match) {
                foreach ($fields as $f) {
                    if ($match === 'exact') {
                        $w->whereOr('client_company.' . $f, $keyword);
                    } else {
                        $w->whereOr('client_company.' . $f, 'like', "%{$keyword}%");
                    }
                }
            });
        }
        // 兼容旧版扁平参数（H5 仍使用）
        $industry = trim((string)$this->request->param('industry', ''));
        $scale    = trim((string)$this->request->param('company_scale', ''));
        $status   = $this->request->param('company_status', '');
        if ($industry) {
            $q->where('client_company.industry', $industry);
        }
        if ($scale) {
            $q->where('client_company.company_scale', $scale);
        }
        if ($status !== '') {
            $q->where('client_company.status', $status);
        }

        // 条件构造器
        $this->applyConditions($q, $conditions, 'company', $logic);

        $total = (clone $q)->count();
        if ($legacyLimit > 0) {
            $list = $q->limit($legacyLimit)->select()->toArray();
        } else {
            $list = $q->page($page, $pageSize)->select()->toArray();
        }
        $cids = array_map('intval', array_column($list, 'id'));

        // 批量统计各企业：在用证书 / 关联人员（真实数） / 合同 / 费用
        $certCntMap = $staffCntMap = $contractCntMap = $feeTotalMap = $feeUnsettledMap = [];
        if ($cids) {
            foreach (Db::name('cert_secondment')->whereIn('company_id', $cids)->where('status', 1)
                ->field('company_id, COUNT(*) c')->group('company_id')->select()->toArray() as $r) {
                $certCntMap[(int)$r['company_id']] = (int)$r['c'];
            }
            foreach (Db::name('staff_company_rel')->whereIn('company_id', $cids)
                ->field('company_id, COUNT(DISTINCT staff_id) c')->group('company_id')->select()->toArray() as $r) {
                $staffCntMap[(int)$r['company_id']] = (int)$r['c'];
            }
            foreach (Db::name('contract')->whereIn('company_id', $cids)
                ->field('company_id, COUNT(*) c')->group('company_id')->select()->toArray() as $r) {
                $contractCntMap[(int)$r['company_id']] = (int)$r['c'];
            }
            foreach (Db::name('fee')->whereIn('company_id', $cids)
                ->field('company_id, SUM(amount) total, SUM(CASE WHEN settle_status = 0 THEN amount ELSE 0 END) unsettled')
                ->group('company_id')->select()->toArray() as $r) {
                $feeTotalMap[(int)$r['company_id']] = (float)$r['total'];
                $feeUnsettledMap[(int)$r['company_id']] = (float)$r['unsettled'];
            }
        }

        // v2.80：批量带出各企业关联人员明细（与详情抽屉同口径：staff_company_rel join staff）
        $staffMap = [];
        if ($cids) {
            $relRows = Db::name('staff_company_rel')->alias('r')
                ->join('staff s', 's.id = r.staff_id')
                ->whereIn('r.company_id', $cids)
                ->field('r.company_id, s.id, s.staff_name, s.dept, s.position, s.phone, s.id_card, s.social_status, s.status')
                ->order('s.id', 'desc')
                ->select()->toArray();
            foreach ($relRows as $rv) {
                $staffMap[(int)$rv['company_id']][] = $rv;
            }
        }
        $user = $this->request->user ?? [];

        foreach ($list as &$row) {
            $cid = (int)$row['id'];
            $row['cert_count']     = $certCntMap[$cid] ?? 0;
            $row['staff_count']    = $staffCntMap[$cid] ?? 0;
            $row['contract_count'] = $contractCntMap[$cid] ?? 0;
            $row['fee_total']      = $feeTotalMap[$cid] ?? 0;
            $row['fee_unsettled']  = $feeUnsettledMap[$cid] ?? 0;
            $staffs = $staffMap[$cid] ?? [];
            if ($staffs) {
                $staffs = \app\service\MaskService::staffList($staffs, false, $user);
            }
            $row['staffs'] = $staffs;
            // 敏感字段脱敏（企业联系电话，按字段级矩阵）
            $row['contact_phone'] = \app\service\MaskService::fieldValue('company.contact_phone', $row['contact_phone'] ?? '', $user);
        }
        unset($row);
        return ['list' => $list, 'total' => $total];
    }

    private function companyDetail(int $id)
    {
        $company = ClientCompany::find($id);
        if (!$company) {
            return json(r_error('企业不存在', 400));
        }
        $this->assertCompanyAccess($id);
        $data = $company->toArray();
        $data['owner_name'] = $this->ownerName((int)($data['owner_user_id'] ?? 0));

        $staffs = Db::name('staff_company_rel')->alias('r')
            ->join('staff s', 's.id = r.staff_id')
            ->where('r.company_id', $id)
            ->field('s.*')
            ->select()->toArray();
        $data['staffs'] = $staffs;

        $secs = Db::name('cert_secondment')->alias('sec')
            ->join('certificate c', 'c.id = sec.cert_id')
            ->join('staff s', 's.id = sec.staff_id')
            ->where('sec.company_id', $id)
            ->field('sec.*, c.cert_name, c.cert_no, c.expire_date, c.social_status, s.staff_name')
            ->order('sec.status', 'asc')->order('sec.id', 'desc')
            ->select()->toArray();
        foreach ($secs as &$s) {
            $s['social_status_text'] = self::socialText((int)($s['social_status'] ?? 0));
        }
        unset($s);
        $data['secondments'] = $secs;

        $data['contracts'] = Contract::where('company_id', $id)->order('id', 'desc')->select()->toArray();
        $data['fees']      = Db::name('fee')->where('company_id', $id)->order('id', 'desc')->select()->toArray();
        $data['receivers'] = Db::name('company_receiver')->where('company_id', $id)->select()->toArray();

        CustomFieldService::attachOne('company', $data);
        // 敏感字段脱敏：联系电话、关联人员身份证/电话、借调证书编号（v2.99.51 按字段级矩阵）
        $user = $this->request->user ?? [];
        $data['contact_phone'] = \app\service\MaskService::fieldValue('company.contact_phone', $data['contact_phone'] ?? '', $user);
        $data['staffs']        = \app\service\MaskService::staffList($data['staffs'] ?? [], false, $user);
        $data['secondments']   = \app\service\MaskService::certList($data['secondments'] ?? [], $user);
        return json(r_success($data));
    }

    /** 负责人姓名（0 表示未分配） */
    private function ownerName(int $uid): string
    {
        if ($uid <= 0) {
            return '';
        }
        return (string)(Db::name('sys_user')->where('id', $uid)->value('real_name') ?: '');
    }

    /** 社保状态文本 */
    public static function socialText(int $v): string
    {
        $map = [1 => '在缴', 2 => '停缴', 3 => '未缴纳'];
        return $map[$v] ?? '未知';
    }
}
