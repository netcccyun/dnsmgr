<?php

declare (strict_types=1);

namespace app\lib;

use think\db\ConnectionInterface;

class NewDbManager extends \think\Db
{
    /**
     * 创建数据库连接实例
     * @access protected
     * @param string|null $name  连接标识
     * @param bool        $force 强制重新连接
     * @return ConnectionInterface
     */
    protected function instance(string $name = null, bool $force = false): ConnectionInterface
    {
        if (empty($name)) {
            $name = $this->getConfig('default', 'mysql');
        }

        return $this->createConnection($name);
    }
}
