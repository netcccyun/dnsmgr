<?php

namespace app\lib;

use think\Facade;

class NewDb extends Facade
{
    /**
     * 获取当前Facade对应类名（或者已经绑定的容器对象标识）
     * @access protected
     * @return string
     */
    protected static function getFacadeClass()
    {
        return 'app\lib\NewDbManager';
    }
}
