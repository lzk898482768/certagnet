<?php
namespace app\service;

/**
 * 身份证服务：校验位校验 / 出生日期 / 性别 / 年龄
 * 移植自 exam-reg IdCardService（去掉脱敏开关依赖，脱敏统一由本系统 MaskService 处理）。
 *
 * 18 位：6 位地区 + 8 位出生日期 + 3 位顺序 + 1 位校验位；兼容 15 位旧号（补全为 18 位）。
 */
class ExamIdCardService
{
    /** 加权因子 */
    private const WEIGHTS = [7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2];

    /** 校验码表 */
    private const CHECK_CODES = ['1', '0', 'X', '9', '8', '7', '6', '5', '4', '3', '2'];

    /** 省级行政区码（前两位） */
    private const PROVINCES = [
        '11' => '北京', '12' => '天津', '13' => '河北', '14' => '山西', '15' => '内蒙古',
        '21' => '辽宁', '22' => '吉林', '23' => '黑龙江', '31' => '上海', '32' => '江苏',
        '33' => '浙江', '34' => '安徽', '35' => '福建', '36' => '江西', '37' => '山东',
        '41' => '河南', '42' => '湖北', '43' => '湖南', '44' => '广东', '45' => '广西',
        '46' => '海南', '50' => '重庆', '51' => '四川', '52' => '贵州', '53' => '云南',
        '54' => '西藏', '61' => '陕西', '62' => '甘肃', '63' => '青海', '64' => '宁夏',
        '65' => '新疆', '71' => '台湾', '81' => '香港', '82' => '澳门',
    ];

    /**
     * 解析并校验身份证号
     * @return array{ok:bool,msg:string,id_card:string,gender:int,birthday:string,age:int,province:string}
     */
    public static function parse(string $id): array
    {
        $id  = strtoupper(trim($id));
        $bad = ['ok' => false, 'msg' => '身份证号格式不正确', 'id_card' => $id, 'gender' => 0, 'birthday' => '', 'age' => 0, 'province' => ''];

        // 15 位旧号 → 补全为 18 位
        if (preg_match('/^\d{15}$/', $id)) {
            $id = substr($id, 0, 6) . '19' . substr($id, 6, 9);
            $id .= self::checkCode($id);
        }

        if (!preg_match('/^\d{17}[\dX]$/', $id)) {
            return $bad;
        }
        if (self::checkCode($id) !== $id[17]) {
            $bad['msg'] = '身份证号校验位不正确';
            return array_merge($bad, ['id_card' => $id]);
        }

        $y = (int)substr($id, 6, 4);
        $m = (int)substr($id, 10, 2);
        $d = (int)substr($id, 12, 2);
        if (!checkdate($m, $d, $y) || $y < 1900 || $y > (int)date('Y')) {
            $bad['msg'] = '身份证号出生日期不正确';
            return array_merge($bad, ['id_card' => $id]);
        }

        $birthday = sprintf('%04d-%02d-%02d', $y, $m, $d);
        $gender   = ((int)$id[16]) % 2 === 1 ? 1 : 2;

        return [
            'ok'       => true,
            'msg'      => 'ok',
            'id_card'  => $id,
            'gender'   => $gender,
            'birthday' => $birthday,
            'age'      => self::age($birthday),
            'province' => self::PROVINCES[substr($id, 0, 2)] ?? '',
        ];
    }

    /** 由出生日期计算年龄 */
    public static function age(string $birthday): int
    {
        $ts = strtotime($birthday);
        if (!$ts) {
            return 0;
        }
        $b = new \DateTime(date('Y-m-d', $ts));
        $n = new \DateTime(date('Y-m-d'));
        return (int)$b->diff($n)->y;
    }

    /** 计算校验码 */
    private static function checkCode(string $id17): string
    {
        $sum = 0;
        for ($i = 0; $i < 17; $i++) {
            $sum += (int)$id17[$i] * self::WEIGHTS[$i];
        }
        return self::CHECK_CODES[$sum % 11];
    }
}
