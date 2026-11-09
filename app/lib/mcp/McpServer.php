<?php

namespace app\lib\mcp;

class McpServer
{
    const SUPPORTED_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    private $registry;

    public function __construct()
    {
        $this->registry = new ToolRegistry();
    }

    /**
     * 处理 HTTP 请求体，返回需要输出的数据；返回 null 表示无需响应内容（仅通知）
     */
    public function handleBody(string $body)
    {
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return $this->error(null, -32700, 'Parse error');
        }

        if (array_is_list($payload)) {
            if (empty($payload)) {
                return $this->error(null, -32600, 'Invalid Request');
            }
            $responses = [];
            foreach ($payload as $message) {
                $res = $this->handleMessage($message);
                if ($res !== null) {
                    $responses[] = $res;
                }
            }
            return empty($responses) ? null : $responses;
        }

        return $this->handleMessage($payload);
    }

    private function handleMessage($message)
    {
        if (!is_array($message) || ($message['jsonrpc'] ?? null) !== '2.0' || !isset($message['method']) || !is_string($message['method'])) {
            if (is_array($message) && !isset($message['method']) && (isset($message['result']) || isset($message['error']))) {
                return null;
            }
            return $this->error($message['id'] ?? null, -32600, 'Invalid Request');
        }

        $isNotification = !array_key_exists('id', $message);
        $id = $message['id'] ?? null;
        $params = isset($message['params']) && is_array($message['params']) ? $message['params'] : [];

        try {
            switch ($message['method']) {
                case 'initialize':
                    $result = $this->initialize($params);
                    break;
                case 'ping':
                    $result = new \stdClass();
                    break;
                case 'tools/list':
                    $result = ['tools' => $this->registry->listTools()];
                    break;
                case 'tools/call':
                    $name = $params['name'] ?? null;
                    if (!is_string($name) || $name === '') {
                        return $isNotification ? null : $this->error($id, -32602, 'Invalid params: name is required');
                    }
                    $arguments = $params['arguments'] ?? [];
                    if (!is_array($arguments)) {
                        return $isNotification ? null : $this->error($id, -32602, 'Invalid params: arguments must be an object');
                    }
                    if (!$this->registry->has($name)) {
                        return $isNotification ? null : $this->error($id, -32602, 'Unknown tool: ' . $name);
                    }
                    $result = $this->registry->call($name, $arguments);
                    break;
                default:
                    if ($isNotification) {
                        return null;
                    }
                    return $this->error($id, -32601, 'Method not found: ' . $message['method']);
            }
        } catch (\Throwable $e) {
            return $isNotification ? null : $this->error($id, -32603, 'Internal error: ' . $e->getMessage());
        }

        if ($isNotification) {
            return null;
        }
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function initialize(array $params)
    {
        $requested = $params['protocolVersion'] ?? '';
        $version = in_array($requested, self::SUPPORTED_VERSIONS, true) ? $requested : self::SUPPORTED_VERSIONS[0];
        return [
            'protocolVersion' => $version,
            'capabilities' => [
                'tools' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name' => 'dnsmgr',
                'title' => '聚合DNS管理系统',
                'version' => (string)config_get('version', '1.0'),
            ],
            'instructions' => '聚合DNS管理系统MCP接口。通常先调用 domain_query(action=list) 获取域名ID，再用 record_query / record_manage 管理解析记录。添加账户或部署任务前可调用 list_provider_types 查看各服务商需要的配置字段。',
        ];
    }

    private function error($id, int $code, string $message)
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
