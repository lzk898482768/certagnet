<?php
namespace app\model;

use think\Model;

class Certificate extends Model
{
    protected $name = 'certificate';
    protected $autoWriteTimestamp = true;

    // 让访问器随 toArray() 输出（含被其他模型预载嵌套时的序列化）
    protected $append = ['cert_status_text', 'social_status_text', 'remain_days'];

    public function staff()
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }

    /** 该证书的全部借调历史 */
    public function secondments()
    {
        return $this->hasMany(CertSecondment::class, 'cert_id');
    }

    /** 当前生效中的借调记录（决定证书现在归哪家企业使用） */
    public function activeSecondment()
    {
        return $this->hasOne(CertSecondment::class, 'cert_id')->where('status', 1);
    }

    public function getCertStatusTextAttr($value, $data): string
    {
        $map = [1 => '正常', 0 => '已过期', 2 => '挂失', 3 => '注销'];
        return $map[$data['cert_status']] ?? '未知';
    }

    /** 社保状态：0未知 1在缴 2停缴 3未缴纳 */
    public function getSocialStatusTextAttr($value, $data): string
    {
        $map = [1 => '在缴', 2 => '停缴', 3 => '未缴纳'];
        return $map[(int)($data['social_status'] ?? 0)] ?? '未知';
    }

    public function getPriorityTextAttr($value, $data): string
    {
        return $data['priority'] ?? '普通';
    }

    /** 距离到期的天数；到期日为空表示长期有效，返回 null */
    public function getRemainDaysAttr($value, $data): ?int
    {
        if (empty($data['expire_date'])) {
            return null;
        }
        $exp = strtotime((string)$data['expire_date']);
        if ($exp === false) {
            return null;
        }
        return (int)ceil(($exp - time()) / 86400);
    }

    public function getAlertRulesAttr($value)
    {
        return $value ? json_decode($value, true) : [];
    }

    public function setAlertRulesAttr($value)
    {
        return is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value;
    }
}
