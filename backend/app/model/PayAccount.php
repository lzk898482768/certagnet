<?php
namespace app\model;

use think\Model;

/**
 * 收款账户（v2.16 创建，v2.18 扩展）
 *  - 支持多种收款渠道：对公银行账户 / 对私银行账户 / 微信 / 支付宝 / 现金 / 其他
 *  - 支持初始余额；当前余额 = 初始余额 + 已收(结算) − 已付(结算)（控制器计算）
 *  - 用于费用台账「结算方式」、合同收款等场景直接选择
 */
class PayAccount extends Model
{
    protected $name = 'pay_account';
    protected $autoWriteTimestamp = true;

    /** 账户类型（渠道）字典 */
    public const TYPES = [
        'bank_public'  => '对公银行账户',
        'bank_private' => '对私银行账户',
        'wechat'       => '微信',
        'alipay'       => '支付宝',
        'cash'         => '现金',
        'other'        => '其他',
    ];

    /** 是否银行类账户（对公/对私） */
    public static function isBank(string $type): bool
    {
        return in_array($type, ['bank_public', 'bank_private', 'public', 'private'], true);
    }

    protected $append = ['account_type_text', 'status_text'];

    public function getAccountTypeTextAttr($value, $data): string
    {
        $t = (string)($data['account_type'] ?? 'bank_public');
        // 兼容 v2.16 旧值
        if ($t === 'public')  return '对公银行账户';
        if ($t === 'private') return '对私银行账户';
        return self::TYPES[$t] ?? $t;
    }

    public function getStatusTextAttr($value, $data): string
    {
        return (int)($data['status'] ?? 1) === 1 ? '启用' : '停用';
    }
}
