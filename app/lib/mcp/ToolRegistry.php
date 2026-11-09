<?php

namespace app\lib\mcp;

use think\facade\Db;

/**
 * MCP工具注册表
 *
 * 工具定义位于 tools/*.php，每个文件返回工具数组。每个工具包含：
 *   name, title, description, readOnly, destructive, properties, required, actions
 * actions 为 动作名 => 动作定义：
 *   desc      动作说明
 *   level     所需最低用户等级（1普通用户，2管理员），默认2
 *   call      [控制器类, 方法]
 *   route     路由参数，值以@开头表示取自同名参数，否则为常量
 *   defaults  参数默认值
 *   json      需要编码为JSON字符串的参数
 *   before    fn(array $args): array 调用前处理参数
 *   after     fn(array $data, array $args): array 调用后处理结果
 *   handler   fn(array $args): array 自定义处理（不调用控制器）
 */
class ToolRegistry
{
    private $tools = [];

    public function __construct()
    {
        foreach (glob(__DIR__ . '/tools/*.php') as $file) {
            foreach (require $file as $tool) {
                $this->tools[$tool['name']] = $tool;
            }
        }
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function listTools(): array
    {
        $list = [];
        foreach ($this->tools as $tool) {
            $actions = $this->availableActions($tool);
            if (empty($actions)) continue;
            $list[] = $this->buildSchema($tool, $actions);
        }
        return $list;
    }

    public function call(string $name, array $args): array
    {
        $tool = $this->tools[$name];
        $actions = $tool['actions'];
        $actionName = $args['action'] ?? null;
        if ($actionName === null && count($actions) == 1) {
            $actionName = array_key_first($actions);
        }
        if (!is_string($actionName) || !isset($actions[$actionName])) {
            return ControllerInvoker::result(['code' => -1, 'msg' => 'action 参数无效，可选值：' . implode(', ', array_keys($actions))], true);
        }
        $action = $actions[$actionName];
        if (self::userLevel() < ($action['level'] ?? 2)) {
            return ControllerInvoker::result(['code' => -1, 'msg' => '无权限'], true);
        }
        unset($args['action']);

        try {
            foreach ($action['defaults'] ?? [] as $key => $value) {
                if (!isset($args[$key]) || $args[$key] === '') {
                    $args[$key] = $value;
                }
            }
            if (isset($action['before'])) {
                $args = $action['before']($args);
            }
            foreach ($action['json'] ?? [] as $key) {
                if (isset($args[$key]) && is_array($args[$key])) {
                    $args[$key] = json_encode($args[$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }

            if (isset($action['handler'])) {
                $data = ControllerInvoker::toArray($action['handler']($args));
            } else {
                $route = [];
                foreach ($action['route'] ?? [] as $key => $value) {
                    if (is_string($value) && str_starts_with($value, '@')) {
                        $value = $args[substr($value, 1)] ?? null;
                    }
                    if ($value !== null) {
                        $route[$key] = $value;
                    }
                }
                [$class, $method] = $action['call'];
                $data = ControllerInvoker::invoke($class, $method, $args, $route);
            }

            if (isset($action['after']) && !ControllerInvoker::isError($data)) {
                $data = $action['after']($data, $args);
            }
        } catch (McpException $e) {
            $data = ['code' => -1, 'msg' => $e->getMessage()];
        } catch (\Throwable $e) {
            $data = ['code' => -1, 'msg' => '执行出错：' . $e->getMessage()];
        }

        return ControllerInvoker::result($data);
    }

    private function availableActions(array $tool): array
    {
        $level = self::userLevel();
        return array_filter($tool['actions'], function ($action) use ($level) {
            return $level >= ($action['level'] ?? 2);
        });
    }

    private function buildSchema(array $tool, array $actions): array
    {
        $properties = [];
        $required = $tool['required'] ?? [];
        $description = $tool['description'];
        if (count($tool['actions']) > 1) {
            $properties['action'] = [
                'type' => 'string',
                'enum' => array_keys($actions),
                'description' => '要执行的操作',
            ];
            array_unshift($required, 'action');
            $description .= "\n\naction 可选值：";
            foreach ($actions as $key => $action) {
                $description .= "\n- " . $key . '：' . $action['desc'];
            }
        }
        foreach ($tool['properties'] ?? [] as $key => $prop) {
            $properties[$key] = $prop;
        }

        $schema = ['type' => 'object', 'properties' => empty($properties) ? new \stdClass() : $properties];
        if (!empty($required)) {
            $schema['required'] = array_values(array_unique($required));
        }

        return [
            'name' => $tool['name'],
            'title' => $tool['title'],
            'description' => $description,
            'inputSchema' => $schema,
            'annotations' => [
                'title' => $tool['title'],
                'readOnlyHint' => $tool['readOnly'] ?? false,
                'destructiveHint' => $tool['destructive'] ?? false,
                'openWorldHint' => true,
            ],
        ];
    }

    public static function userLevel(): int
    {
        $user = request()->user;
        if (empty($user) || ($user['type'] ?? '') != 'user') return 0;
        return intval($user['level']);
    }

    /* ---------- 工具定义辅助方法 ---------- */

    public static function str(string $desc, array $enum = []): array
    {
        $p = ['type' => 'string', 'description' => $desc];
        if (!empty($enum)) $p['enum'] = $enum;
        return $p;
    }

    public static function int(string $desc): array
    {
        return ['type' => 'integer', 'description' => $desc];
    }

    public static function bool(string $desc): array
    {
        return ['type' => 'boolean', 'description' => $desc];
    }

    public static function obj(string $desc): array
    {
        return ['type' => 'object', 'description' => $desc];
    }

    public static function arr(string $desc, array $items = ['type' => 'string']): array
    {
        return ['type' => 'array', 'items' => $items, 'description' => $desc];
    }

    public static function ids(string $desc = 'ID列表'): array
    {
        return self::arr($desc, ['type' => 'integer']);
    }

    /** 分页与排序参数 */
    public static function paging(): array
    {
        return [
            'offset' => self::int('分页偏移量，默认0'),
            'limit' => self::int('每页数量，默认20'),
            'sortName' => self::str('排序字段'),
            'sortOrder' => self::str('排序方向', ['asc', 'desc']),
        ];
    }

    public static function pagingDefaults(): array
    {
        return ['offset' => 0, 'limit' => 20];
    }

    public static function requireArgs(array $args, array $keys): void
    {
        foreach ($keys as $key) {
            if (!isset($args[$key]) || $args[$key] === '' || $args[$key] === []) {
                throw new McpException('缺少必填参数：' . $key);
            }
        }
    }

    /**
     * 编辑操作时，用数据库中的现有值补全未传入的字段，使AI只需传入要修改的字段。
     * config 字段若传入对象，会与原有配置合并。
     */
    public static function prefill(string $table, array $fields, string $idKey = 'id'): \Closure
    {
        return function (array $args) use ($table, $fields, $idKey) {
            self::requireArgs($args, [$idKey]);
            $row = Db::name($table)->where('id', intval($args[$idKey]))->find();
            if (!$row) {
                throw new McpException('记录不存在（ID：' . $args[$idKey] . '）');
            }
            foreach ($fields as $field) {
                if (!array_key_exists($field, $args)) {
                    if ($row[$field] !== null) $args[$field] = $row[$field];
                } elseif ($field == 'config' && is_array($args[$field])) {
                    $old = json_decode((string)$row[$field], true);
                    if (is_array($old)) {
                        $args[$field] = array_merge($old, $args[$field]);
                    }
                }
            }
            return $args;
        };
    }

    /**
     * 账户名称默认取配置中的第一个字段值（与后台表单行为一致）
     */
    public static function accountNameFromConfig(array $args): array
    {
        if (empty($args['name']) && isset($args['config'])) {
            $config = is_array($args['config']) ? $args['config'] : json_decode((string)$args['config'], true);
            if (is_array($config) && !empty($config)) {
                $first = reset($config);
                if (is_scalar($first)) $args['name'] = (string)$first;
            }
        }
        return $args;
    }

    /**
     * 补全解析记录对象中的字段，避免批量操作时缺少字段
     */
    public static function normalizeRecords($records): array
    {
        if (!is_array($records) || empty($records)) {
            throw new McpException('records 不能为空，请传入 record_query(action=list) 返回的记录对象数组');
        }
        $defaults = ['RecordId' => '', 'Name' => '', 'Type' => '', 'Value' => '', 'Line' => '', 'TTL' => 600, 'MX' => 0, 'Weight' => null, 'Remark' => null];
        $list = [];
        foreach ($records as $record) {
            if (!is_array($record) || empty($record['RecordId'])) {
                throw new McpException('records 中的每条记录必须包含 RecordId');
            }
            $list[] = array_merge($defaults, $record);
        }
        return $list;
    }
}
