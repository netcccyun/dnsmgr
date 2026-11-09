<?php

namespace app\lib\mcp;

use think\Response;
use think\response\Json;

class ControllerInvoker
{
    /**
     * 以当前MCP认证用户身份调用后台控制器方法，返回控制器输出的JSON数据
     * @param string $class 控制器类名
     * @param string $method 控制器方法
     * @param array $post 注入的POST/GET参数
     * @param array $route 注入的路由参数（id/action/act等）
     */
    public static function invoke(string $class, string $method, array $post = [], array $route = []): array
    {
        $request = app()->request;
        $post = self::normalize($post);
        $route = self::normalize($route);

        $request->withPost($post);
        $request->withGet($post);
        $request->withServer(array_merge($request->server(), ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']));
        \Closure::bind(function () use ($route) {
            $this->route = $route;
            $this->param = [];
            $this->request = [];
            $this->mergeParam = false;
        }, $request, \think\Request::class)();

        try {
            $controller = new $class(app());
            $response = $controller->$method();
        } catch (\think\exception\HttpResponseException $e) {
            $response = $e->getResponse();
        } catch (\Throwable $e) {
            return ['code' => -1, 'msg' => '执行出错：' . $e->getMessage()];
        }

        if ($response instanceof Json) {
            $data = $response->getData();
        } elseif ($response instanceof Response) {
            return ['code' => -1, 'msg' => '该操作返回了页面内容，不支持通过MCP调用'];
        } elseif ($response === null) {
            return ['code' => -1, 'msg' => '不支持的操作类型或参数错误'];
        } else {
            $data = $response;
        }
        return self::toArray($data);
    }

    /**
     * 将数据（可能包含Collection/Model等对象）转换为纯数组
     */
    public static function toArray($data): array
    {
        $data = json_decode(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), true);
        if (!is_array($data)) {
            return ['result' => $data];
        }
        if (array_is_list($data)) {
            return ['total' => count($data), 'rows' => $data];
        }
        if (isset($data['code']) && $data['code'] == -3 && !isset($data['msg'])) {
            $data['msg'] = '不支持的操作类型';
        }
        unset($data['trace'], $data['stack']);
        return $data;
    }

    public static function isError(array $data): bool
    {
        return isset($data['code']) && is_numeric($data['code']) && intval($data['code']) != 0;
    }

    /**
     * 封装为MCP tools/call 结果
     */
    public static function result(array $data, ?bool $isError = null): array
    {
        if ($isError === null) {
            $isError = self::isError($data);
        }
        $text = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
        $result = [
            'content' => [['type' => 'text', 'text' => $text === false ? '{}' : $text]],
            'structuredContent' => empty($data) ? new \stdClass() : $data,
        ];
        if ($isError) {
            $result['isError'] = true;
        }
        return $result;
    }

    /**
     * 将JSON参数转换为与表单提交一致的格式（布尔转0/1、数字转字符串、移除null）
     */
    private static function normalize(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($value === null) {
                unset($data[$key]);
            } elseif (is_bool($value)) {
                $data[$key] = $value ? '1' : '0';
            } elseif (is_array($value)) {
                $data[$key] = self::normalize($value);
            } elseif (is_float($value) || is_int($value)) {
                $data[$key] = (string)$value;
            }
        }
        return $data;
    }
}
