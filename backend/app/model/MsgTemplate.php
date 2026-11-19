<?php
namespace app\model;

use think\Model;

class MsgTemplate extends Model
{
    protected $name = 'msg_template';
    protected $autoWriteTimestamp = true;

    /**
     * 渲染模板：将 {{变量}} 替换为真实值
     */
    public static function render(string $content, array $data): string
    {
        foreach ($data as $key => $val) {
            $content = str_replace('{{' . $key . '}}', (string)$val, $content);
        }
        return $content;
    }
}
