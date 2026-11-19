<?php
namespace app\service\push;

interface PushDriverInterface
{
    /**
     * @param array  $receiver ['phone'=>,'email'=>,'wecom'=>]
     * @param string $content  已渲染的消息内容
     * @return array ['status'=>1|2,'msg'=>'']
     */
    public function send(array $receiver, string $content): array;
}
