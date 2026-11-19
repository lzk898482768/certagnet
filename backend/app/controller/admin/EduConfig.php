<?php
namespace app\controller\admin;

use app\service\EduFlowService;
use app\service\EduSchemaService;
use think\facade\Db;

/**
 * 学历报名-进度流程配置（v2.99）
 *
 * 按报名类型（电中/成考）各维护一套可自定义状态流，工单建单时快照。
 *
 *  - GET  /admin/edu-config/flows        各报名类型流程列表
 *  - GET  /admin/edu-config/flow-detail  单条流程详情
 *  - POST /admin/edu-config/flow-save    保存（自定义节点，至少 2 个）
 *  - POST /admin/edu-config/flow-reset   恢复默认流程
 */
class EduConfig extends AdminController
{
    protected function boot(): void
    {
        EduSchemaService::ensureTables();
    }

    /** 各报名类型流程（缺配置时用默认流程兜底返回） */
    public function flows()
    {
        $this->boot();
        $rows = Db::name('edu_flow_config')->where('status', 1)->order('id asc')->select()->toArray();
        $existTypes = [];
        foreach ($rows as &$r) {
            $r['status_flow'] = EduFlowService::normalize($r['status_flow'] ?? null);
            $existTypes[$r['signup_type']] = true;
        }
        unset($r);
        // 兜底：种子未落库的类型也返回默认流程，前端可直接编辑保存
        foreach (['dianzhong', 'chengkao'] as $type) {
            if (!isset($existTypes[$type])) {
                $rows[] = [
                    'id' => 0,
                    'signup_type' => $type,
                    'flow_name' => $type === 'chengkao' ? '成人高考报名流程' : '中专（电中）报名流程',
                    'status_flow' => EduFlowService::defaultFlow($type),
                    'is_default' => true,
                ];
            }
        }
        return json(r_success(['list' => $rows]));
    }

    public function flowDetail()
    {
        $this->boot();
        $type = trim((string)$this->request->param('signup_type', ''));
        if ($type === '') {
            return json(r_error('缺少报名类型'));
        }
        $row = Db::name('edu_flow_config')->where('signup_type', $type)->where('status', 1)->find();
        if ($row) {
            $row['status_flow'] = EduFlowService::normalize($row['status_flow'] ?? null);
            return json(r_success($row));
        }
        return json(r_success([
            'id' => 0,
            'signup_type' => $type,
            'flow_name' => $type === 'chengkao' ? '成人高考报名流程' : '中专（电中）报名流程',
            'status_flow' => EduFlowService::defaultFlow($type),
            'is_default' => true,
        ]));
    }

    public function flowSave()
    {
        $this->boot();
        if (!$this->can('educonfig', 'edit')) {
            return json(r_error('无保存权限', 403));
        }
        $type = trim((string)$this->request->param('signup_type', ''));
        if (!in_array($type, ['dianzhong', 'chengkao'], true)) {
            return json(r_error('报名类型非法'));
        }
        $flow = EduFlowService::normalize($this->request->param('status_flow/a', []));
        if (count($flow) < 2) {
            return json(r_error('进度流程至少需要 2 个节点'));
        }
        // key 唯一性校验
        $keys = array_column($flow, 'key');
        if (count($keys) !== count(array_unique($keys))) {
            return json(r_error('节点标识不能重复'));
        }
        $flowName = trim((string)$this->request->param('flow_name', '')) ?: ($type === 'chengkao' ? '成人高考报名流程' : '中专（电中）报名流程');
        $data = [
            'signup_type' => $type,
            'flow_name'   => mb_substr($flowName, 0, 100),
            'status_flow' => json_encode($flow, JSON_UNESCAPED_UNICODE),
            'update_by'   => (int)$this->request->userId,
            'status'      => 1,
            'update_time' => date('Y-m-d H:i:s'),
        ];
        $exist = Db::name('edu_flow_config')->where('signup_type', $type)->find();
        if ($exist) {
            Db::name('edu_flow_config')->where('id', $exist['id'])->update($data);
            $id = (int)$exist['id'];
        } else {
            $data['create_time'] = date('Y-m-d H:i:s');
            $id = (int)Db::name('edu_flow_config')->insertGetId($data);
        }
        $this->log('学历流程配置', '保存进度流程', $flowName);
        return json(r_success(['id' => $id], '保存成功'));
    }

    public function flowReset()
    {
        $this->boot();
        if (!$this->can('educonfig', 'edit')) {
            return json(r_error('无操作权限', 403));
        }
        $type = trim((string)$this->request->param('signup_type', ''));
        if (!in_array($type, ['dianzhong', 'chengkao'], true)) {
            return json(r_error('报名类型非法'));
        }
        $flow = EduFlowService::defaultFlow($type);
        $data = [
            'flow_name'   => $type === 'chengkao' ? '成人高考报名流程' : '中专（电中）报名流程',
            'status_flow' => json_encode($flow, JSON_UNESCAPED_UNICODE),
            'update_by'   => (int)$this->request->userId,
            'status'      => 1,
            'update_time' => date('Y-m-d H:i:s'),
        ];
        $exist = Db::name('edu_flow_config')->where('signup_type', $type)->find();
        if ($exist) {
            Db::name('edu_flow_config')->where('id', $exist['id'])->update($data);
        } else {
            $data['signup_type'] = $type;
            $data['create_time'] = date('Y-m-d H:i:s');
            Db::name('edu_flow_config')->insert($data);
        }
        $this->log('学历流程配置', '恢复默认流程', $type);
        return json(r_success(['status_flow' => $flow], '已恢复默认流程'));
    }
}
