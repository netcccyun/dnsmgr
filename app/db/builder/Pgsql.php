<?php
declare(strict_types=1);

namespace app\db\builder;

use think\db\builder\Pgsql as BasePgsql;
use think\db\BaseQuery;

/**
 * PHP 的 pdo_pgsql 把所有 ? 默认按 text 发送，配合 think-orm 默认的
 * `INSERT INTO t (cols) SELECT ?,? UNION ALL SELECT ?,?` 模板会让 PG
 * 推断成 text 列，遇到 int/smallint 列即报 42804。改为多行 VALUES，
 * PG 可由 INSERT 目标列直接推断参数类型。
 */
class Pgsql extends BasePgsql
{
    protected $insertAllSql = 'INSERT INTO %TABLE% (%FIELD%) VALUES %DATA% %COMMENT%';

    /**
     * 让 LIKE 行为与 MySQL 默认一致：PG 的 LIKE 区分大小写，
     * 替换为 ILIKE 以恢复用户搜索时的预期行为。
     */
    protected function parseLike(BaseQuery $query, string $key, string $exp, $value, $field, int $bindType, ?string $logic = null): string
    {
        $exp = preg_replace('/\bLIKE\b/i', 'ILIKE', $exp);
        return parent::parseLike($query, $key, $exp, $value, $field, $bindType, $logic);
    }

    public function insertAll(BaseQuery $query, array $dataSet): string
    {
        $options = $query->getOptions();
        $bind = $query->getFieldsBindType();

        if (empty($options['field']) || '*' == $options['field']) {
            $allowFields = array_keys($bind);
        } else {
            $allowFields = $options['field'];
        }

        $values = [];
        $insertFields = null;
        foreach ($dataSet as $data) {
            $data = $this->parseData($query, $data, $allowFields, $bind);
            $values[] = '(' . implode(',', array_values($data)) . ')';
            if ($insertFields === null) {
                $insertFields = array_keys($data);
            }
        }

        $fields = [];
        foreach ((array) $insertFields as $field) {
            $fields[] = $this->parseKey($query, $field);
        }

        return str_replace(
            ['%INSERT%', '%TABLE%', '%EXTRA%', '%FIELD%', '%DATA%', '%COMMENT%'],
            [
                !empty($options['replace']) ? 'REPLACE' : 'INSERT',
                $this->parseTable($query, $options['table']),
                $this->parseExtra($query, $options['extra']),
                implode(' , ', $fields),
                implode(',', $values),
                $this->parseComment($query, $options['comment']),
            ],
            $this->insertAllSql
        );
    }

    public function insertAllByKeys(BaseQuery $query, array $keys, array $datas): string
    {
        $options = $query->getOptions();
        $bind = $query->getFieldsBindType();

        $fields = [];
        foreach ($keys as $field) {
            $fields[] = $this->parseKey($query, $field);
        }

        $values = [];
        foreach ($datas as $data) {
            foreach ($data as $idx => &$val) {
                $col = $keys[$idx] ?? null;
                if (!$query->isAutoBind()) {
                    $val = ($col !== null && \think\db\Connection::PARAM_STR == ($bind[$col] ?? \think\db\Connection::PARAM_STR))
                        ? '\'' . $val . '\''
                        : $val;
                } else {
                    $val = $this->parseDataBind($query, $col ?? (string) $idx, $val, $bind);
                }
            }
            unset($val);
            $values[] = '(' . implode(',', $data) . ')';
        }

        return str_replace(
            ['%INSERT%', '%TABLE%', '%EXTRA%', '%FIELD%', '%DATA%', '%COMMENT%'],
            [
                !empty($options['replace']) ? 'REPLACE' : 'INSERT',
                $this->parseTable($query, $options['table']),
                $this->parseExtra($query, $options['extra']),
                implode(' , ', $fields),
                implode(',', $values),
                $this->parseComment($query, $options['comment']),
            ],
            $this->insertAllSql
        );
    }
}
