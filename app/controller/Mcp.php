<?php

namespace app\controller;

use app\BaseController;
use app\lib\mcp\McpServer;

class Mcp extends BaseController
{
    public function handle()
    {
        $origin = $this->request->header('origin', '');
        if ($origin !== '' && strcasecmp((string)parse_url($origin, PHP_URL_HOST), $this->request->host(true)) !== 0) {
            return $this->rpcError(-32000, 'Origin not allowed', 403);
        }

        if (!$this->request->isPost()) {
            return $this->rpcError(-32000, 'Method not allowed', 405)->header(['Allow' => 'POST']);
        }

        $body = $this->request->getContent();
        $server = new McpServer();
        $result = $server->handleBody($body);
        if ($result === null) {
            return response('', 202);
        }
        return json($result, 200, [], ['json_encode_param' => JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES]);
    }

    private function rpcError($code, $message, $httpCode)
    {
        return json([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => $code, 'message' => $message],
        ])->code($httpCode);
    }
}
