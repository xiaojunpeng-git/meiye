<?php

declare(strict_types=1);

namespace think\facade {
    /** In-memory query double preserves filters; no production or local business rows are written. */
    final class Db
    {
        public static $tables = [];
        public static $reads = [];

        public static function name(string $table): DefaultQuery
        {
            return new DefaultQuery($table);
        }
    }

    final class DefaultQuery
    {
        private $table;
        private $filters = [];

        public function __construct(string $table) { $this->table = $table; }
        public function where($field, $value): self { $this->filters[$field] = $value; return $this; }
        public function order($field, $direction): self { return $this; }
        public function value($field) { $row = $this->find(); return $row[$field] ?? null; }

        /** Apply every predicate so the behavioral cases detect wrong tenant or project identity. */
        public function find(): ?array
        {
            Db::$reads[] = [$this->table, $this->filters];
            foreach (Db::$tables[$this->table] ?? [] as $row) {
                foreach ($this->filters as $field => $value) {
                    if (!array_key_exists($field, $row) || (string)$row[$field] !== (string)$value) {
                        continue 2;
                    }
                }
                return $row;
            }
            return null;
        }
    }
}

namespace {
    require_once dirname(__DIR__, 3)
        . '/后端代码/app/services/cashier/v3/CashierV3ProjectPersonnelDefaults.php';
    require_once dirname(__DIR__, 3)
        . '/后端代码/app/services/cashier/v3/CashierV3ProjectLaborDefault.php';

    use app\services\cashier\v3\CashierV3ProjectLaborDefault;
    use think\facade\Db;

    // Reproduce a platform 5-yuan setting masked by a store copy's legacy zero rule.
    Db::$tables = [
        'store_product' => [
            ['id' => 600451, 'type' => 0, 'product_type' => 6, 'pid' => 0],
            ['id' => 600478, 'type' => 1, 'product_type' => 6, 'pid' => 600451],
            ['id' => 600479, 'type' => 1, 'product_type' => 6, 'pid' => 600451],
        ],
        'cashier_v3_project_performance_rule' => [
            ['tenant_id' => 'other', 'project_id' => 600451, 'labor_configured_unit_amount_cents' => 9999],
            ['tenant_id' => '0', 'project_id' => 600451, 'labor_configured_unit_amount_cents' => 500],
            ['tenant_id' => '0', 'project_id' => 600478, 'labor_configured_unit_amount_cents' => 0],
            ['tenant_id' => '0', 'project_id' => 600479, 'labor_configured_unit_amount_cents' => 800],
        ],
    ];
    $checks = 0;
    $check = static function (string $name, int $actual, int $expected) use (&$checks): void {
        if ($actual !== $expected) throw new \RuntimeException("{$name}: {$actual} != {$expected}");
        ++$checks;
        echo "PASS {$name}\n";
    };
    $check('master setting', CashierV3ProjectLaborDefault::cents(600451, '0'), 500);
    $check('store zero cannot mask master', CashierV3ProjectLaborDefault::cents(600478, '0'), 500);
    $check('store nonzero cannot mask master', CashierV3ProjectLaborDefault::cents(600479, '0'), 500);
    $check('tenant isolation', CashierV3ProjectLaborDefault::cents(600478, 'missing'), 0);
    $check('other tenant uses own setting', CashierV3ProjectLaborDefault::cents(600478, 'other'), 9999);
    $check('unconfigured project', CashierV3ProjectLaborDefault::cents(123, '0'), 0);
    Db::$tables['cashier_v3_project_performance_rule'][1]['labor_configured_unit_amount_cents'] = 0;
    $check('explicit master zero stays zero', CashierV3ProjectLaborDefault::cents(600479, '0'), 0);
    unset(Db::$tables['cashier_v3_project_performance_rule'][1]);
    $check('missing master never uses stale copy', CashierV3ProjectLaborDefault::cents(600479, '0'), 0);
    $reads = count(Db::$reads);
    $check('invalid project', CashierV3ProjectLaborDefault::cents(0, '0'), 0);
    $check('empty tenant', CashierV3ProjectLaborDefault::cents(600478, ''), 0);
    if (count(Db::$reads) !== $reads) throw new \RuntimeException('invalid input queried database');
    echo "{$checks}/{$checks} passed; read-only rule resolution\n";
}
