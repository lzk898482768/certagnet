<?php
namespace app\service;

use app\model\OrgDepartment;
use app\model\SysUser;
use app\model\WecomUser;
use think\facade\Db;

/**
 * 企业微信通讯录同步服务
 *
 *  - getDepartments / getUsers：拉取企微部门与成员
 *  - sync：把企微部门树 upsert 进 org_department（source=1），
 *          成员 upsert 进 wecom_user（与登录账号 sys_user 解耦）
 *  - DEV 模式：CorpID 以 DEV_ 开头时不连企微，返回沙箱部门/成员，便于离线联调
 */
class WecomContactService
{
    /**
     * 拉取部门列表（企微 department/list 返回平铺数组，含 parentid）
     * @return array [{id,name,parentid,order}, ...]
     */
    public static function getDepartments(array $cfg): array
    {
        if (WecomClient::isDev($cfg)) {
            return self::devDepartments();
        }
        $token = WecomClient::getAccessToken($cfg);
        $resp  = WecomClient::httpGet(WecomClient::WECOM_API . '/department/list?access_token=' . urlencode($token));
        if (($resp['errcode'] ?? -1) !== 0) {
            throw new \Exception('拉取部门失败: ' . ($resp['errmsg'] ?? 'unknown'));
        }
        return $resp['department'] ?? [];
    }

    /**
     * 拉取成员列表（根部门递归子部门，拿到完整通讯录）
     * @return array [{userid,name,mobile,email,avatar,position,gender,department:[...],is_leader_in_dept:[...],status}, ...]
     */
    public static function getUsers(array $cfg): array
    {
        if (WecomClient::isDev($cfg)) {
            return self::devUsers();
        }
        $token = WecomClient::getAccessToken($cfg);
        $resp  = WecomClient::httpGet(
            WecomClient::WECOM_API . '/user/list?access_token=' . urlencode($token)
            . '&department_id=1&fetch_child=1'
        );
        if (($resp['errcode'] ?? -1) !== 0) {
            throw new \Exception('拉取成员失败: ' . ($resp['errmsg'] ?? 'unknown'));
        }
        return $resp['userlist'] ?? [];
    }

    /**
     * 执行同步
     * @param bool $dryRun true=只统计不写库
     * @return array 同步统计
     */
    public static function sync(bool $dryRun = false): array
    {
        $cfg = WecomClient::loadConfig();
        if (empty($cfg['wecom_corp_id']) || empty($cfg['wecom_app_secret'])) {
            throw new \Exception('请先在「企微登录配置」填写 CorpID 与 Secret');
        }
        $depts = self::getDepartments($cfg);
        $users = self::getUsers($cfg);

        $stat = [
            'dept_total' => count($depts),
            'user_total' => count($users),
            'dept_created' => 0, 'dept_updated' => 0,
            'user_created' => 0, 'user_updated' => 0,
            'mode' => WecomClient::isDev($cfg) ? 'dev' : 'prod',
        ];
        if ($dryRun) {
            return $stat;
        }

        // 1) 部门：先全部 upsert（parent_id 暂置 0），建立 企微id -> 本地id 映射
        $map = [];
        foreach ($depts as $d) {
            $wecomId = (int)$d['id'];
            $name    = $d['name'] ?? '';
            $order   = (int)($d['order'] ?? 0);
            $row = OrgDepartment::where('wecom_dept_id', $wecomId)
                ->where('source', 1)
                ->find();
            if ($row) {
                $row->save(['name' => $name, 'wecom_order' => $order, 'wecom_raw' => json_encode($d, JSON_UNESCAPED_UNICODE)]);
                $stat['dept_updated']++;
                $localId = $row->id;
            } else {
                $m = OrgDepartment::create([
                    'parent_id'    => 0,
                    'name'         => $name,
                    'wecom_dept_id'=> $wecomId,
                    'wecom_order'  => $order,
                    'source'       => 1,
                    'status'       => 1,
                    'wecom_raw'    => json_encode($d, JSON_UNESCAPED_UNICODE),
                ]);
                $stat['dept_created']++;
                $localId = $m->id;
            }
            $map[$wecomId] = $localId;
        }
        // 2) 回填 parent_id（父部门的本地 id）
        foreach ($depts as $d) {
            $parentWecom = (int)($d['parentid'] ?? 0);
            $localId     = $map[(int)$d['id']] ?? 0;
            $parentLocal = ($parentWecom && isset($map[$parentWecom])) ? $map[$parentWecom] : 0;
            if ($localId) {
                OrgDepartment::where('id', $localId)->update(['parent_id' => $parentLocal]);
            }
        }

        // 3) 成员 upsert
        foreach ($users as $u) {
            $userid = $u['userid'] ?? '';
            if (!$userid) continue;
            $row = WecomUser::where('wecom_userid', $userid)->find();
            $data = [
                'name'      => $u['name'] ?? '',
                'mobile'    => $u['mobile'] ?? '',
                'email'     => $u['email'] ?? '',
                'avatar'    => $u['avatar'] ?? '',
                'position'  => $u['position'] ?? '',
                'gender'    => (int)($u['gender'] ?? 0),
                'dept_ids'  => json_encode($u['department'] ?? [], JSON_UNESCAPED_UNICODE),
                'is_leader' => json_encode($u['is_leader_in_dept'] ?? [], JSON_UNESCAPED_UNICODE),
                'status'    => (int)($u['status'] ?? 1),
                'last_sync' => date('Y-m-d H:i:s'),
            ];
            if ($row) {
                $row->save($data);
                $stat['user_updated']++;
            } else {
                $row = WecomUser::create(array_merge(['wecom_userid' => $userid], $data));
                $stat['user_created']++;
            }

            // 4) 已绑定系统账号 → 同步多部门关联（v2.14 部门数据权限实时生效）
            $boundUid = (int)($row->bind_user_id ?: SysUser::where('wecom_userid', $userid)->value('id') ?: 0);
            if ($boundUid > 0) {
                $localDeptIds = [];
                foreach ((array)($u['department'] ?? []) as $wecomDeptId) {
                    $lid = (int)($map[(int)$wecomDeptId] ?? 0);
                    if ($lid > 0 && !in_array($lid, $localDeptIds, true)) {
                        $localDeptIds[] = $lid;
                    }
                }
                if ($localDeptIds) {
                    Db::name('user_dept_rel')->where('user_id', $boundUid)->delete();
                    foreach (array_values($localDeptIds) as $i => $did) {
                        Db::name('user_dept_rel')->insert([
                            'user_id'     => $boundUid,
                            'dept_id'     => $did,
                            'is_primary'  => $i === 0 ? 1 : 0,
                            'create_time' => date('Y-m-d H:i:s'),
                        ]);
                    }
                    // 主部门未设置时回写，不覆盖管理员手动配置
                    $cur = (int)(SysUser::where('id', $boundUid)->value('dept_id') ?: 0);
                    if ($cur === 0) {
                        SysUser::where('id', $boundUid)->update(['dept_id' => $localDeptIds[0]]);
                    }
                }
            }
        }

        // 部门树/数据权限缓存立即失效
        \app\service\DeptScopeService::clearCache();
        return $stat;
    }

    // ============ DEV 沙箱数据 ============
    private static function devDepartments(): array
    {
        return [
            ['id' => 1, 'name' => '开发有限公司', 'parentid' => 0, 'order' => 1],
            ['id' => 2, 'name' => '技术部',       'parentid' => 1, 'order' => 1],
            ['id' => 3, 'name' => '市场部',       'parentid' => 1, 'order' => 2],
        ];
    }

    private static function devUsers(): array
    {
        return [
            ['userid' => 'dev_admin',  'name' => '开发管理员', 'mobile' => '13800000001', 'email' => 'admin@dev.com',  'position' => 'CEO',    'gender' => 1, 'department' => [1, 2], 'is_leader_in_dept' => [1, 0], 'status' => 1, 'avatar' => ''],
            ['userid' => 'dev_user01', 'name' => '李四',       'mobile' => '13800000002', 'email' => 'lisi@dev.com',  'position' => '工程师', 'gender' => 1, 'department' => [2],    'is_leader_in_dept' => [0],    'status' => 1, 'avatar' => ''],
            ['userid' => 'dev_user02', 'name' => '王五',       'mobile' => '13800000003', 'email' => 'wang@dev.com',  'position' => '经理',   'gender' => 2, 'department' => [3],    'is_leader_in_dept' => [1],    'status' => 1, 'avatar' => ''],
        ];
    }
}
