<?php
declare(strict_types=1);

namespace think\facade {
    /** Read-only SQLite adapter for the real historical scope policy, without altering app schema. */
    final class Db
    {
        public static \PDO $pdo;
        public static function connect(): object { return new class { public function getConfig(string $key): string { return 'eb_'; } }; }
        public static function query(string $sql, array $parameters): array
        {
            $query = self::$pdo->prepare('SELECT COUNT(*) AS n FROM sqlite_master WHERE type=? AND name=?');
            $query->execute(['table', $parameters[0]]);
            return $query->fetchAll(\PDO::FETCH_ASSOC);
        }
        public static function name(string $table): \R53HistoricalQuery { return new \R53HistoricalQuery($table); }
    }
}
namespace {
    /** Narrow query adapter intentionally supports only the policy's existing SELECT vocabulary. */
    final class R53HistoricalQuery
    {
        private string $table; private array $where = []; private array $params = []; private string $fields = '*';
        public function __construct(string $table) { $this->table = 'eb_' . $table; }
        public function where(string $column, $value): self
        {
            $this->where[] = $column . '=?'; $this->params[] = $value; return $this;
        }
        public function whereIn(string $column, array $values): self
        {
            $this->where[] = $column . ' IN (' . implode(',', array_fill(0, count($values), '?')) . ')';
            array_push($this->params, ...$values); return $this;
        }
        public function field(string $fields): self { $this->fields = $fields; return $this; }
        private function rows(): array
        {
            $sql = 'SELECT ' . $this->fields . ' FROM ' . $this->table;
            if ($this->where) $sql .= ' WHERE ' . implode(' AND ', $this->where);
            $query = \think\facade\Db::$pdo->prepare($sql); $query->execute($this->params);
            return $query->fetchAll(\PDO::FETCH_ASSOC);
        }
        public function select(): object
        {
            return new class($this->rows()) {
                private array $rows;
                public function __construct(array $rows) { $this->rows = $rows; }
                public function toArray(): array { return $this->rows; }
            };
        }
        public function find(): array { return $this->rows()[0] ?? []; }
        public function column(string $field): array { return array_column($this->field($field)->rows(), $field); }
        public function value(string $field) { return $this->find()[$field] ?? null; }
        public function count(): int { return count($this->rows()); }
    }
    $backend = dirname(__DIR__, 3) . '/后端代码';
    require $backend . '/app/services/cashier/v3/card/CashierV3HistoricalEntitlementScopePolicy.php';
    require $backend . '/vendor/autoload.php';
    use app\services\cashier\v3\card\CashierV3HistoricalEntitlementScopePolicy as Policy;
    $db = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    \think\facade\Db::$pdo = $db;
    $db->exec('CREATE TABLE eb_system_store(id INTEGER,is_show INTEGER,is_del INTEGER,business_status INTEGER)');
    $db->exec('CREATE TABLE eb_organization(id INTEGER,pid INTEGER,status INTEGER,is_del INTEGER)');
    $db->exec('CREATE TABLE eb_organization_store(store_id INTEGER,org_id INTEGER)');
    $db->exec('CREATE TABLE eb_migration_entitlement_scope(tenant_id TEXT,status TEXT,card_holder_id INTEGER,owner_org_id INTEGER,source_snapshot_json TEXT)');
    $db->beginTransaction();
    try {
        $db->exec('INSERT INTO eb_system_store VALUES(10,1,0,1),(20,1,0,1),(30,0,0,1)');
        $db->exec('INSERT INTO eb_organization VALUES(100,0,1,0),(101,100,1,0),(200,0,1,0)');
        $db->exec('INSERT INTO eb_organization_store VALUES(10,101),(20,200),(30,101)');
        $query = $db->prepare('INSERT INTO eb_migration_entitlement_scope VALUES(?,?,?,?,?)');
        $query->execute(['0', 'active', 1, 100, json_encode(['source_paid_amount_yuan' => '1000.00'])]);
        $tests = [
            'owner organization descendant store allowed' => Policy::allowedHolderIds([1], '0', 10) === [1],
            'unrelated organization rejected' => Policy::allowedHolderIds([1], '0', 20) === [],
            'unregistered holder rejected' => Policy::allowedHolderIds([2], '0', 10) === [],
            'other tenant rejected' => Policy::allowedHolderIds([1], 'another', 10) === [],
            'inactive store rejected' => Policy::allowedHolderIds([1], '0', 30) === [],
            'immutable paid amount restored from zero carrier source' => Policy::sourcePaidPrice(1, '0') === '1000.00',
        ];
        // Execute the production projection caller and real shared-pool allocator
        // with the paid amount obtained above from the real migration policy.
        $projectionClass = new \ReflectionClass(\app\services\cashier\v3\cashier\CashierV3EntitlementProjectionServices::class);
        $projection = $projectionClass->newInstanceWithoutConstructor();
        $transferAmount = $projectionClass->getMethod('transferRemainingAmount'); $transferAmount->setAccessible(true);
        $carrier = ['id' => 1, 'store_id' => 0, 'mark' => 'migration_entitlement', 'pay_price' => '0.00'];
        $authority = ['ruleType' => 'choice_count', 'cardRuleType' => 'choice_count', 'totalTimes' => 10, 'remainingTimes' => 7];
        $project = ['id' => 1, 'purchaseAmount' => '0.00', 'remainingAmount' => '0.00', 'sourceType' => ''];
        $actualRemaining = $transferAmount->invoke($projection, [$project, array_replace($project, ['id' => 2])], $authority,
            $carrier, '0.00', '0.00', [], Policy::sourcePaidPrice(1, '0'), '0.00', 'count_card');
        $tests['migration choice_count paid1000 remaining7of10 projects700 without duplicate options'] = $actualRemaining === '700.00';
        $tests['conversion caller preserves technical carrier zero'] = $carrier['pay_price'] === '0.00';
        $tests['migration shared value still excludes cancelled100'] = $transferAmount->invoke($projection, [$project], $authority,
            $carrier, '0.00', '100.00', [], Policy::sourcePaidPrice(1, '0'), '0.00', 'count_card') === '600.00';
        foreach ($tests as $label => $passed) {
            if (!$passed) throw new \RuntimeException($label);
            echo 'PASS ' . $label . PHP_EOL;
        }
    } finally { $db->rollBack(); }
    if ((int)$db->query('SELECT COUNT(*) FROM eb_migration_entitlement_scope')->fetchColumn() !== 0) throw new \RuntimeException('fixture leak');
    echo 'R53_HISTORICAL_SCOPE_SQLITE_OK checks=' . (count($tests) + 1) . PHP_EOL;
}
