<?php

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

require_once dirname(__DIR__, 3) . '/后端代码/app/services/report/StoreReportNormalDataScopeServices.php';

use app\services\report\StoreReportNormalDataScopeServices;

/** Compile the normal-scope callbacks against a small SQL query surface. */
final class VoidScopeQuery
{
    private string $table;
    private string $alias;
    private array $joins = [];
    private array $predicates = [];

    public function __construct(string $table = '', string $alias = '')
    {
        $this->table = $table;
        $this->alias = $alias;
    }

    public function name(string $table): self
    {
        $this->table = 'eb_' . $table;
        return $this;
    }

    public function alias(string $alias): self
    {
        $this->alias = $alias;
        return $this;
    }

    public function join(string $table, string $condition): self
    {
        $this->joins[] = 'JOIN eb_' . $table . ' ON ' . $condition;
        return $this;
    }

    public function whereRaw(string $expression): self
    {
        $this->predicates[] = $expression;
        return $this;
    }

    public function where(string $column, string $value): self
    {
        $this->predicates[] = $column . ' = ' . "'" . str_replace("'", "''", $value) . "'";
        return $this;
    }

    public function whereIn(string $column, array $values): self
    {
        $quoted = array_map(static fn(string $value): string => "'" . str_replace("'", "''", $value) . "'", $values);
        $this->predicates[] = $column . ' IN (' . implode(',', $quoted) . ')';
        return $this;
    }

    public function whereNotExists(callable $predicate): self
    {
        $nested = new self();
        $predicate($nested);
        $this->predicates[] = 'NOT EXISTS (' . $nested->sql() . ')';
        return $this;
    }

    public function sql(): string
    {
        return 'SELECT 1 FROM ' . $this->table . ($this->alias === '' ? '' : ' ' . $this->alias)
            . ($this->joins === [] ? '' : ' ' . implode(' ', $this->joins))
            . ($this->predicates === [] ? '' : ' WHERE ' . implode(' AND ', $this->predicates));
    }
}

function assertVisible(PDO $db, VoidScopeQuery $query, array $expected, string $case): void
{
    $actual = array_map('strval', $db->query(str_replace('SELECT 1 FROM ', 'SELECT row_id FROM ', $query->sql()))->fetchAll(PDO::FETCH_COLUMN));
    sort($actual);
    sort($expected);
    if ($actual !== $expected) {
        throw new RuntimeException($case . ': expected ' . json_encode($expected) . ', got ' . json_encode($actual));
    }
    echo 'PASS ' . $case . PHP_EOL;
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->sqliteCreateFunction('CONCAT', static fn(string $a, string $b): string => $a . $b, 2);
$db->exec('CREATE TABLE eb_cashier_v3_payment_fact (row_id TEXT, tenant_id TEXT, store_id INTEGER, order_id TEXT, fact_id TEXT, source_document_type TEXT)');
$db->exec('CREATE TABLE eb_cashier_v3_payment_sale_allocation_fact (row_id TEXT, tenant_id TEXT, store_id INTEGER, payment_fact_id TEXT)');
$db->exec('CREATE TABLE eb_cashier_v3_entitlement_service_fact (row_id TEXT, id INTEGER, tenant_id TEXT, store_id INTEGER, checkout_request_id TEXT, source_line_id TEXT)');
$db->exec('CREATE TABLE eb_cashier_v3_sale_fact (tenant_id TEXT, order_id TEXT, source_line_id TEXT)');
$db->exec('CREATE TABLE eb_cashier_v3_order_lifecycle_operation (tenant_id TEXT, source_order_id TEXT, source_type TEXT, operation_type TEXT, status TEXT)');
$db->exec('CREATE TABLE eb_cashier_v3_order_center_void_operation (tenant_id TEXT, store_id INTEGER, source_id TEXT, source_kind TEXT, status TEXT)');
$db->exec('CREATE TABLE eb_cashier_v3_service_record_void_operation (tenant_id TEXT, service_fact_id INTEGER, status TEXT)');
$db->exec("INSERT INTO eb_cashier_v3_payment_fact VALUES
    ('sale-original','t1',1,'S1','PF-S1','sales_order'),
    ('sale-reversal','t1',1,'S1','RF-S1','sales_order'),
    ('sale-refund','t1',1,'S2','PF-S2','sales_order'),
    ('recharge-original','t1',1,'RCH:7','PF-R7','recharge'),
    ('recharge-other-tenant','t2',1,'RCH:7','PF-T2','recharge'),
    ('supplement-original','t1',1,'RP1','PF-RP1','debt_repayment'),
    ('supplement-reversal','t1',1,'RP1','RF-RP1','debt_repayment'),
    ('supplement-failed','t1',1,'RP2','PF-RP2','debt_repayment'),
    ('supplement-other-store','t1',2,'RP1','PF-RP1-2','debt_repayment')");
$db->exec("INSERT INTO eb_cashier_v3_order_lifecycle_operation VALUES
    ('t1','S1','sales','void','succeeded'),
    ('t1','S2','sales','refund','succeeded'),
    ('t1','7','recharge','void','succeeded')");
$db->exec("INSERT INTO eb_cashier_v3_order_center_void_operation VALUES
    ('t1',1,'RP1','sales_supplement','succeeded'),
    ('t1',1,'RP2','sales_supplement','failed')");
$db->exec("INSERT INTO eb_cashier_v3_payment_sale_allocation_fact VALUES
    ('allocation-original','t1',1,'PF-RP1'),
    ('allocation-reversal','t1',1,'RF-RP1'),
    ('allocation-failed','t1',1,'PF-RP2'),
    ('allocation-other-store','t1',2,'PF-RP1-2')");
$db->exec("INSERT INTO eb_cashier_v3_entitlement_service_fact VALUES
    ('service-void',10,'t1',1,'C1','L1'),
    ('service-other-line',11,'t1',1,'C1','L2'),
    ('service-other-tenant',12,'t2',1,'C1','L1'),
    ('service-sale-void',13,'t1',1,'C2','L3'),
    ('service-sale-refund',14,'t1',1,'C3','L4')");
$db->exec("INSERT INTO eb_cashier_v3_sale_fact VALUES ('t1','S1','L3'),('t1','S2','L4')");
$db->exec("INSERT INTO eb_cashier_v3_service_record_void_operation VALUES ('t1',10,'succeeded'),('t1',11,'failed')");

$scope = new StoreReportNormalDataScopeServices();
$sales = new VoidScopeQuery('eb_cashier_v3_payment_fact', 'p');
$scope->excludeVoidedSalesOrderFacts($sales, 'p.tenant_id', 'p.order_id');
assertVisible($db, $sales, ['sale-refund','recharge-original','recharge-other-tenant','supplement-original','supplement-reversal','supplement-failed','supplement-other-store'], 'sales void excludes both days, refund stays');

$recharge = new VoidScopeQuery('eb_cashier_v3_payment_fact', 'p');
$scope->excludeVoidedRechargeOrderFacts($recharge, 'p.tenant_id', 'p.order_id');
assertVisible($db, $recharge, ['sale-original','sale-reversal','sale-refund','recharge-other-tenant','supplement-original','supplement-reversal','supplement-failed','supplement-other-store'], 'recharge void is tenant-scoped');

$supplement = new VoidScopeQuery('eb_cashier_v3_payment_fact', 'p');
$scope->excludeVoidedSupplementPaymentFacts($supplement, 'p.tenant_id', 'p.store_id', 'p.order_id');
assertVisible($db, $supplement, ['sale-original','sale-reversal','sale-refund','recharge-original','recharge-other-tenant','supplement-failed','supplement-other-store'], 'supplement void excludes both days, failed stays');

$allocations = new VoidScopeQuery('eb_cashier_v3_payment_sale_allocation_fact', 'a');
$scope->excludeVoidedSupplementAllocations($allocations, 'a');
assertVisible($db, $allocations, ['allocation-failed','allocation-other-store'], 'supplement allocation follows payment identity');

$services = new VoidScopeQuery('eb_cashier_v3_entitlement_service_fact', 's');
$scope->excludeVoidedServiceRecord($services, 's');
assertVisible($db, $services, ['service-other-line','service-other-tenant','service-sale-void','service-sale-refund'], 'service void keeps unrelated service facts');

$allServices = new VoidScopeQuery('eb_cashier_v3_entitlement_service_fact', 's');
$scope->excludeVoidedSalesOrderServices($allServices, 's');
assertVisible($db, $allServices, ['service-other-line','service-other-tenant','service-sale-refund'], 'service normal scope excludes direct and sale voids');
