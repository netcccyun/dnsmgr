<?php

namespace app\lib\mcp;

/**
 * 工具调用的业务错误，会以 isError=true 的工具结果返回给客户端
 */
class McpException extends \Exception
{
}
