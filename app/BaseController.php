<?php
declare (strict_types = 1);

namespace app;

use app\lib\DnsHelper;
use think\App;
use think\exception\ValidateException;
use think\Validate;
use think\facade\Db;
use think\facade\View;

/**
 * 控制器基础类
 */
abstract class BaseController
{
    /**
     * Request实例
     * @var \think\Request
     */
    protected $request;

    /**
     * 应用实例
     * @var \think\App
     */
    protected $app;

    /**
     * 是否批量验证
     * @var bool
     */
    protected $batchValidate = false;

    /**
     * 控制器中间件
     * @var array
     */
    protected $middleware = [];

    protected $clientip;

    /**
     * 构造方法
     * @access public
     * @param  App  $app  应用对象
     */
    public function __construct(App $app)
    {
        $this->app     = $app;
        $this->request = $this->app->request;

        // 控制器初始化
        $this->initialize();
    }

    // 初始化
    protected function initialize()
    {
        $this->clientip = real_ip(env('app.ip_type', 0));
    }

    /**
     * 验证数据
     * @access protected
     * @param  array        $data     数据
     * @param  string|array $validate 验证器名或者验证规则数组
     * @param  array        $message  提示信息
     * @param  bool         $batch    是否批量验证
     * @return array|string|true
     * @throws ValidateException
     */
    protected function validate(array $data, $validate, array $message = [], bool $batch = false)
    {
        if (is_array($validate)) {
            $v = new Validate();
            $v->rule($validate);
        } else {
            if (strpos($validate, '.')) {
                // 支持场景
                [$validate, $scene] = explode('.', $validate);
            }
            $class = false !== strpos($validate, '\\') ? $validate : $this->app->parseClass('validate', $validate);
            $v     = new $class();
            if (!empty($scene)) {
                $v->scene($scene);
            }
        }

        $v->message($message);

        // 是否批量验证
        if ($batch || $this->batchValidate) {
            $v->batch(true);
        }

        return $v->failException(true)->check($data);
    }

    protected function getManagedDomainOptions(?string $type = null): array
    {
        if (!checkPermission(1)) {
            return [];
        }

        $query = Db::name('domain')->alias('A')
            ->join('account B', 'A.aid = B.id')
            ->field('A.id,A.name,B.type');
        if (!empty($type)) {
            $query->where('B.type', $type);
        }
        if (request()->user['level'] == 1) {
            $query->where('A.is_hide', 0)->where('A.name', 'in', request()->user['permission']);
        }

        $rows = $query->order('A.name', 'asc')->select();
        $list = [];
        foreach ($rows as $row) {
            $typeName = DnsHelper::$dns_config[$row['type']]['name'] ?? strtoupper((string)$row['type']);
            $list[] = [
                'id' => intval($row['id']),
                'name' => $row['name'],
                'type' => $row['type'],
                'text' => $row['name'] . ' [' . $typeName . ']',
            ];
        }
        return $list;
    }


    protected function alert($code, $msg = '', $url = null, $wait = 3)
    {
        if ($url) {
            $url = (strpos($url, '://') || 0 === strpos($url, '/')) ? $url : (string)$this->app->route->buildUrl($url);
        }
        if (empty($msg)) {
            $msg = '未知错误';
        }

        if ($this->request->isApi) {
            return json(['code' => $code == 'success' ? 0 : -1, 'msg' => $msg]);
        }
        if ($this->request->isAjax()) {
            return json(['code' => $code == 'success' ? 0 : -1, 'msg' => $msg, 'url' => $url]);
        }

        View::assign([
            'code' => $code,
            'msg' => $msg,
            'url' => $url,
            'wait' => $wait,
        ]);
        return View::fetch(app()->getBasePath().'view/dispatch_jump.html');
    }
}
