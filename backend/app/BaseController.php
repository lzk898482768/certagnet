<?php
namespace app;

use think\App;
use think\exception\ValidateException;
use think\Request;
use think\Validate;

/**
 * 控制器基础类
 */
abstract class BaseController
{
    protected $app;
    protected $request;

    public function __construct(App $app, Request $request)
    {
        $this->app     = $app;
        $this->request = $request;
    }

    /**
     * 验证数据
     */
    protected function validate(array $data, $validate)
    {
        if (is_string($validate)) {
            $v = new $validate();
        } else {
            $v = new Validate();
            $v->rule($validate);
        }
        if (!$v->check($data)) {
            throw new ValidateException($v->getError());
        }
    }
}
