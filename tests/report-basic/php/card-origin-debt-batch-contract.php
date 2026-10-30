<?php
declare(strict_types=1);

namespace think\facade {
    /** Minimal read-only query double records query count and filters fixture rows. */
    final class Db
    {
        public static $rows = [];
        public static $queries = 0;
        public static function name(string $table): Query { ++self::$queries; return new Query($table); }
    }
    final class Query
    {
        private $table;
        private $filters = [];
        public function __construct(string $table) { $this->table = $table; }
        public function alias(string $alias): self { return $this; }
        public function join(string $table, string $on): self { return $this; }
        public function field(string $fields): self { return $this; }
        public function order(string $order): self { return $this; }
        public function lock(bool $lock): self { return $this; }
        public function where(string $key, $value): self
        {
            $this->filters[] = [preg_replace('/^.*\./', '', $key), [$value]];
            return $this;
        }
        public function whereIn(string $key, array $values): self
        {
            $this->filters[] = [preg_replace('/^.*\./', '', $key), $values];
            return $this;
        }
        public function select(): Rows
        {
            $rows = [];
            foreach (Db::$rows[$this->table] ?? [] as $row) {
                foreach ($this->filters as [$key, $allowed]) if (!in_array($row[$key] ?? null, $allowed, false)) continue 2;
                $rows[] = $row;
            }
            return new Rows($rows);
        }
        public function find(): ?array { return $this->select()->toArray()[0] ?? null; }
    }
    final class Rows
    {
        private $rows;
        public function __construct(array $rows) { $this->rows = $rows; }
        public function toArray(): array { return $this->rows; }
    }
}

namespace {
    require_once __DIR__.'/../../../后端代码/app/services/cashier/v3/cashier/CashierV3CardOriginDebtResolver.php';
    use app\services\cashier\v3\cashier\CashierV3CardOriginDebtResolver;
    use think\facade\Db;

    /** Fixtures test active, settled, cancelled and historic card boundaries. */
    Db::$rows = [
        'store_order_cart_info' => [
            ['oid'=>101,'uid'=>1,'cart_type'=>0,'product_type'=>5,'source_type'=>'cashier_v3',
                'cart_info'=>'{"salesOrderId":"SO1","salesOrderLineId":"L1"}'],
            ['oid'=>102,'uid'=>2,'cart_type'=>0,'product_type'=>5,'source_type'=>'cashier_v3',
                'cart_info'=>'{"salesOrderId":"SO2","salesOrderLineId":"L2"}'],
            ['oid'=>104,'uid'=>4,'cart_type'=>0,'product_type'=>5,'source_type'=>'cashier_v3',
                'cart_info'=>'{"salesOrderId":"SO4","salesOrderLineId":"L4"}'],
        ],
        'cashier_v3_debt_authority' => [
            ['tenant_id'=>'0','sales_order_id'=>'SO1','member_id'=>1,'debt_id'=>501,'status'=>0],
            ['tenant_id'=>'0','sales_order_id'=>'SO2','member_id'=>2,'debt_id'=>502,'status'=>1],
            ['tenant_id'=>'0','sales_order_id'=>'SO4','member_id'=>4,'debt_id'=>504,'status'=>3],
        ],
        'cashier_v3_sales_order_line' => [
            ['tenant_id'=>'0','order_id'=>'SO1','order_line_id'=>'L1','debt_amount_cents'=>3000],
            ['tenant_id'=>'0','order_id'=>'SO2','order_line_id'=>'L2','debt_amount_cents'=>2000],
            ['tenant_id'=>'0','order_id'=>'SO4','order_line_id'=>'L4','debt_amount_cents'=>4000],
        ],
        'cashier_v3_debt_item_personnel_authority' => [
            ['tenant_id'=>'0','debt_id'=>501,'order_line_id'=>'L1','debt_amount'=>'30.00','repaid_debt'=>'10.00'],
            ['tenant_id'=>'0','debt_id'=>502,'order_line_id'=>'L2','debt_amount'=>'20.00','repaid_debt'=>'20.00'],
            ['tenant_id'=>'0','debt_id'=>504,'order_line_id'=>'L4','debt_amount'=>'40.00','repaid_debt'=>'0.00'],
        ],
    ];
    $orders = [['id'=>101,'uid'=>1,'tenant_id'=>'0'],['id'=>102,'uid'=>2,'tenant_id'=>'0'],
        ['id'=>103,'uid'=>3,'tenant_id'=>'0'],['id'=>104,'uid'=>4,'tenant_id'=>'0']];
    $resolver = new CashierV3CardOriginDebtResolver();
    $statuses = [];
    $batch = $resolver->pendingV3CardDebtsForOrders($orders, '0', $statuses);
    if ($batch !== [101=>'20.00',102=>'0.00',103=>null,104=>'0.00'])
        throw new \RuntimeException('batch pending result differs from single-card contract');
    if ($statuses !== [101=>0,102=>1,103=>null,104=>3])
        throw new \RuntimeException('batch debt status identity invalid');
    if (Db::$queries !== 4) throw new \RuntimeException('batch must use four source reads, got '.Db::$queries);
    foreach ($orders as $order) {
        if ($resolver->pendingV3CardDebt($order, $order['uid']) !== $batch[$order['id']])
            throw new \RuntimeException('single-card and batch pending amount disagree');
    }
    // Identical business IDs in another tenant must not duplicate or replace
    // the authorized source chain. Mixed-tenant original orders fail closed.
    Db::$rows['cashier_v3_debt_authority'][] =
        ['tenant_id'=>'1','sales_order_id'=>'SO1','member_id'=>1,'debt_id'=>601,'status'=>0];
    Db::$rows['cashier_v3_sales_order_line'][] =
        ['tenant_id'=>'1','order_id'=>'SO1','order_line_id'=>'L1','debt_amount_cents'=>9900];
    Db::$rows['cashier_v3_debt_item_personnel_authority'][] =
        ['tenant_id'=>'1','debt_id'=>501,'order_line_id'=>'L1','debt_amount'=>'99.00','repaid_debt'=>'0.00'];
    if ($resolver->pendingV3CardDebtsForOrders($orders, '0') !== $batch)
        throw new \RuntimeException('cross-tenant rows changed authorized batch');
    $mixed = $orders; $mixed[0]['tenant_id'] = '1';
    try { $resolver->pendingV3CardDebtsForOrders($mixed, '0');
        throw new \RuntimeException('mixed-tenant originals were accepted');
    } catch (\LogicException $expected) {
        if ($expected->getMessage() !== 'cashier_v3_card_origin_batch_identity_invalid') throw $expected;
    }
    echo "card-origin-debt-batch-contract PASS\n";
}
